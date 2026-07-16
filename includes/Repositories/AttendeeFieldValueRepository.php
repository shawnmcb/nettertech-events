<?php
/**
 * Attendee Field Value Repository.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Models\AttendeeFieldValue;

/**
 * Database operations for attendee field values (EAV storage).
 *
 * @since 3.6.0
 * @api
 */
class AttendeeFieldValueRepository implements AttendeeFieldValueRepositoryInterface {

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
		$this->table = Schema::table( 'attendee_field_values' );
	}

	/**
	 * Get all field values for an attendee.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return array<AttendeeFieldValue>
	 */
	public function for_attendee( int $attendee_id ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE attendee_id = %d",
				$attendee_id
			)
		);

		return array_map(
			fn( $row ) => AttendeeFieldValue::from_row( $row ),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Save multiple field values for an attendee (upsert).
	 *
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomic upsert.
	 *
	 * @param int                     $attendee_id  Attendee ID.
	 * @param array<int, string|null> $field_values Map of field_id => value.
	 * @return void
	 */
	public function save_values( int $attendee_id, array $field_values ): void {
		foreach ( $field_values as $field_id => $value ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema::table().
			$sql = $this->db->prepare(
				"INSERT INTO {$this->table} (attendee_id, field_id, field_value) VALUES (%d, %d, %s)
				ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)",
				$attendee_id,
				(int) $field_id,
				$value
			);
			if ( null !== $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
				$this->db->query( $sql );
			}
		}
	}

	/**
	 * Delete all field values for an attendee.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return int Number of deleted rows.
	 */
	public function delete_for_attendee( int $attendee_id ): int {
		$result = $this->db->delete(
			$this->table,
			array( 'attendee_id' => $attendee_id ),
			array( '%d' )
		);

		return false !== $result ? $result : 0;
	}

	/**
	 * Delete all values for a specific field definition.
	 *
	 * @param int $field_id Field definition ID.
	 * @return int Number of deleted rows.
	 */
	public function delete_for_field( int $field_id ): int {
		$result = $this->db->delete(
			$this->table,
			array( 'field_id' => $field_id ),
			array( '%d' )
		);

		return false !== $result ? $result : 0;
	}

	/**
	 * Get field values for an attendee as a key-value map.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return array<int, string|null> Map of field_id => value.
	 */
	public function get_values_map( int $attendee_id ): array {
		$values = $this->for_attendee( $attendee_id );
		$map    = array();

		foreach ( $values as $value ) {
			$map[ $value->field_id ] = $value->field_value;
		}

		return $map;
	}
}
