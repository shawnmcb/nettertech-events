<?php
/**
 * Table Definition Interface.
 *
 * Contract for database table definitions.
 *
 * @package NetterTechEvents\Database\Tables
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for database table definitions.
 *
 * Each table definition class provides the DDL for a single table.
 * This enables:
 * - Single responsibility per class
 * - Testable table definitions
 * - Easy addition of new tables
 *
 * @since 1.1.0
 * @api
 */
interface TableDefinitionInterface {

	/**
	 * Get the short table name (without WordPress prefix).
	 *
	 * @return string Table name (e.g., 'events', 'occurrences').
	 */
	public function get_name(): string;

	/**
	 * Get the SQL CREATE TABLE statement.
	 *
	 * @param string $table_name      Full table name with WordPress prefix.
	 * @param string $charset_collate Database charset and collation.
	 * @return string SQL CREATE TABLE statement.
	 */
	public function get_sql( string $table_name, string $charset_collate ): string;

	/**
	 * Get tables this table depends on.
	 *
	 * Used to determine creation order. Return empty array if no dependencies.
	 *
	 * @return array<string> List of table names this depends on.
	 */
	public function get_dependencies(): array;
}
