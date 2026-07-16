<?php
/**
 * Series pass integration test.
 *
 * Exercises the "buy once for entire recurring run" path through
 * OrderAttendeeCreator::process(): a single WC order item flagged as a
 * series pass produces one attendee per occurrence of the event.
 *
 * @package NetterTechEvents\Tests\Integration\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Integrations\WooCommerce;

use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Enums\AttendeeStatus;
use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Integrations\WooCommerce\OrderAttendeeCreator;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\TicketRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\TicketCodeGenerator;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;

/**
 * Integration test: series pass (scope=event) fan-out across occurrences.
 *
 * Row 7 evidence for wporg-submission-evidence.md.
 */
class SeriesPassIntegrationTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepository
	 */
	private OccurrenceRepository $occurrence_repo;

	/**
	 * Event repository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $event_repo;

	/**
	 * Attendee repository.
	 *
	 * @var AttendeeRepository
	 */
	private AttendeeRepository $attendee_repo;

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepository
	 */
	private TicketTypeRepository $ticket_type_repo;

	/**
	 * Ticket repository.
	 *
	 * @var TicketRepository
	 */
	private TicketRepository $ticket_repo;

	/**
	 * Unique slug prefix for this test run.
	 *
	 * @var string
	 */
	private string $test_prefix;

	/**
	 * Set up fresh repositories per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$filter_repo            = new OccurrenceFilterRepository( $wpdb );
		$query_repo             = new OccurrenceQueryRepository( $wpdb, $filter_repo );
		$this->occurrence_repo  = new OccurrenceRepository( $wpdb, $query_repo );
		$this->event_repo       = new EventRepository( $wpdb );
		$this->attendee_repo    = new AttendeeRepository( $wpdb );
		$this->ticket_type_repo = new TicketTypeRepository( $wpdb );
		$this->ticket_repo      = new TicketRepository( $wpdb );
		$this->test_prefix      = 'series-' . time() . '-' . mt_rand( 1000, 9999 ) . '-';
	}

	/**
	 * Create an event with a given number of occurrences.
	 *
	 * @param int $count Number of occurrences to create.
	 * @return array{event_id: int, occurrence_ids: array<int>}
	 */
	private function create_event_with_occurrences( int $count ): array {
		$event     = EventFactory::create(
			array(
				'slug'   => $this->test_prefix . 'evt-' . mt_rand( 10, 99 ),
				'status' => 'published',
			)
		);
		$event->id = null;
		$saved_event = $this->event_repo->save( $event );

		$occurrence_ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$occ           = OccurrenceFactory::create( array( 'event_id' => $saved_event->id ) );
			$occ->id       = null;
			$occ->event_id = $saved_event->id;
			// Space occurrences a week apart to avoid any horizon filter edge cases.
			$occ->start_datetime = gmdate( 'Y-m-d H:i:s', strtotime( '+' . ( $i + 1 ) . ' weeks' ) );
			$occ->end_datetime   = gmdate( 'Y-m-d H:i:s', strtotime( '+' . ( $i + 1 ) . ' weeks +2 hours' ) );
			$saved_occ           = $this->occurrence_repo->save( $occ );
			$occurrence_ids[]    = $saved_occ->id;
		}

		return array(
			'event_id'       => $saved_event->id,
			'occurrence_ids' => $occurrence_ids,
		);
	}

	/**
	 * Insert a series-pass ticket type (scope=event) attached to an event.
	 *
	 * Event-scoped ticket types require event_id set and occurrence_id null —
	 * enforced at TicketType::validate(). OrderAttendeeCreator reads event_id
	 * from the order item meta to fan out across occurrences.
	 *
	 * @param int $event_id Parent event.
	 * @return \NetterTechEvents\Models\TicketType
	 */
	private function insert_series_pass_ticket_type( int $event_id ): \NetterTechEvents\Models\TicketType {
		$ticket_type = TicketTypeFactory::create(
			array(
				'name'       => 'Full Series Pass',
				'price'      => 60.00,
				'capacity'   => 100,
				'sold_count' => 0,
			)
		);
		$ticket_type->id            = null;
		$ticket_type->occurrence_id = null;
		$ticket_type->event_id      = $event_id;
		$ticket_type->scope         = TicketTypeScope::EVENT->value;
		$ticket_type->capacity_type = CapacityType::FIXED->value;

		return $this->ticket_type_repo->save( $ticket_type );
	}

	/**
	 * Build a mocked WC_Order_Item_Product configured as a series pass.
	 *
	 * @param int $item_id        Order item ID.
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $event_id       Event ID.
	 * @return \PHPUnit\Framework\MockObject\MockObject&\WC_Order_Item_Product
	 */
	private function build_series_pass_item( int $item_id, int $ticket_type_id, int $event_id ) {
		$item = $this->createMock( \WC_Order_Item_Product::class );

		$item->method( 'get_id' )->willReturn( $item_id );
		$item->method( 'get_product_id' )->willReturn( 4242 );
		$item->method( 'get_quantity' )->willReturn( 1 );
		$item->method( 'get_total' )->willReturn( '60.00' );

		$meta_map = array(
			MetaKeys::TICKET_TYPE_ID => $ticket_type_id,
			MetaKeys::EVENT_ID       => $event_id,
			MetaKeys::IS_SERIES_PASS => 'yes',
		);
		$item->method( 'get_meta' )->willReturnCallback(
			static function ( string $key, bool $single = true ) use ( $meta_map ) {
				return $meta_map[ $key ] ?? ( $single ? '' : array() );
			}
		);

		return $item;
	}

	/**
	 * Build a mocked WC_Order carrying the series pass item.
	 *
	 * @param int                                                                           $order_id Order ID.
	 * @param \PHPUnit\Framework\MockObject\MockObject&\WC_Order_Item_Product $item     Series pass item.
	 * @return \PHPUnit\Framework\MockObject\MockObject&\WC_Order
	 */
	private function build_order( int $order_id, $item ) {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( $order_id );
		$order->method( 'get_items' )->willReturn( array( $item->get_id() => $item ) );
		$order->method( 'get_billing_first_name' )->willReturn( 'Series' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Buyer' );
		$order->method( 'get_billing_email' )->willReturn( 'series@example.test' );
		$order->method( 'get_billing_phone' )->willReturn( '' );
		$order->method( 'get_meta' )->willReturn( '' );

		return $order;
	}

	/**
	 * Build an OrderAttendeeCreator wired with real repositories and minimal mocks.
	 *
	 * @return OrderAttendeeCreator
	 */
	private function build_creator(): OrderAttendeeCreator {
		$product_manager = $this->createMock( ProductManager::class );
		$product_manager->method( 'is_event_ticket' )->willReturn( true );

		$code_generator = $this->createMock( TicketCodeGenerator::class );
		$code_generator->method( 'generate' )->willReturnCallback(
			static function (): string {
				return 'SP-' . strtoupper( bin2hex( random_bytes( 4 ) ) );
			}
		);

		return new OrderAttendeeCreator(
			$product_manager,
			$this->attendee_repo,
			$this->attendee_repo,
			$this->ticket_repo,
			$code_generator,
			$this->occurrence_repo,
			null
		);
	}

	/**
	 * A series pass order fans out into one attendee per occurrence.
	 *
	 * Buy once, attend all three nights: the core promise of a series pass.
	 *
	 * @return void
	 */
	public function test_series_pass_creates_one_attendee_per_occurrence(): void {
		$fixture         = $this->create_event_with_occurrences( 3 );
		$ticket_type     = $this->insert_series_pass_ticket_type( $fixture['event_id'] );
		$order_id        = mt_rand( 70000, 79999 );
		$order_item_id   = mt_rand( 80000, 89999 );

		$item    = $this->build_series_pass_item( $order_item_id, $ticket_type->id, $fixture['event_id'] );
		$order   = $this->build_order( $order_id, $item );
		$creator = $this->build_creator();

		$creator->process( $order );

		// Fresh repo bypasses identity map to verify DB state.
		global $wpdb;
		$fresh_repo = new AttendeeRepository( $wpdb );
		$attendees  = $fresh_repo->find_all_by_order( $order_id );

		$this->assertCount(
			3,
			$attendees,
			'Series pass must produce one attendee per occurrence in the event.'
		);

		// Each attendee attached to a distinct occurrence in the set.
		$attendee_occurrence_ids = array_map(
			static fn( $a ) => (int) $a->occurrence_id,
			$attendees
		);
		sort( $attendee_occurrence_ids );
		$expected_occurrence_ids = $fixture['occurrence_ids'];
		sort( $expected_occurrence_ids );
		$this->assertSame(
			$expected_occurrence_ids,
			$attendee_occurrence_ids,
			'Series pass attendees must cover every occurrence, no duplicates or gaps.'
		);

		// Every attendee carries the same order, same billing, confirmed status.
		foreach ( $attendees as $attendee ) {
			$this->assertSame( $order_id, (int) $attendee->wc_order_id );
			$this->assertSame( 'series@example.test', $attendee->email );
			$this->assertSame( AttendeeStatus::CONFIRMED->value, $attendee->status );
			$this->assertSame( $ticket_type->id, (int) $attendee->ticket_type_id );
		}
	}

	/**
	 * A series pass creates a ticket record per occurrence, priced evenly.
	 *
	 * One pass at $60 across 3 occurrences = $20 per ticket. This prevents
	 * accounting drift between order total and sum of ticket prices.
	 *
	 * @return void
	 */
	public function test_series_pass_splits_price_across_occurrence_tickets(): void {
		$fixture       = $this->create_event_with_occurrences( 3 );
		$ticket_type   = $this->insert_series_pass_ticket_type( $fixture['event_id'] );
		$order_id      = mt_rand( 90000, 99999 );
		$order_item_id = mt_rand( 100000, 109999 );

		$item    = $this->build_series_pass_item( $order_item_id, $ticket_type->id, $fixture['event_id'] );
		$order   = $this->build_order( $order_id, $item );
		$creator = $this->build_creator();

		$creator->process( $order );

		global $wpdb;
		$fresh_repo = new AttendeeRepository( $wpdb );
		$attendees  = $fresh_repo->find_all_by_order( $order_id );

		$fresh_tickets = new TicketRepository( $wpdb );
		$total_paid    = 0.0;
		$ticket_count  = 0;

		foreach ( $attendees as $attendee ) {
			$tickets = $fresh_tickets->find_by_attendee( $attendee->id );
			$this->assertCount(
				1,
				$tickets,
				'Each series-pass attendee gets exactly one ticket (quantity=1 on the order item).'
			);
			foreach ( $tickets as $ticket ) {
				$this->assertSame( $order_id, (int) $ticket->wc_order_id );
				$total_paid += (float) $ticket->price_paid;
				++$ticket_count;
			}
		}

		$this->assertSame( 3, $ticket_count, 'One ticket per occurrence.' );
		// 60.00 split across 3 occurrences is 20.00 each; tolerate 1 cent drift.
		$this->assertEqualsWithDelta(
			60.00,
			$total_paid,
			0.01,
			'Sum of ticket prices must equal the order item total.'
		);
	}
}
