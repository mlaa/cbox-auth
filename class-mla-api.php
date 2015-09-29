<?php
/**
 * Class MLAAPI
 * @package cbox-auth
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
	 * We can't use the normal groups_join_group() function, since we're hooking into it
	 * in customAuth.php, so we roll our own, based on `groups_join_group()`.
	 * @param int $group_id ID of the group.
	 * @param int $user_id Optional. ID of the user.
	 * @return bool True on success, false on failure.
	 */
	function mla_groups_join_group( $group_id, $user_id ) {

		global $bp;

		// Check if the user has an outstanding invite. If so, delete it.
		if ( groups_check_user_has_invite( $user_id, $group_id ) ) {
			groups_delete_invite( $user_id, $group_id ); }

		// Check if the user has an outstanding request. If so, delete it.
		if ( groups_check_for_membership_request( $user_id, $group_id ) ) {
			groups_delete_membership_request( $user_id, $group_id ); }

		// User is already a member, just return true.
		if ( groups_is_user_member( $user_id, $group_id ) ) {
			return true; }

		$new_member                = new BP_Groups_Member;
		$new_member->group_id      = $group_id;
		$new_member->user_id       = $user_id;
		$new_member->inviter_id    = 0;
		$new_member->is_admin      = 0;
		$new_member->user_title    = '';
		$new_member->date_modified = bp_core_current_time();
		$new_member->is_confirmed  = 1;

		if ( ! $new_member->save() ) {
			return false; }

		if ( ! isset( $bp->groups->current_group ) || ! $bp->groups->current_group || $group_id !== $bp->groups->current_group->id ) {
			$group = groups_get_group( array( 'group_id' => $group_id ) );
		} else { 			$group = $bp->groups->current_group; }

		// Return without recording activity - too much noise.
		return true;
	}

	/**
	 * We also can't use `groups_leave_group()`, since we've hooked into
	 * that action in customAuth.php. But no problem, we'll just do that
	 * sort of thing ourselves.
	 */
	public function mla_groups_leave_group( $group_id, $user_id ) {
		global $bp;

		// Don't let single admins leave the group.
		if ( count( groups_get_group_admins( $group_id ) ) < 2 ) {
			if ( groups_is_user_admin( $user_id, $group_id ) ) {
				bp_core_add_message( __( 'As the only admin, you cannot leave the group.', 'buddypress' ), 'error' );
				return false;
			}
		}

		// This is exactly the same as deleting an invite, just is_confirmed = 1 NOT 0.
		if ( ! groups_uninvite_user( $user_id, $group_id ) ) {
			return false;
		}

		// bp_core_add_message( __( 'You successfully left the group.', 'buddypress' ) );
		return true;
	}

	/**
	 * Translate MLA roles like 'chair', 'liaison,' 'mla staff', into
	 * the corresponding BP role, like 'admin', member.
	 *
	 * @param $mla_role str the MLA role, like 'chair', 'mla staff.'
	 * @return $bp_role str the BP role, like 'admin', 'member.'
	 */
	public function translate_mla_role( $mla_role ) {

		$mla_role = strtolower( $mla_role );

		// List of MLA group roles that count as admins; see class-CustomAuthentication.php.
		$mla_admin_roles = array( 'chair', 'liaison', 'liason', 'secretary', 'executive', 'program-chair' );
		if ( in_array( $mla_role, $mla_admin_roles ) ) {
			$bp_role = 'admin';
		} else {
			$bp_role = 'member';
		}
		return $bp_role;
	}
}
