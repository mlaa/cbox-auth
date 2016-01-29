<?php
/**
 * Login Form
 *
 * @package CustomAuth
 */

namespace MLA\Commons\Plugin\CustomAuth;

use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Modifies the WordPress login form to authenticate against our API using
 * custom fields and AJAX requests.
 *
 * @package CustomAuth
 * @subpackage LoginForm
 * @class LoginForm
 */
class LoginForm extends Base {

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

		$this->add_action( 'login_form', $this, 'output_additional_html' );
		$this->add_action( 'login_enqueue_scripts', $this, 'enqueue_resources' );
		$this->add_action( 'wp_ajax_nopriv_test_user', $this, 'ajax_test_user' );
		$this->add_action( 'wp_ajax_nopriv_validate_preferred_username', $this, 'ajax_validate_preferred_username' );
		$this->run();

	}

	/**
	 * Output additional HTML for WordPress login form.
	 */
	public function output_additional_html() {
?>
		<noscript>
			<p class="warning">
				<strong>Warning:</strong> JavaScript is required when logging in for
				the first time.
			</p>
		</noscript>

		<div id="input-preferred">

			<label for="user_login_preferred">
				<strong>Please choose a user name.</strong>
			</label>

			<p>
				<input type="text" id="user_login_preferred" name="preferred" tabindex="30">
			</p>

			<p>
				<strong>This is your only opportunity to choose a user name and you
				cannot change it later.</strong> Your user name will be visible to
				other members.
			</p>

			<p>
				User names must be between four and twenty characters in length and
				must contain at least one letter. Only lowercase letters, numbers, and
				underscores are allowed.
			</p>

			<p class="fineprint">
				<input type="checkbox" id="user_acceptance" name="acceptance" tabindex="40" value="Yes">
				<label for="user_acceptance">
					I accept the <a href="/terms/" target="_blank">Terms of Service</a>,
					<a href="/privacy/" target="_blank">Privacy Policy</a>,
					and <a href="/guidelines/" target="_blank">Guidelines for Participation</a>.
				</label>
			</p>

		</div>

		<p id="forgot-password">
			<strong>Use your MLA credentials to log in.</strong>
			<a href="https://www.mla.org/user/account-retrieval/">Forgotten your log-in credentials?</a>
		</p>
<?php
	}

	/**
	 * Enqueue CSS and JavaScript for login form.
	 */
	public function enqueue_resources() {
		$login_data = array( 'ajaxurl' => \admin_url( 'admin-ajax.php' ) );
		$this->enqueue_script( 'jquery' );
		$this->enqueue_script( 'auth_login_script', 'login.js', array( 'jquery' ), $login_data );
		$this->enqueue_script( 'auth_md5_script', 'lib/md5-min.js' );
		$this->enqueue_style( 'auth_login_style', 'login.css' );
		$this->run();
	}

	/**
	 * Called from AJAX to determine if the user is unregistered (and therefore
	 * requires additional steps before login). Members can log in with either
	 * a member number (123456) or username.
	 */
	public function ajax_test_user() {

		$return_data = array(
			'result'  => 'unknown', // Or: existing, valid, invalid_credentials, invalid_status.
			'guess'   => '',        // Prepopulated value for preferred username.
		);

		// See if the user already has a WordPress account with the ID they typed.
		// $_POST values are injected into $this->data_cache.
		if ( \username_exists( $this->data_cache['username'] ) ) {
			$return_data['result'] = 'existing';
			\wp_send_json( $return_data );
		}

		// Validate the user's credentials.
		try {

			// $_POST values are injected into $this->data_cache.
			$member = new MLAMember( null, $this->mla_api, $this->logger );
			$member->authenticate( $this->data_cache['username'], $this->data_cache['password'] );
			$member_username = $member->authentication->username;

			// The user may have logged in with their member number, so we haven't
			// determined if they already exist. If they do, our member object will
			// have a WP id set.
			if ( $member->user_id ) {
				$return_data['result'] = 'existing';
			} else {
				$return_data['result'] = 'valid';
				$return_data['guess'] = ( $member->id === $member_username ) ? '' : $member_username;
			}
		} catch ( \Exception $e ) {

			$this->logger->addInfo( $e->getMessage() );

			switch ( $e->getCode() ) {
				case 400:
					$return_data['result'] = 'invalid_credentials';
					break;
				case 401:
					$return_data['result'] = 'invalid_status';
					break;
				case 510:
					if ( strpos( $e->getMessage(), 'API-2100' ) ) {
						$return_data['result'] = 'invalid_credentials';
						break;
					}
				default:
					$this->logger->addError( $e->getMessage() );
					break;
			}
		}

		// Output JSON message to login.js.
		\wp_send_json( $return_data );

	}

	/**
	 * Called from javascript to determine if the user's preferred username is valid.
	 */
	public function ajax_validate_preferred_username() {

		$result = 'unknown'; // Or: valid, invalid, duplicate.

		try {
			// $_POST values are injected into $this->data_cache.
			$mla_member = new MLAMember( false, $this->mla_api, $this->logger );
			$mla_member->validate_username( $this->data_cache['username'], $this->data_cache['preferred'] );
			$result = 'valid';
		} catch ( \Exception $e ) {

			$this->logger->addInfo( $e->getMessage() );

			switch ( $e->getCode() ) {
				case 450:
					$result = 'invalid';
					break;
				case 460:
					$result = 'duplicate';
					break;
				default:
					$this->logger->addError( $e->getMessage() );
					break;
			}
		}

		\wp_send_json( array( 'result' => $result ) );

	}
}
