<?php
/**
 * Events Table Definition.
 *
 * @package NetterTechEvents\Database\Tables
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the events table structure.
 *
 * Events are the core entity with title, description, venue info, and recurrence rules.
 *
 * @since 1.1.0
 * @api
 */
class EventsTable implements TableDefinitionInterface {

	/**
	 * Get the short table name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'events';
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
            post_id bigint(20) unsigned DEFAULT NULL,
            title varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            description longtext,
            excerpt text,
            featured_image_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) DEFAULT 'draft',
            event_type varchar(20) DEFAULT 'single',
            series_id bigint(20) unsigned DEFAULT NULL,
            venue_name varchar(255) DEFAULT NULL,
            venue_address text DEFAULT NULL,
            recurrence_rule text DEFAULT NULL,
            recurrence_end_date date DEFAULT NULL,
            layout_config text DEFAULT NULL,
            reminders_enabled tinyint(1) DEFAULT NULL,
            notification_emails text DEFAULT NULL,
            custom_fields JSON DEFAULT NULL,
            is_virtual tinyint(1) DEFAULT NULL,
            virtual_url varchar(500) DEFAULT NULL,
            collect_individual_attendees tinyint(1) DEFAULT NULL,
            qr_logo_mode varchar(20) DEFAULT NULL,
            qr_logo_attachment_id bigint(20) unsigned DEFAULT NULL,
            space_id bigint(20) unsigned DEFAULT NULL,
            image_vertical_anchor varchar(10) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY post_id (post_id),
            KEY series_id (series_id),
            KEY status (status),
            KEY event_type (event_type)
        ) {$charset_collate};";
	}

	/**
	 * Get tables this table depends on.
	 *
	 * @return array<string>
	 */
	public function get_dependencies(): array {
		return array( 'series' );
	}
}
