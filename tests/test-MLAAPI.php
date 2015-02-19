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

abstract class Base extends PHPUnit_Framework_TestCase {

  protected $testClass;
  protected $reflection;

  protected function setUp () {
    $this->reflection = new ReflectionClass($this->testClass);
  }

  public function getMethod ($method) {
    $method = $this->reflection->getMethod($method);
    $method->setAccessible(true);
    return $method;
  }

}


/* Test */

class MLAAPITest extends Base {

  protected $MLAAPI;
  protected $member_data;
  protected $member_json;

  protected function setUp () {

    $this->testClass = new CustomAuthentication();
    parent::setUp();

    // Load mocked member data.
    $this->member_json = file_get_contents('tests/data/mock-member.json');
    $this->member_data = json_decode($this->member_json, true)['data'][0];

  }

  public function testClassProperties () {
    // Should be an instance of the MLAAPI abstract class
    $this->assertInstanceOf('MLAAPI', $this->testClass);
  }

  public function testMemberValidation () {

    // Mock member data should be loaded by internal mapping function.
    $method = $this->getMethod('memberJSONToArray');
    $member_array = $method->invoke($this->testClass, $this->member_data, 'test');
    $this->assertInternalType('array', $member_array);

    // Mock member data should represent a valid, active member.
    $method = $this->getMethod('validateCustomUser');
    $isValid = $method->invoke($this->testClass, $member_array, 'exampleuser');
    $this->assertTrue($isValid);

    // Changing the membership status to inactive should throw an error.
    // However, this currently generates a fatal error as WP_Error is not found.
    //$member_array['status'] = 'inactive';
    //$method = $this->getMethod('validateCustomUser');
    //$isValid = $method->invoke($this->testClass, $member_array, 'exampleuser');
    //$this->assertTrue($isValid);

  }

  public function testTranslateMLARole() { 
	  $method = $this->getMethod('translate_mla_role');
	  $retval = $method->invoke($this->testClass, 'chair' );
	  $this->assertTrue( $retval == 'admin' ); 
	  $retval = $method->invoke($this->testClass, 'liaison' );
	  $this->assertTrue( $retval == 'admin' ); 
	  $retval = $method->invoke($this->testClass, 'liason' );
	  $this->assertTrue( $retval == 'admin' ); 
	  $retval = $method->invoke($this->testClass, 'secretary' );
	  $this->assertTrue( $retval == 'admin' ); 
  } 

}

?>
