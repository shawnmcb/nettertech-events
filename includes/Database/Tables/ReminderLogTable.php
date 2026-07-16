<?php
/**
 * Reminder Log Table Definition.
 *
 * @package NetterTechEvents\Database\Tables
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the reminder_log table structure.
 *
 * Tracks sent reminder emails to prevent duplicate sends.
 * Each record represents one reminder sent to one attendee
 * for one occurrence.
 *
 * @since 0.9.5
 * @api
 */
class ReminderLogTable implements TableDefinitionInterface {

	/**
	 * Get the short table name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'reminder_log';
	}

	/**
	 * Get the SQL CREATE TABLE statement.
	 *
	 * @param string $table_name      Full table name with WordPress prefix.
	 * @param string $charset_collate Database charset and collation.
	 * @return string SQL CREATE TABLE statement.
	 */
	public function get_sql( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            occurrence_id bigint(20) unsigned NOT NULL,
            attendee_id bigint(20) unsigned NOT NULL,
            reminder_type varchar(20) NOT NULL DEFAULT '24h_before',
            status varchar(20) NOT NULL DEFAULT 'sent',
            sent_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_reminder (occurrence_id, attendee_id, reminder_type),
            KEY occurrence_lookup (occurrence_id),
            KEY attendee_lookup (attendee_id)
        ) {$charset_collate};";
	}

	/**
	 * Get tables this table depends on.
	 *
	 * @return array<string>
	 */
	public function get_dependencies(): array {
		return array( 'occurrences', 'attendees' );
	}
}
