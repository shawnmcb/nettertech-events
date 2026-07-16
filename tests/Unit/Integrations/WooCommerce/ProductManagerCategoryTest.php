<?php
/**
 * ProductManager product-category assignment tests (NTE-129).
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Integrations\WooCommerce\CategoryProductCatMapper;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;

/**
 * Test no-clobber merge, re-sync, and backfill assignment logic.
 */
class ProductManagerCategoryTest extends \NetterTechEventsTestCase {

	/**
	 * Build a ProductManager whose ticket-product lookups return a fixed list.
	 *
	 * @param CategoryProductCatMapper $mapper   Mapper.
	 * @param array<\WC_Product>       $products Stub products for lookups.
	 * @return ProductManager
	 */
	private function manager_with_products( CategoryProductCatMapper $mapper, array $products ): ProductManager {
		$ticket_repo     = $this->createMock( TicketTypeRepository::class );
		$occurrence_repo = $this->createMock( OccurrenceRepository::class );

		return new class( $ticket_repo, $occurrence_repo, $mapper, $products ) extends ProductManager {

			/**
			 * Stubbed product list.
			 *
			 * @var array<\WC_Product>
			 */
			private array $stub_products;

			/**
			 * Constructor.
			 *
			 * @param TicketTypeRepository     $ticket_repo Ticket repo.
			 * @param OccurrenceRepository     $occ_repo    Occurrence repo.
			 * @param CategoryProductCatMapper $mapper      Mapper.
			 * @param array<\WC_Product>       $products    Stub products.
			 */
			public function __construct( $ticket_repo, $occ_repo, $mapper, array $products ) {
				parent::__construct( $ticket_repo, $occ_repo, $mapper );
				$this->stub_products = $products;
			}

			/**
			 * Override DB lookup with the stub list.
			 *
			 * @param int $event_id Event ID.
			 * @return array<\WC_Product>
			 */
			protected function get_event_ticket_products( int $event_id ): array {
				return $this->stub_products;
			}

			/**
			 * Override DB lookup with the stub list.
			 *
			 * @return array<\WC_Product>
			 */
			protected function get_all_ticket_products(): array {
				return $this->stub_products;
			}
		};
	}

	/**
	 * compute_managed_category_set: preserves manual, drops removed managed, adds resolved.
	 *
	 * @return void
	 */
	public function test_compute_managed_category_set_no_clobber(): void {
		// Existing: 11 (managed, still resolved), 99 (managed, removed), 500 (manual).
		// Managed set: 10, 11, 99. Resolved: 10, 11.
		$result = ProductManager::compute_managed_category_set(
			array( 11, 99, 500 ),
			array( 10, 11, 99 ),
			array( 10, 11 )
		);

		sort( $result );
		$this->assertSame( array( 10, 11, 500 ), $result );
	}

	/**
	 * compute_managed_category_set drops all managed terms when nothing resolves.
	 *
	 * @return void
	 */
	public function test_compute_managed_category_set_event_categories_cleared(): void {
		$result = ProductManager::compute_managed_category_set(
			array( 10, 11, 500 ),
			array( 10, 11 ),
			array()
		);

		$this->assertSame( array( 500 ), $result );
	}

	/**
	 * resync_event_product_categories assigns the merged set and saves each product.
	 *
	 * @return void
	 */
	public function test_resync_assigns_and_saves(): void {
		$mapper = $this->createMock( CategoryProductCatMapper::class );
		$mapper->method( 'resolve_product_cat_ids' )->willReturn( array( 10 ) );
		$mapper->method( 'managed_term_ids' )->willReturn( array() );

		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_category_ids' )->andReturn( array( 500 ) );
		$product->shouldReceive( 'set_category_ids' )->once()->with( array( 500, 10 ) );
		$product->shouldReceive( 'save' )->once();

		$manager = $this->manager_with_products( $mapper, array( $product ) );

		$this->assertSame( 1, $manager->resync_event_product_categories( 42 ) );
	}

	/**
	 * Backfill dry-run reports changes without saving.
	 *
	 * @return void
	 */
	public function test_backfill_dry_run_does_not_save(): void {
		$mapper = $this->createMock( CategoryProductCatMapper::class );
		$mapper->method( 'resolve_product_cat_ids' )->willReturn( array( 10 ) );
		$mapper->method( 'managed_term_ids' )->willReturn( array() );

		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_meta' )->andReturn( '42' );
		$product->shouldReceive( 'get_category_ids' )->andReturn( array() );
		$product->shouldReceive( 'save' )->never();
		$product->shouldReceive( 'set_category_ids' )->never();

		$manager = $this->manager_with_products( $mapper, array( $product ) );

		$summary = $manager->backfill_product_categories( null, true );

		$this->assertSame( 1, $summary['products'] );
		$this->assertSame( 1, $summary['updated'] );
		$this->assertTrue( $summary['dry_run'] );
	}

	/**
	 * Backfill live run assigns categories and saves.
	 *
	 * @return void
	 */
	public function test_backfill_live_saves(): void {
		$mapper = $this->createMock( CategoryProductCatMapper::class );
		$mapper->method( 'resolve_product_cat_ids' )->willReturn( array( 10 ) );
		$mapper->method( 'managed_term_ids' )->willReturn( array() );

		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_meta' )->andReturn( '42' );
		$product->shouldReceive( 'get_category_ids' )->andReturn( array() );
		$product->shouldReceive( 'set_category_ids' )->once()->with( array( 10 ) );
		$product->shouldReceive( 'save' )->once();

		$manager = $this->manager_with_products( $mapper, array( $product ) );

		$summary = $manager->backfill_product_categories( null, false );

		$this->assertSame( 1, $summary['updated'] );
		$this->assertFalse( $summary['dry_run'] );
	}

	/**
	 * Backfill skips products with no linked event.
	 *
	 * @return void
	 */
	public function test_backfill_skips_products_without_event(): void {
		$mapper = $this->createMock( CategoryProductCatMapper::class );
		$mapper->method( 'managed_term_ids' )->willReturn( array() );

		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_meta' )->andReturn( '0' );
		$product->shouldReceive( 'save' )->never();

		$manager = $this->manager_with_products( $mapper, array( $product ) );

		$summary = $manager->backfill_product_categories( null, false );

		$this->assertSame( 0, $summary['updated'] );
	}
}
