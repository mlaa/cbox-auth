<?php
/*
Plugin Name: CBOX Authentication
Author: Peter Soots, Cast Iron Coding
Author URI: http://www.castironcoding.com
*/
/**
 * This plugin verifies the wordpress login process against
 * a remote database via an HTTPS API.
 */


require_once plugin_dir_path(__FILE__).'class-CustomAuthentication.php';
require_once plugin_dir_path(__FILE__).'class-MLAMember.php';

$myCustomAuthentication = new CustomAuthentication();

// Do one-time stuff on activation
register_activation_hook(__FILE__, 'activateCustomAuthentication');

// Hook into the 'authenticate' filter. This is where everything begins.
add_filter('authenticate', array($myCustomAuthentication, 'authenticate_username_password'), 1, 3);
add_action('wp_ajax_nopriv_test_user', array($myCustomAuthentication, 'ajax_test_user'));
add_action('wp_ajax_nopriv_validate_preferred_username', array($myCustomAuthentication, 'ajax_validate_preferred_username'));

// We're completely ignoring the WP authentication process.
remove_filter('authenticate', 'wp_authenticate_username_password', 20, 3);

// Reflect changes in division and discussion groups in the MLA database
add_action('groups_leave_group', array($myCustomAuthentication, 'remove_user_from_group'));
add_action('groups_join_group',  array($myCustomAuthentication, 'add_user_to_group'));

// Hide join/leave buttons where necessary
add_action('bp_directory_groups_actions', array($myCustomAuthentication, 'hide_join_button'), 1);
add_action('bp_group_header_actions', array($myCustomAuthentication, 'hide_join_button'), 1);

// Only show some group settings
add_filter('bp_group_settings_allowed_sections', array($myCustomAuthentication, 'determine_group_settings_sections'));

// Don't show the request membership tab for committee groups
add_filter('bp_get_options_nav_request-membership', array($myCustomAuthentication, 'hide_request_membership_tab'));
add_filter('bp_get_options_nav_invite', array($myCustomAuthentication, 'hide_send_invites_tab'));

// Set up the custom profile and login fields
require plugin_dir_path(__FILE__).'fields.php';

function activateCustomAuthentication() {
	$myCustomAuthentication = new CustomAuthentication();
	$myCustomAuthentication->activate();
}
