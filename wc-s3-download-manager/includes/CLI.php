<?php
/**
 * WP-CLI commands.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * wp wcs3dm …
 */
final class CLI {

	/**
	 * Test S3 connection.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcs3dm test-connection
	 *
	 * @when after_wp_load
	 */
	public function test_connection( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$plugin = Plugin::instance();
		$tester = new Connection_Tester( $plugin->settings(), $plugin->logger() );
		$result = $tester->run();
		foreach ( $result['tests'] as $test ) {
			\WP_CLI::log( sprintf( '[%s] %s — %s', strtoupper( $test['status'] ), $test['label'], $test['message'] ) );
		}
		\WP_CLI::success( $result['summary'] );
		if ( ! empty( $result['leftover_key'] ) ) {
			\WP_CLI::warning( 'Leftover test object key: ' . $result['leftover_key'] );
		}
	}

	/**
	 * Discover downloadable files.
	 *
	 * ## OPTIONS
	 *
	 * [--page=<n>]
	 * : Page number.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcs3dm discover
	 *
	 * @when after_wp_load
	 */
	public function discover( array $args, array $assoc_args ): void {
		unset( $args );
		$page     = (int) ( $assoc_args['page'] ?? 1 );
		$migrator = new Bulk_Migrator( Plugin::instance()->settings(), Plugin::instance()->logger() );
		$result   = $migrator->discover( $page, 50 );
		\WP_CLI::log( 'Page ' . $result['page'] . '/' . $result['pages'] . ' — products matched query: ' . $result['total_products'] );
		foreach ( $result['items'] as $item ) {
			\WP_CLI::log( sprintf( '%d | %s | %s | %s | %d bytes', $item['product_id'], $item['download_id'], $item['status'], $item['name'], $item['size'] ) );
		}
		\WP_CLI::success( wp_json_encode( $result['summary'] ) );
	}

	/**
	 * Migrate downloads to S3.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Do not upload or update references.
	 *
	 * [--product_id=<id>]
	 * : Limit to one product.
	 *
	 * [--sync]
	 * : Run batches in-process until done (for CLI only).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcs3dm migrate --dry-run
	 *     wp wcs3dm migrate --sync
	 *
	 * @when after_wp_load
	 */
	public function migrate( array $args, array $assoc_args ): void {
		unset( $args );
		$options = array(
			'dry_run' => isset( $assoc_args['dry-run'] ),
		);
		if ( ! empty( $assoc_args['product_id'] ) ) {
			$options['product_ids'] = array( (int) $assoc_args['product_id'] );
		}

		$jobs = new Background_Jobs( Plugin::instance()->settings(), Plugin::instance()->logger() );

		if ( isset( $assoc_args['sync'] ) ) {
			$migrator = new Bulk_Migrator( Plugin::instance()->settings(), Plugin::instance()->logger() );
			$cursor   = 0;
			$total    = array( 'processed' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0 );
			do {
				$options['cursor'] = $cursor;
				$batch = $migrator->process_batch( $options );
				$cursor = (int) $batch['cursor'];
				foreach ( array( 'processed', 'success', 'failed', 'skipped' ) as $k ) {
					$total[ $k ] += (int) $batch[ $k ];
				}
				foreach ( $batch['messages'] as $msg ) {
					\WP_CLI::log( $msg );
				}
				if ( $batch['done'] && 0 === $batch['processed'] ) {
					break;
				}
			} while ( empty( $batch['done'] ) || $batch['processed'] > 0 );

			\WP_CLI::success( 'Done: ' . wp_json_encode( $total ) );
			return;
		}

		$result = $jobs->start_migration( $options );
		if ( ! $result['ok'] ) {
			\WP_CLI::error( $result['message'] );
		}
		\WP_CLI::success( 'Queued job ' . $result['job_id'] );
	}

	/**
	 * Show job status.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : Job UUID.
	 *
	 * @when after_wp_load
	 */
	public function job( array $args ): void {
		$job_id = $args[0] ?? '';
		$jobs   = new Background_Jobs( Plugin::instance()->settings(), Plugin::instance()->logger() );
		$job    = $jobs->get_job( $job_id );
		if ( ! $job ) {
			\WP_CLI::error( 'Job not found.' );
		}
		\WP_CLI::log( wp_json_encode( $job, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Verify and optionally clean local files.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report only.
	 *
	 * [--execute]
	 * : Actually delete after verification (requires cleanup_enabled).
	 *
	 * @when after_wp_load
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		unset( $args );
		$dry     = ! isset( $assoc_args['execute'] );
		$cleanup = new Local_Cleanup( Plugin::instance()->settings(), Plugin::instance()->logger() );
		foreach ( $cleanup->candidates( 50 ) as $row ) {
			$result = $cleanup->process_entry(
				(string) $row['source_path'],
				(string) $row['object_key'],
				(string) $row['checksum_sha256'],
				(int) $row['file_size'],
				array( (int) $row['product_id'] ),
				$dry || ! empty( $assoc_args['dry-run'] )
			);
			\WP_CLI::log( $result['outcome'] . ': ' . $result['reason'] );
		}
		\WP_CLI::success( 'Cleanup pass finished.' );
	}
}
