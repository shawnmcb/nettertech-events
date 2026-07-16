<?php
/**
 * MigrationManager unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Database
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Database;

use NetterTechEvents\Database\MigrationManager;

/**
 * Test MigrationManager class.
 */
class MigrationManagerTest extends \NetterTechEventsTestCase {

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

		global $wpdb;
		$this->original_wpdb = $wpdb;

		$this->wpdb_mock                = new \wpdb();
		$this->wpdb_mock->prefix        = 'wp_';
		$this->wpdb_mock->posts         = 'wp_posts';
		$this->wpdb_mock->term_taxonomy = 'wp_term_taxonomy';
		$this->wpdb_mock->postmeta      = 'wp_postmeta';
		$this->wpdb_mock->options       = 'wp_options';
		$this->wpdb_mock->usermeta      = 'wp_usermeta';
	}

	/**
	 * Restore original wpdb after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;

		parent::tearDown();
	}

	/**
	 * Create a wpdb partial mock with standard table properties.
	 *
	 * @param array<string> $methods Methods to mock.
	 * @return \wpdb
	 */
	private function create_wpdb_mock( array $methods ): \wpdb {
		$mock                = $this->createPartialMock( \wpdb::class, $methods );
		$mock->prefix        = 'wp_';
		$mock->posts         = 'wp_posts';
		$mock->term_taxonomy = 'wp_term_taxonomy';
		$mock->postmeta      = 'wp_postmeta';
		$mock->options       = 'wp_options';
		$mock->usermeta      = 'wp_usermeta';
		return $mock;
	}

	/**
	 * Stub all WordPress functions needed by run_migration().
	 *
	 * @param array<string, mixed> $option_map Map of option_name => return value for get_option.
	 * @return void
	 */
	private function stub_run_migration_functions( array $option_map = array() ): void {
		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () use ( $option_map ) {
				$args = func_get_args();
				$key  = $args[0];
				if ( array_key_exists( $key, $option_map ) ) {
					return $option_map[ $key ];
				}
				// Default: return the provided default or false.
				return $args[1] ?? false;
			}
		);

		\Brain\Monkey\Functions\when( 'update_option' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'delete_option' )->justReturn( true );
		\Brain\Monkey\Functions\when( '_get_cron_array' )->justReturn( array() );
		\Brain\Monkey\Functions\when( '_set_cron_array' )->justReturn( true );
	}

	// =========================================================================
	// maybe_migrate() Flow Control Tests
	// =========================================================================

	/**
	 * Test maybe_migrate skips when migration already complete.
	 *
	 * @return void
	 */
	public function test_maybe_migrate_skips_when_already_complete(): void {
		global $wpdb;
		$wpdb = $this->wpdb_mock;

		// Completion option returns truthy — migration already done.
		$get_option_calls = array();
		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () use ( &$get_option_calls ) {
				$args               = func_get_args();
				$get_option_calls[] = $args[0];
				if ( 'nettertech_events_legacy_rebrand_complete' === $args[0] ) {
					return true;
				}
				return $args[1] ?? false;
			}
		);

		\Brain\Monkey\Functions\when( '_get_cron_array' )->justReturn( array() );
		\Brain\Monkey\Functions\when( '_set_cron_array' )->justReturn( true );

		MigrationManager::maybe_migrate();

		// v1.0.2 upgrade-path transition runs first (reads legacy state keys),
		// then the completion-marker check.
		$this->assertSame(
			array(
				'nte_rebrand_migration_complete',
				'nte_rebrand_migration_log',
				'nettertech_events_legacy_rebrand_complete',
			),
			$get_option_calls
		);
	}

	/**
	 * Test maybe_migrate skips on fresh install with no legacy data.
	 *
	 * @return void
	 */
	public function test_maybe_migrate_skips_on_fresh_install(): void {
		global $wpdb;
		$wpdb_mock = $this->create_wpdb_mock( array( 'get_var' ) );

		// No old tables, no legacy posts.
		$wpdb_mock->method( 'get_var' )
			->willReturnOnConsecutiveCalls( null, '0' );

		$wpdb = $wpdb_mock;

		$update_option_calls = array();
		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () {
				$args = func_get_args();
				if ( 'nettertech_events_legacy_rebrand_complete' === $args[0] ) {
					return false;
				}
				if ( 'venue_events_settings' === $args[0] ) {
					return false;
				}
				return $args[1] ?? false;
			}
		);

		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function () use ( &$update_option_calls ) {
				$update_option_calls[] = func_get_args();
				return true;
			}
		);

		\Brain\Monkey\Functions\when( '_get_cron_array' )->justReturn( array() );
		\Brain\Monkey\Functions\when( '_set_cron_array' )->justReturn( true );

		MigrationManager::maybe_migrate();

		// No update_option calls means no migration ran.
		$this->assertEmpty( $update_option_calls );
	}

	// =========================================================================
	// needs_migration() Detection Tests
	// =========================================================================

	/**
	 * Test needs_migration detects old ve_* tables.
	 *
	 * @return void
	 */
	public function test_needs_migration_detects_old_tables(): void {
		global $wpdb;
		$wpdb_mock = $this->create_wpdb_mock( array( 'get_var' ) );

		// SHOW TABLES LIKE 'wp_ve_%' returns a table name.
		$wpdb_mock->method( 'get_var' )
			->willReturn( 'wp_ve_events' );

		$wpdb = $wpdb_mock;

		$method = new \ReflectionMethod( MigrationManager::class, 'needs_migration' );

		$result = $method->invoke( null );

		$this->assertTrue( $result );
	}

	/**
	 * Test needs_migration detects old options.
	 *
	 * @return void
	 */
	public function test_needs_migration_detects_old_options(): void {
		global $wpdb;
		$wpdb_mock = $this->create_wpdb_mock( array( 'get_var' ) );

		// No old tables found.
		$wpdb_mock->method( 'get_var' )
			->willReturn( null );

		$wpdb = $wpdb_mock;

		$method = new \ReflectionMethod( MigrationManager::class, 'needs_migration' );

		// venue_events_settings exists.
		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () {
				$args = func_get_args();
				if ( 'venue_events_settings' === $args[0] ) {
					return array( 'some' => 'settings' );
				}
				return $args[1] ?? false;
			}
		);

		$result = $method->invoke( null );

		$this->assertTrue( $result );
	}

	/**
	 * Test needs_migration detects old post types.
	 *
	 * @return void
	 */
	public function test_needs_migration_detects_old_post_types(): void {
		global $wpdb;
		$wpdb_mock = $this->create_wpdb_mock( array( 'get_var' ) );

		// First: SHOW TABLES → null (no old tables).
		// Second: SELECT COUNT(*) → '5' (legacy posts found).
		$wpdb_mock->method( 'get_var' )
			->willReturnOnConsecutiveCalls( null, '5' );

		$wpdb = $wpdb_mock;

		$method = new \ReflectionMethod( MigrationManager::class, 'needs_migration' );

		// venue_events_settings not found.
		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () {
				$args = func_get_args();
				if ( 'venue_events_settings' === $args[0] ) {
					return false;
				}
				return $args[1] ?? false;
			}
		);

		$result = $method->invoke( null );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// migrate_tables() Tests
	// =========================================================================

	/**
	 * Test migrate_tables renames when old table exists and new does not.
	 *
	 * @return void
	 */
	public function test_migrate_tables_renames_when_old_exists(): void {
		global $wpdb;

		$executed_queries = array();
		$wpdb_mock        = $this->create_wpdb_mock( array( 'get_var', 'query' ) );

		$call_count = 0;
		$wpdb_mock->method( 'get_var' )
			->willReturnCallback(
				function ( string $query ) use ( &$call_count ) {
					++$call_count;
					// First call: old table wp_ve_events exists.
					if ( 1 === $call_count ) {
						return 'wp_ve_events';
					}
					// Second call: new table wp_nte_events does not exist.
					if ( 2 === $call_count ) {
						return null;
					}
					// All remaining: no tables exist.
					return null;
				}
			);

		$wpdb_mock->method( 'query' )
			->willReturnCallback(
				function ( string $query ) use ( &$executed_queries ) {
					$executed_queries[] = $query;
					return 1;
				}
			);

		$wpdb = $wpdb_mock;

		$method = new \ReflectionMethod( MigrationManager::class, 'migrate_tables' );

		$result = $method->invoke( null );

		$this->assertContains( 'events', $result );
		$this->assertCount( 1, $executed_queries );
		$this->assertStringContainsString( 'RENAME TABLE', $executed_queries[0] );
		$this->assertStringContainsString( 'wp_ve_events', $executed_queries[0] );
		$this->assertStringContainsString( 'wp_nte_events', $executed_queries[0] );
	}

	/**
	 * Test migrate_tables skips when both old and new tables exist.
	 *
	 * @return void
	 */
	public function test_migrate_tables_skips_when_both_exist(): void {
		global $wpdb;

		$executed_queries = array();
		$wpdb_mock        = $this->create_wpdb_mock( array( 'get_var', 'query' ) );

		$call_count = 0;
		$wpdb_mock->method( 'get_var' )
			->willReturnCallback(
				function ( string $query ) use ( &$call_count ) {
					++$call_count;
					// First call: old table exists.
					if ( 1 === $call_count ) {
						return 'wp_ve_events';
					}
					// Second call: new table also exists.
					if ( 2 === $call_count ) {
						return 'wp_nte_events';
					}
					return null;
				}
			);

		$wpdb_mock->method( 'query' )
			->willReturnCallback(
				function ( string $query ) use ( &$executed_queries ) {
					$executed_queries[] = $query;
					return 1;
				}
			);

		$wpdb = $wpdb_mock;

		$method = new \ReflectionMethod( MigrationManager::class, 'migrate_tables' );

		$result = $method->invoke( null );

		$this->assertNotContains( 'events', $result );
		$this->assertEmpty( $executed_queries );
	}

	// =========================================================================
	// migrate_post_type() Tests
	// =========================================================================

	/**
	 * Test migrate_post_type updates both old name variants.
	 *
	 * @return void
	 */
	public function test_migrate_post_type_updates_both_old_names(): void {
		global $wpdb;

		$executed_queries = array();
		$wpdb_mock        = $this->create_wpdb_mock( array( 'query' ) );

		$wpdb_mock->method( 'query' )
			->willReturnCallback(
				function ( string $query ) use ( &$executed_queries ) {
					$executed_queries[] = $query;
					return 3;
				}
			);

		$wpdb = $wpdb_mock;

		$method = new \ReflectionMethod( MigrationManager::class, 'migrate_post_type' );

		$result = $method->invoke( null );

		$this->assertSame( 3, $result );
		$this->assertCount( 1, $executed_queries );
		$this->assertStringContainsString( "post_type = 'nte_event'", $executed_queries[0] );
		$this->assertStringContainsString( "'ve_event'", $executed_queries[0] );
		$this->assertStringContainsString( "'venue_event'", $executed_queries[0] );
	}

	// =========================================================================
	// migrate_taxonomy() Tests
	// =========================================================================

	/**
	 * Test migrate_taxonomy renames venue_event_category to nte_event_category.
	 *
	 * @return void
	 */
	public function test_migrate_taxonomy_renames(): void {
		global $wpdb;

		$executed_queries = array();
		$wpdb_mock        = $this->create_wpdb_mock( array( 'query' ) );

		$wpdb_mock->method( 'query' )
			->willReturnCallback(
				function ( string $query ) use ( &$executed_queries ) {
					$executed_queries[] = $query;
					return 7;
				}
			);

		$wpdb = $wpdb_mock;

		$method = new \ReflectionMethod( MigrationManager::class, 'migrate_taxonomy' );

		$result = $method->invoke( null );

		$this->assertSame( 7, $result );
		$this->assertCount( 1, $executed_queries );
		$this->assertStringContainsString( "taxonomy = 'nte_event_category'", $executed_queries[0] );
		$this->assertStringContainsString( "'venue_event_category'", $executed_queries[0] );
		$this->assertStringContainsString( 'wp_term_taxonomy', $executed_queries[0] );
	}

	// =========================================================================
	// migrate_options() Tests
	// =========================================================================

	/**
	 * Test migrate_options renames known option keys.
	 *
	 * @return void
	 */
	public function test_migrate_options_renames_known_keys(): void {
		global $wpdb;
		$wpdb = $this->wpdb_mock;

		$method = new \ReflectionMethod( MigrationManager::class, 'migrate_options' );

		$updated_options = array();
		$deleted_options = array();

		// Set up get_option to return the old value only for venue_events_settings.
		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () {
				$args    = func_get_args();
				$key     = $args[0];

				// Old option exists with a value.
				if ( 'venue_events_settings' === $key ) {
					return array( 'key' => 'value' );
				}

				// New option does not exist yet.
				if ( 'nettertech_events_settings' === $key ) {
					return false;
				}

				// All other old options don't exist — return the explicit default.
				return array_key_exists( 1, $args ) ? $args[1] : false;
			}
		);

		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function () use ( &$updated_options ) {
				$args                      = func_get_args();
				$updated_options[ $args[0] ] = $args[1];
				return true;
			}
		);

		\Brain\Monkey\Functions\when( 'delete_option' )->alias(
			function ( $key ) use ( &$deleted_options ) {
				$deleted_options[] = $key;
				return true;
			}
		);

		$result = $method->invoke( null );

		// Only venue_events_settings should have been migrated.
		$this->assertContains( 'venue_events_settings', $result );
		$this->assertCount( 1, $result );

		// New option should be set with old value.
		$this->assertArrayHasKey( 'nettertech_events_settings', $updated_options );
		$this->assertSame( array( 'key' => 'value' ), $updated_options['nettertech_events_settings'] );

		// Old option should be deleted.
		$this->assertContains( 'venue_events_settings', $deleted_options );
	}

	/**
	 * Test migrate_options skips when old option is missing.
	 *
	 * @return void
	 */
	public function test_migrate_options_skips_when_old_missing(): void {
		global $wpdb;
		$wpdb = $this->wpdb_mock;

		$method = new \ReflectionMethod( MigrationManager::class, 'migrate_options' );

		$updated_options = array();
		$deleted_options = array();

		// All get_option calls return the default (null for old key checks).
		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () {
				$args = func_get_args();
				return array_key_exists( 1, $args ) ? $args[1] : false;
			}
		);

		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function () use ( &$updated_options ) {
				$updated_options[] = func_get_args();
				return true;
			}
		);

		\Brain\Monkey\Functions\when( 'delete_option' )->alias(
			function () use ( &$deleted_options ) {
				$deleted_options[] = func_get_args();
				return true;
			}
		);

		$result = $method->invoke( null );

		$this->assertEmpty( $result );
		$this->assertEmpty( $updated_options );
		$this->assertEmpty( $deleted_options );
	}

	// =========================================================================
	// run_migration() Completion Tests
	// =========================================================================

	/**
	 * Test completion marker is set after full migration.
	 *
	 * @return void
	 */
	public function test_completion_marker_is_set(): void {
		global $wpdb;
		$wpdb_mock = $this->create_wpdb_mock( array( 'get_var', 'query' ) );
		$wpdb_mock->method( 'get_var' )->willReturn( null );
		$wpdb_mock->method( 'query' )->willReturn( 0 );
		$wpdb = $wpdb_mock;

		$method = new \ReflectionMethod( MigrationManager::class, 'run_migration' );

		$updated_options = array();

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () {
				$args = func_get_args();
				return $args[1] ?? false;
			}
		);

		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function () use ( &$updated_options ) {
				$args                        = func_get_args();
				$updated_options[ $args[0] ] = $args[1];
				return true;
			}
		);

		\Brain\Monkey\Functions\when( 'delete_option' )->justReturn( true );
		\Brain\Monkey\Functions\when( '_get_cron_array' )->justReturn( array() );
		\Brain\Monkey\Functions\when( '_set_cron_array' )->justReturn( true );

		$method->invoke( null );

		$this->assertArrayHasKey( 'nettertech_events_legacy_rebrand_complete', $updated_options );
		$this->assertTrue( $updated_options['nettertech_events_legacy_rebrand_complete'] );
	}

	/**
	 * Test migration log is written with expected structure.
	 *
	 * @return void
	 */
	public function test_migration_log_is_written(): void {
		global $wpdb;
		$wpdb_mock = $this->create_wpdb_mock( array( 'get_var', 'query' ) );
		$wpdb_mock->method( 'get_var' )->willReturn( null );
		$wpdb_mock->method( 'query' )->willReturn( 0 );
		$wpdb = $wpdb_mock;

		$method = new \ReflectionMethod( MigrationManager::class, 'run_migration' );

		$updated_options = array();

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () {
				$args = func_get_args();
				return $args[1] ?? false;
			}
		);

		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function () use ( &$updated_options ) {
				$args                        = func_get_args();
				$updated_options[ $args[0] ] = $args[1];
				return true;
			}
		);

		\Brain\Monkey\Functions\when( 'delete_option' )->justReturn( true );
		\Brain\Monkey\Functions\when( '_get_cron_array' )->justReturn( array() );
		\Brain\Monkey\Functions\when( '_set_cron_array' )->justReturn( true );

		$method->invoke( null );

		$this->assertArrayHasKey( 'nettertech_events_legacy_rebrand_log', $updated_options );

		$log = $updated_options['nettertech_events_legacy_rebrand_log'];
		$this->assertIsArray( $log );
		$this->assertArrayHasKey( 'migrated_at', $log );
		$this->assertArrayHasKey( 'tables_renamed', $log );
		$this->assertArrayHasKey( 'posts_updated', $log );
		$this->assertArrayHasKey( 'terms_updated', $log );
		$this->assertArrayHasKey( 'options_renamed', $log );
		$this->assertArrayHasKey( 'meta_keys_updated', $log );
		$this->assertArrayHasKey( 'transients_renamed', $log );
		$this->assertArrayHasKey( 'user_meta_updated', $log );
		$this->assertArrayHasKey( 'cron_hooks_updated', $log );
	}

	/**
	 * Test action hook fires on migration completion.
	 *
	 * @return void
	 */
	public function test_action_hook_fires_on_completion(): void {
		global $wpdb;
		$wpdb_mock = $this->create_wpdb_mock( array( 'get_var', 'query' ) );
		$wpdb_mock->method( 'get_var' )->willReturn( null );
		$wpdb_mock->method( 'query' )->willReturn( 0 );
		$wpdb = $wpdb_mock;

		$method = new \ReflectionMethod( MigrationManager::class, 'run_migration' );

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () {
				$args = func_get_args();
				return array_key_exists( 1, $args ) ? $args[1] : false;
			}
		);

		\Brain\Monkey\Functions\when( 'update_option' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'delete_option' )->justReturn( true );
		\Brain\Monkey\Functions\when( '_get_cron_array' )->justReturn( array() );
		\Brain\Monkey\Functions\when( '_set_cron_array' )->justReturn( true );

		$do_action_calls = array();
		\Brain\Monkey\Functions\when( 'do_action' )->alias(
			function () use ( &$do_action_calls ) {
				$args = func_get_args();
				$do_action_calls[ $args[0] ] = array_slice( $args, 1 );
			}
		);

		$method->invoke( null );

		$this->assertArrayHasKey( 'nettertech_events_legacy_rebrand_complete', $do_action_calls );
		$this->assertIsArray( $do_action_calls['nettertech_events_legacy_rebrand_complete'][0] );
	}

	// =========================================================================
	// Idempotency Tests
	// =========================================================================

	/**
	 * Test second call to maybe_migrate is a no-op after completion marker set.
	 *
	 * @return void
	 */
	public function test_idempotency_second_run_is_noop(): void {
		global $wpdb;
		$wpdb = $this->wpdb_mock;

		$get_option_calls    = array();
		$update_option_calls = array();

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () use ( &$get_option_calls ) {
				$args               = func_get_args();
				$get_option_calls[] = $args[0];
				if ( 'nettertech_events_legacy_rebrand_complete' === $args[0] ) {
					return true;
				}
				return $args[1] ?? false;
			}
		);

		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function () use ( &$update_option_calls ) {
				$update_option_calls[] = func_get_args();
				return true;
			}
		);

		\Brain\Monkey\Functions\when( '_get_cron_array' )->justReturn( array() );
		\Brain\Monkey\Functions\when( '_set_cron_array' )->justReturn( true );

		MigrationManager::maybe_migrate();

		// v1.0.2 upgrade-path transition runs first (reads legacy state keys),
		// then the completion-marker check.
		$this->assertSame(
			array(
				'nte_rebrand_migration_complete',
				'nte_rebrand_migration_log',
				'nettertech_events_legacy_rebrand_complete',
			),
			$get_option_calls
		);

		// No update_option calls means no migration ran.
		$this->assertEmpty( $update_option_calls );
	}

	// =========================================================================
	// v1.0.2 Upgrade-Path Tests (state-option transition)
	// =========================================================================

	/**
	 * Test the v1.0.2 transition forwards legacy completion + log keys onto
	 * the new canonical-prefix keys, then deletes the legacy keys.
	 *
	 * @return void
	 */
	public function test_v102_transition_forwards_legacy_state_options_onto_new_keys(): void {
		global $wpdb;
		$wpdb_mock = $this->create_wpdb_mock( array( 'get_var' ) );
		// No legacy ve_* data — only the state-option transition should run.
		$wpdb_mock->method( 'get_var' )->willReturn( null );
		$wpdb = $wpdb_mock;

		$option_store = array(
			'nte_rebrand_migration_complete' => true,
			'nte_rebrand_migration_log'      => array( 'migrated_at' => '2026-04-01 00:00:00' ),
		);
		$update_option_calls = array();
		$delete_option_calls = array();

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () use ( &$option_store ) {
				$args = func_get_args();
				$key  = $args[0];
				if ( array_key_exists( $key, $option_store ) ) {
					return $option_store[ $key ];
				}
				return $args[1] ?? false;
			}
		);

		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function () use ( &$update_option_calls, &$option_store ) {
				$args                  = func_get_args();
				$update_option_calls[] = $args;
				$option_store[ $args[0] ] = $args[1];
				return true;
			}
		);

		\Brain\Monkey\Functions\when( 'delete_option' )->alias(
			function () use ( &$delete_option_calls, &$option_store ) {
				$args                  = func_get_args();
				$delete_option_calls[] = $args[0];
				unset( $option_store[ $args[0] ] );
				return true;
			}
		);

		\Brain\Monkey\Functions\when( '_get_cron_array' )->justReturn( array() );
		\Brain\Monkey\Functions\when( '_set_cron_array' )->justReturn( true );

		MigrationManager::maybe_migrate();

		// Both legacy keys forwarded onto the new keys.
		$write_keys = array_map(
			fn ( array $call ): string => $call[0],
			$update_option_calls
		);
		$this->assertContains( 'nettertech_events_legacy_rebrand_complete', $write_keys );
		$this->assertContains( 'nettertech_events_legacy_rebrand_log', $write_keys );

		// Both legacy keys deleted after forwarding.
		$this->assertContains( 'nte_rebrand_migration_complete', $delete_option_calls );
		$this->assertContains( 'nte_rebrand_migration_log', $delete_option_calls );
	}

	/**
	 * Test the v1.0.2 transition does NOT clobber a value already at the new key.
	 *
	 * @return void
	 */
	public function test_v102_transition_does_not_clobber_existing_new_key(): void {
		global $wpdb;
		$wpdb_mock = $this->create_wpdb_mock( array( 'get_var' ) );
		$wpdb_mock->method( 'get_var' )->willReturn( null );
		$wpdb = $wpdb_mock;

		$option_store = array(
			'nte_rebrand_migration_complete'              => true,
			'nettertech_events_legacy_rebrand_complete' => true, // Already exists.
		);
		$update_option_calls = array();
		$delete_option_calls = array();

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () use ( &$option_store ) {
				$args = func_get_args();
				$key  = $args[0];
				if ( array_key_exists( $key, $option_store ) ) {
					return $option_store[ $key ];
				}
				return $args[1] ?? false;
			}
		);

		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function () use ( &$update_option_calls ) {
				$update_option_calls[] = func_get_args();
				return true;
			}
		);

		\Brain\Monkey\Functions\when( 'delete_option' )->alias(
			function () use ( &$delete_option_calls ) {
				$args                  = func_get_args();
				$delete_option_calls[] = $args[0];
				return true;
			}
		);

		\Brain\Monkey\Functions\when( '_get_cron_array' )->justReturn( array() );
		\Brain\Monkey\Functions\when( '_set_cron_array' )->justReturn( true );

		MigrationManager::maybe_migrate();

		// The new key already had a value — do NOT overwrite.
		$write_keys = array_map( fn ( array $call ): string => $call[0], $update_option_calls );
		$this->assertNotContains( 'nettertech_events_legacy_rebrand_complete', $write_keys );

		// But the legacy key still gets cleaned up.
		$this->assertContains( 'nte_rebrand_migration_complete', $delete_option_calls );
	}

	/**
	 * Test the v1.0.2 transition is a no-op on a fresh install that never
	 * ran v1.0.0/1.0.1 (no legacy state keys present).
	 *
	 * @return void
	 */
	public function test_v102_transition_is_noop_on_fresh_install(): void {
		global $wpdb;
		$wpdb_mock = $this->create_wpdb_mock( array( 'get_var' ) );
		$wpdb_mock->method( 'get_var' )->willReturn( null );
		$wpdb = $wpdb_mock;

		$update_option_calls = array();
		$delete_option_calls = array();

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () {
				$args = func_get_args();
				return $args[1] ?? false;
			}
		);

		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function () use ( &$update_option_calls ) {
				$update_option_calls[] = func_get_args();
				return true;
			}
		);

		\Brain\Monkey\Functions\when( 'delete_option' )->alias(
			function () use ( &$delete_option_calls ) {
				$args                  = func_get_args();
				$delete_option_calls[] = $args[0];
				return true;
			}
		);

		\Brain\Monkey\Functions\when( '_get_cron_array' )->justReturn( array() );
		\Brain\Monkey\Functions\when( '_set_cron_array' )->justReturn( true );

		MigrationManager::maybe_migrate();

		// No legacy keys → no forwarding writes, no legacy-key deletes.
		$this->assertEmpty( $update_option_calls );
		$this->assertEmpty( $delete_option_calls );
	}

	/**
	 * Test the canonical completion hook fires when the migration completes.
	 *
	 * The legacy `nte_rebrand_migration_complete` bridge hook was removed for
	 * the initial WP.org submission — only the canonical action fires now.
	 *
	 * @return void
	 */
	public function test_run_migration_fires_canonical_completion_action(): void {
		global $wpdb;
		$wpdb_mock = $this->create_wpdb_mock( array( 'get_var', 'query' ) );
		// needs_migration(): no ve_* tables (null), no legacy posts ('0').
		// Then run_migration() loops table_exists() over each of the 16 known tables;
		// each call gets null (no legacy table exists, no new table either) so
		// nothing renames — that's fine for this hook-firing test.
		$wpdb_mock->method( 'get_var' )->willReturn( null );
		$wpdb_mock->method( 'query' )->willReturn( 0 );
		$wpdb = $wpdb_mock;

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () {
				$args = func_get_args();
				if ( 'venue_events_settings' === $args[0] ) {
					// Make needs_migration() return true via this branch.
					return array( 'some' => 'data' );
				}
				return $args[1] ?? false;
			}
		);
		\Brain\Monkey\Functions\when( 'update_option' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'delete_option' )->justReturn( true );
		\Brain\Monkey\Functions\when( '_get_cron_array' )->justReturn( array() );
		\Brain\Monkey\Functions\when( '_set_cron_array' )->justReturn( true );

		$do_action_calls = array();
		\Brain\Monkey\Functions\when( 'do_action' )->alias(
			function () use ( &$do_action_calls ) {
				$args                   = func_get_args();
				$do_action_calls[ $args[0] ] = $args;
				return null;
			}
		);

		MigrationManager::maybe_migrate();

		$this->assertArrayHasKey( 'nettertech_events_legacy_rebrand_complete', $do_action_calls );
		$this->assertArrayNotHasKey(
			'nte_rebrand_migration_complete',
			$do_action_calls,
			'Legacy bridge hook was removed for initial WP.org submission; only canonical action should fire.'
		);
	}
}
