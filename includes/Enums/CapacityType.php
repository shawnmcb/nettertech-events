<?php
/**
 * Capacity type enum.
 *
 * @package NetterTechEvents\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Enums;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;

/**
 * Represents how a ticket type's capacity is calculated.
 *
 * - FIXED: Uses the capacity column value (NULL = unlimited for backward compatibility).
 * - UNLIMITED: Explicit unlimited capacity, capacity column is ignored.
 * - SHARED: Draws from the occurrence's remaining capacity after fixed allocations.
 *
 * @api
 */
enum CapacityType: string {

	case FIXED     = 'fixed';
	case UNLIMITED = 'unlimited';
	case SHARED    = 'shared';
	case SEATED    = 'seated';

	/**
	 * Get the human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::FIXED     => __( 'Fixed', 'nettertech-events' ),
			self::UNLIMITED => __( 'Unlimited', 'nettertech-events' ),
			self::SHARED    => __( 'Shared', 'nettertech-events' ),
			self::SEATED    => __( 'Seated', 'nettertech-events' ),
		};
	}

	/**
	 * Get a description of what this capacity type means.
	 *
	 * @return string
	 */
	public function description(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::FIXED     => __( 'Fixed number of tickets available.', 'nettertech-events' ),
			self::UNLIMITED => __( 'No limit on ticket sales.', 'nettertech-events' ),
			self::SHARED    => __( 'Uses remaining occurrence capacity.', 'nettertech-events' ),
			self::SEATED    => __( 'Individual seat selection via seating map. Capacity equals total active seats.', 'nettertech-events' ),
		};
	}

	/**
	 * Check if this capacity type uses the capacity column value.
	 *
	 * @return bool
	 */
	public function uses_capacity_column(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return self::FIXED === $this;
	}

	/**
	 * Check if this capacity type has unlimited availability.
	 *
	 * @return bool
	 */
	public function is_unlimited(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return self::UNLIMITED === $this;
	}

	/**
	 * Check if this capacity type draws from shared occurrence capacity.
	 *
	 * @return bool
	 */
	public function is_shared(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return self::SHARED === $this;
	}

	/**
	 * Check if this capacity type requires occurrence scope.
	 *
	 * SHARED and SEATED capacity can only be used with OCCURRENCE scope tickets.
	 *
	 * @return bool
	 */
	public function requires_occurrence_scope(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return self::SHARED === $this || self::SEATED === $this;
	}

	/**
	 * Get all capacity type values as an array.
	 *
	 * @return array<string>
	 */
	public static function values(): array {
		return array_column( self::cases(), 'value' );
	}

	/**
	 * Get capacity types allowed for a given ticket scope.
	 *
	 * SHARED and SEATED are only allowed for OCCURRENCE scope.
	 * The result is filterable so add-on plugins can control which
	 * types appear in the admin UI.
	 *
	 * @param TicketTypeScope $scope The ticket type scope.
	 * @return array<CapacityType>
	 */
	public static function for_scope( TicketTypeScope $scope ): array {
		if ( TicketTypeScope::OCCURRENCE === $scope ) {
			$types = array( self::FIXED, self::UNLIMITED, self::SHARED, self::SEATED );
		} else {
			$types = array( self::FIXED, self::UNLIMITED );
		}

		/**
		 * Filters the capacity types available for a given ticket scope.
		 *
		 * Allows add-on plugins to control which capacity types appear
		 * in the admin ticket type editor dropdown.
		 *
		 * @since 1.0.2
		 *
		 * @param array<CapacityType> $types The available capacity types.
		 * @param TicketTypeScope     $scope The ticket type scope.
		 */
		return apply_filters( 'nettertech_events_capacity_types', $types, $scope );
	}
}
