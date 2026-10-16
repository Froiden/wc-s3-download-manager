<?php
/**
 * Plugin Name:       WooCommerce S3 Download Manager
 * Plugin URI:        https://github.com/froiden/wc-s3-download-manager
 * Description:       Store and deliver WooCommerce downloadable products from private Amazon S3 buckets using temporary signed URLs.
 * Version:           1.0.9
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Froiden
 * Author URI:        https://froiden.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-s3-download-manager
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   9.8
 *
 * @package WCS3DM
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCS3DM_VERSION', '1.0.9' );
define( 'WCS3DM_FILE', __FILE__ );
define( 'WCS3DM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCS3DM_URL', plugin_dir_url( __FILE__ ) );
define( 'WCS3DM_BASENAME', plugin_basename( __FILE__ ) );
define( 'WCS3DM_MIN_PHP', '8.2' );
define( 'WCS3DM_MIN_WP', '6.4' );
define( 'WCS3DM_MIN_WC', '8.0' );
define( 'WCS3DM_SCHEME', 'wcs3dm' );

/**
 * Fail gracefully when PHP is too old (before autoload).
 */
if ( version_compare( PHP_VERSION, WCS3DM_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'WooCommerce S3 Download Manager requires PHP %1$s or higher. You are running PHP %2$s.', 'wc-s3-download-manager' ),
					WCS3DM_MIN_PHP,
					PHP_VERSION
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

$wcs3dm_autoload = WCS3DM_PATH . 'vendor/autoload.php';
if ( ! file_exists( $wcs3dm_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'WooCommerce S3 Download Manager: Composer dependencies are missing. Run composer install in the plugin directory.', 'wc-s3-download-manager' );
			echo '</p></div>';
		}
	);
	return;
}

require_once $wcs3dm_autoload;

/**
 * Declares WooCommerce feature compatibility.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCS3DM_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WCS3DM_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( \WCS3DM\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WCS3DM\Deactivator::class, 'deactivate' ) );

/**
 * Boot the plugin after plugins are loaded.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		\WCS3DM\Plugin::instance()->init();
	},
	20
);
