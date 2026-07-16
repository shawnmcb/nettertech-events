<?php
/**
 * SpaceRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Space;
use NetterTechEvents\Repositories\SpaceRepository;

/**
 * Test SpaceRepository functionality.
 *
 * Uses dynamic wpdb mocks to test query building and data handling
 * without requiring a live database connection.
 */
class SpaceRepositoryTest extends \NetterTechEventsTestCase {

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
		$repo = new SpaceRepository( $wpdb );

		$this->assertInstanceOf( SpaceRepository::class, $repo );
	}

	/**
	 * Test repository stores wpdb reference.
	 *
	 * @return void
	 */
	public function test_repository_stores_wpdb_reference(): void {
		global $wpdb;
		$repo = new SpaceRepository( $wpdb );

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
		$repo = new SpaceRepository( $wpdb );

		$reflection = new \ReflectionClass( $repo );
		$table_prop = $reflection->getProperty( 'table' );

		$this->assertStringContainsString( 'nettertech_events_spaces', $table_prop->getValue( $repo ) );
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
			$repo   = new SpaceRepository( $mock_wpdb );
			$result = $repo->find( 999 );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_spaces', $captured_sql );
			$this->assertStringContainsString( 'WHERE id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find returns Space from database.
	 *
	 * @return void
	 */
	public function test_find_returns_space_from_database(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$row                         = new \stdClass();
		$row->id                     = 1;
		$row->name                   = 'Main Hall';
		$row->slug                   = 'main-hall';
		$row->tagline                = 'Main Hall — 400 Seats';
		$row->capacity               = 200;
		$row->square_footage         = 5000;
		$row->description            = 'A large hall';
		$row->featured_image_id      = 42;
		$row->sort_order             = 1;
		$row->status                 = 'active';
		$row->seating_model          = 'assigned';
		$row->accessibility_features = '[{"key":"wheelchair_seating"},{"key":"hearing_loop"}]';
		$row->amenities              = null;
		$row->gallery_image_ids      = null;
		$row->created_at             = '2026-01-01 12:00:00';
		$row->updated_at             = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new SpaceRepository( $mock_wpdb );
			$result = $repo->find( 1 );

			$this->assertInstanceOf( Space::class, $result );
			$this->assertEquals( 1, $result->id );
			$this->assertEquals( 'Main Hall', $result->name );
			$this->assertEquals( 'main-hall', $result->slug );
			$this->assertEquals( 'Main Hall — 400 Seats', $result->tagline );
			$this->assertEquals( 200, $result->capacity );
			$this->assertEquals( 'assigned', $result->seating_model );
			$features = $result->get_accessibility_features();
			$this->assertCount( 2, $features );
			$this->assertSame( 'wheelchair_seating', $features[0]['key'] );
			$this->assertSame( 'hearing_loop', $features[1]['key'] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find uses identity map on second call.
	 *
	 * @return void
	 */
	public function test_find_uses_identity_map(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$row       = new \stdClass();
		$row->id   = 1;
		$row->name = 'Main Hall';
		$row->slug = 'main-hall';

		// get_row should only be called once (second call hits identity map).
		$mock_wpdb->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo    = new SpaceRepository( $mock_wpdb );
			$result1 = $repo->find( 1 );
			$result2 = $repo->find( 1 );

			$this->assertSame( $result1, $result2 );
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
			$repo   = new SpaceRepository( $mock_wpdb );
			$result = $repo->find_by_slug( 'nonexistent' );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'WHERE slug = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_slug returns Space when found.
	 *
	 * @return void
	 */
	public function test_find_by_slug_returns_space(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$row       = new \stdClass();
		$row->id   = 5;
		$row->name = 'Studio B';
		$row->slug = 'studio-b';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new SpaceRepository( $mock_wpdb );
			$result = $repo->find_by_slug( 'studio-b' );

			$this->assertInstanceOf( Space::class, $result );
			$this->assertEquals( 5, $result->id );
			$this->assertEquals( 'Studio B', $result->name );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// save() Tests
	// =========================================================================

	/**
	 * Test save inserts new space.
	 *
	 * @return void
	 */
	public function test_save_inserts_new_space(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix    = 'wp_';
		$mock_wpdb->insert_id = 42;

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$mock_wpdb->expects( $this->once() )
			->method( 'insert' )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new SpaceRepository( $mock_wpdb );
			$id   = $repo->save( array(
				'name'     => 'New Space',
				'slug'     => 'new-space',
				'capacity' => 100,
				'status'   => 'active',
			) );

			$this->assertEquals( 42, $id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save updates existing space.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_space(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new SpaceRepository( $mock_wpdb );
			$id   = $repo->save( array(
				'id'       => 10,
				'name'     => 'Updated Space',
				'slug'     => 'updated-space',
				'capacity' => 150,
				'status'   => 'active',
			) );

			$this->assertEquals( 10, $id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save throws ValidationException for missing name.
	 *
	 * @return void
	 */
	public function test_save_throws_validation_exception_for_missing_name(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$wpdb = $mock_wpdb;

		try {
			$this->expectException( ValidationException::class );

			$repo = new SpaceRepository( $mock_wpdb );
			$repo->save( array(
				'name'   => '',
				'slug'   => 'no-name',
				'status' => 'active',
			) );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save throws ValidationException for missing slug.
	 *
	 * @return void
	 */
	public function test_save_throws_validation_exception_for_missing_slug(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$wpdb = $mock_wpdb;

		try {
			$this->expectException( ValidationException::class );

			$repo = new SpaceRepository( $mock_wpdb );
			$repo->save( array(
				'name'   => 'Has Name',
				'slug'   => '',
				'status' => 'active',
			) );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save throws DatabaseException on insert failure.
	 *
	 * @return void
	 */
	public function test_save_throws_database_exception_on_insert_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'insert', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix     = 'wp_';
		$mock_wpdb->last_error = 'Duplicate entry';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$mock_wpdb->method( 'insert' )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$this->expectException( DatabaseException::class );

			$repo = new SpaceRepository( $mock_wpdb );
			$repo->save( array(
				'name'   => 'Fail Space',
				'slug'   => 'fail-space',
				'status' => 'active',
			) );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save throws DatabaseException on update failure.
	 *
	 * @return void
	 */
	public function test_save_throws_database_exception_on_update_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix     = 'wp_';
		$mock_wpdb->last_error = 'Some error';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$mock_wpdb->method( 'update' )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$this->expectException( DatabaseException::class );

			$repo = new SpaceRepository( $mock_wpdb );
			$repo->save( array(
				'id'     => 10,
				'name'   => 'Update Fail',
				'slug'   => 'update-fail',
				'status' => 'active',
			) );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// delete() Tests
	// =========================================================================

	/**
	 * Test delete performs soft delete (sets status to archived).
	 *
	 * @return void
	 */
	public function test_delete_performs_soft_delete(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update', 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		// find() returns the space before deletion.
		$row       = new \stdClass();
		$row->id   = 5;
		$row->name = 'To Delete';
		$row->slug = 'to-delete';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$captured_data = array();
		$mock_wpdb->method( 'update' )
			->willReturnCallback( function ( $table, $data ) use ( &$captured_data ) {
				$captured_data = $data;
				return 1;
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new SpaceRepository( $mock_wpdb );
			$result = $repo->delete( 5 );

			$this->assertTrue( $result );
			$this->assertEquals( 'archived', $captured_data['status'] );
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
			->onlyMethods( array( 'update', 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$mock_wpdb->method( 'update' )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new SpaceRepository( $mock_wpdb );
			$result = $repo->delete( 999 );

			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// is_slug_unique() Tests
	// =========================================================================

	/**
	 * Test is_slug_unique returns true when slug is unique.
	 *
	 * @return void
	 */
	public function test_is_slug_unique_returns_true(): void {
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
			->willReturn( '0' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new SpaceRepository( $mock_wpdb );
			$result = $repo->is_slug_unique( 'unique-slug' );

			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test is_slug_unique returns false when slug exists.
	 *
	 * @return void
	 */
	public function test_is_slug_unique_returns_false_when_exists(): void {
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
			->willReturn( '1' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new SpaceRepository( $mock_wpdb );
			$result = $repo->is_slug_unique( 'existing-slug' );

			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test is_slug_unique with exclude_id.
	 *
	 * @return void
	 */
	public function test_is_slug_unique_with_exclude_id(): void {
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
			$repo   = new SpaceRepository( $mock_wpdb );
			$result = $repo->is_slug_unique( 'my-slug', 5 );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'id != %d', $captured_sql );
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

		$mock_wpdb->method( 'get_var' )
			->willReturn( '5' );

		$wpdb = $mock_wpdb;

		try {
			$repo  = new SpaceRepository( $mock_wpdb );
			$count = $repo->count();

			$this->assertEquals( 5, $count );
			$this->assertIsInt( $count );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// paginate() Tests
	// =========================================================================

	/**
	 * Test paginate returns empty array when no results.
	 *
	 * @return void
	 */
	public function test_paginate_returns_empty_array(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'esc_like' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new SpaceRepository( $mock_wpdb );
			$result = $repo->paginate();

			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test paginate returns array of Space objects.
	 *
	 * @return void
	 */
	public function test_paginate_returns_space_objects(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'esc_like' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) {
				return $sql;
			} );

		$row1       = new \stdClass();
		$row1->id   = 1;
		$row1->name = 'Space A';
		$row1->slug = 'space-a';

		$row2       = new \stdClass();
		$row2->id   = 2;
		$row2->name = 'Space B';
		$row2->slug = 'space-b';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row1, $row2 ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new SpaceRepository( $mock_wpdb );
			$result = $repo->paginate();

			$this->assertCount( 2, $result );
			$this->assertInstanceOf( Space::class, $result[0] );
			$this->assertInstanceOf( Space::class, $result[1] );
			$this->assertEquals( 'Space A', $result[0]->name );
			$this->assertEquals( 'Space B', $result[1]->name );
		} finally {
			$wpdb = $original_wpdb;
		}
	}
}
