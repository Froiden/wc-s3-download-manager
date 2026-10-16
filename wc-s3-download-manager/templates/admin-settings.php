<?php
/**
 * S3 settings tab.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = \WCS3DM\Plugin::instance()->settings();
$all      = $settings->all();
$masked   = $settings->masked_credentials();
?>
<form method="post" action="" class="wcs3dm-card">
	<?php wp_nonce_field( 'wcs3dm_save_settings' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="wcs3dm-access-key"><?php esc_html_e( 'AWS Access Key ID', 'wc-s3-download-manager' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="wcs3dm-access-key" name="access_key" value="" autocomplete="off" placeholder="<?php echo esc_attr( $masked['access_key'] ?: '' ); ?>" />
				<p class="description"><?php esc_html_e( 'Leave blank to keep the existing key. Never use AWS root credentials.', 'wc-s3-download-manager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-secret-key"><?php esc_html_e( 'AWS Secret Access Key', 'wc-s3-download-manager' ); ?></label></th>
			<td>
				<input type="password" class="regular-text" id="wcs3dm-secret-key" name="secret_key" value="" autocomplete="new-password" placeholder="<?php echo ! empty( $masked['secret_key_set'] ) ? '********' : ''; ?>" />
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-session-token"><?php esc_html_e( 'AWS Session Token (optional)', 'wc-s3-download-manager' ); ?></label></th>
			<td><input type="text" class="regular-text" id="wcs3dm-session-token" name="session_token" value="" autocomplete="off" /></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Use IAM instance/role credentials', 'wc-s3-download-manager' ); ?></th>
			<td><label><input type="checkbox" name="use_iam_role" value="1" <?php checked( ! empty( $all['use_iam_role'] ) ); ?> /> <?php esc_html_e( 'Prefer temporary credentials / IAM role when available (no access keys required).', 'wc-s3-download-manager' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-region"><?php esc_html_e( 'AWS Region', 'wc-s3-download-manager' ); ?></label></th>
			<td><input type="text" class="regular-text" id="wcs3dm-region" name="region" value="<?php echo esc_attr( (string) $all['region'] ); ?>" required /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-bucket"><?php esc_html_e( 'Bucket name', 'wc-s3-download-manager' ); ?></label></th>
			<td><input type="text" class="regular-text" id="wcs3dm-bucket" name="bucket" value="<?php echo esc_attr( (string) $all['bucket'] ); ?>" required /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-endpoint"><?php esc_html_e( 'Custom endpoint (optional)', 'wc-s3-download-manager' ); ?></label></th>
			<td><input type="url" class="regular-text" id="wcs3dm-endpoint" name="endpoint" value="<?php echo esc_attr( (string) $all['endpoint'] ); ?>" placeholder="https://s3.example.com" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-prefix"><?php esc_html_e( 'Object key prefix', 'wc-s3-download-manager' ); ?></label></th>
			<td><input type="text" class="regular-text" id="wcs3dm-prefix" name="prefix" value="<?php echo esc_attr( (string) $all['prefix'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-expiry"><?php esc_html_e( 'Signed URL expiry (minutes)', 'wc-s3-download-manager' ); ?></label></th>
			<td><input type="number" min="1" max="60" id="wcs3dm-expiry" name="url_expiry_minutes" value="<?php echo esc_attr( (string) $all['url_expiry_minutes'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-storage"><?php esc_html_e( 'Storage class', 'wc-s3-download-manager' ); ?></label></th>
			<td>
				<select id="wcs3dm-storage" name="storage_class">
					<?php foreach ( array( 'STANDARD', 'STANDARD_IA', 'INTELLIGENT_TIERING', 'ONEZONE_IA', 'REDUCED_REDUNDANCY' ) as $sc ) : ?>
						<option value="<?php echo esc_attr( $sc ); ?>" <?php selected( $all['storage_class'], $sc ); ?>><?php echo esc_html( $sc ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-sse"><?php esc_html_e( 'Server-side encryption', 'wc-s3-download-manager' ); ?></label></th>
			<td>
				<select id="wcs3dm-sse" name="server_side_encryption">
					<option value="" <?php selected( $all['server_side_encryption'], '' ); ?>><?php esc_html_e( 'None (bucket default)', 'wc-s3-download-manager' ); ?></option>
					<option value="AES256" <?php selected( $all['server_side_encryption'], 'AES256' ); ?>>AES256</option>
					<option value="aws:kms" <?php selected( $all['server_side_encryption'], 'aws:kms' ); ?>>aws:kms</option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-kms"><?php esc_html_e( 'KMS key ID (optional)', 'wc-s3-download-manager' ); ?></label></th>
			<td><input type="text" class="regular-text" id="wcs3dm-kms" name="kms_key_id" value="<?php echo esc_attr( (string) $all['kms_key_id'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Path-style addressing', 'wc-s3-download-manager' ); ?></th>
			<td><label><input type="checkbox" name="path_style" value="1" <?php checked( ! empty( $all['path_style'] ) ); ?> /> <?php esc_html_e( 'Enable for some S3-compatible providers', 'wc-s3-download-manager' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-mp-threshold"><?php esc_html_e( 'Multipart threshold (bytes)', 'wc-s3-download-manager' ); ?></label></th>
			<td><input type="number" class="regular-text" id="wcs3dm-mp-threshold" name="multipart_threshold" value="<?php echo esc_attr( (string) $all['multipart_threshold'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-mp-part"><?php esc_html_e( 'Multipart part size (bytes)', 'wc-s3-download-manager' ); ?></label></th>
			<td><input type="number" class="regular-text" id="wcs3dm-mp-part" name="multipart_part_size" value="<?php echo esc_attr( (string) $all['multipart_part_size'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-cf-domain"><?php esc_html_e( 'CloudFront domain (optional)', 'wc-s3-download-manager' ); ?></label></th>
			<td><input type="text" class="regular-text" id="wcs3dm-cf-domain" name="cloudfront_domain" value="<?php echo esc_attr( (string) $all['cloudfront_domain'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-cf-kpi"><?php esc_html_e( 'CloudFront key pair ID', 'wc-s3-download-manager' ); ?></label></th>
			<td><input type="text" class="regular-text" id="wcs3dm-cf-kpi" name="cloudfront_key_pair_id" value="<?php echo esc_attr( (string) $all['cloudfront_key_pair_id'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-batch"><?php esc_html_e( 'Migration batch size', 'wc-s3-download-manager' ); ?></label></th>
			<td><input type="number" min="1" max="50" id="wcs3dm-batch" name="migration_batch_size" value="<?php echo esc_attr( (string) $all['migration_batch_size'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable local cleanup', 'wc-s3-download-manager' ); ?></th>
			<td><label><input type="checkbox" name="cleanup_enabled" value="1" <?php checked( ! empty( $all['cleanup_enabled'] ) ); ?> /> <?php esc_html_e( 'Allow verified deletion of local files (disabled by default)', 'wc-s3-download-manager' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><label for="wcs3dm-grace"><?php esc_html_e( 'Cleanup grace period (days)', 'wc-s3-download-manager' ); ?></label></th>
			<td><input type="number" min="0" id="wcs3dm-grace" name="cleanup_grace_days" value="<?php echo esc_attr( (string) $all['cleanup_grace_days'] ); ?>" /></td>
		</tr>
	</table>

	<div class="notice notice-info inline"><p>
		<?php esc_html_e( 'Credentials are stored in the WordPress database via the Options API. This is convenient but is not equivalent to a dedicated secrets manager. Prefer IAM roles or short-lived session tokens when possible.', 'wc-s3-download-manager' ); ?>
	</p></div>

	<p class="submit">
		<button type="submit" name="wcs3dm_save_settings" class="button button-primary"><?php esc_html_e( 'Save settings', 'wc-s3-download-manager' ); ?></button>
		<button type="button" class="button" id="wcs3dm-test-connection"><?php esc_html_e( 'Test Connection', 'wc-s3-download-manager' ); ?></button>
		<button type="button" class="button" id="wcs3dm-run-diagnostics"><?php esc_html_e( 'Run Diagnostics', 'wc-s3-download-manager' ); ?></button>
	</p>
	<div id="wcs3dm-connection-results"></div>
	<div id="wcs3dm-diagnostics-results"></div>
</form>
