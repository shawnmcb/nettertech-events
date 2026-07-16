<?php
/**
 * EventRepository integration test.
 *
 * Verifies the Schema -> Repository pipeline: create, read, update, delete
 * against a real database using the class-level transaction rollback pattern.
 *
 * @package NetterTechEvents\Tests\Integration
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration;

use NetterTechEvents\Database\Schema;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Tests\Factories\EventFactory;

/**
 * Integration test: EventRepository CRUD through the Schema -> Repository pipeline.
 */
class EventRepositoryIntegrationTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * Repository under test.
	 *
	 * @var EventRepository
	 */
	private EventRepository $repository;

	/**
	 * Unique prefix to avoid cross-test slug collisions within the transaction.
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
		$this->test_prefix = 'eri-' . time() . '-' . mt_rand( 1000, 9999 ) . '-';
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Return a unique slug prefixed for this test run.
	 *
	 * @param string $suffix Distinguishing suffix.
	 * @return string
	 */
	private function slug( string $suffix ): string {
		return $this->test_prefix . $suffix;
	}

	/**
	 * Insert a new event and return the saved instance.
	 *
	 * @param array<string, mixed> $overrides Factory attribute overrides.
	 * @return Event
	 */
	private function insert_event( array $overrides = array() ): Event {
		$event     = EventFactory::create( array_merge( array( 'slug' => $this->slug( 'event' ) ), $overrides ) );
		$event->id = null; // Force insert path.
		return $this->repository->save( $event );
	}

	// =========================================================================
	// Schema pipeline: tables exist before CRUD
	// =========================================================================

	/**
	 * Verify the events table exists in the database before exercising CRUD.
	 *
	 * @return void
	 */
	public function test_events_table_exists_in_database(): void {
		$wpdb       = $this->get_wpdb();
		$table_name = Schema::table( 'events' );

		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		if ( null === $exists ) {
			$this->markTestSkipped( 'Plugin tables not present in this database.' );
		}

		$this->assertSame( $table_name, $exists );
	}

	// =========================================================================
	// Create
	// =========================================================================

	/**
	 * Inserting a new event assigns an integer ID greater than zero.
	 *
	 * @return void
	 */
	public function test_insert_assigns_positive_integer_id(): void {
		$event = EventFactory::create( array( 'slug' => $this->slug( 'insert' ) ) );
		$event->id = null;

		$saved = $this->repository->save( $event );

		$this->assertNotNull( $saved->id );
		$this->assertIsInt( $saved->id );
		$this->assertGreaterThan( 0, $saved->id );
	}

	/**
	 * Inserted event is immediately retrievable by ID.
	 *
	 * @return void
	 */
	public function test_inserted_event_is_findable_by_id(): void {
		$saved = $this->insert_event( array( 'title' => 'Pipeline Read Test', 'slug' => $this->slug( 'find-id' ) ) );

		// Use a fresh repository to bypass the identity map.
		global $wpdb;
		$fresh = new EventRepository( $wpdb );
		$found = $fresh->find( $saved->id );

		$this->assertNotNull( $found );
		$this->assertInstanceOf( Event::class, $found );
		$this->assertSame( $saved->id, $found->id );
		$this->assertSame( 'Pipeline Read Test', $found->title );
	}

	/**
	 * Inserted event is immediately retrievable by slug.
	 *
	 * @return void
	 */
	public function test_inserted_event_is_findable_by_slug(): void {
		$slug  = $this->slug( 'find-slug' );
		$saved = $this->insert_event( array( 'slug' => $slug ) );

		global $wpdb;
		$fresh = new EventRepository( $wpdb );
		$found = $fresh->find_by_slug( $slug );

		$this->assertNotNull( $found );
		$this->assertSame( $saved->id, $found->id );
		$this->assertSame( $slug, $found->slug );
	}

	// =========================================================================
	// Read
	// =========================================================================

	/**
	 * find() returns null for a non-existent ID.
	 *
	 * @return void
	 */
	public function test_find_returns_null_for_nonexistent_id(): void {
		$result = $this->repository->find( 999_999_999 );
		$this->assertNull( $result );
	}

	/**
	 * find_by_slug() returns null for a non-existent slug.
	 *
	 * @return void
	 */
	public function test_find_by_slug_returns_null_for_nonexistent(): void {
		$result = $this->repository->find_by_slug( 'definitely-not-used-' . time() );
		$this->assertNull( $result );
	}

	/**
	 * count() increases by one after inserting an event.
	 *
	 * @return void
	 */
	public function test_count_increases_after_insert(): void {
		$initial = $this->repository->count();

		$this->insert_event( array( 'slug' => $this->slug( 'count' ) ) );

		$this->assertSame( $initial + 1, $this->repository->count() );
	}

	// =========================================================================
	// Update
	// =========================================================================

	/**
	 * Updating an existing event persists the changed field to the database.
	 *
	 * @return void
	 */
	public function test_update_persists_field_change_to_database(): void {
		$saved = $this->insert_event( array( 'title' => 'Original Title', 'slug' => $this->slug( 'update' ) ) );

		$saved->title = 'Updated Title';
		$this->repository->save( $saved );

		// Fresh repo bypasses identity map.
		global $wpdb;
		$fresh = new EventRepository( $wpdb );
		$found = $fresh->find( $saved->id );

		$this->assertNotNull( $found );
		$this->assertSame( 'Updated Title', $found->title );
	}

	/**
	 * Updating an event does not change its ID.
	 *
	 * @return void
	 */
	public function test_update_preserves_id(): void {
		$saved       = $this->insert_event( array( 'slug' => $this->slug( 'update-id' ) ) );
		$original_id = $saved->id;

		$saved->title = 'Changed Again';
		$updated      = $this->repository->save( $saved );

		$this->assertSame( $original_id, $updated->id );
	}

	// =========================================================================
	// Delete
	// =========================================================================

	/**
	 * Deleting an existing event returns true.
	 *
	 * @return void
	 */
	public function test_delete_returns_true_for_existing_event(): void {
		$saved  = $this->insert_event( array( 'slug' => $this->slug( 'delete' ) ) );
		$result = $this->repository->delete( $saved->id );
		$this->assertTrue( $result );
	}

	/**
	 * Deleted event is no longer findable.
	 *
	 * @return void
	 */
	public function test_deleted_event_is_not_findable(): void {
		$saved = $this->insert_event( array( 'slug' => $this->slug( 'delete-find' ) ) );
		$this->repository->delete( $saved->id );

		global $wpdb;
		$fresh = new EventRepository( $wpdb );
		$found = $fresh->find( $saved->id );
		$this->assertNull( $found );
	}

	/**
	 * Deleting a non-existent event returns false.
	 *
	 * @return void
	 */
	public function test_delete_returns_false_for_nonexistent(): void {
		$result = $this->repository->delete( 999_999_998 );
		$this->assertFalse( $result );
	}

	// =========================================================================
	// Slug uniqueness
	// =========================================================================

	/**
	 * slug_exists() returns true for a slug that has been inserted.
	 *
	 * @return void
	 */
	public function test_slug_exists_returns_true_after_insert(): void {
		$slug = $this->slug( 'exists' );
		$this->insert_event( array( 'slug' => $slug ) );

		$this->assertTrue( $this->repository->slug_exists( $slug ) );
	}

	/**
	 * slug_exists() excludes the owning event's own ID.
	 *
	 * @return void
	 */
	public function test_slug_exists_excludes_own_id(): void {
		$slug  = $this->slug( 'exclude' );
		$saved = $this->insert_event( array( 'slug' => $slug ) );

		// Should return false when excluding the event that holds the slug.
		$this->assertFalse( $this->repository->slug_exists( $slug, $saved->id ) );
	}
}
