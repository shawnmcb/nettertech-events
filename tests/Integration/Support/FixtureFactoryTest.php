<?php
/**
 * FixtureFactory smoke tests.
 *
 * Three assertions that verify the FixtureFactory creates real DB rows and
 * that tear_down() empties them. test_create_recurring_event_generates_occurrences
 * would have caught NTE-008 (null coercion in batch insert UNIQUE column) on
 * the very first run — it asserts the exact COUNT that was silently dropped.
 *
 * @package NetterTechEvents\Tests\Integration\Support
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Support;

use NetterTechEvents\Database\Schema;

/**
 * Smoke tests for FixtureFactory.
 */
class FixtureFactoryTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * Use explicit TRUNCATE isolation rather than transaction rollback so
	 * tear_down() itself can be exercised inside a test.
	 *
	 * @var bool
	 */
	protected static bool $use_transactions = false;

	/**
	 * Clean tables before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		FixtureFactory::tear_down();
	}

	/**
	 * Clean tables after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		FixtureFactory::tear_down();
		parent::tearDown();
	}

	// =========================================================================
	// Smoke test 1 — single event insert
	// =========================================================================

	/**
	 * create_event() returns an ID and a row exists in wp_nettertech_events_events.
	 *
	 * @return void
	 */
	public function test_create_event_inserts_row(): void {
		$wpdb     = $this->get_wpdb();
		$event_id = FixtureFactory::create_event( array( 'title' => 'Smoke Event' ) );

		$this->assertGreaterThan( 0, $event_id, 'create_event() should return a positive integer ID' );

		$table = Schema::table( 'events' );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$event_id
			)
		);

		$this->assertSame( 1, $count, 'Exactly one row should exist for the created event' );
	}

	// =========================================================================
	// Smoke test 2 — recurring event generates correct occurrence count (NTE-008)
	// =========================================================================

	/**
	 * create_recurring_event('WEEKLY', 10) generates exactly 10 occurrences.
	 *
	 * This assertion directly exercises the NTE-008 bug surface: if the null
	 * coercion in batch_insert_occurrences were still present, the UNIQUE
	 * constraint on checkin_token would silently discard rows 2-10, leaving
	 * COUNT(*) = 1 and the assertion would fail.
	 *
	 * @return void
	 */
	public function test_create_recurring_event_generates_occurrences(): void {
		$wpdb     = $this->get_wpdb();
		$event_id = FixtureFactory::create_recurring_event( 'WEEKLY', 10 );

		$this->assertGreaterThan( 0, $event_id, 'create_recurring_event() should return a positive integer ID' );

		$table = Schema::table( 'occurrences' );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE event_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$event_id
			)
		);

		$this->assertSame(
			10,
			$count,
			'Exactly 10 occurrence rows should exist — if this fails with count=1, NTE-008 has regressed'
		);
	}

	// =========================================================================
	// Smoke test 3 — tear_down empties tables
	// =========================================================================

	/**
	 * tear_down() empties wp_nettertech_events_events and wp_nettertech_events_occurrences.
	 *
	 * Verifies that the explicit TRUNCATE approach provides a clean slate
	 * independent of the transaction rollback mechanism.
	 *
	 * @return void
	 */
	public function test_tear_down_empties_tables(): void {
		$wpdb = $this->get_wpdb();

		// Create some data so there's something to truncate.
		FixtureFactory::create_recurring_event( 'DAILY', 3 );

		$events_table      = Schema::table( 'events' );
		$occurrences_table = Schema::table( 'occurrences' );

		// Sanity: data exists before tear_down.
		$pre_events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertGreaterThan( 0, $pre_events, 'Events table should have rows before tear_down' );

		FixtureFactory::tear_down();

		$post_events      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_occurrences = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$occurrences_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 0, $post_events, 'wp_nettertech_events_events should be empty after tear_down()' );
		$this->assertSame( 0, $post_occurrences, 'wp_nettertech_events_occurrences should be empty after tear_down()' );
	}
}
