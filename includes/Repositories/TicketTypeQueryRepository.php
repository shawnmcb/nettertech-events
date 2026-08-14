<?php
/**
 * Ticket type query repository.
 *
 * Handles read-only ticket type queries (occurrence lookups, event scope,
 * product lookups, free/paid checks). Extracted from TicketTypeRepository
 * to enforce SRP.
 *
 * @package NetterTechEvents\Repositories
 * @since   1.5.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\TicketTypeQueryRepositoryInterface;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Services\Capacity\HouseRule;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Models\TicketType;

/**
 * Read-only ticket type queries for occurrence, event, and product lookups.
 *
 * @since 1.5.0
 */
class TicketTypeQueryRepository implements TicketTypeQueryRepositoryInterface {

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
	 * Constructor.
	 *
	 * @param \wpdb $db Database instance.
	 */
	public function __construct( \wpdb $db ) {
		$this->db    = $db;
		$this->table = Schema::table( 'ticket_types' );
	}

	// =========================================================================
	// Query Methods - Occurrence Scope
	// =========================================================================

	/**
	 * Get ticket types for an occurrence.
	 *
	 * @param int                  $occurrence_id Occurrence ID.
	 * @param array<string, mixed> $args          Query arguments.
	 * @return array<TicketType>
	 */
	public function for_occurrence( int $occurrence_id, array $args = array() ): array {
		$defaults = array(
			'status'  => null,
			'orderby' => 'sort_order',
			'order'   => 'ASC',
		);

		$args = wp_parse_args( $args, $defaults );

		// Cache default-args queries (the hot public path).
		$use_cache = ( null === $args['status'] && 'sort_order' === $args['orderby'] && 'ASC' === $args['order'] );
		$cache_key = 'ticket_types_occurrence_' . $occurrence_id;

		if ( $use_cache ) {
			$cached = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );
			if ( false !== $cached ) {
				return array_map( array( TicketType::class, 'from_row' ), $cached );
			}
		}

		$occurrences_table = Schema::table( 'occurrences' );
		$event_id          = $this->db->get_var(
			$this->db->prepare(
				"SELECT event_id FROM {$occurrences_table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id
			)
		);

		if ( ! $event_id ) {
			return array();
		}

		$where  = array( '(occurrence_id = %d OR (occurrence_id IS NULL AND event_id = %d))' );
		$values = array( $occurrence_id, $event_id );

		if ( null !== $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		$where_clause      = 'WHERE ' . implode( ' AND ', $where );
		$sanitized_orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		$orderby           = $sanitized_orderby ? $sanitized_orderby : 'sort_order ASC';

		$sql  = $this->db->prepare(
			"SELECT * FROM {$this->table} {$where_clause} ORDER BY {$orderby}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			$values
		);
		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().

		$rows = $rows ? $rows : array();

		// When occurrence-specific rows exist, they supersede event-level templates.
		$has_occurrence_rows = false;
		foreach ( $rows as $row ) {
			if ( null !== $row->occurrence_id ) {
				$has_occurrence_rows = true;
				break;
			}
		}

		if ( $has_occurrence_rows ) {
			$rows = array_values(
				array_filter( $rows, fn( $row ) => null !== $row->occurrence_id )
			);
		}

		if ( $use_cache ) {
			wp_cache_set( $cache_key, $rows, CacheManager::CACHE_GROUP, CacheManager::TTL_TICKET_TYPE );
		}

		return array_map( array( TicketType::class, 'from_row' ), $rows );
	}

	/**
	 * Get ticket types for multiple occurrences (N+1 prevention).
	 *
	 * @param array<int> $occurrence_ids Occurrence IDs.
	 * @param string     $status         Optional status filter.
	 * @return array<int, array<TicketType>> Grouped by occurrence_id.
	 */
	public function for_multiple_occurrences( array $occurrence_ids, ?string $status = null ): array {
		if ( empty( $occurrence_ids ) ) {
			return array();
		}

		$occurrence_ids = array_filter( array_map( 'absint', $occurrence_ids ) );
		if ( empty( $occurrence_ids ) ) {
			return array();
		}

		$occurrences_table = Schema::table( 'occurrences' );
		$placeholders      = implode( ',', array_fill( 0, count( $occurrence_ids ), '%d' ) );
		$occ_event_map     = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, event_id FROM {$occurrences_table} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_ids
			),
			OBJECT_K
		);

		if ( empty( $occ_event_map ) ) {
			return array();
		}

		$event_ids          = array_map( 'absint', array_unique( array_column( (array) $occ_event_map, 'event_id' ) ) );
		$occ_placeholders   = implode( ',', array_fill( 0, count( $occurrence_ids ), '%d' ) );
		$event_placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
		$values             = array_merge( $occurrence_ids, $event_ids );

		$where = "(occurrence_id IN ({$occ_placeholders}) OR (occurrence_id IS NULL AND event_id IN ({$event_placeholders})))";

		if ( null !== $status ) {
			$where   .= ' AND status = %s';
			$values[] = $status;
		}

		$sql  = $this->db->prepare(
			"SELECT * FROM {$this->table} WHERE {$where} ORDER BY event_id, occurrence_id, sort_order ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			$values
		);
		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().

		// Build event→occurrences mapping.
		$event_to_occs = array();
		foreach ( $occ_event_map as $occ_id => $occ_row ) {
			$eid = (int) $occ_row->event_id;
			if ( ! isset( $event_to_occs[ $eid ] ) ) {
				$event_to_occs[ $eid ] = array();
			}
			$event_to_occs[ $eid ][] = (int) $occ_id;
		}

		// Group results.
		$grouped = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$ticket = TicketType::from_row( $row );

			if ( null === $row->occurrence_id ) {
				$eid = (int) $row->event_id;
				if ( isset( $event_to_occs[ $eid ] ) ) {
					foreach ( $event_to_occs[ $eid ] as $oid ) {
						$grouped[ $oid ]   = $grouped[ $oid ] ?? array();
						$grouped[ $oid ][] = $ticket;
					}
				}
			} else {
				$oid               = (int) $row->occurrence_id;
				$grouped[ $oid ]   = $grouped[ $oid ] ?? array();
				$grouped[ $oid ][] = $ticket;
			}
		}

		return $grouped;
	}

	/**
	 * Get active ticket types for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<TicketType>
	 */
	public function get_active_for_occurrence( int $occurrence_id ): array {
		return $this->for_occurrence( $occurrence_id, array( 'status' => 'active' ) );
	}

	/**
	 * Get ticket types currently on sale for an occurrence.
	 *
	 * The window is read in the occurrence's own timezone, not the site's (NTE-148).
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<TicketType>
	 */
	public function get_on_sale_for_occurrence( int $occurrence_id ): array {
		$types = $this->get_active_for_occurrence( $occurrence_id );
		$zones = $this->timezones_for_occurrences( array( $occurrence_id ) );
		$zone  = $zones[ $occurrence_id ] ?? wp_timezone();

		$on_sale = array_values(
			array_filter( $types, fn( TicketType $type ) => $type->is_on_sale( $zone ) )
		);

		return $this->filter_on_sale( $on_sale, $types, $occurrence_id );
	}

	/**
	 * Get ticket types currently on sale for multiple occurrences (N+1 prevention).
	 *
	 * @since 1.0.3
	 *
	 * @param array<int> $occurrence_ids Occurrence IDs.
	 * @return array<int, array<TicketType>> Map of occurrence_id => list of on-sale ticket types.
	 */
	public function get_on_sale_for_occurrences( array $occurrence_ids ): array {
		$grouped = $this->for_multiple_occurrences( $occurrence_ids, 'active' );
		$zones   = $this->timezones_for_occurrences( array_map( 'intval', array_keys( $grouped ) ) );

		$result = array();
		foreach ( $grouped as $occurrence_id => $types ) {
			$zone    = $zones[ (int) $occurrence_id ] ?? wp_timezone();
			$on_sale = array_values(
				array_filter( $types, fn( TicketType $type ) => $type->is_on_sale( $zone ) )
			);
			$on_sale = $this->filter_on_sale( $on_sale, $types, (int) $occurrence_id );

			if ( ! empty( $on_sale ) ) {
				$result[ (int) $occurrence_id ] = $on_sale;
			}
		}

		return $result;
	}

	/**
	 * Get event-scope (series pass) ticket types currently on sale for an event.
	 *
	 * A series pass belongs to no single occurrence, so it never appears in the
	 * occurrence lookups above — which is exactly why it had no buy button on the
	 * public page (NTE-156). The sale window is read in the passed zone, which the
	 * caller anchors to the event's next occurrence so this agrees with the cart
	 * gate (CartValidator reads the window in `next_for_event()`'s timezone).
	 *
	 * @since 1.1.3
	 *
	 * @param int                $event_id Event ID.
	 * @param \DateTimeZone|null $zone     Zone the sale window is read in (default: site zone).
	 * @return array<TicketType>
	 */
	public function get_on_sale_for_event( int $event_id, ?\DateTimeZone $zone = null ): array {
		$types = $this->for_event(
			$event_id,
			array(
				'scope'  => TicketTypeScope::EVENT->value,
				'status' => 'active',
			)
		);

		$zone = $zone ?? wp_timezone();

		return array_values(
			array_filter( $types, static fn( TicketType $type ) => $type->is_on_sale( $zone ) )
		);
	}

	/**
	 * Let extensions decide which tiers a buyer actually sees.
	 *
	 * The sale window answers "is this tier open right now?". It cannot answer the
	 * questions an extension needs to: should a sold-out early-bird tier stay on the page
	 * struck through, or vanish? Should the next tier in a sequence appear the moment the
	 * one before it exhausts, rather than waiting on a clock? Those are policy, and policy
	 * does not belong in a repository.
	 *
	 * Both sets are passed: an extension that wants to *re-admit* a tier the window
	 * excluded — the struck-through case — needs the ones that were filtered out, and
	 * would otherwise have to re-query for them.
	 *
	 * @since 1.1.2
	 *
	 * @param array<TicketType> $on_sale       Tiers the sale window admitted.
	 * @param array<TicketType> $all_active    Every active tier on the occurrence, admitted or not.
	 * @param int               $occurrence_id Occurrence ID.
	 * @return array<TicketType>
	 */
	private function filter_on_sale( array $on_sale, array $all_active, int $occurrence_id ): array {
		/**
		 * Filters the ticket types offered to a buyer for an occurrence.
		 *
		 * @since 1.1.2
		 *
		 * @param array<TicketType> $on_sale       Tiers the sale window admitted.
		 * @param array<TicketType> $all_active    Every active tier on the occurrence.
		 * @param int               $occurrence_id Occurrence ID.
		 */
		$filtered = apply_filters(
			'nettertech_events_on_sale_ticket_types',
			$on_sale,
			array_values( $all_active ),
			$occurrence_id
		);

		return is_array( $filtered ) ? array_values( $filtered ) : $on_sale;
	}

	/**
	 * Load the timezone of each occurrence in one query.
	 *
	 * A sale window is stored as bare wall-clock, so it only means something once you
	 * know the zone it was authored in — the event's, not the site's. Fetched in bulk to
	 * keep the N+1 promise these callers exist to make.
	 *
	 * @since 1.1.2
	 *
	 * @param array<int> $occurrence_ids Occurrence IDs.
	 * @return array<int, \DateTimeZone> Map of occurrence_id => timezone. Unreadable zones are omitted.
	 */
	private function timezones_for_occurrences( array $occurrence_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $occurrence_ids ) ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$occurrences_table = Schema::table( 'occurrences' );
		$placeholders      = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name from Schema::table(); IDs bound via prepare().
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, timezone FROM {$occurrences_table} WHERE id IN ({$placeholders})",
				...$ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$zones = array();
		foreach ( (array) $rows as $row ) {
			$name = (string) ( $row->timezone ?? '' );

			if ( '' === $name ) {
				continue;
			}

			try {
				$zones[ (int) $row->id ] = new \DateTimeZone( $name );
			} catch ( \Exception $e ) {
				unset( $e ); // Unreadable stored zone — caller falls back to the site zone.
			}
		}

		return $zones;
	}

	// =========================================================================
	// Query Methods - Event Scope
	// =========================================================================

	/**
	 * Get ticket types for an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array<TicketType>
	 */
	public function for_event( int $event_id, array $args = array() ): array {
		$defaults = array(
			'status'  => null,
			'scope'   => null,
			'orderby' => 'sort_order',
			'order'   => 'ASC',
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array( 'event_id = %d' );
		$values = array( $event_id );

		if ( null !== $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( null !== $args['scope'] ) {
			$where[]  = 'scope = %s';
			$values[] = $args['scope'];
		}

		$where_clause      = 'WHERE ' . implode( ' AND ', $where );
		$sanitized_orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		$orderby           = $sanitized_orderby ? $sanitized_orderby : 'sort_order ASC';

		$sql  = $this->db->prepare(
			"SELECT * FROM {$this->table} {$where_clause} ORDER BY {$orderby}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			$values
		);
		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().

		return array_map( array( TicketType::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Get ticket templates for an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array<TicketType>
	 */
	public function get_templates( int $event_id, array $args = array() ): array {
		$defaults = array(
			'status'  => 'active',
			'orderby' => 'sort_order',
			'order'   => 'ASC',
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array( 'event_id = %d', 'scope = %s' );
		$values = array( $event_id, TicketTypeScope::TEMPLATE->value );

		if ( null !== $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		$where_clause      = 'WHERE ' . implode( ' AND ', $where );
		$sanitized_orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		$orderby           = $sanitized_orderby ? $sanitized_orderby : 'sort_order ASC';

		$sql  = $this->db->prepare(
			"SELECT * FROM {$this->table} {$where_clause} ORDER BY {$orderby}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			$values
		);
		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().

		return array_map( array( TicketType::class, 'from_row' ), $rows ? $rows : array() );
	}

	// =========================================================================
	// Query Methods - Event Capacity Aggregate
	// =========================================================================

	/**
	 * Compute the shared-pool-aware total capacity per event for a batch of events.
	 *
	 * The denominator for "percentage of capacity sold" on the All Events list.
	 * Mirrors the occurrence-level capacity model (see HouseRule):
	 * for each occurrence the authoritative ceiling is `occurrences.capacity` when
	 * set — that single ceiling already bounds any shared pool, so shared ticket
	 * types are counted once (inside the occurrence ceiling) rather than added on
	 * top of fixed allocations. When an occurrence has no capacity set, the
	 * effective capacity falls back to the sum of its sellable ticket types'
	 * fixed capacities, marking the event unlimited if any sellable ticket type is
	 * unlimited (NULL capacity or `unlimited` capacity_type) on an uncapped
	 * occurrence. The per-event capacity is the sum across occurrences; any
	 * unlimited contribution makes the whole event unlimited (null capacity).
	 *
	 * Bounded query count: two batched queries for the whole page (one for
	 * occurrence ceilings, one for ticket types), never per-row.
	 *
	 * @since 1.0.3
	 *
	 * @param array<int> $event_ids Event IDs to aggregate.
	 * @return array<int, array{capacity: ?int, has_unlimited: bool, configured: bool}>
	 *         Map of event_id => denominator data. `configured` is false when the
	 *         event has no sellable ticket types at all (column renders "—").
	 */
	public function event_capacity_for_events( array $event_ids ): array {
		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );

		if ( empty( $event_ids ) ) {
			return array();
		}

		$occurrences_table = Schema::table( 'occurrences' );
		$placeholders      = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );

		// Query 1: occurrence ceilings (event_id => occurrence_id => ?capacity).
		/**
		 * Occurrence-ceiling rows; columns per the SELECT below.
		 *
		 * @var array<int, object{id: string, event_id: string, capacity: string|null}>|null $occ_rows
		 */
		$occ_rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, event_id, capacity
				FROM {$occurrences_table}
				WHERE event_id IN ({$placeholders})
					AND status = 'scheduled'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant; event IDs bound via prepare().
				$event_ids
			)
		);

		// Query 2: sellable ticket types for these events (occurrence-scoped via
		// occurrence_id, event-scoped via event_id with NULL occurrence_id). Exclude
		// templates and inactive types — they are not sellable allocations.
		/**
		 * Sellable ticket-type rows; columns per the SELECT below.
		 *
		 * @var array<int, object{event_id: string|null, occurrence_id: string|null, capacity: string|null, capacity_type: string, occ_event_id: string|null}>|null $tt_rows
		 */
		$tt_rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT tt.event_id, tt.occurrence_id, tt.capacity, tt.capacity_type, o.event_id AS occ_event_id
				FROM {$this->table} tt
				LEFT JOIN {$occurrences_table} o ON o.id = tt.occurrence_id
				WHERE tt.scope <> %s
					AND tt.status = 'active'
					AND (
						o.event_id IN ({$placeholders})
						OR ( tt.occurrence_id IS NULL AND tt.event_id IN ({$placeholders}) )
					)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant; values bound via prepare().
				array_merge( array( TicketTypeScope::TEMPLATE->value ), $event_ids, $event_ids )
			)
		);

		return $this->reduce_event_capacity( $event_ids, $occ_rows ? $occ_rows : array(), $tt_rows ? $tt_rows : array() );
	}

	/**
	 * Reduce raw occurrence + ticket-type rows into per-event capacity denominators.
	 *
	 * Extracted for testability and to keep the query method focused on SQL.
	 *
	 * @param array<int>                                                                                                                                     $event_ids Event IDs being aggregated.
	 * @param array<int, object{id: string, event_id: string, capacity: string|null}>                                                                        $occ_rows Occurrence rows.
	 * @param array<int, object{event_id: string|null, occurrence_id: string|null, capacity: string|null, capacity_type: string, occ_event_id: string|null}> $tt_rows Ticket-type rows.
	 * @return array<int, array{capacity: ?int, has_unlimited: bool, configured: bool}>
	 */
	private function reduce_event_capacity( array $event_ids, array $occ_rows, array $tt_rows ): array {
		// occurrence_id => ?capacity ceiling.
		$occ_capacity = array();
		// event_id => occurrence_id => true.
		$event_occurrences = array();
		foreach ( $occ_rows as $row ) {
			$occ_id                                  = (int) $row->id;
			$evt_id                                  = (int) $row->event_id;
			$occ_capacity[ $occ_id ]                 = null !== $row->capacity ? (int) $row->capacity : null;
			$event_occurrences[ $evt_id ][ $occ_id ] = true;
		}

		// Group ticket types by event and by occurrence (null occurrence = event-scoped).
		$event_has_tickets  = array();
		$event_scoped_tiers = array(); // event_id => tiers sharing the event-scoped house.
		$occ_tiers          = array(); // occurrence_id => tiers sharing that date's house.
		foreach ( $tt_rows as $row ) {
			$occurrence_id = null !== $row->occurrence_id ? (int) $row->occurrence_id : null;
			$evt_id        = null !== $row->occ_event_id ? (int) $row->occ_event_id : (int) $row->event_id;

			$event_has_tickets[ $evt_id ] = true;

			$tier = array(
				'capacity'      => null !== $row->capacity ? (int) $row->capacity : null,
				'capacity_type' => (string) $row->capacity_type,
			);

			if ( null === $occurrence_id ) {
				$event_scoped_tiers[ $evt_id ][] = $tier;
				continue;
			}

			$occ_tiers[ $occurrence_id ][] = $tier;
		}

		$result = array();
		foreach ( $event_ids as $event_id ) {
			if ( empty( $event_has_tickets[ $event_id ] ) ) {
				$result[ $event_id ] = array(
					'capacity'      => 0,
					'has_unlimited' => false,
					'configured'    => false,
				);
				continue;
			}

			$has_unlimited = false;

			// Each date is its own house; the event's dates sum.
			$occurrence_house_total = 0;
			foreach ( array_keys( $event_occurrences[ $event_id ] ?? array() ) as $occ_id ) {
				$house = HouseRule::house( $occ_tiers[ $occ_id ] ?? array(), $occ_capacity[ $occ_id ] );

				if ( null === $house ) {
					$has_unlimited = true;
					continue;
				}

				$occurrence_house_total += $house;
			}

			// Event-scoped tiers share one house across the event, with no ceiling
			// of their own to defer to.
			$event_scoped_house = HouseRule::house( $event_scoped_tiers[ $event_id ] ?? array(), null );

			if ( null === $event_scoped_house ) {
				$has_unlimited      = true;
				$event_scoped_house = 0;
			}

			// The event's house is the larger of the occurrence-date total and the
			// event-scoped pool. Taking the max (not the sum) avoids double-counting
			// when event-scoped tiers describe the same room as the occurrence(s).
			$total = max( $occurrence_house_total, $event_scoped_house );

			$result[ $event_id ] = array(
				'capacity'      => $has_unlimited ? null : $total,
				'has_unlimited' => $has_unlimited,
				'configured'    => true,
			);
		}

		return $result;
	}

	// =========================================================================
	// Query Methods - Product Lookup
	// =========================================================================

	/**
	 * Find by WooCommerce product ID.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return TicketType|null
	 */
	public function find_by_product( int $product_id ): ?TicketType {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE wc_product_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$product_id
			)
		);

		return $row ? TicketType::from_row( $row ) : null;
	}

	/**
	 * Find by WooCommerce variation ID.
	 *
	 * @param int $variation_id WooCommerce variation ID.
	 * @return TicketType|null
	 */
	public function find_by_variation( int $variation_id ): ?TicketType {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE wc_variation_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$variation_id
			)
		);

		return $row ? TicketType::from_row( $row ) : null;
	}

	// =========================================================================
	// Query Methods - Free/Paid Checks
	// =========================================================================

	/**
	 * Check if occurrence has any free ticket types.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool
	 */
	public function occurrence_has_free_tickets( int $occurrence_id ): bool {
		$count = (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table}
				 WHERE occurrence_id = %d AND price <= 0 AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id
			)
		);

		return $count > 0;
	}

	/**
	 * Check if occurrence has only free ticket types.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool
	 */
	public function occurrence_is_free( int $occurrence_id ): bool {
		$occurrences_table = Schema::table( 'occurrences' );
		$event_id          = $this->db->get_var(
			$this->db->prepare(
				"SELECT event_id FROM {$occurrences_table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id
			)
		);

		if ( ! $event_id ) {
			return true;
		}

		$count = (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table}
				 WHERE (occurrence_id = %d OR (occurrence_id IS NULL AND event_id = %d))
				 AND price > 0 AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id,
				$event_id
			)
		);

		return 0 === $count;
	}
}
