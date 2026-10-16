<?php
/**
 * Action Scheduler / WP-Cron background jobs.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Manages migrate/cleanup/verify background jobs with locks and resume.
 */
final class Background_Jobs {

	public const HOOK_MIGRATE_BATCH = 'wcs3dm_migrate_batch';
	public const HOOK_CLEANUP_BATCH = 'wcs3dm_cleanup_batch';
	public const HOOK_VERIFY_BATCH  = 'wcs3dm_verify_batch';

	/**
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( self::HOOK_MIGRATE_BATCH, array( $this, 'run_migrate_batch' ), 10, 1 );
		add_action( self::HOOK_CLEANUP_BATCH, array( $this, 'run_cleanup_batch' ), 10, 1 );
		add_action( self::HOOK_VERIFY_BATCH, array( $this, 'run_verify_batch' ), 10, 1 );
		add_action( 'wcs3dm_cron_fallback', array( $this, 'cron_fallback' ) );
	}

	/**
	 * Start a migration job.
	 *
	 * @param array<string, mixed> $options Options.
	 * @return array{ok:bool,job_id?:string,message:string}
	 */
	public function start_migration( array $options ): array {
		$active = $this->find_active( 'migrate' );
		if ( $active ) {
			return array(
				'ok'      => false,
				'job_id'  => $active['id'],
				'status'  => $active['status'],
				'message' => 'A migration job is already ' . $active['status'] . '. This page will keep processing it while it stays open.',
			);
		}

		$job_id = wp_generate_uuid4();
		$stats  = array(
			'processed' => 0,
			'success'   => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'bytes'     => 0,
			'started'   => gmdate( 'c' ),
		);
		$options = array_merge(
			array(
				'dry_run'     => false,
				'batch_size'  => (int) $this->settings->get( 'migration_batch_size', 5 ),
				'cursor'      => 0,
				'retry'       => 0,
			),
			$options
		);

		$this->insert_job( $job_id, 'migrate', 'queued', array( 'cursor' => 0 ), $stats, $options );
		$this->enqueue( self::HOOK_MIGRATE_BATCH, array( $job_id ), 0 );
		$this->logger->info( 'Migration job queued', array( 'job_id' => $job_id ) );

		return array( 'ok' => true, 'job_id' => $job_id, 'message' => 'Migration queued.' );
	}

	/**
	 * Pause job.
	 */
	public function pause( string $job_id ): array {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return array( 'ok' => false, 'message' => 'Job not found.' );
		}
		$this->update_job( $job_id, array( 'status' => 'paused' ) );
		return array( 'ok' => true, 'message' => 'Job paused.' );
	}

	/**
	 * Resume job.
	 */
	public function resume( string $job_id ): array {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return array( 'ok' => false, 'message' => 'Job not found.' );
		}
		if ( 'paused' !== $job['status'] && 'failed' !== $job['status'] ) {
			return array( 'ok' => false, 'message' => 'Job is not paused/failed.' );
		}
		$this->update_job( $job_id, array( 'status' => 'queued', 'lock_token' => '' ) );
		$hook = 'migrate' === $job['type'] ? self::HOOK_MIGRATE_BATCH : self::HOOK_CLEANUP_BATCH;
		$this->enqueue( $hook, array( $job_id ), 0 );
		return array( 'ok' => true, 'message' => 'Job resumed.' );
	}

	/**
	 * Cancel job.
	 */
	public function cancel( string $job_id ): array {
		$this->update_job( $job_id, array( 'status' => 'cancelled', 'lock_token' => '' ) );
		return array( 'ok' => true, 'message' => 'Job cancelled.' );
	}

	/**
	 * Retry failed migration files by starting a new job with same options.
	 */
	public function retry( string $job_id ): array {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return array( 'ok' => false, 'message' => 'Job not found.' );
		}
		$options = $job['options'];
		$options['cursor'] = 0;
		$options['retry']  = (int) ( $options['retry'] ?? 0 ) + 1;
		return $this->start_migration( $options );
	}

	/**
	 * Execute one migrate batch.
	 *
	 * @param string $job_id Job ID.
	 */
	public function run_migrate_batch( string $job_id ): void {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return;
		}
		if ( in_array( $job['status'], array( 'cancelled', 'paused', 'completed' ), true ) ) {
			return;
		}

		$token = wp_generate_password( 16, false );
		if ( ! $this->acquire_lock( $job_id, $token ) ) {
			$this->logger->warning( 'Could not acquire job lock', array( 'job_id' => $job_id ) );
			return;
		}

		$this->update_job( $job_id, array( 'status' => 'running' ) );

		try {
			$migrator = new Bulk_Migrator( $this->settings, $this->logger );
			$options  = $job['options'];
			$options['cursor'] = (int) ( $job['cursor']['cursor'] ?? $options['cursor'] ?? 0 );
			$result   = $migrator->process_batch( $options );

			$stats = $job['stats'];
			$stats['processed'] += (int) $result['processed'];
			$stats['success']   += (int) $result['success'];
			$stats['failed']    += (int) $result['failed'];
			$stats['skipped']   += (int) $result['skipped'];
			$stats['updated']    = gmdate( 'c' );

			$cursor = array( 'cursor' => (int) $result['cursor'] );
			$options['cursor'] = (int) $result['cursor'];

			if ( ! empty( $result['done'] ) ) {
				$this->update_job(
					$job_id,
					array(
						'status'       => 'completed',
						'cursor_data'  => $cursor,
						'stats'        => $stats,
						'options_data' => $options,
						'lock_token'   => '',
					)
				);
				$this->logger->success( 'Migration job completed', array( 'job_id' => $job_id, 'stats' => $stats ) );
				return;
			}

			$this->update_job(
				$job_id,
				array(
					'status'       => 'queued',
					'cursor_data'  => $cursor,
					'stats'        => $stats,
					'options_data' => $options,
					'lock_token'   => '',
				)
			);

			$delay = $this->backoff_seconds( (int) ( $result['failed'] ?? 0 ) );
			$this->enqueue( self::HOOK_MIGRATE_BATCH, array( $job_id ), $delay );
		} catch ( \Throwable $e ) {
			$retry = (int) ( $job['options']['retry'] ?? 0 );
			$this->logger->error( 'Migration batch exception', array( 'job_id' => $job_id, 'error' => $e->getMessage() ) );
			$options = $job['options'];
			$options['retry'] = $retry + 1;
			if ( $retry >= 5 ) {
				$this->update_job( $job_id, array( 'status' => 'failed', 'lock_token' => '', 'options_data' => $options ) );
				return;
			}
			$this->update_job( $job_id, array( 'status' => 'queued', 'lock_token' => '', 'options_data' => $options ) );
			$this->enqueue( self::HOOK_MIGRATE_BATCH, array( $job_id ), $this->backoff_seconds( $retry + 1 ) );
		}
	}

	/**
	 * Cleanup batch runner.
	 *
	 * @param string $job_id Job ID.
	 */
	public function run_cleanup_batch( string $job_id ): void {
		$job = $this->get_job( $job_id );
		if ( ! $job || in_array( $job['status'], array( 'cancelled', 'paused', 'completed' ), true ) ) {
			return;
		}
		$cleanup = new Local_Cleanup( $this->settings, $this->logger );
		$dry     = ! empty( $job['options']['dry_run'] );
		$offset  = (int) ( $job['cursor']['offset'] ?? 0 );
		$items   = $cleanup->candidates( 20 );
		$slice   = array_slice( $items, $offset, 5 );
		if ( ! $slice ) {
			$this->update_job( $job_id, array( 'status' => 'completed', 'lock_token' => '' ) );
			return;
		}
		foreach ( $slice as $row ) {
			$cleanup->process_entry(
				(string) $row['source_path'],
				(string) $row['object_key'],
				(string) $row['checksum_sha256'],
				(int) $row['file_size'],
				array( (int) $row['product_id'] ),
				$dry
			);
		}
		$offset += count( $slice );
		$this->update_job(
			$job_id,
			array(
				'status'      => 'queued',
				'cursor_data' => array( 'offset' => $offset ),
				'lock_token'  => '',
			)
		);
		$this->enqueue( self::HOOK_CLEANUP_BATCH, array( $job_id ), 5 );
	}

	/**
	 * Verify batch (placeholder for overview refresh jobs).
	 *
	 * @param string $job_id Job ID.
	 */
	public function run_verify_batch( string $job_id ): void {
		$this->update_job( $job_id, array( 'status' => 'completed' ) );
	}

	/**
	 * WP-Cron fallback tick.
	 */
	public function cron_fallback(): void {
		$job = $this->find_active( 'migrate' );
		if ( $job && 'queued' === $job['status'] ) {
			$this->run_migrate_batch( $job['id'] );
		}
	}

	/**
	 * Start cleanup job.
	 *
	 * @param array<string, mixed> $options Options.
	 */
	public function start_cleanup( array $options ): array {
		if ( $this->find_active( 'cleanup' ) ) {
			return array( 'ok' => false, 'message' => 'A cleanup job is already active.' );
		}
		$job_id = wp_generate_uuid4();
		$this->insert_job( $job_id, 'cleanup', 'queued', array( 'offset' => 0 ), array(), $options );
		$this->enqueue( self::HOOK_CLEANUP_BATCH, array( $job_id ), 0 );
		return array( 'ok' => true, 'job_id' => $job_id, 'message' => 'Cleanup queued.' );
	}

	/**
	 * Get job status payload.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_job( string $job_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'wcs3dm_jobs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $job_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return $this->hydrate( $row );
	}

	/**
	 * Latest jobs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_jobs( int $limit = 20 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wcs3dm_jobs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d", $limit ), ARRAY_A );
		$out  = array();
		foreach ( $rows ?: array() as $row ) {
			$out[] = $this->hydrate( $row );
		}
		return $out;
	}

	/**
	 * Find active job of type.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_active( string $type ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'wcs3dm_jobs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE type = %s AND status IN ('queued','running','paused') ORDER BY updated_at DESC LIMIT 1",
				$type
			),
			ARRAY_A
		);
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Enqueue via Action Scheduler or WP-Cron.
	 *
	 * @param array<int, mixed> $args Args.
	 */
	private function enqueue( string $hook, array $args, int $delay ): void {
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, 'wcs3dm' ) ) {
			return;
		}
		if ( 0 === $delay && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, 'wcs3dm' );
			return;
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + max( 1, $delay ), $hook, $args, 'wcs3dm' );
			return;
		}
		if ( ! wp_next_scheduled( 'wcs3dm_cron_fallback' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'wcs3dm_cron_fallback' );
		}
		wp_schedule_single_event( time() + max( 1, $delay ), $hook, $args );
	}

	/**
	 * Exponential backoff helper. Successful batches wait only a couple of seconds.
	 */
	private function backoff_seconds( int $failures ): int {
		if ( $failures <= 0 ) {
			return 2;
		}
		$failures = min( 8, $failures );
		return (int) min( 900, 60 * ( 2 ** ( $failures - 1 ) ) );
	}

	/**
	 * Insert job row.
	 *
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $stats   Stats.
	 * @param array<string, mixed> $options Options.
	 */
	private function insert_job( string $id, string $type, string $status, array $cursor, array $stats, array $options ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$wpdb->prefix . 'wcs3dm_jobs',
			array(
				'id'           => $id,
				'type'         => $type,
				'status'       => $status,
				'cursor_data'  => wp_json_encode( $cursor ),
				'stats'        => wp_json_encode( $stats ),
				'options_data' => wp_json_encode( $options ),
				'lock_token'   => '',
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
	}

	/**
	 * Update job fields.
	 *
	 * @param array<string, mixed> $fields Fields.
	 */
	private function update_job( string $id, array $fields ): void {
		global $wpdb;
		$data = array( 'updated_at' => current_time( 'mysql', true ) );
		$fmt  = array( '%s' );
		if ( isset( $fields['status'] ) ) {
			$data['status'] = $fields['status'];
			$fmt[]          = '%s';
		}
		if ( isset( $fields['lock_token'] ) ) {
			$data['lock_token'] = $fields['lock_token'];
			$fmt[]              = '%s';
		}
		if ( isset( $fields['cursor_data'] ) ) {
			$data['cursor_data'] = wp_json_encode( $fields['cursor_data'] );
			$fmt[]               = '%s';
		}
		if ( isset( $fields['stats'] ) ) {
			$data['stats'] = wp_json_encode( $fields['stats'] );
			$fmt[]         = '%s';
		}
		if ( isset( $fields['options_data'] ) ) {
			$data['options_data'] = wp_json_encode( $fields['options_data'] );
			$fmt[]                = '%s';
		}
		$wpdb->update( $wpdb->prefix . 'wcs3dm_jobs', $data, array( 'id' => $id ), $fmt, array( '%s' ) );
	}

	/**
	 * Acquire lock.
	 */
	private function acquire_lock( string $job_id, string $token ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'wcs3dm_jobs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET lock_token = %s, updated_at = %s WHERE id = %s AND (lock_token = '' OR lock_token IS NULL OR updated_at < %s)",
				$token,
				current_time( 'mysql', true ),
				$job_id,
				gmdate( 'Y-m-d H:i:s', time() - 300 )
			)
		);
		return false !== $updated && $updated > 0;
	}

	/**
	 * Hydrate DB row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'      => (string) $row['id'],
			'type'    => (string) $row['type'],
			'status'  => (string) $row['status'],
			'cursor'  => json_decode( (string) ( $row['cursor_data'] ?? '{}' ), true ) ?: array(),
			'stats'   => json_decode( (string) ( $row['stats'] ?? '{}' ), true ) ?: array(),
			'options' => json_decode( (string) ( $row['options_data'] ?? '{}' ), true ) ?: array(),
			'lock'    => (string) ( $row['lock_token'] ?? '' ),
			'created' => (string) ( $row['created_at'] ?? '' ),
			'updated' => (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
