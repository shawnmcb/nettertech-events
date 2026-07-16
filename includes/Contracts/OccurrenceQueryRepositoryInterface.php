<?php
/**
 * Occurrence Query Repository interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Occurrence;

/**
 * Interface for read-only occurrence query implementations.
 *
 * @since 1.0.0
 */
interface OccurrenceQueryRepositoryInterface {

	/**
	 * Get occurrences for an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array<Occurrence>
	 */
	public function for_event( int $event_id, array $args = array() ): array;

	/**
	 * Get occurrences for an event, grouped by past and upcoming.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array{past: array<Occurrence>, upcoming: array<Occurrence>}
	 */
	public function for_event_grouped( int $event_id, array $args = array() ): array;

	/**
	 * Get sibling occurrences for the same event.
	 *
	 * Returns all occurrences for the parent event, organized for navigation.
	 *
	 * @param int $occurrence_id The current occurrence ID.
	 * @return array{all: array<Occurrence>, past: array<Occurrence>, upcoming: array<Occurrence>, current_index: int}
	 */
	public function get_siblings( int $occurrence_id ): array;

	/**
	 * Get occurrences in a date range.
	 *
	 * @param string               $start_date Start date (Y-m-d or Y-m-d H:i:s).
	 * @param string               $end_date   End date (Y-m-d or Y-m-d H:i:s).
	 * @param array<string, mixed> $args       Query arguments.
	 * @return array<Occurrence>
	 */
	public function in_range( string $start_date, string $end_date, array $args = array() ): array;

	/**
	 * Get upcoming occurrences.
	 *
	 * @param int                  $limit Number of occurrences.
	 * @param array<string, mixed> $args  Query arguments.
	 * @return array<Occurrence>
	 */
	public function upcoming( int $limit = 10, array $args = array() ): array;

	/**
	 * Get next occurrence for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return Occurrence|null
	 */
	public function next_for_event( int $event_id ): ?Occurrence;

	/**
	 * Get occurrence date bounds per event for a batch of events.
	 *
	 * Returns, for each event, the earliest (`first`) and latest (`last`) scheduled
	 * occurrence start datetimes across ALL occurrences (past and future), plus the
	 * next upcoming (`next`) scheduled occurrence start datetime and whether that
	 * next occurrence is all-day. Executes a single batched aggregate query (no N+1).
	 *
	 * Only `scheduled` occurrences are considered (cancelled occurrences excluded),
	 * matching next_for_event()'s status filter. Events with no scheduled occurrences
	 * are omitted from the result; callers should treat a missing key as "no dates".
	 *
	 * @since 1.0.3
	 *
	 * @param array<int> $event_ids Event IDs to aggregate.
	 * @return array<int, array{first: ?string, last: ?string, next: ?string, next_all_day: bool, count: int}>
	 */
	public function date_bounds_for_events( array $event_ids ): array;

	/**
	 * Get upcoming occurrences for an event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $limit    Maximum number of occurrences.
	 * @return array<Occurrence>
	 */
	public function get_upcoming_by_event( int $event_id, int $limit = 10 ): array;

	/**
	 * Count occurrences for an event.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $status   Optional status filter.
	 * @return int
	 */
	public function count_for_event( int $event_id, string $status = '' ): int;

	/**
	 * Get filtered occurrences with pagination.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<Occurrence>, total: int, total_pages: int}
	 */
	public function get_filtered( array $args = array() ): array;
}
