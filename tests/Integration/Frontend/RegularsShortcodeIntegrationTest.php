<?php
/**
 * Integration tests for [nettertech_events_regulars] shortcode.
 *
 * Exercises the full production path: FixtureFactory creates real DB rows via
 * RecurrenceService/OccurrenceRepository, then do_shortcode() calls the
 * registered RegularsShortcode::render() which queries EventRepository and
 * OccurrenceRepository and renders via the real Templates service.
 *
 * These tests would have caught:
 *   - NTE-008: null->'' coercion on UNIQUE checkin_token caused all but one
 *     occurrence to be silently dropped (test 1 asserts COUNT = 10).
 *   - NTE-017 bug 1: EventQueryRepository::LIST_COLUMNS omitted recurrence_rule,
 *     so every hydrated Event had recurrence_rule = null and the shortcode
 *     skipped every row (tests 2-4 assert rendered output).
 *
 * Isolation: TRUNCATE-based (not transaction) so tear_down() can be called
 * inside individual tests without nesting transaction issues.
 *
 * @package NetterTechEvents\Tests\Integration\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Frontend;

use NetterTechEvents\Database\Schema;
use NetterTechEvents\Tests\Integration\Support\FixtureFactory;

/**
 * Integration tests for the [nettertech_events_regulars] shortcode.
 */
class RegularsShortcodeIntegrationTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * Use TRUNCATE isolation — tear_down() is called inside each test.
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
	// Test 1 — NTE-008 regression: all occurrences are persisted
	// =========================================================================

	/**
	 * create_recurring_event('WEEKLY', 10) must produce exactly 10 occurrence rows,
	 * each with checkin_token IS NULL (not empty string '').
	 *
	 * Catches NTE-008: the null->''-coercion bug caused the UNIQUE constraint on
	 * checkin_token to silently discard rows 2-10, leaving COUNT(*) = 1.
	 *
	 * @return void
	 */
	public function test_recurring_event_creates_all_occurrence_rows(): void {
		$wpdb     = $this->get_wpdb();
		$event_id = FixtureFactory::create_recurring_event( 'WEEKLY', 10 );

		$this->assertGreaterThan( 0, $event_id, 'create_recurring_event() must return a positive integer ID' );

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
			'Exactly 10 occurrences must exist — if this fails with count=1, NTE-008 has regressed'
		);

		// Assert checkin_token is NULL (not empty string '') for the first 3 rows.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT checkin_token FROM {$table} WHERE event_id = %d LIMIT 3", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$event_id
			)
		);

		$this->assertCount( 3, $rows, 'Expected 3 rows for spot-check' );

		foreach ( $rows as $row ) {
			$this->assertNull(
				$row->checkin_token,
				'checkin_token must be NULL (not empty string) — empty string violates the UNIQUE constraint at scale'
			);
		}
	}

	// =========================================================================
	// Test 2 — NTE-017 bug 1: shortcode renders weekly event
	// =========================================================================

	/**
	 * [nettertech_events_regulars] renders a weekly event's title and URL in the output.
	 *
	 * Catches NTE-017 bug 1: if recurrence_rule is absent from LIST_COLUMNS the
	 * shortcode receives null for every event and skips them all, rendering the
	 * empty state instead.
	 *
	 * @return void
	 */
	public function test_regulars_shortcode_renders_weekly_event(): void {
		$event_id = FixtureFactory::create_recurring_event(
			'WEEKLY',
			10,
			array( 'title' => 'Weekly Test Event' )
		);

		$this->assertGreaterThan( 0, $event_id, 'create_recurring_event() must return a positive integer ID' );

		$output = do_shortcode( '[nettertech_events_regulars]' );

		$this->assertStringContainsString(
			'Weekly Test Event',
			$output,
			'Shortcode output must contain the weekly event title'
		);

		$this->assertStringContainsString(
			'<tr',
			$output,
			'Shortcode output must contain table row markup'
		);

		$this->assertStringNotContainsString(
			'No weekly regulars',
			$output,
			'Shortcode must not render the empty state when weekly events exist'
		);
	}

	// =========================================================================
	// Test 3 — empty state when no events exist
	// =========================================================================

	/**
	 * [nettertech_events_regulars] renders the empty state message when no events exist.
	 *
	 * Relies on setUp() tear_down() leaving tables empty.
	 *
	 * @return void
	 */
	public function test_regulars_shortcode_empty_state(): void {
		$output = do_shortcode( '[nettertech_events_regulars]' );

		$this->assertStringContainsString(
			'No weekly regulars at this time.',
			$output,
			'Shortcode must render the empty state when no weekly events exist'
		);
	}

	// =========================================================================
	// Test 4 — monthly events must NOT appear in the regulars table
	// =========================================================================

	/**
	 * [nettertech_events_regulars] must exclude monthly recurring events.
	 *
	 * Only FREQ=WEEKLY events are "regulars". Monthly events must be silently
	 * skipped even if they exist in the database.
	 *
	 * @return void
	 */
	public function test_regulars_shortcode_with_non_weekly_recurrence_excluded(): void {
		FixtureFactory::create_recurring_event(
			'MONTHLY',
			5,
			array( 'title' => 'Monthly Members Meeting' )
		);

		$output = do_shortcode( '[nettertech_events_regulars]' );

		$this->assertStringNotContainsString(
			'Monthly Members Meeting',
			$output,
			'Monthly events must not appear in the regulars shortcode output'
		);

		$this->assertStringContainsString(
			'No weekly regulars at this time.',
			$output,
			'Shortcode must render the empty state when only non-weekly events exist'
		);
	}
}
