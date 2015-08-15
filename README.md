# WordPress/CBOX Authentication Plugin

[![Build status][build-status]][travis-ci]

This plugin allows authentication against an external propietary API. The API
response provides user metadata which is used to populate the user in or out
of groups within an installation of [Commons-in-a-Box][cbox], including roles
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

### License

The source code of this plugin is released under the GPLv2 (see LICENSE.txt).

[travis-ci]: https://travis-ci.org/mlaa/cbox-auth
[build-status]: https://travis-ci.org/mlaa/cbox-auth.svg?branch=develop
[cbox]: http://commonsinabox.org
