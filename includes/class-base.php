<?php
/**
 * Base class
 *
 * @package CustomAuth
 */

namespace MLA\Commons\Plugin\CustomAuth;

/**
 * Base class
 *
 * @package CustomAuth
 * @subpackage Base
 */
class Base {

	/**
	 * Plugin actions
	 *
	 * @var array
	 */
	protected $actions = array();

	/**
	 * Plugin filters
	 *
	 * @var array
	 */
	protected $filters = array();

	/**
	 * Plugin scripts
	 *
	 * @var array
	 */
	protected $scripts = array();

	/**
	 * Plugin styles
	 *
	 * @var array
	 */
	protected $styles = array();

	/**
	 * User name cache
	 *
	 * @var array
	 */
	protected $user_names = array();

	/**
	 * Group name cache
	 *
	 * @var array
	 */
	protected $group_names = array();

	/**
	 * Data storage
	 *
	 * @var array
	 */
	protected $data_cache = array();

	/**
	 * Add action to awaiting stack.
	 *
	 * @param string $hook           WordPress hook.
	 * @param object $component      Instance containing callback.
	 * @param string $callback       Method name.
	 * @param int    $priority       Priority of action.
	 * @param int    $accepted_args  Number of accepted arguments.
	 */
	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
			'processed'     => false,
		);
	}

	/**
	 * Add filter to awaiting stack.
	 *
	 * @param string $hook           WordPress hook.
	 * @param object $component      Instance containing callback.
	 * @param string $callback       Method name.
	 * @param int    $priority       Priority of filter.
	 * @param int    $accepted_args  Number of accepted arguments.
	 */
	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
			'processed'     => false,
		);
	}

	/**
	 * Add script to awaiting stack.
	 *
	 * @param string $name          Name of resource.
	 * @param string $path          Path (relative to plugin) to the resource.
	 * @param mixed  $dependencies  Array of dependency names.
	 * @param mixed  $localizations Array of script localizations (used to pass data to script).
	 */
	public function enqueue_script( $name, $path = false, $dependencies = false, $localizations = false ) {

		// Check if the passed path is a URL. If not, get relative path.
		$is_url = preg_match( '/^https\:/', $path );
		$path = ( $path && ! $is_url ) ? \plugins_url( 'public/js/' . $path, dirname( __FILE__ ) ) : $path;

		$this->scripts[] = array(
			'name'          => $name,
			'path'          => $path,
			'dependencies'  => $dependencies,
			'localizations' => $localizations,
			'processed'     => false,
		);

	}

	/**
	 * Add stylesheet to awaiting stack.
	 *
	 * @param string $name         Name of resource.
	 * @param string $path         Path (relative to plugin) to the resource.
	 * @param mixed  $dependencies Array of dependency names.
	 */
	public function enqueue_style( $name, $path = false, $dependencies = null ) {

		// Check if the passed path is a URL. If not, get relative path.
		$is_url = preg_match( '/^https\:/', $path );
		$path = ( $path && ! $is_url ) ? \plugins_url( 'public/css/' . $path, dirname( __FILE__ ) ) : $path;

		$this->styles[] = array(
			'name'         => $name,
			'path'         => $path,
			'dependencies' => $dependencies,
			'processed'     => false,
		);

	}

	/**
	 * Process actions queue.
	 */
	private function run_actions() {
		foreach ( $this->actions as $item ) {
			if ( ! $item['processed'] ) {
				\add_action( $item['hook'], array( $item['component'], $item['callback'] ), $item['priority'], $item['accepted_args'] );
				$item['processed'] = true;
			}
		}
	}

	/**
	 * Process filters queue.
	 */
	private function run_filters() {
		foreach ( $this->filters as $item ) {
			if ( ! $item['processed'] ) {
				\add_filter( $item['hook'], array( $item['component'], $item['callback'] ), $item['priority'], $item['accepted_args'] );
				$item['processed'] = true;
			}
		}
	}

	/**
	 * Process scripts queue.
	 */
	private function run_scripts() {
		foreach ( $this->scripts as $item ) {
			if ( ! $item['processed'] ) {
				\wp_enqueue_script( $item['name'], $item['path'], $item['dependencies'] );
				$item['processed'] = true;
				if ( $item['localizations'] ) {
					\wp_localize_script( 'auth_login_script', 'WordPress', $item['localizations'] );
				}
			}
		}
	}

	/**
	 * Process styles queue.
	 */
	private function run_styles() {
		foreach ( $this->styles as $item ) {
			if ( ! $item['processed'] ) {
				\wp_enqueue_style( $item['name'], $item['path'], $item['dependencies'] );
				$item['processed'] = true;
			}
		}
	}

	/**
	 * Process all queues.
	 */
	public function run() {
		$this->run_actions();
		$this->run_filters();
		$this->run_scripts();
		$this->run_styles();
	}

	/**
	 * Put data into data cache.
	 *
	 * @param string $key   Data key.
	 * @param mixed  $value Data value.
	 */
	public function set_cache( $key, $value ) {
		$this->data_cache[ $key ] = $value;
	}

	/**
	 * Get superglobal value while properly checking and sanitizing.
	 *
	 * @param string $type Superglobal constant, e.g., INPUT_GET, INPUT_SERVER.
	 * @param string $key  Key of superglobal to get.
	 * @return mixed Superglobal value.
	 */
	public function get_superglobal( $type, $key ) {
		$value = ( filter_has_var( $type, $key ) ) ? filter_input( $type, $key ) : null;
		return ( is_string( $value ) ) ? \sanitize_text_field( \wp_unslash( $value ) ) : $value;
	}

	/**
	 * Get object property or return default value.
	 *
	 * @param object $obj      Object.
	 * @param string $property Property name.
	 * @param mixed  $default  Default value.
	 * @return mixed Property value or default value.
	 */
	protected function get_object_property( $obj, $property, $default ) {
		return ( property_exists( $obj, $property ) ) ? $obj->$property : $default;
	}

	/**
	 * Make an attempt to describe a user for logging.
	 *
	 * @param int $user_id  WordPress ID of user to demote.
	 */
	protected function describe_user( $user_id ) {
		$user_name = ( isset( $this->user_names[ $user_id ] ) ) ? $this->user_names[ $user_id ] : null;
		return ( $user_name ) ? '"' . $user_name . '" (' . $user_id . ')' : $user_id;
	}

	/**
	 * Make an attempt to describe a group for logging.
	 *
	 * @param int $group_id BuddyPress group ID.
	 */
	protected function describe_group( $group_id ) {
		$group_name = ( isset( $this->group_names[ $group_id ] ) ) ? $this->group_names[ $group_id ] : null;
		return ( $group_name ) ? '"' . $group_name . '" (' . $group_id . ')' : $group_id;
	}

	/**
	 * Add user to group.
	 *
	 * @param int $user_id  WordPress ID of user to demote.
	 * @param int $group_id BuddyPress group ID.
	 */
	protected function add_user( $user_id, $group_id ) {

		// Log username and group description instead of ID, if available.
		$user_ref = $this->describe_user( $user_id );
		$group_ref = $this->describe_group( $group_id );

		$success = \groups_join_group( $group_id, $user_id );

		if ( $success ) {
			$this->logger->addInfo( 'User ' . $user_ref . ' added to group ' . $group_ref . '.' );
		} else {
			$this->logger->addError( 'User ' . $user_ref . ' could not be added to group ' . $group_ref . '.' );
		}

		return $success;

	}

	/**
	 * Remove user from group.
	 *
	 * @param int $user_id  WordPress ID of user to demote.
	 * @param int $group_id BuddyPress group ID.
	 */
	protected function remove_user( $user_id, $group_id ) {

		// Log username and group description instead of ID, if available.
		$user_ref = $this->describe_user( $user_id );
		$group_ref = $this->describe_group( $group_id );

		$success = \groups_leave_group( $group_id, $user_id );

		if ( $success ) {
			$this->logger->addInfo( 'User ' . $user_ref . ' removed from group ' . $group_ref . '.' );
		} else {
			$this->logger->addError( 'User ' . $user_ref . ' could not be removed from group ' . $group_ref . '.' );
		}

		return $success;

	}

	/**
	 * Promote user to group role "admin".
	 *
	 * @param int $user_id  WordPress ID of user to demote.
	 * @param int $group_id BuddyPress group ID.
	 */
	protected function promote_user( $user_id, $group_id ) {

		// Log username and group description instead of ID, if available.
		$user_ref = $this->describe_user( $user_id );
		$group_ref = $this->describe_group( $group_id );

		$member = new \BP_Groups_Member( $user_id, $group_id );
		$success = $member->promote( 'admin' );

		if ( $success ) {
			\do_action( 'groups_promote_member', $group_id, $user_id, 'admin' );
			$this->logger->addInfo( 'Promoting user ' . $user_ref . ' to admin in group ' . $group_ref . '.' );
		} else {
			$this->logger->addError( 'User ' . $user_ref . ' could not be promoted to admin in group ' . $group_ref . '.' );
		}

		return $success;

	}

	/**
	 * Demote user to "member" group role.
	 *
	 * @param int $user_id  WordPress ID of user to demote.
	 * @param int $group_id BuddyPress group ID.
	 */
	protected function demote_user( $user_id, $group_id ) {

		// Log username and group description instead of ID, if available.
		$user_ref = $this->describe_user( $user_id );
		$group_ref = $this->describe_group( $group_id );

		$member = new \BP_Groups_Member( $user_id, $group_id );
		$success = $member->demote();

		if ( $success ) {
			\do_action( 'groups_demote_member', $group_id, $user_id );
			$this->logger->addInfo( 'Demoting user ' . $user_ref . ' to member in group ' . $group_ref . '.' );
		} else {
			$this->logger->addError( 'User ' . $user_ref . ' could not be demoted to member in group ' . $group_ref . '.' );
		}

		return $success;

	}
}
