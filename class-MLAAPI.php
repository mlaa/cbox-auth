<?php
/** 
 * This class that contains methods common to the classes MLAGroup
 * and MLAMember.  
 */
class MLAAPI extends MLAAPIRequest {

	/**
	 * Gets a BuddyPress group ID if given the group's MLA OID.
	 * @param $mla_oid str, the MLA OID, i.e. D086
	 * @return int BuddyPress group ID, i.e. 86
	 */
	public function get_group_id_from_mla_oid( $mla_oid ) {
		global $wpdb;
		$sql = "SELECT group_id FROM wp_bp_groups_groupmeta WHERE meta_key = 'mla_oid' AND meta_value = '$mla_oid'";
		// @todo use wp_cache_get or some other caching method
		$result = $wpdb->get_results( $sql );
		if ( count( $result ) > 0 ) { 
			return $result[0]->group_id;
		} else { 
			return false; 
		} 
	}

	/**
	 * Gets a BP user ID if given that user's MLA OID.
	 * @param $mla_oid str, the user's MLA OID
	 * @return $bp_user_id str, that user's BP User ID
	 */
	public function get_bp_user_id_from_mla_oid( $mla_oid ) {
		global $wpdb;
		$sql = "SELECT user_id FROM wp_usermeta WHERE meta_key = 'mla_oid' AND meta_value = '$mla_oid'";
		// @todo use wp_cache_get or some other caching method
		$result = $wpdb->get_results( $sql );
		if ( count( $result ) > 0 ) { 
			return $result[0]->user_id;
		} else { 
			return false; 
		} 
	}

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
