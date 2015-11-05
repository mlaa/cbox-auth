var readCookie;

jQuery(document).ready(function($) {
  var needsPreferred, preferredVerified, preferredVisible;
  $('#input-preferred').hide();
  preferredVisible = false;
  preferredVerified = false;
  needsPreferred = true;
  return $('#login form').submit(function(e) {
    var data, error;
    $('#login_error, .message').remove();
    if (!needsPreferred || (preferredVisible && preferredVerified)) {
      return true;
    }
    if (preferredVisible) {
      if (!$('#user_acceptance').is(':checked')) {
        $('#wp-submit').removeClass('busy');
        error = $('<div id="login_error"><strong>Error (999):</strong> You must agree to the Terms of Service, Privacy Policy, and Guidelines for Participation.</div>');
        $('#login form').before(error);
        return false;
      }
      if ($('#user_login_preferred').val() === '') {
        return true;
      }
      data = {
        action: 'validate_preferred_username',
        preferred: $('#user_login_preferred').val(),
        username: $('#user_login').val(),
        password: $('#user_pass').val()
      };
      $('#wp-submit').addClass('busy');
      $.post(WordPress.ajaxurl, data, function(response) {
        response = $.parseJSON(response);
        switch (response.result) {
          case 'false':
            $('#wp-submit').removeClass('busy');
            error = $('<div id="login_error">' + response.message + '</div>');
            return $('#login form').before(error);
          case 'true':
            preferredVerified = true;
            return $('#login form').submit();
        }
      });
      return false;
    }
    $('#wp-submit').addClass('busy');
    if (hex_md5($('#user_login').val()) === readCookie('MLABeenHereBefore')) {
      return true;
    }
    data = {
      username: $('#user_login').val(),
      password: $('#user_pass').val(),
      action: 'test_user'
    };
    $.post(WordPress.ajaxurl, data, function(response) {
      response = $.parseJSON(response);
      switch (response.result) {
        case "true":
          $('#forgot-password').hide();
          $('#input-preferred').show();
          $('#user_login').parents('p').hide();
          $('#user_pass').parents('p').hide();
          $('#login p.forgetmenot').hide();
          $('#nav').hide();
          $('#wp-submit').val('Save').removeClass('busy');
          $('#user_login_preferred').val(response.guess.toLowerCase());
          return preferredVisible = true;
        case "false":
          if (response.message.length > 0) {
            $('#wp-submit').removeClass('busy');
            error = $('<div id="login_error">' + response.message + '</div>');
            return $('#login form').before(error);
          }
          needsPreferred = false;
          return $('#login form').submit();
      }
    });
    return false;
  });
});

readCookie = function(name) {
  var c, ca, i, nameEQ;
  nameEQ = name + "=";
  ca = document.cookie.split(";");
  i = 0;
  while (i < ca.length) {
    c = ca[i];
    while (c.charAt(0) === " ") {
      c = c.substring(1, c.length);
    }
    if (c.indexOf(nameEQ) === 0) {
      return c.substring(nameEQ.length, c.length);
    }
    i++;
  }
  return null;
};
