<?php
/**
 * House capacity repository contract.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the shared-capacity ("house") context surrounding a ticket type.
 *
 * @since 1.1.2
 */
interface HouseCapacityRepositoryInterface {

	/**
	 * Load the house context for a ticket type.
	 *
	 * `house` is null when the room is unbounded. `house_sold` and `house_reserved`
	 * aggregate every tier sharing the room, including the tier asked about.
	 *
	 * @since 1.1.2
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return array{
	 *     house: ?int,
	 *     house_sold: int,
	 *     house_reserved: int,
	 *     own_capacity: ?int,
	 *     own_capacity_type: string,
	 *     own_sold: int,
	 *     peer_ids: array<int, int>
	 * }|null Null when the ticket type does not exist.
	 */
	public function context_for_ticket_type( int $ticket_type_id ): ?array;

	/**
	 * List the ticket type IDs sharing a house with the given one, including itself.
	 *
	 * @since 1.1.2
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return array<int, int> Ticket type IDs.
	 */
	public function house_peer_ids( int $ticket_type_id ): array;

	/**
	 * Resolve one date's room: size, seats sold, seats reserved.
	 *
	 * Pass tiers (event-scoped) count among the sold/reserved seats — a pass
	 * occupies a seat on every date it spans (NTE-156) — but never size the room.
	 *
	 * @since 1.1.3
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array{house: ?int, house_sold: int, house_reserved: int}|null Null when the occurrence does not exist.
	 */
	public function house_context_for_occurrence( int $occurrence_id ): ?array;
}
