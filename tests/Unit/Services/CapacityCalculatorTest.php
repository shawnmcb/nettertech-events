<?php
/**
 * CapacityCalculator unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use NetterTechEvents\Contracts\HouseCapacityRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Services\CapacityCalculator;
use NetterTechEvents\Contracts\ReservationManagerInterface;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;

/**
 * Test CapacityCalculator implementation.
 *
 * Tests the real calculator (not the interface mock used by CapacityServiceTest).
 */
class CapacityCalculatorTest extends \NetterTechEventsTestCase {

	/**
	 * Mock TicketTypeRepositoryInterface.
	 *
	 * @var TicketTypeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * Mock OccurrenceRepositoryInterface.
	 *
	 * @var OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Mock ReservationManagerInterface.
	 *
	 * @var ReservationManagerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $reservation_manager;

	/**
	 * CapacityCalculator instance under test.
	 *
	 * @var CapacityCalculator
	 */
	private CapacityCalculator $calculator;

	/**
	 * Mock HouseCapacityRepositoryInterface.
	 *
	 * @var HouseCapacityRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $house_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->ticket_type_repo    = $this->createMock( TicketTypeRepositoryInterface::class );
		$this->occurrence_repo     = $this->createMock( OccurrenceRepositoryInterface::class );
		$this->reservation_manager = $this->createMock( ReservationManagerInterface::class );
		$this->house_repo          = $this->createMock( HouseCapacityRepositoryInterface::class );

		$this->calculator = new CapacityCalculator(
			$this->ticket_type_repo,
			$this->occurrence_repo,
			$this->reservation_manager,
			$this->house_repo
		);

		// Default stubs for WordPress cache functions — return false (cache miss).
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_add' )->justReturn( true );

		// Default stub for get_option — empty buffer stock array.
		Functions\when( 'get_option' )->justReturn( array() );

		// Default stub for apply_filters — pass through first arg (no override).
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return $value;
			}
		);

		// Default stub for do_action — no-op.
		Functions\when( 'do_action' )->justReturn( null );
	}

	/**
	 * Stub the house context a ticket type sits in.
	 *
	 * Defaults describe a tier alone in an unbounded room, so a test that cares
	 * only about its own capacity need not describe the house at all.
	 *
	 * @param int|null $own_capacity   The tier's own capacity (null = no limit of its own).
	 * @param int|null $house          The room's capacity (null = unbounded).
	 * @param int      $own_sold       Seats sold on this tier.
	 * @param int      $house_sold     Seats sold across the whole room.
	 * @param int      $house_reserved Seats held across the whole room.
	 * @param string   $capacity_type  The tier's capacity type.
	 * @return void
	 */
	private function stub_house(
		?int $own_capacity,
		?int $house = null,
		int $own_sold = 0,
		int $house_sold = 0,
		int $house_reserved = 0,
		string $capacity_type = 'fixed'
	): void {
		$this->house_repo->method( 'context_for_ticket_type' )->willReturn(
			array(
				'house'             => $house,
				'house_sold'        => $house_sold,
				'house_reserved'    => $house_reserved,
				'own_capacity'      => $own_capacity,
				'own_capacity_type' => $capacity_type,
				'own_sold'          => $own_sold,
				'peer_ids'          => array( 1 ),
			)
		);
	}

	// =========================================================================
	// get_available_count() Tests
	// =========================================================================

	/**
	 * Test returns null for unlimited capacity.
	 *
	 * @return void
	 */
	public function test_get_available_count_returns_null_for_unlimited(): void {
		$ticket_type = TicketTypeFactory::unlimited( array( 'id' => 1 ) );
		$ticket_type->capacity_type = CapacityType::UNLIMITED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->stub_house( null, null, 0, 0, 0, CapacityType::UNLIMITED->value );

		$result = $this->calculator->get_available_count( 1 );

		$this->assertNull( $result );
	}

	/**
	 * Test subtracts buffer stock from base available.
	 *
	 * @return void
	 */
	public function test_get_available_count_subtracts_buffer_stock(): void {
		$ticket_type = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100, 'sold_count' => 20 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->stub_house( 100, null, 20 );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		// Buffer stock of 10 for ticket type 1.
		Functions\when( 'get_option' )->justReturn( array( 1 => 10 ) );

		$result = $this->calculator->get_available_count( 1 );

		$this->assertSame( 70, $result );
	}

	/**
	 * Test subtracts pending reservations.
	 *
	 * @return void
	 */
	public function test_get_available_count_subtracts_pending_reservations(): void {
		$ticket_type = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->stub_house( 100, null, 50 );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 5 );

		$result = $this->calculator->get_available_count( 1, true );

		$this->assertSame( 45, $result );
	}

	/**
	 * Test does not subtract pending when include_pending is false.
	 *
	 * @return void
	 */
	public function test_get_available_count_skips_pending_when_disabled(): void {
		$ticket_type = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->stub_house( 100, null, 50 );

		// Should never be called.
		$this->reservation_manager->expects( $this->never() )
			->method( 'get_pending_count' );

		$result = $this->calculator->get_available_count( 1, false );

		$this->assertSame( 50, $result );
	}

	/**
	 * Test returns cached value on cache hit.
	 *
	 * @return void
	 */
	public function test_get_available_count_returns_cached_value(): void {
		$ticket_type = TicketTypeFactory::create( array( 'id' => 1 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		Functions\when( 'wp_cache_get' )->justReturn( 42 );

		// The house should never be read when the cache answers.
		$this->house_repo->expects( $this->never() )
			->method( 'context_for_ticket_type' );

		$result = $this->calculator->get_available_count( 1 );

		$this->assertSame( 42, $result );
	}

	/**
	 * Test skip_cache bypasses cache.
	 *
	 * @return void
	 */
	public function test_get_available_count_skips_cache_when_requested(): void {
		$ticket_type = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->stub_house( 100, null, 20 );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		// Cache has stale value, but skip_cache=true should bypass.
		Functions\when( 'wp_cache_get' )->justReturn( 999 );

		$result = $this->calculator->get_available_count( 1, true, true );

		$this->assertSame( 80, $result );
	}

	/**
	 * Test filter override short-circuits calculation.
	 *
	 * @return void
	 */
	public function test_get_available_count_returns_filter_override(): void {
		$ticket_type = TicketTypeFactory::create( array( 'id' => 1 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->stub_house( null, null );

		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value, $ticket_type_id ) {
				if ( 'nettertech_events_available_count' === $hook ) {
					return 25;
				}
				return $value;
			}
		);

		$result = $this->calculator->get_available_count( 1 );

		$this->assertSame( 25, $result );
	}

	/**
	 * Test available count clamps to zero when buffer exceeds base.
	 *
	 * @return void
	 */
	public function test_get_available_count_clamps_to_zero(): void {
		$ticket_type = TicketTypeFactory::create( array( 'id' => 1 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->stub_house( 5, null, 0 );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		// Buffer stock (10) exceeds base available (5).
		Functions\when( 'get_option' )->justReturn( array( 1 => 10 ) );

		$result = $this->calculator->get_available_count( 1 );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test availability is bounded by what the house has left.
	 *
	 * The CJAC regression at the display gate: three tiers each carrying the hall's
	 * 250 capacity must not each advertise their own remainder.
	 *
	 * @return void
	 */
	public function test_get_available_count_is_bounded_by_the_house(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 250 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		// The tier has sold 101 of its own 250, but 112 seats are gone from the
		// 250-seat room it shares with two other tiers.
		$this->stub_house( 250, 250, 101, 112 );

		$this->assertSame( 138, $this->calculator->get_available_count( 1 ) );
	}

	/**
	 * Test a tier's own capacity binds when it is smaller than the house remainder.
	 *
	 * @return void
	 */
	public function test_get_available_count_is_bounded_by_the_tier_below_the_house(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 50 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		// A 50-seat balcony with 4 sold, in a 300-seat hall with 32 gone.
		$this->stub_house( 50, 300, 4, 32 );

		$this->assertSame( 46, $this->calculator->get_available_count( 1 ) );
	}

	/**
	 * Test a shared tier takes the house remainder and ignores its capacity column.
	 *
	 * Only a fixed tier's capacity column means anything; a shared tier may carry a
	 * stale value there because the admin form hides the input without clearing it.
	 *
	 * @return void
	 */
	public function test_get_available_count_ignores_a_stale_capacity_on_a_shared_tier(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 999 ) );
		$ticket_type->capacity_type = CapacityType::SHARED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		$this->stub_house( 999, 300, 10, 32, 0, CapacityType::SHARED->value );

		$this->assertSame( 268, $this->calculator->get_available_count( 1 ) );
	}

	/**
	 * Test seats held anywhere in the house count against every tier in it.
	 *
	 * @return void
	 */
	public function test_get_available_count_subtracts_house_wide_holds(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		// 100-seat house, 50 sold, 20 held in other tiers' carts.
		$this->stub_house( 100, 100, 0, 50, 20 );

		$this->assertSame( 30, $this->calculator->get_available_count( 1, true ) );
	}

	/**
	 * Test the seating add-on's count is still bounded by the house.
	 *
	 * The add-on knows about seats, not about the room the tier is sold into.
	 *
	 * @return void
	 */
	public function test_get_available_count_bounds_the_seating_override_by_the_house(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1 ) );
		$ticket_type->capacity_type = CapacityType::SEATED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->stub_house( null, 100, 0, 95, 0, CapacityType::SEATED->value );

		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				if ( 'nettertech_events_available_count' === $hook ) {
					return 40;
				}
				return $value;
			}
		);

		// The seating chart offers 40 seats; the room has 5 left.
		$this->assertSame( 5, $this->calculator->get_available_count( 1 ) );
	}

	// =========================================================================
	// get_total_capacity() Tests
	// =========================================================================

	/**
	 * Test returns capacity value from ticket type.
	 *
	 * @return void
	 */
	public function test_get_total_capacity_returns_value(): void {
		$ticket_type = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 200 ) );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		$this->assertSame( 200, $this->calculator->get_total_capacity( 1 ) );
	}

	/**
	 * Test returns null when ticket type not found.
	 *
	 * @return void
	 */
	public function test_get_total_capacity_returns_null_when_not_found(): void {
		$this->ticket_type_repo->method( 'find' )->willReturn( null );

		$this->assertNull( $this->calculator->get_total_capacity( 999 ) );
	}

	// =========================================================================
	// get_sold_count() Tests
	// =========================================================================

	/**
	 * Test returns sold count from ticket type.
	 *
	 * @return void
	 */
	public function test_get_sold_count_returns_value(): void {
		$ticket_type = TicketTypeFactory::create( array( 'id' => 1, 'sold_count' => 42 ) );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		$this->assertSame( 42, $this->calculator->get_sold_count( 1 ) );
	}

	/**
	 * Test returns zero when ticket type not found.
	 *
	 * @return void
	 */
	public function test_get_sold_count_returns_zero_when_not_found(): void {
		$this->ticket_type_repo->method( 'find' )->willReturn( null );

		$this->assertSame( 0, $this->calculator->get_sold_count( 999 ) );
	}

	// =========================================================================
	// Buffer Stock Tests
	// =========================================================================

	/**
	 * Test get_buffer_stock reads from option.
	 *
	 * @return void
	 */
	public function test_get_buffer_stock_returns_value_from_option(): void {
		Functions\when( 'get_option' )->justReturn( array( 5 => 15 ) );

		$this->assertSame( 15, $this->calculator->get_buffer_stock( 5 ) );
	}

	/**
	 * Test get_buffer_stock returns zero when no option set.
	 *
	 * @return void
	 */
	public function test_get_buffer_stock_returns_zero_when_no_option(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 0, $this->calculator->get_buffer_stock( 99 ) );
	}

	/**
	 * Test set_buffer_stock clamps negative values to zero.
	 *
	 * @return void
	 */
	public function test_set_buffer_stock_clamps_negative_to_zero(): void {
		$saved_value = null;

		Functions\when( 'update_option' )->alias(
			function ( $option, $value ) use ( &$saved_value ) {
				$saved_value = $value;
				return true;
			}
		);

		$this->calculator->set_buffer_stock( 1, -5 );

		$this->assertSame( 0, $saved_value[1] );
	}

	/**
	 * Test set_buffer_stock fires action on success.
	 *
	 * @return void
	 */
	public function test_set_buffer_stock_fires_action_on_success(): void {
		$action_fired = false;

		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'do_action' )->alias(
			function ( $hook ) use ( &$action_fired ) {
				if ( 'nettertech_events_buffer_stock_updated' === $hook ) {
					$action_fired = true;
				}
			}
		);

		$this->calculator->set_buffer_stock( 1, 10 );

		$this->assertTrue( $action_fired );
	}

	/**
	 * Test set_buffer_stock does not fire action on failure.
	 *
	 * @return void
	 */
	public function test_set_buffer_stock_does_not_fire_action_on_failure(): void {
		$action_fired = false;

		Functions\when( 'update_option' )->justReturn( false );
		Functions\when( 'do_action' )->alias(
			function ( $hook ) use ( &$action_fired ) {
				if ( 'nettertech_events_buffer_stock_updated' === $hook ) {
					$action_fired = true;
				}
			}
		);

		$this->calculator->set_buffer_stock( 1, 10 );

		$this->assertFalse( $action_fired );
	}

	/**
	 * Test set_buffer_stock handles corrupted (non-array) option value.
	 *
	 * @return void
	 */
	public function test_set_buffer_stock_handles_non_array_option(): void {
		Functions\when( 'get_option' )->justReturn( 'corrupted_string' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );

		$result = $this->calculator->set_buffer_stock( 1, 5 );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// get_series_pass_capacity() Tests
	// =========================================================================

	/**
	 * Test returns defaults when ticket type not found.
	 *
	 * @return void
	 */
	public function test_get_series_pass_capacity_returns_defaults_when_not_found(): void {
		$this->ticket_type_repo->method( 'find' )->willReturn( null );

		$result = $this->calculator->get_series_pass_capacity( 999 );

		$this->assertNull( $result['total'] );
		$this->assertNull( $result['per_occurrence'] );
		$this->assertSame( 0, $result['occurrence_count'] );
	}

	/**
	 * Test non-EVENT scope returns single occurrence result.
	 *
	 * @return void
	 */
	public function test_get_series_pass_capacity_non_event_scope(): void {
		$ticket_type        = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100 ) );
		$ticket_type->scope = TicketTypeScope::OCCURRENCE->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		$result = $this->calculator->get_series_pass_capacity( 1 );

		$this->assertSame( 100, $result['total'] );
		$this->assertSame( 100, $result['per_occurrence'] );
		$this->assertSame( 1, $result['occurrence_count'] );
	}

	/**
	 * Test EVENT scope counts occurrences from repository.
	 *
	 * @return void
	 */
	public function test_get_series_pass_capacity_event_scope_with_occurrences(): void {
		$ticket_type           = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 50 ) );
		$ticket_type->scope    = TicketTypeScope::EVENT->value;
		$ticket_type->event_id = 10;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->occurrence_repo->method( 'for_event' )->willReturn(
			array( new \stdClass(), new \stdClass(), new \stdClass() )
		);

		$result = $this->calculator->get_series_pass_capacity( 1 );

		$this->assertSame( 50, $result['total'] );
		$this->assertSame( 50, $result['per_occurrence'] );
		$this->assertSame( 3, $result['occurrence_count'] );
	}

	/**
	 * Test EVENT scope with null event_id returns zero occurrence count.
	 *
	 * @return void
	 */
	public function test_get_series_pass_capacity_event_scope_without_event_id(): void {
		$ticket_type           = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100 ) );
		$ticket_type->scope    = TicketTypeScope::EVENT->value;
		$ticket_type->event_id = null;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		$result = $this->calculator->get_series_pass_capacity( 1 );

		$this->assertSame( 100, $result['total'] );
		$this->assertSame( 100, $result['per_occurrence'] );
		$this->assertSame( 0, $result['occurrence_count'] );
	}

	// =========================================================================
	// get_capacity_summary() Tests
	// =========================================================================

	/**
	 * Test returns cached summary on cache hit.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_returns_cached_value(): void {
		$cached_summary = array( 'capacity' => 100, 'sold' => 50 );

		Functions\when( 'wp_cache_get' )->justReturn( $cached_summary );

		$result = $this->calculator->get_capacity_summary( 1 );

		$this->assertSame( $cached_summary, $result );
	}

	/**
	 * Test returns defaults when ticket type not found.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_returns_defaults_when_not_found(): void {
		$this->ticket_type_repo->method( 'find' )->willReturn( null );

		$result = $this->calculator->get_capacity_summary( 999 );

		$this->assertNull( $result['capacity'] );
		$this->assertSame( 0, $result['sold'] );
		$this->assertTrue( $result['is_unlimited'] );
		$this->assertFalse( $result['is_sold_out'] );
		$this->assertFalse( $result['is_low_stock'] );
	}

	/**
	 * Test calculates all fields correctly.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_calculates_correctly(): void {
		$ticket_type = TicketTypeFactory::create(
			array( 'id' => 1, 'capacity' => 100, 'sold_count' => 30 )
		);

		$this->stub_house( 100, null, 30 );
		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 5 );

		// Buffer stock of 10.
		Functions\when( 'get_option' )->justReturn( array( 1 => 10 ) );

		$result = $this->calculator->get_capacity_summary( 1 );

		$this->assertSame( 100, $result['capacity'] );
		$this->assertSame( 30, $result['sold'] );
		$this->assertSame( 70, $result['available'] );       // capacity - sold.
		$this->assertSame( 10, $result['buffer'] );
		$this->assertSame( 5, $result['pending'] );
		$this->assertSame( 55, $result['effective_available'] ); // available - buffer - pending.
		$this->assertFalse( $result['is_unlimited'] );
		$this->assertFalse( $result['is_sold_out'] );
		$this->assertFalse( $result['is_low_stock'] );
	}

	/**
	 * Test detects sold out when effective available is zero.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_detects_sold_out(): void {
		$ticket_type = TicketTypeFactory::soldOut( array( 'id' => 1, 'capacity' => 100 ) );

		$this->stub_house( 100, null, 100 );
		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		$result = $this->calculator->get_capacity_summary( 1 );

		$this->assertTrue( $result['is_sold_out'] );
		$this->assertFalse( $result['is_low_stock'] );
	}

	/**
	 * Test detects low stock at 10% threshold.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_detects_low_stock(): void {
		// capacity=100, sold=90 → available=10, effective_available=10 → 10% threshold.
		$ticket_type = TicketTypeFactory::create(
			array( 'id' => 1, 'capacity' => 100, 'sold_count' => 90 )
		);

		$this->stub_house( 100, null, 90 );
		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		$result = $this->calculator->get_capacity_summary( 1 );

		$this->assertFalse( $result['is_sold_out'] );
		$this->assertTrue( $result['is_low_stock'] );
	}

	/**
	 * Test handles unlimited capacity.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_handles_unlimited(): void {
		$ticket_type = TicketTypeFactory::unlimited( array( 'id' => 1 ) );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		$result = $this->calculator->get_capacity_summary( 1 );

		$this->assertNull( $result['capacity'] );
		$this->assertTrue( $result['is_unlimited'] );
		$this->assertNull( $result['available'] );
		$this->assertNull( $result['effective_available'] );
		$this->assertFalse( $result['is_sold_out'] );
	}

	// =========================================================================
	// get_occurrence_capacity() Tests
	// =========================================================================

	/**
	 * Test returns cached occurrence capacity.
	 *
	 * @return void
	 */
	public function test_get_occurrence_capacity_returns_cached_value(): void {
		$cached = array( 'total_capacity' => 200, 'total_sold' => 50 );

		Functions\when( 'wp_cache_get' )->justReturn( $cached );

		$result = $this->calculator->get_occurrence_capacity( 1 );

		$this->assertSame( $cached, $result );
	}

	/**
	 * Test the occurrence total is the house, never the sum of its tiers.
	 *
	 * Two tiers of 100 and 50 in one room with no stated ceiling describe a
	 * 100-seat room — the larger tier can sell the whole of it. Adding them to
	 * 150 invents fifty seats that do not exist (ADR-019).
	 *
	 * @return void
	 */
	public function test_get_occurrence_capacity_takes_the_house_not_the_sum(): void {
		$tt1 = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100, 'sold_count' => 20 ) );
		$tt2 = TicketTypeFactory::create( array( 'id' => 2, 'capacity' => 50, 'sold_count' => 10 ) );

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array( $tt1, $tt2 ) );
		$this->ticket_type_repo->method( 'find' )->willReturnMap(
			array(
				array( 1, $tt1 ),
				array( 2, $tt2 ),
			)
		);
		$this->occurrence_repo->method( 'find' )->willReturn(
			OccurrenceFactory::create( array( 'id' => 1, 'capacity' => null ) )
		);
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		$result = $this->calculator->get_occurrence_capacity( 1 );

		$this->assertSame( 100, $result['total_capacity'] );  // MAX(100, 50) — not 150.
		$this->assertSame( 30, $result['total_sold'] );       // 20 + 10; issued does add up.
		$this->assertSame( 70, $result['total_available'] );  // 100 - 30.
		$this->assertFalse( $result['has_unlimited'] );
		$this->assertCount( 2, $result['ticket_types'] );
	}

	/**
	 * Test an occurrence ceiling wins outright over the tiers beneath it.
	 *
	 * @return void
	 */
	public function test_get_occurrence_capacity_prefers_the_ceiling(): void {
		$tt1 = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 250, 'sold_count' => 100 ) );
		$tt2 = TicketTypeFactory::create( array( 'id' => 2, 'capacity' => 250, 'sold_count' => 100 ) );

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array( $tt1, $tt2 ) );
		$this->ticket_type_repo->method( 'find' )->willReturnMap(
			array(
				array( 1, $tt1 ),
				array( 2, $tt2 ),
			)
		);
		$this->occurrence_repo->method( 'find' )->willReturn(
			OccurrenceFactory::create( array( 'id' => 1, 'capacity' => 250 ) )
		);
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		$result = $this->calculator->get_occurrence_capacity( 1 );

		// The CJAC case: two 250-cap tiers in a 250-seat hall, 200 issued between
		// them. The hall has 50 left — not 300.
		$this->assertSame( 250, $result['total_capacity'] );
		$this->assertSame( 200, $result['total_sold'] );
		$this->assertSame( 50, $result['total_available'] );
		$this->assertFalse( $result['has_unlimited'] );
	}

	/**
	 * Test handles unlimited ticket type in occurrence.
	 *
	 * @return void
	 */
	public function test_get_occurrence_capacity_handles_unlimited_type(): void {
		$tt1 = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100, 'sold_count' => 20 ) );
		$tt2 = TicketTypeFactory::unlimited( array( 'id' => 2, 'sold_count' => 5 ) );

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array( $tt1, $tt2 ) );
		$this->ticket_type_repo->method( 'find' )->willReturnMap(
			array(
				array( 1, $tt1 ),
				array( 2, $tt2 ),
			)
		);
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		$result = $this->calculator->get_occurrence_capacity( 1 );

		$this->assertNull( $result['total_capacity'] );
		$this->assertTrue( $result['has_unlimited'] );
		$this->assertNull( $result['total_available'] );
	}

	/**
	 * Test handles empty occurrence (no ticket types).
	 *
	 * @return void
	 */
	public function test_get_occurrence_capacity_handles_empty_occurrence(): void {
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$result = $this->calculator->get_occurrence_capacity( 999 );

		// When no ticket types are configured, the calculator signals "no constraint"
		// via null total_capacity / total_available so RSVPFormShortcode can render
		// the RSVP form for unconfigured events instead of treating them as sold out.
		$this->assertNull( $result['total_capacity'] );
		$this->assertSame( 0, $result['total_sold'] );
		$this->assertNull( $result['total_available'] );
		$this->assertFalse( $result['has_unlimited'] );
		$this->assertEmpty( $result['ticket_types'] );
	}

	// =========================================================================
	// Mutation-hardening tests (Infection-escaped mutants)
	// =========================================================================

	/**
	 * A seating override of exactly zero must stay zero, not float up to one.
	 *
	 * Guards the `max( 0, (int) $override )` floor on the override path against an
	 * off-by-one (IncrementInteger) that would advertise a phantom seat.
	 *
	 * @return void
	 */
	public function test_get_available_count_override_of_zero_stays_zero(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1 ) );
		$ticket_type->capacity_type = CapacityType::SEATED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		// Room with plenty left, so the override — not the house — is the binding floor.
		$this->stub_house( null, 100, 0, 0, 0, CapacityType::SEATED->value );

		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'nettertech_events_available_count' === $hook ? 0 : $value;
			}
		);

		$this->assertSame( 0, $this->calculator->get_available_count( 1 ) );
	}

	/**
	 * A negative seating override is clamped up to zero by the `max( 0, ... )` floor.
	 *
	 * Guards that floor against a DecrementInteger (max( -1, ... )) that would leak a
	 * negative availability through the house bound.
	 *
	 * @return void
	 */
	public function test_get_available_count_negative_override_clamps_to_zero(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1 ) );
		$ticket_type->capacity_type = CapacityType::SEATED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->stub_house( null, 100, 0, 0, 0, CapacityType::SEATED->value );

		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'nettertech_events_available_count' === $hook ? -1 : $value;
			}
		);

		$this->assertSame( 0, $this->calculator->get_available_count( 1 ) );
	}

	/**
	 * The pre-hold `available` figure reads the house without counting reserved seats.
	 *
	 * Kills the FalseValue mutant that would flip `house_remaining( $context, false )`
	 * to `true` (folding holds into the pre-hold figure), plus the Increment/Decrement
	 * mutants on the `: 0` false-branch of the `$taken` sum.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_available_ignores_holds_and_reads_house(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 999 ) );
		$ticket_type->capacity_type = CapacityType::SHARED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		// 100-seat room, 50 sold, 20 held. Pre-hold availability is 50 — the holds
		// belong to effective_available, not to `available`.
		$this->stub_house( null, 100, 0, 50, 20, CapacityType::SHARED->value );

		$result = $this->calculator->get_capacity_summary( 1 );

		$this->assertSame( 50, $result['available'] );
	}

	/**
	 * A full-or-oversold house yields exactly zero pre-hold availability.
	 *
	 * The `available` field exposes `house_remaining()` with no downstream clamp, so it
	 * kills both the Increment (max( 1, ... ) → 1) and Decrement (max( -1, ... ) → -1)
	 * mutants on the `max( 0, house - taken )` floor.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_available_floors_oversold_house_at_zero(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 999 ) );
		$ticket_type->capacity_type = CapacityType::SHARED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		// 50-seat room with 51 already sold: one seat oversold. Availability floors at 0.
		$this->stub_house( null, 50, 0, 51, 0, CapacityType::SHARED->value );

		$result = $this->calculator->get_capacity_summary( 1 );

		$this->assertSame( 0, $result['available'] );
	}

	/**
	 * An unlimited tier is never sold out, even when a seating override reads zero.
	 *
	 * Kills the LogicalAnd mutant that rewrites the sold-out guard as
	 * `( ! $is_unlimited || null !== $effective_available )`, which would call an
	 * unbounded tier sold out the moment its effective availability hit zero.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_unlimited_is_not_sold_out_on_zero_override(): void {
		$ticket_type                = TicketTypeFactory::unlimited( array( 'id' => 1 ) );
		$ticket_type->capacity_type = CapacityType::UNLIMITED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );
		// Unbounded room and tier — capacity is null, so is_unlimited is true.
		$this->stub_house( null, null, 0, 0, 0, CapacityType::UNLIMITED->value );

		// The seating add-on reports zero seats, giving a non-null effective_available <= 0.
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'nettertech_events_available_count' === $hook ? 0 : $value;
			}
		);

		$result = $this->calculator->get_capacity_summary( 1 );

		$this->assertTrue( $result['is_unlimited'] );
		$this->assertSame( 0, $result['effective_available'] );
		$this->assertFalse( $result['is_sold_out'] );
	}

	/**
	 * Each occurrence tier entry keeps its `id` and its `summary` array.
	 *
	 * Kills the ArrayItemRemoval mutant that drops `'id' => $tt_id` and the ArrayItem
	 * mutant that corrupts `'summary' => ...` into a `'summary' > ...` comparison.
	 *
	 * @return void
	 */
	public function test_get_occurrence_capacity_entry_keeps_id_and_summary(): void {
		$tt1 = TicketTypeFactory::create( array( 'id' => 7, 'capacity' => 100, 'sold_count' => 20 ) );

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array( $tt1 ) );
		$this->ticket_type_repo->method( 'find' )->willReturn( $tt1 );
		$this->occurrence_repo->method( 'find' )->willReturn(
			OccurrenceFactory::create( array( 'id' => 1, 'capacity' => null ) )
		);
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		$result = $this->calculator->get_occurrence_capacity( 1 );

		$entry = $result['ticket_types'][0];
		$this->assertArrayHasKey( 'id', $entry );
		$this->assertSame( 7, $entry['id'] );
		$this->assertArrayHasKey( 'summary', $entry );
		$this->assertIsArray( $entry['summary'] );
		$this->assertArrayHasKey( 'capacity', $entry['summary'] );
	}

	/**
	 * Occurrence-level availability floors an oversold house at exactly zero.
	 *
	 * The `total_available` value is returned with no further clamp, so it kills both
	 * the Increment (max( 1, ... ) → 1) and Decrement (max( -1, ... ) → -1) mutants on
	 * the `max( 0, $house - $total_sold )` floor.
	 *
	 * @return void
	 */
	public function test_get_occurrence_capacity_total_available_floors_oversold_at_zero(): void {
		$tt1 = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 250, 'sold_count' => 250 ) );
		$tt2 = TicketTypeFactory::create( array( 'id' => 2, 'capacity' => 250, 'sold_count' => 1 ) );

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array( $tt1, $tt2 ) );
		$this->ticket_type_repo->method( 'find' )->willReturnMap(
			array(
				array( 1, $tt1 ),
				array( 2, $tt2 ),
			)
		);
		// A 250-seat ceiling with 251 issued between the tiers: one seat oversold.
		$this->occurrence_repo->method( 'find' )->willReturn(
			OccurrenceFactory::create( array( 'id' => 1, 'capacity' => 250 ) )
		);
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		$result = $this->calculator->get_occurrence_capacity( 1 );

		$this->assertSame( 251, $result['total_sold'] );
		$this->assertSame( 0, $result['total_available'] );
	}

	/**
	 * The pending-subtraction floor holds a fully-consumed tier at zero, never below.
	 *
	 * Line 158's `max( 0, own_remaining - pending )` floor is normally masked by the
	 * final buffer clamp, since a non-negative buffer maps both 0 and -1 to zero. It
	 * stops being redundant when the stored buffer is negative: get_buffer_stock()
	 * returns the option's raw int with no floor of its own, and a negative buffer is
	 * *added* back to the base. A tier whose holds have eaten its whole allocation must
	 * therefore contribute exactly zero to that sum — the DecrementInteger mutant
	 * (`max( -1, ... )`) would let it contribute minus one and short the count by a seat.
	 *
	 * @return void
	 */
	public function test_get_available_count_pending_floor_holds_consumed_tier_at_zero(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 5 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		// Unbounded room, five on the tier, six seats held: own remaining floors at 0.
		$this->stub_house( 5, null, 0 );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 6 );

		// A corrupted negative buffer is added back at the end, so the pre-buffer base
		// must be exactly 0 for the answer to be 5 — a leaked -1 would give 4.
		Functions\when( 'get_option' )->justReturn( array( 1 => -5 ) );

		$this->assertSame( 5, $this->calculator->get_available_count( 1, true ) );
	}

	/**
	 * Build a bare occurrence object carrying only an id, for for_event() stubs.
	 *
	 * @param int $id Occurrence ID.
	 * @return \stdClass
	 */
	private function occurrence_with_id( int $id ): \stdClass {
		$occurrence     = new \stdClass();
		$occurrence->id = $id;

		return $occurrence;
	}

	/**
	 * Test a series pass is additionally bounded by its tightest date's room (NTE-156).
	 *
	 * The pass's own allotment leaves 100, but one of its dates has only 5 seats
	 * left. A pass buyer must fit into every date, so 5 is the most it can sell.
	 *
	 * @return void
	 */
	public function test_get_available_count_series_pass_bounded_by_tightest_date(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;
		$ticket_type->scope         = TicketTypeScope::EVENT->value;
		$ticket_type->event_id      = 42;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		// Own allotment 100, unbounded event-scoped room (the event branch).
		$this->stub_house( 100, null, 0 );

		$this->occurrence_repo->method( 'for_event' )->willReturn(
			array( $this->occurrence_with_id( 11 ), $this->occurrence_with_id( 12 ) )
		);

		$this->house_repo->method( 'house_context_for_occurrence' )->willReturnMap(
			array(
				array( 11, array( 'house' => 300, 'house_sold' => 295, 'house_reserved' => 0 ) ),
				array( 12, array( 'house' => 400, 'house_sold' => 10, 'house_reserved' => 0 ) ),
			)
		);

		// Date 11 has 5 left, date 12 has 390 — the tightest binds at 5.
		$this->assertSame( 5, $this->calculator->get_available_count( 1 ) );
	}

	/**
	 * Test a series pass whose every date is unbounded keeps its own remainder.
	 *
	 * When no date's room binds, the pass is held only by its own allotment.
	 *
	 * @return void
	 */
	public function test_get_available_count_series_pass_unbounded_dates_use_own_remainder(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;
		$ticket_type->scope         = TicketTypeScope::EVENT->value;
		$ticket_type->event_id      = 42;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		$this->stub_house( 100, null, 0 );

		$this->occurrence_repo->method( 'for_event' )->willReturn(
			array( $this->occurrence_with_id( 11 ), $this->occurrence_with_id( 12 ) )
		);

		// Both dates report an unbounded room — none of them binds the pass.
		$this->house_repo->method( 'house_context_for_occurrence' )->willReturnMap(
			array(
				array( 11, array( 'house' => null, 'house_sold' => 0, 'house_reserved' => 0 ) ),
				array( 12, array( 'house' => null, 'house_sold' => 0, 'house_reserved' => 0 ) ),
			)
		);

		$this->assertSame( 100, $this->calculator->get_available_count( 1 ) );
	}

	/**
	 * Test a tier that is not event-scoped is never bounded by its event's dates.
	 *
	 * The date bound is reserved for series passes (scope EVENT). A tier that
	 * merely carries an event_id — an occurrence- or global-scoped tier — must be
	 * held only by its own allotment and its own room. Kills the two LogicalAnd
	 * mutants on the scope guard: `($ticket_type || scope===EVENT) && event_id`
	 * and `$ticket_type && scope===EVENT || event_id`, both of which would let the
	 * tightest date bind a non-pass tier down from 100 to 5.
	 *
	 * @return void
	 */
	public function test_get_available_count_non_event_scope_not_bounded_by_dates(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;
		$ticket_type->scope         = TicketTypeScope::OCCURRENCE->value;
		$ticket_type->event_id      = 42;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		// Own allotment 100 in an unbounded room.
		$this->stub_house( 100, null, 0 );

		// A tight date exists, but a non-EVENT tier must not consult it.
		$this->occurrence_repo->method( 'for_event' )->willReturn(
			array( $this->occurrence_with_id( 11 ) )
		);
		$this->house_repo->method( 'house_context_for_occurrence' )->willReturn(
			array( 'house' => 300, 'house_sold' => 295, 'house_reserved' => 0 )
		);

		$this->assertSame( 100, $this->calculator->get_available_count( 1 ) );
	}

	/**
	 * Test an oversold date floors the pass at exactly zero, never below.
	 *
	 * A date whose room is oversold (house_sold > house) contributes zero seats,
	 * not a negative number, through the `max( 0, house - house_sold )` floor on
	 * the tightest-date bound. A negative buffer is added back so the floored
	 * value survives the final buffer clamp to the assertion: it kills both the
	 * IncrementInteger (`max( 1, ... )` → 6) and DecrementInteger (`max( -1, ... )`
	 * → 4) mutants against the correct answer of 5.
	 *
	 * @return void
	 */
	public function test_get_available_count_series_pass_oversold_date_floors_at_zero(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;
		$ticket_type->scope         = TicketTypeScope::EVENT->value;
		$ticket_type->event_id      = 42;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		// Own allotment 100, unbounded event-scoped room.
		$this->stub_house( 100, null, 0 );

		$this->occurrence_repo->method( 'for_event' )->willReturn(
			array( $this->occurrence_with_id( 11 ) )
		);
		// 50-seat room with 51 sold: one seat oversold → remaining floors at 0.
		$this->house_repo->method( 'house_context_for_occurrence' )->willReturn(
			array( 'house' => 50, 'house_sold' => 51, 'house_reserved' => 0 )
		);

		// A corrupted negative buffer (-5) is added back after the bound, so the
		// floored 0 becomes 5. A leaked 1 would give 6, a leaked -1 would give 4.
		Functions\when( 'get_option' )->justReturn( array( 1 => -5 ) );

		$this->assertSame( 5, $this->calculator->get_available_count( 1 ) );
	}

	/**
	 * Test a date with an unbounded room is skipped, not treated as a loop end.
	 *
	 * When the tightest-date scan meets an occurrence whose room is unbounded, it
	 * must skip that date and keep scanning — a later date may still bind. Kills
	 * the Continue_→break mutant: the first date reports a null house (skip), and
	 * the second is a tight 5-seat room that must still bound the pass. A break
	 * would abandon the scan at the first date and leave the pass at its own 100.
	 *
	 * @return void
	 */
	public function test_get_available_count_series_pass_skips_unbounded_date_then_binds(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;
		$ticket_type->scope         = TicketTypeScope::EVENT->value;
		$ticket_type->event_id      = 42;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		$this->stub_house( 100, null, 0 );

		$this->occurrence_repo->method( 'for_event' )->willReturn(
			array( $this->occurrence_with_id( 11 ), $this->occurrence_with_id( 12 ) )
		);
		$this->house_repo->method( 'house_context_for_occurrence' )->willReturnMap(
			array(
				// First date: unbounded room — must be skipped, not end the scan.
				array( 11, array( 'house' => null, 'house_sold' => 0, 'house_reserved' => 0 ) ),
				// Second date: 5 seats left — the bound the pass must inherit.
				array( 12, array( 'house' => 300, 'house_sold' => 295, 'house_reserved' => 0 ) ),
			)
		);

		$this->assertSame( 5, $this->calculator->get_available_count( 1 ) );
	}

	/**
	 * Test the tightest-date bound is the min across dates, in either order.
	 *
	 * The second date is tighter than the first here (the reverse of the existing
	 * tightest-date test), so `min( $tightest, $remaining )` must fold the later,
	 * smaller value in rather than keeping the first. Guards the min accumulation
	 * against a direction flip.
	 *
	 * @return void
	 */
	public function test_get_available_count_series_pass_min_folds_later_tighter_date(): void {
		$ticket_type                = TicketTypeFactory::create( array( 'id' => 1, 'capacity' => 100 ) );
		$ticket_type->capacity_type = CapacityType::FIXED->value;
		$ticket_type->scope         = TicketTypeScope::EVENT->value;
		$ticket_type->event_id      = 42;

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->reservation_manager->method( 'get_pending_count' )->willReturn( 0 );

		$this->stub_house( 100, null, 0 );

		$this->occurrence_repo->method( 'for_event' )->willReturn(
			array( $this->occurrence_with_id( 11 ), $this->occurrence_with_id( 12 ) )
		);
		$this->house_repo->method( 'house_context_for_occurrence' )->willReturnMap(
			array(
				// First date has 90 left, second only 7 — the later, tighter one binds.
				array( 11, array( 'house' => 100, 'house_sold' => 10, 'house_reserved' => 0 ) ),
				array( 12, array( 'house' => 300, 'house_sold' => 293, 'house_reserved' => 0 ) ),
			)
		);

		$this->assertSame( 7, $this->calculator->get_available_count( 1 ) );
	}
}
