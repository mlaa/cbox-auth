<?php
class CustomAuthentication {

	// Seconds before curl times out
	protected $curlTimeout = 10;

	// The base url to the membership API
	protected $apiMembersUrl = 'https://www.mla.org/api/1/members/';
	protected $apiGroupsUrl = 'https://www.mla.org/api/1/groups/';




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
		$url = $this->apiMembersUrl.'information'.$this->startQueryString($username, $password);
		$xmlResponse = $this->runCurl($url);
		$this->log($xmlResponse, $url);

		if($xmlResponse === false || $xmlResponse == '') {
			// This only happens if we can't access the API server.
			error_log('Authentication Plugin: is API server down?');
			return new WP_Error('server_error', __('<strong>Error (' . __LINE__ . '):</strong> There was a problem verifying your member credentials. Please try again later.'));
		}

		try {
			$xml = new SimpleXMLElement($xmlResponse);
		}catch (Exception $e) {
			error_log('Authentication Plugin: is API server down?');
			return new WP_Error('server_error', __('<strong>Error (' . __LINE__ . '):</strong> Because of a temporary problem, we cannot verify your member credentials at this time. Please try again in a few minutes.'));
		}

		if($xml->getName() === 'errors') {
			return new WP_Error('not_authorized', sprintf(__('<strong>Error (' . __LINE__ . '):</strong> Your user name and password could not be verified. Please try again.'), wp_lostpassword_url()));
		}

		if($xml->getName() !== 'members') {
			// This should not happen. The only appropriate API responses are 'errors' or 'members'
			error_log('Authentication Plugin: is API server down?');
			return new WP_Error('server_error', __('<strong>Error (' . __LINE__ . '):</strong> There was a problem verifying your member credentials. Please try again later.'));
		}

		$xmlReturn = $this->memberXmlToArray($xml, $password);

		// Make sure the user is active and of the allowed types (i.e. 'member')
		if(!$this->validateCustomUser($xmlReturn, $username, $error)) {
			return $error;
		}

		return $xmlReturn;

	}

	protected function mergeWpUser($userId, $member) {
		wp_update_user( array('ID' => $userId, 'user_email' => $member['email']) );
		update_user_meta($userId, 'languages', $member['languages']);
		update_user_meta($userId, 'affiliations', $member['affiliations']);
		update_user_meta($userId, 'mla_oid', $member['id']);

		// import member data into xprofile fields
		$affiliation_field_id = xprofile_get_field_id_from_name( 'Institutional or Other Affiliation' ); 
		$title_field_id = xprofile_get_field_id_from_name( 'Title' ); 
		
		// map the MLA member XML value to the xprofile field ID. 
		$mla_xprofile_import_map = array( 
			'affiliations' => $affiliation_field_id,
			'rank' => $title_field_id,
		); 

		// check to see if "Title" and "Affiliation" xprofile fields are empty.
		// if so, import this data from the MLA member database. 
		foreach ( $mla_xprofile_import_map as $from_meta => $to_xprofile_field ) { 	
			if ( ! ( xprofile_get_field_data( $to_xprofile_field ) ) ) { 
				$user_meta_value = bp_get_user_meta( $userId, $from_meta );
				$out_data = $user_meta_value[0]; // get the first affiliation or rank
				if ( 'array' == gettype( $out_data ) ) { 
					$out_data = $out_data[0]; // some affiliations are in 2-D arrays
				} 
				if ( $to_xprofile_field && $userId && $out_data ) { 
					xprofile_set_field_data( $to_xprofile_field, $userId, $out_data ); 
				} 
			} 
		} 
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
		$url = $this->apiMembersUrl.'user-name'.$this->startQueryString($username, $password)."&new_user_name=$newname";
		$xmlResponse = $this->runCurl($url);
		$this->log($xmlResponse, $url);

		if($xmlResponse === false || $xmlResponse == '') {
			// This only happens if we can't access the API server.
			error_log('Authentication Plugin: is API server down?');
			return new WP_Error('server_error', __('<strong>Error (' . __LINE__ . '):</strong> There was a problem verifying your member credentials. Please try again later.'));
		}

		try {
			$xml = new SimpleXMLElement($xmlResponse);
		}catch (Exception $e) {
			error_log('Authentication Plugin: is API server down?');
			return new WP_Error('server_error', __('<strong>Error (' . __LINE__ . '):</strong> There was a problem verifying your member credentials. Please try again later.'));
		}

		if($xml->getName() === 'errors') {
			$attrs = $xml->error->attributes();
			$message = $attrs['message'];
 			return new WP_Error('name_change_error',  __('<strong>Error (' . __LINE__ . '):</strong> '.$message));
		}
		if($xml->getName() === 'members') {
			return true;
		}
		return false;
	}


	/**
	 * Turns member xml data from the membership API
	 * into a readable array.
	 *
	 * @param $xml
	 * @param $password
	 * @return array
	 */
	protected function memberXmlToArray($xml, $password) {
		$memberAttrs = $xml->member->attributes();
		$languages = array();
		$affiliations = array();
		$groups = array();
		foreach($xml->member->languages->language as $language) {
			$languages[] = ''.$language[0];
		}
		foreach($xml->member->affiliations->affiliation as $affiliation) {
			$affiliations[] = ''.$affiliation[0];
		}
		$this->extractGroups($groups, $xml->member->committees->committee);
		$this->extractGroups($groups, $xml->member->divisions->division);
		$this->extractGroups($groups, $xml->member->discussions->discussion);


		return array(
			'id' => trim($memberAttrs['oid']),
			'user_name' => trim($xml->member->user_name),
			'status' => trim($xml->member->status),
			'type' => trim($xml->member->type),
			'password' => $password,
			'email' => trim($xml->member->email_address),
			'first_name' => trim($xml->member->name->first_names),
			'last_name' => trim($xml->member->name->surname),
			'display_name' => trim($xml->member->name->first_names.' '.$xml->member->name->surname),
			'website' => trim($xml->member->website),
			'languages' => $languages,
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
		$this->send_group_action('remove', $group_id, $user_id);
	}

	/**
	 * Add the user to the group in the MLA database
	 *
	 * @param int $group_id
	 * @param int $user_id
	 */
	public function add_user_to_group($group_id, $user_id = 0) {
		$this->send_group_action('add', $group_id, $user_id);
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
			return;
		}

		// Only division and discussion groups should be reflected in the MLA database
		if(!$this->isDivisionOrDiscussionGroup($group_custom_oid)) {
			return;
		}

		$time = time();
		$data = array(
			"method" => $method,
			"user_id" => $user_custom_oid,
			"timestamp" => $time,
			"signature" => hash_hmac('sha256', "$user_custom_oid:$group_custom_oid:$time", CBOX_AUTH_GROUPS_SECRET_TOKEN)
		);

		$url = $this->apiGroupsUrl."$group_custom_oid/members";
		$result = $this->postCurl($url, $data);
		$this->log("POST: " . print_r($data, true) . "\n\n" . $result, $url);
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
	 * Gets data from a URL using cUrl
	 *
	 * @param $url
	 * @return mixed
	 */
	protected function runCurl($url) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->curlTimeout);
		$data = curl_exec($ch);
		curl_close($ch);
		return $data;
	}

	/**
	 * Sends a post request to the given url
	 *
	 * @param string $url
	 * @param array $data
	 * @return mixed
	 */
	protected function postCurl($url, array $data) {

		// Build query string
		$query = "";
		foreach($data as $key => $value) {
			$query .= "$key=$value&";
		}
		$query = rtrim($query, '&');

		// Send post
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, count($data));
		curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->curlTimeout);
		$result = curl_exec($ch);
		curl_close($ch);
		return $result;
	}

	/**
	 * Adds the appropriate parameters to the membership API URL.
	 *
	 * @param $username Can be a username or ID
	 * @param $password
	 * @return string
	 */
	protected function startQueryString($username, $password) {
		$time = time();
		$signature = hash_hmac('sha256', "$username:$password:$time", CBOX_AUTH_SECRET_TOKEN);
		return "?user_id=$username&timestamp=$time&signature=$signature";
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
			$filename = str_replace(array('%t','%r','%h'), array($time, $rand, $hash), CBOX_AUTH_DEBUG_LOG);

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
