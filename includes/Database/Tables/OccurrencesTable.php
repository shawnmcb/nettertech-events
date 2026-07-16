<?php
/**
 * Occurrences Table Definition.
 *
 * @package NetterTechEvents\Database\Tables
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the occurrences table structure.
 *
 * Pre-computed occurrences for fast calendar queries.
 * Each occurrence represents a single instance of an event.
 *
 * @since 1.1.0
 * @api
 */
class OccurrencesTable implements TableDefinitionInterface {

	/**
	 * Get the short table name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'occurrences';
	}

	/**
	 * Get the SQL CREATE TABLE statement.
	 *
	 * `start_datetime` / `end_datetime` are **wall-clock**, in the zone named by `timezone` —
	 * what the operator typed, which is what must be *displayed* back and what survives a DST
	 * shift. They are not instants, and SQL cannot turn them into instants: `CONVERT_TZ()`
	 * needs MySQL's named-timezone tables, which are empty on a stock server, and returns NULL
	 * when they are — so a WHERE built on it matches nothing and silently empties the calendar
	 * rather than failing loudly.
	 *
	 * So the instant is stored too. `start_utc` / `end_utc` are the same moments resolved to
	 * UTC, computed in PHP on write (where the zone database is real) and backfilled by
	 * migration 3.16.0. Every "is it over yet" question compares these against `UTC_TIMESTAMP()`.
	 * Nullable only so the migration can add them to a populated table; the write path always
	 * stamps them, and queries that read them tolerate a NULL rather than dropping the row.
	 *
	 * @param string $table_name      Full table name with WordPress prefix.
	 * @param string $charset_collate Database charset and collation.
	 * @return string SQL CREATE TABLE statement.
	 */
	public function get_sql( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            start_datetime datetime NOT NULL,
            end_datetime datetime NOT NULL,
            start_utc datetime DEFAULT NULL,
            end_utc datetime DEFAULT NULL,
            all_day tinyint(1) DEFAULT 0,
            timezone varchar(50) DEFAULT 'UTC',
            title_override varchar(255) DEFAULT NULL,
            description_override longtext DEFAULT NULL,
            featured_image_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) DEFAULT 'scheduled',
            capacity int(10) unsigned DEFAULT NULL,
            sequence_number int(10) unsigned DEFAULT 1,
            is_rescheduled tinyint(1) DEFAULT 0,
            is_override tinyint(1) NOT NULL DEFAULT 0,
            venue_name_override varchar(255) DEFAULT NULL,
            venue_address_override text DEFAULT NULL,
            virtual_url_override varchar(500) DEFAULT NULL,
            checkin_token varchar(96) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY start_datetime (start_datetime),
            KEY end_datetime (end_datetime),
            KEY status (status),
            KEY calendar_query (start_datetime, end_datetime, status),
            KEY event_schedule (event_id, start_datetime),
            KEY end_utc (end_utc),
            KEY timeline_utc (end_utc, status),
            UNIQUE KEY checkin_token (checkin_token)
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
