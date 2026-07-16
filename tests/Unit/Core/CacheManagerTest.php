<?php
/**
 * CacheManager unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Contracts\HouseCapacityRepositoryInterface;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\TemplateLoader\Templates;
use Brain\Monkey\Functions;

/**
 * Test CacheManager class functionality.
 */
class CacheManagerTest extends \NetterTechEventsTestCase {

	/**
	 * CacheManager instance.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache_manager;

	/**
	 * Mock house capacity repository.
	 *
	 * @var HouseCapacityRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $house_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset wpdb mock.
		$GLOBALS['wpdb'] = new \wpdb();

		$this->house_repo = $this->createMock( HouseCapacityRepositoryInterface::class );
		$this->house_repo->method( 'house_peer_ids' )->willReturn( array() );

		global $wpdb;
		$this->cache_manager = new CacheManager( $wpdb, Templates::get_instance(), $this->house_repo );
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
		$this->assertTrue( class_exists( CacheManager::class ) );
	}

	/**
	 * Test CACHE_GROUP constant value.
	 *
	 * @return void
	 */
	public function test_cache_group_constant(): void {
		$this->assertSame( 'nettertech_events', CacheManager::CACHE_GROUP );
	}

	/**
	 * Test TTL constants are integers.
	 *
	 * @return void
	 */
	public function test_ttl_constants_are_integers(): void {
		$this->assertIsInt( CacheManager::TTL_CAPACITY );
		$this->assertIsInt( CacheManager::TTL_OCCURRENCE );
		$this->assertIsInt( CacheManager::TTL_EVENT );
	}

	/**
	 * Test TTL_CAPACITY is 60 seconds.
	 *
	 * @return void
	 */
	public function test_ttl_capacity_value(): void {
		$this->assertSame( 60, CacheManager::TTL_CAPACITY );
	}

	/**
	 * Test TTL_OCCURRENCE is 300 seconds.
	 *
	 * @return void
	 */
	public function test_ttl_occurrence_value(): void {
		$this->assertSame( 300, CacheManager::TTL_OCCURRENCE );
	}

	/**
	 * Test TTL_EVENT is 3600 seconds.
	 *
	 * @return void
	 */
	public function test_ttl_event_value(): void {
		$this->assertSame( 3600, CacheManager::TTL_EVENT );
	}

	// =========================================================================
	// register Tests
	// =========================================================================

	/**
	 * Test register adds hooks.
	 *
	 * @return void
	 */
	public function test_register_adds_hooks(): void {
		$hooks_added = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$hooks_added ) {
				$hooks_added[] = $hook;
				return true;
			}
		);

		$this->cache_manager->register();

		$expected_hooks = array(
			'nettertech_events_after_save_event',
			'nettertech_events_after_delete_event',
			'nettertech_events_occurrences_generated',
			'nettertech_events_occurrence_status_changed',
			'nettertech_events_attendee_created',
			'nettertech_events_attendee_cancelled',
			'nettertech_events_capacity_reserved',
			'nettertech_events_capacity_released',
			'nettertech_events_buffer_stock_updated',
		);

		foreach ( $expected_hooks as $expected ) {
			$this->assertContains( $expected, $hooks_added, "Hook '{$expected}' should be registered" );
		}
	}

	// =========================================================================
	// invalidate_all Tests
	// =========================================================================

	/**
	 * Test invalidate_all deletes transients.
	 *
	 * @return void
	 */
	public function test_invalidate_all_deletes_transients(): void {
		$query_executed = null;

		$wpdb          = new \stdClass();
		$wpdb->options = 'wp_options';
		$wpdb->query   = function ( $query ) use ( &$query_executed ) {
			$query_executed = $query;
			return 1;
		};

		// Use Mockery for wpdb.
		$mock_wpdb          = \Mockery::mock( 'wpdb' );
		$mock_wpdb->options = 'wp_options';
		$mock_wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			function ( $text ) {
				return addcslashes( $text, '_%\\' );
			}
		);
		$mock_wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $query, ...$args ) {
				// Simple placeholder replacement for test.
				$i = 0;
				return (string) preg_replace_callback(
					'/%[sdf]/',
					function () use ( $args, &$i ) {
						return isset( $args[ $i ] ) ? "'" . addslashes( (string) $args[ $i++ ] ) . "'" : "''";
					},
					$query
				);
			}
		);
		$mock_wpdb->shouldReceive( 'query' )->once()->andReturnUsing(
			function ( $query ) use ( &$query_executed ) {
				$query_executed = $query;
				return 1;
			}
		);

		$GLOBALS['wpdb'] = $mock_wpdb;

		Functions\when( 'wp_cache_flush_group' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );

		$cache_manager = new CacheManager( $mock_wpdb, Templates::get_instance(), $this->house_repo );
		$cache_manager->invalidate_all();

		// esc_like() escapes underscores, so check for the escaped pattern.
		$this->assertStringContainsString( 'nettertech', $query_executed );
		$this->assertStringContainsString( 'events', $query_executed );
		$this->assertStringContainsString( 'transient', $query_executed );
	}

	/**
	 * Test invalidate_all clears cache group.
	 *
	 * @return void
	 */
	public function test_invalidate_all_clears_cache_group(): void {
		$group_flushed = null;

		$mock_wpdb          = \Mockery::mock( 'wpdb' );
		$mock_wpdb->options = 'wp_options';
		$mock_wpdb->shouldReceive( 'esc_like' )->andReturnUsing( fn( $text ) => addcslashes( $text, '_%\\' ) );
		$mock_wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 ); // Return query unchanged for this test.
		$mock_wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$GLOBALS['wpdb'] = $mock_wpdb;

		Functions\when( 'wp_cache_flush_group' )->alias(
			function ( $group ) use ( &$group_flushed ) {
				$group_flushed = $group;
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		$this->cache_manager->invalidate_all();

		$this->assertSame( 'nettertech_events', $group_flushed );
	}

	/**
	 * Test invalidate_all fires action hook.
	 *
	 * @return void
	 */
	public function test_invalidate_all_fires_action(): void {
		$action_fired = null;

		$mock_wpdb          = \Mockery::mock( 'wpdb' );
		$mock_wpdb->options = 'wp_options';
		$mock_wpdb->shouldReceive( 'esc_like' )->andReturnUsing( fn( $text ) => addcslashes( $text, '_%\\' ) );
		$mock_wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$mock_wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$GLOBALS['wpdb'] = $mock_wpdb;

		Functions\when( 'wp_cache_flush_group' )->justReturn( true );
		Functions\when( 'do_action' )->alias(
			function ( $action ) use ( &$action_fired ) {
				$action_fired = $action;
			}
		);

		$this->cache_manager->invalidate_all();

		$this->assertSame( 'nettertech_events_cache_invalidated', $action_fired );
	}

	// =========================================================================
	// event_transient_key Tests
	// =========================================================================

	/**
	 * Test event_transient_key returns correct key format.
	 *
	 * @return void
	 */
	public function test_event_transient_key_format(): void {
		$key = $this->cache_manager->event_transient_key( 42, 'details' );

		$this->assertSame( 'nettertech_events_event_42_details', $key );
	}

	/**
	 * Test event_transient_key includes suffix correctly.
	 *
	 * @return void
	 */
	public function test_event_transient_key_includes_suffix(): void {
		$key = $this->cache_manager->event_transient_key( 7, 'occurrences' );

		$this->assertSame( 'nettertech_events_event_7_occurrences', $key );
	}

	// =========================================================================
	// invalidate_event Tests
	// =========================================================================

	/**
	 * Test invalidate_event targeted scope deletes legacy series transient.
	 *
	 * @return void
	 */
	public function test_invalidate_event_deletes_legacy_series_transient(): void {
		$deleted_transient = null;

		$mock_wpdb          = \Mockery::mock( 'wpdb' );
		$mock_wpdb->options = 'wp_options';
		$mock_wpdb->shouldReceive( 'esc_like' )->andReturnUsing( fn( $text ) => addcslashes( $text, '_%\\' ) );
		$mock_wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$mock_wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$GLOBALS['wpdb'] = $mock_wpdb;

		Functions\when( 'delete_transient' )->alias(
			function ( $key ) use ( &$deleted_transient ) {
				$deleted_transient = $key;
				return true;
			}
		);
		Functions\when( 'wp_cache_flush_group' )->justReturn( true );

		$this->cache_manager->invalidate_event( 123 );

		$this->assertSame( 'nettertech_events_series_123', $deleted_transient );
	}

	/**
	 * Test invalidate_event targeted scope queries DB for event-specific transients.
	 *
	 * Verifies esc_like() is called with an event-specific pattern (not the global nte_ prefix).
	 *
	 * @return void
	 */
	public function test_invalidate_event_targeted_queries_event_transients(): void {
		$esc_like_calls = array();

		$mock_wpdb          = \Mockery::mock( 'wpdb' );
		$mock_wpdb->options = 'wp_options';
		$mock_wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			function ( $text ) use ( &$esc_like_calls ) {
				$esc_like_calls[] = $text;
				return addcslashes( $text, '_%\\' );
			}
		);
		$mock_wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$mock_wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$GLOBALS['wpdb'] = $mock_wpdb;

		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_cache_flush_group' )->justReturn( true );

		$cache_manager = new CacheManager( $mock_wpdb, Templates::get_instance(), $this->house_repo );
		$cache_manager->invalidate_event( 55 );

		// esc_like should be called with event-specific transient prefix patterns.
		$this->assertContains( '_transient_nettertech_events_event_55_', $esc_like_calls, 'esc_like should be called with event-specific transient pattern' );
		$this->assertContains( '_transient_timeout_nettertech_events_event_55_', $esc_like_calls, 'esc_like should be called with event-specific timeout transient pattern' );
	}

	/**
	 * Test invalidate_event targeted scope does NOT use the broad nte_ prefix pattern.
	 *
	 * invalidate_all() uses '_transient_nettertech_events_' (without 'event_').
	 * Targeted invalidation should only use '_transient_nettertech_events_event_{id}_'.
	 *
	 * @return void
	 */
	public function test_invalidate_event_targeted_does_not_flush_all(): void {
		$esc_like_calls = array();

		$mock_wpdb          = \Mockery::mock( 'wpdb' );
		$mock_wpdb->options = 'wp_options';
		$mock_wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			function ( $text ) use ( &$esc_like_calls ) {
				$esc_like_calls[] = $text;
				return addcslashes( $text, '_%\\' );
			}
		);
		$mock_wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$mock_wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$GLOBALS['wpdb'] = $mock_wpdb;

		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_cache_flush_group' )->justReturn( true );

		$cache_manager = new CacheManager( $mock_wpdb, Templates::get_instance(), $this->house_repo );
		$cache_manager->invalidate_event( 10 );

		// The broad prefix used by invalidate_all() should not appear.
		// invalidate_all() passes '_transient_nettertech_events_' (without 'event_').
		// Targeted invalidation uses '_transient_nettertech_events_event_10_'.
		$this->assertNotContains( '_transient_nettertech_events_', $esc_like_calls, 'Targeted invalidation should not use the broad invalidate_all() prefix' );
		$this->assertNotContains( '_transient_timeout_nettertech_events_', $esc_like_calls, 'Targeted invalidation should not use the broad invalidate_all() timeout prefix' );
		// Confirm event-specific patterns ARE present, ensuring we actually asserted something meaningful.
		$this->assertContains( '_transient_nettertech_events_event_10_', $esc_like_calls );
	}

	/**
	 * Test invalidate_event with scope 'all' delegates to invalidate_all.
	 *
	 * @return void
	 */
	public function test_invalidate_event_scope_all_calls_invalidate_all(): void {
		$query_called = false;

		$mock_wpdb          = \Mockery::mock( 'wpdb' );
		$mock_wpdb->options = 'wp_options';
		$mock_wpdb->shouldReceive( 'esc_like' )->andReturnUsing( fn( $text ) => addcslashes( $text, '_%\\' ) );
		$mock_wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$mock_wpdb->shouldReceive( 'query' )->andReturnUsing(
			function () use ( &$query_called ) {
				$query_called = true;
				return 1;
			}
		);

		$GLOBALS['wpdb'] = $mock_wpdb;

		Functions\when( 'wp_cache_flush_group' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );

		$cache_manager = new CacheManager( $mock_wpdb, Templates::get_instance(), $this->house_repo );
		$cache_manager->invalidate_event( 99, 'all' );

		// invalidate_all() runs a DB query — confirm it was called.
		$this->assertTrue( $query_called );
	}

	/**
	 * Test invalidate_event targeted scope clears the object cache group.
	 *
	 * @return void
	 */
	public function test_invalidate_event_targeted_flushes_cache_group(): void {
		$group_flushed = null;

		$mock_wpdb          = \Mockery::mock( 'wpdb' );
		$mock_wpdb->options = 'wp_options';
		$mock_wpdb->shouldReceive( 'esc_like' )->andReturnUsing( fn( $text ) => addcslashes( $text, '_%\\' ) );
		$mock_wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$mock_wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$GLOBALS['wpdb'] = $mock_wpdb;

		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_cache_flush_group' )->alias(
			function ( $group ) use ( &$group_flushed ) {
				$group_flushed = $group;
				return true;
			}
		);

		$this->cache_manager->invalidate_event( 77 );

		$this->assertSame( 'nettertech_events', $group_flushed );
	}

	// =========================================================================
	// Hook handler method Tests
	// =========================================================================

	/**
	 * Test register uses named handler methods for event save/delete/occurrences hooks.
	 *
	 * @return void
	 */
	public function test_register_uses_named_handlers_for_event_hooks(): void {
		$hooks_registered = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) use ( &$hooks_registered ) {
				$method                  = is_array( $callback ) ? $callback[1] : null;
				$hooks_registered[$hook] = $method;
				return true;
			}
		);

		$this->cache_manager->register();

		$this->assertSame( 'on_after_save_event', $hooks_registered['nettertech_events_after_save_event'] );
		$this->assertSame( 'on_after_delete_event', $hooks_registered['nettertech_events_after_delete_event'] );
		$this->assertSame( 'on_occurrences_generated', $hooks_registered['nettertech_events_occurrences_generated'] );
	}

	// =========================================================================
	// invalidate_capacity_for_ticket_type Tests
	// =========================================================================

	/**
	 * Test invalidate_capacity_for_ticket_type deletes cache keys.
	 *
	 * @return void
	 */
	public function test_invalidate_capacity_for_ticket_type(): void {
		$deleted_keys = array();

		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group ) use ( &$deleted_keys ) {
				$deleted_keys[] = array( 'key' => $key, 'group' => $group );
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		$this->cache_manager->invalidate_capacity_for_ticket_type( 456 );

		$this->assertCount( 3, $deleted_keys );
		$this->assertSame( 'capacity_summary_456', $deleted_keys[0]['key'] );
		$this->assertSame( 'nettertech_events', $deleted_keys[0]['group'] );
		$this->assertSame( 'capacity_available_456', $deleted_keys[1]['key'] );

		// The pending variant is a separate key and was previously left behind,
		// so a cart hold kept reading a stale count after the sale that cleared it.
		$this->assertSame( 'capacity_available_456_pending', $deleted_keys[2]['key'] );
	}

	/**
	 * Test invalidate_capacity_for_ticket_type clears every tier sharing the house.
	 *
	 * @return void
	 */
	public function test_invalidate_capacity_clears_the_whole_house(): void {
		$deleted_keys = array();

		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group ) use ( &$deleted_keys ) {
				$deleted_keys[] = $key;
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		$house_repo = $this->createMock( HouseCapacityRepositoryInterface::class );
		$house_repo->method( 'house_peer_ids' )->willReturn( array( 11, 12, 13 ) );

		global $wpdb;
		$cache_manager = new CacheManager( $wpdb, Templates::get_instance(), $house_repo );

		// Selling on tier 11 changes what tiers 12 and 13 have left, because all
		// three draw from the same room.
		$cache_manager->invalidate_capacity_for_ticket_type( 11 );

		$this->assertContains( 'capacity_available_12', $deleted_keys );
		$this->assertContains( 'capacity_available_13', $deleted_keys );
		$this->assertContains( 'capacity_available_12_pending', $deleted_keys );
	}

	/**
	 * Test invalidate_capacity_for_ticket_type fires action.
	 *
	 * @return void
	 */
	public function test_invalidate_capacity_for_ticket_type_fires_action(): void {
		$action_args = array();

		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'do_action' )->alias(
			function ( $action, $arg ) use ( &$action_args ) {
				$action_args = array( 'action' => $action, 'arg' => $arg );
			}
		);

		$this->cache_manager->invalidate_capacity_for_ticket_type( 789 );

		$this->assertSame( 'nettertech_events_capacity_cache_invalidated', $action_args['action'] );
		$this->assertSame( 789, $action_args['arg'] );
	}

	// =========================================================================
	// invalidate_capacity_for_occurrence Tests
	// =========================================================================

	/**
	 * Test invalidate_capacity_for_occurrence deletes cache keys.
	 *
	 * @return void
	 */
	public function test_invalidate_capacity_for_occurrence(): void {
		$deleted_keys = array();

		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group ) use ( &$deleted_keys ) {
				$deleted_keys[] = array( 'key' => $key, 'group' => $group );
				return true;
			}
		);

		$this->cache_manager->invalidate_capacity_for_occurrence( 100 );

		$this->assertCount( 2, $deleted_keys );
		$this->assertSame( 'capacity_occurrence_100', $deleted_keys[0]['key'] );
		$this->assertSame( 'ticket_types_occurrence_100', $deleted_keys[1]['key'] );
	}

	// =========================================================================
	// get_cache_version Tests
	// =========================================================================

	/**
	 * Test get_cache_version returns integer.
	 *
	 * @return void
	 */
	public function test_get_cache_version_returns_integer(): void {
		Functions\when( 'get_option' )->justReturn( 5 );

		$version = CacheManager::get_cache_version();

		$this->assertIsInt( $version );
		$this->assertSame( 5, $version );
	}

	/**
	 * Test get_cache_version returns 1 as default.
	 *
	 * @return void
	 */
	public function test_get_cache_version_returns_default(): void {
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				return $default;
			}
		);

		$version = CacheManager::get_cache_version();

		$this->assertSame( 1, $version );
	}

	// =========================================================================
	// bump_cache_version Tests
	// =========================================================================

	/**
	 * Test bump_cache_version increments version.
	 *
	 * @return void
	 */
	public function test_bump_cache_version_increments(): void {
		$updated_value = null;

		Functions\when( 'get_option' )->justReturn( 3 );
		Functions\when( 'update_option' )->alias(
			function ( $option, $value ) use ( &$updated_value ) {
				$updated_value = $value;
				return true;
			}
		);

		CacheManager::bump_cache_version();

		$this->assertSame( 4, $updated_value );
	}

	// =========================================================================
	// versioned_key Tests
	// =========================================================================

	/**
	 * Test versioned_key appends version.
	 *
	 * @return void
	 */
	public function test_versioned_key_appends_version(): void {
		Functions\when( 'get_option' )->justReturn( 7 );

		$key = CacheManager::versioned_key( 'my_cache_key' );

		$this->assertSame( 'my_cache_key_v7', $key );
	}

	/**
	 * Test versioned_key with default version.
	 *
	 * @return void
	 */
	public function test_versioned_key_with_default_version(): void {
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				return $default;
			}
		);

		$key = CacheManager::versioned_key( 'test_key' );

		$this->assertSame( 'test_key_v1', $key );
	}
}
