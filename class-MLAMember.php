<?php
/* This class, MLA Member (not to be confused with MLAM ember) interfaces
 * with the new member API, and syncs that data with BuddyPress if it has changed. 
 */
class MLAMember {
	public $user_id = bp_displayed_user_id();
	public $name = '';
	public $affiliations = array();
	public $title = ''; // rank

	// number of seconds below which to force update of group membership data. 
	private $update_interval = 3600; 

	/* Checks when the group member data was last updated,
	 * so that it doesn't reload it from the member API 
	 * unnecessarily.
	 *
	 * @return bool 
	 */ 
	private function is_too_old() { 
		$last_updated = (integer) get_user_meta( $this->user_id, 'last_updated' ); 
		return true; /* always enable for debugging */ 
		if ( ! $last_updated ) { 
			return true; /* never updated, so, it's too old. */ 
		} else { 
			return ( time() - $last_updated > 3600 );
		} 
	} 

	/* After a sync, we have to update the user meta with the last updated time. 
	 */  
	private function update_last_updated_time() { 
		update_user_meta( $displayed_user_id, 'last_updated', time() ); 
	} 

	/**
	 * Gets the member data from the API and stores it in this class's
	 * parameters.
	 * Contains dummy data for now.
	 */
	private function get_mla_member_data() {
		$this->affiliations[] = array( 'Modern Language Association' );
		$this->first_name = 'Jonathan';
		$this->last_name = 'Reeve';
		$this->nickname = 'Jonathan Reeve';
		$this->fullname = 'Jonathan Reeve';
		$this->title = 'Web Developer';

		$this->mla_groups_list = array(
			'17' 	=> 'member', 
			'44'   => 'member', 
			'46'   => 'member',
		);
	}

	private function get_bp_member_groups() {
		$args = array(
			user_id => $this->user_id,
		);
		$this->bp_groups = groups_get_groups( $args );
		//_log( 'heyoo! have some groups here for you!' );
		//_log( $this->bp_groups );

		$this->bp_groups_list = array();
		foreach ( $this->bp_groups['groups'] as $bp_group ) {
			//_log( 'looking at the bp_group:' ); 
			//_log( $bp_group ); 
			
			// disabling the is_member check for the moment, since for some reason 
			// I don't have that value on any of the groups I'm a member of!
			//if ( $bp_group->is_member ) {
				$role = 'member';
				$this->bp_groups_list[ $bp_group->id ] = $role;
			//}
		}
		//_log( 'bp_groups_list is:' );
		//_log( $this->bp_groups_list );

		// ignore groups that don't have a mla_oid
		foreach ( $this->bp_groups_list as $bp_group_id => $bp_group_role ) {
			if ( ! groups_get_groupmeta( $bp_group_id, 'mla_oid' ) ) {
				unset( $this->bp_groups_list[ $bp_group_id ] );
			}
		}
		//_log( 'bp_groups_list of just the mla groups is now:' );
		//_log( $this->bp_groups_list );
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
			update_user_meta( $this->user_id, $field, $this->$field );
			/*
			 *_log( 'Setting user meta:' );
			 *_log( $field );
			 *_log( 'with data:' );
			 *_log( $this->$field );
			 */
		}

		// Map of fields to sync. Key is incoming MLA field; 
		// value is Xprofile field name.  
		$xprofile_fields_to_sync = array(
			'affiliations' => 'Institutional or Other Affiliation',
			'title' => 'Title',
			'fullname' => 'Name',
		);

		foreach ( $xprofile_fields_to_sync as $source_field => $dest_field ) {
			$source = $this->flatten_array( $this->$source_field );
			if ( xprofile_set_field_data( $dest_field, $this->user_id, $source ) ) {
				//_log( 'Successfully updated xprofile data.' );
			} else {
				//_log( 'Something went wrong while updating xprofile data from member database.' );
			}
		}

		// Now sync member groups. Loop through MLA groups and add new ones to BP
		foreach ( $this->mla_groups_list as $group_id => $member_role ) {
			if ( ! array_key_exists( $group_id, $this->bp_groups_list ) ) {
				_log( "$group_id not found in this user's membership list. adding user $this->user_id to group $group_id" );
				groups_join_group( $group_id, $this->user_id );

				// list of MLA group roles that count as admins, stolen from
				// class-CustomAuthentication.php:232 
				$admins = array('chair', 'liaison', 'liason', 'secretary', 'executive', 'program-chair'); 

				// Now promote user if user is chair or equivalent. 
				if ( in_array( $member_role, $admins ) ) { 
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

		update_last_updated_time(); 

		return true;
	}

	private function flatten_array( $array ) {
		// Some values are stuck in arrays. For example, sometimes affiliations comes back as
		// array( array( 'College of Yoknapatawpha' ) )
		if ( 'array' == gettype( $array ) ) {
			// data is hidden in an array
			$value = $array[0];
			if ( 'array' == gettype( $value ) ) {
				// data is *still* hidden in another array
				$value = $value[0];
			}
		} else {
			// This wasn't an array at all.
			// Carry on. Nothing to see here.
			$value = $array;
		}
		return $value;
	}
}
