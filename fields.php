<?php
/**
 * This code adds some extra fields to the user's profile page that already
 * exist in the custom Authentication API response.
 */

class CustomAuthenticationCustomFields {

    /**
     * Show custom user fields in the profile
     *
     * @param $user
     */
    public function showFields($user) {
?>
        <h3>MLA Details</h3>

        <table class="form-table">
            <tbody>
                <?php $this->languagesRow($user) ?>
                <?php $this->affiliationsRow($user) ?>
                <?php $this->acceptedRow($user) ?>
            </tbody>
        </table>
<?php
    }

	public function showExtraLoginFields() {
?>
		<noscript>
			<p class="warning"><strong>Warning:</strong> JavaScript is required when logging in for the first time.</p>
		</noscript>
		<div id="input-preferred">
			<label for="user_login_preferred"><strong>Please choose a user name.</strong></label>
			<p><input type="text" id="user_login_preferred" name="preferred" tabindex="30" /></p>
			<p><strong>This is your only opportunity to choose a user name and you cannot change it later.</strong> Your user name will be visible to other members.</p>
			<p>User names must be between four and twenty characters in length and must contain at least one letter. Only lowercase letters, numbers, and underscores are allowed.</p>
			<p class="fineprint"><input type="checkbox" id="user_acceptance" name="acceptance" tabindex="40" value="Yes" /> <label for="user_acceptance">I accept the <a href="/terms/">Terms of Service</a>, <a href="/privacy/">Privacy Policy</a>, and <a href="/guidelines/">Guidelines for Participation</a>.</label></p>
		</div>
        <p id="forgot-password"><strong>Use your mla.org credentials to log in.</strong> <a href="http://www.mla.org/login">Forgot your login information?</a></p>
<?php
	}

	public function addLoginScripts() {
		wp_enqueue_style('auth_login_style', plugins_url('resources/login.css', __FILE__), false);
		wp_enqueue_script('jquery');
		wp_enqueue_script('auth_login_script', plugins_url('resources/login.js', __FILE__), array('jquery'));
		wp_enqueue_script('auth_md5_script', plugins_url('resources/md5-min.js', __FILE__), false);
		wp_localize_script('auth_login_script', 'WordPress', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
	}

    private function languagesRow($user) {
        $languages = get_the_author_meta('languages', $user->ID);
        if($languages) {
?>
             <tr>
                <th><label>Languages</label></th>
                <td>
                    <ul>
                        <?php $this->listItems($languages); ?>
                    </ul>
                </td>
             </tr>
<?php
        }
    }

    private function affiliationsRow($user) {
        $affiliations = get_the_author_meta('affiliations', $user->ID);
        if($affiliations) {
?>
            <tr>
                <th><label>Affiliations</label></th>
                <td>
                    <ul>
                        <?php $this->listItems($affiliations); ?>
                    </ul>
                </td>
            </tr>
<?php
        }
    }

    private function acceptedRow($user) {
?>
        <tr>
            <th><label>Accepted Terms</label></th>
            <td><?php if(get_the_author_meta('accepted_terms', $user->ID) == 'Yes'){ echo 'Yes'; } else { echo 'No'; }  ?></td>
        </tr>
<?php
    }

    private function listItems($items) {
        foreach($items as $item) {
            $item = esc_attr($item);
            echo "<li>$item</li>";
        }
    }
}


// Hook into the actions now
$myCustomAuthenticationCustomFields = new CustomAuthenticationCustomFields();
add_action('show_user_profile', array($myCustomAuthenticationCustomFields, 'showFields'));
add_action('edit_user_profile',array($myCustomAuthenticationCustomFields, 'showFields'));
add_action('login_form', array($myCustomAuthenticationCustomFields, 'showExtraLoginFields'));
add_action('login_enqueue_scripts', array($myCustomAuthenticationCustomFields, 'addLoginScripts'));

?>
