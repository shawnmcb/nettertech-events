<?php
/**
 * TicketType repository class.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\TicketTypeQueryRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeStockRepositoryInterface;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Traits\IdentityMapTrait;
use NetterTechEvents\Utilities\DatabaseLogger;

/**
 * Handles TicketType persistence and retrieval.
 *
 * Stock management and shared capacity operations are delegated to
 * TicketTypeStockRepository for separation of concerns.
 *
 * @since 0.9.0
 * @api
 */
class TicketTypeRepository implements TicketTypeRepositoryInterface {

	use IdentityMapTrait;

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
	 * Stock repository for inventory operations.
	 *
	 * @var TicketTypeStockRepositoryInterface
	 */
	private TicketTypeStockRepositoryInterface $stock_repo;

	/**
	 * Query repository for read-only operations.
	 *
	 * @var TicketTypeQueryRepositoryInterface
	 */
	private TicketTypeQueryRepositoryInterface $query_repo;

	/**
	 * Constructor.
	 *
	 * @param \wpdb                                   $db         Database instance.
	 * @param TicketTypeStockRepositoryInterface|null $stock_repo Optional stock repository for testing.
	 * @param TicketTypeQueryRepositoryInterface|null $query_repo Optional query repository for testing.
	 */
	public function __construct(
		\wpdb $db,
		?TicketTypeStockRepositoryInterface $stock_repo = null,
		?TicketTypeQueryRepositoryInterface $query_repo = null
	) {
		$this->db         = $db;
		$this->table      = Schema::table( 'ticket_types' );
		$this->stock_repo = $stock_repo ?? new TicketTypeStockRepository( $this->db );
		$this->query_repo = $query_repo ?? new TicketTypeQueryRepository( $this->db );
	}

	/**
	 * Format database error for exceptions.
	 *
	 * @param string $operation The operation that failed.
	 * @param string $entity    The entity type.
	 * @return string Safe error message.
	 */
	private function format_db_error( string $operation, string $entity ): string {
		if ( $this->db->last_error ) {
			DatabaseLogger::log_error( $operation, $entity, $this->db->last_error );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return sprintf(
				/* translators: 1: operation (insert/update), 2: entity type, 3: error message */
				esc_html__( 'Failed to %1$s %2$s: %3$s', 'nettertech-events' ),
				$operation,
				$entity,
				esc_html( $this->db->last_error )
			);
		}

		return sprintf(
			/* translators: 1: operation (insert/update), 2: entity type */
			esc_html__( 'Failed to %1$s %2$s. Please try again or contact support.', 'nettertech-events' ),
			$operation,
			$entity
		);
	}

	// =========================================================================
	// Core CRUD Methods
	// =========================================================================

	/**
	 * Find a ticket type by ID.
	 *
	 * Uses identity map for per-request deduplication and persistent
	 * object cache for cross-request performance.
	 *
	 * @param int $id Ticket type ID.
	 * @return TicketType|null
	 */
	public function find( int $id ): ?TicketType {
		// Check identity map first (per-request).
		$cached_ticket = $this->recalled( $id );
		if ( $cached_ticket instanceof TicketType ) {
			return $cached_ticket;
		}

		// Check persistent object cache (cross-request).
		$cache_key = 'ticket_type_' . $id;
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );
		if ( false !== $cached ) {
			$ticket_type = TicketType::from_row( $cached );
			$this->remember( $id, $ticket_type );
			return $ticket_type;
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

		$ticket_type = TicketType::from_row( $row );
		$this->remember( $id, $ticket_type );

		wp_cache_set( $cache_key, $row, CacheManager::CACHE_GROUP, CacheManager::TTL_TICKET_TYPE );

		return $ticket_type;
	}

	/**
	 * Save a ticket type (insert or update).
	 *
	 * @param TicketType $ticket_type Ticket type to save.
	 * @return TicketType The saved ticket type with ID populated.
	 * @throws ValidationException If validation fails.
	 * @throws DatabaseException   If save fails.
	 */
	public function save( TicketType $ticket_type ): TicketType {
		// A non-fixed tier has no capacity of its own, so it must not carry one into
		// storage. Every writer — the metabox, the saver, templates, duplication —
		// reaches the table through here, which is why the rule lives here and not in
		// each of them.
		$ticket_type->normalize_capacity();

		$errors = $ticket_type->validate();
		if ( ! empty( $errors ) ) {
			throw ValidationException::fromErrors( array_map( 'esc_html', $errors ) );
		}

		$data    = $ticket_type->to_array();
		$formats = $ticket_type->get_formats();

		$is_new = ( null === $ticket_type->id );

		if ( $is_new ) {
			$result = $this->db->insert( $this->table, $data, $formats );

			if ( false === $result ) {
				throw DatabaseException::insertFailed( 'ticket type', esc_html( $this->format_db_error( 'insert', 'ticket type' ) ) );
			}

			$ticket_type->id = (int) $this->db->insert_id;
		} else {
			$result = $this->db->update(
				$this->table,
				$data,
				array( 'id' => $ticket_type->id ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				throw DatabaseException::updateFailed( 'ticket type', (int) $ticket_type->id, esc_html( $this->format_db_error( 'update', 'ticket type' ) ) );
			}
		}

		// Invalidate individual ticket type cache.
		if ( $ticket_type->id ) {
			$this->forget( $ticket_type->id );
			wp_cache_delete( 'ticket_type_' . $ticket_type->id, CacheManager::CACHE_GROUP );
		}

		// Invalidate occurrence cache for this ticket type.
		if ( $ticket_type->occurrence_id ) {
			wp_cache_delete( 'ticket_types_occurrence_' . $ticket_type->occurrence_id, CacheManager::CACHE_GROUP );
		}

		if ( $is_new ) {
			/**
			 * Fires after a ticket type is created.
			 *
			 * Every writer (metabox, saver, templates, duplication) persists
			 * through this repository, so this fires for all creation paths.
			 *
			 * @since 1.1.2
			 *
			 * @param int                  $ticket_type_id Ticket type ID.
			 * @param array<string, mixed> $data           Persisted column data.
			 */
			do_action( Hooks::TICKET_TYPE_CREATED, (int) $ticket_type->id, $data );
		} else {
			/**
			 * Fires after a ticket type is updated.
			 *
			 * @since 1.1.2
			 *
			 * @param int                  $ticket_type_id Ticket type ID.
			 * @param array<string, mixed> $data           Persisted column data.
			 */
			do_action( Hooks::TICKET_TYPE_UPDATED, (int) $ticket_type->id, $data );
		}

		return $ticket_type;
	}

	/**
	 * Delete a ticket type.
	 *
	 * @param int $id Ticket type ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool {
		$ticket_type = $this->find( $id );

		if ( null === $ticket_type ) {
			return false;
		}

		/**
		 * Fires when a ticket type is deleted, while its row (and any linked
		 * WooCommerce product) is still resolvable. Listeners that look the
		 * ticket type up by ID — e.g. ProductManager::delete_product — depend on
		 * this firing BEFORE the row is removed.
		 *
		 * @since 1.0.3
		 *
		 * @param int    $id   Ticket type ID being deleted.
		 * @param string $name Ticket type name (for activity logging).
		 */
		do_action( Hooks::TICKET_TYPE_DELETED, $id, $ticket_type->name );

		$result = $this->db->delete(
			$this->table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false !== $result ) {
			$this->forget( $id );
			wp_cache_delete( 'ticket_type_' . $id, CacheManager::CACHE_GROUP );

			if ( $ticket_type->occurrence_id ) {
				wp_cache_delete( 'ticket_types_occurrence_' . $ticket_type->occurrence_id, CacheManager::CACHE_GROUP );
			}
		}

		return false !== $result;
	}

	/**
	 * Update ticket type status.
	 *
	 * @param int    $id     Ticket type ID.
	 * @param string $status New status.
	 * @return bool True on success.
	 */
	public function update_status( int $id, string $status ): bool {
		if ( ! in_array( $status, TicketType::STATUSES, true ) ) {
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
	 * Link a ticket type to a WooCommerce product.
	 *
	 * @param int      $ticket_type_id Ticket type ID.
	 * @param int      $product_id     WooCommerce product ID.
	 * @param int|null $variation_id   WooCommerce variation ID.
	 * @return bool True on success.
	 */
	public function link_to_product( int $ticket_type_id, int $product_id, ?int $variation_id = null ): bool {
		$result = $this->db->update(
			$this->table,
			array(
				'wc_product_id'   => $product_id,
				'wc_variation_id' => $variation_id,
			),
			array( 'id' => $ticket_type_id ),
			array( '%d', '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete all ticket types for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Number deleted.
	 */
	public function delete_for_occurrence( int $occurrence_id ): int {
		foreach ( $this->for_occurrence( $occurrence_id, array( 'status' => null ) ) as $ticket_type ) {
			// for_occurrence() also returns event-scoped (series) ticket types
			// whose occurrence_id is NULL. The bulk delete below only removes
			// rows whose occurrence_id matches, so only fire the deletion action
			// for the rows that are actually being deleted.
			if ( null === $ticket_type->id || $occurrence_id !== $ticket_type->occurrence_id ) {
				continue;
			}

			/** This action is documented in includes/Repositories/TicketTypeRepository.php */
			do_action( Hooks::TICKET_TYPE_DELETED, (int) $ticket_type->id, $ticket_type->name );

			$this->forget( (int) $ticket_type->id );
			wp_cache_delete( 'ticket_type_' . (int) $ticket_type->id, CacheManager::CACHE_GROUP );
		}

		$result = $this->db->delete(
			$this->table,
			array( 'occurrence_id' => $occurrence_id ),
			array( '%d' )
		);

		wp_cache_delete( 'ticket_types_occurrence_' . $occurrence_id, CacheManager::CACHE_GROUP );

		return false !== $result ? $result : 0;
	}

	// =========================================================================
	// Query Methods (Delegated to TicketTypeQueryRepository)
	// =========================================================================

	/**
	 * Get ticket types for an occurrence.
	 *
	 * @param int                  $occurrence_id Occurrence ID.
	 * @param array<string, mixed> $args          Query arguments.
	 * @return array<TicketType>
	 */
	public function for_occurrence( int $occurrence_id, array $args = array() ): array {
		return $this->query_repo->for_occurrence( $occurrence_id, $args );
	}

	/**
	 * Get ticket types for multiple occurrences (N+1 prevention).
	 *
	 * @param array<int> $occurrence_ids Occurrence IDs.
	 * @param string     $status         Optional status filter.
	 * @return array<int, array<TicketType>> Grouped by occurrence_id.
	 */
	public function for_multiple_occurrences( array $occurrence_ids, ?string $status = null ): array {
		return $this->query_repo->for_multiple_occurrences( $occurrence_ids, $status );
	}

	/**
	 * Get active ticket types for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<TicketType>
	 */
	public function get_active_for_occurrence( int $occurrence_id ): array {
		return $this->query_repo->get_active_for_occurrence( $occurrence_id );
	}

	/**
	 * Get ticket types currently on sale for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<TicketType>
	 */
	public function get_on_sale_for_occurrence( int $occurrence_id ): array {
		return $this->query_repo->get_on_sale_for_occurrence( $occurrence_id );
	}

	/**
	 * Get ticket types currently on sale for multiple occurrences (N+1 prevention).
	 *
	 * @since 1.0.3
	 *
	 * @param array<int> $occurrence_ids Occurrence IDs.
	 * @return array<int, array<TicketType>> Map of occurrence_id => list of ticket types.
	 */
	public function get_on_sale_for_occurrences( array $occurrence_ids ): array {
		return $this->query_repo->get_on_sale_for_occurrences( $occurrence_ids );
	}

	/**
	 * Get ticket types for an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array<TicketType>
	 */
	public function for_event( int $event_id, array $args = array() ): array {
		return $this->query_repo->for_event( $event_id, $args );
	}

	/**
	 * Compute the shared-pool-aware total capacity per event for a batch of events.
	 *
	 * @since 1.0.3
	 *
	 * @param array<int> $event_ids Event IDs to aggregate.
	 * @return array<int, array{capacity: ?int, has_unlimited: bool, configured: bool}>
	 */
	public function event_capacity_for_events( array $event_ids ): array {
		return $this->query_repo->event_capacity_for_events( $event_ids );
	}

	/**
	 * Get ticket templates for an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array<TicketType>
	 */
	public function get_templates( int $event_id, array $args = array() ): array {
		return $this->query_repo->get_templates( $event_id, $args );
	}

	/**
	 * Find by WooCommerce product ID.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return TicketType|null
	 */
	public function find_by_product( int $product_id ): ?TicketType {
		return $this->query_repo->find_by_product( $product_id );
	}

	/**
	 * Find by WooCommerce variation ID.
	 *
	 * @param int $variation_id WooCommerce variation ID.
	 * @return TicketType|null
	 */
	public function find_by_variation( int $variation_id ): ?TicketType {
		return $this->query_repo->find_by_variation( $variation_id );
	}

	/**
	 * Check if occurrence has any free ticket types.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool
	 */
	public function occurrence_has_free_tickets( int $occurrence_id ): bool {
		return $this->query_repo->occurrence_has_free_tickets( $occurrence_id );
	}

	/**
	 * Check if occurrence has only free ticket types.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool
	 */
	public function occurrence_is_free( int $occurrence_id ): bool {
		return $this->query_repo->occurrence_is_free( $occurrence_id );
	}

	// =========================================================================
	// Template Operations
	// =========================================================================

	/**
	 * Create an occurrence ticket from a template.
	 *
	 * @param TicketType $template      Template ticket type.
	 * @param int        $occurrence_id Occurrence ID.
	 * @return TicketType New ticket type instance (unsaved).
	 * @throws ValidationException If not a template.
	 */
	public function create_from_template( TicketType $template, int $occurrence_id ): TicketType {
		if ( TicketTypeScope::TEMPLATE->value !== $template->scope ) {
			throw ValidationException::invalidField( 'scope', esc_html( (string) $template->scope ), 'Expected template scope' );
		}

		$new_ticket                = new TicketType();
		$new_ticket->scope         = TicketTypeScope::OCCURRENCE->value;
		$new_ticket->event_id      = $template->event_id;
		$new_ticket->occurrence_id = $occurrence_id;
		$new_ticket->template_id   = $template->id;
		$new_ticket->name          = $template->name;
		$new_ticket->description   = $template->description;
		$new_ticket->price         = $template->price;
		$new_ticket->capacity_type = $template->capacity_type;
		$new_ticket->capacity      = $template->capacity;
		$new_ticket->sale_start    = $template->sale_start;
		$new_ticket->sale_end      = $template->sale_end;
		$new_ticket->min_per_order = $template->min_per_order;
		$new_ticket->max_per_order = $template->max_per_order;
		$new_ticket->sort_order    = $template->sort_order;
		$new_ticket->status        = $template->status;
		$new_ticket->stock_status  = 'in_stock';
		$new_ticket->sold_count    = 0;
		$new_ticket->wc_product_id = null;

		return $new_ticket;
	}

	// =========================================================================
	// Stock Operations (Delegated to TicketTypeStockRepository)
	// =========================================================================

	/**
	 * Get sold count for a ticket type.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Number sold.
	 */
	public function get_sold_count( int $ticket_type_id ): int {
		return $this->stock_repo->get_sold_count( $ticket_type_id );
	}

	/**
	 * Get available count for a ticket type.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int|null Available (null = unlimited).
	 */
	public function get_available_count( int $ticket_type_id ): ?int {
		return $this->stock_repo->get_available_count( $ticket_type_id );
	}

	/**
	 * Check if a ticket type has availability.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity to check.
	 * @return bool
	 */
	public function has_availability( int $ticket_type_id, int $quantity = 1 ): bool {
		return $this->stock_repo->has_availability( $ticket_type_id, $quantity );
	}

	/**
	 * Check if a ticket type is sold out.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return bool
	 */
	public function is_sold_out( int $ticket_type_id ): bool {
		return $this->stock_repo->is_sold_out( $ticket_type_id );
	}

	/**
	 * Increment sold count and update stock status.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity sold.
	 * @return bool
	 */
	public function increment_sold_count( int $ticket_type_id, int $quantity = 1 ): bool {
		$result = $this->stock_repo->increment_sold_count( $ticket_type_id, $quantity );

		if ( $result ) {
			$this->invalidate_stock_caches( $ticket_type_id );
		}

		return $result;
	}

	/**
	 * Decrement sold count and update stock status.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity to release.
	 * @return bool
	 */
	public function decrement_sold_count( int $ticket_type_id, int $quantity = 1 ): bool {
		$result = $this->stock_repo->decrement_sold_count( $ticket_type_id, $quantity );

		if ( $result ) {
			$this->invalidate_stock_caches( $ticket_type_id );
		}

		return $result;
	}

	/**
	 * Recalculate sold_count from attendee data.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return bool
	 */
	public function recalculate_sold_count( int $ticket_type_id ): bool {
		$result = $this->stock_repo->recalculate_sold_count( $ticket_type_id );

		if ( $result ) {
			$this->invalidate_stock_caches( $ticket_type_id );
		}

		return $result;
	}

	/**
	 * Invalidate identity map and object caches after a stock mutation.
	 *
	 * Stock mutations (increment/decrement/recalculate_sold_count) bypass
	 * save() and therefore do not touch the identity map or the cached
	 * ticket_type rows — without this, same-request reads after a stock
	 * change see stale sold_count. Mirrors the invalidation logic in save()
	 * and the listener chain bound to Hooks::CAPACITY_RESERVED.
	 *
	 * NTE-036: required for RSVP submit → capacity re-check to see the
	 * updated sold_count within the same request lifecycle.
	 *
	 * @since 3.7.0
	 *
	 * @param int $ticket_type_id Ticket type ID that was mutated.
	 * @return void
	 */
	private function invalidate_stock_caches( int $ticket_type_id ): void {
		// Per-request identity map — same-instance same-request stale reads.
		$this->forget( $ticket_type_id );

		// Cross-request ticket_type row cache.
		wp_cache_delete( 'ticket_type_' . $ticket_type_id, CacheManager::CACHE_GROUP );

		// Per-ticket capacity summaries (aggregated from sold_count).
		wp_cache_delete( 'capacity_summary_' . $ticket_type_id, CacheManager::CACHE_GROUP );
		wp_cache_delete( 'capacity_available_' . $ticket_type_id, CacheManager::CACHE_GROUP );

		// Invalidate the occurrence-level aggregates that cache TicketType rows
		// directly (bypassing find()). Requires a lookup to resolve occurrence_id.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table; targeted single-row lookup for cache invalidation.
		$occurrence_id = $this->db->get_var(
			$this->db->prepare(
				"SELECT occurrence_id FROM {$this->table} WHERE id = %d",
				$ticket_type_id
			)
		);

		if ( $occurrence_id ) {
			wp_cache_delete( 'ticket_types_occurrence_' . (int) $occurrence_id, CacheManager::CACHE_GROUP );
			wp_cache_delete( 'capacity_occurrence_' . (int) $occurrence_id, CacheManager::CACHE_GROUP );
		}
	}

	// =========================================================================
	// Shared Capacity (Delegated to TicketTypeStockRepository)
	// =========================================================================

	/**
	 * Get sum of fixed capacity allocations for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int
	 */
	public function get_fixed_capacity_sum( int $occurrence_id ): int {
		return $this->stock_repo->get_fixed_capacity_sum( $occurrence_id );
	}

	/**
	 * Get sum of sold_count for shared ticket types.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int
	 */
	public function get_shared_sold_count( int $occurrence_id ): int {
		return $this->stock_repo->get_shared_sold_count( $occurrence_id );
	}

	/**
	 * Get ticket types with shared capacity.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<TicketType>
	 */
	public function get_shared_for_occurrence( int $occurrence_id ): array {
		return $this->stock_repo->get_shared_for_occurrence( $occurrence_id );
	}

	/**
	 * Check for unlimited fixed tickets.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool
	 */
	public function has_unlimited_fixed_tickets( int $occurrence_id ): bool {
		return $this->stock_repo->has_unlimited_fixed_tickets( $occurrence_id );
	}

	/**
	 * Check for unlimited capacity tickets.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool
	 */
	public function has_unlimited_tickets( int $occurrence_id ): bool {
		return $this->stock_repo->has_unlimited_tickets( $occurrence_id );
	}

	/**
	 * Get the capacity type for a ticket type.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return string|null Capacity type string, or null if not found.
	 */
	public function get_capacity_type( int $ticket_type_id ): ?string {
		$ticket_type = $this->find( $ticket_type_id );

		if ( null === $ticket_type ) {
			return null;
		}

		return $ticket_type->capacity_type;
	}
}
