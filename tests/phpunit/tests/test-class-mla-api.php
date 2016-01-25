<?php

ini_set('xdebug.overload_var_dump', 0);

require_once 'class-custom-authentication.php';

/**
 * @coversDefaultClass CustomAuthentication
 */
class CustomAuthenticationTest extends WP_Ajax_UnitTestCase {

	public function setUp() {
		//parent::setUp(); // this breaks buddypress: Undefined property: BP_Members_Component::$admin
		//BP_UnitTestCase::setUp(); BP_UnitTestCase class not found
	}

	/**
	 * allow access to protected methods
	 * http://stackoverflow.com/a/2798203/700113
	 */
	private function get_method( $name ) {
		$class = new ReflectionClass( 'CustomAuthentication' );
		$method = $class->getMethod( $name );
		$method->setAccessible( true );
		return $method;
	}

	/**
	 * this provider is shared by several functions that use member json
	 * TODO add more mock data to test multiple member scenarios
	 */
	public function provider_member_json() {
		$mocked_members = [
			[ 'exampleuser', 'test' ],
		];
		$instance = new CustomAuthentication;
		$data = [];

		foreach ( $mocked_members as $mocked_member ) {
			$username = $mocked_member[0];
			$password = $mocked_member[1];

			$response = $instance->get_member( $username );
			$decoded = json_decode( $response['body'], true );
			$member_json = $decoded['data'][0];

			$data[] = [ $member_json, $username, $password ];
		}

		return $data;
	}

	/**
	 * @covers ::member_json_to_array
	 * @dataProvider provider_member_json
	 */
	public function test_member_json_to_array( $member_json, $username, $password ) {
		$method = $this->get_method( 'member_json_to_array' );
		$member_array = $method->invoke( new CustomAuthentication, $member_json, $password );

		// check that resulting array is in fact an array
		$this->assertInternalType( 'array', $member_array );

		// check that resulting array has required fields.
		$required_fields = [ 'id', 'user_name', 'status', 'password' ];
		foreach ( $required_fields as $required_field ) {
			$this->assertArrayHasKey( $required_field, $member_array );
		}
	}

	/**
	 * @covers ::validate_custom_user
	 * @dataProvider provider_member_json
	 */
	public function test_validate_custom_user( $member_json, $username, $password ) {
		$method = $this->get_method( 'member_json_to_array' );
		$member_array = $method->invoke( new CustomAuthentication, $member_json, $password );

		// Mock member data should represent a valid, active member.
		$method = $this->get_method( 'validate_custom_user' );
		$is_valid = $method->invoke( new CustomAuthentication, $member_array, $username );
		$this->assertTrue( $is_valid );

		// Changing the membership status to inactive should throw an error.
		$member_array['status'] = 'inactive';
		$is_valid = $method->invoke( new CustomAuthentication, $member_array, $username );
		$this->assertFalse( $is_valid );
	}

	/**
	 * @dataProvider provider_member_json
	 */
	public function test_group_creation( $member_json, $username, $password ) {
		// Get test data and convert it to an array.
		$convertermethod = $this->get_method( 'member_json_to_array' );
		$member_array = $convertermethod->invoke( new CustomAuthentication, $member_json, $password );

		// Now take the array and feed it to `manage_groups()`, which is going to add
		// groups that don't exist (i.e. all of them).
		$method = $this->get_method( 'manage_groups' );
		$retval = $method->invoke( new CustomAuthentication, 2, $member_array['groups'] );

		// Now since our test data has a bunch of test groups in it,
		// and since our database doesn't currently have these groups,
		// the method manage_groups should've created these groups for us,
		// and we should be able to see them now in the database.

		// this looks for groups by their slug. We should see 'hebrew',
		// 'hungarian', 'travel-writing', etc.
		$hebrew = groups_get_id( 'hebrew' );
		$hungarian = groups_get_id( 'hungarian' );
		$travel_writing = groups_get_id( 'travel-writing' );
		$advisory = groups_get_id( 'advisory-comm-on-foreign-lang-programs' );
		$women = groups_get_id( 'committee-on-the-status-of-women-in-the-profession' );
		$research = groups_get_id( 'office-of-research' );
		$very_good = groups_get_id( 'very-good-literature' );

		$this->assertEquals( 1, $hebrew );
		$this->assertEquals( 2, $hungarian );
		$this->assertEquals( 3, $travel_writing );
		$this->assertEquals( 4, $women );

		// Groups that are not committees or forums should not be added.
		$this->assertEquals( 0, $research );
		$this->assertEquals( 0, $advisory );
		$this->assertEquals( 0, $very_good );
	}

	/**
	 * @depends test_group_creation
	 */
	public function test_committee_creation() {
		// Committees should be private groups.
		$committee_id = groups_get_id( 'committee-on-the-status-of-women-in-the-profession' );
		$committee_group = groups_get_group( array( 'group_id' => $committee_id ) );

		//_log( 'Group is: ', $committee_group );
		//_log( 'Group status is: ', $committee_group->status );
		$this->assertEquals( 'private', $committee_group->status );
	}

	/**
	 * Add a new forum to a user's member forums,
	 * then make sure this forum is created in the database.
	 * This depends on all the prior tests to have run correctly.
	 *
	 * @dataProvider provider_member_json
	 * @depends test_group_creation
	 */
	public function test_new_forum( $member_json, $username, $password ) {
		// When we're starting out, this group shouldn't already exist in the database.
		$interdisciplinary = groups_get_id( 'interdisciplinary-approaches-to-culture-and-society' );

		// Delete the group if it's already there. Ideally, there would be a destructor method of this
		// class that would delete everything from the DB, but until then, there's this.
		if ( $interdisciplinary ) { groups_delete_group( $interdisciplinary ); }

		$newForum = array(
			'id' => '215',
			'name' => 'Interdisciplinary Approaches to Culture and Society',
			'type' => 'Forum',
			'convention_code' => 'G017',
			'position' => 'Chair',
			'exclude_from_commons' => '',
		);

		// add new forum to mock user's list of forums
		$member_json['organizations'][] = $newForum;

		// Get test data and convert it to an array.
		$convertermethod = $this->get_method( 'member_json_to_array' );
		$member_array = $convertermethod->invoke( new CustomAuthentication, $member_json, 'test' );

		// Now take the array and feed it to `manage_groups()`, which is going to add
		// groups that don't exist (i.e. the one we just added).
		// Assumes our new user has ID of 2.
		$method = $this->get_method( 'manage_groups' );
		$retval = $method->invoke( new CustomAuthentication, 2, $member_array['groups'] );

		// Now we should see our new forum appear in slot 6.
		$interdisciplinary = groups_get_id( 'interdisciplinary-approaches-to-culture-and-society' );

		$this->assertTrue( $interdisciplinary > 0 );
	}

	/**
	 * OK, now that we've tested the creation of a new forum, that creation
	 * should've also synced the group using `MLAGroup::sync()`. If that's
	 * the case, then our beloved member `exampleuser` should be a member of that group.
	 *
	 * @depends test_new_forum
	 */
	public function test_group_member_sync() {
		$interdisciplinary = groups_get_id( 'interdisciplinary-approaches-to-culture-and-society' );
		// We assume that our example user has the BP user ID of 2.
		//_log( "Checking that user 2 is member of group id: $interdisciplinary" );
		$membership_id = groups_is_user_member( 2, $interdisciplinary );

		// groups_is_user_member() returns membership ID (int) or NULL,
		// so let's check for that.
		$this->assertTrue( is_int( $membership_id ) );
	}

	/**
	 * But since our member is actually the chair of this new group, let's test
	 * that our member is, in fact, a chair of the corresponding BuddyPress group.
	 *
	 * @depends test_new_forum
	 */
	public function test_group_member_status() {
		$interdisciplinary = groups_get_id( 'interdisciplinary-approaches-to-culture-and-society' );
		// We assume that our example user has the BP user ID of 2.
		//_log( "Checking that user 2 is admin of group id: $interdisciplinary" );
		$is_admin = groups_is_user_admin( 2, $interdisciplinary );

		// groups_is_user_admin() returns membership ID (int) or NULL,
		// so let's check for that.
		$this->assertTrue( is_int( $is_admin ) );
	}

	/**
	 * This one is very much like testNewForum() above, but it checks to see whether
	 * cbox-auth correctly demotes a user that has been demoted in the MLA database.
	 * Here, the crucial distinction here is that "position" is "Member" instead of "Chair."
	 *
	 * @depends test_new_forum
	 * @dataProvider provider_member_json
	 */
	public function test_demoted_user( $member_json, $username, $password ) {
		$newForum = array(
			'id' => '215',
			'name' => 'Interdisciplinary Approaches to Culture and Society',
			'type' => 'Forum',
			'convention_code' => 'G017',
			'position' => 'Member',
			'exclude_from_commons' => '',
		);

		// add new forum to mock user's list of forums
		$member_json['organizations'][] = $newForum;

		// Get test data and convert it to an array.
		$convertermethod = $this->get_method( 'member_json_to_array' );
		$member_array = $convertermethod->invoke( new CustomAuthentication, $member_json, 'test' );

		// Now take the array and feed it to `manage_groups()`, which should hopefully
		// detect that there is a difference in roles among the two groups, and
		// demote our user accordingly.
		//_log( 'Now managing groups again with our demoted user.' );
		$method = $this->get_method( 'manage_groups' );
		$retval = $method->invoke( new CustomAuthentication, 2, $member_array['groups'] );

		// Now we should see our new forum appear in slot 6.
		$interdisciplinary = groups_get_id( 'interdisciplinary-approaches-to-culture-and-society' );

		// We assume that our example user has the BP user ID of 2.
		//_log( "Checking that user 2 is NOT an admin of group id: $interdisciplinary" );
		$is_admin = groups_is_user_admin( 2, $interdisciplinary );

		// groups_is_user_admin() returns membership ID (int) if user is admin,
		// and returns 0 or NULL, it seems, if user is not.
		$this->assertFalse( ( is_int( $is_admin ) && $is_admin > 0 ) );
	}

	public function provider_ajax_validate_preferred_username() {
		// use string 'true' and 'false' to match json response
		return [
			[ '', '123a', 'true' ],
			[ '', '123', 'false' ],                   // too short
			[ '', '12345678901234567890a', 'false' ], // too long
			[ '', '1234567890', 'false' ],            // contains no letters
			[ '', 'abcD', 'false' ],                  // contains uppercase
			[ '', 'abc-', 'false' ],                  // contains a non-alphanumeric character other than underscore
			[ 'abcd', 'abcd', 'true' ],               // username === preferred (short-circuit, considered valid)

			// TODO it would be nice to be able to test duplicate checking here, but that's probably a job for testing against the MLAApiRequest class
			//[ '', 'czarate', 'false' ],             // duplicate
		];
	}

	/**
	 * @group ajax
	 * @dataProvider provider_ajax_validate_preferred_username
	 * @covers ::ajax_validate_preferred_username
	 *
	 * @param string $username username to test
	 * @param string $expected_result either "true" or "false"
	 */
	public function test_validate_preferred_username( $username, $preferred, $expected_result ) {
		// we need this to make wp_die() throw a WPAjaxDieStopException and Base::setup() does not call it
		WP_Ajax_UnitTestCase::setUp();

		$_POST['preferred'] = $preferred;
		$_POST['username'] = $username;
		$_POST['password'] = '';

		// expect an exception with a message containing the json response
		try {
			$this->_handleAjax( 'nopriv_validate_preferred_username' );
		} catch ( WPAjaxDieStopException $e ) {
			$response = json_decode( $e->getMessage() );

			$this->assertInternalType( 'object', $response );
			$this->assertObjectHasAttribute( 'result', $response );
			$this->assertObjectHasAttribute( 'message', $response );

			// expect non-empty error message if $preferred did not validate for any reason
			if ( $response->result === 'false' ) {
				$this->assertNotEmpty( $response->message );
			}

			$this->assertEquals( $expected_result, $response->result );
		}
	}

	/**
	 * this only provides good & bad password matches.
	 * TODO find a way to test active status & other potential issues (might need to adjust check for empty message)
	 */
	public function provider_test_ajax_test_user() {
		// use string 'true' and 'false' to match json response
		return [
			[ '999999', 'test', 'true' ],
			[ '999999', '', 'false' ],
			[ 'exampleuser', 'test', 'true' ],
			[ 'exampleuser', '', 'false' ],
		];
	}

	/**
	 * @group ajax
	 * @dataProvider provider_test_ajax_test_user
	 * @covers ::ajax_test_user
	 *
	 * @param string $username_or_member_number
	 * @param string $password
	 * @param string $expected_result either "true" or "false"
	 */
	public function test_ajax_test_user( $username_or_member_number, $password, $expected_result ) {
		// we need this to make wp_die() throw a WPAjaxDieStopException and Base::setup() does not call it
		WP_Ajax_UnitTestCase::setUp();

		$_POST['username'] = $username_or_member_number;
		$_POST['password'] = $password;

		// expect an exception with a message containing the json response
		try {
			$this->_handleAjax( 'nopriv_test_user' );
		} catch ( WPAjaxDieStopException $e ) {
			$response = json_decode( $e->getMessage() );

			$this->assertInternalType( 'object', $response );
			$this->assertObjectHasAttribute( 'result', $response );
			$this->assertObjectHasAttribute( 'guess', $response );
			$this->assertObjectHasAttribute( 'message', $response );

			// if new test data is added (checking anything other than password matching),
			// might need to refactor ajax_test_user() to handle other errors more consistently
			// for this to make sense
			if ( $response->result === 'false' ) {
				$this->assertNotEmpty( $response->message );
			}

			$this->assertEquals( $expected_result, $response->result );
		}
	}

	/**
	 * This must run after test_ajax_test_user since it actually creates a WP user and changes the behavior of that function.
	 *
	 * @covers ::authenticate_username_password
	 * @dataProvider provider_member_json
	 */
	public function test_authenticate_username_password( $member_json, $username, $password ) {
		$_POST['preferred'] = ''; // the function expects this to be set
		$_POST['acceptance'] = false; // the function expects this to be set, too
		$_SERVER['SERVER_PORT'] = 0; // the function expects this to be set, too

		$method = $this->get_method( 'authenticate_username_password' );
		$retval = $method->invoke( new CustomAuthentication, null, $username, $password );

		// this tests if the valid user (returned as valid from the API)
		// is correctly added to the database, which should return an instance
		// of WP_User from the function AuthenticateUsernamePassword.
		$this->assertInstanceOf( 'WP_User', $retval );
	}

}
?>
