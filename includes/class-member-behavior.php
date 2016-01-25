<?php
/**
 * Member Behavior
 *
 * @package CustomAuth
 */

namespace MLA\Commons\Plugin\CustomAuth;

use \MLA\Commons\Plugin\Logging\Logger;

/**
 * Initiate API interaction for MLA members.
 *
 * @package CustomAuth
 * @subpackage MemberBehavior
 * @class MemberBehavior
 */
class MemberBehavior extends Base {

	/**
	 * MLA group type
	 *
	 * @var string
	 */
	private $mla_group_type;

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

		$this->add_action( 'bp_before_member_groups_content', $this, 'sync_membership_from_api' );
		$this->add_action( 'cacap_header', $this, 'sync_membership_from_api' );
		$this->run();

	}

	/**
	 * Sync member data from MLA API.
	 */
	public function sync_membership_from_api() {

		$user_id = \bp_displayed_user_id();

		try {
			$mla_member = new MLAMember( $user_id, $this->mla_api, $this->logger );
			$mla_member->sync();
		} catch ( \Exception $e ) {
			$this->logger->addError( $e->getMessage() );
		}

	}
}
