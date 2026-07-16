<?php
/**
 * Attendee check-in repository class.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeCheckInInterface;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Models\Attendee;

/**
 * Handles attendee check-in persistence and queries.
 *
 * Extracted from AttendeeRepository to enforce the Single Responsibility Principle.
 * Implements AttendeeCheckInInterface for ISP-compliant consumers
 * (CheckInController, ExportService, CheckInPage).
 *
 * @since 1.4.0
 */
class AttendeeCheckInRepository implements AttendeeCheckInInterface {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Attendees table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Ticket types table name.
	 *
	 * @var string
	 */
	private string $ticket_types_table;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database instance.
	 */
	public function __construct( \wpdb $db ) {
		$this->db                 = $db;
		$this->table              = Schema::table( 'attendees' );
		$this->ticket_types_table = Schema::table( 'ticket_types' );
	}

	/**
	 * Find an attendee by ID (internal helper for check-in operations).
	 *
	 * @param int $id Attendee ID.
	 * @return Attendee|null
	 */
	private function find( int $id ): ?Attendee {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$id
			)
		);

		return $row ? Attendee::from_row( $row ) : null;
	}

	/**
	 * Invalidate cached attendee data for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return void
	 */
	private function invalidate_occurrence_caches( int $occurrence_id ): void {
		wp_cache_delete( 'attendee_count_' . $occurrence_id, CacheManager::CACHE_GROUP );
		wp_cache_delete( 'attendee_stats_' . $occurrence_id, CacheManager::CACHE_GROUP );
	}

	/**
	 * Mark an attendee as fully checked in (all guests).
	 *
	 * @param int $id Attendee ID.
	 * @return bool True on success.
	 */
	public function mark_checked_in( int $id ): bool {
		$attendee = $this->find( $id );
		if ( ! $attendee ) {
			return false;
		}

		$result = $this->db->update(
			$this->table,
			array(
				'checked_in'       => 1,
				'checked_in_count' => $attendee->quantity,
				'checked_in_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			$this->invalidate_occurrence_caches( $attendee->occurrence_id );
		}

		return false !== $result;
	}

	/**
	 * Mark an attendee as not checked in (reset all guests).
	 *
	 * @param int $id Attendee ID.
	 * @return bool True on success.
	 */
	public function mark_not_checked_in( int $id ): bool {
		$result = $this->db->update(
			$this->table,
			array(
				'checked_in'       => 0,
				'checked_in_count' => 0,
				'checked_in_at'    => null,
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Increment checked-in count by 1 for partial check-ins.
	 *
	 * @param int $id Attendee ID.
	 * @return array{success: bool, checked_in_count: int, checked_in: bool} Result with new state.
	 */
	public function increment_checked_in( int $id ): array {
		// Atomic increment avoids read-then-write race condition.
		// MySQL evaluates SET left-to-right, so checked_in_count in the CASE
		// uses the already-incremented value after the first assignment.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source.
		$sql = $this->db->prepare(
			"UPDATE {$this->table}
			SET checked_in_count = checked_in_count + 1,
				checked_in = CASE WHEN checked_in_count >= quantity THEN 1 ELSE checked_in END,
				checked_in_at = COALESCE(checked_in_at, %s)
			WHERE id = %d AND checked_in_count < quantity",
			current_time( 'mysql' ),
			$id
		);
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
			$this->db->query( $sql );
		}

		$affected = $this->db->rows_affected;

		// Read back updated state.
		$attendee = $this->find( $id );
		if ( ! $attendee ) {
			return array(
				'success'          => false,
				'checked_in_count' => 0,
				'checked_in'       => false,
			);
		}

		$this->invalidate_occurrence_caches( $attendee->occurrence_id );

		return array(
			'success'          => $affected > 0,
			'checked_in_count' => $attendee->checked_in_count,
			'checked_in'       => $attendee->checked_in,
		);
	}

	/**
	 * Decrement checked-in count by 1 for partial check-ins.
	 *
	 * @param int $id Attendee ID.
	 * @return array{success: bool, checked_in_count: int, checked_in: bool} Result with new state.
	 */
	public function decrement_checked_in( int $id ): array {
		$attendee = $this->find( $id );
		if ( ! $attendee ) {
			return array(
				'success'          => false,
				'checked_in_count' => 0,
				'checked_in'       => false,
			);
		}

		$attendee->decrement_checked_in();

		$result = $this->db->update(
			$this->table,
			array(
				'checked_in'       => $attendee->checked_in ? 1 : 0,
				'checked_in_count' => $attendee->checked_in_count,
				'checked_in_at'    => $attendee->checked_in_at,
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);

		$this->invalidate_occurrence_caches( $attendee->occurrence_id );

		return array(
			'success'          => false !== $result,
			'checked_in_count' => $attendee->checked_in_count,
			'checked_in'       => $attendee->checked_in,
		);
	}

	/**
	 * Set checked-in count to a specific value.
	 *
	 * @param int $id    Attendee ID.
	 * @param int $count Number of guests checked in.
	 * @return array{success: bool, checked_in_count: int, checked_in: bool} Result with new state.
	 */
	public function set_checked_in_count( int $id, int $count ): array {
		$attendee = $this->find( $id );
		if ( ! $attendee ) {
			return array(
				'success'          => false,
				'checked_in_count' => 0,
				'checked_in'       => false,
			);
		}

		$attendee->set_checked_in_count( $count );

		$result = $this->db->update(
			$this->table,
			array(
				'checked_in'       => $attendee->checked_in ? 1 : 0,
				'checked_in_count' => $attendee->checked_in_count,
				'checked_in_at'    => $attendee->checked_in_at,
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);

		$this->invalidate_occurrence_caches( $attendee->occurrence_id );

		return array(
			'success'          => false !== $result,
			'checked_in_count' => $attendee->checked_in_count,
			'checked_in'       => $attendee->checked_in,
		);
	}

	/**
	 * Toggle check-in status.
	 *
	 * @param int $id Attendee ID.
	 * @return bool New checked-in state.
	 */
	public function toggle_checked_in( int $id ): bool {
		$attendee = $this->find( $id );
		if ( ! $attendee ) {
			return false;
		}

		if ( $attendee->checked_in ) {
			$this->mark_not_checked_in( $id );
			return false;
		} else {
			$this->mark_checked_in( $id );
			return true;
		}
	}

	/**
	 * Get check-in list for an occurrence.
	 *
	 * Returns attendees formatted for compact check-in display.
	 * Default sort is by family name (last word in name field).
	 *
	 * @param int                  $occurrence_id Occurrence ID.
	 * @param array<string, mixed> $args          Query arguments.
	 * @return array<array<string, mixed>>
	 */
	public function get_check_in_list( int $occurrence_id, array $args = array() ): array {
		$defaults = array(
			'search'     => '',
			'checked_in' => null,
			'orderby'    => 'last_name',
			'order'      => 'ASC',
			'limit'      => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array( 'a.occurrence_id = %d', "a.status = 'confirmed'" );
		$values = array( $occurrence_id );

		if ( ! empty( $args['search'] ) ) {
			$search_term = '%' . $this->db->esc_like( $args['search'] ) . '%';
			$where[]     = '(a.name LIKE %s OR a.email LIKE %s)';
			$values[]    = $search_term;
			$values[]    = $search_term;
		}

		if ( null !== $args['checked_in'] ) {
			$where[]  = 'a.checked_in = %d';
			$values[] = $args['checked_in'] ? 1 : 0;
		}

		$where_clause = 'WHERE ' . implode( ' AND ', $where );

		// Handle special 'last_name' sort (extracts last word from name).
		if ( 'last_name' === $args['orderby'] ) {
			$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
			$orderby = "SUBSTRING_INDEX(a.name, ' ', -1) {$order}, a.name {$order}";
		} else {
			$sanitized_a_orderby = sanitize_sql_orderby( 'a.' . $args['orderby'] . ' ' . $args['order'] );
			$orderby             = $sanitized_a_orderby ? $sanitized_a_orderby : 'a.name ASC';
		}

		$limit_clause = '';
		if ( $args['limit'] > 0 ) {
			$limit_clause = sprintf( ' LIMIT %d', (int) $args['limit'] );
		}

		$sql = $this->db->prepare(
			"SELECT a.*, tt.name as ticket_type_name
             FROM {$this->table} a
             LEFT JOIN {$this->ticket_types_table} tt ON a.ticket_type_id = tt.id
             {$where_clause}
             ORDER BY {$orderby}{$limit_clause}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			$values
		);

		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().

		$list = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$attendee = Attendee::from_row( $row );
			$list[]   = array(
				'id'                  => $attendee->id,
				'name'                => $attendee->name,
				'email'               => $attendee->email,
				'quantity'            => $attendee->quantity,
				'checked_in'          => $attendee->checked_in,
				'checked_in_count'    => $attendee->checked_in_count,
				'checked_in_at'       => $attendee->get_formatted_check_in_time(),
				'ticket_type'         => $row->ticket_type_name ?? '',
				'accessibility_notes' => $attendee->accessibility_notes,
				'line'                => $attendee->get_check_in_line(),
				'line_with_status'    => $attendee->get_check_in_line_with_status(),
			);
		}

		return $list;
	}

	/**
	 * Get check-in statistics for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<string, int>
	 */
	public function get_check_in_stats( int $occurrence_id ): array {
		$cache_key = 'attendee_stats_' . $occurrence_id;
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT
                    COUNT(*) as total_registrations,
                    COALESCE(SUM(quantity), 0) as total_guests,
                    SUM(CASE WHEN checked_in = 1 THEN 1 ELSE 0 END) as fully_checked_in_count,
                    COALESCE(SUM(checked_in_count), 0) as checked_in_guests
                 FROM {$this->table}
                 WHERE occurrence_id = %d AND status = 'confirmed'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id
			)
		);

		$stats = array(
			'total_registrations'    => (int) ( $row->total_registrations ?? 0 ),
			'total_guests'           => (int) ( $row->total_guests ?? 0 ),
			'fully_checked_in_count' => (int) ( $row->fully_checked_in_count ?? 0 ),
			'checked_in_guests'      => (int) ( $row->checked_in_guests ?? 0 ),
		);

		wp_cache_set( $cache_key, $stats, CacheManager::CACHE_GROUP, CacheManager::TTL_ATTENDEE_STATS );

		return $stats;
	}
}
