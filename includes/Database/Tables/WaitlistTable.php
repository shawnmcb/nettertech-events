<?php
/**
 * Waitlist Table Definition.
 *
 * @package NetterTechEvents\Database\Tables
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the waitlist table structure.
 *
 * Stores waitlist entries for sold-out occurrences.
 * One entry per email per occurrence. Position tracks queue order.
 *
 * @since 1.4.0
 * @api
 */
class WaitlistTable implements TableDefinitionInterface {

	/**
	 * Get the short table name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'waitlist';
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
            ticket_type_id bigint(20) unsigned DEFAULT NULL,
            email varchar(255) NOT NULL,
            name varchar(255) NOT NULL,
            phone varchar(50) DEFAULT NULL,
            position int(10) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'waiting',
            notified_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY occurrence_email (occurrence_id, email),
            KEY ticket_type_id (ticket_type_id),
            KEY status (status),
            KEY occurrence_status (occurrence_id, status)
        ) {$charset_collate};";
	}

	/**
	 * Get tables this table depends on.
	 *
	 * @return array<string>
	 */
	public function get_dependencies(): array {
		return array( 'occurrences', 'ticket_types' );
	}
}
