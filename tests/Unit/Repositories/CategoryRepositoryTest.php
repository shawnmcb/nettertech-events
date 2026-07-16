<?php
/**
 * CategoryRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Repositories\CategoryRepository;
use NetterTechEvents\Models\Category;

/**
 * Test CategoryRepository functionality.
 *
 * Uses dynamic wpdb mocks to test query building and data handling
 * without requiring a live database connection.
 */
class CategoryRepositoryTest extends \NetterTechEventsTestCase {

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
		$repo = new CategoryRepository( $wpdb );

		$this->assertInstanceOf( CategoryRepository::class, $repo );
	}

	/**
	 * Test repository stores wpdb reference.
	 *
	 * @return void
	 */
	public function test_repository_stores_wpdb_reference(): void {
		global $wpdb;
		$repo = new CategoryRepository( $wpdb );

		$reflection = new \ReflectionClass( $repo );
		$db_prop    = $reflection->getProperty( 'db' );

		$this->assertSame( $wpdb, $db_prop->getValue( $repo ) );
	}

	/**
	 * Test repository initializes table names.
	 *
	 * @return void
	 */
	public function test_repository_initializes_table_names(): void {
		global $wpdb;
		$repo = new CategoryRepository( $wpdb );

		$reflection = new \ReflectionClass( $repo );

		$table_prop          = $reflection->getProperty( 'table' );
		$junction_table_prop = $reflection->getProperty( 'junction_table' );

		$this->assertStringContainsString( 'categories', $table_prop->getValue( $repo ) );
		$this->assertStringContainsString( 'event_categories', $junction_table_prop->getValue( $repo ) );
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
			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->find( 999 );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_categories', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find returns category from database.
	 *
	 * @return void
	 */
	public function test_find_returns_category_from_database(): void {
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

		$row                    = new \stdClass();
		$row->id                = 1;
		$row->name              = 'Music Events';
		$row->slug              = 'music-events';
		$row->description       = 'All music related events';
		$row->parent_id         = null;
		$row->featured_image_id = 100;
		$row->sort_order        = 1;
		$row->created_at        = '2026-01-01 12:00:00';
		$row->updated_at        = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->find( 1 );

			$this->assertInstanceOf( Category::class, $result );
			$this->assertEquals( 1, $result->id );
			$this->assertEquals( 'Music Events', $result->name );
			$this->assertEquals( 'music-events', $result->slug );
			$this->assertStringContainsString( 'nettertech_events_categories', $captured_sql );
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
			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->find_by_slug( 'nonexistent-slug' );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_categories', $captured_sql );
			$this->assertStringContainsString( 'slug', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_slug returns category from database.
	 *
	 * @return void
	 */
	public function test_find_by_slug_returns_category_from_database(): void {
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

		$row                    = new \stdClass();
		$row->id                = 2;
		$row->name              = 'Dance';
		$row->slug              = 'dance';
		$row->description       = 'Dance events';
		$row->parent_id         = null;
		$row->featured_image_id = null;
		$row->sort_order        = 0;
		$row->created_at        = '2026-01-01 12:00:00';
		$row->updated_at        = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->find_by_slug( 'dance' );

			$this->assertInstanceOf( Category::class, $result );
			$this->assertEquals( 'dance', $result->slug );
			$this->assertEquals( 'Dance', $result->name );
			$this->assertStringContainsString( 'nettertech_events_categories', $captured_sql );
			$this->assertStringContainsString( 'slug', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// save() Tests
	// =========================================================================

	/**
	 * Test save inserts new category.
	 *
	 * @return void
	 */
	public function test_save_inserts_new_category(): void {
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
			->with( $this->stringContains( 'nettertech_events_categories' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$category       = new Category();
			$category->name = 'New Category';
			$category->slug = 'new-category';

			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->save( $category );

			$this->assertInstanceOf( Category::class, $result );
			$this->assertEquals( 5, $result->id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save updates existing category.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_category(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_categories' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$category       = new Category();
			$category->id   = 3;
			$category->name = 'Updated Category';
			$category->slug = 'updated-category';

			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->save( $category );

			$this->assertInstanceOf( Category::class, $result );
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

		$category       = new Category();
		$category->name = ''; // Invalid - empty name.
		$category->slug = 'test';

		$repo = new CategoryRepository( $wpdb );
		$repo->save( $category );
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
			->with( $this->stringContains( 'nettertech_events_categories' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$this->expectException( \RuntimeException::class );

			$category       = new Category();
			$category->name = 'Test Category';
			$category->slug = 'test-category';

			$repo = new CategoryRepository( $wpdb );
			$repo->save( $category );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// delete() Tests
	// =========================================================================

	/**
	 * Test delete removes category and updates children.
	 *
	 * @return void
	 */
	public function test_delete_removes_category(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete', 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		// Update children to have no parent.
		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'update' )
			->willReturn( 1 );

		// Delete from junction table and main table.
		$mock_wpdb->expects( $this->exactly( 2 ) )
			->method( 'delete' )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new CategoryRepository( $wpdb );
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
			->onlyMethods( array( 'delete', 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'update' )
			->willReturn( 0 );

		$mock_wpdb->method( 'delete' )
			->willReturnOnConsecutiveCalls( 0, false );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new CategoryRepository( $wpdb );
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
			->with( $this->stringContains( 'nettertech_events_categories' ) )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->get_all();

			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_all returns categories.
	 *
	 * @return void
	 */
	public function test_get_all_returns_categories(): void {
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

		$row1             = new \stdClass();
		$row1->id         = 1;
		$row1->name       = 'Category 1';
		$row1->slug       = 'category-1';
		$row1->sort_order = 0;

		$row2             = new \stdClass();
		$row2->id         = 2;
		$row2->name       = 'Category 2';
		$row2->slug       = 'category-2';
		$row2->sort_order = 1;

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'get_results' )
			->with( $this->stringContains( 'nettertech_events_categories' ) )
			->willReturn( array( $row1, $row2 ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->get_all();

			$this->assertCount( 2, $result );
			$this->assertInstanceOf( Category::class, $result[0] );
			$this->assertEquals( 'Category 1', $result[0]->name );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_all respects top-level filter.
	 *
	 * @return void
	 */
	public function test_get_all_respects_top_level_filter(): void {
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
			->with( $this->stringContains( 'nettertech_events_categories' ) )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new CategoryRepository( $wpdb );
			// parent_id = 0 should filter to top-level only.
			$result = $repo->get_all( array( 'parent_id' => 0 ) );

			$this->assertIsArray( $result );
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
	public function test_get_all_with_upcoming_returns_only_terms_on_upcoming_events(): void {
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

		$row             = new \stdClass();
		$row->id         = 1;
		$row->name       = 'Workshops';
		$row->slug       = 'workshops';
		$row->sort_order = 0;

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
			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->get_all( array(), 'upcoming' );

			$this->assertCount( 1, $result );
			$this->assertEquals( 'Workshops', $result[0]->name );
			$this->assertStringContainsString( 'DISTINCT c.*', $captured_sql, 'Query must select DISTINCT category rows.' );
			$this->assertStringContainsString( 'nettertech_events_event_categories', $captured_sql, 'Query must join through event_categories junction.' );
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
	public function test_get_all_with_past_returns_only_terms_on_past_events(): void {
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
			$repo = new CategoryRepository( $wpdb );
			$repo->get_all( array(), 'past' );

			$this->assertStringContainsString( 'o.end_utc <', $captured_sql, 'Past predicate must use < operator on end_datetime (NTE-128).' );
			$this->assertStringNotContainsString( 'o.end_utc >=', $captured_sql, 'Past predicate must not use >= operator.' );
			$this->assertStringContainsString( "o.status = 'scheduled'", $captured_sql, 'Only scheduled occurrences should qualify.' );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_all with null timeframe uses the simple, unfiltered query (back-compat).
	 *
	 * @return void
	 */
	public function test_get_all_with_no_timeframe_returns_all_terms_via_legacy_query(): void {
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
			$repo = new CategoryRepository( $wpdb );
			$repo->get_all( array(), null );

			$this->assertStringNotContainsString( 'nettertech_events_occurrences', $captured_sql, 'Unfiltered query must not join occurrences.' );
			$this->assertStringNotContainsString( 'DISTINCT c.*', $captured_sql, 'Unfiltered query must use simple SELECT.' );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_all with an invalid timeframe string falls back to unfiltered.
	 *
	 * Defensive against caller mistakes: a typo should not silently produce
	 * a zero-row result.
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
			$repo = new CategoryRepository( $wpdb );
			$repo->get_all( array(), 'futur' ); // Misspelled, should fall back.

			$this->assertStringNotContainsString( 'nettertech_events_occurrences', $captured_sql, 'Invalid timeframe must fall back to unfiltered query.' );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_event() Tests
	// =========================================================================

	/**
	 * Test find_by_event returns categories for event.
	 *
	 * @return void
	 */
	public function test_find_by_event_returns_categories(): void {
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

		$row             = new \stdClass();
		$row->id         = 1;
		$row->name       = 'Music';
		$row->slug       = 'music';
		$row->is_primary = 1;

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->find_by_event( 10 );

			$this->assertCount( 1, $result );
			$this->assertInstanceOf( Category::class, $result[0] );
			$this->assertStringContainsString( 'nettertech_events_event_categories', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_children() Tests
	// =========================================================================

	/**
	 * Test find_children returns child categories.
	 *
	 * @return void
	 */
	public function test_find_children_returns_child_categories(): void {
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

		$child           = new \stdClass();
		$child->id       = 2;
		$child->name     = 'Irish Music';
		$child->slug     = 'irish-music';
		$child->parent_id = 1;

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $child ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->find_children( 1 );

			$this->assertCount( 1, $result );
			$this->assertEquals( 'Irish Music', $result[0]->name );
			$this->assertStringContainsString( 'nettertech_events_categories', $captured_sql );
			$this->assertStringContainsString( 'parent_id', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_children returns top-level when null.
	 *
	 * @return void
	 */
	public function test_find_children_returns_top_level_when_null(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->find_children( null );

			$this->assertIsArray( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// get_tree() Tests
	// =========================================================================

	/**
	 * Test get_tree returns hierarchical structure.
	 *
	 * @return void
	 */
	public function test_get_tree_returns_hierarchical_structure(): void {
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

		$parent             = new \stdClass();
		$parent->id         = 1;
		$parent->name       = 'Music';
		$parent->slug       = 'music';
		$parent->parent_id  = null;
		$parent->sort_order = 0;

		$child              = new \stdClass();
		$child->id          = 2;
		$child->name        = 'Irish Music';
		$child->slug        = 'irish-music';
		$child->parent_id   = 1;
		$child->sort_order  = 0;

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'get_results' )
			->with( $this->stringContains( 'nettertech_events_categories' ) )
			->willReturn( array( $parent, $child ) );

		$wpdb = $mock_wpdb;

		try {
			$repo = new CategoryRepository( $wpdb );
			$tree = $repo->get_tree();

			$this->assertIsArray( $tree );
			$this->assertCount( 1, $tree ); // One top-level.
			$this->assertArrayHasKey( 'category', $tree[0] );
			$this->assertArrayHasKey( 'children', $tree[0] );
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
	public function test_attach_to_event_returns_true_on_success(): void {
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
			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->attach_to_event( 10, 1, true );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_event_categories', $captured_sql );
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
	public function test_detach_from_event_returns_true_on_success(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->once() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_event_categories' ), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->detach_from_event( 10, 1 );

			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// sync_event_categories() Tests
	// =========================================================================

	/**
	 * Test sync_event_categories syncs categories.
	 *
	 * @return void
	 */
	public function test_sync_event_categories_syncs_categories(): void {
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
			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->sync_event_categories( 10, array( 1, 2, 3 ) );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_event_categories', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test sync_event_categories handles array config.
	 *
	 * @return void
	 */
	public function test_sync_event_categories_handles_array_config(): void {
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
			$repo   = new CategoryRepository( $wpdb );
			$result = $repo->sync_event_categories(
				10,
				array(
					array( 'id' => 1, 'is_primary' => true ),
					array( 'id' => 2, 'is_primary' => false ),
				)
			);

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_event_categories', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}
}
