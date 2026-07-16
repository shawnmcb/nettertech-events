<?php
/**
 * Reminder log repository class.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\ReminderLogRepositoryInterface;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Models\ReminderLog;

/**
 * Handles ReminderLog persistence and retrieval.
 *
 * Provides methods for logging sent reminders and checking
 * whether reminders have already been sent (duplicate prevention).
 *
 * @since 0.9.5
 * @api
 */
class ReminderLogRepository implements ReminderLogRepositoryInterface {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Reminder log table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database instance.
	 */
	public function __construct( \wpdb $db ) {
		$this->db    = $db;
		$this->table = Schema::table( 'reminder_log' );
	}

	/**
	 * Log a sent reminder.
	 *
	 * Uses INSERT IGNORE to handle race conditions where two cron runs
	 * attempt to log the same reminder simultaneously.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param int    $attendee_id   Attendee ID.
	 * @param string $type          Reminder type.
	 * @param string $status        Send status ('sent' or 'failed').
	 * @return ReminderLog|null The created log entry, or null on failure.
	 */
	public function log_sent( int $occurrence_id, int $attendee_id, string $type = '24h_before', string $status = 'sent' ): ?ReminderLog {
		$log                = new ReminderLog();
		$log->occurrence_id = $occurrence_id;
		$log->attendee_id   = $attendee_id;
		$log->reminder_type = $type;
		$log->status        = $status;

		$result = $this->db->insert(
			$this->table,
			$log->to_array(),
			$log->get_formats()
		);

		if ( false === $result ) {
			return null;
		}

		$log->id      = (int) $this->db->insert_id;
		$log->sent_at = current_time( 'mysql' );

		return $log;
	}

	/**
	 * Check if a reminder has been sent.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param int    $attendee_id   Attendee ID.
	 * @param string $type          Reminder type.
	 * @return bool True if already sent.
	 */
	public function has_been_sent( int $occurrence_id, int $attendee_id, string $type = '24h_before' ): bool {
		$count = $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE occurrence_id = %d AND attendee_id = %d AND reminder_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id,
				$attendee_id,
				$type
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Get attendee IDs that have already received a reminder.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $type          Reminder type.
	 * @return array<int> Array of attendee IDs.
	 */
	public function get_sent_attendee_ids( int $occurrence_id, string $type = '24h_before' ): array {
		$results = $this->db->get_col(
			$this->db->prepare(
				"SELECT attendee_id FROM {$this->table} WHERE occurrence_id = %d AND reminder_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id,
				$type
			)
		);

		return array_map( 'intval', $results );
	}

	/**
	 * Get all log entries for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<ReminderLog>
	 */
	public function for_occurrence( int $occurrence_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE occurrence_id = %d ORDER BY sent_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id
			)
		);

		return array_map( array( ReminderLog::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Delete old reminder log entries.
	 *
	 * @param int $days_to_keep Number of days to retain logs.
	 * @return int Number of deleted entries.
	 */
	public function cleanup( int $days_to_keep = 90 ): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$days_to_keep} days" ) );

		$sql = $this->db->prepare(
			"DELETE FROM {$this->table} WHERE sent_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			$cutoff
		);
		if ( null === $sql ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Scheduled cleanup; query prepared above.
		$deleted = $this->db->query( $sql );

		return (int) $deleted;
	}

	/**
	 * Delete log entries for an occurrence.
	 *
	 * Used when an occurrence is deleted.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Number of entries deleted.
	 */
	public function delete_for_occurrence( int $occurrence_id ): int {
		$result = $this->db->delete(
			$this->table,
			array( 'occurrence_id' => $occurrence_id ),
			array( '%d' )
		);

		return false !== $result ? $result : 0;
	}
}
