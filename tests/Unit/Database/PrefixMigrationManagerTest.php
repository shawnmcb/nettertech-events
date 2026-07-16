<?php
/**
 * PrefixMigrationManager unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Database
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Database;

use NetterTechEvents\Database\PrefixMigrationManager;

/**
 * Test PrefixMigrationManager Step 2 migration logic.
 */
class PrefixMigrationManagerTest extends \NetterTechEventsTestCase {

	/**
	 * Original wpdb instance.
	 *
	 * @var \wpdb|null
	 */
	private ?\wpdb $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->original_wpdb = $wpdb;
	}

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
		$mock                 = $this->createPartialMock( \wpdb::class, $methods );
		$mock->prefix         = 'wp_';
		$mock->posts          = 'wp_posts';
		$mock->term_taxonomy  = 'wp_term_taxonomy';
		$mock->postmeta       = 'wp_postmeta';
		$mock->options        = 'wp_options';
		$mock->usermeta       = 'wp_usermeta';
		return $mock;
	}

	/**
	 * Test maybe_migrate exits early when completion flag is set.
	 *
	 * @return void
	 */
	public function test_maybe_migrate_skips_when_already_complete(): void {
		$calls = array();
		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function () use ( &$calls ) {
				$args    = func_get_args();
				$calls[] = $args[0];
				if ( PrefixMigrationManager::COMPLETION_OPTION === $args[0] ) {
					return true;
				}
				return $args[1] ?? false;
			}
		);

		PrefixMigrationManager::maybe_migrate();

		$this->assertSame( array( PrefixMigrationManager::COMPLETION_OPTION ), $calls );
	}

	/**
	 * Test maybe_migrate marks complete and exits on a fresh install
	 * (no old tables, no legacy options, no legacy post types).
	 *
	 * @return void
	 */
	public function test_maybe_migrate_marks_complete_on_fresh_install(): void {
		global $wpdb;

		$mock = $this->create_wpdb_mock( array( 'get_var', 'prepare' ) );
		$mock->method( 'prepare' )->willReturnArgument( 0 );
		// needs_migration() walks: SHOW TABLES LIKE (null = no legacy table),
		// then one COUNT(*) per entry in post_type_map (typically 3 entries,
		// each '0' = no legacy posts of that type).
		$mock->method( 'get_var' )->willReturn( null );
		$wpdb = $mock;

		$update_calls = array();
		\Brain\Monkey\Functions\when( 'get_option' )->alias( fn() => false );
		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$update_calls ) {
				$update_calls[] = $name;
				return true;
			}
		);
		\Brain\Monkey\Functions\when( 'get_transient' )->justReturn( false );
		\Brain\Monkey\Functions\when( 'set_transient' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'delete_transient' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'wp_next_scheduled' )->justReturn( false );

		PrefixMigrationManager::maybe_migrate();

		$this->assertContains( PrefixMigrationManager::COMPLETION_OPTION, $update_calls );
	}

	/**
	 * Test maybe_migrate aborts on table conflict and does NOT mark complete.
	 *
	 * @return void
	 */
	public function test_maybe_migrate_aborts_on_table_conflict(): void {
		global $wpdb;

		$mock = $this->create_wpdb_mock( array( 'get_var', 'prepare', 'query', 'esc_like' ) );
		$mock->method( 'prepare' )->willReturnArgument( 0 );
		$mock->method( 'esc_like' )->willReturnArgument( 0 );

		// needs_migration: SHOW TABLES LIKE returns truthy → true.
		// preflight (table_exists per name × 20 names × 2 = 40) — both old and new return truthy.
		// run_migration: migrate_tables sees both → conflict.
		$mock->method( 'get_var' )->willReturn( 'wp_nte_events' );

		$wpdb = $mock;

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => false
		);
		$update_calls = array();
		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$update_calls ) {
				$update_calls[] = $name;
				return true;
			}
		);
		\Brain\Monkey\Functions\when( 'get_transient' )->justReturn( false );
		\Brain\Monkey\Functions\when( 'set_transient' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'delete_transient' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'wp_json_encode' )->alias(
			fn( $v ) => json_encode( $v )
		);

		PrefixMigrationManager::maybe_migrate();

		// Log option should be written, completion flag should NOT.
		$this->assertContains( PrefixMigrationManager::LOG_OPTION, $update_calls );
		$this->assertNotContains( PrefixMigrationManager::COMPLETION_OPTION, $update_calls );
	}

	/**
	 * Test that the lock prevents re-entry when already held.
	 *
	 * @return void
	 */
	public function test_maybe_migrate_skips_when_lock_held(): void {
		global $wpdb;

		$mock = $this->create_wpdb_mock( array( 'get_var', 'prepare', 'esc_like' ) );
		$mock->method( 'prepare' )->willReturnArgument( 0 );
		$mock->method( 'esc_like' )->willReturnArgument( 0 );
		// needs_migration returns true (old table found).
		$mock->method( 'get_var' )->willReturn( 'wp_nte_events' );
		$wpdb = $mock;

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => false
		);
		// Lock already held.
		\Brain\Monkey\Functions\when( 'get_transient' )->alias(
			fn( $name ) => PrefixMigrationManager::LOCK_TRANSIENT === $name ? time() : false
		);
		$set_transient_calls = array();
		\Brain\Monkey\Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $ttl ) use ( &$set_transient_calls ) {
				$set_transient_calls[] = $name;
				return true;
			}
		);
		\Brain\Monkey\Functions\when( 'delete_transient' )->justReturn( true );

		PrefixMigrationManager::maybe_migrate();

		// set_transient should never have been called for the migration lock.
		$this->assertNotContains( PrefixMigrationManager::LOCK_TRANSIENT, $set_transient_calls );
	}
}
