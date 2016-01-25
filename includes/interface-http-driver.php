<?php
/**
 * HTTP Driver
 *
 * @package CustomAuth
 */

namespace MLA\Commons\Plugin\CustomAuth;

/**
 * Interface for (real and mock) HTTP requests to the MLA API.
 *
 * @package CustomAuth
 * @subpackage HttpDriver
 * @interface HttpDriver
 */
interface HttpDriver {

	/**
	 * GET API request.
	 *
	 * @param string $request_path HTTP request path.
	 * @param array  $parameters   HTTP request parameters.
	 */
	public function get( $request_path, $parameters );

	/**
	 * PUT API request.
	 *
	 * @param string $request_path HTTP request path.
	 * @param array  $parameters   HTTP request parameters.
	 * @param string $request_body HTTP request body as stringifed JSON.
	 */
	public function put( $request_path, $parameters, $request_body );

	/**
	 * POST API request.
	 *
	 * @param string $request_path HTTP request path.
	 * @param array  $parameters   HTTP request parameters.
	 * @param string $request_body HTTP request body as stringifed JSON.
	 */
	public function post( $request_path, $parameters, $request_body );

	/**
	 * DELETE API request.
	 *
	 * @param string $request_path HTTP request path.
	 * @param array  $parameters   HTTP request parameters.
	 * @param string $request_body HTTP request body as stringifed JSON.
	 */
	public function delete( $request_path, $parameters, $request_body );

}
