<?php
/**
 * Attendees Table Definition.
 *
 * @package NetterTechEvents\Database\Tables
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the attendees table structure.
 *
 * Registration records for check-in. One record per order/RSVP.
 * Stores party size (quantity) for group tracking.
 *
 * @since 1.1.0
 * @api
 */
class AttendeesTable implements TableDefinitionInterface {

	/**
	 * Get the short table name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'attendees';
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
            wc_order_id bigint(20) unsigned DEFAULT NULL,
            source varchar(20) DEFAULT 'woocommerce',
            name varchar(255) NOT NULL,
            email varchar(255) NOT NULL,
            phone varchar(50) DEFAULT NULL,
            quantity int(10) unsigned NOT NULL DEFAULT 1,
            status varchar(20) DEFAULT 'confirmed',
            checked_in tinyint(1) DEFAULT 0,
            checked_in_count int(10) unsigned NOT NULL DEFAULT 0,
            checked_in_at datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            accessibility_notes text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ticket_type_id (ticket_type_id),
            KEY wc_order_id (wc_order_id),
            KEY source (source),
            KEY email (email),
            KEY checked_in (checked_in),
            KEY checkin_query (occurrence_id, checked_in),
            KEY occurrence_status (occurrence_id, status),
            KEY occurrence_email (occurrence_id, email)
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
