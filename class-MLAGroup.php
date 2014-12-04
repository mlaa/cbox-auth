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

	public function __construct( $debug = false ) { 
		// Allow debugging to be turned on by passing a parameter 
		// while instantiating this class. 
		$this->debug = $debug; 

		// Get BuddyPress ID for this group. 
		$this->group_bp_id = bp_get_group_id(); 
		_log( 'This group\'s BP ID is: ', $this->group_bp_id ); 

		// Get MLA OID for this group, e.g. D038. 
		$this->group_mla_oid = groups_get_groupmeta( $this->group_bp_id, 'mla_oid' ); 
		_log( 'This group\'s MLA OID is: ', $this->group_mla_oid ); 

		// Check to see if it already has an MLA API ID. 
		$this->group_mla_api_id = groups_get_groupmeta( $this->group_bp_id, 'mla_api_id' ); 

		if ( ! $this->group_mla_api_id || empty( $this->group_mla_api_id ) ) { 
			_log( 'This group doesn\'t already have an MLA API ID, so asking the API for one.' ); 
			$this->group_mla_api_id = $this->get_group_mla_api_id(); 
			$this->set_group_mla_api_id(); 
		} else { 
			_log( 'Looks like this group already has a recorded MLA API ID, and it\'s:', $this->group_mla_api_id ); 
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
		return true; /* always enable for debugging */ 
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
	 * Gets a lookup table of group IDs so that we can 
	 * translate MLA OIDs (e.g. D045) into MLA API IDs (e.g. 235). 
	 * Returns a table of the form: 
	 * array( [A072] => 234, [A073] => 235 ).
	 * @return $lookup_table assoc. array 
	 */ 
	public function get_group_id_table() { 

		$file_path = plugin_dir_path( __FILE__ ) . 'group_ids.json'; 

		if ( file_exists( $file_path ) ) { 
			$lookup_table = json_decode( file_get_contents( $file_path ), true ); 
			//_log( 'Found group IDs in file. Lookup table is:', $lookup_table ); 
			return $lookup_table; 
		} else { 
			// First get a list of all the organizations. 
			$http_method = 'GET'; 
			$base_url = 'https://apidev.mla.org/1/'; 
			$simple_query = 'organizations';
			$request_url = $base_url . $simple_query; 
			$response = $this->send_request( $http_method, $request_url, $query ); 

			$decoded = json_decode( $response['body'] ); 
			$data = $decoded->data; 
			//_log( 'data is: ', $data ); 

			// Now transform it into a lookup table. 
			$lookup_table = array(); 
			foreach ( $data as $group ) { 
				$lookup_table[$group->convention_code] = $group->id; 
			} 
			//_log( 'lookup table is:', $lookup_table ); 

			file_put_contents( $file_path, json_encode( $lookup_table, true ) ); 
			return $lookup_table; 
		} 
	} 

	public function get_member_id_table() { 
		$file_path = plugin_dir_path( __FILE__ ) . 'member_ids.json'; 

		// @todo: Regenerate this file every so often. 
		
		if ( file_exists( $file_path ) ) { 
			$lookup_table = json_decode( file_get_contents( $file_path ), true ); 
			//_log( 'Found member IDs in file. Lookup table is:', $lookup_table ); 
			return $lookup_table; 
		} else { 
			
			global $wpdb; 
			$sql = "SELECT meta_value, user_id FROM wp_usermeta WHERE meta_key = 'mla_oid'"; 
			// @todo use wp_cache_get or some other caching method 
			$result = $wpdb->get_results( $sql ); 
			$lookup_table = $result; 

			// Clean up lookup table so it's more usable. 
			// Convert an array of objects into an associative array. 
			$lookup_table_clean = array(); 
			foreach( $lookup_table as $member ) { 
				$lookup_table_clean[ $member->meta_value ] = $member->user_id; 
			} 

			//_log( 'Member ID lookup_table clean:', $lookup_table_clean ); 

			file_put_contents( $file_path, json_encode( $lookup_table_clean, true ) ); 
			return $lookup_table_clean; 
		} 
	} 

	/**
	 * Gets a BP user ID if given that user's MLA OID. 
	 * @param $mla_oid str, the user's MLA OID
	 * @return $bp_user_id str, that user's BP User ID
	 */ 
	public function get_bp_user_id_from_mla_oid( $mla_oid ) { 
		if ( empty( $this->member_lookup_table ) ) { 
			$this->member_lookup_table = $this->get_member_id_table(); 
		} 
		// Member lookup table should be in the form: 
		// MLA OID => BP ID
		return $this->member_lookup_table[ $mla_oid ]; 
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

		$lookup_table = $this->get_group_id_table(); 

		if ( ! $lookup_table || empty( $lookup_table ) ) { 
			_log( 'Something went wrong getting the group ID lookup table. Abandon ship!' ); 
			return false; 
		} 

		$mla_api_id = $lookup_table[ $this->group_mla_oid ]; 
		_log( 'Found the group\'s MLA API ID. It\'s:', $mla_api_id ); 

		return $mla_api_id; 
	} 

	/** 
	 * Stores the MLA API ID in the BuddyPress metadata, 
	 * so that we can find it easier in the future. 
	 */ 
	public function set_group_mla_api_id() { 
		$success = groups_update_groupmeta( $this->group_bp_id, 'mla_api_id', $this->group_mla_api_id );  
		if ( ! $success ) { 
			_log( 'Something went wrong while trying to update the MLA API ID for this group in the BuddyPress metadata.' ); 
			return false; 
		} else { 
			_log( 'Successfully set MLA API ID value in BuddyPress group metadata.' ); 
			return true; 
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
		$response = $this->send_request( $http_method, $request_url ); 

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

		// now look up those member IDs 
		$members_list_translated = array(); 
		foreach ( $members_list as $member ) { 
			$members_list_translated[ $this->get_bp_user_id_from_mla_oid( $member->id ) ] = strtolower( $member->position ); 
		} 
		_log( 'Translated members list from MLA API is:',  $members_list_translated ); 

		return $members_list_translated; 
		
		//$members_list = send_request(); 

		/* Mock group members: 
		 *   Jonathan - jonreeve - 3164 
		 *   Katina - katinalynn - 57
		 *   Chris - czarate - 1
		 *   Kathleen - kfitz - 60
		 */ 
		//$this->mla_members_list = array( 
			//3164 	=> 'chair', 
			//7 	=> 'member', 
			//1 	=> 'member', 
			//60 	=> 'member', 
		//); 
		//_log( 'mla members list:', $this->mla_members_list ); 

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
			$bp_members_list[ $member_obj->ID ] = $role; 
		} 
		_log( 'My bp members list:', $bp_members_list ); 

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

		if ( ! isset( $this->mla_members_list ) ) { 
			$this->mla_members_list = $this->get_mla_group_data(); 
		} 

		if ( ! isset( $this->bp_members_list ) ) { 
			$this->bp_members_list = $this->get_bp_group_data(); 
		} 

		$group_id = $this->group_bp_id; 

		_log( 'Now syncing with mla_members_list:', $this->mla_members_list ); 
		_log( 'Now syncing with bp_members_list:', $this->bp_members_list ); 


		return; // debugging. Dry run.   

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
