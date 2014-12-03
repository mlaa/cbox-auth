<?php 

/* The abstract API class is in another file. */ 
require_once( 'class-MLAAPI.php' ); 

/* This class, MLA Group, is primarily used to update group memberships, 
 * so that when there is a group membership change, these are updated more frequently than 
 * when the user logs out and logs back in.  
 */ 
class MLAGroup extends MLAAPI { 
	public $group_bp_id = 0; 
	public $group_mla_oid = 0; 
	public $members = array(); // guessing that members list is going to be an array of member IDs
	private $update_interval = 3600; // number of seconds below which to force update of group membership data. 

	/* Checks when the group member data was last updated,
	 * so that it doesn't reload it from the member API 
	 * unnecessarily.
	 *
	 * @return bool 
	 */ 
	public function is_too_old() { 
		$last_updated = groups_get_groupmeta( $this->group_bp_id, 'last_updated' ); 
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
		groups_update_groupmeta( $this->group_bp_id, 'last_updated', time() ); 
	} 

	/* 
	 * Gets group data, like membership, etc, from the new API. 
	 * This mockup assumes that there will eventually be a way to ask 
	 * the API for group data that includes a list of all the group members. 
	 */ 
	public function get_mla_group_data() { 
		if ( ! $this->group_bp_id ) { 
			$this->group_bp_id = bp_get_group_id(); 
		} 
		if ( ! $this->group_mla_oid ) { 
			$this->group_mla_oid = groups_get_groupmeta( $group_bp_id, 'mla_oid' ); 
		} 
		/* Mock group members: 
		 *   Jonathan - jonreeve - 3164 
		 *   Katina - katinalynn - 57
		 *   Chris - czarate - 1
		 *   Kathleen - kfitz - 60
		 */ 
		$this->mla_members_list = array( 
			3164 	=> 'chair', 
			57 	=> 'member', 
			1 	=> 'member', 
			60 	=> 'member', 
		); 
		//_log( 'mla members list:' ); 
		//_log( $this->mla_members_list ); 

	} 

	/* Gets group membership data from BP. 
	 * Populates `bp_members_list` with an array made to resemble the $this->members array. 
	 */ 
	public function get_bp_group_data() { 
		
		$this->group_bp_id = bp_get_group_id(); 
		$this->group_mla_oid = groups_get_groupmeta( $group_bp_id, 'mla_oid' ); 

		$args = array( 
			'group_id'		=> $this->group_bp_id, 
			'per_page'		=> 999, 
			'exclude_admins_mods'	=> false, 
		); 
				
		$this->bp_members = groups_get_group_members( $args );   

		//_log( 'bp_members are:' ); 
		//_log( $members ); 

		$this->bp_members_list = array(); 
		foreach ( $this->bp_members['members'] as $member_obj ) { 
			$role = ( 1 == $member_obj->is_mod ) ? 'mod' : 'member'; 
			$role = ( 1 == $member_obj->is_admin ) ? 'admin' : 'member'; 
			$this->bp_members_list[ $member_obj->ID ] = $role; 
		} 
		//_log( 'my bp members list:' ); 
		//_log( $this->bp_members_list ); 
		
	} 

	/* 
	 * Syncs API-given group membership data with that of BuddyPress.
	 * Basically looks to see if there are discrepancies between the member DB
	 * and the BP DB, and if so, changes the BP group membership information
	 * so that it reflects  
	 */ 
	public function sync() { 

		if ( ! $this->is_too_old() ) { 
			//_log( 'No need to sync this group, since it\'s apparently been synced within the last hour.' ); 
			return false; 
		} 

		if ( ! isset( $this->mla_members_list ) ) { 
			$this->get_mla_group_data(); 
		} 

		if ( ! isset( $this->bp_members_list ) ) { 
			$this->get_bp_group_data(); 
		} 

		$group_id = $this->group_bp_id; 

		// loop through members list from DB and make sure they're 
		// all BP members. If not, add them or remove them. 

		foreach ( $this->mla_members_list as $member_id => $member_role ) { 

			//_log( 'parsing member with id: ' ); 
			//_log( $member_id ); 

			// new members
			if ( ! groups_is_user_member( $member_id, $group_id ) ) { 
				//_log( 'user is not a member! adding.' ); 
				groups_join_group( $group_id, $member_id ); 
			} else { 
				//_log( 'user is already a member. skipping.' ); 
			} 

			if ( 'chair' == $member_role ) { 
				groups_promote_member( $member_id, $group_id, 'admin' ); 
			} 
		} 

		// now look through BP members list and remove anyone that doesn't 
		// already exist in the MLA list

		foreach ( $this->bp_members_list as $member_id => $member_role ) { 
			//_log( 'parsing BP member with id: ' ); 
			//_log( $member_id ); 

			if ( ! array_key_exists( $member_id, $this->mla_members_list ) ) { 
				// user is not or no longer a member, remove from BP group
				groups_remove_member( $member_id, $group_id );
			} 
		} 

		$this->update_last_updated_time(); 

	} 
} 
