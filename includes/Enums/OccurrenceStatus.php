<?php
/**
 * Occurrence status enum.
 *
 * @package NetterTechEvents\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Represents the status of an event occurrence.
 *
 * @since 0.8.0
 * @api
 */
enum OccurrenceStatus: string {

	case SCHEDULED = 'scheduled';
	case CANCELLED = 'cancelled';
	case POSTPONED = 'postponed';
	case COMPLETED = 'completed';

	/**
	 * Get the human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::SCHEDULED => __( 'Scheduled', 'nettertech-events' ),
			self::CANCELLED => __( 'Cancelled', 'nettertech-events' ),
			self::POSTPONED => __( 'Postponed', 'nettertech-events' ),
			self::COMPLETED => __( 'Completed', 'nettertech-events' ),
		};
	}

	/**
	 * Check if tickets can be purchased for this occurrence.
	 *
	 * @return bool
	 */
	public function is_purchasable(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return self::SCHEDULED === $this;
	}

	/**
	 * Check if the occurrence is still active (not cancelled/completed).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::SCHEDULED, self::POSTPONED => true,
			self::CANCELLED, self::COMPLETED => false,
		};
	}

	/**
	 * Get all status values as an array.
	 *
	 * @return array<string>
	 */
	public static function values(): array {
		return array_column( self::cases(), 'value' );
	}
}
