<?php
/**
 * Plugin activation handler.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Database\MigrationManager;
use NetterTechEvents\Database\PrefixMigrationManager;
use NetterTechEvents\Database\Schema;

/**
 * Handles plugin activation.
 *
 * @since 0.8.0
 * @api
 */
class Activator {

	/**
	 * Minimum PHP version required.
	 *
	 * @var string
	 */
	private const MIN_PHP_VERSION = '8.2';

	/**
	 * Minimum WordPress version required.
	 *
	 * @var string
	 */
	private const MIN_WP_VERSION = '6.0';

	/**
	 * Run activation routine.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::check_requirements();
		MigrationManager::maybe_migrate();
		PrefixMigrationManager::maybe_migrate();
		self::create_tables();
		self::set_default_options();
		self::schedule_cron_events();
		self::flush_rewrite_rules();

		// Store activation time and version.
		update_option( 'nettertech_events_activated', time() );
		update_option( 'nettertech_events_version', NETTERTECH_EVENTS_VERSION );

		/**
		 * Fires after the plugin has been activated.
		 */
		do_action( 'nettertech_events_activated' );
	}

	/**
	 * Check system requirements.
	 *
	 * @return void
	 */
	private static function check_requirements(): void {
		// @phpstan-ignore-next-line if.alwaysFalse -- Runtime check required; PHP version may change across deployments.
		if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
			wp_die(
				sprintf(
					/* translators: 1: Required PHP version, 2: Current PHP version */
					esc_html__( 'NetterTech Events requires PHP %1$s or higher. You are running PHP %2$s.', 'nettertech-events' ),
					esc_html( self::MIN_PHP_VERSION ),
					esc_html( PHP_VERSION )
				),
				esc_html__( 'Plugin Activation Error', 'nettertech-events' ),
				array( 'back_link' => true )
			);
		}

		global $wp_version;
		if ( version_compare( $wp_version, self::MIN_WP_VERSION, '<' ) ) {
			wp_die(
				sprintf(
					/* translators: 1: Required WordPress version, 2: Current WordPress version */
					esc_html__( 'NetterTech Events requires WordPress %1$s or higher. You are running WordPress %2$s.', 'nettertech-events' ),
					esc_html( self::MIN_WP_VERSION ),
					esc_html( $wp_version )
				),
				esc_html__( 'Plugin Activation Error', 'nettertech-events' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Create database tables.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		require_once NETTERTECH_EVENTS_PLUGIN_DIR . 'includes/Database/Schema.php';
		Schema::create_tables();
		// Stamp schema version for fresh installs (no migrations to run).
		update_option( 'nettertech_events_db_version', Schema::DB_VERSION );
	}

	/**
	 * Set default plugin options.
	 *
	 * @return void
	 */
	private static function set_default_options(): void {
		$defaults = array(
			'nettertech_events_settings' => array(
				'default_view'             => 'month',
				'events_per_page'          => 10,
				'currency'                 => 'USD',
				'timezone'                 => wp_timezone_string(),
				'enable_rsvp'              => true,
				'enable_tickets'           => true,
				'occurrence_horizon'       => 365, // Days to generate occurrences ahead.
				'ical_feed_horizon_days'   => 730, // Forward UNTIL cap for open-ended iCal RRULEs.
				'delete_data_on_uninstall' => false,
				'show_frontend_branding'   => false,
				'events_base_path'         => 'events',
				'events_archive_path'      => '', // Empty = use {base}/archive pattern.
			),
		);

		foreach ( $defaults as $option => $value ) {
			if ( false === get_option( $option ) ) {
				add_option( $option, $value );
			}
		}
	}

	/**
	 * Schedule cron events.
	 *
	 * @return void
	 */
	private static function schedule_cron_events(): void {
		// Schedule occurrence generation for recurring events.
		if ( ! wp_next_scheduled( 'nettertech_events_generate_occurrences' ) ) {
			wp_schedule_event( time(), 'daily', 'nettertech_events_generate_occurrences' );
		}
	}

	/**
	 * Flush rewrite rules.
	 *
	 * @return void
	 */
	private static function flush_rewrite_rules(): void {
		// Set flag to flush rules on next init.
		update_option( 'nettertech_events_flush_rewrite_rules', true );
	}
}
