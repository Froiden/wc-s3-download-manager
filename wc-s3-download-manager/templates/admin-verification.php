<?php
/**
 * Verification tab.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wcs3dm-card">
	<p><?php esc_html_e( 'This page shows where each downloadable file lives, so you can see what customers will get.', 'wc-s3-download-manager' ); ?></p>
	<ul class="wcs3dm-legend">
		<li><span class="wcs3dm-status-passed"><?php esc_html_e( 'On S3', 'wc-s3-download-manager' ); ?></span> — <?php esc_html_e( 'copied to your private bucket. Customers download it from there.', 'wc-s3-download-manager' ); ?></li>
		<li><span class="wcs3dm-status-info"><?php esc_html_e( 'On this server', 'wc-s3-download-manager' ); ?></span> — <?php esc_html_e( 'still a local file. Bulk Migration can copy it.', 'wc-s3-download-manager' ); ?></li>
		<li><span class="wcs3dm-status-warning"><?php esc_html_e( 'Website link, file missing', 'wc-s3-download-manager' ); ?></span> — <?php esc_html_e( 'the product still points at a link on this site, but the file is not on disk and not on S3.', 'wc-s3-download-manager' ); ?></li>
	</ul>
	<p>
		<button type="button" class="button" id="wcs3dm-discover"><?php esc_html_e( 'Refresh', 'wc-s3-download-manager' ); ?></button>
	</p>
	<div id="wcs3dm-discover-results" data-autoload="1">
		<p class="description"><?php esc_html_e( 'Checking products…', 'wc-s3-download-manager' ); ?></p>
	</div>
</div>
<div class="wcs3dm-card" style="margin-top:16px;">
	<h2><?php esc_html_e( 'Files no product uses', 'wc-s3-download-manager' ); ?></h2>
	<p><?php esc_html_e( 'These files are in the WooCommerce downloads folder on this server, but no product download points at them. That includes files left behind after a product was moved to S3. Media Library files are not listed. S3 objects are not deleted.', 'wc-s3-download-manager' ); ?></p>
	<p>
		<button type="button" class="button" id="wcs3dm-orphans-refresh"><?php esc_html_e( 'Refresh', 'wc-s3-download-manager' ); ?></button>
		<button type="button" class="button" id="wcs3dm-orphans-select"><?php esc_html_e( 'Select all', 'wc-s3-download-manager' ); ?></button>
		<button type="button" class="button button-secondary" id="wcs3dm-orphans-delete"><?php esc_html_e( 'Delete selected from this server', 'wc-s3-download-manager' ); ?></button>
	</p>
	<p>
		<label><?php esc_html_e( 'Type DELETE to confirm', 'wc-s3-download-manager' ); ?>
			<input type="text" id="wcs3dm-orphans-confirm" autocomplete="off" />
		</label>
	</p>
	<div id="wcs3dm-orphan-results" data-autoload="1">
		<p class="description"><?php esc_html_e( 'Looking for unused files…', 'wc-s3-download-manager' ); ?></p>
	</div>
</div>
