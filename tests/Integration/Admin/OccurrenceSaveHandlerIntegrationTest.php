<?php
/**
 * OccurrenceSaveHandler integration tests.
 *
 * Drives the real handler (auth + nonce + DB) against the live schema to prove
 * the series-split RE-POINT mechanism: future occurrences keep their IDs — so
 * attendee links survive — while moving to a new event, and the parent series
 * RRULE is capped.
 *
 * @package NetterTechEvents\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Admin;

use NetterTechEvents\Admin\OccurrenceEditor;
use NetterTechEvents\Admin\OccurrenceSaveHandler;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\CategoryRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\OrganizerRepository;
use NetterTechEvents\Repositories\TagRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\OccurrenceGenerator;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\RRuleParser;
use NetterTechEvents\Tests\Integration\Support\FixtureFactory;

/**
 * Integration: per-occurrence save handler series split.
 */
class OccurrenceSaveHandlerIntegrationTest extends \NetterTechEventsIntegrationTestCase {

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
	 * Attendee repository.
	 *
	 * @var AttendeeRepository
	 */
	private AttendeeRepository $attendee_repo;

	/**
	 * Handler under test.
	 *
	 * @var OccurrenceSaveHandler
	 */
	private OccurrenceSaveHandler $handler;

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
		$this->attendee_repo   = new AttendeeRepository( $this->wpdb );
		$ticket_type_repo      = new TicketTypeRepository( $this->wpdb );

		$recurrence_service = new RecurrenceService(
			new RRuleParser(),
			new OccurrenceGenerator(),
			$this->occurrence_repo,
			$ticket_type_repo,
			$this->attendee_repo
		);

		$this->handler = new OccurrenceSaveHandler(
			$this->occurrence_repo,
			$this->event_repo,
			$recurrence_service,
			$ticket_type_repo,
			new CategoryRepository( $this->wpdb ),
			new TagRepository( $this->wpdb ),
			new OrganizerRepository( $this->wpdb )
		);

		$this->admin_id = wp_insert_user(
			array(
				'user_login' => 'nte_occ_admin_' . uniqid(),
				'user_pass'  => 'pass-' . uniqid(),
				'user_email' => 'occ_admin_' . uniqid() . '@example.test',
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
	 * @test
	 * Series split re-points future occurrences (IDs + attendee links survive) and caps the parent.
	 *
	 * @return void
	 */
	public function test_following_split_repoints_and_preserves_attendee(): void {
		$event_id = FixtureFactory::create_recurring_event( 'weekly', 6 );

		$occurrences = $this->occurrence_repo->for_event( $event_id );
		usort( $occurrences, fn( $a, $b ) => strcmp( $a->start_datetime, $b->start_datetime ) );
		$this->assertCount( 6, $occurrences, 'Fixture must seed six weekly occurrences.' );

		$cutoff      = $occurrences[2];
		$cutoff_id   = (int) $cutoff->id;
		$before_ids  = array( (int) $occurrences[0]->id, (int) $occurrences[1]->id );
		$after_ids   = array( $cutoff_id, (int) $occurrences[3]->id, (int) $occurrences[4]->id, (int) $occurrences[5]->id );

		// Attach an attendee to the cutoff occurrence — the link is occurrence-scoped.
		$attendee                = new Attendee();
		$attendee->occurrence_id = $cutoff_id;
		$attendee->name          = 'Ticket Holder';
		$attendee->email         = 'holder_' . uniqid() . '@example.test';
		$attendee->quantity      = 2;
		$attendee->status        = 'confirmed';
		$this->attendee_repo->save( $attendee );

		$_POST = array(
			'nettertech_events_occurrence_nonce' => wp_create_nonce( OccurrenceEditor::NONCE_ACTION ),
			'occurrence_id'                      => (string) $cutoff_id,
			'event_id'                           => (string) $event_id,
			'scope'                              => 'following',
			'start_date'                         => substr( $cutoff->start_datetime, 0, 10 ),
			'start_time'                         => substr( $cutoff->start_datetime, 11, 5 ),
			'end_date'                           => substr( $cutoff->end_datetime, 0, 10 ),
			'end_time'                           => substr( $cutoff->end_datetime, 11, 5 ),
			'status'                             => 'scheduled',
		);

		$url = $this->handler->process_save();
		$this->assertStringContainsString( 'message=updated', $url );

		// Cutoff occurrence keeps its ID but now belongs to a new event.
		$moved = $this->occurrence_repo->find( $cutoff_id );
		$this->assertNotNull( $moved, 'Cutoff occurrence ID must survive the split.' );
		$new_event_id = (int) $moved->event_id;
		$this->assertNotSame( $event_id, $new_event_id, 'Cutoff occurrence must move to a new event.' );

		// All four cutoff-and-after occurrences moved to the new event.
		foreach ( $after_ids as $id ) {
			$row = $this->occurrence_repo->find( $id );
			$this->assertNotNull( $row, "Occurrence {$id} must still exist (re-point, not delete)." );
			$this->assertSame( $new_event_id, (int) $row->event_id, "Occurrence {$id} must move to the new event." );
		}

		// The two before-cutoff occurrences stay on the parent.
		foreach ( $before_ids as $id ) {
			$row = $this->occurrence_repo->find( $id );
			$this->assertNotNull( $row );
			$this->assertSame( $event_id, (int) $row->event_id, "Occurrence {$id} must stay on the parent." );
		}

		// Attendee link survives because occurrence_id is unchanged.
		$attendees = $this->attendee_repo->for_occurrence( $cutoff_id );
		$this->assertNotEmpty( $attendees, 'Attendee must remain linked to the (preserved) cutoff occurrence.' );

		// Parent series RRULE is capped; the new series carries the remaining COUNT.
		$parent = $this->event_repo->find( $event_id );
		$this->assertStringContainsString( 'UNTIL=', (string) $parent->recurrence_rule, 'Parent RRULE must be capped.' );

		$new_event = $this->event_repo->find( $new_event_id );
		$this->assertNotNull( $new_event );
		$this->assertStringContainsString( 'COUNT=4', (string) $new_event->recurrence_rule, 'New series keeps 6 - 2 = 4 remaining.' );
	}
}
