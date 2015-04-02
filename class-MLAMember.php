<?php
/* The abstract API class is in another file. */
require_once( 'class-MLAAPI.php' );

/*
 * This class, MLA Member (not to be confused with MLAM ember) interfaces
 * with the new member API, and syncs that data with BuddyPress if it has changed.
 */
class MLAMember extends MLAAPI {
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

		if ( 'verbose' === $this->debug ) { 
			_log( 'Class MLAMember instantiated. Here\'s some information about this member.' ); 
			_log( 'This user BP ID is is:', $this->user_id );
			_log( 'Username is:', $this->username );
			_log( 'MLA user ID is:', $this->mla_user_id );
		} 
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
		if ( $this->debug ) return true;

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
	 * properties.
	 */
	private function get_mla_member_data() {

		// dummy data
		//$this->affiliation = 'Modern Language Association';
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

		//return true; // debugging. 

		$request_method = 'GET';
		$query_domain = 'members';
		// this is for queries that come directly after the query domain,
		// like https://apidev.mla.org/1/members/168880
		$simple_query = '/' . $this->mla_user_id;
		$base_url = 'https://apidev.mla.org/1/' . $query_domain . $simple_query;
		$response = $this->send_request( $request_method, $base_url );

		if ( 'verbose' === $this->debug ) { 
			_log( 'Response from API is: ', $response ); 
		} 

		if ( $response['code'] != 200 ) {
			_log( 'There was some kind of error while trying to get MLA member data. Here\'s what the API said:', $response );
			return false;
		}

		$meta = json_decode( $response['body'] )->meta; 

		if ( 'API-1000' != $meta->code ) { 
			_log( 'API has returned an error or exception. Message:', $meta ); 
			return false; 
		} 

		$decoded = json_decode( $response['body'] )->data[0];
		_log( 'decoded member data:' );
		_log( $decoded );

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
			_log( 'now looking at group: ', $group ); 
			// groups array is in the form 'group_id' => role
			$group_id = (string) $this->get_group_id_from_mla_oid( $group->convention_code );
			if ( false == $group_id ) { 
				// this means the MLA API group doesn't have a 
				// corresponding BP group, and we need to create
				// a BP group, provided that the group isn't 
				// explicitly excluded from the Commons. 
				if ( ! 'Y' === $group->exclude_from_commons ) { 
					_log( "This group doesn\'t have a BP id, which means it's a new group that should be created." ); 
					// create group
					$this->create_group( $group ); 
				} 
			} 

			// Check to see whether the group has an MLA API ID. 
			$group_mla_api_id = groups_get_groupmeta( $group_id, 'mla_api_id' );
			// If not, write it to the group meta. 
			if ( ! $group_mla_api_id || empty( $group_mla_api_id ) ) {
				_log( "The group $group_id doesn't already have an MLA API ID, so writing it." );
				$group_mla_api_id = $group->id; 
				groups_update_groupmeta( $group_id, 'mla_api_id', $group_mla_api_id );
			} else { 
				_log( "Found this group's MLA API ID, and it's: $group_mla_api_id" ); 
			} 

			// have to translate the roles so that we can diff this array later
			$this->mla_groups_list[ $group_id ] = $this->translate_mla_role( strtolower( $group->position ) ); 
		}

		_log( 'groups are:', $this->mla_groups_list );
		_log( 'this->first_name is', $this->first_name );
		_log( 'this->last_name is', $this->last_name );
		_log( 'this->affiliation is', $this->affiliation );
		_log( 'this->title is', $this->title );

		return true; 

	}

	/** 
	 * Creates a BuddyPress group based on MLA API group data. 
	 * Used to create a group in BuddyPress when that group exists
	 * in the MLA API, but not on the Commons. 
	 *
	 * @param $group stdClass Object, generally looks like this: 
	 *   [id] => 360
	 *   [name] => Cognitive and Affect Studies
	 *   [convention_code] => D088
	 *   [position] => Member
	 *   [type] => Forum
	 * 
	 * @uses $this->user_id 
	 * @return bool If success, returns true. If failure, returns false. 
	 */ 
	public function create_bp_group( $group_data ) { 
		$newGroup = array(
			'slug' => groups_check_slug( sanitize_title_with_dashes( $group_data['name'] ) ),
			'name' => $group_data['name'],
			//'status' => $group_data['status'],
			// @todo handle group status (private, public, hidden) 
			// based on the group type, i.e. "type": "Committee" 
		);
		$group_id = groups_create_group( $new_group );
		if ( ! $group_id || empty( $group_id ) || $group_id instanceof WP_Error ) { 
			_log( 'Something went wrong while trying to create a new group. Response:', $group_id ); 
			return false; 
		} 
		groups_update_groupmeta( $group_id, 'mla_oid', $groupData['convention_code'] );
		groups_update_groupmeta( $group_id, 'mla_api_id', $groupData['id'] );
		groups_join_group( $group_id, $this->user_id );

		if ( 'admin' === translate_mla_role( $group_data['position'] ) ) { 
			groups_promote_member( $user_id, $group_id, 'admin' );
		} 

		return true; 
	} 

	private function get_bp_member_groups() {
		$args = array(
			'user_id' => $this->user_id,
			'per_page' => 9999, 
			'populate_extras' => true, 
		);
		$this->bp_groups = groups_get_groups( $args );
		if ( 'verbose' === $this->debug ) _log( 'A full list of this member\'s groups:', $this->bp_groups );

		if ( ! isset( $this->bp_groups ) || ! array_key_exists( 'total', $this->bp_groups ) || ! array_key_exists( 'groups', $this->bp_groups ) ) { 
			_log( 'Something went wrong while trying to get the BP groups for this user.' ); 
			return false; 
		} 

		$admin_of = BP_Groups_Member::get_is_admin_of( $this->user_id ); 
		$mod_of = BP_Groups_Member::get_is_mod_of( $this->user_id ); 
		if ( 'verbose' === $this->debug ) _log( 'This user is admin of:', $admin_of ); 
		if ( 'verbose' === $this->debug ) _log( 'This user is mod of:', $mod_of ); 
		
		// now make a standard table for groups for which this user
		// is an admin or a mod.
		$admin_and_mod_of = array(); 
		foreach ( $admin_of['groups'] as $group ) { 
			$admin_and_mod_of[ $group->id ] = 'admin'; 
		} 
		foreach ( $mod_of['groups'] as $group ) { 
			$admin_and_mod_of[ $group->id ] = 'mod'; 
		} 
		if ( 'verbose' === $this->debug ) _log( 'This user is admin or mod of:', $admin_and_mod_of ); 

		// make an array containing groups and roles 
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
		$count = count( $this->bp_groups_list ); 
		_log( "This member belongs to $count BuddyPress groups." );

		// ignore groups that don't have a mla_oid, since they're 
		// probably Commons-born groups. 
		foreach ( $this->bp_groups_list as $bp_group_id => $bp_group_role ) {
			if ( ! groups_get_groupmeta( $bp_group_id, 'mla_oid' ) ) {
				unset( $this->bp_groups_list[ $bp_group_id ] );
			}
		}
		_log( 'bp_groups_list of just the mla groups is now:' );
		_log( $this->bp_groups_list );

		return true; 
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
		$success = $this->get_mla_member_data();
		if ( ! $success ) return false; 
		$success = $this->get_bp_member_groups();
		if ( ! $success ) return false; 

		// don't actually need to map these in an associative array,
		// since they're already the names of their associates
		$fields_to_sync = array( 'first_name', 'last_name', 'nickname', 'affiliations', 'title' );
		foreach ( $fields_to_sync as $field ) {
			if ( ! empty( $this->field ) ) {
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
					_log( "Successfully updated xprofile field $dest_field." );
				} else {
					_log( 'Something went wrong while updating xprofile data from member database.' );
				}
			}
		}

		// Now sync member groups. Loop through MLA groups and add new ones to BP
		if ( 'verbose' === $this->debug ) { 
			_log( 'About to sync using this mla_groups_list:' );
			_log( $this->mla_groups_list );
			_log( 'And about to sync using this bp_groups_list:' ); 
			_log( $this->bp_groups_list ); 
		} 
		$diff = array_diff_assoc( $this->mla_groups_list, $this->bp_groups_list ); 
		_log( 'MLA API groups for this member that aren\'t in the BP member group list:', $diff ); 

		$bp_admins = array( 'admin', 'mod' ); 

		foreach ( $diff as $group_id => $member_role  ) { 
			$bp_role = ( array_key_exists( $group_id, $this->bp_groups_list ) ) ? $this->bp_groups_list[$group_id] : false; 

			_log( "Now handling group $group_id, which is different between MLA and BP records." ); 
		        _log( "Member has role: \"$member_role\" in the MLA API and role: \"$bp_role\" on BP." ); 
			// If the user isn't yet a member of the BP group, add them and promote them as necessary. 
			if ( ! $bp_role ) {
				_log( "$group_id not found in this user's membership list. Adding user $this->user_id to group $group_id" );
				groups_join_group( $group_id, $this->user_id );

				// Now promote user if user is chair or equivalent.
				// Should user be a BP admin according to the MLA API? 
				if ( in_array( $member_role, $bp_admins ) ) {
					groups_promote_member( $this->user_id, $group_id, 'admin' );
				}
				continue; // Nothing more to do here. 
			} else { 
				// Now handle cases where the user is a member of the BP group, but 
				// doesn't have the right permissions. 

				// Now demote member if member isn't an admin according to the MLA API records. 
				// Is user a BP admin or mod, but shouldn't be? 
				if ( in_array( $this->bp_groups_list[ $group_id ], $bp_admins ) ) { 
					groups_demote_member( $this->user_id, $group_id ); 
				} 
			} 
		} 

		// Now loop through the bp groups list and remove any groups
		// with MLA OIDs that don't exist in the MLA database for this user, 
		// because we don't need to sync those. 
		foreach ( $this->bp_groups_list as $group_id => $member_role ) {
			if ( ! array_key_exists( $group_id, $this->mla_groups_list ) ) {
				_log( "BP group ID $group_id not found in MLA groups list for this member." ); 

				// Ignore prospective forums. 
				$diffed_group_mla_oid = groups_get_groupmeta( $group_id, 'mla_oid' );  
				if ( 'FXX' == $diffed_group_mla_oid ) { 
					_log( "Group $group_id is a prospective forum, though, so ignoring." ); 
					continue; 
				} 

				_log( 'Assuming member has been removed from this group on the MLA API, so removing this user from the Commons group.' );
				groups_leave_group( $group_id, $this->user_id );
			}
		}

		$this->update_last_updated_time();

		return true;
	}
}
