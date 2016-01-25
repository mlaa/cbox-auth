<?php
/**
 * Test LoginForm class
 *
 * @package CustomAuthTests
 * @group LoginForm
 */

namespace MLA\Commons\Plugin\CustomAuth\Tests;

use \MLA\Commons\Plugin\CustomAuth\LoginForm;
use \MLA\Commons\Plugin\CustomAuth\MLAAPI;
use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Test LoginForm class
 *
 * @class Test_LoginForm
 * @group LoginForm
 */
class Test_LoginForm extends \BP_UnitTestCase {

	/**
	 * Login form instance
	 *
	 * @var object
	 */
	protected static $login_form;

	/**
	 * Class set up.
	 */
	public static function setUpBeforeClass() {

		// Create null logger.
		$logger = new Logger();
		$logger->createLog();

		// Create MLA API instance with mock cURL driver.
		$mock_curl_driver = new MockCurlDriver();
		$mla_api = new MLAAPI( $mock_curl_driver, $logger );

		self::$login_form = new LoginForm( $mla_api, $logger );

	}

	/**
	 * Test for extra login HTML.
	 */
	public function test_output_additional_html() {

		ob_start();
		self::$login_form->output_additional_html();

		$this->assertContains( 'input type="text" id="user_login_preferred"', ob_get_contents() );
		ob_end_clean();

	}
}
