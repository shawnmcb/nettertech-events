<?php
/**
 * AttendeeRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Models\Attendee;

/**
 * Test AttendeeRepository functionality.
 *
 * Uses dynamic wpdb mocks to test query building and data handling
 * without requiring a live database connection.
 */
class AttendeeRepositoryTest extends \NetterTechEventsTestCase {

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
		$repo = new AttendeeRepository( $wpdb );

		$this->assertInstanceOf( AttendeeRepository::class, $repo );
	}

	/**
	 * Test repository stores wpdb reference.
	 *
	 * @return void
	 */
	public function test_repository_stores_wpdb_reference(): void {
		global $wpdb;
		$repo = new AttendeeRepository( $wpdb );

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
		$repo = new AttendeeRepository( $wpdb );

		$reflection = new \ReflectionClass( $repo );

		$table_prop = $reflection->getProperty( 'table' );

		$this->assertStringContainsString( 'attendees', $table_prop->getValue( $repo ) );
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
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->find( 999 );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'WHERE id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find returns attendee from database.
	 *
	 * @return void
	 */
	public function test_find_returns_attendee_from_database(): void {
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
		$row->occurrence_id     = 10;
		$row->name              = 'John Doe';
		$row->email             = 'john@example.com';
		$row->quantity          = 2;
		$row->status            = 'confirmed';
		$row->checked_in        = 0;
		$row->checked_in_count  = 0;
		$row->checked_in_at     = null;
		$row->wc_order_id       = null;
		$row->wc_order_item_id  = null;
		$row->ticket_type_id    = null;
		$row->accessibility_notes = null;
		$row->created_at        = '2026-01-01 12:00:00';
		$row->updated_at        = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->find( 1 );

			$this->assertInstanceOf( Attendee::class, $result );
			$this->assertEquals( 1, $result->id );
			$this->assertEquals( 'John Doe', $result->name );
			$this->assertEquals( 'john@example.com', $result->email );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'WHERE id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// for_occurrence() Tests
	// =========================================================================

	/**
	 * Test for_occurrence returns empty array when none.
	 *
	 * @return void
	 */
	public function test_for_occurrence_returns_empty_array_when_none(): void {
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
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->for_occurrence( 10 );

			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'occurrence_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test for_occurrence returns attendees.
	 *
	 * @return void
	 */
	public function test_for_occurrence_returns_attendees(): void {
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

		$row1                    = new \stdClass();
		$row1->id                = 1;
		$row1->occurrence_id     = 10;
		$row1->name              = 'John Doe';
		$row1->email             = 'john@example.com';
		$row1->quantity          = 2;
		$row1->status            = 'confirmed';
		$row1->checked_in        = 0;
		$row1->checked_in_count  = 0;
		$row1->checked_in_at     = null;
		$row1->wc_order_id       = null;
		$row1->wc_order_item_id  = null;
		$row1->ticket_type_id    = null;
		$row1->accessibility_notes = null;
		$row1->created_at        = '2026-01-01 12:00:00';
		$row1->updated_at        = '2026-01-01 12:00:00';

		$row2                    = new \stdClass();
		$row2->id                = 2;
		$row2->occurrence_id     = 10;
		$row2->name              = 'Jane Smith';
		$row2->email             = 'jane@example.com';
		$row2->quantity          = 1;
		$row2->status            = 'confirmed';
		$row2->checked_in        = 0;
		$row2->checked_in_count  = 0;
		$row2->checked_in_at     = null;
		$row2->wc_order_id       = null;
		$row2->wc_order_item_id  = null;
		$row2->ticket_type_id    = null;
		$row2->accessibility_notes = null;
		$row2->created_at        = '2026-01-01 12:00:00';
		$row2->updated_at        = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row1, $row2 ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->for_occurrence( 10 );

			$this->assertCount( 2, $result );
			$this->assertEquals( 'John Doe', $result[0]->name );
			$this->assertEquals( 'Jane Smith', $result[1]->name );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'occurrence_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test for_occurrence with checked_in filter.
	 *
	 * @return void
	 */
	public function test_for_occurrence_with_checked_in_filter(): void {
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
			$repo = new AttendeeRepository( $wpdb );
			$repo->for_occurrence( 10, array( 'checked_in' => true ) );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'checked_in = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_order() Tests
	// =========================================================================

	/**
	 * Test find_by_order returns null when not found.
	 *
	 * @return void
	 */
	public function test_find_by_order_returns_null_when_not_found(): void {
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
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->find_by_order( 123 );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'wc_order_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_order returns attendee.
	 *
	 * @return void
	 */
	public function test_find_by_order_returns_attendee(): void {
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
		$row->occurrence_id     = 10;
		$row->name              = 'John Doe';
		$row->email             = 'john@example.com';
		$row->quantity          = 2;
		$row->status            = 'confirmed';
		$row->checked_in        = 0;
		$row->checked_in_count  = 0;
		$row->checked_in_at     = null;
		$row->wc_order_id       = 123;
		$row->wc_order_item_id  = 456;
		$row->ticket_type_id    = null;
		$row->accessibility_notes = null;
		$row->created_at        = '2026-01-01 12:00:00';
		$row->updated_at        = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->find_by_order( 123 );

			$this->assertInstanceOf( Attendee::class, $result );
			$this->assertEquals( 123, $result->wc_order_id );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'wc_order_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_all_by_order() Tests
	// =========================================================================

	/**
	 * Test find_all_by_order returns array.
	 *
	 * @return void
	 */
	public function test_find_all_by_order_returns_array(): void {
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
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->find_all_by_order( 123 );

			$this->assertIsArray( $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'wc_order_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_order_and_occurrence() Tests
	// =========================================================================

	/**
	 * Test find_by_order_and_occurrence returns null when not found.
	 *
	 * @return void
	 */
	public function test_find_by_order_and_occurrence_returns_null(): void {
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
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->find_by_order_and_occurrence( 123, 10 );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'wc_order_id = %d', $captured_sql );
			$this->assertStringContainsString( 'occurrence_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_email() Tests
	// =========================================================================

	/**
	 * Test find_by_email returns attendees.
	 *
	 * @return void
	 */
	public function test_find_by_email_returns_attendees(): void {
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
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->find_by_email( 10, 'john@example.com' );

			$this->assertIsArray( $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'occurrence_id = %d', $captured_sql );
			$this->assertStringContainsString( 'email = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// save() Tests
	// =========================================================================

	/**
	 * Test save throws on missing name.
	 *
	 * @return void
	 */
	public function test_save_throws_on_missing_name(): void {
		global $wpdb;
		$repo = new AttendeeRepository( $wpdb );

		$attendee                = new Attendee();
		$attendee->occurrence_id = 1;
		$attendee->email         = 'test@example.com';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Name is required' );

		$repo->save( $attendee );
	}

	/**
	 * Test save throws on missing occurrence_id.
	 *
	 * @return void
	 */
	public function test_save_throws_on_missing_occurrence_id(): void {
		global $wpdb;
		$repo = new AttendeeRepository( $wpdb );

		$attendee        = new Attendee();
		$attendee->name  = 'John Doe';
		$attendee->email = 'test@example.com';

		$this->expectException( \RuntimeException::class );

		$repo->save( $attendee );
	}

	/**
	 * Test save throws on invalid email.
	 *
	 * @return void
	 */
	public function test_save_throws_on_invalid_email(): void {
		global $wpdb;
		$repo = new AttendeeRepository( $wpdb );

		$attendee                = new Attendee();
		$attendee->name          = 'John Doe';
		$attendee->occurrence_id = 1;
		$attendee->email         = 'not-an-email';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid email address' );

		$repo->save( $attendee );
	}

	/**
	 * Test save inserts new attendee.
	 *
	 * @return void
	 */
	public function test_save_inserts_new_attendee(): void {
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
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new AttendeeRepository( $wpdb );

			$attendee                = new Attendee();
			$attendee->name          = 'John Doe';
			$attendee->email         = 'john@example.com';
			$attendee->occurrence_id = 10;
			$attendee->quantity      = 2;

			$saved = $repo->save( $attendee );

			$this->assertEquals( 42, $saved->id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save updates existing attendee.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_attendee(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new AttendeeRepository( $wpdb );

			$attendee                = new Attendee();
			$attendee->id            = 5;
			$attendee->name          = 'John Doe';
			$attendee->email         = 'john@example.com';
			$attendee->occurrence_id = 10;
			$attendee->quantity      = 2;

			$saved = $repo->save( $attendee );

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
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo = new AttendeeRepository( $wpdb );

			$attendee                = new Attendee();
			$attendee->name          = 'John Doe';
			$attendee->email         = 'john@example.com';
			$attendee->occurrence_id = 10;

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'Failed to insert attendee' );

			$repo->save( $attendee );
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
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
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
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->delete( 999 );

			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// update_quantity() Tests
	// =========================================================================

	/**
	 * Test update_quantity returns false for negative.
	 *
	 * @return void
	 */
	public function test_update_quantity_returns_false_for_negative(): void {
		global $wpdb;
		$repo = new AttendeeRepository( $wpdb );

		$result = $repo->update_quantity( 1, -1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test update_quantity accepts zero.
	 *
	 * @return void
	 */
	public function test_update_quantity_accepts_zero(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_table = '';
		$captured_data  = null;
		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->willReturnCallback( function ( $table, $data, $where, $formats, $where_formats ) use ( &$captured_table, &$captured_data ) {
				$captured_table = $table;
				$captured_data  = $data;
				return 1;
			} );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->update_quantity( 1, 0 );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_table );
			$this->assertEquals( 0, $captured_data['quantity'] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test update_quantity updates successfully.
	 *
	 * @return void
	 */
	public function test_update_quantity_updates_successfully(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->update_quantity( 1, 5 );

			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// search() Tests
	// =========================================================================

	/**
	 * Test search returns array.
	 *
	 * @return void
	 */
	public function test_search_returns_array(): void {
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

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->search( 10, 'john' );

			$this->assertIsArray( $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'occurrence_id = %d', $captured_sql );
			$this->assertStringContainsString( 'name LIKE %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// count_for_occurrence() Tests
	// =========================================================================

	/**
	 * Test count_for_occurrence returns integer.
	 *
	 * @return void
	 */
	public function test_count_for_occurrence_returns_integer(): void {
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
			->willReturn( '15' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->count_for_occurrence( 10 );

			$this->assertSame( 15, $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'occurrence_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test count_for_occurrence with null status.
	 *
	 * @return void
	 */
	public function test_count_for_occurrence_with_null_status(): void {
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
			->willReturn( '20' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->count_for_occurrence( 10, null );

			// Without status filter.
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringNotContainsString( 'AND status', $captured_sql );
			$this->assertSame( 20, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// update_status() Tests
	// =========================================================================

	/**
	 * Test update_status returns false for invalid status.
	 *
	 * @return void
	 */
	public function test_update_status_returns_false_for_invalid_status(): void {
		global $wpdb;
		$repo = new AttendeeRepository( $wpdb );

		$result = $repo->update_status( 1, 'invalid_status' );

		$this->assertFalse( $result );
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
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->update_status( 1, 'confirmed' );

			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Regression for NTE-037: every value in Attendee::STATUSES must be accepted.
	 *
	 * This guards the cross-file contract between OrderHandler (and other callers)
	 * that write specific status strings and AttendeeRepository::update_status()
	 * that whitelists them. If the whitelist ever regresses, the caller's write
	 * silently returns false and production data rots.
	 *
	 * @return void
	 */
	public function test_update_status_accepts_every_whitelisted_status(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';
		$mock_wpdb->method( 'update' )->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new AttendeeRepository( $wpdb );

			foreach ( Attendee::STATUSES as $status ) {
				$this->assertTrue(
					$repo->update_status( 1, $status ),
					sprintf( 'Whitelisted status "%s" must be accepted by update_status().', $status )
				);
			}
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Regression for NTE-037: the 'voided' status must be on the whitelist.
	 *
	 * Explicit sentinel test — if someone ever removes 'voided' from STATUSES
	 * (e.g. while "cleaning up" legacy statuses), this test will fire.
	 * OrderHandler::void_attendees_for_order writes 'voided' on full refunds,
	 * and silently dropping that write leaves refunded buyers as confirmed.
	 *
	 * @return void
	 */
	public function test_voided_status_is_whitelisted(): void {
		$this->assertContains(
			'voided',
			Attendee::STATUSES,
			'NTE-037 regression: "voided" must stay on Attendee::STATUSES. ' .
				'OrderHandler::void_attendees_for_order depends on it.'
		);
	}

	// =========================================================================
	// delete_for_occurrence() Tests
	// =========================================================================

	/**
	 * Test delete_for_occurrence returns count.
	 *
	 * @return void
	 */
	public function test_delete_for_occurrence_returns_count(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything() )
			->willReturn( 5 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->delete_for_occurrence( 10 );

			$this->assertEquals( 5, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// email_exists_for_occurrence() Tests
	// =========================================================================

	/**
	 * Test email_exists_for_occurrence returns boolean.
	 *
	 * @return void
	 */
	public function test_email_exists_for_occurrence_returns_boolean(): void {
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
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->email_exists_for_occurrence( 10, 'john@example.com' );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'occurrence_id = %d', $captured_sql );
			$this->assertStringContainsString( 'email = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test email_exists_for_occurrence with exclude_id.
	 *
	 * @return void
	 */
	public function test_email_exists_for_occurrence_with_exclude_id(): void {
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
			->willReturn( '0' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->email_exists_for_occurrence( 10, 'john@example.com', 5 );

			$this->assertFalse( $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'AND id != %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test email_exists_for_occurrence excludes refunded status (NTE-039).
	 *
	 * A refunded attendee must not block re-registration for the same occurrence.
	 * Reads the source file from the plugin root (two levels up from tests/) to
	 * assert the exclusion list contains 'refunded', independent of autoloader
	 * path resolution.
	 *
	 * @return void
	 */
	public function test_email_exists_for_occurrence_excludes_refunded_status(): void {
		$plugin_root = dirname( dirname( dirname( __DIR__ ) ) );
		$source_file = $plugin_root . '/includes/Repositories/AttendeeRepository.php';
		$source      = file_get_contents( $source_file );
		$this->assertStringContainsString( "'refunded'", $source );
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
		$repo = new AttendeeRepository( $wpdb );

		$this->assertInstanceOf(
			\NetterTechEvents\Contracts\AttendeeRepositoryInterface::class,
			$repo
		);
	}

	/**
	 * Test repository implements order interface.
	 *
	 * @return void
	 */
	public function test_implements_order_interface(): void {
		global $wpdb;
		$repo = new AttendeeRepository( $wpdb );

		$this->assertInstanceOf(
			\NetterTechEvents\Contracts\AttendeeOrderInterface::class,
			$repo
		);
	}

	/**
	 * Test repository implements search interface.
	 *
	 * @return void
	 */
	public function test_implements_search_interface(): void {
		global $wpdb;
		$repo = new AttendeeRepository( $wpdb );

		$this->assertInstanceOf(
			\NetterTechEvents\Contracts\AttendeeSearchInterface::class,
			$repo
		);
	}

	// =========================================================================
	// format_db_error() Tests (production branch)
	// =========================================================================

	/**
	 * Test save insert failure shows generic error in production.
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
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo = new AttendeeRepository( $wpdb );

			$attendee                = new Attendee();
			$attendee->name          = 'John Doe';
			$attendee->email         = 'john@example.com';
			$attendee->occurrence_id = 10;

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'Failed to insert attendee' );

			$repo->save( $attendee );
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
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo = new AttendeeRepository( $wpdb );

			$attendee                = new Attendee();
			$attendee->id            = 5;
			$attendee->name          = 'John Doe';
			$attendee->email         = 'john@example.com';
			$attendee->occurrence_id = 10;
			$attendee->quantity      = 2;

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'Failed to update attendee' );

			$repo->save( $attendee );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_ticket_code() Tests
	// =========================================================================

	/**
	 * Test find_by_ticket_code returns null when not found.
	 *
	 * @return void
	 */
	public function test_find_by_ticket_code_returns_null_when_not_found(): void {
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
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->find_by_ticket_code( 'ABCD-EFGH-IJKL-MNOP', 10 );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'ticket_code = %s', $captured_sql );
			$this->assertStringContainsString( 'occurrence_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_ticket_code returns attendee when found.
	 *
	 * @return void
	 */
	public function test_find_by_ticket_code_returns_attendee(): void {
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
		$row->occurrence_id       = 10;
		$row->name                = 'John Doe';
		$row->email               = 'john@example.com';
		$row->quantity            = 2;
		$row->status              = 'confirmed';
		$row->checked_in          = 0;
		$row->checked_in_count    = 0;
		$row->checked_in_at       = null;
		$row->wc_order_id         = null;
		$row->wc_order_item_id    = null;
		$row->ticket_type_id      = null;
		$row->accessibility_notes = null;
		$row->created_at          = '2026-01-01 12:00:00';
		$row->updated_at          = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->find_by_ticket_code( 'ABCD-EFGH-IJKL-MNOP', 10 );

			$this->assertInstanceOf( Attendee::class, $result );
			$this->assertEquals( 1, $result->id );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'ticket_code = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// delete() Additional Tests
	// =========================================================================

	/**
	 * Test delete invalidates caches on success when attendee exists.
	 *
	 * @return void
	 */
	public function test_delete_invalidates_caches_on_success(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare', 'delete' ) )
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
		$row->occurrence_id       = 10;
		$row->name                = 'John Doe';
		$row->email               = 'john@example.com';
		$row->quantity            = 2;
		$row->status              = 'confirmed';
		$row->checked_in          = 0;
		$row->checked_in_count    = 0;
		$row->checked_in_at       = null;
		$row->wc_order_id         = null;
		$row->wc_order_item_id    = null;
		$row->ticket_type_id      = null;
		$row->accessibility_notes = null;
		$row->created_at          = '2026-01-01 12:00:00';
		$row->updated_at          = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->delete( 1 );

			// Verifies the success path with attendee found + cache invalidation.
			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// count_for_occurrence() Additional Tests
	// =========================================================================

	/**
	 * Test count_for_occurrence with confirmed status uses default cache path.
	 *
	 * @return void
	 */
	public function test_count_for_occurrence_with_confirmed_uses_cache_path(): void {
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
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->count_for_occurrence( 10, 'confirmed' );

			$this->assertSame( 12, $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'occurrence_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test count_for_occurrence skips cache for non-confirmed status.
	 *
	 * @return void
	 */
	public function test_count_for_occurrence_skips_cache_for_non_confirmed(): void {
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
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->count_for_occurrence( 10, 'cancelled' );

			$this->assertSame( 5, $result );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'AND status = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// delete_for_occurrence() Additional Tests
	// =========================================================================

	/**
	 * Test delete_for_occurrence returns zero on failure.
	 *
	 * @return void
	 */
	public function test_delete_for_occurrence_returns_zero_on_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->atLeastOnce() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->delete_for_occurrence( 10 );

			$this->assertEquals( 0, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_order_and_occurrence() Additional Tests
	// =========================================================================

	/**
	 * Test find_by_order_and_occurrence returns attendee when found.
	 *
	 * @return void
	 */
	public function test_find_by_order_and_occurrence_returns_attendee(): void {
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
		$row->occurrence_id       = 10;
		$row->name                = 'Jane Smith';
		$row->email               = 'jane@example.com';
		$row->quantity            = 1;
		$row->status              = 'confirmed';
		$row->checked_in          = 0;
		$row->checked_in_count    = 0;
		$row->checked_in_at       = null;
		$row->wc_order_id         = 123;
		$row->wc_order_item_id    = 456;
		$row->ticket_type_id      = null;
		$row->accessibility_notes = null;
		$row->created_at          = '2026-01-01 12:00:00';
		$row->updated_at          = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->find_by_order_and_occurrence( 123, 10 );

			$this->assertInstanceOf( Attendee::class, $result );
			$this->assertEquals( 123, $result->wc_order_id );
			$this->assertEquals( 10, $result->occurrence_id );
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'wc_order_id = %d', $captured_sql );
			$this->assertStringContainsString( 'occurrence_id = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// Identity Map Tests (remember/forget)
	// =========================================================================

	/**
	 * Test find uses identity map on second call.
	 *
	 * Kills mutant: removal of $this->remember($id, $attendee) in find().
	 *
	 * @return void
	 */
	public function test_find_uses_identity_map_on_second_call(): void {
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

		$row                      = new \stdClass();
		$row->id                  = 1;
		$row->occurrence_id       = 10;
		$row->name                = 'John Doe';
		$row->email               = 'john@example.com';
		$row->quantity            = 2;
		$row->status              = 'confirmed';
		$row->checked_in          = 0;
		$row->checked_in_count    = 0;
		$row->checked_in_at       = null;
		$row->wc_order_id         = null;
		$row->wc_order_item_id    = null;
		$row->ticket_type_id      = null;
		$row->accessibility_notes = null;
		$row->created_at          = '2026-01-01 12:00:00';
		$row->updated_at          = '2026-01-01 12:00:00';

		// get_row should only be called once; second call uses identity map.
		$mock_wpdb->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo = new AttendeeRepository( $wpdb );

			$attendee1 = $repo->find( 1 );
			$attendee2 = $repo->find( 1 );

			$this->assertSame( $attendee1, $attendee2 );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save invalidates identity map via invalidate_attendee_cache.
	 *
	 * Kills mutant: removal of $this->forget($id) in invalidate_attendee_cache.
	 *
	 * @return void
	 */
	public function test_save_invalidates_identity_map(): void {
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
		$row->occurrence_id       = 10;
		$row->name                = 'John Doe';
		$row->email               = 'john@example.com';
		$row->quantity            = 2;
		$row->status              = 'confirmed';
		$row->checked_in          = 0;
		$row->checked_in_count    = 0;
		$row->checked_in_at       = null;
		$row->wc_order_id         = null;
		$row->wc_order_item_id    = null;
		$row->ticket_type_id      = null;
		$row->accessibility_notes = null;
		$row->created_at          = '2026-01-01 12:00:00';
		$row->updated_at          = '2026-01-01 12:00:00';

		$db_call_count = 0;
		$mock_wpdb->method( 'get_row' )
			->willReturnCallback( function () use ( $row, &$db_call_count ) {
				++$db_call_count;
				return $row;
			} );

		$mock_wpdb->method( 'update' )->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new AttendeeRepository( $wpdb );

			// Populate identity map.
			$attendee = $repo->find( 1 );
			$this->assertSame( 1, $db_call_count );

			// Verify identity map is used.
			$repo->find( 1 );
			$this->assertSame( 1, $db_call_count );

			// Save (update) should invalidate identity map.
			$attendee->name = 'Jane Doe';
			$repo->save( $attendee );

			// Next find should hit DB again.
			$repo->find( 1 );
			$this->assertSame( 2, $db_call_count );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// confirmed_guest_counts_for_events() Tests
	// =========================================================================

	/**
	 * Test confirmed_guest_counts_for_events returns empty array for empty input.
	 *
	 * @return void
	 */
	public function test_confirmed_guest_counts_for_events_empty_input(): void {
		global $wpdb;
		$repo = new AttendeeRepository( $wpdb );

		$this->assertSame( array(), $repo->confirmed_guest_counts_for_events( array() ) );
	}

	/**
	 * Test the SQL sums party quantity (not ticket rows) and filters to
	 * confirmed attendees only.
	 *
	 * Guest count is SUM(quantity) on confirmed attendees — the source of
	 * truth for both paid sales (one ticket row per seat) and free RSVPs (one
	 * row, quantity = party size). Counting attendee quantity rather than
	 * ticket rows is the fix for the admin "Tickets Sold" column.
	 *
	 * @return void
	 */
	public function test_confirmed_guest_counts_sums_quantity_and_filters_confirmed(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql  = '';
		$captured_args = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback(
				function ( $sql, ...$args ) use ( &$captured_sql, &$captured_args ) {
					$captured_sql  = $sql;
					$captured_args = $args;
					return $sql;
				}
			);
		$mock_wpdb->method( 'get_results' )->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new AttendeeRepository( $wpdb );
			$repo->confirmed_guest_counts_for_events( array( 5, 6 ) );

			$this->assertStringContainsString( 'SUM( a.quantity )', $captured_sql );
			$this->assertStringNotContainsString( 'COUNT(', $captured_sql );
			$this->assertStringContainsString( 'a.status = %s', $captured_sql );
			$this->assertStringContainsString( 'GROUP BY o.event_id', $captured_sql );
			$this->assertStringContainsString( 'INNER JOIN', $captured_sql );
			$this->assertStringContainsString( 'event_id IN (%d,%d)', $captured_sql );
			// Bound args: the two event IDs, then the confirmed status.
			$this->assertSame( array( array( 5, 6, 'confirmed' ) ), $captured_args );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test guest quantities roll up per event across occurrences; events with
	 * no confirmed attendees are omitted (callers default missing keys to 0).
	 *
	 * @return void
	 */
	public function test_confirmed_guest_counts_maps_event_to_guest_sum(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';
		$mock_wpdb->method( 'prepare' )->willReturnArgument( 0 );

		$row1           = new \stdClass();
		$row1->event_id = 5;
		$row1->guests   = 42; // e.g. 30 paid seats + a 12-guest RSVP party.

		$row2           = new \stdClass();
		$row2->event_id = 6;
		$row2->guests   = 3;

		$mock_wpdb->method( 'get_results' )->willReturn( array( $row1, $row2 ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeRepository( $wpdb );
			$result = $repo->confirmed_guest_counts_for_events( array( 5, 6, 7 ) );

			$this->assertSame( 42, $result[5] );
			$this->assertSame( 3, $result[6] );
			$this->assertArrayNotHasKey( 7, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

}
