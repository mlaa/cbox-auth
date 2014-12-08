<?php
/* This is an abstract class that contains methods for polling the MLA API.
 */
abstract class MLAAPI {

	/*
	 * sendRequest
	 * -----------
	 * Send a RESTful request to the API.
	 */
	public function send_request( $http_method, $base_url, $parameters = array(), $request_body = '' ) {

		// The `private.php` file contains API passwords.
		// It populates the variables $api_key and $api_secret.
		// @todo: put this in wp-config.php
		require( 'private.php' );

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

		_log( 'Using request:', $request_url );

		// Initialize a cURL session.
		$ch = curl_init();
		$headers = array('Accept: application/json');

		// Set cURL options.
		curl_setopt($ch, CURLOPT_URL, $request_url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FAILONERROR, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

		// Validate certificates.
		if ( substr($request_url, 0, 23) === "https://apidev.mla.org/" ) {
			// openssl x509 -in /path/to/self-signed.crt -text > self-signed.pem
			curl_setopt($ch, CURLOPT_CAINFO, getcwd() . '/wp-content/plugins/cbox-auth/ssl/self-signed.pem');
		} elseif ( substr($request_url, 0, 20) === "https://api.mla.org/" ) {
			curl_setopt($ch, CURLOPT_CAINFO, getcwd() . '/wp-content/plugins/cbox-auth/ssl/self-signed-production.pem');
		}
		//elseif ( substr($request_url, 0, 20) === "https://api.mla.org/" ):
		// curl_setopt($ch, CURLOPT_CAINFO, getcwd() . '/ssl/cacert.pem');

		// Set HTTP method.
		if ( $http_method === 'PUT' || $http_method === 'DELETE' ) {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $http_method);
		} elseif ($http_method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, 1);
		}

		// Add request body.
		if ( strlen($request_body) ) {
			$headers[] = 'Content-Length: ' . strlen($request_body);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
		}

		// Add HTTP headers.
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		// Send request.
		$response_text = curl_exec($ch);

		// Describe error if request failed.
		if ( !$response_text ) {
			$response = array(
				'code' => '500',
				'body' => curl_error($ch)
			);
		} else {
			$response = array(
				'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
				'body' => $response_text
			);
		}
		// Close cURL session.
		curl_close($ch);
		return $response;
	}

	/*
	 * Gets a BuddyPress group ID if given the group's MLA OID.
	 * @param $mla_oid str, the MLA OID, i.e. D086
	 * @return int BuddyPress group ID, i.e. 86
	 */
	public function get_group_id_from_mla_oid( $mla_oid ) {
		global $wpdb;
		$sql = "SELECT group_id FROM wp_bp_groups_groupmeta WHERE meta_key = 'mla_oid' AND meta_value = '$mla_oid'";
		// @todo use wp_cache_get or some other caching method
		$result = $wpdb->get_results( $sql );
		$group_id = $result[0]->group_id;
		return $group_id;
	}

	/*
	 * Gets a MLA user ID if given that user's WP/BP ID.
	 * @param $bp_user_id str, the user's WP/BP ID
	 * @return $mla_user_id str, the MLA OID for that user.
	 */
	public function get_mla_user_id_from_bp_user_id( $bp_user_id ) {
		global $wpdb;
		$sql = "SELECT meta_value FROM wp_usermeta WHERE meta_key = 'mla_oid' AND user_id = '$bp_user_id'";
		// @todo use wp_cache_get or some other caching method
		$result = $wpdb->get_results( $sql );
		$mla_user_id = $result[0]->meta_value;
		return $mla_user_id;
	}


	/**
	 * Translate MLA roles like 'chair', 'liaison,' 'mla staff', into
	 * the corresponding BP role, like 'admin', member.
	 *
	 * @param $mla_role str the MLA role, like 'chair', 'mla staff.'
	 * @return $bp_role str the BP role, like 'admin', 'member.'
	 */
	public function translate_mla_role( $mla_role ){
		$mla_admin_roles = array('chair', 'liaison', 'liason', 'secretary', 'executive', 'program-chair');

		if ( in_array( $mla_role, $mla_admin_roles ) ) {
			$bp_role = 'admin';
		} else {
			$bp_role = 'member';
		}
		return $bp_role;
	}
}
