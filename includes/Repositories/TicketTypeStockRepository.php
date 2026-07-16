<?php
/**
 * Ticket type stock/inventory management repository.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\TicketTypeStockRepositoryInterface;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Services\Capacity\HouseRule;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Handles ticket type stock management and shared capacity operations.
 *
 * Extracted from TicketTypeRepository to reduce god class size.
 * Contains all transactional stock operations and capacity calculations.
 *
 * @since 0.9.5
 */
class TicketTypeStockRepository implements TicketTypeStockRepositoryInterface {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Ticket types table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Attendees table name (for reconciliation).
	 *
	 * @var string
	 */
	private string $attendees_table;

	/**
	 * Occurrences table name (for house ceilings).
	 *
	 * @var string
	 */
	private string $occurrences_table;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database instance.
	 */
	public function __construct( \wpdb $db ) {
		$this->db                = $db;
		$this->table             = Schema::table( 'ticket_types' );
		$this->attendees_table   = Schema::table( 'attendees' );
		$this->occurrences_table = Schema::table( 'occurrences' );
	}

	// =========================================================================
	// Stock Status Methods
	// =========================================================================

	/**
	 * Get sold count for a ticket type.
	 *
	 * Uses denormalized sold_count column (no attendee query needed).
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Number of tickets sold.
	 */
	public function get_sold_count( int $ticket_type_id ): int {
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT sold_count FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$ticket_type_id
			)
		);
	}

	/**
	 * Get available count for a ticket type.
	 *
	 * Uses denormalized sold_count column (single query).
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int|null Available count (null = unlimited).
	 */
	public function get_available_count( int $ticket_type_id ): ?int {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT capacity, sold_count FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$ticket_type_id
			)
		);

		if ( ! $row || null === $row->capacity ) {
			return null; // Unlimited.
		}

		return max( 0, (int) $row->capacity - (int) $row->sold_count );
	}

	/**
	 * Check if a ticket type has availability.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity to check.
	 * @return bool True if available.
	 */
	public function has_availability( int $ticket_type_id, int $quantity = 1 ): bool {
		$available = $this->get_available_count( $ticket_type_id );

		if ( null === $available ) {
			return true; // Unlimited.
		}

		return $available >= $quantity;
	}

	/**
	 * Check if a ticket type is sold out.
	 *
	 * Uses stock_status column (no computation needed).
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return bool True if sold out.
	 */
	public function is_sold_out( int $ticket_type_id ): bool {
		$status = $this->db->get_var(
			$this->db->prepare(
				"SELECT stock_status FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$ticket_type_id
			)
		);

		return 'out_of_stock' === $status;
	}

	// =========================================================================
	// Transactional Stock Operations
	// =========================================================================

	/**
	 * Increment sold count and update stock status.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity sold.
	 * @return bool True on success.
	 */
	public function increment_sold_count( int $ticket_type_id, int $quantity = 1 ): bool {
		// Use transaction with row-level locking and capacity check to prevent overselling.
		$this->db->query( 'START TRANSACTION' );

		try {
			$house = $this->lock_house( $ticket_type_id );

			if ( null === $house ) {
				$this->db->query( 'ROLLBACK' );
				return false;
			}

			// The tier's own limit, where it has one.
			$own_remaining = HouseRule::own_remaining(
				$house['own_capacity_type'],
				$house['own_capacity'],
				$house['own_sold']
			);

			if ( null !== $own_remaining && $quantity > $own_remaining ) {
				$this->db->query( 'ROLLBACK' );
				return false; // Would exceed the tier's own capacity.
			}

			// The room the tier is sold into. Every tier sharing it draws from the
			// same seats, so a tier well inside its own limit can still find the
			// house full. Both rows are locked above, so this holds under concurrency.
			if ( null !== $house['house'] && $house['house_sold'] + $quantity > $house['house'] ) {
				$this->db->query( 'ROLLBACK' );
				return false; // Would oversell the house.
			}

			// Atomic update: increment sold_count and recalculate stock_status.
			$sql = $this->db->prepare(
				"UPDATE {$this->table}
                     SET sold_count = sold_count + %d,
                         stock_status = CASE
                             WHEN capacity IS NULL THEN 'in_stock'
                             WHEN sold_count + %d >= capacity THEN 'out_of_stock'
                             WHEN capacity > 0 AND sold_count + %d >= capacity * 0.9 THEN 'low_stock'
                             ELSE 'in_stock'
                         END
                     WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$quantity,
				$quantity,
				$quantity,
				$ticket_type_id
			);
			if ( null === $sql ) {
				$this->db->query( 'ROLLBACK' );
				return false;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
			$result = $this->db->query( $sql );

			$this->db->query( 'COMMIT' );
			return false !== $result;
		} catch ( \Exception $e ) {
			$this->db->query( 'ROLLBACK' );
			DebugLogger::exception( $e, 'StockReservation' );
			return false;
		}
	}

	/**
	 * Lock every tier sharing a room with this one, and report the room's state.
	 *
	 * Rows are locked in a single statement ordered by primary key, so two sales
	 * racing on different tiers of the same house always take their locks in the
	 * same order and cannot deadlock each other. The lock is what makes the
	 * caller's house check safe: without it, two sales could each read 1 seat
	 * remaining and both commit.
	 *
	 * Must be called inside a transaction.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return array{house: ?int, house_sold: int, own_capacity: ?int, own_capacity_type: string, own_sold: int}|null Null when the ticket type does not exist.
	 */
	private function lock_house( int $ticket_type_id ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table has no WP_Query equivalent; direct access required.
		$tier = $this->db->get_row(
			$this->db->prepare(
				"SELECT id, occurrence_id, event_id FROM {$this->table} WHERE id = %d",
				$ticket_type_id
			)
		);

		if ( ! $tier ) {
			return null;
		}

		if ( null !== $tier->occurrence_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table has no WP_Query equivalent; direct access required.
			$peers = $this->db->get_results(
				$this->db->prepare(
					"SELECT id, capacity, capacity_type, sold_count, status FROM {$this->table}
					 WHERE occurrence_id = %d ORDER BY id ASC FOR UPDATE",
					(int) $tier->occurrence_id
				)
			);
		} elseif ( null !== $tier->event_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table has no WP_Query equivalent; direct access required.
			$peers = $this->db->get_results(
				$this->db->prepare(
					"SELECT id, capacity, capacity_type, sold_count, status FROM {$this->table}
					 WHERE event_id = %d AND occurrence_id IS NULL ORDER BY id ASC FOR UPDATE",
					(int) $tier->event_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table has no WP_Query equivalent; direct access required.
			$peers = $this->db->get_results(
				$this->db->prepare(
					"SELECT id, capacity, capacity_type, sold_count, status FROM {$this->table}
					 WHERE id = %d FOR UPDATE",
					$ticket_type_id
				)
			);
		}

		if ( ! $peers ) {
			return null;
		}

		$ceiling = null;
		if ( null !== $tier->occurrence_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table has no WP_Query equivalent; direct access required.
			$raw = $this->db->get_var(
				$this->db->prepare(
					"SELECT capacity FROM {$this->occurrences_table} WHERE id = %d",
					(int) $tier->occurrence_id
				)
			);

			$ceiling = null !== $raw ? (int) $raw : null;
		}

		$tiers      = array();
		$house_sold = 0;
		$own        = null;

		foreach ( $peers as $peer ) {
			$house_sold += (int) $peer->sold_count;

			if ( (int) $peer->id === $ticket_type_id ) {
				$own = $peer;
			}

			// A retired tier keeps the seats it sold but can no longer size the room.
			if ( 'active' === (string) $peer->status ) {
				$tiers[] = array(
					'capacity'      => null !== $peer->capacity ? (int) $peer->capacity : null,
					'capacity_type' => (string) $peer->capacity_type,
				);
			}
		}

		if ( null === $own ) {
			return null;
		}

		return array(
			'house'             => HouseRule::house( $tiers, $ceiling ),
			'house_sold'        => $house_sold,
			'own_capacity'      => null !== $own->capacity ? (int) $own->capacity : null,
			'own_capacity_type' => (string) $own->capacity_type,
			'own_sold'          => (int) $own->sold_count,
		);
	}

	/**
	 * Decrement sold count and update stock status.
	 *
	 * Uses row-level locking within a transaction to prevent race conditions
	 * during concurrent refund operations.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity to release.
	 * @return bool True on success.
	 */
	public function decrement_sold_count( int $ticket_type_id, int $quantity = 1 ): bool {
		// Use transaction with row-level locking for thread safety.
		$this->db->query( 'START TRANSACTION' );

		try {
			// Lock the row for update to prevent concurrent modifications.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table has no WP_Query equivalent; direct access required.
			$this->db->get_row(
				$this->db->prepare(
					"SELECT id FROM {$this->table} WHERE id = %d FOR UPDATE",
					$ticket_type_id
				)
			);

			// Atomic update: decrement sold_count (not below 0) and recalculate stock_status.
			$sql = $this->db->prepare(
				"UPDATE {$this->table}
                     SET sold_count = GREATEST(0, sold_count - %d),
                         stock_status = CASE
                             WHEN capacity IS NULL THEN 'in_stock'
                             WHEN GREATEST(0, sold_count - %d) >= capacity THEN 'out_of_stock'
                             WHEN capacity > 0 AND GREATEST(0, sold_count - %d) >= capacity * 0.9 THEN 'low_stock'
                             ELSE 'in_stock'
                         END
                     WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$quantity,
				$quantity,
				$quantity,
				$ticket_type_id
			);
			if ( null === $sql ) {
				$this->db->query( 'ROLLBACK' );
				return false;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
			$result = $this->db->query( $sql );

			$this->db->query( 'COMMIT' );
			return false !== $result;
		} catch ( \Exception $e ) {
			$this->db->query( 'ROLLBACK' );
			DebugLogger::exception( $e, 'StockRelease' );
			return false;
		}
	}

	/**
	 * Recalculate sold_count from attendee data.
	 *
	 * Use this for reconciliation if counts get out of sync.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return bool True on success.
	 */
	public function recalculate_sold_count( int $ticket_type_id ): bool {
		$sql = $this->db->prepare(
			"UPDATE {$this->table} tt
                 SET sold_count = COALESCE(
                     (SELECT SUM(a.quantity)
                      FROM {$this->attendees_table} a
                      WHERE a.ticket_type_id = tt.id AND a.status = 'confirmed'),
                     0
                 ),
                 stock_status = CASE
                     WHEN capacity IS NULL THEN 'in_stock'
                     WHEN COALESCE(
                         (SELECT SUM(a.quantity)
                          FROM {$this->attendees_table} a
                          WHERE a.ticket_type_id = tt.id AND a.status = 'confirmed'),
                         0
                     ) >= capacity THEN 'out_of_stock'
                     WHEN capacity > 0 AND COALESCE(
                         (SELECT SUM(a.quantity)
                          FROM {$this->attendees_table} a
                          WHERE a.ticket_type_id = tt.id AND a.status = 'confirmed'),
                         0
                     ) >= capacity * 0.9 THEN 'low_stock'
                     ELSE 'in_stock'
                 END
                 WHERE tt.id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			$ticket_type_id
		);
		if ( null === $sql ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$result = $this->db->query( $sql );

		return false !== $result;
	}

	// =========================================================================
	// Shared Capacity Methods
	// =========================================================================

	/**
	 * Get sum of fixed capacity allocations for an occurrence.
	 *
	 * Sums the capacity column for all ticket types with capacity_type='fixed'
	 * and a non-null capacity value for the given occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Total fixed capacity allocated (0 if none).
	 */
	public function get_fixed_capacity_sum( int $occurrence_id ): int {
		$sum = $this->db->get_var(
			$this->db->prepare(
				"SELECT COALESCE(SUM(capacity), 0) FROM {$this->table}
				 WHERE occurrence_id = %d
				   AND capacity_type = %s
				   AND capacity IS NOT NULL
				   AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id,
				CapacityType::FIXED->value
			)
		);

		return (int) $sum;
	}

	/**
	 * Get sum of sold_count for shared ticket types on an occurrence.
	 *
	 * Sums the sold_count for all ticket types with capacity_type='shared'
	 * for the given occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Total sold count across shared ticket types (0 if none).
	 */
	public function get_shared_sold_count( int $occurrence_id ): int {
		$sum = $this->db->get_var(
			$this->db->prepare(
				"SELECT COALESCE(SUM(sold_count), 0) FROM {$this->table}
				 WHERE occurrence_id = %d
				   AND capacity_type = %s
				   AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id,
				CapacityType::SHARED->value
			)
		);

		return (int) $sum;
	}

	/**
	 * Get ticket types with shared capacity for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<\NetterTechEvents\Models\TicketType>
	 */
	public function get_shared_for_occurrence( int $occurrence_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table}
				 WHERE occurrence_id = %d
				   AND capacity_type = %s
				   AND status = 'active'
				 ORDER BY sort_order ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id,
				CapacityType::SHARED->value
			)
		);

		return array_map( array( \NetterTechEvents\Models\TicketType::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Check if an occurrence has any ticket types with unlimited fixed capacity.
	 *
	 * Fixed capacity with NULL capacity value is considered unlimited.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool True if any unlimited fixed ticket types exist.
	 */
	public function has_unlimited_fixed_tickets( int $occurrence_id ): bool {
		$count = (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table}
				 WHERE occurrence_id = %d
				   AND capacity_type = %s
				   AND capacity IS NULL
				   AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id,
				CapacityType::FIXED->value
			)
		);

		return $count > 0;
	}

	/**
	 * Check if an occurrence has any ticket types with explicit unlimited capacity.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool True if any unlimited capacity ticket types exist.
	 */
	public function has_unlimited_tickets( int $occurrence_id ): bool {
		$count = (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table}
				 WHERE occurrence_id = %d
				   AND capacity_type = %s
				   AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id,
				CapacityType::UNLIMITED->value
			)
		);

		return $count > 0;
	}
}
