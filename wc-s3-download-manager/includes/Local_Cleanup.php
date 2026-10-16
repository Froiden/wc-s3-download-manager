<?php
/**
 * Verified local file cleanup after migration.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Deletes local files only after multi-step verification. Disabled by default.
 */
final class Local_Cleanup {

	/**
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * @var File_Verifier
	 */
	private File_Verifier $verifier;

	/**
	 * @var S3_Client
	 */
	private S3_Client $s3;

	/**
	 * Constructor.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->s3       = new S3_Client( $settings, $logger );
		$this->verifier = new File_Verifier( $settings, $logger, $this->s3 );
	}

	/**
	 * Whether cleanup feature is enabled in settings.
	 */
	public function is_enabled(): bool {
		return (bool) $this->settings->get( 'cleanup_enabled', false );
	}

	/**
	 * Review local files that a successful migration left behind.
	 *
	 * @param array<int, string> $only_paths Limit an execute pass to these local paths.
	 * @return array<string, mixed>
	 */
	public function review( bool $dry_run, array $only_paths = array() ): array {
		$groups = $this->grouped_candidates( 500 );
		$only   = array();
		foreach ( $only_paths as $path ) {
			$only[ (string) $path ] = true;
		}

		$items  = array();
		$totals = array(
			'files'          => 0,
			'bytes'          => 0,
			'ready_files'    => 0,
			'ready_bytes'    => 0,
			'blocked_files'  => 0,
			'blocked_bytes'  => 0,
			'deleted_files'  => 0,
			'deleted_bytes'  => 0,
		);

		foreach ( $groups as $row ) {
			$path = (string) $row['source_path'];
			if ( $only && ! isset( $only[ $path ] ) ) {
				continue;
			}

			$local_bytes = is_file( $path ) ? (int) filesize( $path ) : (int) $row['file_size'];
			$result      = $this->process_entry(
				$path,
				(string) $row['object_key'],
				(string) $row['checksum_sha256'],
				(int) $row['file_size'],
				$row['product_ids'],
				$dry_run,
				! $dry_run
			);

			$result['file_size']   = $local_bytes;
			$result['logged_size'] = (int) $row['file_size'];
			$result['products']    = $row['products'];
			$result['select_path'] = $path;
			$result['deletable']   = 'dry_run' === $result['outcome'];
			$items[]               = $result;

			++$totals['files'];
			$totals['bytes'] += $local_bytes;
			if ( 'dry_run' === $result['outcome'] ) {
				++$totals['ready_files'];
				$totals['ready_bytes'] += $local_bytes;
			} elseif ( 'deleted' === $result['outcome'] ) {
				++$totals['deleted_files'];
				$totals['deleted_bytes'] += $local_bytes;
			} else {
				++$totals['blocked_files'];
				$totals['blocked_bytes'] += $local_bytes;
			}
		}

		return array(
			'dry_run'          => $dry_run,
			'cleanup_enabled'  => $this->is_enabled(),
			'grace_days'       => (int) $this->settings->get( 'cleanup_grace_days', 7 ),
			'items'            => $items,
			'totals'           => $totals,
		);
	}

	/**
	 * Build dry-run or execute cleanup for a local path known from migration log.
	 *
	 * @param array<int, int|string> $product_ids Product IDs that used this file.
	 * @return array<string, mixed>
	 */
	public function process_entry(
		string $local_path,
		string $object_key,
		string $checksum,
		int $file_size,
		array $product_ids,
		bool $dry_run = true,
		bool $write_audit = true
	): array {
		if ( ! $this->is_enabled() ) {
			return $this->audit( $product_ids, $local_path, $object_key, $checksum, $file_size, 'skipped', 'Cleanup is disabled in settings.', $dry_run, $write_audit );
		}

		$prefix = (string) $this->settings->get( 'prefix', 'woocommerce-downloads/' );
		if ( ! Reference::validate_key( $object_key ) || ! Security::key_under_prefix( $object_key, $prefix ) ) {
			return $this->audit( $product_ids, $local_path, $object_key, $checksum, $file_size, 'skipped', 'Object key failed security validation.', $dry_run, $write_audit );
		}

		$validated = Path_Validator::validate_existing( $local_path );
		if ( ! $validated['ok'] ) {
			return $this->audit( $product_ids, $local_path, $object_key, $checksum, $file_size, 'skipped', $validated['message'], $dry_run, $write_audit );
		}
		$local_path = $validated['path'];

		// Grace period: check migration log created_at.
		$grace = (int) $this->settings->get( 'cleanup_grace_days', 7 );
		$grace_status = $this->grace_status( $object_key, $grace );
		if ( $grace > 0 && ! $grace_status['elapsed'] ) {
			return $this->audit(
				$product_ids,
				$local_path,
				$object_key,
				$checksum,
				$file_size,
				'skipped',
				sprintf(
					'Grace period of %d days has not elapsed. Eligible on %s (%d day(s) left).',
					$grace,
					$grace_status['eligible_at'],
					$grace_status['days_left']
				),
				$dry_run,
				$write_audit
			);
		}

		$verify = $this->verifier->verify_object( $object_key, $file_size, $checksum ?: null, true );
		if ( ! $verify['ok'] ) {
			return $this->audit( $product_ids, $local_path, $object_key, $checksum, $file_size, 'skipped', 'S3 verification failed: ' . $verify['message'], $dry_run, $write_audit );
		}

		// Ensure all dependent products point at S3 for this object.
		$deps = $this->find_local_dependents( $local_path );
		foreach ( $deps as $dep ) {
			$ref_check = $this->verifier->verify_product_reference( (int) $dep['product_id'], (string) $dep['download_id'] );
			if ( ! $ref_check['ok'] ) {
				return $this->audit( $product_ids, $local_path, $object_key, $checksum, $file_size, 'skipped', 'Dependent product still uses local reference: ' . $ref_check['message'], $dry_run, $write_audit );
			}
		}

		// Media library attachment check.
		if ( $this->is_attachment_file( $local_path ) ) {
			return $this->audit( $product_ids, $local_path, $object_key, $checksum, $file_size, 'skipped', 'File is referenced by the Media Library; refusing delete.', $dry_run, $write_audit );
		}

		if ( $dry_run ) {
			return $this->audit( $product_ids, $local_path, $object_key, $checksum, $file_size, 'dry_run', 'Ready to delete this local file. The S3 object is kept.', true, $write_audit );
		}

		// Delete file only (never directories).
		if ( ! is_file( $local_path ) ) {
			return $this->audit( $product_ids, $local_path, $object_key, $checksum, $file_size, 'skipped', 'File already absent.', false, $write_audit );
		}

		$deleted = unlink( $local_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( ! $deleted ) {
			return $this->audit( $product_ids, $local_path, $object_key, $checksum, $file_size, 'failed', 'unlink() failed.', false, $write_audit );
		}

		$this->logger->success( 'Local file deleted after verification', array( 'path' => $local_path, 'key' => $object_key ) );
		return $this->audit( $product_ids, $local_path, $object_key, $checksum, $file_size, 'deleted', 'Local file deleted.', false, $write_audit );
	}

	/**
	 * Candidates from successful migrations still having local files.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function candidates( int $limit = 100 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wcs3dm_migration_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND dry_run = 0 ORDER BY id DESC LIMIT %d",
				'success',
				$limit
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows ?: array() as $row ) {
			$path = (string) $row['source_path'];
			if ( ! is_file( $path ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * One row per local file, newest migration first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function grouped_candidates( int $limit = 500 ): array {
		$rows    = $this->candidates( max( $limit, 1000 ) );
		$grouped = array();
		foreach ( $rows as $row ) {
			$path = (string) $row['source_path'];
			if ( ! isset( $grouped[ $path ] ) ) {
				$row['product_ids'] = array();
				$row['products']    = array();
				$grouped[ $path ]   = $row;
			}
			$pid = (int) $row['product_id'];
			if ( in_array( $pid, $grouped[ $path ]['product_ids'], true ) ) {
				continue;
			}
			$grouped[ $path ]['product_ids'][] = $pid;
			$name = '';
			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $pid );
				$name    = $product ? $product->get_name() : '';
			}
			$grouped[ $path ]['products'][] = array(
				'id'   => $pid,
				'name' => $name,
			);
		}
		return array_slice( array_values( $grouped ), 0, $limit );
	}

	/**
	 * Grace period check against migration log.
	 *
	 * @return array{elapsed:bool,eligible_at:string,days_left:int}
	 */
	private function grace_status( string $object_key, int $days ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wcs3dm_migration_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$created = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM {$table} WHERE object_key = %s AND status = 'success' ORDER BY id ASC LIMIT 1",
				$object_key
			)
		);
		if ( ! $created ) {
			return array(
				'elapsed'     => false,
				'eligible_at' => __( 'unknown', 'wc-s3-download-manager' ),
				'days_left'   => $days,
			);
		}
		$ts = strtotime( $created . ' UTC' );
		if ( false === $ts ) {
			return array(
				'elapsed'     => false,
				'eligible_at' => __( 'unknown', 'wc-s3-download-manager' ),
				'days_left'   => $days,
			);
		}
		$eligible = $ts + ( $days * DAY_IN_SECONDS );
		$left     = (int) ceil( max( 0, $eligible - time() ) / DAY_IN_SECONDS );
		return array(
			'elapsed'     => time() >= $eligible,
			'eligible_at' => wp_date( 'Y-m-d', $eligible ),
			'days_left'   => $left,
		);
	}

	/**
	 * Find products still listing this exact local path (should be none after migration).
	 *
	 * @return array<int, array{product_id:int,download_id:string}>
	 */
	private function find_local_dependents( string $local_path ): array {
		// After migration references should be S3; we still scan recent product IDs from migration log.
		global $wpdb;
		$table = $wpdb->prefix . 'wcs3dm_migration_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT product_id FROM {$table} WHERE source_path = %s",
				$local_path
			)
		);
		$deps = array();
		foreach ( $ids ?: array() as $pid ) {
			$product = wc_get_product( (int) $pid );
			if ( ! $product ) {
				continue;
			}
			foreach ( $product->get_downloads() as $did => $download ) {
				$file = $download->get_file();
				if ( Reference::is_ours( $file ) ) {
					$deps[] = array( 'product_id' => (int) $pid, 'download_id' => (string) $did );
					continue;
				}
				$resolved = Path_Validator::resolve_local( $file );
				if ( $resolved['ok'] && isset( $resolved['path'] ) && $resolved['path'] === $local_path ) {
					// Still points at local — block cleanup.
					return array(
						array(
							'product_id'  => (int) $pid,
							'download_id' => (string) $did,
						),
					);
				}
			}
		}
		return $deps;
	}

	/**
	 * Whether path belongs to a WP attachment.
	 */
	private function is_attachment_file( string $path ): bool {
		global $wpdb;
		$upload = wp_upload_dir( null, false );
		if ( empty( $upload['basedir'] ) || 0 !== strpos( $path, $upload['basedir'] ) ) {
			return false;
		}
		$relative = ltrim( substr( $path, strlen( $upload['basedir'] ) ), '/' );
		$like     = '%' . $wpdb->esc_like( $relative );
		$found    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
				$like
			)
		);
		return ! empty( $found );
	}

	/**
	 * Write cleanup audit row.
	 *
	 * @param array<int, int|string> $product_ids IDs.
	 * @return array<string, mixed>
	 */
	private function audit( array $product_ids, string $path, string $key, string $checksum, int $file_size, string $outcome, string $reason, bool $dry_run, bool $write_audit = true ): array {
		$ids = array_values( array_map( 'intval', $product_ids ) );
		if ( $write_audit ) {
			global $wpdb;
			$table = $wpdb->prefix . 'wcs3dm_cleanup_log';
			$wpdb->insert(
				$table,
				array(
					'product_ids'     => wp_json_encode( $ids ),
					'local_path'      => $path,
					'object_key'      => $key,
					'checksum_sha256' => $checksum,
					'outcome'         => $outcome,
					'reason'          => $this->logger->redact( $reason ),
					'created_at'      => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			$this->logger->log(
				'deleted' === $outcome ? Logger::SUCCESS : Logger::INFO,
				'Cleanup: ' . $outcome . ' — ' . $reason,
				array( 'path' => $path, 'key' => $key, 'bytes' => $file_size )
			);
		}

		return array(
			'ok'           => in_array( $outcome, array( 'deleted', 'dry_run', 'skipped' ), true ),
			'outcome'      => $outcome,
			'reason'       => $reason,
			'local_path'   => $path,
			'filename'     => basename( str_replace( '\\', '/', $path ) ),
			'object_key'   => $key,
			's3_filename'  => Reference::customer_filename( $key ),
			'file_size'    => $file_size,
			'product_ids'  => $ids,
			'deletable'    => 'dry_run' === $outcome,
		);
	}

	/**
	 * Files in the WooCommerce downloads folder that no product points at.
	 *
	 * @return array<string, mixed>
	 */
	public function unreferenced_files(): array {
		$referenced = $this->referenced_download_paths();
		$migrated   = $this->migrated_source_paths();
		$items      = array();
		$bytes      = 0;
		$truncated  = false;

		foreach ( $this->download_folder_roots() as $root ) {
			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
				);
			} catch ( \UnexpectedValueException ) {
				continue;
			}
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
					continue;
				}
				if ( count( $items ) >= 2000 ) {
					$truncated = true;
					break 2;
				}
				$real = $file->getRealPath();
				if ( ! is_string( $real ) || ! $this->is_under_download_root( $real ) ) {
					continue;
				}
				$base = $file->getFilename();
				if ( in_array( $base, array( 'index.html', '.htaccess', '.htaccess.txt' ), true ) ) {
					continue;
				}
				if ( preg_match( '/\.(php|phtml|phar)(\.|$)/i', $base ) ) {
					continue;
				}
				if ( isset( $referenced[ $real ] ) ) {
					continue;
				}
				if ( Path_Validator::is_protected_path( $real ) ) {
					continue;
				}

				$size    = (int) $file->getSize();
				$kind    = isset( $migrated[ $real ] ) ? 'leftover' : 'orphan';
				$reason  = 'leftover' === $kind
					? __( 'No product points at this file anymore. It was left on disk after the product moved to S3.', 'wc-s3-download-manager' )
					: __( 'No product download in the database uses this file.', 'wc-s3-download-manager' );
				$blocked = $this->is_attachment_file( $real );
				if ( $blocked ) {
					$reason = __( 'The Media Library uses this file, so it will not be deleted.', 'wc-s3-download-manager' );
				}

				$items[] = array(
					'path'       => $real,
					'filename'   => $base,
					'size'       => $size,
					'kind'       => $blocked ? 'media' : $kind,
					'deletable'  => ! $blocked,
					'reason'     => $reason,
					'product_id' => $migrated[ $real ]['product_id'] ?? 0,
				);
				if ( ! $blocked ) {
					$bytes += $size;
				}
			}
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return (int) $b['size'] <=> (int) $a['size'];
			}
		);

		$deletable = 0;
		foreach ( $items as $item ) {
			if ( ! empty( $item['deletable'] ) ) {
				++$deletable;
			}
		}

		return array(
			'items'     => $items,
			'truncated' => $truncated,
			'totals'    => array(
				'files' => count( $items ),
				'ready' => $deletable,
				'bytes' => $bytes,
			),
		);
	}

	/**
	 * Delete selected files that are still unreferenced.
	 *
	 * @param array<int, string> $paths Requested paths.
	 * @return array<string, mixed>
	 */
	public function delete_unreferenced( array $paths ): array {
		$report    = $this->unreferenced_files();
		$allowed   = array();
		foreach ( $report['items'] as $item ) {
			if ( ! empty( $item['deletable'] ) ) {
				$allowed[ (string) $item['path'] ] = $item;
			}
		}

		$deleted_files = 0;
		$deleted_bytes = 0;
		$results       = array();
		foreach ( $paths as $path ) {
			$real = realpath( (string) $path );
			if ( ! is_string( $real ) || ! isset( $allowed[ $real ] ) ) {
				$results[] = array(
					'path'    => (string) $path,
					'outcome' => 'skipped',
					'reason'  => __( 'This file is used by a product, or it is outside the downloads folder.', 'wc-s3-download-manager' ),
				);
				continue;
			}
			$size = (int) $allowed[ $real ]['size'];
			if ( ! is_file( $real ) || ! unlink( $real ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				$results[] = array(
					'path'    => $real,
					'outcome' => 'failed',
					'reason'  => __( 'Could not delete this file.', 'wc-s3-download-manager' ),
				);
				continue;
			}
			$this->audit( array(), $real, '', '', $size, 'deleted', 'Unreferenced local file deleted.', false, true );
			++$deleted_files;
			$deleted_bytes += $size;
			$results[] = array(
				'path'     => $real,
				'filename' => basename( $real ),
				'outcome'  => 'deleted',
				'reason'   => __( 'Deleted from this server.', 'wc-s3-download-manager' ),
				'size'     => $size,
			);
		}

		$fresh = $this->unreferenced_files();
		$fresh['deleted_files'] = $deleted_files;
		$fresh['deleted_bytes'] = $deleted_bytes;
		$fresh['results']       = $results;
		return $fresh;
	}

	/**
	 * WooCommerce protected download directories.
	 *
	 * @return array<int, string>
	 */
	private function download_folder_roots(): array {
		$candidates = array( WP_CONTENT_DIR . '/woocommerce_uploads' );
		$upload     = wp_upload_dir( null, false );
		if ( ! empty( $upload['basedir'] ) ) {
			$candidates[] = trailingslashit( (string) $upload['basedir'] ) . 'woocommerce_uploads';
		}
		$roots = array();
		foreach ( $candidates as $candidate ) {
			$real = realpath( $candidate );
			if ( is_string( $real ) && is_dir( $real ) ) {
				$roots[ $real ] = true;
			}
		}
		return array_keys( $roots );
	}

	/**
	 * Whether a real path is inside a WooCommerce downloads folder.
	 */
	private function is_under_download_root( string $real ): bool {
		foreach ( $this->download_folder_roots() as $root ) {
			if ( $real === $root || str_starts_with( $real, trailingslashit( $root ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Real paths currently saved on a product download.
	 *
	 * @return array<string, true>
	 */
	private function referenced_download_paths(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_downloadable_files'" );
		$set  = array();
		foreach ( $rows ?: array() as $raw ) {
			$files = maybe_unserialize( $raw );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as $file ) {
				$url = '';
				if ( is_array( $file ) && isset( $file['file'] ) ) {
					$url = (string) $file['file'];
				}
				if ( '' === $url || Reference::is_ours( $url ) ) {
					continue;
				}
				$resolved = Path_Validator::resolve_local( $url );
				if ( ! empty( $resolved['ok'] ) && ! empty( $resolved['path'] ) ) {
					$set[ (string) $resolved['path'] ] = true;
					continue;
				}
				$guess = $this->download_url_to_path( $url );
				if ( null !== $guess ) {
					$set[ $guess ] = true;
				}
			}
		}
		return $set;
	}

	/**
	 * Map a site download URL onto the local downloads folder.
	 */
	private function download_url_to_path( string $url ): ?string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$marker = '/woocommerce_uploads/';
		$pos    = stripos( $path, $marker );
		if ( false === $pos ) {
			return null;
		}
		$relative = ltrim( substr( $path, $pos + strlen( $marker ) ), '/' );
		if ( '' === $relative || str_contains( $relative, '..' ) ) {
			return null;
		}
		foreach ( $this->download_folder_roots() as $root ) {
			$real = realpath( $root . '/' . $relative );
			if ( is_string( $real ) && $this->is_under_download_root( $real ) ) {
				return $real;
			}
		}
		return null;
	}

	/**
	 * Local paths recorded by a successful migration.
	 *
	 * @return array<string, array{product_id:int}>
	 */
	private function migrated_source_paths(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wcs3dm_migration_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_path, product_id FROM {$table} WHERE status = %s AND dry_run = 0",
				'success'
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows ?: array() as $row ) {
			$real = realpath( (string) $row['source_path'] );
			if ( ! is_string( $real ) ) {
				continue;
			}
			$out[ $real ] = array( 'product_id' => (int) $row['product_id'] );
		}
		return $out;
	}
}
