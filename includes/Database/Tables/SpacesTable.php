<?php
/**
 * Spaces Table Definition.
 *
 * @package NetterTechEvents\Database\Tables
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the spaces table structure.
 *
 * Core space properties only. Rental-specific columns (rates, buffers,
 * booking durations) are added by the Rentals add-on via ALTER TABLE.
 *
 * @since 2.1.0
 * @api
 */
class SpacesTable implements TableDefinitionInterface {

	/**
	 * Get the short table name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'spaces';
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
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            tagline varchar(255) DEFAULT NULL,
            capacity int(10) unsigned NOT NULL DEFAULT 0,
            square_footage int(10) unsigned DEFAULT NULL,
            description text,
            featured_image_id bigint(20) unsigned DEFAULT NULL,
            sort_order int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'active',
            seating_model varchar(20) NOT NULL DEFAULT 'free',
            accessibility_features text,
            amenities text,
            gallery_image_ids text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY status (status)
        ) {$charset_collate};";
	}

	/**
	 * Get tables this table depends on.
	 *
	 * @return array<string>
	 */
	public function get_dependencies(): array {
		return array();
	}
}
