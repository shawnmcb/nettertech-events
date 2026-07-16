<?php
/**
 * Capacity Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CapacityCalculatorInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\ReservationManagerInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Enums\CapacityType;

/**
 * Service for managing ticket capacity.
 *
 * This is the main orchestration service for capacity operations.
 * Delegates to CapacityCalculator for calculations and
 * ReservationManager for pending reservation handling.
 *
 * @since 0.8.0
 * @api
 */
class CapacityService implements CapacityServiceInterface {

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Capacity calculator.
	 *
	 * @var CapacityCalculatorInterface
	 */
	private CapacityCalculatorInterface $calculator;

	/**
	 * Reservation manager.
	 *
	 * @var ReservationManagerInterface
	 */
	private ReservationManagerInterface $reservation_manager;

	/**
	 * Constructor.
	 *
	 * @param TicketTypeRepositoryInterface $ticket_type_repo    Ticket type repository.
	 * @param CapacityCalculatorInterface   $calculator          Capacity calculator.
	 * @param ReservationManagerInterface   $reservation_manager Reservation manager.
	 */
	public function __construct(
		TicketTypeRepositoryInterface $ticket_type_repo,
		CapacityCalculatorInterface $calculator,
		ReservationManagerInterface $reservation_manager
	) {
		$this->ticket_type_repo    = $ticket_type_repo;
		$this->calculator          = $calculator;
		$this->reservation_manager = $reservation_manager;
	}

	/**
	 * Reserve capacity for a ticket type.
	 *
	 * Called when an order is completed/paid. Updates sold_count and
	 * syncs stock status atomically.
	 *
	 * @since 0.8.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity to reserve.
	 * @return bool True on success.
	 */
	public function reserve_capacity( int $ticket_type_id, int $quantity ): bool {
		if ( $quantity <= 0 ) {
			return false;
		}

		$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );

		if ( ! $ticket_type ) {
			return false;
		}

		// Clear any pending reservation for this since it's now confirmed.
		$this->reservation_manager->clear_pending( $ticket_type_id );

		// Delegate to repository for atomic update.
		$result = $this->ticket_type_repo->increment_sold_count( $ticket_type_id, $quantity );

		if ( $result ) {
			/**
			 * Fires when capacity is reserved for a ticket type.
			 *
			 * @param int $ticket_type_id Ticket type ID.
			 * @param int $quantity       Quantity reserved.
			 */
			do_action( 'nettertech_events_capacity_reserved', $ticket_type_id, $quantity );
		}

		return $result;
	}

	/**
	 * Release capacity for a ticket type.
	 *
	 * Called when an order is cancelled or refunded.
	 *
	 * @since 0.8.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity to release.
	 * @return bool True on success.
	 */
	public function release_capacity( int $ticket_type_id, int $quantity ): bool {
		if ( $quantity <= 0 ) {
			return false;
		}

		$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );

		if ( ! $ticket_type ) {
			return false;
		}

		// Delegate to repository for atomic update.
		$result = $this->ticket_type_repo->decrement_sold_count( $ticket_type_id, $quantity );

		if ( $result ) {
			/**
			 * Fires when capacity is released for a ticket type.
			 *
			 * @param int $ticket_type_id Ticket type ID.
			 * @param int $quantity       Quantity released.
			 */
			do_action( 'nettertech_events_capacity_released', $ticket_type_id, $quantity );
		}

		return $result;
	}

	/**
	 * Check if capacity is available.
	 *
	 * Takes into account buffer stock and pending reservations.
	 *
	 * @since 0.8.0
	 *
	 * @param int  $ticket_type_id Ticket type ID.
	 * @param int  $quantity       Quantity to check.
	 * @param bool $include_pending Whether to count pending reservations.
	 * @return bool True if available.
	 */
	public function has_availability( int $ticket_type_id, int $quantity = 1, bool $include_pending = true ): bool {
		$ticket_type   = $this->ticket_type_repo->find( $ticket_type_id );
		$capacity_type = $ticket_type ? CapacityType::tryFrom( $ticket_type->capacity_type ) : null;

		/**
		 * Filters the availability check result for a ticket type.
		 *
		 * Return a non-null boolean to short-circuit the default calculation.
		 * Used by add-on plugins (e.g., nettertech-events-seating) to delegate
		 * availability checks for custom capacity types.
		 *
		 * @since 1.0.2
		 *
		 * @param bool|null          $override       null to use default, bool to override.
		 * @param int                $ticket_type_id Ticket type ID.
		 * @param int                $quantity       Quantity to check.
		 * @param CapacityType|null  $capacity_type  The ticket type's capacity type.
		 */
		$override = apply_filters( 'nettertech_events_capacity_check', null, $ticket_type_id, $quantity, $capacity_type );

		if ( null !== $override ) {
			return (bool) $override;
		}

		$available = $this->calculator->get_available_count( $ticket_type_id, $include_pending );

		if ( null === $available ) {
			return true; // Unlimited capacity.
		}

		return $available >= $quantity;
	}

	// =========================================================================
	// Capacity Calculation Delegation
	// =========================================================================

	/**
	 * Get available capacity count.
	 *
	 * @since 0.8.0
	 *
	 * @param int  $ticket_type_id  Ticket type ID.
	 * @param bool $include_pending Whether to subtract pending reservations.
	 * @param bool $skip_cache      Whether to bypass cache.
	 * @return int|null Available count, or null if unlimited.
	 */
	public function get_available_count( int $ticket_type_id, bool $include_pending = true, bool $skip_cache = false ): ?int {
		return $this->calculator->get_available_count( $ticket_type_id, $include_pending, $skip_cache );
	}

	/**
	 * Get total capacity for a ticket type.
	 *
	 * @since 0.8.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int|null Total capacity, or null if unlimited.
	 */
	public function get_total_capacity( int $ticket_type_id ): ?int {
		return $this->calculator->get_total_capacity( $ticket_type_id );
	}

	/**
	 * Get sold count for a ticket type.
	 *
	 * @since 0.8.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Sold count.
	 */
	public function get_sold_count( int $ticket_type_id ): int {
		return $this->calculator->get_sold_count( $ticket_type_id );
	}

	/**
	 * Get buffer stock for a ticket type.
	 *
	 * @since 0.8.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Buffer stock amount.
	 */
	public function get_buffer_stock( int $ticket_type_id ): int {
		return $this->calculator->get_buffer_stock( $ticket_type_id );
	}

	/**
	 * Set buffer stock for a ticket type.
	 *
	 * @since 0.8.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $amount         Buffer stock amount.
	 * @return bool True on success.
	 */
	public function set_buffer_stock( int $ticket_type_id, int $amount ): bool {
		return $this->calculator->set_buffer_stock( $ticket_type_id, $amount );
	}

	/**
	 * Get capacity summary for a ticket type.
	 *
	 * @since 0.8.0
	 *
	 * @param int  $ticket_type_id Ticket type ID.
	 * @param bool $skip_cache     Whether to bypass cache.
	 * @return array{capacity: ?int, sold: int, available: ?int, buffer: int, pending: int, effective_available: ?int, is_unlimited: bool, is_sold_out: bool, is_low_stock: bool} Capacity summary.
	 */
	public function get_capacity_summary( int $ticket_type_id, bool $skip_cache = false ): array {
		return $this->calculator->get_capacity_summary( $ticket_type_id, $skip_cache );
	}

	/**
	 * Get total capacity for an occurrence across all ticket types.
	 *
	 * @since 0.8.0
	 *
	 * @param int  $occurrence_id Occurrence ID.
	 * @param bool $skip_cache    Whether to bypass cache.
	 * @return array{total_capacity: ?int, total_sold: int, total_available: ?int, has_unlimited: bool, ticket_types: array<int, array<string, mixed>>} Occurrence capacity data.
	 */
	public function get_occurrence_capacity( int $occurrence_id, bool $skip_cache = false ): array {
		return $this->calculator->get_occurrence_capacity( $occurrence_id, $skip_cache );
	}

	/**
	 * Get effective capacity for a series pass.
	 *
	 * @since 0.8.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return array{total: ?int, per_occurrence: ?int, occurrence_count: int} Series pass capacity data.
	 */
	public function get_series_pass_capacity( int $ticket_type_id ): array {
		return $this->calculator->get_series_pass_capacity( $ticket_type_id );
	}

	/**
	 * Check if series pass capacity allows attendance at an occurrence.
	 *
	 * @since 0.8.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $occurrence_id  Occurrence ID.
	 * @param int $quantity       Quantity to check.
	 * @return bool True if available.
	 */
	public function series_pass_has_occurrence_availability(
		int $ticket_type_id,
		int $occurrence_id,
		int $quantity = 1
	): bool {
		$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );

		if ( ! $ticket_type ) {
			return false;
		}

		// For series passes, check overall capacity (not per-occurrence).
		return $this->has_availability( $ticket_type_id, $quantity );
	}

	// =========================================================================
	// Pending Reservation Delegation
	// =========================================================================

	/**
	 * Get the configured hold time for pending reservations.
	 *
	 * @since 0.9.0
	 *
	 * @return int Hold time in seconds.
	 */
	public function get_hold_time(): int {
		return $this->reservation_manager->get_hold_time();
	}

	/**
	 * Create a pending reservation.
	 *
	 * Used to hold capacity while a customer is in checkout.
	 * Checks availability before creating the reservation.
	 *
	 * @since 0.8.0
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param int    $quantity       Quantity to hold.
	 * @param string $session_key    Session or cart key for identification.
	 * @param int    $hold_time      Hold time in seconds (0 = use settings).
	 * @return bool True on success.
	 */
	public function create_pending_reservation(
		int $ticket_type_id,
		int $quantity,
		string $session_key,
		int $hold_time = 0
	): bool {
		if ( $quantity <= 0 ) {
			return false;
		}

		// Check if capacity is available (excluding our own pending).
		$current_pending = $this->reservation_manager->get_pending( $ticket_type_id, $session_key );
		$net_quantity    = $quantity - $current_pending;

		if ( $net_quantity > 0 && ! $this->has_availability( $ticket_type_id, $net_quantity, true ) ) {
			return false;
		}

		return $this->reservation_manager->create_pending( $ticket_type_id, $quantity, $session_key, $hold_time );
	}

	/**
	 * Clear a pending reservation.
	 *
	 * @since 0.8.0
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $session_key    Optional session key. Clears all if not provided.
	 * @return bool True on success.
	 */
	public function clear_pending_reservation( int $ticket_type_id, string $session_key = '' ): bool {
		return $this->reservation_manager->clear_pending( $ticket_type_id, $session_key );
	}

	/**
	 * Get pending reservation for a session.
	 *
	 * @since 0.8.0
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $session_key    Session key.
	 * @return int Quantity held.
	 */
	public function get_pending_reservation( int $ticket_type_id, string $session_key ): int {
		return $this->reservation_manager->get_pending( $ticket_type_id, $session_key );
	}

	/**
	 * Get total pending count for a ticket type.
	 *
	 * @since 0.8.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Total pending quantity.
	 */
	public function get_pending_count( int $ticket_type_id ): int {
		return $this->reservation_manager->get_pending_count( $ticket_type_id );
	}
}
