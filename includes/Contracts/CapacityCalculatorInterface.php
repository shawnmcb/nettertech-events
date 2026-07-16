<?php
/**
 * Capacity Calculator Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for capacity calculation services.
 *
 * Defines methods for calculating ticket type and occurrence capacity,
 * including buffer stock, shared capacity, and series pass support.
 *
 * @since 0.9.4
 */
interface CapacityCalculatorInterface {

	/**
	 * Get available capacity count.
	 *
	 * Returns null for unlimited capacity.
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
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int|null Total capacity, or null if unlimited.
	 */
	public function get_total_capacity( int $ticket_type_id ): ?int;

	/**
	 * Get sold count for a ticket type.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Sold count.
	 */
	public function get_sold_count( int $ticket_type_id ): int;

	/**
	 * Get buffer stock for a ticket type.
	 *
	 * Buffer stock is held back from sale for venue use (comps, emergencies).
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Buffer stock amount.
	 */
	public function get_buffer_stock( int $ticket_type_id ): int;

	/**
	 * Set buffer stock for a ticket type.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $amount         Buffer stock amount.
	 * @return bool True on success.
	 */
	public function set_buffer_stock( int $ticket_type_id, int $amount ): bool;

	/**
	 * Get effective capacity for a series pass.
	 *
	 * For EVENT-scoped ticket types, calculates capacity distribution
	 * across all occurrences.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return array{total: ?int, per_occurrence: ?int, occurrence_count: int}
	 */
	public function get_series_pass_capacity( int $ticket_type_id ): array;

	/**
	 * Get capacity summary for a ticket type.
	 *
	 * Uses caching to reduce database queries. Cache is invalidated
	 * when capacity changes (reserve, release, buffer update).
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
	 * Uses caching with locking to prevent cache stampede on expensive
	 * aggregation queries.
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
