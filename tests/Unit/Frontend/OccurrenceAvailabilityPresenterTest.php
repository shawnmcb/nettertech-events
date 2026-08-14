<?php
/**
 * OccurrenceAvailabilityPresenter unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Frontend\OccurrenceAvailabilityPresenter;
use NetterTechEvents\Models\TicketType;
use Mockery;

/**
 * Test OccurrenceAvailabilityPresenter (NTE-203 sold-out badge predicate).
 *
 * The predicate must route through get_capacity_summary() — the path whose
 * effective availability matches the till — and must never read sold out
 * from absence of ticket types (NTE-009 bug class) or from closed sale
 * windows ("sales closed" is not "sold out").
 *
 * @coversDefaultClass \NetterTechEvents\Frontend\OccurrenceAvailabilityPresenter
 */
class OccurrenceAvailabilityPresenterTest extends \NetterTechEventsTestCase {

	/**
	 * Mock capacity service.
	 *
	 * @var CapacityServiceInterface|Mockery\MockInterface
	 */
	private $capacity_service;

	/**
	 * Mock ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface|Mockery\MockInterface
	 */
	private $ticket_type_repo;

	/**
	 * Presenter under test.
	 *
	 * @var OccurrenceAvailabilityPresenter
	 */
	private OccurrenceAvailabilityPresenter $presenter;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->capacity_service = Mockery::mock( CapacityServiceInterface::class );
		$this->ticket_type_repo = Mockery::mock( TicketTypeRepositoryInterface::class );
		$this->presenter        = new OccurrenceAvailabilityPresenter( $this->capacity_service, $this->ticket_type_repo );
	}

	/**
	 * Build a ticket type with an ID.
	 *
	 * @param int $id Ticket type ID.
	 * @return TicketType
	 */
	private function make_ticket_type( int $id ): TicketType {
		$ticket_type     = new TicketType();
		$ticket_type->id = $id;

		return $ticket_type;
	}

	/**
	 * Stub a capacity summary verdict for one ticket type.
	 *
	 * @param int  $id       Ticket type ID.
	 * @param bool $sold_out The is_sold_out verdict.
	 */
	private function stub_summary( int $id, bool $sold_out ): void {
		$this->capacity_service
			->shouldReceive( 'get_capacity_summary' )
			->with( $id )
			->andReturn( array( 'is_sold_out' => $sold_out ) );
	}

	/**
	 * No on-sale ticket types is never sold out (unticketed event, or all
	 * sale windows closed — NTE-009 bug class).
	 *
	 * @covers ::is_sold_out
	 */
	public function test_empty_ticket_types_is_not_sold_out(): void {
		$this->capacity_service->shouldNotReceive( 'get_capacity_summary' );

		$this->assertFalse( $this->presenter->is_sold_out( array() ) );
	}

	/**
	 * Every saleable type exhausted means sold out.
	 *
	 * @covers ::is_sold_out
	 */
	public function test_all_types_sold_out_is_sold_out(): void {
		$this->stub_summary( 1, true );
		$this->stub_summary( 2, true );

		$this->assertTrue(
			$this->presenter->is_sold_out(
				array( $this->make_ticket_type( 1 ), $this->make_ticket_type( 2 ) )
			)
		);
	}

	/**
	 * One buyable type means not sold out, regardless of exhausted siblings.
	 *
	 * @covers ::is_sold_out
	 */
	public function test_one_available_type_is_not_sold_out(): void {
		$this->stub_summary( 1, true );
		$this->stub_summary( 2, false );

		$this->assertFalse(
			$this->presenter->is_sold_out(
				array( $this->make_ticket_type( 1 ), $this->make_ticket_type( 2 ) )
			)
		);
	}

	/**
	 * Types without an ID (unsaved rows) are skipped and alone never read
	 * as sold out.
	 *
	 * @covers ::is_sold_out
	 */
	public function test_only_idless_types_is_not_sold_out(): void {
		$this->capacity_service->shouldNotReceive( 'get_capacity_summary' );

		$this->assertFalse( $this->presenter->is_sold_out( array( new TicketType() ) ) );
	}

	/**
	 * The occurrence convenience path resolves on-sale types — not all
	 * types — so closed sale windows cannot masquerade as sold out.
	 *
	 * @covers ::is_occurrence_sold_out
	 */
	public function test_occurrence_path_resolves_on_sale_types(): void {
		$this->ticket_type_repo
			->shouldReceive( 'get_on_sale_for_occurrence' )
			->once()
			->with( 42 )
			->andReturn( array( $this->make_ticket_type( 7 ) ) );
		$this->stub_summary( 7, true );

		$this->assertTrue( $this->presenter->is_occurrence_sold_out( 42 ) );
	}

	/**
	 * A non-positive occurrence ID never queries and never reads sold out.
	 *
	 * @covers ::is_occurrence_sold_out
	 */
	public function test_invalid_occurrence_id_is_not_sold_out(): void {
		$this->ticket_type_repo->shouldNotReceive( 'get_on_sale_for_occurrence' );

		$this->assertFalse( $this->presenter->is_occurrence_sold_out( 0 ) );
	}
}
