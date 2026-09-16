<?php
/**
 * WooCommerce Product Manager.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CapacityCalculatorInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\HouseCapacityRepositoryInterface;
use NetterTechEvents\Services\Capacity\HouseRule;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Manages WooCommerce products for ticket types.
 *
 * Creates virtual products linked to ticket types for sales tracking.
 *
 * @since 0.8.0
 * @api
 */
class ProductManager {

	/**
	 * Product type for event tickets.
	 */
	public const PRODUCT_TYPE = 'simple';

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepository
	 */
	private TicketTypeRepository $ticket_type_repo;

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepository
	 */
	private OccurrenceRepository $occurrence_repo;

	/**
	 * Event-category → product_cat mapper (NTE-129).
	 *
	 * @var CategoryProductCatMapper|null
	 */
	private ?CategoryProductCatMapper $product_cat_mapper;

	/**
	 * House capacity repository, when available.
	 *
	 * @var HouseCapacityRepositoryInterface|null
	 */
	private ?HouseCapacityRepositoryInterface $house_repo;

	/**
	 * Capacity calculator, when available.
	 *
	 * @var CapacityCalculatorInterface|null
	 */
	private ?CapacityCalculatorInterface $calculator;

	/**
	 * Event repository for series-pass product naming. Injected; the
	 * ServiceRegistry fallback covers un-provided construction (tests).
	 *
	 * @var EventRepositoryInterface|null
	 */
	private ?EventRepositoryInterface $event_repo;

	/**
	 * Constructor.
	 *
	 * @param TicketTypeRepository                  $ticket_type_repo   Ticket type repository.
	 * @param OccurrenceRepository                  $occurrence_repo    Occurrence repository.
	 * @param CategoryProductCatMapper|null         $product_cat_mapper Event-category mapper (NTE-129).
	 * @param HouseCapacityRepositoryInterface|null $house_repo         House capacity repository.
	 * @param CapacityCalculatorInterface|null      $calculator         Capacity calculator.
	 * @param EventRepositoryInterface|null         $event_repo         Event repository (ServiceRegistry fallback when null).
	 */
	public function __construct(
		TicketTypeRepository $ticket_type_repo,
		OccurrenceRepository $occurrence_repo,
		?CategoryProductCatMapper $product_cat_mapper = null,
		?HouseCapacityRepositoryInterface $house_repo = null,
		?CapacityCalculatorInterface $calculator = null,
		?EventRepositoryInterface $event_repo = null
	) {
		$this->ticket_type_repo   = $ticket_type_repo;
		$this->occurrence_repo    = $occurrence_repo;
		$this->product_cat_mapper = $product_cat_mapper;
		$this->house_repo         = $house_repo;
		$this->calculator         = $calculator;
		$this->event_repo         = $event_repo;
	}

	/**
	 * Meta key for linking product to occurrence.
	 */
	public const META_OCCURRENCE_ID = '_nettertech_events_occurrence_id';

	/**
	 * Meta key for linking product to ticket type.
	 */
	public const META_TICKET_TYPE_ID = '_nettertech_events_ticket_type_id';

	/**
	 * Sync WooCommerce product for a ticket type.
	 *
	 * Creates or updates the linked WC product.
	 *
	 * @param TicketType  $ticket_type Ticket type.
	 * @param Occurrence  $occurrence  Parent occurrence.
	 * @param string|null $admin_sku   Optional admin-supplied SKU (NTE-114). Applied
	 *                                 only when the product has no SKU yet and the
	 *                                 value is unique; otherwise a default is generated.
	 *                                 Hook callbacks (2-arg) pass null and auto-generate.
	 * @return int WooCommerce product ID.
	 */
	public function sync_product( TicketType $ticket_type, Occurrence $occurrence, ?string $admin_sku = null ): int {
		// Get or create product.
		$product_id = $ticket_type->wc_product_id;
		$product    = null;

		if ( $product_id ) {
			$product = wc_get_product( $product_id );
		}

		if ( ! $product ) {
			$product = new \WC_Product_Simple();
		}

		// Get event title for product name.
		$full_occurrence = null !== $occurrence->id ? $this->occurrence_repo->find_with_event( $occurrence->id ) : null;
		$event_title     = $full_occurrence && $full_occurrence->get_event()
			? $full_occurrence->get_event()->title
			: __( 'Event', 'nettertech-events' );

		// Build the product title from the bare tier name. A name that already
		// carries this occurrence's "{event} - {date} - " prefix (a migrated
		// product whose composed title was adopted as the tier name, NTE-235) is
		// reduced to the bare tier first, so the prefix is never stacked.
		$title_prefix  = self::occurrence_title_prefix( $event_title, $occurrence->get_formatted_date() );
		$tier_name     = self::strip_title_prefix( $ticket_type->name, $title_prefix );
		$product_title = sprintf(
			/* translators: 1: event title, 2: date, 3: ticket type name */
			__( '%1$s - %2$s - %3$s', 'nettertech-events' ),
			$event_title,
			$occurrence->get_formatted_date(),
			$tier_name
		);

		// Set product properties. A product the migrator adopted keeps the name
		// the site gave it (NTE-235): sync never renames an adopted product.
		if ( '1' !== (string) $product->get_meta( MetaKeys::ADOPTED, true ) ) {
			$product->set_name( $product_title );
		}
		$product->set_status( 'active' === $ticket_type->status ? 'publish' : 'draft' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_price( (string) $ticket_type->price );
		$product->set_regular_price( (string) $ticket_type->price );
		$product->set_virtual( true );
		$product->set_sold_individually( false );

		// Set product image from event (occurrence override takes precedence).
		$event_image_id = 0;
		if ( $full_occurrence ) {
			$event_image_id = $full_occurrence->get_featured_image_id();
			if ( ! $event_image_id && $full_occurrence->get_event() ) {
				$event_image_id = (int) $full_occurrence->get_event()->featured_image_id;
			}
		}
		if ( ! $event_image_id ) {
			$event_image_id = $this->default_ticket_image_id();
		}
		if ( $event_image_id ) {
			$product->set_image_id( $event_image_id );
		}

		// Set stock management. The seats to publish are what the room has left for this
		// tier, not the tier's own column: a shared tier owns no capacity, but it is not
		// therefore unlimited — the room still runs out (ADR-019). Reading the column
		// directly here published a stale number as real stock, and left shared tiers
		// with stock management off entirely.
		$stock = null === $ticket_type->id ? null : $this->available_for_stock( $ticket_type->id );

		if ( null !== $stock ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
			$product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
			$product->set_backorders( 'no' );
		} else {
			$product->set_manage_stock( false );
			$product->set_stock_status( 'instock' );
		}

		// Store NetterTechEvents meta via WC CRUD API per ADR-008 — future-proof against HPOS product meta migration.
		$product->update_meta_data( self::META_OCCURRENCE_ID, (string) $occurrence->id );
		$product->update_meta_data( self::META_TICKET_TYPE_ID, (string) $ticket_type->id );
		$product->update_meta_data( '_nettertech_events_is_event_ticket', 'yes' );
		$product->update_meta_data( MetaKeys::TIER_NAME, $tier_name );

		$event_id = $full_occurrence ? (int) $full_occurrence->event_id : 0;
		if ( $event_id > 0 ) {
			$product->update_meta_data( MetaKeys::EVENT_ID, (string) $event_id );
		}

		// SKU (NTE-114): generated and locked on first save only. Once the product
		// has a non-empty SKU it is never auto-regenerated, and slug edits to the
		// event or ticket type do NOT propagate — to change a SKU, create a new
		// ticket type. Admin-supplied SKUs are honored only while still empty.
		if ( '' === (string) $product->get_sku() ) {
			$event_slug = $full_occurrence && $full_occurrence->get_event()
				? (string) $full_occurrence->get_event()->slug
				: '';
			$sku        = $this->resolve_sku( $admin_sku, $ticket_type, $event_slug, $product->get_id() );
			if ( '' !== $sku ) {
				$product->set_sku( $sku );
			}
		}

		// NTE-129: mirror the event's categories onto the ticket product so
		// category-scoped coupons match without per-product listing.
		if ( $event_id > 0 && null !== $this->product_cat_mapper ) {
			$this->apply_managed_product_cats( $product, $event_id );
		}

		// Single save() persists product properties and all queued meta updates.
		$product_id = $product->save();

		// Update ticket type with product ID.
		if ( $ticket_type->wc_product_id !== $product_id && null !== $ticket_type->id ) {
			$this->ticket_type_repo->link_to_product( $ticket_type->id, $product_id );
		}

		return $product_id;
	}

	/**
	 * Assign the NTE-managed `product_cat` set to a ticket product (NTE-129).
	 *
	 * No-clobber semantics: product categories the operator added manually (those
	 * without the back-reference meta) are preserved; only the NTE-owned set is
	 * managed. The new set is filterable.
	 *
	 * @param \WC_Product $product  Ticket product (unsaved).
	 * @param int         $event_id Linked event ID.
	 * @return void
	 */
	private function apply_managed_product_cats( \WC_Product $product, int $event_id ): void {
		if ( null === $this->product_cat_mapper ) {
			return;
		}

		$resolved = $this->product_cat_mapper->resolve_product_cat_ids( $event_id );
		$managed  = $this->product_cat_mapper->managed_term_ids();
		$existing = $product->get_category_ids();

		$new_set = self::compute_managed_category_set( $existing, $managed, $resolved );

		/**
		 * Filter the final `product_cat` term-ID set for a ticket product.
		 *
		 * @since 1.1.2
		 *
		 * @param array<int>  $new_set  Resolved + preserved term IDs.
		 * @param int         $event_id Linked event ID.
		 * @param \WC_Product $product  Ticket product.
		 */
		$new_set = apply_filters( 'nettertech_events_product_cat_ids', $new_set, $event_id, $product );

		$product->set_category_ids( array_map( 'intval', (array) $new_set ) );
	}

	/**
	 * Compute the new product-category set under no-clobber semantics.
	 *
	 * Drops NTE-managed terms no longer resolved (e.g. category removed from the
	 * event), keeps the freshly resolved managed set, and preserves any manually
	 * added (non-managed) terms.
	 *
	 * @param array<int> $existing Current product category IDs.
	 * @param array<int> $managed  All NTE-owned term IDs (carry back-ref meta).
	 * @param array<int> $resolved Term IDs mirrored from the event's categories.
	 * @return array<int> The new category ID set.
	 */
	public static function compute_managed_category_set( array $existing, array $managed, array $resolved ): array {
		$existing = array_map( 'intval', $existing );
		$managed  = array_map( 'intval', $managed );
		$resolved = array_map( 'intval', $resolved );

		// Preserve only manually-added categories (existing, not NTE-owned).
		$preserved = array_values( array_diff( $existing, $managed ) );

		return array_values( array_unique( array_merge( $preserved, $resolved ) ) );
	}

	/**
	 * Re-sync the `product_cat` set for all of an event's ticket products (NTE-129).
	 *
	 * Used by the event-category-change trigger and the wp-cli backfill so the
	 * mirrored categories stay current when an event's categories change.
	 *
	 * @param int $event_id Event ID.
	 * @return int Number of products updated.
	 */
	public function resync_event_product_categories( int $event_id ): int {
		if ( null === $this->product_cat_mapper || $event_id <= 0 ) {
			return 0;
		}

		$count = 0;
		foreach ( $this->get_event_ticket_products( $event_id ) as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$this->apply_managed_product_cats( $product, $event_id );
			$product->save();
			++$count;
		}

		return $count;
	}

	/**
	 * Fetch the ticket products linked to an event.
	 *
	 * Products remain a CPT under HPOS, so a meta-keyed post query is the
	 * reliable selector. Protected to allow test substitution without a live DB.
	 *
	 * @param int $event_id Event ID.
	 * @return array<\WC_Product> Ticket products.
	 */
	protected function get_event_ticket_products( int $event_id ): array {
		$ids = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded admin/CLI ticket-product lookup.
					'relation' => 'AND',
					array(
						'key'   => '_nettertech_events_is_event_ticket',
						'value' => 'yes',
					),
					array(
						'key'   => MetaKeys::EVENT_ID,
						'value' => (string) $event_id,
					),
				),
			)
		);

		$products = array();
		foreach ( (array) $ids as $id ) {
			$product = wc_get_product( (int) $id );
			if ( $product ) {
				$products[] = $product;
			}
		}

		return $products;
	}

	/**
	 * Fetch all NTE ticket products across all events.
	 *
	 * Protected to allow test substitution without a live DB.
	 *
	 * @return array<\WC_Product> Ticket products.
	 */
	protected function get_all_ticket_products(): array {
		$ids = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded CLI ticket-product backfill.
					array(
						'key'   => '_nettertech_events_is_event_ticket',
						'value' => 'yes',
					),
				),
			)
		);

		$products = array();
		foreach ( (array) $ids as $id ) {
			$product = wc_get_product( (int) $id );
			if ( $product ) {
				$products[] = $product;
			}
		}

		return $products;
	}

	/**
	 * Backfill mirrored `product_cat` assignments on existing ticket products (NTE-129).
	 *
	 * Used by the wp-cli command (FR-007). In dry-run mode no terms are created
	 * and no products are saved — only already-linked terms are considered, so
	 * the reported `updated` count is the conservative blast radius. A live run
	 * creates/adopts any missing mirrored terms and persists the assignments.
	 *
	 * @param int|null $event_id Optional event ID to limit the backfill.
	 * @param bool     $dry_run  When true (default), report only — no writes.
	 * @return array{products:int,updated:int,dry_run:bool} Summary.
	 */
	public function backfill_product_categories( ?int $event_id = null, bool $dry_run = true ): array {
		if ( null === $this->product_cat_mapper ) {
			return array(
				'products' => 0,
				'updated'  => 0,
				'dry_run'  => $dry_run,
			);
		}

		$products = ( null !== $event_id && $event_id > 0 )
			? $this->get_event_ticket_products( $event_id )
			: $this->get_all_ticket_products();

		$managed = $this->product_cat_mapper->managed_term_ids();
		$updated = 0;

		foreach ( $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$linked_event = (int) $product->get_meta( MetaKeys::EVENT_ID, true );
			if ( $linked_event <= 0 ) {
				continue;
			}

			$before   = array_map( 'intval', $product->get_category_ids() );
			$resolved = $this->product_cat_mapper->resolve_product_cat_ids( $linked_event, ! $dry_run );
			$after    = self::compute_managed_category_set( $before, $managed, $resolved );

			$before_sorted = $before;
			$after_sorted  = $after;
			sort( $before_sorted );
			sort( $after_sorted );
			if ( $before_sorted === $after_sorted ) {
				continue;
			}

			++$updated;
			if ( ! $dry_run ) {
				$product->set_category_ids( $after );
				$product->save();
			}
		}

		return array(
			'products' => count( $products ),
			'updated'  => $updated,
			'dry_run'  => $dry_run,
		);
	}

	/**
	 * Resolve the SKU to assign to a ticket product on first save (NTE-114).
	 *
	 * Prefers a unique admin-supplied SKU; otherwise generates a collision-safe
	 * default. Never called once a product already has a SKU (lock enforced by
	 * the caller).
	 *
	 * @param string|null $admin_sku          Admin-supplied SKU candidate (may be null/empty).
	 * @param TicketType  $ticket_type        Ticket type (supplies the ticket slug).
	 * @param string      $event_slug         Parent event slug.
	 * @param int         $exclude_product_id Product ID to exclude from the uniqueness check (its own id).
	 * @return string Resolved unique SKU, or '' when no slug material is available.
	 */
	private function resolve_sku( ?string $admin_sku, TicketType $ticket_type, string $event_slug, int $exclude_product_id ): string {
		$admin_sku = null === $admin_sku ? '' : trim( $admin_sku );

		if ( '' !== $admin_sku && $this->sku_is_unique( $exclude_product_id, $admin_sku ) ) {
			return $admin_sku;
		}

		return $this->build_default_sku( $ticket_type, $event_slug, $exclude_product_id );
	}

	/**
	 * Build a default ticket SKU as `{event_slug}--{ticket_slug}` (NTE-114).
	 *
	 * Resolves collisions by appending a numeric suffix (`-2`, `-3`, ...) via
	 * WooCommerce's `wc_product_has_unique_sku()`.
	 *
	 * @param TicketType $ticket_type        Ticket type (supplies the ticket slug).
	 * @param string     $event_slug         Parent event slug.
	 * @param int        $exclude_product_id Product ID to exclude from the uniqueness check.
	 * @return string Unique SKU, or '' when neither slug yields any material.
	 */
	private function build_default_sku( TicketType $ticket_type, string $event_slug, int $exclude_product_id ): string {
		$ticket_slug = sanitize_title( $ticket_type->name );
		$event_slug  = sanitize_title( $event_slug );

		$base = '' !== $event_slug ? $event_slug . '--' . $ticket_slug : $ticket_slug;
		$base = trim( $base, '-' );

		if ( '' === $base ) {
			return '';
		}

		$candidate = $base;
		$suffix    = 2;
		while ( ! $this->sku_is_unique( $exclude_product_id, $candidate ) ) {
			$candidate = $base . '-' . $suffix;
			++$suffix;
			if ( $suffix > 100 ) {
				// Pathological-collision guard: fall back to a product-id-scoped form.
				$candidate = $base . '-' . $exclude_product_id . '-' . wp_rand( 1000, 9999 );
				break;
			}
		}

		return $candidate;
	}

	/**
	 * Whether a SKU is unique across the store (defensive when WC helper is absent).
	 *
	 * @param int    $exclude_product_id Product ID to exclude (its own id).
	 * @param string $sku                SKU candidate.
	 * @return bool True if unique (or the WC helper is unavailable).
	 */
	private function sku_is_unique( int $exclude_product_id, string $sku ): bool {
		if ( ! function_exists( 'wc_product_has_unique_sku' ) ) {
			return true;
		}

		return wc_product_has_unique_sku( $exclude_product_id, $sku );
	}

	/**
	 * Delete WooCommerce product for a ticket type (order-aware).
	 *
	 * Products referenced by any existing order line item are trashed
	 * (`delete( false )`) to preserve order/refund history; products with no
	 * order references are force-deleted (`delete( true )`). Acts only on
	 * NetterTechEvents ticket products — a defensive scope guard refuses to
	 * touch any product whose `_nettertech_events_is_event_ticket` meta is
	 * absent, in case a `wc_product_id` link were ever corrupted.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return bool True when the product was handled (or there was nothing to delete); false otherwise.
	 */
	public function delete_product( int $ticket_type_id ): bool {
		$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );

		if ( ! $ticket_type || ! $ticket_type->wc_product_id ) {
			return false;
		}

		$product = wc_get_product( $ticket_type->wc_product_id );

		if ( ! $product ) {
			// Nothing to delete — link points at a product that no longer exists.
			return true;
		}

		// Scope guard: never touch a product that is not an NTE ticket product.
		if ( ! $this->is_event_ticket( $product ) ) {
			return false;
		}

		$product_id = (int) $ticket_type->wc_product_id;

		if ( $this->product_has_orders( $product_id ) ) {
			// Order-referenced → trash, preserving order and refund history.
			$product->delete( false );
			DebugLogger::log(
				sprintf(
					'Trashed order-referenced ticket product #%1$d (ticket type #%2$d) to preserve order history.',
					$product_id,
					$ticket_type_id
				),
				'ProductManager'
			);
		} else {
			// No order references → force-delete.
			$product->delete( true );
			DebugLogger::log(
				sprintf(
					'Force-deleted ticket product #%1$d (ticket type #%2$d); no order references found.',
					$product_id,
					$ticket_type_id
				),
				'ProductManager'
			);
		}

		return true;
	}

	/**
	 * Whether any order line item references the given product.
	 *
	 * Queries WooCommerce's order-item tables directly. These tables
	 * (`woocommerce_order_items` / `woocommerce_order_itemmeta`) hold line-item
	 * data identically under both legacy post-based storage and HPOS (order
	 * items were never stored in postmeta), so the check is storage-mode-safe.
	 *
	 * The check is intentionally status-agnostic: a reference from an order of
	 * ANY status (including pending, on-hold, failed, cancelled, or refunded)
	 * counts as history worth preserving — so the linked product is trashed
	 * rather than force-deleted. WooCommerce is necessarily active here (the
	 * product was just loaded via `wc_get_product()`), so the tables exist.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return bool True if at least one order line item references the product.
	 */
	private function product_has_orders( int $product_id ): bool {
		global $wpdb;

		$order_items_table    = $wpdb->prefix . 'woocommerce_order_items';
		$order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from trusted $wpdb->prefix; product id bound via prepare().
		$sql = $wpdb->prepare(
			"SELECT oi.order_item_id
			 FROM {$order_items_table} oi
			 INNER JOIN {$order_itemmeta_table} oim ON oi.order_item_id = oim.order_item_id
			 WHERE oi.order_item_type = 'line_item'
			   AND oim.meta_key = '_product_id'
			   AND oim.meta_value = %d
			 LIMIT 1",
			$product_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL prepared above; order-reference lookup is point-in-time and must not be cached.
		$found = $wpdb->get_var( $sql );

		return null !== $found;
	}

	/**
	 * Get WooCommerce product for a ticket type.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return \WC_Product|null
	 */
	public function get_product( int $ticket_type_id ): ?\WC_Product {
		$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );

		if ( ! $ticket_type || ! $ticket_type->wc_product_id ) {
			return null;
		}

		$product = wc_get_product( $ticket_type->wc_product_id );
		return false === $product ? null : $product;
	}

	/**
	 * Create products for all ticket types of an occurrence.
	 *
	 * @param int                $occurrence_id Occurrence ID.
	 * @param bool               $include_free  Include free tickets (default: true).
	 * @param array<int, string> $admin_skus    Tier id => operator-entered SKU, honored
	 *                                          only while the product has none (NTE-114).
	 * @param bool               $existing_only When true, only re-sync tiers that already have a
	 *                                          WooCommerce product; never mint a new one. Used by the
	 *                                          unpublish path so a draft transition reverts existing
	 *                                          products without creating any (R5).
	 * @return array<int> Array of product IDs created.
	 */
	public function create_products_for_occurrence( int $occurrence_id, bool $include_free = true, array $admin_skus = array(), bool $existing_only = false ): array {
		$occurrence = $this->occurrence_repo->find( $occurrence_id );

		if ( ! $occurrence ) {
			return array();
		}

		$ticket_types = $this->ticket_type_repo->for_occurrence( $occurrence_id );

		$product_ids = array();
		foreach ( $ticket_types as $ticket_type ) {
			// Skip free tickets if not included (legacy behavior).
			if ( ! $include_free && $ticket_type->price <= 0 ) {
				continue;
			}
			// Never mint a product on an existing-only (unpublish) resync.
			if ( $existing_only && empty( $ticket_type->wc_product_id ) ) {
				continue;
			}
			$admin_sku     = null !== $ticket_type->id ? ( $admin_skus[ $ticket_type->id ] ?? null ) : null;
			$product_ids[] = $this->sync_product( $ticket_type, $occurrence, $admin_sku );
		}

		return $product_ids;
	}

	/**
	 * Sync the WooCommerce product for an event-scoped ticket type (series pass).
	 *
	 * The occurrence-product twin of {@see sync_product()}. A pass belongs to the
	 * event, not to any one date, so the product carries EVENT_ID and the
	 * IS_SERIES_PASS marker and no occurrence link; the order pipeline reads the
	 * marker to fan an attendee out to every date (NTE-156). Stock is the
	 * pass-aware available count: the pass's own allotment bounded by its
	 * tightest date's room.
	 *
	 * @since 1.1.3
	 *
	 * @param TicketType  $ticket_type Event-scoped ticket type.
	 * @param string|null $admin_sku   Optional admin-supplied SKU, first save only.
	 * @return int WooCommerce product ID, or 0 when the tier is not event-scoped.
	 */
	public function sync_event_product( TicketType $ticket_type, ?string $admin_sku = null ): int {
		if ( TicketTypeScope::EVENT->value !== $ticket_type->scope || null === $ticket_type->event_id ) {
			return 0;
		}

		$product_id = $ticket_type->wc_product_id;
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $product ) {
			$product = new \WC_Product_Simple();
		}

		$event_repo  = $this->event_repo ?? \NetterTechEvents\Core\ServiceRegistry::event_repository();
		$event       = $event_repo->find( (int) $ticket_type->event_id );
		$event_title = $event ? $event->title : __( 'Event', 'nettertech-events' );

		// Same NTE-235 rules as sync_product(): compose from the bare tier name,
		// and never rename a product the migrator adopted.
		$tier_name = self::strip_title_prefix( $ticket_type->name, self::series_pass_title_prefix( $event_title ) );
		if ( '1' !== (string) $product->get_meta( MetaKeys::ADOPTED, true ) ) {
			$product->set_name(
				sprintf(
					/* translators: 1: event title, 2: ticket type name */
					__( '%1$s - Series Pass - %2$s', 'nettertech-events' ),
					$event_title,
					$tier_name
				)
			);
		}
		$product->set_status( 'active' === $ticket_type->status ? 'publish' : 'draft' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_price( (string) $ticket_type->price );
		$product->set_regular_price( (string) $ticket_type->price );
		$product->set_virtual( true );
		$product->set_sold_individually( false );

		$series_image_id = $event && $event->featured_image_id ? (int) $event->featured_image_id : $this->default_ticket_image_id();
		if ( $series_image_id ) {
			$product->set_image_id( $series_image_id );
		}

		$stock = null === $ticket_type->id ? null : $this->available_for_stock( $ticket_type->id );

		if ( null !== $stock ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
			$product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
			$product->set_backorders( 'no' );
		} else {
			$product->set_manage_stock( false );
			$product->set_stock_status( 'instock' );
		}

		$product->update_meta_data( self::META_TICKET_TYPE_ID, (string) $ticket_type->id );
		$product->update_meta_data( '_nettertech_events_is_event_ticket', 'yes' );
		$product->update_meta_data( MetaKeys::EVENT_ID, (string) $ticket_type->event_id );
		$product->update_meta_data( MetaKeys::IS_SERIES_PASS, 'yes' );
		$product->update_meta_data( MetaKeys::TIER_NAME, $tier_name );

		if ( '' === (string) $product->get_sku() ) {
			$sku = $this->resolve_sku( $admin_sku, $ticket_type, $event ? (string) $event->slug : '', $product->get_id() );
			if ( '' !== $sku ) {
				$product->set_sku( $sku );
			}
		}

		if ( null !== $this->product_cat_mapper ) {
			$this->apply_managed_product_cats( $product, (int) $ticket_type->event_id );
		}

		$product_id = $product->save();

		if ( $ticket_type->wc_product_id !== $product_id && null !== $ticket_type->id ) {
			$this->ticket_type_repo->link_to_product( $ticket_type->id, $product_id );
		}

		return $product_id;
	}

	/**
	 * Create products for all event-scoped ticket types (series passes) of an event.
	 *
	 * @since 1.1.3
	 *
	 * @param int                $event_id      Event ID.
	 * @param array<int, string> $admin_skus    Tier id => operator-entered SKU, honored
	 *                                          only while the product has none (NTE-114).
	 * @param bool               $existing_only When true, only re-sync event tiers that already have
	 *                                          a WooCommerce product; never mint a new one. Used by the
	 *                                          unpublish path so a draft transition reverts existing
	 *                                          products without creating any (R5).
	 * @return array<int> Product IDs synced.
	 */
	public function create_products_for_event( int $event_id, array $admin_skus = array(), bool $existing_only = false ): array {
		$product_ids = array();

		foreach ( $this->ticket_type_repo->for_event( $event_id ) as $ticket_type ) {
			if ( TicketTypeScope::EVENT->value !== $ticket_type->scope ) {
				continue;
			}
			// Never mint a product on an existing-only (unpublish) resync.
			if ( $existing_only && empty( $ticket_type->wc_product_id ) ) {
				continue;
			}
			$product_id = $this->sync_event_product( $ticket_type, null !== $ticket_type->id ? ( $admin_skus[ $ticket_type->id ] ?? null ) : null );
			if ( $product_id > 0 ) {
				$product_ids[] = $product_id;
			}
		}

		return $product_ids;
	}

	/**
	 * Sync stock from NetterTechEvents to WooCommerce for a tier and its housemates.
	 *
	 * Selling a seat changes what is left for *every* tier sold into the same room, not
	 * just the one that sold it. Syncing only the tier that moved leaves its peers
	 * advertising the seats they had when they last sold something, which drifts above
	 * the house — WooCommerce ends up offering more seats than exist (ADR-019).
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return bool True if the requested ticket type synced.
	 */
	public function sync_stock( int $ticket_type_id ): bool {
		$peer_ids = null === $this->house_repo
			? array( $ticket_type_id )
			: $this->house_repo->house_peer_ids( $ticket_type_id );

		if ( empty( $peer_ids ) ) {
			$peer_ids = array( $ticket_type_id );
		}

		// A sold pass took a seat on EVERY date (NTE-156), so every date's tiers are
		// now advertising one seat too many. Their houses changed; republish them.
		$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );
		if ( $ticket_type
			&& TicketTypeScope::EVENT->value === $ticket_type->scope
			&& null !== $ticket_type->event_id ) {
			foreach ( $this->occurrence_repo->for_event( (int) $ticket_type->event_id ) as $occurrence ) {
				if ( null === $occurrence->id ) {
					continue;
				}
				foreach ( $this->ticket_type_repo->for_occurrence( $occurrence->id ) as $peer ) {
					if ( null !== $peer->id ) {
						$peer_ids[] = $peer->id;
					}
				}
			}
			$peer_ids = array_values( array_unique( $peer_ids ) );
		}

		$synced = false;

		foreach ( $peer_ids as $peer_id ) {
			$result = $this->sync_product_stock( (int) $peer_id );

			if ( (int) $peer_id === $ticket_type_id ) {
				$synced = $result;
			}
		}

		return $synced;
	}

	/**
	 * The "{event} - {date} - " prefix sync_product() puts in front of the tier
	 * name for an occurrence ticket product (NTE-235). Single source for the
	 * title-repair command and for the migrator's prefix recognition.
	 *
	 * @param string $event_title    Event title.
	 * @param string $formatted_date Occurrence date as Occurrence::get_formatted_date().
	 * @return string
	 */
	public static function occurrence_title_prefix( string $event_title, string $formatted_date ): string {
		return sprintf(
			/* translators: 1: event title, 2: date, 3: ticket type name */
			__( '%1$s - %2$s - %3$s', 'nettertech-events' ),
			$event_title,
			$formatted_date,
			''
		);
	}

	/**
	 * The "{event} - Series Pass - " prefix sync_event_product() puts in front
	 * of the tier name for a series-pass product (NTE-235).
	 *
	 * @param string $event_title Event title.
	 * @return string
	 */
	public static function series_pass_title_prefix( string $event_title ): string {
		return sprintf(
			/* translators: 1: event title, 2: ticket type name */
			__( '%1$s - Series Pass - %2$s', 'nettertech-events' ),
			$event_title,
			''
		);
	}

	/**
	 * Strip every leading copy of a composed-title prefix from a tier name.
	 *
	 * "Show - Jan 1, 2027 - Show - Jan 1, 2027 - GA" with the prefix
	 * "Show - Jan 1, 2027 - " yields "GA". A name that consists of nothing but
	 * the prefix is returned unchanged rather than emptied.
	 *
	 * @param string $name   Tier name, possibly carrying stacked prefixes.
	 * @param string $prefix The prefix to strip (empty strips nothing).
	 * @return string
	 */
	public static function strip_title_prefix( string $name, string $prefix ): string {
		if ( '' === $prefix ) {
			return $name;
		}

		$bare = $name;
		while ( '' !== $bare && str_starts_with( $bare, $prefix ) ) {
			$bare = substr( $bare, strlen( $prefix ) );
		}

		return '' === $bare ? $name : $bare;
	}

	/**
	 * Publish one tier's remaining seats to its WooCommerce product.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return bool True on success.
	 */
	private function sync_product_stock( int $ticket_type_id ): bool {
		$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );

		if ( ! $ticket_type || ! $ticket_type->wc_product_id ) {
			return false;
		}

		$product = wc_get_product( $ticket_type->wc_product_id );
		if ( ! $product ) {
			return false;
		}

		$available = $this->available_for_stock( $ticket_type_id );

		if ( null === $available ) {
			$product->set_manage_stock( false );
			$product->set_stock_status( 'instock' );
		} else {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $available );
			$product->set_stock_status( $available > 0 ? 'instock' : 'outofstock' );
		}

		$product->save();

		return true;
	}

	/**
	 * What WooCommerce should advertise as in stock for this tier.
	 *
	 * The product mirrors a tier, but a tier does not own its seats — it shares a
	 * room with the event's other tiers. Publishing the tier's own remainder would
	 * tell three tiers in a 250-seat hall that they each have 250 to sell. The
	 * gates downstream would still refuse the sale, but only after the shopper had
	 * been shown a number that was never true.
	 *
	 * Held (reserved) seats are excluded: WooCommerce's stock figure is what may
	 * be added to a cart, and a shopper's own hold must not read as unavailable.
	 *
	 * The calculator is asked rather than the arithmetic repeated here, because it is
	 * the one place that knows the whole answer — the house, and any add-on override
	 * arriving through `nettertech_events_available_count` (a seated tier's seats, an
	 * early-bird tier's allotment). Deriving the number locally would publish a stock
	 * figure the till then refuses, which is precisely the drift ADR-019 exists to end.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int|null Seats to publish, or null when unbounded.
	 */
	private function available_for_stock( int $ticket_type_id ): ?int {
		if ( null !== $this->calculator ) {
			return $this->calculator->get_available_count( $ticket_type_id, false );
		}

		// No calculator injected: fall back to the tier's own remainder, held by the room.
		$own = $this->ticket_type_repo->get_available_count( $ticket_type_id );

		if ( null === $this->house_repo ) {
			return $own;
		}

		$context = $this->house_repo->context_for_ticket_type( $ticket_type_id );

		if ( null === $context ) {
			return $own;
		}

		$own = HouseRule::own_remaining(
			$context['own_capacity_type'],
			$context['own_capacity'],
			$context['own_sold']
		);

		$house_remaining = null === $context['house']
			? null
			: max( 0, $context['house'] - $context['house_sold'] );

		return HouseRule::bound( $own, $house_remaining );
	}

	/**
	 * Get ticket type from WooCommerce product.
	 *
	 * @param int|\WC_Product $product Product ID or object.
	 * @return TicketType|null
	 */
	public function get_ticket_type_from_product( int|\WC_Product $product ): ?TicketType {
		$wc_product = $product instanceof \WC_Product ? $product : wc_get_product( $product );

		if ( ! $wc_product ) {
			return null;
		}

		$ticket_type_id = $wc_product->get_meta( self::META_TICKET_TYPE_ID, true );

		if ( ! $ticket_type_id ) {
			return null;
		}

		return $this->ticket_type_repo->find( (int) $ticket_type_id );
	}

	/**
	 * Get occurrence from WooCommerce product.
	 *
	 * @param int|\WC_Product $product Product ID or object.
	 * @return Occurrence|null
	 */
	public function get_occurrence_from_product( int|\WC_Product $product ): ?Occurrence {
		$wc_product = $product instanceof \WC_Product ? $product : wc_get_product( $product );

		if ( ! $wc_product ) {
			return null;
		}

		$occurrence_id = $wc_product->get_meta( self::META_OCCURRENCE_ID, true );

		if ( ! $occurrence_id ) {
			return null;
		}

		return $this->occurrence_repo->find_with_event( (int) $occurrence_id );
	}

	/**
	 * Check if a WooCommerce product is an event ticket.
	 *
	 * @param int|\WC_Product $product Product ID or object.
	 * @return bool
	 */
	public function is_event_ticket( int|\WC_Product $product ): bool {
		$wc_product = $product instanceof \WC_Product ? $product : wc_get_product( $product );

		if ( ! $wc_product ) {
			return false;
		}

		return 'yes' === $wc_product->get_meta( '_nettertech_events_is_event_ticket', true );
	}

	/**
	 * Get add to cart URL for a ticket type.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity.
	 * @return string Add to cart URL.
	 */
	public function get_add_to_cart_url( int $ticket_type_id, int $quantity = 1 ): string {
		$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );

		if ( ! $ticket_type || ! $ticket_type->wc_product_id ) {
			return '';
		}

		return add_query_arg(
			array(
				'add-to-cart' => $ticket_type->wc_product_id,
				'quantity'    => $quantity,
			),
			wc_get_cart_url()
		);
	}

	/**
	 * Site-wide default ticket product image (NTE-219), used when neither the
	 * occurrence nor the event has a featured image.
	 *
	 * @since 1.4.7
	 *
	 * @return int Attachment ID, or 0 when unset.
	 */
	private function default_ticket_image_id(): int {
		return \NetterTechEvents\Core\NetterTechEventsSettings::from_option()->tickets->default_ticket_image_id;
	}
}
