<?php
/**
 * Uninstall cleanup.
 *
 * @package JSON_Sync_Guard_For_ACF
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_transient( 'json_sync_guard_for_acf_pending' );
