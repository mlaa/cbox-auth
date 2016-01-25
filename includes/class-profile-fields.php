<?php
/**
 * Profile Fields
 *
 * @package CustomAuth
 */

namespace MLA\Commons\Plugin\CustomAuth;

// PHPCS recognizes global-namespaced function calls, but not when they are
// part of the sniff.
use \esc_attr as esc_attr;

/**
 * Adds extra fields to the user's profile page.
 *
 * @package CustomAuth
 * @subpackage ProfileFields
 * @class ProfileFields
 */
class ProfileFields extends Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->add_action( 'show_user_profile', $this, 'show_fields' );
		$this->add_action( 'edit_user_profile', $this, 'show_fields' );
		$this->run();
	}

	/**
	 * Show custom profile fields on the profile page.
	 *
	 * @param object $user WordPress user.
	 */
	public function show_fields( $user ) {
?>
		<h3>MLA Details</h3>

		<table class="form-table">
			<tbody>
				<?php $this->generate_list_row( 'Languages', 'languages', $user->ID ) ?>
				<?php $this->generate_list_row( 'Affiliations', 'affiliations', $user->ID ) ?>
				<?php $this->generate_boolean_row( 'Accepted Terms', 'accepted_terms', $user->ID ) ?>
			</tbody>
		</table>
<?php
	}

	/**
	 * Generate a list row of profile data given a user meta key.
	 *
	 * @param string $name     Row name.
	 * @param string $meta_key User meta key to retrieve.
	 * @param int    $user_id  WordPress user id.
	 */
	private function generate_list_row( $name, $meta_key, $user_id ) {
		$data = \get_the_author_meta( $meta_key, $user_id );
		if ( $data ) {
?>
		<tr>
			<th><label><?php echo( esc_attr( $name ) ); ?></label></th>
			<td>
				<ul>
					<?php $this->generate_list_items( $data ); ?>
				</ul>
			</td>
		</tr>
<?php
		}
	}

	/**
	 * Generate list items for profile data array.
	 *
	 * @param array $items Data items.
	 */
	private function generate_list_items( $items ) {
		foreach ( $items as $item ) {
			echo '<li>' . esc_attr( $item ) . '</li>';
		}
	}

	/**
	 * Generate a boolean row of profile data given a user meta key.
	 *
	 * @param string $name     Row name.
	 * @param string $meta_key User meta key to retrieve.
	 * @param int    $user_id  WordPress user id.
	 */
	private function generate_boolean_row( $name, $meta_key, $user_id ) {
?>
		<tr>
			<th><label><?php echo( esc_attr( $name ) ); ?></label></th>
			<td><?php echo ( 'Yes' === \get_the_author_meta( $meta_key, $user_id ) ) ? 'Yes' : 'No'; ?></td>
		</tr>
<?php
	}
}
