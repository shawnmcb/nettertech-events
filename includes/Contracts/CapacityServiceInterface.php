<?php
/**
 * Capacity Service Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for CapacityService implementations.
 *
 * @since 0.9.0
 * @api
 */
interface CapacityServiceInterface {

	/**
	 * Reserve capacity for a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity to reserve.
	 * @return bool True on success.
	 */
	public function reserve_capacity( int $ticket_type_id, int $quantity ): bool;

	/**
	 * Release capacity for a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity to release.
	 * @return bool True on success.
	 */
	public function release_capacity( int $ticket_type_id, int $quantity ): bool;

	/**
	 * Check if capacity is available.
	 *
	 * @since 0.9.0
	 *
	 * @param int  $ticket_type_id  Ticket type ID.
	 * @param int  $quantity        Quantity to check.
	 * @param bool $include_pending Whether to count pending reservations.
	 * @return bool True if available.
	 */
	public function has_availability( int $ticket_type_id, int $quantity = 1, bool $include_pending = true ): bool;

	/**
	 * Get available capacity count.
	 *
	 * @since 0.9.0
	 *
	 * @param int  $ticket_type_id  Ticket type ID.
	 * @param bool $include_pending Whether to subtract pending reservations.
	 * @param bool $skip_cache      Whether to bypass cache (use for checkout validation).
	 * @return int|null Available count, or null if unlimited.
	 */
	public function get_available_count( int $ticket_type_id, bool $include_pending = true, bool $skip_cache = false ): ?int;

	/**
	 * Get total capacity for a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int|null Total capacity, or null if unlimited.
	 */
	public function get_total_capacity( int $ticket_type_id ): ?int;

	/**
	 * Get sold count for a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Sold count.
	 */
	public function get_sold_count( int $ticket_type_id ): int;

	/**
	 * Get buffer stock for a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Buffer stock amount.
	 */
	public function get_buffer_stock( int $ticket_type_id ): int;

	/**
	 * Set buffer stock for a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $amount         Buffer stock amount.
	 * @return bool True on success.
	 */
	public function set_buffer_stock( int $ticket_type_id, int $amount ): bool;

	/**
	 * Get the configured hold time for pending reservations.
	 *
	 * @since 0.9.0
	 *
	 * @return int Hold time in seconds.
	 */
	public function get_hold_time(): int;

	/**
	 * Create a pending reservation.
	 *
	 * @since 0.9.0
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param int    $quantity       Quantity to hold.
	 * @param string $session_key    Session or cart key.
	 * @param int    $hold_time      Hold time in seconds (0 = use settings).
	 * @return bool True on success.
	 */
	public function create_pending_reservation(
		int $ticket_type_id,
		int $quantity,
		string $session_key,
		int $hold_time = 0
	): bool;

	/**
	 * Clear a pending reservation.
	 *
	 * @since 0.9.0
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $session_key    Optional session key.
	 * @return bool True on success.
	 */
	public function clear_pending_reservation( int $ticket_type_id, string $session_key = '' ): bool;

	/**
	 * Get pending reservation for a session.
	 *
	 * @since 0.9.0
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $session_key    Session key.
	 * @return int Quantity held.
	 */
	public function get_pending_reservation( int $ticket_type_id, string $session_key ): int;

	/**
	 * Get total pending count for a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Total pending quantity.
	 */
	public function get_pending_count( int $ticket_type_id ): int;

	/**
	 * Get effective capacity for a series pass.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return array{total: ?int, per_occurrence: ?int, occurrence_count: int}
	 */
	public function get_series_pass_capacity( int $ticket_type_id ): array;

	/**
	 * Check if series pass capacity allows attendance at an occurrence.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $occurrence_id  Occurrence ID.
	 * @param int $quantity       Quantity to check.
	 * @return bool True if available.
	 */
	public function series_pass_has_occurrence_availability(
		int $ticket_type_id,
		int $occurrence_id,
		int $quantity = 1
	): bool;

	/**
	 * Get capacity summary for a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int  $ticket_type_id Ticket type ID.
	 * @param bool $skip_cache     Whether to bypass cache.
	 * @return array{
	 *     capacity: ?int,
	 *     sold: int,
	 *     available: ?int,
	 *     buffer: int,
	 *     pending: int,
	 *     effective_available: ?int,
	 *     is_unlimited: bool,
	 *     is_sold_out: bool,
	 *     is_low_stock: bool
	 * }
	 */
	public function get_capacity_summary( int $ticket_type_id, bool $skip_cache = false ): array;

	/**
	 * Get total capacity for an occurrence across all ticket types.
	 *
	 * @since 0.9.0
	 *
	 * @param int  $occurrence_id Occurrence ID.
	 * @param bool $skip_cache    Whether to bypass cache.
	 * @return array{
	 *     total_capacity: ?int,
	 *     total_sold: int,
	 *     total_available: ?int,
	 *     has_unlimited: bool,
	 *     ticket_types: array<int, mixed>
	 * }
	 */
	public function get_occurrence_capacity( int $occurrence_id, bool $skip_cache = false ): array;
}
