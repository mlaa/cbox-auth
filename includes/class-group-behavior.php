<?php
/**
 * Group Behavior
 *
 * @package CustomAuth
 */

namespace MLA\Commons\Plugin\CustomAuth;

use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Selectively show/hide BuddyPress group settings and initiate API interaction
 * for MLA groups.
 *
 * @package CustomAuth
 * @subpackage GroupBehavior
 * @class GroupBehavior
 */
class GroupBehavior extends Base {

	/**
	 * BuddyPress group id
	 *
	 * @var int
	 */
	private $bp_id;

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
	private $logger;

	/**
	 * Constructor
	 *
	 * @param MLAAPI $mla_api Dependency: MLAAPI.
	 * @param Logger $logger  Dependency: Logger.
	 */
	public function __construct( MLAAPI $mla_api, Logger $logger ) {

		$this->mla_api = $mla_api;
		$this->logger = $logger;

		// Hide join/leave buttons where necessary.
		$this->add_action( 'bp_directory_groups_actions', $this, 'hide_join_button', 1 );
		$this->add_action( 'bp_group_header_actions', $this, 'hide_join_button', 1 );

		// Only show some group settings.
		$this->add_filter( 'bp_group_settings_allowed_sections', $this, 'filter_group_settings_sections' );

		// Don't show the request membership tab for committee groups.
		$this->add_filter( 'bp_get_options_nav_request-membership', $this, 'hide_request_membership_tab' );
		$this->add_filter( 'bp_get_options_nav_invite', $this, 'hide_send_invites_tab' );

		$this->add_action( 'bp_before_group_body', $this, 'sync_membership_from_api' );

		$this->run();

	}

	/**
	 * Get group type.
	 */
	public function get_group_type() {

		global $groups_template;

		if ( $groups_template && $this->get_object_property( $groups_template, 'group', false ) ) {
			$bp_id = $groups_template->group->id;
			return strtolower( \groups_get_groupmeta( $bp_id, 'mla_group_type', true ) );
		}

		return 0;

	}

	/**
	 * Sync group membership data from MLA API.
	 */
	public function sync_membership_from_api() {

		try {
			$mla_group = new MLAGroup( \bp_get_group_id(), $this->mla_api, $this->logger );
			$mla_group->sync();
		} catch ( \Exception $e ) {
			$this->logger->addError( $e->getMessage() );
		}

	}

	/**
	 * Hide the request membership tab for MLA committees.
	 *
	 * @param string $string Unchanged filter string.
	 */
	public function hide_request_membership_tab( $string ) {
		return ( 'mla organization' === $this->get_group_type() ) ? null : $string;
	}

	/**
	 * Hide the send invites tab for MLA committees.
	 *
	 * @param string $string Unchanged filter string.
	 */
	public function hide_send_invites_tab( $string ) {
		return ( 'mla organization' === $this->get_group_type() ) ? null : $string;
	}

	/**
	 * Hide privacy and invite sections for MLA committees and forums.
	 *
	 * @param array $allowed Unchanged settings array.
	 */
	public function filter_group_settings_sections( $allowed ) {
		return ( $this->get_group_type() ) ? null : $allowed;
	}


	/**
	 * Hide the join/leave button for MLA committees and forums for which the
	 * user is not an admin.
	 *
	 * @param BP_Groups_Group $group BuddyPress group.
	 */
	public function hide_join_button( $group ) {

		// Remove the other actions that would create this button.
		$actions = array(
			'bp_group_header_actions'     => 'bp_group_join_button',
			'bp_directory_groups_actions' => 'bp_group_join_button',
		);
		foreach ( $actions as $name => $action ) {
			\remove_action( $name, $action, \has_action( $name, $action ) );
		}

		$user_id = \bp_loggedin_user_id();
		$group_type = $this->get_group_type();

		$is_committee   = ( 'mla organization' === $group_type );
		$is_forum_admin = ( 'forum' === $group_type && \groups_is_user_admin( $user_id, \bp_get_group_id() ) );

		if ( $is_committee || $is_forum_admin ) {
			return;
		}

		return \bp_group_join_button( $group );

	}
}
