
<?php 
/* This class, MLA Group, is primarily used to update group memberships, 
 * so that when there is a group membership change, these are updated more frequently than 
 * when the user logs out and logs back in.  
 */ 
class MLAGroup { 
	public $group_bp_id = 0; 
	public $group_mla_oid = 0; 
	public $members = array(); // guessing that members list is going to be an array of member IDs
	private $update_interval = 3600; // number of seconds below which to force update of group membership data. 

	/* Checks when the group member data was last updated,
	 * so that it doesn't reload it from the member API 
	 * unnecessarily.
	 */ 
	public function is_too_old() { 
		$last_updated = groups_get_groupmeta( $this->group_bp_id, 'last_updated' ); 
		if ( ! $last_updated ) { 
			return true; /* never updated, so, it's too old. */ 
		} else { 
		       return ( time() - $last_updated > 3600 ) ? true: false;
		} 
	} 

	/* 
	 * Gets group data, like membership, etc, from the new API. 
	 * This mockup assumes that there will eventually be a way to ask 
	 * the API for group data that includes a list of all the group members. 
	 */ 
	public function get_group_data() { 
		if ( ! $this->group_bp_id ) { 
			$this->group_bp_id = bp_get_group_id(); 
		} 
		if ( ! $this->group_mla_oid ) { 
			$this->group_mla_oid = gorups_get_group_meta( $group_bp_id, 'mla_oid' ); 
		} 
		/* Mock group members: 
		 *   Jonathan - jonreeve - 3164 
		 *   Katina - katinalynn - 57
		 *   Chris - czarate - 1
		 *   Kathleen - kfitz - 60
		 */ 
		$this->members = array( 3164, 57, 1, 60, ); 
	} 

	/* 
	 * Syncs API-given group membership data with that of BuddyPress.
	 */ 
	public function sync() { 

		if ( ! $this->is_too_old() ) { 
			_log( 'No need to sync groups, since they\'ve apparently been synced within the last hour.' ); 
			return false; 
		} 

		if ( ! $this->members ) { 
			$this->get_group_data(); 
		} 

		_log( 'group meta is:' ); 
		_log( groups_get_groupmeta( $this->group_bp_id ) ); 

	} 
		


} 
