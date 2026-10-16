<?php
/**
 * Administration UI and AJAX endpoints.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * WooCommerce → S3 Download Manager screens.
 */
final class Admin {

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
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );

		$actions = array(
			'wcs3dm_test_connection',
			'wcs3dm_run_diagnostics',
			'wcs3dm_list_objects',
			'wcs3dm_upload_file',
			'wcs3dm_discover',
			'wcs3dm_start_migration',
			'wcs3dm_job_action',
			'wcs3dm_job_status',
			'wcs3dm_cleanup',
			'wcs3dm_orphans',
			'wcs3dm_delete_orphans',
			'wcs3dm_overview',
			'wcs3dm_export_csv',
			'wcs3dm_clear_logs',
			'wcs3dm_select_object',
		);
		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'ajax_' . substr( $action, 7 ) ) );
			// Never expose admin actions to unauthenticated users.
			add_action(
				'wp_ajax_nopriv_' . $action,
				static function (): void {
					wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
				}
			);
		}
	}

	/**
	 * Register submenu under WooCommerce.
	 */
	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'S3 Download Manager', 'wc-s3-download-manager' ),
			__( 'S3 Download Manager', 'wc-s3-download-manager' ),
			'manage_woocommerce',
			'wcs3dm',
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue assets on our screen and product editor.
	 *
	 * @param string $hook Hook.
	 */
	public function assets( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_ours = ( isset( $_GET['page'] ) && 'wcs3dm' === $_GET['page'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_product = $screen && in_array( $screen->id, array( 'product', 'edit-product' ), true );

		if ( ! $is_ours && ! $is_product ) {
			return;
		}

		wp_enqueue_style( 'wcs3dm-admin', WCS3DM_URL . 'assets/css/admin.css', array(), WCS3DM_VERSION );
		wp_enqueue_script( 'wcs3dm-admin', WCS3DM_URL . 'assets/js/admin.js', array( 'jquery' ), WCS3DM_VERSION, true );
		wp_localize_script(
			'wcs3dm-admin',
			'wcs3dmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wcs3dm_admin' ),
				'i18n'    => array(
					'uploading' => __( 'Uploading…', 'wc-s3-download-manager' ),
					'success'   => __( 'Success', 'wc-s3-download-manager' ),
					'error'     => __( 'Error', 'wc-s3-download-manager' ),
					'confirmCleanup' => __( 'This will permanently delete verified local files. Continue?', 'wc-s3-download-manager' ),
				),
			)
		);
	}

	/**
	 * Handle settings form POST.
	 */
	public function handle_post(): void {
		if ( ! isset( $_POST['wcs3dm_save_settings'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'wcs3dm_save_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in Settings::save.
		$input = wp_unslash( $_POST );
		$this->settings->save( is_array( $input ) ? $input : array() );
		add_settings_error( 'wcs3dm', 'saved', __( 'Settings saved.', 'wc-s3-download-manager' ), 'success' );
	}

	/**
	 * Render admin app shell.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-s3-download-manager' ) );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed = array( 'overview', 'settings', 'browse', 'uploads', 'migration', 'verification', 'cleanup', 'logs' );
		if ( ! in_array( $tab, $allowed, true ) ) {
			$tab = 'overview';
		}

		settings_errors( 'wcs3dm' );
		include WCS3DM_PATH . 'templates/admin-shell.php';
	}

	/**
	 * Capability + nonce + rate-limit gate.
	 *
	 * @param string $action Rate-limit bucket name.
	 * @param int    $max    Max requests per window.
	 */
	private function guard( string $action = 'admin_ajax', int $max = 60 ): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		check_ajax_referer( 'wcs3dm_admin', 'nonce' );
		if ( ! Security::rate_limit( $action, $max, 60 ) ) {
			$this->logger->warning( 'Admin AJAX rate limit exceeded', array( 'action' => $action ) );
			wp_send_json_error( array( 'message' => 'Too many requests. Please wait and try again.' ), 429 );
		}
	}

	/**
	 * AJAX: test connection.
	 */
	public function ajax_test_connection(): void {
		$this->guard( 'test_connection', 10 );
		$tester = new Connection_Tester( $this->settings, $this->logger );
		wp_send_json_success( $tester->run() );
	}

	/**
	 * AJAX: diagnostics.
	 */
	public function ajax_run_diagnostics(): void {
		$this->guard();
		$diag = new Diagnostics( $this->settings );
		wp_send_json_success( array( 'tests' => $diag->run() ) );
	}

	/**
	 * AJAX: list S3 objects.
	 */
	public function ajax_list_objects(): void {
		$this->guard( 'list_objects', 40 );
		$root   = (string) $this->settings->get( 'prefix' );
		$prefix = isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['prefix'] ) ) : $root;
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['search'] ) ) : '';
		$token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : null;
		if ( $search ) {
			// Search is treated as a filename fragment under the root prefix only.
			$search = preg_replace( '#[^A-Za-z0-9._/\-]#', '', $search ) ?? '';
			$prefix = rtrim( $root, '/' ) . '/' . ltrim( $search, '/' );
		}
		$prefix = Security::constrain_list_prefix( $prefix, $root );
		$s3     = new S3_Client( $this->settings, $this->logger );
		wp_send_json_success( $s3->list_objects( $prefix, 50, $token ?: null ) );
	}

	/**
	 * AJAX: upload file to S3.
	 */
	public function ajax_upload_file(): void {
		$this->guard( 'upload_file', 20 );
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => 'No file uploaded.' ) );
		}
		$uploader = new File_Uploader( $this->settings, $this->logger );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in File_Uploader.
		$result = $uploader->upload_from_files_array( $_FILES['file'], $product_id );
		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? 'Upload failed.' ) ) );
		}
		// Never return signed URLs; only the plugin reference scheme.
		wp_send_json_success(
			array(
				'ok'        => true,
				'message'   => $result['message'],
				'reference' => $result['reference'] ?? '',
				'key'       => $result['key'] ?? '',
				'size'      => $result['size'] ?? 0,
				'name'      => $result['name'] ?? '',
			)
		);
	}

	/**
	 * AJAX: select existing object → reference.
	 */
	public function ajax_select_object(): void {
		$this->guard( 'select_object', 40 );
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['key'] ) ) : '';
		$prefix = (string) $this->settings->get( 'prefix' );
		if ( ! Reference::validate_key( $key ) || ! Security::key_under_prefix( $key, $prefix ) ) {
			wp_send_json_error( array( 'message' => 'Invalid key or outside configured prefix.' ) );
		}
		$s3   = new S3_Client( $this->settings, $this->logger );
		$head = $s3->head_object( $key );
		if ( ! $head['ok'] ) {
			wp_send_json_error( array( 'message' => $head['message'] ) );
		}
		wp_send_json_success(
			array(
				'reference' => Reference::build( $s3->bucket(), $key ),
				'key'       => $key,
				'size'      => $head['size'] ?? 0,
				'name'      => basename( $key ),
			)
		);
	}

	/**
	 * AJAX: discover downloads.
	 */
	public function ajax_discover(): void {
		$this->guard();
		$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$migrator = new Bulk_Migrator( $this->settings, $this->logger );
		wp_send_json_success( $migrator->discover( $page, 50 ) );
	}

	/**
	 * AJAX: start migration.
	 */
	public function ajax_start_migration(): void {
		$this->guard( 'start_migration', 10 );
		$options = array(
			'dry_run' => ! empty( $_POST['dry_run'] ),
		);
		if ( ! empty( $_POST['product_ids'] ) ) {
			$raw = wp_unslash( (string) $_POST['product_ids'] );
			$options['product_ids'] = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
		}
		$jobs   = new Background_Jobs( $this->settings, $this->logger );
		$result = $jobs->start_migration( $options );
		if ( ! $result['ok'] ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: pause/resume/cancel/retry.
	 */
	public function ajax_job_action(): void {
		$this->guard();
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['job_id'] ) ) : '';
		$action = isset( $_POST['job_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['job_action'] ) ) : '';
		$jobs   = new Background_Jobs( $this->settings, $this->logger );
		$result = match ( $action ) {
			'pause'  => $jobs->pause( $job_id ),
			'resume' => $jobs->resume( $job_id ),
			'cancel' => $jobs->cancel( $job_id ),
			'retry'  => $jobs->retry( $job_id ),
			default  => array( 'ok' => false, 'message' => 'Unknown action.' ),
		};
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: job status.
	 */
	public function ajax_job_status(): void {
		$this->guard( 'job_status', 120 );
		$jobs   = new Background_Jobs( $this->settings, $this->logger );
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['job_id'] ) ) : '';
		$process = ! empty( $_POST['process'] );

		if ( '' === $job_id ) {
			$active = $jobs->find_active( 'migrate' );
			$job_id = $active ? (string) $active['id'] : '';
		}

		if ( $process && '' !== $job_id ) {
			$current = $jobs->get_job( $job_id );
			if ( $current && 'queued' === $current['status'] && 'migrate' === $current['type'] ) {
				$jobs->run_migrate_batch( $job_id );
			}
		}

		$recent = ( new Bulk_Migrator( $this->settings, $this->logger ) )->recent_rows( 40 );
		if ( '' !== $job_id ) {
			wp_send_json_success(
				array(
					'job'    => $jobs->get_job( $job_id ),
					'recent' => $recent,
				)
			);
		}
		wp_send_json_success( array( 'jobs' => $jobs->list_jobs(), 'job' => null, 'recent' => $recent ) );
	}

	/**
	 * AJAX: cleanup.
	 */
	public function ajax_cleanup(): void {
		$this->guard( 'cleanup', 5 );
		$dry = empty( $_POST['execute'] );
		if ( ! $dry ) {
			if ( empty( $_POST['confirm'] ) || 'DELETE' !== (string) wp_unslash( $_POST['confirm'] ) ) {
				wp_send_json_error( array( 'message' => 'Type DELETE to confirm.' ) );
			}
			if ( ! $this->settings->get( 'cleanup_enabled' ) ) {
				wp_send_json_error( array( 'message' => 'Cleanup is disabled in settings.' ) );
			}
		}
		$paths = array();
		if ( ! $dry && isset( $_POST['paths'] ) && is_array( $_POST['paths'] ) ) {
			foreach ( wp_unslash( $_POST['paths'] ) as $path ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path is re-validated by Path_Validator.
				if ( is_string( $path ) && '' !== $path ) {
					$paths[] = $path;
				}
			}
		}
		if ( ! $dry && ! $paths ) {
			wp_send_json_error( array( 'message' => 'Select at least one file that is ready to delete.' ) );
		}

		$cleanup = new Local_Cleanup( $this->settings, $this->logger );
		wp_send_json_success( $cleanup->review( $dry, $paths ) );
	}

	/**
	 * AJAX: list local download files that no product uses.
	 */
	public function ajax_orphans(): void {
		$this->guard( 'orphans', 10 );
		$cleanup = new Local_Cleanup( $this->settings, $this->logger );
		wp_send_json_success( $cleanup->unreferenced_files() );
	}

	/**
	 * AJAX: delete selected unreferenced local files.
	 */
	public function ajax_delete_orphans(): void {
		$this->guard( 'delete_orphans', 5 );
		if ( empty( $_POST['confirm'] ) || 'DELETE' !== (string) wp_unslash( $_POST['confirm'] ) ) {
			wp_send_json_error( array( 'message' => 'Type DELETE to confirm.' ) );
		}
		$paths = array();
		if ( isset( $_POST['paths'] ) && is_array( $_POST['paths'] ) ) {
			foreach ( wp_unslash( $_POST['paths'] ) as $path ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path is re-checked against the orphan list.
				if ( is_string( $path ) && '' !== $path ) {
					$paths[] = $path;
				}
			}
		}
		if ( ! $paths ) {
			wp_send_json_error( array( 'message' => 'Select at least one file.' ) );
		}
		$cleanup = new Local_Cleanup( $this->settings, $this->logger );
		wp_send_json_success( $cleanup->delete_unreferenced( $paths ) );
	}

	/**
	 * AJAX: overview metrics.
	 */
	public function ajax_overview(): void {
		$this->guard();
		$migrator = new Bulk_Migrator( $this->settings, $this->logger );
		$counts   = $migrator->overview_counts();
		$stats    = get_option( 'wcs3dm_stats', array() );
		$jobs     = new Background_Jobs( $this->settings, $this->logger );
		wp_send_json_success(
			array(
				'counts' => $counts,
				'stats'  => is_array( $stats ) ? $stats : array(),
				'job'    => $jobs->find_active( 'migrate' ),
			)
		);
	}

	/**
	 * AJAX: export CSV.
	 */
	public function ajax_export_csv(): void {
		$this->guard();
		$migrator = new Bulk_Migrator( $this->settings, $this->logger );
		$csv      = $migrator->export_csv();
		wp_send_json_success( array( 'csv' => $csv, 'filename' => 'wcs3dm-migration-' . gmdate( 'Ymd-His' ) . '.csv' ) );
	}

	/**
	 * AJAX: clear logs.
	 */
	public function ajax_clear_logs(): void {
		$this->guard();
		$this->logger->clear();
		wp_send_json_success( array( 'message' => 'Logs cleared.' ) );
	}
}
