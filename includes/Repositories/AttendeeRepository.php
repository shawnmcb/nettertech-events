<?php
/**
 * Attendee repository class.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeOrderInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeSearchInterface;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Enums\AttendeeStatus;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Traits\IdentityMapTrait;
use NetterTechEvents\Utilities\DatabaseLogger;

/**
 * Handles Attendee persistence and retrieval.
 *
 * Implements segregated interfaces following the Interface Segregation Principle:
 * - AttendeeRepositoryInterface: Core CRUD operations (7 methods)
 * - AttendeeOrderInterface: WooCommerce order operations (4 methods)
 * - AttendeeSearchInterface: Search and lookup operations (4 methods)
 *
 * Check-in operations (8 methods) are in AttendeeCheckInRepository.
 *
 * @since 0.9.0
 * @since 0.9.3 Refactored to implement segregated interfaces.
 * @since 1.4.0 Check-in methods extracted to AttendeeCheckInRepository.
 * @api
 */
class AttendeeRepository implements
	AttendeeRepositoryInterface,
	AttendeeOrderInterface,
	AttendeeSearchInterface {

	use IdentityMapTrait;

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
	 * Constructor.
	 *
	 * @param \wpdb $db Database instance.
	 */
	public function __construct( \wpdb $db ) {
		$this->db    = $db;
		$this->table = Schema::table( 'attendees' );
	}

	/**
	 * Format database error for exceptions.
	 *
	 * In production (WP_DEBUG false), returns a generic message to avoid
	 * leaking database schema information. In development, includes the
	 * actual error for debugging.
	 *
	 * @param string $operation The operation that failed (e.g., 'insert', 'update').
	 * @param string $entity    The entity type (e.g., 'attendee').
	 * @return string Safe error message.
	 */
	private function format_db_error( string $operation, string $entity ): string {
		// Log the error with appropriate detail level (sanitized in production).
		if ( $this->db->last_error ) {
			DatabaseLogger::log_error( $operation, $entity, $this->db->last_error );
		}

		// In debug mode, include the actual error for developers.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return sprintf(
				/* translators: 1: operation (insert/update), 2: entity type, 3: error message */
				esc_html__( 'Failed to %1$s %2$s: %3$s', 'nettertech-events' ),
				$operation,
				$entity,
				esc_html( $this->db->last_error )
			);
		}

		// In production, return a generic message.
		return sprintf(
			/* translators: 1: operation (insert/update), 2: entity type */
			esc_html__( 'Failed to %1$s %2$s. Please try again or contact support.', 'nettertech-events' ),
			$operation,
			$entity
		);
	}

	/**
	 * Find an attendee by ID.
	 *
	 * Uses identity map for per-request deduplication and persistent
	 * object cache for cross-request performance.
	 *
	 * @param int $id Attendee ID.
	 * @return Attendee|null
	 */
	public function find( int $id ): ?Attendee {
		// Check identity map first (per-request).
		$cached_attendee = $this->recalled( $id );
		if ( $cached_attendee instanceof Attendee ) {
			return $cached_attendee;
		}

		// Check persistent object cache (cross-request).
		$cache_key = 'attendee_' . $id;
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );
		if ( false !== $cached ) {
			$attendee = Attendee::from_row( $cached );
			$this->remember( $id, $attendee );
			return $attendee;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$id
			)
		);

		if ( ! $row ) {
			return null;
		}

		$attendee = Attendee::from_row( $row );
		$this->remember( $id, $attendee );

		wp_cache_set( $cache_key, $row, CacheManager::CACHE_GROUP, CacheManager::TTL_ATTENDEE_STATS );

		return $attendee;
	}

	/**
	 * Get attendees for an occurrence.
	 *
	 * @param int                  $occurrence_id Occurrence ID.
	 * @param array<string, mixed> $args          Query arguments.
	 * @return array<Attendee>
	 */
	public function for_occurrence( int $occurrence_id, array $args = array() ): array {
		$defaults = array(
			'status'     => 'confirmed',
			'checked_in' => null,
			'orderby'    => 'name',
			'order'      => 'ASC',
			'limit'      => 500,
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array( 'occurrence_id = %d' );
		$values = array( $occurrence_id );

		if ( null !== $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( null !== $args['checked_in'] ) {
			$where[]  = 'checked_in = %d';
			$values[] = $args['checked_in'] ? 1 : 0;
		}

		$where_clause      = 'WHERE ' . implode( ' AND ', $where );
		$sanitized_orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		$orderby           = $sanitized_orderby ? $sanitized_orderby : 'name ASC';
		$values[]          = $args['limit'];

		$sql = $this->db->prepare(
			"SELECT * FROM {$this->table} {$where_clause} ORDER BY {$orderby} LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			$values
		);

		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().

		return array_map( array( Attendee::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Find attendee by WooCommerce order ID.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return Attendee|null
	 */
	public function find_by_order( int $order_id ): ?Attendee {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE wc_order_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$order_id
			)
		);

		return $row ? Attendee::from_row( $row ) : null;
	}

	/**
	 * Find all attendees by WooCommerce order ID.
	 *
	 * Returns all attendees for an order (one per line item).
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array<Attendee>
	 */
	public function find_all_by_order( int $order_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE wc_order_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$order_id
			)
		);

		return array_map( array( Attendee::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Find attendee by WooCommerce order ID and occurrence ID.
	 *
	 * Used for partial refunds to find the specific attendee record.
	 *
	 * @param int $order_id      WooCommerce order ID.
	 * @param int $occurrence_id Occurrence ID.
	 * @return Attendee|null
	 */
	public function find_by_order_and_occurrence( int $order_id, int $occurrence_id ): ?Attendee {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE wc_order_id = %d AND occurrence_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$order_id,
				$occurrence_id
			)
		);

		return $row ? Attendee::from_row( $row ) : null;
	}

	/**
	 * Update attendee quantity.
	 *
	 * Used for partial refunds to reduce the party size.
	 *
	 * @param int $id       Attendee ID.
	 * @param int $quantity New quantity.
	 * @return bool True on success.
	 */
	public function update_quantity( int $id, int $quantity ): bool {
		if ( $quantity < 0 ) {
			return false;
		}

		$result = $this->db->update(
			$this->table,
			array( 'quantity' => $quantity ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Find attendees by email for an occurrence.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $email         Email address.
	 * @return array<Attendee>
	 */
	public function find_by_email( int $occurrence_id, string $email ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table}
                 WHERE occurrence_id = %d AND email = %s AND status = 'confirmed'
                 ORDER BY name ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id,
				$email
			)
		);

		return array_map( array( Attendee::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Find attendee by ticket code for an occurrence.
	 *
	 * @param string $ticket_code   Ticket code (format: XXXX-XXXX-XXXX-XXXX).
	 * @param int    $occurrence_id Occurrence ID.
	 * @return Attendee|null
	 */
	public function find_by_ticket_code( string $ticket_code, int $occurrence_id ): ?Attendee {
		$tickets = Schema::table( 'tickets' );

		// ticket_code lives on the tickets table; attendees has no such column.
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT a.* FROM {$this->table} a
				 INNER JOIN {$tickets} t ON t.attendee_id = a.id
				 WHERE a.occurrence_id = %d AND t.ticket_code = %s AND a.status = 'confirmed'
				 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id,
				$ticket_code
			)
		);

		return $row ? Attendee::from_row( $row ) : null;
	}

	/**
	 * Save an attendee (insert or update).
	 *
	 * @param Attendee $attendee Attendee to save.
	 * @return Attendee The saved attendee with ID populated.
	 * @throws ValidationException If validation fails.
	 * @throws DatabaseException   If save fails.
	 */
	public function save( Attendee $attendee ): Attendee {
		$errors = $attendee->validate();
		if ( ! empty( $errors ) ) {
			throw ValidationException::fromErrors( array_map( 'esc_html', $errors ) );
		}

		$data    = $attendee->to_array();
		$formats = $attendee->get_formats();

		if ( null === $attendee->id ) {
			$result = $this->db->insert( $this->table, $data, $formats );

			if ( false === $result ) {
				throw DatabaseException::insertFailed( 'attendee', esc_html( $this->format_db_error( 'insert', 'attendee' ) ) );
			}

			$attendee->id = (int) $this->db->insert_id;
		} else {
			$result = $this->db->update(
				$this->table,
				$data,
				array( 'id' => $attendee->id ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				throw DatabaseException::updateFailed( 'attendee', (int) $attendee->id, esc_html( $this->format_db_error( 'update', 'attendee' ) ) );
			}
		}

		if ( $attendee->id ) {
			$this->invalidate_attendee_cache( $attendee->id );
		}
		$this->invalidate_occurrence_caches( $attendee->occurrence_id );

		return $attendee;
	}

	/**
	 * Delete an attendee.
	 *
	 * @param int $id Attendee ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool {
		$attendee = $this->find( $id );

		$this->invalidate_attendee_cache( $id );

		$result = $this->db->delete(
			$this->table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false !== $result && $attendee ) {
			$this->invalidate_occurrence_caches( $attendee->occurrence_id );
		}

		return false !== $result;
	}

	/**
	 * Search attendees for an occurrence.
	 *
	 * Searches name and email fields.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $query         Search query.
	 * @return array<Attendee>
	 */
	public function search( int $occurrence_id, string $query ): array {
		$search_term = '%' . $this->db->esc_like( $query ) . '%';

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table}
                 WHERE occurrence_id = %d
                   AND status = 'confirmed'
                   AND (name LIKE %s OR email LIKE %s)
                 ORDER BY name ASC
                 LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id,
				$search_term,
				$search_term
			)
		);

		return array_map( array( Attendee::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Sum confirmed guest quantities per event across all its occurrences.
	 *
	 * Batched aggregate for the admin Events list "Tickets Sold" column: one
	 * query for a whole page of events. Counts guests (SUM of party quantity)
	 * from confirmed attendee rows, which is the source of truth for both
	 * paid sales (one ticket row per seat, so guest sum equals ticket rows)
	 * and free RSVPs (one ticket row per party, but quantity carries the
	 * party size). Counting attendee quantity rather than ticket rows keeps
	 * the column correct for both, and for legacy-migrated rows that have no
	 * ticket rows at all.
	 *
	 * @since 1.1.1
	 *
	 * @param array<int> $event_ids Event IDs to aggregate.
	 * @return array<int, int> Map of event_id => confirmed guest count (events with none omitted).
	 */
	public function confirmed_guest_counts_for_events( array $event_ids ): array {
		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );

		if ( empty( $event_ids ) ) {
			return array();
		}

		$occurrences_table = Schema::table( 'occurrences' );
		$placeholders      = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT o.event_id AS event_id, COALESCE( SUM( a.quantity ), 0 ) AS guests
				FROM {$this->table} a
				INNER JOIN {$occurrences_table} o ON o.id = a.occurrence_id
				WHERE o.event_id IN ({$placeholders})
					AND a.status = %s
				GROUP BY o.event_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from trusted constants; event IDs bound via prepare(); status from typed enum.
				array_merge( $event_ids, array( AttendeeStatus::CONFIRMED->value ) )
			)
		);

		$counts = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$counts[ (int) $row->event_id ] = (int) $row->guests;
		}

		return $counts;
	}

	/**
	 * Sum confirmed guest quantities per occurrence.
	 *
	 * Date-grain sibling of {@see self::confirmed_guest_counts_for_events()},
	 * for screens that break a series out into its individual dates. Counts
	 * guests (SUM of party quantity) rather than ticket rows for the same
	 * reason: a free RSVP carries its party size in the quantity of a single
	 * row, and legacy-migrated rows have no ticket rows at all.
	 *
	 * @since 1.4.8
	 *
	 * @param array<int> $occurrence_ids Occurrence IDs to aggregate.
	 * @return array<int, int> Map of occurrence_id => confirmed guest count (occurrences with none omitted).
	 */
	public function confirmed_guest_counts_for_occurrences( array $occurrence_ids ): array {
		$occurrence_ids = array_unique( array_filter( array_map( 'absint', $occurrence_ids ) ) );

		if ( empty( $occurrence_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $occurrence_ids ), '%d' ) );

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT a.occurrence_id AS occurrence_id, COALESCE( SUM( a.quantity ), 0 ) AS guests
				FROM {$this->table} a
				WHERE a.occurrence_id IN ({$placeholders})
					AND a.status = %s
				GROUP BY a.occurrence_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant; occurrence IDs bound via prepare(); status from typed enum.
				array_merge( $occurrence_ids, array( AttendeeStatus::CONFIRMED->value ) )
			)
		);

		$counts = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$counts[ (int) $row->occurrence_id ] = (int) $row->guests;
		}

		return $counts;
	}

	/**
	 * Count attendees for an occurrence.
	 *
	 * @param int         $occurrence_id Occurrence ID.
	 * @param string|null $status        Optional status filter.
	 * @return int
	 */
	public function count_for_occurrence( int $occurrence_id, ?string $status = 'confirmed' ): int {
		// Cache the default confirmed-status count (most common query).
		$use_cache = ( 'confirmed' === $status );
		$cache_key = 'attendee_count_' . $occurrence_id;

		if ( $use_cache ) {
			$cached = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );
			if ( false !== $cached ) {
				return (int) $cached;
			}
		}

		$sql    = "SELECT COALESCE(SUM(quantity), 0) FROM {$this->table} WHERE occurrence_id = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
		$values = array( $occurrence_id );

		if ( null !== $status ) {
			$sql     .= ' AND status = %s';
			$values[] = $status;
		}

		$count = (int) $this->db->get_var( $this->db->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().

		if ( $use_cache ) {
			wp_cache_set( $cache_key, $count, CacheManager::CACHE_GROUP, CacheManager::TTL_ATTENDEE_STATS );
		}

		return $count;
	}

	/**
	 * Update attendee status.
	 *
	 * @param int    $id     Attendee ID.
	 * @param string $status New status.
	 * @return bool True on success.
	 */
	public function update_status( int $id, string $status ): bool {
		if ( ! in_array( $status, Attendee::STATUSES, true ) ) {
			return false;
		}

		$result = $this->db->update(
			$this->table,
			array( 'status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete attendees for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Number of attendees deleted.
	 */
	public function delete_for_occurrence( int $occurrence_id ): int {
		$result = $this->db->delete(
			$this->table,
			array( 'occurrence_id' => $occurrence_id ),
			array( '%d' )
		);

		$this->invalidate_occurrence_caches( $occurrence_id );

		return false !== $result ? $result : 0;
	}

	/**
	 * Check if email is already registered for occurrence.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $email         Email address.
	 * @param int    $exclude_id    Attendee ID to exclude (for updates).
	 * @return bool True if already registered.
	 */
	public function email_exists_for_occurrence( int $occurrence_id, string $email, int $exclude_id = 0 ): bool {
		$sql    = "SELECT COUNT(*) FROM {$this->table}
                WHERE occurrence_id = %d AND email = %s AND status NOT IN ( 'cancelled', 'refunded', 'voided' )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
		$values = array( $occurrence_id, $email );

		if ( $exclude_id > 0 ) {
			$sql     .= ' AND id != %d';
			$values[] = $exclude_id;
		}

		return (int) $this->db->get_var( $this->db->prepare( $sql, $values ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().
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
	 * Invalidate the cache for a specific attendee.
	 *
	 * @param int $id Attendee ID.
	 * @return void
	 */
	public function invalidate_attendee_cache( int $id ): void {
		$this->forget( $id );
		wp_cache_delete( 'attendee_' . $id, CacheManager::CACHE_GROUP );
	}
}
