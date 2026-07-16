<?php
/**
 * Reservations Table Definition.
 *
 * @package NetterTechEvents\Database\Tables
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the reservations table structure.
 *
 * Tracks per-session capacity reservations for cart holds.
 * The aggregate reserved count lives on ticket_types.reserved;
 * this table provides per-session detail for release and expiry.
 *
 * @since 3.4.0
 * @api
 */
class ReservationsTable implements TableDefinitionInterface {

	/**
	 * Get the short table name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'reservations';
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
            ticket_type_id bigint(20) unsigned NOT NULL,
            session_key varchar(64) NOT NULL,
            quantity int(10) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ticket_session (ticket_type_id, session_key),
            KEY expires_at (expires_at)
        ) {$charset_collate};";
	}

	/**
	 * Get tables this table depends on.
	 *
	 * @return array<string>
	 */
	public function get_dependencies(): array {
		return array( 'ticket_types' );
	}
}
