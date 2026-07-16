<?php
/**
 * CategoryProductCatMapper unit tests (NTE-129).
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey\Functions;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Integrations\WooCommerce\CategoryProductCatMapper;
use NetterTechEvents\Models\Category;

/**
 * Test event-category → product_cat resolution (back-ref, adopt, create, rename).
 */
class CategoryProductCatMapperTest extends \NetterTechEventsTestCase {

	/**
	 * Build a Category model.
	 *
	 * @param int    $id   Category ID.
	 * @param string $name Category name.
	 * @param string $slug Optional slug.
	 * @return Category
	 */
	private function make_category( int $id, string $name, string $slug = '' ): Category {
		$category       = new Category();
		$category->id   = $id;
		$category->name = $name;
		$category->slug = '' !== $slug ? $slug : strtolower( $name );
		return $category;
	}

	/**
	 * Mock a category repository returning the given categories for any event.
	 *
	 * @param array<Category> $categories Categories.
	 * @return CategoryRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function repo_returning( array $categories ) {
		$repo = $this->createMock( CategoryRepositoryInterface::class );
		$repo->method( 'find_by_event' )->willReturn( $categories );
		return $repo;
	}

	/**
	 * Back-ref hit: existing term resolved by ID, no term creation.
	 *
	 * @return void
	 */
	public function test_resolve_by_back_reference_returns_existing_term(): void {
		$repo = $this->repo_returning( array( $this->make_category( 7, 'Concerts' ) ) );

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array( 55 ) );

		$term       = new \WP_Term();
		$term->term_id = 55;
		$term->name = 'Concerts';
		Functions\when( 'get_term' )->justReturn( $term );
		Functions\expect( 'wp_update_term' )->never();
		Functions\expect( 'wp_insert_term' )->never();

		$mapper = new CategoryProductCatMapper( $repo );

		$this->assertSame( array( 55 ), $mapper->resolve_product_cat_ids( 123 ) );
	}

	/**
	 * Rename reconcile: same back-ref term reused, display name updated.
	 *
	 * @return void
	 */
	public function test_rename_reconcile_reuses_term_and_updates_name(): void {
		$repo = $this->repo_returning( array( $this->make_category( 7, 'Concert Series' ) ) );

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array( 55 ) );

		$term       = new \WP_Term();
		$term->term_id = 55;
		$term->name = 'Concerts';
		Functions\when( 'get_term' )->justReturn( $term );

		Functions\expect( 'wp_update_term' )
			->once()
			->with( 55, 'product_cat', array( 'name' => 'Concert Series' ) );
		Functions\expect( 'wp_insert_term' )->never();

		$mapper = new CategoryProductCatMapper( $repo );

		$this->assertSame( array( 55 ), $mapper->resolve_product_cat_ids( 123 ) );
	}

	/**
	 * Adopt by name: existing same-named term linked via back-ref, not duplicated.
	 *
	 * @return void
	 */
	public function test_adopt_existing_same_named_term(): void {
		$repo = $this->repo_returning( array( $this->make_category( 7, 'Concerts' ) ) );

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array() );

		$existing          = new \WP_Term();
		$existing->term_id = 88;
		$existing->name    = 'Concerts';
		Functions\when( 'get_term_by' )->justReturn( $existing );

		Functions\expect( 'update_term_meta' )
			->once()
			->with( 88, CategoryProductCatMapper::SOURCE_CATEGORY_META, 7 );
		Functions\expect( 'wp_insert_term' )->never();

		$mapper = new CategoryProductCatMapper( $repo );

		$this->assertSame( array( 88 ), $mapper->resolve_product_cat_ids( 123 ) );
	}

	/**
	 * Create new: no back-ref, no same-named term → wp_insert_term + back-ref set.
	 *
	 * @return void
	 */
	public function test_create_new_term_when_none_exists(): void {
		$repo = $this->repo_returning( array( $this->make_category( 7, 'Concerts', 'concerts' ) ) );

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'wp_insert_term' )->justReturn( array( 'term_id' => 99 ) );

		Functions\expect( 'update_term_meta' )
			->once()
			->with( 99, CategoryProductCatMapper::SOURCE_CATEGORY_META, 7 );

		$mapper = new CategoryProductCatMapper( $repo );

		$this->assertSame( array( 99 ), $mapper->resolve_product_cat_ids( 123 ) );
	}

	/**
	 * managed_term_ids returns only terms carrying the back-ref meta.
	 *
	 * @return void
	 */
	public function test_managed_term_ids_returns_back_ref_terms(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array( 55, 88 ) );

		$mapper = new CategoryProductCatMapper( $this->createMock( CategoryRepositoryInterface::class ) );

		$this->assertSame( array( 55, 88 ), $mapper->managed_term_ids() );
	}

	/**
	 * Dry-run resolution ($create = false) performs no writes and returns only
	 * already-linked terms (none here → empty).
	 *
	 * @return void
	 */
	public function test_dry_run_resolution_makes_no_writes(): void {
		$repo = $this->repo_returning( array( $this->make_category( 7, 'Concerts' ) ) );

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array() );

		Functions\expect( 'get_term_by' )->never();
		Functions\expect( 'wp_insert_term' )->never();
		Functions\expect( 'update_term_meta' )->never();
		Functions\expect( 'wp_update_term' )->never();

		$mapper = new CategoryProductCatMapper( $repo );

		$this->assertSame( array(), $mapper->resolve_product_cat_ids( 123, false ) );
	}

	/**
	 * No event, no work — and crucially no repository round-trip.
	 *
	 * @dataProvider non_positive_event_ids
	 * @param int $event_id Event ID that identifies no event.
	 * @return void
	 */
	public function test_non_positive_event_id_resolves_to_nothing( int $event_id ): void {
		$repo = $this->createMock( CategoryRepositoryInterface::class );
		$repo->expects( $this->never() )->method( 'find_by_event' );

		Functions\expect( 'get_terms' )->never();

		$mapper = new CategoryProductCatMapper( $repo );

		$this->assertSame( array(), $mapper->resolve_product_cat_ids( $event_id ) );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function non_positive_event_ids(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -1 ),
		);
	}

	/**
	 * Without a category repository the mapper is inert.
	 *
	 * @return void
	 */
	public function test_absent_repository_resolves_to_nothing(): void {
		Functions\expect( 'get_terms' )->never();

		$mapper = new CategoryProductCatMapper( null );

		$this->assertSame( array(), $mapper->resolve_product_cat_ids( 123 ) );
	}

	/**
	 * A category with no real ID has no back-reference to resolve against.
	 *
	 * @return void
	 */
	public function test_category_without_id_is_skipped(): void {
		$repo = $this->repo_returning( array( $this->make_category( 0, 'Concerts' ) ) );

		Functions\expect( 'get_terms' )->never();
		Functions\expect( 'wp_insert_term' )->never();

		$mapper = new CategoryProductCatMapper( $repo );

		$this->assertSame( array(), $mapper->resolve_product_cat_ids( 123 ) );
	}

	/**
	 * Two categories mirrored onto the same term yield one ID, and the returned
	 * list is a contiguous list — not an array with holes where duplicates were.
	 *
	 * @return void
	 */
	public function test_duplicate_terms_collapse_to_a_contiguous_unique_list(): void {
		$repo = $this->repo_returning(
			array(
				$this->make_category( 1, 'Concerts' ),
				$this->make_category( 2, 'Concerts' ),
				$this->make_category( 3, 'Concerts' ),
			)
		);

		Functions\when( 'is_wp_error' )->justReturn( false );

		// Categories 1 and 2 both mirror term 55; category 3 mirrors term 66.
		$queue = array( array( 55 ), array( 55 ), array( 66 ) );
		Functions\when( 'get_terms' )->alias(
			function () use ( &$queue ) {
				return array_shift( $queue );
			}
		);

		$term          = new \WP_Term();
		$term->name    = 'Concerts';
		Functions\when( 'get_term' )->justReturn( $term );
		Functions\expect( 'wp_update_term' )->never();
		Functions\expect( 'wp_insert_term' )->never();

		$mapper = new CategoryProductCatMapper( $repo );

		$this->assertSame( array( 55, 66 ), $mapper->resolve_product_cat_ids( 123 ) );
	}

	/**
	 * The back-reference lookup asks for exactly one term, by meta value, in the
	 * product_cat taxonomy, including empty ones. Each of those is load-bearing:
	 * drop hide_empty and a brand-new term is invisible; drop the meta_query and
	 * an unrelated term is adopted.
	 *
	 * @return void
	 */
	public function test_back_reference_lookup_query_is_exact(): void {
		$repo = $this->repo_returning( array( $this->make_category( 7, 'Concerts' ) ) );

		Functions\when( 'is_wp_error' )->justReturn( false );

		Functions\expect( 'get_terms' )
			->once()
			->with(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'number'     => 1,
					'fields'     => 'ids',
					'meta_query' => array(
						array(
							'key'     => CategoryProductCatMapper::SOURCE_CATEGORY_META,
							'value'   => '7',
							'compare' => '=',
						),
					),
				)
			)
			->andReturn( array( 55 ) );

		$term       = new \WP_Term();
		$term->name = 'Concerts';
		Functions\when( 'get_term' )->justReturn( $term );

		$mapper = new CategoryProductCatMapper( $repo );

		$this->assertSame( array( 55 ), $mapper->resolve_product_cat_ids( 123 ) );
	}

	/**
	 * The managed-set query is the no-clobber boundary: it must select every
	 * NTE-owned term and nothing else.
	 *
	 * @return void
	 */
	public function test_managed_term_ids_query_is_exact(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );

		Functions\expect( 'get_terms' )
			->once()
			->with(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'fields'     => 'ids',
					'meta_query' => array(
						array(
							'key'     => CategoryProductCatMapper::SOURCE_CATEGORY_META,
							'compare' => 'EXISTS',
						),
					),
				)
			)
			->andReturn( array( 55 ) );

		$mapper = new CategoryProductCatMapper( $this->createMock( CategoryRepositoryInterface::class ) );

		$this->assertSame( array( 55 ), $mapper->managed_term_ids() );
	}

	/**
	 * get_terms() returns strings for IDs under some configurations; the managed
	 * set is compared against integer term IDs, so it must return integers.
	 *
	 * @return void
	 */
	public function test_managed_term_ids_are_integers(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array( '55', '88' ) );

		$mapper = new CategoryProductCatMapper( $this->createMock( CategoryRepositoryInterface::class ) );

		$this->assertSame( array( 55, 88 ), $mapper->managed_term_ids() );
	}

	/**
	 * A failed or malformed taxonomy query yields an empty managed set — never a
	 * partial one, which would let no-clobber logic delete terms it does not own.
	 *
	 * @return void
	 */
	public function test_managed_term_ids_empty_on_error(): void {
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'get_terms' )->justReturn( new \WP_Error() );

		$mapper = new CategoryProductCatMapper( $this->createMock( CategoryRepositoryInterface::class ) );

		$this->assertSame( array(), $mapper->managed_term_ids() );
	}

	/**
	 * @return void
	 */
	public function test_managed_term_ids_empty_on_non_array(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( 'not-an-array' );

		$mapper = new CategoryProductCatMapper( $this->createMock( CategoryRepositoryInterface::class ) );

		$this->assertSame( array(), $mapper->managed_term_ids() );
	}

	/**
	 * A slug is passed through on create so the storefront URL is predictable; an
	 * empty slug must not be sent as an empty string, which WordPress would honour.
	 *
	 * @return void
	 */
	public function test_create_passes_slug_only_when_present(): void {
		$with_slug       = $this->make_category( 7, 'Concerts', 'concerts' );
		$without_slug    = new Category();
		$without_slug->id   = 8;
		$without_slug->name = 'Workshops';
		$without_slug->slug = '';

		$repo = $this->repo_returning( array( $with_slug, $without_slug ) );

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'update_term_meta' )->justReturn( true );

		Functions\expect( 'wp_insert_term' )
			->once()
			->with( 'Concerts', 'product_cat', array( 'slug' => 'concerts' ) )
			->andReturn( array( 'term_id' => 99 ) );
		Functions\expect( 'wp_insert_term' )
			->once()
			->with( 'Workshops', 'product_cat', array() )
			->andReturn( array( 'term_id' => 100 ) );

		$mapper = new CategoryProductCatMapper( $repo );

		$this->assertSame( array( 99, 100 ), $mapper->resolve_product_cat_ids( 123 ) );
	}

	/**
	 * A created term's ID arrives as a string from wp_insert_term(); it is stored
	 * and returned as an integer.
	 *
	 * @return void
	 */
	public function test_created_term_id_is_cast_to_int(): void {
		$repo = $this->repo_returning( array( $this->make_category( 7, 'Concerts', 'concerts' ) ) );

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'wp_insert_term' )->justReturn( array( 'term_id' => '99' ) );

		Functions\expect( 'update_term_meta' )
			->once()
			->with( 99, CategoryProductCatMapper::SOURCE_CATEGORY_META, 7 );

		$mapper = new CategoryProductCatMapper( $repo );

		$this->assertSame( array( 99 ), $mapper->resolve_product_cat_ids( 123 ) );
	}

	/**
	 * A term ID of zero is a failed create: no back-reference is written against
	 * it, and it never enters the mirrored set.
	 *
	 * @return void
	 */
	public function test_zero_term_id_writes_no_back_reference(): void {
		$repo = $this->repo_returning( array( $this->make_category( 7, 'Concerts', 'concerts' ) ) );

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'wp_insert_term' )->justReturn( array( 'term_id' => 0 ) );

		Functions\expect( 'update_term_meta' )->never();

		$mapper = new CategoryProductCatMapper( $repo );

		$this->assertSame( array(), $mapper->resolve_product_cat_ids( 123 ) );
	}
}
