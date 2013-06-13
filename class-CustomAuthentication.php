<?php
class CustomAuthtentication {

	// Seconds before curl times out
	protected $curlTimeout = 10;

	// The base url to the authentication API
	protected $apiUrl = 'https://www.example.com/users/';


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

		// Get the user from the database. If the user doesn't exist
		// or the username/password is wrong, return the error
		$customUserData = $this->findCustomUser($username, $password);
		$customLoginError = null;
		if($customUserData instanceof WP_Error) {
			// If the user is not an user, let's see if she is a WP admin.
			$customLoginError = $customUserData;
		}

		// Make sure the user is active and of the allowed types (i.e. 'member')
		if(!$customLoginError && !$this->validateCustomUser($customUserData, $username, $error)) {
			return $error;
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

		return $userdata;
	}

	private function setRememberCookie($value) {
		if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) {
			$secure_connection = true;
		} else {
			$secure_connection = false;
		}
		setcookie('AuthBeenHereBefore', md5($value), time() + (20 * 365 * 24 * 60 * 60), null, null, $secure_connection);
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
		$custom_groups = $wpdb->get_results($wpdb->prepare('SELECT group_id, meta_value FROM ' . $bp->groups->table_name_groupmeta . ' WHERE meta_key = %s', 'custom_oid'));

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

				// If a user has the role 'chair', 'liason' [sic],
				// 'secretary', or 'executive', then promote the
				// user will to admin. Otherwise, demote the user.
				bp_update_is_item_admin(true, 'groups');
				if(isset($groupData['role']) && ($groupData['role'] == 'chair' || $groupData['role'] == 'liason' || $groupData['role'] == 'secretary' || $groupData['role'] == 'executive')) {
					groups_promote_member($userId, $groupId, 'admin');
				} else {
					groups_demote_member($userId, $groupId);
				}

			} else {
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
			groups_update_groupmeta($groupId, 'custom_oid', $groupData['oid']);
			groups_join_group($groupId, $userId);

			// If a user has the role 'chair', 'liason' [sic],
			// 'secretary', or 'executive', then promote the
			// user will to admin. Otherwise, demote the user.
			if(isset($groupData['role']) && ($groupData['role'] == 'chair' || $groupData['role'] == 'liason' || $groupData['role'] == 'secretary' || $groupData['role'] == 'executive')) {
				groups_promote_member($userId, $groupId, 'admin');
			}

		}

		return null;
	}

	/**
	 * Use cUrl to find the member data from the Authentication API.
	 *
	 * The API gives one of two things: an error or member data. If
	 * we get member data, we convert it to a readable array.
	 *
	 * @param $username Again, this can be an username or ID.
	 * @param $password
	 * @return array|WP_Error
	 */
	protected function findCustomUser($username, $password) {
		$url = $this->apiUrl.'information'.$this->startQueryString($username, $password);
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

		return $this->memberXmlToArray($xml, $password);
	}

	protected function mergeWpUser($userId, $member) {
		update_user_meta($userId, 'languages', $member['languages']);
		update_user_meta($userId, 'affiliations', $member['affiliations']);
		update_user_meta($userId, 'custom_oid', $member['id']);
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
		$url = $this->apiUrl.'user-name'.$this->startQueryString($username, $password)."&new_user_name=$newname";
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
	 * Turns member xml data from the Authentication API
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
			'affiliations' => '',
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
				'status' => 'private',
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

		if($id !== $member['id'] && $id !== $member['user_name']) {
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
	 * Adds the appropriate parameters to the Authentication API URL.
	 *
	 * @param $username Can be an username or ID
	 * @param $password
	 * @return string
	 */
	protected function startQueryString($username, $password) {
		$time = time();
		$signature = hash_hmac('sha256', "$username:$password:$time", AUTHENTICATION_SECRET_TOKEN);
		return "?user_id=$username&timestamp=$time&signature=$signature";
	}

	public static function log($msg, $url) {
		if(AUTHENTICATION_DEBUG && AUTHENTICATION_DEBUG_LOG) {
			$time = round(microtime(true) * 1000);
			$rand = rand();
			$hash = md5($msg);
			$filename = str_replace(array('%t','%r','%h'), array($time, $rand, $hash), AUTHENTICATION_DEBUG_LOG);

			if(is_array($msg) || is_object($msg)) {
				$msg = print_r($msg, true);
			}
			$date = new DateTime('now');
			$format = $date->format(DATE_COOKIE);
			$msg = "AUTH LOG: $format \nREQUEST: $url\n\n$msg \n";
			file_put_contents($filename, $msg, FILE_APPEND);
		}
	}
}
?>
