<?php

/* The abstract API class is in another file. */
require_once( 'class-MLAAPI.php' );

class CustomAuthentication extends MLAAPI {

	# AUTHENTICATION FUNCTIONS (PHASE 1)
	#####################################################################

	/**
	 * This function is hooked into the 'authenticate' filter where
	 * we can authenticate what the user submits to the login form.
	 *
	 * @param $ignored
	 * @param $username Can be username or ID
	 * @param $password
	 * @return array|bool|object|WP_Error|WP_User
	 */
	public function authenticate_username_password($ignored, $username, $password) {

		_log( 'starting authenticate_username_password()' ); 
		// Stolen from wp_authenticate_username_password
		if ( empty($username) || empty($password) ) {
			return new WP_Error();
		}

		// List forbidden usernames here. 
		// This will make the ceaseless hack attempts from bots 
		// a little easier on the logs. 
		$forbidden_usernames = array(
			'admin', 
			'administrator',
		); 

		if ( in_array($username, $forbidden_usernames) ) { 
			return new WP_Error('forbidden_username', __('This username has been blocked.')); 	
		} 

		// Get the user from the MLA database. If the user doesn't exist
		// or the username/password is wrong, return the error
		$customUserData = $this->findCustomUser($username, $password);

		$customLoginError = null;
		if($customUserData instanceof WP_Error) {
			// If the user is not a member, let's see if she is a WP admin.
			$customLoginError = $customUserData;
		}

		// Get the user from the WP database
		if($customUserData instanceof WP_Error) {
			$userdata = get_user_by('login', $username);
		} else {
			$userdata = get_user_by('login', $customUserData['user_name']);
		}

		// If the user doesn't exist yet, create one.
		if(!$userdata) {
			if($_POST['preferred'] != '' && username_exists($_POST['preferred'])) {
				return new WP_Error('invalid_username', __('<strong>Error (' . __LINE__ . '):</strong> That user name already exists.'));
			}

			if($_POST['preferred'] != '' && !validate_username($_POST['preferred'])) {
				return new WP_Error('invalid_username', __('<strong>Error (' . __LINE__ . '):</strong> User names must be between four and twenty characters in length and must contain at least one letter. Only lowercase letters, numbers, and underscores are allowed.'));
			}

			if($customLoginError) {
				// The user doesn't exist yet anywhere. Don't allow login.
				return $customLoginError;
			}
			$userdata = $this->createWpUser($customUserData);
			if($userdata instanceof WP_Error) {
				return $userdata;
			}
			// Catch terms acceptance on first login.
			update_user_meta($userdata->ID, 'accepted_terms', $_POST['acceptance']);
			add_filter('login_redirect', array($this, 'redirect_to_profile'), 10, 3);
		} else {
			// Stolen from wp_authenticate_username_password
			if ( is_multisite() ) {
				// Is user marked as spam?
				if ( 1 == $userdata->spam)
					return new WP_Error('invalid_username', __('<strong>Error (' . __LINE__ . '):</strong> Your account has been marked as a spammer.'));

				// Is a user's blog marked as spam?
				if ( !is_super_admin( $userdata->ID ) && isset($userdata->primary_blog) ) {
					$details = get_blog_details( $userdata->primary_blog );
					if ( is_object( $details ) && $details->spam == 1 )
						return new WP_Error('blog_suspended', __('<strong>Error (' . __LINE__ . '):</strong> Your account has been suspended.'));
				}
			}

			if($customLoginError) {
				// See if the WP user is an admin. If so, grant access immediately. Otherwise, return errors.
				if(wp_check_password($password, $userdata->user_pass, $userdata->ID) && (array_search('administrator', $userdata->roles) !== false || is_super_admin($userdata->ID))) {
					// Add a cookie to speed up the login process for non-first-time users
					$this->setRememberCookie($username);
					return $userdata;
				} else {
					return $customLoginError;
				}
			}
		}

		// Add a cookie to speed up the login process for non-first-time users
		$this->setRememberCookie($username);

		// At this point $userdata is a WP_User
		$this->mergeWpUser($userdata->ID, $customUserData);

		// Create/join/leave groups.
		$this->manageGroups($userdata->ID, $customUserData['groups']);

		// Special activities for special users.
		$this->specialCases($userdata->ID, $customUserData['id']);

		return $userdata;
	}

	private function setRememberCookie($value) {
		if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) {
			$secure_connection = true;
		} else {
			$secure_connection = false;
		}
		setcookie('MLABeenHereBefore', md5($value), time() + (20 * 365 * 24 * 60 * 60), null, null, $secure_connection);
	}

	/**
	 * Called from javascript to determine if the user is new.
	 */
	public function ajax_test_user() {
		$result = false;
		$guess = '';
		$customUserData = $this->findCustomUser($_POST['username'], $_POST['password']);
		if(!$customUserData instanceof WP_Error) {
			$userdata = get_user_by('login', $customUserData['user_name']);
			if(!$userdata) {
				$result = true;
				if($customUserData['id'] != $customUserData['user_name']) {
					$guess = $customUserData['user_name'];
				}
			}
		}
		echo json_encode(array('result' => ($result ? 'true' : 'false'), 'guess' => $guess));
		die();
	}

	/**
	 * Called from javascript to determine if a username is valid.
	 */
	public function ajax_validate_preferred_username() {
		$preferred = $_POST['preferred'];
		$username = $_POST['username'];
		$password = $_POST['password'];
		$message = '';
		$result = false;
		if (validate_username($preferred)) {
			if (username_exists($preferred)) {
				$message = 'That user name already exists.';
			} else {
				$res = $this->changeCustomUsername($username, $password, $preferred);
				if($res instanceof WP_Error) {
					$message = $res->get_error_message('name_change_error');
				} else {
					$result = true;
				}
			}
		} else {
			$message = 'User names must be between four and twenty characters in length and must contain at least one letter. Only lowercase letters, numbers, and underscores are allowed.';
		}
		echo json_encode(array('result' => ($result ? 'true' : 'false'), 'message' => $message));
		die();
	}

	/**
	 * A filter for redirecting users to the profile page. For the filter: login_redirect.
	 *
	 * @param null $redirect_to
	 * @param null $request
	 * @param null $user
	 * @return null|string|void
	 */
	public function redirect_to_profile($redirect_to = null, $request = null, $user = null) {
		if($user != null) {
			return home_url("members/$user->user_login/profile/edit");
		} else {
			return $redirect_to;
		}
	}

	/**
	 * @param $userId
	 * @param array $groupsData
	 * @return null
	 */
	protected function manageGroups($userId, array $groupsData) {
		global $wpdb, $bp;

		// Get BP groups with OIDs and their associated BP group IDs.
		$custom_groups = $wpdb->get_results($wpdb->prepare('SELECT group_id, meta_value FROM ' . $bp->groups->table_name_groupmeta . ' WHERE meta_key = %s', 'mla_oid'));

		// Make an efficient copy of the user's groups.
		$user_groups = array();
		foreach($groupsData as $groupData) {
			$user_groups[$groupData['oid']] = $groupData;
		}

		// Loop through each BP group and add/remove/promote/demote the user as needed.
		foreach($custom_groups as $custom_group) {

			$groupId = $custom_group->group_id;
			$customOid = $custom_group->meta_value;

			if(isset($user_groups[$customOid])) {

				// Copy user group data and delete it.
				$groupData = $user_groups[$customOid];
				unset($user_groups[$customOid]);

				// No-Op if user is already a member
				groups_join_group($groupId, $userId);

				// If a user has the role 'chair', 'liaison', 'liason' [sic],
				// 'secretary', 'executive', or 'program-chair', then promote
				// the user to admin. Otherwise, demote the user.
				bp_update_is_item_admin(true, 'groups');
				if(isset($groupData['role']) && ($groupData['role'] == 'chair' || $groupData['role'] == 'liaison' || $groupData['role'] == 'liason' || $groupData['role'] == 'secretary' || $groupData['role'] == 'executive' || $groupData['role'] == 'program-chair')) {
					groups_promote_member($userId, $groupId, 'admin');
				} else {
					groups_demote_member($userId, $groupId);
				}

			} elseif(!$this->isForumGroup($customOid)) {
				// Remove the user from the group.
				groups_leave_group($groupId, $userId);
			}
		}

		// Groups still in the user_groups array need to be created.
		foreach($user_groups as $groupData) {

			$newGroup = array(
				'slug' => groups_check_slug(sanitize_title_with_dashes($groupData['name'])),
				'name' => $groupData['name'],
				'status' => $groupData['status'],
			);
			$groupId = groups_create_group($newGroup);
			groups_update_groupmeta($groupId, 'mla_oid', $groupData['oid']);
			groups_join_group($groupId, $userId);

			// If a user has the role 'chair', 'liaison', 'liason' [sic],
			// 'secretary', 'executive', or 'program-chair', then promote
			// the user to admin. Otherwise, demote the user.
			if(isset($groupData['role']) && ($groupData['role'] == 'chair' || $groupData['role'] == 'liaison' || $groupData['role'] == 'liason' || $groupData['role'] == 'secretary' || $groupData['role'] == 'executive' || $groupData['role'] == 'program-chair')) {
				groups_promote_member($userId, $groupId, 'admin');
			}

		}

		return null;
	}

	/**
	 * @param $userId
	 * @param $customUserId
	 * @return null
	 */
	protected function specialCases($userId, $customUserId) {
		global $wpdb, $bp;

		switch($customUserId) {
			case 67613:
				// Add the user to some groups.
				foreach(Array(310, 321, 322) as $groupId) {
					groups_join_group($groupId, $userId);
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
	protected function findCustomUser($username, $password) {
		//$url = $this->apiMembersUrl.'information'.$this->startQueryString($username, $password);
		//$xmlResponse = $this->runCurl($url);
		//$this->log($xmlResponse, $url);

		_log( 'now in findCustomUser()' ); 
		$request_method = 'GET';
		$query_domain = 'members';
		// this is for queries that come directly after the query domain,
		// like https://apidev.mla.org/1/members/168880
		$simple_query = '/' . $username;
		$base_url = 'https://apidev.mla.org/1/' . $query_domain . $simple_query;
		$response = $this->send_request( $request_method, $base_url, $query );
		//_log( 'response was:', $response ); 

		if( $response === false || $response == '' ) {
			// This only happens if we can't access the API server.
			error_log('Authentication Plugin: is API server down?');
			return new WP_Error('server_error', __('<strong>Error (' . __LINE__ . '):</strong> There was a problem verifying your member credentials. Please try again later.'));
		}

		if ( ! array_key_exists( 'code', $response ) ) { 
			error_log('Authentication Plugin: is API server down?');
			return new WP_Error('server_error', __('<strong>Error (' . __LINE__ . '):</strong> There was a problem verifying your member credentials. Please try again later.'));
		} else if ( $response['code'] != 200 ) { 
			_log('Authentication Plugin: got request error. Here\'s what the server said:');
			_log( $response );
			return new WP_Error('server_error', __('<strong>Error (' . __LINE__ . '):</strong> There was a problem verifying your member credentials. Please try again later.'));
		} 

		try {
			$decoded = json_decode( $response['body'], true );
		} catch ( Exception $e ) {
			_log( 'Authentication Plugin: couldn\'t decode JSON response from server. Response was:', $response );
			return new WP_Error('server_error', __('<strong>Error (' . __LINE__ . '):</strong> Because of a temporary problem, we cannot verify your member credentials at this time. Please try again in a few minutes.'));
		}

		//_log( 'Decoded JSON is: ', $decoded );

		if ( $decoded['meta']['status'] != 'success' ) {
			_log( 'Authentication plugin: member lookup was not a success. Server says:', $decoded->meta ); 
			return new WP_Error('not_authorized', sprintf(__('<strong>Error (' . __LINE__ . '):</strong> Your user name and password could not be verified. Please try again.'), wp_lostpassword_url()));
		}

		$json_data = $decoded['data']; 

		if ( sizeof( $json_data ) > 1 ) { 
			_log( 'Authentication plugin: there was more than one response for that username. This should never happen. Responses:', $json_data ); 
			return new WP_Error('not_authorized', sprintf(__('<strong>Error (' . __LINE__ . '):</strong> Your user name and password could not be verified. Please try again.'), wp_lostpassword_url()));
		} 

		$json_data = $json_data[0]; // There should only be one now. 

		//Authenticate with password!
		$our_password = crypt( $password, $json_data['authentication']['password'] ); // salt it and hash it appropriately 
		$their_password = $json_data['authentication']['password']; 
		_log( "encrypted password:", crypt($password, $json_data['authentication']['password']) ); 
		_log( "API password:", $json_data['authentication']['password'] ); 

		if ( $our_password != $their_password ) { 
			_log( 'Passwords do not match!' ); 
			return new WP_Error('not_authorized', sprintf(__('<strong>Error (' . __LINE__ . '):</strong> Your user name and password could not be verified. Please try again.'), wp_lostpassword_url()));
		} 

		$json_member_data = $decoded['data'][0]; 

		$json_array = $this->memberJSONToArray( $json_member_data, $password );

		_log( '$json_array is as follows', $json_array ); 

		// Make sure the user is active and of the allowed types (i.e. 'member')
		if(!$this->validateCustomUser($json_array, $username, $error)) {
			return $error;
		}

		return $json_array;

	}

	protected function mergeWpUser($userId, $member) {
		wp_update_user( array('ID' => $userId, 'user_email' => $member['email']) );
		update_user_meta($userId, 'languages', $member['languages']);
		update_user_meta($userId, 'affiliations', $member['affiliations']);
		update_user_meta($userId, 'mla_oid', $member['id']);
	}

	/**
	 * Takes the member data and creates a WP user.
	 *
	 * @param $member
	 * @return WP_Error|WP_User
	 */
	protected function createWpUser($member) {
		if($_POST['preferred'] != '' && $member['user_name'] != $_POST['preferred']) {
			$result = $this->changeCustomUsername($member['user_name'], $member['password'], $_POST['preferred']);
			if(!$result instanceof WP_Error) {
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
		$userId = wp_insert_user($newUserData);
		if($userId instanceof WP_Error) {
			return $userId;
		}

		return new WP_User($userId);
	}

	protected function changeCustomUsername($username, $password, $newname) {

		// If the new username is the same as the old, we can save ourselves
		// a little bit of effort.  
		if ( $newname == $username ) return true; 

		// First we need to get the user ID from the MLA API, 
		// because the API can't look up users by username, 
		// and we don't have the user's ID now.
		// It sucks that we can't pass the User ID, but an AJAX function
		// that calls this one doesn't have access to it. 
		$customUserData = $this->findCustomUser($username, $password);

		$user_id = $customUserData['id']; 

		// now we change the username
		$request_method = 'PUT';
		$query_domain = 'members';
		$simple_query = '/' . $user_id . '/username'; 
		$base_url = 'https://apidev.mla.org/1/' . $query_domain . $simple_query;
		$request_body = "{ \"username\": \"$newname\" }"; 
		_log( 'changing username with params: ' ); 
		_log( 'base_url: ', $base_url ); 
		_log( 'request body: ', $request_body );
		$response = $this->send_request( $request_method, $base_url, '', $request_body) ;

		_log( 'changing username. API response was:', $response ); 

		if ( ( ! is_array( $response ) ) || ( ! array_key_exists( 'code', $response ) ) ) {
			// This only happens if we can't access the API server.
			error_log('Authentication Plugin: is API server down?');
			_log( 'On changing username, API gave a non-array response. Something is terribly wrong!' ); 
			return new WP_Error('server_error', __('<strong>Error (' . __LINE__ . '):</strong> There was a problem changing your username. Please try again later.'));
		}

		if ( 200 != $response['code'] ) { 
			_log( 'On changing username, API gave a non-200 response. Something is kind of wrong!' ); 
			_log( 'Response: ', $response ); 
			return new WP_Error('server_error', __('<strong>Error (' . __LINE__ . '):</strong> There was a problem changing your username. Please try again later.'));
		} 
		
		return true; 
	}


	/**
	 * Turns member xml data from the membership API
	 * into a readable array.
	 *
	 * @param $xml
	 * @param $password
	 * @return array
	 */
	protected function memberJSONToArray( $json, $password ) {
		$languages = array();
		$affiliations = array();
		$groups = array();
		$affiliations = array(); 

		foreach ( $json['addresses'] as $address ) { 
			if ( array_key_exists( 'affiliation', $address ) ) { 
				$affiliations[] = $address['affiliation']; 
			} 
		} 

		foreach ( $json['organizations'] as $group ) { 
			//_log( 'here comes a group!', $group ); 
			$groups[] = array( 
				//[oid] => G049
				//[role] => 
				//[name] => Age Studies
				//[status] => public
				'oid'  => $group['convention_code'],
				'role' => $group['position'], 
				'type' => $group['type'], 
				'name' => $group['name'], 
				// @todo how to get status? 
			); 
		} 

		return array(
			'id' => $json['id'],
			'user_name' => $json['authentication']['username'],
			'status' => $json['authentication']['membership_status'],
			// ??? 
			//'type' => trim($xml->member->type),
			'password' => $password,
			'email' => trim($json['general']['email']),
			'first_name' => trim( $json['general']['first_name'] ),
			'last_name' => trim( $json['general']['last_name'] ),
			'display_name' => trim($json['general']['first_name'] . ' ' . $json['general']['last_name']),
			'website' => trim($json['general']['web_site']),
			'languages' => $json['languages'],
			'affiliations' => $affiliations,
			'groups' => $groups,
			'role' => 'subscriber',
		);
	}

	/**
	 * Pulls group info from the xml element
	 *
	 * @param array $groups
	 * @param $xmlElement
	 */
	private function extractGroups(array &$groups, $xmlElement) {
		foreach($xmlElement as $item) {
			$attrs = $item->attributes();
			$groups[] = array(
				'oid' => (string) $attrs['oid'],
				'role' => (string) $attrs['role'],
				'name' => (string) $item[0],
				'status' => $this->isDivisionOrDiscussionGroup($attrs['oid']) ? 'public' : 'private',
			);
		}
	}

	/**
	 * Makes sure that the user is
	 * 1) The same user we asked for.
	 * 2) An active user.
	 *
	 * @param $member
	 * @param $id
	 * @param null $error
	 * @return bool
	 */
	protected function validateCustomUser($member, $id, &$error = null) {

		//_log( "attempting to validate member with id $id. Member is:", $member ); 

		if($id !== $member['id'] && $id !== strtolower($member['user_name'])) {
			// This should not happen since the API gives us the member based on the ID or Username. Nonetheless, it's worth checking.
			$error = new WP_Error('server_error', __('<strong>Error (' . __LINE__ . '):</strong> There was a problem verifying your member credentials. Please try again later.'));
			return false;
		}
		if($member['status'] !== 'active') {
			$error = new WP_Error('server_error', __('<strong>Error (' . __LINE__ . '):</strong> Your membership is not active.'));
			return false;
		}

		return true;
	}




	# GROUP MANAGEMENT FUNCTIONS (PHASE 2)
	#####################################################################

	public function activate() {
		$allGroups = groups_get_groups(array('per_page' => null));

		foreach($allGroups['groups'] as $group) {
			$group_custom_oid = groups_get_groupmeta($group->id, 'mla_oid', true);
			if(empty($group_custom_oid)) {
				continue;
			}
			if($this->isDivisionOrDiscussionGroup($group_custom_oid)) {
				$this->changeGroupStatus($group, 'public');
				continue;
			}
			if($this->isCommitteeGroup($group_custom_oid)) {
				$this->changeGroupStatus($group, 'private');
			}
		}
	}

	/**
	 * Allows you to change a groups status (i.e. private or public)
	 *
	 * @param stdClass $group
	 * @param $status
	 */
	private function changeGroupStatus(stdClass $group, $status) {
		global $wpdb, $bp;

		if($group->status != $status) {
			// Have to use SQL because buddypress isn't all set up at this point
			$wpdb->query($wpdb->prepare("UPDATE ".$bp->groups->table_name." SET status=%s WHERE id=%d", $status, $group->id));
		}
	}

	/**
	 * A filter to hide the request membership tab for committee groups
	 *
	 * @param $string
	 */
	public function hide_request_membership_tab($string) {
		global $groups_template;

		// Get group info
		$group =& $groups_template->group;
		$group_custom_oid = groups_get_groupmeta($group->id, 'mla_oid', true);

		// Don't show request membership if it's an MLA group (probably a committee, since only committees are private)
		if(!empty($group_custom_oid) && ($this->isDivisionOrDiscussionGroup($group_custom_oid) || $this->isCommitteeGroup($group_custom_oid))) {
			return;
		}

		return $string;
	}
	/**
	 * A filter to hide the send invites tab for committee groups
	 *
	 * @param $string
	 */
	public function hide_send_invites_tab($string) {
		global $groups_template;

		// Get group info
		$group =& $groups_template->group;
		$group_custom_oid = groups_get_groupmeta($group->id, 'mla_oid', true);

		// Don't show request membership if it's an MLA group (probably a committee, since only committees are private)
		if(!empty($group_custom_oid) && $this->isCommitteeGroup($group_custom_oid)) {
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
	public function determine_group_settings_sections(array $allowed) {
		global $groups_template;

		// Get group info
		$group =& $groups_template->group;
		$group_custom_oid = groups_get_groupmeta($group->id, 'mla_oid', true);

		// Don't show privacy or invitation settings if it's an MLA group
		if(!empty($group_custom_oid) && ($this->isDivisionOrDiscussionGroup($group_custom_oid) || $this->isCommitteeGroup($group_custom_oid))) {
			return;
		}

		// Show all settings
		$allowed['privacy'] = true;
		$allowed['invitations'] = true;
		return $allowed;
	}


	/**
	 * Only show the join/login button if the group is not a committee
	 * and the member is not an administrator of the
	 * division or discussion group.
	 *
	 * @param bool $group
	 */
	public function hide_join_button($group = false) {
		global $groups_template;

		// Remove the other actions that would create this button
		$priority = has_action('bp_group_header_actions', 'bp_group_join_button');
		remove_action('bp_group_header_actions', 'bp_group_join_button', $priority);
		$priority = has_action('bp_directory_groups_actions', 'bp_group_join_button');
		remove_action('bp_directory_groups_actions', 'bp_group_join_button', $priority);

		// Look up group info
		if(empty($group)) {
			$group =& $groups_template->group;
		}
		$group_custom_oid = groups_get_groupmeta($group->id, 'mla_oid', true);

		// Get user id
		$user_id = bp_loggedin_user_id();

		// Render as normal if it's not an MLA group or it's a division or discussion group and user is not admin of group
		if(empty($group_custom_oid) || $this->isForumGroup($group_custom_oid) || ($group_custom_oid && $this->isDivisionOrDiscussionGroup($group_custom_oid) && !groups_is_user_admin($user_id, $group->id)) ) {
			return bp_group_join_button($group);
		}


	}

	/**
	 * Remove user from the group in the MLA database
	 *
	 * @param int $group_id
	 * @param int $user_id
	 */
	public function remove_user_from_group($group_id, $user_id = 0) {
		$this->send_group_action('DELETE', $group_id, $user_id);
	}

	/**
	 * Add the user to the group in the MLA database
	 *
	 * @param int $group_id
	 * @param int $user_id
	 */
	public function add_user_to_group($group_id, $user_id = 0) {
		$this->send_group_action('POST', $group_id, $user_id);
	}

	/**
	 * Sends post data to the API to manage group memberships
	 *
	 * @param string $method
	 * @param int $group_id
	 * @param int $user_id
	 */
	protected function send_group_action($method, $group_id, $user_id = 0) {
		// Get user and group info
		if (empty($user_id)) {
			$user_id = bp_loggedin_user_id();
		}
		$user_custom_oid = get_user_meta($user_id, 'mla_oid', true);
		$group_custom_oid = groups_get_groupmeta($group_id, 'mla_oid', true);

		// Can't do anything if the user or group isn't in the MLA database
		if(empty($group_custom_oid) || empty($user_custom_oid)) {
			_log( 'no group MLA OID or user OID! Can\'t perform this request. ' );
			_log( 'user_custom_oid is:', $user_custom_oid );
			_log( 'group_custom_oid is:', $group_custom_oid );
			return;
		}

		// Only division and discussion groups should be reflected in the MLA database
		if(!$this->isDivisionOrDiscussionGroup($group_custom_oid)) {
			_log( 'not a division or discussion group!' ); 
			return;
		}

		// Don't try to do anything that uses a method we're not prepared for. 
		if ( ( 'POST' != $method ) && ( 'DELETE' != $method ) ) { 
			_log( 'not a recognized method!' ); 
			_log( 'method is:', $method );
			return;
		} 

		// New API Endpoints
		$query_domain = 'members';
		// this is for queries that come directly after the query domain,
		// like https://apidev.mla.org/1/members/168880
		$simple_query = '/' . $user_custom_oid . '/organizations';
		$query = array( 'items' => $group_custom_oid ); 
		$base_url = 'https://apidev.mla.org/1/' . $query_domain . $simple_query;
		_log( 'now sending requests with params:' ); 
		_log( 'base_url is:', $base_url );
		_log( 'query is:', $query );
		$response = $this->send_request( $method, $base_url, $query );
		_log( 'response from API is:', $response ); 
	}




	# UTILITY FUNCTIONS
	#####################################################################

	/**
	 * Determine if the group is a division or discussion
	 *
	 * @param string $group_custom_oid
	 * @return bool
	 */
	protected function isDivisionOrDiscussionGroup($group_custom_oid) {
		$flag = substr($group_custom_oid, 0, 1);
		if($flag != "D" && $flag != "G") {
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
	protected function isCommitteeGroup($group_custom_oid) {
		$flag = substr($group_custom_oid, 0, 1);
		if($flag != "M") {
			return false;
		}
		return true;
	}

	/**
	 * Determine if the group is a prospective forum
	 *
	 * @param string $group_custom_oid
	 * @return bool
	 */
	protected function isForumGroup($group_custom_oid) {
		$flag = substr($group_custom_oid, 0, 1);
		if($flag != "F") {
			return false;
		}
		return true;
	}

	/**
	 * A logging function for production environments
	 *
	 * @param $msg
	 * @param $url
	 */
	public static function log($msg, $url) {
		if(CBOX_AUTH_DEBUG && CBOX_AUTH_DEBUG_LOG) {
			$time = round(microtime(true) * 1000);
			$rand = rand();
			$hash = md5($msg);
			$filename = str_replace(array('%t','%r','%h'), array($time, $rand, $hash), MLA_DEBUG_LOG);

			if(is_array($msg) || is_object($msg)) {
				$msg = print_r($msg, true);
			}
			$date = new DateTime('now');
			$format = $date->format(DATE_COOKIE);
			$msg = "AUTH LOG: $format \nREQUEST: $url\n\n$msg \n";
			file_put_contents($filename, $msg, FILE_APPEND);
		}
	}

	/**
	 * A logging function for development environments
	 *
	 * @param $msg
	 */
	protected function l($msg) {
		if(WP_DEBUG) {
			if(is_object($msg) || is_array($msg)) {
				error_log(print_r($msg, true));
			} else {
				error_log($msg);
			}
		}
	}
}
?>
