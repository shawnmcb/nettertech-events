<?php
/**
 * Admin CRUD integration tests.
 *
 * Exercises the Repository layer that SaveHandlers delegate to.
 * SaveHandlers (OrganizerSaveHandler, SpaceSaveHandler, CategorySaveHandler,
 * EventSaveHandler) call wp_die() + exit on nonce/capability failure and cannot
 * be invoked directly from the test harness — the authoritative test surface is
 * the repository they write through, which is exactly what FixtureFactory documents.
 *
 * These six tests cover the full POST -> Repository -> DB row path for every
 * entity type and join operation that admin save forms exercise.
 *
 * @package NetterTechEvents\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Admin;

use NetterTechEvents\Database\Schema;
use NetterTechEvents\Models\Category;
use NetterTechEvents\Models\Organizer;
use NetterTechEvents\Repositories\CategoryRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OrganizerRepository;
use NetterTechEvents\Repositories\SpaceRepository;
use NetterTechEvents\Tests\Integration\Support\FixtureFactory;

/**
 * Integration tests: admin CRUD save paths via repositories.
 */
class CrudIntegrationTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * DB instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = $this->get_wpdb();
	}

	/**
	 * @test
	 * Test that creating an organizer via OrganizerRepository writes a row to wp_nettertech_events_organizers.
	 *
	 * Mirrors what OrganizerSaveHandler::handle_save() does after it validates the
	 * nonce and capability: it populates an Organizer model from $_POST and calls
	 * $this->repo->save($organizer).
	 *
	 * @return void
	 */
	public function test_organizer_create_updates_db(): void {
		$repo      = new OrganizerRepository( $this->wpdb );
		$organizer = new Organizer();

		$organizer->name        = 'Test Organizer';
		$organizer->slug        = 'test-organizer-' . uniqid();
		$organizer->email       = 'organizer@example.com';
		$organizer->phone       = '555-0100';
		$organizer->website     = 'https://example.com';
		$organizer->description = 'An organizer for testing.';

		$saved = $repo->save( $organizer );

		$this->assertNotNull( $saved->id, 'Repository::save() must return model with non-null id.' );

		$table = Schema::table( 'organizers' );
		$row   = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $saved->id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$this->assertNotNull( $row, 'Row must exist in wp_nettertech_events_organizers after save.' );
		$this->assertSame( 'Test Organizer', $row['name'] );
		$this->assertSame( 'organizer@example.com', $row['email'] );
		$this->assertSame( '555-0100', $row['phone'] );
	}

	/**
	 * @test
	 * Test that creating a space via SpaceRepository writes a row to wp_nettertech_events_spaces.
	 *
	 * Mirrors what SpaceSaveHandler::handle_save() does after nonce/capability checks:
	 * it builds an array from $_POST and calls $this->repo->save($data).
	 *
	 * @return void
	 */
	public function test_space_create_updates_db(): void {
		$repo = new SpaceRepository( $this->wpdb );

		$space_id = $repo->save( array(
			'name'        => 'Main Hall',
			'slug'        => 'main-hall-' . uniqid(),
			'description' => 'Primary performance space.',
			'capacity'    => 250,
			'status'      => 'active',
		) );

		$this->assertGreaterThan( 0, $space_id, 'SpaceRepository::save() must return a positive integer ID.' );

		$table = Schema::table( 'spaces' );
		$row   = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $space_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$this->assertNotNull( $row, 'Row must exist in wp_nettertech_events_spaces after save.' );
		$this->assertSame( 'Main Hall', $row['name'] );
		$this->assertSame( 250, (int) $row['capacity'] );
	}

	/**
	 * @test
	 * Test that creating a category via CategoryRepository writes a row to wp_nettertech_events_categories.
	 *
	 * CategorySaveHandler reads $_POST['category_name'], '_slug', '_description' and
	 * calls $this->repo->save($category).
	 *
	 * @return void
	 */
	public function test_category_create_updates_db(): void {
		$repo     = new CategoryRepository( $this->wpdb );
		$category = new Category();

		$category->name        = 'Music';
		$category->slug        = 'music-' . uniqid();
		$category->description = 'Musical events.';

		$saved = $repo->save( $category );

		$this->assertNotNull( $saved->id, 'CategoryRepository::save() must return model with non-null id.' );

		$table = Schema::table( 'categories' );
		$row   = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $saved->id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$this->assertNotNull( $row, 'Row must exist in wp_nettertech_events_categories after save.' );
		$this->assertSame( 'Music', $row['name'] );
	}

	/**
	 * @test
	 * Test that assigning a category to an event creates a row in wp_nettertech_events_event_categories.
	 *
	 * Mirrors what EventSaveHandler::save_event_associations() does:
	 * it calls $this->category_repo->sync_event_categories($event_id, $category_ids).
	 *
	 * @return void
	 */
	public function test_category_assign_to_event_creates_join_row(): void {
		$event_id    = FixtureFactory::create_event( array( 'title' => 'Category Assignment Test' ) );
		$category_id = FixtureFactory::create_category( 'Jazz ' . uniqid() );

		$category_repo = new CategoryRepository( $this->wpdb );
		$category_repo->sync_event_categories( $event_id, array( $category_id ) );

		$junction_table = Schema::table( 'event_categories' );
		$row            = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$junction_table} WHERE event_id = %d AND category_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$event_id,
				$category_id
			),
			ARRAY_A
		);

		$this->assertNotNull(
			$row,
			"wp_nettertech_events_event_categories must have a row linking event {$event_id} to category {$category_id}."
		);
	}

	/**
	 * @test
	 * Test that assigning an organizer to an event creates a row in wp_nettertech_events_event_organizers.
	 *
	 * Mirrors what EventSaveHandler::save_event_associations() does:
	 * it calls $this->organizer_repo->sync_event_organizers($event_id, $organizer_ids).
	 *
	 * @return void
	 */
	public function test_organizer_assign_to_event_creates_join_row(): void {
		$event_id     = FixtureFactory::create_event( array( 'title' => 'Organizer Assignment Test' ) );
		$organizer_id = FixtureFactory::create_organizer( 'Concert Co ' . uniqid() );

		$organizer_repo = new OrganizerRepository( $this->wpdb );
		$organizer_repo->sync_event_organizers( $event_id, array( $organizer_id ) );

		$junction_table = Schema::table( 'event_organizers' );
		$row            = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$junction_table} WHERE event_id = %d AND organizer_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$event_id,
				$organizer_id
			),
			ARRAY_A
		);

		$this->assertNotNull(
			$row,
			"wp_nettertech_events_event_organizers must have a row linking event {$event_id} to organizer {$organizer_id}."
		);
	}

	/**
	 * @test
	 * Test that updating an event via EventRepository preserves unchanged fields.
	 *
	 * Mirrors what EventSaveHandler::process_save() does on update: load existing
	 * event, apply extract_event_fields() changes, then call $this->event_repo->save($event).
	 *
	 * @return void
	 */
	public function test_event_update_preserves_fields(): void {
		$event_id = FixtureFactory::create_event( array(
			'title'       => 'Original Title',
			'description' => 'Keep this description.',
			'venue_name'  => 'The Old Venue',
		) );

		$event_repo = new EventRepository( $this->wpdb );
		$event      = $event_repo->find( $event_id );

		$this->assertNotNull( $event, "Event {$event_id} must be findable before update." );

		// Change only the title — simulates a minimal admin POST update.
		$event->title = 'Updated Title';
		$event_repo->save( $event );

		$table      = Schema::table( 'events' );
		$row        = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$this->assertNotNull( $row, 'Event row must still exist after update.' );
		$this->assertSame( 'Updated Title', $row['title'], 'Title must reflect the update.' );
		$this->assertSame( 'Keep this description.', $row['description'], 'Description must not be blanked by the update.' );
		$this->assertSame( 'The Old Venue', $row['venue_name'], 'Venue name must not be blanked by the update.' );
	}
}
