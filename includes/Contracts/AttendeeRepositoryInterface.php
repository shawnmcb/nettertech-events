<?php
/**
 * Attendee Repository Interface.
 *
 * Defines core CRUD operations for attendees.
 * Part of the segregated interface design (ISP compliance).
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Attendee;

/**
 * Interface for core attendee repository operations.
 *
 * This interface defines the fundamental CRUD operations for attendees.
 * For specialized operations, see the segregated interfaces:
 *
 * - {@see AttendeeCheckInInterface} - Check-in operations
 * - {@see AttendeeOrderInterface} - WooCommerce order operations
 * - {@see AttendeeSearchInterface} - Search and lookup operations
 *
 * This design follows the Interface Segregation Principle (ISP):
 * consumers depend only on the interfaces they actually use.
 *
 * @since 0.9.0
 * @since 0.9.3 Refactored to segregated interface design.
 * @api
 */
interface AttendeeRepositoryInterface {

	/**
	 * Find an attendee by ID.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Attendee ID.
	 * @return Attendee|null
	 */
	public function find( int $id ): ?Attendee;

	/**
	 * Get attendees for an occurrence.
	 *
	 * @since 0.9.0
	 *
	 * @param int                  $occurrence_id Occurrence ID.
	 * @param array<string, mixed> $args          Query arguments.
	 * @return array<Attendee>
	 */
	public function for_occurrence( int $occurrence_id, array $args = array() ): array;

	/**
	 * Count attendees for an occurrence.
	 *
	 * @since 0.9.0
	 *
	 * @param int         $occurrence_id Occurrence ID.
	 * @param string|null $status        Optional status filter.
	 * @return int
	 */
	public function count_for_occurrence( int $occurrence_id, ?string $status = 'confirmed' ): int;

	/**
	 * Sum confirmed guest quantities per event across all its occurrences.
	 *
	 * @since 1.1.1
	 *
	 * @param array<int> $event_ids Event IDs to aggregate.
	 * @return array<int, int> Map of event_id => confirmed guest count (events with none omitted).
	 */
	public function confirmed_guest_counts_for_events( array $event_ids ): array;

	/**
	 * Save an attendee (insert or update).
	 *
	 * @since 0.9.0
	 *
	 * @param Attendee $attendee Attendee to save.
	 * @return Attendee The saved attendee with ID populated.
	 * @throws \RuntimeException If validation fails or save fails.
	 */
	public function save( Attendee $attendee ): Attendee;

	/**
	 * Delete an attendee.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Attendee ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool;

	/**
	 * Update attendee status.
	 *
	 * @since 0.9.0
	 *
	 * @param int    $id     Attendee ID.
	 * @param string $status New status.
	 * @return bool True on success.
	 */
	public function update_status( int $id, string $status ): bool;

	/**
	 * Delete attendees for an occurrence.
	 *
	 * @since 0.9.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Number of attendees deleted.
	 */
	public function delete_for_occurrence( int $occurrence_id ): int;

	/**
	 * Invalidate cached data for a specific attendee.
	 *
	 * Clears both the per-request identity map and the persistent object cache.
	 * Must be called after external writes (e.g. from AttendeeCheckInRepository)
	 * before reloading the attendee to avoid stale reads.
	 *
	 * @since 1.6.0
	 *
	 * @param int $id Attendee ID.
	 * @return void
	 */
	public function invalidate_attendee_cache( int $id ): void;
}
