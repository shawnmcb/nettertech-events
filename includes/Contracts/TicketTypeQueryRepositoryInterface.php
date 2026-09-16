<?php
/**
 * Ticket Type Query Repository interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\TicketType;

/**
 * Interface for read-only ticket type query implementations.
 *
 * @since 1.0.0
 */
interface TicketTypeQueryRepositoryInterface {

	/**
	 * Get ticket types for an occurrence.
	 *
	 * @param int                  $occurrence_id Occurrence ID.
	 * @param array<string, mixed> $args          Query arguments.
	 * @return array<TicketType>
	 */
	public function for_occurrence( int $occurrence_id, array $args = array() ): array;

	/**
	 * Get ticket types for multiple occurrences (N+1 prevention).
	 *
	 * @param array<int> $occurrence_ids Occurrence IDs.
	 * @param string     $status         Optional status filter.
	 * @return array<int, array<TicketType>> Grouped by occurrence_id.
	 */
	public function for_multiple_occurrences( array $occurrence_ids, ?string $status = null ): array;

	/**
	 * Get active ticket types for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<TicketType>
	 */
	public function get_active_for_occurrence( int $occurrence_id ): array;

	/**
	 * Get ticket types currently on sale for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<TicketType>
	 */
	public function get_on_sale_for_occurrence( int $occurrence_id ): array;

	/**
	 * Get ticket types for an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array<TicketType>
	 */
	public function for_event( int $event_id, array $args = array() ): array;

	/**
	 * Get ticket templates for an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array<TicketType>
	 */
	public function get_templates( int $event_id, array $args = array() ): array;

	/**
	 * Compute the shared-pool-aware total capacity per event for a batch of events.
	 *
	 * The denominator for the "percentage of capacity sold" column on the All
	 * Events list. Ticket tiers share one house pool, so tiers collapse to the
	 * largest tier capacity (or the occurrence ceiling when set) rather than
	 * summing; multiple occurrences (dates) are summed, since each date is its
	 * own house. Any unlimited contribution makes the event capacity unlimited
	 * (null).
	 *
	 * @since 1.0.3
	 *
	 * @param array<int> $event_ids Event IDs to aggregate.
	 * @return array<int, array{capacity: ?int, has_unlimited: bool, configured: bool}>
	 */
	public function event_capacity_for_events( array $event_ids ): array;

	/**
	 * Compute the house capacity of each occurrence in a batch.
	 *
	 * Date-grain sibling of event_capacity_for_events(): tiers on one date share
	 * one house, event-scoped tiers (series passes) reach every date of their
	 * event and combine by max() rather than sum, and the occurrence's own
	 * capacity bounds the result when set.
	 *
	 * @since 1.4.8
	 *
	 * @param array<int> $occurrence_ids Occurrence IDs to aggregate.
	 * @return array<int, array{capacity: ?int, has_unlimited: bool, configured: bool}>
	 */
	public function occurrence_capacity_for_occurrences( array $occurrence_ids ): array;

	/**
	 * Find by WooCommerce product ID.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return TicketType|null
	 */
	public function find_by_product( int $product_id ): ?TicketType;

	/**
	 * Find by WooCommerce variation ID.
	 *
	 * @param int $variation_id WooCommerce variation ID.
	 * @return TicketType|null
	 */
	public function find_by_variation( int $variation_id ): ?TicketType;

	/**
	 * Check if occurrence has any free ticket types.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool
	 */
	public function occurrence_has_free_tickets( int $occurrence_id ): bool;

	/**
	 * Check if occurrence has only free ticket types.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool
	 */
	public function occurrence_is_free( int $occurrence_id ): bool;
	/**
	 * Get on-sale ticket types for multiple occurrences in one query.
	 *
	 * @since 1.0.3
	 *
	 * @param array<int> $occurrence_ids Occurrence IDs.
	 * @return array<int, array<\NetterTechEvents\Models\TicketType>> Map of occurrence_id => list of on-sale ticket types.
	 */
	public function get_on_sale_for_occurrences( array $occurrence_ids ): array;

	/**
	 * Get event-scope (series pass) on-sale ticket types for an event.
	 *
	 * @since 1.1.3
	 *
	 * @param int                $event_id Event ID.
	 * @param \DateTimeZone|null $zone     Zone the sale window is read in (default: site zone).
	 * @return array<\NetterTechEvents\Models\TicketType>
	 */
	public function get_on_sale_for_event( int $event_id, ?\DateTimeZone $zone = null ): array;
}
