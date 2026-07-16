<?php
/**
 * OccurrenceRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Models\Occurrence;

/**
 * Test OccurrenceRepository functionality.
 *
 * Uses dynamic wpdb mocks to test query building and data handling
 * without requiring a live database connection.
 */
class OccurrenceRepositoryTest extends \NetterTechEventsTestCase {

	/**
	 * Stub the site timezone for all tests — OccurrenceRepository::save() stamps the
	 * authoring zone via wp_timezone_string() on every persist (timezone fix).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\Functions\when( 'wp_timezone_string' )->justReturn( 'America/Chicago' );
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test repository can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate_repository(): void {
		global $wpdb;
		$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

		$this->assertInstanceOf( OccurrenceRepository::class, $repo );
	}

	/**
	 * Test ensure_timezone() stamps the site zone on occurrences with the unset
	 * default ('' / 'UTC') and preserves a deliberately-set zone (timezone fix).
	 *
	 * @return void
	 */
	public function test_ensure_timezone_stamps_authoring_zone(): void {
		global $wpdb;
		\Brain\Monkey\Functions\when( 'wp_timezone_string' )->justReturn( 'America/Chicago' );

		$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
		$method = new \ReflectionMethod( $repo, 'ensure_timezone' );

		$default           = new Occurrence();
		$default->timezone = 'UTC';
		$method->invoke( $repo, $default );
		$this->assertSame( 'America/Chicago', $default->timezone, "Unset 'UTC' default must be stamped with the site zone." );

		$empty           = new Occurrence();
		$empty->timezone = '';
		$method->invoke( $repo, $empty );
		$this->assertSame( 'America/Chicago', $empty->timezone, 'Empty timezone must be stamped with the site zone.' );

		$explicit           = new Occurrence();
		$explicit->timezone = 'Europe/London';
		$method->invoke( $repo, $explicit );
		$this->assertSame( 'Europe/London', $explicit->timezone, 'A deliberately-set zone must be preserved.' );
	}

	// =========================================================================
	// Identity Map Tests
	// =========================================================================

	/**
	 * Test identity map is initialized empty.
	 *
	 * @return void
	 */
	public function test_identity_map_initialized_empty(): void {
		global $wpdb;
		$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

		$reflection = new \ReflectionClass( $repo );
		$map_prop   = $reflection->getProperty( 'identity_map' );

		$this->assertEmpty( $map_prop->getValue( $repo ) );
	}

	// =========================================================================
	// find() Tests
	// =========================================================================

	/**
	 * Test find returns null when occurrence not found.
	 *
	 * @return void
	 */
	public function test_find_returns_null_when_not_found(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->find( 999 );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'WHERE id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find returns occurrence from database.
	 *
	 * @return void
	 */
	public function test_find_returns_occurrence_from_database(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		// Return a mock database row.
		$row                 = new \stdClass();
		$row->id             = 1;
		$row->event_id       = 10;
		$row->start_datetime = '2026-06-15 19:00:00';
		$row->end_datetime   = '2026-06-15 21:00:00';
		$row->status         = 'scheduled';
		$row->created_at     = '2026-01-01 12:00:00';
		$row->updated_at     = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->find( 1 );

			$this->assertInstanceOf( Occurrence::class, $result );
			$this->assertEquals( 1, $result->id );
			$this->assertEquals( 10, $result->event_id );
			$this->assertEquals( '2026-06-15 19:00:00', $result->start_datetime );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'WHERE id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find uses identity map for cached occurrences.
	 *
	 * @return void
	 */
	public function test_find_uses_identity_map_cache(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$call_count = 0;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		// Return a mock database row only on first call.
		$row                 = new \stdClass();
		$row->id             = 1;
		$row->event_id       = 10;
		$row->start_datetime = '2026-06-15 19:00:00';
		$row->end_datetime   = '2026-06-15 21:00:00';
		$row->status         = 'scheduled';
		$row->created_at     = '2026-01-01 12:00:00';
		$row->updated_at     = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturnCallback( function () use ( $row, &$call_count ) {
				++$call_count;
				return $row;
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

			// First call should hit database.
			$result1 = $repo->find( 1 );
			$this->assertEquals( 1, $call_count );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );

			// Second call should use identity map (no additional DB call).
			$result2 = $repo->find( 1 );
			$this->assertEquals( 1, $call_count ); // Still 1, no additional call.

			// Same object returned from cache.
			$this->assertSame( $result1, $result2 );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_with_event() Tests
	// =========================================================================

	/**
	 * Test find_with_event returns null when not found.
	 *
	 * @return void
	 */
	public function test_find_with_event_returns_null_when_not_found(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->find_with_event( 999 );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'JOIN', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_with_event returns occurrence with event data.
	 *
	 * @return void
	 */
	public function test_find_with_event_attaches_event_data(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		// Return mock row with joined event data.
		$row                    = new \stdClass();
		$row->id                = 1;
		$row->event_id          = 10;
		$row->start_datetime    = '2026-06-15 19:00:00';
		$row->end_datetime      = '2026-06-15 21:00:00';
		$row->status            = 'scheduled';
		$row->created_at        = '2026-01-01 12:00:00';
		$row->updated_at        = '2026-01-01 12:00:00';
		$row->event_title       = 'Test Event';
		$row->event_slug        = 'test-event';
		$row->event_description = 'Test description';
		$row->event_image_id    = 42;

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->find_with_event( 1 );

			$this->assertInstanceOf( Occurrence::class, $result );
			$this->assertEquals( 1, $result->id );

			// Verify event is attached.
			$event = $result->get_event();
			$this->assertNotNull( $event );
			$this->assertEquals( 'Test Event', $event->title );
			$this->assertEquals( 'test-event', $event->slug );

			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'JOIN', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_event_and_datetime() Tests
	// =========================================================================

	/**
	 * Test find_by_event_and_datetime returns null when not found.
	 *
	 * @return void
	 */
	public function test_find_by_event_and_datetime_returns_null_when_not_found(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo     = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$datetime = new \DateTimeImmutable( '2026-06-15 19:00:00' );
			$result   = $repo->find_by_event_and_datetime( 10, $datetime );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'o.event_id = %d', $captured_sql );
			$this->assertStringContainsString( 'o.start_datetime = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_event_and_datetime returns occurrence with event.
	 *
	 * @return void
	 */
	public function test_find_by_event_and_datetime_returns_occurrence(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		// Return mock row with joined event data.
		$row                  = new \stdClass();
		$row->id              = 5;
		$row->event_id        = 10;
		$row->start_datetime  = '2026-06-15 19:00:00';
		$row->end_datetime    = '2026-06-15 21:00:00';
		$row->status          = 'scheduled';
		$row->created_at      = '2026-01-01 12:00:00';
		$row->updated_at      = '2026-01-01 12:00:00';
		$row->event_title     = 'Concert';
		$row->event_slug      = 'concert';
		$row->event_description = 'A concert';
		$row->event_image_id  = null;
		$row->event_type      = 'recurring';
		$row->venue_name      = 'Main Hall';
		$row->venue_address   = '123 Main St';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo     = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$datetime = new \DateTimeImmutable( '2026-06-15 19:00:00' );
			$result   = $repo->find_by_event_and_datetime( 10, $datetime );

			$this->assertInstanceOf( Occurrence::class, $result );
			$this->assertEquals( 5, $result->id );

			$event = $result->get_event();
			$this->assertEquals( 'Concert', $event->title );
			$this->assertEquals( 'Main Hall', $event->venue_name );

			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'JOIN', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// for_event() Tests
	// =========================================================================

	/**
	 * Test for_event returns empty array when no occurrences.
	 *
	 * @return void
	 */
	public function test_for_event_returns_empty_array_when_none(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->for_event( 10 );

			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'event_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test for_event returns array of occurrences.
	 *
	 * @return void
	 */
	public function test_for_event_returns_occurrences(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$row1                 = new \stdClass();
		$row1->id             = 1;
		$row1->event_id       = 10;
		$row1->start_datetime = '2026-06-15 19:00:00';
		$row1->end_datetime   = '2026-06-15 21:00:00';
		$row1->status         = 'scheduled';
		$row1->created_at     = '2026-01-01 12:00:00';
		$row1->updated_at     = '2026-01-01 12:00:00';

		$row2                 = new \stdClass();
		$row2->id             = 2;
		$row2->event_id       = 10;
		$row2->start_datetime = '2026-06-22 19:00:00';
		$row2->end_datetime   = '2026-06-22 21:00:00';
		$row2->status         = 'scheduled';
		$row2->created_at     = '2026-01-01 12:00:00';
		$row2->updated_at     = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row1, $row2 ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->for_event( 10 );

			$this->assertCount( 2, $result );
			$this->assertInstanceOf( Occurrence::class, $result[0] );
			$this->assertEquals( 1, $result[0]->id );
			$this->assertEquals( 2, $result[1]->id );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'event_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test for_event accepts status filter argument.
	 *
	 * @return void
	 */
	public function test_for_event_with_status_filter(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$repo->for_event( 10, array( 'status' => 'cancelled' ) );

			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'status = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// for_event_grouped() Tests
	// =========================================================================

	/**
	 * Test for_event_grouped returns grouped arrays.
	 *
	 * @return void
	 */
	public function test_for_event_grouped_returns_past_and_upcoming(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		// One past, one upcoming.
		$past_row                 = new \stdClass();
		$past_row->id             = 1;
		$past_row->event_id       = 10;
		$past_row->start_datetime = '2025-01-01 19:00:00';
		$past_row->end_datetime   = '2025-01-01 21:00:00';
		$past_row->status         = 'scheduled';
		$past_row->created_at     = '2024-12-01 12:00:00';
		$past_row->updated_at     = '2024-12-01 12:00:00';
		$past_row->is_past        = 1;

		$future_row                 = new \stdClass();
		$future_row->id             = 2;
		$future_row->event_id       = 10;
		$future_row->start_datetime = '2027-06-15 19:00:00';
		$future_row->end_datetime   = '2027-06-15 21:00:00';
		$future_row->status         = 'scheduled';
		$future_row->created_at     = '2026-01-01 12:00:00';
		$future_row->updated_at     = '2026-01-01 12:00:00';
		$future_row->is_past        = 0;

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $past_row, $future_row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->for_event_grouped( 10 );

			$this->assertArrayHasKey( 'past', $result );
			$this->assertArrayHasKey( 'upcoming', $result );
			$this->assertCount( 1, $result['past'] );
			$this->assertCount( 1, $result['upcoming'] );
			$this->assertEquals( 1, $result['past'][0]->id );
			$this->assertEquals( 2, $result['upcoming'][0]->id );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'event_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// in_range() Tests
	// =========================================================================

	/**
	 * Test in_range normalizes date strings.
	 *
	 * @return void
	 */
	public function test_in_range_normalizes_dates(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_values = null;
		$captured_sql    = '';

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, $values ) use ( &$captured_values, &$captured_sql ) {
				$captured_values = $values;
				$captured_sql    = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

			// Pass short date format (should be normalized).
			$repo->in_range( '2026-06-01', '2026-06-30' );

			// First two values should be the normalized dates.
			$this->assertEquals( '2026-06-01 00:00:00', $captured_values[0] );
			$this->assertEquals( '2026-06-30 23:59:59', $captured_values[1] );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test in_range returns occurrences with events attached.
	 *
	 * @return void
	 */
	public function test_in_range_attaches_event_data(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$row                  = new \stdClass();
		$row->id              = 1;
		$row->event_id        = 10;
		$row->start_datetime  = '2026-06-15 19:00:00';
		$row->end_datetime    = '2026-06-15 21:00:00';
		$row->status          = 'scheduled';
		$row->created_at      = '2026-01-01 12:00:00';
		$row->updated_at      = '2026-01-01 12:00:00';
		$row->event_title     = 'Summer Concert';
		$row->event_slug      = 'summer-concert';
		$row->event_image_id  = 55;
		$row->venue_name      = 'Outdoor Stage';
		$row->venue_address   = '456 Park Ave';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->in_range( '2026-06-01', '2026-06-30' );

			$this->assertCount( 1, $result );

			$event = $result[0]->get_event();
			$this->assertEquals( 'Summer Concert', $event->title );
			$this->assertEquals( 55, $event->featured_image_id );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'JOIN', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test in_range without including events.
	 *
	 * @return void
	 */
	public function test_in_range_without_events(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$repo->in_range( '2026-06-01', '2026-06-30', array( 'include_events' => false ) );

			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			// SQL should not include event columns.
			$this->assertStringNotContainsString( 'event_title', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// upcoming() Tests
	// =========================================================================

	/**
	 * Test upcoming delegates to in_range.
	 *
	 * @return void
	 */
	public function test_upcoming_uses_in_range(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->upcoming( 5 );

			$this->assertIsArray( $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// next_for_event() Tests
	// =========================================================================

	/**
	 * Test next_for_event returns null when none upcoming.
	 *
	 * @return void
	 */
	public function test_next_for_event_returns_null_when_none(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->next_for_event( 10 );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'event_id = %d', $captured_sql );

			// Bounded on the end: an occurrence under way is the *next* one, since that is the
			// one a visitor landing on the page right now cares about.
			$this->assertStringContainsString( '(end_utc IS NULL OR end_utc >= %s)', $captured_sql );
			$this->assertStringNotContainsString( 'start_datetime >= %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test next_for_event returns next occurrence.
	 *
	 * @return void
	 */
	public function test_next_for_event_returns_occurrence(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$row                 = new \stdClass();
		$row->id             = 3;
		$row->event_id       = 10;
		$row->start_datetime = '2027-01-01 19:00:00';
		$row->end_datetime   = '2027-01-01 21:00:00';
		$row->status         = 'scheduled';
		$row->created_at     = '2026-01-01 12:00:00';
		$row->updated_at     = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->next_for_event( 10 );

			$this->assertInstanceOf( Occurrence::class, $result );
			$this->assertEquals( 3, $result->id );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'event_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// save() Validation Tests
	// =========================================================================

	/**
	 * Test save throws exception for invalid occurrence.
	 *
	 * @return void
	 */
	public function test_save_throws_on_invalid_occurrence(): void {
		global $wpdb;
		$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

		// Create invalid occurrence (missing event_id).
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-15 19:00:00';
		$occurrence->end_datetime   = '2026-06-15 21:00:00';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Event ID is required' );

		$repo->save( $occurrence );
	}

	/**
	 * Test save throws on missing start_datetime.
	 *
	 * @return void
	 */
	public function test_save_throws_on_missing_start_datetime(): void {
		global $wpdb;
		$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

		$occurrence               = new Occurrence();
		$occurrence->event_id     = 1;
		$occurrence->end_datetime = '2026-06-15 21:00:00';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Start date/time is required' );

		$repo->save( $occurrence );
	}

	/**
	 * Test save throws when end before start.
	 *
	 * @return void
	 */
	public function test_save_throws_when_end_before_start(): void {
		global $wpdb;
		$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

		$occurrence                 = new Occurrence();
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-06-15 21:00:00';
		$occurrence->end_datetime   = '2026-06-15 19:00:00'; // Before start.

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'End time cannot be before start time' );

		$repo->save( $occurrence );
	}

	/**
	 * Test save inserts new occurrence.
	 *
	 * @return void
	 */
	public function test_save_inserts_new_occurrence(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert' ) )
			->getMock();

		$mock_wpdb->prefix    = 'wp_';
		$mock_wpdb->insert_id = 42;

		$mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->with( $this->stringContains( 'nettertech_events_occurrences' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

			$occurrence                 = new Occurrence();
			$occurrence->event_id       = 10;
			$occurrence->start_datetime = '2026-06-15 19:00:00';
			$occurrence->end_datetime   = '2026-06-15 21:00:00';

			$saved = $repo->save( $occurrence );

			$this->assertEquals( 42, $saved->id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save updates existing occurrence.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_occurrence(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_occurrences' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

			$occurrence                 = new Occurrence();
			$occurrence->id             = 5; // Existing ID.
			$occurrence->event_id       = 10;
			$occurrence->start_datetime = '2026-06-15 19:00:00';
			$occurrence->end_datetime   = '2026-06-15 21:00:00';

			$saved = $repo->save( $occurrence );

			$this->assertEquals( 5, $saved->id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save throws on insert failure.
	 *
	 * @return void
	 */
	public function test_save_throws_on_insert_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert' ) )
			->getMock();

		$mock_wpdb->prefix     = 'wp_';
		$mock_wpdb->last_error = 'Duplicate entry';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'insert' )
			->with( $this->stringContains( 'nettertech_events_occurrences' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

			$occurrence                 = new Occurrence();
			$occurrence->event_id       = 10;
			$occurrence->start_datetime = '2026-06-15 19:00:00';
			$occurrence->end_datetime   = '2026-06-15 21:00:00';

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'Failed to insert occurrence' );

			$repo->save( $occurrence );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// save_batch() Tests
	// =========================================================================

	/**
	 * Test save_batch returns 0 for empty array.
	 *
	 * @return void
	 */
	public function test_save_batch_returns_zero_for_empty(): void {
		global $wpdb;
		$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

		$result = $repo->save_batch( array() );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test save_batch saves multiple occurrences.
	 *
	 * @return void
	 */
	public function test_save_batch_saves_occurrences(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'query', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix    = 'wp_';
		$mock_wpdb->insert_id = 100;

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'query' )
			->willReturn( 2 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

			$occ1                 = new Occurrence();
			$occ1->event_id       = 10;
			$occ1->start_datetime = '2026-06-15 19:00:00';
			$occ1->end_datetime   = '2026-06-15 21:00:00';

			$occ2                 = new Occurrence();
			$occ2->event_id       = 10;
			$occ2->start_datetime = '2026-06-22 19:00:00';
			$occ2->end_datetime   = '2026-06-22 21:00:00';

			$saved = $repo->save_batch( array( $occ1, $occ2 ) );

			$this->assertEquals( 2, $saved );
			// Verify AUTO_INCREMENT IDs were assigned.
			$this->assertEquals( 100, $occ1->id );
			$this->assertEquals( 101, $occ2->id );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'INSERT INTO', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// delete() Tests
	// =========================================================================

	/**
	 * Test delete returns true on success.
	 *
	 * @return void
	 */
	public function test_delete_returns_true_on_success(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_occurrences' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->delete( 1 );

			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test delete returns false on failure.
	 *
	 * @return void
	 */
	public function test_delete_returns_false_on_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_occurrences' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->delete( 999 );

			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// delete_for_event() Tests
	// =========================================================================

	/**
	 * Test delete_for_event returns count of deleted.
	 *
	 * @return void
	 */
	public function test_delete_for_event_returns_count(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_occurrences' ), $this->anything(), $this->anything() )
			->willReturn( 5 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->delete_for_event( 10 );

			$this->assertEquals( 5, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// delete_future_for_event() Tests
	// =========================================================================

	/**
	 * Test delete_future_for_event returns count of deleted.
	 *
	 * @return void
	 */
	public function test_delete_future_for_event_returns_count(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'query', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'query' )
			->willReturn( 3 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->delete_future_for_event( 10 );

			$this->assertEquals( 3, $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'DELETE FROM', $captured_sql );
			$this->assertStringContainsString( 'event_id = %d', $captured_sql );
			$this->assertStringContainsString( 'start_datetime >= %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// get_upcoming_by_event() Tests
	// =========================================================================

	/**
	 * Test get_upcoming_by_event delegates to for_event.
	 *
	 * @return void
	 */
	public function test_get_upcoming_by_event_uses_for_event(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->get_upcoming_by_event( 10, 5 );

			$this->assertIsArray( $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			// Should filter by status = scheduled.
			$this->assertStringContainsString( 'status = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// count_for_event() Tests
	// =========================================================================

	/**
	 * Test count_for_event returns integer count.
	 *
	 * @return void
	 */
	public function test_count_for_event_returns_count(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '12' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->count_for_event( 10 );

			$this->assertSame( 12, $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'event_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test count_for_event accepts status filter.
	 *
	 * @return void
	 */
	public function test_count_for_event_with_status_filter(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '5' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->count_for_event( 10, 'cancelled' );

			$this->assertSame( 5, $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'AND status = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// update_status() Tests
	// =========================================================================

	/**
	 * Test update_status rejects invalid status.
	 *
	 * @return void
	 */
	public function test_update_status_rejects_invalid_status(): void {
		global $wpdb;
		$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

		$result = $repo->update_status( 1, 'invalid_status' );

		$this->assertFalse( $result );
	}

	/**
	 * Test update_status returns false when not found.
	 *
	 * @return void
	 */
	public function test_update_status_returns_false_when_not_found(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->update_status( 999, 'cancelled' );

			$this->assertFalse( $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test update_status returns true on success.
	 *
	 * @return void
	 */
	public function test_update_status_returns_true_on_success(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'get_var', 'prepare', 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$all_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$all_sqls ) {
				$all_sqls[] = $sql;
				return $sql;
			} );

		// find() returns the occurrence.
		$row                 = new \stdClass();
		$row->id             = 1;
		$row->event_id       = 10;
		$row->start_datetime = '2026-06-15 19:00:00';
		$row->end_datetime   = '2026-06-15 21:00:00';
		$row->status         = 'scheduled';
		$row->created_at     = '2026-01-01 12:00:00';
		$row->updated_at     = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		// get_event_title() fallback query for OCCURRENCE_CANCELLED hook.
		$mock_wpdb->method( 'get_var' )
			->willReturn( 'Test Event' );

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_occurrences' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->update_status( 1, 'cancelled' );

			$this->assertTrue( $result );
			// Verify at least one query targeted the occurrences table.
			$has_occurrences_query = false;
			foreach ( $all_sqls as $sql ) {
				if ( str_contains( $sql, 'nettertech_events_occurrences' ) ) {
					$has_occurrences_query = true;
					break;
				}
			}
			$this->assertTrue( $has_occurrences_query, 'Expected at least one query targeting nettertech_events_occurrences' );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// get_filtered() Tests
	// =========================================================================

	/**
	 * Test get_filtered returns expected structure.
	 *
	 * @return void
	 */
	public function test_get_filtered_returns_expected_structure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'get_results', 'prepare', 'esc_like' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'esc_like' )
			->willReturnCallback( function ( $text ) {
				return $text;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '25' ); // Total count.

		$row                  = new \stdClass();
		$row->id              = 1;
		$row->event_id        = 10;
		$row->start_datetime  = '2026-06-15 19:00:00';
		$row->end_datetime    = '2026-06-15 21:00:00';
		$row->status          = 'scheduled';
		$row->created_at      = '2026-01-01 12:00:00';
		$row->updated_at      = '2026-01-01 12:00:00';
		$row->event_title     = 'Test Event';
		$row->event_slug      = 'test-event';
		$row->event_image_id  = null;
		$row->venue_name      = null;
		$row->venue_address   = null;
		$row->event_excerpt   = '';
		$row->event_type      = 'single';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->get_filtered( array( 'per_page' => 12 ) );

			$this->assertArrayHasKey( 'items', $result );
			$this->assertArrayHasKey( 'total', $result );
			$this->assertArrayHasKey( 'total_pages', $result );
			$this->assertEquals( 25, $result['total'] );
			$this->assertEquals( 3, $result['total_pages'] ); // ceil(25/12).
			$this->assertCount( 1, $result['items'] );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_filtered with search parameter.
	 *
	 * @return void
	 */
	public function test_get_filtered_with_search(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'get_results', 'prepare', 'esc_like' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'esc_like' )
			->willReturnCallback( function ( $text ) {
				return $text;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '0' );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$repo->get_filtered( array( 'search' => 'concert' ) );

			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'e.title LIKE %s', $captured_sql );
			$this->assertStringContainsString( 'e.description LIKE %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_filtered with past filter.
	 *
	 * @return void
	 */
	public function test_get_filtered_with_past_filter(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'get_results', 'prepare', 'esc_like' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'esc_like' )
			->willReturnCallback( function ( $text ) {
				return $text;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '0' );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$repo->get_filtered( array( 'past' => true, 'upcoming' => false ) );

			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			// Past events filter compares end_datetime (NTE-128).
			$this->assertStringContainsString( '(o.end_utc IS NOT NULL AND o.end_utc < %s)', $captured_sql );
			// Past events ordered DESC by start_datetime (chronological ordering unchanged).
			$this->assertStringContainsString( 'ORDER BY o.start_datetime DESC', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// Interface Tests
	// =========================================================================

	/**
	 * Test repository implements interface.
	 *
	 * @return void
	 */
	public function test_implements_repository_interface(): void {
		global $wpdb;
		$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

		$this->assertInstanceOf(
			\NetterTechEvents\Contracts\OccurrenceRepositoryInterface::class,
			$repo
		);
	}

	// =========================================================================
	// format_db_error() Tests (production branch)
	// =========================================================================

	/**
	 * Test format_db_error returns generic message when WP_DEBUG is false.
	 *
	 * @return void
	 */
	public function test_save_insert_failure_production_error_message(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert' ) )
			->getMock();

		$mock_wpdb->prefix     = 'wp_';
		$mock_wpdb->last_error = 'Some DB error';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'insert' )
			->with( $this->stringContains( 'nettertech_events_occurrences' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

			$occurrence                 = new Occurrence();
			$occurrence->event_id       = 10;
			$occurrence->start_datetime = '2026-06-15 19:00:00';
			$occurrence->end_datetime   = '2026-06-15 21:00:00';

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'Failed to insert occurrence' );

			$repo->save( $occurrence );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save throws on update failure.
	 *
	 * @return void
	 */
	public function test_save_throws_on_update_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix     = 'wp_';
		$mock_wpdb->last_error = 'Update failed';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_occurrences' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

			$occurrence                 = new Occurrence();
			$occurrence->id             = 5;
			$occurrence->event_id       = 10;
			$occurrence->start_datetime = '2026-06-15 19:00:00';
			$occurrence->end_datetime   = '2026-06-15 21:00:00';

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'Failed to update occurrence' );

			$repo->save( $occurrence );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// get_siblings() Tests
	// =========================================================================

	/**
	 * Test get_siblings returns empty result when occurrence not found.
	 *
	 * @return void
	 */
	public function test_get_siblings_returns_empty_when_not_found(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->get_siblings( 999 );

			$this->assertArrayHasKey( 'all', $result );
			$this->assertArrayHasKey( 'past', $result );
			$this->assertArrayHasKey( 'upcoming', $result );
			$this->assertArrayHasKey( 'current_index', $result );
			$this->assertEmpty( $result['all'] );
			$this->assertEquals( -1, $result['current_index'] );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_siblings returns categorized occurrences.
	 *
	 * @return void
	 */
	public function test_get_siblings_returns_categorized_occurrences(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		// get_var returns the event_id for the occurrence.
		$mock_wpdb->method( 'get_var' )
			->willReturn( '10' );

		// Past sibling.
		$past_row                  = new \stdClass();
		$past_row->id              = 1;
		$past_row->event_id        = 10;
		$past_row->start_datetime  = '2020-01-01 19:00:00';
		$past_row->end_datetime    = '2020-01-01 21:00:00';
		$past_row->status          = 'scheduled';
		$past_row->created_at      = '2019-12-01 12:00:00';
		$past_row->updated_at      = '2019-12-01 12:00:00';
		$past_row->event_title     = 'Concert';
		$past_row->event_slug      = 'concert';
		$past_row->event_image_id  = null;
		$past_row->event_type      = 'recurring';
		$past_row->venue_name      = 'Hall';
		$past_row->venue_address   = '123 St';

		// Current sibling.
		$current_row                  = new \stdClass();
		$current_row->id              = 2;
		$current_row->event_id        = 10;
		$current_row->start_datetime  = '2090-06-15 19:00:00';
		$current_row->end_datetime    = '2090-06-15 21:00:00';
		$current_row->status          = 'scheduled';
		$current_row->created_at      = '2026-01-01 12:00:00';
		$current_row->updated_at      = '2026-01-01 12:00:00';
		$current_row->event_title     = 'Concert';
		$current_row->event_slug      = 'concert';
		$current_row->event_image_id  = 55;
		$current_row->event_type      = 'recurring';
		$current_row->venue_name      = 'Hall';
		$current_row->venue_address   = '123 St';

		// Future sibling.
		$future_row                  = new \stdClass();
		$future_row->id              = 3;
		$future_row->event_id        = 10;
		$future_row->start_datetime  = '2090-12-01 19:00:00';
		$future_row->end_datetime    = '2090-12-01 21:00:00';
		$future_row->status          = 'scheduled';
		$future_row->created_at      = '2026-01-01 12:00:00';
		$future_row->updated_at      = '2026-01-01 12:00:00';
		$future_row->event_title     = 'Concert';
		$future_row->event_slug      = 'concert';
		$future_row->event_image_id  = null;
		$future_row->event_type      = 'recurring';
		$future_row->venue_name      = null;
		$future_row->venue_address   = null;

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $past_row, $current_row, $future_row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->get_siblings( 2 );

			$this->assertCount( 3, $result['all'] );
			$this->assertCount( 1, $result['past'] );
			$this->assertCount( 2, $result['upcoming'] );
			$this->assertEquals( 1, $result['current_index'] );

			// Verify event data is attached.
			$event = $result['all'][0]->get_event();
			$this->assertNotNull( $event );
			$this->assertEquals( 'Concert', $event->title );

			// First prepare: lookup event_id from nettertech_events_occurrences.
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sqls[0] );
			// Second prepare: JOIN with nettertech_events_events.
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sqls[1] );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sqls[1] );
			$this->assertStringContainsString( 'JOIN', $captured_sqls[1] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_siblings with null get_results returns empty categories.
	 *
	 * @return void
	 */
	public function test_get_siblings_with_empty_results(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		// get_var returns the event_id for the occurrence.
		$mock_wpdb->method( 'get_var' )
			->willReturn( '10' );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->get_siblings( 1 );

			$this->assertEmpty( $result['all'] );
			$this->assertEmpty( $result['past'] );
			$this->assertEmpty( $result['upcoming'] );
			$this->assertEquals( -1, $result['current_index'] );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// save_batch() Additional Tests
	// =========================================================================

	/**
	 * Test save_batch skips invalid occurrences.
	 *
	 * @return void
	 */
	public function test_save_batch_skips_invalid_occurrences(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'query', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix    = 'wp_';
		$mock_wpdb->insert_id = 100;

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'query' )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

			// Invalid occurrence (missing event_id).
			$invalid                 = new Occurrence();
			$invalid->start_datetime = '2026-06-15 19:00:00';
			$invalid->end_datetime   = '2026-06-15 21:00:00';

			// Valid occurrence.
			$valid                 = new Occurrence();
			$valid->event_id       = 10;
			$valid->start_datetime = '2026-06-22 19:00:00';
			$valid->end_datetime   = '2026-06-22 21:00:00';

			$saved = $repo->save_batch( array( $invalid, $valid ) );

			// Only the valid one should be saved.
			$this->assertEquals( 1, $saved );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save_batch handles existing occurrences via update path.
	 *
	 * @return void
	 */
	public function test_save_batch_updates_existing_occurrences(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_occurrences' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

			$occ                 = new Occurrence();
			$occ->id             = 5;
			$occ->event_id       = 10;
			$occ->start_datetime = '2026-06-15 19:00:00';
			$occ->end_datetime   = '2026-06-15 21:00:00';

			$saved = $repo->save_batch( array( $occ ) );

			$this->assertEquals( 1, $saved );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save_batch handles update failure gracefully.
	 *
	 * @return void
	 */
	public function test_save_batch_handles_update_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix     = 'wp_';
		$mock_wpdb->last_error = 'Update failed';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_occurrences' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

			$occ                 = new Occurrence();
			$occ->id             = 5;
			$occ->event_id       = 10;
			$occ->start_datetime = '2026-06-15 19:00:00';
			$occ->end_datetime   = '2026-06-15 21:00:00';

			$saved = $repo->save_batch( array( $occ ) );

			// Update failure should not count as saved.
			$this->assertEquals( 0, $saved );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save_batch handles batch insert failure.
	 *
	 * @return void
	 */
	public function test_save_batch_handles_insert_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'query', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'query' )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

			$occ                 = new Occurrence();
			$occ->event_id       = 10;
			$occ->start_datetime = '2026-06-15 19:00:00';
			$occ->end_datetime   = '2026-06-15 21:00:00';

			$saved = $repo->save_batch( array( $occ ) );

			$this->assertEquals( 0, $saved );
			// ID should remain null on failure.
			$this->assertNull( $occ->id );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'INSERT INTO', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// delete_for_event() Additional Tests
	// =========================================================================

	/**
	 * Test delete_for_event returns zero on failure.
	 *
	 * @return void
	 */
	public function test_delete_for_event_returns_zero_on_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_occurrences' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->delete_for_event( 10 );

			$this->assertEquals( 0, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// in_range() Additional Tests
	// =========================================================================

	/**
	 * Test in_range with null status filters.
	 *
	 * @return void
	 */
	public function test_in_range_with_null_status_filters(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$repo->in_range(
				'2026-06-01 00:00:00',
				'2026-06-30 23:59:59',
				array(
					'status'       => null,
					'event_status' => null,
				)
			);

			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			// Should not include status filters.
			$this->assertStringNotContainsString( 'o.status = %s', $captured_sql );
			$this->assertStringNotContainsString( 'e.status = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// update_status() Additional Tests
	// =========================================================================

	/**
	 * Test update_status returns false on update failure.
	 *
	 * @return void
	 */
	public function test_update_status_returns_false_on_update_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare', 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$row                 = new \stdClass();
		$row->id             = 1;
		$row->event_id       = 10;
		$row->start_datetime = '2026-06-15 19:00:00';
		$row->end_datetime   = '2026-06-15 21:00:00';
		$row->status         = 'scheduled';
		$row->created_at     = '2026-01-01 12:00:00';
		$row->updated_at     = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_occurrences' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->update_status( 1, 'cancelled' );

			$this->assertFalse( $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// for_event() Additional Tests
	// =========================================================================

	/**
	 * Test for_event with upcoming filter adds datetime condition.
	 *
	 * @return void
	 */
	public function test_for_event_with_upcoming_filter(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$repo->for_event( 10, array( 'upcoming' => true ) );

			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			// NTE-128: upcoming buckets by end_datetime (in-progress stays upcoming).
			$this->assertStringContainsString( '(end_utc IS NULL OR end_utc >= %s)', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test for_event with null status skips status filter.
	 *
	 * @return void
	 */
	public function test_for_event_with_null_status(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$repo->for_event( 10, array( 'status' => null ) );

			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringNotContainsString( 'status = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// for_event_grouped() Additional Tests
	// =========================================================================

	/**
	 * Test for_event_grouped with null status skips status filter.
	 *
	 * @return void
	 */
	public function test_for_event_grouped_with_null_status(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->for_event_grouped( 10, array( 'status' => null ) );

			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringNotContainsString( 'status = %s', $captured_sql );
			$this->assertArrayHasKey( 'past', $result );
			$this->assertArrayHasKey( 'upcoming', $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test for_event_grouped returns empty arrays when no results.
	 *
	 * @return void
	 */
	public function test_for_event_grouped_returns_empty_when_no_results(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->for_event_grouped( 10 );

			$this->assertEmpty( $result['past'] );
			$this->assertEmpty( $result['upcoming'] );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'event_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// count_for_event() Additional Tests
	// =========================================================================

	/**
	 * Test count_for_event without status filter omits status condition.
	 *
	 * @return void
	 */
	public function test_count_for_event_without_status(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '8' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
			$result = $repo->count_for_event( 10, '' );

			$this->assertSame( 8, $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringNotContainsString( 'AND status', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// Identity Map Invalidation Tests (save/delete)
	// =========================================================================

	/**
	 * Test save update invalidates identity map via forget().
	 *
	 * Kills mutant: removal of $this->forget($occurrence->id) after update in save().
	 *
	 * @return void
	 */
	public function test_save_update_invalidates_identity_map(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'update', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$row                 = new \stdClass();
		$row->id             = 1;
		$row->event_id       = 10;
		$row->start_datetime = '2026-06-15 19:00:00';
		$row->end_datetime   = '2026-06-15 21:00:00';
		$row->status         = 'scheduled';
		$row->created_at     = '2026-01-01 12:00:00';
		$row->updated_at     = '2026-01-01 12:00:00';

		$db_call_count = 0;
		$mock_wpdb->method( 'get_row' )
			->willReturnCallback( function () use ( $row, &$db_call_count ) {
				++$db_call_count;
				return $row;
			} );

		$mock_wpdb->method( 'update' )->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

			// Populate identity map via find().
			$occ1 = $repo->find( 1 );
			$this->assertSame( 1, $db_call_count );

			// Second find should use identity map.
			$occ2 = $repo->find( 1 );
			$this->assertSame( 1, $db_call_count );
			$this->assertSame( $occ1, $occ2 );

			// Save (update) should invalidate identity map.
			$occ1->status = 'cancelled';
			$repo->save( $occ1 );

			// Next find should hit DB again since identity map was cleared.
			$occ3 = $repo->find( 1 );
			$this->assertSame( 2, $db_call_count );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test delete invalidates identity map via forget().
	 *
	 * Kills mutant: removal of $this->forget($id) after delete, and condition flip.
	 *
	 * @return void
	 */
	public function test_delete_invalidates_identity_map(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'delete', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$row                 = new \stdClass();
		$row->id             = 1;
		$row->event_id       = 10;
		$row->start_datetime = '2026-06-15 19:00:00';
		$row->end_datetime   = '2026-06-15 21:00:00';
		$row->status         = 'scheduled';
		$row->created_at     = '2026-01-01 12:00:00';
		$row->updated_at     = '2026-01-01 12:00:00';

		$db_call_count = 0;
		$mock_wpdb->method( 'get_row' )
			->willReturnCallback( function () use ( $row, &$db_call_count ) {
				++$db_call_count;
				// First call returns the row, subsequent calls simulate deletion.
				return 1 === $db_call_count ? $row : null;
			} );

		$mock_wpdb->method( 'delete' )->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );

			// Populate identity map.
			$occ = $repo->find( 1 );
			$this->assertInstanceOf( Occurrence::class, $occ );

			// Delete should clear identity map.
			$result = $repo->delete( 1 );
			$this->assertTrue( $result );

			// Next find should hit DB again (returns null since row is gone).
			$occ2 = $repo->find( 1 );
			$this->assertNull( $occ2 );
			$this->assertSame( 2, $db_call_count );
		} finally {
			$wpdb = $original_wpdb;
		}
	}
}