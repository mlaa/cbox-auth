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

		if ( 'verbose' == $this->debug ) _log( 'Using request:', $request_url );

		// Initialize a cURL session.
		$ch = curl_init();
		$headers = array('Accept: application/json');

		// Set cURL options.
		curl_setopt( $ch, CURLOPT_URL, $request_url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FAILONERROR, false );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );

		// Validate certificates.
		if ( substr( $request_url, 0, 23 ) === 'https://apidev.mla.org/' ) {
			// openssl x509 -in /path/to/self-signed.crt -text > self-signed.pem
			curl_setopt( $ch, CURLOPT_CAINFO, plugin_dir_path( __FILE__ ) . 'ssl/self-signed.pem' );
		} elseif ( substr( $request_url, 0, 20 ) === 'https://api.mla.org/' ) {
			curl_setopt( $ch, CURLOPT_CAINFO, plugin_dir_path( __FILE__ ) . 'ssl/self-signed-production.pem' );
		}
		//elseif ( substr($request_url, 0, 20) === "https://api.mla.org/" ):
		// curl_setopt($ch, CURLOPT_CAINFO, getcwd() . '/ssl/cacert.pem');

		// Set HTTP method.
		if ( $http_method === 'PUT' || $http_method === 'DELETE' ) {
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $http_method );
		} elseif ( $http_method === 'POST' ) {
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
				'body' => curl_error( $ch )
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
		$this->debug='verbose'; 
		if ( 'verbose' == $this->debug ) _log( 'Now getting the member from the API.' );
		$username = urlencode( $username );
		$response = $this->send_request( 'GET', 'https://apidev.mla.org/1/members/' . $username );
		if ( 'verbose' == $this->debug ) _log( 'API response was:', $response );
		return $response; 
	} 

	public function get_mla_group_data_from_api() { 
		$http_method = 'GET';
		$base_url = 'https://apidev.mla.org/1/';
		$simple_query = 'organizations/' . $this->group_mla_api_id;
		$request_url = $base_url . $simple_query;
		$params = array( 'joined_commons' => 'Y' );
		$response = $this->send_request( $http_method, $request_url, $params );

		return $response; 
	} 
} 
