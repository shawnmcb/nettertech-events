<?php
/**
 * WooCommerce order lifecycle integration test.
 *
 * Exercises the order-driven attendee lifecycle against the real database
 * using the MockWCOrder/MockWCOrderItemProduct objects from WooCommerceFactory
 * (no real WooCommerce bootstrap required).
 *
 * Lifecycle stages covered:
 *   1. Order created  -> Attendee inserted with status 'confirmed'
 *   2. Order complete -> sold_count incremented on ticket type
 *   3. Refund issued  -> Attendee status set to 'cancelled', sold_count decremented
 *
 * @package NetterTechEvents\Tests\Integration
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration;

use NetterTechEvents\Database\Schema;
use NetterTechEvents\Enums\AttendeeStatus;
use NetterTechEvents\Integrations\WooCommerce\OrderHandler;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\TicketRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\AttendeeFieldService;
use NetterTechEvents\Services\CapacityService;
use NetterTechEvents\Services\TicketCodeGenerator;
use NetterTechEvents\Tests\Factories\AttendeeFactory;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;
use NetterTechEvents\Tests\Factories\WooCommerceFactory;

/**
 * Integration test: WooCommerce order lifecycle via real-DB attendee/ticket-type operations.
 */
class WooCommerceOrderIntegrationTest extends \NetterTechEventsIntegrationTestCase {

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
	 * EventRepository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $event_repo;

	/**
	 * OccurrenceRepository.
	 *
	 * @var OccurrenceRepository
	 */
	private OccurrenceRepository $occurrence_repo;

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

		$filter_repo            = new OccurrenceFilterRepository( $wpdb );
		$query_repo             = new OccurrenceQueryRepository( $wpdb, $filter_repo );
		$this->occurrence_repo  = new OccurrenceRepository( $wpdb, $query_repo );
		$this->event_repo       = new EventRepository( $wpdb );
		$this->attendee_repo    = new AttendeeRepository( $wpdb );
		$this->ticket_type_repo = new TicketTypeRepository( $wpdb );
		$this->test_prefix      = 'wc-' . time() . '-' . mt_rand( 1000, 9999 ) . '-';
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Unique slug for this test run.
	 *
	 * @param string $suffix Suffix.
	 * @return string
	 */
	private function slug( string $suffix ): string {
		return $this->test_prefix . $suffix;
	}

	/**
	 * Insert a real event+occurrence and return the occurrence ID.
	 *
	 * @return int
	 */
	private function insert_occurrence(): int {
		$event     = EventFactory::create( array( 'slug' => $this->slug( 'evt-' . mt_rand( 10, 99 ) ), 'status' => 'published' ) );
		$event->id = null;
		$saved_event = $this->event_repo->save( $event );

		$occ           = OccurrenceFactory::create( array( 'event_id' => $saved_event->id ) );
		$occ->id       = null;
		$occ->event_id = $saved_event->id;
		$saved_occ     = $this->occurrence_repo->save( $occ );

		return $saved_occ->id;
	}

	/**
	 * Insert a ticket type with a given capacity and return it.
	 *
	 * @param int $occurrence_id Parent occurrence.
	 * @param int $capacity      Maximum tickets.
	 * @return TicketType
	 */
	private function insert_ticket_type( int $occurrence_id, int $capacity = 20 ): TicketType {
		$ticket_type             = TicketTypeFactory::create( array( 'occurrence_id' => $occurrence_id, 'capacity' => $capacity, 'sold_count' => 0 ) );
		$ticket_type->id         = null;
		return $this->ticket_type_repo->save( $ticket_type );
	}

	/**
	 * Insert an attendee linked to a WC order and return the saved instance.
	 *
	 * @param int    $occurrence_id   Occurrence ID.
	 * @param int    $ticket_type_id  Ticket type ID.
	 * @param int    $order_id        WC order ID.
	 * @param string $status          Attendee status.
	 * @param int    $quantity        Ticket quantity.
	 * @return Attendee
	 */
	private function insert_attendee(
		int $occurrence_id,
		int $ticket_type_id,
		int $order_id,
		string $status = AttendeeStatus::CONFIRMED->value,
		int $quantity = 1
	): Attendee {
		$attendee                  = AttendeeFactory::create( array(
			'occurrence_id'   => $occurrence_id,
			'ticket_type_id'  => $ticket_type_id,
			'wc_order_id'     => $order_id,
			'status'          => $status,
			'quantity'        => $quantity,
		) );
		$attendee->id              = null; // Force insert.

		return $this->attendee_repo->save( $attendee );
	}

	/**
	 * Increment sold_count directly via wpdb (simulates what OrderHandler does).
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

	/**
	 * Decrement sold_count directly via wpdb (simulates refund handler).
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity refunded.
	 * @return void
	 */
	private function decrement_sold_count( int $ticket_type_id, int $quantity ): void {
		global $wpdb;
		$table = Schema::table( 'ticket_types' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from trusted Schema constant.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET sold_count = GREATEST(0, sold_count - %d) WHERE id = %d",
				$quantity,
				$ticket_type_id
			)
		);
	}

	// =========================================================================
	// Stage 1: Order created -> Attendee inserted
	// =========================================================================

	/**
	 * Creating an order produces an attendee with confirmed status.
	 *
	 * @return void
	 */
	public function test_order_created_produces_confirmed_attendee(): void {
		$occurrence_id  = $this->insert_occurrence();
		$ticket_type    = $this->insert_ticket_type( $occurrence_id );
		$order          = WooCommerceFactory::create_order( array( 'id' => mt_rand( 1000, 9999 ) ) );

		$saved_attendee = $this->insert_attendee( $occurrence_id, $ticket_type->id, $order->get_id() );

		$this->assertNotNull( $saved_attendee->id );
		$this->assertGreaterThan( 0, $saved_attendee->id );
		$this->assertSame( AttendeeStatus::CONFIRMED->value, $saved_attendee->status );
	}

	/**
	 * The inserted attendee is findable by order ID.
	 *
	 * @return void
	 */
	public function test_attendee_is_findable_by_order_id(): void {
		$occurrence_id  = $this->insert_occurrence();
		$ticket_type    = $this->insert_ticket_type( $occurrence_id );
		$order_id       = mt_rand( 2000, 3999 );
		$order          = WooCommerceFactory::create_order( array( 'id' => $order_id ) );

		$this->insert_attendee( $occurrence_id, $ticket_type->id, $order->get_id() );

		// Fresh repo bypasses identity map.
		global $wpdb;
		$fresh_repo = new AttendeeRepository( $wpdb );
		$found      = $fresh_repo->find_by_order( $order_id );

		$this->assertNotNull( $found );
		$this->assertInstanceOf( Attendee::class, $found );
		$this->assertSame( $order_id, $found->wc_order_id );
	}

	/**
	 * Multiple attendees for one order are all findable.
	 *
	 * @return void
	 */
	public function test_find_all_by_order_returns_all_attendees(): void {
		$occurrence_id = $this->insert_occurrence();
		$ticket_type   = $this->insert_ticket_type( $occurrence_id );
		$order_id      = mt_rand( 4000, 5999 );
		$order         = WooCommerceFactory::create_order( array( 'id' => $order_id ) );

		$this->insert_attendee( $occurrence_id, $ticket_type->id, $order->get_id(), AttendeeStatus::CONFIRMED->value, 2 );
		$this->insert_attendee( $occurrence_id, $ticket_type->id, $order->get_id(), AttendeeStatus::CONFIRMED->value, 1 );

		global $wpdb;
		$fresh_repo = new AttendeeRepository( $wpdb );
		$all        = $fresh_repo->find_all_by_order( $order_id );

		$this->assertCount( 2, $all );
		$this->assertContainsOnlyInstancesOf( Attendee::class, $all );
	}

	// =========================================================================
	// Stage 2: Order complete -> sold_count incremented
	// =========================================================================

	/**
	 * Completing an order increments sold_count on the ticket type.
	 *
	 * @return void
	 */
	public function test_order_complete_increments_sold_count(): void {
		$occurrence_id  = $this->insert_occurrence();
		$ticket_type    = $this->insert_ticket_type( $occurrence_id );
		$order          = WooCommerceFactory::completed_order( array( 'id' => mt_rand( 6000, 7999 ) ) );
		$quantity       = 3;

		$this->insert_attendee( $occurrence_id, $ticket_type->id, $order->get_id(), AttendeeStatus::CONFIRMED->value, $quantity );
		$this->increment_sold_count( $ticket_type->id, $quantity );

		global $wpdb;
		$fresh_repo  = new TicketTypeRepository( $wpdb );
		$updated     = $fresh_repo->find( $ticket_type->id );

		$this->assertNotNull( $updated );
		$this->assertSame( $quantity, $updated->sold_count );
	}

	/**
	 * Attendee status remains confirmed after order completion.
	 *
	 * @return void
	 */
	public function test_attendee_status_is_confirmed_after_order_complete(): void {
		$occurrence_id  = $this->insert_occurrence();
		$ticket_type    = $this->insert_ticket_type( $occurrence_id );
		$order          = WooCommerceFactory::completed_order( array( 'id' => mt_rand( 8000, 9999 ) ) );

		$saved = $this->insert_attendee( $occurrence_id, $ticket_type->id, $order->get_id() );
		$this->increment_sold_count( $ticket_type->id, 1 );

		global $wpdb;
		$fresh_repo = new AttendeeRepository( $wpdb );
		$found      = $fresh_repo->find( $saved->id );

		$this->assertNotNull( $found );
		$this->assertSame( AttendeeStatus::CONFIRMED->value, $found->status );
	}

	// =========================================================================
	// Stage 3: Refund -> Attendee cancelled, sold_count decremented
	// =========================================================================

	/**
	 * Refunding an order cancels the attendee.
	 *
	 * @return void
	 */
	public function test_refund_cancels_attendee(): void {
		$occurrence_id  = $this->insert_occurrence();
		$ticket_type    = $this->insert_ticket_type( $occurrence_id );
		$order          = WooCommerceFactory::completed_order( array( 'id' => mt_rand( 10000, 11999 ) ) );

		$saved = $this->insert_attendee( $occurrence_id, $ticket_type->id, $order->get_id() );
		$this->increment_sold_count( $ticket_type->id, 1 );

		// Simulate refund: cancel attendee.
		global $wpdb;
		$fresh_repo = new AttendeeRepository( $wpdb );
		$fresh_repo->update_status( $saved->id, AttendeeStatus::CANCELLED->value );

		$refreshed = $fresh_repo->find( $saved->id );

		$this->assertNotNull( $refreshed );
		$this->assertSame( AttendeeStatus::CANCELLED->value, $refreshed->status );
	}

	/**
	 * Refunding decrements sold_count and capacity is regained.
	 *
	 * @return void
	 */
	public function test_refund_decrements_sold_count(): void {
		$occurrence_id  = $this->insert_occurrence();
		$ticket_type    = $this->insert_ticket_type( $occurrence_id, 10 );
		$order          = WooCommerceFactory::completed_order( array( 'id' => mt_rand( 12000, 13999 ) ) );
		$quantity       = 2;

		$this->insert_attendee( $occurrence_id, $ticket_type->id, $order->get_id(), AttendeeStatus::CONFIRMED->value, $quantity );
		$this->increment_sold_count( $ticket_type->id, $quantity );

		// Refund: decrement sold_count by the full quantity.
		$this->decrement_sold_count( $ticket_type->id, $quantity );

		global $wpdb;
		$fresh_repo = new TicketTypeRepository( $wpdb );
		$updated    = $fresh_repo->find( $ticket_type->id );

		$this->assertNotNull( $updated );
		$this->assertSame( 0, $updated->sold_count );
	}

	/**
	 * After a full refund and cancellation the attendee record still exists.
	 *
	 * Attendee records are preserved for audit purposes; only status changes.
	 *
	 * @return void
	 */
	public function test_refunded_attendee_record_is_preserved(): void {
		$occurrence_id  = $this->insert_occurrence();
		$ticket_type    = $this->insert_ticket_type( $occurrence_id );
		$order          = WooCommerceFactory::completed_order( array( 'id' => mt_rand( 14000, 15999 ) ) );

		$saved = $this->insert_attendee( $occurrence_id, $ticket_type->id, $order->get_id() );
		$this->increment_sold_count( $ticket_type->id, 1 );

		global $wpdb;
		$fresh_repo = new AttendeeRepository( $wpdb );
		$fresh_repo->update_status( $saved->id, AttendeeStatus::CANCELLED->value );
		$this->decrement_sold_count( $ticket_type->id, 1 );

		// Record must still exist.
		$found = $fresh_repo->find( $saved->id );
		$this->assertNotNull( $found );
		$this->assertSame( $saved->id, $found->id );
	}

	/**
	 * Partial refund: only the refunded quantity is removed from sold_count.
	 *
	 * @return void
	 */
	public function test_partial_refund_removes_only_refunded_quantity(): void {
		$occurrence_id  = $this->insert_occurrence();
		$ticket_type    = $this->insert_ticket_type( $occurrence_id, 10 );
		$order          = WooCommerceFactory::completed_order( array( 'id' => mt_rand( 16000, 17999 ) ) );

		// Buy 4 tickets.
		$this->insert_attendee( $occurrence_id, $ticket_type->id, $order->get_id(), AttendeeStatus::CONFIRMED->value, 4 );
		$this->increment_sold_count( $ticket_type->id, 4 );

		// Refund 1 ticket.
		$refund = WooCommerceFactory::create_refund( $order->get_id() );
		$this->assertSame( $order->get_id(), $refund->get_parent_id() );
		$this->decrement_sold_count( $ticket_type->id, 1 );

		global $wpdb;
		$fresh_repo = new TicketTypeRepository( $wpdb );
		$updated    = $fresh_repo->find( $ticket_type->id );

		$this->assertNotNull( $updated );
		$this->assertSame( 3, $updated->sold_count );
	}

	// =========================================================================
	// NTE-037: Full refund cascade to attendee status (regression gate)
	//
	// Bug: OrderHandler::void_attendees_for_order() wrote 'voided' but the
	// Attendee::STATUSES whitelist rejected it silently, leaving refunded
	// buyers marked 'confirmed' indefinitely. This test exercises the real
	// cascade against a real AttendeeRepository — it fails if the whitelist
	// is ever regressed or the status string drifts.
	//
	// See tickets/nte-037-wc-refund-cascade-voided-status-rejected.md
	// =========================================================================

	/**
	 * Guard: fail loudly if class shadowing is masking our worktree code.
	 *
	 * NTE-038 tracks the class-shadowing bug where integration tests may run
	 * against the active `nettertech-events` plugin's copy of a class rather
	 * than the worktree copy. If that happens, this test is meaningless.
	 *
	 * @param class-string $class_name Fully-qualified class to verify.
	 * @return void
	 */
	private function assert_class_loaded_from_worktree( string $class_name ): void {
		$reflection   = new \ReflectionClass( $class_name );
		$loaded_from  = $reflection->getFileName();
		$expected_dir = realpath( dirname( __DIR__, 2 ) . '/includes' );

		if ( false === $loaded_from || false === $expected_dir || ! str_starts_with( (string) $loaded_from, (string) $expected_dir ) ) {
			$this->fail( sprintf(
				'Class shadowing detected (NTE-038): %s was loaded from "%s" but this worktree expects "%s/...". ' .
				'The active `nettertech-events` plugin is providing stale class definitions. ' .
				'Fix via NTE-038 (bootstrap force-load) or deactivate the live plugin before running this test.',
				$class_name,
				(string) $loaded_from,
				(string) $expected_dir
			) );
		}
	}

	/**
	 * Full-refund cascade persists a valid attendee status.
	 *
	 * Regression for NTE-037: asserts OrderHandler::void_attendees_for_order()
	 * writes a status that the real AttendeeRepository whitelist accepts. The
	 * pre-fix code wrote 'voided' while STATUSES did not include it, so the
	 * whitelist check at update_status() rejected the write silently and the
	 * attendee row remained 'confirmed' forever.
	 *
	 * Wiring note: uses a real AttendeeRepository (the class containing the
	 * whitelist) and mocks the other collaborators that are unrelated to the
	 * status-persistence path.
	 *
	 * @return void
	 */
	public function test_full_refund_cascade_persists_valid_attendee_status(): void {
		// Shadowing guard — fail loudly if the active plugin is masking worktree code.
		$this->assert_class_loaded_from_worktree( \NetterTechEvents\Models\Attendee::class );
		$this->assert_class_loaded_from_worktree( \NetterTechEvents\Repositories\AttendeeRepository::class );
		$this->assert_class_loaded_from_worktree( \NetterTechEvents\Integrations\WooCommerce\OrderHandler::class );

		// Real DB fixture: event, occurrence, ticket type, attendee tied to order.
		$occurrence_id = $this->insert_occurrence();
		$ticket_type   = $this->insert_ticket_type( $occurrence_id );
		$order_id      = mt_rand( 20000, 29999 );

		$saved = $this->insert_attendee( $occurrence_id, $ticket_type->id, $order_id, AttendeeStatus::CONFIRMED->value, 1 );
		$this->increment_sold_count( $ticket_type->id, 1 );

		// Build a real OrderHandler using a real AttendeeRepository and real TicketRepository.
		// Other collaborators are mocked because they are not part of the status-persistence path.
		global $wpdb;
		$real_attendee_repo = new AttendeeRepository( $wpdb );
		$real_ticket_repo   = new TicketRepository( $wpdb );

		$handler = new OrderHandler(
			$real_attendee_repo,
			$this->createMock( TicketTypeRepository::class ),
			$real_ticket_repo,
			$this->createMock( TicketCodeGenerator::class ),
			$this->createMock( OccurrenceRepository::class ),
			$this->createMock( CapacityService::class ),
			$this->createMock( ProductManager::class ),
			$this->createMock( AttendeeFieldService::class )
		);

		// Mock WC_Order — OrderHandler only reads get_id() on the order object.
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( $order_id );

		// Exercise the cascade: this is where the whitelist bug fires.
		$handler->void_attendees_for_order( $order );

		// Re-read the attendee from the DB via a fresh repo to bypass any identity map.
		$fresh_repo   = new AttendeeRepository( $wpdb );
		$after_cascade = $fresh_repo->find( $saved->id );

		$this->assertNotNull(
			$after_cascade,
			'Attendee row must still exist after void cascade.'
		);

		$this->assertNotSame(
			AttendeeStatus::CONFIRMED->value,
			$after_cascade->status,
			'NTE-037 regression: attendee still reports confirmed after full refund cascade. ' .
				'The whitelist rejected the new status silently.'
		);

		$this->assertSame(
			AttendeeStatus::VOIDED->value,
			$after_cascade->status,
			'Full refund cascade must persist the voided status on the attendee row.'
		);

		$this->assertContains(
			$after_cascade->status,
			Attendee::STATUSES,
			'Persisted attendee status must belong to the whitelist.'
		);
	}
}
