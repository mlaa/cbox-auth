<?php
/**
 * CURL Driver
 *
 * @package CustomAuth
 */

namespace MLA\Commons\Plugin\CustomAuth;

use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Creates HTTP requests for the MLA API.
 *
 * @package CustomAuth
 * @subpackage CurlDriver
 * @class CurlDriver
 */
class CurlDriver implements HttpDriver {

	/**
	 * API base URL
	 *
	 * @var string
	 */
	private $api_url = CBOX_AUTH_API_URL;

	/**
	 * API key
	 *
	 * @var string
	 */
	private $api_key = CBOX_AUTH_API_KEY;

	/**
	 * API shared secret
	 *
	 * @var string
	 */
	private $api_secret = CBOX_AUTH_API_SECRET;

	/**
	 * Dependency: Logger
	 *
	 * @var object
	 */
	private $logger;

	/**
	 * Constructor
	 *
	 * @param Logger $logger Dependency: Logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Build request URL according to MLA API specifications.
	 *
	 * @param string $http_method  HTTP request method, e.g., 'GET'.
	 * @param string $request_path HTTP request path, e.g., 'members/123'.
	 * @param array  $parameters   HTTP request parameters in key=>value array.
	 * @return string Final request URL.
	 */
	private function build_request_url( $http_method, $request_path, $parameters ) {

		// Append current time to request parameters (seconds from UNIX epoch).
		$parameters['key'] = $this->api_key;
		$parameters['timestamp'] = time();

		// Sort the request parameters.
		ksort( $parameters );

		// Collapse the parameters into a URI query string.
		$query_string = http_build_query( $parameters, '', '&' );

		// Add the request parameters to the base URL.
		$request_url = $this->api_url . $request_path . '?' . $query_string;

		// Compute the request signature (see specification).
		$hash_input = $http_method . '&' . rawurlencode( $request_url );
		$api_signature = hash_hmac( 'sha256', $hash_input, $this->api_secret );

		// Append the signature to the request.
		return $request_url . '&signature=' . $api_signature;

	}

	/**
	 * Create handler and set cURL options.
	 *
	 * @param string $http_method  HTTP method, e.g., 'GET'.
	 * @param string $request_url  HTTP request URL.
	 * @param string $request_body HTTP request body as stringifed JSON.
	 * @return object cURL handler.
	 */
	private function create_request( $http_method, $request_url, $request_body ) {

		// @codingStandardsIgnoreStart WordPress.VIP.cURL
		$handler = curl_init();

		curl_setopt( $handler, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $handler, CURLOPT_FAILONERROR, false );
		curl_setopt( $handler, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $handler, CURLOPT_SSL_VERIFYHOST, 2 );

		// Set HTTP method.
		if ( 'PUT' === $http_method || 'DELETE' === $http_method ) {
			curl_setopt( $handler, CURLOPT_CUSTOMREQUEST, $http_method );
		} elseif ( 'POST' === $http_method ) {
			curl_setopt( $handler, CURLOPT_POST, 1 );
		}

		// Set HTTP headers.
		$headers = array( 'Accept: application/json' );

		// Add request body.
		if ( is_string( $request_body ) && strlen( $request_body ) ) {
			$headers[] = 'Content-Length: ' . strlen( $request_body );
			curl_setopt( $handler, CURLOPT_POSTFIELDS, $request_body );
		}

		curl_setopt( $handler, CURLOPT_HTTPHEADER, $headers );

		// Set final request URL.
		curl_setopt( $handler, CURLOPT_URL, $request_url );
		// @codingStandardsIgnoreEnd

		return $handler;

	}

	/**
	 * Send request.
	 *
	 * @param object $handler cURL handler.
	 * @return string Response text.
	 * @throws \Exception Describes HTTP error.
	 */
	private function send_request( $handler ) {

		// Send request.
		// @codingStandardsIgnoreStart WordPress.VIP.cURL
		$response_text = curl_exec( $handler );
		$response_code = curl_getinfo( $handler, CURLINFO_HTTP_CODE );
		// @codingStandardsIgnoreEnd

		// Get cURL error if response is false.
		if ( false === $response_text || 200 !== $response_code ) {
			$this->logger->addDebug( serialize( curl_error( $handler ) ) ); // @codingStandardsIgnoreLine WordPress.VIP.cURL
			throw new \Exception( 'HTTP response code ' . $response_code );
		}

		return $response_text;

	}

	/**
	 * Close request.
	 *
	 * @param object $handler cURL handler.
	 */
	private function close_request( $handler ) {
		curl_close( $handler ); // @codingStandardsIgnoreLine WordPress.VIP.cURL
	}

	/**
	 * Process request for various HTTP verbs.
	 *
	 * @param string $http_method  HTTP request method.
	 * @param string $request_path HTTP request path.
	 * @param array  $parameters   HTTP request parameters.
	 * @param string $request_body HTTP request body as stringifed JSON.
	 * @return object cURL handler.
	 */
	private function process_request( $http_method, $request_path, $parameters, $request_body ) {

		// Build request URL.
		$request_url = $this->build_request_url( $http_method, $request_path, $parameters );

		// Create cURL handler and set options.
		$curl_handler = $this->create_request( $http_method, $request_url, $request_body );

		// Send HTTP request.
		$response_text = $this->send_request( $curl_handler );
		$this->close_request( $curl_handler );

		// Parse response.
		$this->logger->addDebug( $response_text );
		return json_decode( $response_text );

	}

	/**
	 * GET API request.
	 *
	 * @param string $request_path HTTP request path.
	 * @param array  $parameters   HTTP request parameters.
	 * @return object Parsed response object.
	 */
	public function get( $request_path, $parameters = array() ) {
		return $this->process_request( 'GET', $request_path, $parameters, '' );
	}

	/**
	 * PUT API request.
	 *
	 * @param string $request_path HTTP request path.
	 * @param array  $parameters   HTTP request parameters.
	 * @param string $request_body HTTP request body as stringifed JSON.
	 * @return object Parsed response object.
	 */
	public function put( $request_path, $parameters = array(), $request_body = '' ) {
		return $this->process_request( 'PUT', $request_path, $parameters, $request_body );
	}

	/**
	 * POST API request.
	 *
	 * @param string $request_path HTTP request path.
	 * @param array  $parameters   HTTP request parameters.
	 * @param string $request_body HTTP request body as stringifed JSON.
	 * @return object Parsed response object.
	 */
	public function post( $request_path, $parameters = array(), $request_body = '' ) {
		return $this->process_request( 'POST', $request_path, $parameters, $request_body );
	}

	/**
	 * DELETE API request.
	 *
	 * @param string $request_path HTTP request path.
	 * @param array  $parameters   HTTP request parameters.
	 * @param string $request_body HTTP request body as stringifed JSON.
	 * @return object Parsed response object.
	 */
	public function delete( $request_path, $parameters = array(), $request_body = '' ) {
		return $this->process_request( 'DELETE', $request_path, $parameters, $request_body );
	}
}
