<?php
/*
Plugin Name: Custom Authentication
Author: Peter Soots, Cast Iron Coding
Author URI: http://www.castironcoding.com
*/
/**
 * This plugin verifies the wordpress login process against
 * a remote database via an HTTPS API.
 */


require_once plugin_dir_path(__FILE__).'class-CustomAuthentication.php';

// Hook into the 'authenticate' filter. This is where everything begins.
$myCustomAuthentication = new CustomAuthtentication();
add_filter('authenticate', array($myCustomAuthentication, 'authenticate_username_password'), 1, 3);
add_action('wp_ajax_nopriv_test_user', array($myCustomAuthentication, 'ajax_test_user'));
add_action('wp_ajax_nopriv_validate_preferred_username', array($myCustomAuthentication, 'ajax_validate_preferred_username'));

// We're completely ignoring the WP authentication process.
remove_filter('authenticate', 'wp_authenticate_username_password', 20, 3);

// Set up the custom profile and login fields
require plugin_dir_path(__FILE__).'fields.php';

?>
