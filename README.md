# WordPress/CBOX Authentication Plugin

This plugin allows authentication against an external propietary API. The API
response provides user metadata which is used to populate the user in or out
of groups within an installation of [Commons-in-a-Box][1], including roles
within the group.

### Installation

1. Move this directory to wp-content/plugins.
2. Add this to wp-config.php: `define('CBOX_AUTH_SECRET_TOKEN', '<secret_token_here>');`
3. And this: `define('CBOX_AUTH_GROUPS_SECRET_TOKEN', '<secret_token_here>');`
4. Network-activate the plugin.

### Debugging

To turn on debugging of the plugin you can add and customize the following lines into wp-config.php.

```php
/**
 * Debugging
 *
 * CBOX_AUTH_DEBUG => true or false
 * CBOX_AUTH_DEBUG_LOG => false or '/log/file/location/...', %t = timestamp, %r = random number, %h = hash of message
 */
define('CBOX_AUTH_DEBUG', true);
define('CBOX_AUTH_DEBUG_LOG', '/log/file/location/username_%t.log');
```

## TODO: Testing New Auth Plugin
 * [x] 0212 16:20 User logs in with correct password. 
  - Should successfully log user in, if user has already logged in at least once. 
  - [ ] User logs in for the first time. 
    - Should present user with username change screen. 
    - [ ] User chooses a username that is already taken. 
      - Should present user with message explaining that the name is taken. 
    - [!] User chooses an username with invalid characters. 
      - Should present user with message explaining that some characters are not allowed. 
      BROKEN: when changing username to a username containing spaces, auth plugin doesn't validate, and the username is changed to a username containing spaces in the API. 
    - [ ] User enters nothing in the username box. 
      - Should successfully log user in. 
 * [x] 0212 16:20 User tries to log in with incorrect password. 
  - Should disallow log-in and present user with an error message saying so. 
 * [ ] User's password has changed in Oracle, and user logs in with new password. 
  - Should successfully log user in. 
 * [ ] Oracle user becomes inactive, and user tries to log in. 
  - Should disallow the user to log in, and return an error about the user's membership status. 
 * [ ] New user created in Oracle, with some groups excluded from Commons. 
  - Should create user in WP, add to all relevant groups, except those excluded from Commons. 
 * [ ] User added to forum(s) in Oracle, some of which are excluded from Commons. 
  - Should add the user to those forums in BP, omitting those forums that are excluded from the Commons. 
 * [ ] User removed from forum(s) in Oracle, some of which are excluded from Commons. 
  - Should remove those user from those forums in BP. 
 * [ ] New forum created in Oracle. 
  - Should create that forum in BP and add all members to it, except for those members that are not yet on the Commons. 
  - [ ] New forum created in Oracle, excluded from Commons. 
    - Nothing should happen on Commons side. 
 * [x] 0212 15:31 BP user leaves a committee or discussion group. 
  - If that group exists in Oracle, the user should be removed from the group in Oracle. 
 * [x] 0212 15:31 BP user joins a discussion group or committee. 
  - If that group exists in Oracle, the user should be added to the group in Oracle. 
 * [ ] User visits his or her own Portfolio page. 
  - Name, title, institutional affiliation, and groups for that user's own portfolio should be synced with Oracle every hour. 
 * [ ] User visits another user's page. 
  - Name, title, institutional affiliation, and groups for viewed user's portfolio should be synced with Oracle every hour. 
 * [ ] User visits a group page.
  - If that page is a committee or discussion group, membership data should be synced with Oracle every hour.  

### License

The source code of this plugin is released under the GPLv2 (see LICENSE.txt).

[1]: http://commonsinabox.org
