<?php
/**
 * Browse S3 tab.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wcs3dm-card" id="wcs3dm-browser">
	<p>
		<label for="wcs3dm-browse-prefix"><?php esc_html_e( 'Prefix', 'wc-s3-download-manager' ); ?></label>
		<input type="text" id="wcs3dm-browse-prefix" class="regular-text" value="<?php echo esc_attr( (string) \WCS3DM\Plugin::instance()->settings()->get( 'prefix' ) ); ?>" />
		<label for="wcs3dm-browse-search"><?php esc_html_e( 'Search', 'wc-s3-download-manager' ); ?></label>
		<input type="text" id="wcs3dm-browse-search" class="regular-text" />
		<button type="button" class="button button-primary" id="wcs3dm-browse-refresh"><?php esc_html_e( 'List objects', 'wc-s3-download-manager' ); ?></button>
	</p>
	<div id="wcs3dm-browse-results"></div>
</div>
