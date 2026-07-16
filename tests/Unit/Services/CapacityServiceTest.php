<?php
/**
 * CapacityService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Contracts\CapacityCalculatorInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\CapacityService;
use NetterTechEvents\Contracts\ReservationManagerInterface;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;

/**
 * Test CapacityService functionality.
 */
class CapacityServiceTest extends \NetterTechEventsTestCase {

	/**
	 * CapacityService instance.
	 *
	 * @var CapacityService
	 */
	private CapacityService $service;

	/**
	 * Mock TicketTypeRepositoryInterface.
	 *
	 * @var TicketTypeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * Mock CapacityCalculatorInterface.
	 *
	 * @var CapacityCalculatorInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $calculator;

	/**
	 * Mock ReservationManagerInterface.
	 *
	 * @var ReservationManagerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $reservation_manager;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->ticket_type_repo    = $this->createMock( TicketTypeRepositoryInterface::class );
		$this->calculator          = $this->createMock( CapacityCalculatorInterface::class );
		$this->reservation_manager = $this->createMock( ReservationManagerInterface::class );
		$this->service             = new CapacityService(
			$this->ticket_type_repo,
			$this->calculator,
			$this->reservation_manager
		);

		TicketTypeFactory::reset();
		OccurrenceFactory::reset();
	}

	// =========================================================================
	// has_availability tests
	// =========================================================================

	/**
	 * Test has_availability returns true for unlimited capacity.
	 *
	 * @return void
	 */
	public function test_has_availability_returns_true_for_unlimited(): void {
		$ticket_type = TicketTypeFactory::unlimited();

		$this->calculator
			->expects( $this->once() )
			->method( 'get_available_count' )
			->with( $ticket_type->id, true )
			->willReturn( null );

		$result = $this->service->has_availability( $ticket_type->id, 100 );

		$this->assertTrue( $result );
	}

	/**
	 * Test has_availability returns true when capacity available.
	 *
	 * @return void
	 */
	public function test_has_availability_returns_true_when_available(): void {
		$ticket_type = TicketTypeFactory::create( array( 'capacity' => 100, 'sold_count' => 50 ) );

		$this->calculator
			->expects( $this->once() )
			->method( 'get_available_count' )
			->with( $ticket_type->id, true )
			->willReturn( 50 );

		$result = $this->service->has_availability( $ticket_type->id, 10 );

		$this->assertTrue( $result );
	}

	/**
	 * Test has_availability returns false when capacity exhausted.
	 *
	 * @return void
	 */
	public function test_has_availability_returns_false_when_exhausted(): void {
		$ticket_type = TicketTypeFactory::soldOut();

		$this->calculator
			->expects( $this->once() )
			->method( 'get_available_count' )
			->with( $ticket_type->id, true )
			->willReturn( 0 );

		$result = $this->service->has_availability( $ticket_type->id, 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test has_availability returns false when requesting more than available.
	 *
	 * @return void
	 */
	public function test_has_availability_returns_false_for_oversized_request(): void {
		$ticket_type = TicketTypeFactory::create( array( 'capacity' => 100, 'sold_count' => 95 ) );

		$this->calculator
			->expects( $this->once() )
			->method( 'get_available_count' )
			->with( $ticket_type->id, true )
			->willReturn( 5 );

		$result = $this->service->has_availability( $ticket_type->id, 10 );

		$this->assertFalse( $result );
	}

	/**
	 * Test has_availability uses default quantity of 1.
	 *
	 * @return void
	 */
	public function test_has_availability_defaults_to_quantity_one(): void {
		$ticket_type = TicketTypeFactory::create( array( 'capacity' => 100, 'sold_count' => 99 ) );

		$this->calculator
			->method( 'get_available_count' )
			->willReturn( 1 );

		$result = $this->service->has_availability( $ticket_type->id );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// reserve_capacity tests
	// =========================================================================

	/**
	 * Test reserve_capacity calls increment_sold_count.
	 *
	 * @return void
	 */
	public function test_reserve_capacity_increments_sold_count(): void {
		$ticket_type = TicketTypeFactory::create();

		$this->ticket_type_repo
			->expects( $this->once() )
			->method( 'find' )
			->with( $ticket_type->id )
			->willReturn( $ticket_type );

		$this->ticket_type_repo
			->expects( $this->once() )
			->method( 'increment_sold_count' )
			->with( $ticket_type->id, 5 )
			->willReturn( true );

		$result = $this->service->reserve_capacity( $ticket_type->id, 5 );

		$this->assertTrue( $result );
	}

	/**
	 * Test reserve_capacity returns false for zero quantity.
	 *
	 * @return void
	 */
	public function test_reserve_capacity_rejects_zero_quantity(): void {
		$result = $this->service->reserve_capacity( 1, 0 );

		$this->assertFalse( $result );
	}

	/**
	 * Test reserve_capacity returns false for negative quantity.
	 *
	 * @return void
	 */
	public function test_reserve_capacity_rejects_negative_quantity(): void {
		$result = $this->service->reserve_capacity( 1, -5 );

		$this->assertFalse( $result );
	}

	/**
	 * Test reserve_capacity returns false when ticket type not found.
	 *
	 * @return void
	 */
	public function test_reserve_capacity_returns_false_when_not_found(): void {
		$this->ticket_type_repo
			->expects( $this->once() )
			->method( 'find' )
			->with( 999 )
			->willReturn( null );

		$result = $this->service->reserve_capacity( 999, 5 );

		$this->assertFalse( $result );
	}

	/**
	 * Test reserve_capacity returns false when increment fails.
	 *
	 * @return void
	 */
	public function test_reserve_capacity_returns_false_when_increment_fails(): void {
		$ticket_type = TicketTypeFactory::create();

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		$this->ticket_type_repo
			->method( 'increment_sold_count' )
			->willReturn( false );

		$result = $this->service->reserve_capacity( $ticket_type->id, 5 );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// release_capacity tests
	// =========================================================================

	/**
	 * Test release_capacity calls decrement_sold_count.
	 *
	 * @return void
	 */
	public function test_release_capacity_decrements_sold_count(): void {
		$ticket_type = TicketTypeFactory::create( array( 'sold_count' => 10 ) );

		$this->ticket_type_repo
			->expects( $this->once() )
			->method( 'find' )
			->with( $ticket_type->id )
			->willReturn( $ticket_type );

		$this->ticket_type_repo
			->expects( $this->once() )
			->method( 'decrement_sold_count' )
			->with( $ticket_type->id, 3 )
			->willReturn( true );

		$result = $this->service->release_capacity( $ticket_type->id, 3 );

		$this->assertTrue( $result );
	}

	/**
	 * Test release_capacity returns false for zero quantity.
	 *
	 * @return void
	 */
	public function test_release_capacity_rejects_zero_quantity(): void {
		$result = $this->service->release_capacity( 1, 0 );

		$this->assertFalse( $result );
	}

	/**
	 * Test release_capacity returns false for negative quantity.
	 *
	 * @return void
	 */
	public function test_release_capacity_rejects_negative_quantity(): void {
		$result = $this->service->release_capacity( 1, -5 );

		$this->assertFalse( $result );
	}

	/**
	 * Test release_capacity returns false when ticket type not found.
	 *
	 * @return void
	 */
	public function test_release_capacity_returns_false_when_not_found(): void {
		$this->ticket_type_repo
			->expects( $this->once() )
			->method( 'find' )
			->with( 999 )
			->willReturn( null );

		$result = $this->service->release_capacity( 999, 5 );

		$this->assertFalse( $result );
	}

	/**
	 * Test release_capacity returns false when decrement fails.
	 *
	 * @return void
	 */
	public function test_release_capacity_returns_false_when_decrement_fails(): void {
		$ticket_type = TicketTypeFactory::create( array( 'sold_count' => 10 ) );

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		$this->ticket_type_repo
			->method( 'decrement_sold_count' )
			->willReturn( false );

		$result = $this->service->release_capacity( $ticket_type->id, 3 );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// get_available_count tests
	// =========================================================================

	/**
	 * Test get_available_count returns null for unlimited.
	 *
	 * @return void
	 */
	public function test_get_available_count_returns_null_for_unlimited(): void {
		$ticket_type = TicketTypeFactory::unlimited();

		$this->calculator
			->expects( $this->once() )
			->method( 'get_available_count' )
			->with( $ticket_type->id, true, false )
			->willReturn( null );

		$result = $this->service->get_available_count( $ticket_type->id );

		$this->assertNull( $result );
	}

	/**
	 * Test get_available_count returns base value when no buffer.
	 *
	 * @return void
	 */
	public function test_get_available_count_returns_base_value(): void {
		$this->calculator
			->expects( $this->once() )
			->method( 'get_available_count' )
			->with( 42, false, false )
			->willReturn( 50 );

		$result = $this->service->get_available_count( 42, false );

		$this->assertEquals( 50, $result );
	}

	/**
	 * Test get_available_count can skip cache.
	 *
	 * @return void
	 */
	public function test_get_available_count_skip_cache(): void {
		$this->calculator
			->method( 'get_available_count' )
			->with( 1, false, true )
			->willReturn( 50 );

		$result = $this->service->get_available_count( 1, false, true );

		$this->assertEquals( 50, $result );
	}

	// =========================================================================
	// get_total_capacity tests
	// =========================================================================

	/**
	 * Test get_total_capacity returns capacity for existing ticket type.
	 *
	 * @return void
	 */
	public function test_get_total_capacity_returns_capacity(): void {
		$ticket_type = TicketTypeFactory::create( array( 'capacity' => 100 ) );

		$this->calculator
			->expects( $this->once() )
			->method( 'get_total_capacity' )
			->with( $ticket_type->id )
			->willReturn( 100 );

		$result = $this->service->get_total_capacity( $ticket_type->id );

		$this->assertEquals( 100, $result );
	}

	/**
	 * Test get_total_capacity returns null when not found.
	 *
	 * @return void
	 */
	public function test_get_total_capacity_returns_null_when_not_found(): void {
		$this->calculator
			->expects( $this->once() )
			->method( 'get_total_capacity' )
			->with( 999 )
			->willReturn( null );

		$result = $this->service->get_total_capacity( 999 );

		$this->assertNull( $result );
	}

	/**
	 * Test get_total_capacity returns null for unlimited.
	 *
	 * @return void
	 */
	public function test_get_total_capacity_returns_null_for_unlimited(): void {
		$ticket_type = TicketTypeFactory::unlimited();

		$this->calculator
			->method( 'get_total_capacity' )
			->willReturn( null );

		$result = $this->service->get_total_capacity( $ticket_type->id );

		$this->assertNull( $result );
	}

	// =========================================================================
	// get_sold_count tests
	// =========================================================================

	/**
	 * Test get_sold_count returns sold count.
	 *
	 * @return void
	 */
	public function test_get_sold_count_returns_count(): void {
		$ticket_type = TicketTypeFactory::create( array( 'sold_count' => 25 ) );

		$this->calculator
			->expects( $this->once() )
			->method( 'get_sold_count' )
			->with( $ticket_type->id )
			->willReturn( 25 );

		$result = $this->service->get_sold_count( $ticket_type->id );

		$this->assertEquals( 25, $result );
	}

	/**
	 * Test get_sold_count returns zero when not found.
	 *
	 * @return void
	 */
	public function test_get_sold_count_returns_zero_when_not_found(): void {
		$this->calculator
			->expects( $this->once() )
			->method( 'get_sold_count' )
			->with( 999 )
			->willReturn( 0 );

		$result = $this->service->get_sold_count( 999 );

		$this->assertEquals( 0, $result );
	}

	// =========================================================================
	// Buffer stock tests
	// =========================================================================

	/**
	 * Test get_buffer_stock returns zero with default stubs.
	 *
	 * @return void
	 */
	public function test_get_buffer_stock_returns_zero_with_default(): void {
		$this->calculator
			->method( 'get_buffer_stock' )
			->willReturn( 0 );

		$result = $this->service->get_buffer_stock( 1 );

		$this->assertEquals( 0, $result );
	}

	/**
	 * Test set_buffer_stock returns true.
	 *
	 * @return void
	 */
	public function test_set_buffer_stock_returns_true(): void {
		$this->calculator
			->method( 'set_buffer_stock' )
			->willReturn( true );

		$result = $this->service->set_buffer_stock( 42, 10 );

		$this->assertTrue( $result );
	}

	/**
	 * Test set_buffer_stock clamps negative values to zero.
	 *
	 * @return void
	 */
	public function test_set_buffer_stock_clamps_negative_to_zero(): void {
		$this->calculator
			->method( 'set_buffer_stock' )
			->willReturn( true );

		$result = $this->service->set_buffer_stock( 42, -5 );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// get_hold_time tests
	// =========================================================================

	/**
	 * Test get_hold_time returns default when no settings.
	 *
	 * @return void
	 */
	public function test_get_hold_time_returns_default(): void {
		$this->reservation_manager
			->method( 'get_hold_time' )
			->willReturn( 900 );

		$result = $this->service->get_hold_time();

		$this->assertEquals( 900, $result );
	}

	// =========================================================================
	// Pending reservation tests
	// =========================================================================

	/**
	 * Test create_pending_reservation rejects zero quantity.
	 *
	 * @return void
	 */
	public function test_create_pending_reservation_rejects_zero(): void {
		$result = $this->service->create_pending_reservation( 1, 0, 'session_123' );

		$this->assertFalse( $result );
	}

	/**
	 * Test create_pending_reservation rejects negative quantity.
	 *
	 * @return void
	 */
	public function test_create_pending_reservation_rejects_negative(): void {
		$result = $this->service->create_pending_reservation( 1, -5, 'session_123' );

		$this->assertFalse( $result );
	}

	/**
	 * Test get_pending_reservation returns zero with default stubs.
	 *
	 * @return void
	 */
	public function test_get_pending_reservation_returns_zero_with_default(): void {
		$this->reservation_manager
			->method( 'get_pending' )
			->willReturn( 0 );

		$result = $this->service->get_pending_reservation( 42, 'session_123' );

		$this->assertEquals( 0, $result );
	}

	/**
	 * Test get_pending_count returns zero with default stubs.
	 *
	 * @return void
	 */
	public function test_get_pending_count_returns_zero_with_default(): void {
		$this->reservation_manager
			->method( 'get_pending_count' )
			->willReturn( 0 );

		$result = $this->service->get_pending_count( 42 );

		$this->assertEquals( 0, $result );
	}

	/**
	 * Test clear_pending_reservation returns true.
	 *
	 * @return void
	 */
	public function test_clear_pending_reservation_returns_true(): void {
		$this->reservation_manager
			->method( 'clear_pending' )
			->willReturn( true );

		$result = $this->service->clear_pending_reservation( 42 );

		$this->assertTrue( $result );
	}

	/**
	 * Test clear_pending_reservation with session key returns true.
	 *
	 * @return void
	 */
	public function test_clear_pending_reservation_with_session_returns_true(): void {
		$this->reservation_manager
			->method( 'clear_pending' )
			->willReturn( true );

		$result = $this->service->clear_pending_reservation( 42, 'session_123' );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// get_capacity_summary tests
	// =========================================================================

	/**
	 * Test get_capacity_summary returns correct structure.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_returns_complete_structure(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'capacity'   => 100,
				'sold_count' => 92,
			)
		);

		$this->calculator
			->expects( $this->once() )
			->method( 'get_capacity_summary' )
			->with( $ticket_type->id, false )
			->willReturn(
				array(
					'capacity'            => 100,
					'sold'                => 92,
					'available'           => 8,
					'buffer'              => 0,
					'pending'             => 0,
					'effective_available' => 8,
					'is_unlimited'        => false,
					'is_sold_out'         => false,
					'is_low_stock'        => true,
				)
			);

		$summary = $this->service->get_capacity_summary( $ticket_type->id );

		$this->assertArrayHasKey( 'capacity', $summary );
		$this->assertArrayHasKey( 'sold', $summary );
		$this->assertArrayHasKey( 'available', $summary );
		$this->assertArrayHasKey( 'is_unlimited', $summary );
		$this->assertArrayHasKey( 'is_sold_out', $summary );
		$this->assertArrayHasKey( 'is_low_stock', $summary );

		$this->assertEquals( 100, $summary['capacity'] );
		$this->assertEquals( 92, $summary['sold'] );
		$this->assertEquals( 8, $summary['available'] );
		$this->assertFalse( $summary['is_unlimited'] );
		$this->assertFalse( $summary['is_sold_out'] );
		$this->assertTrue( $summary['is_low_stock'] );
	}

	/**
	 * Test get_capacity_summary identifies sold out correctly.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_identifies_sold_out(): void {
		$ticket_type = TicketTypeFactory::soldOut();

		$this->calculator
			->expects( $this->once() )
			->method( 'get_capacity_summary' )
			->with( $ticket_type->id, false )
			->willReturn(
				array(
					'capacity'            => 100,
					'sold'                => 100,
					'available'           => 0,
					'buffer'              => 0,
					'pending'             => 0,
					'effective_available' => 0,
					'is_unlimited'        => false,
					'is_sold_out'         => true,
					'is_low_stock'        => false,
				)
			);

		$summary = $this->service->get_capacity_summary( $ticket_type->id );

		$this->assertTrue( $summary['is_sold_out'] );
		$this->assertEquals( 0, $summary['available'] );
	}

	/**
	 * Test get_capacity_summary handles unlimited capacity.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_handles_unlimited(): void {
		$ticket_type = TicketTypeFactory::unlimited();

		$this->calculator
			->expects( $this->once() )
			->method( 'get_capacity_summary' )
			->with( $ticket_type->id, false )
			->willReturn(
				array(
					'capacity'            => null,
					'sold'                => 0,
					'available'           => null,
					'buffer'              => 0,
					'pending'             => 0,
					'effective_available' => null,
					'is_unlimited'        => true,
					'is_sold_out'         => false,
					'is_low_stock'        => false,
				)
			);

		$summary = $this->service->get_capacity_summary( $ticket_type->id );

		$this->assertTrue( $summary['is_unlimited'] );
		$this->assertNull( $summary['capacity'] );
		$this->assertNull( $summary['available'] );
		$this->assertFalse( $summary['is_sold_out'] );
		$this->assertFalse( $summary['is_low_stock'] );
	}

	/**
	 * Test get_capacity_summary returns default values when not found.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_returns_defaults_when_not_found(): void {
		$this->calculator
			->expects( $this->once() )
			->method( 'get_capacity_summary' )
			->with( 999, false )
			->willReturn(
				array(
					'capacity'            => null,
					'sold'                => 0,
					'available'           => null,
					'buffer'              => 0,
					'pending'             => 0,
					'effective_available' => null,
					'is_unlimited'        => true,
					'is_sold_out'         => false,
					'is_low_stock'        => false,
				)
			);

		$summary = $this->service->get_capacity_summary( 999 );

		$this->assertNull( $summary['capacity'] );
		$this->assertEquals( 0, $summary['sold'] );
		$this->assertNull( $summary['available'] );
		$this->assertTrue( $summary['is_unlimited'] );
		$this->assertFalse( $summary['is_sold_out'] );
		$this->assertFalse( $summary['is_low_stock'] );
	}

	/**
	 * Test get_capacity_summary includes buffer and pending keys.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_includes_buffer_and_pending(): void {
		$ticket_type = TicketTypeFactory::create( array( 'capacity' => 100, 'sold_count' => 50 ) );

		$this->calculator
			->method( 'get_capacity_summary' )
			->willReturn(
				array(
					'capacity'            => 100,
					'sold'                => 50,
					'available'           => 50,
					'buffer'              => 0,
					'pending'             => 0,
					'effective_available' => 50,
					'is_unlimited'        => false,
					'is_sold_out'         => false,
					'is_low_stock'        => false,
				)
			);

		$summary = $this->service->get_capacity_summary( $ticket_type->id );

		$this->assertArrayHasKey( 'buffer', $summary );
		$this->assertArrayHasKey( 'pending', $summary );
		$this->assertArrayHasKey( 'effective_available', $summary );
	}

	/**
	 * Test get_capacity_summary can skip cache.
	 *
	 * @return void
	 */
	public function test_get_capacity_summary_skip_cache(): void {
		$ticket_type = TicketTypeFactory::create( array( 'capacity' => 100, 'sold_count' => 50 ) );

		$this->calculator
			->method( 'get_capacity_summary' )
			->with( $ticket_type->id, true )
			->willReturn(
				array(
					'capacity'            => 100,
					'sold'                => 50,
					'available'           => 50,
					'buffer'              => 0,
					'pending'             => 0,
					'effective_available' => 50,
					'is_unlimited'        => false,
					'is_sold_out'         => false,
					'is_low_stock'        => false,
				)
			);

		$summary = $this->service->get_capacity_summary( $ticket_type->id, true );

		$this->assertEquals( 100, $summary['capacity'] );
	}

	// =========================================================================
	// get_occurrence_capacity tests
	// =========================================================================

	/**
	 * Test get_occurrence_capacity aggregates ticket types.
	 *
	 * @return void
	 */
	public function test_get_occurrence_capacity_aggregates_types(): void {
		$this->calculator
			->expects( $this->once() )
			->method( 'get_occurrence_capacity' )
			->with( 99, false )
			->willReturn(
				array(
					'total_capacity'  => 120,
					'total_sold'      => 60,
					'total_available' => 60,
					'has_unlimited'   => false,
					'ticket_types'    => array(
						array( 'id' => 1, 'name' => 'GA' ),
						array( 'id' => 2, 'name' => 'VIP' ),
					),
				)
			);

		$result = $this->service->get_occurrence_capacity( 99 );

		$this->assertEquals( 120, $result['total_capacity'] );
		$this->assertEquals( 60, $result['total_sold'] );
		$this->assertEquals( 60, $result['total_available'] );
		$this->assertFalse( $result['has_unlimited'] );
		$this->assertCount( 2, $result['ticket_types'] );
	}

	/**
	 * Test get_occurrence_capacity handles unlimited types.
	 *
	 * @return void
	 */
	public function test_get_occurrence_capacity_handles_unlimited(): void {
		$this->calculator
			->expects( $this->once() )
			->method( 'get_occurrence_capacity' )
			->with( 99, false )
			->willReturn(
				array(
					'total_capacity'  => null,
					'total_sold'      => 20,
					'total_available' => null,
					'has_unlimited'   => true,
					'ticket_types'    => array(
						array( 'id' => 1, 'name' => 'Free' ),
					),
				)
			);

		$result = $this->service->get_occurrence_capacity( 99 );

		$this->assertNull( $result['total_capacity'] );
		$this->assertNull( $result['total_available'] );
		$this->assertTrue( $result['has_unlimited'] );
	}

	/**
	 * Test get_occurrence_capacity handles empty ticket types.
	 *
	 * @return void
	 */
	public function test_get_occurrence_capacity_handles_empty(): void {
		$this->calculator
			->method( 'get_occurrence_capacity' )
			->willReturn(
				array(
					'total_capacity'  => 0,
					'total_sold'      => 0,
					'total_available' => 0,
					'has_unlimited'   => false,
					'ticket_types'    => array(),
				)
			);

		$result = $this->service->get_occurrence_capacity( 99 );

		$this->assertEquals( 0, $result['total_capacity'] );
		$this->assertEquals( 0, $result['total_sold'] );
		$this->assertEquals( 0, $result['total_available'] );
		$this->assertFalse( $result['has_unlimited'] );
		$this->assertEmpty( $result['ticket_types'] );
	}

	/**
	 * Test get_occurrence_capacity can skip cache.
	 *
	 * @return void
	 */
	public function test_get_occurrence_capacity_skip_cache(): void {
		$this->calculator
			->method( 'get_occurrence_capacity' )
			->with( 99, true )
			->willReturn(
				array(
					'total_capacity'  => 50,
					'total_sold'      => 10,
					'total_available' => 40,
					'has_unlimited'   => false,
					'ticket_types'    => array(),
				)
			);

		$result = $this->service->get_occurrence_capacity( 99, true );

		$this->assertEquals( 50, $result['total_capacity'] );
	}

	// =========================================================================
	// Series pass capacity tests
	// =========================================================================

	/**
	 * Test get_series_pass_capacity returns defaults when not found.
	 *
	 * @return void
	 */
	public function test_get_series_pass_capacity_returns_defaults_when_not_found(): void {
		$this->calculator
			->method( 'get_series_pass_capacity' )
			->willReturn(
				array(
					'total'            => null,
					'per_occurrence'   => null,
					'occurrence_count' => 0,
				)
			);

		$result = $this->service->get_series_pass_capacity( 999 );

		$this->assertNull( $result['total'] );
		$this->assertNull( $result['per_occurrence'] );
		$this->assertEquals( 0, $result['occurrence_count'] );
	}

	/**
	 * Test get_series_pass_capacity for occurrence-scoped ticket.
	 *
	 * @return void
	 */
	public function test_get_series_pass_capacity_for_occurrence_scope(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'capacity' => 100,
				'scope'    => 'occurrence',
			)
		);

		$this->calculator
			->method( 'get_series_pass_capacity' )
			->with( $ticket_type->id )
			->willReturn(
				array(
					'total'            => 100,
					'per_occurrence'   => 100,
					'occurrence_count' => 1,
				)
			);

		$result = $this->service->get_series_pass_capacity( $ticket_type->id );

		$this->assertEquals( 100, $result['total'] );
		$this->assertEquals( 100, $result['per_occurrence'] );
		$this->assertEquals( 1, $result['occurrence_count'] );
	}

	/**
	 * Test get_series_pass_capacity for event-scoped ticket with occurrences.
	 *
	 * @return void
	 */
	public function test_get_series_pass_capacity_for_event_scope(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'       => 1,
				'capacity' => 50,
			)
		);

		$this->calculator
			->method( 'get_series_pass_capacity' )
			->with( $ticket_type->id )
			->willReturn(
				array(
					'total'            => 50,
					'per_occurrence'   => 50,
					'occurrence_count' => 3,
				)
			);

		$result = $this->service->get_series_pass_capacity( $ticket_type->id );

		$this->assertEquals( 50, $result['total'] );
		$this->assertEquals( 50, $result['per_occurrence'] );
		$this->assertEquals( 3, $result['occurrence_count'] );
	}

	/**
	 * Test get_series_pass_capacity for event-scoped ticket without event_id.
	 *
	 * @return void
	 */
	public function test_get_series_pass_capacity_without_event_id(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'capacity' => 50,
			)
		);

		$this->calculator
			->method( 'get_series_pass_capacity' )
			->with( $ticket_type->id )
			->willReturn(
				array(
					'total'            => 50,
					'per_occurrence'   => 50,
					'occurrence_count' => 0,
				)
			);

		$result = $this->service->get_series_pass_capacity( $ticket_type->id );

		$this->assertEquals( 50, $result['total'] );
		$this->assertEquals( 50, $result['per_occurrence'] );
		$this->assertEquals( 0, $result['occurrence_count'] );
	}

	/**
	 * Test series_pass_has_occurrence_availability returns false when not found.
	 *
	 * @return void
	 */
	public function test_series_pass_has_occurrence_availability_returns_false_when_not_found(): void {
		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( null );

		$result = $this->service->series_pass_has_occurrence_availability( 999, 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test series_pass_has_occurrence_availability delegates to has_availability.
	 *
	 * @return void
	 */
	public function test_series_pass_has_occurrence_availability_delegates(): void {
		$ticket_type = TicketTypeFactory::create( array( 'capacity' => 100, 'sold_count' => 50 ) );

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		$this->calculator
			->method( 'get_available_count' )
			->willReturn( 50 );

		$result = $this->service->series_pass_has_occurrence_availability( $ticket_type->id, 99, 10 );

		$this->assertTrue( $result );
	}
}
