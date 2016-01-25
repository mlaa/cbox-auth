<?php
/**
 * Test MLAMember class authentication
 *
 * @package CustomAuthTests
 * @group MLAMember
 */

namespace MLA\Commons\Plugin\CustomAuth\Tests;

use \MLA\Commons\Plugin\CustomAuth\MLAAPI;
use \MLA\Commons\Plugin\CustomAuth\MLAMember;
use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Test MLAMember class authentication
 *
 * @class Test_MLAMember_Auth
 * @group MLAMember
 */
class Test_MLAMember_Auth extends \BP_UnitTestCase {

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
	 * Test authentication. The input for the hardcoded password hash is "test".
	 */
	public function test_authenticate() {

		$is_authenticated = $this->mla_member->authenticate( 'exampleuser', 'test' );
		$this->assertTrue( $is_authenticated );

		// Reauthentication is not allowed.
		$this->setExpectedException( 'Exception', 'is already authenticated' );
		$this->mla_member->authenticate( 'exampleuser', 'test' );

	}

	/**
	 * Test member authentication with incorrect password.
	 */
	public function test_authenticate_incorrect_password() {
		$this->setExpectedException( 'Exception', 'supplied incorrect credentials' );
		$this->mla_member->authenticate( 'exampleuser', 'test1' );
	}

	/**
	 * Test member authentication of inactive member.
	 */
	public function test_authenticate_inactive_member() {
		$this->setExpectedException( 'Exception', 'status is not active' );
		$this->mla_member->authenticate( 'inactiveuser', 'test' );
	}

	/**
	 * Test username validation.
	 */
	public function test_validate_username() {
		$this->setExpectedException( 'Exception', 'provided invalid preferred username' );
		$this->mla_member->validate_username( 'madeupuser', '1exampleuser' );
	}

	/**
	 * Test username validation of unchanged username.
	 */
	public function test_validate_username_unchanged() {
		$this->assertTrue( $this->mla_member->validate_username( 'exampleuser', 'exampleuser' ) );
	}

	/**
	 * Test username validation of duplicate WP username.
	 */
	public function test_validate_username_wp_duplicate() {

		// Create user to reserve username.
		$this->mla_member->authenticate( 'exampleuser', 'test' );
		$this->mla_member->create_wp_user( 'exampleuser' );

		$this->setExpectedException( 'Exception', 'provided duplicate WP username' );
		$this->mla_member->validate_username( 'madeupuser', 'exampleuser' );

	}

	/**
	 * Test username validation of duplicate API username.
	 */
	public function test_validate_username_api_duplicate() {
		$this->setExpectedException( 'Exception', 'provided duplicate API username' );
		$this->mla_member->validate_username( 'madeupuser', 'exampleuser2' );
	}
}
