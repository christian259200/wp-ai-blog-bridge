<?php
/**
 * Removes plugin options and the log table. Posts and media stay untouched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'abb_settings' );

global $wpdb;
$table = $wpdb->prefix . 'abb_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
