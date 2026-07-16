<?php
/**
 * Attendee Field Values Table Definition.
 *
 * @package NetterTechEvents\Database\Tables
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the attendee_field_values table structure.
 *
 * Stores custom registration field values per attendee.
 * One row per attendee per field (EAV pattern).
 *
 * @since 3.6.0
 * @api
 */
class AttendeeFieldValuesTable implements TableDefinitionInterface {

	/**
	 * Get the short table name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'attendee_field_values';
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
            attendee_id bigint(20) unsigned NOT NULL,
            field_id bigint(20) unsigned NOT NULL,
            field_value text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY attendee_field (attendee_id, field_id),
            KEY field_id (field_id)
        ) {$charset_collate};";
	}

	/**
	 * Get tables this table depends on.
	 *
	 * @return array<string>
	 */
	public function get_dependencies(): array {
		return array( 'attendees', 'attendee_fields' );
	}
}
