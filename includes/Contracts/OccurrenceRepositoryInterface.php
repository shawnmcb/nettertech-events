<?php
/**
 * Occurrence Repository Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Occurrence;

/**
 * Interface for occurrence repository implementations.
 *
 * @since 0.1.0
 * @api
 */
interface OccurrenceRepositoryInterface {

	/**
	 * Find an occurrence by ID.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Occurrence ID.
	 * @return Occurrence|null
	 */
	public function find( int $id ): ?Occurrence;

	/**
	 * Find an occurrence by event ID and start datetime.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $event_id Event ID.
	 * @param \DateTimeInterface $datetime Start datetime.
	 * @return Occurrence|null
	 */
	public function find_by_event_and_datetime( int $event_id, \DateTimeInterface $datetime ): ?Occurrence;

	/**
	 * Get occurrences for an event.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments (status, orderby, order, limit).
	 * @return array<Occurrence>
	 */
	public function for_event( int $event_id, array $args = array() ): array;

	/**
	 * Get upcoming occurrences for an event.
	 *
	 * @since 0.1.0
	 *
	 * @param int $event_id Event ID.
	 * @param int $limit    Maximum number to return.
	 * @return array<Occurrence>
	 */
	public function get_upcoming_by_event( int $event_id, int $limit = 10 ): array;

	/**
	 * Get sibling occurrences (same event, different dates).
	 *
	 * @since 0.1.0
	 *
	 * @param int $occurrence_id Current occurrence ID.
	 * @return array{all: array<Occurrence>, past: array<Occurrence>, upcoming: array<Occurrence>, current_index: int}
	 */
	public function get_siblings( int $occurrence_id ): array;

	/**
	 * Get occurrences for an event, grouped by past and upcoming.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments (status, limit).
	 * @return array{past: array<Occurrence>, upcoming: array<Occurrence>}
	 */
	public function for_event_grouped( int $event_id, array $args = array() ): array;

	/**
	 * Save an occurrence (insert or update).
	 *
	 * @since 0.9.0
	 *
	 * @param Occurrence $occurrence Occurrence to save.
	 * @return Occurrence The saved occurrence with ID populated.
	 * @throws \RuntimeException If save fails.
	 */
	public function save( Occurrence $occurrence ): Occurrence;

	/**
	 * Delete an occurrence.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Occurrence ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool;

	/**
	 * Get the IDs of ALL occurrences for an event, unbounded.
	 *
	 * Unlike for_event(), this applies no LIMIT and no status filter, so it
	 * is safe to use when every occurrence must be processed (e.g. cascade
	 * deletion of recurring events with more than 100 occurrences).
	 *
	 * @since 1.0.0
	 *
	 * @param int $event_id Event ID.
	 * @return array<int> All occurrence IDs for the event.
	 */
	public function all_ids_for_event( int $event_id ): array;

	/**
	 * Delete all occurrences for an event.
	 *
	 * @since 0.9.0
	 *
	 * @param int $event_id Event ID.
	 * @return int Number of occurrences deleted.
	 */
	public function delete_for_event( int $event_id ): int;

	/**
	 * Delete future occurrences for an event.
	 *
	 * Used when regenerating occurrences for a recurring event.
	 *
	 * @since 0.9.0
	 *
	 * @param int $event_id Event ID.
	 * @return int Number of occurrences deleted.
	 */
	public function delete_future_for_event( int $event_id ): int;

	/**
	 * Update an occurrence's status.
	 *
	 * @since 0.9.0
	 *
	 * @param int    $id     Occurrence ID.
	 * @param string $status New status.
	 * @return bool True on success.
	 */
	public function update_status( int $id, string $status ): bool;

	/**
	 * Count occurrences for an event.
	 *
	 * @since 0.9.0
	 *
	 * @param int    $event_id Event ID.
	 * @param string $status   Optional status filter.
	 * @return int Count of occurrences.
	 */
	public function count_for_event( int $event_id, string $status = '' ): int;

	/**
	 * Save multiple occurrences in a batch.
	 *
	 * @since 0.9.0
	 *
	 * @param array<Occurrence> $occurrences Occurrences to save.
	 * @return int Number of occurrences saved.
	 */
	public function save_batch( array $occurrences ): int;

	/**
	 * Get filtered occurrences with pagination.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $args Query arguments (page, per_page, upcoming, past, category, search).
	 * @return array{items: array<Occurrence>, total: int, total_pages: int}
	 */
	public function get_filtered( array $args = array() ): array;

	/**
	 * Get upcoming occurrences.
	 *
	 * @since 0.9.0
	 *
	 * @param int                  $limit Maximum number to return.
	 * @param array<string, mixed> $args  Additional query arguments.
	 * @return array<Occurrence>
	 */
	public function upcoming( int $limit = 10, array $args = array() ): array;

	/**
	 * Get occurrences in a date range.
	 *
	 * @since 0.9.0
	 *
	 * @param string               $start_date Start date (Y-m-d).
	 * @param string               $end_date   End date (Y-m-d).
	 * @param array<string, mixed> $args       Additional query arguments.
	 * @return array<Occurrence>
	 */
	public function in_range( string $start_date, string $end_date, array $args = array() ): array;

	/**
	 * Find an occurrence with its associated event loaded.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Occurrence ID.
	 * @return Occurrence|null
	 */
	public function find_with_event( int $id ): ?Occurrence;

	/**
	 * Get the next upcoming occurrence for an event.
	 *
	 * @since 0.9.0
	 *
	 * @param int $event_id Event ID.
	 * @return Occurrence|null Next upcoming occurrence, or null if none.
	 */
	public function next_for_event( int $event_id ): ?Occurrence;
}
