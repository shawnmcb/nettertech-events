<?php
/**
 * Deferred Schema Definitions.
 *
 * Contains table definitions for future features.
 * These tables exist in the database but PHP models/repositories are TODO.
 * Methods are NOT called from Schema::create_tables().
 *
 * @package NetterTechEvents\Database
 */

declare(strict_types=1);

namespace NetterTechEvents\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Deferred table definitions for future features.
 *
 * Organized by feature domain:
 * - Space rental (spaces, bookings, configurations, addons)
 * - Seating system (maps, seats, assignments, holds)
 * - Resources/Certifications (equipment, certifications, user certs)
 *
 * @since 1.1.0
 * @api
 */
class DeferredSchema {

	// =========================================================================
	// Space Rental Tables
	// =========================================================================

	/**
	 * Get spaces table SQL.
	 *
	 * @param string $table_name      Full table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string SQL CREATE TABLE statement.
	 */
	public static function get_spaces_sql( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            tagline varchar(255) DEFAULT NULL,
            description longtext,
            featured_image_id bigint(20) unsigned DEFAULT NULL,
            capacity int(10) unsigned DEFAULT 0,
            square_footage int(10) unsigned DEFAULT NULL,
            hourly_rate int(10) unsigned DEFAULT NULL,
            daily_rate int(10) unsigned DEFAULT NULL,
            rental_tier varchar(20) DEFAULT 'standard',
            buffer_before int(10) unsigned DEFAULT 0,
            buffer_after int(10) unsigned DEFAULT 0,
            min_booking_duration int(10) unsigned DEFAULT 60,
            max_booking_duration int(10) unsigned DEFAULT NULL,
            advance_booking_days int(10) unsigned DEFAULT 90,
            seating_model varchar(20) NOT NULL DEFAULT 'free',
            accessibility_features text,
            amenities text,
            status varchar(20) DEFAULT 'active',
            sort_order int(11) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY rental_tier (rental_tier),
            KEY status (status),
            KEY sort_order (sort_order)
        ) {$charset_collate};";
	}

	/**
	 * Get bookings table SQL.
	 *
	 * @param string $table_name      Full table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string SQL CREATE TABLE statement.
	 */
	public static function get_bookings_sql( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_type varchar(30) NOT NULL,
            bookable_type varchar(30) NOT NULL,
            bookable_id bigint(20) unsigned NOT NULL,
            configuration_id bigint(20) unsigned DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            customer_name varchar(255) NOT NULL,
            customer_email varchar(255) NOT NULL,
            customer_phone varchar(50) DEFAULT NULL,
            organization varchar(255) DEFAULT NULL,
            start_datetime datetime NOT NULL,
            end_datetime datetime NOT NULL,
            timezone varchar(50) DEFAULT 'America/Los_Angeles',
            title varchar(255) NOT NULL,
            description text,
            expected_attendance int(10) unsigned DEFAULT NULL,
            base_price decimal(10,2) NOT NULL DEFAULT 0.00,
            addons_total decimal(10,2) NOT NULL DEFAULT 0.00,
            discount_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            tax_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            total_price decimal(10,2) NOT NULL DEFAULT 0.00,
            deposit_amount decimal(10,2) DEFAULT NULL,
            deposit_paid tinyint(1) DEFAULT 0,
            wc_order_id bigint(20) unsigned DEFAULT NULL,
            status varchar(30) DEFAULT 'pending',
            internal_notes text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY booking_type (booking_type),
            KEY bookable_type (bookable_type),
            KEY user_id (user_id),
            KEY customer_email (customer_email),
            KEY start_datetime (start_datetime),
            KEY end_datetime (end_datetime),
            KEY status (status),
            KEY wc_order_id (wc_order_id)
        ) {$charset_collate};";
	}

	/**
	 * Get space_configurations table SQL.
	 *
	 * @param string $table_name      Full table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string SQL CREATE TABLE statement.
	 */
	public static function get_space_configurations_sql( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            space_id bigint(20) unsigned NOT NULL,
            name varchar(100) NOT NULL,
            layout_type varchar(50) NOT NULL,
            capacity int(10) unsigned DEFAULT 0,
            table_count int(10) unsigned DEFAULT NULL,
            seats_per_table int(10) unsigned DEFAULT NULL,
            is_default tinyint(1) DEFAULT 0,
            notes text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY space_id (space_id),
            KEY layout_type (layout_type)
        ) {$charset_collate};";
	}

	/**
	 * Get addon_types table SQL.
	 *
	 * @param string $table_name      Full table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string SQL CREATE TABLE statement.
	 */
	public static function get_addon_types_sql( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            description text,
            addon_category varchar(50) NOT NULL,
            price decimal(10,2) NOT NULL,
            price_type varchar(20) DEFAULT 'flat',
            applicable_to text,
            status varchar(20) DEFAULT 'active',
            sort_order int(11) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY addon_category (addon_category),
            KEY status (status)
        ) {$charset_collate};";
	}

	/**
	 * Get booking_addons table SQL.
	 *
	 * @param string $table_name      Full table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string SQL CREATE TABLE statement.
	 */
	public static function get_booking_addons_sql( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) unsigned NOT NULL,
            addon_type_id bigint(20) unsigned DEFAULT NULL,
            name varchar(255) NOT NULL,
            description text,
            quantity int(10) unsigned DEFAULT 1,
            unit_price decimal(10,2) NOT NULL,
            total_price decimal(10,2) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY booking_id (booking_id),
            KEY addon_type_id (addon_type_id)
        ) {$charset_collate};";
	}

	// =========================================================================
	// Seating System Tables — REMOVED
	// Seating tables (seating_maps, seats, seat_assignments, seat_holds)
	// are now owned by the nettertech-events-seating add-on plugin.
	// See nettertech-events-seating/includes/Database/SeatingSchema.php.
	// =========================================================================

	// =========================================================================
	// Resource & Certification Tables
	// =========================================================================

	/**
	 * Get resources table SQL.
	 *
	 * @param string $table_name      Full table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string SQL CREATE TABLE statement.
	 */
	public static function get_resources_sql( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            space_id bigint(20) unsigned DEFAULT NULL,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            description text,
            resource_type varchar(50) NOT NULL,
            featured_image_id bigint(20) unsigned DEFAULT NULL,
            hourly_rate decimal(10,2) DEFAULT NULL,
            min_booking_minutes int(10) unsigned DEFAULT 60,
            max_booking_minutes int(10) unsigned DEFAULT 480,
            buffer_minutes int(10) unsigned DEFAULT 15,
            quantity_available int(10) unsigned DEFAULT 1,
            operating_hours text,
            required_certifications text,
            is_accessible tinyint(1) DEFAULT 0,
            accessibility_notes text,
            status varchar(20) DEFAULT 'available',
            sort_order int(11) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY space_id (space_id),
            KEY resource_type (resource_type),
            KEY status (status)
        ) {$charset_collate};";
	}

	/**
	 * Get certification_types table SQL.
	 *
	 * @param string $table_name      Full table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string SQL CREATE TABLE statement.
	 */
	public static function get_certification_types_sql( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            description text,
            requirements text,
            validity_months int(10) unsigned DEFAULT NULL,
            requires_renewal tinyint(1) DEFAULT 0,
            prerequisite_ids text,
            min_age int(10) unsigned DEFAULT NULL,
            training_event_id bigint(20) unsigned DEFAULT NULL,
            badge_image_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) DEFAULT 'active',
            sort_order int(11) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY training_event_id (training_event_id),
            KEY status (status)
        ) {$charset_collate};";
	}

	/**
	 * Get user_certifications table SQL.
	 *
	 * @param string $table_name      Full table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string SQL CREATE TABLE statement.
	 */
	public static function get_user_certifications_sql( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            certification_type_id bigint(20) unsigned NOT NULL,
            completed_at datetime NOT NULL,
            expires_at datetime DEFAULT NULL,
            source_type varchar(50) DEFAULT 'class',
            source_id bigint(20) unsigned DEFAULT NULL,
            granted_by bigint(20) unsigned DEFAULT NULL,
            notes text,
            status varchar(20) DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY certification_type_id (certification_type_id),
            KEY expires_at (expires_at),
            KEY source_type (source_type),
            KEY status (status),
            UNIQUE KEY user_cert (user_id, certification_type_id)
        ) {$charset_collate};";
	}

	/**
	 * Get list of all deferred table names.
	 *
	 * @return array<string> Table names without prefix.
	 */
	public static function get_table_names(): array {
		return array(
			// Space rental ('spaces' moved to core Schema in 3.5.0).
			'space_configurations',
			'bookings',
			'addon_types',
			'booking_addons',
			// Seating — removed; now owned by nettertech-events-seating add-on.
			// Resources.
			'resources',
			'certification_types',
			'user_certifications',
		);
	}
}
