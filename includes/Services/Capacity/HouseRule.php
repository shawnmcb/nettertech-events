<?php
/**
 * The house rule: how big is the room?
 *
 * @package NetterTechEvents\Services\Capacity
 */

declare(strict_types=1);

namespace NetterTechEvents\Services\Capacity;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Enums\CapacityType;

/**
 * Resolves the shared capacity ("house") of a set of ticket tiers.
 *
 * Ticket types sold against the same date share one room. A three-tier concert —
 * standing, seated, youth — that lists 250 on each tier is describing one 250-seat
 * hall three times over, not 750 chairs. Summing the tiers is therefore always
 * wrong, and the failure is expensive: it sells seats that do not exist.
 *
 * Every screen and every gate in the plugin must agree on this number, which is
 * why the arithmetic lives here and nowhere else. Availability, the Events list's
 * "% of capacity" column, the cart, and the final atomic commit all resolve the
 * house through this class.
 *
 * @since 1.1.2
 */
final class HouseRule {

	/**
	 * Resolve the house capacity for one set of tiers sharing a room.
	 *
	 * A ceiling — the occurrence's own capacity — is the operator stating the
	 * room's size directly, so it wins outright. Under a ceiling, a tier marked
	 * unlimited means "no limit of its own", not "no limit at all": the room
	 * still runs out. Without a ceiling the largest tier stands in for the room,
	 * and a genuinely unlimited tier leaves it unbounded.
	 *
	 * @since 1.1.2
	 *
	 * @param array<int, array{capacity: ?int, capacity_type: string}> $tiers   Tiers sharing the house.
	 * @param int|null                                                 $ceiling Occurrence capacity, or null if unset.
	 * @return int|null House capacity, or null when the house is unbounded.
	 */
	public static function house( array $tiers, ?int $ceiling ): ?int {
		if ( null !== $ceiling ) {
			return max( 0, $ceiling );
		}

		$largest = 0;

		foreach ( $tiers as $tier ) {
			$capacity = $tier['capacity'];

			if ( null === $capacity || CapacityType::UNLIMITED->value === $tier['capacity_type'] ) {
				return null;
			}

			$largest = max( $largest, (int) $capacity );
		}

		return $largest;
	}

	/**
	 * Bound a tier's own remaining count by what the house has left.
	 *
	 * Either bound may be absent. A tier with no limit of its own is held only by
	 * the room; a tier in an unbounded room is held only by its own limit.
	 *
	 * @since 1.1.2
	 *
	 * @param int|null $own_remaining   The tier's own remaining count, or null if it has no limit of its own.
	 * @param int|null $house_remaining What the house has left, or null if the house is unbounded.
	 * @return int|null The lesser bound, or null when neither binds.
	 */
	public static function bound( ?int $own_remaining, ?int $house_remaining ): ?int {
		if ( null === $own_remaining ) {
			return $house_remaining;
		}

		if ( null === $house_remaining ) {
			return $own_remaining;
		}

		return min( $own_remaining, $house_remaining );
	}

	/**
	 * Resolve a tier's own remaining count from its capacity column.
	 *
	 * Only a fixed tier's `capacity` column means anything. Shared, seated, and
	 * unlimited tiers may carry a stale value there — the ticket-type form hides
	 * the input rather than clearing it — so reading it without first checking
	 * `capacity_type` resurrects whatever number was last typed into the box.
	 *
	 * @since 1.1.2
	 *
	 * @param string   $capacity_type The tier's capacity type.
	 * @param int|null $capacity      The tier's capacity column.
	 * @param int      $sold          Seats already sold on this tier.
	 * @return int|null Remaining on this tier, or null when the tier has no limit of its own.
	 */
	public static function own_remaining( string $capacity_type, ?int $capacity, int $sold ): ?int {
		if ( CapacityType::FIXED !== ( CapacityType::tryFrom( $capacity_type ) ?? CapacityType::FIXED ) ) {
			return null;
		}

		if ( null === $capacity ) {
			return null;
		}

		return max( 0, $capacity - $sold );
	}
}
