<?php
/**
 * Cache effectiveness tests.
 *
 * These tests verify that caching is properly integrated and improves
 * performance by reducing database queries on repeated operations.
 *
 * @package NetterTechEvents\Tests\Performance
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Performance;

use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Services\CapacityService;
use NetterTechEvents\TemplateLoader\Templates;
use Brain\Monkey\Functions;

/**
 * Test cache effectiveness patterns.
 */
class CacheEffectivenessTest extends \NetterTechEventsTestCase {

	/**
	 * CacheManager instance.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache_manager;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb']     = new \wpdb();
		$this->cache_manager = new CacheManager( $GLOBALS['wpdb'], Templates::get_instance() );
	}

	// =========================================================================
	// TTL Strategy Tests
	// =========================================================================

	/**
	 * Test capacity data uses short TTL due to checkout sensitivity.
	 *
	 * @return void
	 */
	public function test_capacity_ttl_is_short_for_checkout_accuracy(): void {
		// 60 seconds is short enough to keep capacity accurate during checkout.
		$this->assertSame( 60, CacheManager::TTL_CAPACITY );
		$this->assertLessThan( 120, CacheManager::TTL_CAPACITY, 'Capacity TTL should be under 2 minutes' );
	}

	/**
	 * Test occurrence data uses medium TTL.
	 *
	 * @return void
	 */
	public function test_occurrence_ttl_is_medium(): void {
		// 5 minutes is reasonable for occurrence data which changes less frequently.
		$this->assertSame( 300, CacheManager::TTL_OCCURRENCE );
	}

	/**
	 * Test event metadata uses long TTL.
	 *
	 * @return void
	 */
	public function test_event_ttl_is_long(): void {
		// 1 hour for event metadata which rarely changes.
		$this->assertSame( 3600, CacheManager::TTL_EVENT );
	}

	/**
	 * Test TTL hierarchy makes sense (capacity < occurrence < event).
	 *
	 * @return void
	 */
	public function test_ttl_hierarchy_is_logical(): void {
		$this->assertLessThan(
			CacheManager::TTL_OCCURRENCE,
			CacheManager::TTL_CAPACITY,
			'Capacity should have shorter TTL than occurrences'
		);

		$this->assertLessThan(
			CacheManager::TTL_EVENT,
			CacheManager::TTL_OCCURRENCE,
			'Occurrences should have shorter TTL than events'
		);
	}

	// =========================================================================
	// Cache Invalidation Tests
	// =========================================================================

	/**
	 * Test cache invalidation hooks are registered.
	 *
	 * @return void
	 */
	public function test_invalidation_hooks_are_comprehensive(): void {
		$hooks_registered = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$hooks_registered ) {
				$hooks_registered[] = $hook;
				return true;
			}
		);

		$this->cache_manager->register();

		// Data modification hooks should trigger invalidation.
		$expected_hooks = array(
			'nettertech_events_after_save_event',
			'nettertech_events_after_delete_event',
			'nettertech_events_occurrences_generated',
			'nettertech_events_occurrence_status_changed',
			'nettertech_events_attendee_created',
			'nettertech_events_attendee_cancelled',
			'nettertech_events_capacity_reserved',
			'nettertech_events_capacity_released',
		);

		foreach ( $expected_hooks as $hook ) {
			$this->assertContains(
				$hook,
				$hooks_registered,
				"Cache should invalidate on {$hook}"
			);
		}
	}

	/**
	 * Test targeted invalidation for capacity changes.
	 *
	 * @return void
	 */
	public function test_capacity_invalidation_is_targeted(): void {
		$deleted_keys = array();

		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group ) use ( &$deleted_keys ) {
				$deleted_keys[] = $key;
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		$this->cache_manager->invalidate_capacity_for_ticket_type( 123 );

		// Should only delete specific ticket type cache, not all caches.
		$this->assertContains( 'capacity_summary_123', $deleted_keys );
		$this->assertContains( 'capacity_available_123', $deleted_keys );
		$this->assertCount( 2, $deleted_keys, 'Should only delete specific keys, not all' );
	}

	/**
	 * Test occurrence invalidation is targeted.
	 *
	 * @return void
	 */
	public function test_occurrence_invalidation_is_targeted(): void {
		$deleted_keys = array();

		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group ) use ( &$deleted_keys ) {
				$deleted_keys[] = $key;
				return true;
			}
		);

		$this->cache_manager->invalidate_capacity_for_occurrence( 456 );

		$this->assertContains( 'capacity_occurrence_456', $deleted_keys );
		$this->assertContains( 'ticket_types_occurrence_456', $deleted_keys );
		$this->assertCount( 2, $deleted_keys );
	}

	// =========================================================================
	// Versioned Cache Key Tests
	// =========================================================================

	/**
	 * Test versioned cache keys enable bulk invalidation.
	 *
	 * @return void
	 */
	public function test_versioned_keys_enable_bulk_invalidation(): void {
		Functions\when( 'get_option' )->justReturn( 5 );

		$key1 = CacheManager::versioned_key( 'my_cache' );

		$this->assertSame( 'my_cache_v5', $key1 );
	}

	/**
	 * Test bumping version invalidates all versioned caches.
	 *
	 * @return void
	 */
	public function test_version_bump_invalidates_versioned_caches(): void {
		$current_version = 5;
		$updated_version = null;

		Functions\when( 'get_option' )->justReturn( $current_version );
		Functions\when( 'update_option' )->alias(
			function ( $option, $value ) use ( &$updated_version ) {
				$updated_version = $value;
				return true;
			}
		);

		// Key before bump.
		$old_key = CacheManager::versioned_key( 'my_cache' );

		CacheManager::bump_cache_version();

		// After bump, same base key would produce different versioned key.
		$this->assertSame( 6, $updated_version );

		// New lookups with old key would miss cache (version mismatch).
		$this->assertSame( 'my_cache_v5', $old_key );
		// New key would be 'my_cache_v6'.
	}

	// =========================================================================
	// Cache Group Tests
	// =========================================================================

	/**
	 * Test all caches use consistent group name.
	 *
	 * @return void
	 */
	public function test_cache_group_is_consistent(): void {
		$this->assertSame( 'nettertech_events', CacheManager::CACHE_GROUP );

		// This allows wp_cache_flush_group() to clear all plugin caches at once.
	}

	/**
	 * Test invalidate_all flushes entire cache group.
	 *
	 * @return void
	 */
	public function test_invalidate_all_flushes_group(): void {
		$flushed_group = null;

		$mock_wpdb          = \Mockery::mock( 'wpdb' );
		$mock_wpdb->options = 'wp_options';
		$mock_wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$GLOBALS['wpdb'] = $mock_wpdb;

		Functions\when( 'wp_cache_flush_group' )->alias(
			function ( $group ) use ( &$flushed_group ) {
				$flushed_group = $group;
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		$this->cache_manager->invalidate_all();

		$this->assertSame( 'nettertech_events', $flushed_group );
	}

	// =========================================================================
	// Transient Cleanup Tests
	// =========================================================================

	/**
	 * Test transient cleanup pattern.
	 *
	 * @return void
	 */
	public function test_transient_cleanup_on_invalidate_all(): void {
		$query_executed = null;

		$mock_wpdb          = \Mockery::mock( 'wpdb' );
		$mock_wpdb->options = 'wp_options';
		$mock_wpdb->shouldReceive( 'esc_like' )->andReturnArg( 0 );
		$mock_wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $query, ...$args ) {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}
		);
		$mock_wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $query ) use ( &$query_executed ) {
				$query_executed = $query;
				return 1;
			}
		);

		$GLOBALS['wpdb'] = $mock_wpdb;

		Functions\when( 'wp_cache_flush_group' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );

		$cache_manager = new CacheManager( $mock_wpdb, Templates::get_instance() );
		$cache_manager->invalidate_all();

		// Should delete both transient and timeout entries.
		$this->assertStringContainsString( '_transient_nettertech_events_', $query_executed );
		$this->assertStringContainsString( '_transient_timeout_nettertech_events_', $query_executed );
	}

	// =========================================================================
	// Cache Effectiveness Metrics
	// =========================================================================

	/**
	 * Test identity map prevents redundant database calls within a request.
	 *
	 * NOTE: find() uses identity map (request-scoped), not object cache.
	 * This tests that repeated finds within the same request only query once.
	 *
	 * @return void
	 */
	public function test_identity_map_prevents_redundant_queries(): void {
		$db_queries = 0;

		$mock_wpdb         = \Mockery::mock( 'wpdb' );
		$mock_wpdb->prefix = 'wp_';

		// Track database query attempts - should only be called once.
		$mock_wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function () use ( &$db_queries ) {
				++$db_queries;
				return (object) array(
					'id'             => 1,
					'event_id'       => 1,
					'start_datetime' => '2026-01-20 19:00:00',
					'end_datetime'   => '2026-01-20 21:00:00',
					'status'         => 'scheduled',
				);
			}
		);
		$mock_wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );

		$GLOBALS['wpdb'] = $mock_wpdb;

		// No object cache for find() - it uses identity map.
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		$repo = new \NetterTechEvents\Repositories\OccurrenceRepository( $mock_wpdb );

		// First find queries database.
		$repo->find( 1 );

		// Subsequent finds use identity map.
		$repo->find( 1 );
		$repo->find( 1 );

		// Identity map should prevent subsequent queries.
		$this->assertSame( 1, $db_queries, 'Identity map should prevent redundant queries' );
	}
}
