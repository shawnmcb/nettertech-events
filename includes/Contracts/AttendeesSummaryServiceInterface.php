<?php
/**
 * Contract for the Attendees-screen summary builder.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the Event Details, Ticket Overview and Attendance Overview data for
 * the event-scoped Attendees view.
 *
 * @since 1.1.2
 */
interface AttendeesSummaryServiceInterface {

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
	public function build( int $event_id, int $occurrence_id ): array;
}
