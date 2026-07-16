<?php
/**
 * OccurrenceRepository integration tests.
 *
 * Tests repository CRUD operations with a real database.
 *
 * @package NetterTechEvents\Tests\Integration\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Repositories;

use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;

/**
 * Integration tests for OccurrenceRepository.
 *
 * Note: Uses class-level transactions for isolation.
 * Data created in tests is rolled back after the test class completes.
 */
class OccurrenceRepositoryTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * OccurrenceRepository instance.
	 *
	 * @var OccurrenceRepository
	 */
	private OccurrenceRepository $repository;

	/**
	 * EventRepository for creating parent events.
	 *
	 * @var EventRepository
	 */
	private EventRepository $event_repository;

	/**
	 * Unique test prefix to avoid conflicts.
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
		$this->repository       = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
		$this->event_repository = new EventRepository( $wpdb );
		$this->test_prefix      = 'test-' . time() . '-' . mt_rand( 1000, 9999 ) . '-';
	}

	/**
	 * Create a unique slug for this test.
	 *
	 * @param string $base Base slug name.
	 * @return string
	 */
	private function unique_slug( string $base ): string {
		return $this->test_prefix . $base;
	}

	/**
	 * Create and save a test event.
	 *
	 * @param array<string, mixed> $attributes Event attributes.
	 * @return Event
	 */
	private function create_saved_event( array $attributes = array() ): Event {
		$defaults = array(
			'slug'   => $this->unique_slug( 'event' ),
			'status' => 'published',
		);

		$event     = EventFactory::create( array_merge( $defaults, $attributes ) );
		$event->id = null; // Ensure insert.

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
		$occurrence->id       = null; // Ensure insert.
		$occurrence->event_id = $event_id;

		return $this->repository->save( $occurrence );
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
	 * Test find returns Occurrence for existing ID.
	 *
	 * @return void
	 */
	public function test_find_returns_occurrence_for_existing_id(): void {
		$event = $this->create_saved_event();
		$saved = $this->create_saved_occurrence( $event->id );

		$found = $this->repository->find( $saved->id );

		$this->assertNotNull( $found );
		$this->assertInstanceOf( Occurrence::class, $found );
		$this->assertSame( $saved->id, $found->id );
		$this->assertSame( $event->id, $found->event_id );
	}

	/**
	 * Test find uses identity map for caching.
	 *
	 * @return void
	 */
	public function test_find_uses_identity_map(): void {
		$event = $this->create_saved_event();
		$saved = $this->create_saved_occurrence( $event->id );

		$first  = $this->repository->find( $saved->id );
		$second = $this->repository->find( $saved->id );

		$this->assertSame( $first, $second );
	}

	// =========================================================================
	// find_with_event() Tests
	// =========================================================================

	/**
	 * Test find_with_event returns null for non-existent ID.
	 *
	 * @return void
	 */
	public function test_find_with_event_returns_null_for_nonexistent(): void {
		$result = $this->repository->find_with_event( 999999 );

		$this->assertNull( $result );
	}

	/**
	 * Test find_with_event returns occurrence with attached event data.
	 *
	 * @return void
	 */
	public function test_find_with_event_attaches_event_data(): void {
		$event = $this->create_saved_event(
			array(
				'title' => 'Concert With Event',
				'slug'  => $this->unique_slug( 'with-event' ),
			)
		);
		$saved = $this->create_saved_occurrence( $event->id );

		$found = $this->repository->find_with_event( $saved->id );

		$this->assertNotNull( $found );
		$this->assertNotNull( $found->get_event() );
		$this->assertSame( 'Concert With Event', $found->get_event()->title );
	}

	// =========================================================================
	// find_by_event_and_datetime() Tests
	// =========================================================================

	/**
	 * Test find_by_event_and_datetime returns null when not found.
	 *
	 * @return void
	 */
	public function test_find_by_event_and_datetime_returns_null(): void {
		$result = $this->repository->find_by_event_and_datetime(
			999999,
			new \DateTimeImmutable( '2030-01-01 19:00:00' )
		);

		$this->assertNull( $result );
	}

	/**
	 * Test find_by_event_and_datetime returns matching occurrence.
	 *
	 * @return void
	 */
	public function test_find_by_event_and_datetime_returns_match(): void {
		$event    = $this->create_saved_event();
		$datetime = '2030-06-15 19:00:00';
		$saved    = $this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => $datetime,
				'end_datetime'   => '2030-06-15 21:00:00',
			)
		);

		$found = $this->repository->find_by_event_and_datetime(
			$event->id,
			new \DateTimeImmutable( $datetime )
		);

		$this->assertNotNull( $found );
		$this->assertSame( $saved->id, $found->id );
	}

	// =========================================================================
	// save() Tests - Insert
	// =========================================================================

	/**
	 * Test save inserts new occurrence and returns ID.
	 *
	 * @return void
	 */
	public function test_save_inserts_new_occurrence(): void {
		$event = $this->create_saved_event();

		$occurrence                 = OccurrenceFactory::create();
		$occurrence->id             = null;
		$occurrence->event_id       = $event->id;
		$occurrence->start_datetime = '2030-07-01 19:00:00';
		$occurrence->end_datetime   = '2030-07-01 21:00:00';

		$saved = $this->repository->save( $occurrence );

		$this->assertNotNull( $saved->id );
		$this->assertGreaterThan( 0, $saved->id );
	}

	/**
	 * Test save validates occurrence before insert.
	 *
	 * @return void
	 */
	public function test_save_validates_occurrence(): void {
		$this->expectException( \RuntimeException::class );

		$occurrence           = new Occurrence();
		$occurrence->event_id = 0; // Invalid.

		$this->repository->save( $occurrence );
	}

	// =========================================================================
	// save() Tests - Update
	// =========================================================================

	/**
	 * Test save updates existing occurrence.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_occurrence(): void {
		$event = $this->create_saved_event();
		$saved = $this->create_saved_occurrence( $event->id );

		$original_id = $saved->id;

		$saved->status = 'cancelled';
		$updated       = $this->repository->save( $saved );

		$this->assertSame( $original_id, $updated->id );

		// Fresh repo to bypass identity map.
		global $wpdb;
		$fresh_repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
		$found      = $fresh_repo->find( $updated->id );
		$this->assertSame( 'cancelled', $found->status );
	}

	// =========================================================================
	// save_batch() Tests
	// =========================================================================

	/**
	 * Test save_batch saves multiple occurrences.
	 *
	 * @return void
	 */
	public function test_save_batch_saves_multiple(): void {
		$event = $this->create_saved_event();

		$occurrences = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$occ                 = OccurrenceFactory::create();
			$occ->id             = null;
			$occ->event_id       = $event->id;
			$occ->start_datetime = "2030-08-0{$i} 19:00:00";
			$occ->end_datetime   = "2030-08-0{$i} 21:00:00";
			$occurrences[]       = $occ;
		}

		$saved_count = $this->repository->save_batch( $occurrences );

		$this->assertSame( 3, $saved_count );
	}

	/**
	 * Test save_batch returns 0 for empty array.
	 *
	 * @return void
	 */
	public function test_save_batch_returns_zero_for_empty(): void {
		$result = $this->repository->save_batch( array() );

		$this->assertSame( 0, $result );
	}

	// =========================================================================
	// delete() Tests
	// =========================================================================

	/**
	 * Test delete returns true even for non-existent ID (idempotent delete).
	 *
	 * Note: Unlike EventRepository, OccurrenceRepository doesn't check existence
	 * before delete. wpdb->delete() returns 0 (not false) for no rows affected.
	 *
	 * @return void
	 */
	public function test_delete_returns_true_for_nonexistent(): void {
		$result = $this->repository->delete( 999999 );

		// wpdb->delete() returns 0 for no rows, which is truthy (!== false).
		$this->assertTrue( $result );
	}

	/**
	 * Test delete removes occurrence from database.
	 *
	 * @return void
	 */
	public function test_delete_removes_occurrence(): void {
		$event = $this->create_saved_event();
		$saved = $this->create_saved_occurrence( $event->id );

		$result = $this->repository->delete( $saved->id );

		$this->assertTrue( $result );

		// Fresh repo to bypass identity map.
		global $wpdb;
		$fresh_repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
		$found      = $fresh_repo->find( $saved->id );
		$this->assertNull( $found );
	}

	// =========================================================================
	// delete_for_event() Tests
	// =========================================================================

	/**
	 * Test delete_for_event removes all occurrences for event.
	 *
	 * @return void
	 */
	public function test_delete_for_event_removes_all(): void {
		$event = $this->create_saved_event();

		// Create multiple occurrences.
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->create_saved_occurrence(
				$event->id,
				array(
					'start_datetime' => "2030-09-0{$i} 19:00:00",
					'end_datetime'   => "2030-09-0{$i} 21:00:00",
				)
			);
		}

		$deleted = $this->repository->delete_for_event( $event->id );

		$this->assertSame( 3, $deleted );

		// Verify no occurrences remain.
		$remaining = $this->repository->for_event( $event->id );
		$this->assertEmpty( $remaining );
	}

	// =========================================================================
	// for_event() Tests
	// =========================================================================

	/**
	 * Test for_event returns occurrences for event.
	 *
	 * @return void
	 */
	public function test_for_event_returns_occurrences(): void {
		$event = $this->create_saved_event();

		$this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2030-10-01 19:00:00',
				'end_datetime'   => '2030-10-01 21:00:00',
			)
		);
		$this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2030-10-02 19:00:00',
				'end_datetime'   => '2030-10-02 21:00:00',
			)
		);

		$occurrences = $this->repository->for_event( $event->id );

		$this->assertCount( 2, $occurrences );
		$this->assertContainsOnlyInstancesOf( Occurrence::class, $occurrences );
	}

	/**
	 * Test for_event respects status filter.
	 *
	 * @return void
	 */
	public function test_for_event_filters_by_status(): void {
		$event = $this->create_saved_event();

		$this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2030-10-05 19:00:00',
				'end_datetime'   => '2030-10-05 21:00:00',
				'status'         => 'scheduled',
			)
		);
		$this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2030-10-06 19:00:00',
				'end_datetime'   => '2030-10-06 21:00:00',
				'status'         => 'cancelled',
			)
		);

		$scheduled = $this->repository->for_event( $event->id, array( 'status' => 'scheduled' ) );
		$cancelled = $this->repository->for_event( $event->id, array( 'status' => 'cancelled' ) );

		$this->assertCount( 1, $scheduled );
		$this->assertCount( 1, $cancelled );
	}

	/**
	 * Test for_event respects limit.
	 *
	 * @return void
	 */
	public function test_for_event_respects_limit(): void {
		$event = $this->create_saved_event();

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->create_saved_occurrence(
				$event->id,
				array(
					'start_datetime' => "2030-10-1{$i} 19:00:00",
					'end_datetime'   => "2030-10-1{$i} 21:00:00",
				)
			);
		}

		$occurrences = $this->repository->for_event( $event->id, array( 'limit' => 2 ) );

		$this->assertCount( 2, $occurrences );
	}

	// =========================================================================
	// in_range() Tests
	// =========================================================================

	/**
	 * Test in_range returns occurrences within date range.
	 *
	 * @return void
	 */
	public function test_in_range_returns_occurrences_in_range(): void {
		$event = $this->create_saved_event();

		// Inside range.
		$this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2030-11-15 19:00:00',
				'end_datetime'   => '2030-11-15 21:00:00',
			)
		);

		// Outside range (before).
		$this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2030-11-01 19:00:00',
				'end_datetime'   => '2030-11-01 21:00:00',
			)
		);

		// Outside range (after).
		$this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2030-11-30 19:00:00',
				'end_datetime'   => '2030-11-30 21:00:00',
			)
		);

		$occurrences = $this->repository->in_range( '2030-11-10', '2030-11-20' );

		$this->assertCount( 1, $occurrences );
		$this->assertStringContainsString( '2030-11-15', $occurrences[0]->start_datetime );
	}

	/**
	 * Test in_range filters by occurrence status.
	 *
	 * @return void
	 */
	public function test_in_range_filters_by_status(): void {
		$event = $this->create_saved_event();

		$this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2030-12-15 19:00:00',
				'end_datetime'   => '2030-12-15 21:00:00',
				'status'         => 'scheduled',
			)
		);
		$this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2030-12-16 19:00:00',
				'end_datetime'   => '2030-12-16 21:00:00',
				'status'         => 'cancelled',
			)
		);

		$scheduled = $this->repository->in_range(
			'2030-12-01',
			'2030-12-31',
			array( 'status' => 'scheduled' )
		);

		$this->assertCount( 1, $scheduled );
	}

	// =========================================================================
	// count_for_event() Tests
	// =========================================================================

	/**
	 * Test count_for_event returns integer.
	 *
	 * @return void
	 */
	public function test_count_for_event_returns_integer(): void {
		$event = $this->create_saved_event();

		$count = $this->repository->count_for_event( $event->id );

		$this->assertIsInt( $count );
	}

	/**
	 * Test count_for_event counts occurrences correctly.
	 *
	 * @return void
	 */
	public function test_count_for_event_counts_correctly(): void {
		$event = $this->create_saved_event();

		for ( $i = 1; $i <= 3; $i++ ) {
			$this->create_saved_occurrence(
				$event->id,
				array(
					'start_datetime' => "2031-01-0{$i} 19:00:00",
					'end_datetime'   => "2031-01-0{$i} 21:00:00",
				)
			);
		}

		$count = $this->repository->count_for_event( $event->id );

		$this->assertSame( 3, $count );
	}

	/**
	 * Test count_for_event filters by status.
	 *
	 * @return void
	 */
	public function test_count_for_event_filters_by_status(): void {
		$event = $this->create_saved_event();

		$this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2031-02-01 19:00:00',
				'end_datetime'   => '2031-02-01 21:00:00',
				'status'         => 'scheduled',
			)
		);
		$this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2031-02-02 19:00:00',
				'end_datetime'   => '2031-02-02 21:00:00',
				'status'         => 'cancelled',
			)
		);

		$scheduled_count = $this->repository->count_for_event( $event->id, 'scheduled' );
		$cancelled_count = $this->repository->count_for_event( $event->id, 'cancelled' );

		$this->assertSame( 1, $scheduled_count );
		$this->assertSame( 1, $cancelled_count );
	}

	// =========================================================================
	// update_status() Tests
	// =========================================================================

	/**
	 * Test update_status returns false for non-existent ID.
	 *
	 * @return void
	 */
	public function test_update_status_returns_false_for_nonexistent(): void {
		$result = $this->repository->update_status( 999999, 'cancelled' );

		$this->assertFalse( $result );
	}

	/**
	 * Test update_status returns false for invalid status.
	 *
	 * @return void
	 */
	public function test_update_status_returns_false_for_invalid_status(): void {
		$event = $this->create_saved_event();
		$saved = $this->create_saved_occurrence( $event->id );

		$result = $this->repository->update_status( $saved->id, 'invalid_status' );

		$this->assertFalse( $result );
	}

	/**
	 * Test update_status updates status correctly.
	 *
	 * @return void
	 */
	public function test_update_status_updates_correctly(): void {
		$event = $this->create_saved_event();
		$saved = $this->create_saved_occurrence( $event->id );

		$result = $this->repository->update_status( $saved->id, 'cancelled' );

		$this->assertTrue( $result );

		// Fresh repo to verify.
		global $wpdb;
		$fresh_repo = new OccurrenceRepository( $wpdb, new OccurrenceQueryRepository( $wpdb, new OccurrenceFilterRepository( $wpdb ) ) );
		$found      = $fresh_repo->find( $saved->id );
		$this->assertSame( 'cancelled', $found->status );
	}

	// =========================================================================
	// next_for_event() Tests
	// =========================================================================

	/**
	 * Test next_for_event returns null when no upcoming occurrences.
	 *
	 * @return void
	 */
	public function test_next_for_event_returns_null_when_none(): void {
		$event = $this->create_saved_event();

		$result = $this->repository->next_for_event( $event->id );

		$this->assertNull( $result );
	}

	/**
	 * Test next_for_event returns closest upcoming occurrence.
	 *
	 * @return void
	 */
	public function test_next_for_event_returns_closest_upcoming(): void {
		$event = $this->create_saved_event();

		// Further future.
		$this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2031-06-15 19:00:00',
				'end_datetime'   => '2031-06-15 21:00:00',
			)
		);

		// Closer future.
		$closer = $this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2031-03-15 19:00:00',
				'end_datetime'   => '2031-03-15 21:00:00',
			)
		);

		$next = $this->repository->next_for_event( $event->id );

		$this->assertNotNull( $next );
		$this->assertSame( $closer->id, $next->id );
	}

	// =========================================================================
	// for_event_grouped() Tests
	// =========================================================================

	/**
	 * Test for_event_grouped returns past and upcoming arrays.
	 *
	 * @return void
	 */
	public function test_for_event_grouped_returns_grouped_arrays(): void {
		$event = $this->create_saved_event();

		// Future occurrence.
		$this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2031-07-01 19:00:00',
				'end_datetime'   => '2031-07-01 21:00:00',
			)
		);

		$grouped = $this->repository->for_event_grouped( $event->id );

		$this->assertIsArray( $grouped );
		$this->assertArrayHasKey( 'past', $grouped );
		$this->assertArrayHasKey( 'upcoming', $grouped );
	}

	// =========================================================================
	// upcoming() Tests
	// =========================================================================

	/**
	 * Test upcoming returns upcoming occurrences.
	 *
	 * @return void
	 */
	public function test_upcoming_returns_upcoming_occurrences(): void {
		$event = $this->create_saved_event();

		$this->create_saved_occurrence(
			$event->id,
			array(
				'start_datetime' => '2031-08-01 19:00:00',
				'end_datetime'   => '2031-08-01 21:00:00',
			)
		);

		$upcoming = $this->repository->upcoming( 10 );

		$this->assertNotEmpty( $upcoming );
		$this->assertContainsOnlyInstancesOf( Occurrence::class, $upcoming );
	}

	/**
	 * Test upcoming respects limit.
	 *
	 * @return void
	 */
	public function test_upcoming_respects_limit(): void {
		$event = $this->create_saved_event();

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->create_saved_occurrence(
				$event->id,
				array(
					'start_datetime' => "2031-09-0{$i} 19:00:00",
					'end_datetime'   => "2031-09-0{$i} 21:00:00",
				)
			);
		}

		$upcoming = $this->repository->upcoming( 2 );

		$this->assertLessThanOrEqual( 2, count( $upcoming ) );
	}
}
