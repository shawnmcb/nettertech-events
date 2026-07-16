<?php
/**
 * OrganizerRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Repositories\OrganizerRepository;
use NetterTechEvents\Models\Organizer;

/**
 * Test OrganizerRepository functionality.
 *
 * Uses dynamic wpdb mocks to test query building and data handling
 * without requiring a live database connection.
 */
class OrganizerRepositoryTest extends \NetterTechEventsTestCase {

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
		$repo = new OrganizerRepository( $wpdb );

		$this->assertInstanceOf( OrganizerRepository::class, $repo );
	}

	/**
	 * Test repository stores wpdb reference.
	 *
	 * @return void
	 */
	public function test_repository_stores_wpdb_reference(): void {
		global $wpdb;
		$repo = new OrganizerRepository( $wpdb );

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
		$repo = new OrganizerRepository( $wpdb );

		$reflection = new \ReflectionClass( $repo );

		$table_prop          = $reflection->getProperty( 'table' );
		$junction_table_prop = $reflection->getProperty( 'junction_table' );

		$this->assertStringContainsString( 'organizers', $table_prop->getValue( $repo ) );
		$this->assertStringContainsString( 'event_organizers', $junction_table_prop->getValue( $repo ) );
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
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->find( 999 );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_organizers', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find returns organizer from database.
	 *
	 * @return void
	 */
	public function test_find_returns_organizer_from_database(): void {
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
		$row->name              = 'Celtic Junction Arts Center';
		$row->slug              = 'celtic-junction';
		$row->description       = 'Premier Irish arts center';
		$row->email             = 'info@celticjunction.org';
		$row->phone             = '612-555-1234';
		$row->website           = 'https://celticjunction.org';
		$row->featured_image_id = 100;
		$row->created_at        = '2026-01-01 12:00:00';
		$row->updated_at        = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->find( 1 );

			$this->assertInstanceOf( Organizer::class, $result );
			$this->assertEquals( 1, $result->id );
			$this->assertEquals( 'Celtic Junction Arts Center', $result->name );
			$this->assertEquals( 'info@celticjunction.org', $result->email );
			$this->assertStringContainsString( 'nettertech_events_organizers', $captured_sql );
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
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->find_by_slug( 'nonexistent-slug' );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_organizers', $captured_sql );
			$this->assertStringContainsString( 'slug', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_slug returns organizer from database.
	 *
	 * @return void
	 */
	public function test_find_by_slug_returns_organizer_from_database(): void {
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
		$row->name              = 'Irish Music Association';
		$row->slug              = 'irish-music-assoc';
		$row->description       = 'Promoting Irish music';
		$row->email             = null;
		$row->phone             = null;
		$row->website           = null;
		$row->featured_image_id = null;
		$row->created_at        = '2026-01-01 12:00:00';
		$row->updated_at        = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->find_by_slug( 'irish-music-assoc' );

			$this->assertInstanceOf( Organizer::class, $result );
			$this->assertEquals( 'irish-music-assoc', $result->slug );
			$this->assertEquals( 'Irish Music Association', $result->name );
			$this->assertStringContainsString( 'nettertech_events_organizers', $captured_sql );
			$this->assertStringContainsString( 'slug', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// save() Tests
	// =========================================================================

	/**
	 * Test save inserts new organizer.
	 *
	 * @return void
	 */
	public function test_save_inserts_new_organizer(): void {
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
			->with( $this->stringContains( 'nettertech_events_organizers' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$organizer       = new Organizer();
			$organizer->name = 'New Organizer';
			$organizer->slug = 'new-organizer';

			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->save( $organizer );

			$this->assertInstanceOf( Organizer::class, $result );
			$this->assertEquals( 5, $result->id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save updates existing organizer.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_organizer(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_organizers' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$organizer       = new Organizer();
			$organizer->id   = 3;
			$organizer->name = 'Updated Organizer';
			$organizer->slug = 'updated-organizer';

			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->save( $organizer );

			$this->assertInstanceOf( Organizer::class, $result );
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

		$organizer       = new Organizer();
		$organizer->name = ''; // Invalid - empty name.
		$organizer->slug = 'test';

		$repo = new OrganizerRepository( $wpdb );
		$repo->save( $organizer );
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
			->with( $this->stringContains( 'nettertech_events_organizers' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$this->expectException( \RuntimeException::class );

			$organizer       = new Organizer();
			$organizer->name = 'Test Organizer';
			$organizer->slug = 'test-organizer';

			$repo = new OrganizerRepository( $wpdb );
			$repo->save( $organizer );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save throws exception on update failure.
	 *
	 * @return void
	 */
	public function test_save_throws_exception_on_update_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix     = 'wp_';
		$mock_wpdb->last_error = 'Update failed';

		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_organizers' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$this->expectException( \RuntimeException::class );

			$organizer       = new Organizer();
			$organizer->id   = 1;
			$organizer->name = 'Test Organizer';
			$organizer->slug = 'test-organizer';

			$repo = new OrganizerRepository( $wpdb );
			$repo->save( $organizer );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// delete() Tests
	// =========================================================================

	/**
	 * Test delete removes organizer from events and main table.
	 *
	 * @return void
	 */
	public function test_delete_removes_organizer(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		// First delete from junction table, then from main table.
		$mock_wpdb->expects( $this->exactly( 2 ) )
			->method( 'delete' )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OrganizerRepository( $wpdb );
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
			$repo   = new OrganizerRepository( $wpdb );
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
			->with( $this->stringContains( 'nettertech_events_organizers' ) )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->get_all();

			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_all returns organizers.
	 *
	 * @return void
	 */
	public function test_get_all_returns_organizers(): void {
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
		$row1->name = 'Organizer A';
		$row1->slug = 'organizer-a';

		$row2       = new \stdClass();
		$row2->id   = 2;
		$row2->name = 'Organizer B';
		$row2->slug = 'organizer-b';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'get_results' )
			->with( $this->stringContains( 'nettertech_events_organizers' ) )
			->willReturn( array( $row1, $row2 ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->get_all();

			$this->assertCount( 2, $result );
			$this->assertInstanceOf( Organizer::class, $result[0] );
			$this->assertEquals( 'Organizer A', $result[0]->name );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_all respects ordering.
	 *
	 * @return void
	 */
	public function test_get_all_respects_ordering(): void {
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
			->with( $this->stringContains( 'nettertech_events_organizers' ) )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OrganizerRepository( $wpdb );
			$result = $repo->get_all( array( 'orderby' => 'created_at', 'order' => 'DESC' ) );

			$this->assertIsArray( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_all respects limit.
	 *
	 * @return void
	 */
	public function test_get_all_respects_limit(): void {
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
			->with( $this->stringContains( 'nettertech_events_organizers' ) )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new OrganizerRepository( $wpdb );
			$result = $repo->get_all( array( 'limit' => 5 ) );

			$this->assertIsArray( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_event() Tests
	// =========================================================================

	/**
	 * Test find_by_event returns organizers for event.
	 *
	 * @return void
	 */
	public function test_find_by_event_returns_organizers(): void {
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
		$row->name       = 'Celtic Junction';
		$row->slug       = 'celtic-junction';
		$row->is_primary = 1;
		$row->sort_order = 0;

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->find_by_event( 10 );

			$this->assertCount( 1, $result );
			$this->assertInstanceOf( Organizer::class, $result[0] );
			$this->assertStringContainsString( 'nettertech_events_event_organizers', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_event returns empty array when none.
	 *
	 * @return void
	 */
	public function test_find_by_event_returns_empty_array_when_none(): void {
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
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->find_by_event( 999 );

			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
			$this->assertStringContainsString( 'nettertech_events_event_organizers', $captured_sql );
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
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->attach_to_event( 10, 1, true, 0 );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_event_organizers', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test attach_to_event returns false on failure.
	 *
	 * @return void
	 */
	public function test_attach_to_event_returns_false_on_failure(): void {
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
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->attach_to_event( 10, 1 );

			$this->assertFalse( $result );
			$this->assertStringContainsString( 'nettertech_events_event_organizers', $captured_sql );
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
			->with( $this->stringContains( 'nettertech_events_event_organizers' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->detach_from_event( 10, 1 );

			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test detach_from_event returns false on failure.
	 *
	 * @return void
	 */
	public function test_detach_from_event_returns_false_on_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->once() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_event_organizers' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->detach_from_event( 10, 1 );

			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// sync_event_organizers() Tests
	// =========================================================================

	/**
	 * Test sync_event_organizers syncs organizers.
	 *
	 * @return void
	 */
	public function test_sync_event_organizers_syncs_organizers(): void {
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
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->sync_event_organizers( 10, array( 1, 2, 3 ) );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_event_organizers', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test sync_event_organizers handles array config.
	 *
	 * @return void
	 */
	public function test_sync_event_organizers_handles_array_config(): void {
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
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->sync_event_organizers(
				10,
				array(
					array( 'id' => 1, 'is_primary' => true, 'sort_order' => 0 ),
					array( 'id' => 2, 'is_primary' => false, 'sort_order' => 1 ),
				)
			);

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_event_organizers', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test sync_event_organizers with empty array clears all.
	 *
	 * @return void
	 */
	public function test_sync_event_organizers_with_empty_array_clears_all(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete', 'query', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		// Should only delete, no inserts.
		$mock_wpdb->expects( $this->once() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_event_organizers' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new OrganizerRepository( $wpdb );
			$result = $repo->sync_event_organizers( 10, array() );

			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}
}
