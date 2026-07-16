<?php
/**
 * WooCommerce ticketing integration tests — capacity types.
 *
 * Exercises the attendee + ticket creation path and capacity tracking for
 * each of the four CapacityType values: fixed, unlimited, shared, seated.
 *
 * Follows the pattern established in WooCommerceOrderIntegrationTest.php:
 * real DB operations via repositories, MockWCOrder/MockWCOrderItemProduct for
 * WC context (no real WC product bootstrap required).
 *
 * @package NetterTechEvents\Tests\Integration\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Integrations\WooCommerce;

use NetterTechEvents\Database\Schema;
use NetterTechEvents\Enums\AttendeeStatus;
use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Models\Ticket;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\HouseCapacityRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\TicketRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\CapacityCalculator;
use NetterTechEvents\Services\ReservationManager;
use NetterTechEvents\Tests\Factories\AttendeeFactory;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;
use NetterTechEvents\Tests\Factories\WooCommerceFactory;
use NetterTechEvents\Repositories\EventRepository;

/**
 * Integration tests for the WooCommerce ticketing flow across all capacity types.
 */
class TicketingIntegrationTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * AttendeeRepository.
	 *
	 * @var AttendeeRepository
	 */
	private AttendeeRepository $attendee_repo;

	/**
	 * TicketTypeRepository.
	 *
	 * @var TicketTypeRepository
	 */
	private TicketTypeRepository $ticket_type_repo;

	/**
	 * TicketRepository.
	 *
	 * @var TicketRepository
	 */
	private TicketRepository $ticket_repo;

	/**
	 * OccurrenceRepository.
	 *
	 * @var OccurrenceRepository
	 */
	private OccurrenceRepository $occurrence_repo;

	/**
	 * EventRepository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $event_repo;

	/**
	 * Unique prefix to avoid slug/key collisions within the transaction.
	 *
	 * @var string
	 */
	private string $test_prefix;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$filter_repo           = new OccurrenceFilterRepository( $wpdb );
		$query_repo            = new OccurrenceQueryRepository( $wpdb, $filter_repo );
		$this->occurrence_repo = new OccurrenceRepository( $wpdb, $query_repo );
		$this->event_repo      = new EventRepository( $wpdb );
		$this->attendee_repo   = new AttendeeRepository( $wpdb );
		$this->ticket_type_repo = new TicketTypeRepository( $wpdb );
		$this->ticket_repo     = new TicketRepository( $wpdb );
		$this->test_prefix     = 'tkt-' . time() . '-' . mt_rand( 1000, 9999 ) . '-';
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Create and persist an event + occurrence, return the occurrence ID.
	 *
	 * @return int
	 */
	private function insert_occurrence(): int {
		$event       = EventFactory::create( array( 'slug' => $this->test_prefix . 'evt-' . mt_rand( 10, 99 ), 'status' => 'published' ) );
		$event->id   = null;
		$saved_event = $this->event_repo->save( $event );

		$occ           = OccurrenceFactory::create( array( 'event_id' => $saved_event->id ) );
		$occ->id       = null;
		$occ->event_id = $saved_event->id;
		$saved_occ     = $this->occurrence_repo->save( $occ );

		return $saved_occ->id;
	}

	/**
	 * Create and persist an event + occurrence with a specific total capacity.
	 *
	 * Shared capacity draws from occurrence.capacity so the occurrence must
	 * have a capacity set.
	 *
	 * @param int $occurrence_capacity Total capacity to set on the occurrence.
	 * @return int Occurrence ID.
	 */
	private function insert_occurrence_with_capacity( int $occurrence_capacity ): int {
		$event       = EventFactory::create( array( 'slug' => $this->test_prefix . 'evt-' . mt_rand( 10, 99 ), 'status' => 'published' ) );
		$event->id   = null;
		$saved_event = $this->event_repo->save( $event );

		$occ              = OccurrenceFactory::create( array( 'event_id' => $saved_event->id ) );
		$occ->id          = null;
		$occ->event_id    = $saved_event->id;
		$occ->capacity    = $occurrence_capacity;
		$saved_occ        = $this->occurrence_repo->save( $occ );

		return $saved_occ->id;
	}

	/**
	 * Create a ticket type attached to an occurrence.
	 *
	 * TicketTypeFactory does not map capacity_type, so we apply overrides that
	 * the factory ignores (capacity_type) as direct property assignments after
	 * factory creation to ensure they reach the repository and are persisted.
	 *
	 * @param int                  $occurrence_id Occurrence ID.
	 * @param array<string, mixed> $overrides     Property overrides.
	 * @return \NetterTechEvents\Models\TicketType
	 */
	private function insert_ticket_type( int $occurrence_id, array $overrides = array() ): \NetterTechEvents\Models\TicketType {
		$ticket_type = TicketTypeFactory::create( array_merge( array( 'occurrence_id' => $occurrence_id, 'sold_count' => 0 ), $overrides ) );
		$ticket_type->id = null;

		// Apply overrides that the factory does not map.
		foreach ( $overrides as $key => $value ) {
			if ( property_exists( $ticket_type, $key ) ) {
				$ticket_type->$key = $value;
			}
		}

		return $this->ticket_type_repo->save( $ticket_type );
	}

	/**
	 * Create and persist an attendee record linked to a mock WC order.
	 *
	 * @param int    $occurrence_id  Occurrence ID.
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param int    $order_id       WC order ID.
	 * @param int    $quantity       Ticket quantity.
	 * @return Attendee
	 */
	private function insert_attendee( int $occurrence_id, int $ticket_type_id, int $order_id, int $quantity = 1 ): Attendee {
		$attendee               = AttendeeFactory::create( array(
			'occurrence_id'  => $occurrence_id,
			'ticket_type_id' => $ticket_type_id,
			'wc_order_id'    => $order_id,
			'status'         => AttendeeStatus::CONFIRMED->value,
			'quantity'       => $quantity,
		) );
		$attendee->id           = null;
		return $this->attendee_repo->save( $attendee );
	}

	/**
	 * Create ticket records for an attendee (one per unit purchased).
	 *
	 * Mirrors what OrderAttendeeCreator::create_attendee_for_occurrence() does
	 * without the WC coupling.
	 *
	 * @param int $occurrence_id  Occurrence ID.
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $attendee_id    Attendee ID.
	 * @param int $order_id       WC order ID.
	 * @param int $quantity       Number of tickets to create.
	 * @return array<Ticket>
	 */
	private function insert_tickets( int $occurrence_id, int $ticket_type_id, int $attendee_id, int $order_id, int $quantity = 1 ): array {
		$tickets = array();

		for ( $i = 0; $i < $quantity; $i++ ) {
			$ticket                = new Ticket();
			$ticket->ticket_type_id = $ticket_type_id;
			$ticket->occurrence_id  = $occurrence_id;
			$ticket->attendee_id    = $attendee_id;
			$ticket->wc_order_id    = $order_id;
			$ticket->ticket_code    = 'TEST-' . strtoupper( bin2hex( random_bytes( 6 ) ) );
			$ticket->status         = 'confirmed';
			$ticket->price_paid     = 10.00;

			$tickets[] = $this->ticket_repo->save( $ticket );
		}

		return $tickets;
	}

	/**
	 * Increment sold_count on a ticket type (mirrors CapacityService::reserve_capacity).
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity sold.
	 * @return void
	 */
	private function increment_sold_count( int $ticket_type_id, int $quantity ): void {
		global $wpdb;
		$table = Schema::table( 'ticket_types' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from trusted Schema constant.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET sold_count = sold_count + %d WHERE id = %d",
				$quantity,
				$ticket_type_id
			)
		);
	}

	// =========================================================================
	// Test 1: Fixed capacity — sold_count increments, attendee + ticket created
	// =========================================================================

	/**
	 * Fixed capacity purchase creates attendee and ticket, decrements available capacity.
	 *
	 * @return void
	 */
	public function test_fixed_capacity_ticket_purchase_creates_attendee_and_ticket(): void {
		$occurrence_id  = $this->insert_occurrence();
		$ticket_type    = $this->insert_ticket_type(
			$occurrence_id,
			array(
				'capacity'      => 50,
				'capacity_type' => CapacityType::FIXED->value,
				'price'         => 10.00,
			)
		);
		$order          = WooCommerceFactory::create_order( array( 'id' => mt_rand( 20000, 29999 ) ) );
		$order_id       = $order->get_id();

		$attendee = $this->insert_attendee( $occurrence_id, $ticket_type->id, $order_id, 1 );
		$this->insert_tickets( $occurrence_id, $ticket_type->id, $attendee->id, $order_id, 1 );
		$this->increment_sold_count( $ticket_type->id, 1 );

		// Assert attendee row exists.
		$this->assertNotNull( $attendee->id );
		global $wpdb;
		$fresh_attendee = $this->attendee_repo->find( $attendee->id );
		$this->assertNotNull( $fresh_attendee );
		$this->assertSame( AttendeeStatus::CONFIRMED->value, $fresh_attendee->status );
		$this->assertSame( $order_id, $fresh_attendee->wc_order_id );

		// Assert ticket row exists.
		$tickets = $this->ticket_repo->find_by_attendee( $attendee->id );
		$this->assertCount( 1, $tickets );
		$this->assertSame( $ticket_type->id, $tickets[0]->ticket_type_id );
		$this->assertSame( 'confirmed', $tickets[0]->status );

		// Assert capacity decremented: available = capacity - sold_count = 50 - 1 = 49.
		$fresh_repo = new TicketTypeRepository( $wpdb );
		$updated    = $fresh_repo->find( $ticket_type->id );
		$this->assertNotNull( $updated );
		$this->assertSame( 1, $updated->sold_count );
		$this->assertSame( 49, $updated->get_available_count() );
	}

	// =========================================================================
	// Test 2: Unlimited capacity — attendee + ticket created, capacity not decremented
	// =========================================================================

	/**
	 * Unlimited capacity purchase creates attendee and ticket without decrementing capacity.
	 *
	 * @return void
	 */
	public function test_unlimited_capacity_ticket_purchase_does_not_decrement(): void {
		$occurrence_id  = $this->insert_occurrence();
		$ticket_type    = $this->insert_ticket_type(
			$occurrence_id,
			array(
				'capacity'      => null,
				'capacity_type' => CapacityType::UNLIMITED->value,
				'price'         => 15.00,
			)
		);
		$order          = WooCommerceFactory::create_order( array( 'id' => mt_rand( 30000, 39999 ) ) );
		$order_id       = $order->get_id();

		$attendee = $this->insert_attendee( $occurrence_id, $ticket_type->id, $order_id, 3 );
		$this->insert_tickets( $occurrence_id, $ticket_type->id, $attendee->id, $order_id, 3 );
		$this->increment_sold_count( $ticket_type->id, 3 );

		// Attendee created.
		$this->assertNotNull( $attendee->id );
		$fresh_attendee = $this->attendee_repo->find( $attendee->id );
		$this->assertNotNull( $fresh_attendee );
		$this->assertSame( 3, $fresh_attendee->quantity );

		// Ticket created (3 tickets for qty 3).
		$tickets = $this->ticket_repo->find_by_attendee( $attendee->id );
		$this->assertCount( 3, $tickets );

		// Available capacity is null (unlimited) regardless of sold_count.
		global $wpdb;
		$fresh_repo = new TicketTypeRepository( $wpdb );
		$updated    = $fresh_repo->find( $ticket_type->id );
		$this->assertNotNull( $updated );
		$this->assertSame( CapacityType::UNLIMITED->value, $updated->capacity_type );
		// get_available_count() returns null for unlimited.
		$this->assertNull( $updated->get_available_count() );
	}

	// =========================================================================
	// Test 3: Shared capacity pool — decrements correctly across multiple types
	// =========================================================================

	/**
	 * Shared capacity pool decrements correctly when sold across multiple ticket types.
	 *
	 * Shared capacity = occurrence.capacity - sum(fixed ticket capacities).
	 * Two shared ticket types on the same occurrence share a single pool.
	 * Purchasing from type A and type B both reduce the shared sold count.
	 *
	 * @return void
	 */
	public function test_shared_capacity_pool_decrements_correctly(): void {
		// Occurrence with capacity 100; no fixed types means shared_pool = 100.
		$occurrence_id  = $this->insert_occurrence_with_capacity( 100 );
		$ticket_type_a  = $this->insert_ticket_type(
			$occurrence_id,
			array(
				'name'          => 'Shared Type A',
				'capacity_type' => CapacityType::SHARED->value,
				'capacity'      => null,
				'price'         => 20.00,
			)
		);
		$ticket_type_b  = $this->insert_ticket_type(
			$occurrence_id,
			array(
				'name'          => 'Shared Type B',
				'capacity_type' => CapacityType::SHARED->value,
				'capacity'      => null,
				'price'         => 25.00,
			)
		);

		$order_id_a = mt_rand( 40000, 44999 );
		$order_id_b = mt_rand( 45000, 49999 );

		// Purchase 2 of type A.
		$attendee_a = $this->insert_attendee( $occurrence_id, $ticket_type_a->id, $order_id_a, 2 );
		$this->insert_tickets( $occurrence_id, $ticket_type_a->id, $attendee_a->id, $order_id_a, 2 );
		$this->increment_sold_count( $ticket_type_a->id, 2 );

		// Purchase 3 of type B.
		$attendee_b = $this->insert_attendee( $occurrence_id, $ticket_type_b->id, $order_id_b, 3 );
		$this->insert_tickets( $occurrence_id, $ticket_type_b->id, $attendee_b->id, $order_id_b, 3 );
		$this->increment_sold_count( $ticket_type_b->id, 3 );

		// Assert per-type sold counts.
		global $wpdb;
		$fresh_repo = new TicketTypeRepository( $wpdb );
		$type_a     = $fresh_repo->find( $ticket_type_a->id );
		$type_b     = $fresh_repo->find( $ticket_type_b->id );
		$this->assertNotNull( $type_a );
		$this->assertNotNull( $type_b );
		$this->assertSame( 2, $type_a->sold_count );
		$this->assertSame( 3, $type_b->sold_count );

		// Assert shared sold count = 5 (2 + 3).
		$shared_sold = $fresh_repo->get_shared_sold_count( $occurrence_id );
		$this->assertSame( 5, $shared_sold );

		// Both tiers sell into one house of 100; 5 issued leaves 95, and each tier
		// quotes the house remainder rather than a private pool of its own.
		$filter_repo = new OccurrenceFilterRepository( $wpdb );
		$query_repo  = new OccurrenceQueryRepository( $wpdb, $filter_repo );
		$occ_repo    = new OccurrenceRepository( $wpdb, $query_repo );
		$res_manager = new ReservationManager();
		$house_repo  = new HouseCapacityRepository( $wpdb );
		$calculator  = new CapacityCalculator( $fresh_repo, $occ_repo, $res_manager, $house_repo );

		$this->assertSame( 95, $calculator->get_available_count( $ticket_type_a->id ) );
		$this->assertSame( 95, $calculator->get_available_count( $ticket_type_b->id ) );

		// Assert total attendees created.
		$all_by_order_a = $this->attendee_repo->find_all_by_order( $order_id_a );
		$all_by_order_b = $this->attendee_repo->find_all_by_order( $order_id_b );
		$this->assertCount( 1, $all_by_order_a );
		$this->assertCount( 1, $all_by_order_b );

	}

	// =========================================================================
	// Test 4: Seated capacity — scaffold (Pro plugin required for full flow)
	// =========================================================================

	/**
	 * Seated capacity ticket type can be persisted without a fatal error.
	 *
	 * Full seat-selection flow (seating map, seat assignment) requires the
	 * nettertech-events-seating Pro add-on plugin. When Pro is absent, this
	 * test marks itself skipped after verifying the basic record can be saved.
	 *
	 * @return void
	 */
	public function test_seated_capacity_placeholder(): void {
		$occurrence_id = $this->insert_occurrence();

		// Seated ticket type can be saved without error — no fatal on insert.
		$ticket_type = $this->insert_ticket_type(
			$occurrence_id,
			array(
				'name'          => 'Seated Type',
				'capacity_type' => CapacityType::SEATED->value,
				'capacity'      => 200,
				'price'         => 50.00,
			)
		);

		$this->assertNotNull( $ticket_type->id );
		$this->assertGreaterThan( 0, $ticket_type->id );

		global $wpdb;
		$fresh_repo  = new TicketTypeRepository( $wpdb );
		$persisted   = $fresh_repo->find( $ticket_type->id );
		$this->assertNotNull( $persisted );
		$this->assertSame( CapacityType::SEATED->value, $persisted->capacity_type );

		$seating_active = is_plugin_active( 'nettertech-events-seating/nettertech-events-seating.php' );
		if ( ! $seating_active ) {
			$this->markTestSkipped( 'Seated capacity full flow requires the nettertech-events-seating Pro plugin.' );
		}
	}
}
