<?php
/**
 * Uninstall cleanup for WooCommerce S3 Download Manager.
 *
 * Removes options and custom tables. Does not delete S3 objects or alter product download references.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'wcs3dm_settings' );
delete_option( 'wcs3dm_credentials' );
delete_option( 'wcs3dm_db_version' );
delete_option( 'wcs3dm_logs' );
delete_option( 'wcs3dm_stats' );

$tables = array(
	$wpdb->prefix . 'wcs3dm_migration_log',
	$wpdb->prefix . 'wcs3dm_cleanup_log',
	$wpdb->prefix . 'wcs3dm_jobs',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from trusted prefix.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}
