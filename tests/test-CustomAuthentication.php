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

require_once 'class-CustomAuthentication.php';


/*
 * This class extends PHPUnit to allow us to test protected methods. This is
 * not good practice ... we should instead construct our classes so that the
 * public methods are testable and cover the protected methods. We should also
 * run these tests in the context of WP. This is only a quick and dirty proof
 * of concept.
 */

abstract class Base extends WP_UnitTestCase {

  protected $testClass;
  protected $reflection;

  public function setUp () {
    $this->reflection = new ReflectionClass($this->testClass);
  }

  public function getMethod ($method) {
    $method = $this->reflection->getMethod($method);
    $method->setAccessible(true);
    return $method;
  }

}

/* Test */

class CustomAuthenticationTest extends Base {

  public $MLAAPI;
  public $member_data;
  public $member_json;

  public function setUp () {

    $this->testClass = new CustomAuthentication();
    parent::setUp();

    // Load mocked member data.
    $this->member_data_raw = MLAAPI::get_member(); 
    $this->member_data = json_decode( $this->member_data_raw['body'], TRUE);
    $this->member_json = $this->member_data['data'][0]; 
  }

  public function testClassProperties () {
    // Should be an instance of the MLAAPI abstract class
    $this->assertInstanceOf('MLAAPI', $this->testClass);
  }

  public function testMockDataLoaded() { 
	  $mockdata = $this->member_data; 
	  $this->assertInternalType('array', $mockdata);
  } 

  public function testMemberJSONToArray() { 
    $method = $this->getMethod('memberJSONToArray');
    $member_array = $method->invoke($this->testClass, $this->member_json, 'test');

    // check that resulting array is in fact an array
    $this->assertInternalType('array', $member_array);

    // check that resulting array has required fields. 
    $required_fields = array( 'id', 'user_name', 'status', 'password' ); 
    foreach ( $required_fields as $required_field ) { 
	    $this->assertArrayHasKey( $required_field, $member_array ); 
    } 

    // Mock member data should represent a valid, active member.
    $method = $this->getMethod('validateCustomUser');
    $isValid = $method->invoke($this->testClass, $member_array, 'exampleuser');
    $this->assertTrue($isValid);

    // Changing the membership status to inactive should throw an error.
    // However, this currently generates a fatal error as WP_Error is not found.
    $member_array['status'] = 'inactive';
    $method = $this->getMethod('validateCustomUser');
    $isValid = $method->invoke($this->testClass, $member_array, 'exampleuser');
    $this->assertFalse($isValid);
  } 

  public function testValidUserAuthentication() { 
	  $_POST['preferred'] = ''; // the function expects this to be set 
	  $_POST['acceptance'] = false; // the function expects this to be set, too
	  $_SERVER['SERVER_PORT'] = 0; // the function expects this to be set, too
	  $method = $this->getMethod('authenticate_username_password');
	  // username should be `exampleuser`
	  $username = 'exampleuser'; 
	  // password should be `test`. 
	  $password = 'test'; 
	  $retval = $method->invoke( $this->testClass, '', $username, $password );
	  // this tests if the valid user (returned as valid from the API) 
	  // is correctly added to the database, which should return an instance
	  // of WP_User from the function AuthenticateUsernamePassword. 
	  $this->assertInstanceOf( 'WP_User', $retval );  
  } 

  public function testInvalidUserAuthentication() { 
	  $_POST['preferred'] = ''; // the function expects this to be set 
	  $_POST['acceptance'] = false; // the function expects this to be set, too
	  $_SERVER['SERVER_PORT'] = 0; // the function expects this to be set, too
	  $method = $this->getMethod('authenticate_username_password');
	  // credentials are bogus! Don't let this person in!
	  $username = 'notauser'; 
	  $password = 'whatever'; 
	  $retval = $method->invoke( $this->testClass, '', $username, $password );
	  // this tests if the valid user (returned as valid from the API) 
	  // is correctly added to the database, which should return an instance
	  // of WP_User from the function AuthenticateUsernamePassword. 
	  $this->assertInstanceOf( 'WP_Error', $retval );  

  } 
  public function testGroupCreation() { 
	  // Get test data and convert it to an array. 
	  $convertermethod = $this->getMethod('memberJSONToArray');
	  $member_array = $convertermethod->invoke($this->testClass, $this->member_json, 'test');

	  // Now take the array and feed it to `manageGroups()`, which is going to add
	  // groups that don't exist (i.e. all of them). 
	  $method = $this->getMethod('manageGroups');
	  $retval = $method->invoke( $this->testClass, $member_array['id'], $member_array['groups'] );

	  // Now since our test data has a bunch of test groups in it, 
	  // and since our database doesn't currently have these groups, 
	  // the method manageGroups should've created these groups for us, 
	  // and we should be able to see them now in the database. 

	  // this looks for groups by their slug. We should see 'hebrew', 
	  // 'hungarian', 'travel-writing', etc. 
	  $hebrew = groups_get_id( 'hebrew' ); 
	  $hungarian = groups_get_id( 'hungarian' ); 
	  $travel_writing = groups_get_id( 'travel-writing' ); 
	  $advisory = groups_get_id( 'advisory-comm-on-foreign-lang-programs' ); 
	  $women = groups_get_id( 'committee-on-the-status-of-women-in-the-profession' ); 
	  $research = groups_get_id( 'office-of-research' ); 

	  _log( 'groups!', $member_array['groups'] ); 

	  $this->assertEquals( $hebrew, 1 ); 
	  $this->assertEquals( $hungarian, 2 ); 
	  $this->assertEquals( $travel_writing, 3 ); 
	  $this->assertEquals( $advisory, 4 ); 
	  $this->assertEquals( $women, 5 ); 
	  //$this->assertEquals( $research, 0 ); 
  } 
}

?>
