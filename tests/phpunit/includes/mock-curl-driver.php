<?php
/**
 * Mock cURL Driver
 *
 * @package CustomAuth
 */

namespace MLA\Commons\Plugin\CustomAuth\Tests;

use \MLA\Commons\Plugin\CustomAuth\HttpDriver;

/**
 * Mocks HTTP requests for tests by serializing the request path and query
 * parameters into a file name and looks for that file in the data subfolder.
 *
 * @package CustomAuth
 * @subpackage MockCurlDriver
 * @class MockCurlDriver
 */
class MockCurlDriver implements HttpDriver {

	/**
	 * Process request for various HTTP verbs.
	 *
	 * @param string $http_method  HTTP request method.
	 * @param string $request_path HTTP request path.
	 * @param array  $parameters   HTTP request parameters.
	 * @return object cURL handler.
	 * @throws \Exception If mock data cannot be found.
	 */
	private function process_request( $http_method, $request_path, $parameters ) {

		// File name parts: 'GET', 'resource-path'.
		$file_name_parts = array( $http_method, str_replace( '/', '-', $request_path ) );

		// Append sorted parameters onto a file name slug.
		if ( count( $parameters ) ) {
			ksort( $parameters );
			$file_name_parts[] = http_build_query( $parameters, '', '-' );
		}

		// Retrieve mock data.
		$file_name = implode( '-', $file_name_parts ) . '.json';
		if ( file_exists( dirname( __FILE__ ) . '/../data/' . $file_name ) ) {
			// @codingStandardsIgnoreStart WordPress VIP.FileGetContents
			return json_decode( file_get_contents( dirname( __FILE__ ) . '/../data/' . $file_name ) );
			// @codingStandardsIgnoreEnd
		}

		throw new \Exception( 'No mock data for: ' . $file_name );

	}

	/**
	 * GET API request.
	 *
	 * @param string $request_path HTTP request path.
	 * @param array  $parameters   HTTP request parameters.
	 * @return object Parsed response object.
	 */
	public function get( $request_path, $parameters = array() ) {
		return $this->process_request( 'GET', $request_path, $parameters );
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
