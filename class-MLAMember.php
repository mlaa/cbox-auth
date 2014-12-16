<?php
/* The abstract API class is in another file. */
require_once( 'class-MLAAPI.php' );

/*
 * This class, MLA Member (not to be confused with MLAM ember) interfaces
 * with the new member API, and syncs that data with BuddyPress if it has changed.
 */
class MLAMember extends MLAAPI {
	public $affiliations = array(); // a list of affiliations
	public $affiliation = ''; // primary affiliation
	public $title = ''; // a.k.a. rank
	public $user_id = 0; // BuddyPress user ID
	public $debug = false; // debugging mode

	// number of seconds below which to force update of group membership data.
	public $update_interval = 3600;

	// get the displayed user ID and username right from the beginning
	function __construct( $debug = false ) {

		// allow debugging mode by passing $debug = true while
		// instantiating this class.
		$this->debug = $debug;

		$this->user_id = bp_displayed_user_id();
		$this->username = bp_get_displayed_user_username();
		$mla_user_id_array = get_user_meta( $this->user_id, 'mla_oid' );  
		$this->mla_user_id = $mla_user_id_array[0]; 

		_log( '$this->user_id is:', $this->user_id );
		_log( '$this->username is:', $this->username );
		_log( '$this->mla_user_id is:', $this->mla_user_id );
	}

	/**
	 * Checks when the group member data was last updated,
	 * so that it doesn't reload it from the member API
	 * unnecessarily.
	 *
	 * @return bool
	 */
	private function is_too_old() {
		$last_updated = (integer) get_user_meta( $this->user_id, 'last_updated' );

		// never skip updating while debugging
		if ( $this->debug ) {
			return true;
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
		update_user_meta( $displayed_user_id, 'last_updated', time() );
	}

	/**
	 * Gets the member data from the API and stores it in this class's
	 * parameters.
	 */
	private function get_mla_member_data() {
		$request_method = 'GET';
		$query_domain = 'members';
		// this is for queries that come directly after the query domain,
		// like https://apidev.mla.org/1/members/168880
		$simple_query = '/' . $this->mla_user_id;
		$base_url = 'https://apidev.mla.org/1/' . $query_domain . $simple_query;

		$query = array(
			//'id' => 168880,
			//'last_name' => 'Reeve',
		);
		$response = $this->send_request( $request_method, $base_url, $query );

		_log( 'the response is: ', $response );

		//@todo: validate JSON, make sure we're getting a 200 code.
		if ( $response['code'] != 200 ) {
			_log( 'There was some kind of error while trying to get MLA member data. Here\'s what the API said:', $response['body']);
			return false;
		}

		$decoded = json_decode( $response['body'] )->data[0];
		//_log( 'decoded member data:' );
		//_log( $decoded );

		$this->first_name = $decoded->general->first_name;
		$this->last_name  = $decoded->general->last_name;
		$this->fullname  = $this->first_name . ' ' . $this->last_name;
		$this->nickname  = $this->fullname;
		$this->affiliation = $decoded->addresses[0]->affiliation; // assuming primary affiliation is at 0
		$this->title = $decoded->addresses[0]->rank;

		$raw_groups = $decoded->organizations;

		// parse raw affiliations
		$this->affiliations = array();
		foreach ( $decoded->addresses as $address ) {
			$this->affiliations[] = $address->affiliation;
		}

		// parse raw groups
		$mla_groups_list = array();
		foreach ( $raw_groups as $group ) {
			// groups array is in the form 'group_id' => role
			$group_id = (string) $this->get_group_id_from_mla_oid( $group->convention_code );
			$this->mla_groups_list[ $group_id ] = strtolower( $group->position );
		}

		_log( 'groups are:', $this->mla_groups_list );
		_log( 'this->first_name is', $this->first_name );
		_log( 'this->last_name is', $this->last_name );
		_log( 'this->affiliation is', $this->affiliation );
		_log( 'this->title is', $this->title );

		// dummy data
		//$this->affiliations[] = array( 'Modern Language Association' );
		//$this->first_name = 'Jonathan';
		//$this->last_name = 'Reeve';
		//$this->nickname = 'Jonathan Reeve';
		//$this->fullname = 'Jonathan Reeve';
		//$this->title = 'Web Developer';

		//$this->mla_groups_list = array(
			//'17' 	=> 'member',
			//'44'   => 'member',
			//'46'   => 'member',
		//);

	}

	private function get_bp_member_groups() {
		$args = array(
			'user_id' => $this->user_id,
			'per_page' => 9999, 
			'populate_extras' => true, 
		);
		$this->bp_groups = groups_get_groups( $args );
		//_log( 'heyoo! have some groups here for you!', $this->bp_groups );

		$admin_of =  BP_Groups_Member::get_is_admin_of( $this->user_id ); 
		$mod_of = BP_Groups_Member::get_is_mod_of( $this->user_id ); 
		_log( 'this user is admin of:', $admin_of ); 
		_log( 'this user is mod of:', $mod_of ); 
		
		// now make a standard table for groups for which this user
		// is an admin or a mod.
		$admin_and_mod_of = array(); 
		foreach( $admin_of['groups'] as $group ) { 
			$admin_and_mod_of[$group->id] = 'admin'; 
		} 
		foreach( $mod_of['groups'] as $group ) { 
			$admin_and_mod_of[$group->id] = 'mod'; 
		} 
		_log( 'this user is admin or mod of:', $admin_and_mod_of ); 

		$this->bp_groups_list = array();
		foreach ( $this->bp_groups['groups'] as $bp_group ) {
			if ( array_key_exists( $bp_group->id, $admin_and_mod_of ) ) { 
				$this->bp_groups_list[ $bp_group->id ] = $admin_and_mod_of[ $bp_group->id ];
			} else {  
				$this->bp_groups_list[ $bp_group->id ] = 'member';
			} 
		}
		_log( 'bp_groups_list is:' );
		_log( $this->bp_groups_list );
		_log( "this member is a member or greater of $count BuddyPress groups.", count($this->bp_groups_list) );

		// ignore groups that don't have a mla_oid
		foreach ( $this->bp_groups_list as $bp_group_id => $bp_group_role ) {
			if ( ! groups_get_groupmeta( $bp_group_id, 'mla_oid' ) ) {
				unset( $this->bp_groups_list[ $bp_group_id ] );
			}
		}
		_log( 'bp_groups_list of just the mla groups is now:' );
		_log( $this->bp_groups_list );
	}
	/**
	 * Gets member data from the new API and, if there are any changes,
	 * updates the corresponding WordPress user.
	 */
	public function sync() {
		// don't sync unless the data is already too old
		if ( ! $this->is_too_old() ) {
			return;
		}

		// get all the data
		$this->get_mla_member_data();
		$this->get_bp_member_groups();

		// don't actually need to map these in an associative array,
		// since they're already the names of their associates
		$fields_to_sync = array( 'first_name', 'last_name', 'nickname', 'affiliations', 'title' );
		foreach ( $fields_to_sync as $field ) {
			if ( ! empty($this->field ) ) {
				update_user_meta( $this->user_id, $field, $this->$field );
				_log( 'Setting user meta:', $field );
				_log( 'with data:', $this->$field );
			}
		}

		// Map of fields to sync. Key is incoming MLA field;
		// value is Xprofile field name.
		$xprofile_fields_to_sync = array(
			'affiliation' => 'Institutional or Other Affiliation',
			'title' => 'Title',
			'fullname' => 'Name',
		);

		foreach ( $xprofile_fields_to_sync as $source_field => $dest_field ) {
			if ( ! empty( $this->$source_field ) ) {
				$result = xprofile_set_field_data( $dest_field, $this->user_id, $this->$source_field );
				if ( $result ) {
					_log( 'Successfully updated xprofile data.' );
				} else {
					_log( 'Something went wrong while updating xprofile data from member database.' );
				}
			}
		}

		// Now sync member groups. Loop through MLA groups and add new ones to BP
		_log( 'About to sync with mla_groups_list:' );
		_log( $this->mla_groups_list );
		foreach ( $this->mla_groups_list as $group_id => $member_role ) {
			if ( ! array_key_exists( $group_id, $this->bp_groups_list ) ) {
				_log( "$group_id not found in this user's membership list. adding user $this->user_id to group $group_id" );
				groups_join_group( $group_id, $this->user_id );

				// Now promote user if user is chair or equivalent.
				if ( 'admin' == $this->translate_mla_role( $member_role ) ) {
					groups_promote_member( $this->user_id, $group_id, 'admin' );
				}
			}
		}

		// Now look through the bp groups list and remove any groups
		// with MLA OIDs that don't exist in the MLA database for this user.
		foreach ( $this->bp_groups_list as $group_id => $member_role ) {
			if ( ! array_key_exists( $group_id, $this->mla_groups_list ) ) {
				_log( "BP group ID $group_id not found in MLA groups list, removing." );
				groups_leave_group( $group_id, $this->user_id );
			}
		}

		$this->update_last_updated_time();

		return true;
	}
}
