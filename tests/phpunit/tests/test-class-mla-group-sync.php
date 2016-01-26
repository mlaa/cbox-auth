<?php
/**
 * Test MLAGroup class sync
 *
 * @package CustomAuthTests
 * @group MLAGroup
 */

namespace MLA\Commons\Plugin\CustomAuth\Tests;

use \MLA\Commons\Plugin\CustomAuth\MLAAPI;
use \MLA\Commons\Plugin\CustomAuth\MLAGroup;
use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Test MLAGroup class sync
 *
 * @class Test_MLAGroup_Sync
 * @group MLAGroup
 */
class Test_MLAGroup_Sync extends \BP_UnitTestCase {

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
	 * Create a test user.
	 *
	 * @param string $username     Username of test user.
	 * @param bool   $add_to_group User should be added to test group.
	 * @param string $group_role   Role that user should obtain in test group.
	 * @return int WP user id.
	 */
	private function create_test_user( $username, $add_to_group, $group_role = null ) {

		// Create user.
		$user_id = $this->factory->user->create( array( 'user_login' => $username ) );

		if ( $add_to_group ) {
			\groups_join_group( $this->mla_group->bp_id, $user_id );
		}

		if ( $group_role && 'member' !== $group_role ) {
			$member = new \BP_Groups_Member( $user_id, $this->mla_group->bp_id );
			\do_action( 'groups_promote_member', $this->mla_group->bp_id, $user_id, $group_role );
			$member->promote( $group_role );
		}

		return $user_id;

	}

	/**
	 * Create an array of test users with known attributes.
	 *
	 * @return array Array of test users.
	 */
	private function create_test_users() {

		$new_users = array();

		// Create four sets of four users each to test against. On the BuddyPress
		// side, we are testing four states: absent, member, mod, and admin. We
		// will end up with sixteen users, with usernames ranging from groupuser1
		// to groupuser16. The mock API data is reponsible for representing, in
		// order, four possible API states: Chair, Primary Member, Member, and
		// absent.
		for ( $i = 0; $i < 4; $i++ ) {
			$new_users[] = $this->create_test_user( 'groupuser' . ( $i * 4 + 1 ), false ); // Absent.
			$new_users[] = $this->create_test_user( 'groupuser' . ( $i * 4 + 2 ), true ); // Member.
			$new_users[] = $this->create_test_user( 'groupuser' . ( $i * 4 + 3 ), true, 'mod' ); // Mod.
			$new_users[] = $this->create_test_user( 'groupuser' . ( $i * 4 + 4 ), true, 'admin' ); // Admin.
		}

		return $new_users;

	}

	/**
	 * Create and sync a group and gets its members in a testable format.
	 *
	 * @return array $group_members Array of BP group members and roles.
	 */
	public function create_group_members() {

		// Sync the group.
		$is_synced = $this->mla_group->sync();
		$this->assertTrue( $is_synced );

		// Get the updated group members and roles.
		$args = array(
			'group_id'   => $this->mla_group->bp_id,
			'group_role' => array( 'admin', 'mod', 'member' ),
			'per_page'   => 9999,
		);
		$raw_group_members = \groups_get_group_members( $args );

		// Create a simplified representation of group members and roles.
		$group_members = array();
		foreach ( $raw_group_members['members'] as $member ) {
			$role = ( 1 === $member->is_mod )   ? 'mod'   : 'member';
			$role = ( 1 === $member->is_admin ) ? 'admin' : $role;
			$group_members[ strtolower( $member->ID ) ] = $role;
		}

		return $group_members;

	}

	/**
	 * Test syncing an MLA forum.
	 */
	public function test_sync_forum() {

		// No groups should already exist.
		$groups = \groups_get_groups();
		$this->assertEquals( 0, count( $groups['groups'] ) );

		// Create the group.
		$this->mla_group->create_bp_group( 'Hungarian', 'Forum', 100 );

		// Insert users.
		$new_users = $this->create_test_users();

		// Sync the group and get BP group members.
		$group_members = $this->create_group_members();

		// The group should be public.
		$group = \groups_get_group( array( 'group_id' => $this->mla_group->bp_id ) );
		$this->assertEquals( 'public', $group->status );

		// User groupuser1: "Chair" in API, absent locally. Should be added and promoted.
		$this->assertEquals( 'admin', $group_members[ $new_users[0] ] );

		// User groupuser2: "Chair" in API, member locally. Should be promoted.
		$this->assertEquals( 'admin', $group_members[ $new_users[1] ] );

		// User groupuser3: "Chair" in API, mod locally. Should be promoted.
		$this->assertEquals( 'admin', $group_members[ $new_users[2] ] );

		// User groupuser4: "Chair" in API, admin locally. Should be unchanged.
		$this->assertEquals( 'admin', $group_members[ $new_users[3] ] );

		// User groupuser5: Primary "Member" in API, absent locally. Should be added but not promoted.
		$this->assertEquals( 'member', $group_members[ $new_users[4] ] );

		// User groupuser6: Primary "Member" in API, member locally. Should be unchanged.
		$this->assertEquals( 'member', $group_members[ $new_users[5] ] );

		// User groupuser7: Primary "Member" in API, mod locally. Should be unchanged.
		$this->assertEquals( 'mod', $group_members[ $new_users[6] ] );

		// User groupuser8: Primary "Member" in API, admin locally. Should be demoted.
		$this->assertEquals( 'member', $group_members[ $new_users[7] ] );

		// User groupuser9: "Member" in API, absent locally. Should not be added.
		$this->assertArrayNotHasKey( $new_users[8], $group_members );

		// User groupuser10: "Member" in API, member locally. Should be unchanged.
		$this->assertEquals( 'member', $group_members[ $new_users[9] ] );

		// User groupuser11: "Member" in API, mod locally. Should be unchanged.
		$this->assertEquals( 'mod', $group_members[ $new_users[10] ] );

		// User groupuser12: "Member" in API, admin locally. Should be demoted.
		$this->assertEquals( 'member', $group_members[ $new_users[11] ] );

		// User groupuser13: Absent in API, absent locally. Should be unchanged.
		$this->assertArrayNotHasKey( $new_users[12], $group_members );

		// User groupuser14: Absent in API, member locally. Should be unchanged.
		$this->assertEquals( 'member', $group_members[ $new_users[13] ] );

		// User groupuser15: Absent in API, mod locally. Should be unchanged.
		$this->assertEquals( 'mod', $group_members[ $new_users[14] ] );

		// User groupuser16: Absent in API, admin locally. Should be demoted.
		$this->assertEquals( 'member', $group_members[ $new_users[15] ] );

	}

	/**
	 * Test syncing an MLA committee.
	 */
	public function test_sync_committee() {

		// No groups should already exist.
		$groups = \groups_get_groups();
		$this->assertEquals( 0, count( $groups['groups'] ) );

		// Create the group.
		$this->mla_group->create_bp_group( 'Committee on the Status of Women in the Profession', 'MLA Organization', 200 );

		// Insert users.
		$new_users = $this->create_test_users();

		// Sync the group and get BP group members.
		$group_members = $this->create_group_members();

		// The group should be private.
		$group = \groups_get_group( array( 'group_id' => $this->mla_group->bp_id ) );
		$this->assertEquals( 'private', $group->status );

		// User groupuser1: "Chair" in API, absent locally. Should be added and promoted.
		$this->assertEquals( 'admin', $group_members[ $new_users[0] ] );

		// User groupuser2: "Chair" in API, member locally. Should be promoted.
		$this->assertEquals( 'admin', $group_members[ $new_users[1] ] );

		// User groupuser3: "Chair" in API, mod locally. Should be promoted.
		$this->assertEquals( 'admin', $group_members[ $new_users[2] ] );

		// User groupuser4: "Chair" in API, admin locally. Should be unchanged.
		$this->assertEquals( 'admin', $group_members[ $new_users[3] ] );

		// User groupuser5: Primary "Member" in API, absent locally. Should be added but not promoted.
		$this->assertEquals( 'member', $group_members[ $new_users[4] ] );

		// User groupuser6: Primary "Member" in API, member locally. Should be unchanged.
		$this->assertEquals( 'member', $group_members[ $new_users[5] ] );

		// User groupuser7: Primary "Member" in API, mod locally. Should be unchanged.
		$this->assertEquals( 'mod', $group_members[ $new_users[6] ] );

		// User groupuser8: Primary "Member" in API, admin locally. Should be demoted.
		$this->assertEquals( 'member', $group_members[ $new_users[7] ] );

		// User groupuser9: "Member" in API, absent locally. Should be added.
		$this->assertEquals( 'member', $group_members[ $new_users[8] ] );

		// User groupuser10: "Member" in API, member locally. Should be unchanged.
		$this->assertEquals( 'member', $group_members[ $new_users[9] ] );

		// User groupuser11: "Member" in API, mod locally. Should be unchanged.
		$this->assertEquals( 'mod', $group_members[ $new_users[10] ] );

		// User groupuser12: "Member" in API, admin locally. Should be demoted.
		$this->assertEquals( 'member', $group_members[ $new_users[11] ] );

		// User groupuser13: Absent in API, absent locally. Should be unchanged.
		$this->assertArrayNotHasKey( $new_users[12], $group_members );

		// User groupuser14: Absent in API, member locally. Should be removed.
		$this->assertArrayNotHasKey( $new_users[13], $group_members );

		// User groupuser15: Absent in API, mod locally. Should be removed.
		$this->assertArrayNotHasKey( $new_users[14], $group_members );

		// User groupuser16: Absent in API, admin locally. Should be removed.
		$this->assertArrayNotHasKey( $new_users[15], $group_members );

	}

	/**
	 * Test syncing an excluded group.
	 */
	public function test_sync_excluded_group() {

		// No groups should already exist.
		$groups = \groups_get_groups();
		$this->assertEquals( 0, count( $groups['groups'] ) );

		// Create the group.
		$this->mla_group->create_bp_group( 'Office of Research', 'MLA Organization', 300 );

		// Attempt to sync the group.
		$this->setExpectedException( 'Exception', 'Attempt to sync excluded group' );
		$this->mla_group->sync();

	}
}
