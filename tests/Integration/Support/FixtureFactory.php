<?php
/**
 * Shared fixture factory for integration tests.
 *
 * Each factory method exercises a real code path (repository save or
 * RecurrenceService) against the live database. No direct $wpdb inserts.
 *
 * Isolation: integration tests wrap each class in START TRANSACTION / ROLLBACK
 * (see NetterTechEventsIntegrationTestCase). tear_down() provides explicit
 * TRUNCATE for tests that need a clean slate within a single test method.
 *
 * @package NetterTechEvents\Tests\Integration\Support
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Support;

use NetterTechEvents\Database\Schema;
use NetterTechEvents\Models\Category;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Organizer;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\CategoryRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\OrganizerRepository;
use NetterTechEvents\Repositories\SpaceRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\OccurrenceGenerator;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\RRuleParser;

/**
 * Static factory helpers for integration test fixtures.
 *
 * All methods use real repository/service paths — no direct $wpdb inserts —
 * so the full production code path runs on every fixture creation.
 */
final class FixtureFactory {

	/**
	 * Create a single, non-recurring, published event.
	 *
	 * Routes through EventRepository::save(), exercising the real insert path
	 * including slug generation and all column mapping.
	 *
	 * @param array<string, mixed> $overrides Property overrides for the Event model.
	 * @return int Inserted event ID.
	 */
	public static function create_event( array $overrides = array() ): int {
		global $wpdb;

		$event             = new Event();
		$event->title      = $overrides['title'] ?? 'Test Event';
		$event->slug       = $overrides['slug'] ?? 'test-event-' . uniqid();
		$event->event_type = $overrides['event_type'] ?? 'single';
		$event->status     = $overrides['status'] ?? \NetterTechEvents\Enums\EventStatus::PUBLISHED;

		foreach ( $overrides as $key => $value ) {
			if ( property_exists( $event, $key ) ) {
				$event->$key = $value;
			}
		}

		$repo   = new EventRepository( $wpdb );
		$saved  = $repo->save( $event );

		return (int) $saved->id;
	}

	/**
	 * Create a recurring event and generate its occurrences.
	 *
	 * Routes through EventRepository::save() then RecurrenceService::generate_occurrences(),
	 * which internally calls OccurrenceRepository::save_batch(). This is the exact code path
	 * that NTE-008 (null coercion in batch insert UNIQUE column) would manifest on.
	 *
	 * @param string               $freq      RRULE FREQ value (e.g. 'WEEKLY', 'DAILY').
	 * @param int                  $count     Number of occurrences (RRULE COUNT).
	 * @param array<string, mixed> $overrides Property overrides for the Event model.
	 * @return int Inserted event ID.
	 */
	public static function create_recurring_event( string $freq, int $count, array $overrides = array() ): int {
		global $wpdb;

		$event                  = new Event();
		$event->title           = $overrides['title'] ?? 'Recurring Test Event';
		$event->slug            = $overrides['slug'] ?? 'recurring-test-' . uniqid();
		$event->event_type      = 'recurring';
		$event->status          = $overrides['status'] ?? \NetterTechEvents\Enums\EventStatus::PUBLISHED;
		$event->recurrence_rule = 'FREQ=' . strtoupper( $freq ) . ';COUNT=' . $count;

		foreach ( $overrides as $key => $value ) {
			if ( property_exists( $event, $key ) && ! in_array( $key, array( 'event_type', 'recurrence_rule' ), true ) ) {
				$event->$key = $value;
			}
		}

		$event_repo = new EventRepository( $wpdb );
		$saved      = $event_repo->save( $event );

		// Build RecurrenceService with real repositories — same wiring as production.
		$filter_repo      = new OccurrenceFilterRepository( $wpdb );
		$query_repo       = new OccurrenceQueryRepository( $wpdb, $filter_repo );
		$occurrence_repo  = new OccurrenceRepository( $wpdb, $query_repo );
		$ticket_type_repo = new TicketTypeRepository( $wpdb );
		$attendee_repo    = new AttendeeRepository( $wpdb );

		$recurrence_service = new RecurrenceService(
			new RRuleParser(),
			new OccurrenceGenerator(),
			$occurrence_repo,
			$ticket_type_repo,
			$attendee_repo
		);

		// Start tomorrow for a clean, future-dated series.
		$start = new \DateTimeImmutable( 'tomorrow 19:00:00' );
		$end   = new \DateTimeImmutable( 'tomorrow 21:00:00' );

		$recurrence_service->generate_occurrences(
			$saved,
			$start,
			$end,
			$saved->recurrence_rule
		);

		return (int) $saved->id;
	}

	/**
	 * Create a ticket type for an event.
	 *
	 * Routes through TicketTypeRepository::save(). No SaveHandler available for
	 * ticket types outside of the TicketSaveHandler metabox context (which requires
	 * a nonce and admin POST) — repository is the correct test-facing entry point.
	 *
	 * @param int                  $event_id  Event to attach the ticket type to.
	 * @param array<string, mixed> $overrides Property overrides for the TicketType model.
	 * @return int Inserted ticket type ID.
	 */
	public static function create_ticket_type( int $event_id, array $overrides = array() ): int {
		global $wpdb;

		$ticket_type                = new TicketType();
		$ticket_type->event_id      = $event_id;
		$ticket_type->name          = $overrides['name'] ?? 'General Admission';
		$ticket_type->price         = (float) ( $overrides['price'] ?? 10.00 );
		$ticket_type->capacity      = (int) ( $overrides['capacity'] ?? 50 );
		$ticket_type->capacity_type = $overrides['capacity_type'] ?? 'fixed';
		$ticket_type->status        = $overrides['status'] ?? 'active';

		foreach ( $overrides as $key => $value ) {
			if ( property_exists( $ticket_type, $key ) ) {
				$ticket_type->$key = $value;
			}
		}

		$repo  = new TicketTypeRepository( $wpdb );
		$saved = $repo->save( $ticket_type );

		return (int) $saved->id;
	}

	/**
	 * Create a category.
	 *
	 * Routes through CategoryRepository::save().
	 *
	 * @param string $name Category display name.
	 * @return int Inserted category ID.
	 */
	public static function create_category( string $name ): int {
		global $wpdb;

		$category              = new Category();
		$category->name        = $name;
		$category->slug        = sanitize_title( $name ) . '-' . uniqid();
		$category->description = '';

		$repo  = new CategoryRepository( $wpdb );
		$saved = $repo->save( $category );

		return (int) $saved->id;
	}

	/**
	 * Create an organizer.
	 *
	 * Routes through OrganizerRepository::save(). OrganizerSaveHandler requires
	 * a nonce + capability check (admin POST form) and cannot be called directly
	 * in test context. The repository is the correct test-facing entry point.
	 *
	 * @param string $name Organizer display name.
	 * @return int Inserted organizer ID.
	 */
	public static function create_organizer( string $name ): int {
		global $wpdb;

		$organizer       = new Organizer();
		$organizer->name = $name;
		$organizer->slug = sanitize_title( $name ) . '-' . uniqid();

		$repo  = new OrganizerRepository( $wpdb );
		$saved = $repo->save( $organizer );

		return (int) $saved->id;
	}

	/**
	 * Create a space (venue room/location).
	 *
	 * Routes through SpaceRepository::save(). SpaceSaveHandler requires a nonce
	 * + capability check and cannot be called directly in test context. The
	 * repository is the correct test-facing entry point.
	 *
	 * @param string $name     Space display name.
	 * @param int    $capacity Maximum occupancy.
	 * @return int Inserted space ID.
	 */
	public static function create_space( string $name, int $capacity = 100 ): int {
		global $wpdb;

		$repo = new SpaceRepository( $wpdb );

		return (int) $repo->save( array(
			'name'     => $name,
			'slug'     => sanitize_title( $name ) . '-' . uniqid(),
			'capacity' => $capacity,
		) );
	}

	/**
	 * Suffix a database name must carry to be considered disposable.
	 *
	 * @var string
	 */
	private const DISPOSABLE_DB_SUFFIX = '_test';

	/**
	 * Truncate all wp_nettertech_events_* tables.
	 *
	 * Call in tearDown() when a test needs a fully clean state rather than
	 * relying on the transaction rollback (e.g., tests that commit mid-run).
	 *
	 * Pattern is derived from Schema::TABLE_PREFIX rather than hard-coded so
	 * a future table-prefix change updates here automatically.
	 *
	 * @return void
	 */
	public static function tear_down(): void {
		global $wpdb;

		self::assert_database_is_disposable();

		// Disable FK checks so TRUNCATE order doesn't matter.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );

		$pattern = $wpdb->esc_like( $wpdb->prefix . \NetterTechEvents\Database\Schema::TABLE_PREFIX ) . '%';
		$tables  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );

		foreach ( $tables as $table ) {
			$wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from SHOW TABLES with an esc_like-bound prefix pattern; no user input path.
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
	}

	/**
	 * Refuse to truncate a database that is not disposable (NTE-146).
	 *
	 * TRUNCATE is DDL: MySQL issues an implicit COMMIT before executing it, so it
	 * is NOT covered by the START TRANSACTION / ROLLBACK isolation in
	 * NetterTechEventsIntegrationTestCase. Every other safety in this suite assumes
	 * rollback, and this one method walks straight through it — pointed at a live
	 * dev database it once destroyed every occurrence, ticket type and attendee,
	 * including a fixture hand-seeded through a real Woo checkout.
	 *
	 * The name check is the guard rather than the default in wp-tests-config.php,
	 * because a default can be overridden by an env var or a stray export, and the
	 * failure is silent and irreversible. This one is neither.
	 *
	 * @throws \RuntimeException When the connected database is not marked disposable.
	 * @return void
	 */
	private static function assert_database_is_disposable(): void {
		$database = defined( 'DB_NAME' ) ? (string) DB_NAME : '';

		if ( str_ends_with( $database, self::DISPOSABLE_DB_SUFFIX ) ) {
			return;
		}

		throw new \RuntimeException(
			sprintf(
				'FixtureFactory::tear_down() refuses to TRUNCATE database "%s": it is not disposable. '
				. 'This method truncates EVERY %s* table, and TRUNCATE cannot be rolled back. '
				. 'Integration tests must run against a database whose name ends in "%s" '
				. '(set NTE_TEST_DB_NAME, default local_nte_test). See ticket NTE-146.',
				'' === $database ? '(undefined)' : $database,
				\NetterTechEvents\Database\Schema::TABLE_PREFIX,
				self::DISPOSABLE_DB_SUFFIX
			)
		);
	}
}
