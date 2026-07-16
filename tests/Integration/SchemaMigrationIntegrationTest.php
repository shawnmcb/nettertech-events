<?php
/**
 * Schema migration integration test.
 *
 * Verifies idempotency: running Schema::create_tables() twice produces
 * the same result as running it once (dbDelta semantics), and that all
 * expected plugin tables exist after table creation.
 *
 * @package NetterTechEvents\Tests\Integration
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration;

use NetterTechEvents\Database\Schema;

/**
 * Integration test: Schema migration idempotency and table presence.
 */
class SchemaMigrationIntegrationTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * Idempotency does not require transactions — tables already exist.
	 * We do NOT roll back schema changes, so we disable the class-level
	 * transaction to avoid DDL auto-commit issues.
	 *
	 * @var bool
	 */
	protected static bool $use_transactions = false;

	// =========================================================================
	// Table presence
	// =========================================================================

	/**
	 * All core plugin tables exist after activation.
	 *
	 * @return void
	 */
	public function test_all_core_tables_exist(): void {
		$wpdb = $this->get_wpdb();

		$table_names = Schema::get_all_tables();

		if ( empty( $table_names ) ) {
			$this->fail( 'Schema::get_all_tables() returned an empty list.' );
		}

		foreach ( $table_names as $short_name ) {
			$full_name = Schema::table( $short_name );
			$exists    = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $full_name )
			);

			if ( null === $exists ) {
				$this->markTestSkipped(
					"Plugin tables not present. Activate the plugin against this database first. Missing: {$full_name}"
				);
			}

			$this->assertSame( $full_name, $exists, "Table {$full_name} should exist." );
		}
	}

	/**
	 * Schema::table() returns a correctly prefixed table name.
	 *
	 * @return void
	 */
	public function test_schema_table_helper_returns_prefixed_name(): void {
		global $wpdb;
		$table = Schema::table( 'events' );

		$this->assertStringContainsString( $wpdb->prefix, $table );
		$this->assertStringContainsString( 'nettertech_events_events', $table );
	}

	// =========================================================================
	// Idempotency: create_tables() twice is safe
	// =========================================================================

	/**
	 * Running create_tables() a second time does not throw or produce errors.
	 *
	 * dbDelta() is designed to be idempotent: a second call only adds missing
	 * columns/indexes, it never drops existing data.
	 *
	 * @return void
	 */
	public function test_create_tables_is_idempotent(): void {
		$wpdb = $this->get_wpdb();

		// Capture wpdb errors before the first call.
		$wpdb->last_error = '';

		// First call (tables already exist from plugin activation).
		Schema::create_tables();
		$error_after_first = $wpdb->last_error;

		// Second call — must not produce a new wpdb error.
		Schema::create_tables();
		$error_after_second = $wpdb->last_error;

		$this->assertSame( '', $error_after_second, 'create_tables() should not produce wpdb errors on second call.' );
	}

	/**
	 * Row counts in core tables are unchanged after a second create_tables() call.
	 *
	 * This guards against a catastrophic regression where a second call truncates data.
	 *
	 * @return void
	 */
	public function test_create_tables_second_call_does_not_truncate_data(): void {
		$wpdb       = $this->get_wpdb();
		$table_name = Schema::table( 'events' );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( null === $exists ) {
			$this->markTestSkipped( 'events table not present; plugin not activated against this DB.' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from trusted Schema constant.
		$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		Schema::create_tables();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from trusted Schema constant.
		$count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		$this->assertSame( $count_before, $count_after, 'create_tables() must not delete existing rows.' );
	}

	/**
	 * The v3.11.0 per-occurrence override columns exist after table creation.
	 *
	 * dbDelta adds the new columns to the existing occurrences table without
	 * dropping data. Guards the NTE-077 migration against the existing schema.
	 *
	 * @return void
	 */
	public function test_occurrence_override_columns_exist_after_create(): void {
		$wpdb       = $this->get_wpdb();
		$table_name = Schema::table( 'occurrences' );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( null === $exists ) {
			$this->markTestSkipped( 'occurrences table not present; plugin not activated against this DB.' );
		}

		// dbDelta adds the columns from the updated table definition.
		Schema::create_tables();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from trusted Schema constant.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table_name}" );

		$this->assertContains( 'is_override', $columns );
		$this->assertContains( 'venue_name_override', $columns );
		$this->assertContains( 'venue_address_override', $columns );
		$this->assertContains( 'virtual_url_override', $columns );
	}

	// =========================================================================
	// needs_migration()
	// =========================================================================

	/**
	 * After the plugin has been used, needs_migration() returns false when the
	 * stored version matches Schema::DB_VERSION.
	 *
	 * @return void
	 */
	public function test_needs_migration_returns_false_when_current_version_matches(): void {
		// Simulate "just migrated" state.
		$original = get_option( 'nettertech_events_db_version' );
		update_option( 'nettertech_events_db_version', Schema::DB_VERSION );

		$result = Schema::needs_migration();

		// Restore original value.
		if ( false === $original ) {
			delete_option( 'nettertech_events_db_version' );
		} else {
			update_option( 'nettertech_events_db_version', $original );
		}

		$this->assertFalse( $result );
	}

	/**
	 * needs_migration() returns true when the stored version is lower than
	 * Schema::DB_VERSION.
	 *
	 * @return void
	 */
	public function test_needs_migration_returns_true_when_version_is_old(): void {
		$original = get_option( 'nettertech_events_db_version' );
		update_option( 'nettertech_events_db_version', '0.0.1' );

		$result = Schema::needs_migration();

		if ( false === $original ) {
			delete_option( 'nettertech_events_db_version' );
		} else {
			update_option( 'nettertech_events_db_version', $original );
		}

		$this->assertTrue( $result );
	}

	// =========================================================================
	// get_all_tables()
	// =========================================================================

	/**
	 * get_all_tables() returns a non-empty array of strings.
	 *
	 * @return void
	 */
	public function test_get_all_tables_returns_non_empty_array(): void {
		$tables = Schema::get_all_tables();

		$this->assertIsArray( $tables );
		$this->assertNotEmpty( $tables );
		$this->assertContainsOnly( 'string', $tables );
	}

	/**
	 * get_all_tables() includes the core events and occurrences tables.
	 *
	 * @return void
	 */
	public function test_get_all_tables_includes_core_tables(): void {
		$tables = Schema::get_all_tables();

		$this->assertContains( 'events', $tables );
		$this->assertContains( 'occurrences', $tables );
		$this->assertContains( 'ticket_types', $tables );
		$this->assertContains( 'attendees', $tables );
	}
}
