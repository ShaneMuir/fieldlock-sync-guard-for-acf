<?php
/**
 * Plugin Name:       FieldLock Sync Guard for ACF
 * Description:       Prevents ACF field groups from being edited while Local JSON changes are waiting to be synced.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Author:            Shane Muirhead
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fieldlock-sync-guard-for-acf
 *
 * @package FieldLock_Sync_Guard_For_ACF
 */

defined( 'ABSPATH' ) || exit;

define( 'FIELDLOCK_SYNC_GUARD_FOR_ACF_VERSION', '1.0.0' );
define( 'FIELDLOCK_SYNC_GUARD_FOR_ACF_FILE', __FILE__ );
define( 'FIELDLOCK_SYNC_GUARD_FOR_ACF_DIR', plugin_dir_path( __FILE__ ) );
define( 'FIELDLOCK_SYNC_GUARD_FOR_ACF_URL', plugin_dir_url( __FILE__ ) );

require_once FIELDLOCK_SYNC_GUARD_FOR_ACF_DIR . 'includes/class-fieldlock-sync-guard-for-acf.php';

FieldLock_Sync_Guard_For_ACF::instance();
