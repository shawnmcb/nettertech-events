<?php
/**
 * Ticket repository class.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Enums\TicketStatus;
use NetterTechEvents\Models\Ticket;

/**
 * Handles Ticket persistence and retrieval.
 *
 * Manages individual ticket records with QR codes for check-in.
 *
 * @since 0.8.0
 * @api
 */
class TicketRepository implements TicketRepositoryInterface {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Tickets table name.
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
		$this->table = Schema::table( 'tickets' );
	}

	/**
	 * Find a ticket by ID.
	 *
	 * Uses persistent object cache for cross-request performance.
	 *
	 * @param int $id Ticket ID.
	 * @return Ticket|null
	 */
	public function find( int $id ): ?Ticket {
		$cache_key = 'ticket_' . $id;
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );
		if ( false !== $cached ) {
			return Ticket::from_row( $cached );
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

		wp_cache_set( $cache_key, $row, CacheManager::CACHE_GROUP, CacheManager::TTL_TICKET_TYPE );

		return Ticket::from_row( $row );
	}

	/**
	 * Find a ticket by its unique code.
	 *
	 * Uses persistent object cache keyed by sanitized code.
	 *
	 * @param string $code Ticket code.
	 * @return Ticket|null
	 */
	public function find_by_code( string $code ): ?Ticket {
		$cache_key = 'ticket_code_' . md5( $code );
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );
		if ( false !== $cached ) {
			return Ticket::from_row( $cached );
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE ticket_code = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$code
			)
		);

		if ( ! $row ) {
			return null;
		}

		wp_cache_set( $cache_key, $row, CacheManager::CACHE_GROUP, CacheManager::TTL_TICKET_TYPE );

		return Ticket::from_row( $row );
	}

	/**
	 * Find tickets by WooCommerce order ID.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array<Ticket>
	 */
	public function find_by_order( int $order_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE wc_order_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$order_id
			)
		);

		return array_map( array( Ticket::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Find tickets by attendee ID.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return array<Ticket>
	 */
	public function find_by_attendee( int $attendee_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE attendee_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$attendee_id
			)
		);

		return array_map( array( Ticket::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Find tickets for an occurrence.
	 *
	 * @param int         $occurrence_id Occurrence ID.
	 * @param string|null $status        Optional status filter.
	 * @return array<Ticket>
	 */
	public function find_by_occurrence( int $occurrence_id, ?string $status = null ): array {
		$where  = array( 'occurrence_id = %d' );
		$values = array( $occurrence_id );

		if ( null !== $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}

		$where_clause = 'WHERE ' . implode( ' AND ', $where );

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} {$where_clause} ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$values
			)
		);

		return array_map( array( Ticket::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Save a ticket (insert or update).
	 *
	 * @param Ticket $ticket Ticket model.
	 * @return Ticket Updated ticket with ID.
	 */
	public function save( Ticket $ticket ): Ticket {
		$data = array(
			'ticket_type_id'   => $ticket->ticket_type_id,
			'occurrence_id'    => $ticket->occurrence_id,
			'attendee_id'      => $ticket->attendee_id,
			'wc_order_id'      => $ticket->wc_order_id,
			'wc_order_item_id' => $ticket->wc_order_item_id,
			'ticket_code'      => $ticket->ticket_code,
			'qr_code_url'      => $ticket->qr_code_url,
			'status'           => $ticket->status,
			'checked_in_at'    => $ticket->checked_in_at,
			'price_paid'       => $ticket->price_paid,
		);

		$format = array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%f' );

		if ( $ticket->id ) {
			// Update existing.
			$this->db->update(
				$this->table,
				$data,
				array( 'id' => $ticket->id ),
				$format,
				array( '%d' )
			);

			// Invalidate caches for this ticket.
			wp_cache_delete( 'ticket_' . $ticket->id, CacheManager::CACHE_GROUP );
			if ( $ticket->ticket_code ) {
				wp_cache_delete( 'ticket_code_' . md5( $ticket->ticket_code ), CacheManager::CACHE_GROUP );
			}
		} else {
			// Insert new.
			$this->db->insert( $this->table, $data, $format );
			$ticket->id = (int) $this->db->insert_id;
		}

		return $ticket;
	}

	/**
	 * Cancel all tickets for an attendee.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return int Number of tickets cancelled.
	 */
	public function cancel_all_tickets_for_attendee( int $attendee_id ): int {
		return (int) $this->db->update(
			$this->table,
			array( 'status' => 'cancelled' ),
			array( 'attendee_id' => $attendee_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Cancel a specific number of tickets for an attendee.
	 *
	 * Cancels the most recently created tickets first.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @param int $quantity    Number of tickets to cancel.
	 * @return int Number of tickets actually cancelled.
	 */
	public function cancel_tickets_for_attendee( int $attendee_id, int $quantity ): int {
		$confirmed = TicketStatus::CONFIRMED->value;
		$cancelled = TicketStatus::CANCELLED->value;

		// Get ticket IDs to cancel (most recent first).
		$ticket_ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT id FROM {$this->table}
				WHERE attendee_id = %d AND status = '{$confirmed}'
				ORDER BY id DESC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare(); status from typed enum.
				$attendee_id,
				$quantity
			)
		);

		if ( empty( $ticket_ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) );

		$sql = $this->db->prepare(
			"UPDATE {$this->table} SET status = '{$cancelled}' WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare(); status from typed enum.
			$ticket_ids
		);
		if ( null === $sql ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		return (int) $this->db->query( $sql );
	}

	/**
	 * Check in a ticket by code.
	 *
	 * Uses atomic operation to prevent race conditions.
	 *
	 * @param string $code Ticket code.
	 * @return bool True if check-in succeeded, false if already checked in or invalid.
	 */
	public function check_in( string $code ): bool {
		$now        = current_time( 'mysql' );
		$confirmed  = TicketStatus::CONFIRMED->value;
		$checked_in = TicketStatus::CHECKED_IN->value;

		// Atomic update - only succeeds if ticket exists and not already checked in.
		$sql = $this->db->prepare(
			"UPDATE {$this->table}
			SET status = '{$checked_in}', checked_in_at = %s
			WHERE ticket_code = %s AND status = '{$confirmed}'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare(); status from typed enum.
			$now,
			$code
		);
		if ( null === $sql ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$result = $this->db->query( $sql );

		return $result > 0;
	}

	/**
	 * Delete a ticket.
	 *
	 * @param int $id Ticket ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		return (bool) $this->db->delete(
			$this->table,
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	/**
	 * Whether an event has any order-linked (paid) attendance.
	 *
	 * Returns true if any ticket OR attendee belonging to one of the event's
	 * occurrences references a WooCommerce order (`wc_order_id > 0`). Used as a
	 * pre-delete guard: deleting such an event would destroy order-linked
	 * attendance history, so the delete paths block on a true result. Free /
	 * RSVP rows (null `wc_order_id`) do not block — they cascade safely.
	 *
	 * Both tables are checked because a paid order creates both ticket and
	 * attendee rows; checking either alone could miss order-linked data.
	 *
	 * @since 1.0.4
	 *
	 * @param int $event_id Event ID.
	 * @return bool True if the event has order-linked tickets or attendees.
	 */
	public function has_paid_attendance( int $event_id ): bool {
		$occurrences_table = Schema::table( 'occurrences' );
		$attendees_table   = Schema::table( 'attendees' );

		$ticket_hit = $this->db->get_var(
			$this->db->prepare(
				"SELECT t.id
				FROM {$this->table} t
				INNER JOIN {$occurrences_table} o ON o.id = t.occurrence_id
				WHERE o.event_id = %d AND t.wc_order_id > 0
				LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from trusted constants; event ID bound via prepare().
				$event_id
			)
		);

		if ( null !== $ticket_hit ) {
			return true;
		}

		$attendee_hit = $this->db->get_var(
			$this->db->prepare(
				"SELECT a.id
				FROM {$attendees_table} a
				INNER JOIN {$occurrences_table} o ON o.id = a.occurrence_id
				WHERE o.event_id = %d AND a.wc_order_id > 0
				LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from trusted constants; event ID bound via prepare().
				$event_id
			)
		);

		return null !== $attendee_hit;
	}

	/**
	 * Find a ticket by code with attendee data.
	 *
	 * Returns ticket data joined with attendee information for scan results.
	 *
	 * @param string $code Ticket code.
	 * @return object|null Object with ticket and attendee data, or null if not found.
	 */
	public function find_by_code_with_attendee( string $code ): ?object {
		$attendees_table   = Schema::table( 'attendees' );
		$occurrences_table = Schema::table( 'occurrences' );
		$events_table      = Schema::table( 'events' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names cannot be parameterized.
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT
					t.id as ticket_id,
					t.ticket_code,
					t.status as ticket_status,
					t.checked_in_at as ticket_checked_in_at,
					t.occurrence_id,
					t.attendee_id,
					a.name as attendee_name,
					a.email as attendee_email,
					a.quantity,
					a.checked_in,
					a.checked_in_count,
					a.checked_in_at as attendee_checked_in_at,
					o.start_datetime,
					o.end_datetime,
					o.title_override as occurrence_title,
					e.title as event_title,
					e.venue_name
				FROM {$this->table} t
				LEFT JOIN {$attendees_table} a ON t.attendee_id = a.id
				LEFT JOIN {$occurrences_table} o ON t.occurrence_id = o.id
				LEFT JOIN {$events_table} e ON o.event_id = e.id
				WHERE t.ticket_code = %s
				LIMIT 1",
				$code
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Count tickets by occurrence and status.
	 *
	 * @param int         $occurrence_id Occurrence ID.
	 * @param string|null $status        Optional status filter.
	 * @return int
	 */
	public function count_for_occurrence( int $occurrence_id, ?string $status = null ): int {
		$where  = array( 'occurrence_id = %d' );
		$values = array( $occurrence_id );

		if ( null !== $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}

		$where_clause = 'WHERE ' . implode( ' AND ', $where );

		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table} {$where_clause}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$values
			)
		);
	}
}
