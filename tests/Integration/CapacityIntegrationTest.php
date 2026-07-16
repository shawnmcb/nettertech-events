<?php
/**
 * Capacity integration test.
 *
 * Verifies atomic reservations against the real database.  Tests are
 * intentionally sequential (not truly concurrent) but validate the
 * atomicity guarantees the single-UPDATE design provides: the WHERE
 * clause is the availability check, so the DB row is the source of truth.
 *
 * @package NetterTechEvents\Tests\Integration
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration;

use NetterTechEvents\Database\Schema;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\ReservationManager;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;

/**
 * Integration test: capacity and reservation atomicity using real DB.
 */
class CapacityIntegrationTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * ReservationManager under test.
	 *
	 * @var ReservationManager
	 */
	private ReservationManager $manager;

	/**
	 * TicketTypeRepository for inserting test fixtures.
	 *
	 * @var TicketTypeRepository
	 */
	private TicketTypeRepository $ticket_type_repo;

	/**
	 * EventRepository for creating parent events.
	 *
	 * @var EventRepository
	 */
	private EventRepository $event_repo;

	/**
	 * OccurrenceRepository for creating parent occurrences.
	 *
	 * @var OccurrenceRepository
	 */
	private OccurrenceRepository $occurrence_repo;

	/**
	 * Unique test prefix.
	 *
	 * @var string
	 */
	private string $test_prefix;

	/**
	 * Hold time in seconds passed directly to create_pending() to avoid
	 * reading from WP options in test context.
	 *
	 * @var int
	 */
	private const HOLD_TIME = 900;

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
		$this->ticket_type_repo = new TicketTypeRepository( $wpdb );
		$this->manager          = new ReservationManager();
		$this->test_prefix      = 'cap-' . time() . '-' . mt_rand( 1000, 9999 ) . '-';
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Insert a ticket type with a given capacity and return the saved instance.
	 *
	 * @param int      $capacity   Maximum tickets.
	 * @param int|null $occurrence_id Occurrence ID (creates a real one if null).
	 * @return TicketType
	 */
	private function insert_ticket_type( int $capacity, ?int $occurrence_id = null ): TicketType {
		if ( null === $occurrence_id ) {
			$occurrence_id = $this->insert_occurrence();
		}

		$ticket_type             = TicketTypeFactory::create( array( 'capacity' => $capacity, 'occurrence_id' => $occurrence_id, 'sold_count' => 0 ) );
		$ticket_type->id         = null; // Force insert.

		return $this->ticket_type_repo->save( $ticket_type );
	}

	/**
	 * Insert an occurrence tied to a new event and return the occurrence ID.
	 *
	 * @return int
	 */
	private function insert_occurrence(): int {
		$slug  = $this->test_prefix . 'evt-' . mt_rand( 100, 999 );
		$event = EventFactory::create( array( 'slug' => $slug, 'status' => 'published' ) );
		$event->id = null;
		$saved_event = $this->event_repo->save( $event );

		$occ           = OccurrenceFactory::create( array( 'event_id' => $saved_event->id ) );
		$occ->id       = null;
		$occ->event_id = $saved_event->id;
		$saved_occ     = $this->occurrence_repo->save( $occ );

		return $saved_occ->id;
	}

	/**
	 * Unique session key for this test.
	 *
	 * @param string $suffix Suffix.
	 * @return string
	 */
	private function session( string $suffix ): string {
		return $this->test_prefix . $suffix;
	}

	// =========================================================================
	// create_pending(): success cases
	// =========================================================================

	/**
	 * Reserving within capacity returns true.
	 *
	 * @return void
	 */
	public function test_create_pending_within_capacity_returns_true(): void {
		$ticket_type = $this->insert_ticket_type( 10 );

		$result = $this->manager->create_pending( $ticket_type->id, 3, $this->session( 'a' ), self::HOLD_TIME );

		$this->assertTrue( $result );
	}

	/**
	 * Reserved count increases after a successful reservation.
	 *
	 * @return void
	 */
	public function test_create_pending_increments_reserved_count(): void {
		$ticket_type = $this->insert_ticket_type( 10 );
		$session_key = $this->session( 'incr' );

		$this->manager->create_pending( $ticket_type->id, 4, $session_key, self::HOLD_TIME );

		$this->assertSame( 4, $this->manager->get_pending_count( $ticket_type->id ) );
	}

	/**
	 * get_pending() returns the quantity for a specific session.
	 *
	 * @return void
	 */
	public function test_get_pending_returns_session_quantity(): void {
		$ticket_type = $this->insert_ticket_type( 20 );
		$session_key = $this->session( 'sess-qty' );

		$this->manager->create_pending( $ticket_type->id, 5, $session_key, self::HOLD_TIME );

		$this->assertSame( 5, $this->manager->get_pending( $ticket_type->id, $session_key ) );
	}

	// =========================================================================
	// create_pending(): capacity enforcement
	// =========================================================================

	/**
	 * Reserving more than the available capacity returns false.
	 *
	 * @return void
	 */
	public function test_create_pending_exceeding_capacity_returns_false(): void {
		$ticket_type = $this->insert_ticket_type( 5 );

		$result = $this->manager->create_pending( $ticket_type->id, 6, $this->session( 'over' ), self::HOLD_TIME );

		$this->assertFalse( $result );
	}

	/**
	 * The reserved count is not changed when a reservation attempt fails.
	 *
	 * @return void
	 */
	public function test_failed_reservation_does_not_change_reserved_count(): void {
		$ticket_type = $this->insert_ticket_type( 3 );

		$before = $this->manager->get_pending_count( $ticket_type->id );
		$this->manager->create_pending( $ticket_type->id, 10, $this->session( 'fail' ), self::HOLD_TIME );
		$after = $this->manager->get_pending_count( $ticket_type->id );

		$this->assertSame( $before, $after );
	}

	/**
	 * Two sequential reservations that together fill all capacity both succeed.
	 *
	 * @return void
	 */
	public function test_two_sequential_reservations_filling_capacity_both_succeed(): void {
		$ticket_type = $this->insert_ticket_type( 6 );

		$result_a = $this->manager->create_pending( $ticket_type->id, 4, $this->session( 'seq-a' ), self::HOLD_TIME );
		$result_b = $this->manager->create_pending( $ticket_type->id, 2, $this->session( 'seq-b' ), self::HOLD_TIME );

		$this->assertTrue( $result_a );
		$this->assertTrue( $result_b );
		$this->assertSame( 6, $this->manager->get_pending_count( $ticket_type->id ) );
	}

	/**
	 * A third reservation that would exceed capacity is rejected.
	 *
	 * @return void
	 */
	public function test_third_reservation_exceeding_capacity_is_rejected(): void {
		$ticket_type = $this->insert_ticket_type( 6 );

		$this->manager->create_pending( $ticket_type->id, 4, $this->session( 'third-a' ), self::HOLD_TIME );
		$this->manager->create_pending( $ticket_type->id, 2, $this->session( 'third-b' ), self::HOLD_TIME );

		$result_c = $this->manager->create_pending( $ticket_type->id, 1, $this->session( 'third-c' ), self::HOLD_TIME );

		$this->assertFalse( $result_c );
		$this->assertSame( 6, $this->manager->get_pending_count( $ticket_type->id ) );
	}

	// =========================================================================
	// clear_pending(): session release
	// =========================================================================

	/**
	 * Clearing a session's reservation releases capacity.
	 *
	 * @return void
	 */
	public function test_clear_pending_releases_session_capacity(): void {
		$ticket_type = $this->insert_ticket_type( 10 );
		$session_key = $this->session( 'clear-sess' );

		$this->manager->create_pending( $ticket_type->id, 5, $session_key, self::HOLD_TIME );
		$this->manager->clear_pending( $ticket_type->id, $session_key );

		$this->assertSame( 0, $this->manager->get_pending_count( $ticket_type->id ) );
		$this->assertSame( 0, $this->manager->get_pending( $ticket_type->id, $session_key ) );
	}

	/**
	 * After clearing a session, a new reservation can take that freed capacity.
	 *
	 * @return void
	 */
	public function test_after_clear_pending_new_reservation_succeeds(): void {
		$ticket_type = $this->insert_ticket_type( 5 );
		$session_key = $this->session( 'clear-refill' );

		$this->manager->create_pending( $ticket_type->id, 5, $session_key, self::HOLD_TIME );
		$this->manager->clear_pending( $ticket_type->id, $session_key );

		$result = $this->manager->create_pending( $ticket_type->id, 5, $this->session( 'new' ), self::HOLD_TIME );

		$this->assertTrue( $result );
	}

	/**
	 * Clearing all reservations for a ticket type removes every pending row.
	 *
	 * @return void
	 */
	public function test_clear_pending_all_removes_all_sessions(): void {
		$ticket_type = $this->insert_ticket_type( 20 );

		$this->manager->create_pending( $ticket_type->id, 3, $this->session( 'all-a' ), self::HOLD_TIME );
		$this->manager->create_pending( $ticket_type->id, 4, $this->session( 'all-b' ), self::HOLD_TIME );

		// Passing empty session key clears all.
		$this->manager->clear_pending( $ticket_type->id );

		$this->assertSame( 0, $this->manager->get_pending_count( $ticket_type->id ) );
	}

	// =========================================================================
	// Zero / negative quantity guards
	// =========================================================================

	/**
	 * create_pending() rejects a zero quantity without touching the DB.
	 *
	 * @return void
	 */
	public function test_create_pending_zero_quantity_returns_false(): void {
		$ticket_type = $this->insert_ticket_type( 10 );

		$result = $this->manager->create_pending( $ticket_type->id, 0, $this->session( 'zero' ), self::HOLD_TIME );

		$this->assertFalse( $result );
		$this->assertSame( 0, $this->manager->get_pending_count( $ticket_type->id ) );
	}
}
