<?php
/**
 * TicketStatusSyncService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Mockery;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Services\TicketStatusSyncService;

/**
 * Test TicketStatusSyncService behaviour on event status transitions.
 */
class TicketStatusSyncServiceTest extends \NetterTechEventsTestCase {

	/**
	 * Ticket type repository mock.
	 *
	 * @var TicketTypeRepositoryInterface|Mockery\MockInterface
	 */
	private $ticket_repo;

	/**
	 * Occurrence repository mock.
	 *
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * Product manager mock.
	 *
	 * @var ProductManager|Mockery\MockInterface
	 */
	private $product_manager;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->ticket_repo     = Mockery::mock( TicketTypeRepositoryInterface::class );
		$this->occurrence_repo = Mockery::mock( OccurrenceRepositoryInterface::class );
		$this->product_manager = Mockery::mock( ProductManager::class );
	}

	/**
	 * Build a ticket type with an id and status.
	 *
	 * @param int    $id     Ticket type ID.
	 * @param string $status Status.
	 * @return TicketType
	 */
	private function ticket( int $id, string $status ): TicketType {
		$ticket         = new TicketType();
		$ticket->id     = $id;
		$ticket->status = $status;
		return $ticket;
	}

	/**
	 * Build an occurrence with an id.
	 *
	 * @param int $id Occurrence ID.
	 * @return Occurrence
	 */
	private function occurrence( int $id ): Occurrence {
		$occurrence     = new Occurrence();
		$occurrence->id = $id;
		return $occurrence;
	}

	/**
	 * Publishing an event activates its draft tickets and re-syncs products.
	 *
	 * @return void
	 */
	public function test_publish_activates_tickets_and_resyncs_products(): void {
		$this->occurrence_repo->shouldReceive( 'for_event' )->with( 55 )->andReturn( array( $this->occurrence( 10 ) ) );

		$event_tier      = $this->ticket( 1, 'draft' );
		$occurrence_tier = $this->ticket( 2, 'draft' );
		$this->ticket_repo->shouldReceive( 'for_event' )->with( 55 )->andReturn( array( $event_tier ) );
		$this->ticket_repo->shouldReceive( 'for_occurrence' )->with( 10, array( 'status' => null ) )->andReturn( array( $occurrence_tier ) );

		$saved = array();
		$this->ticket_repo->shouldReceive( 'save' )->twice()->andReturnUsing(
			function ( TicketType $ticket ) use ( &$saved ) {
				$saved[ (int) $ticket->id ] = $ticket->status;
				return $ticket;
			}
		);

		$this->product_manager->shouldReceive( 'create_products_for_event' )->once()->with( 55, array(), false );
		$this->product_manager->shouldReceive( 'create_products_for_occurrence' )->once()->with( 10, true, array(), false );

		$service = new TicketStatusSyncService( $this->ticket_repo, $this->occurrence_repo, $this->product_manager );
		$service->on_event_published( 55 );

		$this->assertSame( array( 1 => 'active', 2 => 'active' ), $saved );
	}

	/**
	 * Publishing only lifts draft tiers: a deliberately-parked status (e.g. a withdrawn
	 * or not-yet-current Pro sale tier held at 'inactive') must not be resurrected.
	 *
	 * @return void
	 */
	public function test_publish_leaves_non_draft_statuses_untouched(): void {
		$this->occurrence_repo->shouldReceive( 'for_event' )->with( 55 )->andReturn( array( $this->occurrence( 10 ) ) );

		$draft_tier    = $this->ticket( 1, 'draft' );
		$inactive_tier = $this->ticket( 2, 'inactive' );
		$this->ticket_repo->shouldReceive( 'for_event' )->with( 55 )->andReturn( array( $draft_tier ) );
		$this->ticket_repo->shouldReceive( 'for_occurrence' )->with( 10, array( 'status' => null ) )->andReturn( array( $inactive_tier ) );

		$saved = array();
		$this->ticket_repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( TicketType $ticket ) use ( &$saved ) {
				$saved[ (int) $ticket->id ] = $ticket->status;
				return $ticket;
			}
		);

		$this->product_manager->shouldReceive( 'create_products_for_event' )->once()->with( 55, array(), false );
		$this->product_manager->shouldReceive( 'create_products_for_occurrence' )->once()->with( 10, true, array(), false );

		$service = new TicketStatusSyncService( $this->ticket_repo, $this->occurrence_repo, $this->product_manager );
		$service->on_event_published( 55 );

		$this->assertSame( array( 1 => 'active' ), $saved, 'Only the draft tier activates; the inactive tier stays parked.' );
		$this->assertSame( 'inactive', $inactive_tier->status );
	}

	/**
	 * Un-publishing an event drafts its active tickets and re-syncs products.
	 *
	 * @return void
	 */
	public function test_unpublish_drafts_tickets_and_resyncs_products(): void {
		$this->occurrence_repo->shouldReceive( 'for_event' )->with( 55 )->andReturn( array( $this->occurrence( 10 ) ) );

		$this->ticket_repo->shouldReceive( 'for_event' )->with( 55 )->andReturn( array( $this->ticket( 1, 'active' ) ) );
		$this->ticket_repo->shouldReceive( 'for_occurrence' )->with( 10, array( 'status' => null ) )->andReturn( array( $this->ticket( 2, 'active' ) ) );

		$saved = array();
		$this->ticket_repo->shouldReceive( 'save' )->twice()->andReturnUsing(
			function ( TicketType $ticket ) use ( &$saved ) {
				$saved[ (int) $ticket->id ] = $ticket->status;
				return $ticket;
			}
		);

		// R5: an unpublish reverts existing products only — existing_only = true, never mint.
		$this->product_manager->shouldReceive( 'create_products_for_event' )->once()->with( 55, array(), true );
		$this->product_manager->shouldReceive( 'create_products_for_occurrence' )->once()->with( 10, true, array(), true );

		$service = new TicketStatusSyncService( $this->ticket_repo, $this->occurrence_repo, $this->product_manager );
		$service->on_event_unpublished( 55 );

		$this->assertSame( array( 1 => 'draft', 2 => 'draft' ), $saved );
	}

	/**
	 * Re-firing a transition already applied writes no ticket rows (idempotent).
	 *
	 * @return void
	 */
	public function test_idempotent_when_status_already_matches(): void {
		$this->occurrence_repo->shouldReceive( 'for_event' )->with( 55 )->andReturn( array( $this->occurrence( 10 ) ) );

		// Already active — publishing again must not re-save.
		$this->ticket_repo->shouldReceive( 'for_event' )->with( 55 )->andReturn( array( $this->ticket( 1, 'active' ) ) );
		$this->ticket_repo->shouldReceive( 'for_occurrence' )->with( 10, array( 'status' => null ) )->andReturn( array( $this->ticket( 2, 'active' ) ) );

		$this->ticket_repo->shouldNotReceive( 'save' );

		// Product re-sync is safe to re-run and still happens.
		$this->product_manager->shouldReceive( 'create_products_for_event' )->once()->with( 55, array(), false );
		$this->product_manager->shouldReceive( 'create_products_for_occurrence' )->once()->with( 10, true, array(), false );

		$service = new TicketStatusSyncService( $this->ticket_repo, $this->occurrence_repo, $this->product_manager );
		$service->on_event_published( 55 );

		$this->assertTrue( true );
	}

	/**
	 * A tier appearing under both for_event and for_occurrence is synced once.
	 *
	 * @return void
	 */
	public function test_overlapping_tiers_are_deduplicated(): void {
		$this->occurrence_repo->shouldReceive( 'for_event' )->with( 55 )->andReturn( array( $this->occurrence( 10 ) ) );

		// Same id 2 in both lists.
		$this->ticket_repo->shouldReceive( 'for_event' )->with( 55 )->andReturn( array( $this->ticket( 2, 'draft' ) ) );
		$this->ticket_repo->shouldReceive( 'for_occurrence' )->with( 10, array( 'status' => null ) )->andReturn( array( $this->ticket( 2, 'draft' ) ) );

		$this->ticket_repo->shouldReceive( 'save' )->once();

		$this->product_manager->shouldReceive( 'create_products_for_event' )->once();
		$this->product_manager->shouldReceive( 'create_products_for_occurrence' )->once();

		$service = new TicketStatusSyncService( $this->ticket_repo, $this->occurrence_repo, $this->product_manager );
		$service->on_event_published( 55 );

		$this->assertTrue( true );
	}

	/**
	 * With WooCommerce absent (no product manager) ticket statuses still sync.
	 *
	 * @return void
	 */
	public function test_syncs_tickets_without_product_manager(): void {
		$this->occurrence_repo->shouldReceive( 'for_event' )->with( 55 )->andReturn( array() );
		$this->ticket_repo->shouldReceive( 'for_event' )->with( 55 )->andReturn( array( $this->ticket( 1, 'active' ) ) );

		$saved = array();
		$this->ticket_repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( TicketType $ticket ) use ( &$saved ) {
				$saved[ (int) $ticket->id ] = $ticket->status;
				return $ticket;
			}
		);

		$service = new TicketStatusSyncService( $this->ticket_repo, $this->occurrence_repo, null );
		$service->on_event_unpublished( 55 );

		$this->assertSame( array( 1 => 'draft' ), $saved );
	}

	/**
	 * A non-positive event ID is ignored entirely (no repository work at all).
	 *
	 * Guards the `<= 0` boundary against a `< 0` mutation: event_id 0 must still bail.
	 *
	 * @return void
	 */
	public function test_sync_bails_on_nonpositive_event_id(): void {
		$this->occurrence_repo->shouldNotReceive( 'for_event' );
		$this->ticket_repo->shouldNotReceive( 'for_event' );
		$this->ticket_repo->shouldNotReceive( 'save' );
		$this->product_manager->shouldNotReceive( 'create_products_for_event' );

		$service = new TicketStatusSyncService( $this->ticket_repo, $this->occurrence_repo, $this->product_manager );
		$service->on_event_published( 0 );

		$this->assertTrue( true );
	}
}
