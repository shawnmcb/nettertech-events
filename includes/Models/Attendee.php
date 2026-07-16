<?php
/**
 * Attendee model class.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents an attendee registration for an occurrence.
 *
 * Each attendee record represents one registration (order or RSVP).
 * The quantity field tracks party size for group check-ins.
 *
 * @since 0.8.0
 * @api
 */
class Attendee {

	/**
	 * Attendee ID.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Parent occurrence ID.
	 *
	 * @var int
	 */
	public int $occurrence_id;

	/**
	 * Ticket type ID.
	 *
	 * @var int|null
	 */
	public ?int $ticket_type_id = null;

	/**
	 * WooCommerce order ID (null for free RSVP).
	 *
	 * @var int|null
	 */
	public ?int $wc_order_id = null;

	/**
	 * Source of attendee creation (woocommerce, rsvp, manual).
	 *
	 * Used for lossless round-trip migration between VE and TEC.
	 *
	 * @var string
	 */
	public string $source = 'woocommerce';

	/**
	 * Attendee name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * Email address.
	 *
	 * @var string
	 */
	public string $email = '';

	/**
	 * Phone number.
	 *
	 * @var string|null
	 */
	public ?string $phone = null;

	/**
	 * Party size (number of people in this registration).
	 *
	 * @var int
	 */
	public int $quantity = 1;

	/**
	 * Status (confirmed, cancelled, pending).
	 *
	 * @var string
	 */
	public string $status = 'confirmed';

	/**
	 * Whether this attendee has been checked in (all guests).
	 *
	 * @var bool
	 */
	public bool $checked_in = false;

	/**
	 * Number of guests checked in (for partial check-ins).
	 *
	 * @var int
	 */
	public int $checked_in_count = 0;

	/**
	 * Check-in timestamp (first check-in).
	 *
	 * @var string|null
	 */
	public ?string $checked_in_at = null;

	/**
	 * Notes about this attendee.
	 *
	 * @var string|null
	 */
	public ?string $notes = null;

	/**
	 * Accessibility notes for special needs accommodations.
	 *
	 * @var string|null
	 */
	public ?string $accessibility_notes = null;

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
	 * Valid statuses.
	 *
	 * @var array<string>
	 */
	public const STATUSES = array( 'pending', 'confirmed', 'cancelled', 'refunded', 'voided' );

	/**
	 * Valid source values.
	 *
	 * @var array<string>
	 */
	public const SOURCES = array( 'woocommerce', 'rsvp', 'manual' );

	/**
	 * Create an Attendee from a database row.
	 *
	 * @param object|array<string, mixed> $row Database row.
	 * @return self
	 */
	public static function from_row( object|array $row ): self {
		$row      = (object) $row;
		$attendee = new self();

		$attendee->id                  = isset( $row->id ) ? (int) $row->id : null;
		$attendee->occurrence_id       = (int) ( $row->occurrence_id ?? 0 );
		$attendee->ticket_type_id      = isset( $row->ticket_type_id ) ? (int) $row->ticket_type_id : null;
		$attendee->wc_order_id         = isset( $row->wc_order_id ) ? (int) $row->wc_order_id : null;
		$attendee->source              = $row->source ?? 'woocommerce';
		$attendee->name                = $row->name ?? '';
		$attendee->email               = $row->email ?? '';
		$attendee->phone               = $row->phone ?? null;
		$attendee->quantity            = (int) ( $row->quantity ?? 1 );
		$attendee->status              = $row->status ?? 'confirmed';
		$attendee->checked_in          = ! empty( $row->checked_in );
		$attendee->checked_in_count    = (int) ( $row->checked_in_count ?? 0 );
		$attendee->checked_in_at       = $row->checked_in_at ?? null;
		$attendee->notes               = $row->notes ?? null;
		$attendee->accessibility_notes = $row->accessibility_notes ?? null;
		$attendee->created_at          = $row->created_at ?? null;
		$attendee->updated_at          = $row->updated_at ?? null;

		return $attendee;
	}

	/**
	 * Convert to array for database insertion.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'occurrence_id'       => $this->occurrence_id,
			'ticket_type_id'      => $this->ticket_type_id,
			'wc_order_id'         => $this->wc_order_id,
			'source'              => $this->source,
			'name'                => $this->name,
			'email'               => $this->email,
			'phone'               => $this->phone,
			'quantity'            => $this->quantity,
			'status'              => $this->status,
			'checked_in'          => $this->checked_in ? 1 : 0,
			'checked_in_count'    => $this->checked_in_count,
			'checked_in_at'       => $this->checked_in_at,
			'notes'               => $this->notes,
			'accessibility_notes' => $this->accessibility_notes,
		);
	}

	/**
	 * Get format specifiers for database operations.
	 *
	 * @return array<string>
	 */
	public function get_formats(): array {
		return array(
			'%d', // occurrence_id.
			'%d', // ticket_type_id.
			'%d', // wc_order_id.
			'%s', // source.
			'%s', // name.
			'%s', // email.
			'%s', // phone.
			'%d', // quantity.
			'%s', // status.
			'%d', // checked_in.
			'%d', // checked_in_count.
			'%s', // checked_in_at.
			'%s', // notes.
			'%s', // accessibility_notes.
		);
	}

	/**
	 * Validate the attendee data.
	 *
	 * @return array<string> Array of error messages, empty if valid.
	 */
	public function validate(): array {
		$errors = array();

		if ( empty( $this->occurrence_id ) ) {
			$errors[] = __( 'Occurrence ID is required.', 'nettertech-events' );
		}

		if ( empty( $this->name ) ) {
			$errors[] = __( 'Name is required.', 'nettertech-events' );
		}

		if ( empty( $this->email ) ) {
			$errors[] = __( 'Email is required.', 'nettertech-events' );
		} elseif ( ! is_email( $this->email ) ) {
			$errors[] = __( 'Invalid email address.', 'nettertech-events' );
		}

		if ( $this->quantity < 1 ) {
			$errors[] = __( 'Party size must be at least 1.', 'nettertech-events' );
		}

		if ( ! in_array( $this->status, self::STATUSES, true ) ) {
			$errors[] = __( 'Invalid attendee status.', 'nettertech-events' );
		}

		if ( ! in_array( $this->source, self::SOURCES, true ) ) {
			$errors[] = __( 'Invalid attendee source.', 'nettertech-events' );
		}

		return $errors;
	}

	/**
	 * Mark this attendee as fully checked in (all guests).
	 *
	 * @return void
	 */
	public function mark_checked_in(): void {
		$this->checked_in       = true;
		$this->checked_in_count = $this->quantity;
		if ( empty( $this->checked_in_at ) ) {
			$this->checked_in_at = current_time( 'mysql' );
		}
	}

	/**
	 * Mark this attendee as not checked in (reset all).
	 *
	 * @return void
	 */
	public function mark_not_checked_in(): void {
		$this->checked_in       = false;
		$this->checked_in_count = 0;
		$this->checked_in_at    = null;
	}

	/**
	 * Toggle check-in status (all or none).
	 *
	 * @return bool New checked-in state.
	 */
	public function toggle_checked_in(): bool {
		if ( $this->checked_in ) {
			$this->mark_not_checked_in();
		} else {
			$this->mark_checked_in();
		}
		return $this->checked_in;
	}

	/**
	 * Increment checked-in count by 1.
	 *
	 * @return int New checked-in count.
	 */
	public function increment_checked_in(): int {
		if ( $this->checked_in_count < $this->quantity ) {
			++$this->checked_in_count;
			if ( empty( $this->checked_in_at ) ) {
				$this->checked_in_at = current_time( 'mysql' );
			}
			if ( $this->checked_in_count >= $this->quantity ) {
				$this->checked_in = true;
			}
		}
		return $this->checked_in_count;
	}

	/**
	 * Decrement checked-in count by 1.
	 *
	 * @return int New checked-in count.
	 */
	public function decrement_checked_in(): int {
		if ( $this->checked_in_count > 0 ) {
			--$this->checked_in_count;
			$this->checked_in = false;
			if ( 0 === $this->checked_in_count ) {
				$this->checked_in_at = null;
			}
		}
		return $this->checked_in_count;
	}

	/**
	 * Set checked-in count to a specific value.
	 *
	 * @param int $count Number of guests checked in.
	 * @return int New checked-in count.
	 */
	public function set_checked_in_count( int $count ): int {
		$this->checked_in_count = max( 0, min( $count, $this->quantity ) );
		$this->checked_in       = $this->checked_in_count >= $this->quantity;
		if ( $this->checked_in_count > 0 && empty( $this->checked_in_at ) ) {
			$this->checked_in_at = current_time( 'mysql' );
		} elseif ( 0 === $this->checked_in_count ) {
			$this->checked_in_at = null;
		}
		return $this->checked_in_count;
	}

	/**
	 * Check if all guests are checked in.
	 *
	 * @return bool
	 */
	public function is_fully_checked_in(): bool {
		return $this->checked_in_count >= $this->quantity;
	}

	/**
	 * Check if some but not all guests are checked in.
	 *
	 * @return bool
	 */
	public function is_partially_checked_in(): bool {
		return $this->checked_in_count > 0 && $this->checked_in_count < $this->quantity;
	}

	/**
	 * Get remaining guests to check in.
	 *
	 * @return int
	 */
	public function get_remaining_count(): int {
		return max( 0, $this->quantity - $this->checked_in_count );
	}

	/**
	 * Check if this is a paid ticket (WooCommerce order).
	 *
	 * @return bool
	 */
	public function is_paid(): bool {
		return 'woocommerce' === $this->source && ! empty( $this->wc_order_id );
	}

	/**
	 * Check if this is an RSVP attendee.
	 *
	 * @return bool
	 */
	public function is_rsvp(): bool {
		return 'rsvp' === $this->source;
	}

	/**
	 * Check if this is a manually added attendee.
	 *
	 * @return bool
	 */
	public function is_manual(): bool {
		return 'manual' === $this->source;
	}

	/**
	 * Get compact check-in line for door volunteers.
	 *
	 * Format: "Name, email, party_size | ▢▢▢▢▢"
	 *
	 * @return string
	 */
	public function get_check_in_line(): string {
		$hash_marks = str_repeat( '▢', $this->quantity );
		return sprintf(
			'%s, %s, %d | %s',
			$this->name,
			$this->email,
			$this->quantity,
			$hash_marks
		);
	}

	/**
	 * Get check-in line with status indicator.
	 *
	 * Format: "☑ Name, email, party_size | ▢▢▢" or "☐ Name, email, party_size | ▢▢▢"
	 *
	 * @return string
	 */
	public function get_check_in_line_with_status(): string {
		$indicator  = $this->checked_in ? '☑' : '☐';
		$hash_marks = str_repeat( '▢', $this->quantity );
		return sprintf(
			'%s %s, %s, %d | %s',
			$indicator,
			$this->name,
			$this->email,
			$this->quantity,
			$hash_marks
		);
	}

	/**
	 * Get display name (first part of full name).
	 *
	 * @return string
	 */
	public function get_display_name(): string {
		$parts = explode( ' ', $this->name );
		return $parts[0];
	}

	/**
	 * Get formatted check-in time.
	 *
	 * @return string|null
	 */
	public function get_formatted_check_in_time(): ?string {
		if ( empty( $this->checked_in_at ) ) {
			return null;
		}

		$timestamp = strtotime( $this->checked_in_at );
		if ( false === $timestamp ) {
			return null;
		}
		$result = wp_date( get_option( 'time_format', 'g:i a' ), $timestamp );
		return false === $result ? null : $result;
	}
}
