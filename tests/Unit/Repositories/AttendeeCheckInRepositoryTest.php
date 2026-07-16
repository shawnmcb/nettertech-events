<?php
/**
 * AttendeeCheckInRepository unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Repositories;

use NetterTechEvents\Repositories\AttendeeCheckInRepository;
use NetterTechEvents\Models\Attendee;

/**
 * Test AttendeeCheckInRepository functionality.
 *
 * Uses dynamic wpdb mocks to test query building and data handling
 * without requiring a live database connection.
 */
class AttendeeCheckInRepositoryTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// mark_checked_in() Tests
	// =========================================================================

	/**
	 * Test mark_checked_in returns false when not found.
	 *
	 * @return void
	 */
	public function test_mark_checked_in_returns_false_when_not_found(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->mark_checked_in( 999 );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test mark_checked_in succeeds when found.
	 *
	 * @return void
	 */
	public function test_mark_checked_in_succeeds(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare', 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

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

		$mock_wpdb->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->mark_checked_in( 1 );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// mark_not_checked_in() Tests
	// =========================================================================

	/**
	 * Test mark_not_checked_in succeeds.
	 *
	 * @return void
	 */
	public function test_mark_not_checked_in_succeeds(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->mark_not_checked_in( 1 );

			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// increment_checked_in() Tests
	// =========================================================================

	/**
	 * Test increment_checked_in returns failure when not found.
	 *
	 * @return void
	 */
	public function test_increment_checked_in_returns_failure_when_not_found(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare', 'query' ) )
			->getMock();

		$mock_wpdb->prefix        = 'wp_';
		$mock_wpdb->rows_affected = 0;

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'query' )
			->willReturn( 0 );

		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->increment_checked_in( 999 );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertFalse( $result['success'] );
			$this->assertEquals( 0, $result['checked_in_count'] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test increment_checked_in returns success.
	 *
	 * @return void
	 */
	public function test_increment_checked_in_returns_success(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare', 'query' ) )
			->getMock();

		$mock_wpdb->prefix        = 'wp_';
		$mock_wpdb->rows_affected = 1;

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'query' )
			->willReturnCallback( function () use ( $mock_wpdb ) {
				$mock_wpdb->rows_affected = 1;
				return 1;
			} );

		// After atomic UPDATE, find() reads back the updated row.
		$row                      = new \stdClass();
		$row->id                  = 1;
		$row->occurrence_id       = 10;
		$row->name                = 'John Doe';
		$row->email               = 'john@example.com';
		$row->quantity            = 2;
		$row->status              = 'confirmed';
		$row->checked_in          = 0;
		$row->checked_in_count    = 1;
		$row->checked_in_at       = '2026-01-01 12:00:00';
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
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->increment_checked_in( 1 );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertTrue( $result['success'] );
			$this->assertEquals( 1, $result['checked_in_count'] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// decrement_checked_in() Tests
	// =========================================================================

	/**
	 * Test decrement_checked_in returns failure when not found.
	 *
	 * @return void
	 */
	public function test_decrement_checked_in_returns_failure_when_not_found(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->decrement_checked_in( 999 );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertFalse( $result['success'] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test decrement_checked_in succeeds when attendee found.
	 *
	 * @return void
	 */
	public function test_decrement_checked_in_succeeds(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare', 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

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
		$row->quantity            = 3;
		$row->status              = 'confirmed';
		$row->checked_in          = 0;
		$row->checked_in_count    = 2;
		$row->checked_in_at       = '2026-01-15 10:00:00';
		$row->wc_order_id         = null;
		$row->wc_order_item_id    = null;
		$row->ticket_type_id      = null;
		$row->accessibility_notes = null;
		$row->created_at          = '2026-01-01 12:00:00';
		$row->updated_at          = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$mock_wpdb->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->decrement_checked_in( 1 );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertTrue( $result['success'] );
			$this->assertEquals( 1, $result['checked_in_count'] );
			$this->assertFalse( $result['checked_in'] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// set_checked_in_count() Tests
	// =========================================================================

	/**
	 * Test set_checked_in_count returns failure when not found.
	 *
	 * @return void
	 */
	public function test_set_checked_in_count_returns_failure_when_not_found(): void {
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

		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->set_checked_in_count( 999, 5 );

			$this->assertFalse( $result['success'] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test set_checked_in_count succeeds when attendee found.
	 *
	 * @return void
	 */
	public function test_set_checked_in_count_succeeds(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare', 'update' ) )
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
		$row->quantity            = 3;
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

		$mock_wpdb->method( 'update' )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->set_checked_in_count( 1, 3 );

			$this->assertTrue( $result['success'] );
			$this->assertEquals( 3, $result['checked_in_count'] );
			$this->assertTrue( $result['checked_in'] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test set_checked_in_count with partial count.
	 *
	 * @return void
	 */
	public function test_set_checked_in_count_partial(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare', 'update' ) )
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
		$row->quantity            = 5;
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

		$mock_wpdb->method( 'update' )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->set_checked_in_count( 1, 2 );

			$this->assertTrue( $result['success'] );
			$this->assertEquals( 2, $result['checked_in_count'] );
			$this->assertFalse( $result['checked_in'] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// toggle_checked_in() Tests
	// =========================================================================

	/**
	 * Test toggle_checked_in returns false when not found.
	 *
	 * @return void
	 */
	public function test_toggle_checked_in_returns_false_when_not_found(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$mock_wpdb->method( 'get_row' )
			->willReturn( null );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->toggle_checked_in( 999 );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test toggle_checked_in checks in when not checked in.
	 *
	 * @return void
	 */
	public function test_toggle_checked_in_checks_in(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare', 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

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

		$mock_wpdb->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->toggle_checked_in( 1 );

			// Was not checked in, now should be checked in.
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test toggle_checked_in checks out when already checked in.
	 *
	 * @return void
	 */
	public function test_toggle_checked_in_checks_out(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare', 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

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
		$row->checked_in          = 1;
		$row->checked_in_count    = 2;
		$row->checked_in_at       = '2026-01-15 10:00:00';
		$row->wc_order_id         = null;
		$row->wc_order_item_id    = null;
		$row->ticket_type_id      = null;
		$row->accessibility_notes = null;
		$row->created_at          = '2026-01-01 12:00:00';
		$row->updated_at          = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_row' )
			->willReturn( $row );

		$mock_wpdb->method( 'update' )
			->with( $this->stringContains( 'nettertech_events_attendees' ), $this->anything(), $this->anything(), $this->anything() )
			->willReturn( 1 );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->toggle_checked_in( 1 );

			// Was checked in, now should not be checked in.
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// get_check_in_list() Tests
	// =========================================================================

	/**
	 * Test get_check_in_list returns array.
	 *
	 * @return void
	 */
	public function test_get_check_in_list_returns_array(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'esc_like' ) )
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

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->get_check_in_list( 10 );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertIsArray( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_check_in_list with search parameter.
	 *
	 * @return void
	 */
	public function test_get_check_in_list_with_search(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'esc_like' ) )
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

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new AttendeeCheckInRepository( $wpdb );
			$repo->get_check_in_list( 10, array( 'search' => 'john' ) );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'a.name LIKE %s', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_check_in_list returns formatted attendee data.
	 *
	 * @return void
	 */
	public function test_get_check_in_list_returns_formatted_data(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		// Stub get_option to return time format for get_formatted_check_in_time().
		\Brain\Monkey\Functions\stubs(
			array(
				'get_option' => function ( $option, $default = false ) {
					if ( 'time_format' === $option ) {
						return 'g:i a';
					}
					return $default;
				},
			)
		);

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'esc_like' ) )
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

		$row                      = new \stdClass();
		$row->id                  = 1;
		$row->occurrence_id       = 10;
		$row->name                = 'John Doe';
		$row->email               = 'john@example.com';
		$row->quantity            = 2;
		$row->status              = 'confirmed';
		$row->checked_in          = 1;
		$row->checked_in_count    = 2;
		$row->checked_in_at       = '2026-01-15 10:00:00';
		$row->wc_order_id         = null;
		$row->wc_order_item_id    = null;
		$row->ticket_type_id      = 5;
		$row->accessibility_notes = 'Wheelchair access';
		$row->ticket_type_name    = 'VIP Ticket';
		$row->created_at          = '2026-01-01 12:00:00';
		$row->updated_at          = '2026-01-01 12:00:00';

		$mock_wpdb->method( 'get_results' )
			->willReturn( array( $row ) );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->get_check_in_list( 10 );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertCount( 1, $result );
			$this->assertEquals( 1, $result[0]['id'] );
			$this->assertEquals( 'John Doe', $result[0]['name'] );
			$this->assertEquals( 'john@example.com', $result[0]['email'] );
			$this->assertEquals( 2, $result[0]['quantity'] );
			$this->assertTrue( $result[0]['checked_in'] );
			$this->assertEquals( 2, $result[0]['checked_in_count'] );
			$this->assertEquals( 'VIP Ticket', $result[0]['ticket_type'] );
			$this->assertEquals( 'Wheelchair access', $result[0]['accessibility_notes'] );
			$this->assertArrayHasKey( 'line', $result[0] );
			$this->assertArrayHasKey( 'line_with_status', $result[0] );
			$this->assertArrayHasKey( 'checked_in_at', $result[0] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_check_in_list with checked_in filter.
	 *
	 * @return void
	 */
	public function test_get_check_in_list_with_checked_in_filter(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'esc_like' ) )
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

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new AttendeeCheckInRepository( $wpdb );
			$repo->get_check_in_list( 10, array( 'checked_in' => true ) );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'a.checked_in = %d', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_check_in_list with limit parameter.
	 *
	 * @return void
	 */
	public function test_get_check_in_list_with_limit(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'esc_like' ) )
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

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new AttendeeCheckInRepository( $wpdb );
			$repo->get_check_in_list( 10, array( 'limit' => 50 ) );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'LIMIT 50', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_check_in_list with non-last_name orderby.
	 *
	 * @return void
	 */
	public function test_get_check_in_list_with_name_orderby(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'esc_like' ) )
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

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new AttendeeCheckInRepository( $wpdb );
			$repo->get_check_in_list( 10, array( 'orderby' => 'name' ) );

			// Should use sanitize_sql_orderby path, not SUBSTRING_INDEX.
			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringNotContainsString( 'SUBSTRING_INDEX', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_check_in_list with DESC order on last_name.
	 *
	 * @return void
	 */
	public function test_get_check_in_list_with_desc_order(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_results', 'prepare', 'esc_like' ) )
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

		$mock_wpdb->method( 'get_results' )
			->willReturn( array() );

		$wpdb = $mock_wpdb;

		try {
			$repo = new AttendeeCheckInRepository( $wpdb );
			$repo->get_check_in_list(
				10,
				array(
					'orderby' => 'last_name',
					'order'   => 'DESC',
				)
			);

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertStringContainsString( 'SUBSTRING_INDEX', $captured_sql );
			$this->assertStringContainsString( 'DESC', $captured_sql );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// get_check_in_stats() Tests
	// =========================================================================

	/**
	 * Test get_check_in_stats returns expected structure.
	 *
	 * @return void
	 */
	public function test_get_check_in_stats_returns_expected_structure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		$stats_row                          = new \stdClass();
		$stats_row->total_registrations     = 10;
		$stats_row->total_guests            = 25;
		$stats_row->fully_checked_in_count  = 5;
		$stats_row->checked_in_guests       = 15;

		$mock_wpdb->method( 'get_row' )
			->willReturn( $stats_row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->get_check_in_stats( 10 );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertArrayHasKey( 'total_registrations', $result );
			$this->assertArrayHasKey( 'total_guests', $result );
			$this->assertArrayHasKey( 'fully_checked_in_count', $result );
			$this->assertArrayHasKey( 'checked_in_guests', $result );
			$this->assertEquals( 10, $result['total_registrations'] );
			$this->assertEquals( 25, $result['total_guests'] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test get_check_in_stats handles null row values.
	 *
	 * @return void
	 */
	public function test_get_check_in_stats_handles_null_row(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

		$mock_wpdb->method( 'prepare' )
			->willReturnCallback( function ( $sql, ...$args ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return $sql;
			} );

		// Row with null values to test COALESCE fallbacks.
		$stats_row                          = new \stdClass();
		$stats_row->total_registrations     = null;
		$stats_row->total_guests            = null;
		$stats_row->fully_checked_in_count  = null;
		$stats_row->checked_in_guests       = null;

		$mock_wpdb->method( 'get_row' )
			->willReturn( $stats_row );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->get_check_in_stats( 99 );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertEquals( 0, $result['total_registrations'] );
			$this->assertEquals( 0, $result['total_guests'] );
			$this->assertEquals( 0, $result['fully_checked_in_count'] );
			$this->assertEquals( 0, $result['checked_in_guests'] );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	// =========================================================================
	// mark_checked_in() Additional Tests
	// =========================================================================

	/**
	 * Test mark_checked_in returns false on update failure.
	 *
	 * @return void
	 */
	public function test_mark_checked_in_returns_false_on_update_failure(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		$captured_sql = null;

		$mock_wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_row', 'prepare', 'update' ) )
			->getMock();

		$mock_wpdb->prefix = 'wp_';

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

		$mock_wpdb->method( 'update' )
			->willReturn( false );

		$wpdb = $mock_wpdb;

		try {
			$repo   = new AttendeeCheckInRepository( $wpdb );
			$result = $repo->mark_checked_in( 1 );

			$this->assertStringContainsString( 'nettertech_events_attendees', $captured_sql );
			$this->assertFalse( $result );
		} finally {
			$wpdb = $original_wpdb;
		}
	}
}
