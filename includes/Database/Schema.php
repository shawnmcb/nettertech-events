<?php
/**
 * Database schema class.
 *
 * @package NetterTechEvents\Database
 */

declare(strict_types=1);

namespace NetterTechEvents\Database;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Database\Tables\ActivityLogTable;
use NetterTechEvents\Database\Tables\AttendeesTable;
use NetterTechEvents\Database\Tables\CategoriesTable;
use NetterTechEvents\Database\Tables\EventCategoriesTable;
use NetterTechEvents\Database\Tables\EventOrganizersTable;
use NetterTechEvents\Database\Tables\EventsTable;
use NetterTechEvents\Database\Tables\EventTagsTable;
use NetterTechEvents\Database\Tables\OccurrencesTable;
use NetterTechEvents\Database\Tables\OrganizersTable;
use NetterTechEvents\Database\Tables\ReminderLogTable;
use NetterTechEvents\Database\Tables\SeriesTable;
use NetterTechEvents\Database\Tables\SpacesTable;
use NetterTechEvents\Database\Tables\TableDefinitionInterface;
use NetterTechEvents\Database\Tables\TagsTable;
use NetterTechEvents\Database\Tables\TicketsTable;
use NetterTechEvents\Database\Tables\TicketTypesTable;
use NetterTechEvents\Database\Tables\ReservationsTable;
use NetterTechEvents\Database\Tables\WaitlistTable;
use NetterTechEvents\Database\Tables\EventRevisionsTable;
use NetterTechEvents\Database\Tables\AttendeeFieldsTable;
use NetterTechEvents\Database\Tables\AttendeeFieldValuesTable;

/**
 * Manages database table creation and schema migrations.
 *
 * Design decisions:
 * - Custom tables instead of post_meta for performance
 * - Denormalized occurrences for fast calendar queries
 * - Proper indexes on frequently queried columns
 *
 * @since 0.8.0 Original implementation.
 * @since 1.1.0 Refactored to use TableDefinitionInterface classes.
 * PHPMD TooManyMethods/ExcessiveClassLength suppressed: structural schema facade and
 * central migration registry; splitting would scatter table definitions and ordering.
 *
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
class Schema {

	/**
	 * Database version for migrations.
	 *
	 * @var string
	 */
	public const DB_VERSION = '3.16.0';

	/**
	 * Table prefix for plugin tables.
	 *
	 * @var string
	 */
	public const TABLE_PREFIX = 'nettertech_events_';

	/**
	 * Option name for the per-migration audit log.
	 *
	 * @var string
	 */
	private const MIGRATION_LOG_OPTION = 'nettertech_events_migration_log';

	/**
	 * Get table definition instances in dependency order.
	 *
	 * @return array<TableDefinitionInterface>
	 */
	private static function get_table_definitions(): array {
		return array(
			// Core tables (no dependencies first).
			new SeriesTable(),
			new EventsTable(),
			new OccurrencesTable(),
			new SpacesTable(),
			new TicketTypesTable(),
			new AttendeesTable(),
			new TicketsTable(),
			// Attendee registration fields (depends on events, attendees).
			new AttendeeFieldsTable(),
			new AttendeeFieldValuesTable(),
			// Taxonomy tables.
			new OrganizersTable(),
			new EventOrganizersTable(),
			new CategoriesTable(),
			new EventCategoriesTable(),
			new TagsTable(),
			new EventTagsTable(),
			// Operational tables.
			new ActivityLogTable(),
			new ReminderLogTable(),
			new ReservationsTable(),
			new WaitlistTable(),
			new EventRevisionsTable(),
		);
	}

	/**
	 * Create all plugin tables.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// Create tables using definition classes.
		foreach ( self::get_table_definitions() as $definition ) {
			$table_name = self::table( $definition->get_name() );
			$sql        = $definition->get_sql( $table_name, $charset_collate );
			dbDelta( $sql );
		}
	}

	/**
	 * Get full table name with WordPress prefix.
	 *
	 * @param string $table_name Short table name.
	 * @return string Full table name.
	 */
	public static function table( string $table_name ): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_PREFIX . $table_name;
	}

	/**
	 * Get all table names managed by this plugin.
	 *
	 * @param bool $include_deferred Include deferred (orphan) tables.
	 * @return array<string> Table names without prefix.
	 */
	public static function get_all_tables( bool $include_deferred = false ): array {
		// Core tables from definitions.
		$tables = array_map(
			fn( TableDefinitionInterface $def ) => $def->get_name(),
			self::get_table_definitions()
		);

		if ( $include_deferred ) {
			$tables = array_merge( $tables, DeferredSchema::get_table_names() );
		}

		return $tables;
	}

	/**
	 * Drop all plugin tables.
	 *
	 * Only used during complete uninstall.
	 *
	 * @param bool $include_deferred Also drop deferred (orphan) tables. Default false.
	 * @return void
	 */
	public static function drop_tables( bool $include_deferred = false ): void {
		global $wpdb;

		$tables = self::get_all_tables( $include_deferred );

		// Drop in reverse dependency order.
		$tables = array_reverse( $tables );

		foreach ( $tables as $table ) {
			$table_name = self::table( $table );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from trusted source.
			$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		}

		delete_option( 'nettertech_events_db_version' );
		delete_option( self::MIGRATION_LOG_OPTION );
	}

	/**
	 * Check if tables need migration.
	 *
	 * @return bool
	 */
	public static function needs_migration(): bool {
		$current_version = get_option( 'nettertech_events_db_version', '0.0.0' );
		return version_compare( $current_version, self::DB_VERSION, '<' );
	}

	/**
	 * Get migration version map.
	 *
	 * Maps version numbers to migration method names.
	 * Migrations run in ascending version order.
	 *
	 * @return array<string, string> Version => method name mapping.
	 */
	private static function get_migration_map(): array {
		return array(
			'1.5.0'  => 'migrate_to_1_5_0',
			'1.6.0'  => 'migrate_to_1_6_0',
			'1.7.0'  => 'migrate_to_1_7_0',
			'1.8.0'  => 'migrate_to_1_8_0',
			'1.9.0'  => 'migrate_to_1_9_0',
			'2.0.0'  => 'migrate_to_2_0_0',
			'2.1.0'  => 'migrate_to_2_1_0',
			'2.2.0'  => 'migrate_to_2_2_0',
			'2.3.0'  => 'migrate_to_2_3_0',
			'2.4.0'  => 'migrate_to_2_4_0',
			'2.5.0'  => 'migrate_to_2_5_0',
			'2.6.0'  => 'migrate_to_2_6_0',
			'2.7.0'  => 'migrate_to_2_7_0',
			'2.8.0'  => 'migrate_to_2_8_0',
			'2.9.0'  => 'migrate_to_2_9_0',
			'3.0.0'  => 'migrate_to_3_0_0',
			'3.1.0'  => 'migrate_to_3_1_0',
			'3.2.0'  => 'migrate_to_3_2_0',
			'3.3.0'  => 'migrate_to_3_3_0',
			'3.4.0'  => 'migrate_to_3_4_0',
			'3.5.0'  => 'migrate_to_3_5_0',
			'3.6.0'  => 'migrate_to_3_6_0',
			'3.7.0'  => 'migrate_to_3_7_0',
			'3.8.0'  => 'migrate_to_3_8_0',
			'3.9.0'  => 'migrate_to_3_9_0',
			'3.10.0' => 'migrate_to_3_10_0',
			'3.11.0' => 'migrate_to_3_11_0',
			'3.12.0' => 'migrate_to_3_12_0',
			'3.13.0' => 'migrate_to_3_13_0',
			'3.14.0' => 'migrate_to_3_14_0',
			'3.15.0' => 'migrate_to_3_15_0',
			'3.16.0' => 'migrate_to_3_16_0',
		);
	}

	/**
	 * Run necessary migrations.
	 *
	 * Each migration is logged individually with success/failure status.
	 * Already-succeeded migrations are skipped (idempotency guard).
	 * On failure, the migration chain stops to prevent cascading errors.
	 *
	 * @return void
	 */
	public static function migrate(): void {
		if ( ! self::needs_migration() ) {
			return;
		}

		$current_version = get_option( 'nettertech_events_db_version', '0.0.0' );
		$migration_log   = self::get_migration_log();

		// Re-run table creation (dbDelta handles updates).
		self::create_tables();

		// Run version-specific migrations using data-driven approach.
		foreach ( self::get_migration_map() as $version => $method ) {
			if ( version_compare( $current_version, $version, '<' ) ) {
				// Idempotency guard: skip if already recorded as success.
				if ( self::migration_has_succeeded( $migration_log, $version ) ) {
					update_option( 'nettertech_events_db_version', $version );
					continue;
				}

				try {
					self::$method();
					self::log_migration( $version, 'success' );
					update_option( 'nettertech_events_db_version', $version );
				} catch ( \Throwable $exception ) {
					self::log_migration( $version, 'failed', $exception->getMessage() );
					return;
				}
			}
		}
	}

	/**
	 * Get the migration audit log.
	 *
	 * @return array<int, array{migration: string, status: string, executed_at: string, error?: string}> Migration log entries.
	 */
	public static function get_migration_log(): array {
		$log = get_option( self::MIGRATION_LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Check if a migration version has already succeeded.
	 *
	 * @param array<int, array{migration: string, status: string, executed_at: string, error?: string}> $log     The migration log.
	 * @param string                                                                                    $version The version to check.
	 * @return bool True if the migration has a success record.
	 */
	private static function migration_has_succeeded( array $log, string $version ): bool {
		foreach ( $log as $entry ) {
			if ( $version === $entry['migration'] && 'success' === $entry['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Record a migration result in the audit log.
	 *
	 * @param string $version The migration version.
	 * @param string $status  The result status ('success' or 'failed').
	 * @param string $error   Optional error message on failure.
	 * @return void
	 */
	private static function log_migration( string $version, string $status, string $error = '' ): void {
		$log   = self::get_migration_log();
		$entry = array(
			'migration'   => $version,
			'status'      => $status,
			'executed_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( '' !== $error ) {
			$entry['error'] = $error;
		}

		$log[] = $entry;
		update_option( self::MIGRATION_LOG_OPTION, $log, false );
	}

	// =========================================================================
	// Migration Methods
	// =========================================================================

	/**
	 * Migration to v1.5.0: Populate sold_count from attendee data.
	 *
	 * @return void
	 */
	private static function migrate_to_1_5_0(): void {
		global $wpdb;

		$ticket_types_table = self::table( 'ticket_types' );
		$attendees_table    = self::table( 'attendees' );

		// Update sold_count for all ticket types based on confirmed attendees.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names cannot be parameterized; custom tables.
		$wpdb->query(
			"UPDATE {$ticket_types_table} tt
             SET sold_count = COALESCE(
                 (SELECT SUM(a.quantity)
                  FROM {$attendees_table} a
                  WHERE a.ticket_type_id = tt.id AND a.status = 'confirmed'),
                 0
             )"
		);

		// Update stock_status based on sold_count vs capacity.
		$wpdb->query(
			"UPDATE {$ticket_types_table}
             SET stock_status = CASE
                 WHEN capacity IS NULL THEN 'in_stock'
                 WHEN sold_count >= capacity THEN 'out_of_stock'
                 WHEN capacity > 0 AND sold_count >= capacity * 0.9 THEN 'low_stock'
                 ELSE 'in_stock'
             END"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v1.6.0: Add featured_image_id to occurrences.
	 *
	 * @return void
	 */
	private static function migrate_to_1_6_0(): void {
		// Schema changes handled by dbDelta in create_tables().
	}

	/**
	 * Migration to v1.7.0: Add three-tier ticket scoping columns.
	 *
	 * @return void
	 */
	private static function migrate_to_1_7_0(): void {
		global $wpdb;

		$ticket_types_table = self::table( 'ticket_types' );

		// Set scope='occurrence' for all existing ticket types.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom tables.
		$wpdb->query(
			"UPDATE {$ticket_types_table}
             SET scope = 'occurrence'
             WHERE scope IS NULL OR scope = ''"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v1.8.0: Add category_ids and tag_ids columns to events.
	 *
	 * @return void
	 */
	private static function migrate_to_1_8_0(): void {
		global $wpdb;

		$events_table = self::table( 'events' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'category_ids'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN category_ids text DEFAULT NULL AFTER recurrence_end_date" );
		}

		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'tag_ids'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN tag_ids text DEFAULT NULL AFTER category_ids" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v1.9.0: Add capacity_type column to ticket_types.
	 *
	 * @return void
	 */
	private static function migrate_to_1_9_0(): void {
		global $wpdb;

		$ticket_types_table = self::table( 'ticket_types' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$ticket_types_table} LIKE 'capacity_type'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$ticket_types_table} ADD COLUMN capacity_type varchar(20) NOT NULL DEFAULT 'fixed' AFTER price" );
		}

		$wpdb->query(
			"UPDATE {$ticket_types_table}
			 SET capacity_type = 'fixed'
			 WHERE capacity_type IS NULL OR capacity_type = ''"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v2.0.0: Add layout_config column to events table.
	 *
	 * @return void
	 */
	private static function migrate_to_2_0_0(): void {
		global $wpdb;

		$events_table = self::table( 'events' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'layout_config'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN layout_config text DEFAULT NULL AFTER tag_ids" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v2.1.0: Add checkin_token column to occurrences table.
	 *
	 * @return void
	 */
	private static function migrate_to_2_1_0(): void {
		global $wpdb;

		$occurrences_table = self::table( 'occurrences' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$occurrences_table} LIKE 'checkin_token'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$occurrences_table} ADD COLUMN checkin_token varchar(64) DEFAULT NULL AFTER is_rescheduled" );
			$wpdb->query( "ALTER TABLE {$occurrences_table} ADD UNIQUE KEY checkin_token (checkin_token)" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v2.2.0: Remove JSON columns, add taxonomy tables.
	 *
	 * @return void
	 */
	private static function migrate_to_2_2_0(): void {
		global $wpdb;

		$events_table = self::table( 'events' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'category_ids'" );
		if ( ! empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$events_table} DROP COLUMN category_ids" );
		}

		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'tag_ids'" );
		if ( ! empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$events_table} DROP COLUMN tag_ids" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v2.3.0: Add performance indexes.
	 *
	 * @return void
	 */
	private static function migrate_to_2_3_0(): void {
		global $wpdb;

		$tickets_table   = self::table( 'tickets' );
		$attendees_table = self::table( 'attendees' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names cannot be parameterized; custom table schema migration.
		$index_exists = $wpdb->get_results(
			"SHOW INDEX FROM {$tickets_table} WHERE Key_name = 'occurrence_status'"
		);
		if ( empty( $index_exists ) ) {
			$wpdb->query( "ALTER TABLE {$tickets_table} ADD KEY occurrence_status (occurrence_id, status)" );
		}

		$index_exists = $wpdb->get_results(
			"SHOW INDEX FROM {$attendees_table} WHERE Key_name = 'status'"
		);
		if ( empty( $index_exists ) ) {
			$wpdb->query( "ALTER TABLE {$attendees_table} ADD KEY status (status)" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v2.4.0: Add activity_log table.
	 *
	 * @return void
	 */
	private static function migrate_to_2_4_0(): void {
		// Table creation handled by dbDelta in create_tables().
	}

	/**
	 * Migration to v2.5.0: Expand checkin_token column for enhanced security.
	 *
	 * @return void
	 */
	private static function migrate_to_2_5_0(): void {
		global $wpdb;

		$occurrences_table = self::table( 'occurrences' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table schema migration.
		$wpdb->query( "ALTER TABLE {$occurrences_table} MODIFY COLUMN checkin_token varchar(96) DEFAULT NULL" );
	}

	/**
	 * Migration to v2.6.0: Add source column to ticket_types and attendees.
	 *
	 * @return void
	 */
	private static function migrate_to_2_6_0(): void {
		global $wpdb;

		$ticket_types_table = self::table( 'ticket_types' );
		$attendees_table    = self::table( 'attendees' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names cannot be parameterized; custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$ticket_types_table} LIKE 'source'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$ticket_types_table} ADD COLUMN source varchar(20) DEFAULT 'woocommerce' AFTER wc_variation_id" );
			$wpdb->query( "ALTER TABLE {$ticket_types_table} ADD KEY source (source)" );
		}

		$wpdb->query(
			"UPDATE {$ticket_types_table}
			 SET source = CASE
				 WHEN wc_product_id IS NOT NULL THEN 'woocommerce'
				 ELSE 'manual'
			 END
			 WHERE source IS NULL OR source = ''"
		);

		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$attendees_table} LIKE 'source'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$attendees_table} ADD COLUMN source varchar(20) DEFAULT 'woocommerce' AFTER wc_order_id" );
			$wpdb->query( "ALTER TABLE {$attendees_table} ADD KEY source (source)" );
		}

		$wpdb->query(
			"UPDATE {$attendees_table}
			 SET source = CASE
				 WHEN wc_order_id IS NOT NULL THEN 'woocommerce'
				 ELSE 'manual'
			 END
			 WHERE source IS NULL OR source = ''"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v2.8.0: Add reminder_log table and reminders_enabled to events.
	 *
	 * @return void
	 */
	private static function migrate_to_2_8_0(): void {
		global $wpdb;

		$events_table = self::table( 'events' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'reminders_enabled'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN reminders_enabled tinyint(1) DEFAULT NULL AFTER layout_config" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v2.9.0: Drop redundant standalone occurrence_id index on attendees.
	 *
	 * The standalone occurrence_id index is redundant because three composite indexes
	 * (checkin_query, occurrence_status, occurrence_email) all share occurrence_id as
	 * their leading column. MySQL can use any composite for single-column lookups.
	 *
	 * @return void
	 */
	private static function migrate_to_2_9_0(): void {
		global $wpdb;

		$attendees_table = self::table( 'attendees' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table schema migration.
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$attendees_table} WHERE Key_name = 'occurrence_id'" );
		if ( ! empty( $indexes ) ) {
			$wpdb->query( "ALTER TABLE {$attendees_table} DROP INDEX occurrence_id" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v2.7.0: Add updated_at column to occurrences table.
	 *
	 * @return void
	 */
	private static function migrate_to_2_7_0(): void {
		global $wpdb;

		$occurrences_table = self::table( 'occurrences' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$occurrences_table} LIKE 'updated_at'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query(
				"ALTER TABLE {$occurrences_table}
				 ADD COLUMN updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
				 AFTER created_at"
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v3.0.0: Add waitlist table.
	 *
	 * Table creation handled by dbDelta in create_tables().
	 *
	 * @return void
	 */
	private static function migrate_to_3_0_0(): void {
		// Table creation handled by dbDelta in create_tables().
	}

	/**
	 * Migration to v3.1.0: Add event_revisions table.
	 *
	 * Table creation handled by dbDelta in create_tables().
	 *
	 * @return void
	 */
	private static function migrate_to_3_1_0(): void {
		// Table creation handled by dbDelta in create_tables().
	}

	/**
	 * Migration to v3.2.0: Add custom_fields JSON column to events table.
	 *
	 * Stores arbitrary custom fields from source systems (ACF, third-party meta)
	 * that don't map to standard VE schema columns.
	 *
	 * @return void
	 */
	private static function migrate_to_3_2_0(): void {
		global $wpdb;

		$events_table = self::table( 'events' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'custom_fields'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN custom_fields JSON DEFAULT NULL AFTER reminders_enabled" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v3.3.0: Add notification_emails column to events table.
	 *
	 * Stores per-event notification recipient email addresses (comma-separated).
	 *
	 * @return void
	 */
	private static function migrate_to_3_3_0(): void {
		global $wpdb;

		$events_table = self::table( 'events' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'notification_emails'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN notification_emails text DEFAULT NULL AFTER reminders_enabled" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v3.4.0: Add reserved column to ticket_types and reservations table.
	 *
	 * Adds atomic capacity reservation support. The reserved column on ticket_types
	 * holds the aggregate count; the reservations table tracks per-session detail.
	 *
	 * @return void
	 */
	private static function migrate_to_3_4_0(): void {
		global $wpdb;

		$ticket_types_table = self::table( 'ticket_types' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$ticket_types_table} LIKE 'reserved'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$ticket_types_table} ADD COLUMN reserved int(10) unsigned NOT NULL DEFAULT 0 AFTER wc_variation_id" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Reservations table created by dbDelta in create_tables().
	}

	/**
	 * Migration to v3.5.0: Add spaces table to core schema.
	 *
	 * Table creation handled by dbDelta in create_tables().
	 * If the table already exists (from DeferredSchema/Rentals), dbDelta
	 * will reconcile column differences.
	 *
	 * @return void
	 */
	private static function migrate_to_3_5_0(): void {
		// Table creation handled by dbDelta in create_tables().
	}

	/**
	 * Migration to v3.6.0: Add attendee registration fields tables and collect_individual_attendees column.
	 *
	 * New tables (attendee_fields, attendee_field_values) created by dbDelta in create_tables().
	 * Adds collect_individual_attendees column to events table for per-attendee checkout control.
	 *
	 * @return void
	 */
	private static function migrate_to_3_6_0(): void {
		global $wpdb;

		$events_table = self::table( 'events' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from Schema::table(); custom table migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'collect_individual_attendees'" );
		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from Schema::table(); custom table schema migration.
			$wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN collect_individual_attendees tinyint(1) DEFAULT NULL AFTER custom_fields" );
		}
	}

	/**
	 * Migration to v3.7.0: Add virtual event columns to events table.
	 *
	 * Adds is_virtual and virtual_url columns for virtual/hybrid event support.
	 *
	 * @return void
	 */
	private static function migrate_to_3_7_0(): void {
		global $wpdb;

		$events_table = self::table( 'events' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from Schema::table(); custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'is_virtual'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN is_virtual tinyint(1) DEFAULT NULL AFTER custom_fields" );
		}

		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'virtual_url'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN virtual_url varchar(500) DEFAULT NULL AFTER is_virtual" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v3.8.0: Add QR logo columns to events and migrate data from post_meta.
	 *
	 * Moves _nettertech_events_qr_logo_mode and _nettertech_events_qr_logo_id post_meta into the events table
	 * columns qr_logo_mode and qr_logo_attachment_id. Cleans up migrated post_meta.
	 *
	 * @return void
	 */
	private static function migrate_to_3_8_0(): void {
		global $wpdb;

		$events_table = self::table( 'events' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from Schema::table(); custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'qr_logo_mode'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN qr_logo_mode varchar(20) DEFAULT NULL AFTER collect_individual_attendees" );
		}

		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'qr_logo_attachment_id'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN qr_logo_attachment_id bigint(20) unsigned DEFAULT NULL AFTER qr_logo_mode" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Migrate existing post_meta values into the events table.
		// Events store post_id; post_meta was (incorrectly) keyed by event table ID,
		// so we join on postmeta.post_id = events.id for the migration.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from Schema::table() and $wpdb properties; one-time data migration.
		$wpdb->query(
			"UPDATE {$events_table} e
			INNER JOIN {$wpdb->postmeta} pm_mode
				ON pm_mode.post_id = e.id
				AND pm_mode.meta_key = '_nettertech_events_qr_logo_mode'
			SET e.qr_logo_mode = pm_mode.meta_value
			WHERE e.qr_logo_mode IS NULL"
		);

		$wpdb->query(
			"UPDATE {$events_table} e
			INNER JOIN {$wpdb->postmeta} pm_id
				ON pm_id.post_id = e.id
				AND pm_id.meta_key = '_nettertech_events_qr_logo_id'
			SET e.qr_logo_attachment_id = CAST(pm_id.meta_value AS UNSIGNED)
			WHERE e.qr_logo_attachment_id IS NULL
				AND pm_id.meta_value IS NOT NULL
				AND pm_id.meta_value != '0'"
		);

		// Clean up migrated post_meta.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
				'_nettertech_events_qr_logo_mode',
				'_nettertech_events_qr_logo_id'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v3.9.0: Add space_id column to events table.
	 *
	 * Links events to physical spaces (nettertech_events_spaces) for seating and capacity.
	 *
	 * @return void
	 */
	private static function migrate_to_3_9_0(): void {
		global $wpdb;

		$events_table = self::table( 'events' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from Schema::table(); custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'space_id'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN space_id bigint(20) unsigned DEFAULT NULL AFTER qr_logo_attachment_id" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v3.10.0: Restructure spaces accessibility + seating + tagline.
	 *
	 * - Adds tagline (varchar 255, NULL).
	 * - Adds accessibility_features (text, NULL) — JSON array of feature entries.
	 * - Adds seating_model (varchar 20, default 'free').
	 * - Migrates legacy boolean a11y flags into seeded preset keys when set.
	 * - Migrates has_assigned_seating into seating_model='assigned' when set.
	 * - Drops the four legacy boolean columns after migration.
	 *
	 * @return void
	 */
	private static function migrate_to_3_10_0(): void {
		global $wpdb;

		$spaces_table = self::table( 'spaces' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table schema migration; table name from Schema::table().

		// Add new columns.
		$has_tagline = $wpdb->get_results( "SHOW COLUMNS FROM {$spaces_table} LIKE 'tagline'" );
		if ( empty( $has_tagline ) ) {
			$wpdb->query( "ALTER TABLE {$spaces_table} ADD COLUMN tagline varchar(255) DEFAULT NULL AFTER slug" );
		}

		$has_a11y_features = $wpdb->get_results( "SHOW COLUMNS FROM {$spaces_table} LIKE 'accessibility_features'" );
		if ( empty( $has_a11y_features ) ) {
			$wpdb->query( "ALTER TABLE {$spaces_table} ADD COLUMN accessibility_features text AFTER status" );
		}

		$has_seating_model = $wpdb->get_results( "SHOW COLUMNS FROM {$spaces_table} LIKE 'seating_model'" );
		if ( empty( $has_seating_model ) ) {
			$wpdb->query( "ALTER TABLE {$spaces_table} ADD COLUMN seating_model varchar(20) NOT NULL DEFAULT 'free' AFTER status" );
		}

		// Backfill accessibility_features from legacy booleans.
		$legacy_columns = $wpdb->get_results( "SHOW COLUMNS FROM {$spaces_table} WHERE Field IN ('is_accessible','has_accessible_stage','has_hearing_loop','has_assigned_seating')" );
		if ( ! empty( $legacy_columns ) ) {
			$rows = $wpdb->get_results(
				"SELECT id, is_accessible, has_accessible_stage, has_hearing_loop, has_assigned_seating FROM {$spaces_table}"
			);
			foreach ( (array) $rows as $row ) {
				$features = array();
				if ( ! empty( $row->is_accessible ) ) {
					$features[] = array( 'key' => 'wheelchair_seating' );
				}
				if ( ! empty( $row->has_accessible_stage ) ) {
					$features[] = array( 'key' => 'accessible_stage' );
				}
				if ( ! empty( $row->has_hearing_loop ) ) {
					$features[] = array( 'key' => 'hearing_loop' );
				}
				$seating = ! empty( $row->has_assigned_seating ) ? 'assigned' : 'free';
				$wpdb->update(
					$spaces_table,
					array(
						'accessibility_features' => empty( $features ) ? null : (string) wp_json_encode( $features ),
						'seating_model'          => $seating,
					),
					array( 'id' => (int) $row->id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}

			// Drop legacy columns.
			$wpdb->query( "ALTER TABLE {$spaces_table} DROP COLUMN is_accessible" );
			$wpdb->query( "ALTER TABLE {$spaces_table} DROP COLUMN has_accessible_stage" );
			$wpdb->query( "ALTER TABLE {$spaces_table} DROP COLUMN has_hearing_loop" );
			$wpdb->query( "ALTER TABLE {$spaces_table} DROP COLUMN has_assigned_seating" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migration to v3.11.0: Add per-occurrence override columns.
	 *
	 * Supports per-occurrence editing (NTE-077). is_override flags rows with
	 * manual edits so regeneration does not clobber them. venue_name_override,
	 * venue_address_override, and virtual_url_override extend the existing
	 * dedicated-column override pattern (title_override, description_override,
	 * featured_image_id) to fields that previously lived only on the parent event.
	 *
	 * @return void
	 */
	private static function migrate_to_3_11_0(): void {
		global $wpdb;

		$occurrences_table = self::table( 'occurrences' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from Schema::table(); custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$occurrences_table} LIKE 'is_override'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$occurrences_table} ADD COLUMN is_override tinyint(1) NOT NULL DEFAULT 0 AFTER is_rescheduled" );
		}

		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$occurrences_table} LIKE 'venue_name_override'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$occurrences_table} ADD COLUMN venue_name_override varchar(255) DEFAULT NULL AFTER is_override" );
		}

		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$occurrences_table} LIKE 'venue_address_override'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$occurrences_table} ADD COLUMN venue_address_override text DEFAULT NULL AFTER venue_name_override" );
		}

		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$occurrences_table} LIKE 'virtual_url_override'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$occurrences_table} ADD COLUMN virtual_url_override varchar(500) DEFAULT NULL AFTER venue_address_override" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migrate to 3.12.0: backfill occurrence sequence_number.
	 *
	 * Prior to this version OccurrenceGenerator never set sequence_number, so
	 * every row defaulted to 1. This broke iCal original-slot recovery
	 * (RECURRENCE-ID / EXDATE always resolved to the first slot). Backfill a
	 * per-event 1-based sequence ordered by start_datetime (tie-break on id).
	 * Version-gated, so it runs once and will not clobber correct sequences
	 * written by the fixed generator on subsequent saves.
	 *
	 * @return void
	 */
	private static function migrate_to_3_12_0(): void {
		global $wpdb;

		$occurrences_table = self::table( 'occurrences' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from Schema::table(); custom table data backfill (MySQL 8.0 window function).
		$wpdb->query(
			"UPDATE {$occurrences_table} o
			JOIN (
				SELECT id, ROW_NUMBER() OVER ( PARTITION BY event_id ORDER BY start_datetime, id ) AS rn
				FROM {$occurrences_table}
			) r ON o.id = r.id
			SET o.sequence_number = r.rn"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migrate to 3.13.0: add the per-event image vertical crop anchor column.
	 *
	 * Additive, nullable, no backfill — existing rows read NULL and render as
	 * center (the model/render default), so the upgrade is safe for live installs.
	 * Idempotent guard (SHOW COLUMNS) so re-runs and the dbDelta belt-and-suspenders
	 * in create_tables() cannot collide.
	 *
	 * @return void
	 */
	private static function migrate_to_3_13_0(): void {
		global $wpdb;

		$events_table = self::table( 'events' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from Schema::table(); custom table schema migration.
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$events_table} LIKE 'image_vertical_anchor'" );
		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN image_vertical_anchor varchar(10) DEFAULT NULL AFTER space_id" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migrate to 3.14.0: backfill the occurrences timezone column to the site zone.
	 *
	 * Occurrence start/end are stored as site-local wall-clock, but the timezone
	 * column was the vestigial 'UTC' default (never set on write before this fix),
	 * causing past/now comparisons to misread the wall-clock as UTC. Backfill the
	 * authoring zone so get_start()/get_end() and is_past()/has_ended() interpret
	 * correctly (DST-aware). Safe: existing wall-clock was authored in the current
	 * site timezone. On a UTC site this is a no-op (site zone resolves to 'UTC').
	 * Idempotent (re-run sets the same value). Goes hand-in-hand with the write-time
	 * stamping in OccurrenceRepository::ensure_timezone().
	 *
	 * @return void
	 */
	private static function migrate_to_3_14_0(): void {
		global $wpdb;

		$occurrences_table = self::table( 'occurrences' );
		$site_tz           = wp_timezone_string();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from Schema::table(); value bound via prepare(); custom-table data backfill.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$occurrences_table} SET timezone = %s WHERE timezone = 'UTC' OR timezone = '' OR timezone IS NULL",
				$site_tz
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Clear capacity values from tiers that have no capacity of their own.
	 *
	 * Only a FIXED tier's `capacity` column means anything. The ticket-type form hid
	 * the capacity input for the other types without disabling it, so a number typed
	 * while a tier was fixed was still submitted after the tier was switched to
	 * shared — and it stuck. Read back later it looked like a real allotment: a tier
	 * quietly holding 999 seats nobody had granted it.
	 *
	 * The form and the storage boundary now both refuse to write such a value
	 * (TicketType::normalize_capacity()), but rows written before that are still
	 * carrying one. This clears them. Nothing is lost: for a non-fixed tier the
	 * column was never consulted by the house rule, so the value it held had no
	 * meaning to erase.
	 *
	 * @since 1.1.2
	 *
	 * @return void
	 */
	private static function migrate_to_3_15_0(): void {
		global $wpdb;

		$ticket_types_table = self::table( 'ticket_types' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from Schema::table(); custom-table data cleanup.
		$wpdb->query(
			"UPDATE {$ticket_types_table} SET capacity = NULL WHERE capacity_type <> 'fixed' AND capacity IS NOT NULL"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migrate to 3.16.0: resolve every occurrence's wall-clock to a real instant.
	 *
	 * The stored start/end are wall-clock in the occurrence's own zone. Every "has it ended
	 * yet" query compared that wall-clock against the *site's* clock, so an event in Sydney
	 * sold from a site in Chicago was judged past or upcoming against the wrong clock — the
	 * same class of error as NTE-148, and it grows with the distance between the zones.
	 *
	 * The conversion cannot be done in SQL. `CONVERT_TZ()` with a named zone needs MySQL's
	 * time_zone tables, which ship empty and are unpopulated on most hosts; it returns NULL
	 * there, and a WHERE on NULL quietly matches nothing. A calendar that silently empties
	 * itself on some hosts and not others is worse than the bug being fixed. So PHP does the
	 * conversion, where the zone database is real and DST is handled.
	 *
	 * Chunked: a site with many years of recurring occurrences should not have to hold them
	 * all in memory at once. Idempotent — re-running recomputes the same instants — and it
	 * only touches rows that still have no instant, so it is cheap on a second pass.
	 *
	 * @since 1.1.2
	 *
	 * @return void
	 */
	private static function migrate_to_3_16_0(): void {
		global $wpdb;

		$occurrences_table = self::table( 'occurrences' );
		$site_tz           = wp_timezone_string();

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from Schema::table(); one-time backfill of a custom table.
			$rows = $wpdb->get_results(
				"SELECT id, start_datetime, end_datetime, timezone
				 FROM {$occurrences_table}
				 WHERE start_utc IS NULL OR end_utc IS NULL
				 LIMIT 500"
			);

			foreach ( $rows ? $rows : array() as $row ) {
				$zone = self::zone_for( (string) ( $row->timezone ?? '' ), $site_tz );

				$start = self::to_utc( (string) $row->start_datetime, $zone );
				$end   = self::to_utc( (string) $row->end_datetime, $zone );

				if ( null === $start || null === $end ) {
					// An unreadable wall-clock cannot be resolved. Leave the instants NULL —
					// the queries fall back to the wall-clock for such a row rather than
					// dropping it — and do not spin forever on it.
					continue 2;
				}

				$wpdb->update(
					$occurrences_table,
					array(
						'start_utc' => $start,
						'end_utc'   => $end,
					),
					array( 'id' => (int) $row->id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		} while ( ! empty( $rows ) );
	}

	/**
	 * Resolve a stored zone name, falling back to the site's.
	 *
	 * @param string $timezone The stored zone name, possibly empty or junk.
	 * @param string $fallback The site zone name.
	 * @return \DateTimeZone
	 */
	private static function zone_for( string $timezone, string $fallback ): \DateTimeZone {
		try {
			return new \DateTimeZone( '' !== $timezone ? $timezone : $fallback );
		} catch ( \Exception $e ) {
			unset( $e );
			return wp_timezone();
		}
	}

	/**
	 * Read a wall-clock in a zone and render the instant it names, in UTC.
	 *
	 * @param string        $wall_clock The stored datetime.
	 * @param \DateTimeZone $zone       The zone it was authored in.
	 * @return string|null The UTC datetime, or null when the value cannot be read.
	 */
	private static function to_utc( string $wall_clock, \DateTimeZone $zone ): ?string {
		if ( '' === $wall_clock ) {
			return null;
		}

		try {
			return ( new \DateTimeImmutable( $wall_clock, $zone ) )
				->setTimezone( new \DateTimeZone( 'UTC' ) )
				->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			unset( $e );
			return null;
		}
	}
}
