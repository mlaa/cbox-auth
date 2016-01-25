<?php
/**
 * Test Base class
 *
 * @package CustomAuthTests
 * @group Base
 */

namespace MLA\Commons\Plugin\CustomAuth\Tests;

use \MLA\Commons\Plugin\CustomAuth\Base;

/**
 * Dummy class for spying
 *
 * @class Dummy
 * @group Base
 */
class Dummy {

	/**
	 * Dummy action
	 */
	public function dummy_action() {}

	/**
	 * Dummy filter
	 */
	public function dummy_filter() {}
}

/**
 * Test Base class
 *
 * @class Test_Base
 * @group Base
 */
class Test_Base extends \BP_UnitTestCase {

	/**
	 * Instance of Base
	 *
	 * @var object
	 */
	private static $base;

	/**
	 * Class set up. Create a test class and dummy to spy on.
	 */
	public static function setUpBeforeClass() {
		self::$base = new Base();
	}

	/**
	 * Test running the added action and passed parameter.
	 */
	public function test_run_action() {

		// Create spy.
		$dummy = $this->getMock( 'Dummy', array( 'dummy_action' ) );
		self::$base->add_action( 'test_action', $dummy, 'dummy_action' );
		self::$base->run();

		// Assert that the spy will be called.
		$dummy
			->expects( $this->once() )
			->method( 'dummy_action' )
			->with( $this->equalTo( 'action_param' ) );

		\do_action( 'test_action', 'action_param' );

	}

	/**
	 * Test running the added filter and passed parameter.
	 */
	public function test_run_filter() {

		// Create spy.
		$dummy = $this->getMock( 'Dummy', array( 'dummy_filter' ) );
		self::$base->add_filter( 'test_filter', $dummy, 'dummy_filter' );
		self::$base->run();

		// Assert that the spy will be called.
		$dummy
			->expects( $this->once() )
			->method( 'dummy_filter' )
			->with( $this->equalTo( 'filter_param' ) );

		\apply_filters( 'test_filter', 'filter_param' );

	}

	/**
	 * Test whether the test script was enqueued.
	 */
	public function test_enqueue_script() {
		self::$base->enqueue_script( 'test_script', 'test_script' );
		self::$base->run();
		$this->assertTrue( \wp_script_is( 'test_script', 'enqueued' ) );
	}

	/**
	 * Test whether the test style was enqueued.
	 */
	public function test_enqueue_style() {
		self::$base->enqueue_style( 'test_style', 'test_style' );
		self::$base->run();
		$this->assertTrue( \wp_style_is( 'test_style', 'enqueued' ) );
	}

	/**
	 * Test whether superglobal is retrieved.
	 */
	public function test_get_superglobal() {
		$this->assertContains( 'phpunit', self::$base->get_superglobal( INPUT_SERVER, 'PHP_SELF' ) );
	}
}
