<?php
/**
 * WaitlistEntry model.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a waitlist entry for a sold-out occurrence.
 *
 * @since 1.4.0
 * @api
 */
class WaitlistEntry {

	/**
	 * Entry ID.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Occurrence ID.
	 *
	 * @var int
	 */
	public int $occurrence_id = 0;

	/**
	 * Ticket type ID (optional, for specific ticket type waitlists).
	 *
	 * @var int|null
	 */
	public ?int $ticket_type_id = null;

	/**
	 * Email address.
	 *
	 * @var string
	 */
	public string $email = '';

	/**
	 * Name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * Phone number.
	 *
	 * @var string|null
	 */
	public ?string $phone = null;

	/**
	 * Position in waitlist queue.
	 *
	 * @var int
	 */
	public int $position = 0;

	/**
	 * Entry status: waiting, notified, converted, expired, removed.
	 *
	 * @var string
	 */
	public string $status = 'waiting';

	/**
	 * When the availability notification was sent.
	 *
	 * @var string|null
	 */
	public ?string $notified_at = null;

	/**
	 * When the entry was created.
	 *
	 * @var string|null
	 */
	public ?string $created_at = null;

	/**
	 * When the entry was last updated.
	 *
	 * @var string|null
	 */
	public ?string $updated_at = null;

	/**
	 * Create a WaitlistEntry from a database row.
	 *
	 * @param object|array<string, mixed> $row Database row.
	 * @return self
	 */
	public static function from_row( object|array $row ): self {
		$row   = (object) $row;
		$entry = new self();

		$entry->id             = isset( $row->id ) ? (int) $row->id : null;
		$entry->occurrence_id  = (int) ( $row->occurrence_id ?? 0 );
		$entry->ticket_type_id = isset( $row->ticket_type_id ) ? (int) $row->ticket_type_id : null;
		$entry->email          = $row->email ?? '';
		$entry->name           = $row->name ?? '';
		$entry->phone          = $row->phone ?? null;
		$entry->position       = (int) ( $row->position ?? 0 );
		$entry->status         = $row->status ?? 'waiting';
		$entry->notified_at    = $row->notified_at ?? null;
		$entry->created_at     = $row->created_at ?? null;
		$entry->updated_at     = $row->updated_at ?? null;

		return $entry;
	}

	/**
	 * Convert to array for database insertion.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'occurrence_id'  => $this->occurrence_id,
			'ticket_type_id' => $this->ticket_type_id,
			'email'          => $this->email,
			'name'           => $this->name,
			'phone'          => $this->phone,
			'position'       => $this->position,
			'status'         => $this->status,
			'notified_at'    => $this->notified_at,
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
			'%s', // email.
			'%s', // name.
			'%s', // phone.
			'%d', // position.
			'%s', // status.
			'%s', // notified_at.
		);
	}

	/**
	 * Validate the entry data.
	 *
	 * @return array<string> Validation errors (empty if valid).
	 */
	public function validate(): array {
		$errors = array();

		if ( $this->occurrence_id <= 0 ) {
			$errors[] = __( 'Occurrence ID is required.', 'nettertech-events' );
		}

		if ( '' === $this->email || ! is_email( $this->email ) ) {
			$errors[] = __( 'A valid email address is required.', 'nettertech-events' );
		}

		if ( '' === trim( $this->name ) ) {
			$errors[] = __( 'Name is required.', 'nettertech-events' );
		}

		$valid_statuses = array( 'waiting', 'notified', 'converted', 'expired', 'removed' );
		if ( ! in_array( $this->status, $valid_statuses, true ) ) {
			$errors[] = __( 'Invalid waitlist status.', 'nettertech-events' );
		}

		return $errors;
	}

	/**
	 * Check if the entry is actively waiting.
	 *
	 * @return bool
	 */
	public function is_waiting(): bool {
		return 'waiting' === $this->status;
	}

	/**
	 * Check if the entry has been notified.
	 *
	 * @return bool
	 */
	public function is_notified(): bool {
		return 'notified' === $this->status;
	}

	/**
	 * Check if the entry converted to a ticket purchase.
	 *
	 * @return bool
	 */
	public function is_converted(): bool {
		return 'converted' === $this->status;
	}
}
