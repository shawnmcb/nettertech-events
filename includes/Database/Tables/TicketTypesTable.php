<?php
/**
 * Ticket Types Table Definition.
 *
 * @package NetterTechEvents\Database\Tables
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the ticket_types table structure.
 *
 * Supports three-tier ticket scoping:
 * - scope='occurrence': Single occurrence ticket (occurrence_id required)
 * - scope='event': Series pass valid for all occurrences (event_id required)
 * - scope='template': Template that propagates to new occurrences (event_id required)
 *
 * @since 1.1.0
 * @api
 */
class TicketTypesTable implements TableDefinitionInterface {

	/**
	 * Get the short table name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'ticket_types';
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
            scope varchar(20) NOT NULL DEFAULT 'occurrence',
            event_id bigint(20) unsigned DEFAULT NULL,
            template_id bigint(20) unsigned DEFAULT NULL,
            occurrence_id bigint(20) unsigned DEFAULT NULL,
            name varchar(255) NOT NULL,
            description text,
            price decimal(10,2) NOT NULL DEFAULT 0.00,
            capacity_type varchar(20) NOT NULL DEFAULT 'fixed',
            capacity int(10) unsigned DEFAULT NULL,
            sold_count int(10) unsigned NOT NULL DEFAULT 0,
            stock_status varchar(20) NOT NULL DEFAULT 'in_stock',
            sale_start datetime DEFAULT NULL,
            sale_end datetime DEFAULT NULL,
            min_per_order int(10) unsigned DEFAULT 1,
            max_per_order int(10) unsigned DEFAULT 10,
            sort_order int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'active',
            wc_product_id bigint(20) unsigned DEFAULT NULL,
            wc_variation_id bigint(20) unsigned DEFAULT NULL,
            reserved int(10) unsigned NOT NULL DEFAULT 0,
            source varchar(20) DEFAULT 'woocommerce',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY occurrence_id (occurrence_id),
            KEY event_id (event_id),
            KEY template_id (template_id),
            KEY scope (scope),
            KEY wc_product_id (wc_product_id),
            KEY status (status),
            KEY occurrence_status (occurrence_id, status),
            KEY event_scope (event_id, scope),
            KEY stock_status (stock_status),
            KEY source (source)
        ) {$charset_collate};";
	}

	/**
	 * Get tables this table depends on.
	 *
	 * @return array<string>
	 */
	public function get_dependencies(): array {
		return array( 'events', 'occurrences' );
	}
}
