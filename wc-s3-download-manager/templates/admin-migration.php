<?php
/**
 * Bulk migration tab.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wcs3dm-card">
	<div class="notice notice-info inline"><p><?php esc_html_e( 'Migration copies files to S3 and updates the product. It does not delete anything on this server. Local files stay until you review the results and then run Local Cleanup yourself.', 'wc-s3-download-manager' ); ?></p></div>
	<p>
		<button type="button" class="button" id="wcs3dm-discover"><?php esc_html_e( 'Discover files', 'wc-s3-download-manager' ); ?></button>
		<button type="button" class="button" id="wcs3dm-migrate-dry"><?php esc_html_e( 'Start dry-run migration', 'wc-s3-download-manager' ); ?></button>
		<button type="button" class="button button-primary" id="wcs3dm-migrate-run"><?php esc_html_e( 'Start migration', 'wc-s3-download-manager' ); ?></button>
		<button type="button" class="button" id="wcs3dm-export-csv"><?php esc_html_e( 'Export CSV report', 'wc-s3-download-manager' ); ?></button>
	</p>
	<p>
		<label><?php esc_html_e( 'Limit to product IDs (comma-separated, optional)', 'wc-s3-download-manager' ); ?>
			<input type="text" id="wcs3dm-migrate-products" class="regular-text" />
		</label>
	</p>
	<div id="wcs3dm-job-controls" hidden>
		<button type="button" class="button" data-job-action="pause"><?php esc_html_e( 'Pause', 'wc-s3-download-manager' ); ?></button>
		<button type="button" class="button" data-job-action="resume"><?php esc_html_e( 'Resume', 'wc-s3-download-manager' ); ?></button>
		<button type="button" class="button" data-job-action="cancel"><?php esc_html_e( 'Cancel', 'wc-s3-download-manager' ); ?></button>
		<button type="button" class="button" data-job-action="retry"><?php esc_html_e( 'Retry', 'wc-s3-download-manager' ); ?></button>
	</div>
	<div id="wcs3dm-migration-status" aria-live="polite"></div>
	<div id="wcs3dm-activity"></div>
	<div id="wcs3dm-discover-results"></div>
</div>
