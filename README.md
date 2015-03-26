# WordPress/CBOX Authentication Plugin

![Is the build passing?](https://travis-ci.org/mlaa/cbox-auth.svg?branch=develop)

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
  - [x] 0213 15:33 User logs in for the first time. 
    - Should present user with username change screen. 
    - [x] 0213 13:41 User chooses a username that is already taken. 
      - Should present user with message explaining that the name is taken. 
    - [x] 0213 13:41 User chooses an username with invalid characters. 
      - Should present user with message explaining that some characters are not allowed. 
    - [x] 0213 15:32 User choose a username with too many characters
      - Should throw error
    - [x] 0213 13:42 User enters nothing in the username box. 
      - Should successfully log user in. 
 * [x] 0212 16:20 User tries to log in with incorrect password. 
  - Should disallow log-in and present user with an error message saying so. 
 * [x] 0213 15:42 User's password has changed in Oracle, and user logs in with new password. 
  - Should successfully log user in. 
 * [/] Oracle user becomes inactive, and user tries to log in. 
  - Should disallow the user to log in, and return an error about the user's membership status. 
  - Can't test, since API doesn't seem to have the capability to change a member's status.
    - [x] 0219 16:26 tested with PHPUnit 
 * [x] 0213 15:52 New user created in Oracle, with some groups excluded from Commons. 
  - Should create user in WP, add to all relevant groups, except those excluded from Commons. 
 * [x] 0213 16:00 User added to forum(s) in Oracle, some of which are excluded from Commons. 
  - Should add the user to those forums in BP, omitting those forums that are excluded from the Commons. 
    - Syncs in My Commons -> Groups, but the badge count next to the tab is out of sync for one page load.
    - Doesn't sync in Groups -> My Groups tab
      - opened cbox-auth #8 about this. 
 * [x] 0213 15:56 User removed from forum(s) in Oracle, some of which are excluded from Commons. 
  - Should remove those user from those forums in BP. 
 * [/] New forum created in Oracle. 
  - Should create that forum in BP and add all members to it, except for those members that are not yet on the Commons. 
    - Can't test, since API doesn't seem to have the capability to add an organization.
      - opened cbox-auth #9 about this. 
  - [/] New forum created in Oracle, excluded from Commons. 
    - Nothing should happen on Commons side. 
    - Can't test, API doesn't have this ability.
      - opened cbox-auth #10 for this.  
 * [x] 0212 15:31 BP user leaves a committee or discussion group. 
  - If that group exists in Oracle, the user should be removed from the group in Oracle. 
 * [x] 0212 15:31 BP user joins a discussion group or committee. 
  - If that group exists in Oracle, the user should be added to the group in Oracle. 
 * [x] 0213 16:03 User visits his or her own Portfolio page. 
  - Name, title, institutional affiliation, and groups for that user's own portfolio should be synced with Oracle every hour. 
 * [x] 0213 16:19 User visits another user's page. 
  - Name, title, institutional affiliation, and groups for viewed user's portfolio should be synced with Oracle every hour. 
 * [x] 0213 16:20 User visits a group page.
  - If that page is a committee or discussion group, membership data should be synced with Oracle every hour.  
    - **Works, but the members count in the badge is out of sync with the count at the bottom.**
 * [x] 0220 11:20 Set up Travis

### Testing Round 2
 * [ ] User logs in with correct password. 
  - Should successfully log user in, if user has already logged in at least once. 
  - [ ] User logs in for the first time. 
    - Should present user with username change screen. 
    - [ ] User chooses a username that is already taken. 
      - Should present user with message explaining that the name is taken. 
    - [ ] User chooses an username with invalid characters. 
      - Should present user with message explaining that some characters are not allowed. 
    - [ ] User choose a username with too many characters
      - Should throw error
    - [ ] User enters nothing in the username box. 
      - Should successfully log user in. 
 * [ ] User tries to log in with incorrect password. 
  - Should disallow log-in and present user with an error message saying so. 
 * [ ] User's password has changed in Oracle, and user logs in with new password. 
  - Should successfully log user in. 
 * [ ] Oracle user becomes inactive, and user tries to log in. 
  - Should disallow the user to log in, and return an error about the user's membership status. 
  - Can't test, since API doesn't seem to have the capability to change a member's status.
    - [ ] tested with PHPUnit 
 * [ ] New user created in Oracle, with some groups excluded from Commons. 
  - Should create user in WP, add to all relevant groups, except those excluded from Commons. 
 * [ ] User added to forum(s) in Oracle, some of which are excluded from Commons. 
  - Should add the user to those forums in BP, omitting those forums that are excluded from the Commons. 
    - Syncs in My Commons -> Groups, but the badge count next to the tab is out of sync for one page load.
    - Doesn't sync in Groups -> My Groups tab
      - opened cbox-auth #8 about this. 
 * [ ] User removed from forum(s) in Oracle, some of which are excluded from Commons. 
  - Should remove those user from those forums in BP. 
 * [ ] New forum created in Oracle. 
  - Should create that forum in BP and add all members to it, except for those members that are not yet on the Commons. 
    - Can't test, since API doesn't seem to have the capability to add an organization.
      - opened cbox-auth #9 about this. 
  - [ ] New forum created in Oracle, excluded from Commons. 
    - Nothing should happen on Commons side. 
    - Can't test, API doesn't have this ability.
      - opened cbox-auth #10 for this.  
 * [ ] BP user leaves a committee or discussion group. 
  - If that group exists in Oracle, the user should be removed from the group in Oracle. 
 * [ ] BP user joins a discussion group or committee. 
  - If that group exists in Oracle, the user should be added to the group in Oracle. 
 * [ ] User visits his or her own Portfolio page. 
  - Name, title, institutional affiliation, and groups for that user's own portfolio should be synced with Oracle every hour. 
 * [ ] User visits another user's page. 
  - Name, title, institutional affiliation, and groups for viewed user's portfolio should be synced with Oracle every hour. 
 * [ ] User visits a group page.
  - If that page is a committee or discussion group, membership data should be synced with Oracle every hour.  
    - **Works, but the members count in the badge is out of sync with the count at the bottom.**
 * [ ] Set up Travis

### License

The source code of this plugin is released under the GPLv2 (see LICENSE.txt).

[1]: http://commonsinabox.org
