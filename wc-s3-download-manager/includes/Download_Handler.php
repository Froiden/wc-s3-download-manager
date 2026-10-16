<?php
/**
 * Secure download delivery via presigned URLs.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Hooks into WooCommerce download flow after authorization.
 */
final class Download_Handler {

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
		add_filter( 'woocommerce_file_download_method', array( $this, 'force_redirect_method' ), 10, 3 );
		add_action( 'woocommerce_download_file_redirect', array( $this, 'redirect_s3_download' ), 1, 2 );
		add_filter( 'woocommerce_download_product_filepath', array( $this, 'filter_filepath' ), 10, 5 );
	}

	/**
	 * Force redirect method for our files so WooCommerce does not try to stream the reference.
	 *
	 * Parameters are untyped on purpose. WooCommerce often passes the product ID as a numeric string,
	 * and a strict int hint fatals the download request.
	 *
	 * @param mixed $method     Download method.
	 * @param mixed $product_id Product ID.
	 * @param mixed $file_path  File path.
	 */
	public function force_redirect_method( $method, $product_id = 0, $file_path = '' ): string {
		unset( $product_id );
		$file_path = html_entity_decode( (string) $file_path, ENT_QUOTES );
		if ( Reference::is_ours( $file_path ) ) {
			return 'redirect';
		}
		return (string) $method;
	}

	/**
	 * Pass through filepath (kept for future order-based overrides).
	 *
	 * @param mixed $file_path File path.
	 */
	public function filter_filepath( $file_path ): string {
		return (string) $file_path;
	}

	/**
	 * After WC authorization, redirect to a short-lived signed URL.
	 *
	 * Authorization must already have passed in WC_Download_Handler
	 * (order key, email, limits, expiry, login). This method never bypasses that.
	 *
	 * @param mixed $file_path File path / reference.
	 * @param mixed $filename  Filename hint.
	 */
	public function redirect_s3_download( $file_path, $filename = '' ): void {
		$file_path = html_entity_decode( (string) $file_path, ENT_QUOTES );
		$filename  = is_scalar( $filename ) ? (string) $filename : '';

		if ( ! Reference::is_ours( $file_path ) ) {
			return;
		}

		$parsed = Reference::parse( $file_path );
		if ( null === $parsed ) {
			$this->logger->error( 'Invalid S3 download reference during delivery' );
			wp_die( esc_html__( 'Invalid download reference.', 'wc-s3-download-manager' ), 404 );
		}

		$configured_bucket = (string) $this->settings->get( 'bucket', '' );
		$prefix            = (string) $this->settings->get( 'prefix', 'woocommerce-downloads/' );

		if ( ! Security::bucket_allowed( $parsed['bucket'], $configured_bucket ) ) {
			$this->logger->error( 'Download blocked: bucket mismatch' );
			wp_die( esc_html__( 'Invalid download reference.', 'wc-s3-download-manager' ), 403 );
		}

		if ( ! Reference::validate_key( $parsed['key'] ) || ! Security::key_under_prefix( $parsed['key'], $prefix ) ) {
			$this->logger->error( 'Download blocked: object key outside configured prefix', array( 'key' => $parsed['key'] ) );
			wp_die( esc_html__( 'Invalid download reference.', 'wc-s3-download-manager' ), 403 );
		}

		if ( ! Security::rate_limit( 'customer_download', 60, 60 ) ) {
			$this->logger->warning( 'Download rate limit exceeded' );
			wp_die( esc_html__( 'Too many download attempts. Please wait a moment and try again.', 'wc-s3-download-manager' ), 429 );
		}

		try {
			$s3 = new S3_Client( $this->settings, $this->logger );

			// Existence check before minting a signed URL.
			$head = $s3->head_object( $parsed['key'], $configured_bucket );
			if ( ! $head['ok'] ) {
				$this->logger->error( 'S3 object missing during download', array( 'key' => $parsed['key'] ) );
				wp_die( esc_html__( 'The downloadable file could not be found.', 'wc-s3-download-manager' ), 404 );
			}

			unset( $filename );
			$safe_name = Reference::customer_filename( $parsed['key'] );
			$result    = $s3->presign( $parsed['key'], null, $configured_bucket, $safe_name );
			if ( ! $result['ok'] || empty( $result['url'] ) ) {
				$this->logger->error( 'Failed to generate signed URL for download', array( 'key' => $parsed['key'], 'error' => $result['message'] ) );
				wp_die( esc_html__( 'This download is temporarily unavailable. Please try again later.', 'wc-s3-download-manager' ), 503 );
			}

			$this->logger->info( 'Authorized download redirected to signed URL', array( 'key' => $parsed['key'] ) );

			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			wp_redirect( $result['url'], 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- intentional external S3 URL after WC auth.
			exit;
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Download handler exception', array( 'error' => $e->getMessage() ) );
			wp_die( esc_html__( 'Download failed due to a storage error.', 'wc-s3-download-manager' ), 500 );
		}
	}
}
