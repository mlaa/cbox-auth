<?php
/**
 * Plugin Name: CBOX Authentication
 * @link https://github.com/mlaa/cbox-auth
 * @package cbox-auth
 * This plugin verifies the WordPress login process against
 * a remote database via an HTTPS API.
 */

require_once plugin_dir_path( __FILE__ ).'class-mla-api-request.php';
require_once plugin_dir_path( __FILE__ ).'class-mla-api.php';
require_once plugin_dir_path( __FILE__ ).'class-custom-authentication.php';
require_once plugin_dir_path( __FILE__ ).'class-mla-member.php';
require_once plugin_dir_path( __FILE__ ).'class-mla-group.php';

$myCustomAuthentication = new CustomAuthentication();

// Do one-time stuff on activation.
register_activation_hook( __FILE__, 'activate_custom_authentication' );

// Hook into the 'authenticate' filter. This is where everything begins.
add_filter( 'authenticate', array( $myCustomAuthentication, 'authenticate_username_password' ), 1, 3 );
add_action( 'wp_ajax_nopriv_test_user', array( $myCustomAuthentication, 'ajax_test_user' ) );
add_action( 'wp_ajax_nopriv_validate_preferred_username', array( $myCustomAuthentication, 'ajax_validate_preferred_username' ) );

// We're completely ignoring the WP authentication process.
remove_filter( 'authenticate', 'wp_authenticate_username_password', 20, 3 );

// Reflect changes in division and discussion groups in the MLA database.
add_action( 'groups_leave_group', array( $myCustomAuthentication, 'remove_user_from_group' ) );
add_action( 'groups_join_group',  array( $myCustomAuthentication, 'add_user_to_group' ) );

// Hide join/leave buttons where necessary.
add_action( 'bp_directory_groups_actions', array( $myCustomAuthentication, 'hide_join_button' ), 1 );
add_action( 'bp_group_header_actions', array( $myCustomAuthentication, 'hide_join_button' ), 1 );

// Only show some group settings.
add_filter( 'bp_group_settings_allowed_sections', array( $myCustomAuthentication, 'determine_group_settings_sections' ) );

// Don't show the request membership tab for committee groups.
add_filter( 'bp_get_options_nav_request-membership', array( $myCustomAuthentication, 'hide_request_membership_tab' ) );
add_filter( 'bp_get_options_nav_invite', array( $myCustomAuthentication, 'hide_send_invites_tab' ) );

// Set up the custom profile and login fields.
require plugin_dir_path( __FILE__ ).'fields.php';

/**
 * Instatiate and activate plugin code.
 */
function activate_custom_authentication() {
	$myCustomAuthentication = new CustomAuthentication();
	$myCustomAuthentication->activate();
}

/**
 * Sniffs the refering URL and stores it in a cookie
 * so that we can use it later as the target of our redirect.
 */
function mla_sniff_referer() {
	_log( 'Saving referer for later reference. Referer is: ', wp_get_referer() );

	$server_https = ( isset( $_SERVER['HTTPS'] ) ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTPS'] ) ) : false; // Input var okay.
	$server_port = ( isset( $_SERVER['SERVER_PORT'] ) ) ? absint( $_SERVER['SERVER_PORT'] ) : false; // Input var okay.
	if ( 'off' !== $server_https || 443 === $server_port ) {
		$secure_connection = true;
	} else {
		$secure_connection = false;
	}

	if ( ! defined( 'RUNNING_TESTS' ) ) {
		if ( false === strpos( $mla_referer, 'wp-login' ) ) {
			_log( 'Me set cookie!' );
			setcookie( 'MLAReferer', $mla_referer, time() + ( 20 * 365 * 24 * 60 * 60 ), null, null, $secure_connection );
		} else {
			_log( 'Not setting redirect cookie, since referer is wp-login.' );
		}
	} else {
		_log( 'Running tests, so not setting cookies.' );
	}
}
add_action( 'login_head', 'mla_sniff_referer' );
