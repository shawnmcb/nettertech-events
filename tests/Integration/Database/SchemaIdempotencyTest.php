<?php
/**
 * Schema migration idempotency integration test (INV-R1).
 *
 * Running Schema::migrate() against an already-migrated database must be a
 * no-op: same db_version, no errors, no data change. Re-entrant migrations
 * fire in production on plugin re-activation and partial upgrades, so this
 * property is load-bearing (cf. the ve_/nte_/nettertech_ rename lineage and
 * the INMN 1.0.0 → 1.1.0.x upgrade, 2026-06-11).
 *
 * @package NetterTechEvents\Tests\Integration\Database
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Database;

use NetterTechEvents\Database\Schema;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;

/**
 * Integration test: migrations are idempotent against the live test DB.
 */
class SchemaIdempotencyTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * Seed an event with occurrences so row checksums are non-trivial.
	 *
	 * Without seeded rows, GROUP_CONCAT over an empty table returns NULL on
	 * both sides and the idempotency assertions pass vacuously.
	 *
	 * @return void
	 */
	private function seed_occurrences(): void {
		global $wpdb;

		$filter_repo     = new OccurrenceFilterRepository( $wpdb );
		$query_repo      = new OccurrenceQueryRepository( $wpdb, $filter_repo );
		$occurrence_repo = new OccurrenceRepository( $wpdb, $query_repo );
		$event_repo      = new EventRepository( $wpdb );

		$event     = EventFactory::create( array( 'slug' => 'idempotency-seed-' . uniqid(), 'status' => 'published' ) );
		$event->id = null;
		$event     = $event_repo->save( $event );

		foreach ( array( '+1 week', '+2 weeks', '+3 weeks' ) as $offset ) {
			$occ           = OccurrenceFactory::create(
				array(
					'event_id'       => $event->id,
					'start_datetime' => gmdate( 'Y-m-d 19:00:00', strtotime( $offset ) ),
					'end_datetime'   => gmdate( 'Y-m-d 21:00:00', strtotime( $offset ) ),
				)
			);
			$occ->id       = null;
			$occ->event_id = $event->id;
			$occurrence_repo->save( $occ );
		}
	}

	/**
	 * A current database reports no pending migration.
	 *
	 * @return void
	 */
	public function test_current_database_needs_no_migration(): void {
		$this->assertSame( Schema::DB_VERSION, get_option( 'nettertech_events_db_version' ) );
		$this->assertFalse( Schema::needs_migration() );
	}

	/**
	 * Calling migrate() twice on a current database changes nothing.
	 *
	 * @return void
	 */
	public function test_migrate_is_idempotent_on_current_database(): void {
		global $wpdb;

		$this->seed_occurrences();

		$version_before     = get_option( 'nettertech_events_db_version' );
		$occurrences_table  = Schema::table( 'occurrences' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integration checksum over trusted table name.
		$checksum_before = $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(id, start_datetime, end_datetime, timezone, status ORDER BY id)) FROM {$occurrences_table}" );

		Schema::migrate();
		Schema::migrate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integration checksum over trusted table name.
		$checksum_after = $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(id, start_datetime, end_datetime, timezone, status ORDER BY id)) FROM {$occurrences_table}" );

		$this->assertSame( $version_before, get_option( 'nettertech_events_db_version' ) );
		$this->assertSame( $checksum_before, $checksum_after, 'Occurrence rows must be untouched by re-entrant migrate().' );
		$this->assertSame( '', $wpdb->last_error, 'Re-entrant migrate() must not produce database errors.' );
	}

	/**
	 * Re-running the 3.14.0 timezone backfill directly is a no-op.
	 *
	 * The migration's own doc comment claims idempotency ("re-run sets the
	 * same value"); this pins the claim.
	 *
	 * @return void
	 */
	public function test_timezone_backfill_rerun_is_noop(): void {
		global $wpdb;

		$this->seed_occurrences();

		$occurrences_table = Schema::table( 'occurrences' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integration checksum over trusted table name.
		$tz_before = $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(id, timezone ORDER BY id)) FROM {$occurrences_table}" );

		$method = ( new \ReflectionClass( Schema::class ) )->getMethod( 'migrate_to_3_14_0' );
		$method->invoke( null );
		$method->invoke( null );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integration checksum over trusted table name.
		$tz_after = $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(id, timezone ORDER BY id)) FROM {$occurrences_table}" );

		$this->assertSame( $tz_before, $tz_after );
		$this->assertSame( '', $wpdb->last_error );
	}
}
