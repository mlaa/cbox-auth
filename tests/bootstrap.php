<?php

// set a flag so that certain functions know we're running tests. 
define( 'RUNNING_TESTS', TRUE ); 

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wp_tests_dir ) $wp_tests_dir = '/tmp/wordpress-tests-lib';
define( 'WP_TESTS_DIR', $wp_tests_dir ); 

$bp_tests_dir = getenv( 'BP_TESTS_DIR' ); 
if ( ! $bp_tests_dir ) $bp_tests_dir = '/tmp/buddypress/tests/phpunit';
define( 'BP_TESTS_DIR', $bp_tests_dir ); 

echo 'Done defining stuff! '; 

require_once $wp_tests_dir . '/includes/functions.php';

require $wp_tests_dir . '/includes/bootstrap.php';

require $bp_tests_dir . '/includes/loader.php';

echo 'Done requiring test dirs! ' ; 

function _manually_load_plugin() {
	require BP_TESTS_DIR . '/includes/loader.php';

	// this is my debugging file, which ensures that tests won't fail 
	// if there's a call to `_log()`. 
	require dirname( __FILE__ ) . '/debug.php';

	// override this class to load mock data
	require dirname( __FILE__ ) . '/class-MockMLAAPI.php'; 

	// don't get the whole plugin now, just a few classes, because 
	// to test them individually we feed them mock data above. 
	require dirname( __FILE__ ) . '/../class-CustomAuthentication.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// this is my debugging file, which ensures that tests won't fail 
// if there's a call to `_log()`. 
require dirname( __FILE__ ) . '/debug.php';

_log( 'using BP_TESTS_DIR:', BP_TESTS_DIR ); 

// Requiring this file gives you access to BP_UnitTestCase
require $bp_tests_dir . '/includes/testcase.php';
