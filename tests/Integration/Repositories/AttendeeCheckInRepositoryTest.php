<?php
/**
 * AttendeeCheckInRepository integration tests.
 *
 * Tests check-in repository operations with a real database.
 *
 * @package NetterTechEvents\Tests\Integration\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Repositories;

use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Repositories\AttendeeCheckInRepository;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Tests\Factories\AttendeeFactory;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;

/**
 * Integration tests for AttendeeCheckInRepository.
 */
class AttendeeCheckInRepositoryTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * AttendeeCheckInRepository instance.
	 *
	 * @var AttendeeCheckInRepository
	 */
	private AttendeeCheckInRepository $repository;

	/**
	 * AttendeeRepository for creating test attendees.
	 *
	 * @var AttendeeRepository
	 */
	private AttendeeRepository $attendee_repository;

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

		$this->repository            = new AttendeeCheckInRepository( $wpdb );
		$this->attendee_repository   = new AttendeeRepository( $wpdb );
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

		return $this->attendee_repository->save( $attendee );
	}

	// =========================================================================
	// mark_checked_in() Tests
	// =========================================================================

	/**
	 * Test mark_checked_in marks attendee as checked in.
	 *
	 * @return void
	 */
	public function test_mark_checked_in(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_attendee( $occurrence->id, array( 'quantity' => 3 ) );

		$result = $this->repository->mark_checked_in( $saved->id );

		$this->assertTrue( $result );

		$found = $this->attendee_repository->find( $saved->id );
		$this->assertTrue( $found->checked_in );
		$this->assertSame( 3, $found->checked_in_count );
		$this->assertNotNull( $found->checked_in_at );
	}

	// =========================================================================
	// mark_not_checked_in() Tests
	// =========================================================================

	/**
	 * Test mark_not_checked_in resets check-in.
	 *
	 * @return void
	 */
	public function test_mark_not_checked_in(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_attendee( $occurrence->id );

		$this->repository->mark_checked_in( $saved->id );
		$result = $this->repository->mark_not_checked_in( $saved->id );

		$this->assertTrue( $result );

		$found = $this->attendee_repository->find( $saved->id );
		$this->assertFalse( $found->checked_in );
		$this->assertSame( 0, $found->checked_in_count );
	}

	// =========================================================================
	// toggle_checked_in() Tests
	// =========================================================================

	/**
	 * Test toggle_checked_in toggles state.
	 *
	 * @return void
	 */
	public function test_toggle_checked_in(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_attendee( $occurrence->id );

		// Toggle on.
		$result = $this->repository->toggle_checked_in( $saved->id );
		$this->assertTrue( $result );

		// Toggle off.
		$result = $this->repository->toggle_checked_in( $saved->id );
		$this->assertFalse( $result );
	}

	// =========================================================================
	// increment_checked_in() Tests
	// =========================================================================

	/**
	 * Test increment_checked_in increases count.
	 *
	 * @return void
	 */
	public function test_increment_checked_in(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_attendee( $occurrence->id, array( 'quantity' => 3 ) );

		$result = $this->repository->increment_checked_in( $saved->id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['checked_in_count'] );
		$this->assertFalse( $result['checked_in'] ); // Not fully checked in yet.
	}

	// =========================================================================
	// decrement_checked_in() Tests
	// =========================================================================

	/**
	 * Test decrement_checked_in decreases count.
	 *
	 * @return void
	 */
	public function test_decrement_checked_in(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_attendee( $occurrence->id, array( 'quantity' => 3 ) );

		$this->repository->increment_checked_in( $saved->id );
		$this->repository->increment_checked_in( $saved->id );
		$result = $this->repository->decrement_checked_in( $saved->id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['checked_in_count'] );
	}

	// =========================================================================
	// set_checked_in_count() Tests
	// =========================================================================

	/**
	 * Test set_checked_in_count sets specific count.
	 *
	 * @return void
	 */
	public function test_set_checked_in_count(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );
		$saved      = $this->create_saved_attendee( $occurrence->id, array( 'quantity' => 5 ) );

		$result = $this->repository->set_checked_in_count( $saved->id, 3 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 3, $result['checked_in_count'] );
		$this->assertFalse( $result['checked_in'] ); // Not fully checked in.
	}

	// =========================================================================
	// get_check_in_stats() Tests
	// =========================================================================

	/**
	 * Test get_check_in_stats returns statistics.
	 *
	 * @return void
	 */
	public function test_get_check_in_stats_returns_statistics(): void {
		$event      = $this->create_saved_event();
		$occurrence = $this->create_saved_occurrence( $event->id );

		$this->create_saved_attendee( $occurrence->id, array( 'quantity' => 2 ) );
		$saved2 = $this->create_saved_attendee( $occurrence->id, array( 'quantity' => 3 ) );

		// Check in second attendee fully.
		$this->repository->mark_checked_in( $saved2->id );

		$stats = $this->repository->get_check_in_stats( $occurrence->id );

		$this->assertSame( 2, $stats['total_registrations'] );
		$this->assertSame( 5, $stats['total_guests'] );
		$this->assertSame( 1, $stats['fully_checked_in_count'] );
		$this->assertSame( 3, $stats['checked_in_guests'] );
	}
}
