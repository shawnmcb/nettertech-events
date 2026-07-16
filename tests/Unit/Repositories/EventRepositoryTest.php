<?php
/**
 * EventRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Models\Event;

/**
 * Test EventRepository functionality.
 *
 * Tests all repository methods with wpdb mocking.
 */
class EventRepositoryTest extends \NetterTechEventsTestCase {

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
		$repo = new EventRepository( $wpdb );

		$this->assertInstanceOf( EventRepository::class, $repo );
	}

	/**
	 * Test repository stores wpdb reference.
	 *
	 * @return void
	 */
	public function test_repository_stores_wpdb_reference(): void {
		global $wpdb;
		$repo = new EventRepository( $wpdb );

		$reflection = new \ReflectionClass( $repo );
		$db_prop    = $reflection->getProperty( 'db' );

		$this->assertSame( $wpdb, $db_prop->getValue( $repo ) );
	}

	/**
	 * Test repository initializes table name.
	 *
	 * @return void
	 */
	public function test_repository_initializes_table_name(): void {
		global $wpdb;
		$repo = new EventRepository( $wpdb );

		$reflection = new \ReflectionClass( $repo );
		$table_prop = $reflection->getProperty( 'table' );

		$this->assertStringContainsString( 'events', $table_prop->getValue( $repo ) );
	}

	// =========================================================================
	// Identity Map Tests
	// =========================================================================

	/**
	 * Test identity map caches events.
	 *
	 * @return void
	 */
	public function test_identity_map_caches_events(): void {
		global $wpdb;
		$repo = new EventRepository( $wpdb );

		$reflection = new \ReflectionClass( $repo );
		$map_prop   = $reflection->getProperty( 'identity_map' );

		$this->assertEmpty( $map_prop->getValue( $repo ) );
	}

	/**
	 * Test slug index is initialized empty.
	 *
	 * @return void
	 */
	public function test_slug_index_initialized_empty(): void {
		global $wpdb;
		$repo = new EventRepository( $wpdb );

		$reflection = new \ReflectionClass( $repo );
		$index_prop = $reflection->getProperty( 'slug_index' );

		$this->assertEmpty( $index_prop->getValue( $repo ) );
	}

	/**
	 * Test find uses identity map cache.
	 *
	 * @return void
	 */
	public function test_find_uses_identity_map_cache(): void {
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

		$row                     = new \stdClass();
		$row->id                 = 1;
		$row->title              = 'Test Event';
		$row->slug               = 'test-event';
		$row->description        = 'Test description';
		$row->excerpt            = '';
		$row->featured_image_id  = null;
		$row->event_type         = 'single';
		$row->series_id          = null;
		$row->venue_name         = 'Test Venue';
		$row->venue_address      = '123 Test St';
		$row->recurrence_rule    = null;
		$row->recurrence_end_date = null;
		$row->category_ids       = null;
		$row->tag_ids            = null;
		$row->status             = 'published';
		$row->post_id            = null;
		$row->created_at         = '2026-01-01 00:00:00';
		$row->updated_at         = '2026-01-01 00:00:00';

		// First call returns from DB.
		$mock_wpdb->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );

			// First call - fetches from DB.
			$event1 = $repo->find( 1 );
			$this->assertInstanceOf( Event::class, $event1 );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'WHERE id = %d', $captured_sql );

			// Second call - should come from identity map (get_row not called again).
			$event2 = $repo->find( 1 );
			$this->assertSame( $event1, $event2 );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find() Tests
	// =========================================================================

	/**
	 * Test find returns event when found.
	 *
	 * @return void
	 */
	public function test_find_returns_event_when_found(): void {
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

		$row                     = new \stdClass();
		$row->id                 = 1;
		$row->title              = 'Test Event';
		$row->slug               = 'test-event';
		$row->description        = 'Description';
		$row->excerpt            = 'Excerpt';
		$row->featured_image_id  = 100;
		$row->event_type         = 'recurring';
		$row->series_id          = 5;
		$row->venue_name         = 'Test Venue';
		$row->venue_address      = '123 Test St';
		$row->recurrence_rule    = 'FREQ=WEEKLY';
		$row->recurrence_end_date = '2026-12-31';
		$row->category_ids       = '1,2,3';
		$row->tag_ids            = '4,5';
		$row->status             = 'published';
		$row->post_id            = 999;
		$row->created_at         = '2026-01-01 00:00:00';
		$row->updated_at         = '2026-01-01 00:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->find( 1 );

			$this->assertInstanceOf( Event::class, $result );
			$this->assertEquals( 1, $result->id );
			$this->assertEquals( 'Test Event', $result->title );
			$this->assertEquals( 'test-event', $result->slug );
			$this->assertEquals( 'recurring', $result->event_type );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'WHERE id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find returns null when not found.
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
			$repo   = new EventRepository( $wpdb );
			$result = $repo->find( 999 );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'WHERE id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_slug() Tests
	// =========================================================================

	/**
	 * Test find_by_slug returns event when found.
	 *
	 * @return void
	 */
	public function test_find_by_slug_returns_event(): void {
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

		$row                     = new \stdClass();
		$row->id                 = 1;
		$row->title              = 'Test Event';
		$row->slug               = 'test-event';
		$row->description        = '';
		$row->excerpt            = '';
		$row->featured_image_id  = null;
		$row->event_type         = 'single';
		$row->series_id          = null;
		$row->venue_name         = '';
		$row->venue_address      = '';
		$row->recurrence_rule    = null;
		$row->recurrence_end_date = null;
		$row->category_ids       = null;
		$row->tag_ids            = null;
		$row->status             = 'published';
		$row->post_id            = null;
		$row->created_at         = '2026-01-01 00:00:00';
		$row->updated_at         = '2026-01-01 00:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->find_by_slug( 'test-event' );

			$this->assertInstanceOf( Event::class, $result );
			$this->assertEquals( 'test-event', $result->slug );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'WHERE slug = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_slug returns null when not found.
	 *
	 * @return void
	 */
	public function test_find_by_slug_returns_null_when_not_found(): void {
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
			$repo   = new EventRepository( $wpdb );
			$result = $repo->find_by_slug( 'nonexistent' );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'WHERE slug = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_post_id() Tests
	// =========================================================================

	/**
	 * Test find_by_post_id returns event when found.
	 *
	 * @return void
	 */
	public function test_find_by_post_id_returns_event(): void {
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

		$row                     = new \stdClass();
		$row->id                 = 1;
		$row->title              = 'Test Event';
		$row->slug               = 'test-event';
		$row->description        = '';
		$row->excerpt            = '';
		$row->featured_image_id  = null;
		$row->event_type         = 'single';
		$row->series_id          = null;
		$row->venue_name         = '';
		$row->venue_address      = '';
		$row->recurrence_rule    = null;
		$row->recurrence_end_date = null;
		$row->category_ids       = null;
		$row->tag_ids            = null;
		$row->status             = 'published';
		$row->post_id            = 999;
		$row->created_at         = '2026-01-01 00:00:00';
		$row->updated_at         = '2026-01-01 00:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->find_by_post_id( 999 );

			$this->assertInstanceOf( Event::class, $result );
			$this->assertEquals( 999, $result->post_id );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'WHERE post_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_post_id returns null when not found.
	 *
	 * @return void
	 */
	public function test_find_by_post_id_returns_null(): void {
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
			$repo   = new EventRepository( $wpdb );
			$result = $repo->find_by_post_id( 999 );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'WHERE post_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// all() Tests
	// =========================================================================

	/**
	 * Test all returns array of events.
	 *
	 * @return void
	 */
	public function test_all_returns_events(): void {
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

		$row1                     = new \stdClass();
		$row1->id                 = 1;
		$row1->title              = 'Event 1';
		$row1->slug               = 'event-1';
		$row1->description        = '';
		$row1->excerpt            = '';
		$row1->featured_image_id  = null;
		$row1->event_type         = 'single';
		$row1->series_id          = null;
		$row1->venue_name         = '';
		$row1->venue_address      = '';
		$row1->recurrence_rule    = null;
		$row1->recurrence_end_date = null;
		$row1->category_ids       = null;
		$row1->tag_ids            = null;
		$row1->status             = 'published';
		$row1->post_id            = null;
		$row1->created_at         = '2026-01-01 00:00:00';
		$row1->updated_at         = '2026-01-01 00:00:00';

		$row2        = clone $row1;
		$row2->id    = 2;
		$row2->title = 'Event 2';
		$row2->slug  = 'event-2';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row1, $row2 ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->all();

			$this->assertIsArray( $result );
			$this->assertCount( 2, $result );
			$this->assertInstanceOf( Event::class, $result[0] );
			$this->assertEquals( 'Event 1', $result[0]->title );
			$this->assertEquals( 'Event 2', $result[1]->title );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test all returns empty array when none found.
	 *
	 * @return void
	 */
	public function test_all_returns_empty_when_none(): void {
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
			$repo   = new EventRepository( $wpdb );
			$result = $repo->all();

			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test all with status filter.
	 *
	 * @return void
	 */
	public function test_all_with_status_filter(): void {
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
			$repo = new EventRepository( $wpdb );
			$repo->all( array( 'status' => 'published' ) );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'status = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test all with type filter.
	 *
	 * @return void
	 */
	public function test_all_with_type_filter(): void {
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
			$repo = new EventRepository( $wpdb );
			$repo->all( array( 'type' => 'recurring' ) );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'event_type = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test all with series filter.
	 *
	 * @return void
	 */
	public function test_all_with_series_filter(): void {
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
			$repo = new EventRepository( $wpdb );
			$repo->all( array( 'series_id' => 5 ) );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'series_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test all uses default ordering.
	 *
	 * @return void
	 */
	public function test_all_uses_default_ordering(): void {
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
			$repo = new EventRepository( $wpdb );
			$repo->all();

			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'ORDER BY created_at DESC', $captured_sql );
			$this->assertStringContainsString( 'LIMIT %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// published() Tests
	// =========================================================================

	/**
	 * Test published filters by published status.
	 *
	 * @return void
	 */
	public function test_published_filters_by_status(): void {
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
			$repo = new EventRepository( $wpdb );
			$repo->published();

			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'status = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// by_series() Tests
	// =========================================================================

	/**
	 * Test by_series filters by series_id.
	 *
	 * @return void
	 */
	public function test_by_series_filters_by_series_id(): void {
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
			$repo = new EventRepository( $wpdb );
			$repo->by_series( 5 );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'series_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// count() Tests
	// =========================================================================

	/**
	 * Test count returns integer.
	 *
	 * @return void
	 */
	public function test_count_returns_integer(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'get_var' )
			->with( $this->stringContains( 'nettertech_events_events' ) )
			->willReturn( '15' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->count();

			$this->assertIsInt( $result );
			$this->assertEquals( 15, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test count with status filter.
	 *
	 * @return void
	 */
	public function test_count_with_status_filter(): void {
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
			->willReturn( '5' );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );
			$repo->count( array( 'status' => 'published' ) );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'status = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test count without filters has no WHERE clause.
	 *
	 * @return void
	 */
	public function test_count_without_filters(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'get_var' )
			->with( $this->stringContains( 'nettertech_events_events' ) )
			->willReturn( '10' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->count();

			$this->assertEquals( 10, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// save() Tests
	// =========================================================================

	/**
	 * Test save throws exception for invalid event.
	 *
	 * @return void
	 */
	public function test_save_throws_on_invalid_event(): void {
		global $wpdb;
		$repo = new EventRepository( $wpdb );

		$event       = new Event();
		$event->slug = 'test-slug';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Event title is required' );

		$repo->save( $event );
	}

	/**
	 * Test save generates slug from title when missing.
	 *
	 * @return void
	 */
	public function test_save_generates_slug_from_title(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix    = 'wp_';
		$mock_wpdb->insert_id = 123;

		$captured_table = '';
		$captured_data  = null;
		$mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->willReturnCallback( function ( $table, $data, $formats ) use ( &$captured_table, &$captured_data ) {
				$captured_table = $table;
				$captured_data  = $data;
				return 1;
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );

			$event        = new Event();
			$event->title = 'My Test Event';

			$saved = $repo->save( $event );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_table );
			$this->assertEquals( 'My Test Event', $captured_data['title'] );
			$this->assertStringContainsString( 'my-test-event', $captured_data['slug'] );
			$this->assertEquals( 123, $saved->id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save inserts new event.
	 *
	 * @return void
	 */
	public function test_save_inserts_new_event(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix    = 'wp_';
		$mock_wpdb->insert_id = 456;

		$captured_table = '';
		$captured_data  = null;
		$mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->willReturnCallback( function ( $table, $data, $formats ) use ( &$captured_table, &$captured_data ) {
				$captured_table = $table;
				$captured_data  = $data;
				return 1;
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );

			$event        = new Event();
			$event->title = 'New Event';
			$event->slug  = 'new-event';

			$saved = $repo->save( $event );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_table );
			$this->assertEquals( 'New Event', $captured_data['title'] );
			$this->assertEquals( 'new-event', $captured_data['slug'] );
			$this->assertEquals( 456, $saved->id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save writes created_at explicitly in UTC (NTE-131).
	 *
	 * The insert path must set created_at with gmdate() (UTC) rather than relying
	 * on the MySQL CURRENT_TIMESTAMP default, which is environment-dependent.
	 *
	 * @return void
	 */
	public function test_save_writes_created_at_in_utc(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix    = 'wp_';
		$mock_wpdb->insert_id = 789;

		$captured_data    = null;
		$captured_formats = null;
		$mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->willReturnCallback( function ( $table, $data, $formats ) use ( &$captured_data, &$captured_formats ) {
				$captured_data    = $data;
				$captured_formats = $formats;
				return 1;
			} );

		$wpdb = $mock_wpdb;

		try {
			$expected_utc = gmdate( 'Y-m-d H:i:s' );

			$repo         = new EventRepository( $wpdb );
			$event        = new Event();
			$event->title = 'UTC Event';
			$event->slug  = 'utc-event';

			$saved = $repo->save( $event );

			// created_at must be present and a valid UTC datetime string.
			$this->assertArrayHasKey( 'created_at', $captured_data );
			$this->assertMatchesRegularExpression(
				'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
				$captured_data['created_at']
			);

			// Must equal gmdate (UTC) within a small tolerance, proving it is UTC
			// and not the MySQL default or current_time('mysql') (site-local).
			$delta = abs( strtotime( $captured_data['created_at'] ) - strtotime( $expected_utc ) );
			$this->assertLessThanOrEqual( 5, $delta, 'created_at should be written in UTC via gmdate().' );

			// The format specifier for created_at must be appended as a string.
			$this->assertSame( '%s', end( $captured_formats ) );

			// The saved model should carry the same UTC value.
			$this->assertSame( $captured_data['created_at'], $saved->created_at );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save does NOT set created_at on the update path (NTE-131).
	 *
	 * created_at must only be written once, at insert time; updates must never
	 * overwrite it.
	 *
	 * @return void
	 */
	public function test_save_does_not_set_created_at_on_update(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_data = null;
		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->willReturnCallback( function ( $table, $data, $where, $formats, $where_formats ) use ( &$captured_data ) {
				$captured_data = $data;
				return 1;
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo         = new EventRepository( $wpdb );
			$event        = new Event();
			$event->id    = 7;
			$event->title = 'Existing Event';
			$event->slug  = 'existing-event';

			$repo->save( $event );

			$this->assertArrayNotHasKey( 'created_at', $captured_data );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save updates existing event.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_event(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_table = '';
		$captured_where = null;
		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->willReturnCallback( function ( $table, $data, $where, $formats, $where_formats ) use ( &$captured_table, &$captured_where ) {
				$captured_table = $table;
				$captured_where = $where;
				return 1;
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );

			$event        = new Event();
			$event->id    = 5;
			$event->title = 'Updated Event';
			$event->slug  = 'updated-event';

			$saved = $repo->save( $event );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_table );
			$this->assertEquals( array( 'id' => 5 ), $captured_where );
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
			->onlyMethods( array( 'insert', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix     = 'wp_';
		$mock_wpdb->last_error = 'Duplicate entry';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'insert' )
			->with( $this->stringContains( 'nettertech_events_events' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );

			$event        = new Event();
			$event->title = 'Test Event';
			$event->slug  = 'test-event';

			$this->expectException( \RuntimeException::class );

			$repo->save( $event );
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
			->onlyMethods( array( 'get_row', 'delete', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$row                     = new \stdClass();
		$row->id                 = 1;
		$row->title              = 'Test Event';
		$row->slug               = 'test-event';
		$row->description        = '';
		$row->excerpt            = '';
		$row->featured_image_id  = null;
		$row->event_type         = 'single';
		$row->series_id          = null;
		$row->venue_name         = '';
		$row->venue_address      = '';
		$row->recurrence_rule    = null;
		$row->recurrence_end_date = null;
		$row->category_ids       = null;
		$row->tag_ids            = null;
		$row->status             = 'published';
		$row->post_id            = null;
		$row->created_at         = '2026-01-01 00:00:00';
		$row->updated_at         = '2026-01-01 00:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_events' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->delete( 1 );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'WHERE id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test delete returns false when event not found.
	 *
	 * @return void
	 */
	public function test_delete_returns_false_when_not_found(): void {
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
			$repo   = new EventRepository( $wpdb );
			$result = $repo->delete( 999 );

			$this->assertFalse( $result );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'WHERE id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// slug_exists() Tests
	// =========================================================================

	/**
	 * Test slug_exists returns true when exists.
	 *
	 * @return void
	 */
	public function test_slug_exists_returns_true(): void {
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
			->willReturn( '1' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->slug_exists( 'existing-slug' );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'WHERE slug = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test slug_exists returns false when not exists.
	 *
	 * @return void
	 */
	public function test_slug_exists_returns_false(): void {
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
			->willReturn( '0' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->slug_exists( 'new-slug' );

			$this->assertFalse( $result );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'WHERE slug = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test slug_exists with exclude_id.
	 *
	 * @return void
	 */
	public function test_slug_exists_with_exclude_id(): void {
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
			->willReturn( '0' );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );
			$repo->slug_exists( 'test-slug', 5 );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'WHERE slug = %s', $captured_sql );
			$this->assertStringContainsString( 'id != %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// has_ticket_types() Tests
	// =========================================================================

	/**
	 * Test has_ticket_types returns true when tickets exist.
	 *
	 * @return void
	 */
	public function test_has_ticket_types_returns_true(): void {
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
			->willReturn( '3' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->has_ticket_types( 1 );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			// Must count BOTH event-scoped (tt.event_id) and occurrence-scoped
			// (o.event_id via LEFT JOIN) ticket types — regression guard for the
			// bug that hid every event-scoped-ticketed event from "ticketed".
			$this->assertStringContainsString( 'LEFT JOIN', $captured_sql );
			$this->assertStringContainsString( 'tt.event_id = %d', $captured_sql );
			$this->assertStringContainsString( 'o.event_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test has_ticket_types returns false when no tickets.
	 *
	 * @return void
	 */
	public function test_has_ticket_types_returns_false(): void {
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
			->willReturn( '0' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->has_ticket_types( 1 );

			$this->assertFalse( $result );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql );
			$this->assertStringContainsString( 'nettertech_events_ticket_types', $captured_sql );
			// Must count BOTH event-scoped (tt.event_id) and occurrence-scoped
			// (o.event_id via LEFT JOIN) ticket types — regression guard for the
			// bug that hid every event-scoped-ticketed event from "ticketed".
			$this->assertStringContainsString( 'LEFT JOIN', $captured_sql );
			$this->assertStringContainsString( 'tt.event_id = %d', $captured_sql );
			$this->assertStringContainsString( 'o.event_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// search() Tests
	// =========================================================================

	/**
	 * Test search returns matching events.
	 *
	 * @return void
	 */
	public function test_search_returns_matching_events(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'esc_like' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'esc_like' )
			->willReturnArgument( 0 );

		$row                     = new \stdClass();
		$row->id                 = 1;
		$row->title              = 'Concert Event';
		$row->slug               = 'concert-event';
		$row->description        = '';
		$row->excerpt            = '';
		$row->featured_image_id  = null;
		$row->event_type         = 'single';
		$row->series_id          = null;
		$row->venue_name         = '';
		$row->venue_address      = '';
		$row->recurrence_rule    = null;
		$row->recurrence_end_date = null;
		$row->category_ids       = null;
		$row->tag_ids            = null;
		$row->status             = 'published';
		$row->post_id            = null;
		$row->created_at         = '2026-01-01 00:00:00';
		$row->updated_at         = '2026-01-01 00:00:00';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->search( 'Concert' );

			$this->assertIsArray( $result );
			$this->assertCount( 1, $result );
			$this->assertEquals( 'Concert Event', $result[0]->title );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'title LIKE %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test search builds LIKE query.
	 *
	 * @return void
	 */
	public function test_search_builds_like_query(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'esc_like' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'esc_like' )
			->willReturnArgument( 0 );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );
			$repo->search( 'test' );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'title LIKE %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test search uses default status filter.
	 *
	 * @return void
	 */
	public function test_search_uses_default_status_filter(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'esc_like' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'esc_like' )
			->willReturnArgument( 0 );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );
			$repo->search( 'test' );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'status = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// paginate() Tests
	// =========================================================================

	/**
	 * Test paginate returns items, total, and pages.
	 *
	 * @return void
	 */
	public function test_paginate_returns_items_total_and_pages(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'get_var' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$row                      = new \stdClass();
		$row->id                  = 1;
		$row->title               = 'Test Event';
		$row->slug                = 'test-event';
		$row->description         = '';
		$row->excerpt             = '';
		$row->featured_image_id   = null;
		$row->event_type          = 'single';
		$row->series_id           = null;
		$row->venue_name          = '';
		$row->venue_address       = '';
		$row->recurrence_rule     = null;
		$row->recurrence_end_date = null;
		$row->category_ids        = null;
		$row->tag_ids             = null;
		$row->status              = 'published';
		$row->post_id             = null;
		$row->created_at          = '2026-01-01 00:00:00';
		$row->updated_at          = '2026-01-01 00:00:00';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row ) );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '1' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->paginate();

			$this->assertArrayHasKey( 'items', $result );
			$this->assertArrayHasKey( 'total', $result );
			$this->assertArrayHasKey( 'pages', $result );
			$this->assertIsArray( $result['items'] );
			$this->assertCount( 1, $result['items'] );
			$this->assertInstanceOf( Event::class, $result['items'][0] );
			$this->assertIsInt( $result['total'] );
			$this->assertIsInt( $result['pages'] );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test paginate clamps per_page to maximum of 100.
	 *
	 * @return void
	 */
	public function test_paginate_clamps_per_page_to_max_100(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'get_var' ) )
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

		$mock_wpdb->method( 'get_var' )
			->willReturn( '500' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->paginate( array( 'per_page' => 200 ) );

			// 500 total / 100 per_page (clamped) = 5 pages.
			$this->assertEquals( 5, $result['pages'] );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test paginate calculates pages correctly.
	 *
	 * @return void
	 */
	public function test_paginate_calculates_pages_correctly(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'get_var' ) )
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

		$mock_wpdb->method( 'get_var' )
			->willReturn( '45' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->paginate( array( 'per_page' => 20 ) );

			// ceil(45 / 20) = 3 pages.
			$this->assertEquals( 45, $result['total'] );
			$this->assertEquals( 3, $result['pages'] );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test paginate uses search when search argument is provided.
	 *
	 * @return void
	 */
	public function test_paginate_uses_search_when_search_provided(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'get_var', 'esc_like' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'esc_like' )
			->willReturnArgument( 0 );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '0' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->paginate( array( 'search' => 'Concert' ) );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'title LIKE %s', $captured_sql );
			$this->assertArrayHasKey( 'items', $result );
			$this->assertArrayHasKey( 'total', $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// search_count() Tests
	// =========================================================================

	/**
	 * Test search_count returns integer.
	 *
	 * @return void
	 */
	public function test_search_count_returns_integer(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare', 'esc_like' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'esc_like' )
			->willReturnArgument( 0 );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '7' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->search_count( 'Concert' );

			$this->assertIsInt( $result );
			$this->assertEquals( 7, $result );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'title LIKE %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test search_count uses status filter.
	 *
	 * @return void
	 */
	public function test_search_count_uses_status_filter(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare', 'esc_like' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'esc_like' )
			->willReturnArgument( 0 );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '3' );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );
			$repo->search_count( 'Concert', array( 'status' => 'draft' ) );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'title LIKE %s', $captured_sql );
			$this->assertStringContainsString( 'status = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test search_count without status filter.
	 *
	 * @return void
	 */
	public function test_search_count_without_status(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare', 'esc_like' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'esc_like' )
			->willReturnArgument( 0 );

		$mock_wpdb->method( 'get_var' )
			->willReturn( '12' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->search_count( 'Concert', array( 'status' => null ) );

			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'title LIKE %s', $captured_sql );
			$this->assertStringNotContainsString( 'status = %s', $captured_sql );
			$this->assertEquals( 12, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_slug() Slug Index Cache Tests
	// =========================================================================

	/**
	 * Test find_by_slug uses slug index cache on second call.
	 *
	 * @return void
	 */
	public function test_find_by_slug_uses_slug_index_cache(): void {
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

		$row                      = new \stdClass();
		$row->id                  = 1;
		$row->title               = 'Test Event';
		$row->slug                = 'test-event';
		$row->description         = '';
		$row->excerpt             = '';
		$row->featured_image_id   = null;
		$row->event_type          = 'single';
		$row->series_id           = null;
		$row->venue_name          = '';
		$row->venue_address       = '';
		$row->recurrence_rule     = null;
		$row->recurrence_end_date = null;
		$row->category_ids        = null;
		$row->tag_ids             = null;
		$row->status              = 'published';
		$row->post_id             = null;
		$row->created_at          = '2026-01-01 00:00:00';
		$row->updated_at          = '2026-01-01 00:00:00';

		// DB should only be hit once; second call uses slug index.
		$mock_wpdb->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );

			// First call - fetches from DB, populates slug index.
			$event1 = $repo->find_by_slug( 'test-event' );
			$this->assertInstanceOf( Event::class, $event1 );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql );
			$this->assertStringContainsString( 'WHERE slug = %s', $captured_sql );

			// Second call - should come from slug index.
			$event2 = $repo->find_by_slug( 'test-event' );
			$this->assertSame( $event1, $event2 );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// save() — Update Failure Tests
	// =========================================================================

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
			->onlyMethods( array( 'update', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix     = 'wp_';
		$mock_wpdb->last_error = 'Table locked';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_events' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );

			$event        = new Event();
			$event->id    = 5;
			$event->title = 'Updated Event';
			$event->slug  = 'updated-event';

			$this->expectException( \RuntimeException::class );

			$repo->save( $event );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// generate_unique_slug() Tests
	// =========================================================================

	/**
	 * Test generate_unique_slug throws RuntimeException after max attempts.
	 *
	 * @return void
	 */
	public function test_generate_unique_slug_throws_after_max_attempts(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$mock_wpdb->method( 'get_var' )
			->willReturn( 'existing-slug' );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'Unable to generate unique slug' );

			$repo->generate_unique_slug( 'Test Event' );
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
		$repo = new EventRepository( $wpdb );

		$this->assertInstanceOf(
			\NetterTechEvents\Contracts\EventRepositoryInterface::class,
			$repo
		);
	}

	// =========================================================================
	// Method Existence Tests
	// =========================================================================

	/**
	 * Data provider for method existence.
	 *
	 * @return array<array{string}>
	 */
	public static function method_existence_provider(): array {
		return array(
			'find'             => array( 'find' ),
			'find_by_slug'     => array( 'find_by_slug' ),
			'find_by_post_id'  => array( 'find_by_post_id' ),
			'all'              => array( 'all' ),
			'published'        => array( 'published' ),
			'by_series'        => array( 'by_series' ),
			'count'            => array( 'count' ),
			'save'             => array( 'save' ),
			'delete'           => array( 'delete' ),
			'slug_exists'      => array( 'slug_exists' ),
			'has_ticket_types' => array( 'has_ticket_types' ),
			'search'           => array( 'search' ),
		);
	}

	/**
	 * Test repository methods exist.
	 *
	 * @dataProvider method_existence_provider
	 *
	 * @param string $method Method name.
	 * @return void
	 */
	public function test_method_exists( string $method ): void {
		global $wpdb;
		$repo = new EventRepository( $wpdb );

		$this->assertTrue(
			method_exists( $repo, $method ),
			"Method {$method} should exist on EventRepository"
		);
	}

	// =========================================================================
	// Hook do_action Tests (save/delete lifecycle)
	// =========================================================================

	/**
	 * Test save fires before and after save hooks.
	 *
	 * Kills mutants: removal of do_action(BEFORE_SAVE_EVENT) and do_action(AFTER_SAVE_EVENT).
	 *
	 * @return void
	 */
	public function test_save_fires_before_and_after_hooks(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix    = 'wp_';
		$mock_wpdb->insert_id = 1;

		$mock_wpdb->method( 'insert' )->willReturn( 1 );

		$captured_actions = array();
		\Brain\Monkey\Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ) use ( &$captured_actions ) {
				$captured_actions[] = $hook;
			}
		);

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );

			$event        = new Event();
			$event->title = 'Hook Test';
			$event->slug  = 'hook-test';

			$repo->save( $event );

			$this->assertContains( 'nettertech_events_before_save_event', $captured_actions );
			$this->assertContains( 'nettertech_events_after_save_event', $captured_actions );

			// Verify order: before fires before after.
			$before_idx = array_search( 'nettertech_events_before_save_event', $captured_actions, true );
			$after_idx  = array_search( 'nettertech_events_after_save_event', $captured_actions, true );
			$this->assertLessThan( $after_idx, $before_idx );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test delete fires before and after delete hooks.
	 *
	 * Kills mutants: removal of do_action(BEFORE_DELETE_EVENT) and do_action(AFTER_DELETE_EVENT).
	 *
	 * @return void
	 */
	public function test_delete_fires_before_and_after_hooks(): void {
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

		$row                      = new \stdClass();
		$row->id                  = 1;
		$row->title               = 'Test Event';
		$row->slug                = 'test-event';
		$row->description         = '';
		$row->excerpt             = '';
		$row->featured_image_id   = null;
		$row->event_type          = 'single';
		$row->series_id           = null;
		$row->venue_name          = '';
		$row->venue_address       = '';
		$row->recurrence_rule     = null;
		$row->recurrence_end_date = null;
		$row->category_ids        = null;
		$row->tag_ids             = null;
		$row->status              = 'published';
		$row->post_id             = null;
		$row->created_at          = '2026-01-01 00:00:00';
		$row->updated_at          = '2026-01-01 00:00:00';

		$mock_wpdb->method( 'get_row' )->willReturn( $row );
		$mock_wpdb->method( 'delete' )->willReturn( 1 );

		$captured_actions = array();
		\Brain\Monkey\Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ) use ( &$captured_actions ) {
				$captured_actions[] = $hook;
			}
		);

		$wpdb = $mock_wpdb;

		try {
			$repo   = new EventRepository( $wpdb );
			$result = $repo->delete( 1 );

			$this->assertTrue( $result );
			$this->assertContains( 'nettertech_events_before_delete_event', $captured_actions );
			$this->assertContains( 'nettertech_events_after_delete_event', $captured_actions );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// uncache_event Tests (identity map invalidation on save/delete)
	// =========================================================================

	/**
	 * Test save invalidates identity map so stale data is not returned.
	 *
	 * Kills mutants: removal of uncache_event / forget($id) in save path, and
	 * the condition flip `null !== $event` -> `null === $event` in uncache_event.
	 *
	 * @return void
	 */
	public function test_save_update_invalidates_and_recaches_identity_map(): void {
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

		$row                      = new \stdClass();
		$row->id                  = 1;
		$row->title               = 'Original Title';
		$row->slug                = 'original-title';
		$row->description         = '';
		$row->excerpt             = '';
		$row->featured_image_id   = null;
		$row->event_type          = 'single';
		$row->series_id           = null;
		$row->venue_name          = '';
		$row->venue_address       = '';
		$row->recurrence_rule     = null;
		$row->recurrence_end_date = null;
		$row->category_ids        = null;
		$row->tag_ids             = null;
		$row->status              = 'published';
		$row->post_id             = null;
		$row->created_at          = '2026-01-01 00:00:00';
		$row->updated_at          = '2026-01-01 00:00:00';

		// get_row called for find() and for old status lookup during save().
		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'get_row' )
			->willReturn( $row );

		$mock_wpdb->method( 'update' )->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );

			// Populate identity map via find().
			$event1 = $repo->find( 1 );
			$this->assertInstanceOf( Event::class, $event1 );
			$this->assertSame( 'Original Title', $event1->title );

			// Update the event via save() -- this should re-cache with new title.
			$event1->title = 'Updated Title';
			$repo->save( $event1 );

			// find() again should return the updated event from identity map.
			$event2 = $repo->find( 1 );
			$this->assertSame( 'Updated Title', $event2->title );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test delete removes event from identity map.
	 *
	 * Kills mutant: removal of $this->forget($id) in uncache_event.
	 *
	 * @return void
	 */
	public function test_delete_clears_identity_map(): void {
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

		$row                      = new \stdClass();
		$row->id                  = 1;
		$row->title               = 'Delete Me';
		$row->slug                = 'delete-me';
		$row->description         = '';
		$row->excerpt             = '';
		$row->featured_image_id   = null;
		$row->event_type          = 'single';
		$row->series_id           = null;
		$row->venue_name          = '';
		$row->venue_address       = '';
		$row->recurrence_rule     = null;
		$row->recurrence_end_date = null;
		$row->category_ids        = null;
		$row->tag_ids             = null;
		$row->status              = 'published';
		$row->post_id             = null;
		$row->created_at          = '2026-01-01 00:00:00';
		$row->updated_at          = '2026-01-01 00:00:00';

		$call_count = 0;
		$mock_wpdb->method( 'get_row' )
			->willReturnCallback( function () use ( $row, &$call_count ) {
				++$call_count;
				// First call returns the event, subsequent calls simulate DB row gone.
				return 1 === $call_count ? $row : null;
			} );

		$mock_wpdb->method( 'delete' )->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );

			// Populate identity map.
			$repo->find( 1 );

			// Delete - should remove from identity map.
			$repo->delete( 1 );

			// After delete + identity map clear, find should hit DB again (returns null).
			$result = $repo->find( 1 );
			$this->assertNull( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// generate_unique_slug Precision Tests
	// =========================================================================

	/**
	 * Test first collision slug appends -2 (not -3).
	 *
	 * Kills mutant: counter starts at 2 mutated to 3.
	 *
	 * @return void
	 */
	public function test_generate_unique_slug_first_collision_appends_2(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$slugs_checked = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$slugs_checked ) {
				if ( ! empty( $args ) ) {
					$slugs_checked[] = $args[0];
				}
				return $sql;
			} );

		$call_count = 0;
		$mock_wpdb->method( 'get_var' )
			->willReturnCallback( function () use ( &$call_count ) {
				++$call_count;
				// First call: base slug exists. Second call: slug-2 does not exist.
				return 1 === $call_count ? 'test-event' : null;
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );

			\Brain\Monkey\Functions\when( 'sanitize_title' )->returnArg();

			$slug = $repo->generate_unique_slug( 'test-event' );

			$this->assertSame( 'test-event-2', $slug );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test generate_unique_slug boundary: counter equals max_attempts on exit.
	 *
	 * Slug-99 is the last valid attempt before counter reaches 101 and throws.
	 * With counter=100 on exit (slug-99 was free), counter > max_attempts is false.
	 * Kills mutant: $counter > $max_attempts mutated to >= would fail here.
	 *
	 * @return void
	 */
	public function test_generate_unique_slug_succeeds_at_max_attempt_boundary(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		// Call 1: base "test-event" exists. Calls 2..98: slug-2 through slug-98 exist.
		// Call 99: slug-99 is free (returns null). Counter exits at 100.
		// Post-loop: 100 > 100 is false -> returns "test-event-99".
		$get_var_calls = 0;
		$mock_wpdb->method( 'get_var' )
			->willReturnCallback( function () use ( &$get_var_calls ) {
				++$get_var_calls;
				// Calls 1..98 return existing, call 99 returns null.
				return $get_var_calls <= 98 ? 'existing' : null;
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo = new EventRepository( $wpdb );

			\Brain\Monkey\Functions\when( 'sanitize_title' )->returnArg();

			$slug = $repo->generate_unique_slug( 'test-event' );

			$this->assertSame( 'test-event-99', $slug );
		} finally {
			$wpdb = $original_wpdb;
		}
	}
}
