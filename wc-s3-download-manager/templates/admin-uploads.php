<?php
/**
 * Uploads tab.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wcs3dm-card">
	<p><?php esc_html_e( 'Upload a file directly to the configured private S3 bucket. Use the product editor to attach the resulting reference to a downloadable product.', 'wc-s3-download-manager' ); ?></p>
	<p>
		<input type="file" id="wcs3dm-direct-upload-input" />
		<button type="button" class="button button-primary" id="wcs3dm-direct-upload"><?php esc_html_e( 'Upload to S3', 'wc-s3-download-manager' ); ?></button>
	</p>
	<div id="wcs3dm-upload-progress" class="wcs3dm-progress" hidden>
		<div class="wcs3dm-progress-bar" style="width:0%"></div>
	</div>
	<pre id="wcs3dm-upload-result" class="wcs3dm-code"></pre>
</div>
