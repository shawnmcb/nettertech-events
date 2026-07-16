<?php
/**
 * Ticket Type Stock Repository interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\TicketType;

/**
 * Interface for ticket type stock/inventory management implementations.
 *
 * @since 1.0.0
 */
interface TicketTypeStockRepositoryInterface {

	/**
	 * Get sold count for a ticket type.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Number of tickets sold.
	 */
	public function get_sold_count( int $ticket_type_id ): int;

	/**
	 * Get available count for a ticket type.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int|null Available count (null = unlimited).
	 */
	public function get_available_count( int $ticket_type_id ): ?int;

	/**
	 * Check if a ticket type has availability.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity to check.
	 * @return bool True if available.
	 */
	public function has_availability( int $ticket_type_id, int $quantity = 1 ): bool;

	/**
	 * Check if a ticket type is sold out.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return bool True if sold out.
	 */
	public function is_sold_out( int $ticket_type_id ): bool;

	/**
	 * Increment sold count and update stock status.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity sold.
	 * @return bool True on success.
	 */
	public function increment_sold_count( int $ticket_type_id, int $quantity = 1 ): bool;

	/**
	 * Decrement sold count and update stock status.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity to release.
	 * @return bool True on success.
	 */
	public function decrement_sold_count( int $ticket_type_id, int $quantity = 1 ): bool;

	/**
	 * Recalculate sold_count from attendee data.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return bool True on success.
	 */
	public function recalculate_sold_count( int $ticket_type_id ): bool;

	/**
	 * Get sum of fixed capacity allocations for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Total fixed capacity allocated (0 if none).
	 */
	public function get_fixed_capacity_sum( int $occurrence_id ): int;

	/**
	 * Get sum of sold_count for shared ticket types on an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Total sold count across shared ticket types (0 if none).
	 */
	public function get_shared_sold_count( int $occurrence_id ): int;

	/**
	 * Get ticket types with shared capacity for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<TicketType>
	 */
	public function get_shared_for_occurrence( int $occurrence_id ): array;

	/**
	 * Check if an occurrence has any ticket types with unlimited fixed capacity.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool True if any unlimited fixed ticket types exist.
	 */
	public function has_unlimited_fixed_tickets( int $occurrence_id ): bool;

	/**
	 * Check if an occurrence has any ticket types with explicit unlimited capacity.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool True if any unlimited capacity ticket types exist.
	 */
	public function has_unlimited_tickets( int $occurrence_id ): bool;
}
