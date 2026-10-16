<?php
/**
 * Plugin activation.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Creates database tables and default options on activation.
 */
final class Activator {

	/**
	 * Run activation routines.
	 */
	public static function activate(): void {
		self::create_tables();
		self::seed_defaults();
		update_option( 'wcs3dm_db_version', '1.0.0', false );
		flush_rewrite_rules();
	}

	/**
	 * Create custom tables.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$mig     = $wpdb->prefix . 'wcs3dm_migration_log';
		$clean   = $wpdb->prefix . 'wcs3dm_cleanup_log';
		$jobs    = $wpdb->prefix . 'wcs3dm_jobs';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$mig} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			download_id varchar(64) NOT NULL DEFAULT '',
			source_path text NOT NULL,
			object_key text NOT NULL,
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			checksum_sha256 char(64) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'pending',
			message text NULL,
			dry_run tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY status (status),
			KEY download_id (download_id)
		) {$charset};

		CREATE TABLE {$clean} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_ids text NOT NULL,
			local_path text NOT NULL,
			object_key text NOT NULL,
			checksum_sha256 char(64) NOT NULL DEFAULT '',
			outcome varchar(32) NOT NULL DEFAULT 'skipped',
			reason text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY outcome (outcome)
		) {$charset};

		CREATE TABLE {$jobs} (
			id varchar(64) NOT NULL,
			type varchar(32) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'queued',
			cursor_data longtext NULL,
			stats longtext NULL,
			options_data longtext NULL,
			lock_token varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY type_status (type, status)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Seed default settings if missing.
	 */
	private static function seed_defaults(): void {
		if ( false === get_option( 'wcs3dm_settings', false ) ) {
			update_option( 'wcs3dm_settings', Settings::defaults(), false );
		}
		if ( false === get_option( 'wcs3dm_credentials', false ) ) {
			update_option( 'wcs3dm_credentials', array(), false );
		}
		if ( false === get_option( 'wcs3dm_stats', false ) ) {
			update_option(
				'wcs3dm_stats',
				array(
					'bytes_transferred' => 0,
					'files_migrated'    => 0,
					'files_verified'    => 0,
					'files_failed'      => 0,
				),
				false
			);
		}
	}
}
