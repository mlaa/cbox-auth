/**
 * Login form AJAX requests. Enqueued on WordPress login page.
 * @package CustomAuth
 */

/*global document, jQuery, WordPress, hex_md5*/

'use strict';

jQuery(document).ready(function ($) {

  var $els = {
    form: $('#login form'),
    submit: $('#wp-submit'),
    username: $('#user_login'),
    password: $('#user_pass'),
    preferred: $('#user_login_preferred'),
    preferredBox: $('#input-preferred'),
    acceptance: $('#user_acceptance'),
    forgot: $('#forgot-password')
  };

  // Error messages.
  var messages = {
    invalidCredentials: {
      code: '401A',
      message: 'Your username and password could not be verified. Please try again.'
    },
    invalidStatus: {
      code: '402A',
      message: 'Your membership is not active.'
    },
    invalidUsername: {
      code: '450A',
      message: 'User names must be between four and twenty characters in length and must contain at least one letter. Only lowercase letters, numbers, and underscores are allowed.'
    },
    duplicateUsername: {
      code: '460A',
      message: 'That user name already exists.'
    },
    preferredUsername: {
      code: null,
      message: 'Please enter your preferred username.'
    },
    termsOfService: {
      code: null,
      message: 'You must agree to the Terms of Service, Privacy Policy, and Guidelines for Participation.'
    },
    unknownError: {
      code: '400',
      message: 'An unexpected error occurred. Please try again later.'
    }
  };

  var needsPreferred = true;
  var preferredVerified = false;
  var preferredVisible = false;

  // Hide preferred username section. It will be shown only when needed.
  $els.preferredBox.hide();

  // Intercept login form submission.
  return $els.form.on('submit', function () {

    var data;

    // Hide any previously visible messages.
    $('#login_error, .message').remove();

    // If this is the final step of the initial registration process (user
    // enters valid preferred username), then allow the form to submit to WP.
    if (!needsPreferred || (preferredVisible && preferredVerified)) {
      return true;
    }

    // If this is the step where we ask for the user's preferred username,
    // perform validation and send it via AJAX.
    if (preferredVisible) {

      // Display an error if the user did not accept the Terms of Service.
      if (!$els.acceptance.is(':checked')) {
        $els.form.before(getErrorMessage('termsOfService'));
        return false;
      }

      // If the user does not enter a preferred username, do not proceed and do
      // not submit the form.
      if ($els.preferred.val() === '') {
        $els.form.before(getErrorMessage('preferredUsername'));
        return false;
      }

      data = {
        action: 'validate_preferred_username',
        username: $els.username.val(),
        preferred: $els.preferred.val()
      };

      // Send an AJAX request to the plugin with the user's preferred username.
      $els.submit.addClass('busy');
      $.post(WordPress.ajaxurl, data, function (response) {

        switch (response.result) {

          // Username did not pass validation.
          case 'invalid':
            $els.submit.removeClass('busy');
            $els.form.before(getErrorMessage('invalidUsername'));
            break;

          // Username is a duplicate of another username.
          case 'duplicate':
            $els.submit.removeClass('busy');
            $els.form.before(getErrorMessage('duplicateUsername'));
            break;

          // Acceptance of preferred username.
          case 'valid':
            preferredVerified = true;
            $els.form.submit();
            break;

          default:
            $els.submit.removeClass('busy');
            $els.form.before(getErrorMessage('unknownError'));
            break;

        }

      });

      return false;

    }

    // If the user has a cookie indicating they've logged in before with this
    // username, we can save some time by skipping the check to see if they
    // already have an account.

    // jscs:disable requireCamelCaseOrUpperCaseIdentifiers
    if (hex_md5($els.username.val()) === readCookie('MLABeenHereBefore')) {
      return true;
    }
    // jscs:enable

    // Show a loading indicator.
    $els.submit.addClass('busy');

    data = {
      username: $els.username.val(),
      password: $els.password.val(),
      action: 'test_user'
    };

    // Send an AJAX request to the plugin to determine if the user has already
    // created a WordPress account.
    $.post(WordPress.ajaxurl, data, function (response) {

      switch (response.result) {

        // Indicates user already exists and should proceed to WP login.
        case 'existing':
          needsPreferred = false;
          $els.form.submit();
          break;

        // Indicates user was authenticated and has not created an account.
        case 'valid':
          // Hide everything except the preferred username box.
          $els.preferredBox.show();
          $els.forgot.hide();
          $els.username.parents('p').hide();
          $els.password.parents('p').hide();
          $('#login p.forgetmenot').hide();
          $('#nav').hide();

          // Change the text of the submit form.
          $els.submit.val('Save').removeClass('busy');

          // Populate preferred username with initial guess.
          $els.preferred.val(response.guess.toLowerCase());
          preferredVisible = true;

          break;

        case 'invalid_credentials':
          $els.submit.removeClass('busy');
          $els.form.before(getErrorMessage('invalidCredentials'));
          break;

        case 'invalid_status':
          $els.submit.removeClass('busy');
          $els.form.before(getErrorMessage('invalidStatus'));
          break;

        default:
          $els.submit.removeClass('busy');
          $els.form.before(getErrorMessage('unknownError'));
          break;

      }

    });

    // By default, don't submit the form.
    return false;

  });

  function getErrorMessage (key) {
    var message = messages[key].message;
    if (messages[key].code) {
      message = '<strong>Error ' + messages[key].code + ':</strong> ' + message;
    } else {
      message = '<strong>' + message + '</strong>';
    }
    return $('<div id="login_error">' + message + '</div>');
  }

  function readCookie (name) {
    var c;
    var ca;
    var i;
    var nameEQ;
    nameEQ = name + '=';
    ca = document.cookie.split(';');
    i = 0;
    while (i < ca.length) {
      c = ca[i];
      while (c.charAt(0) === ' ') {
        c = c.substring(1, c.length);
      }
      if (c.indexOf(nameEQ) === 0) {
        return c.substring(nameEQ.length, c.length);
      }
      i = i + 1;
    }
    return null;
  }

});
