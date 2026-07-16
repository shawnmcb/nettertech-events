<?php
/**
 * Event Categories Junction Table Definition.
 *
 * @package NetterTechEvents\Database\Tables
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the event_categories junction table structure.
 *
 * Links events to categories (many-to-many relationship).
 *
 * @since 1.1.0
 * @api
 */
class EventCategoriesTable implements TableDefinitionInterface {

	/**
	 * Get the short table name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'event_categories';
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
            category_id bigint(20) unsigned NOT NULL,
            is_primary tinyint(1) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY event_category (event_id, category_id),
            KEY event_id (event_id),
            KEY category_id (category_id),
            KEY is_primary (is_primary)
        ) {$charset_collate};";
	}

	/**
	 * Get tables this table depends on.
	 *
	 * @return array<string>
	 */
	public function get_dependencies(): array {
		return array( 'events', 'categories' );
	}
}
