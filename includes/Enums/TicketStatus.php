<?php
/**
 * Ticket status enum.
 *
 * @package NetterTechEvents\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Represents the status of an issued ticket row in the tickets table.
 *
 * Canonical taxonomy for the `*_nettertech_events_tickets.status` column.
 * Aligned with `TicketsTable::get_sql()` DEFAULT and with `TicketRepository`
 * queries that read and write status values (e.g. `confirmed`, `checked_in`,
 * `cancelled`).
 *
 * @since 1.0.4
 * @api
 */
enum TicketStatus: string {

	case PENDING    = 'pending';
	case CONFIRMED  = 'confirmed';
	case CHECKED_IN = 'checked_in';
	case CANCELLED  = 'cancelled';

	/**
	 * Get the human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::PENDING    => __( 'Pending', 'nettertech-events' ),
			self::CONFIRMED  => __( 'Confirmed', 'nettertech-events' ),
			self::CHECKED_IN => __( 'Checked In', 'nettertech-events' ),
			self::CANCELLED  => __( 'Cancelled', 'nettertech-events' ),
		};
	}

	/**
	 * Whether this status represents a ticket that has been issued.
	 *
	 * Mirrors the filter used by capacity / sold-count reporting:
	 * an "issued" ticket counts against capacity and toward sold totals.
	 *
	 * @return bool
	 */
	public function is_issued(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid PHP 8.1+ enum syntax.
		return match ( $this ) {
			self::CONFIRMED, self::CHECKED_IN => true,
			self::PENDING, self::CANCELLED    => false,
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
