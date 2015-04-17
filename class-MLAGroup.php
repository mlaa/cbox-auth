<?php

/* This class, MLA Group, is primarily used to update group memberships,
 * so that when there is a group membership change, these are updated
 * more frequently than when the user logs out and logs back in.
 */
class MLAGroup extends MLAAPI {
	public $group_bp_id = 0;
	public $group_mla_oid = 0;
	
	// members list is going to be an array of usernames
	public $members = array(); 

	// number of seconds below which to force update of group membership data.
	private static $update_interval = 3600; 

	public function __construct( $debug = false, $group_bp_id = 0 ) {
		// Allow debugging to be turned on by passing a parameter
		// while instantiating this class.
		$this->debug = $debug;

		//Turn on verbose for now. 
		//$this->debug = 'verbose';

		_log( "Instantiated the MLAGroup class. Here's some information about this group." );

		// If a group ID is passed, assume we're logging in, and go 
		// with the passed value. If not, assume we're on a page, 
		// and get the group ID from context. 
		$this->group_bp_id = $group_bp_id ? $group_bp_id : bp_get_group_id(); 

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

		$mla_api_id = groups_get_groupmeta( $this->group_bp_id, 'mla_api_id' );

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

		// Abstracting this part out so that it can work better with 
		// mock data. 
		$response = $this->get_mla_group_data_from_api(); 

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
		_log( 'BP members for this group are:', $bp_members_list ); 

		return $bp_members_list;
	}

	/*
	 * Syncs API-given group membership data with that of BuddyPress.
	 * Basically looks to see if there are discrepancies between the member DB
	 * and the BP DB, and if so, changes the BP group membership information
	 */
	public function sync() {

		if ( ! $this->is_too_old( 'group', $this->group_bp_id ) ) {
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
		if ( 'verbose' == $this->debug ) _log( 'Diff of arrays:', $diff );

		// BuddyPress values for those diffed members.
		$bp_diff = array();
		foreach ( array_keys( $diff ) as $member ) {
			if ( array_key_exists( $member, $this->bp_members_list ) ) {
				$bp_diff[ $member ] = $this->bp_members_list[ $member ];
			}
		}
		if ( 'verbose' == $this->debug ) _log( 'BP\'s version of those members:', $bp_diff );

		// At this point we should have two associative arrays that reflect differences
		// in the MLA API group membership and the BuddyPress group membership. They should
		// look pretty much like this:
		//
		// -- $diff --          |  -- $bp-diff --
		// [49] => admin        |  [49] => admin
		// [60] => admin        |  [60] => admin
		// []   => member       |  []   =>
		// [40] => member       |  [40] => member
		//
		// Now we need to go through this list and make sure this differences are actually
		// differences we care about, and make the appropriate adjustments.
		foreach ( $diff as $member_username => $mla_role ) {
			// We don't want no scrubs.
			// Ignore records with empty IDs.
			if ( '' == $member_username ) continue;

			if ( 'verbose' == $this->debug ) _log( "Now handling member with username: $member_username and role: $mla_role" ); 

			// Get the member ID for this member from the username. 
			$member_id = bp_core_get_userid( $member_username ); 

			// If we can't look up the member ID, 
			// this might not be a member that has joined the commons. 
			// Ergo, nothing to do.  
			if ( ! $member_id || 0 == $member_id ) continue; 

			// I don't think I need to translate the MLA role here, because the data
			// we get is already translated.   
			//// First translate this to something BP can understand.
			//$mla_role = $this->translate_mla_role( $role );

			// And look up the corresponding role in BP's records.
			if ( array_key_exists( $member_username, $bp_diff ) ) {
				$bp_role = $bp_diff[ $member_username ];
			} else {
				// If MLA member isn't a member of the BuddyPress group, add them.
				if ( 'verbose' == $this->debug ) _log( "Member $member_username not found in this BP group. Adding member ID $member_id to group $group_id and assigning the role of $mla_role." );
				
				// Can't use the regular groups_join_group here, since we're hooking
				// into that action in customAuth.php, so we roll our own.  
				if ( ! $this->mla_groups_join_group( $group_id, $member_id ) ) { 
					_log( "Couldn\'t add user $member_username to BP group $group_id!" ); 
				} else { 
					_log( "Successfully added user $member_username to group $group_id." ); 
				} 

				// Also add it to our list so that we can compare the
				// roles below. Newly-added members are automatically given the role of member. 
				// We can promote or demote them as necessary later. 
				$bp_diff[ $member_username ] = 'member';  
				$bp_role = 'member'; 
			}

			if ( $mla_role == $bp_role ) {
				// Roles are actually the same.
				// Move along, nothing to see here.
				continue;
			}

			if ( 'admin' == $mla_role && 'member' == $bp_role ) {
				// User has been promoted at MLA, but not on BP.
				// Promote them on BP.
				if ( 'verbose' == $this->debug ) _log( "Member $member_username has a higher role in the MLA DB than in BP. Promoting." );
				groups_promote_member( $member_id, $group_id, 'admin' );
			}

			if ( 'member' == $mla_role && 'admin' == $bp_role ) {
				// User has been demoted at MLA, but not on BP.
				// Demote them on BP.
				if ( 'verbose' == $this->debug ) _log( "Member $member_username has a higher role in BP than the MLA API reflects. Demoting." );
				groups_demote_member( $member_id, $group_id );
			}
		}


		// BUT! array_diff_assoc() only diffs in one direction. From the manual: 
		// "Returns an array containing all the values from array1 that are not present in 
		// any of the other arrays." So we actually have to do another diff to find 
		// those members that exist in BP but not in the member database. This time, 
		// we'll use array_diff_key(), since we're not concerned with the values (member roles)
		// anymore, just whether the member exists. If a member exists in BP, but not in the 
		// member database, we will assume that member has been removed on the 
		// Oracle side, and we will therefore remove them on the BP side to reflect that. 
		
		$removed = array_diff_key( $this->bp_members_list, $this->mla_members_list ); 
		if ( 'verbose' == $this->debug ) _log( 'Reverse diff of arrays (removed):', $removed );

		foreach ( $removed as $removed_member_username => $removed_member_role ) { 
			$removed_member_id = bp_core_get_userid( $removed_member_username ); 
			if ( 'verbose' == $this->debug ) _log( "Now removing member: $removed_member_username with ID: $removed_member_id from group $group_id." ); 

			// We can't use groups_leave_group() here, because we're hooking into that
			// action in customAuth.php, so we have to remove the user from the group 
			// semi-manually. 
			if ( ! $this->mla_groups_leave_group( $group_id, $removed_member_id ) ) {
				_log( 'Couldn\'t remove member from group!' ); 
			} else { 
				_log( 'Successfully removed member from BP group!' ); 	
			} 
		} 

		$this->update_last_updated_time();

		return true;
	}
}
