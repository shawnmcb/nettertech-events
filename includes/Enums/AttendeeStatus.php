<?php
/**
 * Attendee status enum.
 *
 * @package NetterTechEvents\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Represents the status of an attendee registration.
 *
 * @since 0.8.0
 * @api
 */
enum AttendeeStatus: string {

	case PENDING   = 'pending';
	case CONFIRMED = 'confirmed';
	case CANCELLED = 'cancelled';
	case REFUNDED  = 'refunded';
	case VOIDED    = 'voided';

	/**
	 * Get the human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::PENDING   => __( 'Pending', 'nettertech-events' ),
			self::CONFIRMED => __( 'Confirmed', 'nettertech-events' ),
			self::CANCELLED => __( 'Cancelled', 'nettertech-events' ),
			self::REFUNDED  => __( 'Refunded', 'nettertech-events' ),
			self::VOIDED    => __( 'Voided', 'nettertech-events' ),
		};
	}

	/**
	 * Check if the status represents an active registration.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::PENDING, self::CONFIRMED  => true,
			self::CANCELLED, self::REFUNDED, self::VOIDED => false,
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
