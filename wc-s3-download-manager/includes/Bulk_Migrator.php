<?php
/**
 * Bulk migration of local downloads to S3.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Discovers and migrates downloadable files.
 */
final class Bulk_Migrator {

	/**
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * @var S3_Client
	 */
	private S3_Client $s3;

	/**
	 * @var File_Uploader
	 */
	private File_Uploader $uploader;

	/**
	 * @var File_Verifier
	 */
	private File_Verifier $verifier;

	/**
	 * @var Product_Integration
	 */
	private Product_Integration $products;

	/**
	 * Constructor.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->s3       = new S3_Client( $settings, $logger );
		$this->uploader = new File_Uploader( $settings, $logger, $this->s3 );
		$this->verifier = new File_Verifier( $settings, $logger, $this->s3 );
		$this->products = new Product_Integration( $settings, $logger );
	}

	/**
	 * Discover downloadable files across products/variations.
	 *
	 * @param int $page Page (1-based).
	 * @param int $per  Per page products.
	 * @return array{items:array<int,array<string,mixed>>,total_products:int,page:int,pages:int,summary:array<string,int|float>}
	 */
	public function discover( int $page = 1, int $per = 50 ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => $per,
				'paged'          => max( 1, $page ),
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$items   = array();
		$summary = array(
			'total_files'     => 0,
			'local'           => 0,
			's3'              => 0,
			'missing'         => 0,
			'unsupported'     => 0,
			'eligible_bytes'  => 0,
		);

		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( (int) $product_id );
			if ( ! $product || ! $product->is_downloadable() ) {
				continue;
			}
			foreach ( $product->get_downloads() as $download_id => $download ) {
				++$summary['total_files'];
				$file = $download->get_file();
				$row  = array(
					'product_id'   => (int) $product_id,
					'product_name' => $product->get_name(),
					'download_id'  => (string) $download_id,
					'name'         => $download->get_name(),
					'file'         => $file,
					'status'       => 'unknown',
					'size'         => 0,
					'path'         => '',
				);

				if ( Reference::is_ours( $file ) || $this->looks_like_s3_url( $file ) ) {
					$row['status'] = 's3';
					++$summary['s3'];
				} else {
					$resolved = Path_Validator::resolve_local( $file );
					if ( $resolved['ok'] ) {
						$row['status'] = 'local';
						$row['path']   = $resolved['path'];
						$row['size']   = (int) filesize( $resolved['path'] );
						++$summary['local'];
						$summary['eligible_bytes'] += $row['size'];
					} elseif ( preg_match( '#^https?://#i', $file ) ) {
						$row['status'] = 'unsupported';
						++$summary['unsupported'];
					} else {
						$row['status'] = 'missing';
						++$summary['missing'];
					}
				}
				$row = array_merge( $row, $this->describe_download( $row ) );
				$items[] = $row;
			}
		}

		return array(
			'items'          => $items,
			'total_products' => (int) $query->found_posts,
			'page'           => max( 1, $page ),
			'pages'          => (int) $query->max_num_pages,
			'summary'        => $summary,
		);
	}

	/**
	 * Aggregate discovery summary across all products (paginated internally).
	 *
	 * @return array<string, int|float>
	 */
	public function overview_counts(): array {
		$totals = array(
			'total_files'    => 0,
			'local'          => 0,
			's3'             => 0,
			'missing'        => 0,
			'unsupported'    => 0,
			'eligible_bytes' => 0,
			'products'       => 0,
		);
		$page  = 1;
		$pages = 1;
		do {
			$batch              = $this->discover( $page, 100 );
			$pages              = max( 1, (int) $batch['pages'] );
			$totals['products'] = (int) $batch['total_products'];
			$totals['total_files']   += (int) $batch['summary']['total_files'];
			$totals['local']         += (int) $batch['summary']['local'];
			$totals['s3']            += (int) $batch['summary']['s3'];
			$totals['missing']       += (int) $batch['summary']['missing'];
			$totals['unsupported']   += (int) $batch['summary']['unsupported'];
			$totals['eligible_bytes'] += (int) $batch['summary']['eligible_bytes'];
			++$page;
		} while ( $page <= $pages );

		return $totals;
	}

	/**
	 * Migrate a single file entry.
	 *
	 * @param array<string, mixed> $options Options: dry_run, product_id, download_id.
	 * @return array{ok:bool,message:string,status?:string}
	 */
	public function migrate_one( int $product_id, string $download_id, bool $dry_run = false ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $this->record( $product_id, $download_id, '', '', 0, '', 'failed', 'Product not found.', $dry_run );
		}
		$downloads = $product->get_downloads( 'edit' );
		if ( empty( $downloads[ $download_id ] ) ) {
			return $this->record( $product_id, $download_id, '', '', 0, '', 'failed', 'Download not found.', $dry_run );
		}

		/** @var \WC_Product_Download $download */
		$download = $downloads[ $download_id ];
		$source   = $download->get_file();

		if ( Reference::is_ours( $source ) ) {
			return $this->record( $product_id, $download_id, $source, '', 0, '', 'skipped', 'Already on S3.', $dry_run );
		}

		$resolved = Path_Validator::resolve_local( $source );
		if ( ! $resolved['ok'] ) {
			return $this->record( $product_id, $download_id, $source, '', 0, '', 'failed', $resolved['message'], $dry_run );
		}

		$path     = $resolved['path'];
		$size     = (int) filesize( $path );
		$checksum = Path_Validator::sha256_file( $path );
		if ( false === $checksum ) {
			return $this->record( $product_id, $download_id, $path, '', $size, '', 'failed', 'Could not hash local file.', $dry_run );
		}

		$key = $this->uploader->migration_key( $product_id, $download_id, basename( $path ), $checksum );

		if ( $dry_run ) {
			return $this->record( $product_id, $download_id, $path, $key, $size, $checksum, 'skipped', 'Dry run — would upload and update reference.', true );
		}

		// Idempotent: if object already exists with matching size, reuse.
		$head = $this->s3->head_object( $key );
		if ( ! ( $head['ok'] && (int) ( $head['size'] ?? 0 ) === $size ) ) {
			$upload = $this->uploader->upload_path( $path, $key );
			if ( ! $upload['ok'] ) {
				return $this->record( $product_id, $download_id, $path, $key, $size, $checksum, 'failed', $upload['message'], false );
			}
		}

		$verify = $this->verifier->verify_object( $key, $size, $checksum, $size <= 50 * 1024 * 1024 );
		if ( ! $verify['ok'] ) {
			return $this->record( $product_id, $download_id, $path, $key, $size, $checksum, 'failed', $verify['message'], false );
		}

		$reference = Reference::build( $this->s3->bucket(), $key );
		$update    = $this->products->set_download_reference( $product_id, $download_id, $download->get_name(), $reference );
		if ( ! $update['ok'] ) {
			return $this->record( $product_id, $download_id, $path, $key, $size, $checksum, 'failed', 'Upload OK but reference update failed: ' . $update['message'] . '. Original reference retained.', false );
		}

		$this->bump_stats( $size, true );
		// The local file is intentionally left in place. Deletion happens only from Local Cleanup after an explicit confirm.
		return $this->record( $product_id, $download_id, $path, $key, $size, $checksum, 'success', 'Uploaded to S3 and product updated. Local file kept: ' . $path, false );
	}

	/**
	 * Latest migration rows for the activity table.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent_rows( int $limit = 40 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wcs3dm_migration_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, product_id, download_id, source_path, object_key, file_size, status, message, dry_run, created_at FROM {$table} ORDER BY id DESC LIMIT %d",
				max( 1, $limit )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows ?: array() as $row ) {
			$source = (string) ( $row['source_path'] ?? '' );
			$out[]  = array(
				'id'         => (int) $row['id'],
				'product_id' => (int) $row['product_id'],
				'file'       => '' !== $source ? basename( $source ) : basename( (string) $row['object_key'] ),
				'size'       => (int) $row['file_size'],
				'status'     => (string) $row['status'],
				'message'    => (string) $row['message'],
				'dry_run'    => (int) $row['dry_run'],
				'created_at' => (string) $row['created_at'],
			);
		}
		return $out;
	}

	/**
	 * Process a batch of eligible local files starting after a product ID cursor.
	 *
	 * @param array<string, mixed> $options Job options.
	 * @return array{processed:int,success:int,failed:int,skipped:int,cursor:int,done:bool,messages:array<int,string>}
	 */
	public function process_batch( array $options ): array {
		$batch_size = (int) ( $options['batch_size'] ?? $this->settings->get( 'migration_batch_size', 5 ) );
		$dry_run    = ! empty( $options['dry_run'] );
		$cursor     = (int) ( $options['cursor'] ?? 0 );
		$product_ids = isset( $options['product_ids'] ) && is_array( $options['product_ids'] ) ? array_map( 'intval', $options['product_ids'] ) : array();
		$only_download = isset( $options['download_ids'] ) && is_array( $options['download_ids'] ) ? $options['download_ids'] : null;

		$query_args = array(
			'post_type'      => array( 'product', 'product_variation' ),
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'post__gt'       => $cursor, // custom — WP does not support; use meta workaround below.
		);

		// WP_Query lacks post__gt; use date-free ID comparison via posts_where.
		$gt = $cursor;
		$filter = static function ( string $where ) use ( $gt ): string {
			global $wpdb;
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $gt );
		};
		add_filter( 'posts_where', $filter );

		if ( $product_ids ) {
			$query_args['post__in'] = $product_ids;
			remove_filter( 'posts_where', $filter );
		}

		$query = new \WP_Query( $query_args );
		if ( ! $product_ids ) {
			remove_filter( 'posts_where', $filter );
		}

		$processed = 0;
		$success   = 0;
		$failed    = 0;
		$skipped   = 0;
		$messages  = array();
		$new_cursor = $cursor;

		foreach ( $query->posts as $product_id ) {
			$product_id = (int) $product_id;
			$new_cursor = max( $new_cursor, $product_id );
			$product    = wc_get_product( $product_id );
			if ( ! $product || ! $product->is_downloadable() ) {
				continue;
			}
			foreach ( $product->get_downloads() as $download_id => $download ) {
				if ( null !== $only_download && ! in_array( (string) $download_id, $only_download, true ) ) {
					continue;
				}
				if ( Reference::is_ours( $download->get_file() ) ) {
					continue;
				}
				$resolved = Path_Validator::resolve_local( $download->get_file() );
				if ( ! $resolved['ok'] ) {
					continue;
				}

				$result = $this->migrate_one( $product_id, (string) $download_id, $dry_run );
				++$processed;
				$messages[] = sprintf( '#%d/%s: %s', $product_id, $download_id, $result['message'] );
				if ( ! empty( $result['ok'] ) && ( $result['status'] ?? '' ) === 'success' ) {
					++$success;
				} elseif ( ( $result['status'] ?? '' ) === 'skipped' ) {
					++$skipped;
				} else {
					++$failed;
				}

				if ( $processed >= $batch_size ) {
					return array(
						'processed' => $processed,
						'success'   => $success,
						'failed'    => $failed,
						'skipped'   => $skipped,
						'cursor'    => $new_cursor,
						'done'      => false,
						'messages'  => $messages,
					);
				}
			}
		}

		$done = empty( $query->posts ) || ( ! $product_ids && count( $query->posts ) < 20 && $processed < $batch_size );
		// If we still have posts but no eligible files, advance and continue.
		if ( ! empty( $query->posts ) && 0 === $processed ) {
			$done = false;
		}
		if ( empty( $query->posts ) ) {
			$done = true;
		}

		return array(
			'processed' => $processed,
			'success'   => $success,
			'failed'    => $failed,
			'skipped'   => $skipped,
			'cursor'    => $new_cursor,
			'done'      => $done,
			'messages'  => $messages,
		);
	}

	/**
	 * Export migration log as CSV string.
	 */
	public function export_csv( int $limit = 5000 ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'wcs3dm_migration_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		$fh    = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $fh, array( 'id', 'product_id', 'download_id', 'source_path', 'object_key', 'file_size', 'checksum_sha256', 'status', 'message', 'dry_run', 'created_at' ) );
		foreach ( $rows ?: array() as $row ) {
			fputcsv(
				$fh,
				array(
					$row['id'],
					$row['product_id'],
					$row['download_id'],
					$row['source_path'],
					$row['object_key'],
					$row['file_size'],
					$row['checksum_sha256'],
					$row['status'],
					$row['message'],
					$row['dry_run'],
					$row['created_at'],
				)
			);
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return (string) $csv;
	}

	/**
	 * Persist migration log row.
	 *
	 * @return array{ok:bool,message:string,status:string}
	 */
	private function record(
		int $product_id,
		string $download_id,
		string $source,
		string $key,
		int $size,
		string $checksum,
		string $status,
		string $message,
		bool $dry_run
	): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wcs3dm_migration_log';
		$now   = current_time( 'mysql', true );
		$wpdb->insert(
			$table,
			array(
				'product_id'       => $product_id,
				'download_id'      => $download_id,
				'source_path'      => $source,
				'object_key'       => $key,
				'file_size'        => $size,
				'checksum_sha256'  => $checksum,
				'status'           => $status,
				'message'          => $this->logger->redact( $message ),
				'dry_run'          => $dry_run ? 1 : 0,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		$ok = in_array( $status, array( 'success', 'skipped' ), true );
		if ( 'failed' === $status ) {
			$this->bump_stats( 0, false );
			$this->logger->error( 'Migration failed', array( 'product_id' => $product_id, 'message' => $message ) );
		}

		return array(
			'ok'      => $ok,
			'message' => $message,
			'status'  => $status,
		);
	}

	/**
	 * Update aggregate stats.
	 */
	private function bump_stats( int $bytes, bool $success ): void {
		$stats = get_option( 'wcs3dm_stats', array() );
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}
		$stats['bytes_transferred'] = (int) ( $stats['bytes_transferred'] ?? 0 ) + ( $success ? $bytes : 0 );
		$stats['files_migrated']    = (int) ( $stats['files_migrated'] ?? 0 ) + ( $success ? 1 : 0 );
		$stats['files_failed']      = (int) ( $stats['files_failed'] ?? 0 ) + ( $success ? 0 : 1 );
		update_option( 'wcs3dm_stats', $stats, false );
	}

	/**
	 * Heuristic for already-remote S3 HTTP URLs.
	 */
	private function looks_like_s3_url( string $file ): bool {
		return (bool) preg_match( '#\.amazonaws\.com/|#s3[.-]|digitaloceanspaces\.com|r2\.cloudflarestorage\.com#i', $file );
	}

	/**
	 * Plain-language description of where a download lives.
	 *
	 * @param array<string, mixed> $row Discovery row.
	 * @return array{filename:string,location:string,stored_as:string,explanation:string}
	 */
	private function describe_download( array $row ): array {
		$file   = (string) $row['file'];
		$status = (string) $row['status'];
		$name   = (string) $row['name'];

		if ( 's3' === $status ) {
			$parsed = Reference::parse( $file );
			if ( $parsed ) {
				$filename = Reference::customer_filename( $parsed['key'] );
				return array(
					'filename'    => $filename,
					'location'    => $parsed['key'],
					'stored_as'   => __( 'On S3', 'wc-s3-download-manager' ),
					'explanation' => sprintf(
						/* translators: %s: filename the customer downloads */
						__( 'Customers download this from your private bucket. The file they save is %s.', 'wc-s3-download-manager' ),
						$filename
					),
				);
			}
			return array(
				'filename'    => '' !== $name ? $name : basename( (string) wp_parse_url( $file, PHP_URL_PATH ) ),
				'location'    => $file,
				'stored_as'   => __( 'On S3', 'wc-s3-download-manager' ),
				'explanation' => __( 'This download already points at an S3 address.', 'wc-s3-download-manager' ),
			);
		}

		if ( 'local' === $status ) {
			$path = (string) $row['path'];
			return array(
				'filename'    => basename( $path ),
				'location'    => $path,
				'stored_as'   => __( 'On this server', 'wc-s3-download-manager' ),
				'explanation' => __( 'Still stored on this server. Bulk Migration can copy it to S3. The size is the disk space it uses here.', 'wc-s3-download-manager' ),
			);
		}

		$filename = basename( (string) ( wp_parse_url( $file, PHP_URL_PATH ) ?: $file ) );
		if ( '' === $filename ) {
			$filename = $name;
		}

		if ( 'unsupported' === $status ) {
			$host      = (string) wp_parse_url( $file, PHP_URL_HOST );
			$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			if ( '' !== $host && '' !== $site_host && strtolower( $host ) === strtolower( $site_host ) ) {
				return array(
					'filename'    => $filename,
					'location'    => $file,
					'stored_as'   => __( 'Website link, file missing', 'wc-s3-download-manager' ),
					'explanation' => __( 'The product still uses a link on this website, but the file is not on the server. It was not copied to S3, so a customer download of this file can fail.', 'wc-s3-download-manager' ),
				);
			}
			return array(
				'filename'    => $filename,
				'location'    => $file,
				'stored_as'   => __( 'Another website', 'wc-s3-download-manager' ),
				'explanation' => __( 'This file is hosted somewhere else. This plugin only copies files that are stored on this server.', 'wc-s3-download-manager' ),
			);
		}

		return array(
			'filename'    => '' !== $filename ? $filename : $name,
			'location'    => $file,
			'stored_as'   => __( 'Missing', 'wc-s3-download-manager' ),
			'explanation' => __( 'No file was found on this server, and this download is not on S3.', 'wc-s3-download-manager' ),
		);
	}
}
