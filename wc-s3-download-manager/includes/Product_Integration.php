<?php
/**
 * WooCommerce product editor integration.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Protects custom download URIs and exposes admin helpers.
 */
final class Product_Integration {

	/**
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Temporary map for clean_url protection.
	 *
	 * @var array<string, string>
	 */
	private array $protect_map = array();

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
		add_action( 'woocommerce_product_options_downloads', array( $this, 'render_s3_controls' ) );
		add_action( 'woocommerce_variation_options_download', array( $this, 'render_variation_s3_controls' ), 10, 3 );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'protect_download_urls' ), 5 );
		add_action( 'woocommerce_admin_process_variation_object', array( $this, 'protect_download_urls' ), 5 );
		add_filter( 'woocommerce_file_download_paths', array( $this, 'allow_scheme_in_paths' ), 10, 1 );
		add_filter( 'woocommerce_product_file_download_path', array( $this, 'filter_download_path' ), 10, 3 );
		add_filter( 'woocommerce_downloadable_file_exists', array( $this, 'file_exists' ), 10, 2 );
		add_shortcode( 'wcs3dm', array( $this, 'shortcode' ) );
		add_action( 'admin_init', array( $this, 'ensure_approved_directory' ) );
	}

	/**
	 * Shortcode stored on the product. WooCommerce expands it only while checking
	 * approved download directories, so the returned URL must sit in an approved folder.
	 * Customer delivery never uses this URL; the download handler reads the shortcode.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function shortcode( $atts = array() ): string {
		unset( $atts );
		$uploads = wp_upload_dir( null, false );
		$base    = ! empty( $uploads['baseurl'] ) ? $uploads['baseurl'] : home_url( '/wp-content/uploads' );
		return trailingslashit( $base ) . 'woocommerce_uploads/wcs3dm-managed.bin';
	}

	/**
	 * Make sure the placeholder directory WooCommerce checks is approved.
	 */
	public function ensure_approved_directory(): void {
		if ( get_option( 'wcs3dm_approved_dir_ready' ) || ! function_exists( 'wc_get_container' ) ) {
			return;
		}
		$class = \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register::class;
		if ( ! class_exists( $class ) ) {
			return;
		}
		try {
			$register = wc_get_container()->get( $class );
			if ( is_object( $register ) && method_exists( $register, 'add_approved_directory' ) ) {
				$uploads = wp_upload_dir( null, false );
				$base    = ! empty( $uploads['baseurl'] ) ? $uploads['baseurl'] : home_url( '/wp-content/uploads' );
				$register->add_approved_directory( trailingslashit( $base ) . 'woocommerce_uploads/', true );
			}
		} catch ( \Throwable $e ) {
			$this->logger->warning( 'Could not register approved download directory', array( 'error' => $e->getMessage() ) );
		}
		update_option( 'wcs3dm_approved_dir_ready', '1', false );
	}

	/**
	 * Legacy wcs3dm:// references should not fail the local file-exists check.
	 *
	 * @param bool   $exists Whether WooCommerce thinks the file exists.
	 * @param string $file   File reference.
	 */
	public function file_exists( $exists, $file ): bool {
		return Reference::is_ours( (string) $file ) ? true : (bool) $exists;
	}

	/**
	 * Render controls under downloadable files.
	 */
	public function render_s3_controls(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="wcs3dm-product-controls" data-product-id="' . esc_attr( (string) get_the_ID() ) . '">';
		echo '<p class="form-field"><strong>' . esc_html__( 'S3 Download Manager', 'wc-s3-download-manager' ) . '</strong></p>';
		echo '<p class="description">' . esc_html__( 'Upload a file to your private S3 bucket or select an existing object. The download reference will use the plugin scheme and be delivered via signed URLs.', 'wc-s3-download-manager' ) . '</p>';
		echo '<p>';
		echo '<button type="button" class="button wcs3dm-upload-to-s3">' . esc_html__( 'Upload to S3', 'wc-s3-download-manager' ) . '</button> ';
		echo '<button type="button" class="button wcs3dm-browse-s3">' . esc_html__( 'Select from S3', 'wc-s3-download-manager' ) . '</button>';
		echo '</p>';
		echo '<input type="file" class="wcs3dm-file-input" style="display:none" />';
		echo '</div>';
	}

	/**
	 * Variation controls.
	 *
	 * @param int      $loop           Loop index.
	 * @param array    $variation_data Data.
	 * @param \WP_Post $variation      Variation post.
	 */
	public function render_variation_s3_controls( int $loop, array $variation_data, \WP_Post $variation ): void {
		unset( $variation_data );
		echo '<div class="wcs3dm-product-controls" data-product-id="' . esc_attr( (string) $variation->ID ) . '" data-loop="' . esc_attr( (string) $loop ) . '">';
		echo '<p><button type="button" class="button wcs3dm-upload-to-s3">' . esc_html__( 'Upload to S3', 'wc-s3-download-manager' ) . '</button> ';
		echo '<button type="button" class="button wcs3dm-browse-s3">' . esc_html__( 'Select from S3', 'wc-s3-download-manager' ) . '</button></p>';
		echo '<input type="file" class="wcs3dm-file-input" style="display:none" />';
		echo '</div>';
	}

	/**
	 * Prevent WordPress from stripping our custom scheme on save.
	 *
	 * @param \WC_Product $product Product.
	 */
	public function protect_download_urls( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$downloads = $product->get_downloads( 'edit' );
		if ( empty( $downloads ) ) {
			return;
		}

		$this->protect_map = array();
		foreach ( $downloads as $download ) {
			$file = $download->get_file();
			if ( Reference::is_ours( $file ) ) {
				$this->protect_map[ $file ] = $file;
			}
		}
		if ( ! $this->protect_map ) {
			return;
		}

		add_filter( 'clean_url', array( $this, 'preserve_scheme' ), 1, 2 );

		$rebuilt = array();
		foreach ( $downloads as $id => $download ) {
			$rebuilt[ $id ] = array(
				'id'      => $download->get_id(),
				'name'    => $download->get_name(),
				'file'    => $download->get_file(),
				'enabled' => $download->get_enabled(),
			);
		}
		try {
			$product->set_downloads( $rebuilt );
		} catch ( \Throwable $e ) {
			$this->logger->warning( 'Could not re-set downloads while protecting S3 URIs', array( 'error' => $e->getMessage() ) );
		}

		remove_filter( 'clean_url', array( $this, 'preserve_scheme' ), 1 );
		$this->protect_map = array();
	}

	/**
	 * clean_url callback.
	 *
	 * @param string $url      URL.
	 * @param string $original Original.
	 */
	public function preserve_scheme( string $url, string $original = '' ): string {
		$candidate = $original ?: $url;
		if ( isset( $this->protect_map[ $candidate ] ) ) {
			return $candidate;
		}
		if ( Reference::is_ours( $candidate ) ) {
			return $candidate;
		}
		return $url;
	}

	/**
	 * Allow our scheme through approved path lists when present.
	 *
	 * @param array<int, string> $paths Paths.
	 * @return array<int, string>
	 */
	public function allow_scheme_in_paths( $paths ): array {
		$paths   = is_array( $paths ) ? $paths : array();
		$paths[] = WCS3DM_SCHEME . '://';
		return $paths;
	}

	/**
	 * Filter download path accessor.
	 *
	 * @param string $file       File.
	 * @param int    $product_id Product ID.
	 * @param string $download_id Download ID.
	 */
	public function filter_download_path( $file, $product_id = 0, $download_id = '' ): string {
		unset( $product_id, $download_id );
		return (string) $file;
	}

	/**
	 * Treat plugin references as approved.
	 *
	 * @param bool   $approved Whether approved.
	 * @param string $file     File URL/path.
	 */
	public function approve_our_scheme( bool $approved, string $file ): bool {
		return Reference::is_ours( $file ) ? true : $approved;
	}

	/**
	 * Allow plugin-owned download references through WC validation hooks.
	 *
	 * @param bool   $allowed Whether allowed.
	 * @param string $file    File.
	 */
	public function allow_our_download( bool $allowed, string $file ): bool {
		return Reference::is_ours( $file ) ? true : $allowed;
	}

	/**
	 * Apply an S3 reference to a product download entry via CRUD.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function set_download_reference( int $product_id, string $download_id, string $name, string $reference ): array {
		if ( ! Reference::is_ours( $reference ) ) {
			return array( 'ok' => false, 'message' => 'Invalid reference format.' );
		}
		$parsed = Reference::parse( $reference );
		if ( null === $parsed ) {
			return array( 'ok' => false, 'message' => 'Could not parse S3 reference.' );
		}
		$bucket = (string) $this->settings->get( 'bucket', '' );
		$prefix = (string) $this->settings->get( 'prefix', 'woocommerce-downloads/' );
		if ( ! Security::bucket_allowed( $parsed['bucket'], $bucket ) ) {
			return array( 'ok' => false, 'message' => 'Reference bucket does not match configured bucket.' );
		}
		if ( ! Reference::validate_key( $parsed['key'] ) || ! Security::key_under_prefix( $parsed['key'], $prefix ) ) {
			return array( 'ok' => false, 'message' => 'Reference object key is not allowed.' );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array( 'ok' => false, 'message' => 'Product not found.' );
		}

		$downloads = $product->get_downloads( 'edit' );
		$entries   = array();
		$found     = false;

		foreach ( $downloads as $id => $download ) {
			$file = $download->get_file();
			$entry_name = $download->get_name();
			if ( (string) $id === (string) $download_id || ( '' === $download_id && ! $found ) ) {
				$file         = $reference;
				$entry_name   = $name ?: $entry_name;
				$download_id  = (string) $id;
				$found        = true;
			}
			$entries[] = array(
				'download_id' => (string) $id,
				'name'        => $entry_name,
				'file'        => $file,
			);
		}

		if ( ! $found ) {
			$entries[] = array(
				'download_id' => $download_id ?: wp_generate_uuid4(),
				'name'        => $name ?: 'Download',
				'file'        => $reference,
			);
		}

		$this->protect_map = array( $reference => $reference );
		add_filter( 'clean_url', array( $this, 'preserve_scheme' ), 1, 2 );
		try {
			$product->set_downloads( $entries );
			$product->set_downloadable( true );
			$product->save();
		} catch ( \Throwable $e ) {
			remove_filter( 'clean_url', array( $this, 'preserve_scheme' ), 1 );
			return array( 'ok' => false, 'message' => $e->getMessage() );
		}
		remove_filter( 'clean_url', array( $this, 'preserve_scheme' ), 1 );
		$this->protect_map = array();

		// Read back.
		$fresh = wc_get_product( $product_id );
		if ( ! $fresh ) {
			return array( 'ok' => false, 'message' => 'Could not re-load product after save.' );
		}
		$ok = false;
		foreach ( $fresh->get_downloads() as $d ) {
			if ( Reference::is_ours( $d->get_file() ) && $d->get_file() === $reference ) {
				$ok = true;
				break;
			}
		}
		if ( ! $ok ) {
			return array( 'ok' => false, 'message' => 'Saved reference could not be verified on read-back.' );
		}

		$this->logger->success(
			'Updated product download reference',
			array(
				'product_id' => $product_id,
			)
		);

		return array( 'ok' => true, 'message' => 'Download reference updated.' );
	}
}
