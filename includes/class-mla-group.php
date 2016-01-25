<?php
/**
 * MLA Group
 *
 * @package CustomAuth
 */

namespace MLA\Commons\Plugin\CustomAuth;

use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Uses MLA API to sync group membership data.
 *
 * @package CustomAuth
 * @subpackage MLAGroup
 * @class MLAGroup
 */
class MLAGroup extends Base {

	/**
	 * BuddyPress group id
	 *
	 * @var int
	 */
	public $bp_id;

	/**
	 * BuddyPress group metadata
	 *
	 * @var object
	 */
	private $bp_meta;

	/**
	 * MLA API data
	 *
	 * @var object
	 */
	private $api_data;

	/**
	 * Dependency: MLAAPI
	 *
	 * @var object
	 */
	private $mla_api;

	/**
	 * Dependency: Logger
	 *
	 * @var object
	 */
	protected $logger;

	/**
	 * API update interval: number of seconds below which to force update of
	 * group membership data.
	 *
	 * @var string
	 */
	private $update_interval = 3600;

	/**
	 * Constructor
	 *
	 * @param int    $bp_id   BuddyPress group id.
	 * @param MLAAPI $mla_api Dependency: MLAAPI.
	 * @param Logger $logger  Dependency: Logger.
	 */
	public function __construct( $bp_id, MLAAPI $mla_api, Logger $logger ) {
		$this->bp_id = $bp_id;
		$this->mla_api = $mla_api;
		$this->logger = $logger;
	}

	/**
	 * Checks when the group data was last updated, so that it doesn't reload it
	 * from the API unnecessarily.
	 *
	 * @return bool True if the group should be synced.
	 */
	private function allow_sync() {

		if ( ! isset( $this->bp_meta['mla_api_id'] ) || empty( $this->bp_meta['mla_api_id'][0] ) ) {
			$this->logger->addDebug( 'Skipping: Group ' . $this->bp_id . ' does not have an MLA API ID.' );
			return false;
		}

		$last_updated = ( isset( $this->bp_meta['last_updated'] ) ) ? (integer) $this->bp_meta['last_updated'][0] : 0;
		$time_since = time() - $last_updated;

		if ( $time_since < $this->update_interval ) {
			$group_ref = $this->describe_group( $this->bp_id );
			$this->logger->addDebug( 'Skipping: Group ' . $group_ref . ' has been recently synced.' );
			return false;
		}

		return true;

	}

	/**
	 * Gets group data from the API.
	 */
	private function get_api_group_data() {

		// If api_members is already populated, no need to refetch.
		if ( isset( $this->api_data ) ) {
			return true;
		}

		$this->api_data = $this->mla_api->get_group( $this->bp_meta['mla_api_id'][0] );
		$this->group_names[ $this->bp_id ] = $this->api_data->name;

	}

	/**
	 * Gets group data from BuddyPress.
	 *
	 * @return array BuddyPress membership array.
	 */
	private function get_bp_group_data() {

		$args = array(
			'group_id'   => $this->bp_id,
			'group_role' => array( 'admin', 'mod', 'member' ),
			'per_page'   => 9999,
		);

		$bp_members = array();
		$raw_bp_members = \groups_get_group_members( $args );

		foreach ( $raw_bp_members['members'] as $member ) {

			// Cache user name since we have it.
			$this->user_names[ $member->user_id ] = $member->user_nicename;

			// Store 'username => group_role'.
			$bp_members[ strtolower( $member->user_nicename ) ] = $this->get_bp_group_role( $member );

		}

		return $bp_members;

	}

	/**
	 * Gets string represention of BuddyPress role.
	 *
	 * @param object $member BuddyPress group member.
	 * @return string BuddyPress group role.
	 */
	private function get_bp_group_role( $member ) {
		if ( 1 === $member->is_mod ) {
			return 'mod';
		}
		if ( 1 === $member->is_admin ) {
			return 'admin';
		}
		return 'member';
	}

	/**
	 * Update BuddyPress group metadata if it has changed.
	 */
	private function set_bp_group_meta() {

		$group_type = strtolower( $this->api_data->type );
		$group_status = ( 'mla organization' === $group_type ) ? 'private' : 'public';
		$group_ref = $this->describe_group( $this->bp_id );

		$bp_meta = array(
			'mla_api_id' => strtolower( $this->api_data->id ),
			'mla_group_type' => $group_type,
		);

		// Update BP group metadata.
		foreach ( $bp_meta as $meta_key => $meta_value ) {
			if ( ! isset( $this->bp_meta[ $meta_key ] ) || $meta_value !== $this->bp_meta[ $meta_key ][0] ) {
				$this->logger->addInfo( 'Group ' . $group_ref . ' updated group meta ' . $meta_key . '.' );
				\groups_update_groupmeta( $this->bp_id, $meta_key, $meta_value );
			}
		}

		// Update the group visibility.
		$group = new \BP_Groups_Group( $this->bp_id, true );
		if ( $group->status !== $group_status ) {
			$group->status = $group_status;
			$group->save();
		}

	}

	/**
	 * Syncs BuddyPress group members from MLA API data.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function sync_group_members() {

		$bp_members = $this->get_bp_group_data();
		$group_type = strtolower( $this->api_data->type );
		$group_ref = $this->describe_group( $this->bp_id );

		// Find differences between API member list and BP member list.
		$diff = array_diff_assoc( $this->api_data->members, $bp_members );
		$this->logger->addDebug( 'Adjusting API memberships for group ' . $group_ref . ':', $diff );

		// At this point we have an associative array that reflects members that
		// are in the API group membership but NOT the BuddyPress group membership,
		// in the form of `username => role`.
		foreach ( $diff as $member_username => $mla_role ) {

			// Ignore records with empty IDs.
			if ( ! $member_username ) {
				$this->logger->addError( 'Encountered empty username when syncing group ' . $group_ref . '.' );
				continue;
			}

			// Get the WP user ID for this member from the username.
			$user_id = \bp_core_get_userid( $member_username );

			// If we can't look up the member ID, this is a member that has not joined
			// the Commons.
			if ( ! $user_id || 0 === $user_id ) {
				$this->logger->addError( 'API returned member ' . $member_username . ' that has not joined the Commons.' );
				continue;
			}

			// Look up the corresponding role in BP's records.
			if ( array_key_exists( $member_username, $bp_members ) ) {
				$bp_role = $bp_members[ $member_username ];
			} else {

				// Cache user name since we have it.
				$this->user_names[ $user_id ] = $member_username;

				// If user isn't a member of the BuddyPress group, add them.
				$this->add_user( $user_id, $this->bp_id );

				// Newly-added members are automatically given the role of member. We can
				// promote them as necessary later.
				$bp_role = 'member';

			}

			if ( $mla_role === $bp_role ) {
				continue;
			}

			if ( 'admin' === $mla_role ) {
				// User has been promoted at MLA, but not on BP. Promote.
				$this->promote_user( $user_id, $this->bp_id );
			}

			if ( 'member' === $mla_role && 'admin' === $bp_role ) {
				// User has been demoted at MLA, but not on BP. Demote.
				$this->demote_user( $user_id, $this->bp_id );
			}
		}

		// These diffs can be large, so free up some memory and make the next task
		// easier by removing roles we no longer care about.
		unset( $diff );
		if ( 'forum' === $group_type ) {
			$bp_members = array_filter( $bp_members, function ( $item ) {
				return ( 'admin' === $item );
			} );
		}

		// Compare BP memberships to API group memberships (opposite direction).
		$opposing_diff = array_diff_assoc( $bp_members, $this->api_data->members );
		$this->logger->addDebug( 'Adjusting BP memberships for group ' . $group_ref . ':', $opposing_diff );

		// Find differences between the BP group membership and the API group
		// membership (in the other direction). We will either remove or demote
		// them -- for forums, we demote; for committees, we remove.
		foreach ( $opposing_diff as $member_username => $bp_role ) {

			// Get the WP user ID for this member from the username.
			$user_id = \bp_core_get_userid( $member_username );

			// We'll never remove a forum member for being absent in the API, but we
			// will demote them if they're an admin.
			if ( 'forum' === $group_type && 'admin' === $bp_role ) {
				$this->demote_user( $user_id, $this->bp_id );
				continue;
			}

			// For committees, if they don't exist in the API, they should be removed
			// from the group.
			if ( 'mla organization' === $group_type && ! array_key_exists( $member_username, $this->api_data->members ) ) {
				$this->remove_user( $user_id, $this->bp_id );
				continue;
			}
		}

	}

	/**
	 * Finds discrepancies between the API and BuddyPress group memberships and
	 * makes changes to BuddyPress.
	 */
	public function sync() {

		if ( $this->bp_id ) {
			$this->bp_meta = \groups_get_groupmeta( $this->bp_id );
		}

		if ( ! $this->allow_sync() ) {
			return false;
		}

		$this->get_api_group_data();
		$this->set_bp_group_meta();
		$this->sync_group_members();

		// Update last-updated date.
		\groups_update_groupmeta( $this->bp_id, 'last_updated', time() );

		return true;

	}

	/**
	 * Create a BuddyPress group. Used when that group exists in the MLA API, but
	 * not in BuddyPress.
	 *
	 * @param string $name Group name.
	 * @param string $type MLA group type ('forum').
	 * @param string $api_id MLA API ID.
	 * @return mixed BuddyPress group ID if group was successfully created; otherwise false.
	 */
	public function create_bp_group( $name, $type, $api_id ) {

		$new_group = array(
			'creator_id' => 1,
			'slug'       => \groups_check_slug( \sanitize_title_with_dashes( $name ) ),
			'name'       => $name,
			'status'     => ( 'mla organization' === strtolower( $type ) ) ? 'private' : 'public',
		);

		$this->bp_id = \groups_create_group( $new_group );
		$this->group_names[ $this->bp_id ] = $name;

		if ( ! $this->bp_id || empty( $this->bp_id ) || $this->bp_id instanceof \WP_Error ) {
			$this->logger->addError( 'Group "' . $name . '" could not be created: ' . serialize( $this->bp_id ) );
			return false;
		}

		$group_ref = $this->describe_group( $this->bp_id );
		$this->logger->addInfo( 'Group ' . $group_ref . ' created.' );

		// Set MLA API ID and group type.
		\groups_update_groupmeta( $this->bp_id, 'mla_api_id', $api_id );
		\groups_update_groupmeta( $this->bp_id, 'mla_group_type', strtolower( $type ) );

		return $this->bp_id;

	}
}
