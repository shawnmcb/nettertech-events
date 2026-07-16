<?php
/**
 * Tests for AttendeesSummaryService (NTE-143).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Attendees
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Attendees;

use NetterTechEvents\Admin\Attendees\AttendeesSummaryService;
use NetterTechEvents\Contracts\CapacityCalculatorInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @coversDefaultClass \NetterTechEvents\Admin\Attendees\AttendeesSummaryService
 */
class AttendeesSummaryServiceTest extends \NetterTechEventsTestCase {

	/**
	 * Mock database.
	 *
	 * @var \wpdb|Mockery\MockInterface
	 */
	private $db;

	/**
	 * Mock event repository.
	 *
	 * @var EventRepositoryInterface|Mockery\MockInterface
	 */
	private $event_repo;

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * Mock capacity calculator.
	 *
	 * @var CapacityCalculatorInterface|Mockery\MockInterface
	 */
	private $capacity;

	protected function setUp(): void {
		parent::setUp();

		$this->db         = Mockery::mock( \wpdb::class );
		$this->db->prefix = 'wp_';
		$this->db->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => $sql )->byDefault();
		$this->db->shouldReceive( 'get_row' )->andReturn( array( 'total_guests' => 0, 'checked_in_guests' => 0 ) )->byDefault();

		$this->event_repo      = Mockery::mock( EventRepositoryInterface::class );
		$this->occurrence_repo = Mockery::mock( OccurrenceRepositoryInterface::class );
		$this->capacity        = Mockery::mock( CapacityCalculatorInterface::class );

		// Event-scoped builds (occurrence_id = 0) resolve their date from the
		// event's first occurrence rather than find().
		$this->occurrence_repo->shouldReceive( 'for_event' )->andReturn( array() )->byDefault();
		$this->occurrence_repo->shouldReceive( 'find' )->andReturn( null )->byDefault();

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $s, $p, $n ) => 1 === (int) $n ? $s : $p );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'http://example.test/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
	}

	/**
	 * Build the service under test.
	 *
	 * @return AttendeesSummaryService
	 */
	private function service(): AttendeesSummaryService {
		return new AttendeesSummaryService( $this->db, $this->event_repo, $this->occurrence_repo, $this->capacity );
	}

	/**
	 * An event the repository will return.
	 *
	 * @return Event
	 */
	private function given_event(): Event {
		$event     = new Event();
		$event->id = 5;
		$this->event_repo->shouldReceive( 'find' )->andReturn( $event )->byDefault();

		return $event;
	}

	/**
	 * Stub the three get_results() calls the builder makes, in order: ticket-type
	 * rows, occurrence ceilings, then attendance status rows.
	 *
	 * @param array<int, array<string, mixed>> $ticket_rows Ticket type rows.
	 * @param array<int, array<string, mixed>> $ceilings    Occurrence ceiling rows.
	 * @param array<int, array<string, mixed>> $status_rows Attendance status rows.
	 * @return void
	 */
	private function stub_queries( array $ticket_rows, array $ceilings = array(), array $status_rows = array() ): void {
		$this->db->shouldReceive( 'get_results' )->andReturn( $ticket_rows, $ceilings, $status_rows );
	}

	/**
	 * An occurrence ceiling row.
	 *
	 * @param int      $id       Occurrence ID.
	 * @param int|null $capacity Ceiling (null = uncapped).
	 * @return array<string, mixed>
	 */
	private function ceiling( int $id, ?int $capacity ): array {
		return array(
			'id'       => $id,
			'capacity' => $capacity,
		);
	}

	/**
	 * A ticket type row as returned by the ticket-overview query.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function ticket_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'            => 1,
				'name'          => 'General',
				'capacity'      => 100,
				'capacity_type' => 'fixed',
				'occurrence_id' => 42,
				'scope'         => 'occurrence',
				'issued'        => 0,
				'checked_in'    => 0,
				'issued_total'  => 0,
			),
			$overrides
		);
	}

	/**
	 * Three fixed tiers sharing one room report the room's remainder, not three
	 * private allotments.
	 *
	 * Regression (CJAC "Celtic Junction Presents Beoga"): Standing / Seated / Youth
	 * each carried the hall's 250-seat capacity, so summing `capacity - issued` per
	 * tier reported 638 seats available in a 250-seat hall. The house is sold once.
	 *
	 * @covers ::build_ticket_overview
	 * @covers ::resolve_house
	 * @covers ::resolve_available
	 */
	public function test_fixed_tiers_share_one_house(): void {
		$this->given_event();

		$this->stub_queries(
			array(
				$this->ticket_row( array( 'id' => 1, 'name' => 'Beoga in Concert', 'capacity' => 250, 'issued' => 101, 'issued_total' => 101 ) ),
				$this->ticket_row( array( 'id' => 2, 'name' => 'Standing room', 'capacity' => 250, 'issued' => 5, 'issued_total' => 5 ) ),
				$this->ticket_row( array( 'id' => 3, 'name' => 'Youth Ticket', 'capacity' => 250, 'issued' => 6, 'issued_total' => 6 ) ),
			),
			array( $this->ceiling( 42, null ) )
		);

		$summary = $this->service()->build( 5, 0 );
		$tickets = $summary['tickets'];

		// House = largest tier capacity = 250. Issued 112 => 138 left, for anyone.
		$this->assertSame( 250, $tickets['house'] );
		$this->assertSame( 112, $tickets['total_issued'] );
		$this->assertSame( 138, $tickets['total_available'] );
		$this->assertSame( 138, $tickets['rows'][0]['available'] );
		$this->assertSame( 138, $tickets['rows'][1]['available'] );
		$this->assertSame( 138, $tickets['rows'][2]['available'] );
		$this->assertTrue( $tickets['shares_house'] );
		// All three quote the house, so all three carry the footnote marker.
		foreach ( $tickets['rows'] as $row ) {
			$this->assertTrue( $row['house_bound'] );
		}
	}

	/**
	 * The occurrence ceiling is the house when one is set.
	 *
	 * @covers ::resolve_house
	 */
	public function test_occurrence_ceiling_is_the_house(): void {
		$this->given_event();

		$this->stub_queries(
			array(
				$this->ticket_row( array( 'id' => 1, 'name' => 'VIP Balcony', 'capacity' => 50, 'issued' => 4, 'issued_total' => 4 ) ),
				$this->ticket_row( array( 'id' => 2, 'name' => 'General', 'capacity' => null, 'capacity_type' => 'shared', 'issued' => 28, 'issued_total' => 28 ) ),
			),
			array( $this->ceiling( 42, 300 ) )
		);

		$tickets = $this->service()->build( 5, 0 )['tickets'];

		// House 300, 32 issued => 268 left. VIP is further capped by its own 50.
		$this->assertSame( 300, $tickets['house'] );
		$this->assertSame( 46, $tickets['rows'][0]['available'] );
		$this->assertSame( 268, $tickets['rows'][1]['available'] );
		$this->assertSame( 268, $tickets['total_available'] );
		// VIP quotes its own 50-seat limit, not the house, so it takes no marker.
		$this->assertFalse( $tickets['rows'][0]['house_bound'] );
		$this->assertTrue( $tickets['rows'][1]['house_bound'] );
	}

	/**
	 * Per-tier check-ins surface on each row and in the total (NTE-143 amendment).
	 *
	 * A pass row is flagged so the renderer can label its check-ins as gate scans
	 * across dates rather than people — one buyer checked in on two days is two
	 * scans of one pass, not two passes.
	 *
	 * @covers ::build_ticket_overview
	 */
	public function test_checked_in_breaks_out_per_tier_and_flags_passes(): void {
		$this->given_event();

		$this->stub_queries(
			array(
				$this->ticket_row( array( 'id' => 1, 'name' => 'General', 'capacity' => 100, 'issued' => 10, 'checked_in' => 4, 'issued_total' => 10 ) ),
				$this->ticket_row(
					array(
						'id'            => 2,
						'name'          => 'Weekend Pass',
						'scope'         => 'event',
						'occurrence_id' => null,
						'capacity'      => 25,
						'issued'        => 1,
						'checked_in'    => 2,
						'issued_total'  => 2,
					)
				),
			),
			array( $this->ceiling( 42, 300 ) )
		);

		$tickets = $this->service()->build( 5, 0 )['tickets'];

		$this->assertSame( 4, $tickets['rows'][0]['checked_in'] );
		$this->assertFalse( $tickets['rows'][0]['is_pass'] );
		$this->assertSame( 2, $tickets['rows'][1]['checked_in'] );
		$this->assertTrue( $tickets['rows'][1]['is_pass'] );
		$this->assertSame( 6, $tickets['total_checked_in'] );
		// The pass counts once in issued (the display figure), while its two
		// per-date rows still occupy the house via issued_total.
		$this->assertSame( 11, $tickets['total_issued'] );
	}

	/**
	 * A tier never reports more than its own capacity, even in a bigger house.
	 *
	 * @covers ::resolve_available
	 */
	public function test_tier_capacity_bounds_availability_below_the_house(): void {
		$this->given_event();

		$this->stub_queries(
			array( $this->ticket_row( array( 'capacity' => 20, 'issued' => 5, 'issued_total' => 5 ) ) ),
			array( $this->ceiling( 42, 500 ) )
		);

		$tickets = $this->service()->build( 5, 0 )['tickets'];

		// House has 495 left, but this tier only ever sells 20.
		$this->assertSame( 15, $tickets['rows'][0]['available'] );
		$this->assertFalse( $tickets['rows'][0]['house_bound'] );
		// Total is what the room has left, not the tier's remainder.
		$this->assertSame( 495, $tickets['total_available'] );
	}

	/**
	 * A stale value left in `capacity` by the hidden admin field must not be read
	 * for a shared tier — the house decides.
	 *
	 * @covers ::resolve_available
	 */
	public function test_shared_tier_ignores_capacity_column(): void {
		$this->given_event();

		$this->stub_queries(
			array( $this->ticket_row( array( 'capacity' => 9999, 'capacity_type' => 'shared', 'issued' => 30, 'issued_total' => 30 ) ) ),
			array( $this->ceiling( 42, 80 ) )
		);

		$tickets = $this->service()->build( 5, 0 )['tickets'];

		$this->assertSame( 50, $tickets['rows'][0]['available'] );
	}

	/**
	 * Each date is its own house, so occurrences sum.
	 *
	 * @covers ::resolve_house
	 */
	public function test_each_occurrence_is_its_own_house(): void {
		$this->given_event();

		$this->stub_queries(
			array(
				$this->ticket_row( array( 'id' => 1, 'occurrence_id' => 42, 'capacity' => null, 'capacity_type' => 'shared', 'issued' => 15, 'issued_total' => 15 ) ),
				$this->ticket_row( array( 'id' => 2, 'occurrence_id' => 43, 'capacity' => null, 'capacity_type' => 'shared', 'issued' => 20, 'issued_total' => 20 ) ),
			),
			array( $this->ceiling( 42, 100 ), $this->ceiling( 43, 60 ) )
		);

		$tickets = $this->service()->build( 5, 0 )['tickets'];

		// 160 seats across two dates, 35 issued.
		$this->assertSame( 160, $tickets['house'] );
		$this->assertSame( 125, $tickets['total_available'] );
	}

	/**
	 * An uncapped occurrence carrying an unlimited tier makes the house unbounded.
	 *
	 * @covers ::resolve_house
	 */
	public function test_unlimited_tier_without_ceiling_unbounds_the_house(): void {
		$this->given_event();

		$this->stub_queries(
			array( $this->ticket_row( array( 'capacity' => null, 'capacity_type' => 'unlimited', 'issued' => 4, 'issued_total' => 4 ) ) ),
			array( $this->ceiling( 42, null ) )
		);

		$tickets = $this->service()->build( 5, 0 )['tickets'];

		$this->assertNull( $tickets['house'] );
		$this->assertNull( $tickets['rows'][0]['available'] );
		$this->assertNull( $tickets['total_available'] );
		$this->assertFalse( $tickets['shares_house'] );
	}

	/**
	 * A ceiling bounds the house even when a tier carries no capacity of its own,
	 * which is the case that keeps SHARED tiers from unbounding it.
	 *
	 * @covers ::resolve_house
	 */
	public function test_ceiling_bounds_a_shared_tier(): void {
		$this->given_event();

		$this->stub_queries(
			array( $this->ticket_row( array( 'capacity' => null, 'capacity_type' => 'shared', 'issued' => 10, 'issued_total' => 10 ) ) ),
			array( $this->ceiling( 42, 90 ) )
		);

		$tickets = $this->service()->build( 5, 0 )['tickets'];

		$this->assertSame( 90, $tickets['house'] );
		$this->assertSame( 80, $tickets['total_available'] );
	}

	/**
	 * Event-scoped tiers collapse to their largest capacity rather than summing.
	 *
	 * @covers ::resolve_house
	 */
	public function test_event_scoped_tiers_collapse_to_one_house(): void {
		$this->given_event();

		$this->stub_queries(
			array(
				$this->ticket_row( array( 'id' => 1, 'occurrence_id' => 0, 'capacity' => 250, 'issued' => 10, 'issued_total' => 10 ) ),
				$this->ticket_row( array( 'id' => 2, 'occurrence_id' => 0, 'capacity' => 250, 'issued' => 5, 'issued_total' => 5 ) ),
			),
			array()
		);

		$tickets = $this->service()->build( 5, 0 )['tickets'];

		$this->assertSame( 250, $tickets['house'] );
		$this->assertSame( 235, $tickets['total_available'] );
	}

	/**
	 * Seated tiers defer to the seating add-on, still bounded by the house.
	 *
	 * @covers ::resolve_available
	 */
	public function test_seated_tier_defers_to_capacity_calculator(): void {
		$this->given_event();

		$this->capacity->shouldReceive( 'get_available_count' )->once()->with( 7 )->andReturn( 18 );

		$this->stub_queries(
			array( $this->ticket_row( array( 'id' => 7, 'capacity' => null, 'capacity_type' => 'seated', 'issued' => 2, 'issued_total' => 2 ) ) ),
			array( $this->ceiling( 42, 500 ) )
		);

		$tickets = $this->service()->build( 5, 0 )['tickets'];

		$this->assertSame( 18, $tickets['rows'][0]['available'] );
	}

	/**
	 * A seated tier cannot exceed what the house has left.
	 *
	 * @covers ::resolve_available
	 */
	public function test_seated_tier_is_bounded_by_the_house(): void {
		$this->given_event();

		$this->capacity->shouldReceive( 'get_available_count' )->with( 7 )->andReturn( 400 );

		$this->stub_queries(
			array( $this->ticket_row( array( 'id' => 7, 'capacity' => null, 'capacity_type' => 'seated', 'issued' => 10, 'issued_total' => 10 ) ) ),
			array( $this->ceiling( 42, 100 ) )
		);

		$tickets = $this->service()->build( 5, 0 )['tickets'];

		$this->assertSame( 90, $tickets['rows'][0]['available'] );
	}

	/**
	 * A ticket type that has sold nothing still appears, as "0 issued".
	 *
	 * @covers ::build_ticket_overview
	 */
	public function test_zero_issued_ticket_type_is_listed(): void {
		$this->given_event();

		$this->stub_queries(
			array(
				$this->ticket_row( array( 'id' => 1, 'name' => 'General', 'capacity' => 100, 'issued' => 4, 'issued_total' => 4 ) ),
				$this->ticket_row( array( 'id' => 2, 'name' => 'Student', 'capacity' => 20, 'issued' => 0, 'issued_total' => 0 ) ),
			),
			array( $this->ceiling( 42, 100 ) )
		);

		$tickets = $this->service()->build( 5, 0 )['tickets'];

		$this->assertCount( 2, $tickets['rows'] );
		$this->assertSame( 'Student', $tickets['rows'][1]['name'] );
		$this->assertSame( 0, $tickets['rows'][1]['issued'] );
		$this->assertSame( 20, $tickets['rows'][1]['available'] );
	}

	/**
	 * A single tier needs no "counted once" footnote.
	 *
	 * @covers ::build_ticket_overview
	 */
	public function test_single_tier_does_not_share_a_house(): void {
		$this->given_event();

		$this->stub_queries(
			array( $this->ticket_row( array( 'capacity' => 100, 'issued' => 4, 'issued_total' => 4 ) ) ),
			array( $this->ceiling( 42, 100 ) )
		);

		$this->assertFalse( $this->service()->build( 5, 0 )['tickets']['shares_house'] );
	}

	/**
	 * Under an occurrence drill-down, availability accounts for sales on the other
	 * occurrences while issued still reflects only what the list shows.
	 *
	 * @covers ::resolve_available
	 */
	public function test_drilldown_available_uses_issued_total(): void {
		$this->given_event();

		$this->stub_queries(
			array( $this->ticket_row( array( 'capacity' => 100, 'issued' => 4, 'issued_total' => 30 ) ) ),
			array( $this->ceiling( 42, 500 ) )
		);

		$tickets = $this->service()->build( 5, 42 )['tickets'];

		$this->assertSame( 4, $tickets['rows'][0]['issued'] );
		$this->assertSame( 70, $tickets['rows'][0]['available'] );
	}

	/**
	 * The Ticket Overview total must equal the Attendance Overview tickets total.
	 *
	 * This is the reconciliation invariant: issued is derived from the same
	 * confirmed rows the list shows, so the headline never disagrees with the
	 * table beneath it (the "0 issued while 28 present" failure mode).
	 *
	 * @covers ::build
	 * @covers ::build_ticket_overview
	 * @covers ::build_attendance_overview
	 */
	public function test_ticket_overview_reconciles_with_attendance_total(): void {
		$this->given_event();

		$this->stub_queries(
			array(
				$this->ticket_row( array( 'id' => 1, 'name' => 'Standing room', 'capacity' => 250, 'issued' => 12, 'issued_total' => 12 ) ),
				$this->ticket_row( array( 'id' => 2, 'name' => 'Beoga in Concert', 'capacity' => null, 'capacity_type' => 'shared', 'issued' => 10, 'issued_total' => 10 ) ),
				$this->ticket_row( array( 'id' => 3, 'name' => 'Youth Ticket', 'capacity' => null, 'capacity_type' => 'shared', 'issued' => 6, 'issued_total' => 6 ) ),
			),
			array( $this->ceiling( 42, 250 ) ),
			array(
				array( 'status' => 'confirmed', 'c' => 28 ),
				array( 'status' => 'cancelled', 'c' => 2 ),
			)
		);
		$this->db->shouldReceive( 'get_row' )->andReturn( array( 'total_guests' => 28, 'checked_in_guests' => 7 ) );

		$summary = $this->service()->build( 5, 42 );

		$this->assertSame( 28, $summary['tickets']['total_issued'] );
		$this->assertSame( 28, $summary['attendance']['total_guests'] );
		$this->assertSame( $summary['attendance']['total_guests'], $summary['tickets']['total_issued'] );
	}

	/**
	 * Status counts are guests, not attendee rows, so the confirmed line agrees
	 * with the Tickets line above it rather than reading "Confirmed 5" under 32.
	 *
	 * @covers ::build_attendance_overview
	 */
	public function test_status_counts_are_guests_not_rows(): void {
		$this->given_event();

		$this->stub_queries(
			array(),
			array(),
			array(
				array( 'status' => 'confirmed', 'c' => 32 ),
				array( 'status' => 'cancelled', 'c' => 3 ),
			)
		);
		$this->db->shouldReceive( 'get_row' )->andReturn( array( 'total_guests' => 32, 'checked_in_guests' => 0 ) );

		$attendance = $this->service()->build( 5, 0 )['attendance'];

		$this->assertSame( 32, $attendance['status_counts']['confirmed'] );
		$this->assertSame( 3, $attendance['status_counts']['cancelled'] );
		$this->assertSame( $attendance['total_guests'], $attendance['status_counts']['confirmed'] );
	}

	/**
	 * Check-in percentage rounds against the guest total.
	 *
	 * @covers ::build_attendance_overview
	 */
	public function test_checked_in_percent(): void {
		$this->given_event();

		$this->stub_queries( array(), array(), array() );
		$this->db->shouldReceive( 'get_row' )->andReturn( array( 'total_guests' => 40, 'checked_in_guests' => 10 ) );

		$this->assertSame( 25, $this->service()->build( 5, 0 )['attendance']['checked_in_percent'] );
	}

	/**
	 * Zero guests must not divide by zero.
	 *
	 * @covers ::build_attendance_overview
	 */
	public function test_zero_guests_yields_zero_percent(): void {
		$this->given_event();

		$this->stub_queries( array(), array(), array() );
		$this->db->shouldReceive( 'get_row' )->andReturn( array( 'total_guests' => 0, 'checked_in_guests' => 0 ) );

		$this->assertSame( 0, $this->service()->build( 5, 0 )['attendance']['checked_in_percent'] );
	}

	/**
	 * The percentage rounds to nearest — it does not truncate or round up.
	 *
	 * 10/40 is exactly 25%, so it cannot tell rounding apart from flooring or
	 * ceiling. These thirds can: 1/3 must not become 34, and 2/3 must not
	 * become 66.
	 *
	 * @dataProvider check_in_rounding_cases
	 * @covers ::build_attendance_overview
	 * @param int $checked_in Guests checked in.
	 * @param int $total      Total guests.
	 * @param int $expected   Expected whole-number percentage.
	 * @return void
	 */
	public function test_checked_in_percent_rounds_to_nearest( int $checked_in, int $total, int $expected ): void {
		$this->given_event();

		$this->stub_queries( array(), array(), array() );
		$this->db->shouldReceive( 'get_row' )->andReturn(
			array( 'total_guests' => $total, 'checked_in_guests' => $checked_in )
		);

		$this->assertSame( $expected, $this->service()->build( 5, 0 )['attendance']['checked_in_percent'] );
	}

	/**
	 * @return array<string, array{0: int, 1: int, 2: int}>
	 */
	public static function check_in_rounding_cases(): array {
		return array(
			'one third rounds down'  => array( 1, 3, 33 ),
			'two thirds rounds up'   => array( 2, 3, 67 ),
			'exact half rounds up'   => array( 1, 2, 50 ),
			'all guests checked in'  => array( 3, 3, 100 ),
		);
	}

	/**
	 * A totals row the database never returned must read as zero, not as a fatal.
	 *
	 * @covers ::build_attendance_overview
	 * @return void
	 */
	public function test_absent_totals_row_reads_as_zero(): void {
		$this->given_event();

		$this->stub_queries( array(), array(), array() );
		$this->db->shouldReceive( 'get_row' )->andReturn( array() );

		$attendance = $this->service()->build( 5, 0 )['attendance'];

		$this->assertSame( 0, $attendance['total_guests'] );
		$this->assertSame( 0, $attendance['checked_in_guests'] );
		$this->assertSame( 0, $attendance['checked_in_percent'] );
	}

	/**
	 * A house already sold past its capacity reports nothing left — never a
	 * negative remainder.
	 *
	 * NTE-144 scope item 5: a site that oversold before the house rule landed must
	 * not render "-50 available" or fatal. Availability floors at zero.
	 *
	 * @covers ::build_ticket_overview
	 * @covers ::resolve_available
	 * @return void
	 */
	public function test_oversold_house_floors_availability_at_zero(): void {
		$this->given_event();

		$this->stub_queries(
			array(
				$this->ticket_row( array( 'id' => 1, 'capacity' => 250, 'issued' => 180, 'issued_total' => 180 ) ),
				$this->ticket_row( array( 'id' => 2, 'capacity' => 250, 'issued' => 120, 'issued_total' => 120 ) ),
			),
			array( $this->ceiling( 42, 250 ) )
		);

		$tickets = $this->service()->build( 5, 42 )['tickets'];

		// 300 issued into a 250-seat house.
		$this->assertSame( 300, $tickets['total_issued'] );
		$this->assertSame( 0, $tickets['total_available'] );
		$this->assertSame( 0, $tickets['rows'][0]['available'] );
		$this->assertSame( 0, $tickets['rows'][1]['available'] );
	}

	/**
	 * With no house at all, nothing quotes a remainder and nothing is footnoted.
	 *
	 * @covers ::build_ticket_overview
	 * @covers ::resolve_available
	 * @return void
	 */
	public function test_unbounded_house_marks_no_row_as_house_bound(): void {
		$this->given_event();

		$this->stub_queries(
			array(
				$this->ticket_row( array( 'id' => 1, 'capacity' => null, 'capacity_type' => 'unlimited', 'issued' => 5, 'issued_total' => 5 ) ),
				$this->ticket_row( array( 'id' => 2, 'capacity' => null, 'capacity_type' => 'shared', 'issued' => 3, 'issued_total' => 3 ) ),
			),
			array( $this->ceiling( 42, null ) )
		);

		$tickets = $this->service()->build( 5, 42 )['tickets'];

		$this->assertNull( $tickets['house'] );
		$this->assertNull( $tickets['total_available'] );
		$this->assertNull( $tickets['rows'][0]['available'] );
		$this->assertNull( $tickets['rows'][1]['available'] );
		$this->assertFalse( $tickets['rows'][0]['house_bound'] );
		$this->assertFalse( $tickets['rows'][1]['house_bound'] );
		$this->assertFalse( $tickets['shares_house'] );
	}

	/**
	 * The footnote is earned by two or more rows actually quoting the house — one
	 * row at the house beside a row held down by its own capacity is not a shared
	 * house from the reader's point of view, and must not be asterisked.
	 *
	 * @covers ::build_ticket_overview
	 * @return void
	 */
	public function test_shares_house_requires_two_rows_quoting_the_house(): void {
		$this->given_event();

		$this->stub_queries(
			array(
				// A 50-seat balcony: bounded by its own capacity (50 - 10 = 40), not the house.
				$this->ticket_row( array( 'id' => 1, 'capacity' => 50, 'issued' => 10, 'issued_total' => 10 ) ),
				// Sells into the house: 300 - 30 = 270.
				$this->ticket_row( array( 'id' => 2, 'capacity' => null, 'capacity_type' => 'shared', 'issued' => 20, 'issued_total' => 20 ) ),
			),
			array( $this->ceiling( 42, 300 ) )
		);

		$tickets = $this->service()->build( 5, 42 )['tickets'];

		$this->assertSame( 270, $tickets['total_available'] );
		$this->assertSame( 40, $tickets['rows'][0]['available'] );
		$this->assertSame( 270, $tickets['rows'][1]['available'] );
		$this->assertFalse( $tickets['rows'][0]['house_bound'] );
		$this->assertTrue( $tickets['rows'][1]['house_bound'] );
		// Only one row quotes the house, so no footnote.
		$this->assertFalse( $tickets['shares_house'] );
	}

	/**
	 * The shared flag reads capacity_type, and a nameless row still renders.
	 *
	 * @covers ::build_ticket_overview
	 * @return void
	 */
	public function test_row_flags_and_name_fallback(): void {
		$this->given_event();

		$this->stub_queries(
			array(
				$this->ticket_row( array( 'id' => 1, 'name' => null, 'capacity' => 100, 'capacity_type' => 'fixed' ) ),
				$this->ticket_row( array( 'id' => 2, 'name' => 'Shared tier', 'capacity' => null, 'capacity_type' => 'shared' ) ),
			),
			array( $this->ceiling( 42, 100 ) )
		);

		$rows = $this->service()->build( 5, 42 )['tickets']['rows'];

		$this->assertSame( 'Unknown ticket', $rows[0]['name'] );
		$this->assertFalse( $rows[0]['is_shared'] );
		$this->assertSame( 'Shared tier', $rows[1]['name'] );
		$this->assertTrue( $rows[1]['is_shared'] );
	}

	/**
	 * A missing event yields empty details rather than a fatal.
	 *
	 * @covers ::build_event_details
	 */
	public function test_missing_event_returns_empty_details(): void {
		$this->event_repo->shouldReceive( 'find' )->andReturn( null );

		$this->stub_queries( array(), array(), array() );

		$summary = $this->service()->build( 999, 0 );

		$this->assertSame( '', $summary['event']['title'] );
		$this->assertSame( '', $summary['event']['venue'] );
	}

	/**
	 * Every headline number is an int however wpdb typed the column.
	 *
	 * The overview's contract declares ints, but the driver decides what a row
	 * actually holds: an ID arrives as the string '1', and `SUM()` is DECIMAL in
	 * MySQL, which mysqlnd hands back as a float once native types are on. Left
	 * uncast, those propagate — a float `total_available` reaches the renderer's
	 * number_format and the percentages computed from it — so the normalization
	 * is asserted with assertSame(), which is the only assertion that can see it.
	 *
	 * @covers ::build_ticket_overview
	 */
	public function test_wpdb_scalars_are_normalized_to_ints(): void {
		$this->given_event();

		$this->stub_queries(
			array(
				$this->ticket_row(
					array(
						'id'            => '1',
						'name'          => 'General',
						'capacity'      => '250',
						'occurrence_id' => '42',
						'issued'        => 12.0,
						'checked_in'    => 5.0,
						'issued_total'  => 12.0,
					)
				),
			),
			array( $this->ceiling( 42, null ) )
		);

		$tickets = $this->service()->build( 5, 0 )['tickets'];

		// House = the tier's own 250; 12 issued leaves 238 for anyone.
		$this->assertSame( 12, $tickets['total_issued'] );
		$this->assertSame( 5, $tickets['total_checked_in'] );
		$this->assertSame( 238, $tickets['total_available'] );
		$this->assertSame( 1, $tickets['rows'][0]['id'] );
		$this->assertSame( 12, $tickets['rows'][0]['issued'] );
		$this->assertSame( 5, $tickets['rows'][0]['checked_in'] );
	}

	/**
	 * A row missing its optional columns falls back to zeroes, silently.
	 *
	 * `id`, `name`, `scope` and `checked_in` are all read through `??`, so a row
	 * that omits them — an aggregate that returned no matching rows, a query
	 * shape narrowed later — must default rather than warn. The warning matters
	 * as much as the value: an undefined-key notice on an admin screen renders
	 * as raw text above the ticket table, so the handler below fails the test on
	 * any warning the build emits.
	 *
	 * @covers ::build_ticket_overview
	 */
	public function test_row_missing_optional_columns_defaults_without_warnings(): void {
		$this->given_event();

		$this->stub_queries(
			array(
				array(
					'capacity'      => 250,
					'capacity_type' => 'fixed',
					'occurrence_id' => 42,
					'issued'        => 12,
					'issued_total'  => 12,
				),
			),
			array( $this->ceiling( 42, null ) )
		);

		set_error_handler(
			static function ( int $errno, string $message ): bool {
				throw new \RuntimeException( 'Summary build emitted a warning: ' . $message );
			},
			E_WARNING | E_NOTICE
		);

		try {
			$tickets = $this->service()->build( 5, 0 )['tickets'];
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 0, $tickets['total_checked_in'] );
		$this->assertSame( 0, $tickets['rows'][0]['id'] );
		$this->assertSame( 'Unknown ticket', $tickets['rows'][0]['name'] );
		$this->assertSame( 0, $tickets['rows'][0]['checked_in'] );
		$this->assertFalse( $tickets['rows'][0]['is_pass'] );

		// Every key the renderer reads is present, defaulted or not.
		$this->assertSame(
			array( 'id', 'name', 'issued', 'available', 'checked_in', 'is_pass', 'is_shared', 'house_bound' ),
			array_keys( $tickets['rows'][0] )
		);
	}
}
