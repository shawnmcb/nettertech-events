<?php
/**
 * Plugin uninstall handler.
 *
 * This file is executed when the plugin is deleted through
 * the WordPress admin. It removes all plugin data if the
 * user has opted in to data removal.
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

// Exit if not being called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// Check if user wants data deleted.
$settings    = get_option( 'nettertech_events_settings', array() );
$delete_data = isset( $settings['delete_data_on_uninstall'] ) && $settings['delete_data_on_uninstall'];

if ( ! $delete_data ) {
	// User chose to keep data.
	return;
}

// Load plugin constants.
define( 'NETTERTECH_EVENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Load schema class for table removal.
require_once NETTERTECH_EVENTS_PLUGIN_DIR . 'includes/Database/Schema.php';
require_once NETTERTECH_EVENTS_PLUGIN_DIR . 'includes/Core/ShadowPostType.php';

// Only clean up Pro tables if Pro was previously installed.
if ( get_option( 'nettertech_events_pro_db_version' ) ) {
	// Pro-specific cleanup: drop Pro tables and remove Pro options.
	// Pro plugin registers its own Schema class; this scaffold ensures
	// base uninstall never fails when Pro has been active.
	delete_option( 'nettertech_events_pro_db_version' );
	delete_option( 'nettertech_events_pro_settings' );
	delete_option( 'nettertech_events_pro_activated' );
}

// Drop all base tables.
NetterTechEvents\Database\Schema::drop_tables();

// Remove all base options.
$option_names = array(
	'nettertech_events_settings',
	'nettertech_events_version',
	'nettertech_events_activated',
	'nettertech_events_db_version',
	'nettertech_events_migration_log',
	'nettertech_events_cache_version',
	'nettertech_events_flush_rewrite_rules',
);

foreach ( $option_names as $option_name ) {
	delete_option( $option_name );
}

// Remove transients.
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		'_transient_nettertech_events_%'
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		'_transient_timeout_nettertech_events_%'
	)
);

// Remove user meta.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		'nettertech_events_%'
	)
);

// Remove shadow posts and their associated post meta.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall runs once; $wpdb direct query is the correct pattern for schema teardown.
$shadow_post_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
		NetterTechEvents\Core\ShadowPostType::POST_TYPE
	)
);

if ( $shadow_post_ids ) {
	$shadow_id_placeholders = implode( ',', array_fill( 0, count( $shadow_post_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholder count from array_fill; string cannot be parameterized.
			"DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$shadow_id_placeholders})",
			...$shadow_post_ids
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholder count from array_fill; string cannot be parameterized.
			"DELETE FROM {$wpdb->posts} WHERE ID IN ({$shadow_id_placeholders})",
			...$shadow_post_ids
		)
	);
}

// Clear all scheduled cron events.
wp_clear_scheduled_hook( 'nettertech_events_generate_occurrences' );
wp_clear_scheduled_hook( 'nettertech_events_daily_cleanup' );
wp_clear_scheduled_hook( 'nettertech_events_send_reminder_emails' );
wp_clear_scheduled_hook( 'nettertech_events_purge_activity_log_pii' );
wp_clear_scheduled_hook( 'nettertech_events_sweep_expired_reservations' );
wp_clear_scheduled_hook( 'nettertech_events_extend_horizons_batch' );

// Flush rewrite rules.
flush_rewrite_rules();
