<?php
/**
 * Main plugin bootstrap.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Singleton plugin container.
 */
final class Plugin {

	/**
	 * Instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Logger service.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Get singleton.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->logger   = new Logger();
	}

	/**
	 * Initialize hooks once WooCommerce is available.
	 */
	public function init(): void {
		load_plugin_textdomain( 'wc-s3-download-manager', false, dirname( WCS3DM_BASENAME ) . '/languages' );

		if ( ! $this->meets_requirements() ) {
			return;
		}

		if ( is_admin() ) {
			( new Admin( $this->settings, $this->logger ) )->hooks();
		}

		( new Product_Integration( $this->settings, $this->logger ) )->hooks();
		( new Download_Handler( $this->settings, $this->logger ) )->hooks();
		( new Background_Jobs( $this->settings, $this->logger ) )->hooks();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wcs3dm', CLI::class );
		}
	}

	/**
	 * Settings accessor.
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Logger accessor.
	 */
	public function logger(): Logger {
		return $this->logger;
	}

	/**
	 * Build an S3 client from current settings.
	 */
	public function s3(): S3_Client {
		return new S3_Client( $this->settings, $this->logger );
	}

	/**
	 * Compatibility checks; shows admin notices on failure.
	 */
	private function meets_requirements(): bool {
		$ok = true;

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce S3 Download Manager requires WooCommerce to be active.', 'wc-s3-download-manager' ) . '</p></div>';
				}
			);
			$ok = false;
		} elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, WCS3DM_MIN_WC, '<' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html(
						sprintf(
							/* translators: 1: required WC version, 2: current WC version */
							__( 'WooCommerce S3 Download Manager requires WooCommerce %1$s or higher. You are running %2$s.', 'wc-s3-download-manager' ),
							WCS3DM_MIN_WC,
							WC_VERSION
						)
					);
					echo '</p></div>';
				}
			);
			$ok = false;
		}

		global $wp_version;
		if ( isset( $wp_version ) && version_compare( $wp_version, WCS3DM_MIN_WP, '<' ) ) {
			add_action(
				'admin_notices',
				static function () use ( $wp_version ): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html(
						sprintf(
							/* translators: 1: required WP version, 2: current WP version */
							__( 'WooCommerce S3 Download Manager requires WordPress %1$s or higher. You are running %2$s.', 'wc-s3-download-manager' ),
							WCS3DM_MIN_WP,
							$wp_version
						)
					);
					echo '</p></div>';
				}
			);
			$ok = false;
		}

		return $ok;
	}
}
