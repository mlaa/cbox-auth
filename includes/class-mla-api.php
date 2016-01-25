<?php
/**
 * MLA API
 *
 * @package CustomAuth
 */

namespace MLA\Commons\Plugin\CustomAuth;

use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Implementation of the RESTful MLA API.
 *
 * @package CustomAuth
 * @subpackage MLAAPI
 * @class MLAAPI
 */
class MLAAPI extends Base {

	/**
	 * Dependency: HttpDriver
	 *
	 * @var object
	 */
	private $http_driver;

	/**
	 * Dependency: Logger
	 *
	 * @var object
	 */
	private $logger;

	/**
	 * Constructor
	 *
	 * @param HttpDriver $http_driver Dependency: HttpDriver.
	 * @param Logger     $logger      Dependency: Logger.
	 */
	public function __construct( HttpDriver $http_driver, Logger $logger ) {
		$this->http_driver = $http_driver;
		$this->logger = $logger;
	}

	/**
	 * Get a member from the API.
	 *
	 * @param string $member_id Either MLA member ID number or username.
	 * @return object API response object
	 */
	public function get_member( $member_id ) {

		// Fetch member data from MLA API.
		$request_path = 'members/' . urlencode( $member_id );
		$response = $this->http_driver->get( $request_path );

		// Validate and extract API response.
		$response = $this->extract_api_response_data( $response, 'organizations' );

		// Put the groups list into a standardized form and translate roles into
		// something BuddyPress can understand.
		$groups_list = array();
		foreach ( $response->organizations as $group ) {
			$bp_role = $this->validate_group_membership( $group );
			if ( $bp_role ) {
				$groups_list[ $group->id ] = array(
					'name' => $group->name,
					'role' => $bp_role,
					'type' => strtolower( $group->type ),
				);
			}
		}

		$response->organizations = $groups_list;

		return $response;

	}

	/**
	 * Get a group from the API.
	 *
	 * @param string $group_id MLA group ID.
	 * @return object API response object.
	 * @throws \Exception Describes API error.
	 */
	public function get_group( $group_id ) {

		// Fetch group data from MLA API.
		$request_path = 'organizations/' . $group_id;
		$request_params = array( 'joined_commons' => 'Y' );
		$response = $this->http_driver->get( $request_path, $request_params );

		// Validate and extract API response.
		$response = $this->extract_api_response_data( $response, 'members' );

		// Make sure group should not be excluded.
		$should_exclude = strtolower( $this->get_object_property( $response, 'exclude_from_commons', null ) );
		if ( 'y' === $should_exclude ) {
			throw new \Exception( 'Attempt to sync excluded group: ' . $response->id, 410 );
		}

		// Put the members list into a standardized form and translate roles into
		// something BuddyPress can understand.
		$members_list = array();
		foreach ( $response->members as $member ) {
			$member->type = $response->type;
			$bp_role = $this->validate_group_membership( $member );
			if ( $bp_role ) {
				$members_list[ strtolower( $member->username ) ] = $bp_role;
			}
		}
		$response->members = $members_list;

		return $response;

	}

	/**
	 * Validate API raw response.
	 *
	 * @param object $response Response object (decoded JSON) from MLA API.
	 * @return bool True if response self-reports as successful.
	 * @throws \Exception Describes API error.
	 */
	private function validate_api_response( $response ) {

		// Check that response has the expected properties.
		if ( $response && isset( $response->meta, $response->meta->status, $response->meta->code ) ) {

			if ( 'success' !== strtolower( $response->meta->status ) ) {
				throw new \Exception( 'API returned non-success ' . $response->meta->status . ': ' . $response->meta->code, 510 );
			}

			if ( 'api-1000' !== strtolower( $response->meta->code ) ) {
				throw new \Exception( 'API returned error code: ' . $response->meta->code, 520 );
			}

			return true;

		}

		throw new \Exception( 'API returned malformed response: ' . serialize( $response ), 530 );

	}

	/**
	 * Validate API raw response and extract data.
	 *
	 * @param object $response Response object (decoded JSON) from MLA API.
	 * @param string $property Property name to validate (as array).
	 * @param bool   $singular Whether the API response should contain only one item.
	 * @return object Validated API response data.
	 * @throws \Exception Describes API error.
	 */
	private function extract_api_response_data( $response, $property, $singular = false ) {

		$this->validate_api_response( $response );

		if ( ! isset( $response->data ) || ! is_array( $response->data ) || count( $response->data ) < 1 ) {
			throw new \Exception( 'API returned no data.', 540 );
		}

		if ( $singular && count( $response->data ) > 1 ) {
			throw new \Exception( 'API response returned more than one item: ' . serialize( $response->data ), 550 );
		}

		if ( ! property_exists( $response->data[0], $property ) ) {
			throw new \Exception( 'API response did not contain property: ' . $property, 560 );
		}

		return $response->data[0];

	}

	/**
	 * Validate group membership.
	 *
	 * @param array $group MLA group associative array.
	 * @return mixed False if we ignore the membership; otherwise a BP role.
	 */
	private function validate_group_membership( $group ) {

		$group_type = strtolower( $this->get_object_property( $group, 'type',                 null ) );
		$position   = strtolower( $this->get_object_property( $group, 'position',             null ) );
		$primary    = strtolower( $this->get_object_property( $group, 'primary',              null ) );
		$exclude    = strtolower( $this->get_object_property( $group, 'exclude_from_commons', null ) );

		// Some groups have an exclude flag.
		if ( 'y' === $exclude ) {
			return false;
		}

		// Committees: interested in all members.
		if ( 'mla organization' === $group_type ) {
			return $this->translate_mla_role( $position );
		}

		// Forums: only interested in executive committees and primary affiliations.
		if ( 'forum' === $group_type && ( 'member' !== $position || 'y' === $primary ) ) {
			return $this->translate_mla_role( $position );
		}

		// All other groups: not interested at all.
		return false;

	}

	/**
	 * Change member's username via API.
	 *
	 * @param string $user_id      Member ID number.
	 * @param string $new_username Requested new username.
	 * @return bool True if response indicates success.
	 */
	public function change_username( $user_id, $new_username ) {

		// Send request to API.
		$request_path = 'members/' . $user_id . '/username';
		$request_body = '{"username":"' . $new_username . '"}';
		$response = $this->http_driver->put( $request_path, array(), $request_body );

		return ( $this->validate_api_response( $response ) );

	}

	/**
	 * Query API to check if username already exists.
	 *
	 * @param string $username WordPress username/nicename.
	 * @return bool True if username is duplicate.
	 * @throws \Exception Describes API error.
	 */
	public function is_duplicate_username( $username ) {

		// Query API.
		$request_path = 'members';
		$request_params = array(
			'type' => 'duplicate',
			'username' => $username,
		);
		$response = $this->http_driver->get( $request_path, $request_params );

		// Validate and extract API response.
		$response = $this->extract_api_response_data( $response, 'username' );
		$is_duplicate = $this->get_object_property( $response->username, 'duplicate', null );

		if ( is_bool( $is_duplicate ) ) {
			return $is_duplicate;
		}

		throw new \Exception( 'API did not return boolean for duplicate status of username ' . $username . '.', 570 );

	}

	/**
	 * Translate MLA roles like 'chair', 'liaison,' 'mla staff', into
	 * the corresponding BP role, like 'admin', 'member'.
	 *
	 * @param string $mla_role MLA role, like 'chair', 'secretary'.
	 * @return string $bp_role BP role, like 'admin', 'member'.
	 */
	public static function translate_mla_role( $mla_role ) {

		// List of MLA group roles that count we want to promote to group admin.
		$mla_admin_roles = array( 'chair', 'liaison', 'liason', 'secretary', 'executive' );

		return ( in_array( strtolower( $mla_role ), $mla_admin_roles, true ) ) ? 'admin' : 'member';

	}
}
