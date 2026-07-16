<?php
/**
 * Ticket model class.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents an individual ticket issued to an attendee.
 *
 * Each ticket has a unique code for QR code generation and check-in.
 * Multiple tickets may be issued per attendee based on quantity.
 *
 * @since 0.8.0
 * @api
 */
class Ticket {

	/**
	 * Valid ticket statuses.
	 */
	public const STATUSES = array( 'pending', 'confirmed', 'cancelled', 'refunded', 'checked_in' );

	/**
	 * Ticket ID.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Ticket type ID.
	 *
	 * @var int
	 */
	public int $ticket_type_id = 0;

	/**
	 * Occurrence ID.
	 *
	 * @var int
	 */
	public int $occurrence_id = 0;

	/**
	 * Attendee ID.
	 *
	 * @var int|null
	 */
	public ?int $attendee_id = null;

	/**
	 * WooCommerce order ID.
	 *
	 * @var int|null
	 */
	public ?int $wc_order_id = null;

	/**
	 * WooCommerce order item ID.
	 *
	 * @var int|null
	 */
	public ?int $wc_order_item_id = null;

	/**
	 * Unique ticket code for QR generation.
	 *
	 * @var string
	 */
	public string $ticket_code = '';

	/**
	 * URL to the generated QR code image.
	 *
	 * @var string|null
	 */
	public ?string $qr_code_url = null;

	/**
	 * Ticket status.
	 *
	 * @var string
	 */
	public string $status = 'pending';

	/**
	 * Check-in timestamp.
	 *
	 * @var string|null
	 */
	public ?string $checked_in_at = null;

	/**
	 * Price paid for this ticket.
	 *
	 * @var float
	 */
	public float $price_paid = 0.00;

	/**
	 * Created timestamp.
	 *
	 * @var string|null
	 */
	public ?string $created_at = null;

	/**
	 * Updated timestamp.
	 *
	 * @var string|null
	 */
	public ?string $updated_at = null;

	/**
	 * Create a Ticket from a database row.
	 *
	 * @param object $row Database row object.
	 * @return self
	 */
	public static function from_row( object $row ): self {
		$ticket                   = new self();
		$ticket->id               = isset( $row->id ) ? (int) $row->id : null;
		$ticket->ticket_type_id   = (int) ( $row->ticket_type_id ?? 0 );
		$ticket->occurrence_id    = (int) ( $row->occurrence_id ?? 0 );
		$ticket->attendee_id      = isset( $row->attendee_id ) ? (int) $row->attendee_id : null;
		$ticket->wc_order_id      = isset( $row->wc_order_id ) ? (int) $row->wc_order_id : null;
		$ticket->wc_order_item_id = isset( $row->wc_order_item_id ) ? (int) $row->wc_order_item_id : null;
		$ticket->ticket_code      = $row->ticket_code ?? '';
		$ticket->qr_code_url      = $row->qr_code_url ?? null;
		$ticket->status           = $row->status ?? 'pending';
		$ticket->checked_in_at    = $row->checked_in_at ?? null;
		$ticket->price_paid       = (float) ( $row->price_paid ?? 0.00 );
		$ticket->created_at       = $row->created_at ?? null;
		$ticket->updated_at       = $row->updated_at ?? null;

		return $ticket;
	}

	/**
	 * Check if the ticket is confirmed.
	 *
	 * @return bool
	 */
	public function is_confirmed(): bool {
		return 'confirmed' === $this->status;
	}

	/**
	 * Check if the ticket has been checked in.
	 *
	 * @return bool
	 */
	public function is_checked_in(): bool {
		return 'checked_in' === $this->status || null !== $this->checked_in_at;
	}

	/**
	 * Check if the ticket is cancelled.
	 *
	 * @return bool
	 */
	public function is_cancelled(): bool {
		return 'cancelled' === $this->status || 'refunded' === $this->status;
	}

	/**
	 * Check if the ticket is valid for entry.
	 *
	 * @return bool
	 */
	public function is_valid_for_entry(): bool {
		return $this->is_confirmed() && ! $this->is_checked_in();
	}
}
