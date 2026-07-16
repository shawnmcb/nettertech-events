<?php
/**
 * Waitlist Repository.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\WaitlistRepositoryInterface;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\WaitlistEntry;

/**
 * Database operations for waitlist entries.
 *
 * @since 1.4.0
 * @api
 */
class WaitlistRepository implements WaitlistRepositoryInterface {

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
		$this->table = Schema::table( 'waitlist' );
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

	/**
	 * Find a waitlist entry by ID.
	 *
	 * @param int $id Entry ID.
	 * @return WaitlistEntry|null
	 */
	public function find( int $id ): ?WaitlistEntry {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$id
			)
		);

		return $row ? WaitlistEntry::from_row( $row ) : null;
	}

	/**
	 * Get waitlist entries for an occurrence.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $status        Optional status filter.
	 * @return array<WaitlistEntry>
	 */
	public function for_occurrence( int $occurrence_id, string $status = '' ): array {
		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			$sql = $this->db->prepare(
				"SELECT * FROM {$this->table} WHERE occurrence_id = %d AND status = %s ORDER BY position ASC",
				$occurrence_id,
				$status
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			$sql = $this->db->prepare(
				"SELECT * FROM {$this->table} WHERE occurrence_id = %d ORDER BY position ASC",
				$occurrence_id
			);
		}

		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().

		return array_map(
			fn( $row ) => WaitlistEntry::from_row( $row ),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Count waitlist entries for an occurrence.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $status        Optional status filter.
	 * @return int
	 */
	public function count_for_occurrence( int $occurrence_id, string $status = 'waiting' ): int {
		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			$count = $this->db->get_var(
				$this->db->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE occurrence_id = %d AND status = %s",
					$occurrence_id,
					$status
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			$count = $this->db->get_var(
				$this->db->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE occurrence_id = %d",
					$occurrence_id
				)
			);
		}

		return (int) $count;
	}

	/**
	 * Find a waitlist entry by email and occurrence.
	 *
	 * @param string $email         Email address.
	 * @param int    $occurrence_id Occurrence ID.
	 * @return WaitlistEntry|null
	 */
	public function find_by_email( string $email, int $occurrence_id ): ?WaitlistEntry {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE email = %s AND occurrence_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$email,
				$occurrence_id
			)
		);

		return $row ? WaitlistEntry::from_row( $row ) : null;
	}

	/**
	 * Save a waitlist entry (insert or update).
	 *
	 * @param WaitlistEntry $entry Entry to save.
	 * @return WaitlistEntry Saved entry with ID populated.
	 * @throws ValidationException If validation fails.
	 * @throws DatabaseException   If save fails.
	 */
	public function save( WaitlistEntry $entry ): WaitlistEntry {
		$errors = $entry->validate();
		if ( ! empty( $errors ) ) {
			throw ValidationException::fromErrors( array_map( 'esc_html', $errors ) );
		}

		$data    = $entry->to_array();
		$formats = $entry->get_formats();

		if ( null === $entry->id ) {
			$result = $this->db->insert( $this->table, $data, $formats );

			if ( false === $result ) {
				throw DatabaseException::insertFailed( 'waitlist entry', esc_html( $this->format_db_error( 'insert', 'waitlist entry' ) ) );
			}

			$entry->id = (int) $this->db->insert_id;
		} else {
			$result = $this->db->update(
				$this->table,
				$data,
				array( 'id' => $entry->id ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				throw DatabaseException::updateFailed( 'waitlist entry', (int) $entry->id, esc_html( $this->format_db_error( 'update', 'waitlist entry' ) ) );
			}
		}

		return $entry;
	}

	/**
	 * Delete a waitlist entry.
	 *
	 * @param int $id Entry ID.
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
	 * Get the next position number for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Next position number.
	 */
	public function get_next_position( int $occurrence_id ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
		$max = $this->db->get_var(
			$this->db->prepare(
				"SELECT MAX(position) FROM {$this->table} WHERE occurrence_id = %d",
				$occurrence_id
			)
		);

		return ( null === $max ) ? 1 : ( (int) $max + 1 );
	}

	/**
	 * Get the next waiting entry in the queue for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return WaitlistEntry|null Next entry or null if queue empty.
	 */
	public function get_next_in_queue( int $occurrence_id ): ?WaitlistEntry {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE occurrence_id = %d AND status = 'waiting' ORDER BY position ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id
			)
		);

		return $row ? WaitlistEntry::from_row( $row ) : null;
	}

	/**
	 * Delete all entries for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Number of entries deleted.
	 */
	public function delete_for_occurrence( int $occurrence_id ): int {
		$result = $this->db->delete(
			$this->table,
			array( 'occurrence_id' => $occurrence_id ),
			array( '%d' )
		);

		return false !== $result ? $result : 0;
	}

	/**
	 * Update entry status.
	 *
	 * @param int    $id     Entry ID.
	 * @param string $status New status.
	 * @return bool True on success.
	 */
	public function update_status( int $id, string $status ): bool {
		$data    = array( 'status' => $status );
		$formats = array( '%s' );

		if ( 'notified' === $status ) {
			$data['notified_at'] = current_time( 'mysql' );
			$formats[]           = '%s';
		}

		$result = $this->db->update(
			$this->table,
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		return false !== $result;
	}
}
