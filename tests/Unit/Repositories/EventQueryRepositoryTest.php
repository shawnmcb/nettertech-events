<?php
/**
 * EventQueryRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Repositories\EventQueryRepository;
use NetterTechEvents\Models\Event;

/**
 * Test EventQueryRepository functionality.
 *
 * Tests all read-only event query methods with wpdb mocking.
 */
class EventQueryRepositoryTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Create a mock wpdb and instantiate the repository.
	 *
	 * Sets the global $wpdb so Schema::table() resolves correctly, then
	 * returns the mock, the repository, and the original $wpdb for cleanup.
	 *
	 * @return array{0: \wpdb, 1: EventQueryRepository, 2: \wpdb} [$mock_wpdb, $repo, $original_wpdb]
	 */
	private function create_repo(): array {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'get_row', 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		// Pass-through prepare that does actual placeholder substitution.
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				// Flatten array if first arg is an array (wpdb::prepare supports both forms).
				if ( count( $args ) === 1 && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$i = 0;
				return (string) preg_replace_callback(
					'/%[sdf]/',
					function () use ( $args, &$i ) {
						return isset( $args[ $i ] ) ? "'" . addslashes( (string) $args[ $i++ ] ) . "'" : "''";
					},
					$sql
				);
			} );

		$wpdb = $mock_wpdb;
		$repo = new EventQueryRepository( $mock_wpdb );

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
	 * Build a minimal stdClass row that Event::from_row() can hydrate.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return \stdClass
	 */
	private function make_event_row( array $overrides = array() ): \stdClass {
		$defaults = array(
			'id'                 => 1,
			'post_id'            => null,
			'title'              => 'Test Event',
			'slug'               => 'test-event',
			'description'        => '',
			'excerpt'            => '',
			'featured_image_id'  => null,
			'status'             => 'published',
			'event_type'         => 'single',
			'series_id'          => null,
			'venue_name'         => 'Test Venue',
			'venue_address'      => null,
			'recurrence_rule'    => null,
			'recurrence_end_date' => null,
			'layout_config'      => null,
			'reminders_enabled'  => null,
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
		);

		$merged = array_merge( $defaults, $overrides );

		return (object) $merged;
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test repository can be instantiated.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::__construct
	 * @return void
	 */
	public function test_can_instantiate_repository(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$this->assertInstanceOf( EventQueryRepository::class, $repo );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test repository initializes table name from prefix.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::__construct
	 * @return void
	 */
	public function test_repository_initializes_table_name(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$reflection = new \ReflectionClass( $repo );
			$table_prop = $reflection->getProperty( 'table' );
			$this->assertStringContainsString( 'nettertech_events_events', $table_prop->getValue( $repo ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// all() Tests
	// =========================================================================

	/**
	 * Test all() with no filters returns events from base query.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::all
	 * @return void
	 */
	public function test_all_no_filters_returns_events(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row = $this->make_event_row();

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row ) );

			$result = $repo->all();

			$this->assertCount( 1, $result );
			$this->assertInstanceOf( Event::class, $result[0] );
			$this->assertSame( 1, $result[0]->id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test all() with no filters produces SQL without WHERE clause.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::all
	 * @return void
	 */
	public function test_all_no_filters_sql_has_no_where(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_results' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return array();
				} );

			$repo->all();

			$this->assertStringNotContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'ORDER BY', $captured_sql );
			$this->assertStringContainsString( 'LIMIT', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test all() with status filter adds WHERE status condition.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::all
	 * @return void
	 */
	public function test_all_with_status_filter_adds_where_status(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_results' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return array();
				} );

			$repo->all( array( 'status' => 'published' ) );

			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'status', $captured_sql );
			$this->assertStringContainsString( 'published', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test all() with type filter adds WHERE event_type condition.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::all
	 * @return void
	 */
	public function test_all_with_type_filter_adds_where_event_type(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_results' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return array();
				} );

			$repo->all( array( 'type' => 'recurring' ) );

			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'event_type', $captured_sql );
			$this->assertStringContainsString( 'recurring', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test all() with series_id filter adds WHERE series_id condition.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::all
	 * @return void
	 */
	public function test_all_with_series_id_filter_adds_where_series_id(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_results' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return array();
				} );

			$repo->all( array( 'series_id' => 42 ) );

			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'series_id', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test all() with combined filters produces multiple AND conditions.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::all
	 * @return void
	 */
	public function test_all_with_combined_filters(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_results' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return array();
				} );

			$repo->all(
				array(
					'status'    => 'published',
					'type'      => 'single',
					'series_id' => 5,
				)
			);

			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'AND', $captured_sql );
			$this->assertStringContainsString( 'status', $captured_sql );
			$this->assertStringContainsString( 'event_type', $captured_sql );
			$this->assertStringContainsString( 'series_id', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test all() returns empty array when database returns empty results.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::all
	 * @return void
	 */
	public function test_all_returns_empty_array_when_no_results(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$result = $repo->all();

			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// published() Tests
	// =========================================================================

	/**
	 * Test published() forces status to 'published'.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::published
	 * @return void
	 */
	public function test_published_forces_status_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_results' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return array();
				} );

			$repo->published();

			$this->assertStringContainsString( 'status', $captured_sql );
			$this->assertStringContainsString( 'published', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test published() overrides a different status arg.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::published
	 * @return void
	 */
	public function test_published_overrides_status_arg(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_results' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return array();
				} );

			// Even if draft is passed, published() should override.
			$repo->published( array( 'status' => 'draft' ) );

			$this->assertStringContainsString( 'published', $captured_sql );
			$this->assertStringNotContainsString( "'draft'", $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// by_series() Tests
	// =========================================================================

	/**
	 * Test by_series() adds series_id to query.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::by_series
	 * @return void
	 */
	public function test_by_series_adds_series_id_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_results' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return array();
				} );

			$repo->by_series( 7 );

			$this->assertStringContainsString( 'series_id', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// count() Tests
	// =========================================================================

	/**
	 * Test count() with no filters returns integer count.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::count
	 * @return void
	 */
	public function test_count_no_filters_returns_integer(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '15' );

			$result = $repo->count();

			$this->assertSame( 15, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test count() SQL contains COUNT(*) and no WHERE when unfiltered.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::count
	 * @return void
	 */
	public function test_count_no_filters_sql_has_count_no_where(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_var' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return '0';
				} );

			$repo->count();

			$this->assertStringContainsString( 'COUNT(*)', $captured_sql );
			$this->assertStringNotContainsString( 'WHERE', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test count() with status filter adds WHERE clause.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::count
	 * @return void
	 */
	public function test_count_with_status_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_var' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return '5';
				} );

			$result = $repo->count( array( 'status' => 'draft' ) );

			$this->assertSame( 5, $result );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'status', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test count() returns zero when database returns null.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::count
	 * @return void
	 */
	public function test_count_returns_zero_when_null(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( null );

			$result = $repo->count();

			$this->assertSame( 0, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// paginate() Tests
	// =========================================================================

	/**
	 * Test paginate() returns structured result with items, total, and pages.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::paginate
	 * @return void
	 */
	public function test_paginate_returns_structured_result(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row = $this->make_event_row();

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row ) );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '25' );

			$result = $repo->paginate( array( 'per_page' => 10 ) );

			$this->assertArrayHasKey( 'items', $result );
			$this->assertArrayHasKey( 'total', $result );
			$this->assertArrayHasKey( 'pages', $result );
			$this->assertCount( 1, $result['items'] );
			$this->assertSame( 25, $result['total'] );
			$this->assertSame( 3, $result['pages'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test paginate() clamps page below 1 to 1.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::paginate
	 * @return void
	 */
	public function test_paginate_clamps_page_minimum(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_results' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return array();
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$repo->paginate( array( 'page' => -5 ) );

			// Page 1 means OFFSET 0.
			$this->assertStringContainsString( "OFFSET '0'", $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test paginate() calculates correct offset for second page.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::paginate
	 * @return void
	 */
	public function test_paginate_offset_calculation(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_results' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return array();
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '50' );

			$repo->paginate(
				array(
					'page'     => 3,
					'per_page' => 10,
				)
			);

			// Page 3, per_page 10 -> offset = (3-1)*10 = 20.
			$this->assertStringContainsString( "OFFSET '20'", $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test paginate() returns zero pages when no results.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::paginate
	 * @return void
	 */
	public function test_paginate_empty_results(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$result = $repo->paginate();

			$this->assertEmpty( $result['items'] );
			$this->assertSame( 0, $result['total'] );
			$this->assertSame( 0, $result['pages'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test paginate() delegates to search methods when search term provided.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::paginate
	 * @return void
	 */
	public function test_paginate_with_search_delegates_to_search(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_results' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return array();
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '0' );

			$repo->paginate( array( 'search' => 'concert' ) );

			// Search queries use LIKE on title.
			$this->assertStringContainsString( 'LIKE', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// search() Tests
	// =========================================================================

	/**
	 * Test search() returns matching events.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::search
	 * @return void
	 */
	public function test_search_returns_matching_events(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row = $this->make_event_row( array( 'title' => 'Jazz Concert' ) );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row ) );

			$result = $repo->search( 'Jazz' );

			$this->assertCount( 1, $result );
			$this->assertInstanceOf( Event::class, $result[0] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test search() SQL contains LIKE clause with escaped term.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::search
	 * @return void
	 */
	public function test_search_sql_contains_like_clause(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_results' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return array();
				} );

			$repo->search( 'concert' );

			$this->assertStringContainsString( 'LIKE', $captured_sql );
			$this->assertStringContainsString( 'title', $captured_sql );
			$this->assertStringContainsString( 'concert', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test search() with status filter adds status to WHERE clause.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::search
	 * @return void
	 */
	public function test_search_with_status_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_results' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return array();
				} );

			$repo->search( 'test', array( 'status' => 'draft' ) );

			$this->assertStringContainsString( 'LIKE', $captured_sql );
			$this->assertStringContainsString( 'status', $captured_sql );
			$this->assertStringContainsString( 'draft', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test search() returns empty array for no matches.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::search
	 * @return void
	 */
	public function test_search_returns_empty_for_no_matches(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$result = $repo->search( 'nonexistent' );

			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// search_count() Tests
	// =========================================================================

	/**
	 * Test search_count() returns integer count.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::search_count
	 * @return void
	 */
	public function test_search_count_returns_integer(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '12' );

			$result = $repo->search_count( 'concert' );

			$this->assertSame( 12, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test search_count() SQL uses COUNT(*) with LIKE.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::search_count
	 * @return void
	 */
	public function test_search_count_sql_uses_count_with_like(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'get_var' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return '0';
				} );

			$repo->search_count( 'test' );

			$this->assertStringContainsString( 'COUNT(*)', $captured_sql );
			$this->assertStringContainsString( 'LIKE', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// find_by_post_id() Tests
	// =========================================================================

	/**
	 * Test find_by_post_id() returns Event when found.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::find_by_post_id
	 * @return void
	 */
	public function test_find_by_post_id_returns_event_when_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row = $this->make_event_row( array( 'post_id' => 99 ) );

			$mock_wpdb->method( 'get_row' )
				->willReturn( $row );

			$result = $repo->find_by_post_id( 99 );

			$this->assertInstanceOf( Event::class, $result );
			$this->assertSame( 99, $result->post_id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test find_by_post_id() returns null when not found.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::find_by_post_id
	 * @return void
	 */
	public function test_find_by_post_id_returns_null_when_not_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )
				->willReturn( null );

			$result = $repo->find_by_post_id( 999 );

			$this->assertNull( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test find_by_post_id() SQL selects from events table with post_id WHERE.
	 *
	 * @covers \NetterTechEvents\Repositories\EventQueryRepository::find_by_post_id
	 * @return void
	 */
	public function test_find_by_post_id_sql_structure(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_row' )
				->willReturn( null );

			$repo->find_by_post_id( 42 );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'WHERE post_id = %d', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}
}
