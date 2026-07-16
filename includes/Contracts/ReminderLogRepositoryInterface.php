<?php
/**
 * Reminder Log Repository interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\ReminderLog;

/**
 * Interface for reminder log repository implementations.
 *
 * @since 1.0.0
 * @api
 */
interface ReminderLogRepositoryInterface {

	/**
	 * Log a sent reminder.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param int    $attendee_id   Attendee ID.
	 * @param string $type          Reminder type.
	 * @param string $status        Send status ('sent' or 'failed').
	 * @return ReminderLog|null The created log entry, or null on failure.
	 */
	public function log_sent( int $occurrence_id, int $attendee_id, string $type = '24h_before', string $status = 'sent' ): ?ReminderLog;

	/**
	 * Check if a reminder has been sent.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param int    $attendee_id   Attendee ID.
	 * @param string $type          Reminder type.
	 * @return bool True if already sent.
	 */
	public function has_been_sent( int $occurrence_id, int $attendee_id, string $type = '24h_before' ): bool;

	/**
	 * Get attendee IDs that have already received a reminder.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $type          Reminder type.
	 * @return array<int> Array of attendee IDs.
	 */
	public function get_sent_attendee_ids( int $occurrence_id, string $type = '24h_before' ): array;

	/**
	 * Get all log entries for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<ReminderLog>
	 */
	public function for_occurrence( int $occurrence_id ): array;

	/**
	 * Delete old reminder log entries.
	 *
	 * @param int $days_to_keep Number of days to retain logs.
	 * @return int Number of deleted entries.
	 */
	public function cleanup( int $days_to_keep = 90 ): int;

	/**
	 * Delete log entries for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Number of entries deleted.
	 */
	public function delete_for_occurrence( int $occurrence_id ): int;
}
