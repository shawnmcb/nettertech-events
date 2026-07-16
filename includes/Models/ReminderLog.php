<?php
/**
 * Reminder log model class.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a sent reminder email record.
 *
 * Used to track which reminders have been sent to prevent
 * duplicate sends when the cron job fires multiple times.
 *
 * @since 0.9.5
 * @api
 */
class ReminderLog {

	/**
	 * Record ID.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Occurrence ID.
	 *
	 * @var int
	 */
	public int $occurrence_id;

	/**
	 * Attendee ID.
	 *
	 * @var int
	 */
	public int $attendee_id;

	/**
	 * Reminder type identifier.
	 *
	 * @var string
	 */
	public string $reminder_type = '24h_before';

	/**
	 * Send status.
	 *
	 * @var string
	 */
	public string $status = 'sent';

	/**
	 * Timestamp when reminder was sent.
	 *
	 * @var string|null
	 */
	public ?string $sent_at = null;

	/**
	 * Valid reminder types.
	 *
	 * @var array<string>
	 */
	public const TYPES = array( '24h_before' );

	/**
	 * Valid statuses.
	 *
	 * @var array<string>
	 */
	public const STATUSES = array( 'sent', 'failed' );

	/**
	 * Create a ReminderLog from a database row.
	 *
	 * @param object|array<string, mixed> $row Database row.
	 * @return self
	 */
	public static function from_row( object|array $row ): self {
		$row = (object) $row;
		$log = new self();

		$log->id            = isset( $row->id ) ? (int) $row->id : null;
		$log->occurrence_id = (int) ( $row->occurrence_id ?? 0 );
		$log->attendee_id   = (int) ( $row->attendee_id ?? 0 );
		$log->reminder_type = $row->reminder_type ?? '24h_before';
		$log->status        = $row->status ?? 'sent';
		$log->sent_at       = $row->sent_at ?? null;

		return $log;
	}

	/**
	 * Convert to array for database insertion.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'occurrence_id' => $this->occurrence_id,
			'attendee_id'   => $this->attendee_id,
			'reminder_type' => $this->reminder_type,
			'status'        => $this->status,
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
			'%d', // attendee_id.
			'%s', // reminder_type.
			'%s', // status.
		);
	}
}
