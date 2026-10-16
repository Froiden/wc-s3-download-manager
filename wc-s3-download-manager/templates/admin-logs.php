<?php
/**
 * Logs tab.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$logs = \WCS3DM\Plugin::instance()->logger()->all( 200 );
?>
<div class="wcs3dm-card">
	<p>
		<button type="button" class="button" id="wcs3dm-clear-logs"><?php esc_html_e( 'Clear logs', 'wc-s3-download-manager' ); ?></button>
	</p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Time', 'wc-s3-download-manager' ); ?></th>
				<th><?php esc_html_e( 'Level', 'wc-s3-download-manager' ); ?></th>
				<th><?php esc_html_e( 'Message', 'wc-s3-download-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $logs ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'No log entries yet.', 'wc-s3-download-manager' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $logs as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></td>
						<td><span class="wcs3dm-level wcs3dm-level-<?php echo esc_attr( (string) ( $entry['level'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $entry['level'] ?? '' ) ); ?></span></td>
						<td><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
