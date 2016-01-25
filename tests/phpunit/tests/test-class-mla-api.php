<?php
/**
 * Test MLAAPI class
 *
 * @package CustomAuthTests
 * @group MLAAPI
 */

namespace MLA\Commons\Plugin\CustomAuth\Tests;

use \MLA\Commons\Plugin\CustomAuth\MLAAPI;
use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Test MLAAPI class
 *
 * @class Test_MLAAPI
 * @group MLAAPI
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class Test_MLAAPI extends \BP_UnitTestCase {

	/**
	 * MLA API instance
	 *
	 * @var object
	 */
	protected static $mla_api;

	/**
	 * Class set up.
	 */
	public static function setUpBeforeClass() {

		// Create null logger.
		$logger = new Logger();
		$logger->createLog();

		// Create mock cURL driver.
		$mock_curl_driver = new MockCurlDriver();

		self::$mla_api = new MLAAPI( $mock_curl_driver, $logger );

	}

	/**
	 * Test get member.
	 */
	public function test_get_member() {
		$member_data = self::$mla_api->get_member( 'exampleuser' );
		$this->assertObjectHasAttribute( 'id', $member_data );
		$this->assertEquals( '900000', $member_data->id );
	}

	/**
	 * Test get nonexistent member.
	 */
	public function test_get_member_nonexistent() {
		$this->setExpectedException( 'Exception', 'No mock data' );
		self::$mla_api->get_member( 'nonexistentuser' );
	}

	/**
	 * Test get member with error status.
	 */
	public function test_get_member_error_status() {
		$this->setExpectedException( 'Exception', 'API returned non-success' );
		self::$mla_api->get_member( 'malformed1' );
	}

	/**
	 * Test get member with error code.
	 */
	public function test_get_member_error_code() {
		$this->setExpectedException( 'Exception', 'API returned error code' );
		self::$mla_api->get_member( 'malformed2' );
	}

	/**
	 * Test get member with no data.
	 */
	public function test_get_member_no_data() {
		$this->setExpectedException( 'Exception', 'API returned no data' );
		self::$mla_api->get_member( 'malformed3' );
	}

	/**
	 * Test get member with missing groups.
	 */
	public function test_get_member_missing_groups() {
		$this->setExpectedException( 'Exception', 'API response did not contain property: organizations' );
		self::$mla_api->get_member( 'malformed4' );
	}

	/**
	 * Test get group.
	 */
	public function test_get_group() {
		$group_data = self::$mla_api->get_group( '100' );
		$this->assertObjectHasAttribute( 'id', $group_data );
		$this->assertEquals( '100', $group_data->id );
	}

	/**
	 * Test get nonexistent group.
	 */
	public function test_get_group_nonexistent() {
		$this->setExpectedException( 'Exception', 'No mock data' );
		self::$mla_api->get_group( '500' );
	}

	/**
	 * Test get group that should be excluded.
	 */
	public function test_get_group_excluded() {
		$this->setExpectedException( 'Exception', 'Attempt to sync excluded group' );
		self::$mla_api->get_group( '300' );
	}

	/**
	 * Test checking for duplicate username.
	 */
	public function test_is_duplicate_username() {
		$success = self::$mla_api->is_duplicate_username( 'exampleuser' );
		$this->assertTrue( $success );
	}

	/**
	 * Test change username with malformed response.
	 */
	public function test_is_duplicate_username_malformed() {
		$this->setExpectedException( 'Exception', 'API did not return boolean for duplicate status' );
		self::$mla_api->is_duplicate_username( 'malformed' );
	}

	/**
	 * Test change username.
	 */
	public function test_change_username() {
		$success = self::$mla_api->change_username( '900000', 'newusername' );
		$this->assertTrue( $success );
	}

	/**
	 * Test MLA to BuddyPress role mapping.
	 */
	public function test_translate_mla_role() {

		$admin_roles = array( 'CHAIR', 'liaison', 'Secretary', 'executive' );
		$member_roles = array( 'char', null, 'executive committee', 'member', 'staff' );

		foreach ( $admin_roles as $role ) {
			$this->assertEquals( 'admin', self::$mla_api->translate_mla_role( $role ) );
		}

		foreach ( $member_roles as $role ) {
			$this->assertEquals( 'member', self::$mla_api->translate_mla_role( $role ) );
		}

	}
}
