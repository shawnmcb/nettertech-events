<?php
/**
 * TicketTypeRepository integration tests.
 *
 * Tests repository CRUD operations with a real database.
 *
 * @package NetterTechEvents\Tests\Integration\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Repositories;

use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;

/**
 * Integration tests for TicketTypeRepository.
 */
class TicketTypeRepositoryTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * TicketTypeRepository instance.
	 *
	 * @var TicketTypeRepository
	 */
	private TicketTypeRepository $repository;

	/**
	 * EventRepository for creating parent events.
	 *
	 * @var EventRepository
	 */
	private EventRepository $event_repository;

	/**
	 * OccurrenceRepository for creating parent occurrences.
	 *
	 * @var OccurrenceRepository
	 */
	private OccurrenceRepository $occurrence_repository;

	/**
	 * Unique test prefix.
	 *
	 * @var string
	 */
	private string $test_prefix;

	/**
	 * Counter for unique slugs within a test.
	 *
	 * @var int
	 */
	private static int $slug_counter = 0;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$this->repository            = new TicketTypeRepository( $wpdb );
		$this->event_repository      = new EventRepository( $wpdb );
		$this->occurrence_repository = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
		$this->test_prefix           = 'test-' . time() . '-' . mt_rand( 1000, 9999 ) . '-';
	}

	/**
	 * Create a unique slug.
	 *
	 * @param string $base Base slug name.
	 * @return string
	 */
	private function unique_slug( string $base ): string {
		++self::$slug_counter;
		return $this->test_prefix . $base . '-' . self::$slug_counter;
	}

	/**
	 * Create and save a test event.
	 *
	 * @param array<string, mixed> $attributes Event attributes.
	 * @return Event
	 */
	private function create_saved_event( array $attributes = array() ): Event {
		$defaults  = array(
			'slug'   => $this->unique_slug( 'event' ),
			'status' => 'published',
		);
		$event     = EventFactory::create( array_merge( $defaults, $attributes ) );
		$event->id = null;

		return $this->event_repository->save( $event );
	}

	/**
	 * Create and save a test occurrence.
	 *
	 * @param int                  $event_id   Parent event ID.
	 * @param array<string, mixed> $attributes Occurrence attributes.
	 * @return Occurrence
	 */
	private function create_saved_occurrence( int $event_id, array $attributes = array() ): Occurrence {
		$occurrence           = OccurrenceFactory::create( array_merge( array( 'event_id' => $event_id ), $attributes ) );
		$occurrence->id       = null;
		$occurrence->event_id = $event_id;

		return $this->occurrence_repository->save( $occurrence );
	}

	/**
	 * Create and save a test ticket type.
	 *
	 * @param int                  $occurrence_id Parent occurrence ID.
	 * @param array<string, mixed> $attributes    TicketType attributes.
	 * @return TicketType
	 */
	private function create_saved_ticket_type( int $occurrence_id, array $attributes = array() ): TicketType {
		$ticket_type                = TicketTypeFactory::create( array_merge( array( 'occurrence_id' => $occurrence_id ), $attributes ) );
		$ticket_type->id            = null;
		$ticket_type->occurrence_id = $occurrence_id;

		return $this->repository->save( $ticket_type );
	}

	// =========================================================================
	// find() Tests
	// =========================================================================

	/**
	 * Test find returns null for non-existent ID.
	 *
	 * @return void
	 */
	public function test_find_returns_null_for_nonexistent_id(): void {
		$result = $this->repository->find( 999999 );

		$this->assertNull( $result );
	}

	/**
	 * Test find returns TicketType for existing ID.
	 *
	 * @return void
	 */
	public function test_find_returns_ticket_type_for_existing_id(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_ticket_type( $occurrence->id, array( 'name' => 'VIP' ) );

		$found = $this->repository->find( $saved->id );

		$this->assertNotNull( $found );
		$this->assertInstanceOf( TicketType::class, $found );
		$this->assertSame( $saved->id, $found->id );
		$this->assertSame( 'VIP', $found->name );
	}

	// =========================================================================
	// save() Tests
	// =========================================================================

	/**
	 * Test save inserts new ticket type.
	 *
	 * @return void
	 */
	public function test_save_inserts_new_ticket_type(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$ticket_type                = TicketTypeFactory::create();
		$ticket_type->id            = null;
		$ticket_type->occurrence_id = $occurrence->id;
		$ticket_type->name          = 'General Admission';
		$ticket_type->price         = 25.00;

		$saved = $this->repository->save( $ticket_type );

		$this->assertNotNull( $saved->id );
		$this->assertGreaterThan( 0, $saved->id );
	}

	/**
	 * Test save validates ticket type.
	 *
	 * @return void
	 */
	public function test_save_validates_ticket_type(): void {
		$this->expectException( \RuntimeException::class );

		$ticket_type                = new TicketType();
		$ticket_type->occurrence_id = 0; // Invalid.

		$this->repository->save( $ticket_type );
	}

	/**
	 * Test save updates existing ticket type.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_ticket_type(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_ticket_type( $occurrence->id );

		$original_id  = $saved->id;
		$saved->price = 50.00;
		$updated      = $this->repository->save( $saved );

		$this->assertSame( $original_id, $updated->id );

		$found = $this->repository->find( $updated->id );
		$this->assertEquals( 50.00, $found->price );
	}

	// =========================================================================
	// delete() Tests
	// =========================================================================

	/**
	 * Test delete removes ticket type.
	 *
	 * @return void
	 */
	public function test_delete_removes_ticket_type(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_ticket_type( $occurrence->id );

		$result = $this->repository->delete( $saved->id );

		$this->assertTrue( $result );
		$this->assertNull( $this->repository->find( $saved->id ) );
	}

	// =========================================================================
	// for_occurrence() Tests
	// =========================================================================

	/**
	 * Test for_occurrence returns ticket types for occurrence.
	 *
	 * @return void
	 */
	public function test_for_occurrence_returns_ticket_types(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$this->create_saved_ticket_type( $occurrence->id, array( 'name' => 'GA', 'sort_order' => 1 ) );
		$this->create_saved_ticket_type( $occurrence->id, array( 'name' => 'VIP', 'sort_order' => 2 ) );

		$types = $this->repository->for_occurrence( $occurrence->id );

		$this->assertCount( 2, $types );
		$this->assertContainsOnlyInstancesOf( TicketType::class, $types );
	}

	/**
	 * Test for_occurrence filters by status.
	 *
	 * @return void
	 */
	public function test_for_occurrence_filters_by_status(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$this->create_saved_ticket_type( $occurrence->id, array( 'status' => 'active' ) );
		$this->create_saved_ticket_type( $occurrence->id, array( 'status' => 'inactive' ) );

		$active = $this->repository->for_occurrence( $occurrence->id, array( 'status' => 'active' ) );

		$this->assertCount( 1, $active );
	}

	// =========================================================================
	// get_active_for_occurrence() Tests
	// =========================================================================

	/**
	 * Test get_active_for_occurrence returns only active types.
	 *
	 * @return void
	 */
	public function test_get_active_for_occurrence_returns_active(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$this->create_saved_ticket_type( $occurrence->id, array( 'status' => 'active' ) );
		$this->create_saved_ticket_type( $occurrence->id, array( 'status' => 'inactive' ) );

		$active = $this->repository->get_active_for_occurrence( $occurrence->id );

		$this->assertCount( 1, $active );
		$this->assertSame( 'active', $active[0]->status );
	}

	// =========================================================================
	// delete_for_occurrence() Tests
	// =========================================================================

	/**
	 * Test delete_for_occurrence removes all ticket types.
	 *
	 * @return void
	 */
	public function test_delete_for_occurrence_removes_all(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$this->create_saved_ticket_type( $occurrence->id );
		$this->create_saved_ticket_type( $occurrence->id );
		$this->create_saved_ticket_type( $occurrence->id );

		$deleted = $this->repository->delete_for_occurrence( $occurrence->id );

		$this->assertSame( 3, $deleted );
		$this->assertEmpty( $this->repository->for_occurrence( $occurrence->id ) );
	}

	// =========================================================================
	// Availability Tests
	// =========================================================================

	/**
	 * Test get_sold_count returns correct count.
	 *
	 * @return void
	 */
	public function test_get_sold_count_returns_count(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_ticket_type( $occurrence->id );

		$count = $this->repository->get_sold_count( $saved->id );

		$this->assertSame( 0, $count );
	}

	/**
	 * Test get_available_count for limited capacity.
	 *
	 * @return void
	 */
	public function test_get_available_count_limited(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_ticket_type(
			$occurrence->id,
			array( 'capacity' => 100 )
		);

		// sold_count is managed separately, use increment_sold_count.
		$this->repository->increment_sold_count( $saved->id, 25 );

		$available = $this->repository->get_available_count( $saved->id );

		$this->assertSame( 75, $available );
	}

	/**
	 * Test get_available_count returns null for unlimited.
	 *
	 * @return void
	 */
	public function test_get_available_count_unlimited(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_ticket_type(
			$occurrence->id,
			array( 'capacity' => null )
		);

		$available = $this->repository->get_available_count( $saved->id );

		$this->assertNull( $available );
	}

	/**
	 * Test has_availability returns true when available.
	 *
	 * @return void
	 */
	public function test_has_availability_returns_true(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_ticket_type(
			$occurrence->id,
			array( 'capacity' => 100 )
		);

		// sold_count is managed separately.
		$this->repository->increment_sold_count( $saved->id, 50 );

		$this->assertTrue( $this->repository->has_availability( $saved->id, 10 ) );
	}

	/**
	 * Test has_availability returns false when not available.
	 *
	 * @return void
	 */
	public function test_has_availability_returns_false(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_ticket_type(
			$occurrence->id,
			array( 'capacity' => 100 )
		);

		// sold_count is managed separately.
		$this->repository->increment_sold_count( $saved->id, 95 );

		$this->assertFalse( $this->repository->has_availability( $saved->id, 10 ) );
	}

	// =========================================================================
	// increment/decrement sold_count Tests
	// =========================================================================

	/**
	 * Test increment_sold_count increases count.
	 *
	 * @return void
	 */
	public function test_increment_sold_count_increases(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_ticket_type( $occurrence->id );

		$result = $this->repository->increment_sold_count( $saved->id, 5 );

		$this->assertTrue( $result );
		$this->assertSame( 5, $this->repository->get_sold_count( $saved->id ) );
	}

	/**
	 * Test decrement_sold_count decreases count.
	 *
	 * @return void
	 */
	public function test_decrement_sold_count_decreases(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_ticket_type( $occurrence->id );

		// Increment first.
		$this->repository->increment_sold_count( $saved->id, 10 );
		$result = $this->repository->decrement_sold_count( $saved->id, 3 );

		$this->assertTrue( $result );
		$this->assertSame( 7, $this->repository->get_sold_count( $saved->id ) );
	}

	// =========================================================================
	// update_status() Tests
	// =========================================================================

	/**
	 * Test update_status changes status.
	 *
	 * @return void
	 */
	public function test_update_status_changes_status(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_ticket_type( $occurrence->id );

		$result = $this->repository->update_status( $saved->id, 'inactive' );

		$this->assertTrue( $result );
		$this->assertSame( 'inactive', $this->repository->find( $saved->id )->status );
	}

	/**
	 * Test update_status returns false for invalid status.
	 *
	 * @return void
	 */
	public function test_update_status_rejects_invalid(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_ticket_type( $occurrence->id );

		$result = $this->repository->update_status( $saved->id, 'invalid_status' );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// Free Ticket Tests
	// =========================================================================

	/**
	 * Test occurrence_has_free_tickets returns true when free exists.
	 *
	 * @return void
	 */
	public function test_occurrence_has_free_tickets_true(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$this->create_saved_ticket_type( $occurrence->id, array( 'price' => 25.00 ) );
		$this->create_saved_ticket_type( $occurrence->id, array( 'price' => 0.00 ) );

		$this->assertTrue( $this->repository->occurrence_has_free_tickets( $occurrence->id ) );
	}

	/**
	 * Test occurrence_has_free_tickets returns false when no free.
	 *
	 * @return void
	 */
	public function test_occurrence_has_free_tickets_false(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$this->create_saved_ticket_type( $occurrence->id, array( 'price' => 25.00 ) );

		$this->assertFalse( $this->repository->occurrence_has_free_tickets( $occurrence->id ) );
	}

	/**
	 * Test occurrence_is_free returns true when all free.
	 *
	 * @return void
	 */
	public function test_occurrence_is_free_all_free(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$this->create_saved_ticket_type( $occurrence->id, array( 'price' => 0.00 ) );
		$this->create_saved_ticket_type( $occurrence->id, array( 'price' => 0.00 ) );

		$this->assertTrue( $this->repository->occurrence_is_free( $occurrence->id ) );
	}

	/**
	 * Test occurrence_is_free returns false when paid exists.
	 *
	 * @return void
	 */
	public function test_occurrence_is_free_with_paid(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$this->create_saved_ticket_type( $occurrence->id, array( 'price' => 0.00 ) );
		$this->create_saved_ticket_type( $occurrence->id, array( 'price' => 25.00 ) );

		$this->assertFalse( $this->repository->occurrence_is_free( $occurrence->id ) );
	}
}
