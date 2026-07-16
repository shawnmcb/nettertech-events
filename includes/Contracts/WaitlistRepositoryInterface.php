<?php
/**
 * Waitlist Repository Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\WaitlistEntry;

/**
 * Interface for waitlist repository operations.
 *
 * @since 1.4.0
 * @api
 */
interface WaitlistRepositoryInterface {

	/**
	 * Find a waitlist entry by ID.
	 *
	 * @since 1.4.0
	 *
	 * @param int $id Entry ID.
	 * @return WaitlistEntry|null
	 */
	public function find( int $id ): ?WaitlistEntry;

	/**
	 * Get waitlist entries for an occurrence.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $status        Optional status filter (default: 'waiting').
	 * @return array<WaitlistEntry>
	 */
	public function for_occurrence( int $occurrence_id, string $status = '' ): array;

	/**
	 * Count waitlist entries for an occurrence.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $status        Optional status filter.
	 * @return int
	 */
	public function count_for_occurrence( int $occurrence_id, string $status = 'waiting' ): int;

	/**
	 * Find a waitlist entry by email and occurrence.
	 *
	 * @since 1.4.0
	 *
	 * @param string $email         Email address.
	 * @param int    $occurrence_id Occurrence ID.
	 * @return WaitlistEntry|null
	 */
	public function find_by_email( string $email, int $occurrence_id ): ?WaitlistEntry;

	/**
	 * Save a waitlist entry (insert or update).
	 *
	 * @since 1.4.0
	 *
	 * @param WaitlistEntry $entry Entry to save.
	 * @return WaitlistEntry Saved entry with ID populated.
	 * @throws \RuntimeException If validation or save fails.
	 */
	public function save( WaitlistEntry $entry ): WaitlistEntry;

	/**
	 * Delete a waitlist entry.
	 *
	 * @since 1.4.0
	 *
	 * @param int $id Entry ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool;

	/**
	 * Get the next position number for an occurrence.
	 *
	 * @since 1.4.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Next position number.
	 */
	public function get_next_position( int $occurrence_id ): int;

	/**
	 * Get the next waiting entry in the queue for an occurrence.
	 *
	 * @since 1.4.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return WaitlistEntry|null Next entry or null if queue empty.
	 */
	public function get_next_in_queue( int $occurrence_id ): ?WaitlistEntry;

	/**
	 * Delete all entries for an occurrence.
	 *
	 * @since 1.4.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Number of entries deleted.
	 */
	public function delete_for_occurrence( int $occurrence_id ): int;

	/**
	 * Update entry status.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $id     Entry ID.
	 * @param string $status New status.
	 * @return bool True on success.
	 */
	public function update_status( int $id, string $status ): bool;
}
