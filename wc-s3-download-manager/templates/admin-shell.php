<?php
/**
 * Admin shell with tabs.
 *
 * @package WCS3DM
 * @var string $tab Current tab.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tabs = array(
	'overview'     => __( 'Overview', 'wc-s3-download-manager' ),
	'settings'     => __( 'S3 Settings', 'wc-s3-download-manager' ),
	'browse'       => __( 'Browse S3', 'wc-s3-download-manager' ),
	'uploads'      => __( 'Uploads', 'wc-s3-download-manager' ),
	'migration'    => __( 'Bulk Migration', 'wc-s3-download-manager' ),
	'verification' => __( 'Verification', 'wc-s3-download-manager' ),
	'cleanup'      => __( 'Local Cleanup', 'wc-s3-download-manager' ),
	'logs'         => __( 'Logs', 'wc-s3-download-manager' ),
);

$base = admin_url( 'admin.php?page=wcs3dm' );
?>
<div class="wrap wcs3dm-wrap">
	<h1><?php echo esc_html__( 'WooCommerce S3 Download Manager', 'wc-s3-download-manager' ); ?></h1>
	<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
		<?php foreach ( $tabs as $slug => $label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base ) ); ?>" class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="wcs3dm-panel">
		<?php
		switch ( $tab ) {
			case 'settings':
				include WCS3DM_PATH . 'templates/admin-settings.php';
				break;
			case 'browse':
				include WCS3DM_PATH . 'templates/admin-browse.php';
				break;
			case 'uploads':
				include WCS3DM_PATH . 'templates/admin-uploads.php';
				break;
			case 'migration':
				include WCS3DM_PATH . 'templates/admin-migration.php';
				break;
			case 'verification':
				include WCS3DM_PATH . 'templates/admin-verification.php';
				break;
			case 'cleanup':
				include WCS3DM_PATH . 'templates/admin-cleanup.php';
				break;
			case 'logs':
				include WCS3DM_PATH . 'templates/admin-logs.php';
				break;
			default:
				include WCS3DM_PATH . 'templates/admin-overview.php';
		}
		?>
	</div>
</div>
