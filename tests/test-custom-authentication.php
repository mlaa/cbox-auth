<?php
/*
 * PHPUnit tests
 *
 * Download:
 *   wget https://phar.phpunit.de/phpunit.phar
 *
 * Run:
 *   php phpunit.phar test-MLAAPI.php
 *
 * OR...
 *
 * Download:
 *   sudo apt-get install phpunit
 *
 * Run:
 *   cd /path/to/cbox-auth
 *   phpunit
 *
 */


/* Dependencies */

require_once 'class-custom-authentication.php';


/*
 * This class extends PHPUnit to allow us to test protected methods. This is
 * not good practice ... we should instead construct our classes so that the
 * public methods are testable and cover the protected methods. We should also
 * run these tests in the context of WP. This is only a quick and dirty proof
 * of concept.
 */

abstract class Base extends WP_Ajax_UnitTestCase {

	protected $test_class;
	protected $reflection;

	public function setup () {
		$this->reflection = new ReflectionClass( $this->test_class );
	}

	public function get_method ($method) {
		$method = $this->reflection->getMethod( $method );
		$method->setAccessible( true );
		return $method;
	}

}

/* Test */

class CustomAuthenticationTest extends Base {

	public $MLAAPI;
	public $member_data;
	public $member_json;

	public function setup () {

		$this->test_class = new CustomAuthentication();
		parent::setup();

		// Load mocked member data.
		$this->member_data_raw = $this->test_class->get_member();
		$this->member_data = json_decode( $this->member_data_raw['body'], true );
		$this->member_json = $this->member_data['data'][0];
	}

	public function test_class_properties () {
		// Should be an instance of the MLAAPI abstract class
		$this->assertInstanceOf( 'MLAAPI', $this->test_class );
	}

	public function test_mock_data_loaded() {
		$mockdata = $this->member_data;
		$this->assertInternalType( 'array', $mockdata );
	}

	public function test_member_json_to_array() {
		$method = $this->get_method( 'member_json_to_array' );
		$member_array = $method->invoke( $this->test_class, $this->member_json, 'test' );

		// check that resulting array is in fact an array
		$this->assertInternalType( 'array', $member_array );

		// check that resulting array has required fields.
		$required_fields = array( 'id', 'user_name', 'status', 'password' );
		foreach ( $required_fields as $required_field ) {
			$this->assertArrayHasKey( $required_field, $member_array );
		}

		// Mock member data should represent a valid, active member.
		$method = $this->get_method( 'validate_custom_user' );
		$isValid = $method->invoke( $this->test_class, $member_array, 'exampleuser' );
		$this->assertTrue( $isValid );

		// Changing the membership status to inactive should throw an error.
		// However, this currently generates a fatal error as WP_Error is not found.
		$member_array['status'] = 'inactive';
		$method = $this->get_method( 'validate_custom_user' );
		$isValid = $method->invoke( $this->test_class, $member_array, 'exampleuser' );
		$this->assertFalse( $isValid );
	}

	public function test_valid_user_authentication() {
		$_POST['preferred'] = ''; // the function expects this to be set
		$_POST['acceptance'] = false; // the function expects this to be set, too
		$_SERVER['SERVER_PORT'] = 0; // the function expects this to be set, too
		$method = $this->get_method( 'authenticate_username_password' );
		// username should be `exampleuser`
		$username = 'exampleuser';
		// password should be `test`.
		$password = 'test';
		$retval = $method->invoke( $this->test_class, '', $username, $password );
		// this tests if the valid user (returned as valid from the API)
		// is correctly added to the database, which should return an instance
		// of WP_User from the function AuthenticateUsernamePassword.
		$this->assertInstanceOf( 'WP_User', $retval );
	}

	public function test_invalid_user_authentication() {
		$_POST['preferred'] = ''; // the function expects this to be set
		$_POST['acceptance'] = false; // the function expects this to be set, too
		$_SERVER['SERVER_PORT'] = 0; // the function expects this to be set, too
		$method = $this->get_method( 'authenticate_username_password' );
		// credentials are bogus! Don't let this person in!
		$username = 'notauser';
		$password = 'whatever';
		$retval = $method->invoke( $this->test_class, '', $username, $password );
		// this tests if the valid user (returned as valid from the API)
		// is correctly added to the database, which should return an instance
		// of WP_User from the function AuthenticateUsernamePassword.
		$this->assertInstanceOf( 'WP_Error', $retval );

	}
	public function test_group_creation() {
		// Get test data and convert it to an array.
		$convertermethod = $this->get_method( 'member_json_to_array' );
		$member_array = $convertermethod->invoke( $this->test_class, $this->member_json, 'test' );

		// Now take the array and feed it to `manage_groups()`, which is going to add
		// groups that don't exist (i.e. all of them).
		$method = $this->get_method( 'manage_groups' );
		$retval = $method->invoke( $this->test_class, 2, $member_array['groups'] );

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
	public function test_committee_creation() {
		// Committees should be private groups.
		$committee_id = groups_get_id( 'committee-on-the-status-of-women-in-the-profession' );
		$committee_group = groups_get_group( array( 'group_id' => $committee_id ) );

		//_log( 'Group is: ', $committee_group );
		//_log( 'Group status is: ', $committee_group->status );
		$this->assertEquals( 'private', $committee_group->status );
	}
	/*
	* Add a new forum to a user's member forums,
	* then make sure this forum is created in the database.
	* This depends on all the prior tests to have run correctly.
	*/
	public function test_new_forum() {
		$member_json = $this->member_json;

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
		$member_array = $convertermethod->invoke( $this->test_class, $member_json, 'test' );

		// Now take the array and feed it to `manage_groups()`, which is going to add
		// groups that don't exist (i.e. the one we just added).
		// Assumes our new user has ID of 2.
		$method = $this->get_method( 'manage_groups' );
		$retval = $method->invoke( $this->test_class, 2, $member_array['groups'] );

		// Now we should see our new forum appear in slot 6.
		$interdisciplinary = groups_get_id( 'interdisciplinary-approaches-to-culture-and-society' );

		$this->assertTrue( $interdisciplinary > 0 );
	}

	/*
	* OK, now that we've tested the creation of a new forum, that creation
	* should've also synced the group using `MLAGroup::sync()`. If that's
	* the case, then our beloved member `exampleuser` should be a member of that group.
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

	/*
	* But since our member is actually the chair of this new group, let's test
	* that our member is, in fact, a chair of the corresponding BuddyPress group.
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
	/*
	* This one is very much like testNewForum() above, but it checks to see whether
	* cbox-auth correctly demotes a user that has been demoted in the MLA database.
	* Here, the crucial distinction here is that "position" is "Member" instead of "Chair."
	*/
	public function test_demoted_user() {
		$member_json = $this->member_json;

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
		$member_array = $convertermethod->invoke( $this->test_class, $member_json, 'test' );

		// Now take the array and feed it to `manage_groups()`, which should hopefully
		// detect that there is a difference in roles among the two groups, and
		// demote our user accordingly.
		//_log( 'Now managing groups again with our demoted user.' );
		$method = $this->get_method( 'manage_groups' );
		$retval = $method->invoke( $this->test_class, 2, $member_array['groups'] );

		// Now we should see our new forum appear in slot 6.
		$interdisciplinary = groups_get_id( 'interdisciplinary-approaches-to-culture-and-society' );

		// We assume that our example user has the BP user ID of 2.
		//_log( "Checking that user 2 is NOT an admin of group id: $interdisciplinary" );
		$is_admin = groups_is_user_admin( 2, $interdisciplinary );

		// groups_is_user_admin() returns membership ID (int) if user is admin,
		// and returns 0 or NULL, it seems, if user is not.
		$this->assertFalse( ( is_int( $is_admin ) && $is_admin > 0 ) );
	}

	public function provider_validate_preferred_username() {
		// use string 'true' and 'false' to match json response
		return array(
			array( '', '123a', 'true' ),
			array( '', '123', 'false' ),                   // too short
			array( '', '12345678901234567890a', 'false' ), // too long
			array( '', '1234567890', 'false' ),            // contains no letters
			array( '', 'abcD', 'false' ),                  // contains uppercase
			array( '', 'abc-', 'false' ),                  // contains a non-alphanumeric character other than underscore
			array( 'abcd', 'abcd', 'true' ),               // username === preferred (short-circuit, considered valid)

			// TODO it would be nice to be able to test duplicate checking here, but that's probably a job for testing against the MLAApiRequest class
			//array( '', 'czarate', 'false' ),             // duplicate
		);
	}

	/**
	 * @group ajax
	 * @dataProvider provider_validate_preferred_username
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
			$response = json_decode($e->getMessage());

			$this->assertInternalType( 'object', $response );
			$this->assertObjectHasAttribute( 'result', $response );
			$this->assertObjectHasAttribute( 'message', $response );

			// expect non-empty error message if $preferred did not validate for any reason
			if ($response->result === 'false') {
				$this->assertNotEmpty($response->message);
			}

			$this->assertEquals( $expected_result, $response->result );
		}
	}

}
?>
