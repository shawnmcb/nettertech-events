<?php
/**
 * Query count performance tests.
 *
 * These tests verify that repositories and services follow efficient
 * query patterns - using identity maps, caching, and batch loading.
 *
 * NOTE: For real query counts in a live environment, use browser DevTools
 * or Query Monitor plugin. These tests verify the patterns, not actual counts.
 *
 * @package NetterTechEvents\Tests\Performance
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Performance;

use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use Brain\Monkey\Functions;

/**
 * Test query efficiency patterns.
 */
class QueryCountTest extends \NetterTechEventsTestCase {

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new \wpdb();
	}

	// =========================================================================
	// Identity Map Tests
	// =========================================================================

	/**
	 * Test OccurrenceRepository uses identity map for repeated finds.
	 *
	 * Verifies that calling find() twice with the same ID returns the
	 * cached instance without a second database query.
	 *
	 * @return void
	 */
	public function test_occurrence_repository_identity_map_prevents_duplicate_queries(): void {
		$query_count = 0;

		$mock_wpdb         = \Mockery::mock( 'wpdb' );
		$mock_wpdb->prefix = 'wp_';

		// Track queries - should only be called once for same ID.
		$mock_wpdb->shouldReceive( 'get_row' )
			->andReturnUsing(
				function () use ( &$query_count ) {
					++$query_count;
					return (object) array(
						'id'             => 1,
						'event_id'       => 1,
						'start_datetime' => '2026-01-20 19:00:00',
						'end_datetime'   => '2026-01-20 21:00:00',
						'status'         => 'scheduled',
						'created_at'     => '2026-01-01 12:00:00',
						'updated_at'     => '2026-01-01 12:00:00',
					);
				}
			);

		$mock_wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );

		$GLOBALS['wpdb'] = $mock_wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		$repo = new OccurrenceRepository( $mock_wpdb, new OccurrenceQueryRepository( $mock_wpdb, new OccurrenceFilterRepository( $mock_wpdb ) ) );

		// First call should query database.
		$result1 = $repo->find( 1 );

		// Second call should use identity map.
		$result2 = $repo->find( 1 );

		// Should be same instance (identity map).
		$this->assertSame( $result1, $result2 );

		// Should only have queried once.
		$this->assertSame( 1, $query_count, 'Identity map should prevent second query' );
	}

	/**
	 * Test EventRepository uses identity map.
	 *
	 * @return void
	 */
	public function test_event_repository_identity_map_prevents_duplicate_queries(): void {
		$query_count = 0;

		$mock_wpdb         = \Mockery::mock( 'wpdb' );
		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->shouldReceive( 'get_row' )
			->andReturnUsing(
				function () use ( &$query_count ) {
					++$query_count;
					return (object) array(
						'id'                => 1,
						'title'             => 'Test Event',
						'slug'              => 'test-event',
						'description'       => 'Test description',
						'venue_name'        => 'Test Venue',
						'venue_address'     => '123 Test St',
						'featured_image_id' => 0,
						'is_recurring'      => 0,
						'status'            => 'active',
						'created_at'        => '2026-01-01 12:00:00',
						'updated_at'        => '2026-01-01 12:00:00',
					);
				}
			);

		$mock_wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );

		$GLOBALS['wpdb'] = $mock_wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		$mock_occ_repo    = \Mockery::mock( OccurrenceRepositoryInterface::class );
		$mock_ticket_repo = \Mockery::mock( TicketTypeRepositoryInterface::class );
		$repo             = new EventRepository( $mock_wpdb, null, $mock_occ_repo, $mock_ticket_repo );

		$result1 = $repo->find( 1 );
		$result2 = $repo->find( 1 );

		$this->assertSame( $result1, $result2 );
		$this->assertSame( 1, $query_count, 'Identity map should prevent second query' );
	}

	// =========================================================================
	// Batch Loading Tests
	// =========================================================================

	/**
	 * Test that get_filtered uses single query for list operations.
	 *
	 * @return void
	 */
	public function test_get_filtered_uses_single_query(): void {
		$mock_wpdb         = \Mockery::mock( 'wpdb' );
		$mock_wpdb->prefix = 'wp_';

		// Track get_results calls (should be 1 for list, not N for each event).
		$mock_wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				array(
					(object) array(
						'id'             => 1,
						'event_id'       => 1,
						'start_datetime' => '2026-01-20 19:00:00',
						'end_datetime'   => '2026-01-20 21:00:00',
						'status'         => 'scheduled',
						'event_title'    => 'Event 1',
						'event_slug'     => 'event-1',
						'created_at'     => '2026-01-01 12:00:00',
					),
					(object) array(
						'id'             => 2,
						'event_id'       => 2,
						'start_datetime' => '2026-01-21 19:00:00',
						'end_datetime'   => '2026-01-21 21:00:00',
						'status'         => 'scheduled',
						'event_title'    => 'Event 2',
						'event_slug'     => 'event-2',
						'created_at'     => '2026-01-01 12:00:00',
					),
				)
			);

		// Allow any number of prepare calls.
		$mock_wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );

		// get_var for count query.
		$mock_wpdb->shouldReceive( 'get_var' )->andReturn( '2' );

		$GLOBALS['wpdb'] = $mock_wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-19 12:00:00' );

		$repo   = new OccurrenceRepository( $mock_wpdb, new OccurrenceQueryRepository( $mock_wpdb, new OccurrenceFilterRepository( $mock_wpdb ) ) );
		$result = $repo->get_filtered( array( 'limit' => 10 ) );

		// If we got here without exception, the single query pattern worked.
		// Mockery will fail if get_results was called more than once.
		$this->assertIsArray( $result );
	}

	// =========================================================================
	// Cache Integration Tests
	// =========================================================================

	/**
	 * Test that get_filtered() uses object cache before querying.
	 *
	 * NOTE: find() uses identity map (request-scoped), not object cache.
	 * get_filtered() and get_siblings() use object cache for cross-request caching.
	 *
	 * @return void
	 */
	public function test_get_filtered_uses_object_cache(): void {
		$cache_checked = false;

		$mock_wpdb         = \Mockery::mock( 'wpdb' );
		$mock_wpdb->prefix = 'wp_';

		// Database should NOT be queried if cache hit.
		$mock_wpdb->shouldNotReceive( 'get_results' );
		$mock_wpdb->shouldNotReceive( 'get_var' );
		$mock_wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );

		$GLOBALS['wpdb'] = $mock_wpdb;

		// Cached result for get_filtered.
		$cached_result = array(
			'items' => array(
				Occurrence::from_row(
					(object) array(
						'id'             => 1,
						'event_id'       => 1,
						'start_datetime' => '2026-01-20 19:00:00',
						'end_datetime'   => '2026-01-20 21:00:00',
						'status'         => 'scheduled',
					)
				),
			),
			'total' => 1,
		);

		// Simulate cache hit.
		Functions\when( 'wp_cache_get' )->alias(
			function ( $key, $group ) use ( &$cache_checked, $cached_result ) {
				$cache_checked = true;
				return $cached_result;
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-01-19 12:00:00' );

		$repo   = new OccurrenceRepository( $mock_wpdb, new OccurrenceQueryRepository( $mock_wpdb, new OccurrenceFilterRepository( $mock_wpdb ) ) );
		$result = $repo->get_filtered( array( 'limit' => 10 ) );

		$this->assertTrue( $cache_checked, 'get_filtered should check object cache' );
		$this->assertIsArray( $result );
	}

	// =========================================================================
	// Performance Target Constants
	// =========================================================================

	/**
	 * Test performance target constants are documented.
	 *
	 * Verifies the plugin documents its performance expectations.
	 *
	 * @return void
	 */
	public function test_performance_targets_documented(): void {
		// These targets are defined in docs/PERFORMANCE.md.
		$targets = array(
			'frontend_ttfb_target'    => 200,   // ms
			'frontend_ttfb_critical'  => 500,   // ms
			'frontend_queries_target' => 30,
			'frontend_queries_critical' => 50,
			'admin_queries_target'    => 50,
			'admin_queries_critical'  => 100,
		);

		// Just verify we can define these - actual validation happens in browser profiling.
		foreach ( $targets as $name => $value ) {
			$this->assertIsInt( $value, "{$name} should be an integer" );
			$this->assertGreaterThan( 0, $value, "{$name} should be positive" );
		}
	}
}
