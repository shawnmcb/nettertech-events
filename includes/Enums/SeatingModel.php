<?php
/**
 * Seating model enum.
 *
 * @package NetterTechEvents\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * How seating is allocated within a space.
 *
 * - FREE: General admission, no assignments.
 * - ASSIGNED: Every seat is reserved.
 * - MIXED: Some assigned blocks, some free.
 *
 * @since 3.10.0
 * @api
 */
enum SeatingModel: string {

	case FREE     = 'free';
	case ASSIGNED = 'assigned';
	case MIXED    = 'mixed';

	/**
	 * Get the human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::FREE     => __( 'Free / general admission', 'nettertech-events' ),
			self::ASSIGNED => __( 'Assigned seating', 'nettertech-events' ),
			self::MIXED    => __( 'Mixed (some assigned, some free)', 'nettertech-events' ),
		};
	}

	/**
	 * Get all enum values as a plain string array.
	 *
	 * @return array<string>
	 */
	public static function values(): array {
		return array_column( self::cases(), 'value' );
	}
}
