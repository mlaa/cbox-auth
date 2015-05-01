<?php
/* This class is a replacement for the class MLAAPIRequest, created solely 
 * for the purpose of overriding MLA API calls, and getting mock data instead. 
 * Used for testing purposes. 
 */ 
class MLAAPIRequest { 
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
} 
