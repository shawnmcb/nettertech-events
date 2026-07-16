<?php
/**
 * Assembles the Attendees-screen summary blocks.
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Attendees;

use NetterTechEvents\Contracts\AttendeesSummaryServiceInterface;
use NetterTechEvents\Contracts\CapacityCalculatorInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Services\Capacity\HouseRule;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the Event Details, Ticket Overview and Attendance Overview data shown
 * above the attendee list on the event-scoped Purchases screen.
 *
 * Reconciliation invariant: every headline number is derived from the same
 * confirmed-attendee rows the table lists, never from a cached `sold_count`.
 * So "issued" always equals what an admin can count below it — the failure mode
 * where a ticket overview reads "0 issued" while dozens of attendees are present
 * (because only completed orders were counted) cannot occur here.
 *
 * Capacity invariant: ticket tiers sell one room, they do not each conjure their
 * own. Availability is therefore bounded by the house — the occurrence ceiling
 * when set, else the largest tier capacity, summed across dates — and the Total
 * reports what the house has left rather than the sum of the tiers. Summing them
 * is the failure mode where three 250-seat tiers in a 250-seat hall reported 638
 * seats available. The house is computed the same way the Events list "% of
 * capacity" column computes its denominator, so the two screens cannot disagree.
 *
 * Within that bound only FIXED reads `ticket_types.capacity`
 * ({@see CapacityType::uses_capacity_column()}); SHARED carries no capacity of
 * its own, and SEATED defers to the seating add-on's filtered count.
 *
 * @since 1.1.2
 */
final class AttendeesSummaryService implements AttendeesSummaryServiceInterface {

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Capacity calculator — source of truth for shared pools and seated counts.
	 *
	 * @var CapacityCalculatorInterface
	 */
	private CapacityCalculatorInterface $capacity;

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Constructor.
	 *
	 * @param \wpdb                         $db              Database handle.
	 * @param EventRepositoryInterface      $event_repo      Event repository.
	 * @param OccurrenceRepositoryInterface $occurrence_repo Occurrence repository.
	 * @param CapacityCalculatorInterface   $capacity        Capacity calculator.
	 */
	public function __construct(
		\wpdb $db,
		EventRepositoryInterface $event_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		CapacityCalculatorInterface $capacity
	) {
		$this->db              = $db;
		$this->event_repo      = $event_repo;
		$this->occurrence_repo = $occurrence_repo;
		$this->capacity        = $capacity;
	}

	/**
	 * Build the summary for an event-scoped Attendees view.
	 *
	 * @param int $event_id      Event being viewed.
	 * @param int $occurrence_id Occurrence drill-down filter (0 = all occurrences).
	 * @return array{
	 *     event: array{title: string, date: string, venue: string, edit_url: string, view_url: string},
	 *     tickets: array{rows: array<int, array{id: int, name: string, issued: int, available: ?int, checked_in: int, is_pass: bool, is_shared: bool, house_bound: bool}>, total_issued: int, total_checked_in: int, total_available: ?int, house: ?int, shares_house: bool},
	 *     attendance: array{total_guests: int, status_counts: array<string, int>, checked_in_guests: int, checked_in_percent: int}
	 * }
	 */
	public function build( int $event_id, int $occurrence_id ): array {
		return array(
			'event'      => $this->build_event_details( $event_id, $occurrence_id ),
			'tickets'    => $this->build_ticket_overview( $event_id, $occurrence_id ),
			'attendance' => $this->build_attendance_overview( $event_id, $occurrence_id ),
		);
	}

	/**
	 * Build the Event Details block.
	 *
	 * @param int $event_id      Event ID.
	 * @param int $occurrence_id Occurrence filter (0 = all).
	 * @return array{title: string, date: string, venue: string, edit_url: string, view_url: string}
	 */
	private function build_event_details( int $event_id, int $occurrence_id ): array {
		$event = $this->event_repo->find( $event_id );
		if ( null === $event ) {
			return array(
				'title'    => '',
				'date'     => '',
				'venue'    => '',
				'edit_url' => '',
				'view_url' => '',
			);
		}

		// Events are custom-table entities behind a shadow post type, so the
		// events-list edit route is used rather than get_edit_post_link().
		$edit_url = add_query_arg(
			array(
				'page'     => 'nettertech-events',
				'action'   => 'edit',
				'event_id' => $event_id,
			),
			admin_url( 'admin.php' )
		);

		return array(
			'title'    => $event->title,
			'date'     => $this->resolve_display_date( $event_id, $occurrence_id ),
			'venue'    => (string) ( $event->venue_name ?? '' ),
			'edit_url' => (string) $edit_url,
			'view_url' => (string) $event->get_permalink(),
		);
	}

	/**
	 * Resolve the human date shown in Event Details.
	 *
	 * Uses the selected occurrence when drilled down, else the event's earliest
	 * occurrence, so the header reflects what the admin is looking at.
	 *
	 * @param int $event_id      Event ID.
	 * @param int $occurrence_id Occurrence filter (0 = all).
	 * @return string Formatted date, or '' when none is available.
	 */
	private function resolve_display_date( int $event_id, int $occurrence_id ): string {
		$start = '';

		if ( $occurrence_id > 0 ) {
			$occurrence = $this->occurrence_repo->find( $occurrence_id );
			$start      = $occurrence instanceof \NetterTechEvents\Models\Occurrence ? $occurrence->start_datetime : '';
		} else {
			$occurrences = $this->occurrence_repo->for_event( $event_id, array( 'limit' => 1 ) );
			$first       = $occurrences[0] ?? null;
			$start       = $first instanceof \NetterTechEvents\Models\Occurrence ? $first->start_datetime : '';
		}

		if ( '' === $start ) {
			return '';
		}

		try {
			$date = new \DateTime( $start );
		} catch ( \Exception $e ) {
			return '';
		}

		return $date->format( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) );
	}

	/**
	 * Build the Ticket Overview block from confirmed attendee records.
	 *
	 * @param int $event_id      Event ID.
	 * @param int $occurrence_id Occurrence filter (0 = all).
	 * @return array{rows: array<int, array{id: int, name: string, issued: int, available: ?int, checked_in: int, is_pass: bool, is_shared: bool, house_bound: bool}>, total_issued: int, total_checked_in: int, total_available: ?int, house: ?int, shares_house: bool}
	 */
	private function build_ticket_overview( int $event_id, int $occurrence_id ): array {
		$rows     = $this->fetch_ticket_type_rows( $event_id, $occurrence_id );
		$ceilings = $this->fetch_occurrence_ceilings( $event_id, $occurrence_id );

		$house = $this->resolve_house( $rows, $ceilings );

		$total_issued     = 0;
		$total_checked_in = 0;
		$house_issued     = 0;
		foreach ( $rows as $row ) {
			$total_issued     += (int) $row['issued'];
			$total_checked_in += (int) ( $row['checked_in'] ?? 0 );
			$house_issued     += (int) $row['issued_total'];
		}

		$house_remaining = null === $house ? null : max( 0, $house - $house_issued );

		$out = array();
		foreach ( $rows as $row ) {
			$available = $this->resolve_available( $row, $house_remaining );
			$issued    = (int) $row['issued'];
			$checked   = (int) ( $row['checked_in'] ?? 0 );

			$out[] = array(
				'id'          => (int) ( $row['id'] ?? 0 ),
				'name'        => (string) ( $row['name'] ?? __( 'Unknown ticket', 'nettertech-events' ) ),
				'issued'      => $issued,
				'available'   => $available,
				// Check-ins per tier (amendment 2026-07-15). For a pass this counts
				// gate scans across dates, which can legitimately exceed the pass
				// count — one buyer, two days — so the percentage denominator for a
				// pass is issued × dates-with-rows, handled at render time via is_pass.
				'checked_in'  => $checked,
				'is_pass'     => 'event' === (string) ( $row['scope'] ?? '' ),
				'is_shared'   => CapacityType::SHARED->value === (string) $row['capacity_type'],
				// Only rows actually reporting the house remainder carry the
				// footnote marker. A tier held below it by its own capacity — a
				// 50-seat balcony in a 250-seat hall — is quoting its own limit,
				// so an asterisk pointing at the house would misexplain it.
				'house_bound' => null !== $house_remaining && $available === $house_remaining,
			);
		}

		// The footnote only earns its place when more than one row quotes the house.
		$house_bound_rows = count( array_filter( $out, static fn( array $row ): bool => $row['house_bound'] ) );

		return array(
			'rows'             => $out,
			'total_issued'     => $total_issued,
			'total_checked_in' => $total_checked_in,
			// The house is one allotment however many tiers sell into it, so the
			// total is what the room has left — never the sum of the tiers.
			'total_available'  => $house_remaining,
			'house'            => $house,
			'shares_house'     => null !== $house && count( $out ) > 1 && $house_bound_rows > 1,
		);
	}

	/**
	 * Resolve the house: how many seats the event can sell in total.
	 *
	 * Mirrors {@see \NetterTechEvents\Contracts\TicketTypeQueryRepositoryInterface::event_capacity_for_events()},
	 * the denominator behind the Events list "% of capacity" column, so the two
	 * screens cannot disagree about how big the room is. Tiers on an occurrence
	 * share one house — a Standing / Seated / Youth split sells one room three
	 * ways, it does not conjure three rooms — so the house is the occurrence
	 * ceiling when set, else the largest tier capacity. Occurrences are summed
	 * because each date is its own house. Event-scoped tiers collapse to their
	 * largest capacity, and the event's house is the larger of the two totals
	 * rather than their sum, since event-scoped tiers usually describe the same
	 * room as the occurrences.
	 *
	 * @param array<int, array<string, mixed>> $rows     Ticket type rows.
	 * @param array<int, int|null>             $ceilings Occurrence ID => capacity ceiling.
	 * @return int|null House size, or null when unbounded.
	 */
	private function resolve_house( array $rows, array $ceilings ): ?int {
		$occurrence_tiers   = array();
		$event_scoped_tiers = array();

		foreach ( $rows as $row ) {
			$tier = array(
				'capacity'      => null === $row['capacity'] ? null : (int) $row['capacity'],
				'capacity_type' => (string) $row['capacity_type'],
			);

			$occurrence_id = (int) $row['occurrence_id'];

			if ( 0 === $occurrence_id ) {
				$event_scoped_tiers[] = $tier;
				continue;
			}

			$occurrence_tiers[ $occurrence_id ][] = $tier;
		}

		// Each date is its own house; the dates in scope sum.
		$occurrence_house_total = 0;
		foreach ( $ceilings as $occurrence_id => $ceiling ) {
			$house = HouseRule::house( $occurrence_tiers[ $occurrence_id ] ?? array(), $ceiling );

			if ( null === $house ) {
				return null;
			}

			$occurrence_house_total += $house;
		}

		$event_scoped_house = HouseRule::house( $event_scoped_tiers, null );

		if ( null === $event_scoped_house ) {
			return null;
		}

		return max( $occurrence_house_total, $event_scoped_house );
	}

	/**
	 * Fetch the capacity ceiling of every occurrence in scope.
	 *
	 * @param int $event_id      Event ID.
	 * @param int $occurrence_id Occurrence filter (0 = all).
	 * @return array<int, int|null> Occurrence ID => ceiling (null = uncapped).
	 */
	private function fetch_occurrence_ceilings( int $event_id, int $occurrence_id ): array {
		$occurrences = Schema::table( 'occurrences' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin summary.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table name from Schema; values bound via prepare().
		$where = $occurrence_id > 0 ? 'id = %d' : 'event_id = %d';
		$param = $occurrence_id > 0 ? $occurrence_id : $event_id;

		$rows = $this->db->get_results(
			$this->db->prepare( "SELECT id, capacity FROM {$occurrences} WHERE {$where}", $param ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$ceilings = array();
		foreach ( (array) $rows as $row ) {
			$ceilings[ (int) $row['id'] ] = null === $row['capacity'] ? null : (int) $row['capacity'];
		}

		return $ceilings;
	}

	/**
	 * Fetch every ticket type in scope with its issued counts.
	 *
	 * Driven from `ticket_types` (not from attendee rows) so a type that has sold
	 * nothing still appears as "0 issued" instead of vanishing from the overview.
	 *
	 * Two issued figures are returned. `issued` is scoped to what the attendee
	 * table below is showing, so the headline reconciles with the list. `issued_total`
	 * spans the ticket type's whole natural scope and is what availability is
	 * computed against — under an occurrence drill-down, remaining capacity for an
	 * event-scoped ticket type still has to account for the other occurrences' sales.
	 *
	 * Inactive types are included when they hold confirmed attendees; dropping them
	 * would let the ticket total disagree with the attendance total.
	 *
	 * @param int $event_id      Event ID.
	 * @param int $occurrence_id Occurrence filter (0 = all).
	 * @return array<int, array<string, mixed>> Ticket type rows.
	 */
	private function fetch_ticket_type_rows( int $event_id, int $occurrence_id ): array {
		$attendees    = Schema::table( 'attendees' );
		$occurrences  = Schema::table( 'occurrences' );
		$ticket_types = Schema::table( 'ticket_types' );

		if ( $occurrence_id > 0 ) {
			// Event-scoped types sell into every occurrence, so they stay listed
			// alongside the types bound to the occurrence being drilled into.
			// Within one date, a pass has exactly one row per sale, so the plain
			// per-date sum is already pass-correct here.
			$issued_scope  = 'a.occurrence_id = %d';
			$checked_scope = 'ac.occurrence_id = %d';
			$issued_expr   = "COALESCE( ( SELECT SUM(a.quantity) FROM {$attendees} a
						WHERE a.ticket_type_id = tt.id AND a.status = 'confirmed' AND {$issued_scope} ), 0 )";
			$type_scope    = "( tt.occurrence_id = %d OR ( tt.scope = 'event' AND tt.event_id = %d ) )";
			$params        = array( $occurrence_id, $occurrence_id, $occurrence_id, $event_id );
		} else {
			// Occurrence-scoped types carry a NULL event_id, so the event is
			// reached through the occurrence join rather than tt.event_id alone.
			$issued_scope  = "a.occurrence_id IN ( SELECT eo.id FROM {$occurrences} eo WHERE eo.event_id = %d )";
			$checked_scope = "ac.occurrence_id IN ( SELECT co.id FROM {$occurrences} co WHERE co.event_id = %d )";
			// A pass materializes one attendee row on EVERY date it spans
			// (NTE-156), so summing rows across dates counts each pass once per
			// date — one sold pass read as '2 issued' above a table showing one
			// buyer. Passes issued = the busiest date's rows, which stays right
			// when a date added later carries fewer rows than the older ones.
			$issued_expr = "CASE WHEN tt.scope = 'event' THEN
						COALESCE( ( SELECT MAX(per_date.cnt) FROM (
							SELECT SUM(ap.quantity) AS cnt FROM {$attendees} ap
							WHERE ap.ticket_type_id = tt.id AND ap.status = 'confirmed'
							GROUP BY ap.occurrence_id ) per_date ), 0 )
					ELSE
						COALESCE( ( SELECT SUM(a.quantity) FROM {$attendees} a
							WHERE a.ticket_type_id = tt.id AND a.status = 'confirmed' AND {$issued_scope} ), 0 )
					END";
			$type_scope  = '( tt.event_id = %d OR o.event_id = %d )';
			$params      = array( $event_id, $event_id, $event_id, $event_id );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin summary.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table names from Schema; values bound via prepare().
		$sql = "SELECT tt.id AS id,
					tt.name AS name,
					tt.scope AS scope,
					tt.capacity AS capacity,
					tt.capacity_type AS capacity_type,
					tt.occurrence_id AS occurrence_id,
					{$issued_expr} AS issued,
					COALESCE( ( SELECT SUM(ac.checked_in_count) FROM {$attendees} ac
						WHERE ac.ticket_type_id = tt.id AND ac.status = 'confirmed' AND {$checked_scope} ), 0 ) AS checked_in,
					COALESCE( ( SELECT SUM(at.quantity) FROM {$attendees} at
						WHERE at.ticket_type_id = tt.id AND at.status = 'confirmed' ), 0 ) AS issued_total
				FROM {$ticket_types} tt
				LEFT JOIN {$occurrences} o ON tt.occurrence_id = o.id
				WHERE ( tt.status = 'active' OR EXISTS ( SELECT 1 FROM {$attendees} ax
						WHERE ax.ticket_type_id = tt.id AND ax.status = 'confirmed' ) )
					AND {$type_scope}
				ORDER BY tt.sort_order ASC, tt.name ASC";

		$rows = $this->db->get_results( $this->db->prepare( $sql, $params ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return (array) $rows;
	}

	/**
	 * Resolve remaining capacity for a single ticket type.
	 *
	 * A tier can never sell more than the room has left, so every figure is
	 * bounded by what remains of the house. Within that bound a FIXED tier is
	 * further limited by its own capacity — a 50-seat balcony in a 250-seat hall
	 * still stops at 50. SHARED tiers carry no capacity of their own and take the
	 * house remainder directly; only FIXED reads `ticket_types.capacity`, which is
	 * meaningless for the other types and hidden from the admin form for them.
	 *
	 * @param array<string, mixed> $row             Ticket type row.
	 * @param int|null             $house_remaining Seats left in the house (null = unbounded).
	 * @return int|null Remaining capacity, or null when unlimited.
	 */
	private function resolve_available( array $row, ?int $house_remaining ): ?int {
		$type = CapacityType::tryFrom( (string) $row['capacity_type'] ) ?? CapacityType::FIXED;

		if ( CapacityType::UNLIMITED === $type || null === $house_remaining ) {
			return null;
		}

		if ( CapacityType::SEATED === $type ) {
			// The seating add-on owns seated availability via the
			// `nettertech_events_available_count` filter.
			return HouseRule::bound( $this->capacity->get_available_count( (int) $row['id'] ), $house_remaining );
		}

		$own_remaining = HouseRule::own_remaining(
			(string) $row['capacity_type'],
			null === $row['capacity'] ? null : (int) $row['capacity'],
			(int) $row['issued_total']
		);

		return HouseRule::bound( $own_remaining, $house_remaining );
	}

	/**
	 * Build the Attendance Overview block.
	 *
	 * @param int $event_id      Event ID.
	 * @param int $occurrence_id Occurrence filter (0 = all).
	 * @return array{total_guests: int, status_counts: array<string, int>, checked_in_guests: int, checked_in_percent: int}
	 */
	private function build_attendance_overview( int $event_id, int $occurrence_id ): array {
		$attendees   = Schema::table( 'attendees' );
		$occurrences = Schema::table( 'occurrences' );

		$scope = $this->scope_clause( $event_id, $occurrence_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin summary.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table names from Schema; values bound via prepare().
		// Guests, not attendee rows: one row can carry a quantity of ten. Counting
		// rows here would print "Confirmed 5" directly beneath "Tickets 32".
		$status_rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT a.status AS status, COALESCE(SUM(a.quantity), 0) AS c
				FROM {$attendees} a
				LEFT JOIN {$occurrences} o ON a.occurrence_id = o.id
				WHERE {$scope['where']}
				GROUP BY a.status",
				$scope['params']
			),
			ARRAY_A
		);

		$totals = $this->db->get_row(
			$this->db->prepare(
				"SELECT COALESCE(SUM(a.quantity), 0) AS total_guests,
					COALESCE(SUM(a.checked_in_count), 0) AS checked_in_guests
				FROM {$attendees} a
				LEFT JOIN {$occurrences} o ON a.occurrence_id = o.id
				WHERE {$scope['where']} AND a.status = 'confirmed'",
				$scope['params']
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$status_counts = array();
		foreach ( (array) $status_rows as $row ) {
			$status_counts[ (string) $row['status'] ] = (int) $row['c'];
		}

		$total_guests      = (int) ( $totals['total_guests'] ?? 0 );
		$checked_in_guests = (int) ( $totals['checked_in_guests'] ?? 0 );
		$percent           = $total_guests > 0 ? (int) round( ( $checked_in_guests / $total_guests ) * 100 ) : 0;

		return array(
			'total_guests'       => $total_guests,
			'status_counts'      => $status_counts,
			'checked_in_guests'  => $checked_in_guests,
			'checked_in_percent' => $percent,
		);
	}

	/**
	 * Build the shared WHERE clause + bound params for the current scope.
	 *
	 * @param int $event_id      Event ID.
	 * @param int $occurrence_id Occurrence filter (0 = all).
	 * @return array{where: string, params: array<int, int>}
	 */
	private function scope_clause( int $event_id, int $occurrence_id ): array {
		if ( $occurrence_id > 0 ) {
			return array(
				'where'  => 'a.occurrence_id = %d',
				'params' => array( $occurrence_id ),
			);
		}

		return array(
			'where'  => 'o.event_id = %d',
			'params' => array( $event_id ),
		);
	}
}
