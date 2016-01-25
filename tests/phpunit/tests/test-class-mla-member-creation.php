<?php
/**
 * Test MLAMember class creation
 *
 * @package CustomAuthTests
 * @group MLAMember
 */

namespace MLA\Commons\Plugin\CustomAuth\Tests;

use \MLA\Commons\Plugin\CustomAuth\MLAAPI;
use \MLA\Commons\Plugin\CustomAuth\MLAMember;
use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Test MLAMember class creation
 *
 * @class Test_MLAMember_Creation
 * @group MLAMember
 */
class Test_MLAMember_Creation extends \BP_UnitTestCase {

	/**
	 * MLA member instance
	 *
	 * @var object
	 */
	private $mla_member;

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
		$this->mla_member = new MLAMember( false, self::$mla_api, self::$logger );
		parent::setUp();
	}

	/**
	 * Test creating WP user without first retrieving API data.
	 */
	public function test_create_wp_user_no_api_data() {
		$this->setExpectedException( 'Exception', 'Cannot create user without API data' );
		$this->mla_member->create_wp_user( 'exampleuser' );
	}

	/**
	 * Test creating WP user.
	 */
	public function test_create_wp_user() {

		// The user should not exist before we start.
		$this->assertFalse( \get_user_by( 'login', 'exampleuser' ) );

		// Create user.
		$this->mla_member->authenticate( 'exampleuser', 'test' );
		$this->mla_member->create_wp_user( 'exampleuser' );

		// The user should now exist.
		$this->assertInstanceOf( '\WP_User', \get_user_by( 'login', 'exampleuser' ) );

		// The user should not allow recreation.
		$this->setExpectedException( 'Exception', 'Cannot recreate user' );
		$this->mla_member->create_wp_user( 'exampleuser' );

	}
}
