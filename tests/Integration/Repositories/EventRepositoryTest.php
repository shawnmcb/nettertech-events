<?php
/**
 * EventRepository integration tests.
 *
 * Tests repository CRUD operations with a real database.
 *
 * @package NetterTechEvents\Tests\Integration\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Repositories;

use NetterTechEvents\Models\Event;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Tests\Factories\EventFactory;

/**
 * Integration tests for EventRepository.
 *
 * Note: Uses class-level transactions for isolation.
 * Data created in tests is rolled back after the test class completes.
 */
class EventRepositoryTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * EventRepository instance.
	 *
	 * @var EventRepository
	 */
	private EventRepository $repository;

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

		$this->repository  = new EventRepository( $wpdb );
		$this->test_prefix = 'test-' . time() . '-' . mt_rand( 1000, 9999 ) . '-';
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

		$event = EventFactory::create( array_merge( $defaults, $attributes ) );
		$event->id = null; // Ensure insert, not update.

		return $this->repository->save( $event );
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
	 * Test find returns Event for existing ID.
	 *
	 * @return void
	 */
	public function test_find_returns_event_for_existing_id(): void {
		$saved = $this->create_saved_event( array( 'title' => 'Find Test Event' ) );

		$found = $this->repository->find( $saved->id );

		$this->assertNotNull( $found );
		$this->assertInstanceOf( Event::class, $found );
		$this->assertSame( $saved->id, $found->id );
		$this->assertSame( 'Find Test Event', $found->title );
	}

	/**
	 * Test find uses identity map for caching.
	 *
	 * @return void
	 */
	public function test_find_uses_identity_map(): void {
		$saved = $this->create_saved_event();

		$first  = $this->repository->find( $saved->id );
		$second = $this->repository->find( $saved->id );

		$this->assertSame( $first, $second );
	}

	// =========================================================================
	// find_by_slug() Tests
	// =========================================================================

	/**
	 * Test find_by_slug returns null for non-existent slug.
	 *
	 * @return void
	 */
	public function test_find_by_slug_returns_null_for_nonexistent(): void {
		$result = $this->repository->find_by_slug( 'nonexistent-slug-12345' );

		$this->assertNull( $result );
	}

	/**
	 * Test find_by_slug returns Event for existing slug.
	 *
	 * @return void
	 */
	public function test_find_by_slug_returns_event(): void {
		$slug  = $this->unique_slug( 'slug-test' );
		$saved = $this->create_saved_event( array( 'slug' => $slug ) );

		$found = $this->repository->find_by_slug( $slug );

		$this->assertNotNull( $found );
		$this->assertSame( $saved->id, $found->id );
		$this->assertSame( $slug, $found->slug );
	}

	// =========================================================================
	// save() Tests - Insert
	// =========================================================================

	/**
	 * Test save inserts new event and returns ID.
	 *
	 * @return void
	 */
	public function test_save_inserts_new_event(): void {
		$event        = EventFactory::create();
		$event->id    = null;
		$event->title = 'New Event Save Test';
		$event->slug  = $this->unique_slug( 'save-insert' );

		$saved = $this->repository->save( $event );

		$this->assertNotNull( $saved->id );
		$this->assertGreaterThan( 0, $saved->id );
	}

	// =========================================================================
	// save() Tests - Update
	// =========================================================================

	/**
	 * Test save updates existing event.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_event(): void {
		$saved       = $this->create_saved_event();
		$original_id = $saved->id;

		$saved->title = 'Updated Title';
		$updated      = $this->repository->save( $saved );

		$this->assertSame( $original_id, $updated->id );

		// Fresh find to bypass identity map.
		global $wpdb;
		$fresh_repo = new EventRepository( $wpdb );
		$found      = $fresh_repo->find( $updated->id );
		$this->assertSame( 'Updated Title', $found->title );
	}

	// =========================================================================
	// delete() Tests
	// =========================================================================

	/**
	 * Test delete returns false for non-existent ID.
	 *
	 * @return void
	 */
	public function test_delete_returns_false_for_nonexistent(): void {
		$result = $this->repository->delete( 999999 );

		$this->assertFalse( $result );
	}

	/**
	 * Test delete removes event from database.
	 *
	 * @return void
	 */
	public function test_delete_removes_event(): void {
		$saved = $this->create_saved_event();

		$result = $this->repository->delete( $saved->id );

		$this->assertTrue( $result );

		// Fresh repo to bypass identity map.
		global $wpdb;
		$fresh_repo = new EventRepository( $wpdb );
		$found      = $fresh_repo->find( $saved->id );
		$this->assertNull( $found );
	}

	// =========================================================================
	// all() Tests
	// =========================================================================

	/**
	 * Test all returns array of events.
	 *
	 * @return void
	 */
	public function test_all_returns_events_array(): void {
		// Create a few events with unique prefix.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_saved_event( array( 'slug' => $this->unique_slug( "all-{$i}" ) ) );
		}

		$events = $this->repository->all( array( 'limit' => 100 ) );

		$this->assertIsArray( $events );
		$this->assertGreaterThanOrEqual( 3, count( $events ) );
		$this->assertContainsOnlyInstancesOf( Event::class, $events );
	}

	/**
	 * Test all respects limit.
	 *
	 * @return void
	 */
	public function test_all_respects_limit(): void {
		// Create several events.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->create_saved_event( array( 'slug' => $this->unique_slug( "limit-{$i}" ) ) );
		}

		$events = $this->repository->all( array( 'limit' => 2 ) );

		$this->assertLessThanOrEqual( 2, count( $events ) );
	}

	// =========================================================================
	// count() Tests
	// =========================================================================

	/**
	 * Test count returns integer.
	 *
	 * @return void
	 */
	public function test_count_returns_integer(): void {
		$count = $this->repository->count();

		$this->assertIsInt( $count );
	}

	/**
	 * Test count changes when events are added.
	 *
	 * @return void
	 */
	public function test_count_increases_after_insert(): void {
		$initial = $this->repository->count();

		$this->create_saved_event();

		$after = $this->repository->count();

		$this->assertSame( $initial + 1, $after );
	}

	// =========================================================================
	// slug_exists() Tests
	// =========================================================================

	/**
	 * Test slug_exists returns false for unused slug.
	 *
	 * @return void
	 */
	public function test_slug_exists_returns_false_for_unused(): void {
		$result = $this->repository->slug_exists( 'definitely-not-used-' . time() . '-' . mt_rand() );

		$this->assertFalse( $result );
	}

	/**
	 * Test slug_exists returns true for used slug.
	 *
	 * @return void
	 */
	public function test_slug_exists_returns_true_for_used(): void {
		$slug = $this->unique_slug( 'exists-test' );
		$this->create_saved_event( array( 'slug' => $slug ) );

		$result = $this->repository->slug_exists( $slug );

		$this->assertTrue( $result );
	}

	/**
	 * Test slug_exists excludes specified ID.
	 *
	 * @return void
	 */
	public function test_slug_exists_excludes_id(): void {
		$slug  = $this->unique_slug( 'exclude-test' );
		$saved = $this->create_saved_event( array( 'slug' => $slug ) );

		$result = $this->repository->slug_exists( $slug, $saved->id );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// search() Tests
	// =========================================================================

	/**
	 * Test search returns matching events.
	 *
	 * @return void
	 */
	public function test_search_returns_matching_events(): void {
		$unique_term = 'UniqueSearchTerm' . time();
		$this->create_saved_event(
			array(
				'title' => "Concert with {$unique_term}",
				'slug'  => $this->unique_slug( 'search-match' ),
			)
		);

		$results = $this->repository->search( $unique_term );

		$this->assertNotEmpty( $results );
		$found = false;
		foreach ( $results as $result ) {
			if ( str_contains( $result->title, $unique_term ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Search should find the event' );
	}

	/**
	 * Test search returns empty array for no matches.
	 *
	 * @return void
	 */
	public function test_search_returns_empty_for_no_matches(): void {
		$results = $this->repository->search( 'xyz-impossible-term-' . time() . '-' . mt_rand() );

		$this->assertIsArray( $results );
		$this->assertEmpty( $results );
	}
}
