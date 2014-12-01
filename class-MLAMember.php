<?php
/* This class, MLA Member (not to be confused with MLAM ember) interfaces
 * with the new member API, and syncs that data with BuddyPress if it has changed. 
 */
class MLAMember {
	public $name = '';
	public $affiliations = array();
	public $title = ''; // rank
	public $user_id = 0;

	// number of seconds below which to force update of group membership data. 
	private $update_interval = 3600; 

	// at minimum, get the displayed user ID
	function __construct() { 
		$this->user_id = bp_displayed_user_id(); 
	} 

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

	/*
	 * sendRequest
	 * -----------
	 * Send a RESTful request to the API.
	 */
	function send_request( $http_method, $base_url, $parameters = array(), $request_body = "" ) {

		// The `private.php` file contains API passwords. 
		// It populates the variables $api_key and $api_secret. 
		// @todo: put this in wp-config.php
		require_once( 'private.php' ); 

		// Append current time to request parameters (seconds from UNIX epoch).
		$parameters['key'] = $api_key;  
		$parameters['timestamp'] = time();

		// Sort the request parameters.
		ksort($parameters);

		// Collapse the parameters into a URI query string.
		$query_string = http_build_query($parameters, '', '&');

		// Add the request parameters to the base URL.
		$request_url = $base_url . '?' . $query_string;
		//
		// Compute the request signature (see specification).
		$hash_input = $http_method . '&' . rawurlencode($request_url);
		$api_signature = hash_hmac('sha256', $hash_input, $api_secret);

		// Append the signature to the request.
		$request_url = $request_url . '&signature=' . $api_signature;

		_log( 'using request:' ); 
		_log( $request_url ); 

		// Initialize a cURL session.
		$ch = curl_init();
		$headers = array('Accept: application/json');

		// Set cURL options.
		curl_setopt($ch, CURLOPT_URL, $request_url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FAILONERROR, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

		// Validate certificates.
		if ( substr($request_url, 0, 23) === "https://apidev.mla.org/" ) { 
			// openssl x509 -in /path/to/self-signed.crt -text > self-signed.pem
			curl_setopt($ch, CURLOPT_CAINFO, getcwd() . '/wp-content/plugins/cbox-auth/ssl/self-signed.pem');
		} elseif ( substr($request_url, 0, 20) === "https://api.mla.org/" ) { 
			curl_setopt($ch, CURLOPT_CAINFO, getcwd() . '/wp-content/plugins/cbox-auth/ssl/self-signed-production.pem');
		} 
		//elseif ( substr($request_url, 0, 20) === "https://api.mla.org/" ):
		// curl_setopt($ch, CURLOPT_CAINFO, getcwd() . '/ssl/cacert.pem');
		
		// Set HTTP method.
		if ( $http_method === 'PUT' || $http_method === 'DELETE' ) { 
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $http_method);
		} elseif ($http_method === 'POST') { 
			curl_setopt($ch, CURLOPT_POST, 1);
		} 

		// Add request body.
		if ( strlen($request_body) ) { 
			$headers[] = 'Content-Length: ' . strlen($request_body);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
		} 

		// Add HTTP headers.
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		// Send request.
		$response_text = curl_exec($ch);

		// Describe error if request failed.
		if ( !$response_text ) { 
			$response = array(
				'code' => '500',
				'body' => curl_error($ch)
			);
		} else { 
			$response = array(
				'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
				'body' => $response_text
			);
		} 
		// Close cURL session.
		curl_close($ch);
		return $response;
	} 

	/**
	 * Gets the member data from the API and stores it in this class's
	 * parameters. Contains dummy data for now.
	 *
	 * @TODO: make this a private function once I don't need to call it 
	 * for debugging. 
	 */
	public function get_mla_member_data() {
		$request_method = 'GET'; 
		$query_domain = 'members'; 
		$base_url = 'https://apidev.mla.org/1/' . $query_domain; 

		$query = array(
			//'id' => 168880, 
			'last_name' => 'Reeve', 
		); 
		$response = $this->send_request( $request_method, $base_url, $query );  

		_log( 'the response is: ' ); 
		_log( $response ); 

		// dummy data 
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
			// make sure the user doesn't already have something in this field, 
			// because we're about to overwrite it. 
			if ( ! xprofile_get_field_data( $dest_field, $this->user_id ) ) { 
				$source = $this->flatten_array( $this->$source_field );
				if ( xprofile_set_field_data( $dest_field, $this->user_id, $source ) ) {
					//_log( 'Successfully updated xprofile data.' );
				} else {
					//_log( 'Something went wrong while updating xprofile data from member database.' );
				}
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

		$this->update_last_updated_time(); 

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
