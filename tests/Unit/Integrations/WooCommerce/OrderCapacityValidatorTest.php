<?php
/**
 * OrderCapacityValidator unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Integrations\WooCommerce\OrderCapacityValidator;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;

/**
 * Test OrderCapacityValidator functionality.
 *
 * Tests capacity validation at payment time and oversell handling.
 */
class OrderCapacityValidatorTest extends \NetterTechEventsTestCase {

	/**
	 * Validator under test.
	 *
	 * @var OrderCapacityValidator
	 */
	private OrderCapacityValidator $validator;

	/**
	 * Mock product manager.
	 *
	 * @var ProductManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $product_manager;

	/**
	 * Mock ticket type repo.
	 *
	 * @var TicketTypeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * Mock capacity service.
	 *
	 * @var CapacityServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $capacity_service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->product_manager  = $this->createMock( ProductManager::class );
		$this->ticket_type_repo = $this->createMock( TicketTypeRepositoryInterface::class );
		$this->capacity_service = $this->createMock( CapacityServiceInterface::class );

		$this->validator = new OrderCapacityValidator(
			$this->product_manager,
			$this->ticket_type_repo,
			$this->capacity_service,
		);

		TicketTypeFactory::reset();
	}

	// =========================================================================
	// validate() Tests
	// =========================================================================

	/**
	 * Test validate returns empty when order has no items.
	 *
	 * @return void
	 */
	public function test_validate_returns_empty_for_no_items(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( [] );

		$issues = $this->validator->validate( $order );

		$this->assertEmpty( $issues );
	}

	/**
	 * Test validate skips non-product items.
	 *
	 * @return void
	 */
	public function test_validate_skips_non_product_items(): void {
		$order = $this->createMock( \WC_Order::class );
		// Return a non-WC_Order_Item_Product object.
		$order->method( 'get_items' )->willReturn( [ new \stdClass() ] );

		$issues = $this->validator->validate( $order );

		$this->assertEmpty( $issues );
	}

	/**
	 * Test validate skips non-ticket products.
	 *
	 * @return void
	 */
	public function test_validate_skips_non_ticket_products(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 100 );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( [ $item ] );

		$this->product_manager->method( 'is_event_ticket' )->with( 100 )->willReturn( false );

		$issues = $this->validator->validate( $order );

		$this->assertEmpty( $issues );
	}

	/**
	 * Test validate skips items without ticket type ID.
	 *
	 * @return void
	 */
	public function test_validate_skips_items_without_ticket_type_id(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 100 );
		$item->method( 'get_meta' )->willReturn( '' );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( [ $item ] );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );

		$issues = $this->validator->validate( $order );

		$this->assertEmpty( $issues );
	}

	/**
	 * Test validate returns no issues when capacity is unlimited.
	 *
	 * @return void
	 */
	public function test_validate_returns_no_issues_for_unlimited_capacity(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 100 );
		$item->method( 'get_meta' )->willReturnCallback( function ( $key ) {
			return $key === MetaKeys::TICKET_TYPE_ID ? '5' : '';
		} );
		$item->method( 'get_quantity' )->willReturn( 2 );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( [ $item ] );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );

		$ticket_type       = TicketTypeFactory::create( [ 'id' => 5, 'name' => 'GA' ] );
		$this->ticket_type_repo->method( 'find' )->with( 5 )->willReturn( $ticket_type );

		$this->capacity_service->method( 'get_available_count' )->willReturn( null );

		$issues = $this->validator->validate( $order );

		$this->assertEmpty( $issues );
	}

	/**
	 * Test validate returns no issues when sufficient capacity.
	 *
	 * @return void
	 */
	public function test_validate_returns_no_issues_when_sufficient_capacity(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 100 );
		$item->method( 'get_meta' )->willReturnCallback( function ( $key ) {
			return $key === MetaKeys::TICKET_TYPE_ID ? '5' : '';
		} );
		$item->method( 'get_quantity' )->willReturn( 2 );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( [ $item ] );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );

		$ticket_type = TicketTypeFactory::create( [ 'id' => 5, 'name' => 'GA' ] );
		$this->ticket_type_repo->method( 'find' )->with( 5 )->willReturn( $ticket_type );

		$this->capacity_service->method( 'get_available_count' )->willReturn( 10 );

		$issues = $this->validator->validate( $order );

		$this->assertEmpty( $issues );
	}

	/**
	 * Test validate returns issue when capacity exceeded.
	 *
	 * @return void
	 */
	public function test_validate_returns_issue_when_capacity_exceeded(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 100 );
		$item->method( 'get_meta' )->willReturnCallback( function ( $key ) {
			return $key === MetaKeys::TICKET_TYPE_ID ? '5' : '';
		} );
		$item->method( 'get_quantity' )->willReturn( 3 );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( [ $item ] );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );

		$ticket_type = TicketTypeFactory::create( [ 'id' => 5, 'name' => 'VIP' ] );
		$this->ticket_type_repo->method( 'find' )->with( 5 )->willReturn( $ticket_type );

		$this->capacity_service->method( 'get_available_count' )->willReturn( 1 );

		$issues = $this->validator->validate( $order );

		$this->assertCount( 1, $issues );
		$this->assertSame( 5, $issues[0]['ticket_type_id'] );
		$this->assertSame( 'VIP', $issues[0]['name'] );
		$this->assertSame( 3, $issues[0]['requested'] );
		$this->assertSame( 1, $issues[0]['available'] );
	}

	/**
	 * Test validate skips items when ticket type not found.
	 *
	 * @return void
	 */
	public function test_validate_skips_when_ticket_type_not_found(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 100 );
		$item->method( 'get_meta' )->willReturnCallback( function ( $key ) {
			return $key === MetaKeys::TICKET_TYPE_ID ? '99' : '';
		} );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( [ $item ] );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->ticket_type_repo->method( 'find' )->with( 99 )->willReturn( null );

		$issues = $this->validator->validate( $order );

		$this->assertEmpty( $issues );
	}

	// =========================================================================
	// handle_issues() Tests
	// =========================================================================

	/**
	 * Test handle_issues adds order note.
	 *
	 * @return void
	 */
	public function test_handle_issues_adds_order_note(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 42 );
		$order->expects( $this->once() )->method( 'add_order_note' );

		$issues = [
			[
				'ticket_type_id' => 5,
				'name'           => 'GA',
				'requested'      => 3,
				'available'      => 1,
			],
		];

		$this->validator->handle_issues( $order, $issues );
	}

	/**
	 * Test handle_issues fires oversell hook.
	 *
	 * @return void
	 */
	public function test_handle_issues_fires_oversell_hook(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 42 );

		$hook_fired = false;
		Functions\when( 'do_action' )->alias( function ( $hook ) use ( &$hook_fired ) {
			if ( 'nettertech_events_capacity_oversell_detected' === $hook ) {
				$hook_fired = true;
			}
		} );

		$issues = [
			[
				'ticket_type_id' => 5,
				'name'           => 'GA',
				'requested'      => 3,
				'available'      => 1,
			],
		];

		$this->validator->handle_issues( $order, $issues );

		$this->assertTrue( $hook_fired );
	}
}
