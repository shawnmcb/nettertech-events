<?php
/**
 * Event status enum.
 *
 * @package NetterTechEvents\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Represents the publication status of an event.
 *
 * @since 0.8.0
 * @api
 */
enum EventStatus: string {

	case DRAFT     = 'draft';
	case PUBLISHED = 'published';
	case CANCELLED = 'cancelled';
	case POSTPONED = 'postponed';

	/**
	 * Get the human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::DRAFT     => __( 'Draft', 'nettertech-events' ),
			self::PUBLISHED => __( 'Published', 'nettertech-events' ),
			self::CANCELLED => __( 'Cancelled', 'nettertech-events' ),
			self::POSTPONED => __( 'Postponed', 'nettertech-events' ),
		};
	}

	/**
	 * Check if the event is publicly visible.
	 *
	 * @return bool
	 */
	public function is_public(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return self::PUBLISHED === $this;
	}

	/**
	 * Check if the event is still active (not cancelled).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::DRAFT, self::PUBLISHED, self::POSTPONED => true,
			self::CANCELLED => false,
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
