<?php
/**
 * Event Query Repository interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Event;

/**
 * Interface for read-only event query implementations.
 *
 * @since 1.0.0
 */
interface EventQueryRepositoryInterface {

	/**
	 * Find an event by post ID.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return Event|null
	 */
	public function find_by_post_id( int $post_id ): ?Event;

	/**
	 * Get all events.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<Event>
	 */
	public function all( array $args = array() ): array;

	/**
	 * Get published events.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<Event>
	 */
	public function published( array $args = array() ): array;

	/**
	 * Get events by series.
	 *
	 * @param int                  $series_id Series ID.
	 * @param array<string, mixed> $args      Query arguments.
	 * @return array<Event>
	 */
	public function by_series( int $series_id, array $args = array() ): array;

	/**
	 * Count events.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return int
	 */
	public function count( array $args = array() ): int;

	/**
	 * Paginate events with filtering and search.
	 *
	 * @param array<string, mixed> $args Query arguments (page, per_page, status, search, orderby, order).
	 * @return array{items: array<Event>, total: int, pages: int}
	 */
	public function paginate( array $args = array() ): array;

	/**
	 * Check if an event has any active ticket types.
	 *
	 * @param int $event_id Event ID.
	 * @return bool True if the event has at least one active ticket type.
	 */
	public function has_ticket_types( int $event_id ): bool;

	/**
	 * Search events by title.
	 *
	 * @param string               $search Search term.
	 * @param array<string, mixed> $args   Query arguments.
	 * @return array<Event>
	 */
	public function search( string $search, array $args = array() ): array;

	/**
	 * Count search results without loading rows into memory.
	 *
	 * @param string               $search Search term.
	 * @param array<string, mixed> $args   Query arguments (status).
	 * @return int Total matching rows.
	 */
	public function search_count( string $search, array $args = array() ): int;
}
