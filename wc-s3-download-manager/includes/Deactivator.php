<?php
/**
 * Plugin deactivation.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Clears scheduled actions on deactivation. Leaves data and S3 objects intact.
 */
final class Deactivator {

	/**
	 * Run deactivation routines.
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Background_Jobs::HOOK_MIGRATE_BATCH );
			as_unschedule_all_actions( Background_Jobs::HOOK_CLEANUP_BATCH );
			as_unschedule_all_actions( Background_Jobs::HOOK_VERIFY_BATCH );
		}
		wp_clear_scheduled_hook( 'wcs3dm_cron_fallback' );
		flush_rewrite_rules();
	}
}
