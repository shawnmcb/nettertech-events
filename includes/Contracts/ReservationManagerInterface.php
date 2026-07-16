<?php
/**
 * Reservation Manager Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for pending ticket reservation management.
 *
 * Implementations hold capacity while customers are in checkout.
 * The atomic implementation uses a database column (ticket_types.reserved)
 * with a per-session tracking table for release and expiry.
 *
 * @since 3.4.0
 */
interface ReservationManagerInterface {

	/**
	 * Get the configured hold time for pending reservations.
	 *
	 * @return int Hold time in seconds.
	 */
	public function get_hold_time(): int;

	/**
	 * Create a pending reservation.
	 *
	 * Atomically reserves capacity for the given quantity. Returns false if
	 * insufficient capacity is available (the availability check is part of
	 * the reservation, not a separate step).
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param int    $quantity       Quantity to hold.
	 * @param string $session_key    Session or cart key for identification.
	 * @param int    $hold_time      Hold time in seconds (0 = use settings).
	 * @return bool True if reserved successfully, false if capacity insufficient.
	 */
	public function create_pending(
		int $ticket_type_id,
		int $quantity,
		string $session_key,
		int $hold_time = 0
	): bool;

	/**
	 * Clear a pending reservation.
	 *
	 * Releases the held capacity. If session_key is empty, clears all
	 * reservations for the ticket type.
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $session_key    Optional session key. Clears all if empty.
	 * @return bool True on success.
	 */
	public function clear_pending( int $ticket_type_id, string $session_key = '' ): bool;

	/**
	 * Get pending reservation quantity for a specific session.
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $session_key    Session key.
	 * @return int Quantity held by this session.
	 */
	public function get_pending( int $ticket_type_id, string $session_key ): int;

	/**
	 * Get total pending count across all sessions for a ticket type.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Total pending quantity.
	 */
	public function get_pending_count( int $ticket_type_id ): int;

	/**
	 * Remove expired reservations and release their capacity.
	 *
	 * @return int Number of expired reservations cleaned up.
	 */
	public function sweep_expired(): int;
}
