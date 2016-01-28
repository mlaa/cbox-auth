<?php
/**
 * Test LoginProcessor class
 *
 * @package CustomAuthTests
 * @group LoginProcessor
 */

namespace MLA\Commons\Plugin\CustomAuth\Tests;

use \MLA\Commons\Plugin\CustomAuth\LoginProcessor;
use \MLA\Commons\Plugin\CustomAuth\MLAAPI;
use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Test LoginProcessor class
 *
 * @class Test_LoginProcessor
 * @group LoginProcessor
 */
class Test_LoginProcessor extends \BP_UnitTestCase {

	/**
	 * Login processor instance
	 *
	 * @var object
	 */
	private $login_processor;

	/**
	 * Dependency: MLAAPI
	 *
	 * @var object
	 */
	private static $mla_api;

	/**
	 * Dependency: Logger
	 *
	 * @var object
	 */
	private static $logger;

	/**
	 * Class set up.
	 */
	public static function setUpBeforeClass() {

		// Create null logger.
		self::$logger = new Logger();
		self::$logger->createLog();

		// Create MLA API instance with mock cURL driver.
		$mock_curl_driver = new MockCurlDriver();
		self::$mla_api = new MLAAPI( $mock_curl_driver, self::$logger );

	}

	/**
	 * Test set up.
	 */
	public function setUp() {
		$this->login_processor = new LoginProcessor( self::$mla_api, self::$logger );
		parent::setUp();
	}

	/**
	 * Test authenticating a user with no credentials.
	 */
	public function test_authenticate_user_empty_credentials() {
		$wp_error = $this->login_processor->authenticate_user( null, 'test', '' );
		$this->assertInstanceOf( '\WP_Error', $wp_error );
	}

	/**
	 * Test authenticating a forbidden user.
	 */
	public function test_authenticate_user_forbidden() {
		$wp_error = $this->login_processor->authenticate_user( null, 'admin', 'test' );
		$this->assertInstanceOf( '\WP_Error', $wp_error );
		$this->assertArrayHasKey( 'forbidden_username', $wp_error->errors );
	}

	/**
	 * Test authenticating a user with invalid credentials.
	 */
	public function test_authenticate_user_invalid() {
		$wp_error = $this->login_processor->authenticate_user( null, 'exampleuser', 'badpassword' );
		$this->assertInstanceOf( '\WP_Error', $wp_error );
		$this->assertArrayHasKey( 'not_authorized', $wp_error->errors );
		$this->assertStringStartsWith( '<strong>Error (400):</strong>', $wp_error->errors['not_authorized'][0] );
	}

	/**
	 * Test authenticating a user with invalid status.
	 */
	public function test_authenticate_user_invalid_status() {
		$wp_error = $this->login_processor->authenticate_user( null, 'inactiveuser', 'test' );
		$this->assertInstanceOf( '\WP_Error', $wp_error );
		$this->assertArrayHasKey( 'not_authorized', $wp_error->errors );
		$this->assertStringStartsWith( '<strong>Error (401):</strong>', $wp_error->errors['not_authorized'][0] );
	}

	/**
	 * Test authenticating a user with invalid username.
	 */
	public function test_authenticate_user_invalid_username() {

		$this->login_processor->set_cache( 'preferred', 'example-user' );
		$this->login_processor->set_cache( 'accepted', 'Yes' );
		$wp_error = $this->login_processor->authenticate_user( null, 'exampleuser', 'test' );

		$this->assertInstanceOf( '\WP_Error', $wp_error );
		$this->assertArrayHasKey( 'not_authorized', $wp_error->errors );
		$this->assertStringStartsWith( '<strong>Error (450):</strong>', $wp_error->errors['not_authorized'][0] );

	}

	/**
	 * Test authenticating a user with duplicate username.
	 */
	public function test_authenticate_user_duplicate_username() {

		$this->login_processor->set_cache( 'preferred', 'exampleuser2' );
		$this->login_processor->set_cache( 'accepted', 'Yes' );
		$wp_error = $this->login_processor->authenticate_user( null, 'exampleuser', 'test' );

		$this->assertInstanceOf( '\WP_Error', $wp_error );
		$this->assertArrayHasKey( 'not_authorized', $wp_error->errors );
		$this->assertStringStartsWith( '<strong>Error (460):</strong>', $wp_error->errors['not_authorized'][0] );

	}

	/**
	 * Test authenticating a user with simulated server error.
	 */
	public function test_authenticate_user_server_error() {

		$wp_error = $this->login_processor->authenticate_user( null, 'unmockeduser', 'test' );

		$this->assertInstanceOf( '\WP_Error', $wp_error );
		$this->assertArrayHasKey( 'server_error', $wp_error->errors );
		$this->assertStringStartsWith( '<strong>Error (0):</strong>', $wp_error->errors['server_error'][0] );

	}

	/**
	 * Test authenticating and creating the user.
	 */
	public function test_authenticate_user() {

		// The user should not exist before we start.
		$this->assertFalse( \get_user_by( 'login', 'exampleuser' ) );

		// Create user.
		$this->login_processor->set_cache( 'preferred', 'exampleuser' );
		$this->login_processor->set_cache( 'accepted', 'Yes' );
		$wp_user = $this->login_processor->authenticate_user( null, 'exampleuser', 'test' );

		// The user should now exist.
		$this->assertInstanceOf( '\WP_User', $wp_user );
		$this->assertInstanceOf( '\WP_User', \get_user_by( 'login', 'exampleuser' ) );

	}

	/**
	 * Test authenticating and creating the user with a new username.
	 */
	public function test_authenticate_user_new_username() {

		// The user should not exist before we start.
		$this->assertFalse( \get_user_by( 'login', 'exampleuser' ) );

		// Create user.
		$this->login_processor->set_cache( 'preferred', 'exampleuser3' );
		$this->login_processor->set_cache( 'accepted', 'Yes' );
		$wp_user = $this->login_processor->authenticate_user( null, 'exampleuser', 'test' );

		// The user should now exist.
		$this->assertInstanceOf( '\WP_User', $wp_user );
		$this->assertInstanceOf( '\WP_User', \get_user_by( 'login', 'exampleuser3' ) );

	}
}
