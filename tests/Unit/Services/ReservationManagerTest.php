<?php
/**
 * ReservationManager unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Services\ReservationManager;

/**
 * Test ReservationManager functionality.
 *
 * Tests database-backed reservation storage for holding capacity
 * during checkout using atomic UPDATE operations.
 *
 * @coversDefaultClass \NetterTechEvents\Services\ReservationManager
 */
class ReservationManagerTest extends \NetterTechEventsTestCase {

	/**
	 * ReservationManager instance.
	 *
	 * @var ReservationManager
	 */
	private ReservationManager $manager;

	/**
	 * Mock wpdb instance.
	 *
	 * @var \wpdb&\PHPUnit\Framework\MockObject\MockObject
	 */
	private \wpdb $wpdb;

	/**
	 * Captured do_action calls.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $captured_actions;

	/**
	 * Set up test fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'prepare', 'query', 'get_var', 'get_results' ) )
			->getMock();

		$this->wpdb->prefix = 'wp_';

		// prepare() returns the SQL template string (first argument).
		$this->wpdb->method( 'prepare' )
			->willReturnCallback( function ( string $query ) {
				return $query;
			} );

		$GLOBALS['wpdb'] = $this->wpdb;

		$this->manager = new ReservationManager();

		// Default: no buffer stock configured.
		Functions\when( 'get_option' )->justReturn( array() );

		// Default: current_time returns a fixed value.
		Functions\when( 'current_time' )->justReturn( '2026-03-28 12:00:00' );

		// Capture do_action calls for assertion.
		$this->captured_actions = array();
		Functions\when( 'do_action' )->alias(
			function () {
				$this->captured_actions[] = func_get_args();
			}
		);
	}

	/**
	 * Assert that the RESERVATION_CHANGED hook was fired with given args.
	 *
	 * @param int    $ticket_type_id Expected ticket type ID.
	 * @param int    $delta          Expected delta.
	 * @param string $session_key    Expected session key.
	 * @return void
	 */
	private function assertReservationChangedFired( int $ticket_type_id, int $delta, string $session_key ): void {
		$matching = array_filter(
			$this->captured_actions,
			fn( $args ) => $args[0] === Hooks::RESERVATION_CHANGED
				&& $args[1] === $ticket_type_id
				&& $args[2] === $delta
				&& $args[3] === $session_key
		);
		$this->assertCount(
			1,
			$matching,
			sprintf(
				'Expected RESERVATION_CHANGED(%d, %d, %s) to fire exactly once, fired %d times.',
				$ticket_type_id,
				$delta,
				$session_key ?: "''",
				count( $matching )
			)
		);
	}

	/**
	 * Assert that the RESERVATION_CHANGED hook was NOT fired.
	 *
	 * @return void
	 */
	private function assertReservationChangedNotFired(): void {
		$matching = array_filter(
			$this->captured_actions,
			fn( $args ) => $args[0] === Hooks::RESERVATION_CHANGED
		);
		$this->assertCount(
			0,
			$matching,
			'Expected RESERVATION_CHANGED hook to not fire.'
		);
	}

	// =========================================================================
	// get_hold_time() Tests
	// =========================================================================

	/**
	 * @covers ::get_hold_time
	 */
	public function test_get_hold_time_returns_default_when_no_settings(): void {
		$result = $this->manager->get_hold_time();

		$this->assertSame( 900, $result ); // 15 minutes default.
	}

	/**
	 * @covers ::get_hold_time
	 */
	public function test_get_hold_time_returns_custom_value_from_settings(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'pending_hold_time' => 1800 ) // 30 minutes.
		);

		$result = $this->manager->get_hold_time();

		$this->assertSame( 1800, $result );
	}

	/**
	 * @covers ::get_hold_time
	 */
	public function test_get_hold_time_casts_to_int(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'pending_hold_time' => '600' ) // String value.
		);

		$result = $this->manager->get_hold_time();

		$this->assertSame( 600, $result );
		$this->assertIsInt( $result );
	}

	// =========================================================================
	// create_pending() Tests
	// =========================================================================

	/**
	 * @covers ::create_pending
	 */
	public function test_create_pending_returns_false_for_zero_quantity(): void {
		$result = $this->manager->create_pending( 1, 0, 'session_123' );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::create_pending
	 */
	public function test_create_pending_returns_false_for_negative_quantity(): void {
		$result = $this->manager->create_pending( 1, -5, 'session_123' );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::create_pending
	 */
	public function test_create_pending_returns_true_when_capacity_available(): void {
		// get_pending() returns 0 (no existing reservation).
		$this->wpdb->method( 'get_var' )
			->willReturn( null );

		// Atomic UPDATE affects 1 row, upsert INSERT succeeds.
		$this->wpdb->method( 'query' )
			->willReturn( 1 );

		$result = $this->manager->create_pending( 1, 5, 'session_abc', 900 );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::create_pending
	 */
	public function test_create_pending_returns_false_when_capacity_insufficient(): void {
		// get_pending() returns 0 (no existing reservation).
		$this->wpdb->method( 'get_var' )
			->willReturn( null );

		// Atomic UPDATE affects 0 rows (capacity insufficient).
		$this->wpdb->method( 'query' )
			->willReturn( 0 );

		$result = $this->manager->create_pending( 1, 100, 'session_abc', 900 );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::create_pending
	 */
	public function test_create_pending_fires_hook_on_success(): void {
		$this->wpdb->method( 'get_var' )
			->willReturn( null );

		$this->wpdb->method( 'query' )
			->willReturn( 1 );

		$this->manager->create_pending( 1, 5, 'session_abc', 900 );

		$this->assertReservationChangedFired( 1, 5, 'session_abc' );
	}

	/**
	 * @covers ::create_pending
	 */
	public function test_create_pending_does_not_fire_hook_on_failure(): void {
		$this->wpdb->method( 'get_var' )
			->willReturn( null );

		$this->wpdb->method( 'query' )
			->willReturn( 0 );

		$this->manager->create_pending( 1, 5, 'session_abc', 900 );

		$this->assertReservationChangedNotFired();
	}

	/**
	 * @covers ::create_pending
	 */
	public function test_create_pending_upsert_with_increased_quantity(): void {
		// Existing session has 3, requesting 5 => net_increase = 2.
		$this->wpdb->method( 'get_var' )
			->willReturn( '3' );

		// Atomic UPDATE for net_increase of 2 succeeds.
		$this->wpdb->method( 'query' )
			->willReturn( 1 );

		$result = $this->manager->create_pending( 1, 5, 'session_abc', 900 );

		$this->assertTrue( $result );
		$this->assertReservationChangedFired( 1, 2, 'session_abc' );
	}

	/**
	 * @covers ::create_pending
	 */
	public function test_create_pending_upsert_with_decreased_quantity(): void {
		// Existing session has 5, requesting 3 => net_increase = -2.
		// No atomic capacity check needed, just upsert tracking + adjust aggregate.
		$this->wpdb->method( 'get_var' )
			->willReturn( '5' );

		// upsert_tracking_row and adjust_aggregate both call query().
		$this->wpdb->method( 'query' )
			->willReturn( 1 );

		$result = $this->manager->create_pending( 1, 3, 'session_abc', 900 );

		$this->assertTrue( $result );
		$this->assertReservationChangedFired( 1, -2, 'session_abc' );
	}

	/**
	 * @covers ::create_pending
	 */
	public function test_create_pending_reads_buffer_stock_from_option(): void {
		// Buffer stock of 5 configured for ticket type 1.
		Functions\when( 'get_option' )->justReturn( array( 1 => 5 ) );

		$this->wpdb->method( 'get_var' )
			->willReturn( null );

		// Capture all SQL queries passed to query().
		$captured_queries = array();
		$this->wpdb->method( 'query' )
			->willReturnCallback( function ( string $sql ) use ( &$captured_queries ) {
				$captured_queries[] = $sql;
				return 1;
			} );

		$this->manager->create_pending( 1, 3, 'session_abc', 900 );

		// The atomic UPDATE query should contain the buffer_stock WHERE clause.
		$update_queries = array_filter(
			$captured_queries,
			fn( $sql ) => str_contains( $sql, 'capacity - sold_count' )
		);
		$this->assertNotEmpty( $update_queries, 'Atomic UPDATE query should reference capacity - sold_count.' );
	}

	// =========================================================================
	// clear_pending() Tests
	// =========================================================================

	/**
	 * @covers ::clear_pending
	 */
	public function test_clear_pending_all_sessions_decrements_and_deletes(): void {
		// get_pending_count() returns 10 (total reserved).
		$this->wpdb->method( 'get_var' )
			->willReturn( '10' );

		// adjust_aggregate and DELETE queries succeed.
		$this->wpdb->method( 'query' )
			->willReturn( 1 );

		$result = $this->manager->clear_pending( 1 );

		$this->assertTrue( $result );
		$this->assertReservationChangedFired( 1, -10, '' );
	}

	/**
	 * @covers ::clear_pending
	 */
	public function test_clear_pending_all_sessions_skips_adjust_when_zero(): void {
		// get_pending_count() returns 0.
		$this->wpdb->method( 'get_var' )
			->willReturn( null );

		// Only the DELETE query should run (no adjust_aggregate).
		$this->wpdb->expects( $this->once() )
			->method( 'query' );

		$result = $this->manager->clear_pending( 1 );

		$this->assertTrue( $result );
		$this->assertReservationChangedNotFired();
	}

	/**
	 * @covers ::clear_pending
	 */
	public function test_clear_pending_specific_session(): void {
		// get_pending() returns 3 for this session.
		$this->wpdb->method( 'get_var' )
			->willReturn( '3' );

		$this->wpdb->method( 'query' )
			->willReturn( 1 );

		$result = $this->manager->clear_pending( 1, 'session_abc' );

		$this->assertTrue( $result );
		$this->assertReservationChangedFired( 1, -3, 'session_abc' );
	}

	/**
	 * @covers ::clear_pending
	 */
	public function test_clear_pending_returns_true_when_session_already_cleared(): void {
		// get_pending() returns 0 (no active reservation).
		$this->wpdb->method( 'get_var' )
			->willReturn( null );

		$result = $this->manager->clear_pending( 1, 'nonexistent_session' );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// get_pending() Tests
	// =========================================================================

	/**
	 * @covers ::get_pending
	 */
	public function test_get_pending_returns_zero_when_no_row_found(): void {
		$this->wpdb->method( 'get_var' )
			->willReturn( null );

		$result = $this->manager->get_pending( 1, 'session_123' );

		$this->assertSame( 0, $result );
	}

	/**
	 * @covers ::get_pending
	 */
	public function test_get_pending_returns_quantity_as_int(): void {
		$this->wpdb->method( 'get_var' )
			->willReturn( '7' );

		$result = $this->manager->get_pending( 1, 'session_123' );

		$this->assertSame( 7, $result );
		$this->assertIsInt( $result );
	}

	/**
	 * @covers ::get_pending
	 */
	public function test_get_pending_sql_includes_expiry_check(): void {
		$captured_sql = '';
		// Override prepare to capture the query template.
		$this->wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'prepare', 'query', 'get_var', 'get_results' ) )
			->getMock();

		$this->wpdb->prefix = 'wp_';

		$this->wpdb->method( 'prepare' )
			->willReturnCallback( function ( string $query ) use ( &$captured_sql ) {
				$captured_sql = $query;
				return $query;
			} );

		$this->wpdb->method( 'get_var' )
			->willReturn( null );

		$GLOBALS['wpdb'] = $this->wpdb;

		$this->manager->get_pending( 1, 'session_123' );

		$this->assertStringContainsString( 'expires_at > %s', $captured_sql );
	}

	// =========================================================================
	// get_pending_count() Tests
	// =========================================================================

	/**
	 * @covers ::get_pending_count
	 */
	public function test_get_pending_count_returns_zero_when_null(): void {
		$this->wpdb->method( 'get_var' )
			->willReturn( null );

		$result = $this->manager->get_pending_count( 1 );

		$this->assertSame( 0, $result );
	}

	/**
	 * @covers ::get_pending_count
	 */
	public function test_get_pending_count_returns_reserved_as_int(): void {
		$this->wpdb->method( 'get_var' )
			->willReturn( '15' );

		$result = $this->manager->get_pending_count( 1 );

		$this->assertSame( 15, $result );
		$this->assertIsInt( $result );
	}

	// =========================================================================
	// sweep_expired() Tests
	// =========================================================================

	/**
	 * @covers ::sweep_expired
	 */
	public function test_sweep_expired_returns_zero_when_no_expired_rows(): void {
		$this->wpdb->method( 'get_results' )
			->willReturn( array() );

		$result = $this->manager->sweep_expired();

		$this->assertSame( 0, $result );
	}

	/**
	 * @covers ::sweep_expired
	 */
	public function test_sweep_expired_decrements_and_deletes_expired(): void {
		$expired = array(
			(object) array( 'ticket_type_id' => '1', 'total_qty' => '5' ),
			(object) array( 'ticket_type_id' => '2', 'total_qty' => '3' ),
		);

		$this->wpdb->method( 'get_results' )
			->willReturn( $expired );

		// UPDATE (decrement) for each ticket type + final DELETE.
		$this->wpdb->method( 'query' )
			->willReturn( 1 );

		$result = $this->manager->sweep_expired();

		$this->assertSame( 2, $result );

		// Two RESERVATION_CHANGED hooks fired.
		$reservation_actions = array_filter(
			$this->captured_actions,
			fn( $args ) => $args[0] === Hooks::RESERVATION_CHANGED
		);
		$this->assertCount( 2, $reservation_actions );
	}

	/**
	 * @covers ::sweep_expired
	 */
	public function test_sweep_expired_fires_hook_per_ticket_type(): void {
		$expired = array(
			(object) array( 'ticket_type_id' => '42', 'total_qty' => '7' ),
		);

		$this->wpdb->method( 'get_results' )
			->willReturn( $expired );

		$this->wpdb->method( 'query' )
			->willReturn( 1 );

		$this->manager->sweep_expired();

		$this->assertReservationChangedFired( 42, -7, '' );
	}

	/**
	 * @covers ::sweep_expired
	 */
	public function test_sweep_expired_returns_ticket_type_count_not_row_count(): void {
		// Three ticket types each with multiple expired rows (summed by GROUP BY).
		$expired = array(
			(object) array( 'ticket_type_id' => '1', 'total_qty' => '10' ),
			(object) array( 'ticket_type_id' => '2', 'total_qty' => '20' ),
			(object) array( 'ticket_type_id' => '3', 'total_qty' => '5' ),
		);

		$this->wpdb->method( 'get_results' )
			->willReturn( $expired );

		$this->wpdb->method( 'query' )
			->willReturn( 1 );

		$result = $this->manager->sweep_expired();

		// Returns count of distinct ticket_types cleaned, not sum of quantities.
		$this->assertSame( 3, $result );
	}
}
