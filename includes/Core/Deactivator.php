<?php
/**
 * Plugin deactivation handler.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin deactivation.
 *
 * Note: Tables and data are NOT removed on deactivation.
 * Use uninstall.php for complete removal.
 *
 * @since 0.8.0
 * @api
 */
class Deactivator {

	/**
	 * Run deactivation routine.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		self::clear_scheduled_events();
		self::flush_rewrite_rules();

		/**
		 * Fires after the plugin has been deactivated.
		 */
		do_action( 'nettertech_events_deactivated' );
	}

	/**
	 * Clear scheduled cron events.
	 *
	 * @return void
	 */
	private static function clear_scheduled_events(): void {
		wp_clear_scheduled_hook( 'nettertech_events_generate_occurrences' );
		wp_clear_scheduled_hook( 'nettertech_events_daily_cleanup' );
		wp_clear_scheduled_hook( 'nettertech_events_send_reminder_emails' );
		wp_clear_scheduled_hook( 'nettertech_events_purge_activity_log_pii' );
		wp_clear_scheduled_hook( 'nettertech_events_sweep_expired_reservations' );
		wp_clear_scheduled_hook( 'nettertech_events_extend_horizons_batch' );
		wp_clear_scheduled_hook( 'nettertech_events_ical_static_regenerate' );
	}

	/**
	 * Flush rewrite rules.
	 *
	 * @return void
	 */
	private static function flush_rewrite_rules(): void {
		flush_rewrite_rules();
	}
}
