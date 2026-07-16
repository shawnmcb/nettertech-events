<?php
/**
 * Prefix migration manager (Step 2: nte_* → nettertech_events_*).
 *
 * Runs on plugin activation/upgrade after the legacy ve_* → nte_* migration
 * (handled by MigrationManager). Renames tables, post types, taxonomies,
 * options, post/user/order-item meta keys, transients (cache vs stateful),
 * and reschedules cron hooks. All operations are idempotent.
 *
 * @package NetterTechEvents\Database
 */

declare(strict_types=1);

namespace NetterTechEvents\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Step 2 prefix migration: nte_* → nettertech_events_*.
 *
 * Sites that were on the legacy ve_* schema first run MigrationManager
 * (Step 1: ve_* → nte_*), then run this class (Step 2: nte_* →
 * nettertech_events_*) to land on the WP.org-compliant prefix. The
 * two-step chain is preserved on purpose — sites already migrated past
 * Step 1 should not see a destructive direct ve_* → nettertech_events_*
 * substitution, since they may carry hand-curated nte_* data.
 *
 * Conflict rules:
 *   - old exists, new missing → RENAME / UPDATE
 *   - old missing, new exists → skip (already migrated or never used)
 *   - both exist                → log conflict, ABORT (do not merge)
 *
 * Concurrency: a transient lock prevents parallel runs across processes
 * (admin reload, REST hit, cron). Lock TTL = 600 seconds.
 *
 * Rollback: MySQL DDL (RENAME TABLE) auto-commits, so there is no
 * transactional rollback. Idempotency + step logs + verify() are the
 * recovery model. Operators are expected to take a `wp db export` snapshot
 * before running on production data.
 *
 * @since 1.3.0
 */
class PrefixMigrationManager {

	/**
	 * Completion option (Step 2).
	 *
	 * @var string
	 */
	public const COMPLETION_OPTION = 'nettertech_events_prefix_migration_complete';

	/**
	 * Per-phase log option (counts + errors).
	 *
	 * @var string
	 */
	public const LOG_OPTION = 'nettertech_events_prefix_migration_log';

	/**
	 * Lock transient name. Prevents parallel migration runs.
	 *
	 * @var string
	 */
	public const LOCK_TRANSIENT = 'nettertech_events_prefix_migration_lock';

	/**
	 * Lock TTL in seconds.
	 *
	 * @var int
	 */
	private const LOCK_TTL = 600;

	/**
	 * Old table prefix (after wp_ prefix).
	 *
	 * @var string
	 */
	private const OLD_TABLE_PREFIX = 'nte_';

	/**
	 * New table prefix (after wp_ prefix).
	 *
	 * @var string
	 */
	private const NEW_TABLE_PREFIX = 'nettertech_events_';

	/**
	 * Base plugin's owned table short names.
	 *
	 * @var array<string>
	 */
	private const TABLE_NAMES = array(
		'series',
		'events',
		'occurrences',
		'spaces',
		'ticket_types',
		'attendees',
		'tickets',
		'attendee_fields',
		'attendee_field_values',
		'organizers',
		'event_organizers',
		'categories',
		'event_categories',
		'tags',
		'event_tags',
		'activity_log',
		'reminder_log',
		'reservations',
		'waitlist',
		'event_revisions',
	);

	/**
	 * Cached legacy-prefix map loaded from config/legacy-prefix-map.json.
	 *
	 * Populated lazily on first access via load_legacy_map(). Holds the full
	 * decoded JSON structure (option_map, post_meta_map, etc.). Replacing the
	 * prior per-section `const *_MAP` literals lets the plugin source itself
	 * carry zero `nte_*` string literals; the legacy identifiers live only in
	 * the data file (which is what the migration semantically reads).
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $legacy_map = null;

	/**
	 * Path to the legacy-prefix map JSON, relative to plugin root.
	 *
	 * @var string
	 */
	private const LEGACY_MAP_PATH = 'config/legacy-prefix-map.json';

	/**
	 * Load (and memoize) the legacy-prefix map from JSON.
	 *
	 * Map sections:
	 *   option_map / post_meta_map / order_item_meta_map (assoc, old→new)
	 *   post_type_map / taxonomy_map / shortcode_map     (assoc, old→new)
	 *   cron_hook_map                                    (assoc, old→new)
	 *   cache_transient_prefixes / stateful_transient_prefixes / obsolete_cron_hooks (list)
	 *
	 * Idempotent: the file load + json_decode runs at most once per request.
	 * Throws a runtime exception when the file is missing or unparseable; the
	 * migration is a non-startable operation in that state and silent fallback
	 * would surface as data corruption.
	 *
	 * @throws \RuntimeException If the JSON file is missing, unreadable, or unparseable.
	 *
	 * @return array<string, mixed>
	 */
	private static function load_legacy_map(): array {
		if ( null !== self::$legacy_map ) {
			return self::$legacy_map;
		}

		$path = defined( 'NETTERTECH_EVENTS_PLUGIN_DIR' )
			? NETTERTECH_EVENTS_PLUGIN_DIR . self::LEGACY_MAP_PATH
			: dirname( __DIR__, 2 ) . '/' . self::LEGACY_MAP_PATH;

		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( esc_html( 'Legacy prefix map not readable: ' . $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin-bundled config file, not a remote resource; WP_Filesystem is unavailable this early in activation.
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			throw new \RuntimeException( esc_html( 'Could not read legacy prefix map: ' . $path ) );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( esc_html( 'Legacy prefix map is not valid JSON: ' . $path ) );
		}

		self::$legacy_map = $decoded;
		return self::$legacy_map;
	}

	/**
	 * Get a named assoc-map section from the legacy-prefix data file.
	 *
	 * @param string $section Section key (e.g. 'option_map').
	 * @return array<string, string>
	 */
	private static function map( string $section ): array {
		$data = self::load_legacy_map();
		if ( ! isset( $data[ $section ] ) || ! is_array( $data[ $section ] ) ) {
			return array();
		}
		// @phpstan-var array<string, string> $section_data
		$section_data = $data[ $section ];
		return $section_data;
	}

	/**
	 * Get a named list section from the legacy-prefix data file.
	 *
	 * @param string $section Section key (e.g. 'cache_transient_prefixes').
	 * @return array<int, string>
	 */
	private static function list_section( string $section ): array {
		$data = self::load_legacy_map();
		if ( ! isset( $data[ $section ] ) || ! is_array( $data[ $section ] ) ) {
			return array();
		}
		// @phpstan-var array<int, string> $section_data
		$section_data = array_values( $data[ $section ] );
		return $section_data;
	}

	/**
	 * Entry point: run Step 2 migration if needed.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		if ( get_option( self::COMPLETION_OPTION ) ) {
			return;
		}

		if ( ! self::needs_migration() ) {
			update_option( self::COMPLETION_OPTION, true, false );
			return;
		}

		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			$result = self::run_migration();
			update_option( self::LOG_OPTION, $result, false );

			if ( empty( $result['errors'] ) ) {
				update_option( self::COMPLETION_OPTION, true, false );

				/**
				 * Fires after Step 2 prefix migration completes successfully.
				 *
				 * Add-on plugins (Pro, Rentals, Seating) listen on this hook
				 * to run their own Step 2 migrations.
				 *
				 * @since 1.0.2
				 *
				 * @param array $log Migration log with per-phase counts.
				 */
				do_action( 'nettertech_events_prefix_migration_complete', $result );
			}
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Detect whether any Step 2 migration is needed.
	 *
	 * @return bool True if any old-prefix DB artifact exists.
	 */
	private static function needs_migration(): bool {
		global $wpdb;

		$old_prefix = $wpdb->prefix . self::OLD_TABLE_PREFIX;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Trusted constant; one-time migration check.
		$has_old_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old_prefix ) . '%' ) );
		if ( null !== $has_old_table ) {
			return true;
		}

		foreach ( self::map( 'option_map' ) as $old_key => $_new ) {
			if ( false !== get_option( $old_key, false ) ) {
				return true;
			}
		}

		// Check for any legacy post_type values listed in the data file
		// (the data file is the source of truth — typically includes 'nte_event').
		$legacy_post_types = array_keys( self::map( 'post_type_map' ) );
		foreach ( $legacy_post_types as $legacy_post_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time migration check; post_type bound via $wpdb->prepare placeholder.
			$legacy_post_type_count = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $legacy_post_type )
			);
			if ( (int) $legacy_post_type_count > 0 ) {
				return true;
			}
		}

		foreach ( self::map( 'cron_hook_map' ) as $old_hook => $_n ) {
			if ( wp_next_scheduled( $old_hook ) ) {
				return true;
			}
		}
		foreach ( self::list_section( 'obsolete_cron_hooks' ) as $old_hook ) {
			if ( wp_next_scheduled( $old_hook ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Acquire migration lock via transient. Returns false if already held.
	 *
	 * @return bool True if lock acquired.
	 */
	private static function acquire_lock(): bool {
		if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}
		set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_TTL );
		return true;
	}

	/**
	 * Release migration lock.
	 *
	 * @return void
	 */
	private static function release_lock(): void {
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Execute the full Step 2 migration.
	 *
	 * @return array{tables: array<string, mixed>, post_types: int, taxonomies: int, options: array<string, mixed>, post_meta: int, user_meta: int, order_item_meta: int, transients: array{deleted: int, migrated: int}, cron: array<string, mixed>, errors: array<int, string>, started_at: string, finished_at: string}
	 */
	private static function run_migration(): array {
		$log = array(
			'started_at'      => gmdate( 'Y-m-d H:i:s' ),
			'tables'          => array(
				'renamed'   => array(),
				'skipped'   => array(),
				'conflicts' => array(),
			),
			'post_types'      => 0,
			'taxonomies'      => 0,
			'options'         => array(
				'renamed' => array(),
				'skipped' => array(),
			),
			'post_meta'       => 0,
			'user_meta'       => 0,
			'order_item_meta' => 0,
			'transients'      => array(
				'deleted'  => 0,
				'migrated' => 0,
			),
			'cron'            => array(
				'rescheduled' => array(),
				'cleared'     => array(),
			),
			'shortcodes'      => 0,
			'errors'          => array(),
			'finished_at'     => '',
		);

		// 1. Tables FIRST. Conflict aborts the rest.
		$log['tables'] = self::migrate_tables();
		if ( ! empty( $log['tables']['conflicts'] ) ) {
			$log['errors'][]    = sprintf(
				'Aborted: table conflict (both old and new exist). Conflicts: %s',
				wp_json_encode( $log['tables']['conflicts'] )
			);
			$log['finished_at'] = gmdate( 'Y-m-d H:i:s' );
			return $log;
		}

		// 2. Post types + taxonomies (term relationships preserved via term_taxonomy_id).
		$log['post_types'] = self::migrate_post_types();
		$log['taxonomies'] = self::migrate_taxonomies();

		// 3. Options (exact match per key).
		$log['options'] = self::migrate_options();

		// 4. Meta tables.
		$log['post_meta']       = self::migrate_post_meta();
		$log['user_meta']       = self::migrate_user_meta();
		$log['order_item_meta'] = self::migrate_order_item_meta();

		// 5. Transients (cache delete vs stateful migrate).
		$log['transients'] = self::migrate_transients();

		// 6. Cron (clear old, schedule new with same cadence).
		$log['cron'] = self::migrate_cron();

		// 7. Shortcode rewrite in post_content (clean cutover).
		$log['shortcodes'] = self::migrate_post_content_shortcodes();

		// 8. Trigger rewrite flush on next page load (CPT slug changed).
		update_option( 'nettertech_events_flush_rewrite_rules', true );

		$log['finished_at'] = gmdate( 'Y-m-d H:i:s' );
		return $log;
	}

	/**
	 * Rename custom tables.
	 *
	 * Conflict rule: both exist → log conflict, do not merge.
	 *
	 * @return array{renamed: array<string>, skipped: array<string>, conflicts: array<string>}
	 */
	private static function migrate_tables(): array {
		global $wpdb;
		$out = array(
			'renamed'   => array(),
			'skipped'   => array(),
			'conflicts' => array(),
		);

		foreach ( self::TABLE_NAMES as $name ) {
			$old_table  = $wpdb->prefix . self::OLD_TABLE_PREFIX . $name;
			$new_table  = $wpdb->prefix . self::NEW_TABLE_PREFIX . $name;
			$old_exists = self::table_exists( $old_table );
			$new_exists = self::table_exists( $new_table );

			if ( $old_exists && $new_exists ) {
				$out['conflicts'][] = $name;
				continue;
			}
			if ( ! $old_exists ) {
				$out['skipped'][] = $name;
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Trusted constants; one-time migration.
			$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
			$out['renamed'][] = $name;
		}

		return $out;
	}

	/**
	 * Check table existence.
	 *
	 * @param string $table_name Full table name with prefix.
	 * @return bool
	 */
	private static function table_exists( string $table_name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Trusted source; one-time migration check.
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
		return null !== $result;
	}

	/**
	 * Migrate post_type values per POST_TYPE_MAP.
	 *
	 * @return int Number of rows updated.
	 */
	private static function migrate_post_types(): int {
		global $wpdb;
		$total = 0;
		foreach ( self::map( 'post_type_map' ) as $old_type => $new_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time post_type rename.
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s", $new_type, $old_type ) );
			$total  += (int) $updated;
		}
		return $total;
	}

	/**
	 * Migrate taxonomy slugs per TAXONOMY_MAP. Term relationships unaffected
	 * (they reference term_taxonomy_id, not taxonomy slug).
	 *
	 * @return int Number of rows updated.
	 */
	private static function migrate_taxonomies(): int {
		global $wpdb;
		$total = 0;
		foreach ( self::map( 'taxonomy_map' ) as $old_tax => $new_tax ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time taxonomy rename.
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->term_taxonomy} SET taxonomy = %s WHERE taxonomy = %s", $new_tax, $old_tax ) );
			$total  += (int) $updated;
		}
		return $total;
	}

	/**
	 * Rename options per OPTION_MAP. Exact-match per key (no LIKE/REPLACE).
	 *
	 * If the new key already exists, skip (already migrated). The old row
	 * is deleted explicitly to avoid orphan duplicates.
	 *
	 * @return array{renamed: array<string>, skipped: array<string>}
	 */
	private static function migrate_options(): array {
		$out = array(
			'renamed' => array(),
			'skipped' => array(),
		);
		foreach ( self::map( 'option_map' ) as $old_key => $new_key ) {
			$old_value = get_option( $old_key, null );
			if ( null === $old_value ) {
				$out['skipped'][] = $old_key;
				continue;
			}
			$new_present = ( false !== get_option( $new_key, false ) );
			if ( $new_present ) {
				delete_option( $old_key );
				$out['skipped'][] = $old_key;
				continue;
			}
			$autoload = self::option_autoload( $old_key );
			update_option( $new_key, $old_value, $autoload );
			delete_option( $old_key );
			$out['renamed'][] = $old_key;
		}
		return $out;
	}

	/**
	 * Read original option autoload setting so the renamed option preserves it.
	 *
	 * @param string $key Option key.
	 * @return bool True when WP stored the option with autoload=yes.
	 */
	private static function option_autoload( string $key ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only metadata read.
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		return ( 'yes' === $autoload );
	}

	/**
	 * Migrate wp_postmeta keys per POST_META_MAP.
	 *
	 * @return int Number of rows updated.
	 */
	private static function migrate_post_meta(): int {
		global $wpdb;
		$total = 0;
		foreach ( self::map( 'post_meta_map' ) as $old_key => $new_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time meta key rename.
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s", $new_key, $old_key ) );
			$total  += (int) $updated;
		}
		return $total;
	}

	/**
	 * Migrate wp_usermeta keys.
	 *
	 * Base does not own user meta currently; addon classes handle their own.
	 *
	 * @return int Number of rows updated.
	 */
	private static function migrate_user_meta(): int {
		return 0;
	}

	/**
	 * Migrate WC order item meta keys per ORDER_ITEM_META_MAP.
	 *
	 * Skips silently if the table is not present (Woo not installed).
	 *
	 * @return int Number of rows updated.
	 */
	private static function migrate_order_item_meta(): int {
		global $wpdb;
		$table = self::order_item_meta_table();
		if ( null === $table ) {
			return 0;
		}
		$total = 0;
		foreach ( self::map( 'order_item_meta_map' ) as $old_key => $new_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Validated table name.
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET meta_key = %s WHERE meta_key = %s", $new_key, $old_key ) );
			$total  += (int) $updated;
		}
		return $total;
	}

	/**
	 * Resolve the WC order item meta table name, or null if Woo isn't present.
	 *
	 * @return string|null
	 */
	private static function order_item_meta_table(): ?string {
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_order_itemmeta';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Trusted table name; one-time check.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return ( null !== $exists ) ? $table : null;
	}

	/**
	 * Migrate transients: delete cache, rename stateful.
	 *
	 * @return array{deleted: int, migrated: int}
	 */
	private static function migrate_transients(): array {
		$deleted  = 0;
		$migrated = 0;

		foreach ( self::list_section( 'cache_transient_prefixes' ) as $prefix ) {
			$deleted += self::delete_transients_with_prefix( $prefix );
		}

		foreach ( self::list_section( 'stateful_transient_prefixes' ) as $prefix ) {
			$new_prefix = str_replace( self::OLD_TABLE_PREFIX, self::NEW_TABLE_PREFIX, $prefix );
			$migrated  += self::rename_transients_with_prefix( $prefix, $new_prefix );
		}

		return array(
			'deleted'  => $deleted,
			'migrated' => $migrated,
		);
	}

	/**
	 * Delete transients (and their timeouts) matching a prefix.
	 *
	 * @param string $prefix Prefix without `_transient_`.
	 * @return int Count deleted (data rows only, not timeout rows).
	 */
	private static function delete_transients_with_prefix( string $prefix ): int {
		global $wpdb;
		$data_pattern    = '_transient_' . $wpdb->esc_like( $prefix ) . '%';
		$timeout_pattern = '_transient_timeout_' . $wpdb->esc_like( $prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bulk delete on known prefix list.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $data_pattern ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bulk delete on known prefix list.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_pattern ) );
		return (int) $deleted;
	}

	/**
	 * Rename transients (and their timeouts) by swapping prefix.
	 *
	 * @param string $old_prefix e.g. 'nte_rate_limit_'.
	 * @param string $new_prefix e.g. 'nettertech_events_rate_limit_'.
	 * @return int Count migrated.
	 */
	private static function rename_transients_with_prefix( string $old_prefix, string $new_prefix ): int {
		global $wpdb;
		$old_data_like    = '_transient_' . $wpdb->esc_like( $old_prefix ) . '%';
		$old_timeout_like = '_transient_timeout_' . $wpdb->esc_like( $old_prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bulk update on known prefix list.
		$d = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_name = REPLACE(option_name, %s, %s) WHERE option_name LIKE %s",
				'_transient_' . $old_prefix,
				'_transient_' . $new_prefix,
				$old_data_like
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bulk update on known prefix list.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_name = REPLACE(option_name, %s, %s) WHERE option_name LIKE %s",
				'_transient_timeout_' . $old_prefix,
				'_transient_timeout_' . $new_prefix,
				$old_timeout_like
			)
		);
		return (int) $d;
	}

	/**
	 * Reschedule cron events: clear old hook names, schedule new with the
	 * same cadence (recurrence + next-run timestamp preserved as best effort).
	 *
	 * @return array{rescheduled: array<string>, cleared: array<string>}
	 */
	private static function migrate_cron(): array {
		$out = array(
			'rescheduled' => array(),
			'cleared'     => array(),
		);

		foreach ( self::map( 'cron_hook_map' ) as $old_hook => $new_hook ) {
			$next     = wp_next_scheduled( $old_hook );
			$schedule = wp_get_schedule( $old_hook );
			wp_clear_scheduled_hook( $old_hook );
			$out['cleared'][] = $old_hook;

			if ( wp_next_scheduled( $new_hook ) ) {
				continue;
			}

			if ( $schedule ) {
				wp_schedule_event( $next ? (int) $next : time(), $schedule, $new_hook );
				$out['rescheduled'][] = $new_hook;
			} elseif ( $next ) {
				wp_schedule_single_event( (int) $next, $new_hook );
				$out['rescheduled'][] = $new_hook;
			}
		}

		// Obsolete hooks: clear without reschedule.
		foreach ( self::list_section( 'obsolete_cron_hooks' ) as $old_hook ) {
			if ( wp_next_scheduled( $old_hook ) ) {
				wp_clear_scheduled_hook( $old_hook );
				$out['cleared'][] = $old_hook;
			}
		}

		return $out;
	}

	/**
	 * Rewrite shortcode tags in post_content per SHORTCODE_MAP.
	 *
	 * Uses prepared replacements scoped to posts that contain at least one
	 * old tag, to avoid full-table writes.
	 *
	 * @return int Number of posts updated.
	 */
	private static function migrate_post_content_shortcodes(): int {
		global $wpdb;
		$total = 0;

		foreach ( self::map( 'shortcode_map' ) as $old_tag => $new_tag ) {
			$old_open  = '[' . $old_tag;
			$new_open  = '[' . $new_tag;
			$old_close = '[/' . $old_tag . ']';
			$new_close = '[/' . $new_tag . ']';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bulk content rewrite, exact-string match on shortcode tag.
			$updated_open = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
					$old_open,
					$new_open,
					'%' . $wpdb->esc_like( $old_open ) . '%'
				)
			);
			$total       += (int) $updated_open;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bulk content rewrite, exact-string match on close tag.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
					$old_close,
					$new_close,
					'%' . $wpdb->esc_like( $old_close ) . '%'
				)
			);
		}

		return $total;
	}
}
