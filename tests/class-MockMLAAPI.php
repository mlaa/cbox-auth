<?php
/* This class is a replacement for the class MLAAPI, created solely 
 * for the purpose of overriding MLA API calls, and getting mock data instead. 
 * Used for testing purposes. 
 */ 

class MLAAPI { 
	public function get_member() { 
		$member_json = file_get_contents( 'tests/data/mock-member.json' );
		$member_data = array( 
			'code' => 200, 
			'body' => $member_json, 
		); 
		//$member_data = json_decode( $member_json, true )['data'][0];
		return $member_data; 
	} 
	public function get_mla_group_data_from_api() { 
		$group_json = file_get_contents( 'tests/data/mock-group.json' );
		$group_data = array( 
			'code' => 200, 
			'body' => $group_json, 
		); 
		return $group_data; 

	} 

	// I wish there were a better way of doing this apart from 
	// just copying and pasting code here. 
	
	/**
	 * Translate MLA roles like 'chair', 'liaison,' 'mla staff', into
	 * the corresponding BP role, like 'admin', member.
	 *
	 * @param $mla_role str the MLA role, like 'chair', 'mla staff.'
	 * @return $bp_role str the BP role, like 'admin', 'member.'
	 */
	public function translate_mla_role( $mla_role ){

		$mla_role = strtolower( $mla_role); 

		// list of MLA group roles that count as admins, stolen from
		// class-CustomAuthentication.php:232
		$mla_admin_roles = array('chair', 'liaison', 'liason', 'secretary', 'executive', 'program-chair');
		if ( in_array( $mla_role, $mla_admin_roles ) ) {
			$bp_role = 'admin';
		} else {
			$bp_role = 'member';
		}
		return $bp_role;
	}
} 
