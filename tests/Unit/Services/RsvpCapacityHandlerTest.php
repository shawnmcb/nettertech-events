<?php
/**
 * RsvpCapacityHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Services\RsvpCapacityHandler;

/**
 * Test RsvpCapacityHandler class.
 *
 * @coversDefaultClass \NetterTechEvents\Services\RsvpCapacityHandler
 */
class RsvpCapacityHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock capacity service.
	 *
	 * @var CapacityServiceInterface|Mockery\MockInterface
	 */
	private $capacity_service;

	/**
	 * Mock ticket-type repo.
	 *
	 * @var TicketTypeRepositoryInterface|Mockery\MockInterface
	 */
	private $ticket_type_repo;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'add_action' )->justReturn( true );

		$this->capacity_service = Mockery::mock( CapacityServiceInterface::class );
		$this->ticket_type_repo = Mockery::mock( TicketTypeRepositoryInterface::class );
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Build a handler.
	 *
	 * @return RsvpCapacityHandler
	 */
	private function build_handler(): RsvpCapacityHandler {
		return new RsvpCapacityHandler( $this->capacity_service, $this->ticket_type_repo );
	}

	/**
	 * Test register registers the hook callback.
	 *
	 * @return void
	 */
	public function test_register_does_not_throw(): void {
		$this->build_handler()->register();
		$this->assertTrue( true );
	}

	/**
	 * Test handler returns early when occurrence_id is zero.
	 *
	 * @return void
	 */
	public function test_handle_returns_when_occurrence_id_invalid(): void {
		$this->capacity_service->shouldNotReceive( 'reserve_capacity' );

		$this->build_handler()->handle_rsvp_submitted( 1, array( 'occurrence_id' => 0 ) );
		$this->assertTrue( true );
	}

	/**
	 * Test handler returns early when quantity is zero.
	 *
	 * @return void
	 */
	public function test_handle_returns_when_quantity_zero(): void {
		$this->capacity_service->shouldNotReceive( 'reserve_capacity' );

		$this->build_handler()->handle_rsvp_submitted(
			1,
			array(
				'occurrence_id' => 7,
				'quantity'      => 0,
			)
		);
		$this->assertTrue( true );
	}

	/**
	 * Test handler increments capacity for explicit free ticket type.
	 *
	 * @return void
	 */
	public function test_handle_reserves_capacity_for_explicit_free_ticket(): void {
		$tt           = Mockery::mock( TicketType::class );
		$tt->id       = 10;
		$tt->capacity = 50;
		$tt->shouldReceive( 'is_free' )->andReturn( true );

		$this->ticket_type_repo->shouldReceive( 'find' )->with( 10 )->andReturn( $tt );

		$this->capacity_service->shouldReceive( 'reserve_capacity' )
			->with( 10, 2 )
			->once();

		$this->build_handler()->handle_rsvp_submitted(
			1,
			array(
				'occurrence_id'  => 7,
				'ticket_type_id' => 10,
				'quantity'       => 2,
			)
		);
	}

	/**
	 * Test handler skips when explicit ticket type is paid.
	 *
	 * @return void
	 */
	public function test_handle_skips_when_explicit_ticket_is_paid(): void {
		$tt = Mockery::mock( TicketType::class );
		$tt->shouldReceive( 'is_free' )->andReturn( false );

		$this->ticket_type_repo->shouldReceive( 'find' )->with( 10 )->andReturn( $tt );

		$this->capacity_service->shouldNotReceive( 'reserve_capacity' );

		$this->build_handler()->handle_rsvp_submitted(
			1,
			array(
				'occurrence_id'  => 7,
				'ticket_type_id' => 10,
				'quantity'       => 1,
			)
		);
	}

	/**
	 * Test handler skips when explicit free ticket has null capacity (unlimited).
	 *
	 * @return void
	 */
	public function test_handle_skips_when_capacity_is_null(): void {
		$tt           = Mockery::mock( TicketType::class );
		$tt->id       = 10;
		$tt->capacity = null;
		$tt->shouldReceive( 'is_free' )->andReturn( true );

		$this->ticket_type_repo->shouldReceive( 'find' )->with( 10 )->andReturn( $tt );

		$this->capacity_service->shouldNotReceive( 'reserve_capacity' );

		$this->build_handler()->handle_rsvp_submitted(
			1,
			array(
				'occurrence_id'  => 7,
				'ticket_type_id' => 10,
				'quantity'       => 1,
			)
		);
	}

	/**
	 * Test handler resolves free ticket type from occurrence when no explicit id.
	 *
	 * @return void
	 */
	public function test_handle_falls_back_to_occurrence_free_ticket(): void {
		$paid           = Mockery::mock( TicketType::class );
		$paid->shouldReceive( 'is_free' )->andReturn( false );

		$free           = Mockery::mock( TicketType::class );
		$free->id       = 22;
		$free->capacity = 100;
		$free->shouldReceive( 'is_free' )->andReturn( true );

		$this->ticket_type_repo->shouldReceive( 'for_occurrence' )
			->with( 7 )
			->andReturn( array( $paid, $free ) );

		$this->capacity_service->shouldReceive( 'reserve_capacity' )
			->with( 22, 3 )
			->once();

		$this->build_handler()->handle_rsvp_submitted(
			1,
			array(
				'occurrence_id' => 7,
				'quantity'      => 3,
			)
		);
	}

	/**
	 * Test handler is no-op when no constrained free ticket exists.
	 *
	 * @return void
	 */
	public function test_handle_noop_when_no_constrained_free_ticket(): void {
		$this->ticket_type_repo->shouldReceive( 'for_occurrence' )->andReturn( array() );

		$this->capacity_service->shouldNotReceive( 'reserve_capacity' );

		$this->build_handler()->handle_rsvp_submitted(
			1,
			array(
				'occurrence_id' => 7,
				'quantity'      => 1,
			)
		);
	}

	/**
	 * Test handler swallows exceptions from capacity service.
	 *
	 * @return void
	 */
	public function test_handle_swallows_exceptions(): void {
		$tt           = Mockery::mock( TicketType::class );
		$tt->id       = 10;
		$tt->capacity = 5;
		$tt->shouldReceive( 'is_free' )->andReturn( true );

		$this->ticket_type_repo->shouldReceive( 'find' )->with( 10 )->andReturn( $tt );

		$this->capacity_service->shouldReceive( 'reserve_capacity' )
			->andThrow( new \RuntimeException( 'no go' ) );

		// Should not propagate.
		$this->build_handler()->handle_rsvp_submitted(
			1,
			array(
				'occurrence_id'  => 7,
				'ticket_type_id' => 10,
				'quantity'       => 1,
			)
		);

		$this->assertTrue( true );
	}
}
