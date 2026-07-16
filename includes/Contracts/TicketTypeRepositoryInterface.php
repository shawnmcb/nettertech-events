<?php
/**
 * TicketType Repository Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\TicketType;

/**
 * Interface for TicketType repository implementations.
 *
 * @since 0.9.0
 * @api
 */
interface TicketTypeRepositoryInterface {

	/**
	 * Find a ticket type by ID.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Ticket type ID.
	 * @return TicketType|null
	 */
	public function find( int $id ): ?TicketType;

	/**
	 * Get ticket types for an occurrence.
	 *
	 * @since 0.9.0
	 *
	 * @param int                  $occurrence_id Occurrence ID.
	 * @param array<string, mixed> $args          Query arguments.
	 * @return array<TicketType>
	 */
	public function for_occurrence( int $occurrence_id, array $args = array() ): array;

	/**
	 * Get ticket types for multiple occurrences in a single query.
	 *
	 * @since 0.9.0
	 *
	 * @param array<int> $occurrence_ids Array of occurrence IDs.
	 * @param string     $status         Optional status filter.
	 * @return array<int, array<TicketType>> Ticket types grouped by occurrence_id.
	 */
	public function for_multiple_occurrences( array $occurrence_ids, ?string $status = null ): array;

	/**
	 * Get active ticket types for an occurrence.
	 *
	 * @since 0.9.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<TicketType>
	 */
	public function get_active_for_occurrence( int $occurrence_id ): array;

	/**
	 * Get ticket types currently on sale for an occurrence.
	 *
	 * @since 0.9.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<TicketType>
	 */
	public function get_on_sale_for_occurrence( int $occurrence_id ): array;

	/**
	 * Get ticket types currently on sale for multiple occurrences in a single
	 * batched query.
	 *
	 * N+1 prevention for grid/list renderers that need ticket pricing per
	 * occurrence. Returns a map keyed by occurrence_id; occurrences with no
	 * on-sale ticket types are absent from the returned array.
	 *
	 * @since 1.0.3
	 *
	 * @param array<int> $occurrence_ids Occurrence IDs.
	 * @return array<int, array<TicketType>> Map of occurrence_id => list of ticket types.
	 */
	public function get_on_sale_for_occurrences( array $occurrence_ids ): array;

	/**
	 * Find by WooCommerce product ID.
	 *
	 * @since 0.9.0
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return TicketType|null
	 */
	public function find_by_product( int $product_id ): ?TicketType;

	/**
	 * Find by WooCommerce variation ID.
	 *
	 * @since 0.9.0
	 *
	 * @param int $variation_id WooCommerce variation ID.
	 * @return TicketType|null
	 */
	public function find_by_variation( int $variation_id ): ?TicketType;

	/**
	 * Save a ticket type (insert or update).
	 *
	 * @since 0.9.0
	 *
	 * @param TicketType $ticket_type Ticket type to save.
	 * @return TicketType The saved ticket type with ID populated.
	 * @throws \RuntimeException If validation fails or save fails.
	 */
	public function save( TicketType $ticket_type ): TicketType;

	/**
	 * Delete a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Ticket type ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool;

	/**
	 * Delete all ticket types for an occurrence.
	 *
	 * @since 0.9.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Number of ticket types deleted.
	 */
	public function delete_for_occurrence( int $occurrence_id ): int;

	/**
	 * Get sold count for a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Number of tickets sold.
	 */
	public function get_sold_count( int $ticket_type_id ): int;

	/**
	 * Get available count for a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int|null Available count (null = unlimited).
	 */
	public function get_available_count( int $ticket_type_id ): ?int;

	/**
	 * Check if a ticket type has availability.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity to check.
	 * @return bool True if available.
	 */
	public function has_availability( int $ticket_type_id, int $quantity = 1 ): bool;

	/**
	 * Check if a ticket type is sold out.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return bool True if sold out.
	 */
	public function is_sold_out( int $ticket_type_id ): bool;

	/**
	 * Increment sold count and update stock status.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity sold.
	 * @return bool True on success.
	 */
	public function increment_sold_count( int $ticket_type_id, int $quantity = 1 ): bool;

	/**
	 * Decrement sold count and update stock status.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity to release.
	 * @return bool True on success.
	 */
	public function decrement_sold_count( int $ticket_type_id, int $quantity = 1 ): bool;

	/**
	 * Recalculate sold_count from attendee data.
	 *
	 * Reconciles sold_count by summing confirmed attendee quantities for the
	 * ticket type. Use when relative adjustments (increment/decrement) may be
	 * unreliable due to competing hook ordering.
	 *
	 * @since 1.0.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return bool True on success.
	 */
	public function recalculate_sold_count( int $ticket_type_id ): bool;

	/**
	 * Get ticket types for an event (EVENT and TEMPLATE scopes).
	 *
	 * @since 0.9.0
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array<TicketType>
	 */
	public function for_event( int $event_id, array $args = array() ): array;

	/**
	 * Compute the shared-pool-aware total capacity per event for a batch of events.
	 *
	 * @since 1.0.3
	 *
	 * @param array<int> $event_ids Event IDs to aggregate.
	 * @return array<int, array{capacity: ?int, has_unlimited: bool, configured: bool}>
	 */
	public function event_capacity_for_events( array $event_ids ): array;

	/**
	 * Get ticket templates for an event.
	 *
	 * @since 0.9.0
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array<TicketType>
	 */
	public function get_templates( int $event_id, array $args = array() ): array;

	/**
	 * Create an occurrence ticket from a template.
	 *
	 * @since 0.9.0
	 *
	 * @param TicketType $template      Template ticket type.
	 * @param int        $occurrence_id Occurrence ID to create for.
	 * @return TicketType New ticket type instance (unsaved).
	 * @throws \InvalidArgumentException If template is not a template scope.
	 */
	public function create_from_template( TicketType $template, int $occurrence_id ): TicketType;

	/**
	 * Get sum of fixed capacity allocations for an occurrence.
	 *
	 * @since 0.9.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Total fixed capacity allocated (0 if none).
	 */
	public function get_fixed_capacity_sum( int $occurrence_id ): int;

	/**
	 * Get sum of sold_count for shared ticket types on an occurrence.
	 *
	 * @since 0.9.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Total sold count across shared ticket types (0 if none).
	 */
	public function get_shared_sold_count( int $occurrence_id ): int;

	/**
	 * Get ticket types with shared capacity for an occurrence.
	 *
	 * @since 0.9.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<TicketType>
	 */
	public function get_shared_for_occurrence( int $occurrence_id ): array;

	/**
	 * Check if an occurrence has any ticket types with unlimited fixed capacity.
	 *
	 * @since 0.9.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool True if any unlimited fixed ticket types exist.
	 */
	public function has_unlimited_fixed_tickets( int $occurrence_id ): bool;

	/**
	 * Get the capacity type for a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return string|null Capacity type string, or null if not found.
	 */
	public function get_capacity_type( int $ticket_type_id ): ?string;
	/**
	 * Check if occurrence has only free ticket types.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool
	 */
	public function occurrence_is_free( int $occurrence_id ): bool;
}
