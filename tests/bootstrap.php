<?php

// Set a flag so that certain functions know we're running tests.
define( 'RUNNING_TESTS', TRUE );

// Check for required environment variables.
if ( ! getenv('WP_TESTS_DIR') ) {
  putenv('WP_TESTS_DIR=/tmp/wordpress-tests-lib');
}
if ( ! getenv('BP_TESTS_DIR') ) {
  putenv('BP_TESTS_DIR=/tmp/buddypress/tests/phpunit');
}

// Load WordPress.
require_once getenv('WP_TESTS_DIR') . '/includes/functions.php';

function _manually_load_plugin() {

  $_tests_dir = dirname( __FILE__ );

  // Load BuddyPress
  require_once getenv('BP_TESTS_DIR') . '/includes/loader.php';

  // We'll need this so that we can use `_log()`.
  require_once $_tests_dir . '/debug.php';

  // Override API communications to insert mock data.
  // Used in place of the class MLAAPIRequest.
  require_once $_tests_dir . '/class-MockMLAAPIRequest.php';

  // get the functions common to both the MLAGroup and MLAMember classes.
  require_once $_tests_dir . '/../class-MLAAPI.php';

  // Don't get the whole plugin now, just a few classes, because
  // to test them individually we feed them mock data above.
  require_once $_tests_dir . '/../class-CustomAuthentication.php';
  require_once $_tests_dir . '/../class-MLAGroup.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Bootstap tests.
require_once getenv('WP_TESTS_DIR') . '/includes/bootstrap.php';
