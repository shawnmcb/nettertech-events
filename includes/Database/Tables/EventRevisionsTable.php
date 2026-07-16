<?php
/**
 * Event Revisions Table Definition.
 *
 * @package NetterTechEvents\Database\Tables
 */

declare(strict_types=1);


namespace NetterTechEvents\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the event_revisions table structure.
 *
 * Stores snapshots of event data before each save, enabling
 * undo/restore and change history tracking.
 *
 * @since 1.5.0
 * @api
 */
class EventRevisionsTable implements TableDefinitionInterface {

	/**
	 * Get the short table name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'event_revisions';
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
            user_id bigint(20) unsigned NOT NULL,
            revision_data longtext NOT NULL,
            change_summary varchar(500) DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY event_created (event_id, created_at)
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
