<?php
/**
 * WooCommerceFactory unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Factories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Factories;

use NetterTechEvents\Tests\Factories\MockWCCart;
use NetterTechEvents\Tests\Factories\MockWCOrder;
use NetterTechEvents\Tests\Factories\MockWCOrderItemProduct;
use NetterTechEvents\Tests\Factories\MockWCOrderRefund;
use NetterTechEvents\Tests\Factories\MockWCProduct;
use NetterTechEvents\Tests\Factories\MockWCSingleton;
use NetterTechEvents\Tests\Factories\WooCommerceFactory;

/**
 * Test WooCommerceFactory functionality.
 */
class WooCommerceFactoryTest extends \NetterTechEventsTestCase {

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		WooCommerceFactory::reset();
	}

	// =========================================================================
	// Order Creation Tests
	// =========================================================================

	/**
	 * Test create_order returns MockWCOrder instance.
	 *
	 * @return void
	 */
	public function test_create_order_returns_mock_wc_order(): void {
		$order = WooCommerceFactory::create_order();

		$this->assertInstanceOf( MockWCOrder::class, $order );
	}

	/**
	 * Test create_order uses defaults.
	 *
	 * @return void
	 */
	public function test_create_order_uses_defaults(): void {
		$order = WooCommerceFactory::create_order();

		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'John', $order->get_billing_first_name() );
		$this->assertSame( 'Doe', $order->get_billing_last_name() );
		$this->assertSame( 'john.doe@example.com', $order->get_billing_email() );
		$this->assertSame( '555-1234', $order->get_billing_phone() );
	}

	/**
	 * Test create_order accepts custom attributes.
	 *
	 * @return void
	 */
	public function test_create_order_accepts_custom_attributes(): void {
		$order = WooCommerceFactory::create_order(
			array(
				'status'             => 'pending',
				'billing_first_name' => 'Jane',
				'billing_email'      => 'jane@example.com',
			)
		);

		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'Jane', $order->get_billing_first_name() );
		$this->assertSame( 'jane@example.com', $order->get_billing_email() );
	}

	/**
	 * Test create_order generates unique IDs.
	 *
	 * @return void
	 */
	public function test_create_order_generates_unique_ids(): void {
		$order1 = WooCommerceFactory::create_order();
		$order2 = WooCommerceFactory::create_order();
		$order3 = WooCommerceFactory::create_order();

		$this->assertNotSame( $order1->get_id(), $order2->get_id() );
		$this->assertNotSame( $order2->get_id(), $order3->get_id() );
	}

	/**
	 * Test completed_order helper.
	 *
	 * @return void
	 */
	public function test_completed_order_helper(): void {
		$order = WooCommerceFactory::completed_order();

		$this->assertSame( 'completed', $order->get_status() );
	}

	/**
	 * Test pending_order helper.
	 *
	 * @return void
	 */
	public function test_pending_order_helper(): void {
		$order = WooCommerceFactory::pending_order();

		$this->assertSame( 'pending', $order->get_status() );
	}

	/**
	 * Test order meta data operations.
	 *
	 * @return void
	 */
	public function test_order_meta_data_operations(): void {
		$order = WooCommerceFactory::create_order(
			array(
				'meta_data' => array(
					'_nettertech_events_ticket_ids' => array( 1, 2, 3 ),
				),
			)
		);

		$this->assertSame( array( 1, 2, 3 ), $order->get_meta( '_nettertech_events_ticket_ids' ) );

		$order->update_meta_data( '_nettertech_events_custom', 'test_value' );
		$this->assertSame( 'test_value', $order->get_meta( '_nettertech_events_custom' ) );
	}

	/**
	 * Test order save method sets saved flag.
	 *
	 * @return void
	 */
	public function test_order_save_sets_flag(): void {
		$order = WooCommerceFactory::create_order();

		$this->assertFalse( $order->saved );

		$order->save();

		$this->assertTrue( $order->saved );
	}

	// =========================================================================
	// Refund Creation Tests
	// =========================================================================

	/**
	 * Test create_refund returns MockWCOrderRefund instance.
	 *
	 * @return void
	 */
	public function test_create_refund_returns_mock(): void {
		$refund = WooCommerceFactory::create_refund( 123 );

		$this->assertInstanceOf( MockWCOrderRefund::class, $refund );
		$this->assertSame( 123, $refund->get_parent_id() );
		$this->assertSame( 'refunded', $refund->get_status() );
	}

	// =========================================================================
	// Order Item Creation Tests
	// =========================================================================

	/**
	 * Test create_order_item returns MockWCOrderItemProduct instance.
	 *
	 * @return void
	 */
	public function test_create_order_item_returns_mock(): void {
		$item = WooCommerceFactory::create_order_item();

		$this->assertInstanceOf( MockWCOrderItemProduct::class, $item );
	}

	/**
	 * Test create_order_item uses defaults.
	 *
	 * @return void
	 */
	public function test_create_order_item_uses_defaults(): void {
		$item = WooCommerceFactory::create_order_item();

		$this->assertSame( 1, $item->get_product_id() );
		$this->assertSame( 1, $item->get_quantity() );
		$this->assertSame( '10.00', $item->get_total() );
	}

	/**
	 * Test create_order_item accepts custom attributes.
	 *
	 * @return void
	 */
	public function test_create_order_item_accepts_custom_attributes(): void {
		$item = WooCommerceFactory::create_order_item(
			array(
				'product_id' => 42,
				'quantity'   => 5,
				'total'      => '99.99',
			)
		);

		$this->assertSame( 42, $item->get_product_id() );
		$this->assertSame( 5, $item->get_quantity() );
		$this->assertSame( '99.99', $item->get_total() );
	}

	/**
	 * Test order item meta data.
	 *
	 * @return void
	 */
	public function test_order_item_meta_data(): void {
		$item = WooCommerceFactory::create_order_item(
			array(
				'meta_data' => array(
					'_nettertech_events_occurrence_id' => 456,
				),
			)
		);

		$this->assertSame( 456, $item->get_meta( '_nettertech_events_occurrence_id' ) );

		$item->add_meta_data( '_nettertech_events_ticket_id', 789 );
		$this->assertSame( 789, $item->get_meta( '_nettertech_events_ticket_id' ) );
	}

	/**
	 * Test order with items.
	 *
	 * @return void
	 */
	public function test_order_with_items(): void {
		$order = WooCommerceFactory::create_order(
			array(
				'items' => array(
					array( 'product_id' => 10, 'quantity' => 2 ),
					array( 'product_id' => 20, 'quantity' => 1 ),
				),
			)
		);

		$items = $order->get_items();

		$this->assertCount( 2, $items );
	}

	// =========================================================================
	// Product Creation Tests
	// =========================================================================

	/**
	 * Test create_product returns MockWCProduct instance.
	 *
	 * @return void
	 */
	public function test_create_product_returns_mock(): void {
		$product = WooCommerceFactory::create_product();

		$this->assertInstanceOf( MockWCProduct::class, $product );
	}

	/**
	 * Test create_product uses defaults.
	 *
	 * @return void
	 */
	public function test_create_product_uses_defaults(): void {
		$product = WooCommerceFactory::create_product();

		$this->assertSame( 'Test Product', $product->get_name() );
		$this->assertSame( '10.00', $product->get_price() );
		$this->assertSame( 'simple', $product->get_type() );
	}

	/**
	 * Test create_ticket_product creates event ticket.
	 *
	 * @return void
	 */
	public function test_create_ticket_product(): void {
		$product = WooCommerceFactory::create_ticket_product( 100, 200 );

		$this->assertSame( 'venue_ticket', $product->get_type() );
		$this->assertSame( 100, $product->get_meta( '_nettertech_events_ticket_type_id' ) );
		$this->assertSame( 200, $product->get_meta( '_nettertech_events_occurrence_id' ) );
		$this->assertTrue( $product->is_event_ticket() );
	}

	/**
	 * Test non-ticket product is_event_ticket returns false.
	 *
	 * @return void
	 */
	public function test_non_ticket_product_is_not_event_ticket(): void {
		$product = WooCommerceFactory::create_product();

		$this->assertFalse( $product->is_event_ticket() );
	}

	// =========================================================================
	// Cart Creation Tests
	// =========================================================================

	/**
	 * Test create_cart returns MockWCCart instance.
	 *
	 * @return void
	 */
	public function test_create_cart_returns_mock(): void {
		$cart = WooCommerceFactory::create_cart();

		$this->assertInstanceOf( MockWCCart::class, $cart );
	}

	/**
	 * Test create_cart_item creates cart item array.
	 *
	 * @return void
	 */
	public function test_create_cart_item(): void {
		$product   = WooCommerceFactory::create_product( array( 'price' => '25.00' ) );
		$cart_item = WooCommerceFactory::create_cart_item( $product, 3 );

		$this->assertArrayHasKey( 'key', $cart_item );
		$this->assertSame( $product->get_id(), $cart_item['product_id'] );
		$this->assertSame( 3, $cart_item['quantity'] );
		$this->assertEquals( 75.0, $cart_item['line_total'] );
		$this->assertSame( $product, $cart_item['data'] );
	}

	/**
	 * Test cart operations.
	 *
	 * @return void
	 */
	public function test_cart_operations(): void {
		$cart    = WooCommerceFactory::create_cart();
		$product = WooCommerceFactory::create_product();

		$key = $cart->add_to_cart( $product->get_id(), 2 );

		$this->assertNotEmpty( $key );
		$this->assertCount( 1, $cart->get_cart() );

		$item = $cart->get_cart_item( $key );
		$this->assertSame( 2, $item['quantity'] );

		$cart->remove_cart_item( $key );
		$this->assertEmpty( $cart->get_cart() );
		$this->assertCount( 1, $cart->removed_cart_contents );
	}

	// =========================================================================
	// WC Singleton Tests
	// =========================================================================

	/**
	 * Test create_wc_singleton returns mock.
	 *
	 * @return void
	 */
	public function test_create_wc_singleton(): void {
		$wc = WooCommerceFactory::create_wc_singleton();

		$this->assertInstanceOf( MockWCSingleton::class, $wc );
		$this->assertInstanceOf( MockWCCart::class, $wc->cart );
		$this->assertNotNull( $wc->session );
	}

	/**
	 * Test create_wc_singleton with custom cart.
	 *
	 * @return void
	 */
	public function test_create_wc_singleton_with_custom_cart(): void {
		$cart = WooCommerceFactory::create_cart();
		$cart->add_to_cart( 1, 5 );

		$wc = WooCommerceFactory::create_wc_singleton( $cart );

		$this->assertSame( $cart, $wc->cart );
		$this->assertCount( 1, $wc->cart->get_cart() );
	}

	// =========================================================================
	// Reset Tests
	// =========================================================================

	/**
	 * Test reset resets counter.
	 *
	 * @return void
	 */
	public function test_reset_resets_counter(): void {
		$order1 = WooCommerceFactory::create_order();
		$order2 = WooCommerceFactory::create_order();

		WooCommerceFactory::reset();

		$order3 = WooCommerceFactory::create_order();

		$this->assertSame( 1, $order3->get_id() );
	}
}
