<?php
/**
 * Uninstall cleanup.
 *
 * @package FieldLock_Sync_Guard_For_ACF
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_transient( 'fieldlock_sync_guard_for_acf_pending' );
