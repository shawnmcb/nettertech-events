<?php
/**
 * Schema unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Database
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Database;

use NetterTechEvents\Database\Schema;

/**
 * Test Schema class.
 */
class SchemaTest extends \NetterTechEventsTestCase {

	/**
	 * Mock wpdb object.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb_mock;

	/**
	 * Original wpdb instance.
	 *
	 * @var \wpdb|null
	 */
	private ?\wpdb $original_wpdb = null;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Save original wpdb.
		global $wpdb;
		$this->original_wpdb = $wpdb;

		// Create mock wpdb using the mock class from bootstrap.
		$this->wpdb_mock         = new \wpdb();
		$this->wpdb_mock->prefix = 'wp_';
	}

	/**
	 * Restore original wpdb after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// Restore original wpdb.
		global $wpdb;
		$wpdb = $this->original_wpdb;

		parent::tearDown();
	}

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test DB_VERSION constant exists.
	 *
	 * @return void
	 */
	public function test_db_version_constant_exists(): void {
		$this->assertTrue( defined( Schema::class . '::DB_VERSION' ) );
		$this->assertIsString( Schema::DB_VERSION );
	}

	/**
	 * Test DB_VERSION follows semantic versioning.
	 *
	 * @return void
	 */
	public function test_db_version_is_semver(): void {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', Schema::DB_VERSION );
	}

	/**
	 * Test TABLE_PREFIX constant exists.
	 *
	 * @return void
	 */
	public function test_table_prefix_constant_exists(): void {
		$this->assertTrue( defined( Schema::class . '::TABLE_PREFIX' ) );
		$this->assertSame( 'nettertech_events_', Schema::TABLE_PREFIX );
	}

	// =========================================================================
	// table() Method Tests
	// =========================================================================

	/**
	 * Test table method returns correct full table name.
	 *
	 * @return void
	 */
	public function test_table_returns_correct_full_name(): void {
		global $wpdb;
		$wpdb = $this->wpdb_mock;

		$result = Schema::table( 'events' );

		$this->assertSame( 'wp_nettertech_events_events', $result );
	}

	/**
	 * Test table method works with different table names.
	 *
	 * @return void
	 */
	public function test_table_works_with_various_names(): void {
		global $wpdb;
		$wpdb = $this->wpdb_mock;

		$this->assertSame( 'wp_nettertech_events_occurrences', Schema::table( 'occurrences' ) );
		$this->assertSame( 'wp_nettertech_events_ticket_types', Schema::table( 'ticket_types' ) );
		$this->assertSame( 'wp_nettertech_events_attendees', Schema::table( 'attendees' ) );
		$this->assertSame( 'wp_nettertech_events_tickets', Schema::table( 'tickets' ) );
	}

	/**
	 * Test table method respects custom prefix.
	 *
	 * @return void
	 */
	public function test_table_respects_custom_prefix(): void {
		global $wpdb;
		$wpdb         = $this->wpdb_mock;
		$wpdb->prefix = 'custom_';

		$result = Schema::table( 'events' );

		$this->assertSame( 'custom_nettertech_events_events', $result );
	}

	/**
	 * Test table method handles empty table name.
	 *
	 * @return void
	 */
	public function test_table_handles_empty_name(): void {
		global $wpdb;
		$wpdb = $this->wpdb_mock;

		$result = Schema::table( '' );

		$this->assertSame( 'wp_nettertech_events_', $result );
	}

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test class exists.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( Schema::class ) );
	}

	/**
	 * Test required methods exist.
	 *
	 * @return void
	 */
	public function test_required_methods_exist(): void {
		$methods = array(
			'create_tables',
			'table',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( Schema::class, $method ),
				"Method {$method} should exist"
			);
		}
	}

	/**
	 * Test create_tables is static.
	 *
	 * @return void
	 */
	public function test_create_tables_is_static(): void {
		$reflection = new \ReflectionMethod( Schema::class, 'create_tables' );
		$this->assertTrue( $reflection->isStatic() );
	}

	/**
	 * Test table is static.
	 *
	 * @return void
	 */
	public function test_table_is_static(): void {
		$reflection = new \ReflectionMethod( Schema::class, 'table' );
		$this->assertTrue( $reflection->isStatic() );
	}

	// =========================================================================
	// Known Table Names Tests
	// =========================================================================

	/**
	 * Test known table names are generated correctly.
	 *
	 * @return void
	 */
	public function test_known_tables_generated_correctly(): void {
		global $wpdb;
		$wpdb = $this->wpdb_mock;

		$expected_tables = array(
			'events'           => 'wp_nettertech_events_events',
			'occurrences'      => 'wp_nettertech_events_occurrences',
			'ticket_types'     => 'wp_nettertech_events_ticket_types',
			'attendees'        => 'wp_nettertech_events_attendees',
			'tickets'          => 'wp_nettertech_events_tickets',
			'series'           => 'wp_nettertech_events_series',
			'organizers'       => 'wp_nettertech_events_organizers',
			'event_organizers' => 'wp_nettertech_events_event_organizers',
			'categories'       => 'wp_nettertech_events_categories',
			'event_categories' => 'wp_nettertech_events_event_categories',
			'tags'             => 'wp_nettertech_events_tags',
			'event_tags'       => 'wp_nettertech_events_event_tags',
			'activity_log'     => 'wp_nettertech_events_activity_log',
		);

		foreach ( $expected_tables as $short => $full ) {
			$this->assertSame( $full, Schema::table( $short ), "Table {$short} should be {$full}" );
		}
	}

	// =========================================================================
	// create_tables Tests (Structure Only)
	// =========================================================================

	/**
	 * Test create_tables method returns void.
	 *
	 * @return void
	 */
	public function test_create_tables_returns_void(): void {
		$reflection  = new \ReflectionMethod( Schema::class, 'create_tables' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type );
		$this->assertSame( 'void', $return_type->getName() );
	}

	/**
	 * Test table definition classes exist.
	 *
	 * @return void
	 */
	public function test_table_definition_classes_exist(): void {
		$table_classes = array(
			\NetterTechEvents\Database\Tables\SeriesTable::class,
			\NetterTechEvents\Database\Tables\EventsTable::class,
			\NetterTechEvents\Database\Tables\OccurrencesTable::class,
			\NetterTechEvents\Database\Tables\TicketTypesTable::class,
			\NetterTechEvents\Database\Tables\AttendeesTable::class,
			\NetterTechEvents\Database\Tables\TicketsTable::class,
			\NetterTechEvents\Database\Tables\OrganizersTable::class,
			\NetterTechEvents\Database\Tables\EventOrganizersTable::class,
			\NetterTechEvents\Database\Tables\CategoriesTable::class,
			\NetterTechEvents\Database\Tables\EventCategoriesTable::class,
			\NetterTechEvents\Database\Tables\TagsTable::class,
			\NetterTechEvents\Database\Tables\EventTagsTable::class,
			\NetterTechEvents\Database\Tables\ActivityLogTable::class,
			\NetterTechEvents\Database\Tables\EventRevisionsTable::class,
		);

		foreach ( $table_classes as $class ) {
			$this->assertTrue(
				class_exists( $class ),
				"Table definition class {$class} should exist"
			);
		}
	}

	/**
	 * Test table definition classes implement interface.
	 *
	 * @return void
	 */
	public function test_table_definition_classes_implement_interface(): void {
		$table_classes = array(
			\NetterTechEvents\Database\Tables\SeriesTable::class,
			\NetterTechEvents\Database\Tables\EventsTable::class,
			\NetterTechEvents\Database\Tables\OccurrencesTable::class,
		);

		foreach ( $table_classes as $class ) {
			$instance = new $class();
			$this->assertInstanceOf(
				\NetterTechEvents\Database\Tables\TableDefinitionInterface::class,
				$instance,
				"Class {$class} should implement TableDefinitionInterface"
			);
		}
	}

	// =========================================================================
	// get_all_tables Tests
	// =========================================================================

	/**
	 * Test get_all_tables method exists.
	 *
	 * @return void
	 */
	public function test_get_all_tables_method_exists(): void {
		$this->assertTrue( method_exists( Schema::class, 'get_all_tables' ) );
	}

	/**
	 * Test get_all_tables is static.
	 *
	 * @return void
	 */
	public function test_get_all_tables_is_static(): void {
		$reflection = new \ReflectionMethod( Schema::class, 'get_all_tables' );
		$this->assertTrue( $reflection->isStatic() );
	}

	/**
	 * Test get_all_tables returns core tables by default.
	 *
	 * @return void
	 */
	public function test_get_all_tables_returns_core_tables(): void {
		$tables = Schema::get_all_tables();

		$this->assertIsArray( $tables );
		$this->assertContains( 'events', $tables );
		$this->assertContains( 'occurrences', $tables );
		$this->assertContains( 'ticket_types', $tables );
		$this->assertContains( 'attendees', $tables );
		$this->assertContains( 'tickets', $tables );
		$this->assertContains( 'series', $tables );
		$this->assertContains( 'organizers', $tables );
		$this->assertContains( 'event_organizers', $tables );
		$this->assertContains( 'categories', $tables );
		$this->assertContains( 'event_categories', $tables );
		$this->assertContains( 'tags', $tables );
		$this->assertContains( 'event_tags', $tables );
		$this->assertContains( 'activity_log', $tables );
		$this->assertContains( 'spaces', $tables );
	}

	/**
	 * Test get_all_tables without deferred tables.
	 *
	 * @return void
	 */
	public function test_get_all_tables_excludes_deferred_by_default(): void {
		$tables = Schema::get_all_tables( false );

		// Spaces is now a core table (moved from deferred in 3.5.0).
		$this->assertContains( 'spaces', $tables );

		// Deferred tables should NOT be in the list.
		$this->assertNotContains( 'bookings', $tables );
		$this->assertNotContains( 'seating_maps', $tables );
		$this->assertNotContains( 'seats', $tables );
		$this->assertNotContains( 'resources', $tables );
	}

	/**
	 * Test get_all_tables with deferred tables included.
	 *
	 * @return void
	 */
	public function test_get_all_tables_includes_deferred_when_requested(): void {
		$tables = Schema::get_all_tables( true );

		// Core tables should still be present.
		$this->assertContains( 'events', $tables );
		$this->assertContains( 'occurrences', $tables );

		// Spaces is now a core table (moved from deferred in 3.5.0).
		$this->assertContains( 'spaces', $tables );
		// Deferred tables should now be included.
		$this->assertContains( 'bookings', $tables );
		$this->assertContains( 'space_configurations', $tables );
		$this->assertContains( 'addon_types', $tables );
		$this->assertContains( 'booking_addons', $tables );
		// Seating tables removed — now in nettertech-events-seating add-on.
		$this->assertNotContains( 'seating_maps', $tables );
		$this->assertNotContains( 'seats', $tables );
		$this->assertNotContains( 'seat_assignments', $tables );
		$this->assertNotContains( 'seat_holds', $tables );
		$this->assertContains( 'resources', $tables );
		$this->assertContains( 'certification_types', $tables );
		$this->assertContains( 'user_certifications', $tables );
	}

	/**
	 * Test get_all_tables returns count of 13 core tables.
	 *
	 * @return void
	 */
	public function test_get_all_tables_core_count(): void {
		$tables = Schema::get_all_tables( false );

		$this->assertCount( 20, $tables );
	}

	// =========================================================================
	// needs_migration Tests
	// =========================================================================

	/**
	 * Test needs_migration method exists.
	 *
	 * @return void
	 */
	public function test_needs_migration_method_exists(): void {
		$this->assertTrue( method_exists( Schema::class, 'needs_migration' ) );
	}

	/**
	 * Test needs_migration is static.
	 *
	 * @return void
	 */
	public function test_needs_migration_is_static(): void {
		$reflection = new \ReflectionMethod( Schema::class, 'needs_migration' );
		$this->assertTrue( $reflection->isStatic() );
	}

	/**
	 * Test needs_migration returns true when no version stored (fresh install).
	 *
	 * When no version is stored, get_option returns the default '0.0.0',
	 * which is less than any proper version number.
	 *
	 * @return void
	 */
	public function test_needs_migration_returns_true_for_fresh_install(): void {
		// Simulate fresh install where no version is stored (default is '0.0.0').
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( '0.0.0' );

		$result = Schema::needs_migration();

		$this->assertTrue( $result );
	}

	/**
	 * Test needs_migration returns true when stored version is lower.
	 *
	 * @return void
	 */
	public function test_needs_migration_returns_true_for_old_version(): void {
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( '1.0.0' );

		$result = Schema::needs_migration();

		$this->assertTrue( $result );
	}

	/**
	 * Test needs_migration returns false when version matches.
	 *
	 * @return void
	 */
	public function test_needs_migration_returns_false_for_current_version(): void {
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( Schema::DB_VERSION );

		$result = Schema::needs_migration();

		$this->assertFalse( $result );
	}

	/**
	 * Test needs_migration returns false when version is higher.
	 *
	 * @return void
	 */
	public function test_needs_migration_returns_false_for_higher_version(): void {
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( '99.99.99' );

		$result = Schema::needs_migration();

		$this->assertFalse( $result );
	}

	// =========================================================================
	// migrate Tests
	// =========================================================================

	/**
	 * Test migrate method exists.
	 *
	 * @return void
	 */
	public function test_migrate_method_exists(): void {
		$this->assertTrue( method_exists( Schema::class, 'migrate' ) );
	}

	/**
	 * Test migrate is static.
	 *
	 * @return void
	 */
	public function test_migrate_is_static(): void {
		$reflection = new \ReflectionMethod( Schema::class, 'migrate' );
		$this->assertTrue( $reflection->isStatic() );
	}

	/**
	 * Test migrate is public.
	 *
	 * @return void
	 */
	public function test_migrate_is_public(): void {
		$reflection = new \ReflectionMethod( Schema::class, 'migrate' );
		$this->assertTrue( $reflection->isPublic() );
	}

	// =========================================================================
	// drop_tables Tests
	// =========================================================================

	/**
	 * Test drop_tables method exists.
	 *
	 * @return void
	 */
	public function test_drop_tables_method_exists(): void {
		$this->assertTrue( method_exists( Schema::class, 'drop_tables' ) );
	}

	/**
	 * Test drop_tables is static.
	 *
	 * @return void
	 */
	public function test_drop_tables_is_static(): void {
		$reflection = new \ReflectionMethod( Schema::class, 'drop_tables' );
		$this->assertTrue( $reflection->isStatic() );
	}

	/**
	 * Test drop_tables is public.
	 *
	 * @return void
	 */
	public function test_drop_tables_is_public(): void {
		$reflection = new \ReflectionMethod( Schema::class, 'drop_tables' );
		$this->assertTrue( $reflection->isPublic() );
	}

	// =========================================================================
	// Private Migration Methods Tests
	// =========================================================================

	/**
	 * Test migration methods exist for all versions.
	 *
	 * @return void
	 */
	public function test_migration_methods_exist(): void {
		$reflection = new \ReflectionClass( Schema::class );

		$migration_methods = array(
			'migrate_to_1_5_0',
			'migrate_to_1_6_0',
			'migrate_to_1_7_0',
			'migrate_to_1_8_0',
			'migrate_to_1_9_0',
			'migrate_to_2_0_0',
			'migrate_to_2_1_0',
			'migrate_to_2_2_0',
			'migrate_to_2_3_0',
			'migrate_to_2_4_0',
			'migrate_to_2_5_0',
			'migrate_to_2_6_0',
			'migrate_to_3_11_0',
			'migrate_to_3_12_0',
		);

		foreach ( $migration_methods as $method ) {
			$this->assertTrue(
				$reflection->hasMethod( $method ),
				"Migration method {$method} should exist"
			);
		}
	}

	/**
	 * Test the 3.12.0 sequence_number backfill migration is registered and current.
	 *
	 * Regression for NTE-077: the backfill must be dispatched (in the migration
	 * map) and DB_VERSION must be bumped so the version gate runs it once.
	 *
	 * @return void
	 */
	public function test_sequence_number_backfill_migration_registered(): void {
		// DB_VERSION must be at least 3.12.0 so the version gate runs the backfill once.
		$this->assertTrue(
			version_compare( Schema::DB_VERSION, '3.12.0', '>=' ),
			'DB_VERSION must be >= 3.12.0 so the 3.12.0 backfill version-gate fires.'
		);

		$map_method = new \ReflectionMethod( Schema::class, 'get_migration_map' );
		$map        = $map_method->invoke( null );

		$this->assertArrayHasKey( '3.12.0', $map );
		$this->assertSame( 'migrate_to_3_12_0', $map['3.12.0'] );
	}

	/**
	 * Test the 3.13.0 image-vertical-anchor migration is registered and current.
	 *
	 * Regression for NTE-119: the additive column migration must be dispatched
	 * (in the migration map), the method must exist, and DB_VERSION must be bumped
	 * to >= 3.13.0 so the version gate runs it once on the update path.
	 *
	 * @return void
	 */
	public function test_image_vertical_anchor_migration_registered(): void {
		$this->assertTrue(
			version_compare( Schema::DB_VERSION, '3.13.0', '>=' ),
			'DB_VERSION must be >= 3.13.0 so the image_vertical_anchor migration runs on update.'
		);

		$map_method = new \ReflectionMethod( Schema::class, 'get_migration_map' );
		$map        = $map_method->invoke( null );

		$this->assertArrayHasKey( '3.13.0', $map );
		$this->assertSame( 'migrate_to_3_13_0', $map['3.13.0'] );

		$reflection = new \ReflectionClass( Schema::class );
		$this->assertTrue( $reflection->hasMethod( 'migrate_to_3_13_0' ) );
	}

	/**
	 * Test the 3.14.0 occurrence-timezone backfill migration is registered and current.
	 *
	 * Regression for the timezone fix: the backfill ('UTC'/'' → site zone) must be
	 * dispatched in the migration map, the method must exist, and DB_VERSION must be
	 * >= 3.14.0 so the version gate runs it once on the update path.
	 *
	 * @return void
	 */
	public function test_occurrence_timezone_backfill_migration_registered(): void {
		$this->assertTrue(
			version_compare( Schema::DB_VERSION, '3.14.0', '>=' ),
			'DB_VERSION must be >= 3.14.0 so the occurrence-timezone backfill runs on update.'
		);

		$map_method = new \ReflectionMethod( Schema::class, 'get_migration_map' );
		$map        = $map_method->invoke( null );

		$this->assertArrayHasKey( '3.14.0', $map );
		$this->assertSame( 'migrate_to_3_14_0', $map['3.14.0'] );

		$reflection = new \ReflectionClass( Schema::class );
		$this->assertTrue( $reflection->hasMethod( 'migrate_to_3_14_0' ) );
	}

	/**
	 * Test migration methods are private.
	 *
	 * @return void
	 */
	public function test_migration_methods_are_private(): void {
		$migration_methods = array(
			'migrate_to_1_5_0',
			'migrate_to_1_6_0',
			'migrate_to_1_7_0',
		);

		foreach ( $migration_methods as $method ) {
			$reflection = new \ReflectionMethod( Schema::class, $method );
			$this->assertTrue(
				$reflection->isPrivate(),
				"Migration method {$method} should be private"
			);
		}
	}

	// =========================================================================
	// Deferred Table Creation Methods Tests
	// =========================================================================

	/**
	 * Test deferred schema methods exist.
	 *
	 * @return void
	 */
	public function test_deferred_schema_methods_exist(): void {
		$reflection = new \ReflectionClass( \NetterTechEvents\Database\DeferredSchema::class );

		$deferred_methods = array(
			'get_spaces_sql',
			'get_bookings_sql',
			'get_space_configurations_sql',
			'get_addon_types_sql',
			'get_booking_addons_sql',
			// Seating methods removed — now in nettertech-events-seating add-on.
			'get_resources_sql',
			'get_certification_types_sql',
			'get_user_certifications_sql',
		);

		foreach ( $deferred_methods as $method ) {
			$this->assertTrue(
				$reflection->hasMethod( $method ),
				"Deferred method {$method} should exist"
			);
		}
	}

	/**
	 * Test deferred schema get_table_names returns correct tables.
	 *
	 * @return void
	 */
	public function test_deferred_schema_get_table_names(): void {
		$tables = \NetterTechEvents\Database\DeferredSchema::get_table_names();

		$this->assertIsArray( $tables );
		// 'spaces' moved to core Schema in 3.5.0.
		$this->assertNotContains( 'spaces', $tables );
		$this->assertContains( 'bookings', $tables );
		$this->assertNotContains( 'seating_maps', $tables );
		$this->assertContains( 'resources', $tables );
	}

	// =========================================================================
	// create_tables Execution Tests
	// =========================================================================

	/**
	 * Test create_tables calls dbDelta for all core tables.
	 *
	 * @return void
	 */
	public function test_create_tables_calls_dbdelta_for_all_tables(): void {
		global $wpdb, $nettertech_events_dbdelta_queries;
		$wpdb = $this->wpdb_mock;
		$nettertech_events_dbdelta_queries = array();

		// Mock update_option.
		\Brain\Monkey\Functions\when( 'update_option' )->justReturn( true );

		Schema::create_tables();

		// Should have called dbDelta 18 times (one per core table).
		$this->assertCount( 20, $nettertech_events_dbdelta_queries );
	}

	/**
	 * Test create_tables SQL contains events table.
	 *
	 * @return void
	 */
	public function test_create_tables_creates_events_table(): void {
		global $wpdb, $nettertech_events_dbdelta_queries;
		$wpdb = $this->wpdb_mock;
		$nettertech_events_dbdelta_queries = array();

		\Brain\Monkey\Functions\when( 'update_option' )->justReturn( true );

		Schema::create_tables();

		$events_sql = $this->find_query_containing( $nettertech_events_dbdelta_queries, 'nettertech_events_events' );
		$this->assertNotNull( $events_sql, 'Events table SQL should exist' );
		$this->assertStringContainsString( 'CREATE TABLE', $events_sql );
		$this->assertStringContainsString( 'id bigint(20)', $events_sql );
		$this->assertStringContainsString( 'title varchar(255)', $events_sql );
		$this->assertStringContainsString( 'slug varchar(255)', $events_sql );
		$this->assertStringContainsString( 'status varchar(20)', $events_sql );
		$this->assertStringContainsString( 'venue_name', $events_sql );
		$this->assertStringContainsString( 'recurrence_rule', $events_sql );
		$this->assertStringContainsString( 'PRIMARY KEY', $events_sql );
	}

	/**
	 * Test create_tables SQL contains occurrences table.
	 *
	 * @return void
	 */
	public function test_create_tables_creates_occurrences_table(): void {
		global $wpdb, $nettertech_events_dbdelta_queries;
		$wpdb = $this->wpdb_mock;
		$nettertech_events_dbdelta_queries = array();

		\Brain\Monkey\Functions\when( 'update_option' )->justReturn( true );

		Schema::create_tables();

		$occurrences_sql = $this->find_query_containing( $nettertech_events_dbdelta_queries, 'nettertech_events_occurrences' );
		$this->assertNotNull( $occurrences_sql, 'Occurrences table SQL should exist' );
		$this->assertStringContainsString( 'event_id bigint(20)', $occurrences_sql );
		$this->assertStringContainsString( 'start_datetime datetime', $occurrences_sql );
		$this->assertStringContainsString( 'end_datetime datetime', $occurrences_sql );
		$this->assertStringContainsString( 'status varchar(20)', $occurrences_sql );
		$this->assertStringContainsString( 'checkin_token', $occurrences_sql );
		$this->assertStringContainsString( 'is_override tinyint(1)', $occurrences_sql );
		$this->assertStringContainsString( 'venue_name_override varchar(255)', $occurrences_sql );
		$this->assertStringContainsString( 'venue_address_override text', $occurrences_sql );
		$this->assertStringContainsString( 'virtual_url_override varchar(500)', $occurrences_sql );
	}

	/**
	 * Test create_tables SQL contains ticket_types table.
	 *
	 * @return void
	 */
	public function test_create_tables_creates_ticket_types_table(): void {
		global $wpdb, $nettertech_events_dbdelta_queries;
		$wpdb = $this->wpdb_mock;
		$nettertech_events_dbdelta_queries = array();

		\Brain\Monkey\Functions\when( 'update_option' )->justReturn( true );

		Schema::create_tables();

		$ticket_types_sql = $this->find_query_containing( $nettertech_events_dbdelta_queries, 'nettertech_events_ticket_types' );
		$this->assertNotNull( $ticket_types_sql, 'Ticket types table SQL should exist' );
		$this->assertStringContainsString( 'event_id bigint(20)', $ticket_types_sql );
		$this->assertStringContainsString( 'name varchar(255)', $ticket_types_sql );
		$this->assertStringContainsString( 'price decimal(10,2)', $ticket_types_sql );
		$this->assertStringContainsString( 'capacity int(10)', $ticket_types_sql );
		$this->assertStringContainsString( 'scope varchar(20)', $ticket_types_sql );
	}

	/**
	 * Test create_tables SQL contains attendees table.
	 *
	 * @return void
	 */
	public function test_create_tables_creates_attendees_table(): void {
		global $wpdb, $nettertech_events_dbdelta_queries;
		$wpdb = $this->wpdb_mock;
		$nettertech_events_dbdelta_queries = array();

		\Brain\Monkey\Functions\when( 'update_option' )->justReturn( true );

		Schema::create_tables();

		$attendees_sql = $this->find_query_containing( $nettertech_events_dbdelta_queries, 'nettertech_events_attendees' );
		$this->assertNotNull( $attendees_sql, 'Attendees table SQL should exist' );
		$this->assertStringContainsString( 'occurrence_id bigint(20)', $attendees_sql );
		$this->assertStringContainsString( 'wc_order_id bigint(20)', $attendees_sql );
		$this->assertStringContainsString( 'email varchar(255)', $attendees_sql );
		$this->assertStringContainsString( 'name varchar(255)', $attendees_sql );
		$this->assertStringContainsString( 'checked_in', $attendees_sql );
	}

	/**
	 * Test create_tables SQL contains tickets table.
	 *
	 * @return void
	 */
	public function test_create_tables_creates_tickets_table(): void {
		global $wpdb, $nettertech_events_dbdelta_queries;
		$wpdb = $this->wpdb_mock;
		$nettertech_events_dbdelta_queries = array();

		\Brain\Monkey\Functions\when( 'update_option' )->justReturn( true );

		Schema::create_tables();

		$tickets_sql = $this->find_query_containing( $nettertech_events_dbdelta_queries, 'nettertech_events_tickets' );
		$this->assertNotNull( $tickets_sql, 'Tickets table SQL should exist' );
		$this->assertStringContainsString( 'attendee_id bigint(20)', $tickets_sql );
		$this->assertStringContainsString( 'ticket_type_id bigint(20)', $tickets_sql );
		$this->assertStringContainsString( 'ticket_code varchar(64)', $tickets_sql );
		$this->assertStringContainsString( 'status varchar(20)', $tickets_sql );
	}

	/**
	 * Test create_tables SQL contains series table.
	 *
	 * @return void
	 */
	public function test_create_tables_creates_series_table(): void {
		global $wpdb, $nettertech_events_dbdelta_queries;
		$wpdb = $this->wpdb_mock;
		$nettertech_events_dbdelta_queries = array();

		\Brain\Monkey\Functions\when( 'update_option' )->justReturn( true );

		Schema::create_tables();

		$series_sql = $this->find_query_containing( $nettertech_events_dbdelta_queries, 'nettertech_events_series' );
		$this->assertNotNull( $series_sql, 'Series table SQL should exist' );
		$this->assertStringContainsString( 'title varchar(255)', $series_sql );
		$this->assertStringContainsString( 'slug varchar(255)', $series_sql );
		$this->assertStringContainsString( 'pass_enabled', $series_sql );
		$this->assertStringContainsString( 'pass_price', $series_sql );
	}

	/**
	 * Test create_tables does not stamp db version (callers are responsible).
	 *
	 * @return void
	 */
	public function test_create_tables_does_not_stamp_db_version(): void {
		global $wpdb;
		$wpdb = $this->wpdb_mock;

		\Brain\Monkey\Functions\expect( 'update_option' )
			->with( 'nettertech_events_db_version', \Mockery::any() )
			->never();

		Schema::create_tables();

		$this->assertTrue( true );
	}

	// =========================================================================
	// drop_tables Execution Tests
	// =========================================================================

	/**
	 * Test drop_tables executes DROP TABLE queries.
	 *
	 * @return void
	 */
	public function test_drop_tables_executes_drop_queries(): void {
		global $wpdb;
		$wpdb = $this->wpdb_mock;

		$dropped_queries = array();
		$wpdb_mock = $this->createPartialMock( \wpdb::class, array( 'query' ) );
		$wpdb_mock->prefix = 'wp_';
		$wpdb_mock->method( 'query' )->willReturnCallback(
			function ( $query ) use ( &$dropped_queries ) {
				$dropped_queries[] = $query;
				return true;
			}
		);
		$wpdb = $wpdb_mock;

		\Brain\Monkey\Functions\when( 'delete_option' )->justReturn( true );

		Schema::drop_tables();

		// Should have 18 DROP TABLE queries (one per core table).
		$this->assertCount( 20, $dropped_queries );

		// All queries should be DROP TABLE IF EXISTS.
		foreach ( $dropped_queries as $query ) {
			$this->assertStringContainsString( 'DROP TABLE IF EXISTS', $query );
		}
	}

	/**
	 * Test drop_tables includes deferred tables when requested.
	 *
	 * @return void
	 */
	public function test_drop_tables_includes_deferred_when_requested(): void {
		global $wpdb;
		$wpdb = $this->wpdb_mock;

		$dropped_queries = array();
		$wpdb_mock = $this->createPartialMock( \wpdb::class, array( 'query' ) );
		$wpdb_mock->prefix = 'wp_';
		$wpdb_mock->method( 'query' )->willReturnCallback(
			function ( $query ) use ( &$dropped_queries ) {
				$dropped_queries[] = $query;
				return true;
			}
		);
		$wpdb = $wpdb_mock;

		\Brain\Monkey\Functions\when( 'delete_option' )->justReturn( true );

		Schema::drop_tables( true );

		// Should have more than 13 queries (core + deferred).
		$this->assertGreaterThan( 13, count( $dropped_queries ) );
	}

	// =========================================================================
	// migrate Execution Tests
	// =========================================================================

	/**
	 * Test migrate does nothing when version is current.
	 *
	 * @return void
	 */
	public function test_migrate_does_nothing_when_current(): void {
		global $wpdb;
		$wpdb = $this->wpdb_mock;

		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( Schema::DB_VERSION );

		// Should not call update_option if no migration needed.
		\Brain\Monkey\Functions\expect( 'update_option' )->never();

		Schema::migrate();

		// No exception means success.
		$this->assertTrue( true );
	}

	/**
	 * Test migrate runs when version is old.
	 *
	 * @return void
	 */
	public function test_migrate_runs_for_old_version(): void {
		global $wpdb, $nettertech_events_dbdelta_queries;
		$wpdb = $this->wpdb_mock;
		$nettertech_events_dbdelta_queries = array();

		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( '1.0.0' );
		\Brain\Monkey\Functions\when( 'update_option' )->justReturn( true );

		Schema::migrate();

		// Should have created tables as part of migration.
		$this->assertGreaterThan( 0, count( $nettertech_events_dbdelta_queries ) );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Find a query containing a substring.
	 *
	 * @param array  $queries  Array of SQL queries.
	 * @param string $contains Substring to search for.
	 * @return string|null The matching query or null.
	 */
	private function find_query_containing( array $queries, string $contains ): ?string {
		foreach ( $queries as $query ) {
			if ( strpos( $query, $contains ) !== false ) {
				return $query;
			}
		}
		return null;
	}
}
