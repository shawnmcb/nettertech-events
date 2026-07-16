<?php
/**
 * Maps NetterTech event categories to WooCommerce product categories.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Models\Category;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Resolves the WooCommerce `product_cat` term that mirrors each NTE event
 * category, using a stable term-meta back-reference (NTE-129).
 *
 * Relationship model mirrors ShadowPostSyncService: the source of truth is the
 * NTE custom-table category (`Category::$id`), and the mirrored `product_cat`
 * term carries a back-reference meta pointing at that ID. Resolution is by ID,
 * never by name — so renaming either side reconciles without orphaning the
 * coupon-scoped term.
 *
 * @since 1.1.2
 * @api
 */
class CategoryProductCatMapper {

	/**
	 * Term meta key storing the source event-category ID on a mirrored term.
	 */
	public const SOURCE_CATEGORY_META = '_nettertech_events_source_category';

	/**
	 * WooCommerce product category taxonomy.
	 */
	public const PRODUCT_CAT_TAXONOMY = 'product_cat';

	/**
	 * Category repository (NTE custom-table categories).
	 *
	 * @var CategoryRepositoryInterface|null
	 */
	private ?CategoryRepositoryInterface $category_repo;

	/**
	 * Constructor.
	 *
	 * @param CategoryRepositoryInterface|null $category_repo Category repository.
	 */
	public function __construct( ?CategoryRepositoryInterface $category_repo = null ) {
		$this->category_repo = $category_repo;
	}

	/**
	 * Resolve the mirrored `product_cat` term IDs for an event's categories.
	 *
	 * For each event category, finds (or adopts, or creates) the linked
	 * `product_cat` term and syncs its display name. Returns the unique set of
	 * term IDs.
	 *
	 * @param int  $event_id Event ID.
	 * @param bool $create   When true (default), adopt/create/rename mirrored
	 *                       terms as needed. When false the call is read-only
	 *                       (dry-run): only already-linked terms are returned.
	 * @return array<int> Mirrored `product_cat` term IDs.
	 */
	public function resolve_product_cat_ids( int $event_id, bool $create = true ): array {
		if ( null === $this->category_repo || $event_id <= 0 ) {
			return array();
		}

		$ids = array();
		foreach ( $this->category_repo->find_by_event( $event_id ) as $category ) {
			if ( ! $category instanceof Category ) {
				continue;
			}
			$term_id = $this->resolve_term_for_category( $category, $create );
			if ( $term_id > 0 ) {
				$ids[] = $term_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * All `product_cat` term IDs carrying the back-reference meta.
	 *
	 * This is the NTE-owned set used for no-clobber merge logic: product
	 * categories NOT in this set were added manually and must be preserved.
	 *
	 * @return array<int> NTE-managed `product_cat` term IDs.
	 */
	public function managed_term_ids(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::PRODUCT_CAT_TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded admin/CLI taxonomy lookup.
					array(
						'key'     => self::SOURCE_CATEGORY_META,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		return array_map( 'intval', $terms );
	}

	/**
	 * Resolve the mirrored term for one category: by back-ref, adopt, or create.
	 *
	 * @param Category $category Source event category.
	 * @param bool     $create   When false, only the back-ref lookup runs (no writes).
	 * @return int Mirrored `product_cat` term ID, or 0 on failure.
	 */
	private function resolve_term_for_category( Category $category, bool $create = true ): int {
		$source_id = (int) $category->id;
		if ( $source_id <= 0 ) {
			return 0;
		}

		// 1. Resolve by stable back-reference ID.
		$term_id = $this->find_by_back_reference( $source_id );
		if ( $term_id > 0 ) {
			if ( $create ) {
				$this->sync_term_name( $term_id, $category->name );
			}
			return $term_id;
		}

		// Read-only (dry-run): stop before any adopt/create write.
		if ( ! $create ) {
			return 0;
		}

		// 2. Adopt an existing same-named term (link via back-ref on first encounter).
		$existing = get_term_by( 'name', $category->name, self::PRODUCT_CAT_TAXONOMY );
		if ( $existing instanceof \WP_Term ) {
			update_term_meta( $existing->term_id, self::SOURCE_CATEGORY_META, $source_id );
			return (int) $existing->term_id;
		}

		// 3. Create a new mirrored term.
		return $this->create_mirrored_term( $category, $source_id );
	}

	/**
	 * Find the `product_cat` term whose back-ref meta equals the source ID.
	 *
	 * @param int $source_id Source event-category ID.
	 * @return int Term ID, or 0 if none.
	 */
	private function find_by_back_reference( int $source_id ): int {
		$terms = get_terms(
			array(
				'taxonomy'   => self::PRODUCT_CAT_TAXONOMY,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded single-term lookup by back-ref.
					array(
						'key'     => self::SOURCE_CATEGORY_META,
						'value'   => (string) $source_id,
						'compare' => '=',
					),
				),
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}

		return (int) $terms[0];
	}

	/**
	 * Create a new mirrored `product_cat` term and set its back-reference.
	 *
	 * On a race/duplicate WP_Error, falls back to adopting the existing term.
	 *
	 * @param Category $category  Source event category.
	 * @param int      $source_id Source event-category ID.
	 * @return int New (or adopted) term ID, or 0 on failure.
	 */
	private function create_mirrored_term( Category $category, int $source_id ): int {
		$args   = '' !== $category->slug ? array( 'slug' => $category->slug ) : array();
		$result = wp_insert_term( $category->name, self::PRODUCT_CAT_TAXONOMY, $args );

		if ( is_wp_error( $result ) ) {
			// Race or pre-existing term: adopt the existing one by name.
			$existing = get_term_by( 'name', $category->name, self::PRODUCT_CAT_TAXONOMY );
			if ( $existing instanceof \WP_Term ) {
				update_term_meta( $existing->term_id, self::SOURCE_CATEGORY_META, $source_id );
				return (int) $existing->term_id;
			}
			DebugLogger::log(
				'Failed to create mirrored product_cat for category ' . $source_id . ': ' . $result->get_error_message(),
				'CategoryProductCatMapper'
			);
			return 0;
		}

		$term_id = (int) $result['term_id'];
		if ( $term_id > 0 ) {
			update_term_meta( $term_id, self::SOURCE_CATEGORY_META, $source_id );
		}

		return $term_id;
	}

	/**
	 * Sync the mirrored term's display name to the source category name.
	 *
	 * Slug is intentionally left stable to avoid storefront URL churn; only the
	 * display name (what coupons and admins see) is reconciled.
	 *
	 * @param int    $term_id Mirrored term ID.
	 * @param string $name    Desired display name.
	 * @return void
	 */
	private function sync_term_name( int $term_id, string $name ): void {
		if ( '' === $name ) {
			return;
		}

		$term = get_term( $term_id, self::PRODUCT_CAT_TAXONOMY );
		if ( $term instanceof \WP_Term && $term->name !== $name ) {
			wp_update_term( $term_id, self::PRODUCT_CAT_TAXONOMY, array( 'name' => $name ) );
		}
	}
}
