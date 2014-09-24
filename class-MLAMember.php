<?php 
/* This class, MLA Member (not to be confused with MLAM ember) interfaces
 * with the new member API, and syncs that data with BuddyPress if it has changed. */  
class MLAMember { 
	public $user_id = 0; 
	public $name = ''; 
	public $affiliations = array(); 
	public $title = ''; // rank

	/** 
	 * Gets the member data from the API and stores it in this class's 
	 * parameters.
	 * Contains dummy data for now.  
	 */ 
	public function get_member_data() { 
		$this->affiliations[] = array( 'College of Yoknapatawpha' ); 
		$this->first_name = 'William'; 
		$this->last_name = 'Faulkner'; 
		$this->nickname = 'William Faulkner'; 
		$this->fullname = 'William Faulkner'; 
		$this->title = 'Adjunct Professor'; 
	} 
	/**
	 * Gets member data from the new API and, if there are any changes,
	 * updates the corresponding WordPress user. 
	 */ 
	public function sync() { 
		$this->get_member_data(); 
		// don't actually need to map these in an associative array, 
		// since they're already the names of their associates
		$fields_to_sync = array( 'first_name', 'last_name', 'nickname', 'affiliations', 'title' ); 
		foreach ( $fields_to_sync as $field ) { 
			update_user_meta( $this->user_id, $field, $this->$field ); 
			_log( 'Setting user meta:' );
			_log( $field ); 
			_log( 'with data:' ); 
			_log( $this->$field ); 
		} 

		// update xprofile fields
		$xprofile_fields_to_sync = array( 
			'affiliations' => 'Institutional or Other Affiliation', 
			'title' => 'Title', 
			'fullname' => 'Name', 
		);

		foreach ( $xprofile_fields_to_sync as $source_field => $dest_field ) { 
			$source = $this->flatten_array( $this->$source_field ); 
			if ( xprofile_set_field_data( $dest_field, $this->user_id, $source ) ) { 
				_log( 'Successfully updated xprofile data.' ); 
			} else { 
				_log( 'Something went wrong while updating xprofile data from member database.' ); 
			} 
		} 
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
