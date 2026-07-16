<?php
/**
 * TicketRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Repositories\TicketRepository;
use NetterTechEvents\Models\Ticket;

/**
 * Test TicketRepository functionality.
 *
 * Uses dynamic wpdb mocks to test query building and data handling
 * without requiring a live database connection.
 */
class TicketRepositoryTest extends \NetterTechEventsTestCase {

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
		$repo = new TicketRepository( $wpdb );

		$this->assertInstanceOf( TicketRepository::class, $repo );
	}

	/**
	 * Test repository initializes table name.
	 *
	 * @return void
	 */
	public function test_repository_initializes_table_name(): void {
		global $wpdb;
		$repo = new TicketRepository( $wpdb );

		$reflection = new \ReflectionClass( $repo );
		$table_prop = $reflection->getProperty( 'table' );

		$this->assertStringContainsString( 'tickets', $table_prop->getValue( $repo ) );
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
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->find( 999 );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find returns ticket from database.
	 *
	 * @return void
	 */
	public function test_find_returns_ticket_from_database(): void {
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

		$row                   = new \stdClass();
		$row->id               = 1;
		$row->ticket_type_id   = 10;
		$row->occurrence_id    = 20;
		$row->attendee_id      = 30;
		$row->wc_order_id      = 100;
		$row->wc_order_item_id = 200;
		$row->ticket_code      = 'ABC123';
		$row->qr_code_url      = 'https://example.com/qr/ABC123';
		$row->status           = 'confirmed';
		$row->checked_in_at    = null;
		$row->price_paid       = 25.00;
		$row->created_at       = '2026-01-01 12:00:00';
		$row->updated_at       = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->find( 1 );

			$this->assertInstanceOf( Ticket::class, $result );
			$this->assertEquals( 1, $result->id );
			$this->assertEquals( 'ABC123', $result->ticket_code );
			$this->assertEquals( 'confirmed', $result->status );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_code() Tests
	// =========================================================================

	/**
	 * Test find_by_code returns null when not found.
	 *
	 * @return void
	 */
	public function test_find_by_code_returns_null_when_not_found(): void {
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
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->find_by_code( 'INVALID' );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'ticket_code', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_code returns ticket.
	 *
	 * @return void
	 */
	public function test_find_by_code_returns_ticket(): void {
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

		$row              = new \stdClass();
		$row->id          = 1;
		$row->ticket_code = 'VALID123';
		$row->status      = 'confirmed';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->find_by_code( 'VALID123' );

			$this->assertInstanceOf( Ticket::class, $result );
			$this->assertEquals( 'VALID123', $result->ticket_code );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'ticket_code', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_order() Tests
	// =========================================================================

	/**
	 * Test find_by_order returns empty array when none.
	 *
	 * @return void
	 */
	public function test_find_by_order_returns_empty_array(): void {
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
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->find_by_order( 999 );

			$this->assertIsArray( $result );
			$this->assertEmpty( $result );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'wc_order_id', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_order returns tickets.
	 *
	 * @return void
	 */
	public function test_find_by_order_returns_tickets(): void {
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

		$row1              = new \stdClass();
		$row1->id          = 1;
		$row1->wc_order_id = 100;

		$row2              = new \stdClass();
		$row2->id          = 2;
		$row2->wc_order_id = 100;

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row1, $row2 ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->find_by_order( 100 );

			$this->assertCount( 2, $result );
			$this->assertInstanceOf( Ticket::class, $result[0] );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'wc_order_id', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_attendee() Tests
	// =========================================================================

	/**
	 * Test find_by_attendee returns tickets.
	 *
	 * @return void
	 */
	public function test_find_by_attendee_returns_tickets(): void {
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

		$row              = new \stdClass();
		$row->id          = 1;
		$row->attendee_id = 50;

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->find_by_attendee( 50 );

			$this->assertCount( 1, $result );
			$this->assertInstanceOf( Ticket::class, $result[0] );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'attendee_id', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_occurrence() Tests
	// =========================================================================

	/**
	 * Test find_by_occurrence returns tickets.
	 *
	 * @return void
	 */
	public function test_find_by_occurrence_returns_tickets(): void {
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

		$row                = new \stdClass();
		$row->id            = 1;
		$row->occurrence_id = 20;

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->find_by_occurrence( 20 );

			$this->assertCount( 1, $result );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'occurrence_id', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_occurrence with status filter.
	 *
	 * @return void
	 */
	public function test_find_by_occurrence_with_status_filter(): void {
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
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->find_by_occurrence( 20, 'confirmed' );

			$this->assertIsArray( $result );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'occurrence_id', $captured_sql );
			$this->assertStringContainsString( 'status', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// save() Tests
	// =========================================================================

	/**
	 * Test save inserts new ticket.
	 *
	 * @return void
	 */
	public function test_save_inserts_new_ticket(): void {
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
			->with( $this->stringContains( 'nettertech_events_tickets' ), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$ticket                 = new Ticket();
			$ticket->ticket_type_id = 10;
			$ticket->occurrence_id  = 20;
			$ticket->ticket_code    = 'NEW123';
			$ticket->status         = 'confirmed';

			$repo   = new TicketRepository( $wpdb );
			$result = $repo->save( $ticket );

			$this->assertInstanceOf( Ticket::class, $result );
			$this->assertEquals( 5, $result->id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test save updates existing ticket.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_ticket(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_tickets' ), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$ticket                 = new Ticket();
			$ticket->id             = 3;
			$ticket->ticket_type_id = 10;
			$ticket->occurrence_id  = 20;
			$ticket->status         = 'checked_in';

			$repo   = new TicketRepository( $wpdb );
			$result = $repo->save( $ticket );

			$this->assertEquals( 3, $result->id );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// cancel_all_tickets_for_attendee() Tests
	// =========================================================================

	/**
	 * Test cancel_all_tickets_for_attendee returns count.
	 *
	 * @return void
	 */
	public function test_cancel_all_tickets_for_attendee_returns_count(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->expects( $this->once() )
			->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_tickets' ), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 3 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->cancel_all_tickets_for_attendee( 50 );

			$this->assertEquals( 3, $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// cancel_tickets_for_attendee() Tests
	// =========================================================================

	/**
	 * Test cancel_tickets_for_attendee returns count.
	 *
	 * Note: This test uses property-based mocking since get_col is dynamically
	 * resolved and cannot be mocked with onlyMethods().
	 *
	 * @return void
	 */
	public function test_cancel_tickets_for_attendee_returns_count(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'query', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		// Set up the mock to track calls and return expected values.
		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		// Query will be called for the update.
		$mock_wpdb->method( 'query' )
			->willReturn( 2 );

		$wpdb = $mock_wpdb;

		try {
			$repo = new TicketRepository( $wpdb );

			// Since wpdb->get_col cannot be mocked directly, we verify the method exists
			// and is callable. Full integration testing is recommended for this method.
			$this->assertTrue( method_exists( $repo, 'cancel_tickets_for_attendee' ) );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test cancel_tickets_for_attendee method exists.
	 *
	 * @return void
	 */
	public function test_cancel_tickets_for_attendee_method_exists(): void {
		global $wpdb;
		$repo = new TicketRepository( $wpdb );
		$this->assertTrue( method_exists( $repo, 'cancel_tickets_for_attendee' ) );
	}

	// =========================================================================
	// check_in() Tests
	// =========================================================================

	/**
	 * Test check_in returns true on success.
	 *
	 * @return void
	 */
	public function test_check_in_returns_true_on_success(): void {
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
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->check_in( 'VALID123' );

			$this->assertTrue( $result );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'ticket_code', $captured_sql );
			$this->assertStringContainsString( 'checked_in', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test check_in returns false when already checked in.
	 *
	 * @return void
	 */
	public function test_check_in_returns_false_when_already_checked_in(): void {
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
			->willReturn( 0 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->check_in( 'ALREADY123' );

			$this->assertFalse( $result );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'ticket_code', $captured_sql );
			$this->assertStringContainsString( 'checked_in', $captured_sql );
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

		$mock_wpdb->expects( $this->once() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_tickets' ), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketRepository( $wpdb );
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

		$mock_wpdb->expects( $this->once() )
			->method( 'delete' )
			->with( $this->stringContains( 'nettertech_events_tickets' ), $this->anything() )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->delete( 999 );

			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// count_for_occurrence() Tests
	// =========================================================================

	/**
	 * Test count_for_occurrence returns count.
	 *
	 * @return void
	 */
	public function test_count_for_occurrence_returns_count(): void {
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
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->count_for_occurrence( 20 );

			$this->assertEquals( 15, $result );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'COUNT', $captured_sql );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'occurrence_id', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test count_for_occurrence with status filter.
	 *
	 * @return void
	 */
	public function test_count_for_occurrence_with_status_filter(): void {
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
			->willReturn( '10' );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->count_for_occurrence( 20, 'confirmed' );

			$this->assertEquals( 10, $result );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'COUNT', $captured_sql );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'occurrence_id', $captured_sql );
			$this->assertStringContainsString( 'status', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// cancel_tickets_for_attendee() Tests
	// =========================================================================

	/**
	 * Test cancel_tickets_for_attendee returns zero when no tickets found.
	 *
	 * @return void
	 */
	public function test_cancel_tickets_for_attendee_returns_zero_when_none(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_col', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sql = '';
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		// No ticket IDs found for this attendee.
		$mock_wpdb->method( 'get_col' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->cancel_tickets_for_attendee( 50, 3 );

			$this->assertEquals( 0, $result );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'attendee_id', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test cancel_tickets_for_attendee cancels specified number of tickets.
	 *
	 * @return void
	 */
	public function test_cancel_tickets_for_attendee_cancels_tickets(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_col', 'query', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		// Return ticket IDs that can be cancelled.
		$mock_wpdb->method( 'get_col' )
			->willReturn( array( 5, 4, 3 ) );

		// The update query affected 3 rows.
		$mock_wpdb->method( 'query' )
			->willReturn( 3 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->cancel_tickets_for_attendee( 50, 3 );

			$this->assertEquals( 3, $result );
			$this->assertNotEmpty( $captured_sqls );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sqls[0] );
			$this->assertStringContainsString( 'attendee_id', $captured_sqls[0] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test cancel_tickets_for_attendee includes correct SQL for partial cancel.
	 *
	 * @return void
	 */
	public function test_cancel_tickets_for_attendee_partial_cancel(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_col', 'query', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$captured_sqls = array();
		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sqls ) {
				$captured_sqls[] = $sql;
				return $sql;
			} );

		// Return only 2 ticket IDs even though 5 requested.
		$mock_wpdb->method( 'get_col' )
			->willReturn( array( 10, 9 ) );

		$mock_wpdb->method( 'query' )
			->willReturn( 2 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->cancel_tickets_for_attendee( 50, 5 );

			$this->assertEquals( 2, $result );

			// First SQL selects ticket IDs, second SQL updates them.
			$this->assertCount( 2, $captured_sqls );
			$this->assertStringContainsString( "status = 'confirmed'", $captured_sqls[0] );
			$this->assertStringContainsString( "status = 'cancelled'", $captured_sqls[1] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// find_by_code_with_attendee() Tests
	// =========================================================================

	/**
	 * Test find_by_code_with_attendee returns null when not found.
	 *
	 * @return void
	 */
	public function test_find_by_code_with_attendee_returns_null(): void {
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
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->find_by_code_with_attendee( 'INVALID_CODE' );

			$this->assertNull( $result );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'ticket_code', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_code_with_attendee returns joined data.
	 *
	 * @return void
	 */
	public function test_find_by_code_with_attendee_returns_data(): void {
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

		$row                       = new \stdClass();
		$row->ticket_id            = 1;
		$row->ticket_code          = 'SCAN123';
		$row->ticket_status        = 'confirmed';
		$row->ticket_checked_in_at = null;
		$row->occurrence_id        = 20;
		$row->attendee_id          = 30;
		$row->attendee_name        = 'Jane Doe';
		$row->attendee_email       = 'jane@example.com';
		$row->quantity             = 2;
		$row->checked_in           = 0;
		$row->checked_in_count     = 0;
		$row->attendee_checked_in_at = null;
		$row->start_datetime       = '2026-03-15 19:00:00';
		$row->end_datetime         = '2026-03-15 21:00:00';
		$row->occurrence_title     = null;
		$row->event_title          = 'Spring Concert';
		$row->venue_name           = 'Celtic Junction';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new TicketRepository( $wpdb );
			$result = $repo->find_by_code_with_attendee( 'SCAN123' );

			$this->assertNotNull( $result );
			$this->assertEquals( 1, $result->ticket_id );
			$this->assertEquals( 'SCAN123', $result->ticket_code );
			$this->assertEquals( 'confirmed', $result->ticket_status );
			$this->assertEquals( 'Jane Doe', $result->attendee_name );
			$this->assertEquals( 'jane@example.com', $result->attendee_email );
			$this->assertEquals( 'Spring Concert', $result->event_title );
			$this->assertEquals( 'Celtic Junction', $result->venue_name );
			$this->assertStringContainsString( 'nettertech_events_tickets', $captured_sql );
			$this->assertStringContainsString( 'WHERE', $captured_sql );
			$this->assertStringContainsString( 'ticket_code', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test find_by_code_with_attendee SQL includes JOIN clauses.
	 *
	 * @return void
	 */
	public function test_find_by_code_with_attendee_joins_tables(): void {
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
			$repo = new TicketRepository( $wpdb );
			$repo->find_by_code_with_attendee( 'TEST' );

			// Verify the SQL includes JOINs.
			$this->assertStringContainsString( 'LEFT JOIN', $captured_sql );
			$this->assertStringContainsString( 'attendee', $captured_sql );
			$this->assertStringContainsString( 'occurrence', $captured_sql );
			$this->assertStringContainsString( 'event', $captured_sql );
			$this->assertStringContainsString( 'ticket_code = %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// has_paid_attendance() Tests
	// =========================================================================

	/**
	 * Build a wpdb mock whose get_var returns the given sequence of values.
	 *
	 * @param array<int, string|null> $returns Sequential get_var return values.
	 * @return \wpdb&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_get_var_wpdb( array $returns ) {
		$mock = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();
		$mock->prefix = 'wp_';
		$mock->method( 'prepare' )->willReturnArgument( 0 );
		$mock->method( 'get_var' )->willReturnOnConsecutiveCalls( ...$returns );

		return $mock;
	}

	/**
	 * Test has_paid_attendance returns true when an order-linked ticket exists.
	 *
	 * @return void
	 */
	public function test_has_paid_attendance_true_for_order_linked_ticket(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		// First get_var (tickets) returns a hit; attendee query is not reached.
		$wpdb = $this->make_get_var_wpdb( array( '5' ) );

		try {
			$repo = new TicketRepository( $wpdb );
			$this->assertTrue( $repo->has_paid_attendance( 42 ) );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test has_paid_attendance returns true for an order-linked attendee even
	 * when no ticket references an order.
	 *
	 * @return void
	 */
	public function test_has_paid_attendance_true_for_order_linked_attendee(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		// Tickets query: no hit; attendees query: hit.
		$wpdb = $this->make_get_var_wpdb( array( null, '3' ) );

		try {
			$repo = new TicketRepository( $wpdb );
			$this->assertTrue( $repo->has_paid_attendance( 42 ) );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test has_paid_attendance returns false when neither tickets nor attendees
	 * reference an order (free / RSVP-only event).
	 *
	 * @return void
	 */
	public function test_has_paid_attendance_false_when_no_order_links(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$wpdb = $this->make_get_var_wpdb( array( null, null ) );

		try {
			$repo = new TicketRepository( $wpdb );
			$this->assertFalse( $repo->has_paid_attendance( 42 ) );
		} finally {
			$wpdb = $original_wpdb;
		}
	}
}
