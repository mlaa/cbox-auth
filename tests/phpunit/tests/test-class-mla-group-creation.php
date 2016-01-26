<?php
/**
 * Test MLAGroup class creation
 *
 * @package CustomAuthTests
 * @group MLAGroup
 */

namespace MLA\Commons\Plugin\CustomAuth\Tests;

use \MLA\Commons\Plugin\CustomAuth\MLAAPI;
use \MLA\Commons\Plugin\CustomAuth\MLAGroup;
use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Test MLAGroup class creation
 *
 * @class Test_MLAGroup_Creation
 * @group MLAGroup
 */
class Test_MLAGroup_Creation extends \BP_UnitTestCase {

	/**
	 * MLA group instance
	 *
	 * @var object
	 */
	private $mla_group;

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
		$this->mla_group = new MLAGroup( false, self::$mla_api, self::$logger );
		parent::setUp();
	}

	/**
	 * Test creating a BP group.
	 */
	public function test_create_bp_group() {

		// No groups should already exist.
		$groups = \groups_get_groups();
		$this->assertEquals( 0, count( $groups['groups'] ) );

		// Create the group.
		$this->mla_group->create_bp_group( 'Hungarian', 'Forum', 100 );

		// The group should exist.
		$group = \groups_get_group( array( 'group_id' => $this->mla_group->bp_id ) );
		$this->assertGreaterThan( 0, $group->id );

		// The group should be public.
		$this->assertEquals( 'public', $group->status );

		// The group should have an MLA API id.
		$this->assertEquals( '100', \groups_get_groupmeta( $group->id, 'mla_api_id' ) );

	}
}
