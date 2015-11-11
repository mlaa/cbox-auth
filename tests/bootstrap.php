<?php

// we're using mock data but we need to define these to avoid errors
define( 'CBOX_AUTH_API_KEY', '' );
define( 'CBOX_AUTH_API_SECRET', '' );
define( 'CBOX_AUTH_API_URL', '' );

// Get codebase versions.
$wp_version = ( getenv( 'WP_VERSION' ) ) ? getenv( 'WP_VERSION' ) : 'latest';
$bp_version = ( getenv( 'BP_VERSION' ) ) ? getenv( 'BP_VERSION' ) : 'latest';

// Get paths to codebase installed by install script.
$wp_root_dir = "/tmp/wordpress/$wp_version/src/";
$wp_tests_dir = "/tmp/wordpress/$wp_version/tests/phpunit";
$bp_tests_dir = "/tmp/buddypress/$bp_version/tests";
$bp_tests_dir .= ( 'latest' === $bp_version || intval( $bp_version ) >= 2.1 ) ? '/phpunit' : '';

// Set required environment variables.
putenv( 'WP_TESTS_DIR=' . $wp_tests_dir );
putenv( 'BP_TESTS_DIR=' . $bp_tests_dir );
putenv( 'WP_ABSPATH=' . $wp_root_dir );

// Load WordPress.
require_once $wp_tests_dir . '/includes/functions.php';

// Load plugin files.
function _manually_load_plugin() {

	$_tests_dir = dirname( __FILE__ );

	// Load BuddyPress
	require_once getenv( 'BP_TESTS_DIR' ) . '/includes/loader.php';

	// We'll need this so that we can use `_log()`.
	// TODO find a way to avoid duplicating this file from mu-plugins
	require_once $_tests_dir . '/debug.php';

	// Override API communications to insert mock data.
	// Used in place of the class MLAAPIRequest.
	require_once $_tests_dir . '/class-mock-mla-api-request.php';

	// get the functions common to both the MLAGroup and MLAMember classes.
	require_once $_tests_dir . '/../class-mla-api.php';

	// Don't get the whole plugin now, just a few classes, because
	// to test them individually we feed them mock data above.
	require_once $_tests_dir . '/../class-custom-authentication.php';
	require_once $_tests_dir . '/../class-mla-group.php';

	// since we're not loading the whole plugin, manually add ajax actions to be tested
	$myCustomAuthentication = new CustomAuthentication();
	add_action( 'wp_ajax_nopriv_test_user', array( $myCustomAuthentication, 'ajax_test_user' ) );
	add_action( 'wp_ajax_nopriv_validate_preferred_username', array( $myCustomAuthentication, 'ajax_validate_preferred_username' ) );
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Bootstap tests.
require_once $wp_tests_dir . '/includes/bootstrap.php';
