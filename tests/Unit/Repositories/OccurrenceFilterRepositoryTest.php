<?php
/**
 * OccurrenceFilterRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;

/**
 * Test OccurrenceFilterRepository filtered query logic.
 *
 * Covers filter conditions, pagination, caching, category joins,
 * and hydration for the get_filtered() method.
 */
class OccurrenceFilterRepositoryTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Create a mock wpdb and instantiate the repository.
	 *
	 * Sets the global $wpdb so Schema::table() resolves correctly, then
	 * returns the mock, the repository, and the original $wpdb for cleanup.
	 *
	 * @return array{0: \wpdb, 1: OccurrenceFilterRepository, 2: \wpdb}
	 */
	private function create_repo(): array {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		// Default prepare pass-through.
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$wpdb = $mock_wpdb;
		$repo = new OccurrenceFilterRepository( $mock_wpdb );

		return array( $mock_wpdb, $repo, $original_wpdb );
	}

	/**
	 * Restore the original global $wpdb.
	 *
	 * @param \wpdb $original_wpdb The original global wpdb instance.
	 * @return void
	 */
	private function restore_wpdb( \wpdb $original_wpdb ): void {
		global $wpdb;
		$wpdb = $original_wpdb;
	}

	/**
	 * Stub wp_json_encode for cache key generation.
	 *
	 * @return void
	 */
	private function stub_json_encode(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * Build a mock database row that mirrors the SELECT column list.
	 *
	 * @param int    $id       Occurrence ID.
	 * @param int    $event_id Event ID.
	 * @param string $start    Start datetime.
	 * @return object
	 */
	private function make_row( int $id = 1, int $event_id = 10, string $start = '2026-06-15 19:00:00' ): object {
		$row                    = new \stdClass();
		$row->id                = $id;
		$row->event_id          = $event_id;
		$row->start_datetime    = $start;
		$row->end_datetime      = '2026-06-15 21:00:00';
		$row->all_day           = 0;
		$row->timezone          = 'America/Chicago';
		$row->title_override    = null;
		$row->featured_image_id = null;
		$row->status            = 'scheduled';
		$row->capacity          = 200;
		$row->sequence_number   = 1;
		$row->is_rescheduled    = 0;
		$row->checkin_token     = null;
		$row->created_at        = '2026-01-01 00:00:00';
		$row->updated_at        = '2026-01-01 00:00:00';
		$row->event_title       = 'Test Event';
		$row->event_slug        = 'test-event';
		$row->event_image_id    = 42;
		$row->venue_name        = 'The Bellwright';
		$row->venue_address     = '123 Main St';
		$row->event_excerpt     = 'An excerpt.';
		$row->event_type        = 'single';

		return $row;
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test repository can be instantiated.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::__construct
	 * @return void
	 */
	public function test_can_instantiate_repository(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$this->assertInstanceOf( OccurrenceFilterRepository::class, $repo );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test repository initializes table names from prefix.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::__construct
	 * @return void
	 */
	public function test_repository_initializes_table_names(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$reflection = new \ReflectionClass( $repo );

			$table_prop = $reflection->getProperty( 'table' );
			$this->assertSame( 'wp_nettertech_events_occurrences', $table_prop->getValue( $repo ) );

			$events_prop = $reflection->getProperty( 'events_table' );
			$this->assertSame( 'wp_nettertech_events_events', $events_prop->getValue( $repo ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// get_filtered() — Default / No-Filters Tests
	// =========================================================================

	/**
	 * Test default args produce a query with status and event_status filters.
	 *
	 * Default args include status=scheduled, event_status=published, upcoming=true.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_get_filtered_defaults_include_status_and_upcoming(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$result = $repo->get_filtered();

			// Should contain occurrence status, event status, and upcoming time filter.
			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( 'o.status = %s', $all_sql );
			$this->assertStringContainsString( 'e.status = %s', $all_sql );
			// NTE-128: upcoming/past buckets compare end_datetime so in-progress events are not classed as past.
			$this->assertStringContainsString( '(o.end_utc IS NULL OR o.end_utc >= %s)', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test get_filtered returns correct structure with empty results.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_get_filtered_returns_empty_structure(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$result = $repo->get_filtered();

			$this->assertArrayHasKey( 'items', $result );
			$this->assertArrayHasKey( 'total', $result );
			$this->assertArrayHasKey( 'total_pages', $result );
			$this->assertSame( array(), $result['items'] );
			$this->assertSame( 0, $result['total'] );
			$this->assertSame( 0, $result['total_pages'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Status Filter Tests
	// =========================================================================

	/**
	 * Test occurrence status filter appears in query.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_status_filter_active(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered( array( 'status' => 'cancelled' ) );

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( 'o.status = %s', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test null status omits occurrence status condition.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_null_status_omits_condition(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered(
				array(
					'status'       => null,
					'event_status' => null,
					'upcoming'     => false,
				)
			);

			// Count query has no WHERE when all filters are null/false.
			$count_sql = $captured_sqls[0] ?? '';
			$this->assertStringNotContainsString( 'WHERE', $count_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Event Status Filter Tests
	// =========================================================================

	/**
	 * Test event_status filter adds condition on events table.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_event_status_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered( array( 'event_status' => 'draft' ) );

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( 'e.status = %s', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Time Filter Tests
	// =========================================================================

	/**
	 * Test upcoming filter adds >= condition on end_datetime (NTE-128).
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_upcoming_time_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			Functions\when( 'current_time' )->justReturn( '2026-03-01 12:00:00' );

			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered( array( 'upcoming' => true ) );

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( '(o.end_utc IS NULL OR o.end_utc >= %s)', $all_sql );
			$this->assertStringNotContainsString( 'o.start_datetime >= %s', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test past filter adds < condition on end_datetime and orders DESC (NTE-128).
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_past_time_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			Functions\when( 'current_time' )->justReturn( '2026-03-01 12:00:00' );

			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '1' );

			$row = $this->make_row( 1, 10, '2026-01-01 19:00:00' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row ) );

			$result = $repo->get_filtered(
				array(
					'past'     => true,
					'upcoming' => false,
				)
			);

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( '(o.end_utc IS NOT NULL AND o.end_utc < %s)', $all_sql );
			$this->assertStringNotContainsString( 'o.start_datetime < %s', $all_sql );
			// Items query should order DESC for past.
			$items_sql = end( $captured_sqls );
			$this->assertStringContainsString( 'DESC', $items_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test upcoming=false with past=false omits time filter entirely.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_no_time_filter_when_both_false(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered(
				array(
					'upcoming' => false,
					'past'     => false,
				)
			);

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringNotContainsString( 'start_datetime >=', $all_sql );
			$this->assertStringNotContainsString( 'start_datetime <', $all_sql );
			$this->assertStringNotContainsString( 'end_datetime >=', $all_sql );
			$this->assertStringNotContainsString( 'end_datetime <', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Category JOIN Tests
	// =========================================================================

	/**
	 * Test single category ID adds INNER JOIN with IN clause.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_single_category_adds_join(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered( array( 'category' => array( 5 ) ) );

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( 'INNER JOIN', $all_sql );
			$this->assertStringContainsString( 'nettertech_events_event_categories', $all_sql );
			$this->assertStringContainsString( 'ec.category_id IN', $all_sql );
			// Single category = single %d placeholder.
			$this->assertStringContainsString( 'IN (%d)', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test multiple category IDs produce correct placeholder count.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_multiple_categories_placeholder_count(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered( array( 'category' => array( 1, 2, 3 ) ) );

			$all_sql = implode( ' ', $captured_sqls );
			// Three category IDs = three %d placeholders.
			$this->assertStringContainsString( 'IN (%d,%d,%d)', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test invalid (zero) category IDs trigger the 1=0 fallback.
	 *
	 * When category filter contains only values that resolve to 0 after absint,
	 * array_filter removes them and the build_category_join adds 1=0.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_invalid_category_ids_trigger_impossible_condition(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			// Pass category IDs that all become 0 after absint.
			$repo->get_filtered( array( 'category' => array( 0, 0 ) ) );

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( '1 = 0', $all_sql );
			// No INNER JOIN should be present.
			$this->assertStringNotContainsString( 'INNER JOIN', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Tag JOIN Tests
	// =========================================================================

	/**
	 * Test tag slug filter adds INNER JOIN through event_tags and tags tables.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_tag_slug_adds_join(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered( array( 'tag' => 'jazz' ) );

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( 'INNER JOIN', $all_sql );
			$this->assertStringContainsString( 'nettertech_events_event_tags', $all_sql );
			$this->assertStringContainsString( 'nettertech_events_tags', $all_sql );
			$this->assertStringContainsString( 't_filter.slug = %s', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test numeric tag ID filter adds INNER JOIN with tag_id condition.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_tag_id_adds_join(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered( array( 'tag' => '5' ) );

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( 'INNER JOIN', $all_sql );
			$this->assertStringContainsString( 'nettertech_events_event_tags', $all_sql );
			$this->assertStringContainsString( 'et_tag.tag_id = %d', $all_sql );
			// Should NOT join to tags table when using ID.
			$this->assertStringNotContainsString( 't_filter.slug', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test array of tag IDs builds IN() join with %d placeholders (NTE-068).
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_tag_array_of_ids_builds_in_clause(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered( array( 'tag' => array( 1, 2, 3 ) ) );

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( 'INNER JOIN', $all_sql );
			$this->assertStringContainsString( 'nettertech_events_event_tags', $all_sql );
			$this->assertStringContainsString( 'et_tag.tag_id IN (%d,%d,%d)', $all_sql );
			// Pure-ID path: should not need the tags-table join.
			$this->assertStringNotContainsString( 't_filter.slug', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test array of mixed tag slugs + IDs builds combined IN() clauses (NTE-068).
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_tag_array_of_mixed_slugs_and_ids_builds_combined_clauses(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered( array( 'tag' => array( 5, 'jazz', 'rock' ) ) );

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( 'INNER JOIN', $all_sql );
			$this->assertStringContainsString( 'nettertech_events_event_tags', $all_sql );
			$this->assertStringContainsString( 'nettertech_events_tags', $all_sql );
			$this->assertStringContainsString( 't_filter.id IN (%d)', $all_sql );
			$this->assertStringContainsString( 't_filter.slug IN (%s,%s)', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test empty tag array triggers the 1=0 fallback (no rows) (NTE-068).
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_tag_array_of_invalid_values_triggers_impossible_condition(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			// All values resolve to empty (0 for absint, '' for sanitize_title on empty strings).
			$repo->get_filtered( array( 'tag' => array( 0, '' ) ) );

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( '1 = 0', $all_sql );
			$this->assertStringNotContainsString( 'INNER JOIN', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test empty tag filter produces no join.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_empty_tag_no_join(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered( array( 'tag' => '' ) );

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringNotContainsString( 'nettertech_events_event_tags', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Date Range Filter Tests
	// =========================================================================

	/**
	 * Test date_from filter adds >= condition on start_datetime.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_date_from_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered( array( 'date_from' => '2026-06-01' ) );

			$all_sql = implode( ' ', $captured_sqls );
			// Should have both the upcoming time filter (end_datetime, NTE-128) AND the date_from filter (start_datetime).
			$this->assertStringContainsString( '(o.end_utc IS NULL OR o.end_utc >= %s)', $all_sql );
			$this->assertStringContainsString( 'o.start_datetime >= %s', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test date_to filter adds <= condition on start_datetime.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_date_to_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered( array( 'date_to' => '2026-12-31' ) );

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( 'o.start_datetime <= %s', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test date_from and date_to together produce range condition.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_date_range_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered(
				array(
					'date_from' => '2026-06-01',
					'date_to'   => '2026-06-30',
					'upcoming'  => false,
				)
			);

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( 'o.start_datetime >= %s', $all_sql );
			$this->assertStringContainsString( 'o.start_datetime <= %s', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Search Filter Tests
	// =========================================================================

	/**
	 * Test search term adds LIKE conditions on event title and description.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_search_filter_adds_like_conditions(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered( array( 'search' => 'jazz' ) );

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( 'e.title LIKE %s', $all_sql );
			$this->assertStringContainsString( 'e.description LIKE %s', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Combined Filters Test
	// =========================================================================

	/**
	 * Test status + time + category all applied together.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_combined_filters(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			Functions\when( 'current_time' )->justReturn( '2026-03-01 12:00:00' );

			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered(
				array(
					'status'       => 'scheduled',
					'event_status' => 'published',
					'upcoming'     => true,
					'category'     => array( 2, 7 ),
				)
			);

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( 'o.status = %s', $all_sql );
			$this->assertStringContainsString( 'e.status = %s', $all_sql );
			$this->assertStringContainsString( '(o.end_utc IS NULL OR o.end_utc >= %s)', $all_sql );
			$this->assertStringContainsString( 'INNER JOIN', $all_sql );
			$this->assertStringContainsString( 'IN (%d,%d)', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Pagination Tests
	// =========================================================================

	/**
	 * Test pagination calculates total_pages correctly.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_pagination_total_pages(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '25' );

			$rows = array();
			for ( $i = 1; $i <= 10; $i++ ) {
				$rows[] = $this->make_row( $i, 10 );
			}

			$mock_wpdb->method( 'get_results' )
				->willReturn( $rows );

			$result = $repo->get_filtered(
				array(
					'page'     => 1,
					'per_page' => 10,
				)
			);

			$this->assertSame( 25, $result['total'] );
			$this->assertSame( 3, $result['total_pages'] ); // ceil(25/10) = 3.
			$this->assertCount( 10, $result['items'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test LIMIT and OFFSET appear in the items query.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_pagination_limit_offset_in_sql(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '50' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered(
				array(
					'page'     => 2,
					'per_page' => 12,
				)
			);

			// The items query is the last prepared SQL.
			$items_sql = end( $captured_sqls );
			$this->assertStringContainsString( 'LIMIT %d OFFSET %d', $items_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Hydration Tests
	// =========================================================================

	/**
	 * Test rows are hydrated into Occurrence models with attached Event data.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_hydration_produces_occurrence_models(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '1' );

			$row = $this->make_row( 42, 10, '2026-06-15 19:00:00' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row ) );

			$result = $repo->get_filtered();

			$this->assertCount( 1, $result['items'] );
			$occurrence = $result['items'][0];

			$this->assertInstanceOf( Occurrence::class, $occurrence );
			$this->assertSame( 42, $occurrence->id );
			$this->assertSame( 10, $occurrence->event_id );
			$this->assertSame( '2026-06-15 19:00:00', $occurrence->start_datetime );
			$this->assertSame( 'scheduled', $occurrence->status );

			// Verify attached Event via get_event().
			$event = $occurrence->get_event();
			$this->assertNotNull( $event );
			$this->assertSame( 'Test Event', $event->title );
			$this->assertSame( 'test-event', $event->slug );
			$this->assertSame( 'The Bellwright', $event->venue_name );
			$this->assertSame( 'single', $event->event_type );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Caching Tests
	// =========================================================================

	/**
	 * Test cache hit returns cached data without executing queries.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_cache_hit_skips_queries(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$cached_data = array(
				'items'       => array(),
				'total'       => 5,
				'total_pages' => 1,
			);

			Functions\when( 'wp_cache_get' )->justReturn( $cached_data );

			// If queries are executed, the mock would be called. We verify
			// the cached data is returned directly.
			$mock_wpdb->expects( $this->never() )
				->method( 'get_var' );

			$mock_wpdb->expects( $this->never() )
				->method( 'get_results' );

			$result = $repo->get_filtered();

			$this->assertSame( $cached_data, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test cache miss executes queries and returns fresh result.
	 *
	 * The base test case stubs wp_cache_get to return false (cache miss)
	 * and wp_cache_set to return true. This test verifies that on a cache
	 * miss the repository executes the DB queries and returns a valid result.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_cache_miss_executes_queries(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			// Base stub already returns false for wp_cache_get (cache miss).
			$mock_wpdb->expects( $this->atLeastOnce() )
				->method( 'get_var' )
				->willReturn( '2' );

			$rows = array( $this->make_row( 1 ), $this->make_row( 2 ) );

			$mock_wpdb->expects( $this->atLeastOnce() )
				->method( 'get_results' )
				->willReturn( $rows );

			$result = $repo->get_filtered();

			$this->assertSame( 2, $result['total'] );
			$this->assertCount( 2, $result['items'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Order Direction Tests
	// =========================================================================

	/**
	 * Test upcoming queries sort ASC by start_datetime.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_upcoming_sorts_asc(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '1' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $this->make_row() ) );

			$repo->get_filtered( array( 'upcoming' => true, 'past' => false ) );

			$items_sql = end( $captured_sqls );
			$this->assertStringContainsString( 'ORDER BY o.start_datetime ASC', $items_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test past queries sort DESC by start_datetime.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_past_sorts_desc(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '1' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $this->make_row() ) );

			$repo->get_filtered( array( 'past' => true, 'upcoming' => false ) );

			$items_sql = end( $captured_sqls );
			$this->assertStringContainsString( 'ORDER BY o.start_datetime DESC', $items_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// Base Query Structure Tests
	// =========================================================================

	/**
	 * Test base query contains JOIN between occurrences and events tables.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_base_query_joins_events_table(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$captured_sqls = array();

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_filtered();

			$all_sql = implode( ' ', $captured_sqls );
			$this->assertStringContainsString( 'wp_nettertech_events_occurrences o', $all_sql );
			$this->assertStringContainsString( 'JOIN', $all_sql );
			$this->assertStringContainsString( 'wp_nettertech_events_events e ON o.event_id = e.id', $all_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test no-values count query skips prepare.
	 *
	 * When all filters are null/false, no prepared values exist, so
	 * execute_filtered_count skips the $wpdb->prepare() call.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceFilterRepository::get_filtered
	 * @return void
	 */
	public function test_empty_values_skips_count_prepare(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();
		$this->stub_json_encode();

		try {
			$get_var_sql = '';

			$mock_wpdb->method( 'get_var' )
				->willReturnCallback( function ( $sql ) use ( &$get_var_sql ) {
					$get_var_sql = $sql;
					return '0';
				} );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			// All filters null/false = no WHERE values.
			$repo->get_filtered(
				array(
					'status'       => null,
					'event_status' => null,
					'upcoming'     => false,
					'past'         => false,
				)
			);

			// The count SQL is passed directly to get_var (not through prepare).
			$this->assertStringContainsString( 'COUNT(*)', $get_var_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}
}
