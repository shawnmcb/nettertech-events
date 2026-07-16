<?php
/**
 * Attendee Fields Table Definition.
 *
 * @package NetterTechEvents\Database\Tables
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the attendee_fields table structure.
 *
 * Stores custom registration field definitions per event.
 * Each event can define its own set of fields that attendees fill in at checkout.
 *
 * @since 3.6.0
 * @api
 */
class AttendeeFieldsTable implements TableDefinitionInterface {

	/**
	 * Get the short table name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'attendee_fields';
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
            event_id bigint(20) unsigned NOT NULL,
            field_key varchar(64) NOT NULL,
            field_type varchar(20) NOT NULL DEFAULT 'text',
            label varchar(255) NOT NULL,
            placeholder varchar(255) DEFAULT NULL,
            description text DEFAULT NULL,
            options text DEFAULT NULL,
            is_required tinyint(1) NOT NULL DEFAULT 0,
            sort_order int(10) unsigned NOT NULL DEFAULT 0,
            validation_rules text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            UNIQUE KEY event_field_key (event_id, field_key)
        ) {$charset_collate};";
	}

	/**
	 * Get tables this table depends on.
	 *
	 * @return array<string>
	 */
	public function get_dependencies(): array {
		return array( 'events' );
	}
}
