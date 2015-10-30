<?php

/**
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

require_once 'class-custom-authentication.php';

/**
 * @coversDefaultClass CustomAuthentication
 */
class CustomAuthenticationTest extends WP_Ajax_UnitTestCase {

	/**
	 * allow access to protected methods
	 * http://stackoverflow.com/a/2798203/700113
	 */
	protected function getMethod( $name ) {
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
		$method = $this->getMethod( 'member_json_to_array' );
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
		$method = $this->getMethod( 'member_json_to_array' );
		$member_array = $method->invoke( new CustomAuthentication, $member_json, $password );

		// Mock member data should represent a valid, active member.
		$method = $this->getMethod( 'validate_custom_user' );
		$is_valid = $method->invoke( new CustomAuthentication, $member_array, $username );
		$this->assertTrue( $is_valid );

		// Changing the membership status to inactive should throw an error.
		$member_array['status'] = 'inactive';
		$is_valid = $method->invoke( new CustomAuthentication, $member_array, $username );
		$this->assertFalse( $is_valid );
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
	public function test_ajax_test_user( $username_or_member_number, $password, $expected_result) {
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

}
?>
