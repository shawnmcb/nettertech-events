<?php
/**
 * OccurrenceQueryRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;

/**
 * Test OccurrenceQueryRepository functionality.
 *
 * Tests all 9 public methods with wpdb mocking.
 */
class OccurrenceQueryRepositoryTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Create a mock wpdb and instantiate the repository.
	 *
	 * @param OccurrenceFilterRepository|null $filter_repo Optional filter repo mock.
	 * @return array{0: \wpdb, 1: OccurrenceQueryRepository, 2: \wpdb}
	 */
	private function create_repo( ?OccurrenceFilterRepository $filter_repo = null ): array {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'get_row', 'get_results', 'query', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$wpdb = $mock_wpdb;
		$repo = new OccurrenceQueryRepository( $mock_wpdb, $filter_repo ?? new OccurrenceFilterRepository( $mock_wpdb ) );

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
	 * Create a mock occurrence row from the database.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return object
	 */
	private function make_occurrence_row( array $overrides = array() ): object {
		$defaults = array(
			'id'                => 1,
			'event_id'          => 10,
			'start_datetime'    => '2026-06-15 19:00:00',
			'end_datetime'      => '2026-06-15 21:00:00',
			'all_day'           => 0,
			'timezone'          => 'America/Chicago',
			'title_override'    => null,
			'featured_image_id' => null,
			'status'            => 'scheduled',
			'capacity'          => 200,
			'sequence_number'   => 1,
			'is_rescheduled'    => 0,
			'checkin_token'     => null,
			'created_at'        => '2026-01-01 00:00:00',
			'updated_at'        => '2026-01-01 00:00:00',
		);

		return (object) array_merge( $defaults, $overrides );
	}

	/**
	 * Create a mock occurrence row with event JOIN fields.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return object
	 */
	private function make_joined_row( array $overrides = array() ): object {
		$row = $this->make_occurrence_row( $overrides );

		$event_defaults = array(
			'event_title'    => 'Test Event',
			'event_slug'     => 'test-event',
			'event_image_id' => null,
			'event_type'     => 'recurring',
			'venue_name'     => 'Test Venue',
			'venue_address'  => '123 Main St',
		);

		foreach ( $event_defaults as $key => $value ) {
			if ( ! isset( $row->$key ) ) {
				$row->$key = $value;
			}
		}

		return $row;
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::__construct
	 * @return void
	 */
	public function test_can_instantiate_repository(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$this->assertInstanceOf( OccurrenceQueryRepository::class, $repo );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// for_event() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::for_event
	 * @return void
	 */
	public function test_for_event_returns_occurrences(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row1 = $this->make_occurrence_row( array( 'id' => 1 ) );
			$row2 = $this->make_occurrence_row( array( 'id' => 2, 'start_datetime' => '2026-06-16 19:00:00' ) );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row1, $row2 ) );

			$result = $repo->for_event( 10 );

			$this->assertCount( 2, $result );
			$this->assertInstanceOf( Occurrence::class, $result[0] );
			$this->assertInstanceOf( Occurrence::class, $result[1] );
			$this->assertSame( 1, $result[0]->id );
			$this->assertSame( 2, $result[1]->id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::for_event
	 * @return void
	 */
	public function test_for_event_returns_empty_array_when_no_results(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$result = $repo->for_event( 999 );

			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::for_event
	 * @return void
	 */
	public function test_for_event_with_status_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->for_event( 10, array( 'status' => 'cancelled' ) );

			$this->assertStringContainsString( 'status = %s', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::for_event
	 * @return void
	 */
	public function test_for_event_with_upcoming_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->for_event( 10, array( 'upcoming' => true ) );

			// NTE-128: upcoming filter buckets by end_datetime (in-progress stays upcoming).
			$this->assertStringContainsString( '(end_utc IS NULL OR end_utc >= %s)', $captured_sql );
			$this->assertStringNotContainsString( 'start_datetime >= %s', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::for_event
	 * @return void
	 */
	public function test_for_event_without_status_omits_status_where(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->for_event( 10 );

			$this->assertStringNotContainsString( 'status = %s', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// for_event_grouped() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::for_event_grouped
	 * @return void
	 */
	public function test_for_event_grouped_splits_past_and_upcoming(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$past_row           = $this->make_occurrence_row( array( 'id' => 1, 'start_datetime' => '2020-01-01 10:00:00' ) );
			$past_row->is_past  = '1';
			$upcoming_row           = $this->make_occurrence_row( array( 'id' => 2, 'start_datetime' => '2030-06-15 19:00:00' ) );
			$upcoming_row->is_past  = '0';

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $past_row, $upcoming_row ) );

			$result = $repo->for_event_grouped( 10 );

			$this->assertArrayHasKey( 'past', $result );
			$this->assertArrayHasKey( 'upcoming', $result );
			$this->assertCount( 1, $result['past'] );
			$this->assertCount( 1, $result['upcoming'] );
			$this->assertSame( 1, $result['past'][0]->id );
			$this->assertSame( 2, $result['upcoming'][0]->id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * NTE-128: the is_past projection must compare end_datetime (not start_datetime)
	 * so an in-progress occurrence (start < now <= end) is not classed as past.
	 * ORDER BY remains on start_datetime ASC for stable chronological listing.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::for_event_grouped
	 * @return void
	 */
	public function test_for_event_grouped_is_past_uses_end_datetime(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->for_event_grouped( 10 );

			$this->assertStringContainsString( '(end_utc IS NOT NULL AND end_utc < %s) AS is_past', $captured_sql );
			$this->assertStringNotContainsString( '(start_datetime < %s) AS is_past', $captured_sql );
			$this->assertStringContainsString( 'ORDER BY start_datetime ASC', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::for_event_grouped
	 * @return void
	 */
	public function test_for_event_grouped_all_past(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row1           = $this->make_occurrence_row( array( 'id' => 1 ) );
			$row1->is_past  = '1';
			$row2           = $this->make_occurrence_row( array( 'id' => 2 ) );
			$row2->is_past  = '1';

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row1, $row2 ) );

			$result = $repo->for_event_grouped( 10 );

			$this->assertCount( 2, $result['past'] );
			$this->assertEmpty( $result['upcoming'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::for_event_grouped
	 * @return void
	 */
	public function test_for_event_grouped_all_upcoming(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row1           = $this->make_occurrence_row( array( 'id' => 1 ) );
			$row1->is_past  = '0';
			$row2           = $this->make_occurrence_row( array( 'id' => 2 ) );
			$row2->is_past  = '0';

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row1, $row2 ) );

			$result = $repo->for_event_grouped( 10 );

			$this->assertEmpty( $result['past'] );
			$this->assertCount( 2, $result['upcoming'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::for_event_grouped
	 * @return void
	 */
	public function test_for_event_grouped_empty_results(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$result = $repo->for_event_grouped( 999 );

			$this->assertEmpty( $result['past'] );
			$this->assertEmpty( $result['upcoming'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::for_event_grouped
	 * @return void
	 */
	public function test_for_event_grouped_includes_status_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->for_event_grouped( 10 );

			// Default status is 'scheduled'.
			$this->assertStringContainsString( 'status = %s', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// get_siblings() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::get_siblings
	 * @return void
	 */
	public function test_get_siblings_returns_cached_data_on_cache_hit(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$cached_data = array(
				'all'           => array( new Occurrence() ),
				'past'          => array(),
				'upcoming'      => array( new Occurrence() ),
				'current_index' => 0,
			);

			Functions\when( 'wp_cache_get' )->justReturn( $cached_data );

			$mock_wpdb->expects( $this->never() )
				->method( 'get_var' );

			$result = $repo->get_siblings( 5 );

			$this->assertSame( $cached_data, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::get_siblings
	 * @return void
	 */
	public function test_get_siblings_returns_empty_when_occurrence_not_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( null );

			$result = $repo->get_siblings( 999 );

			$this->assertEmpty( $result['all'] );
			$this->assertEmpty( $result['past'] );
			$this->assertEmpty( $result['upcoming'] );
			$this->assertSame( -1, $result['current_index'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::get_siblings
	 * @return void
	 */
	public function test_get_siblings_queries_groups_and_caches_on_miss(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			// Past occurrence.
			// Both bounds set, and coherent. The split reads end_datetime, so an occurrence
			// must not be handed a start and end that disagree about which side of now it is.
			$row1 = $this->make_joined_row(
				array(
					'id'             => 5,
					'start_datetime' => '2020-01-01 10:00:00',
					'end_datetime'   => '2020-01-01 12:00:00',
				)
			);
			// Future occurrence (the current one).
			$row2 = $this->make_joined_row(
				array(
					'id'             => 6,
					'start_datetime' => '2030-06-15 19:00:00',
					'end_datetime'   => '2030-06-15 21:00:00',
				)
			);

			$mock_wpdb->method( 'get_var' )
				->willReturn( '10' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row1, $row2 ) );

			$cache_set_called = false;
			Functions\when( 'wp_cache_set' )->alias(
				function () use ( &$cache_set_called ) {
					$cache_set_called = true;
					return true;
				}
			);

			$result = $repo->get_siblings( 6 );

			$this->assertCount( 2, $result['all'] );
			$this->assertCount( 1, $result['past'] );
			$this->assertCount( 1, $result['upcoming'] );
			$this->assertSame( 1, $result['current_index'] );
			$this->assertTrue( $cache_set_called );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test an occurrence under way is grouped with the upcoming siblings, not the past ones.
	 *
	 * The sibling list is what an event page shows as "other dates". Moving tonight's show
	 * into the past column at the downbeat tells a visitor standing in the lobby that the
	 * thing they are attending has already happened.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::get_siblings
	 * @return void
	 */
	public function test_get_siblings_treats_an_in_progress_occurrence_as_upcoming(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			// Started an hour ago, runs another hour. Genuinely under way, right now.
			$in_progress = $this->make_joined_row(
				array(
					'id'             => 7,
					'timezone'       => 'America/Chicago',
					'start_datetime' => $this->wall_clock( '-1 hour' ),
					'end_datetime'   => $this->wall_clock( '+1 hour' ),
				)
			);

			$mock_wpdb->method( 'get_var' )->willReturn( '10' );
			$mock_wpdb->method( 'get_results' )->willReturn( array( $in_progress ) );

			$result = $repo->get_siblings( 7 );

			$this->assertCount( 1, $result['upcoming'], 'An occurrence under way has not happened yet.' );
			$this->assertEmpty( $result['past'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Render a moment as the wall-clock an occurrence in America/Chicago would have stored.
	 *
	 * The columns hold bare wall-clock in the occurrence's own zone, so a fixture meant to sit
	 * a fixed distance from *now* has to be written in that zone — not the test runner's.
	 *
	 * @param string $offset A relative offset, e.g. '-1 hour'.
	 * @return string
	 */
	private function wall_clock( string $offset ): string {
		return ( new \DateTimeImmutable( $offset, new \DateTimeZone( 'America/Chicago' ) ) )
			->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::get_siblings
	 * @return void
	 */
	public function test_get_siblings_attaches_event_data(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row = $this->make_joined_row(
				array(
					'id'             => 5,
					'start_datetime' => '2030-06-15 19:00:00',
					'event_title'    => 'Concert Night',
					'event_slug'     => 'concert-night',
					'venue_name'     => 'The Fillmore',
				)
			);

			$mock_wpdb->method( 'get_var' )
				->willReturn( '10' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row ) );

			$result = $repo->get_siblings( 5 );

			$occurrence = $result['all'][0];
			$event      = $occurrence->get_event();
			$this->assertNotNull( $event );
			$this->assertSame( 'Concert Night', $event->title );
			$this->assertSame( 'concert-night', $event->slug );
			$this->assertSame( 'The Fillmore', $event->venue_name );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// in_range() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::in_range
	 * @return void
	 */
	public function test_in_range_returns_occurrences(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

			$row = $this->make_joined_row();

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row ) );

			$result = $repo->in_range( '2026-06-01', '2026-06-30' );

			$this->assertCount( 1, $result );
			$this->assertInstanceOf( Occurrence::class, $result[0] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::in_range
	 * @return void
	 */
	public function test_in_range_normalizes_date_only_start(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			// Pass date-only strings (10 chars).
			$repo->in_range( '2026-06-01', '2026-06-30' );

			// The code appends ' 00:00:00' to start and ' 23:59:59' to end.
			// This is verified indirectly via the cache key or SQL placeholders.
			$this->assertStringContainsString( '(o.start_utc IS NULL OR o.start_utc >= %s)', $captured_sql );
			$this->assertStringContainsString( '(o.start_utc IS NULL OR o.start_utc <= %s)', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * A calendar grid still asks "what starts in this window".
	 *
	 * The in-progress lower bound is opt-in precisely so a month grid does not start pulling in
	 * occurrences that began in a previous month.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::in_range
	 * @return void
	 */
	public function test_in_range_bounds_on_start_by_default(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );
			$mock_wpdb->method( 'get_results' )->willReturn( array() );

			$repo->in_range( '2026-06-01', '2026-06-30' );

			$this->assertStringContainsString( '(o.start_utc IS NULL OR o.start_utc >= %s)', $captured_sql );
			$this->assertStringNotContainsString( '(o.end_utc IS NULL OR o.end_utc >= %s)', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Asking for in-progress occurrences moves the lower bound onto the end.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::in_range
	 * @return void
	 */
	public function test_in_range_bounds_on_end_when_in_progress_included(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );
			$mock_wpdb->method( 'get_results' )->willReturn( array() );

			$repo->in_range( '2026-06-01', '2026-06-30', array( 'include_in_progress' => true ) );

			$this->assertStringContainsString( '(o.end_utc IS NULL OR o.end_utc >= %s)', $captured_sql );
			$this->assertStringNotContainsString( 'o.start_datetime >= %s', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test an occurrence already under way is still upcoming.
	 *
	 * The bug this pins: `upcoming()` bounded on `start_datetime`, so a three-hour concert
	 * fell out of "what's on" at the downbeat — the event vanished from the site while the
	 * audience was in the room. Upcoming means not yet *over*, not not yet *begun*.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::upcoming
	 * @return void
	 */
	public function test_upcoming_keeps_an_occurrence_that_has_started_but_not_ended(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );
			$mock_wpdb->method( 'get_results' )->willReturn( array() );

			$repo->upcoming();

			$this->assertStringContainsString(
				'(o.end_utc IS NULL OR o.end_utc >= %s)',
				$captured_sql,
				'upcoming() must bound on the end of the occurrence, not its start.'
			);
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test the occurrence on stage right now is the "next" one.
	 *
	 * Skipping ahead to next week's date while tonight's show is running is worse than
	 * useless to a visitor landing on the page.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::next_for_event
	 * @return void
	 */
	public function test_next_for_event_counts_an_in_progress_occurrence(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );
			$mock_wpdb->method( 'get_row' )->willReturn( null );

			$repo->next_for_event( 10 );

			$this->assertStringContainsString( '(end_utc IS NULL OR end_utc >= %s)', $captured_sql );
			$this->assertStringNotContainsString( 'start_datetime >= %s', $captured_sql );
			// It is still ordered by when it starts — only the filter moved.
			$this->assertStringContainsString( 'ORDER BY start_datetime ASC', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test the batched next-date aggregate agrees with next_for_event().
	 *
	 * These two answer the same question for the same admin column; if they disagree, the
	 * list table and the row detail contradict each other.
	 *
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::date_bounds_for_events
	 * @return void
	 */
	public function test_date_bounds_next_start_counts_an_in_progress_occurrence(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );
			$mock_wpdb->method( 'get_results' )->willReturn( array() );

			$repo->date_bounds_for_events( array( 10, 11 ) );

			$this->assertStringContainsString( 'CASE WHEN (end_utc IS NULL OR end_utc >= %s) THEN start_datetime', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::in_range
	 * @return void
	 */
	public function test_in_range_normalizes_iso8601_dates(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			// ISO-8601 dates with T separator should be converted via wp_date().
			$result = $repo->in_range( '2026-06-01T00:00:00', '2026-06-30T23:59:59' );

			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::in_range
	 * @return void
	 */
	public function test_in_range_returns_cached_data_on_hit(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

			$cached = array( new Occurrence() );
			Functions\when( 'wp_cache_get' )->justReturn( $cached );

			$mock_wpdb->expects( $this->never() )
				->method( 'get_results' );

			$result = $repo->in_range( '2026-06-01', '2026-06-30' );

			$this->assertSame( $cached, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::in_range
	 * @return void
	 */
	public function test_in_range_with_include_events_joins_event_data(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

			$row = $this->make_joined_row(
				array(
					'event_title' => 'Summer Festival',
					'event_slug'  => 'summer-festival',
				)
			);

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row ) );

			$result = $repo->in_range( '2026-06-01', '2026-06-30', array( 'include_events' => true ) );

			$this->assertCount( 1, $result );
			$event = $result[0]->get_event();
			$this->assertNotNull( $event );
			$this->assertSame( 'Summer Festival', $event->title );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::in_range
	 * @return void
	 */
	public function test_in_range_without_include_events_skips_event_attachment(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

			// Row without event_title set (simulates no JOIN columns).
			$row = $this->make_occurrence_row();

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row ) );

			$result = $repo->in_range(
				'2026-06-01',
				'2026-06-30',
				array( 'include_events' => false )
			);

			$this->assertCount( 1, $result );
			// Event is not attached, so get_event() would rely on ServiceRegistry.
			// We verify no exception was thrown during mapping.
			$this->assertInstanceOf( Occurrence::class, $result[0] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::in_range
	 * @return void
	 */
	public function test_in_range_caches_results_on_miss(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$cache_set_called = false;
			Functions\when( 'wp_cache_set' )->alias(
				function () use ( &$cache_set_called ) {
					$cache_set_called = true;
					return true;
				}
			);

			$repo->in_range( '2026-06-01', '2026-06-30' );

			$this->assertTrue( $cache_set_called );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::in_range
	 * @return void
	 */
	public function test_in_range_sql_includes_status_filters(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->in_range( '2026-06-01', '2026-06-30' );

			// Default: status=scheduled, event_status=published.
			$this->assertStringContainsString( 'o.status = %s', $captured_sql );
			$this->assertStringContainsString( 'e.status = %s', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// upcoming() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::upcoming
	 * @return void
	 */
	public function test_upcoming_delegates_to_in_range(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

			$row = $this->make_joined_row();

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row ) );

			$result = $repo->upcoming( 5 );

			$this->assertCount( 1, $result );
			$this->assertInstanceOf( Occurrence::class, $result[0] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::upcoming
	 * @return void
	 */
	public function test_upcoming_passes_limit_to_in_range(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->upcoming( 3 );

			$this->assertStringContainsString( 'LIMIT %d', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// next_for_event() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::next_for_event
	 * @return void
	 */
	public function test_next_for_event_returns_occurrence_when_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row = $this->make_occurrence_row();

			$mock_wpdb->method( 'get_row' )
				->willReturn( $row );

			$result = $repo->next_for_event( 10 );

			$this->assertInstanceOf( Occurrence::class, $result );
			$this->assertSame( 1, $result->id );
			$this->assertSame( 10, $result->event_id );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::next_for_event
	 * @return void
	 */
	public function test_next_for_event_returns_null_when_not_found(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_row' )
				->willReturn( null );

			$result = $repo->next_for_event( 999 );

			$this->assertNull( $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::next_for_event
	 * @return void
	 */
	public function test_next_for_event_sql_orders_by_start_date_asc_limit_1(): void {
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

			$repo->next_for_event( 10 );

			$this->assertStringContainsString( 'ORDER BY start_datetime ASC', $captured_sql );
			$this->assertStringContainsString( 'LIMIT 1', $captured_sql );
			$this->assertStringContainsString( "status = 'scheduled'", $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// get_upcoming_by_event() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::get_upcoming_by_event
	 * @return void
	 */
	public function test_get_upcoming_by_event_returns_upcoming_occurrences(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$row = $this->make_occurrence_row();

			$mock_wpdb->method( 'get_results' )
				->willReturn( array( $row ) );

			$result = $repo->get_upcoming_by_event( 10, 5 );

			$this->assertCount( 1, $result );
			$this->assertInstanceOf( Occurrence::class, $result[0] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::get_upcoming_by_event
	 * @return void
	 */
	public function test_get_upcoming_by_event_passes_correct_args(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_results' )
				->willReturn( array() );

			$repo->get_upcoming_by_event( 10, 3 );

			// Delegates to for_event with upcoming=true, status=scheduled.
			// NTE-128: upcoming buckets by end_datetime (in-progress stays upcoming).
			$this->assertStringContainsString( '(end_utc IS NULL OR end_utc >= %s)', $captured_sql );
			$this->assertStringContainsString( 'status = %s', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// count_for_event() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::count_for_event
	 * @return void
	 */
	public function test_count_for_event_returns_count(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( '7' );

			$result = $repo->count_for_event( 10 );

			$this->assertSame( 7, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::count_for_event
	 * @return void
	 */
	public function test_count_for_event_returns_zero_when_none(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$mock_wpdb->method( 'get_var' )
				->willReturn( null );

			$result = $repo->count_for_event( 999 );

			$this->assertSame( 0, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::count_for_event
	 * @return void
	 */
	public function test_count_for_event_with_status_filter(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '3' );

			$repo->count_for_event( 10, 'scheduled' );

			$this->assertStringContainsString( 'AND status = %s', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::count_for_event
	 * @return void
	 */
	public function test_count_for_event_without_status_omits_status_clause(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$captured_sql = '';

			$mock_wpdb->method( 'prepare' )
				->willReturnCallback( function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return $sql;
				} );

			$mock_wpdb->method( 'get_var' )
				->willReturn( '5' );

			$repo->count_for_event( 10 );

			$this->assertStringNotContainsString( 'AND status = %s', $captured_sql );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// get_filtered() Tests
	// =========================================================================

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::get_filtered
	 * @return void
	 */
	public function test_get_filtered_delegates_to_filter_repo(): void {
		$filter_repo = \Mockery::mock( OccurrenceFilterRepository::class );

		$expected = array(
			'items'       => array( new Occurrence() ),
			'total'       => 1,
			'total_pages' => 1,
		);

		$filter_repo->shouldReceive( 'get_filtered' )
			->once()
			->with( array( 'page' => 1, 'per_page' => 20 ) )
			->andReturn( $expected );

		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo( $filter_repo );

		try {
			$result = $repo->get_filtered( array( 'page' => 1, 'per_page' => 20 ) );

			$this->assertSame( $expected, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * @covers \NetterTechEvents\Repositories\OccurrenceQueryRepository::get_filtered
	 * @return void
	 */
	public function test_get_filtered_passes_empty_args(): void {
		$filter_repo = \Mockery::mock( OccurrenceFilterRepository::class );

		$expected = array(
			'items'       => array(),
			'total'       => 0,
			'total_pages' => 0,
		);

		$filter_repo->shouldReceive( 'get_filtered' )
			->once()
			->with( array() )
			->andReturn( $expected );

		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo( $filter_repo );

		try {
			$result = $repo->get_filtered();

			$this->assertSame( $expected, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	// =========================================================================
	// date_bounds_for_events() Tests
	// =========================================================================

	/**
	 * Test date_bounds_for_events returns empty array for empty input.
	 *
	 * @return void
	 */
	public function test_date_bounds_for_events_empty_input(): void {
		list( , $repo, $original_wpdb ) = $this->create_repo();

		try {
			$this->assertSame( array(), $repo->date_bounds_for_events( array() ) );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test date_bounds_for_events maps min/max span and next bound per event.
	 *
	 * Single events yield first === last; recurring events yield a min/max span.
	 *
	 * @return void
	 */
	public function test_date_bounds_for_events_maps_first_last_next(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			// Single event 10: one occurrence (first === last).
			$single           = new \stdClass();
			$single->event_id = 10;
			$single->first_start = '2026-06-15 19:00:00';
			$single->last_start  = '2026-06-15 19:00:00';
			$single->next_start  = '2026-06-15 19:00:00';
			$single->occ_count   = 1;

			// Recurring event 11: span across occurrences, next upcoming set.
			$recurring           = new \stdClass();
			$recurring->event_id = 11;
			$recurring->first_start = '2026-01-05 18:00:00';
			$recurring->last_start  = '2026-12-20 18:00:00';
			$recurring->next_start  = '2026-09-01 18:00:00';
			$recurring->occ_count   = 24;

			// all_day flag lookup for the two next bounds.
			$flag1                 = new \stdClass();
			$flag1->event_id       = 10;
			$flag1->start_datetime = '2026-06-15 19:00:00';
			$flag1->all_day        = 0;

			$flag2                 = new \stdClass();
			$flag2->event_id       = 11;
			$flag2->start_datetime = '2026-09-01 18:00:00';
			$flag2->all_day        = 1;

			$mock_wpdb->method( 'get_results' )->willReturnOnConsecutiveCalls(
				array( $single, $recurring ),
				array( $flag1, $flag2 )
			);

			$result = $repo->date_bounds_for_events( array( 10, 11, 12 ) );

			$this->assertSame( '2026-06-15 19:00:00', $result[10]['first'] );
			$this->assertSame( '2026-06-15 19:00:00', $result[10]['last'] );
			$this->assertSame( '2026-06-15 19:00:00', $result[10]['next'] );
			$this->assertFalse( $result[10]['next_all_day'] );
			$this->assertSame( 1, $result[10]['count'] );

			$this->assertSame( '2026-01-05 18:00:00', $result[11]['first'] );
			$this->assertSame( '2026-12-20 18:00:00', $result[11]['last'] );
			$this->assertSame( '2026-09-01 18:00:00', $result[11]['next'] );
			$this->assertTrue( $result[11]['next_all_day'] );
			$this->assertSame( 24, $result[11]['count'] );

			// Event 12 had no scheduled occurrences: omitted from result.
			$this->assertArrayNotHasKey( 12, $result );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}

	/**
	 * Test date_bounds_for_events handles a fully-past event (no next bound).
	 *
	 * A span exists (first/last) even though the next upcoming bound is null,
	 * exercising the "Date column shows dates regardless of past/future" rule.
	 *
	 * @return void
	 */
	public function test_date_bounds_for_events_past_event_has_span_no_next(): void {
		list( $mock_wpdb, $repo, $original_wpdb ) = $this->create_repo();

		try {
			$past              = new \stdClass();
			$past->event_id    = 20;
			$past->first_start = '2024-01-01 10:00:00';
			$past->last_start  = '2024-03-01 10:00:00';
			$past->next_start  = null; // No upcoming occurrence.

			// No next bounds => only the aggregate query runs (no flag lookup).
			$mock_wpdb->method( 'get_results' )->willReturn( array( $past ) );

			$result = $repo->date_bounds_for_events( array( 20 ) );

			$this->assertSame( '2024-01-01 10:00:00', $result[20]['first'] );
			$this->assertSame( '2024-03-01 10:00:00', $result[20]['last'] );
			$this->assertNull( $result[20]['next'] );
			$this->assertFalse( $result[20]['next_all_day'] );
		} finally {
			$this->restore_wpdb( $original_wpdb );
		}
	}
}
