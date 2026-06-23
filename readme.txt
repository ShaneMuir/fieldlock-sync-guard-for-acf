=== JSON Sync Guard for ACF ===
Contributors: shanemuir
Tags: acf, advanced custom fields, local json, sync, field groups
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Prevents ACF field groups from being edited while Local JSON changes are waiting to be synced.

== Description ==

JSON Sync Guard for ACF detects ACF field-group JSON files that do not yet exist in the WordPress database or have a newer modified timestamp. While a sync is pending, it:

* Shows an administrator notice with a link to ACF's sync screen.
* Disables the Update button on ACF field-group edit screens.
* Blocks normal field-group saves on the server.

Private Local JSON files are ignored. Detection is cached briefly and runs only in the WordPress administrator. The plugin works with ACF and ACF PRO, and does not include ACF.

The plugin makes no remote requests, collects no telemetry, and contains no upsells.

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/`, or install the plugin ZIP through Plugins > Add New.
2. Activate ACF or ACF PRO.
3. Activate JSON Sync Guard for ACF.
4. When a warning appears, follow its link and sync the pending field groups in ACF.

== Frequently Asked Questions ==

= What counts as a pending sync? =

A non-private `group_` Local JSON item is pending when its key does not exist as an `acf-field-group` post in the database, or when its `modified` timestamp is newer than the database post's modified timestamp.

= Can lock behaviour be customized? =

Yes. Developers can use these filters:

* `json_sync_guard_for_acf_should_lock` changes the final lock decision.
* `json_sync_guard_for_acf_sync_url` changes the notice link.
* `json_sync_guard_for_acf_cache_lifetime` changes the transient lifetime in seconds.
* `json_sync_guard_for_acf_capability` changes the capability used for notices and locks.

Example:

`add_filter( 'json_sync_guard_for_acf_cache_lifetime', function () { return 30; } );`

== Changelog ==

= 1.0.0 =
* Initial release.
