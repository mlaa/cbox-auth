<?php

// set a flag so that certain functions know we're running tests. 
define( 'RUNNING_TESTS', TRUE ); 

$_tests_dir = getenv('WP_TESTS_DIR');
if ( ! $_tests_dir ) $_tests_dir = '/tmp/wordpress-tests-lib';
require_once $_tests_dir . '/includes/functions.php';

if ( ! getenv( 'BP_TESTS_DIR' ) ) { 
	define( 'BP_TESTS_DIR', '/tmp/buddypress/tests/phpunit' ); 
} else { 
	define( 'BP_TESTS_DIR', getenv( 'BP_TESTS_DIR' ) ); 
} 

function _manually_load_plugin() {
	// Load BuddyPress!
	require BP_TESTS_DIR . '/includes/loader.php';

	// We'll need this so that we can use `_log()`. 
	require dirname( __FILE__ ) . '/debug.php';

	// Override API communications to insert mock data.
	// Used in place of the class MLAAPIRequest. 
	require dirname( __FILE__ ) . '/class-MockMLAAPIRequest.php'; 
	// get the functions commons to both the MLAGroup and MLAMember classes. 
	require dirname( __FILE__ ) . '/class-MLAAPI.php'; 

	// Don't get the whole plugin now, just a few classes, because 
	// to test them individually we feed them mock data above. 
	require dirname( __FILE__ ) . '/../class-CustomAuthentication.php';
	require dirname( __FILE__ ) . '/../class-MLAGroup.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';

