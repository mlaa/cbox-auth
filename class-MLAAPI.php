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
		require_once( 'private.php' ); 

		// Append current time to request parameters (seconds from UNIX epoch).
		$parameters['key'] = $api_key;  
		$parameters['timestamp'] = time();

		// Sort the request parameters.
		ksort($parameters);

		// Collapse the parameters into a URI query string.
		$query_string = http_build_query($parameters, '', '&');

		// Add the request parameters to the base URL.
		$request_url = $base_url . '?' . $query_string;
		//
		// Compute the request signature (see specification).
		$hash_input = $http_method . '&' . rawurlencode($request_url);
		$api_signature = hash_hmac('sha256', $hash_input, $api_secret);

		// Append the signature to the request.
		$request_url = $request_url . '&signature=' . $api_signature;

		_log( 'using request:' ); 
		_log( $request_url ); 

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
} 
