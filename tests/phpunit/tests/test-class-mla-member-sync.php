<?php
/**
 * Test MLAMember class creation and sync
 *
 * @package CustomAuthTests
 * @group MLAMember
 */

namespace MLA\Commons\Plugin\CustomAuth\Tests;

use \MLA\Commons\Plugin\CustomAuth\MLAAPI;
use \MLA\Commons\Plugin\CustomAuth\MLAGroup;
use \MLA\Commons\Plugin\CustomAuth\MLAMember;
use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Test MLAMember class creation and sync
 *
 * @class Test_MLAMember_Sync
 * @group MLAMember
 */
class Test_MLAMember_Sync extends \BP_UnitTestCase {

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
	 * Create a test group.
	 *
	 * @param array  $group_details Username of test user.
	 * @param bool   $add_to_group  User should be added to test group.
	 * @param string $group_role    Role that user should obtain in test group.
	 * @return int BuddyPress group id.
	 */
	private function create_test_group( $group_details, $add_to_group, $group_role = null ) {

		// WP user id.
		$user_id = $this->mla_member->user_id;

		// Group details.
		$group_name = $group_details['name'];
		$group_type = $group_details['type'];
		$mla_api_id = $group_details['api_id'];

		// Create group.
		$test_group = new MLAGroup( false, self::$mla_api, self::$logger );
		$test_group->create_bp_group( $group_name, $group_type, $mla_api_id );
		$group_id = $test_group->bp_id;

		if ( $add_to_group ) {
			\groups_join_group( $group_id, $user_id );
		}

		if ( $group_role && 'member' !== $group_role ) {
			$member = new \BP_Groups_Member( $user_id, $group_id );
			\do_action( 'groups_promote_member', $group_id, $user_id, $group_role );
			$member->promote( $group_role );
		}

		return $group_id;

	}

	/**
	 * Create an array of test group details.
	 *
	 * @param string $type   Test group type (e.g., "Forum").
	 * @param string $api_id Test MLA API id.
	 * @param int    $num    Test group number.
	 * @return array Array of test group details.
	 */
	private function create_test_group_details( $type, $api_id, $num ) {

		$name = ( 'mla organization' === strtolower( $type ) ) ? 'Committee' : 'Forum';

		return array(
			'name'   => 'Test ' . $name . ' ' . $num,
			'type'   => $type,
			'api_id' => $api_id,
		);

	}

	/**
	 * Create an array of test groups with known attributes.
	 *
	 * @param string $type Test group type (e.g., "Forum").
	 * @return array Array of test groups.
	 */
	private function create_test_groups( $type ) {

		$new_groups = array();

		$api_block = ( 'mla organization' === strtolower( $type ) ) ? 200 : 100;

		// Create four sets of four groups each to test against. On the BuddyPress
		// side, we are testing four states: absent, "member", "mod", and "admin".
		// We will end up with sixteen groups, with API ids ranging from x01 to
		// x16. The mock API data is reponsible for representing, in order, four
		// possible API states: "Chair", Primary "Member", "Member", and absent.
		for ( $i = 0; $i < 4; $i++ ) {

			$counter = $i * 4;

			$group_details = $this->create_test_group_details( $type, $api_block + $counter + 1, $counter + 1 );
			$new_groups[]  = $this->create_test_group( $group_details, false ); // Absent.

			$group_details = $this->create_test_group_details( $type, $api_block + $counter + 2, $counter + 2 );
			$new_groups[]  = $this->create_test_group( $group_details, true ); // Member.

			$group_details = $this->create_test_group_details( $type, $api_block + $counter + 3, $counter + 3 );
			$new_groups[]  = $this->create_test_group( $group_details, true, 'mod' ); // Mod.

			$group_details = $this->create_test_group_details( $type, $api_block + $counter + 4, $counter + 4 );
			$new_groups[]  = $this->create_test_group( $group_details, true, 'admin' ); // Admin.

		}

		return $new_groups;

	}

	/**
	 * Test that the user's group memberships were correctly set.
	 *
	 * @param int $user_id WordPress user id.
	 * @return array Associative array of regular, mod, and admin groups.
	 */
	private function get_group_memberships( $user_id ) {

		$args = array(
			'user_id'           => $user_id,
			'per_page'          => 9999,
			'update_meta_cache' => true,
		);

		// Get the user's groups after syncing.
		$groups = \groups_get_groups( $args );
		$groups_slugs = array_map( function ( $item ) {
			return $item->slug;
		}, $groups['groups']);
		sort( $groups_slugs );

		// Get the user's mod groups.
		$mod_groups = \BP_Groups_Member::get_is_mod_of( $this->mla_member->user_id );
		$mod_groups_slugs = array_map( function ( $item ) {
			return $item->slug;
		}, $mod_groups['groups']);
		sort( $mod_groups_slugs );

		// Get the user's admin groups.
		$admin_groups = \BP_Groups_Member::get_is_admin_of( $user_id );
		$admin_groups_slugs = array_map( function ( $item ) {
			return $item->slug;
		}, $admin_groups['groups']);
		sort( $admin_groups_slugs );

		return array(
			'member' => $groups_slugs,
			'mod'    => $mod_groups_slugs,
			'admin'  => $admin_groups_slugs,
		);

	}

	/**
	 * Test syncing a WP user with the API.
	 */
	public function test_sync() {

		// The user should not exist.
		$this->assertFalse( \get_user_by( 'login', 'exampleuser' ) );

		// Create user.
		$this->mla_member->authenticate( 'exampleuser', 'test' );
		$this->mla_member->create_wp_user( 'exampleuser' );

		// No groups should exist.
		$this->assertEquals( 0, \groups_get_group( array( 'group_id' => 1 ) )->id );

		// Sync user.
		$is_synced = $this->mla_member->sync();
		$user_id = $this->mla_member->user_id;

		// Assert that the member synced.
		$this->assertTrue( $is_synced );

		// Assert that the member's metadata was updated.
		// @codingStandardsIgnoreStart WordPress.VIP.UserMeta
		$affiliations = \get_user_meta( $user_id, 'affiliations', true );
		$languages = \get_user_meta( $user_id, 'languages', true );
		$this->assertEquals( '900000', \get_user_meta( $user_id, 'mla_oid', true ) );
		$this->assertEquals( 'Mod Lang Association', $affiliations[0] );
		$this->assertEquals( 'Other languages', $languages[0]['name'] );
		// @codingStandardsIgnoreEnd

		// Get the user's group memberships after syncing.
		$memberships = $this->get_group_memberships( $user_id );

		// Assert that the user belongs to these groups.
		$expected_slugs = array(
			'test-forum-1',
			'test-forum-2',
			'test-forum-3',
			'test-forum-4',
			'test-forum-5',
			'test-forum-6',
			'test-forum-7',
			'test-forum-8',
			'test-committee-1',
			'test-committee-2',
			'test-committee-3',
			'test-committee-4',
			'test-committee-5',
			'test-committee-6',
			'test-committee-7',
			'test-committee-8',
			'test-committee-9',
			'test-committee-10',
			'test-committee-11',
			'test-committee-12',
		);
		sort( $expected_slugs );
		$this->assertEquals( $expected_slugs, $memberships['member'] );

		// Assert that the member is a "mod" of no groups.
		$this->assertEmpty( $memberships['mod'] );

		// Assert that the member is an "admin" of these groups.
		$expected_admin_slugs = array(
			'test-forum-1',
			'test-forum-2',
			'test-forum-3',
			'test-forum-4',
			'test-committee-1',
			'test-committee-2',
			'test-committee-3',
			'test-committee-4',
		);
		sort( $expected_admin_slugs );
		$this->assertEquals( $expected_admin_slugs, $memberships['admin'] );

		// Assert that some groups should not have been created.
		$this->assertEquals( 0, \groups_get_id( 'test-forum-9' ) );
		$this->assertEquals( 0, \groups_get_id( 'test-forum-10' ) );
		$this->assertEquals( 0, \groups_get_id( 'test-forum-11' ) );
		$this->assertEquals( 0, \groups_get_id( 'test-forum-12' ) );

		// Assert that MLA metadata was added to groups.
		$this->assertEquals( '101',   \groups_get_groupmeta( \groups_get_id( 'test-forum-1' ), 'mla_api_id' ) );
		$this->assertEquals( 'forum', \groups_get_groupmeta( \groups_get_id( 'test-forum-1' ), 'mla_group_type' ) );

		// Attempt to sync user again.
		$is_synced = $this->mla_member->sync();

		// Assert that the member did not sync (because it was synced recently).
		$this->assertFalse( $is_synced );

	}

	/**
	 * Test updating a member via with the API.
	 */
	public function test_update_sync() {
		$this->markTestSkipped( 'must be revisited.' );

		// No groups should already exist.
		$groups = \groups_get_groups();
		$this->assertEquals( 0, count( $groups['groups'] ) );

		// The user should not exist.
		$this->assertFalse( \get_user_by( 'login', 'exampleuser' ) );

		// Create user.
		$this->mla_member->authenticate( 'exampleuser', 'test' );
		$this->mla_member->create_wp_user( 'exampleuser' );

		// Create test groups before syncing the user, so that we can verify that
		// permissions are adjusted correctly.
		$this->create_test_groups( 'Forum' );
		$this->create_test_groups( 'MLA Organization' );

		// Sync user.
		$is_synced = $this->mla_member->sync();
		$user_id = $this->mla_member->user_id;

		// Assert that the member synced.
		$this->assertTrue( $is_synced );

		// Get the user's group memberships after syncing.
		$memberships = $this->get_group_memberships( $user_id );

		// Assert that the user belongs to these groups.
		$expected_slugs = array(
			'test-forum-1',
			'test-forum-2',
			'test-forum-3',
			'test-forum-4',
			'test-forum-5',
			'test-forum-6',
			'test-forum-7',
			'test-forum-8',
			'test-forum-10',
			'test-forum-11',
			'test-forum-12',
			'test-forum-14',
			'test-forum-15',
			'test-forum-16',
			'test-committee-1',
			'test-committee-2',
			'test-committee-3',
			'test-committee-4',
			'test-committee-5',
			'test-committee-6',
			'test-committee-7',
			'test-committee-8',
			'test-committee-9',
			'test-committee-10',
			'test-committee-11',
			'test-committee-12',
		);
		sort( $expected_slugs );
		$this->assertEquals( $expected_slugs, $memberships['member'] );

		// Assert that the member is an "mod" of these groups.
		$expected_mod_slugs = array(
			'test-forum-7',
			'test-forum-11',
			'test-forum-15',
			'test-committee-7',
			'test-committee-11',
		);
		sort( $expected_mod_slugs );
		$this->assertEquals( $expected_mod_slugs, $memberships['mod'] );

		// Assert that the member is an "admin" of these groups.
		$expected_admin_slugs = array(
			'test-forum-1',
			'test-forum-2',
			'test-forum-3',
			'test-forum-4',
			'test-committee-1',
			'test-committee-2',
			'test-committee-3',
			'test-committee-4',
		);
		sort( $expected_admin_slugs );
		$this->assertEquals( $expected_admin_slugs, $memberships['admin'] );

	}
}
