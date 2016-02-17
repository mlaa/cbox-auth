<?php
/**
 * Plugin Name: CBOX Authentication
 * Plugin URI:  https://github.com/mlaa/cbox-auth
 * Description: Augments the WordPress login process to verify credentials against an external RESTful API. Additionally uses that API to sync BuddyPress group memberships with an external source.
 *
 * @link https://github.com/mlaa/cbox-auth
 * @package CustomAuth
 */

namespace MLA\Commons\Plugin\CustomAuth;

require_once plugin_dir_path( __FILE__ ) . 'includes/loader.php';

use MLA\Commons\Plugin\Logging\Logger;

/**
 * Plugin Loader class
 *
 * @package CustomAuth
 * @subpackage PluginLoader
 */
class PluginLoader extends Base {

	/**
	 * Login form
	 *
	 * @var object
	 */
	private $login_form;

	/**
	 * Login processor
	 *
	 * @var object
	 */
	private $login_procesor;

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
	 */
	function __construct() {

		// Create logging interface.
		$this->logger = new Logger( 'cbox-auth' );
		$this->logger->createLog( 'cbox-auth' );

		// Expect API credentials to be defined as constants.
		$credentials = array(
			'api_url' => CBOX_AUTH_API_URL,
			'api_key' => CBOX_AUTH_API_KEY,
			'api_secret' => CBOX_AUTH_API_SECRET,
		);

		// Create MLA API interface.
		$http_driver = new CurlDriver( $credentials, $this->logger );
		$this->mla_api = new MLAAPI( $http_driver, $this->logger );

		// Hook into WP authentication.
		$this->login_form = new LoginForm( $this->mla_api, $this->logger );
		$this->login_processor = new LoginProcessor( $this->mla_api, $this->logger );
		$this->inject_superglobals();

		new GroupBehavior( $this->mla_api, $this->logger );
		new MemberBehavior( $this->mla_api, $this->logger );
		new ProfileFields();

		// BuddyPress functionality should wait for BuddyPress.
		$this->add_action( 'bp_include', $this, 'init_ui_changes' );
		$this->run();

	}

	/**
	 * Make adjustments to WP and BP UI.
	 */
	public function init_ui_changes() {
	}

	/**
	 * Inject data from superglobals.
	 */
	public function inject_superglobals() {

		$login_form_data = array(
			'username' => $this->get_superglobal( INPUT_POST, 'username' ),
			'password' => $this->get_superglobal( INPUT_POST, 'password' ),
			'preferred' => $this->get_superglobal( INPUT_POST, 'preferred' ),
		);
		foreach ( $login_form_data as $key => $value ) {
			$this->login_form->set_cache( $key, $value );
		}

		$login_processor_data = array(
			'server_port' => $this->get_superglobal( INPUT_SERVER, 'SERVER_PORT' ),
			'preferred' => $this->get_superglobal( INPUT_POST, 'preferred' ),
			'accepted' => $this->get_superglobal( INPUT_POST, 'acceptance' ),
		);
		foreach ( $login_processor_data as $key => $value ) {
			$this->login_processor->set_cache( $key, $value );
		}

	}
}

// GO!
new PluginLoader();
