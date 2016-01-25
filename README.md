# BuddyPress/CBOX Authentication Plugin

[![Build status][build-status]][travis-ci]

This plugin allows authentication against an external propietary API. The API
response provides user metadata which is used to populate the user in or out
of groups within an installation of [Commons-in-a-Box][cbox], including roles
within the group. It requires BuddyPress.

## Installation

1. Move this directory to `wp-content/plugins`.

2. Define the following constants: `CBOX_AUTH_API_URL`, `CBOX_AUTH_API_KEY`,
   `CBOX_AUTH_API_SECRET`.

3. Network-activate the plugin.

## License

The source code of this plugin is released under the GPLv2 (see LICENSE.txt).

[travis-ci]: https://travis-ci.org/mlaa/cbox-auth
[build-status]: https://travis-ci.org/mlaa/cbox-auth.svg?branch=master
[cbox]: http://commonsinabox.org
