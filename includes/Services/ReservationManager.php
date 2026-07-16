<?php
/**
 * Reservation Manager Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\ReservationManagerInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Database\Schema;

/**
 * Manages pending ticket reservations during checkout.
 *
 * Uses atomic database operations to prevent oversell under concurrency:
 * - ticket_types.reserved column holds the aggregate pending count
 * - nettertech_events_reservations table tracks per-session detail for release and expiry
 *
 * The atomic UPDATE's WHERE clause IS the availability check - no separate
 * capacity read is needed before reserving.
 *
 * @since 0.9.0 Original transient-based implementation.
 * @since 3.4.0 Rewritten for atomic database-backed reservations.
 */
class ReservationManager implements ReservationManagerInterface {

	/**
	 * Option key for buffer stock settings.
	 */
	private const BUFFER_STOCK_OPTION = 'nettertech_events_buffer_stock';

	/**
	 * Get the configured hold time for pending reservations.
	 *
	 * @since 0.9.0
	 *
	 * @return int Hold time in seconds.
	 */
	public function get_hold_time(): int {
		return \NetterTechEvents\Core\NetterTechEventsSettings::from_option()->performance->pending_hold_time;
	}

	/**
	 * Create a pending reservation atomically.
	 *
	 * Uses a single UPDATE with a WHERE clause that checks available capacity.
	 * If affected_rows === 0, capacity was insufficient (no partial reservation).
	 *
	 * For existing session reservations (upsert), the old quantity is released
	 * before the new quantity is reserved to avoid double-counting.
	 *
	 * @since 0.9.0
	 * @since 3.4.0 Rewritten for atomic DB operations.
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param int    $quantity       Quantity to hold.
	 * @param string $session_key    Session or cart key for identification.
	 * @param int    $hold_time      Hold time in seconds (0 = use settings).
	 * @return bool True if reserved successfully, false if capacity insufficient.
	 */
	public function create_pending(
		int $ticket_type_id,
		int $quantity,
		string $session_key,
		int $hold_time = 0
	): bool {
		global $wpdb;

		if ( $quantity <= 0 ) {
			return false;
		}

		if ( 0 === $hold_time ) {
			$hold_time = $this->get_hold_time();
		}

		$ticket_types_table = Schema::table( 'ticket_types' );
		$buffer             = $this->get_buffer_stock( $ticket_type_id );
		$existing_qty       = $this->get_pending( $ticket_type_id, $session_key );
		$net_increase       = $quantity - $existing_qty;

		// If net increase is zero or negative, just update the tracking row.
		if ( $net_increase <= 0 ) {
			$result = $this->upsert_tracking_row( $ticket_type_id, $session_key, $quantity, $hold_time )
				&& $this->adjust_aggregate( $ticket_type_id, $net_increase );

			if ( $result ) {
				/** This action is documented in includes/Core/Hooks.php */
				do_action( 'nettertech_events_reservation_changed', $ticket_type_id, $net_increase, $session_key );
			}

			return $result;
		}

		// Atomic capacity check + reservation in one query.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table atomic reservation.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$ticket_types_table}
				SET reserved = reserved + %d
				WHERE id = %d
				AND capacity IS NOT NULL
				AND (capacity - sold_count - %d - reserved) >= %d",
				$net_increase,
				$ticket_type_id,
				$buffer,
				$net_increase
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( 0 === $affected || false === $affected ) {
			return false;
		}

		// Track the per-session reservation.
		$this->upsert_tracking_row( $ticket_type_id, $session_key, $quantity, $hold_time );

		/** This action is documented in includes/Core/Hooks.php */
		do_action( 'nettertech_events_reservation_changed', $ticket_type_id, $net_increase, $session_key );

		return true;
	}

	/**
	 * Clear a pending reservation.
	 *
	 * Releases the held capacity atomically.
	 *
	 * @since 0.9.0
	 * @since 3.4.0 Rewritten for atomic DB operations.
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $session_key    Optional session key. Clears all if empty.
	 * @return bool True on success.
	 */
	public function clear_pending( int $ticket_type_id, string $session_key = '' ): bool {
		global $wpdb;

		$reservations_table = Schema::table( 'reservations' );

		if ( '' === $session_key ) {
			$total = $this->get_pending_count( $ticket_type_id );
			if ( $total > 0 ) {
				$this->adjust_aggregate( $ticket_type_id, -$total );
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$reservations_table} WHERE ticket_type_id = %d", $ticket_type_id ) );

			if ( $total > 0 ) {
				/** This action is documented in includes/Core/Hooks.php */
				do_action( 'nettertech_events_reservation_changed', $ticket_type_id, -$total, '' );
			}

			return true;
		}

		$quantity = $this->get_pending( $ticket_type_id, $session_key );

		if ( $quantity <= 0 ) {
			return true;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$reservations_table} WHERE ticket_type_id = %d AND session_key = %s",
				$ticket_type_id,
				$session_key
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$this->adjust_aggregate( $ticket_type_id, -$quantity );

		/** This action is documented in includes/Core/Hooks.php */
		do_action( 'nettertech_events_reservation_changed', $ticket_type_id, -$quantity, $session_key );

		return true;
	}

	/**
	 * Get pending reservation for a session.
	 *
	 * @since 0.9.0
	 * @since 3.4.0 Rewritten for DB storage.
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $session_key    Session key.
	 * @return int Quantity held.
	 */
	public function get_pending( int $ticket_type_id, string $session_key ): int {
		global $wpdb;

		$reservations_table = Schema::table( 'reservations' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table.
		$quantity = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT quantity FROM {$reservations_table}
				WHERE ticket_type_id = %d AND session_key = %s AND expires_at > %s",
				$ticket_type_id,
				$session_key,
				current_time( 'mysql', true )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $quantity ? (int) $quantity : 0;
	}

	/**
	 * Get total pending count for a ticket type.
	 *
	 * Reads from the aggregate reserved column for performance.
	 *
	 * @since 0.9.0
	 * @since 3.4.0 Reads from ticket_types.reserved column.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Total pending quantity.
	 */
	public function get_pending_count( int $ticket_type_id ): int {
		global $wpdb;

		$ticket_types_table = Schema::table( 'ticket_types' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table.
		$reserved = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT reserved FROM {$ticket_types_table} WHERE id = %d",
				$ticket_type_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $reserved ? (int) $reserved : 0;
	}

	/**
	 * Remove expired reservations and release their capacity.
	 *
	 * Called by cron to clean up stale reservations from abandoned carts.
	 *
	 * @since 3.4.0
	 *
	 * @return int Number of expired reservations cleaned up.
	 */
	public function sweep_expired(): int {
		global $wpdb;

		$reservations_table = Schema::table( 'reservations' );
		$ticket_types_table = Schema::table( 'ticket_types' );
		$now                = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names cannot be parameterized; custom tables.
		$expired = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ticket_type_id, SUM(quantity) AS total_qty
				FROM {$reservations_table}
				WHERE expires_at <= %s
				GROUP BY ticket_type_id",
				$now
			)
		);

		if ( empty( $expired ) ) {
			return 0;
		}

		$cleaned = 0;

		foreach ( $expired as $row ) {
			$ticket_type_id = (int) $row->ticket_type_id;
			$total_qty      = (int) $row->total_qty;

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$ticket_types_table}
					SET reserved = GREATEST(0, CAST(reserved AS SIGNED) - %d)
					WHERE id = %d",
					$total_qty,
					$ticket_type_id
				)
			);

			/** This action is documented in includes/Core/Hooks.php */
			do_action( 'nettertech_events_reservation_changed', $ticket_type_id, -$total_qty, '' );

			++$cleaned;
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$reservations_table} WHERE expires_at <= %s",
				$now
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $cleaned;
	}

	/**
	 * Insert or update a per-session tracking row.
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $session_key    Session key.
	 * @param int    $quantity       Quantity to track.
	 * @param int    $hold_time      Hold time in seconds.
	 * @return bool True on success.
	 */
	private function upsert_tracking_row(
		int $ticket_type_id,
		string $session_key,
		int $quantity,
		int $hold_time
	): bool {
		global $wpdb;

		$reservations_table = Schema::table( 'reservations' );
		$expires_at         = gmdate( 'Y-m-d H:i:s', time() + $hold_time );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$reservations_table} (ticket_type_id, session_key, quantity, expires_at)
				VALUES (%d, %s, %d, %s)
				ON DUPLICATE KEY UPDATE quantity = %d, expires_at = %s",
				$ticket_type_id,
				$session_key,
				$quantity,
				$expires_at,
				$quantity,
				$expires_at
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return false !== $result;
	}

	/**
	 * Adjust the aggregate reserved count on ticket_types.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $delta          Amount to add (negative to subtract).
	 * @return bool True on success.
	 */
	private function adjust_aggregate( int $ticket_type_id, int $delta ): bool {
		global $wpdb;

		if ( 0 === $delta ) {
			return true;
		}

		$ticket_types_table = Schema::table( 'ticket_types' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterized; custom table.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$ticket_types_table}
				SET reserved = GREATEST(0, CAST(reserved AS SIGNED) + %d)
				WHERE id = %d",
				$delta,
				$ticket_type_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return false !== $result;
	}

	/**
	 * Get buffer stock for a ticket type.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Buffer stock amount.
	 */
	private function get_buffer_stock( int $ticket_type_id ): int {
		$buffers = get_option( self::BUFFER_STOCK_OPTION, array() );

		return isset( $buffers[ $ticket_type_id ] ) ? (int) $buffers[ $ticket_type_id ] : 0;
	}
}
