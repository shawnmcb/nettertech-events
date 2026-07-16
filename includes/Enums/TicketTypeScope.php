<?php
/**
 * Ticket type scope enum.
 *
 * @package NetterTechEvents\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Represents the scope of a ticket type definition.
 *
 * - OCCURRENCE: Ticket valid for a single occurrence only.
 * - EVENT: Series pass valid for all occurrences of the event.
 * - TEMPLATE: Ticket type template that propagates to new occurrences.
 *
 * @since 0.8.0
 * @api
 */
enum TicketTypeScope: string {

	case OCCURRENCE = 'occurrence';
	case EVENT      = 'event';
	case TEMPLATE   = 'template';

	/**
	 * Get the human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::OCCURRENCE => __( 'Single Occurrence', 'nettertech-events' ),
			self::EVENT      => __( 'Series Pass', 'nettertech-events' ),
			self::TEMPLATE   => __( 'Template', 'nettertech-events' ),
		};
	}

	/**
	 * Get a description of what this scope means.
	 *
	 * @return string
	 */
	public function description(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::OCCURRENCE => __( 'Valid for one specific occurrence only.', 'nettertech-events' ),
			self::EVENT      => __( 'Valid for all occurrences in the event series.', 'nettertech-events' ),
			self::TEMPLATE   => __( 'Template that creates occurrence tickets automatically.', 'nettertech-events' ),
		};
	}

	/**
	 * Check if this scope requires an occurrence ID.
	 *
	 * @return bool
	 */
	public function requires_occurrence(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return self::OCCURRENCE === $this;
	}

	/**
	 * Check if this scope grants access to all occurrences.
	 *
	 * @return bool
	 */
	public function is_series_pass(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return self::EVENT === $this;
	}

	/**
	 * Get all scope values as an array.
	 *
	 * @return array<string>
	 */
	public static function values(): array {
		return array_column( self::cases(), 'value' );
	}
}
