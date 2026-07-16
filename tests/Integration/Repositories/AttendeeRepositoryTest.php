<?php
/**
 * AttendeeRepository integration tests.
 *
 * Tests repository CRUD operations with a real database.
 *
 * @package NetterTechEvents\Tests\Integration\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Repositories;

use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Tests\Factories\AttendeeFactory;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;

/**
 * Integration tests for AttendeeRepository.
 */
class AttendeeRepositoryTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * AttendeeRepository instance.
	 *
	 * @var AttendeeRepository
	 */
	private AttendeeRepository $repository;

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

		$this->repository            = new AttendeeRepository( $wpdb );
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
	 * Create a unique email.
	 *
	 * @param string $base Base email prefix.
	 * @return string
	 */
	private function unique_email( string $base = 'test' ): string {
		return $this->test_prefix . $base . '@example.com';
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
	 * Create and save a test attendee.
	 *
	 * @param int                  $occurrence_id Parent occurrence ID.
	 * @param array<string, mixed> $attributes    Attendee attributes.
	 * @return Attendee
	 */
	private function create_saved_attendee( int $occurrence_id, array $attributes = array() ): Attendee {
		$defaults = array(
			'occurrence_id' => $occurrence_id,
			'email'         => $this->unique_email(),
		);

		$attendee                = AttendeeFactory::create( array_merge( $defaults, $attributes ) );
		$attendee->id            = null;
		$attendee->occurrence_id = $occurrence_id;

		return $this->repository->save( $attendee );
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
	 * Test find returns Attendee for existing ID.
	 *
	 * @return void
	 */
	public function test_find_returns_attendee_for_existing_id(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_attendee( $occurrence->id, array( 'name' => 'John Doe' ) );

		$found = $this->repository->find( $saved->id );

		$this->assertNotNull( $found );
		$this->assertInstanceOf( Attendee::class, $found );
		$this->assertSame( $saved->id, $found->id );
		$this->assertSame( 'John Doe', $found->name );
	}

	// =========================================================================
	// save() Tests
	// =========================================================================

	/**
	 * Test save inserts new attendee.
	 *
	 * @return void
	 */
	public function test_save_inserts_new_attendee(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$attendee                = AttendeeFactory::create();
		$attendee->id            = null;
		$attendee->occurrence_id = $occurrence->id;
		$attendee->email         = $this->unique_email( 'new' );

		$saved = $this->repository->save( $attendee );

		$this->assertNotNull( $saved->id );
		$this->assertGreaterThan( 0, $saved->id );
	}

	/**
	 * Test save validates attendee.
	 *
	 * @return void
	 */
	public function test_save_validates_attendee(): void {
		$this->expectException( \RuntimeException::class );

		$attendee                = new Attendee();
		$attendee->occurrence_id = 0; // Invalid.

		$this->repository->save( $attendee );
	}

	/**
	 * Test save updates existing attendee.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_attendee(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_attendee( $occurrence->id );

		$original_id = $saved->id;
		$saved->name = 'Jane Smith';
		$updated     = $this->repository->save( $saved );

		$this->assertSame( $original_id, $updated->id );

		$found = $this->repository->find( $updated->id );
		$this->assertSame( 'Jane Smith', $found->name );
	}

	// =========================================================================
	// delete() Tests
	// =========================================================================

	/**
	 * Test delete removes attendee.
	 *
	 * @return void
	 */
	public function test_delete_removes_attendee(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_attendee( $occurrence->id );

		$result = $this->repository->delete( $saved->id );

		$this->assertTrue( $result );
		$this->assertNull( $this->repository->find( $saved->id ) );
	}

	// =========================================================================
	// for_occurrence() Tests
	// =========================================================================

	/**
	 * Test for_occurrence returns attendees.
	 *
	 * @return void
	 */
	public function test_for_occurrence_returns_attendees(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$this->create_saved_attendee( $occurrence->id, array( 'name' => 'Alice' ) );
		$this->create_saved_attendee( $occurrence->id, array( 'name' => 'Bob' ) );

		$attendees = $this->repository->for_occurrence( $occurrence->id );

		$this->assertCount( 2, $attendees );
		$this->assertContainsOnlyInstancesOf( Attendee::class, $attendees );
	}

	/**
	 * Test for_occurrence filters by status.
	 *
	 * @return void
	 */
	public function test_for_occurrence_filters_by_status(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$this->create_saved_attendee( $occurrence->id, array( 'status' => 'confirmed' ) );
		$this->create_saved_attendee( $occurrence->id, array( 'status' => 'cancelled' ) );

		$confirmed = $this->repository->for_occurrence( $occurrence->id, array( 'status' => 'confirmed' ) );

		$this->assertCount( 1, $confirmed );
	}

	// =========================================================================
	// count_for_occurrence() Tests
	// =========================================================================

	/**
	 * Test count_for_occurrence returns total guest count.
	 *
	 * @return void
	 */
	public function test_count_for_occurrence_returns_count(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$this->create_saved_attendee( $occurrence->id, array( 'quantity' => 2 ) );
		$this->create_saved_attendee( $occurrence->id, array( 'quantity' => 3 ) );

		$count = $this->repository->count_for_occurrence( $occurrence->id );

		$this->assertSame( 5, $count );
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
		$saved      = $this->create_saved_attendee( $occurrence->id );

		$result = $this->repository->update_status( $saved->id, 'cancelled' );

		$this->assertTrue( $result );
		$this->assertSame( 'cancelled', $this->repository->find( $saved->id )->status );
	}

	/**
	 * Test update_status returns false for invalid status.
	 *
	 * @return void
	 */
	public function test_update_status_rejects_invalid(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_attendee( $occurrence->id );

		$result = $this->repository->update_status( $saved->id, 'invalid_status' );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// delete_for_occurrence() Tests
	// =========================================================================

	/**
	 * Test delete_for_occurrence removes all attendees.
	 *
	 * @return void
	 */
	public function test_delete_for_occurrence_removes_all(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$this->create_saved_attendee( $occurrence->id );
		$this->create_saved_attendee( $occurrence->id );
		$this->create_saved_attendee( $occurrence->id );

		$deleted = $this->repository->delete_for_occurrence( $occurrence->id );

		$this->assertSame( 3, $deleted );
		$this->assertEmpty( $this->repository->for_occurrence( $occurrence->id ) );
	}

	// =========================================================================
	// search() Tests
	// =========================================================================

	/**
	 * Test search finds attendees by name.
	 *
	 * @return void
	 */
	public function test_search_finds_by_name(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$unique_name = 'UniqueSearchName' . time();
		$this->create_saved_attendee( $occurrence->id, array( 'name' => $unique_name ) );
		$this->create_saved_attendee( $occurrence->id, array( 'name' => 'Other Person' ) );

		$results = $this->repository->search( $occurrence->id, $unique_name );

		$this->assertCount( 1, $results );
		$this->assertSame( $unique_name, $results[0]->name );
	}

	/**
	 * Test search finds attendees by email.
	 *
	 * @return void
	 */
	public function test_search_finds_by_email(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$unique_email = 'unique' . time() . '@search.test';
		$this->create_saved_attendee( $occurrence->id, array( 'email' => $unique_email ) );

		$results = $this->repository->search( $occurrence->id, $unique_email );

		$this->assertCount( 1, $results );
		$this->assertSame( $unique_email, $results[0]->email );
	}

	// =========================================================================
	// find_by_email() Tests
	// =========================================================================

	/**
	 * Test find_by_email returns matching attendees.
	 *
	 * @return void
	 */
	public function test_find_by_email_returns_matching(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$email = $this->unique_email( 'findtest' );
		$this->create_saved_attendee( $occurrence->id, array( 'email' => $email ) );
		$this->create_saved_attendee( $occurrence->id, array( 'email' => $this->unique_email( 'other' ) ) );

		$results = $this->repository->find_by_email( $occurrence->id, $email );

		$this->assertCount( 1, $results );
		$this->assertSame( $email, $results[0]->email );
	}

	// =========================================================================
	// email_exists_for_occurrence() Tests
	// =========================================================================

	/**
	 * Test email_exists_for_occurrence returns true when exists.
	 *
	 * @return void
	 */
	public function test_email_exists_returns_true(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$email = $this->unique_email( 'exists' );
		$this->create_saved_attendee( $occurrence->id, array( 'email' => $email ) );

		$this->assertTrue( $this->repository->email_exists_for_occurrence( $occurrence->id, $email ) );
	}

	/**
	 * Test email_exists_for_occurrence returns false when not exists.
	 *
	 * @return void
	 */
	public function test_email_exists_returns_false(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$this->assertFalse(
			$this->repository->email_exists_for_occurrence(
				$occurrence->id,
				'nonexistent' . time() . '@example.com'
			)
		);
	}

	/**
	 * Test email_exists_for_occurrence excludes specified ID.
	 *
	 * @return void
	 */
	public function test_email_exists_excludes_id(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$email = $this->unique_email( 'exclude' );
		$saved = $this->create_saved_attendee( $occurrence->id, array( 'email' => $email ) );

		$this->assertFalse(
			$this->repository->email_exists_for_occurrence( $occurrence->id, $email, $saved->id )
		);
	}

	// =========================================================================
	// update_quantity() Tests
	// =========================================================================

	/**
	 * Test update_quantity changes quantity.
	 *
	 * @return void
	 */
	public function test_update_quantity_changes_quantity(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_attendee( $occurrence->id, array( 'quantity' => 5 ) );

		$result = $this->repository->update_quantity( $saved->id, 3 );

		$this->assertTrue( $result );

		$found = $this->repository->find( $saved->id );
		$this->assertSame( 3, $found->quantity );
	}

	/**
	 * Test update_quantity rejects negative.
	 *
	 * @return void
	 */
	public function test_update_quantity_rejects_negative(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_attendee( $occurrence->id );

		$result = $this->repository->update_quantity( $saved->id, -1 );

		$this->assertFalse( $result );
	}
}
