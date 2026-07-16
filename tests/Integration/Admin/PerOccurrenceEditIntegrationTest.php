<?php
/**
 * Cross-chunk per-occurrence edit integration tests (NTE-076/077).
 *
 * Drives the real OccurrenceSaveHandler (auth + nonce + DB) and asserts the
 * downstream behaviours that span chunks:
 *  - scope=this date change is persisted and re-findable at the new slot;
 *  - cancel one occurrence -> the frontend upcoming-dates list omits it AND the
 *    iCal export emits an EXDATE for it (compact-recurring master);
 *  - cancelling an in-window occurrence suppresses its reminder (the reminder
 *    query excludes non-scheduled occurrences).
 *
 * The series-split (scope=following) re-point + parent-cap is covered by
 * OccurrenceSaveHandlerIntegrationTest; this class covers the remaining
 * cross-chunk paths.
 *
 * @package NetterTechEvents\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Admin;

use NetterTechEvents\Admin\OccurrenceEditor;
use NetterTechEvents\Admin\OccurrenceSaveHandler;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\CategoryRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\OrganizerRepository;
use NetterTechEvents\Repositories\ReminderLogRepository;
use NetterTechEvents\Repositories\TagRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\EmailTemplateRenderer;
use NetterTechEvents\Services\IcsGenerator;
use NetterTechEvents\Services\ICalService;
use NetterTechEvents\Services\OccurrenceGenerator;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\ReminderEmailService;
use NetterTechEvents\Services\RRuleParser;
use NetterTechEvents\Services\VEventParser;
use NetterTechEvents\Tests\Integration\Support\FixtureFactory;

/**
 * Integration: per-occurrence edit cross-chunk behaviours.
 */
class PerOccurrenceEditIntegrationTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * DB instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepository
	 */
	private OccurrenceRepository $occurrence_repo;

	/**
	 * Event repository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $event_repo;

	/**
	 * Handler under test.
	 *
	 * @var OccurrenceSaveHandler
	 */
	private OccurrenceSaveHandler $handler;

	/**
	 * iCal export service (real category repo + recurrence + generator).
	 *
	 * @var ICalService
	 */
	private ICalService $ical;

	/**
	 * Admin user ID created for the request context.
	 *
	 * @var int
	 */
	private int $admin_id = 0;

	/**
	 * Set up real repositories + admin request context.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = $this->get_wpdb();

		$filter_repo           = new OccurrenceFilterRepository( $this->wpdb );
		$query_repo            = new OccurrenceQueryRepository( $this->wpdb, $filter_repo );
		$this->occurrence_repo = new OccurrenceRepository( $this->wpdb, $query_repo );
		$this->event_repo      = new EventRepository( $this->wpdb );
		$attendee_repo         = new AttendeeRepository( $this->wpdb );
		$ticket_type_repo      = new TicketTypeRepository( $this->wpdb );
		$category_repo         = new CategoryRepository( $this->wpdb );

		$recurrence_service = new RecurrenceService(
			new RRuleParser(),
			new OccurrenceGenerator(),
			$this->occurrence_repo,
			$ticket_type_repo,
			$attendee_repo
		);

		$this->handler = new OccurrenceSaveHandler(
			$this->occurrence_repo,
			$this->event_repo,
			$recurrence_service,
			$ticket_type_repo,
			$category_repo,
			new TagRepository( $this->wpdb ),
			new OrganizerRepository( $this->wpdb )
		);

		$this->ical = new ICalService(
			$this->event_repo,
			$this->occurrence_repo,
			new VEventParser(),
			$category_repo,
			$recurrence_service,
			new OccurrenceGenerator()
		);

		$this->admin_id = wp_insert_user(
			array(
				'user_login' => 'nte_poe_admin_' . uniqid(),
				'user_pass'  => 'pass-' . uniqid(),
				'user_email' => 'poe_admin_' . uniqid() . '@example.test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Tear down POST + user.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Build the nonce-backed POST payload for a save.
	 *
	 * @param int                  $occurrence_id Occurrence ID.
	 * @param int                  $event_id      Event ID.
	 * @param string               $scope         this|following|all.
	 * @param array<string, mixed> $fields        Field overrides (start/end/status).
	 * @return array<string, mixed>
	 */
	private function build_post( int $occurrence_id, int $event_id, string $scope, array $fields ): array {
		return array_merge(
			array(
				'nettertech_events_occurrence_nonce' => wp_create_nonce( OccurrenceEditor::NONCE_ACTION ),
				'occurrence_id'                      => (string) $occurrence_id,
				'event_id'                           => (string) $event_id,
				'scope'                              => $scope,
				'status'                             => 'scheduled',
			),
			$fields
		);
	}

	/**
	 * Sorted occurrences for an event (start_datetime ASC).
	 *
	 * @param int $event_id Event ID.
	 * @return array<\NetterTechEvents\Models\Occurrence>
	 */
	private function sorted_occurrences( int $event_id ): array {
		$occurrences = $this->occurrence_repo->for_event( $event_id );
		usort( $occurrences, fn( $a, $b ) => strcmp( $a->start_datetime, $b->start_datetime ) );
		return $occurrences;
	}

	/**
	 * @test
	 * scope=this date change is persisted and re-findable at the new slot.
	 *
	 * @return void
	 */
	public function test_scope_this_date_change_persists_new_slot(): void {
		$event_id = FixtureFactory::create_recurring_event( 'weekly', 4 );

		$occurrences = $this->sorted_occurrences( $event_id );
		$this->assertCount( 4, $occurrences );

		$target         = $occurrences[1];
		$target_id      = (int) $target->id;
		$original_start = $target->start_datetime;
		$original_date  = substr( $original_start, 0, 10 );
		$moved_date_obj = ( new \DateTimeImmutable( $original_start ) )->modify( '+2 days' );
		// New slot carries the EDITED time-of-day (18:30), not the source 19:00.
		$moved_start_str = $moved_date_obj->format( 'Y-m-d' ) . ' 18:30:00';

		$_POST = $this->build_post(
			$target_id,
			$event_id,
			'this',
			array(
				'start_date' => $moved_date_obj->format( 'Y-m-d' ),
				'start_time' => '18:30',
				'end_date'   => $moved_date_obj->format( 'Y-m-d' ),
				'end_time'   => '20:30',
			)
		);

		$url = $this->handler->process_save();
		$this->assertStringContainsString( 'message=updated', $url );

		// The occurrence ID is preserved; the date moved; it is an override.
		$moved = $this->occurrence_repo->find( $target_id );
		$this->assertNotNull( $moved );
		$this->assertSame( $event_id, (int) $moved->event_id, 'scope=this must NOT re-point to a new event.' );
		$this->assertSame( $moved_date_obj->format( 'Y-m-d' ), substr( $moved->start_datetime, 0, 10 ) );
		$this->assertSame( '18:30', substr( $moved->start_datetime, 11, 5 ) );
		$this->assertTrue( $moved->is_override, 'A per-date edit must flag the occurrence as an override.' );

		// It is re-findable at the NEW slot, and absent at the original slot.
		$at_new = $this->occurrence_repo->find_by_event_and_datetime(
			$event_id,
			new \DateTimeImmutable( $moved_start_str )
		);
		$this->assertNotNull( $at_new, 'Occurrence must be findable at its new datetime.' );
		$this->assertSame( $target_id, (int) $at_new->id );

		$at_old = $this->occurrence_repo->find_by_event_and_datetime(
			$event_id,
			new \DateTimeImmutable( $original_date . ' 19:00:00' )
		);
		$this->assertNull( $at_old, 'No occurrence should remain at the original slot.' );

		// Count unchanged: scope=this moves, never adds/removes.
		$this->assertCount( 4, $this->sorted_occurrences( $event_id ) );
	}

	/**
	 * @test
	 * Cancel one occurrence -> frontend upcoming list omits it AND iCal emits EXDATE.
	 *
	 * @return void
	 */
	public function test_cancel_hides_from_upcoming_and_adds_exdate(): void {
		$event_id = FixtureFactory::create_recurring_event( 'weekly', 5 );

		$occurrences = $this->sorted_occurrences( $event_id );
		$this->assertCount( 5, $occurrences );

		$cancel        = $occurrences[2];
		$cancel_id     = (int) $cancel->id;
		$cancel_slot   = $cancel->start_datetime;
		$exdate_expect = ( new \DateTimeImmutable( $cancel_slot ) )->format( 'Ymd\THis\Z' );

		$_POST = $this->build_post(
			$cancel_id,
			$event_id,
			'this',
			array(
				'start_date' => substr( $cancel_slot, 0, 10 ),
				'start_time' => substr( $cancel_slot, 11, 5 ),
				'end_date'   => substr( $cancel->end_datetime, 0, 10 ),
				'end_time'   => substr( $cancel->end_datetime, 11, 5 ),
				'status'     => 'cancelled',
			)
		);

		$url = $this->handler->process_save();
		$this->assertStringContainsString( 'message=updated', $url );

		// Status persisted.
		$reloaded = $this->occurrence_repo->find( $cancel_id );
		$this->assertNotNull( $reloaded );
		$this->assertTrue( $reloaded->is_cancelled(), 'Occurrence must be cancelled.' );

		// Frontend upcoming-dates list (status=scheduled only) omits the cancelled one.
		$upcoming     = $this->occurrence_repo->get_upcoming_by_event( $event_id, 10 );
		$upcoming_ids = array_map( static fn( $o ) => (int) $o->id, $upcoming );
		$this->assertNotContains( $cancel_id, $upcoming_ids, 'Cancelled occurrence must not appear in the upcoming list.' );
		$this->assertCount( 4, $upcoming, 'Four scheduled occurrences should remain upcoming.' );

		// iCal export: one master VEVENT with an EXDATE for the cancelled slot.
		$ics = $this->ical->export_event( $event_id );
		$this->assertNotNull( $ics );
		$this->assertSame( 1, substr_count( $ics, 'BEGIN:VEVENT' ), 'Recurring series exports a single master VEVENT.' );
		$this->assertStringContainsString( 'RRULE:', $ics );
		$this->assertStringContainsString( 'EXDATE:' . $exdate_expect, $ics, 'iCal must EXDATE the cancelled slot.' );
		$this->assertStringContainsString( 'STATUS:CONFIRMED', $ics );
		$this->assertStringNotContainsString( 'STATUS:CANCELLED', $ics, 'Master stays CONFIRMED; cancellation is EXDATE.' );
	}

	/**
	 * @test
	 * Cancelling an in-window occurrence suppresses its reminder.
	 *
	 * The reminder service selects occurrences via status=scheduled; a cancelled
	 * occurrence in the reminder window must not be returned.
	 *
	 * @return void
	 */
	public function test_cancel_suppresses_reminder(): void {
		$event_id = FixtureFactory::create_event(
			array(
				'title'           => 'Reminder Series',
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=DAILY;COUNT=2',
			)
		);

		// Seed two occurrences inside the reminder window (now .. now+25h) directly,
		// so the window assertion does not depend on wall-clock time of day.
		$soon  = ( new \DateTimeImmutable( '+3 hours' ) );
		$later = ( new \DateTimeImmutable( '+6 hours' ) );

		$keep_id   = $this->seed_occurrence( $event_id, $soon, 'scheduled' );
		$cancel_id = $this->seed_occurrence( $event_id, $later, 'scheduled' );

		$reminder = $this->make_reminder_service();

		// Both are initially in-window and scheduled.
		$before     = $reminder->get_occurrences_needing_reminders();
		$before_ids = array_map( static fn( $o ) => (int) $o->id, $before );
		$this->assertContains( $keep_id, $before_ids );
		$this->assertContains( $cancel_id, $before_ids );

		// Cancel the second one via the real handler.
		$cancel_occ = $this->occurrence_repo->find( $cancel_id );
		$this->assertNotNull( $cancel_occ );
		$_POST = $this->build_post(
			$cancel_id,
			$event_id,
			'this',
			array(
				'start_date' => substr( $cancel_occ->start_datetime, 0, 10 ),
				'start_time' => substr( $cancel_occ->start_datetime, 11, 5 ),
				'end_date'   => substr( $cancel_occ->end_datetime, 0, 10 ),
				'end_time'   => substr( $cancel_occ->end_datetime, 11, 5 ),
				'status'     => 'cancelled',
			)
		);
		$this->assertStringContainsString( 'message=updated', $this->handler->process_save() );

		// Reminders run on cron in a fresh request with a cold object cache; the
		// in_range query caches for an hour, so model the cold-request boundary by
		// flushing the plugin cache group before re-querying.
		wp_cache_flush_group( 'nettertech_events' );

		// The cancelled occurrence is no longer eligible for a reminder.
		$after     = $reminder->get_occurrences_needing_reminders();
		$after_ids = array_map( static fn( $o ) => (int) $o->id, $after );
		$this->assertContains( $keep_id, $after_ids, 'Scheduled occurrence still needs a reminder.' );
		$this->assertNotContains( $cancel_id, $after_ids, 'Cancelled occurrence reminder is suppressed.' );
	}

	/**
	 * Seed a single occurrence directly via the repository.
	 *
	 * @param int                $event_id Event ID.
	 * @param \DateTimeImmutable $start    Start datetime.
	 * @param string             $status   Occurrence status.
	 * @return int New occurrence ID.
	 */
	private function seed_occurrence( int $event_id, \DateTimeImmutable $start, string $status ): int {
		$occurrence                 = new \NetterTechEvents\Models\Occurrence();
		$occurrence->event_id       = $event_id;
		$occurrence->start_datetime = $start->format( 'Y-m-d H:i:s' );
		$occurrence->end_datetime   = $start->modify( '+2 hours' )->format( 'Y-m-d H:i:s' );
		$occurrence->status         = $status;
		$saved                      = $this->occurrence_repo->save( $occurrence );
		return (int) $saved->id;
	}

	/**
	 * Build a ReminderEmailService wired with real repositories.
	 *
	 * @return ReminderEmailService
	 */
	private function make_reminder_service(): ReminderEmailService {
		$attendee_repo    = new AttendeeRepository( $this->wpdb );
		$ticket_type_repo = new TicketTypeRepository( $this->wpdb );
		$log_repo         = new ReminderLogRepository( $this->wpdb );
		$renderer         = new EmailTemplateRenderer(
			$this->occurrence_repo,
			$this->event_repo,
			$ticket_type_repo
		);
		$ics_generator    = new IcsGenerator(
			$this->occurrence_repo,
			$this->event_repo
		);

		return new ReminderEmailService(
			$this->occurrence_repo,
			$attendee_repo,
			$log_repo,
			$this->event_repo,
			$renderer,
			$ics_generator
		);
	}
}
