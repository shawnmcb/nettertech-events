<?php
/**
 * Waitlist Service Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\WaitlistEntry;

/**
 * Interface for WaitlistService implementations.
 *
 * Business logic for waitlist operations: join, leave, promote,
 * and notification workflows.
 *
 * @since 2.0.0
 * @api
 */
interface WaitlistServiceInterface {

	/**
	 * Join the waitlist for an occurrence.
	 *
	 * @since 1.4.0
	 *
	 * @param int         $occurrence_id  Occurrence ID.
	 * @param string      $email          Email address.
	 * @param string      $name           Name.
	 * @param string|null $phone          Phone number (optional).
	 * @param int|null    $ticket_type_id Ticket type ID (optional).
	 * @return WaitlistEntry The created entry.
	 * @throws ValidationException If already on waitlist.
	 */
	public function join(
		int $occurrence_id,
		string $email,
		string $name,
		?string $phone = null,
		?int $ticket_type_id = null
	): WaitlistEntry;

	/**
	 * Leave the waitlist.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $email         Email address.
	 * @return bool True if removed.
	 */
	public function leave( int $occurrence_id, string $email ): bool;

	/**
	 * Get a user's position in the waitlist.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $email         Email address.
	 * @return int|null Position (1-based) or null if not on waitlist.
	 */
	public function get_position( int $occurrence_id, string $email ): ?int;

	/**
	 * Get the waitlist queue for an occurrence.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $status        Status filter (default: 'waiting').
	 * @return array<WaitlistEntry>
	 */
	public function get_queue( int $occurrence_id, string $status = 'waiting' ): array;

	/**
	 * Get the count of waiting entries for an occurrence.
	 *
	 * @since 1.4.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int
	 */
	public function get_count( int $occurrence_id ): int;

	/**
	 * Promote the next person in the waitlist queue.
	 *
	 * @since 1.4.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return WaitlistEntry|null The promoted entry, or null if queue is empty.
	 */
	public function promote_next( int $occurrence_id ): ?WaitlistEntry;

	/**
	 * Mark an entry as converted (purchased a ticket).
	 *
	 * @since 1.4.0
	 *
	 * @param int $entry_id Entry ID.
	 * @return bool True on success.
	 */
	public function mark_converted( int $entry_id ): bool;

	/**
	 * Check if an email is on the active waitlist for an occurrence.
	 *
	 * @since 1.4.0
	 *
	 * @param string $email         Email address.
	 * @param int    $occurrence_id Occurrence ID.
	 * @return bool
	 */
	public function is_on_waitlist( string $email, int $occurrence_id ): bool;
}
