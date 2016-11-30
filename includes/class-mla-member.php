<?php
/**
 * MLA Member
 *
 * @package CustomAuth
 */

namespace MLA\Commons\Plugin\CustomAuth;

use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Uses MLA API to sync member data.
 *
 * @package CustomAuth
 * @subpackage MLAMember
 * @class MLAMember
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class MLAMember extends Base {

	/**
	 * WordPress/BuddyPress user id
	 *
	 * @var int
	 */
	public $user_id;

	/**
	 * WordPress user metadata
	 *
	 * @var object
	 */
	private $wp_meta;

	/**
	 * BuddyPress XProfile metadata
	 *
	 * @var object
	 */
	private $bp_xprofile;

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
	 * @param int    $user_id WordPress/BuddyPress user id.
	 * @param MLAAPI $mla_api Dependency: MLAAPI.
	 * @param Logger $logger  Dependency: Logger.
	 */
	public function __construct( $user_id, MLAAPI $mla_api, Logger $logger ) {
		$this->user_id = $user_id;
		$this->mla_api = $mla_api;
		$this->logger = $logger;
	}

	/**
	 * Checks when the member data was last updated, so that it doesn't reload it
	 * from the API unnecessarily.
	 *
	 * @return bool True if the member should be synced.
	 */
	private function allow_sync() {

		if ( ! isset( $this->wp_meta['mla_oid'] ) && ! isset( $this->api_data->id ) ) {
			$this->logger->addDebug( 'Skipping: User ' . $this->user_id . ' does not have an MLA API ID.' );
			return false;
		}

		$last_updated = ( isset( $this->wp_meta['last_updated'] ) ) ? (integer) $this->wp_meta['last_updated'][0] : 0;
		$time_since = time() - $last_updated;

		if ( $time_since < $this->update_interval ) {
			$user_ref = $this->describe_user( $this->user_id );
			$this->logger->addDebug( 'Skipping: User ' . $user_ref . ' has been recently synced.' );
			return false;
		}

		return true;

	}

	/**
	 * Gets member data from the API.
	 *
	 * @param string $api_id MLA member ID or username.
	 */
	private function get_api_member_data( $api_id ) {

		// If API data is already populated, no need to refetch.
		if ( isset( $this->api_data ) ) {
			return true;
		}

		$this->api_data = $this->mla_api->get_member( $api_id );
		$this->user_names[ $this->user_id ] = $this->api_data->authentication->username;

	}

	/**
	 * Gets BuddyPress group membersips.
	 *
	 * @return array Associative array of BuddyPress group memberships.
	 */
	private function get_bp_member_data() {

		$args = array(
			'user_id'           => $this->user_id,
			'per_page'          => 9999,
			'update_meta_cache' => true,
		);

		$raw_bp_groups = \groups_get_groups( $args );
		$bp_groups = array();

		// Record group IDs where user is 'mod'.
		$mod_of = \BP_Groups_Member::get_is_mod_of( $this->user_id );
		$mod_group_ids = array();
		foreach ( $mod_of['groups'] as $group ) {
			$mod_group_ids[] = $group->id;
		}

		// Record group IDs where user is 'admin'.
		$admin_of = \BP_Groups_Member::get_is_admin_of( $this->user_id );
		$admin_group_ids = array();
		foreach ( $admin_of['groups'] as $group ) {
			$admin_group_ids[] = $group->id;
		}

		// Record member roles for groups with 'mla_group_type' set.
		foreach ( $raw_bp_groups['groups'] as $group ) {

			// Cache group name since we have it.
			$this->group_names[ $group->id ] = $group->name;

			// Get MLA group type.
			$group_type = \groups_get_groupmeta( $group->id, 'mla_group_type' );

			if ( $group_type ) {
				if ( in_array( $group->id, $admin_group_ids, true ) ) {
					$bp_groups[ $group->id ] = 'admin';
					continue;
				}
				if ( in_array( $group->id, $mod_group_ids, true ) ) {
					$bp_groups[ $group->id ] = 'mod';
					continue;
				}
				// Only record 'member' type if group is a Committee.
				if ( 'mla organization' === strtolower( $group_type ) ) {
					$bp_groups[ $group->id ] = 'member';
				}
			}
		}

		return $bp_groups;

	}

	/**
	 * Get an associative array of MLA API IDs to BuddyPress group IDs.
	 *
	 * @return array Array of IDs.
	 */
	private function get_api_to_bp_ids() {

		global $wpdb;

		$api_to_bp_ids = \wp_cache_get( 'api_to_bp_ids', 'plugin_custom_auth' );

		if ( $api_to_bp_ids ) {
			return $api_to_bp_ids;
		}

		$results = $wpdb->get_results(
			"
			SELECT meta_value,group_id
			FROM {$wpdb->base_prefix}bp_groups_groupmeta
			WHERE meta_key = 'mla_api_id'
			"
		); // WPCS: db call ok.

		if ( count( $results ) > 0 ) {
			$api_to_bp_ids = array();
			foreach ( $results as $result ) {
				$api_to_bp_ids[ $result->meta_value ] = $result->group_id; // @codingStandardsIgnoreLine WordPress.VIP.MetaValue
			}
		}

		\wp_cache_set( 'api_to_bp_ids', $api_to_bp_ids, 'plugin_custom_auth', 60 * 60 );

		return $api_to_bp_ids;

	}

	/**
	 * Update WordPress user metadata if it has changed.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity,PHPMD.NPathComplexity)
	 */
	private function set_wp_user_meta() {

		// These values are cached by WordPress, so don't worry about repeated calls.
		$current_user_data = \get_userdata( $this->user_id );
		$user_ref = $this->describe_user( $this->user_id );

		// Extract affiliations.
		$affiliations = array();
		foreach ( $this->api_data->addresses as $address ) {
			if ( property_exists( $address, 'affiliation' ) ) {
				$affiliations[] = $address->affiliation;
			}
		}

		// Extract languages.
		$languages = array();
		foreach ( $this->api_data->languages as $language ) {
			$languages[] = (array) $language;
		}

		$wp_meta = array(
			'first_name'   => $this->api_data->general->first_name,
			'last_name'    => $this->api_data->general->last_name,
			'nickname'     => $this->api_data->general->first_name . ' ' . $this->api_data->general->last_name,
			'mla_oid'      => $this->api_data->id,
			'mla_status'   => $this->api_data->authentication->membership_status,
			'mla_website'  => $this->api_data->general->web_site,
		);

		$serialized_wp_meta = array(
			'languages'    => $languages,
			'affiliations' => $affiliations,
		);

		$bp_xprofile = array(
			'Name' => $this->wp_meta['nickname'][0],
			'Institutional or Other Affiliation' => $affiliations[0],
			'Title' => $this->api_data->addresses[0]->rank,
		);
/* Turn off user updates
		// Update WP user metadata.
		foreach ( $wp_meta as $meta_key => $meta_value ) {
			if ( ! isset( $this->wp_meta[ $meta_key ] ) || $meta_value !== $this->wp_meta[ $meta_key ][0] ) {
				$this->logger->addInfo( 'User ' . $user_ref . ' updated user meta ' . $meta_key . '.' );
				\update_user_meta( $this->user_id, $meta_key, $meta_value ); // @codingStandardsIgnoreLine WordPress.VIP.UserMeta
			}
		}

		// Update serialized WP user metadata.
		foreach ( $serialized_wp_meta as $meta_key => $meta_value ) {
			if ( ! isset( $this->wp_meta[ $meta_key ] ) || unserialize( $this->wp_meta[ $meta_key ][0] ) !== $meta_value ) {
				$this->logger->addInfo( 'User ' . $user_ref . ' updated user meta ' . $meta_key . '.' );
				\update_user_meta( $this->user_id, $meta_key, $meta_value ); // @codingStandardsIgnoreLine WordPress.VIP.UserMeta
			}
		}

		// Update XProfile fields (if they exist).
		foreach ( $bp_xprofile as $meta_key => $meta_value ) {
			if ( ! isset( $this->bp_xprofile[ $meta_key ] ) || $meta_value !== $this->bp_xprofile[ $meta_key ]['field_data'] ) {
				$this->logger->addInfo( 'User ' . $user_ref . ' updated xprofile ' . $meta_key . '.' );
				\xprofile_set_field_data( $meta_key, $this->user_id, $meta_value );
			}
		}

		if ( $this->api_data->general->email !== $current_user_data->user_email ) {
			$this->logger->addInfo( 'User ' . $user_ref . ' updated e-mail address.' );
			\wp_update_user( array( 'ID' => $this->user_id, 'user_email' => $this->api_data->general->email ) );
		}
*/
		return true;

	}

	/**
	 * Create a group using the MLAGroup class.
	 *
	 * @param string $name   Name of group.
	 * @param string $type   MLA group type of group.
	 * @param string $api_id MLA API id.
	 */
	private function create_group( $name, $type, $api_id ) {

		$new_group = new MLAGroup( false, $this->mla_api, $this->logger );
		$bp_id = $new_group->create_bp_group( $name, $type, $api_id );

		if ( $bp_id ) {
			$this->group_names[ $bp_id ] = $name;
		}

		return $bp_id;

	}

	/**
	 * Syncs BuddyPress group memberships from MLA API data.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity,PHPMD.NPathComplexity,PHPMD.ExcessiveMethodLength)
	 */
	private function sync_group_memberships() {

		$bp_groups = $this->get_bp_member_data();
		$api_to_bp_ids = $this->get_api_to_bp_ids();

		$user_ref = $this->describe_user( $this->user_id );

		// Parse raw groups returned by MLA API and attempt to create the group if
		// it doesn't exist.
		$api_groups = array();
		foreach ( $this->api_data->organizations as $api_id => $group_data ) {

			$bp_id = $api_to_bp_ids[ $api_id ];

			if ( ! $bp_id ) {

				$bp_id = $this->create_group( $group_data['name'], $group_data['type'], $api_id );

				// If we can't create the group, skip it.
				if ( ! $bp_id ) {
					continue;
				}
			}

			$api_groups[ $bp_id ] = $group_data['role'];

		}

		// Compare API group memberships to BP group memberships.
		$diff = array_diff_assoc( $api_groups, $bp_groups );
		$this->logger->addDebug( 'Adjusting API group memberships for user ' . $user_ref . ':', $diff );

		// At this point we have an associative array that reflects members that
		// are in the API group membership but NOT the BuddyPress group membership,
		// in the form of `group_id => role`.
		foreach ( $diff as $group_id => $mla_role ) {

			// Ignore records with empty IDs.
			if ( ! $group_id ) {
				$this->logger->addError( 'Encountered empty group id when syncing user ' . $user_ref . '.' );
				continue;
			}

			// Look up the corresponding role in BP's records.
			if ( array_key_exists( $group_id, $bp_groups ) ) {
				$bp_role = $bp_groups[ $group_id ];
			} else {

				// If user isn't a member of the BuddyPress group, add them.
				$this->add_user( $this->user_id, $group_id );

				// Newly-added members are automatically given the role of member. We can
				// promote them as necessary later.
				$bp_role = 'member';

			}

			if ( $mla_role === $bp_role ) {
				continue;
			}

			if ( 'admin' === $mla_role && 'admin' !== $bp_role ) {
				// User has been promoted at MLA, but not on BP. Promote.
				$this->promote_user( $this->user_id, $group_id );
			}

			if ( 'member' === $mla_role && 'admin' === $bp_role ) {
				// User has been demoted at MLA, but not on BP. Demote.
				$this->demote_user( $this->user_id, $group_id );
			}
		}

		// Compare BP memberships to API group memberships (opposite direction).
		$diff = array_diff_assoc( $bp_groups, $api_groups );
		$this->logger->addDebug( 'Adjusting BP group memberships for user ' . $user_ref . ':', $diff );

		// Find differences between the BP group membership and the API group
		// membership (in the other direction). We will either remove or demote
		// them -- for forums, we demote; for committees, we remove.
		foreach ( $diff as $group_id => $bp_role ) {

			// Get the MLA group type.
			$group_type = \groups_get_groupmeta( $group_id, 'mla_group_type' );

			// We'll never remove a forum member for being absent in the API, but we
			// will demote them if they're an admin.
			if ( 'forum' === $group_type && 'admin' === $bp_role ) {
				$this->demote_user( $this->user_id, $group_id );
				continue;
			}

			// For committees, if they don't exist in the API, they should be removed
			// from the group.
			if ( 'mla organization' === $group_type && ! array_key_exists( $group_id, $api_groups ) ) {
				$this->remove_user( $this->user_id, $group_id );
				continue;
			}
		}

	}

	/**
	 * Change a member's username via the API. This is allowed once when the user
	 * first joins the site.
	 *
	 * @param string $api_id       MLA member number.
	 * @param string $new_username Requested new username.
	 */
	private function change_api_username( $api_id, $new_username ) {

		/**
		 * First we need to get the user ID from the MLA API, because the API
		 * requires an ID when changing usernames. The user is new, so we don't
		 * have a way to look it up in WP.
		 */

		try {
			$this->get_api_member_data( $api_id );
			$this->mla_api->change_username( $this->api_data->id, $new_username );
		} catch ( \Exception $e ) {
			$user_ref = $this->describe_user( $this->user_id );
			$this->logger->addError( 'Unable to change username for user ' . $user_ref . ' to ' . $new_username );
		}

	}

	/**
	 * Authenticate user's login request.
	 *
	 * @param string $user_id  MLA ID number or username.
	 * @param string $password MLA password.
	 * @return bool True if user's credentials are correct and the membership status is valid.
	 * @throws \Exception Describes authentication failure.
	 */
	public function authenticate( $user_id, $password ) {

		// A user should only be authenticated if there were no previous API requests.
		if ( isset( $this->api_data ) ) {
			throw new \Exception( 'User ' . $user_id . ' is already authenticated.', 400 );
		}

		$this->get_api_member_data( $user_id );
		$password_hash = crypt( $password, $this->api_data->authentication->password );

		if ( $password_hash !== $this->api_data->authentication->password ) {
			throw new \Exception( 'User ' . $user_id . ' supplied incorrect credentials.', 400 );
		}

		if ( 'active' !== $this->api_data->authentication->membership_status ) {
			throw new \Exception( 'User ' . $user_id . ' status is not active.', 401 );
		}

		$wp_user = \get_user_by( 'login', $this->api_data->authentication->username );
		if ( $wp_user ) {
			$this->user_id = $wp_user->ID;
		}

		return true;

	}

	/**
	 * Gets member data from the new API and, if there are any changes,
	 * updates the corresponding WordPress user.
	 *
	 * @return bool True if member data was synced.
	 */
	public function sync() {

		if ( $this->user_id ) {
			$this->wp_meta = \get_user_meta( $this->user_id ); // @codingStandardsIgnoreLine WordPress.VIP.UserMeta
			$this->bp_xprofile = \BP_XProfile_ProfileData::get_all_for_user( $this->user_id );
		}

		if ( ! $this->allow_sync() ) {
			return false;
		}

		$mla_api_id = ( isset( $this->wp_meta['mla_oid'] ) ) ? $this->wp_meta['mla_oid'][0] : $this->api_data->id;
		$this->get_api_member_data( $mla_api_id );
		$this->set_wp_user_meta();
		$this->sync_group_memberships();

		// Update last-updated date.
		\update_user_meta( $this->user_id, 'last_updated', time() ); // @codingStandardsIgnoreLine WordPress.VIP.UserMeta

		return true;

	}

	/**
	 * Create a WordPress user. Used when a user exists in the MLA API, but not
	 * in WordPress.
	 *
	 * @param string $preferred Preferred username.
	 * @param string $accepted  Text value for user terms acceptance.
	 * @throws \Exception Describes why user could not be created.
	 */
	public function create_wp_user( $preferred = null, $accepted = 'No' ) {

		// If the user does not have API data, we can't create it.
		if ( ! $this->api_data ) {
			throw new \Exception( 'Cannot create user without API data.', 580 );
		}

		// Don't create the user more than once.
		if ( $this->user_id || \get_user_by( 'login', $this->api_data->authentication->username ) ) {
			throw new \Exception( 'Cannot recreate user ' . $this->api_data->authentication->username . '.', 585 );
		}

		// Change username. We always send the API call even if the username hasn't
		// changed, because that's currently the only way we can record that the
		// member has joined the Commons.
		$this->validate_username( $this->api_data->authentication->username, $preferred );
		$this->change_api_username( $this->api_data->authentication->username, $preferred );

		$new_user_data = array(
			'user_pass'    => 'blank',
			'user_login'   => $preferred,
			'user_url'     => $this->api_data->general->web_site,
			'user_email'   => $this->api_data->general->email,
			'display_name' => $this->api_data->general->first_name . ' ' . $this->api_data->general->last_name,
			'nickname'     => $this->api_data->general->first_name . ' ' . $this->api_data->general->last_name,
			'first_name'   => $this->api_data->general->first_name,
			'last_name'    => $this->api_data->general->last_name,
			'role'         => 'subscriber',
		);

		// Returns WP user id if successful, WP_Error if not.
		$new_user = \wp_insert_user( $new_user_data );
		if ( $new_user instanceof \WP_Error ) {
			$message = 'Unable to create WP account for user ' . $this->api_data->authentication->username;
			throw new \Exception( $message . '. ' . $new_user->get_error_message(), 590 );
		}

		// Save reference to WP user id.
		$this->user_id = $new_user;
		$this->user_names[ $this->user_id ] = $preferred;

		// Log event.
		$user_ref = $this->describe_user( $this->user_id );
		$this->logger->addInfo( 'User ' . $user_ref . ' created.' );

		// Send welcome e-mail.
		\wpmu_welcome_user_notification( $this->user_id, '' );

		// Post activity item for new member.
		$success = \bp_activity_add(
			array(
				'user_id'   => $this->user_id,
				'component' => \buddypress()->members->id,
				'type'      => 'new_member',
			)
		);
		if ( ! $success ) {
			$this->logger->addError( 'Failed to add activity item for new user ' . $user_ref . '.' );
		}

		// Update terms acceptance on first login.
		\update_user_meta( $this->user_id, 'accepted_terms', $accepted ); // @codingStandardsIgnoreLine WordPress.VIP.UserMeta

	}

	/**
	 * Validate a user's requested new username.
	 *
	 * @param string $username  User's current username.
	 * @param string $preferred Requested new username.
	 * @return bool True if username is valid for both WP and the MLA API.
	 * @throws \Exception Describes why username is invalid.
	 */
	public function validate_username( $username, $preferred ) {

		$is_api_valid = preg_match( '/^[a-z][a-z0-9_]{3,19}$/', $preferred );
		$has_alpha = preg_match( '/[a-z]/', $preferred );

		if ( ! ( $has_alpha && $is_api_valid ) ) {
			throw new \Exception( 'User ' . $username . ' provided invalid preferred username: ' . $preferred, 450 );
		} else if ( strtolower( $preferred ) === strtolower( $username ) ) {
			// If the user has not changed their username from it's API value, make
			// sure we don't flag it as a duplicate.
			return true;
		} else if ( ! \validate_username( $preferred ) || \username_exists( $preferred ) ) {
			// Check for duplicate in WordPress.
			throw new \Exception( 'User ' . $username . ' provided duplicate WP username: ' . $preferred, 460 );
		} else if ( $this->mla_api->is_duplicate_username( $preferred ) ) {
			// Check for duplicate from API.
			throw new \Exception( 'User ' . $username . ' provided duplicate API username: ' . $preferred, 460 );
		}

		return true;

	}
}
