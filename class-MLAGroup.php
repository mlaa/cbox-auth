<?php

/* The abstract API class is in another file. */
require_once( 'class-MLAAPI.php' );

/* This class, MLA Group, is primarily used to update group memberships,
 * so that when there is a group membership change, these are updated
 * more frequently than when the user logs out and logs back in.
 */
class MLAGroup extends MLAAPI {
	public $group_bp_id = 0;
	public $group_mla_oid = 0;
	public $members = array(); // guessing that members list is going to be an array of member IDs
	private $update_interval = 3600; // number of seconds below which to force update of group membership data.

	public function __construct( $debug = false ) {
		// Allow debugging to be turned on by passing a parameter
		// while instantiating this class.
		$this->debug = $debug;

		// Get BuddyPress ID for this group.
		$this->group_bp_id = bp_get_group_id();
		_log( "Instantiated the MLAGroup class. Here's some information about this group." ); 
		_log( "This group's BP ID is: $this->group_bp_id" );

		// Get MLA OID for this group, e.g. D038.
		$this->group_mla_oid = groups_get_groupmeta( $this->group_bp_id, 'mla_oid' );
		_log( "This group's MLA OID is: $this->group_mla_oid" );

		// Check to see if it already has an MLA API ID.
		$this->group_mla_api_id = groups_get_groupmeta( $this->group_bp_id, 'mla_api_id' );

		if ( ! $this->group_mla_api_id || empty( $this->group_mla_api_id ) ) {
			_log( 'This group doesn\'t already have an MLA API ID, so asking the API for one.' );
			$this->group_mla_api_id = $this->get_group_mla_api_id();
			if ( ! $this->group_mla_api_id || empty( $this->group_mla_api_id ) ) {
				_log( 'Looks like this group doesn\'t have an MLA API ID.' );
			} else {
				_log( 'Setting this group\'s MLA API ID for future reference.' );
				$this->set_group_mla_api_id();
			}
		} else {
			_log( "Looks like this group already has a recorded MLA API ID, and it's: $this->group_mla_api_id" );
		}
	}

	/**
	 * Checks when the group member data was last updated,
	 * so that it doesn't reload it from the member API
	 * unnecessarily.
	 *
	 * @return bool
	 */
	public function is_too_old() {
		$last_updated = groups_get_groupmeta( $this->group_bp_id, 'last_updated' );

		if ( $this->debug ) { 
			return true; // always enable for debugging
		} 

		if ( ! $last_updated ) {
			return true; /* never updated, so, it's too old. */
		} else {
			return ( time() - $last_updated > $this->update_interval );
		}
	}

	/**
	 * After a sync, we have to update the user meta with the last updated time.
	 */
	private function update_last_updated_time() {
		groups_update_groupmeta( $this->group_bp_id, 'last_updated', time() );
	}

	/**
	 * Gets the MLA API ID, the ID for the group (organization)
	 * used by the API, if given the MLA OID (convention_code).
	 *
	 * @param $mla_oid
	 * @return $mla_api_id int
	 */
	public function get_group_mla_api_id() {

		if ( ! $this->group_mla_oid ) {
			_log( 'Can\'t find the MLA OID for this group, so can\'t find the MLA API ID.' );
			return;
		}

		$mla_api_id = groups_get_groupmeta( $this->group_id, 'mla_api_id' ); 

		if ( ! $mla_api_id || empty( $mla_api_id ) ) {
			_log( 'It doesn\'t look like this group has an MLA API ID. Not syncing.' );
			return false;
		} else {
			_log( "Found the group's MLA API ID. It's: $mla_api_id" );
			return $mla_api_id;
		}
	}

	/*
	 * Gets group data, like membership, etc, from the new API.
	 */
	public function get_mla_group_data() {
		if ( ! $this->group_bp_id ) {
			// Sanity check. If there's no BP id, something is terribly wrong.
			_log( 'Strangely, this group doesn\'t have a BuddyPress ID. No syncing possible.' );
			return false;
		}
		if ( ! $this->group_mla_oid ) {
			_log( 'This group doesn\'t have an MLA OID. Assuming it\'s a Commons-only group and not syncing.' );
			return false;
		}

		if ( ! $this->group_mla_api_id ) {
			_log( 'This group doesn\'t seem to have an MLA API ID. Thus, can\'t look anything up about it from the API.' );
			return false;
		}

		$http_method = 'GET';
		$base_url = 'https://apidev.mla.org/1/';
		$simple_query = 'organizations/' . $this->group_mla_api_id;
		$request_url = $base_url . $simple_query;
		$params = array( 'joined_commons' => 'Y' ); 
		$response = $this->send_request( $http_method, $request_url, $params );

		if ( 200 != $response['code'] ) {
			_log( 'Something went wrong when polling the api with URL:', $request_url );
			_log( 'Here\'s what the API says:', $response );
			return false;
		}

		$decoded = json_decode( $response['body'] );
		$data = $decoded->data;
		//_log( 'Get ready for the raw group data!', $data );

		// get the members with their MLA API IDs
		$members_list = $data[0]->members;
		//_log( 'Members list is:',  $members_list );
		$members_count = count( $members_list );
		_log( "Members list from MLA API has $members_count members, (filtered by joined_commons)." );

		// Put the members list into a standardized form, 
		// and translate roles into something BuddyPress can understand. 
		$members_list_translated = array();
		foreach ( $members_list as $member ) {
			$members_list_translated[ $member->username ] = $this->translate_mla_role( strtolower( $member->position ) ); 
		}
		$members_list_translated_count = count( $members_list_translated );
		_log( "Translated members list from MLA API has $members_list_translated_count members." );

		return $members_list_translated;
	}

	/*
	 * Gets group membership data from BP.
	 * Populates `bp_members_list` with an array made to resemble the $this->members array.
	 */
	public function get_bp_group_data() {
		if ( ! $this->group_bp_id ) {
			// Sanity check. If there's no BP id, something is terribly wrong.
			_log( 'Strangely, this group doesn\'t have a BuddyPress ID. No syncing possible.' );
			return false;
		}
		if ( ! $this->group_mla_oid ) {
			_log( 'This group doesn\'t have an MLA OID. Assuming it\'s a Commons-only group and not syncing.' );
			return false;
		}

		$args = array(
			'group_id'		=> $this->group_bp_id,
			'per_page'		=> 999,
			'exclude_admins_mods'	=> false,
		);

		$this->bp_members = groups_get_group_members( $args );

		$bp_members_list = array();
		foreach ( $this->bp_members['members'] as $member_obj ) {
			$role = ( 1 == $member_obj->is_mod ) ? 'mod' : 'member';
			$role = ( 1 == $member_obj->is_admin ) ? 'admin' : 'member';
			$bp_members_list[ $member_obj->user_nicename ] = $role;
		}
		$bp_members_list_count = count( $bp_members_list );
		_log( "BP members list has $bp_members_list_count members." );

		return $bp_members_list;
	}

	/*
	 * Syncs API-given group membership data with that of BuddyPress.
	 * Basically looks to see if there are discrepancies between the member DB
	 * and the BP DB, and if so, changes the BP group membership information
	 */
	public function sync() {

		if ( ! $this->is_too_old() ) {
			//_log( 'No need to sync this group, since it\'s apparently been synced within the last hour.' );
			return false;
		}

		if ( ! $this->group_mla_api_id || empty( $this->group_mla_api_id ) ) {
			_log( 'This group doesn\'t seem to have an MLA API ID, so not syncing. Nothing to see here.' );
			return false;
		}

		if ( ! isset( $this->mla_members_list ) ) {
			$this->mla_members_list = $this->get_mla_group_data();
		}

		if ( ! isset( $this->bp_members_list ) ) {
			$this->bp_members_list = $this->get_bp_group_data();
		}

		$group_id = $this->group_bp_id;

		//_log( 'Now syncing with mla_members_list:', $this->mla_members_list );
		//_log( 'Now syncing with bp_members_list:', $this->bp_members_list );

		$diff = array_diff_assoc( $this->mla_members_list, $this->bp_members_list );
		_log( 'Diff of arrays:', $diff );

		// BuddyPress values for those diffed members.
		$bp_diff = array();
		foreach( array_keys( $diff ) as $member ) {
			if ( array_key_exists( $member, $this->bp_members_list ) ) { 
				$bp_diff[ $member ] = $this->bp_members_list[ $member ];
			} 
		}
		_log( 'BP\'s version of those members:', $bp_diff );

		// At this point we should have two associative arrays that reflect differences
		// in the MLA API group membership and the BuddyPress group membership. They should
		// look pretty much like this:
		//
		// -- $diff --          |  -- $bp-diff --
		// [49] => chair        |  [49] => admin
		// [60] => liaison      |  [60] => admin
		// [] => member         |  [] =>
		// [40] => mla staff    |  [40] => member
		//
		// Now we need to go through this list and make sure this differences are actually
		// differences we care about, and make the appropriate adjustments.
		foreach ( $diff as $member_id => $role ) {
			// We don't want no scrubs.
			// Ignore records with empty IDs.
			if ( '' == $member_id ) {
				continue;
			}

			// First translate this to something BP can understand.
			$mla_role = $this->translate_mla_role( $role );

			// And look up the corresponding role in BP's records.
			if ( array_key_exists( $member_id, $bp_diff ) ) { 
				$bp_role = $bp_diff[ $member_id ];
			} else {  
				// If MLA member isn't a member of the BuddyPress group, add them.
				_log( "Member $member_id not found in this BP group. Adding to group $group_id and assigning the role of $mla_role." ); 
				groups_join_group( $group_id, $member_id );
				// Also add it to our list so that we can compare the
				// roles below.
				$bp_diff[ $member_id ] = $mla_role;
				$bp_role = $mla_role;  
			}

			if ( $mla_role == $bp_role ) {
				// Roles are actually the same.
				// Move along, nothing to see here.
				continue;
			}

			if ( 'admin' == $mla_role && 'member' == $bp_role ) {
				// User has been promoted at MLA, but not on BP.
				// Promote them on BP.
				_log( "Member $member_id has a higher role in the MLA DB than in BP. Promoting." ); 
				groups_promote_member( $member_id, $group_id, 'admin' );
			}

			if ( 'member' == $mla_role && 'admin' == $bp_role ) {
				// User has been demoted at MLA, but not on BP.
				// Demote them on BP.
				_log( "Member $member_id has a higher role in BP than the MLA API reflects. Demoting." ); 
				groups_demote_member( $member_id, $group_id );
			}
		}

		$this->update_last_updated_time();

		return true; 
	}
}
