<?php
/**
 * ProductManager unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey\Functions;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;

/**
 * Test ProductManager functionality.
 */
class ProductManagerTest extends \NetterTechEventsTestCase {

	/**
	 * ProductManager instance.
	 *
	 * @var ProductManager
	 */
	private ProductManager $manager;

	/**
	 * Mock TicketTypeRepository.
	 *
	 * @var TicketTypeRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * Mock OccurrenceRepository.
	 *
	 * @var OccurrenceRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$this->occurrence_repo  = $this->createMock( OccurrenceRepository::class );

		$this->manager = new ProductManager(
			$this->ticket_type_repo,
			$this->occurrence_repo
		);

		// SKU generation helpers (NTE-114). Defaults: deterministic slugs and
		// "always unique" — individual tests override wc_product_has_unique_sku
		// to exercise collisions / admin-SKU fallback.
		Functions\when( 'sanitize_title' )->alias(
			static function ( $text ) {
				$text = strtolower( (string) $text );
				$text = preg_replace( '/[^a-z0-9]+/', '-', $text );
				return trim( (string) $text, '-' );
			}
		);
		Functions\when( 'wc_product_has_unique_sku' )->justReturn( true );
		Functions\when( 'wp_rand' )->justReturn( 1234 );

		TicketTypeFactory::reset();
		OccurrenceFactory::reset();
	}

	/**
	 * Invoke a private ProductManager method via reflection.
	 *
	 * `setAccessible()` is intentionally omitted — deprecated in PHP 8.5 and
	 * unnecessary since 8.1.
	 *
	 * @param string             $method Method name.
	 * @param array<int, mixed>  $args   Positional arguments.
	 * @return mixed
	 */
	private function invoke_private( string $method, array $args ) {
		$ref = new \ReflectionMethod( ProductManager::class, $method );
		return $ref->invoke( $this->manager, ...$args );
	}

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test PRODUCT_TYPE constant.
	 *
	 * @return void
	 */
	public function test_product_type_constant(): void {
		$this->assertSame( 'simple', ProductManager::PRODUCT_TYPE );
	}

	/**
	 * Test META_OCCURRENCE_ID constant.
	 *
	 * @return void
	 */
	public function test_meta_occurrence_id_constant(): void {
		$this->assertSame( '_nettertech_events_occurrence_id', ProductManager::META_OCCURRENCE_ID );
	}

	/**
	 * Test META_TICKET_TYPE_ID constant.
	 *
	 * @return void
	 */
	public function test_meta_ticket_type_id_constant(): void {
		$this->assertSame( '_nettertech_events_ticket_type_id', ProductManager::META_TICKET_TYPE_ID );
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test can be instantiated with dependencies.
	 *
	 * @return void
	 */
	public function test_can_instantiate_with_dependencies(): void {
		$manager = new ProductManager(
			$this->ticket_type_repo,
			$this->occurrence_repo
		);

		$this->assertInstanceOf( ProductManager::class, $manager );
	}

	// =========================================================================
	// is_event_ticket Tests
	// =========================================================================

	/**
	 * Test is_event_ticket returns true when meta is set.
	 *
	 * @return void
	 */
	public function test_is_event_ticket_returns_true(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_meta' )
			->willReturnCallback(
				function ( $key ) {
					if ( '_nettertech_events_is_event_ticket' === $key ) {
						return 'yes';
					}
					return '';
				}
			);

		Functions\when( 'wc_get_product' )->justReturn( $product );

		$result = $this->manager->is_event_ticket( 100 );

		$this->assertTrue( $result );
	}

	/**
	 * Test is_event_ticket returns false when meta not set.
	 *
	 * @return void
	 */
	public function test_is_event_ticket_returns_false(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_meta' )->willReturn( '' );

		Functions\when( 'wc_get_product' )->justReturn( $product );

		$result = $this->manager->is_event_ticket( 100 );

		$this->assertFalse( $result );
	}

	/**
	 * Test is_event_ticket accepts WC_Product object.
	 *
	 * @return void
	 */
	public function test_is_event_ticket_accepts_product_object(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_id' )->willReturn( 200 );
		$product->method( 'get_meta' )
			->with( '_nettertech_events_is_event_ticket', true )
			->willReturn( 'yes' );

		$result = $this->manager->is_event_ticket( $product );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// get_ticket_type_from_product Tests
	// =========================================================================

	/**
	 * Test get_ticket_type_from_product returns null when no meta.
	 *
	 * @return void
	 */
	public function test_get_ticket_type_from_product_returns_null_no_meta(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_meta' )->willReturn( '' );

		Functions\when( 'wc_get_product' )->justReturn( $product );

		$result = $this->manager->get_ticket_type_from_product( 100 );

		$this->assertNull( $result );
	}

	/**
	 * Test get_ticket_type_from_product returns ticket type.
	 *
	 * @return void
	 */
	public function test_get_ticket_type_from_product_returns_ticket_type(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_meta' )->willReturn( '5' );

		Functions\when( 'wc_get_product' )->justReturn( $product );

		$ticket_type = TicketTypeFactory::create( array( 'id' => 5 ) );

		$this->ticket_type_repo
			->expects( $this->once() )
			->method( 'find' )
			->with( 5 )
			->willReturn( $ticket_type );

		$result = $this->manager->get_ticket_type_from_product( 100 );

		$this->assertSame( $ticket_type, $result );
	}

	/**
	 * Test get_ticket_type_from_product accepts WC_Product object.
	 *
	 * @return void
	 */
	public function test_get_ticket_type_from_product_accepts_product_object(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_id' )->willReturn( 200 );
		$product->method( 'get_meta' )->willReturn( '' );

		$result = $this->manager->get_ticket_type_from_product( $product );

		$this->assertNull( $result );
	}

	// =========================================================================
	// get_occurrence_from_product Tests
	// =========================================================================

	/**
	 * Test get_occurrence_from_product returns null when no meta.
	 *
	 * @return void
	 */
	public function test_get_occurrence_from_product_returns_null_no_meta(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_meta' )->willReturn( '' );

		Functions\when( 'wc_get_product' )->justReturn( $product );

		$result = $this->manager->get_occurrence_from_product( 100 );

		$this->assertNull( $result );
	}

	/**
	 * Test get_occurrence_from_product returns occurrence.
	 *
	 * @return void
	 */
	public function test_get_occurrence_from_product_returns_occurrence(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_meta' )->willReturn( '10' );

		Functions\when( 'wc_get_product' )->justReturn( $product );

		$occurrence = OccurrenceFactory::create( array( 'id' => 10 ) );

		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'find_with_event' )
			->with( 10 )
			->willReturn( $occurrence );

		$result = $this->manager->get_occurrence_from_product( 100 );

		$this->assertSame( $occurrence, $result );
	}

	// =========================================================================
	// get_product Tests
	// =========================================================================

	/**
	 * Test get_product returns null when ticket type not found.
	 *
	 * @return void
	 */
	public function test_get_product_returns_null_ticket_type_not_found(): void {
		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( null );

		$result = $this->manager->get_product( 999 );

		$this->assertNull( $result );
	}

	/**
	 * Test get_product returns null when no wc_product_id.
	 *
	 * @return void
	 */
	public function test_get_product_returns_null_no_product_id(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => null ) );

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		$result = $this->manager->get_product( 1 );

		$this->assertNull( $result );
	}

	/**
	 * Test get_product returns WC_Product.
	 *
	 * @return void
	 */
	public function test_get_product_returns_product(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => 100 ) );
		$product     = $this->createMock( \WC_Product::class );

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		Functions\when( 'wc_get_product' )
			->alias(
				function ( $id ) use ( $product ) {
					return 100 === $id ? $product : null;
				}
			);

		$result = $this->manager->get_product( 1 );

		$this->assertSame( $product, $result );
	}

	// =========================================================================
	// delete_product Tests
	// =========================================================================

	/**
	 * Test delete_product returns false when ticket type not found.
	 *
	 * @return void
	 */
	public function test_delete_product_returns_false_ticket_type_not_found(): void {
		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( null );

		$result = $this->manager->delete_product( 999 );

		$this->assertFalse( $result );
	}

	/**
	 * Test delete_product returns false when no wc_product_id.
	 *
	 * @return void
	 */
	public function test_delete_product_returns_false_no_product_id(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => null ) );

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		$result = $this->manager->delete_product( 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Install a mock global $wpdb whose get_var returns the given value.
	 *
	 * Mirrors the repository-test pattern: swap the global, return the original
	 * for restoration in a finally block.
	 *
	 * @param string|null $get_var_return Value get_var returns (null = no order references).
	 * @return \wpdb The original global $wpdb.
	 */
	private function install_orders_wpdb( ?string $get_var_return ): \wpdb {
		global $wpdb;
		$original = $wpdb;

		$mock = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_var', 'prepare' ) )
			->getMock();
		$mock->prefix = 'wp_';
		$mock->method( 'prepare' )->willReturnArgument( 0 );
		$mock->method( 'get_var' )->willReturn( $get_var_return );

		$wpdb = $mock;

		return $original;
	}

	/**
	 * Build a WC_Product mock that reports as an NTE ticket product.
	 *
	 * @return \Mockery\MockInterface|\WC_Product
	 */
	private function make_ticket_product() {
		$product = \Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'get_meta' )
			->with( '_nettertech_events_is_event_ticket', true )
			->andReturn( 'yes' );
		return $product;
	}

	/**
	 * Test delete_product force-deletes a ticket product with no order references.
	 *
	 * @return void
	 */
	public function test_delete_product_force_deletes_when_no_orders(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => 100 ) );

		$product = $this->make_ticket_product();
		$product->shouldReceive( 'delete' )
			->with( true )
			->once();

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		Functions\when( 'wc_get_product' )->justReturn( $product );

		global $wpdb;
		$original = $this->install_orders_wpdb( null );
		try {
			$result = $this->manager->delete_product( 1 );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original;
		}
	}

	/**
	 * Test delete_product trashes (does not force-delete) a product referenced by an order.
	 *
	 * @return void
	 */
	public function test_delete_product_trashes_when_orders_exist(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => 100 ) );

		$product = $this->make_ticket_product();
		$product->shouldReceive( 'delete' )
			->with( false )
			->once();

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		Functions\when( 'wc_get_product' )->justReturn( $product );

		global $wpdb;
		$original = $this->install_orders_wpdb( '42' );
		try {
			$result = $this->manager->delete_product( 1 );
			$this->assertTrue( $result );
		} finally {
			$wpdb = $original;
		}
	}

	/**
	 * Test delete_product refuses to touch a product that is not an NTE ticket product.
	 *
	 * Defensive scope guard: a corrupted wc_product_id link pointing at a real
	 * shop product must never be deleted.
	 *
	 * @return void
	 */
	public function test_delete_product_refuses_non_nte_product(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => 100 ) );

		$product = \Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'get_meta' )
			->with( '_nettertech_events_is_event_ticket', true )
			->andReturn( '' );
		// delete() must NEVER be called on a non-NTE product.
		$product->shouldReceive( 'delete' )->never();

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		Functions\when( 'wc_get_product' )->justReturn( $product );

		$result = $this->manager->delete_product( 1 );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// sync_stock Tests
	// =========================================================================

	/**
	 * Test sync_stock returns false when ticket type not found.
	 *
	 * @return void
	 */
	public function test_sync_stock_returns_false_ticket_type_not_found(): void {
		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( null );

		$result = $this->manager->sync_stock( 999 );

		$this->assertFalse( $result );
	}

	/**
	 * Test sync_stock returns false when no wc_product_id.
	 *
	 * @return void
	 */
	public function test_sync_stock_returns_false_no_product_id(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => null ) );

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		$result = $this->manager->sync_stock( 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test sync_stock returns false when product not found.
	 *
	 * @return void
	 */
	public function test_sync_stock_returns_false_product_not_found(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => 100 ) );

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		Functions\when( 'wc_get_product' )->justReturn( false );

		$result = $this->manager->sync_stock( 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test sync_stock syncs limited capacity.
	 *
	 * WooCommerce publishes what the calculator says, not a figure derived here.
	 *
	 * The calculator is the only component that sees an add-on's availability override
	 * arriving through `nettertech_events_available_count` — a seated tier's seats, or an
	 * early-bird tier's allotment. If the stock figure were derived locally it would
	 * ignore that override, and Woo would advertise seats the till then refuses: the
	 * screens and the room disagreeing again (ADR-019, NTE-149).
	 *
	 * @return void
	 */
	public function test_sync_stock_publishes_what_the_calculator_says(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'capacity'      => 500, // The tier's own column says plenty...
				'wc_product_id' => 100,
			)
		);

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		// ...but an add-on has capped this tier at 12 (e.g. an early-bird allotment).
		$calculator = $this->createMock( \NetterTechEvents\Contracts\CapacityCalculatorInterface::class );
		$calculator->method( 'get_available_count' )->willReturn( 12 );

		$manager = new ProductManager(
			$this->ticket_type_repo,
			$this->occurrence_repo,
			null,
			null,
			$calculator
		);

		$product = \Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'set_manage_stock' )->with( true )->once();
		$product->shouldReceive( 'set_stock_quantity' )->with( 12 )->once();
		$product->shouldReceive( 'set_stock_status' )->with( 'instock' )->once();
		$product->shouldReceive( 'save' )->once();

		Functions\when( 'wc_get_product' )->justReturn( $product );

		$this->assertTrue( $manager->sync_stock( 1 ) );
	}

	/**
	 * Test sync_stock syncs limited capacity.
	 *
	 * Note: WC_Product methods are difficult to mock without full WC env.
	 * This test verifies the method returns true when product is found.
	 *
	 * @return void
	 */
	public function test_sync_stock_syncs_limited_capacity(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => 100 ) );

		// Create a mock that allows any method calls.
		$product = \Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'set_manage_stock' )->with( true )->once();
		$product->shouldReceive( 'set_stock_quantity' )->with( 50 )->once();
		$product->shouldReceive( 'set_stock_status' )->with( 'instock' )->once();
		$product->shouldReceive( 'save' )->once();

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		$this->ticket_type_repo
			->method( 'get_available_count' )
			->willReturn( 50 );

		Functions\when( 'wc_get_product' )->justReturn( $product );

		$result = $this->manager->sync_stock( 1 );

		$this->assertTrue( $result );
	}

	/**
	 * Test sync_stock syncs unlimited capacity.
	 *
	 * @return void
	 */
	public function test_sync_stock_syncs_unlimited_capacity(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => 100 ) );

		$product = \Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'set_manage_stock' )->with( false )->once();
		$product->shouldReceive( 'set_stock_status' )->with( 'instock' )->once();
		$product->shouldReceive( 'save' )->once();

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		$this->ticket_type_repo
			->method( 'get_available_count' )
			->willReturn( null );

		Functions\when( 'wc_get_product' )->justReturn( $product );

		$result = $this->manager->sync_stock( 1 );

		$this->assertTrue( $result );
	}

	/**
	 * Test sync_stock sets outofstock when zero capacity.
	 *
	 * @return void
	 */
	public function test_sync_stock_sets_outofstock_when_zero(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => 100 ) );

		$product = \Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'set_manage_stock' )->with( true )->once();
		$product->shouldReceive( 'set_stock_quantity' )->with( 0 )->once();
		$product->shouldReceive( 'set_stock_status' )->with( 'outofstock' )->once();
		$product->shouldReceive( 'save' )->once();

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		$this->ticket_type_repo
			->method( 'get_available_count' )
			->willReturn( 0 );

		Functions\when( 'wc_get_product' )->justReturn( $product );

		$result = $this->manager->sync_stock( 1 );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// get_add_to_cart_url Tests
	// =========================================================================

	/**
	 * Test get_add_to_cart_url returns empty when ticket type not found.
	 *
	 * @return void
	 */
	public function test_get_add_to_cart_url_returns_empty_ticket_type_not_found(): void {
		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( null );

		$result = $this->manager->get_add_to_cart_url( 999 );

		$this->assertSame( '', $result );
	}

	/**
	 * Test get_add_to_cart_url returns empty when no wc_product_id.
	 *
	 * @return void
	 */
	public function test_get_add_to_cart_url_returns_empty_no_product_id(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => null ) );

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		$result = $this->manager->get_add_to_cart_url( 1 );

		$this->assertSame( '', $result );
	}

	/**
	 * Test get_add_to_cart_url returns URL with default quantity.
	 *
	 * @return void
	 */
	public function test_get_add_to_cart_url_returns_url(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => 100 ) );

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://example.com/cart/' );
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);

		$result = $this->manager->get_add_to_cart_url( 1 );

		$this->assertStringContainsString( 'add-to-cart=100', $result );
		$this->assertStringContainsString( 'quantity=1', $result );
	}

	/**
	 * Test get_add_to_cart_url respects custom quantity.
	 *
	 * @return void
	 */
	public function test_get_add_to_cart_url_respects_quantity(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => 100 ) );

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://example.com/cart/' );
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);

		$result = $this->manager->get_add_to_cart_url( 1, 5 );

		$this->assertStringContainsString( 'quantity=5', $result );
	}

	// =========================================================================
	// create_products_for_occurrence Tests
	// =========================================================================

	/**
	 * Test create_products_for_occurrence returns empty when occurrence not found.
	 *
	 * @return void
	 */
	public function test_create_products_for_occurrence_returns_empty_no_occurrence(): void {
		$this->occurrence_repo
			->method( 'find' )
			->willReturn( null );

		$result = $this->manager->create_products_for_occurrence( 999 );

		$this->assertSame( array(), $result );
	}


	/**
	 * Test create_products_for_occurrence includes free tickets by default.
	 *
	 * @return void
	 */
	public function test_create_products_for_occurrence_includes_free_by_default(): void {
		$occurrence = OccurrenceFactory::create( array( 'id' => 10 ) );
		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );

		$free       = TicketTypeFactory::create(
			array(
				'id'    => 1,
				'price' => 0.0,
			)
		);
		$paid       = TicketTypeFactory::create(
			array(
				'id'    => 2,
				'price' => 25.0,
			)
		);
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array( $free, $paid ) );

		$manager = $this->getMockBuilder( ProductManager::class )
			->setConstructorArgs( array( $this->ticket_type_repo, $this->occurrence_repo ) )
			->onlyMethods( array( 'sync_product' ) )
			->getMock();
		$manager->expects( $this->exactly( 2 ) )
			->method( 'sync_product' )
			->willReturn( 100 );

		$result = $manager->create_products_for_occurrence( 10 );

		$this->assertCount( 2, $result );
	}

	/**
	 * existing_only re-syncs occurrence tiers that already have a product and mints nothing (R5).
	 *
	 * Operator ruling 2026-07-20 (spec-001 invention audit): an unpublish transition must revert
	 * existing products to draft without creating any for tiers that never had one.
	 *
	 * @return void
	 */
	public function test_create_products_for_occurrence_existing_only_skips_productless_tiers(): void {
		$occurrence = OccurrenceFactory::create( array( 'id' => 10 ) );
		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );

		$with_product                = TicketTypeFactory::create( array( 'id' => 1, 'price' => 25.0 ) );
		$with_product->wc_product_id = 999;
		$no_product                  = TicketTypeFactory::create( array( 'id' => 2, 'price' => 25.0 ) );
		$no_product->wc_product_id   = null;
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array( $with_product, $no_product ) );

		$manager = $this->getMockBuilder( ProductManager::class )
			->setConstructorArgs( array( $this->ticket_type_repo, $this->occurrence_repo ) )
			->onlyMethods( array( 'sync_product' ) )
			->getMock();
		// Only the tier that already has a product is re-synced.
		$manager->expects( $this->once() )
			->method( 'sync_product' )
			->with( $with_product, $occurrence, null )
			->willReturn( 999 );

		$result = $manager->create_products_for_occurrence( 10, true, array(), true );

		$this->assertSame( array( 999 ), $result );
	}

	/**
	 * Test create_products_for_occurrence passes the operator SKU for a saved tier.
	 *
	 * @return void
	 */
	public function test_create_products_for_occurrence_passes_admin_sku_for_saved_tier(): void {
		$occurrence = OccurrenceFactory::create( array( 'id' => 10 ) );
		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );

		$tier = TicketTypeFactory::create(
			array(
				'id'    => 6,
				'price' => 25.0,
			)
		);
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array( $tier ) );

		$manager = $this->getMockBuilder( ProductManager::class )
			->setConstructorArgs( array( $this->ticket_type_repo, $this->occurrence_repo ) )
			->onlyMethods( array( 'sync_product' ) )
			->getMock();
		$manager->expects( $this->once() )
			->method( 'sync_product' )
			->with( $tier, $occurrence, 'occ-sku' )
			->willReturn( 300 );

		$result = $manager->create_products_for_occurrence( 10, true, array( 6 => 'occ-sku' ) );

		$this->assertSame( array( 300 ), $result );
	}

	/**
	 * Test create_products_for_event passes the operator SKU for a tier with an id.
	 *
	 * @return void
	 */
	public function test_create_products_for_event_passes_admin_sku_for_saved_tier(): void {
		$pass = TicketTypeFactory::create(
			array(
				'id'    => 5,
				'price' => 50.0,
			)
		);
		$pass->scope = TicketTypeScope::EVENT->value;
		$this->ticket_type_repo->method( 'for_event' )->willReturn( array( $pass ) );

		$manager = $this->getMockBuilder( ProductManager::class )
			->setConstructorArgs( array( $this->ticket_type_repo, $this->occurrence_repo ) )
			->onlyMethods( array( 'sync_event_product' ) )
			->getMock();
		$manager->expects( $this->once() )
			->method( 'sync_event_product' )
			->with( $pass, 'operator-sku' )
			->willReturn( 200 );

		$result = $manager->create_products_for_event( 9, array( 5 => 'operator-sku' ) );

		$this->assertSame( array( 200 ), $result );
	}

	// =========================================================================
	// sync_product Tests
	// =========================================================================

	/**
	 * Test sync_product creates new product when none exists.
	 *
	 * @return void
	 */
	public function test_sync_product_creates_new_product(): void {
		$occurrence  = OccurrenceFactory::create( array( 'id' => 10 ) );
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'name'          => 'General Admission',
				'price'         => 25.00,
				'status'        => 'active',
				'capacity'      => 100,
				'wc_product_id' => null,
			)
		);

		$this->occurrence_repo
			->method( 'find_with_event' )
			->willReturn( $occurrence );

		$this->ticket_type_repo
			->method( 'link_to_product' )
			->willReturn( true );

		// The stock published is what the room has left for this tier, not the
		// tier's own column (ADR-019).
		$this->ticket_type_repo
			->method( 'get_available_count' )
			->willReturn( 100 );

		$product = \Mockery::mock( 'overload:WC_Product_Simple' );
		$product->shouldReceive( 'set_name' )->once();
		$product->shouldReceive( 'set_status' )->with( 'publish' )->once();
		$product->shouldReceive( 'set_catalog_visibility' )->with( 'hidden' )->once();
		$product->shouldReceive( 'set_price' )->with( '25' )->once();
		$product->shouldReceive( 'set_regular_price' )->with( '25' )->once();
		$product->shouldReceive( 'set_virtual' )->with( true )->once();
		$product->shouldReceive( 'set_sold_individually' )->with( false )->once();
		$product->shouldReceive( 'set_manage_stock' )->with( true )->once();
		$product->shouldReceive( 'set_stock_quantity' )->with( 100 )->once();
		$product->shouldReceive( 'set_stock_status' )->with( 'instock' )->once();
		$product->shouldReceive( 'set_backorders' )->with( 'no' )->once();
		$product->shouldReceive( 'update_meta_data' )->zeroOrMoreTimes();
		$product->shouldReceive( 'get_sku' )->andReturn( '' );
		$product->shouldReceive( 'get_id' )->andReturn( 0 );
		$product->shouldReceive( 'set_sku' )->zeroOrMoreTimes();
		$product->shouldReceive( 'save' )->once()->andReturn( 200 );

		Functions\when( 'wc_get_product' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $text ) => $text );

		$result = $this->manager->sync_product( $ticket_type, $occurrence );

		$this->assertSame( 200, $result );
	}

	/**
	 * Test sync_product updates existing product.
	 *
	 * @return void
	 */
	public function test_sync_product_updates_existing_product(): void {
		$occurrence  = OccurrenceFactory::create( array( 'id' => 10 ) );
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'name'          => 'VIP',
				'price'         => 100.00,
				'status'        => 'active',
				'capacity'      => null, // Unlimited.
				'wc_product_id' => 300,
			)
		);

		$this->occurrence_repo
			->method( 'find_with_event' )
			->willReturn( $occurrence );

		$existing_product = \Mockery::mock( \WC_Product::class );
		$existing_product->shouldReceive( 'set_name' )->once();
		$existing_product->shouldReceive( 'set_status' )->with( 'publish' )->once();
		$existing_product->shouldReceive( 'set_catalog_visibility' )->with( 'hidden' )->once();
		$existing_product->shouldReceive( 'set_price' )->with( '100' )->once();
		$existing_product->shouldReceive( 'set_regular_price' )->with( '100' )->once();
		$existing_product->shouldReceive( 'set_virtual' )->with( true )->once();
		$existing_product->shouldReceive( 'set_sold_individually' )->with( false )->once();
		$existing_product->shouldReceive( 'set_manage_stock' )->with( false )->once();
		$existing_product->shouldReceive( 'set_stock_status' )->with( 'instock' )->once();
		$existing_product->shouldReceive( 'update_meta_data' )->zeroOrMoreTimes();
		$existing_product->shouldReceive( 'get_sku' )->andReturn( '' );
		$existing_product->shouldReceive( 'get_id' )->andReturn( 300 );
		$existing_product->shouldReceive( 'set_sku' )->zeroOrMoreTimes();
		$existing_product->shouldReceive( 'save' )->once()->andReturn( 300 );

		Functions\when( 'wc_get_product' )->justReturn( $existing_product );
		Functions\when( '__' )->alias( fn( $text ) => $text );

		$result = $this->manager->sync_product( $ticket_type, $occurrence );

		$this->assertSame( 300, $result );
	}

	/**
	 * Test sync_product sets draft status for inactive ticket type.
	 *
	 * @return void
	 */
	public function test_sync_product_sets_draft_for_inactive(): void {
		$occurrence  = OccurrenceFactory::create( array( 'id' => 10 ) );
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'name'          => 'Sold Out',
				'price'         => 25.00,
				'status'        => 'inactive',
				'capacity'      => 0, // Zero capacity.
				'wc_product_id' => null,
			)
		);

		$this->occurrence_repo
			->method( 'find_with_event' )
			->willReturn( $occurrence );

		$this->ticket_type_repo
			->method( 'link_to_product' )
			->willReturn( true );

		$this->ticket_type_repo
			->method( 'get_available_count' )
			->willReturn( 0 );

		$product = \Mockery::mock( 'overload:WC_Product_Simple' );
		$product->shouldReceive( 'set_name' )->once();
		$product->shouldReceive( 'set_status' )->with( 'draft' )->once();
		$product->shouldReceive( 'set_catalog_visibility' )->once();
		$product->shouldReceive( 'set_price' )->once();
		$product->shouldReceive( 'set_regular_price' )->once();
		$product->shouldReceive( 'set_virtual' )->once();
		$product->shouldReceive( 'set_sold_individually' )->once();
		$product->shouldReceive( 'set_manage_stock' )->with( true )->once();
		$product->shouldReceive( 'set_stock_quantity' )->with( 0 )->once();
		$product->shouldReceive( 'set_stock_status' )->with( 'outofstock' )->once();
		$product->shouldReceive( 'set_backorders' )->with( 'no' )->once();
		$product->shouldReceive( 'update_meta_data' )->zeroOrMoreTimes();
		$product->shouldReceive( 'get_sku' )->andReturn( '' );
		$product->shouldReceive( 'get_id' )->andReturn( 0 );
		$product->shouldReceive( 'set_sku' )->zeroOrMoreTimes();
		$product->shouldReceive( 'save' )->once()->andReturn( 400 );

		Functions\when( 'wc_get_product' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $text ) => $text );

		$result = $this->manager->sync_product( $ticket_type, $occurrence );

		$this->assertSame( 400, $result );
	}

	/**
	 * Test sync_product stores meta data.
	 *
	 * @return void
	 */
	public function test_sync_product_stores_meta(): void {
		$occurrence  = OccurrenceFactory::create( array( 'id' => 10 ) );
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 5,
				'wc_product_id' => null,
				'capacity'      => null,
			)
		);

		$this->occurrence_repo
			->method( 'find_with_event' )
			->willReturn( $occurrence );

		$this->ticket_type_repo
			->method( 'link_to_product' )
			->willReturn( true );

		$product = \Mockery::mock( 'overload:WC_Product_Simple' );
		$product->shouldReceive( 'set_name' )->once();
		$product->shouldReceive( 'set_status' )->once();
		$product->shouldReceive( 'set_catalog_visibility' )->once();
		$product->shouldReceive( 'set_price' )->once();
		$product->shouldReceive( 'set_regular_price' )->once();
		$product->shouldReceive( 'set_virtual' )->once();
		$product->shouldReceive( 'set_sold_individually' )->once();
		$meta_stored = array();
		$product->shouldReceive( 'set_manage_stock' )->once();
		$product->shouldReceive( 'set_stock_status' )->once();
		$product->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( $key, $value ) use ( &$meta_stored ) {
				$meta_stored[ $key ] = $value;
			}
		);
		$product->shouldReceive( 'get_sku' )->andReturn( '' );
		$product->shouldReceive( 'get_id' )->andReturn( 0 );
		$product->shouldReceive( 'set_sku' )->zeroOrMoreTimes();
		$product->shouldReceive( 'save' )->once()->andReturn( 500 );

		Functions\when( 'wc_get_product' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $text ) => $text );

		$this->manager->sync_product( $ticket_type, $occurrence );

		$this->assertArrayHasKey( '_nettertech_events_occurrence_id', $meta_stored );
		$this->assertArrayHasKey( '_nettertech_events_ticket_type_id', $meta_stored );
		$this->assertArrayHasKey( '_nettertech_events_is_event_ticket', $meta_stored );
		$this->assertArrayHasKey( '_nettertech_events_event_id', $meta_stored );
		$this->assertSame( '10', $meta_stored['_nettertech_events_occurrence_id'] );
		$this->assertSame( '5', $meta_stored['_nettertech_events_ticket_type_id'] );
		$this->assertSame( 'yes', $meta_stored['_nettertech_events_is_event_ticket'] );
		$this->assertSame( '1', $meta_stored['_nettertech_events_event_id'] );
	}

	/**
	 * Test sync_product uses fallback event title when no event found.
	 *
	 * @return void
	 */
	public function test_sync_product_uses_fallback_event_title(): void {
		$occurrence  = OccurrenceFactory::create( array( 'id' => 10 ) );
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'name'          => 'General',
				'wc_product_id' => null,
				'capacity'      => null,
			)
		);

		// Return occurrence without event.
		$this->occurrence_repo
			->method( 'find_with_event' )
			->willReturn( null );

		$this->ticket_type_repo
			->method( 'link_to_product' )
			->willReturn( true );

		$product_name = null;
		$product      = \Mockery::mock( 'overload:WC_Product_Simple' );
		$product->shouldReceive( 'set_name' )->andReturnUsing(
			function ( $name ) use ( &$product_name ) {
				$product_name = $name;
			}
		);
		$product->shouldReceive( 'set_status' )->once();
		$product->shouldReceive( 'set_catalog_visibility' )->once();
		$product->shouldReceive( 'set_price' )->once();
		$product->shouldReceive( 'set_regular_price' )->once();
		$product->shouldReceive( 'set_virtual' )->once();
		$product->shouldReceive( 'set_sold_individually' )->once();
		$product->shouldReceive( 'set_manage_stock' )->once();
		$product->shouldReceive( 'set_stock_status' )->once();
		$product->shouldReceive( 'update_meta_data' )->zeroOrMoreTimes();
		$product->shouldReceive( 'get_sku' )->andReturn( '' );
		$product->shouldReceive( 'get_id' )->andReturn( 0 );
		$product->shouldReceive( 'set_sku' )->zeroOrMoreTimes();
		$product->shouldReceive( 'save' )->once()->andReturn( 600 );

		Functions\when( 'wc_get_product' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $text ) => $text );

		$this->manager->sync_product( $ticket_type, $occurrence );

		// Should use fallback "Event" as title.
		$this->assertStringContainsString( 'Event', $product_name );
	}

	/**
	 * Test delete_product handles null product from wc_get_product.
	 *
	 * @return void
	 */
	public function test_delete_product_handles_null_product(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => 100 ) );

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		Functions\when( 'wc_get_product' )->justReturn( null );

		// Should return true even if product is null (nothing to delete).
		$result = $this->manager->delete_product( 1 );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// SKU generation Tests (NTE-114)
	// =========================================================================

	/**
	 * Test build_default_sku produces `{event_slug}--{ticket_slug}`.
	 *
	 * @return void
	 */
	public function test_build_default_sku_format(): void {
		$ticket_type = TicketTypeFactory::create( array( 'name' => 'General Admission' ) );

		$sku = $this->invoke_private( 'build_default_sku', array( $ticket_type, 'summer-fest', 0 ) );

		$this->assertSame( 'summer-fest--general-admission', $sku );
	}

	/**
	 * Test build_default_sku appends a numeric suffix on collision.
	 *
	 * @return void
	 */
	public function test_build_default_sku_appends_collision_suffix(): void {
		$ticket_type = TicketTypeFactory::create( array( 'name' => 'General Admission' ) );

		// Base is taken; everything else is unique.
		Functions\when( 'wc_product_has_unique_sku' )->alias(
			static function ( $product_id, $sku ) {
				return 'summer-fest--general-admission' !== $sku;
			}
		);

		$sku = $this->invoke_private( 'build_default_sku', array( $ticket_type, 'summer-fest', 0 ) );

		$this->assertSame( 'summer-fest--general-admission-2', $sku );
	}

	/**
	 * Test build_default_sku returns '' when no slug material is available.
	 *
	 * @return void
	 */
	public function test_build_default_sku_empty_when_no_material(): void {
		$ticket_type = TicketTypeFactory::create( array( 'name' => '' ) );

		$sku = $this->invoke_private( 'build_default_sku', array( $ticket_type, '', 0 ) );

		$this->assertSame( '', $sku );
	}

	/**
	 * Test resolve_sku prefers a unique admin-supplied SKU.
	 *
	 * @return void
	 */
	public function test_resolve_sku_prefers_unique_admin_sku(): void {
		$ticket_type = TicketTypeFactory::create( array( 'name' => 'General Admission' ) );

		$sku = $this->invoke_private( 'resolve_sku', array( 'CUSTOM-1', $ticket_type, 'summer-fest', 0 ) );

		$this->assertSame( 'CUSTOM-1', $sku );
	}

	/**
	 * Test resolve_sku falls back to the generated default when the admin SKU is not unique.
	 *
	 * @return void
	 */
	public function test_resolve_sku_falls_back_when_admin_sku_not_unique(): void {
		$ticket_type = TicketTypeFactory::create( array( 'name' => 'General Admission' ) );

		// The admin-supplied 'DUPE' is taken; the generated default is unique.
		Functions\when( 'wc_product_has_unique_sku' )->alias(
			static function ( $product_id, $sku ) {
				return 'DUPE' !== $sku;
			}
		);

		$sku = $this->invoke_private( 'resolve_sku', array( 'DUPE', $ticket_type, 'summer-fest', 0 ) );

		$this->assertSame( 'summer-fest--general-admission', $sku );
	}

	/**
	 * Test sync_product generates a SKU when the product has none.
	 *
	 * @return void
	 */
	public function test_sync_product_generates_sku_when_empty(): void {
		$occurrence  = OccurrenceFactory::create( array( 'id' => 10 ) );
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'name'          => 'General Admission',
				'price'         => 25.00,
				'status'        => 'active',
				'capacity'      => null,
				'wc_product_id' => null,
			)
		);

		$this->occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );
		$this->ticket_type_repo->method( 'link_to_product' )->willReturn( true );

		$product = \Mockery::mock( 'overload:WC_Product_Simple' );
		$product->shouldReceive( 'set_name' )->once();
		$product->shouldReceive( 'set_status' )->once();
		$product->shouldReceive( 'set_catalog_visibility' )->once();
		$product->shouldReceive( 'set_price' )->once();
		$product->shouldReceive( 'set_regular_price' )->once();
		$product->shouldReceive( 'set_virtual' )->once();
		$product->shouldReceive( 'set_sold_individually' )->once();
		$product->shouldReceive( 'set_manage_stock' )->once();
		$product->shouldReceive( 'set_stock_status' )->once();
		$product->shouldReceive( 'update_meta_data' )->zeroOrMoreTimes();
		$product->shouldReceive( 'get_sku' )->andReturn( '' );
		$product->shouldReceive( 'get_id' )->andReturn( 0 );
		// The lock is open (empty SKU) → a SKU must be generated and set.
		$product->shouldReceive( 'set_sku' )->once();
		$product->shouldReceive( 'save' )->once()->andReturn( 700 );

		Functions\when( 'wc_get_product' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $text ) => $text );

		$this->manager->sync_product( $ticket_type, $occurrence );
	}

	/**
	 * Test sync_product never regenerates a SKU once one is set (lock).
	 *
	 * @return void
	 */
	public function test_sync_product_does_not_regenerate_existing_sku(): void {
		$occurrence  = OccurrenceFactory::create( array( 'id' => 10 ) );
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'name'          => 'General Admission',
				'price'         => 25.00,
				'status'        => 'active',
				'capacity'      => null,
				'wc_product_id' => 300,
			)
		);

		$this->occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );

		$product = \Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'set_name' )->once();
		$product->shouldReceive( 'set_status' )->once();
		$product->shouldReceive( 'set_catalog_visibility' )->once();
		$product->shouldReceive( 'set_price' )->once();
		$product->shouldReceive( 'set_regular_price' )->once();
		$product->shouldReceive( 'set_virtual' )->once();
		$product->shouldReceive( 'set_sold_individually' )->once();
		$product->shouldReceive( 'set_manage_stock' )->once();
		$product->shouldReceive( 'set_stock_status' )->once();
		$product->shouldReceive( 'update_meta_data' )->zeroOrMoreTimes();
		// Lock is closed (SKU already present) → set_sku must NOT be called.
		$product->shouldReceive( 'get_sku' )->andReturn( 'summer-fest--vip' );
		$product->shouldReceive( 'set_sku' )->never();
		$product->shouldReceive( 'save' )->once()->andReturn( 300 );

		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( '__' )->alias( fn( $text ) => $text );

		$this->manager->sync_product( $ticket_type, $occurrence );
	}

	// =========================================================================
	// sync_event_product Tests (series pass — NTE-156)
	// =========================================================================

	/**
	 * Test sync_event_product marks the product as a series pass with no date link.
	 *
	 * A pass belongs to the event, not any one date, so the product carries the
	 * event id and the series-pass marker and NO occurrence id (NTE-156).
	 *
	 * @return void
	 */
	public function test_sync_event_product_stores_series_pass_meta(): void {
		ServiceRegistry::reset();

		try {
			$ticket_type = TicketTypeFactory::create(
				array(
					'id'            => 9,
					'name'          => 'Full Festival Pass',
					'price'         => 40.00,
					'status'        => 'active',
					'capacity'      => null,
					'wc_product_id' => null,
				)
			);
			$ticket_type->scope    = TicketTypeScope::EVENT->value;
			$ticket_type->event_id = 5;

			$event = EventFactory::create( array( 'id' => 5, 'title' => 'Summer Fest', 'slug' => 'summer-fest' ) );

			$event_repo = $this->createMock( EventRepositoryInterface::class );
			$event_repo->method( 'find' )->with( 5 )->willReturn( $event );
			ServiceRegistry::set( EventRepositoryInterface::class, $event_repo );

			$this->ticket_type_repo->method( 'link_to_product' )->willReturn( true );
			// No calculator/house injected → stock falls to the tier's own remainder.
			$this->ticket_type_repo->method( 'get_available_count' )->willReturn( null );

			$meta_stored = array();
			$product     = \Mockery::mock( 'overload:WC_Product_Simple' );
			$product->shouldReceive( 'set_name' )->once();
			$product->shouldReceive( 'set_status' )->with( 'publish' )->once();
			$product->shouldReceive( 'set_catalog_visibility' )->once();
			$product->shouldReceive( 'set_price' )->with( '40' )->once();
			$product->shouldReceive( 'set_regular_price' )->with( '40' )->once();
			$product->shouldReceive( 'set_virtual' )->once();
			$product->shouldReceive( 'set_sold_individually' )->once();
			$product->shouldReceive( 'set_manage_stock' )->once();
			$product->shouldReceive( 'set_stock_status' )->once();
			$product->shouldReceive( 'update_meta_data' )->andReturnUsing(
				function ( $key, $value ) use ( &$meta_stored ) {
					$meta_stored[ $key ] = $value;
				}
			);
			$product->shouldReceive( 'get_sku' )->andReturn( '' );
			$product->shouldReceive( 'get_id' )->andReturn( 0 );
			$product->shouldReceive( 'set_sku' )->zeroOrMoreTimes();
			$product->shouldReceive( 'save' )->once()->andReturn( 900 );

			Functions\when( 'wc_get_product' )->justReturn( null );
			Functions\when( '__' )->alias( fn( $text ) => $text );

			$result = $this->manager->sync_event_product( $ticket_type );

			$this->assertSame( 900, $result );
			$this->assertSame( '9', $meta_stored[ ProductManager::META_TICKET_TYPE_ID ] );
			$this->assertSame( 'yes', $meta_stored['_nettertech_events_is_event_ticket'] );
			$this->assertSame( '5', $meta_stored[ MetaKeys::EVENT_ID ] );
			$this->assertSame( 'yes', $meta_stored[ MetaKeys::IS_SERIES_PASS ] );
			// A pass has no single date, so it must never carry an occurrence link.
			$this->assertArrayNotHasKey( ProductManager::META_OCCURRENCE_ID, $meta_stored );
		} finally {
			ServiceRegistry::reset();
		}
	}

	/**
	 * Test sync_event_product returns 0 for a non-event-scoped tier without touching WC.
	 *
	 * @return void
	 */
	public function test_sync_event_product_returns_zero_for_non_event_scope(): void {
		$ticket_type = TicketTypeFactory::create( array( 'id' => 9 ) );
		$ticket_type->scope = TicketTypeScope::OCCURRENCE->value;

		// wc_get_product must never be reached on the early-return path.
		Functions\when( 'wc_get_product' )->alias(
			static function () {
				throw new \RuntimeException( 'WC must not be touched for a non-event-scoped tier.' );
			}
		);

		$this->assertSame( 0, $this->manager->sync_event_product( $ticket_type ) );
	}

	/**
	 * Test sync_event_product returns 0 for an event-scoped tier with no event id.
	 *
	 * Guards the second arm of the scope check (line 692): scope is EVENT but the
	 * event link is missing, so there is nothing to build a pass against and WC
	 * must not be touched. A LogicalOr→And mutation would fall through here.
	 *
	 * @return void
	 */
	public function test_sync_event_product_returns_zero_when_event_id_null(): void {
		$ticket_type           = TicketTypeFactory::create( array( 'id' => 9 ) );
		$ticket_type->scope    = TicketTypeScope::EVENT->value;
		$ticket_type->event_id = null;

		Functions\when( 'wc_get_product' )->alias(
			static function () {
				throw new \RuntimeException( 'WC must not be touched when event_id is null.' );
			}
		);

		$this->assertSame( 0, $this->manager->sync_event_product( $ticket_type ) );
	}

	/**
	 * Test sync_event_product sets every product property exactly (limited stock).
	 *
	 * Pins the full property write-out for a publishable, image-bearing pass whose
	 * calculator caps stock above zero: name, publish status, price strings,
	 * virtual/sold-individually flags, image, the managed-stock branch, the
	 * event-slug SKU, and the product-link update.
	 *
	 * @return void
	 */
	public function test_sync_event_product_sets_all_properties_limited_stock(): void {
		ServiceRegistry::reset();

		try {
			$ticket_type = TicketTypeFactory::create(
				array(
					'id'            => 9,
					'name'          => 'Full Festival Pass',
					'price'         => 40.00,
					'status'        => 'active',
					'capacity'      => 50,
					'wc_product_id' => null,
				)
			);
			$ticket_type->scope    = TicketTypeScope::EVENT->value;
			$ticket_type->event_id = 5;

			$event                    = EventFactory::create(
				array(
					'id'    => 5,
					'title' => 'Summer Fest',
					'slug'  => 'summer-fest',
				)
			);
			$event->featured_image_id = 77;

			$event_repo = $this->createMock( EventRepositoryInterface::class );
			$event_repo->method( 'find' )->with( 5 )->willReturn( $event );
			ServiceRegistry::set( EventRepositoryInterface::class, $event_repo );

			$calculator = $this->createMock( \NetterTechEvents\Contracts\CapacityCalculatorInterface::class );
			$calculator->method( 'get_available_count' )->willReturn( 15 );

			$manager = new ProductManager(
				$this->ticket_type_repo,
				$this->occurrence_repo,
				null,
				null,
				$calculator
			);

			$this->ticket_type_repo
				->expects( $this->once() )
				->method( 'link_to_product' )
				->with( 9, 900 )
				->willReturn( true );

			$product = \Mockery::mock( 'overload:WC_Product_Simple' );
			$product->shouldReceive( 'set_name' )->with( 'Summer Fest - Series Pass - Full Festival Pass' )->once();
			$product->shouldReceive( 'set_status' )->with( 'publish' )->once();
			$product->shouldReceive( 'set_catalog_visibility' )->with( 'hidden' )->once();
			$product->shouldReceive( 'set_price' )->with( '40' )->once();
			$product->shouldReceive( 'set_regular_price' )->with( '40' )->once();
			$product->shouldReceive( 'set_virtual' )->with( true )->once();
			$product->shouldReceive( 'set_sold_individually' )->with( false )->once();
			$product->shouldReceive( 'set_image_id' )->with( 77 )->once();
			$product->shouldReceive( 'set_manage_stock' )->with( true )->once();
			$product->shouldReceive( 'set_stock_quantity' )->with( 15 )->once();
			$product->shouldReceive( 'set_stock_status' )->with( 'instock' )->once();
			$product->shouldReceive( 'set_backorders' )->with( 'no' )->once();
			$product->shouldReceive( 'update_meta_data' )->zeroOrMoreTimes();
			$product->shouldReceive( 'get_sku' )->andReturn( '' );
			$product->shouldReceive( 'get_id' )->andReturn( 0 );
			$product->shouldReceive( 'set_sku' )->with( 'summer-fest--full-festival-pass' )->once();
			$product->shouldReceive( 'save' )->once()->andReturn( 900 );

			Functions\when( 'wc_get_product' )->justReturn( null );
			Functions\when( '__' )->alias( fn( $text ) => $text );

			$this->assertSame( 900, $manager->sync_event_product( $ticket_type ) );
		} finally {
			ServiceRegistry::reset();
		}
	}

	/**
	 * Test sync_event_product publishes zero stock as out of stock.
	 *
	 * The managed-stock branch with a calculator remainder of exactly zero: the
	 * quantity is written as 0 and the status flips to `outofstock`.
	 *
	 * @return void
	 */
	public function test_sync_event_product_zero_stock_is_outofstock(): void {
		ServiceRegistry::reset();

		try {
			$ticket_type = TicketTypeFactory::create(
				array(
					'id'            => 9,
					'name'          => 'Full Festival Pass',
					'wc_product_id' => null,
				)
			);
			$ticket_type->scope    = TicketTypeScope::EVENT->value;
			$ticket_type->event_id = 5;

			$event      = EventFactory::create( array( 'id' => 5, 'slug' => 'summer-fest' ) );
			$event_repo = $this->createMock( EventRepositoryInterface::class );
			$event_repo->method( 'find' )->with( 5 )->willReturn( $event );
			ServiceRegistry::set( EventRepositoryInterface::class, $event_repo );

			$calculator = $this->createMock( \NetterTechEvents\Contracts\CapacityCalculatorInterface::class );
			$calculator->method( 'get_available_count' )->willReturn( 0 );

			$manager = new ProductManager(
				$this->ticket_type_repo,
				$this->occurrence_repo,
				null,
				null,
				$calculator
			);
			$this->ticket_type_repo->method( 'link_to_product' )->willReturn( true );

			$product = \Mockery::mock( 'overload:WC_Product_Simple' );
			$product->shouldReceive( 'set_name' )->once();
			$product->shouldReceive( 'set_status' )->once();
			$product->shouldReceive( 'set_catalog_visibility' )->once();
			$product->shouldReceive( 'set_price' )->once();
			$product->shouldReceive( 'set_regular_price' )->once();
			$product->shouldReceive( 'set_virtual' )->once();
			$product->shouldReceive( 'set_sold_individually' )->once();
			$product->shouldReceive( 'set_manage_stock' )->with( true )->once();
			$product->shouldReceive( 'set_stock_quantity' )->with( 0 )->once();
			$product->shouldReceive( 'set_stock_status' )->with( 'outofstock' )->once();
			$product->shouldReceive( 'set_backorders' )->with( 'no' )->once();
			$product->shouldReceive( 'update_meta_data' )->zeroOrMoreTimes();
			$product->shouldReceive( 'get_sku' )->andReturn( '' );
			$product->shouldReceive( 'get_id' )->andReturn( 0 );
			$product->shouldReceive( 'set_sku' )->zeroOrMoreTimes();
			$product->shouldReceive( 'save' )->once()->andReturn( 901 );

			Functions\when( 'wc_get_product' )->justReturn( null );
			Functions\when( '__' )->alias( fn( $text ) => $text );

			$this->assertSame( 901, $manager->sync_event_product( $ticket_type ) );
		} finally {
			ServiceRegistry::reset();
		}
	}

	/**
	 * Test sync_event_product turns stock management OFF for an unbounded pass.
	 *
	 * A null tier id short-circuits stock to null (line 725), so the product is
	 * marked unmanaged and always in stock — the quantity/backorders writes and
	 * the product-link update never fire.
	 *
	 * @return void
	 */
	public function test_sync_event_product_unbounded_disables_stock(): void {
		ServiceRegistry::reset();

		try {
			$ticket_type = TicketTypeFactory::create(
				array(
					'name'          => 'Full Festival Pass',
					'wc_product_id' => null,
				)
			);
			// Factory's `id ?? counter` never yields null, so clear it explicitly.
			$ticket_type->id       = null;
			$ticket_type->scope    = TicketTypeScope::EVENT->value;
			$ticket_type->event_id = 5;

			$event      = EventFactory::create( array( 'id' => 5, 'slug' => 'summer-fest' ) );
			$event_repo = $this->createMock( EventRepositoryInterface::class );
			$event_repo->method( 'find' )->with( 5 )->willReturn( $event );
			ServiceRegistry::set( EventRepositoryInterface::class, $event_repo );

			// Null id → stock resolves to null before any calculator/repo lookup.
			$this->ticket_type_repo
				->expects( $this->never() )
				->method( 'link_to_product' );

			$product = \Mockery::mock( 'overload:WC_Product_Simple' );
			$product->shouldReceive( 'set_name' )->once();
			$product->shouldReceive( 'set_status' )->once();
			$product->shouldReceive( 'set_catalog_visibility' )->once();
			$product->shouldReceive( 'set_price' )->once();
			$product->shouldReceive( 'set_regular_price' )->once();
			$product->shouldReceive( 'set_virtual' )->once();
			$product->shouldReceive( 'set_sold_individually' )->once();
			$product->shouldReceive( 'set_manage_stock' )->with( false )->once();
			$product->shouldReceive( 'set_stock_status' )->with( 'instock' )->once();
			$product->shouldReceive( 'set_stock_quantity' )->never();
			$product->shouldReceive( 'set_backorders' )->never();
			$product->shouldReceive( 'update_meta_data' )->zeroOrMoreTimes();
			$product->shouldReceive( 'get_sku' )->andReturn( '' );
			$product->shouldReceive( 'get_id' )->andReturn( 0 );
			$product->shouldReceive( 'set_sku' )->zeroOrMoreTimes();
			$product->shouldReceive( 'save' )->once()->andReturn( 902 );

			Functions\when( 'wc_get_product' )->justReturn( null );
			Functions\when( '__' )->alias( fn( $text ) => $text );

			$this->assertSame( 902, $this->manager->sync_event_product( $ticket_type ) );
		} finally {
			ServiceRegistry::reset();
		}
	}

	/**
	 * Test sync_event_product loads and reuses an existing linked product.
	 *
	 * A non-empty wc_product_id sends the code down the wc_get_product() arm of
	 * line 697; when that product's id already equals the saved id the ticket-type
	 * link is left untouched (line 755).
	 *
	 * @return void
	 */
	public function test_sync_event_product_updates_existing_product_without_relink(): void {
		ServiceRegistry::reset();

		try {
			$ticket_type = TicketTypeFactory::create(
				array(
					'id'            => 9,
					'name'          => 'Full Festival Pass',
					'wc_product_id' => 950,
				)
			);
			$ticket_type->scope    = TicketTypeScope::EVENT->value;
			$ticket_type->event_id = 5;

			$event      = EventFactory::create( array( 'id' => 5, 'slug' => 'summer-fest' ) );
			$event_repo = $this->createMock( EventRepositoryInterface::class );
			$event_repo->method( 'find' )->with( 5 )->willReturn( $event );
			ServiceRegistry::set( EventRepositoryInterface::class, $event_repo );

			$this->ticket_type_repo->method( 'get_available_count' )->willReturn( null );
			// Ids already agree → no re-link.
			$this->ticket_type_repo
				->expects( $this->never() )
				->method( 'link_to_product' );

			$existing = \Mockery::mock( \WC_Product::class );
			$existing->shouldReceive( 'set_name' )->once();
			$existing->shouldReceive( 'set_status' )->once();
			$existing->shouldReceive( 'set_catalog_visibility' )->once();
			$existing->shouldReceive( 'set_price' )->once();
			$existing->shouldReceive( 'set_regular_price' )->once();
			$existing->shouldReceive( 'set_virtual' )->once();
			$existing->shouldReceive( 'set_sold_individually' )->once();
			$existing->shouldReceive( 'set_manage_stock' )->once();
			$existing->shouldReceive( 'set_stock_status' )->once();
			$existing->shouldReceive( 'update_meta_data' )->zeroOrMoreTimes();
			// SKU already present → the lock holds, set_sku must never fire.
			$existing->shouldReceive( 'get_sku' )->andReturn( 'summer-fest--full-festival-pass' );
			$existing->shouldReceive( 'get_id' )->andReturn( 950 );
			$existing->shouldReceive( 'set_sku' )->never();
			$existing->shouldReceive( 'save' )->once()->andReturn( 950 );

			Functions\when( 'wc_get_product' )->alias(
				static function ( $id ) use ( $existing ) {
					return 950 === $id ? $existing : null;
				}
			);
			Functions\when( '__' )->alias( fn( $text ) => $text );

			$this->assertSame( 950, $this->manager->sync_event_product( $ticket_type ) );
		} finally {
			ServiceRegistry::reset();
		}
	}

	/**
	 * Test sync_event_product skips SKU assignment when no slug material exists.
	 *
	 * With no event found (fallback title) and an empty ticket name, resolve_sku
	 * yields '' and the `'' !== $sku` guard (line 744) must stop set_sku() firing.
	 *
	 * @return void
	 */
	public function test_sync_event_product_skips_sku_when_no_material(): void {
		ServiceRegistry::reset();

		try {
			$ticket_type = TicketTypeFactory::create(
				array(
					'id'            => 9,
					'name'          => '',
					'wc_product_id' => null,
				)
			);
			$ticket_type->scope    = TicketTypeScope::EVENT->value;
			$ticket_type->event_id = 5;

			// No event resolved → fallback title, empty slug.
			$event_repo = $this->createMock( EventRepositoryInterface::class );
			$event_repo->method( 'find' )->with( 5 )->willReturn( null );
			ServiceRegistry::set( EventRepositoryInterface::class, $event_repo );

			$this->ticket_type_repo->method( 'get_available_count' )->willReturn( null );
			$this->ticket_type_repo->method( 'link_to_product' )->willReturn( true );

			$captured_name = null;
			$product       = \Mockery::mock( 'overload:WC_Product_Simple' );
			$product->shouldReceive( 'set_name' )->andReturnUsing(
				function ( $name ) use ( &$captured_name ) {
					$captured_name = $name;
				}
			);
			$product->shouldReceive( 'set_status' )->once();
			$product->shouldReceive( 'set_catalog_visibility' )->once();
			$product->shouldReceive( 'set_price' )->once();
			$product->shouldReceive( 'set_regular_price' )->once();
			$product->shouldReceive( 'set_virtual' )->once();
			$product->shouldReceive( 'set_sold_individually' )->once();
			$product->shouldReceive( 'set_manage_stock' )->once();
			$product->shouldReceive( 'set_stock_status' )->once();
			$product->shouldReceive( 'update_meta_data' )->zeroOrMoreTimes();
			$product->shouldReceive( 'get_sku' )->andReturn( '' );
			$product->shouldReceive( 'get_id' )->andReturn( 0 );
			// Empty ticket name + empty slug → no SKU material → never set.
			$product->shouldReceive( 'set_sku' )->never();
			$product->shouldReceive( 'save' )->once()->andReturn( 903 );

			Functions\when( 'wc_get_product' )->justReturn( null );
			Functions\when( '__' )->alias( fn( $text ) => $text );

			$this->assertSame( 903, $this->manager->sync_event_product( $ticket_type ) );
			// Fallback title used when the event lookup misses.
			$this->assertStringContainsString( 'Event', (string) $captured_name );
		} finally {
			ServiceRegistry::reset();
		}
	}

	// =========================================================================
	// create_products_for_event Tests (series passes — NTE-156)
	// =========================================================================

	/**
	 * Test create_products_for_event syncs only event-scoped tiers with a positive id.
	 *
	 * An occurrence-scoped tier is skipped by the scope guard; an event-scoped tier
	 * whose sync yields 0 is filtered by the `> 0` collection guard.
	 *
	 * @return void
	 */
	public function test_create_products_for_event_filters_scope_and_zero(): void {
		ServiceRegistry::reset();

		try {
			$occurrence_tier        = TicketTypeFactory::create( array( 'id' => 1 ) );
			$occurrence_tier->scope = TicketTypeScope::OCCURRENCE->value;

			// Event-scoped but with no event id → sync_event_product returns 0.
			$zero_pass           = TicketTypeFactory::create( array( 'id' => 2 ) );
			$zero_pass->scope    = TicketTypeScope::EVENT->value;
			$zero_pass->event_id = null;

			// Event-scoped and syncable → contributes its product id.
			$good_pass                = TicketTypeFactory::create(
				array(
					'id'            => 3,
					'name'          => 'Full Festival Pass',
					'wc_product_id' => null,
				)
			);
			$good_pass->scope         = TicketTypeScope::EVENT->value;
			$good_pass->event_id      = 5;

			$event      = EventFactory::create( array( 'id' => 5, 'slug' => 'summer-fest' ) );
			$event_repo = $this->createMock( EventRepositoryInterface::class );
			$event_repo->method( 'find' )->with( 5 )->willReturn( $event );
			ServiceRegistry::set( EventRepositoryInterface::class, $event_repo );

			$this->ticket_type_repo
				->method( 'for_event' )
				->with( 5 )
				->willReturn( array( $occurrence_tier, $zero_pass, $good_pass ) );
			$this->ticket_type_repo->method( 'get_available_count' )->willReturn( null );
			$this->ticket_type_repo->method( 'link_to_product' )->willReturn( true );

			$product = \Mockery::mock( 'overload:WC_Product_Simple' );
			$product->shouldReceive( 'set_name', 'set_status', 'set_catalog_visibility', 'set_price', 'set_regular_price', 'set_virtual', 'set_sold_individually', 'set_manage_stock', 'set_stock_status', 'update_meta_data', 'set_sku' )->zeroOrMoreTimes();
			$product->shouldReceive( 'get_sku' )->andReturn( '' );
			$product->shouldReceive( 'get_id' )->andReturn( 0 );
			$product->shouldReceive( 'save' )->once()->andReturn( 910 );

			Functions\when( 'wc_get_product' )->justReturn( null );
			Functions\when( '__' )->alias( fn( $text ) => $text );

			$result = $this->manager->create_products_for_event( 5 );

			// Only the one good event-scoped pass survives both guards.
			$this->assertSame( array( 910 ), $result );
		} finally {
			ServiceRegistry::reset();
		}
	}

	/**
	 * existing_only re-syncs event tiers that already have a product and mints nothing (R5).
	 *
	 * @return void
	 */
	public function test_create_products_for_event_existing_only_skips_productless_tiers(): void {
		$with_product                = TicketTypeFactory::create( array( 'id' => 3, 'name' => 'Full Pass' ) );
		$with_product->scope         = TicketTypeScope::EVENT->value;
		$with_product->event_id      = 5;
		$with_product->wc_product_id = 777;

		$no_product                  = TicketTypeFactory::create( array( 'id' => 4, 'name' => 'New Pass' ) );
		$no_product->scope           = TicketTypeScope::EVENT->value;
		$no_product->event_id        = 5;
		$no_product->wc_product_id   = null;

		$this->ticket_type_repo
			->method( 'for_event' )
			->with( 5 )
			->willReturn( array( $with_product, $no_product ) );

		$manager = $this->getMockBuilder( ProductManager::class )
			->setConstructorArgs( array( $this->ticket_type_repo, $this->occurrence_repo ) )
			->onlyMethods( array( 'sync_event_product' ) )
			->getMock();
		// Only the tier that already has a product is re-synced; the productless one is skipped.
		$manager->expects( $this->once() )
			->method( 'sync_event_product' )
			->with( $with_product, null )
			->willReturn( 777 );

		$result = $manager->create_products_for_event( 5, array(), true );

		$this->assertSame( array( 777 ), $result );
	}

	// =========================================================================
	// sync_stock event-scope peer expansion Tests (NTE-156)
	// =========================================================================

	/**
	 * Test sync_stock republishes every date's tiers when a series pass sells.
	 *
	 * A sold pass takes a seat on every date, so sync_stock for an event-scoped
	 * tier must expand to each occurrence's tiers and sync them too — de-duplicated
	 * against the tier that moved. Only an EVENT scope with a real event id triggers
	 * the expansion (lines 810-811).
	 *
	 * @return void
	 */
	public function test_sync_stock_expands_to_occurrence_peers_for_event_scope(): void {
		$synced_ids = array();

		// The pass (id 1) and per-date tiers (ids 2, 3). id 1 also reappears as an
		// occurrence peer to prove the array_unique dedup.
		$this->ticket_type_repo->method( 'find' )->willReturnCallback(
			function ( $id ) {
				$tt                = TicketTypeFactory::create(
					array(
						'id'            => $id,
						'wc_product_id' => 100 + $id,
					)
				);
				if ( 1 === $id ) {
					$tt->scope    = TicketTypeScope::EVENT->value;
					$tt->event_id = 5;
				}
				return $tt;
			}
		);

		$this->ticket_type_repo->method( 'get_available_count' )->willReturnCallback(
			function ( $id ) use ( &$synced_ids ) {
				$synced_ids[] = $id;
				return 7;
			}
		);

		$occurrence_a = OccurrenceFactory::create( array( 'id' => 10 ) );
		$occurrence_b = OccurrenceFactory::create( array( 'id' => 11 ) );
		$this->occurrence_repo->method( 'for_event' )->with( 5 )->willReturn( array( $occurrence_a, $occurrence_b ) );

		$this->ticket_type_repo->method( 'for_occurrence' )->willReturnCallback(
			function ( $occurrence_id ) {
				if ( 10 === $occurrence_id ) {
					return array( TicketTypeFactory::create( array( 'id' => 2 ) ) );
				}
				// Date 11 re-lists the pass (id 1, dedup) plus a fresh tier (id 3).
				return array(
					TicketTypeFactory::create( array( 'id' => 1 ) ),
					TicketTypeFactory::create( array( 'id' => 3 ) ),
				);
			}
		);

		$product = \Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'set_manage_stock' )->zeroOrMoreTimes();
		$product->shouldReceive( 'set_stock_quantity' )->zeroOrMoreTimes();
		$product->shouldReceive( 'set_stock_status' )->zeroOrMoreTimes();
		$product->shouldReceive( 'save' )->zeroOrMoreTimes();

		Functions\when( 'wc_get_product' )->justReturn( $product );

		$this->assertTrue( $this->manager->sync_stock( 1 ) );

		sort( $synced_ids );
		// The pass plus both dates' tiers, each synced exactly once (deduped).
		$this->assertSame( array( 1, 2, 3 ), $synced_ids );
	}

	/**
	 * Test sync_stock does not expand for an event tier with a null event id.
	 *
	 * The null-event-id arm of line 811: without an event to walk, only the tier
	 * that moved is synced — no occurrence peers are pulled in.
	 *
	 * @return void
	 */
	public function test_sync_stock_no_expansion_when_event_id_null(): void {
		$synced_ids = array();

		$this->ticket_type_repo->method( 'find' )->willReturnCallback(
			function ( $id ) {
				$tt           = TicketTypeFactory::create(
					array(
						'id'            => $id,
						'wc_product_id' => 100 + $id,
					)
				);
				$tt->scope    = TicketTypeScope::EVENT->value;
				$tt->event_id = null;
				return $tt;
			}
		);

		$this->ticket_type_repo->method( 'get_available_count' )->willReturnCallback(
			function ( $id ) use ( &$synced_ids ) {
				$synced_ids[] = $id;
				return 7;
			}
		);

		// for_event must never be consulted when there is no event id.
		$this->occurrence_repo
			->expects( $this->never() )
			->method( 'for_event' );

		$product = \Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'set_manage_stock' )->zeroOrMoreTimes();
		$product->shouldReceive( 'set_stock_quantity' )->zeroOrMoreTimes();
		$product->shouldReceive( 'set_stock_status' )->zeroOrMoreTimes();
		$product->shouldReceive( 'save' )->zeroOrMoreTimes();

		Functions\when( 'wc_get_product' )->justReturn( $product );

		$this->assertTrue( $this->manager->sync_stock( 1 ) );
		$this->assertSame( array( 1 ), $synced_ids );
	}

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test class exists.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( ProductManager::class ) );
	}

	/**
	 * Test required methods exist.
	 *
	 * @return void
	 */
	public function test_required_methods_exist(): void {
		$methods = array(
			'sync_product',
			'delete_product',
			'get_product',
			'create_products_for_occurrence',
			'sync_stock',
			'get_ticket_type_from_product',
			'get_occurrence_from_product',
			'is_event_ticket',
			'get_add_to_cart_url',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( ProductManager::class, $method ),
				"Method {$method} should exist"
			);
		}
	}
}
