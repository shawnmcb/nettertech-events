<?php
/**
 * Capacity Calculator Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CapacityCalculatorInterface;
use NetterTechEvents\Contracts\HouseCapacityRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\ReservationManagerInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Services\Capacity\HouseRule;
use NetterTechEvents\Enums\TicketTypeScope;

/**
 * Handles capacity calculations for ticket types and occurrences.
 *
 * This class provides pure calculation methods for capacity data.
 * It does NOT handle reservations or modify capacity - that's the
 * responsibility of CapacityService.
 *
 * @since 0.9.0
 */
class CapacityCalculator implements CapacityCalculatorInterface {

	/**
	 * Option key for buffer stock settings.
	 */
	private const BUFFER_STOCK_OPTION = 'nettertech_events_buffer_stock';

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Reservation manager for pending counts.
	 *
	 * @var ReservationManagerInterface
	 */
	private ReservationManagerInterface $reservation_manager;

	/**
	 * House capacity repository.
	 *
	 * @var HouseCapacityRepositoryInterface
	 */
	private HouseCapacityRepositoryInterface $house_repo;

	/**
	 * Constructor.
	 *
	 * @param TicketTypeRepositoryInterface    $ticket_type_repo    Ticket type repository.
	 * @param OccurrenceRepositoryInterface    $occurrence_repo     Occurrence repository.
	 * @param ReservationManagerInterface      $reservation_manager Reservation manager.
	 * @param HouseCapacityRepositoryInterface $house_repo          House capacity repository.
	 */
	public function __construct(
		TicketTypeRepositoryInterface $ticket_type_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		ReservationManagerInterface $reservation_manager,
		HouseCapacityRepositoryInterface $house_repo
	) {
		$this->ticket_type_repo    = $ticket_type_repo;
		$this->occurrence_repo     = $occurrence_repo;
		$this->reservation_manager = $reservation_manager;
		$this->house_repo          = $house_repo;
	}

	/**
	 * Get available capacity count.
	 *
	 * Returns null for unlimited capacity.
	 *
	 * @since 0.9.0
	 *
	 * @param int  $ticket_type_id  Ticket type ID.
	 * @param bool $include_pending Whether to subtract pending reservations.
	 * @param bool $skip_cache      Whether to bypass cache (use for checkout validation).
	 * @return int|null Available count, or null if unlimited.
	 */
	public function get_available_count( int $ticket_type_id, bool $include_pending = true, bool $skip_cache = false ): ?int {
		// Check for external override (e.g., seated capacity from seating add-on).
		$ticket_type   = $this->ticket_type_repo->find( $ticket_type_id );
		$capacity_type = $ticket_type ? CapacityType::tryFrom( $ticket_type->capacity_type ) : null;

		/**
		 * Filters the available count for a ticket type.
		 *
		 * Return a non-null integer to short-circuit the default calculation.
		 * Used by add-on plugins (e.g., nettertech-events-seating) to provide
		 * custom availability counts for custom capacity types.
		 *
		 * @since 1.0.2
		 *
		 * @param int|null          $override       null to use default, int to override.
		 * @param int               $ticket_type_id Ticket type ID.
		 * @param CapacityType|null $capacity_type  The ticket type's capacity type.
		 */
		$override = apply_filters( 'nettertech_events_available_count', null, $ticket_type_id, $capacity_type );

		// A seated tier's count comes from the seating add-on, which knows about
		// seats but not about the room the tier is sold into. The room still binds.
		if ( null !== $override ) {
			$context = $this->house_repo->context_for_ticket_type( $ticket_type_id );

			if ( null === $context ) {
				return (int) $override;
			}

			return HouseRule::bound( max( 0, (int) $override ), self::house_remaining( $context, $include_pending ) );
		}

		// Try cache first (unless skipped for checkout validation).
		if ( ! $skip_cache ) {
			$cache_key = 'capacity_available_' . $ticket_type_id . ( $include_pending ? '_pending' : '' );
			$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );

			if ( false !== $cached ) {
				return $cached;
			}
		}

		$context = $this->house_repo->context_for_ticket_type( $ticket_type_id );

		if ( null === $context ) {
			return null;
		}

		$house_remaining = self::house_remaining( $context, $include_pending );

		$own_remaining = HouseRule::own_remaining(
			$context['own_capacity_type'],
			$context['own_capacity'],
			$context['own_sold']
		);

		if ( $include_pending && null !== $own_remaining ) {
			$own_remaining = max( 0, $own_remaining - $this->reservation_manager->get_pending_count( $ticket_type_id ) );
		}

		$available = HouseRule::bound( $own_remaining, $house_remaining );

		// A series pass occupies a seat on EVERY date it spans (NTE-156), so beyond
		// its own allotment it is held by its tightest date's room. The per-date
		// house_sold already counts sold passes (HouseCapacityRepository peers).
		if ( $ticket_type
			&& TicketTypeScope::EVENT->value === $ticket_type->scope
			&& null !== $ticket_type->event_id ) {
			$available = HouseRule::bound(
				$available,
				$this->tightest_date_remaining( (int) $ticket_type->event_id )
			);
		}

		if ( null === $available ) {
			return null; // Unlimited.
		}

		// Subtract buffer stock.
		$available = max( 0, $available - $this->get_buffer_stock( $ticket_type_id ) );

		// Cache the result (unless we're skipping cache).
		if ( ! $skip_cache ) {
			$cache_key = 'capacity_available_' . $ticket_type_id . ( $include_pending ? '_pending' : '' );
			wp_cache_set( $cache_key, $available, CacheManager::CACHE_GROUP, CacheManager::TTL_CAPACITY );
		}

		return $available;
	}

	/**
	 * The fewest seats any of the event's dates has left.
	 *
	 * A pass buyer must fit into every room the pass admits them to, so the pass
	 * can sell no more than its most crowded date has seats (NTE-156). Dates whose
	 * room is unbounded impose no bound; if every date is unbounded, neither is
	 * the pass (its own allotment may still hold it).
	 *
	 * @param int $event_id Event ID.
	 * @return int|null Seats left on the tightest date, or null when no date binds.
	 */
	private function tightest_date_remaining( int $event_id ): ?int {
		$tightest = null;

		foreach ( $this->occurrence_repo->for_event( $event_id ) as $occurrence ) {
			if ( null === $occurrence->id ) {
				continue;
			}

			$context = $this->house_repo->house_context_for_occurrence( $occurrence->id );

			if ( null === $context || null === $context['house'] ) {
				continue;
			}

			$remaining = max( 0, $context['house'] - $context['house_sold'] );
			$tightest  = null === $tightest ? $remaining : min( $tightest, $remaining );
		}

		return $tightest;
	}

	/**
	 * What the room has left, across every tier sold into it.
	 *
	 * @param array{house: ?int, house_sold: int, house_reserved: int} $context House context.
	 * @param bool                                                     $include_pending Whether held seats count as taken.
	 * @return int|null Remaining house capacity, or null when the house is unbounded.
	 */
	private static function house_remaining( array $context, bool $include_pending ): ?int {
		if ( null === $context['house'] ) {
			return null;
		}

		$taken = $context['house_sold'] + ( $include_pending ? $context['house_reserved'] : 0 );

		return max( 0, $context['house'] - $taken );
	}

	/**
	 * Get total capacity for a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int|null Total capacity, or null if unlimited.
	 */
	public function get_total_capacity( int $ticket_type_id ): ?int {
		$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );

		if ( ! $ticket_type ) {
			return null;
		}

		return $ticket_type->capacity;
	}

	/**
	 * Get sold count for a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Sold count.
	 */
	public function get_sold_count( int $ticket_type_id ): int {
		$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );

		if ( ! $ticket_type ) {
			return 0;
		}

		return $ticket_type->sold_count;
	}

	// =========================================================================
	// Buffer Stock
	// =========================================================================

	/**
	 * Get buffer stock for a ticket type.
	 *
	 * Buffer stock is held back from sale for venue use (comps, emergencies).
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Buffer stock amount.
	 */
	public function get_buffer_stock( int $ticket_type_id ): int {
		$buffers = get_option( self::BUFFER_STOCK_OPTION, array() );

		return isset( $buffers[ $ticket_type_id ] ) ? (int) $buffers[ $ticket_type_id ] : 0;
	}

	/**
	 * Set buffer stock for a ticket type.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $amount         Buffer stock amount.
	 * @return bool True on success.
	 */
	public function set_buffer_stock( int $ticket_type_id, int $amount ): bool {
		$buffers = get_option( self::BUFFER_STOCK_OPTION, array() );
		if ( ! is_array( $buffers ) ) {
			$buffers = array();
		}
		$buffers[ $ticket_type_id ] = max( 0, $amount );

		$result = update_option( self::BUFFER_STOCK_OPTION, $buffers );

		if ( $result ) {
			/**
			 * Fires when buffer stock is updated.
			 *
			 * @param int $ticket_type_id Ticket type ID.
			 * @param int $amount         New buffer stock amount.
			 */
			do_action( 'nettertech_events_buffer_stock_updated', $ticket_type_id, $amount );
		}

		return $result;
	}

	// =========================================================================
	// Series Pass Capacity
	// =========================================================================

	/**
	 * Get effective capacity for a series pass.
	 *
	 * For EVENT-scoped ticket types, calculates capacity distribution
	 * across all occurrences.
	 *
	 * @since 0.9.0
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return array{total: ?int, per_occurrence: ?int, occurrence_count: int}
	 */
	public function get_series_pass_capacity( int $ticket_type_id ): array {
		$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );

		if ( ! $ticket_type ) {
			return array(
				'total'            => null,
				'per_occurrence'   => null,
				'occurrence_count' => 0,
			);
		}

		// Only applicable to EVENT scope (series passes).
		if ( TicketTypeScope::EVENT->value !== $ticket_type->scope ) {
			return array(
				'total'            => $ticket_type->capacity,
				'per_occurrence'   => $ticket_type->capacity,
				'occurrence_count' => 1,
			);
		}

		// Get occurrence count for this event.
		$event_id = $ticket_type->event_id;

		if ( ! $event_id ) {
			return array(
				'total'            => $ticket_type->capacity,
				'per_occurrence'   => $ticket_type->capacity,
				'occurrence_count' => 0,
			);
		}

		$occurrences      = $this->occurrence_repo->for_event( $event_id );
		$occurrence_count = count( $occurrences );

		return array(
			'total'            => $ticket_type->capacity,
			'per_occurrence'   => $ticket_type->capacity, // Same capacity applies to all.
			'occurrence_count' => $occurrence_count,
		);
	}

	// =========================================================================
	// Capacity Summary
	// =========================================================================

	/**
	 * Get capacity summary for a ticket type.
	 *
	 * Every figure here is bounded by the house (ADR-019). `capacity` is the most
	 * this tier could ever sell — its own limit where it has one, the room where it
	 * does not — so a shared tier under a ceiling is finite, not unlimited, and
	 * `is_unlimited` is true only when neither the tier nor the room binds.
	 *
	 * The tier's `capacity` column is never read directly: only a FIXED tier's value
	 * means anything, and the others may carry a stale number the form hid rather
	 * than cleared. `HouseRule::own_remaining()` makes that check.
	 *
	 * Uses caching to reduce database queries. Cache is invalidated
	 * when capacity changes (reserve, release, buffer update).
	 *
	 * @since 0.9.0
	 *
	 * @param int  $ticket_type_id Ticket type ID.
	 * @param bool $skip_cache     Whether to bypass cache.
	 * @return array{
	 *     capacity: ?int,
	 *     sold: int,
	 *     available: ?int,
	 *     buffer: int,
	 *     pending: int,
	 *     effective_available: ?int,
	 *     is_unlimited: bool,
	 *     is_sold_out: bool,
	 *     is_low_stock: bool
	 * }
	 */
	public function get_capacity_summary( int $ticket_type_id, bool $skip_cache = false ): array {
		// Try cache first (unless skipped).
		if ( ! $skip_cache ) {
			$cache_key = 'capacity_summary_' . $ticket_type_id;
			$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );

			if ( false !== $cached ) {
				return $cached;
			}
		}

		$context = $this->house_repo->context_for_ticket_type( $ticket_type_id );

		if ( null === $context ) {
			return array(
				'capacity'            => null,
				'sold'                => 0,
				'available'           => null,
				'buffer'              => 0,
				'pending'             => 0,
				'effective_available' => null,
				'is_unlimited'        => true,
				'is_sold_out'         => false,
				'is_low_stock'        => false,
			);
		}

		$sold    = $context['own_sold'];
		$buffer  = $this->get_buffer_stock( $ticket_type_id );
		$pending = $this->reservation_manager->get_pending_count( $ticket_type_id );

		// The most this tier could ever sell: its own limit, held by the room. A
		// shared tier has no limit of its own, so the room is the whole of it —
		// which is why an unbounded-looking tier is still finite under a ceiling.
		$capacity = HouseRule::bound(
			HouseRule::own_remaining( $context['own_capacity_type'], $context['own_capacity'], 0 ),
			$context['house']
		);

		// Seats left before holds are counted.
		$available = HouseRule::bound(
			HouseRule::own_remaining( $context['own_capacity_type'], $context['own_capacity'], $sold ),
			self::house_remaining( $context, false )
		);

		// What a shopper can actually buy right now. Sourced from get_available_count()
		// so the seating override, the buffer and pending holds are applied in exactly
		// one place; a second copy of that arithmetic here is how the screens and the
		// till drift apart.
		$effective_available = $this->get_available_count( $ticket_type_id, true, $skip_cache );

		$is_unlimited = ( null === $capacity );
		$is_sold_out  = ! $is_unlimited && null !== $effective_available && $effective_available <= 0;
		$is_low_stock = ! $is_unlimited && ! $is_sold_out && null !== $effective_available
			&& $effective_available <= ( (int) $capacity * 0.1 );

		$result = array(
			'capacity'            => $capacity,
			'sold'                => $sold,
			'available'           => $available,
			'buffer'              => $buffer,
			'pending'             => $pending,
			'effective_available' => $effective_available,
			'is_unlimited'        => $is_unlimited,
			'is_sold_out'         => $is_sold_out,
			'is_low_stock'        => $is_low_stock,
		);

		// Cache the result.
		if ( ! $skip_cache ) {
			$cache_key = 'capacity_summary_' . $ticket_type_id;
			wp_cache_set( $cache_key, $result, CacheManager::CACHE_GROUP, CacheManager::TTL_CAPACITY );
		}

		return $result;
	}

	// =========================================================================
	// Occurrence Capacity Aggregation
	// =========================================================================

	/**
	 * Get total capacity for an occurrence across all ticket types.
	 *
	 * Uses caching with locking to prevent cache stampede on expensive
	 * aggregation queries.
	 *
	 * @since 0.9.0
	 *
	 * @param int  $occurrence_id Occurrence ID.
	 * @param bool $skip_cache    Whether to bypass cache.
	 * @return array{
	 *     total_capacity: ?int,
	 *     total_sold: int,
	 *     total_available: ?int,
	 *     has_unlimited: bool,
	 *     ticket_types: array<int, array<string, mixed>>
	 * }
	 */
	public function get_occurrence_capacity( int $occurrence_id, bool $skip_cache = false ): array {
		$cache_key = 'capacity_occurrence_' . $occurrence_id;

		// Try cache first (unless skipped).
		if ( ! $skip_cache ) {
			$cached = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );

			if ( false !== $cached ) {
				return $cached;
			}

			// Acquire lock to prevent stampede on expensive aggregation.
			$lock_key = 'lock_' . $cache_key;
			wp_cache_add( $lock_key, 1, CacheManager::CACHE_GROUP, 30 );
		}

		$ticket_types = $this->ticket_type_repo->for_occurrence( $occurrence_id );

		// No ticket types configured — signal "no constraint" with null total_capacity.
		// RSVPFormShortcode::check_capacity relies on this to render the RSVP form for
		// events without explicit ticket configuration. Returning 0 here would read as
		// "sold out" and suppress the form entirely.
		if ( empty( $ticket_types ) ) {
			$result = array(
				'total_capacity'  => null,
				'total_sold'      => 0,
				'total_available' => null,
				'has_unlimited'   => false,
				'ticket_types'    => array(),
			);

			if ( ! $skip_cache ) {
				wp_cache_set( $cache_key, $result, CacheManager::CACHE_GROUP, CacheManager::TTL_CAPACITY );
				wp_cache_delete( 'lock_' . $cache_key, CacheManager::CACHE_GROUP );
			}

			return $result;
		}

		$total_sold    = 0;
		$types_summary = array();
		$tiers         = array();

		foreach ( $ticket_types as $tt ) {
			$tt_id = $tt->id;
			if ( null === $tt_id ) {
				continue;
			}

			// Use skip_cache to ensure we get fresh data for aggregation.
			$types_summary[] = array(
				'id'      => $tt_id,
				'name'    => $tt->name,
				'summary' => $this->get_capacity_summary( $tt_id, $skip_cache ),
			);

			$tiers[] = array(
				'capacity'      => $tt->capacity,
				'capacity_type' => $tt->capacity_type,
			);

			$total_sold += $tt->sold_count;
		}

		// The tiers share one room, so the room is resolved once — never summed. Three
		// 250-seat tiers in a 250-seat hall are one hall described three times, and
		// adding them up is what sold seats that did not exist (ADR-019).
		$occurrence = $this->occurrence_repo->find( $occurrence_id );
		$house      = HouseRule::house( $tiers, $occurrence ? $occurrence->capacity : null );

		$result = array(
			'total_capacity'  => $house,
			'total_sold'      => $total_sold,
			'total_available' => null === $house ? null : max( 0, $house - $total_sold ),
			'has_unlimited'   => null === $house,
			'ticket_types'    => $types_summary,
		);

		// Cache the result and release lock.
		if ( ! $skip_cache ) {
			wp_cache_set( $cache_key, $result, CacheManager::CACHE_GROUP, CacheManager::TTL_CAPACITY );
			wp_cache_delete( 'lock_' . $cache_key, CacheManager::CACHE_GROUP );
		}

		return $result;
	}
}
