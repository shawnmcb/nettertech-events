<?php
/**
 * Attendee Field Repository.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\AttendeeField;

/**
 * Database operations for attendee field definitions.
 *
 * @since 3.6.0
 * @api
 */
class AttendeeFieldRepository implements AttendeeFieldRepositoryInterface {

	/**
	 * Database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database instance.
	 */
	public function __construct( \wpdb $db ) {
		$this->db    = $db;
		$this->table = Schema::table( 'attendee_fields' );
	}

	/**
	 * Find a field by ID.
	 *
	 * @param int $id Field ID.
	 * @return AttendeeField|null
	 */
	public function find( int $id ): ?AttendeeField {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$id
			)
		);

		return $row ? AttendeeField::from_row( $row ) : null;
	}

	/**
	 * Get all fields for an event, ordered by sort_order.
	 *
	 * @param int $event_id Event ID.
	 * @return array<AttendeeField>
	 */
	public function for_event( int $event_id ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE event_id = %d ORDER BY sort_order ASC, id ASC",
				$event_id
			)
		);

		return array_map(
			fn( $row ) => AttendeeField::from_row( $row ),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Save a field (insert or update).
	 *
	 * Auto-generates field_key from label if not set.
	 *
	 * @param AttendeeField $field Field to save.
	 * @return AttendeeField Saved field with ID populated.
	 * @throws ValidationException If validation fails.
	 * @throws DatabaseException   If save fails.
	 */
	public function save( AttendeeField $field ): AttendeeField {
		if ( empty( $field->field_key ) ) {
			$field->field_key = $this->generate_field_key( $field->event_id, $field->label );
		}

		$errors = $field->validate();
		if ( ! empty( $errors ) ) {
			throw ValidationException::fromErrors( array_map( 'esc_html', $errors ) );
		}

		$data    = $field->to_array();
		$formats = $field->get_formats();

		if ( null === $field->id ) {
			$result = $this->db->insert( $this->table, $data, $formats );

			if ( false === $result ) {
				throw DatabaseException::insertFailed( 'attendee field', esc_html( $this->format_db_error( 'insert', 'attendee field' ) ) );
			}

			$field->id = (int) $this->db->insert_id;
		} else {
			$result = $this->db->update(
				$this->table,
				$data,
				array( 'id' => $field->id ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				throw DatabaseException::updateFailed( 'attendee field', (int) $field->id, esc_html( $this->format_db_error( 'update', 'attendee field' ) ) );
			}
		}

		return $field;
	}

	/**
	 * Delete a field.
	 *
	 * @param int $id Field ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool {
		$result = $this->db->delete(
			$this->table,
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete all fields for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int Number of deleted rows.
	 */
	public function delete_for_event( int $event_id ): int {
		$result = $this->db->delete(
			$this->table,
			array( 'event_id' => $event_id ),
			array( '%d' )
		);

		return false !== $result ? $result : 0;
	}

	/**
	 * Reorder fields for an event.
	 *
	 * @param int        $event_id  Event ID.
	 * @param array<int> $field_ids Ordered array of field IDs.
	 * @return bool True on success.
	 */
	public function reorder( int $event_id, array $field_ids ): bool {
		foreach ( $field_ids as $position => $field_id ) {
			$this->db->update(
				$this->table,
				array( 'sort_order' => $position ),
				array(
					'id'       => (int) $field_id,
					'event_id' => $event_id,
				),
				array( '%d' ),
				array( '%d', '%d' )
			);
		}

		return true;
	}

	/**
	 * Generate a unique field_key from a label within an event.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $label    Field label.
	 * @return string Unique field key.
	 */
	private function generate_field_key( int $event_id, string $label ): string {
		$base_key = sanitize_title( $label );
		$base_key = substr( $base_key, 0, 50 );

		if ( empty( $base_key ) ) {
			$base_key = 'field';
		}

		$key   = $base_key;
		$count = 1;

		while ( $this->field_key_exists( $event_id, $key ) ) {
			$key = $base_key . '-' . $count;
			++$count;
		}

		return $key;
	}

	/**
	 * Check if a field_key already exists for an event.
	 *
	 * @param int    $event_id  Event ID.
	 * @param string $field_key Field key to check.
	 * @return bool
	 */
	private function field_key_exists( int $event_id, string $field_key ): bool {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
		$exists = $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE event_id = %d AND field_key = %s",
				$event_id,
				$field_key
			)
		);

		return (int) $exists > 0;
	}

	/**
	 * Format a database error message.
	 *
	 * @param string $operation Operation that failed.
	 * @param string $entity    Entity type.
	 * @return string Formatted error message.
	 */
	private function format_db_error( string $operation, string $entity ): string {
		return esc_html(
			sprintf(
				/* translators: 1: operation (insert/update), 2: entity type, 3: error message */
				__( 'Failed to %1$s %2$s: %3$s', 'nettertech-events' ),
				$operation,
				$entity,
				$this->db->last_error
			)
		);
	}
}
