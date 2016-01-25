<?php
/**
 * Login Processor
 *
 * @package CustomAuth
 */

namespace MLA\Commons\Plugin\CustomAuth;

use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Sets up a custom authentication handler for WordPress. Sync member data from
 * API and adjust BuddyPress group memberships.
 *
 * @package CustomAuth
 * @subpackage LoginProcessor
 * @class LoginProcessor
 */
class LoginProcessor extends Base {

	/**
	 * Dependency: MLAAPI
	 *
	 * @var object
	 */
	private $mla_api;

	/**
	 * Dependency: Logger
	 *
	 * @var object
	 */
	private $logger;

	/**
	 * Constructor
	 *
	 * @param MLAAPI $mla_api Dependency: MLAAPI.
	 * @param Logger $logger  Dependency: Logger.
	 */
	public function __construct( MLAAPI $mla_api, Logger $logger ) {

		$this->mla_api = $mla_api;
		$this->logger = $logger;

		$this->add_filter( 'authenticate', $this, 'authenticate_user', 1, 3 );
		$this->run();

		// Completely remove the default WordPress authentication handler.
		\remove_filter( 'authenticate', 'wp_authenticate_username_password', 20, 3 );

	}

	/**
	 * Set a cookie for returning users.
	 *
	 * @param string $value Cookie value (username).
	 */
	private function set_remember_cookie( $value ) {

		// Skip setting cookie if we're running unit tests.
		if ( defined( 'RUNNING_TESTS' ) ) {
			return;
		}

		// $_SERVER values are injected into $this->data_cache.
		$secure_connection = ( 443 === $this->data_cache['server_port'] );

		setcookie( // @codingStandardsIgnoreLine WordPress.VIP.Batcache
			'MLABeenHereBefore',
			md5( $value ),
			time() + ( 20 * 365 * 24 * 60 * 60 ),
			null,
			null,
			$secure_connection
		);

	}

	/**
	 * This function is hooked into the 'authenticate' filter so that we can
	 * authenticate the user's credentials from the login form.
	 *
	 * @param WP_User $wp_user  Always null because the user is not logged in.
	 * @param string  $username MLA member ID or username.
	 * @param string  $password Password.
	 * @return WP_User|WP_Error
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity,PHPMD.NPathComplexity,PHPMD.ExcessiveMethodLength)
	 */
	public function authenticate_user( $wp_user, $username, $password ) {

		if ( $wp_user instanceof \WP_User ) {
			return $wp_user;
		}

		// Reject empty credentials.
		if ( empty( $username ) || empty( $password ) ) {
			return new \WP_Error();
		}

		// Forbidden usernames that we don't even bother checking.
		$forbidden_usernames = array(
			'admin',
			'administrator',
		);
		if ( in_array( $username, $forbidden_usernames, true ) ) {
			return new \WP_Error( 'forbidden_username', \__( 'This username has been blocked.' ) );
		}

		// Nonmembers (including super admins) are stored in WP. Check WP first.
		$local_user = \get_user_by( 'login', $username );
		if ( $local_user ) {

			$is_super_admin = \is_super_admin( $local_user->ID );
			// @codingStandardsIgnoreStart WordPress.VIP.UserMeta
			$is_nonmember = ( 'yes' === \get_user_meta( $local_user->ID, 'mla_nonmember', true ) );
			// @codingStandardsIgnoreEnd
			$is_valid_local_user = ( $is_super_admin || $is_nonmember );

			if ( $is_valid_local_user && \wp_check_password( $password, $local_user->data->user_pass, $local_user->ID ) ) {
				return $local_user;
			}
		}

		// Validate the user's credentials against the API.
		try {

			$member = new MLAMember( false, $this->mla_api, $this->logger );
			$member->authenticate( $username, $password );

			// If the member doesn't have an account in WP, create it.
			if ( ! $member->user_id ) {

				// $_POST values are injected into $this->data_cache.
				$member->create_wp_user( $this->data_cache['preferred'], $this->data_cache['accepted'] );

				// New users should go to their profile.
				$this->add_filter( 'login_redirect', $this, 'redirect_to_profile', 10, 3 );
				$this->run();

			}

			// Sync the member's data and group memberships.
			$member->sync();

			// Add a cookie to speed up the login process for returning users.
			$this->set_remember_cookie( $this->data_cache['preferred'] );

			// Return a WP_User.
			return new \WP_User( $member->user_id );

		} catch ( \Exception $e ) {

			$this->logger->addDebug( $e->getMessage() );
			$message = '<strong>Error (' . $e->getCode() . '):</strong> ';

			switch ( $e->getCode() ) {

				case 400:
					return new \WP_Error(
						'not_authorized',
						\__( $message . 'The user name and password combination you entered is invalid. Please try again.' ),
						\wp_lostpassword_url()
					);

				case 401:
					return new \WP_Error(
						'not_authorized',
						\__( $message . 'Your membership is not active.' )
					);

				case 450:
					return new \WP_Error(
						'not_authorized',
						\__( $message . 'User names must be between four and twenty characters in length and must contain at least one letter. Only lowercase letters, numbers, and underscores are allowed.' )
					);

				case 460:
					return new \WP_Error(
						'not_authorized',
						\__( $message . 'That user name already exists.' )
					);

				case 510:
					if ( strpos( $e->getMessage(), 'API-2100' ) ) {
						return new \WP_Error(
							'not_authorized',
							\__( $message . 'The user name and password combination you entered is invalid. Please try again.' ),
							\wp_lostpassword_url()
						);
					}

			}

			$this->logger->addError( $e->getMessage() );

		}

		return new \WP_Error(
			'server_error',
			\__( $message . 'There was a problem communicating with the server. Please try again later.' )
		);

	}

	/**
	 * A filter for 'login_redirect' that redirects users to the profile page.
	 *
	 * @param string $redirect_to URL to redirect to.
	 * @param string $request_url URL the user is coming from.
	 * @param object $user        Logged-in user.
	 * @return string
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function redirect_to_profile( $redirect_to = null, $request_url = null, $user = null ) {
		if ( isset( $user->user_login ) ) {
			return \home_url( 'members/' . $user->user_login . '/profile/edit' );
		}
		return $redirect_to;
	}
}
