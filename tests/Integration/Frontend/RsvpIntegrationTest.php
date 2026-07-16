<?php
/**
 * RSVP flow integration tests.
 *
 * Covers NTE-009 (CapacityCalculator empty ticket_types regression),
 * NTE-010 (RSVP_SUBMITTED hook contract mismatch / TypeError), and
 * NTE-036 (RSVP submit must increment ticket_type.sold_count so capacity
 * is enforced end-to-end without depending on the WC order path).
 *
 * @package NetterTechEvents\Tests\Integration\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Frontend;

use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CapacityCalculatorInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Frontend\Shortcodes\RSVPFormShortcode;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\RateLimitService;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;
use NetterTechEvents\Tests\Integration\Support\FixtureFactory;

/**
 * Integration tests for the RSVP form flow (NTE-009, NTE-010).
 */
class RsvpIntegrationTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * @var OccurrenceRepository
	 */
	private OccurrenceRepository $occurrence_repo;

	/**
	 * @var TicketTypeRepository
	 */
	private TicketTypeRepository $ticket_type_repo;

	/**
	 * @var AttendeeRepository
	 */
	private AttendeeRepository $attendee_repo;

	/**
	 * @var RSVPFormShortcode
	 */
	private RSVPFormShortcode $shortcode;

	/**
	 * Set up repositories and shortcode instance for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Resolve services through the Plugin DI container. This is critical
		// for capacity-enforcement tests: RsvpCapacityHandler (wired via
		// Plugin::init() in the integration bootstrap) increments sold_count
		// through the *container's* TicketTypeRepository instance and
		// invalidates that instance's identity map. If the shortcode here
		// used a different repository instance, the second RSVP's capacity
		// check would hit a stale identity-map entry and incorrectly report
		// availability. Sharing the container's singletons keeps mutation
		// and observation on the same in-memory state.
		$container = \NetterTechEvents\nettertech_events()->get_container();

		$this->occurrence_repo  = $container->get( OccurrenceRepositoryInterface::class );
		$this->ticket_type_repo = $container->get( TicketTypeRepositoryInterface::class );
		$this->attendee_repo    = $container->get( AttendeeRepositoryInterface::class );

		$capacity_calculator = $container->get( CapacityCalculatorInterface::class );
		$rate_limit_service  = $container->get( RateLimitService::class );

		$this->shortcode = new RSVPFormShortcode(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->attendee_repo,
			$capacity_calculator,
			$rate_limit_service
		);

		// Clear POST state before each test.
		$_POST = array();
	}

	/**
	 * Tear down: restore clean POST state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Create an event + occurrence with no ticket types.
	 *
	 * @param int $capacity Event capacity (currently not stored on event model itself;
	 *                      capacity is on ticket types). Kept for parity with spec.
	 * @return int Occurrence ID.
	 */
	private function create_event_with_occurrence( int $capacity = 20 ): int {
		global $wpdb;

		$event_id  = FixtureFactory::create_event( array( 'capacity' => $capacity ) );

		$occ           = OccurrenceFactory::create( array( 'event_id' => $event_id ) );
		$occ->id       = null;
		$occ->event_id = $event_id;
		$saved_occ     = $this->occurrence_repo->save( $occ );

		return (int) $saved_occ->id;
	}

	/**
	 * Submit the RSVP form via $_POST and invoke the shortcode render.
	 *
	 * @param int    $occurrence_id Occurrence to RSVP for.
	 * @param string $name         Attendee name.
	 * @param string $email        Attendee email.
	 * @param int    $quantity     Party size.
	 * @return string Rendered shortcode HTML.
	 */
	private function submit_rsvp(
		int $occurrence_id,
		string $name,
		string $email,
		int $quantity = 1
	): string {
		$nonce = wp_create_nonce( 'nettertech_events_rsvp_' . $occurrence_id );

		$_POST = array(
			'nettertech_events_rsvp_submit'       => '1',
			'nettertech_events_rsvp_nonce'        => $nonce,
			'nettertech_events_rsvp_occurrence_id' => (string) $occurrence_id,
			'nettertech_events_rsvp_name'         => $name,
			'nettertech_events_rsvp_email'        => $email,
			'nettertech_events_rsvp_quantity'     => (string) $quantity,
		);

		return $this->shortcode->render( array( 'occurrence_id' => $occurrence_id ) );
	}

	// =========================================================================
	// Test 1: RSVP form renders on event with no ticket types (NTE-009)
	// =========================================================================

	/**
	 * RSVP form must render for an event with no ticket types.
	 *
	 * NTE-009: CapacityCalculator returned 0 instead of null when ticket_types
	 * was empty, causing check_capacity() to treat the event as "sold out" and
	 * render the waitlist fallback instead of the registration form.
	 *
	 * @return void
	 */
	public function test_rsvp_form_renders_on_event_with_no_ticket_types(): void {
		$occ_id = $this->create_event_with_occurrence( 20 );

		$output = $this->shortcode->render( array( 'occurrence_id' => $occ_id ) );

		$this->assertStringContainsString( '<form', $output, 'RSVP form element must be present' );
		$this->assertStringContainsString( 'nte-rsvp-form', $output, 'Form must have nte-rsvp-form class' );
		$this->assertStringContainsString( 'name="nettertech_events_rsvp_name"', $output, 'Name input must be present' );
		$this->assertStringContainsString( 'name="nettertech_events_rsvp_email"', $output, 'Email input must be present' );
		$this->assertStringNotContainsString( 'This event is full', $output, 'Must not show sold-out message' );
		$this->assertStringNotContainsString( 'Join Waitlist', $output, 'Must not show waitlist link' );
	}

	// =========================================================================
	// Test 2: Successful RSVP submit creates attendee record (NTE-010)
	// =========================================================================

	/**
	 * Submitting the RSVP form creates an attendee row with correct data.
	 *
	 * NTE-010: The RSVP_SUBMITTED hook was fired with a single Attendee object
	 * but listeners expected (int $attendee_id, array $form_data), causing a
	 * fatal TypeError on every successful RSVP submission. This test fails if
	 * the hook contract is still broken.
	 *
	 * @return void
	 */
	public function test_rsvp_submit_creates_attendee(): void {
		global $wpdb;

		$occ_id = $this->create_event_with_occurrence( 20 );

		$output = $this->submit_rsvp( $occ_id, 'Jane Doe', 'jane@example.com', 1 );

		$this->assertStringContainsString(
			'Thank you',
			$output,
			'Success message must appear after valid RSVP submission'
		);

		$attendees_table = Schema::table( 'attendees' );
		$row             = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$attendees_table} WHERE occurrence_id = %d AND email = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$occ_id,
				'jane@example.com'
			),
			ARRAY_A
		);

		$this->assertNotNull( $row, 'Attendee row must exist in database' );
		$this->assertSame( 'Jane Doe', $row['name'], 'Name must match submitted value' );
		$this->assertSame( 'confirmed', $row['status'], 'Status must be confirmed' );
		$this->assertSame( '1', (string) $row['quantity'], 'Quantity must match submitted value' );
	}

	// =========================================================================
	// Test 3: Duplicate email is rejected
	// =========================================================================

	/**
	 * A second RSVP from the same email for the same occurrence is rejected.
	 *
	 * @return void
	 */
	public function test_rsvp_submit_rejects_duplicate_email(): void {
		global $wpdb;

		$occ_id = $this->create_event_with_occurrence( 20 );

		// First submission — must succeed.
		$this->submit_rsvp( $occ_id, 'Jane Doe', 'dup@example.com', 1 );

		// Second submission with same email.
		$_POST = array(); // reset.
		$output = $this->submit_rsvp( $occ_id, 'Jane Again', 'dup@example.com', 1 );

		$this->assertStringContainsString(
			'already registered',
			$output,
			'Duplicate email must trigger already-registered error'
		);

		$attendees_table = Schema::table( 'attendees' );
		$count           = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$attendees_table} WHERE occurrence_id = %d AND email = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$occ_id,
				'dup@example.com'
			)
		);

		$this->assertSame( 1, $count, 'Only one attendee row should exist for this email' );
	}

	// =========================================================================
	// Test 4: Capacity is enforced
	// =========================================================================

	/**
	 * RSVP submit enforces ticket-type capacity end-to-end (NTE-036).
	 *
	 * Creates an event with a free ticket type of capacity 1. The first
	 * submission must succeed AND increment sold_count via the
	 * RsvpCapacityHandler listener; the second must be rejected with the
	 * capacity-full message because the listener already consumed the
	 * single available seat.
	 *
	 * Prior to NTE-036 the RSVP path never called increment_sold_count,
	 * so a free event with capacity 1 accepted unlimited RSVPs in
	 * production. The listener is hooked in setUp() to mirror the
	 * production wiring done by Plugin::init_rsvp_capacity_handler().
	 *
	 * @return void
	 */
	public function test_rsvp_submit_enforces_capacity(): void {
		global $wpdb;

		$occ_id   = $this->create_event_with_occurrence( 1 );
		$event_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT event_id FROM {$wpdb->prefix}nettertech_events_occurrences WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$occ_id
			)
		);

		// Add a free ticket type with capacity 1 so capacity is constrained.
		// scope=occurrence requires occurrence_id.
		$ticket_type_id = FixtureFactory::create_ticket_type(
			$event_id,
			array(
				'price'         => 0.00,
				'capacity'      => 1,
				'occurrence_id' => $occ_id,
			)
		);

		// First RSVP — must succeed AND consume the single seat.
		$first_output = $this->submit_rsvp( $occ_id, 'Alice', 'alice@example.com', 1 );
		$this->assertStringContainsString(
			'Thank you',
			$first_output,
			'First RSVP must succeed against an empty capacity'
		);

		$sold_after_first = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT sold_count FROM {$wpdb->prefix}nettertech_events_ticket_types WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ticket_type_id
			)
		);
		$this->assertSame(
			1,
			$sold_after_first,
			'sold_count must increment to 1 after the first RSVP (NTE-036 regression check)'
		);

		// Reset POST state between submissions to mirror two real requests.
		$_POST = array();

		// Second RSVP — must be rejected because capacity is exhausted.
		$second_output = $this->submit_rsvp( $occ_id, 'Bob', 'bob@example.com', 1 );
		$this->assertStringNotContainsString(
			'Thank you',
			$second_output,
			'Second RSVP against an exhausted occurrence must not succeed'
		);
		$this->assertStringContainsString(
			'full',
			strtolower( $second_output ),
			'Rejection response must mention the event is full'
		);

		$attendees_table = Schema::table( 'attendees' );
		$count           = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$attendees_table} WHERE occurrence_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$occ_id
			)
		);
		$this->assertSame(
			1,
			$count,
			'Exactly one attendee row should exist (the first submitter); the rejected submit must not create a row'
		);

		$sold_after_second = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT sold_count FROM {$wpdb->prefix}nettertech_events_ticket_types WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ticket_type_id
			)
		);
		$this->assertSame(
			1,
			$sold_after_second,
			'sold_count must remain 1 after the rejected second RSVP (no over-count)'
		);
	}
}
