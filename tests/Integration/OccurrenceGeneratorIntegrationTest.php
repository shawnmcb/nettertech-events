<?php
/**
 * OccurrenceGenerator integration test.
 *
 * Verifies occurrence generation with real DB writes: a weekly recurring event
 * generates the expected rows, and a rule change triggers regeneration.
 *
 * @package NetterTechEvents\Tests\Integration
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration;

use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\RecurrenceRule;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\OccurrenceGenerator;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\RRuleParser;
use NetterTechEvents\Tests\Factories\EventFactory;

/**
 * Integration test: OccurrenceGenerator writes to and reads from the real database.
 */
class OccurrenceGeneratorIntegrationTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * EventRepository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $event_repo;

	/**
	 * OccurrenceRepository.
	 *
	 * @var OccurrenceRepository
	 */
	private OccurrenceRepository $occurrence_repo;

	/**
	 * RecurrenceService (orchestrates parser + generator + repos).
	 *
	 * @var RecurrenceService
	 */
	private RecurrenceService $recurrence_service;

	/**
	 * OccurrenceGenerator (generates Occurrence instances from a rule).
	 *
	 * @var OccurrenceGenerator
	 */
	private OccurrenceGenerator $generator;

	/**
	 * Unique test prefix.
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

		// Tests use start dates in 2032+ to avoid past-date edge cases.
		// OccurrenceGenerator defaults its horizon to today + settings
		// occurrence_horizon (default 365 days), which would discard every
		// 2032 date as past-horizon. Push the horizon out to ~20 years via
		// the option filter so far-future test dates are in range.
		add_filter( 'pre_option_nettertech_events_settings', array( $this, 'override_horizon_for_test' ) );

		global $wpdb;

		$filter_repo              = new OccurrenceFilterRepository( $wpdb );
		$query_repo               = new OccurrenceQueryRepository( $wpdb, $filter_repo );
		$this->occurrence_repo    = new OccurrenceRepository( $wpdb, $query_repo );
		$this->event_repo         = new EventRepository( $wpdb );
		$this->generator          = new OccurrenceGenerator();
		$this->recurrence_service = new RecurrenceService(
			new RRuleParser(),
			$this->generator,
			$this->occurrence_repo,
			new TicketTypeRepository( $wpdb ),
			new AttendeeRepository( $wpdb )
		);

		$this->test_prefix = 'ogi-' . time() . '-' . mt_rand( 1000, 9999 ) . '-';
	}

	/**
	 * Remove the horizon override installed in setUp().
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_option_nettertech_events_settings', array( $this, 'override_horizon_for_test' ) );
		parent::tearDown();
	}

	/**
	 * Return a settings array with a horizon wide enough for 2032+ test dates.
	 *
	 * @return array<string, mixed>
	 */
	public function override_horizon_for_test(): array {
		return array( 'occurrence_horizon' => 7300 );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Return a unique slug for this test run.
	 *
	 * @param string $suffix Suffix.
	 * @return string
	 */
	private function slug( string $suffix ): string {
		return $this->test_prefix . $suffix;
	}

	/**
	 * Insert a new recurring event and return the saved instance.
	 *
	 * @param string $slug Event slug.
	 * @return Event
	 */
	private function insert_recurring_event( string $slug ): Event {
		$event             = EventFactory::recurring( array( 'slug' => $slug ) );
		$event->id         = null;
		$event->event_type = 'recurring';
		return $this->event_repo->save( $event );
	}

	// =========================================================================
	// Weekly generation: Occurrence instances created
	// =========================================================================

	/**
	 * OccurrenceGenerator produces Occurrence instances (not saved) from a weekly rule.
	 *
	 * @return void
	 */
	public function test_generator_produces_occurrences_from_weekly_rule(): void {
		$event = $this->insert_recurring_event( $this->slug( 'weekly-gen' ) );

		$rule   = RecurrenceRule::weekly( 1, array( RecurrenceRule::DAY_WE ) )->with_count( 4 );
		$start  = new \DateTimeImmutable( '2032-01-07 19:00:00' ); // Wednesday.
		$end    = new \DateTimeImmutable( '2032-01-07 21:00:00' );

		$occurrences = $this->generator->generate( $event, $start, $end, $rule );

		$this->assertCount( 4, $occurrences );
		foreach ( $occurrences as $occ ) {
			$this->assertSame( $event->id, $occ->event_id );
			$this->assertSame( 'scheduled', $occ->status );
		}
	}

	/**
	 * Generated occurrences are correctly spaced one week apart.
	 *
	 * @return void
	 */
	public function test_weekly_occurrences_are_spaced_seven_days_apart(): void {
		$event  = $this->insert_recurring_event( $this->slug( 'weekly-spacing' ) );
		$rule   = RecurrenceRule::weekly( 1, array( RecurrenceRule::DAY_TU ) )->with_count( 3 );
		$start  = new \DateTimeImmutable( '2032-02-03 20:00:00' ); // Tuesday.
		$end    = new \DateTimeImmutable( '2032-02-03 22:00:00' );

		$occurrences = $this->generator->generate( $event, $start, $end, $rule );

		$this->assertCount( 3, $occurrences );

		$timestamps = array_map(
			static fn( $occ ) => ( new \DateTimeImmutable( $occ->start_datetime ) )->getTimestamp(),
			$occurrences
		);

		$this->assertSame( 7 * 24 * 3600, $timestamps[1] - $timestamps[0] );
		$this->assertSame( 7 * 24 * 3600, $timestamps[2] - $timestamps[1] );
	}

	// =========================================================================
	// DB writes: occurrences persisted via batch save
	// =========================================================================

	/**
	 * Batch-saving generated occurrences writes the correct number of rows.
	 *
	 * @return void
	 */
	public function test_batch_save_writes_occurrences_to_database(): void {
		$event = $this->insert_recurring_event( $this->slug( 'batch-write' ) );

		$rule        = RecurrenceRule::weekly( 1, array( RecurrenceRule::DAY_TH ) )->with_count( 5 );
		$start       = new \DateTimeImmutable( '2032-03-03 19:00:00' ); // Thursday.
		$end         = new \DateTimeImmutable( '2032-03-03 21:00:00' );
		$occurrences = $this->generator->generate( $event, $start, $end, $rule );

		// Ensure IDs are null so save_batch inserts.
		foreach ( $occurrences as $occ ) {
			$occ->id = null;
		}

		$saved_count = $this->occurrence_repo->save_batch( $occurrences );

		$this->assertSame( 5, $saved_count );

		// Verify rows are actually in the database.
		$db_occurrences = $this->occurrence_repo->for_event( $event->id );
		$this->assertCount( 5, $db_occurrences );
	}

	/**
	 * Generated occurrence durations match the original event duration.
	 *
	 * @return void
	 */
	public function test_generated_occurrences_preserve_original_duration(): void {
		$event = $this->insert_recurring_event( $this->slug( 'duration' ) );

		$rule  = RecurrenceRule::weekly( 1, array( RecurrenceRule::DAY_MO ) )->with_count( 2 );
		$start = new \DateTimeImmutable( '2032-04-04 14:00:00' ); // Monday.
		$end   = new \DateTimeImmutable( '2032-04-04 17:30:00' ); // 3h 30m.

		$occurrences = $this->generator->generate( $event, $start, $end, $rule );

		$this->assertCount( 2, $occurrences );

		foreach ( $occurrences as $occ ) {
			$occ_start    = new \DateTimeImmutable( $occ->start_datetime );
			$occ_end      = new \DateTimeImmutable( $occ->end_datetime );
			$duration_sec = $occ_end->getTimestamp() - $occ_start->getTimestamp();
			// 3 hours 30 minutes = 12600 seconds.
			$this->assertSame( 12600, $duration_sec );
		}
	}

	// =========================================================================
	// Rule change: regeneration replaces old occurrences
	// =========================================================================

	/**
	 * Changing the recurrence rule and regenerating replaces the existing DB rows.
	 *
	 * @return void
	 */
	public function test_rule_change_replaces_existing_occurrences(): void {
		$event = $this->insert_recurring_event( $this->slug( 'regen' ) );

		// First generation: 3 occurrences on Wednesdays.
		$rule_v1        = RecurrenceRule::weekly( 1, array( RecurrenceRule::DAY_WE ) )->with_count( 3 );
		$start_v1       = new \DateTimeImmutable( '2032-05-04 19:00:00' ); // Wednesday.
		$end_v1         = new \DateTimeImmutable( '2032-05-04 21:00:00' );
		$occurrences_v1 = $this->generator->generate( $event, $start_v1, $end_v1, $rule_v1 );
		foreach ( $occurrences_v1 as $occ ) {
			$occ->id = null;
		}
		$this->occurrence_repo->save_batch( $occurrences_v1 );

		$count_after_v1 = $this->occurrence_repo->count_for_event( $event->id );
		$this->assertSame( 3, $count_after_v1 );

		// Rule change: 6 occurrences on Fridays. Delete old, insert new.
		$this->occurrence_repo->delete_for_event( $event->id );

		$rule_v2        = RecurrenceRule::weekly( 1, array( RecurrenceRule::DAY_FR ) )->with_count( 6 );
		$start_v2       = new \DateTimeImmutable( '2032-05-06 19:00:00' ); // Friday.
		$end_v2         = new \DateTimeImmutable( '2032-05-06 21:00:00' );
		$occurrences_v2 = $this->generator->generate( $event, $start_v2, $end_v2, $rule_v2 );
		foreach ( $occurrences_v2 as $occ ) {
			$occ->id = null;
		}
		$this->occurrence_repo->save_batch( $occurrences_v2 );

		$count_after_v2 = $this->occurrence_repo->count_for_event( $event->id );
		$this->assertSame( 6, $count_after_v2 );

		// Verify the new occurrences fall on Fridays.
		$db_occurrences = $this->occurrence_repo->for_event( $event->id );
		foreach ( $db_occurrences as $occ ) {
			$day_of_week = ( new \DateTimeImmutable( $occ->start_datetime ) )->format( 'N' ); // 5 = Friday.
			$this->assertSame( '5', $day_of_week, 'Regenerated occurrences should fall on Fridays.' );
		}
	}

	/**
	 * After delete_for_event the event has no occurrences.
	 *
	 * @return void
	 */
	public function test_delete_for_event_removes_all_generated_occurrences(): void {
		$event = $this->insert_recurring_event( $this->slug( 'delete-all' ) );

		$rule        = RecurrenceRule::daily( 1 )->with_count( 4 );
		$start       = new \DateTimeImmutable( '2032-06-01 10:00:00' );
		$end         = new \DateTimeImmutable( '2032-06-01 11:00:00' );
		$occurrences = $this->generator->generate( $event, $start, $end, $rule );
		foreach ( $occurrences as $occ ) {
			$occ->id = null;
		}
		$this->occurrence_repo->save_batch( $occurrences );

		$this->occurrence_repo->delete_for_event( $event->id );

		$remaining = $this->occurrence_repo->for_event( $event->id );
		$this->assertEmpty( $remaining );
	}

	// =========================================================================
	// Recurrence exceptions: cancelled (EXDATE) slots survive full regeneration
	// without being recreated as a duplicate scheduled occurrence.
	// =========================================================================

	/**
	 * A cancelled occurrence is a recurrence exception (EXDATE): it must survive a
	 * full regeneration (every recurring-event save regenerates) AND the generator
	 * must NOT recreate a fresh scheduled row at the excluded slot.
	 *
	 * @return void
	 */
	public function test_cancelled_slot_survives_regeneration_without_duplicate(): void {
		$event = $this->insert_recurring_event( $this->slug( 'cancel-regen' ) );
		$rrule = 'FREQ=WEEKLY;BYDAY=WE;COUNT=4';
		$start = new \DateTimeImmutable( '2032-09-01 19:00:00' ); // Wednesday.
		$end   = new \DateTimeImmutable( '2032-09-01 21:00:00' );

		// Initial generation via the real replace path: 4 occurrences.
		$this->recurrence_service->generate_occurrences( $event, $start, $end, $rrule, true );
		$initial = $this->occurrence_repo->for_event( $event->id );
		$this->assertCount( 4, $initial );

		// Cancel the 2nd occurrence programmatically (status only; is_override stays false).
		usort( $initial, static fn( $a, $b ) => strcmp( $a->start_datetime, $b->start_datetime ) );
		$cancel_slot = $initial[1]->start_datetime;
		$this->recurrence_service->cancel_occurrence( (int) $initial[1]->id );

		// Full regeneration, as happens on the next event save.
		$this->recurrence_service->generate_occurrences( $event, $start, $end, $rrule, true );

		$all     = $this->occurrence_repo->for_event( $event->id );
		$at_slot = array_values(
			array_filter( $all, static fn( $o ) => $o->start_datetime === $cancel_slot )
		);

		$this->assertCount(
			1,
			$at_slot,
			'Exactly one row must occupy the cancelled slot after regeneration (no duplicate, no loss).'
		);
		$this->assertTrue(
			$at_slot[0]->is_cancelled(),
			'The surviving slot must remain cancelled after regeneration (exclusion preserved).'
		);
		$this->assertCount(
			4,
			$all,
			'Regeneration must not create a duplicate scheduled row at the excluded slot (3 scheduled + 1 cancelled).'
		);
	}

	/**
	 * An in-place override (NTE-077: edited without moving the date) must survive a
	 * full regeneration without the generator recreating a duplicate row at its slot.
	 *
	 * @return void
	 */
	public function test_in_place_override_slot_survives_regeneration_without_duplicate(): void {
		$event = $this->insert_recurring_event( $this->slug( 'override-regen' ) );
		$rrule = 'FREQ=WEEKLY;BYDAY=TH;COUNT=3';
		$start = new \DateTimeImmutable( '2032-10-07 19:00:00' ); // Thursday.
		$end   = new \DateTimeImmutable( '2032-10-07 21:00:00' );

		$this->recurrence_service->generate_occurrences( $event, $start, $end, $rrule, true );
		$initial = $this->occurrence_repo->for_event( $event->id );
		$this->assertCount( 3, $initial );

		// Mark the 2nd occurrence an in-place override (same slot, e.g. retitled).
		usort( $initial, static fn( $a, $b ) => strcmp( $a->start_datetime, $b->start_datetime ) );
		$override            = $initial[1];
		$override_slot       = $override->start_datetime;
		$override->is_override    = true;
		$override->title_override = 'Special edition';
		$this->occurrence_repo->save( $override );

		// Full regeneration (next event save).
		$this->recurrence_service->generate_occurrences( $event, $start, $end, $rrule, true );

		$all     = $this->occurrence_repo->for_event( $event->id );
		$at_slot = array_values(
			array_filter( $all, static fn( $o ) => $o->start_datetime === $override_slot )
		);

		$this->assertCount( 1, $at_slot, 'Exactly one row must occupy the override slot after regeneration.' );
		$this->assertTrue( $at_slot[0]->is_override, 'The surviving slot must remain the override.' );
		$this->assertSame( 'Special edition', $at_slot[0]->title_override, 'Override edit must be preserved.' );
		$this->assertCount( 3, $all, 'Regeneration must not duplicate the override slot.' );
	}
}
