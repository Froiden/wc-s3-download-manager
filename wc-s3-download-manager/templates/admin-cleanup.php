<?php
/**
 * Local cleanup tab.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$enabled = (bool) \WCS3DM\Plugin::instance()->settings()->get( 'cleanup_enabled', false );
$grace   = (int) \WCS3DM\Plugin::instance()->settings()->get( 'cleanup_grace_days', 7 );
?>
<div class="wcs3dm-card">
	<?php if ( ! $enabled ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Local cleanup is disabled. Enable it under S3 Settings after you have checked the downloads.', 'wc-s3-download-manager' ); ?></p></div>
	<?php endif; ?>
	<p><?php esc_html_e( 'Each row is a local WooCommerce download that was copied to S3 and is still on this server. S3 objects are never deleted. Only the selected local files are removed, and only after the checks pass.', 'wc-s3-download-manager' ); ?></p>
	<?php if ( $grace > 0 ) : ?>
		<p class="description"><?php echo esc_html( sprintf( /* translators: %d: grace period in days */ __( 'Grace period is %d days. Files stay on disk until that many days have passed since the migration.', 'wc-s3-download-manager' ), $grace ) ); ?></p>
	<?php endif; ?>
	<p>
		<button type="button" class="button" id="wcs3dm-cleanup-dry"><?php esc_html_e( 'Refresh report', 'wc-s3-download-manager' ); ?></button>
		<button type="button" class="button" id="wcs3dm-cleanup-select-ready"><?php esc_html_e( 'Select ready files', 'wc-s3-download-manager' ); ?></button>
		<button type="button" class="button button-secondary" id="wcs3dm-cleanup-execute" <?php disabled( ! $enabled ); ?>><?php esc_html_e( 'Delete selected local files', 'wc-s3-download-manager' ); ?></button>
	</p>
	<p>
		<label><?php esc_html_e( 'Type DELETE to confirm', 'wc-s3-download-manager' ); ?>
			<input type="text" id="wcs3dm-cleanup-confirm" autocomplete="off" />
		</label>
	</p>
	<div id="wcs3dm-cleanup-results">
		<p class="description"><?php esc_html_e( 'Loading local files…', 'wc-s3-download-manager' ); ?></p>
	</div>
</div>
