<?php
/**
 * Tickets Table Definition.
 *
 * @package NetterTechEvents\Database\Tables
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Tables;

use NetterTechEvents\Enums\TicketStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the tickets table structure.
 *
 * Individual tickets with unique codes and QR data.
 * Each ticket is linked to an attendee, occurrence, and ticket type.
 *
 * @since 1.1.0
 * @api
 */
class TicketsTable implements TableDefinitionInterface {

	/**
	 * Get the short table name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'tickets';
	}

	/**
	 * Get the SQL CREATE TABLE statement.
	 *
	 * @param string $table_name      Full table name with WordPress prefix.
	 * @param string $charset_collate Database charset and collation.
	 * @return string SQL CREATE TABLE statement.
	 */
	public function get_sql( string $table_name, string $charset_collate ): string {
		$default_status = TicketStatus::PENDING->value;
		return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_type_id bigint(20) unsigned NOT NULL,
            occurrence_id bigint(20) unsigned NOT NULL,
            attendee_id bigint(20) unsigned DEFAULT NULL,
            wc_order_id bigint(20) unsigned DEFAULT NULL,
            wc_order_item_id bigint(20) unsigned DEFAULT NULL,
            ticket_code varchar(64) NOT NULL,
            qr_code_url varchar(255) DEFAULT NULL,
            status varchar(20) DEFAULT '{$default_status}',
            checked_in_at datetime DEFAULT NULL,
            price_paid decimal(10,2) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ticket_code (ticket_code),
            KEY ticket_type_id (ticket_type_id),
            KEY occurrence_id (occurrence_id),
            KEY attendee_id (attendee_id),
            KEY wc_order_id (wc_order_id),
            KEY status (status)
        ) {$charset_collate};";
	}

	/**
	 * Get tables this table depends on.
	 *
	 * @return array<string>
	 */
	public function get_dependencies(): array {
		return array( 'ticket_types', 'occurrences', 'attendees' );
	}
}
