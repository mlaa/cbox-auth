jQuery(document).ready( ($)->
	# Hide the preferred username field from the start
	$('#input-preferred').hide()

	# Flags for various states of the form
	preferredVisible = false
	preferredVerified = false
	needsPreferred = true

	# Capture all submits
	$('#login form').submit( (e) ->
		$('#login_error, .message').remove()
	# Allow the last submit to go through if the preferred username is necessary and valid
		return true if !needsPreferred or (preferredVisible and preferredVerified)

		# Handle the second submission when a user saves a preferred username
		if preferredVisible
			# User must accept terms
			if !$('#user_acceptance').is(':checked')
				$('#wp-submit').removeClass('busy')
				error = $('<div id="login_error"><strong>Error (999):</strong> You must agree to the Terms of Service, Privacy Policy, and Guidelines for Participation.</div>')
				$('#login form').before(error)
				return false

			# If the preferred username is empty, just submit it
			return true if $('#user_login_preferred').val() == ''

			# Use AJAX to see if the preferred username already exists
			data =
				action: 'validate_preferred_username'
				preferred: $('#user_login_preferred').val()
				username: $('#user_login').val()
				password: $('#user_pass').val()
			$('#wp-submit').addClass('busy')
			$.post(WordPress.ajaxurl, data, (response) ->
				response = $.parseJSON(response)
				switch response.result
					when 'false'
						$('#wp-submit').removeClass('busy')
						error = $('<div id="login_error">'+response.message+'</div>')
						$('#login form').before(error)
					when 'true'
						preferredVerified = true
						$('#user_login').val($('#user_login_preferred').val())
						$('#login form').submit()
			)
			return false

		# Handle the first submission.
		$('#wp-submit').addClass('busy')

		# Has the user successfully logged in from this client before?
		# If so, skip the 'first-time' ajax call
		return true if hex_md5($('#user_login').val()) == readCookie('AuthBeenHereBefore')

		# See if the user's WP is being created for the first time.
		data =
			username: $('#user_login').val()
			password: $('#user_pass').val()
			action: 'test_user'
		$.post(WordPress.ajaxurl, data, (response) ->
			response = $.parseJSON(response)
			switch response.result
				when "true"
					$('#forgot-password').hide();
					$('#input-preferred').show()
					$('#user_login').parents('p').hide()
					$('#user_pass').parents('p').hide()
					$('#login p.forgetmenot').hide()
					$('#nav').hide()
					$('#wp-submit').val('Save').removeClass('busy')
					$('#user_login_preferred').val(response.guess.toLowerCase())
					preferredVisible = true
				when "false"
					needsPreferred = false
					$('#login form').submit()
		)
		return false
	)
)

readCookie = (name) ->
	nameEQ = name + "="
	ca = document.cookie.split(";")
	i = 0

	while i < ca.length
		c = ca[i]
		c = c.substring(1, c.length)  while c.charAt(0) is " "
		return c.substring(nameEQ.length, c.length)  if c.indexOf(nameEQ) is 0
		i++
	null
