<?php
/**
 * Overview tab.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="wcs3dm-overview" class="wcs3dm-card">
	<p><?php esc_html_e( 'High-level metrics for downloadable files and background jobs.', 'wc-s3-download-manager' ); ?></p>
	<p>
		<button type="button" class="button button-primary" id="wcs3dm-refresh-overview"><?php esc_html_e( 'Refresh metrics', 'wc-s3-download-manager' ); ?></button>
		<button type="button" class="button" id="wcs3dm-run-diagnostics"><?php esc_html_e( 'Run Diagnostics', 'wc-s3-download-manager' ); ?></button>
	</p>
	<div id="wcs3dm-overview-metrics" class="wcs3dm-metrics" aria-live="polite">
		<p class="description"><?php esc_html_e( 'Click refresh to load metrics (scans products in pages).', 'wc-s3-download-manager' ); ?></p>
	</div>
	<div id="wcs3dm-diagnostics-results"></div>
</div>
