<?php
/**
 * Rebrand migration manager.
 *
 * Migrates legacy "Venue Events" (ve_*) identifiers to "NetterTech Events" (nte_*)
 * on plugin activation or upgrade. All operations are idempotent.
 *
 * @package NetterTechEvents\Database
 */

declare(strict_types=1);

namespace NetterTechEvents\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Handles one-time rebrand migration from ve_* legacy identifiers to the
 * canonical `nettertech_events_*` / `nettertech_event` namespace.
 *
 * Runs on plugin activation/upgrade to rename tables, post types, taxonomy
 * slugs, options, meta keys, transients, user meta, and cron hooks from the
 * legacy "Venue Events" naming convention directly to the canonical
 * "NetterTech Events" namespace.
 *
 * Migration target evolution:
 * - v1.0.0/v1.0.1 of this plugin targeted the intermediate `nte_*` namespace
 *   here. {@see PrefixMigrationManager} (Stage 2) was then added to forward
 *   any `nte_*` data still on disk onto the canonical `nettertech_events_*`
 *   names. The Stage-2 layer remains so sites that ran a v1.0.0/v1.0.1
 *   activation continue to be migrated correctly on upgrade.
 * - v1.0.2+ targets `nettertech_events_*` directly here. For users coming
 *   from the original `venue-events` plugin (legacy `ve_*` / `venue_events_*`
 *   data), this single pass lands their data on the canonical names without
 *   a transient `nte_*` intermediate. Stage 2 ({@see PrefixMigrationManager})
 *   still runs after this and no-ops for these users because Stage 1 produced
 *   no `nte_*` source keys for it to find.
 *
 * The class is therefore deliberately free of `nte_*` literal rename targets
 * for everything except the table prefix (which is the physical schema name
 * and is kept stable across the migration; {@see PrefixMigrationManager}
 * still handles the `nte_*` table-prefix rename separately as part of
 * Stage 2).
 *
 * @since 1.2.0
 */
class MigrationManager {

	/**
	 * Option name for the completion marker.
	 *
	 * Uses the canonical plugin prefix (`nettertech_events_*`) because this
	 * option records the migration's own state — it is NOT a legacy/target
	 * key migrated by the migration sequence itself.
	 *
	 * @var string
	 */
	private const COMPLETION_OPTION = 'nettertech_events_legacy_rebrand_complete';

	/**
	 * Option name for the migration log. See COMPLETION_OPTION for naming rationale.
	 *
	 * @var string
	 */
	private const LOG_OPTION = 'nettertech_events_legacy_rebrand_log';

	/**
	 * Legacy option names that need to be migrated to the v1.0.2 canonical-prefix
	 * versions. Populated by v1.0.0/1.0.1 installs that ran this migration before
	 * the rebrand-state-option rename. Each row: old_key => new_key.
	 *
	 * @var array<string, string>
	 */
	private const STATE_OPTION_TRANSITION_V102 = array(
		'nte_rebrand_migration_complete' => self::COMPLETION_OPTION,
		'nte_rebrand_migration_log'      => self::LOG_OPTION,
	);

	/**
	 * Canonical do_action hook fired after the migration completes.
	 *
	 * @var string
	 */
	private const COMPLETE_ACTION = 'nettertech_events_legacy_rebrand_complete';

	/**
	 * Legacy table prefix (without wp_ prefix).
	 *
	 * @var string
	 */
	private const OLD_TABLE_PREFIX = 've_';

	/**
	 * New table prefix (without wp_ prefix).
	 *
	 * @var string
	 */
	private const NEW_TABLE_PREFIX = 'nte_';

	/**
	 * Known table names to migrate (without any prefix).
	 *
	 * @var array<string>
	 */
	private const TABLE_NAMES = array(
		'events',
		'occurrences',
		'ticket_types',
		'attendees',
		'tickets',
		'categories',
		'tags',
		'event_categories',
		'event_tags',
		'organizers',
		'event_organizers',
		'series',
		'event_revisions',
		'activity_log',
		'reminder_log',
		'waitlist',
	);

	/**
	 * Known option keys to migrate: old_name => new_name.
	 *
	 * Targets are the canonical `nettertech_events_*` names that the rest of
	 * the plugin actually reads (see Activator, Router, Schema, CacheManager,
	 * SettingsPage). v1.0.0/v1.0.1 targeted intermediate `nte_*` names; that
	 * layer is now handled by {@see PrefixMigrationManager} for users
	 * upgrading from those releases.
	 *
	 * @var array<string, string>
	 */
	private const OPTION_MAP = array(
		'venue_events_settings'            => 'nettertech_events_settings',
		'venue_events_db_version'          => 'nettertech_events_db_version',
		'venue_events_flush_rewrite_rules' => 'nettertech_events_flush_rewrite_rules',
		'venue_events_cache_version'       => 'nettertech_events_cache_version',
		'venue_events_activated'           => 'nettertech_events_activated',
	);

	/**
	 * Entry point: check if migration is needed and run it.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		// v1.0.2 upgrade-path: forward old completion/log markers written by
		// v1.0.0/1.0.1 installs onto the canonical-prefix option names.
		self::migrate_state_options_v102();

		// Already completed — nothing to do.
		if ( get_option( self::COMPLETION_OPTION ) ) {
			return;
		}

		// Detect if there is anything to migrate.
		if ( ! self::needs_migration() ) {
			return;
		}

		self::run_migration();
	}

	/**
	 * Forward v1.0.0/1.0.1 legacy state-option keys onto v1.0.2 canonical-prefix keys.
	 *
	 * Idempotent: only writes the new key when (a) the legacy key has a value
	 * AND (b) the new key has not already been written. Always deletes the
	 * legacy key after a successful forward.
	 *
	 * Without this step, a site that ran the ve_*→nte_* migration under
	 * v1.0.0/1.0.1 would silently re-evaluate `needs_migration()` on every
	 * page load — `needs_migration()` returns false (the rebrand already
	 * happened) so the new completion marker is never written, and the
	 * `_legacy_rebrand_log` audit-trail option is lost.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	private static function migrate_state_options_v102(): void {
		foreach ( self::STATE_OPTION_TRANSITION_V102 as $legacy_key => $new_key ) {
			$legacy_value = get_option( $legacy_key, false );

			// `false` is the WP missing-sentinel and the completion marker is
			// only ever set to `true`, so `false` means "legacy key not present".
			if ( false === $legacy_value ) {
				continue;
			}

			// Don't clobber a value already at the new key.
			if ( false !== get_option( $new_key, false ) ) {
				delete_option( $legacy_key );
				continue;
			}

			update_option( $new_key, $legacy_value, false );
			delete_option( $legacy_key );
		}
	}

	/**
	 * Detect whether legacy ve_* data exists.
	 *
	 * Checks for: ve_* tables, venue_events_settings option, or
	 * posts with legacy post types (ve_event, venue_event).
	 *
	 * @return bool True if legacy data is detected.
	 */
	private static function needs_migration(): bool {
		global $wpdb;

		// Check for any ve_* tables.
		$old_prefix = $wpdb->prefix . self::OLD_TABLE_PREFIX;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prefix from trusted constant; one-time migration check.
		$tables = $wpdb->get_var( "SHOW TABLES LIKE '{$old_prefix}%'" );
		if ( null !== $tables ) {
			return true;
		}

		// Check for legacy options.
		if ( false !== get_option( 'venue_events_settings', false ) ) {
			return true;
		}

		// Check for legacy post types.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time migration check.
		$legacy_count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('ve_event', 'venue_event')"
		);
		if ( (int) $legacy_count > 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Execute the full migration sequence.
	 *
	 * @return void
	 */
	private static function run_migration(): void {
		$log = array(
			'migrated_at'        => gmdate( 'Y-m-d H:i:s' ),
			'tables_renamed'     => array(),
			'posts_updated'      => 0,
			'terms_updated'      => 0,
			'options_renamed'    => array(),
			'meta_keys_updated'  => 0,
			'transients_renamed' => 0,
			'user_meta_updated'  => 0,
			'cron_hooks_updated' => 0,
		);

		$log['tables_renamed']     = self::migrate_tables();
		$log['posts_updated']      = self::migrate_post_type();
		$log['terms_updated']      = self::migrate_taxonomy();
		$log['options_renamed']    = self::migrate_options();
		$log['meta_keys_updated']  = self::migrate_post_meta();
		$log['transients_renamed'] = self::migrate_transients();
		$log['user_meta_updated']  = self::migrate_user_meta();
		$log['cron_hooks_updated'] = self::migrate_cron_hooks();

		// Trigger rewrite flush on next page load. Uses the canonical
		// option name that Router::maybe_flush_rewrite_rules() reads.
		update_option( 'nettertech_events_flush_rewrite_rules', true );

		// Write the migration log.
		update_option( self::LOG_OPTION, $log, false );

		// Mark migration as complete.
		update_option( self::COMPLETION_OPTION, true, false );

		/**
		 * Fires after all rebrand migrations are complete.
		 *
		 * Extension plugins can hook here to run their own ve_* → nte_* migrations.
		 *
		 * @since 1.2.0
		 *
		 * @param array $log Migration log with counts and renamed items.
		 */
		do_action( self::COMPLETE_ACTION, $log );
	}

	/**
	 * Rename ve_* tables to nte_*.
	 *
	 * For each known table name, renames only if the old table exists and the
	 * new table does not. If both exist, skips to avoid data loss.
	 *
	 * @return array<string> List of table names that were renamed (short names, without prefix).
	 */
	private static function migrate_tables(): array {
		global $wpdb;

		$renamed = array();

		foreach ( self::TABLE_NAMES as $name ) {
			$old_table = $wpdb->prefix . self::OLD_TABLE_PREFIX . $name;
			$new_table = $wpdb->prefix . self::NEW_TABLE_PREFIX . $name;

			$old_exists = self::table_exists( $old_table );
			$new_exists = self::table_exists( $new_table );

			if ( $old_exists && ! $new_exists ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from trusted constants; one-time migration.
				$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
				$renamed[] = $name;
			}
			// If both exist, skip — already partially migrated, don't drop data.
		}

		return $renamed;
	}

	/**
	 * Check if a database table exists.
	 *
	 * @param string $table_name Full table name including prefix.
	 * @return bool True if the table exists.
	 */
	private static function table_exists( string $table_name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from trusted source; one-time migration check.
		$result = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" );
		return null !== $result;
	}

	/**
	 * Migrate legacy post types to nte_event.
	 *
	 * Updates posts with post_type 've_event' or 'venue_event' to 'nte_event'.
	 *
	 * @return int Number of posts updated.
	 */
	private static function migrate_post_type(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time rebrand migration; bulk post_type update.
		$updated = $wpdb->query(
			"UPDATE {$wpdb->posts} SET post_type = 'nte_event' WHERE post_type IN ('ve_event', 'venue_event')"
		);

		return (int) $updated;
	}

	/**
	 * Migrate legacy taxonomy slug to nte_event_category.
	 *
	 * Updates term_taxonomy records from 'venue_event_category' to 'nte_event_category'.
	 *
	 * @return int Number of term_taxonomy rows updated.
	 */
	private static function migrate_taxonomy(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time rebrand migration; bulk taxonomy slug update.
		$updated = $wpdb->query(
			"UPDATE {$wpdb->term_taxonomy} SET taxonomy = 'nte_event_category' WHERE taxonomy = 'venue_event_category'"
		);

		return (int) $updated;
	}

	/**
	 * Migrate known legacy option keys.
	 *
	 * For each known mapping, copies the old value to the new key and deletes
	 * the old key. Skips if the old key does not exist or the new key already exists.
	 *
	 * @return array<string> List of old option names that were migrated.
	 */
	private static function migrate_options(): array {
		$migrated = array();

		foreach ( self::OPTION_MAP as $old_key => $new_key ) {
			$old_value = get_option( $old_key, null );

			// Old option does not exist — nothing to migrate.
			if ( null === $old_value ) {
				continue;
			}

			// New option already exists — don't overwrite.
			if ( false !== get_option( $new_key, false ) ) {
				// Clean up old key even if new already exists.
				delete_option( $old_key );
				$migrated[] = $old_key;
				continue;
			}

			// Copy value and remove old.
			update_option( $new_key, $old_value );
			delete_option( $old_key );
			$migrated[] = $old_key;
		}

		return $migrated;
	}

	/**
	 * Migrate post meta keys from _ve_* to _nte_*.
	 *
	 * Only updates meta rows where no _nte_ version of that key already exists
	 * for the same post_id. Uses a LEFT JOIN to avoid overwriting.
	 *
	 * @return int Number of meta rows updated.
	 */
	private static function migrate_post_meta(): int {
		global $wpdb;

		// Update _ve_* meta keys to _nte_* only where no _nte_ version exists
		// for the same post_id + derived key combination.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb property; one-time rebrand migration.
		$updated = $wpdb->query(
			"UPDATE {$wpdb->postmeta} pm
			LEFT JOIN {$wpdb->postmeta} existing
				ON existing.post_id = pm.post_id
				AND existing.meta_key = CONCAT('_nte_', SUBSTRING(pm.meta_key, 5))
			SET pm.meta_key = CONCAT('_nte_', SUBSTRING(pm.meta_key, 5))
			WHERE pm.meta_key LIKE '_ve\_%'
				AND existing.meta_id IS NULL"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return (int) $updated;
	}

	/**
	 * Migrate transient option names from ve_* to nte_*.
	 *
	 * Renames both _transient_ve_* and _transient_timeout_ve_* entries.
	 *
	 * @return int Number of transient option rows updated.
	 */
	private static function migrate_transients(): int {
		global $wpdb;

		$total = 0;

		// Rename _transient_ve_* → _transient_nettertech_events_*.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time rebrand migration; bulk transient rename.
		$updated = $wpdb->query(
			"UPDATE {$wpdb->options}
			SET option_name = CONCAT('_transient_nettertech_events_', SUBSTRING(option_name, 15))
			WHERE option_name LIKE '_transient_ve\_%'"
		);
		$total  += (int) $updated;

		// Rename _transient_timeout_ve_* → _transient_timeout_nettertech_events_*.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time rebrand migration; bulk transient rename.
		$updated = $wpdb->query(
			"UPDATE {$wpdb->options}
			SET option_name = CONCAT('_transient_timeout_nettertech_events_', SUBSTRING(option_name, 23))
			WHERE option_name LIKE '_transient_timeout_ve\_%'"
		);
		$total  += (int) $updated;

		return $total;
	}

	/**
	 * Migrate user meta keys from ve_* to nte_*.
	 *
	 * @return int Number of user meta rows updated.
	 */
	private static function migrate_user_meta(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time rebrand migration; bulk user meta key rename.
		$updated = $wpdb->query(
			"UPDATE {$wpdb->usermeta}
			SET meta_key = CONCAT('nte_', SUBSTRING(meta_key, 4))
			WHERE meta_key LIKE 've\_%'"
		);

		return (int) $updated;
	}

	/**
	 * Migrate cron hooks from ve_* to nte_* prefix.
	 *
	 * Unschedules events with ve_* hook names and reschedules them with nte_*.
	 *
	 * @return int Number of cron hooks migrated.
	 */
	private static function migrate_cron_hooks(): int {
		$cron_array = _get_cron_array();
		if ( empty( $cron_array ) ) {
			return 0;
		}

		$migrated_count = 0;
		$modified       = false;

		foreach ( $cron_array as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				if ( 0 !== strpos( $hook, 've_' ) ) {
					continue;
				}

				$new_hook = 'nte_' . substr( $hook, 3 );

				// Move each scheduled instance to the new hook name.
				foreach ( $events as $hash => $event ) {
					$args     = $event['args'] ?? array();
					$schedule = $event['schedule'] ?? false;
					$interval = $event['interval'] ?? 0;

					// Remove old entry.
					unset( $cron_array[ $timestamp ][ $hook ][ $hash ] );

					// Add new entry.
					$cron_array[ $timestamp ][ $new_hook ][ $hash ] = array(
						'schedule' => $schedule,
						'args'     => $args,
					);

					if ( $interval > 0 ) {
						$cron_array[ $timestamp ][ $new_hook ][ $hash ]['interval'] = $interval;
					}

					++$migrated_count;
				}

				// Clean up empty hook entry.
				if ( empty( $cron_array[ $timestamp ][ $hook ] ) ) {
					unset( $cron_array[ $timestamp ][ $hook ] );
				}

				$modified = true;
			}

			// Clean up empty timestamp entry.
			if ( empty( $cron_array[ $timestamp ] ) ) {
				unset( $cron_array[ $timestamp ] );
			}
		}

		if ( $modified ) {
			_set_cron_array( $cron_array );
		}

		return $migrated_count;
	}
}
