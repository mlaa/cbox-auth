<?php

class CustomAuthentication extends MLAAPI {

	function __construct() {
		if ( ! isset( $this->debug ) ) { $this->debug = false; }
	}

	// AUTHENTICATION FUNCTIONS ( PHASE 1 )
	//
	/**
	 * This function is hooked into the 'authenticate' filter where
	 * we can authenticate what the user submits to the login form.
	 *
	 * @param $ignored
	 * @param $username Can be username or ID
	 * @param $password
	 * @return array|bool|object|WP_Error|WP_User
	 */
	public function authenticate_username_password( $ignored, $username, $password ) {

		// Stolen from wp_authenticate_username_password
		if ( empty( $username ) || empty( $password ) ) {
			return new WP_Error();
		}

		// List forbidden usernames here.
		// This will make the ceaseless hack attempts from bots
		// a little easier on the logs.
		$forbidden_usernames = array(
			'admin',
			'administrator',
		);

		if ( in_array( $username, $forbidden_usernames ) ) {
			return new WP_Error( 'forbidden_username', __( 'This username has been blocked.' ) );
		}

		// Get the user from the MLA database. If the user doesn't exist
		// or the username/password is wrong, return the error
		$customUserData = $this->find_custom_user( $username, $password );

		$customLoginError = null;
		if ( $customUserData instanceof WP_Error ) {
			// If the user is not a member, let's see if she is a WP admin.
			_log( 'Looks like customUserData is an instance of WP_Error!' );
			$customLoginError = $customUserData;
		}

		// Get the user from the WP database
		if ( $customUserData instanceof WP_Error ) {
			$userdata = get_user_by( 'login', $username );
		} else {
			$userdata = get_user_by( 'login', $customUserData['user_name'] );
		}

		// If the user doesn't exist yet, create one.
		if ( ! $userdata ) {
			if ( '' !== $_POST['preferred'] && username_exists( $_POST['preferred'] ) ) {
				return new WP_Error( 'invalid_username', __( '<strong>Error (' . __LINE__ . '):</strong> That user name already exists.' ) );
			}

			if ( '' !== $_POST['preferred'] && ! validate_username( $_POST['preferred'] ) ) {
				return new WP_Error( 'invalid_username', __( '<strong>Error (' . __LINE__ . '):</strong> User names must be between four and twenty characters in length and must contain at least one letter. Only lowercase letters, numbers, and underscores are allowed.' ) );
			}

			if ( $customLoginError ) {
				// The user doesn't exist yet anywhere. Don't allow login.
				_log( 'Getting a customLoginError.' );
				return $customLoginError;
			}

			$userdata = $this->create_wp_user( $customUserData );

			if ( $userdata instanceof WP_Error ) {
				_log( 'Error creating WP User!' );
				return $userdata;
			}

			// Send welcome email
			$user_id = $userdata->data->ID;
			wpmu_welcome_user_notification( $user_id, $password = '' );

			// Post activity item for new member.
			_log( 'Now attempting to add activity.' );
			$component = buddypress()->members->id;
			$success = bp_activity_add( array(
				'user_id'   => $user_id,
				'component' => $component,
				'type'      => 'new_member',
			) );

			if ( ! $success ) { _log( 'Failed to add activity item for new member.' );
			} else { _log( "Successfully added new activity item for new member at id: $success" ); }

			// Catch terms acceptance on first login.
			update_user_meta( $userdata->ID, 'accepted_terms', $_POST['acceptance'] );

			add_filter( 'login_redirect', array( $this, 'redirect_to_profile' ), 10, 3 );
		} else {
			// Stolen from wp_authenticate_username_password
			if ( is_multisite() ) {
				// Is user marked as spam?
				if ( 1 === $userdata->spam ) {
					return new WP_Error( 'invalid_username', __( '<strong>Error (' . __LINE__ . '):</strong> Your account has been marked as a spammer.' ) ); }

				// Is a user's blog marked as spam?
				if ( ! is_super_admin( $userdata->ID ) && isset( $userdata->primary_blog ) ) {
					$details = get_blog_details( $userdata->primary_blog );
					if ( is_object( $details ) && 1 === $details->spam ) {
						return new WP_Error( 'blog_suspended', __( '<strong>Error (' . __LINE__ . '):</strong> Your account has been suspended.' ) ); }
				}
			}

			if ( $customLoginError ) {
				// See if the WP user is an admin. If so, grant access immediately. Otherwise, return errors.
				if ( wp_check_password( $password, $userdata->user_pass, $userdata->ID )
					&& ( 'yes' === get_user_meta( $userdata->ID, 'mla_nonmember', $single = true )
					|| is_super_admin( $userdata->ID ) ) ) {
					// Add a cookie to speed up the login process for non-first-time users
					$this->set_remember_cookie( $username );
					return $userdata;
				} else {
					return $customLoginError;
				}
			}
		}

		// Add a cookie to speed up the login process for non-first-time users
		$this->set_remember_cookie( $username );

		// At this point $userdata is a WP_User
		$this->merge_wp_user( $userdata->ID, $customUserData );

		// Create/join/leave groups.
		$this->manage_groups( $userdata->ID, $customUserData['groups'] );

		// Special activities for special users.
		$this->special_cases( $userdata->ID, $customUserData['id'] );

		return $userdata;
	}

	private function set_remember_cookie( $value ) {
		if ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] || 443 === $_SERVER['SERVER_PORT'] ) {
			$secure_connection = true;
		} else {
			$secure_connection = false;
		}
		if ( ! defined( 'RUNNING_TESTS' ) ) {
			setcookie( 'MLABeenHereBefore', md5( $value ), time() + ( 20 * 365 * 24 * 60 * 60 ), null, null, $secure_connection );
		}
	}

	/**
	 * Called from javascript to determine if the user is new.
	 */
	public function ajax_test_user() {
		$result = false;
		$guess = '';
		$customUserData = $this->find_custom_user( $_POST['username'], $_POST['password'] );
		if ( ! $customUserData instanceof WP_Error ) {
			$userdata = get_user_by( 'login', $customUserData['user_name'] );
			if ( ! $userdata ) {
				$result = true;
				if ( $customUserData['id'] !== $customUserData['user_name'] ) {
					$guess = $customUserData['user_name'];
				}
			}
		}
		echo wp_json_encode( array( 'result' => ( $result ? 'true' : 'false' ), 'guess' => $guess ) );
		die();
	}

	/**
	 * Called from javascript to determine if a username is valid.
	 */
	public function ajax_validate_preferred_username() {
		$preferred = $_POST['preferred'];
		$username = $_POST['username'];
		$password = $_POST['password']; // not used since switch from change_custom_username() to is_username_duplicate()
		$message = '';
		$error_message_constraints = 'User names must be between four and twenty characters in length and must contain at least one letter. Only lowercase letters, numbers, and underscores are allowed.';
		$error_message_duplicate = 'That user name already exists.';
		$result = false;
		if ( validate_username( $preferred ) ) {
			if ( $preferred === $username ) {
				$result = true;
			} else if ( username_exists( $preferred ) ) { // check for duplicate in WordPress
				$message = $error_message_duplicate;
			} else if ( ! preg_match( '/[a-z]/', $preferred ) ) { // must contain at least one letter
				$message = $error_message_constraints;
			} else if ( ! preg_match( '/^[a-z0-9_]{4,20}$/', $preferred ) ) { // don't allow characters that aren't lowercase letters, numbers, underscores
				$message = $error_message_constraints;
			} else {
				$res = $this->is_username_duplicate( $preferred ); // check for duplicate in MLA API
				if ( $res instanceof WP_Error ) {
					$message = $error_message_duplicate;
				} else {
					$decoded = json_decode( $res['body'], true );
					if ( ! $decoded['data'][0]['username']['duplicate'] ) {
						$result = true;
					}
				}
			}
		} else {
			$message = $error_message_constraints;
		}
		wp_die( wp_json_encode( array( 'result' => ( $result ? 'true' : 'false' ), 'message' => $message ) ) );
	}

	/**
	 * A filter for redirecting users to the profile page. For the filter: login_redirect.
	 *
	 * @param null $redirect_to
	 * @param null $request
	 * @param null $user
	 * @return null|string|void
	 */
	public function redirect_to_profile( $redirect_to = null, $request = null, $user = null ) {
		if ( null !== $user ) {
			return home_url( "members/$user->user_login/profile/edit" );
		} else {
			return $redirect_to;
		}
	}

	/**
	 * @param $userId
	 * @param array  $groupsData
	 * @return null
	 */
	protected function manage_groups( $userId, array $groupsData ) {
		global $wpdb, $bp;

		// _log( "Managing groups with userID: $userId!" );
		// Get BP groups with OIDs and their associated BP group IDs.
		$custom_groups = $wpdb->get_results( $wpdb->prepare( 'SELECT group_id, meta_value FROM ' . $bp->groups->table_name_groupmeta . ' WHERE meta_key = %s', 'mla_oid' ) );

		// Make an efficient copy of the user's groups.
		$user_groups = array();
		foreach ( $groupsData as $groupData ) {
			$user_groups[ $groupData['oid'] ] = $groupData;
		}

		// Loop through each BP group and add/remove/promote/demote the user as needed.
		// @todo: I should probably be instantiating MLAMember here instead of doing all this stuff,
		// just to keep it all in one place, but I'm keeping this here for the moment,
		// because I suspect that it might be more efficient than instantiating the class.
		foreach ( $custom_groups as $custom_group ) {

			// _log( 'Now looking at group:', $custom_group );
			$groupId = $custom_group->group_id;
			$customOid = $custom_group->meta_value;

			if ( isset( $user_groups[ $customOid ] ) ) {

				// Copy user group data and delete it.
				$groupData = $user_groups[ $customOid ];
				unset( $user_groups[ $customOid ] );

				// No-Op if user is already a member
				groups_join_group( $groupId, $userId );

				// _log( 'User role appears to be:', $groupData['role'] );
				// First we need to tell BP we're an admin.
				bp_update_is_item_admin( true, 'groups' );

				// If a user is a chair, liaison, etc, promote them.
				// If not, demote them.
				if ( 'admin' === $this->translate_mla_role( $groupData['role'] ) ) {
					// _log( "User is admin! Promoting user $userId in group $groupId." );
					$success = groups_promote_member( $userId, $groupId, 'admin' );
					 // if ( $success ) _log( 'Great success!' ); else _log( 'Couldn\'t promote user!' );
				} else {
					// _log( "User is regular member! Demoting user $userId in group $groupId." );
					$success = groups_demote_member( $userId, $groupId );
					 // if ( $success ) _log( 'Great success!' ); else _log( 'Couldn\'t demote user!' );
				}
			} elseif ( ! $this->is_prospective_forum_group( $customOid ) ) {
				// Remove the user from the group.
				groups_leave_group( $groupId, $userId );
			}
		}

		// Groups still in the user_groups array need to be created.
		foreach ( $user_groups as $groupData ) {

			$groupData['name'] = $groupData['name'] ? $groupData['name'] : '';

			$newGroup = array(
				'creator_id' => 1, // Chris can be the group creator.
				'slug' => groups_check_slug( sanitize_title_with_dashes( $groupData['name'] ) ),
				'name' => $groupData['name'],
			);

			// add group status to array if given.
			if ( array_key_exists( 'status', $groupData ) ) { $newGroup['status'] = $groupData['status']; }

			// _log( 'About to create group with data: ', $groupData );
			$groupId = groups_create_group( $newGroup );

			// if ( ! $groupId ) {
				// _log( 'Warning! No group created!' );
			// } else {
				// _log( "Group created ID is: $groupId" );
			// }
			// Store MLA OID (convention code, old ID) in the group meta (BP DB).
			groups_update_groupmeta( $groupId, 'mla_oid', $groupData['oid'] );

			// Store MLA API ID (new ID) in the group meta. We'll need this for syncing below.
			groups_update_groupmeta( $groupId, 'mla_api_id', $groupData['mla_api_id'] );

			groups_join_group( $groupId, $userId );

			// Now sync the group memebership data using the new MLAGroup class.
			$sync_obj = new MLAGroup( $debug = true, $id = $groupId );
			$sync_obj->sync();

			// If a user has the role 'chair', 'liaison', 'liason' [sic],
			// 'secretary', 'executive', or 'program-chair', then promote
			// the user to admin. Otherwise, demote the user.
			if ( isset( $groupData['role'] ) ) {
				if ( 'admin' === $this->translate_mla_role( $groupData['role'] ) ) {
					groups_promote_member( $userId, $groupId, 'admin' );
				}
			}
		}

		return null;
	}

	/**
	 * @param $userId
	 * @param $customUserId
	 * @return null
	 */
	protected function special_cases( $userId, $customUserId ) {
		global $wpdb, $bp;

		switch ( $customUserId ) {
			case 67613:
				// Add the user to some groups.
				foreach ( array( 310, 321, 322 ) as $groupId ) {
					groups_join_group( $groupId, $userId );
				}
				break;
		}

		return null;
	}

	/**
	 * Use cUrl to find the member data from the membership API.
	 *
	 * The API gives one of two things: an error or member data. If
	 * we get member data, we convert it to a readable array.
	 *
	 * @param $username Again, this can be an username or ID.
	 * @param $password
	 * @return array|WP_Error
	 */
	protected function find_custom_user( $username, $password ) {

		$response = $this->get_member( $username );

		if ( false === $response || '' === $response ) {
			// This only happens if we can't access the API server.
			_log( 'Authentication Plugin: is API server down?' );
			return new WP_Error( 'server_error', __( '<strong>Error (' . __LINE__ . '):</strong> There was a problem verifying your member credentials. Please try again later.' ) );
		}

		if ( ! array_key_exists( 'code', $response ) ) {
			_log( 'Didn\'t get a code in the response. Is the API server down?' );
			return new WP_Error( 'server_error', __( '<strong>Error (' . __LINE__ . '):</strong> There was a problem verifying your member credentials. Please try again later.' ) );
		} else if ( 200 !== $response['code'] ) {
			_log( 'Authentication plugin: got a code other than 200. Here\'s what the server said:' );
			_log( $response );
			return new WP_Error( 'server_error', __( '<strong>Error (' . __LINE__ . '):</strong> There was a problem verifying your member credentials. Please try again later.' ) );
		}

		try {
			$decoded = json_decode( $response['body'], true );
		} catch ( Exception $e ) {
			_log( 'Authentication Plugin: couldn\'t decode JSON response from server. Response was:', $response );
			return new WP_Error( 'server_error', __( '<strong>Error (' . __LINE__ . '):</strong> Because of a temporary problem, we cannot verify your member credentials at this time. Please try again in a few minutes.' ) );
		}

		if ( 'success' !== $decoded['meta']['status'] ) {
			_log( 'Authentication plugin: member lookup was not a success. Server says:', $decoded['meta'] );
			return new WP_Error( 'not_authorized', sprintf( __( '<strong>Error (' . __LINE__ . '):</strong> Your user name and password could not be verified. Please try again.' ), wp_lostpassword_url() ) );
		}

		$json_data = $decoded['data'];

		if ( count( $json_data ) > 1 ) {
			_log( 'Authentication plugin: there was more than one response for that username. This should never happen. Responses:', $json_data );
			return new WP_Error( 'not_authorized', sprintf( __( '<strong>Error (' . __LINE__ . '):</strong> Your user name and password could not be verified. Please try again.' ), wp_lostpassword_url() ) );
		}

		$json_data = $json_data[0]; // There should only be one now.

		// Authenticate with password!
		$our_password = crypt( $password, $json_data['authentication']['password'] ); // salt it and hash it appropriately
		$their_password = $json_data['authentication']['password'];

		if ( $our_password !== $their_password ) {
			_log( 'Passwords do not match!' );
			return new WP_Error( 'not_authorized', sprintf( __( '<strong>Error (' . __LINE__ . '):</strong> Your user name and password could not be verified. Please try again.' ), wp_lostpassword_url() ) );
		}

		$json_member_data = $decoded['data'][0];

		$json_array = $this->member_json_to_array( $json_member_data, $password );

		// Make sure the user is active and of the allowed types ( i.e. 'member' )
		if ( ! $this->validate_custom_user( $json_array, $username, $error ) ) {
			return $error;
		}

		return $json_array;

	}

	protected function merge_wp_user( $userId, $member ) {
		wp_update_user( array( 'ID' => $userId, 'user_email' => $member['email'] ) );
		update_user_meta( $userId, 'languages', $member['languages'] );
		update_user_meta( $userId, 'affiliations', $member['affiliations'] );
		update_user_meta( $userId, 'mla_oid', $member['id'] );
	}

	/**
	 * Takes the member data and creates a WP user.
	 *
	 * @param $member
	 * @return WP_Error|WP_User
	 */
	protected function create_wp_user( $member ) {
		if ( '' !== $_POST['preferred'] && $member['user_name'] !== $_POST['preferred'] ) {
			$result = $this->change_custom_username( $member['user_name'], $member['password'], $_POST['preferred'] );
			if ( ! $result instanceof WP_Error ) {
				$username = $_POST['preferred'];
			} else {
				return $result;
			}
		} else {
			$username = $member['user_name'];
		}
		$newUserData = array(
			'user_pass' => $member['password'],
			'user_login' => $username,
			'user_email' => $member['email'],
			'display_name' => $member['display_name'],
			'first_name' => $member['first_name'],
			'last_name' => $member['last_name'],
			'user_url' => $member['website'],
			'role' => $member['role'],
		);
		$userId = wp_insert_user( $newUserData );
		if ( $userId instanceof WP_Error ) {
			return $userId;
		}

		return new WP_User( $userId );
	}

	/**
	 * Turns member json data from the membership API
	 * into a readable array.
	 *
	 * @param $json
	 * @param $password
	 * @return array
	 */
	protected function member_json_to_array( $json, $password ) {
		$languages = array();
		$affiliations = array();
		$groups = array();
		$affiliations = array();

		// _log( 'incoming json to member_json_to_array:', $json );
		foreach ( $json['addresses'] as $address ) {
			if ( array_key_exists( 'affiliation', $address ) ) {
				$affiliations[] = $address['affiliation'];
			}
		}

		foreach ( $json['organizations'] as $group ) {

			// Don't parse groups that should not be reflected on the Commons.
			if ( ! $this->is_mla_group( $group['convention_code'] ) ) { continue; }
			if ( 'Y' === $group['exclude_from_commons'] ) { continue; }

			// Committees and other MLA organizations should be private groups.
			if ( $this->is_committee_group( $group['convention_code'] ) ) {
				$group['status'] = 'private';
			} else {
				$group['status'] = 'public';
			}

			$groups[] = array(
				// [oid] => G049
				// [role] =>
				// [name] => Age Studies
				// [status] => public
				'oid'        => $group['convention_code'],
				'mla_api_id' => $group['id'],
				'role'       => $group['position'],
				'type'       => $group['type'],
				'name'       => $group['name'],
				'status'     => $group['status'],
			);
		}

		$return = array(
			'id' => $json['id'],
			'user_name' => $json['authentication']['username'],
			'status' => $json['authentication']['membership_status'],
			'password' => $password,
			'email' => trim( $json['general']['email'] ),
			'first_name' => trim( $json['general']['first_name'] ),
			'last_name' => trim( $json['general']['last_name'] ),
			'display_name' => trim( $json['general']['first_name'] . ' ' . $json['general']['last_name'] ),
			'website' => trim( $json['general']['web_site'] ),
			'languages' => $json['languages'],
			'affiliations' => $affiliations,
			'groups' => $groups,
			'role' => 'subscriber',
		 );
		return $return;
	}

	/**
	 * Makes sure that the user is
	 * 1) The same user we asked for.
	 * 2) An active user.
	 *
	 * @param $member
	 * @param $id
	 * @param null   $error
	 * @return bool
	 */
	protected function validate_custom_user( $member, $id, &$error = null ) {

		if ( $id !== $member['id'] && urlencode( strtolower( $member['user_name'] ) ) !== $id ) {
			// This should not happen since the API gives us the member based on the ID or Username. Nonetheless, it's worth checking.
			$error = new WP_Error( 'server_error', __( '<strong>Error (' . __LINE__ . '):</strong> There was a problem verifying your member credentials. Please try again later.' ) );
			return false;
		}

		if ( 'active' !== $member['status'] ) {
			$error = new WP_Error( 'server_error', __( '<strong>Error (' . __LINE__ . '):</strong> Your membership is not active.' ) );
			return false;
		}

		return true;
	}




	// GROUP MANAGEMENT FUNCTIONS ( PHASE 2 )
	//
	public function activate() {
		$allGroups = groups_get_groups( array( 'per_page' => null ) );

		foreach ( $allGroups['groups'] as $group ) {
			$group_custom_oid = groups_get_groupmeta( $group->id, 'mla_oid', true );
			if ( empty( $group_custom_oid ) ) {
				continue;
			}
			if ( $this->is_forum_group( $group_custom_oid ) ) {
				$this->change_group_status( $group, 'public' );
				continue;
			}
			if ( $this->is_committee_group( $group_custom_oid ) ) {
				$this->change_group_status( $group, 'private' );
			}
		}
	}

	/**
	 * Allows you to change a groups status ( i.e. private or public )
	 *
	 * @param stdClass $group
	 * @param $status
	 */
	private function change_group_status( stdClass $group, $status ) {
		global $wpdb, $bp;

		if ( $group->status !== $status ) {
			// Have to use SQL because buddypress isn't all set up at this point
			$wpdb->query( $wpdb->prepare( 'UPDATE '.$bp->groups->table_name.' SET status=%s WHERE id=%d', $status, $group->id ) );
		}
	}

	/**
	 * A filter to hide the request membership tab for MLA groups
	 *
	 * @param $string
	 */
	public function hide_request_membership_tab( $string ) {
		global $groups_template;

		// Get group info
		$group =& $groups_template->group;
		$group_custom_oid = groups_get_groupmeta( $group->id, 'mla_oid', true );

		// Don't show request membership if it's an MLA group ( probably a committee, since only committees are private )
		if ( ! empty( $group_custom_oid ) && $this->is_mla_group( $group_custom_oid ) ) {
			return;
		}

		return $string;
	}
	/**
	 * A filter to hide the send invites tab for committee groups
	 *
	 * @param $string
	 */
	public function hide_send_invites_tab( $string ) {
		global $groups_template;

		// Get group info
		$group =& $groups_template->group;
		$group_custom_oid = groups_get_groupmeta( $group->id, 'mla_oid', true );

		// Don't show request membership if it's an MLA group ( probably a committee, since only committees are private )
		if ( ! empty( $group_custom_oid ) && $this->is_committee_group( $group_custom_oid ) ) {
			return;
		}

		return $string;
	}

	/**
	 * A filter that whitelists which sections can be shown in
	 * the group settings.
	 *
	 * @param array $allowed
	 * @return mixed
	 */
	public function determine_group_settings_sections( array $allowed ) {
		global $groups_template;

		// Get group info
		$group =& $groups_template->group;
		$group_custom_oid = groups_get_groupmeta( $group->id, 'mla_oid', true );

		// Don't show privacy or invitation settings if it's an MLA group
		if ( ! empty( $group_custom_oid ) && $this->is_mla_group( $group_custom_oid ) ) {
			return;
		}

		// Show all settings
		$allowed['privacy'] = true;
		$allowed['invitations'] = true;
		return $allowed;
	}


	/**
	 * Only show the join/login button if the group is not a committee
	 * and the member is not an administrator of the forum group.
	 *
	 * @param bool $group
	 */
	public function hide_join_button( $group = false ) {
		global $groups_template;

		// Remove the other actions that would create this button
		$priority = has_action( 'bp_group_header_actions', 'bp_group_join_button' );
		remove_action( 'bp_group_header_actions', 'bp_group_join_button', $priority );
		$priority = has_action( 'bp_directory_groups_actions', 'bp_group_join_button' );
		remove_action( 'bp_directory_groups_actions', 'bp_group_join_button', $priority );

		// Look up group info
		if ( empty( $group ) ) {
			$group =& $groups_template->group;
		}
		$group_custom_oid = groups_get_groupmeta( $group->id, 'mla_oid', true );

		// Get user id
		$user_id = bp_loggedin_user_id();

		// Render as normal if it's not an MLA group or it's a forum group and user is not admin of group
		if ( empty( $group_custom_oid ) || $this->is_prospective_forum_group( $group_custom_oid ) || ( $group_custom_oid && $this->is_forum_group( $group_custom_oid ) && ! groups_is_user_admin( $user_id, $group->id ) ) ) {
			return bp_group_join_button( $group );
		}

	}

	protected function send_welcome_email( $to_user ) {
		// $subject = '';
		// $message = '';
		// wp_mail( $to_user, $subject, $message );
	}


	// UTILITY FUNCTIONS
	//
	/**
	 * Determine if the group is a forum
	 *
	 * @param string $group_custom_oid
	 * @return bool
	 */
	protected function is_forum_group( $group_custom_oid ) {
		$flag = substr( $group_custom_oid, 0, 1 );
		if ( 'D' !== $flag && 'G' !== $flag ) {
			return false;
		}
		return true;
	}

	/**
	 * Determine if the group is a committee
	 *
	 * @param string $group_custom_oid
	 * @return bool
	 */
	protected function is_committee_group( $group_custom_oid ) {
		$flag = substr( $group_custom_oid, 0, 1 );
		if ( 'M' !== $flag ) {
			return false;
		}
		return true;
	}

	/**
	 * Determine if the group is an MLA group
	 *
	 * @param string $group_custom_oid
	 * @return bool
	 */
	protected function is_mla_group( $group_custom_oid ) {
        if ( $this->is_forum_group( $group_custom_oid ) || $this->is_committee_group( $group_custom_oid ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Determine if the group is a prospective forum
	 *
	 * @param string $group_custom_oid
	 * @return bool
	 */
	protected function is_prospective_forum_group( $group_custom_oid ) {
		$flag = substr( $group_custom_oid, 0, 1 );
		if ( 'F' !== $flag ) {
			return false;
		}
		return true;
	}

}
?>
