<?php
/**
 * This is the base layer, which contains functions for directly communicating
 * with the MLA API. It's abstracted away from the MLAAPI class, so that MLAAPI
 * class can handle functions that are common to MLAGroup and MLAMember, but that
 * the functions for actually communicating are here. This way, we can rewrite
 * MLAAPIRequest in our tests and fill it with mock data, and not also have to
 * rewrite MLAAPI.
 */
class MLAAPIRequest {

	private $api_url = CBOX_AUTH_API_URL;

	/*
	 * sendRequest
	 * -----------
	 * Send a RESTful request to the API.
	 */
	public function send_request( $http_method, $base_url, $parameters = array(), $request_body = '' ) {

		$api_key = CBOX_AUTH_API_KEY;
		$api_secret = CBOX_AUTH_API_SECRET;

		// Append current time to request parameters (seconds from UNIX epoch).
		$parameters['key'] = $api_key;
		$parameters['timestamp'] = time();

		// Sort the request parameters.
		ksort( $parameters );

		// Collapse the parameters into a URI query string.
		$query_string = http_build_query( $parameters, '', '&' );

		// Add the request parameters to the base URL.
		$request_url = $base_url . '?' . $query_string;

		// Compute the request signature (see specification).
		$hash_input = $http_method . '&' . rawurlencode( $request_url );
		$api_signature = hash_hmac( 'sha256', $hash_input, $api_secret );

		// Append the signature to the request.
		$request_url = $request_url . '&signature=' . $api_signature;

		$this->debug = 'verbose'; // turning on debugging output

		if ( 'verbose' === $this->debug ) { _log( 'Using request:', $request_url ); }

		// Initialize a cURL session.
		$ch = curl_init();
		$headers = array( 'Accept: application/json' );

		// Set cURL options.
		curl_setopt( $ch, CURLOPT_URL, $request_url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FAILONERROR, false );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );

		// Set HTTP method.
		if ( 'PUT' === $http_method || 'DELETE' === $http_method ) {
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $http_method );
		} elseif ( 'POST' === $http_method ) {
			curl_setopt( $ch, CURLOPT_POST, 1 );
		}

		// Add request body.
		if ( strlen( $request_body ) ) {
			$headers[] = 'Content-Length: ' . strlen( $request_body );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $request_body );
		}

		// Add HTTP headers.
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

		// Send request.
		$response_text = curl_exec( $ch );

		// Describe error if request failed.
		if ( ! $response_text ) {
			$response = array(
				'code' => '500',
				'body' => curl_error( $ch ),
			);
		} else {
			$response = array(
				'code' => curl_getinfo( $ch, CURLINFO_HTTP_CODE ),
				'body' => $response_text,
			);
		}
		// Close cURL session.
		curl_close( $ch );
		return $response;
	}

	/**
	 * Get a member from the member database API.
	 * @param $username can be either ID number (e.g. 168880)
	 * or username (e.g. commonstest).
	 * @return response. Can be false, blank, or a member.
	 * @todo: put this in MLAMember? Factor out base URL to make it easier to switch to production?
	 */
	public function get_member( $username ) {
		$this->debug = 'verbose';
		if ( 'verbose' === $this->debug ) { _log( 'Now getting the member from the API.' ); }
		$username = urlencode( $username );
		$response = $this->send_request( 'GET', $this->api_url . 'members/' . $username );
		if ( 'verbose' === $this->debug ) { _log( 'API response was:', $response ); }
		return $response;
	}

	public function get_mla_group_data_from_api() {
		$http_method = 'GET';
		$simple_query = 'organizations/' . $this->group_mla_api_id;
		$request_url = $this->api_url . $simple_query;
		$params = array( 'joined_commons' => 'Y' );
		$response = $this->send_request( $http_method, $request_url, $params );

		return $response;
	}

	/**
	 * Remove user from the group in the MLA database
	 *
	 * @param int $group_id
	 * @param int $user_id
	 */
	public function remove_user_from_group( $group_id, $user_id = 0 ) {
		if ( 'verbose' == $this->debug ) { _log( 'Now attempting to remove user from group!' ); }
		$this->send_group_action( 'DELETE', $group_id, $user_id );
	}

	/**
	 * Add the user to the group in the MLA database
	 *
	 * @param int $group_id
	 * @param int $user_id
	 */
	public function add_user_to_group( $group_id, $user_id = 0 ) {
		if ( 'verbose' === $this->debug ) { _log( 'Now attempting to add user from group!' ); }
		$this->send_group_action( 'POST', $group_id, $user_id );
	}

	/**
	 * Sends post data to the API to manage group memberships
	 *
	 * @param string $method
	 * @param int    $group_id
	 * @param int    $user_id
	 */
	protected function send_group_action( $method, $group_id, $user_id = 0 ) {
		// Get user and group info
		if ( empty( $user_id ) ) {
			$user_id = bp_loggedin_user_id();
		}
		$user_custom_oid = get_user_meta( $user_id, 'mla_oid', true );
		$group_custom_oid = groups_get_groupmeta( $group_id, 'mla_oid', true );

		// Can't do anything if the user or group isn't in the MLA database
		if ( empty( $group_custom_oid ) || empty( $user_custom_oid ) ) {
			_log( 'no group MLA OID or user OID! Can\'t perform this request. ' );
			_log( 'user_custom_oid is:', $user_custom_oid );
			_log( 'group_custom_oid is:', $group_custom_oid );
			return;
		}

		// Only division and discussion groups should be reflected in the MLA database
		if ( ! $this->is_division_or_discussion_group( $group_custom_oid ) ) {
			_log( 'not a division or discussion group!' );
			return;
		}

		// Don't try to do anything that uses a method we're not prepared for.
		if ( ( 'POST' !== $method ) && ( 'DELETE' !== $method ) ) {
			_log( 'not a recognized HTTP method!' );
			_log( 'method is:', $method );
			return;
		}

		// New API Endpoints
		$query_domain = 'members';
		// this is for queries that come directly after the query domain,
		// like https://apidev.mla.org/1/members/168880
		$simple_query = '/' . $user_custom_oid . '/organizations';
		$query = array( 'items' => $group_custom_oid );
		$base_url = $this->api_url . $query_domain . $simple_query;
		// There needs to be a request body for POSTs to work.
		if ( 'POST' === $method ) {
			$body = '{"":""}';
		} else {
			$body = '';
		}

		if ( 'verbose' === $this->debug ) {
			_log( 'now sending requests with params:' );
			_log( 'method is:', $method );
			_log( 'base_url is:', $base_url );
			_log( 'query is:', $query );
			_log( 'body is:', $body );
		}
		$response = $this->send_request( $method, $base_url, $query, $body );
		if ( 'verbose' === $this->debug ) { _log( 'response from API is:', $response ); }
	}

	protected function change_custom_username( $username, $password, $newname ) {

		// If the new username is the same as the old, we can save ourselves
		// a little bit of effort.
		if ( $newname === $username ) { return true; }

		// First we need to get the user ID from the MLA API,
		// because the API can't look up users by username,
		// and we don't have the user's ID now.
		// It sucks that we can't pass the User ID, but an AJAX function
		// that calls this one doesn't have access to it.
		$customUserData = $this->find_custom_user( $username, $password );

		$user_id = $customUserData['id'];

		// now we change the username
		// @todo refactor this.
		$request_method = 'PUT';
		$query_domain = 'members';
		$simple_query = '/' . $user_id . '/username';
		$base_url = $this->api_url . $query_domain . $simple_query;
		$request_body = "{ \"username\": \"$newname\" }";

		if ( 'verbose' === $this->debug ) {
			_log( 'Changing username with params: ' );
			_log( 'base_url: ', $base_url );
			_log( 'request body: ', $request_body );
		}

		$response = $this->send_request( $request_method, $base_url, '', $request_body );

		if ( 'verbose' === $this->debug ) { _log( 'changing username. API response was:', $response ); }

		if ( ( ! is_array( $response ) ) || ( ! array_key_exists( 'code', $response ) ) ) {
			// This only happens if we can't access the API server.
			error_log( 'Authentication Plugin: is API server down?' );
			_log( 'On changing username, API gave a non-array response. Something is terribly wrong!' );
			return new WP_Error( 'server_error', __( '<strong>Error (' . __LINE__ . '):</strong> There was a problem changing your username. Please try again later.' ) );
		}

		if ( 200 !== $response['code'] ) {
			_log( 'On changing username, API gave a non-200 response. Something is kind of wrong!' );
			_log( 'Response: ', $response );
			return new WP_Error( 'server_error', __( '<strong>Error (' . __LINE__ . '):</strong> There was a problem changing your username. Please try again later.' ) );
		}

		return true;
	}
}
