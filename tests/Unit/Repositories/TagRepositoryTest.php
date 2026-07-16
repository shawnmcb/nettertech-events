<?php
/**
 * TagRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Repositories\TagRepository;
use NetterTechEvents\Models\Tag;

/**
 * Test TagRepository functionality.
 *
 * Uses dynamic wpdb mocks to test query building and data handling
 * without requiring a live database connection.
 */
class TagRepositoryTest extends \NetterTechEventsTestCase {

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
		$repo = new TagRepository( $wpdb );

		$this->assertInstanceOf( TagRepository::class, $repo );
	}

	/**
	 * Test repository initializes table names.
	 *
	 * @return void
	 */
	public function test_repository_initializes_table_names(): void {
		global $wpdb;
		$repo = new TagRepository( $wpdb );

		$reflection = new \ReflectionClass( $repo );

		$table_prop          = $reflection->getProperty( 'table' );
		$junction_table_prop = $reflection->getProperty( 'junction_table' );

		$this->assertStringContainsString( 'tags', $table_prop->getValue( $repo ) );
		$this->assertStringContainsString( 'event_tags', $junction_table_prop->getValue( $repo ) );
	}

	// =========================================================================
	// find() Tests
	// =========================================================================

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
			$repo   = new TagRepository( $wpdb );
			$result = $repo->find( 999 );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_tags', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find returns tag from database.
	 *
	 * @return void
	 */
	public function test_find_returns_tag_from_database(): void {
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

		$row             = new \stdClass();
		$row->id         = 1;
		$row->name       = 'Irish Music';
		$row->slug       = 'irish-music';
		$row->created_at = '2026-01-01 12:00:00';
		$row->updated_at = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TagRepository( $wpdb );
			$result = $repo->find( 1 );

			$this->assertInstanceOf( Tag::class, $result );
			$this->assertEquals( 1, $result->id );
			$this->assertEquals( 'Irish Music', $result->name );
			$this->assertStringContainsString( 'nettertech_events_tags', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_slug() Tests
	// =========================================================================

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
			$repo   = new TagRepository( $wpdb );
			$result = $repo->find_by_slug( 'nonexistent' );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_tags', $captured_sql );
			$this->assertStringContainsString( 'slug', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_slug returns tag.
	 *
	 * @return void
	 */
	public function test_find_by_slug_returns_tag(): void {
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

		$row       = new \stdClass();
		$row->id   = 2;
		$row->name = 'Celtic Dance';
		$row->slug = 'celtic-dance';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TagRepository( $wpdb );
			$result = $repo->find_by_slug( 'celtic-dance' );

			$this->assertInstanceOf( Tag::class, $result );
			$this->assertEquals( 'celtic-dance', $result->slug );
			$this->assertStringContainsString( 'nettertech_events_tags', $captured_sql );
			$this->assertStringContainsString( 'slug', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// save() Tests
	// =========================================================================

	/**
	 * Test save inserts new tag.
	 *
	 * @return void
	 */
	public function test_save_inserts_new_tag(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert' ) )
			->getMock();

		$mock_wpdb->prefix    = 'wp_';
		$mock_wpdb->insert_id = 5;

		$mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->with( $this->stringContains( 'nettertech_events_tags' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$tag       = new Tag();
			$tag->name = 'New Tag';
			$tag->slug = 'new-tag';

			$repo   = new TagRepository( $wpdb );
			$result = $repo->save( $tag );

			$this->assertInstanceOf( Tag::class, $result );
			$this->assertEquals( 5, $result->id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save updates existing tag.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_tag(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_tags' ), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$tag       = new Tag();
			$tag->id   = 3;
			$tag->name = 'Updated Tag';
			$tag->slug = 'updated-tag';

			$repo   = new TagRepository( $wpdb );
			$result = $repo->save( $tag );

			$this->assertEquals( 3, $result->id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save throws exception on validation error.
	 *
	 * @return void
	 */
	public function test_save_throws_exception_on_validation_error(): void {
		global $wpdb;
		$this->expectException( \RuntimeException::class );

		$tag       = new Tag();
		$tag->name = ''; // Invalid - empty name.
		$tag->slug = 'test';

		$repo = new TagRepository( $wpdb );
		$repo->save( $tag );
	}

	/**
	 * Test save throws exception on insert failure.
	 *
	 * @return void
	 */
	public function test_save_throws_exception_on_insert_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert' ) )
			->getMock();

		$mock_wpdb->prefix     = 'wp_';
		$mock_wpdb->last_error = 'Database error';

		$mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->with( $this->stringContains( 'nettertech_events_tags' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$this->expectException( \RuntimeException::class );

			$tag       = new Tag();
			$tag->name = 'Test';
			$tag->slug = 'test';

			$repo = new TagRepository( $wpdb );
			$repo->save( $tag );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// delete() Tests
	// =========================================================================

	/**
	 * Test delete removes tag.
	 *
	 * @return void
	 */
	public function test_delete_removes_tag(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->exactly( 2 ) )
			->method( 'delete' )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TagRepository( $wpdb );
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

		$mock_wpdb->method( 'delete' )
			->willReturnOnConsecutiveCalls( 0, false );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TagRepository( $wpdb );
			$result = $repo->delete( 999 );

			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// get_all() Tests
	// =========================================================================

	/**
	 * Test get_all returns empty array when none.
	 *
	 * @return void
	 */
	public function test_get_all_returns_empty_array_when_none(): void {
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

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'get_results' )
			->with( $this->stringContains( 'nettertech_events_tags' ) )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TagRepository( $wpdb );
			$result = $repo->get_all();

			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_all returns tags.
	 *
	 * @return void
	 */
	public function test_get_all_returns_tags(): void {
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

		$row1       = new \stdClass();
		$row1->id   = 1;
		$row1->name = 'Tag A';
		$row1->slug = 'tag-a';

		$row2       = new \stdClass();
		$row2->id   = 2;
		$row2->name = 'Tag B';
		$row2->slug = 'tag-b';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'get_results' )
			->with( $this->stringContains( 'nettertech_events_tags' ) )
			->willReturn( array( $row1, $row2 ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TagRepository( $wpdb );
			$result = $repo->get_all();

			$this->assertCount( 2, $result );
			$this->assertInstanceOf( Tag::class, $result[0] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// get_all() Tests — Timeframe-limited (NTE-068)
	// =========================================================================

	/**
	 * Test get_all with 'upcoming' timeframe joins through occurrences with >= predicate.
	 *
	 * @return void
	 */
	public function test_get_all_with_upcoming_returns_only_tags_on_upcoming_events(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$row       = new \stdClass();
		$row->id   = 1;
		$row->name = 'Acoustic';
		$row->slug = 'acoustic';

		$captured_sql = '';
		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'get_results' )
			->with(
				$this->callback(
					function ( $sql ) use ( &$captured_sql ) {
						$captured_sql = $sql;
						return true;
					}
				)
			)
			->willReturn( array( $row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TagRepository( $wpdb );
			$result = $repo->get_all( array(), 'upcoming' );

			$this->assertCount( 1, $result );
			$this->assertEquals( 'Acoustic', $result[0]->name );
			$this->assertStringContainsString( 'DISTINCT t.*', $captured_sql, 'Query must select DISTINCT tag rows.' );
			$this->assertStringContainsString( 'nettertech_events_event_tags', $captured_sql, 'Query must join through event_tags junction.' );
			$this->assertStringContainsString( 'nettertech_events_events', $captured_sql, 'Query must join through events table.' );
			$this->assertStringContainsString( 'nettertech_events_occurrences', $captured_sql, 'Query must join through occurrences table.' );
			$this->assertStringContainsString( 'o.end_utc >=', $captured_sql, 'Upcoming predicate must use >= operator on end_datetime (NTE-128).' );
			$this->assertStringContainsString( "o.status = 'scheduled'", $captured_sql, 'Only scheduled occurrences should qualify.' );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_all with 'past' timeframe uses < predicate.
	 *
	 * @return void
	 */
	public function test_get_all_with_past_returns_only_tags_on_past_events(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$captured_sql = '';
		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'get_results' )
			->with(
				$this->callback(
					function ( $sql ) use ( &$captured_sql ) {
						$captured_sql = $sql;
						return true;
					}
				)
			)
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TagRepository( $wpdb );
			$repo->get_all( array(), 'past' );

			$this->assertStringContainsString( 'o.end_utc <', $captured_sql, 'Past predicate must use < operator on end_datetime (NTE-128).' );
			$this->assertStringNotContainsString( 'o.end_utc >=', $captured_sql, 'Past predicate must not use >= operator.' );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_all with null timeframe uses simple SELECT (back-compat).
	 *
	 * @return void
	 */
	public function test_get_all_with_no_timeframe_returns_all_tags_via_legacy_query(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$captured_sql = '';
		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'get_results' )
			->with(
				$this->callback(
					function ( $sql ) use ( &$captured_sql ) {
						$captured_sql = $sql;
						return true;
					}
				)
			)
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TagRepository( $wpdb );
			$repo->get_all( array(), null );

			$this->assertStringNotContainsString( 'nettertech_events_occurrences', $captured_sql, 'Unfiltered query must not join occurrences.' );
			$this->assertStringNotContainsString( 'DISTINCT t.*', $captured_sql, 'Unfiltered query must use simple SELECT.' );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_all with invalid timeframe falls back to unfiltered.
	 *
	 * @return void
	 */
	public function test_get_all_with_invalid_timeframe_falls_back_to_unfiltered(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$captured_sql = '';
		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'get_results' )
			->with(
				$this->callback(
					function ( $sql ) use ( &$captured_sql ) {
						$captured_sql = $sql;
						return true;
					}
				)
			)
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TagRepository( $wpdb );
			$repo->get_all( array(), 'never' ); // Bogus, must fall back.

			$this->assertStringNotContainsString( 'nettertech_events_occurrences', $captured_sql, 'Invalid timeframe must fall back to unfiltered query.' );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_event() Tests
	// =========================================================================

	/**
	 * Test find_by_event returns tags.
	 *
	 * @return void
	 */
	public function test_find_by_event_returns_tags(): void {
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

		$row       = new \stdClass();
		$row->id   = 1;
		$row->name = 'Music';
		$row->slug = 'music';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TagRepository( $wpdb );
			$result = $repo->find_by_event( 10 );

			$this->assertCount( 1, $result );
			$this->assertInstanceOf( Tag::class, $result[0] );
			$this->assertStringContainsString( 'nettertech_events_event_tags', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// search() Tests
	// =========================================================================

	/**
	 * Test search returns matching tags.
	 *
	 * @return void
	 */
	public function test_search_returns_matching_tags(): void {
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
			->willReturnCallback( function ( $text ) {
				return $text;
			} );

		$row       = new \stdClass();
		$row->id   = 1;
		$row->name = 'Irish Music';
		$row->slug = 'irish-music';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TagRepository( $wpdb );
			$result = $repo->search( 'irish' );

			$this->assertCount( 1, $result );
			$this->assertEquals( 'Irish Music', $result[0]->name );
			$this->assertStringContainsString( 'nettertech_events_tags', $captured_sql );
			$this->assertStringContainsString( 'LIKE', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// attach_to_event() Tests
	// =========================================================================

	/**
	 * Test attach_to_event returns true on success.
	 *
	 * @return void
	 */
	public function test_attach_to_event_returns_true(): void {
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
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TagRepository( $wpdb );
			$result = $repo->attach_to_event( 10, 1 );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_event_tags', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// detach_from_event() Tests
	// =========================================================================

	/**
	 * Test detach_from_event returns true on success.
	 *
	 * @return void
	 */
	public function test_detach_from_event_returns_true(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->once() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_event_tags' ), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TagRepository( $wpdb );
			$result = $repo->detach_from_event( 10, 1 );

			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// sync_event_tags() Tests
	// =========================================================================

	/**
	 * Test sync_event_tags syncs tags.
	 *
	 * @return void
	 */
	public function test_sync_event_tags_syncs_tags(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete', 'query', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'delete' )
			->willReturn( 1 );

		$mock_wpdb->method( 'query' )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TagRepository( $wpdb );
			$result = $repo->sync_event_tags( 10, array( 1, 2, 3 ) );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_event_tags', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_or_create() Tests
	// =========================================================================

	/**
	 * Test find_or_create returns existing tag.
	 *
	 * @return void
	 */
	public function test_find_or_create_returns_existing_tag(): void {
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

		$row       = new \stdClass();
		$row->id   = 1;
		$row->name = 'Existing Tag';
		$row->slug = 'existing-tag';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TagRepository( $wpdb );
			$result = $repo->find_or_create( 'Existing Tag' );

			$this->assertInstanceOf( Tag::class, $result );
			$this->assertEquals( 1, $result->id );
			$this->assertStringContainsString( 'nettertech_events_tags', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_or_create creates new tag.
	 *
	 * @return void
	 */
	public function test_find_or_create_creates_new_tag(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare', 'insert' ) )
			->getMock();

		$mock_wpdb->prefix    = 'wp_';
		$mock_wpdb->insert_id = 5;

		$captured_sql = '';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		// First call returns null (not found), triggering creation.
		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->with( $this->stringContains( 'nettertech_events_tags' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TagRepository( $wpdb );
			$result = $repo->find_or_create( 'Brand New Tag' );

			$this->assertInstanceOf( Tag::class, $result );
			$this->assertEquals( 5, $result->id );
			$this->assertEquals( 'Brand New Tag', $result->name );
			$this->assertStringContainsString( 'nettertech_events_tags', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}
}
