<?php
/* This class is a replacement for the class MLAAPIRequest, created solely
 * for the purpose of overriding MLA API calls, and getting mock data instead.
 * Used for testing purposes.
 */
class MLAAPIRequest {
	/**
	 * utility function for DRYing other test methods
	 * might want to extend this in the future to allow setting different response codes etc.
	 *
	 * @param $filename name of mock data file (must exist in tests/data)
	 */
	private function get_mock_data( $filename ) {
		$json = file_get_contents( "tests/data/$filename" );
		$data = array(
			'code' => 200,
			'body' => $json,
		);
		return $data;
	}

	public function get_member() {
		return $this->get_mock_data( 'members/exampleuser.json' );
	}

	public function get_mla_group_data_from_api() {
		return $this->get_mock_data( 'organizations/157.json' );
	}

	/**
	 * TODO good way to test/mock both true and false responses?
	 *
	 * @param $username
	 * @return array
	 */
	public function is_username_duplicate( $username ) {
		return $this->get_mock_data( 'mock-member-duplicate-check-false.json' );
	}
}
