<?php
/**
 * CartHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey\Functions;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Integrations\WooCommerce\CartHandler;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\CapacityService;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;

/**
 * Test CartHandler functionality.
 *
 * Note: Many CartHandler methods require full WooCommerce environment
 * (WC() singleton, WC()->session, WC()->cart) and are better tested
 * via integration tests. This file focuses on methods that can be
 * unit tested with simple mocks.
 */
class CartHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * CartHandler instance.
	 *
	 * @var CartHandler
	 */
	private CartHandler $handler;

	/**
	 * Mock ProductManager.
	 *
	 * @var ProductManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $product_manager;

	/**
	 * Mock TicketTypeRepository.
	 *
	 * @var TicketTypeRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * Mock CapacityService.
	 *
	 * @var CapacityService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $capacity_service;

	/**
	 * Mock OccurrenceRepository.
	 *
	 * @var OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->product_manager  = $this->createMock( ProductManager::class );
		$this->ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$this->capacity_service = $this->createMock( CapacityService::class );
		$this->occurrence_repo  = $this->createMock( OccurrenceRepositoryInterface::class );

		$this->handler = new CartHandler(
			$this->ticket_type_repo,
			$this->capacity_service,
			$this->occurrence_repo,
			$this->product_manager
		);

		OccurrenceFactory::reset();
		TicketTypeFactory::reset();
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test CartHandler can be instantiated with all dependencies.
	 *
	 * @return void
	 */
	public function test_handler_can_be_instantiated(): void {
		$handler = new CartHandler(
			$this->ticket_type_repo,
			$this->capacity_service,
			$this->occurrence_repo,
			$this->product_manager
		);

		$this->assertInstanceOf( CartHandler::class, $handler );
	}

	// =========================================================================
	// validate_add_to_cart Tests - Early Returns
	// =========================================================================

	/**
	 * Test validate_add_to_cart passes through for non-ticket products.
	 *
	 * @return void
	 */
	public function test_validate_add_to_cart_passes_for_non_ticket(): void {
		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( false );

		$result = $this->handler->validate_add_to_cart( true, 100, 2 );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_add_to_cart respects existing failure.
	 *
	 * @return void
	 */
	public function test_validate_add_to_cart_respects_existing_failure(): void {
		$result = $this->handler->validate_add_to_cart( false, 100, 2 );

		$this->assertFalse( $result );
	}

	/**
	 * Test validate_add_to_cart fails when ticket type not found.
	 *
	 * @return void
	 */
	public function test_validate_add_to_cart_fails_missing_ticket_type(): void {
		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( null );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( null );

		$result = $this->handler->validate_add_to_cart( true, 100, 1 );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// modify_cart_item_name Tests
	// =========================================================================

	/**
	 * Test modify_cart_item_name returns original when no product data.
	 *
	 * @return void
	 */
	public function test_modify_cart_item_name_unchanged_without_product(): void {
		$cart_item = array();

		$result = $this->handler->modify_cart_item_name( 'Original Name', $cart_item, 'key123' );

		$this->assertSame( 'Original Name', $result );
	}

	// =========================================================================
	// display_cart_item_data Tests
	// =========================================================================

	/**
	 * Test display_cart_item_data returns original when no product data.
	 *
	 * @return void
	 */
	public function test_display_cart_item_data_unchanged_without_product(): void {
		$cart_item = array();
		$item_data = array( 'existing' => 'data' );

		$result = $this->handler->display_cart_item_data( $item_data, $cart_item );

		$this->assertSame( $item_data, $result );
	}

	// =========================================================================
	// handle_cart_emptied Tests
	// =========================================================================

	/**
	 * Test handle_cart_emptied does not error.
	 *
	 * @return void
	 */
	public function test_handle_cart_emptied_completes_without_error(): void {
		$this->handler->handle_cart_emptied();

		$this->assertTrue( true );
	}

	// =========================================================================
	// validate_ticket_purchase Tests (accepts objects directly)
	// =========================================================================

	/**
	 * Test validate_ticket_purchase returns valid for good ticket.
	 *
	 * @return void
	 */
	public function test_validate_ticket_purchase_valid(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->capacity_service
			->method( 'has_availability' )
			->willReturn( true );

		$result = $this->handler->validate_ticket_purchase( $ticket_type, $occurrence, 2 );

		$this->assertTrue( $result['valid'] );
		$this->assertNull( $result['error'] );
	}

	// =========================================================================
	// Re-checking what is already in the cart (NTE-151)
	// =========================================================================

	/**
	 * A cart holding one ticket type, for the re-check to examine.
	 *
	 * @param int $product_id Product in the cart.
	 * @param int $quantity   How many.
	 * @return \WC_Cart
	 */
	private function cart_holding( int $product_id, int $quantity ): \WC_Cart {
		$cart                 = new \WC_Cart();
		$cart->cart_contents  = array(
			'abc123' => array(
				'product_id' => $product_id,
				'quantity'   => $quantity,
			),
		);

		return $cart;
	}

	/**
	 * Point the product manager at a ticket type and its occurrence.
	 *
	 * @param \NetterTechEvents\Models\TicketType $ticket_type The tier.
	 * @param \NetterTechEvents\Models\Occurrence $occurrence  Its occurrence.
	 * @return void
	 */
	private function product_resolves_to( $ticket_type, $occurrence ): void {
		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );

		Functions\when( 'WC' )->justReturn( null );
	}

	/**
	 * Test a ticket whose sale window closed while it sat in the cart cannot be checked out.
	 *
	 * The hole this closes. A ticket was validated on its way into the cart and never again, and
	 * WooCommerce keeps a signed-in shopper's cart for days — so an early-bird ticket added before
	 * the cutoff went on being purchasable *after* it, at the early-bird price. Reproduced against
	 * a live site before the fix: order 7914, charged five minutes past `sale_end`, no error.
	 *
	 * Aimed at the DATE-driven case on purpose. A tier that ran out of *quantity* is caught anyway
	 * by WooCommerce's own stock gate, because the remaining allotment reaches `_stock`. A tier
	 * closed by the clock still has seats, so that gate waves it through and only this stops it.
	 *
	 * @return void
	 */
	public function test_a_ticket_whose_sale_window_closed_in_the_cart_cannot_be_bought(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'name'          => 'Early Bird',
				'wc_product_id' => 555,
				'min_per_order' => 1,
				'max_per_order' => 10,
				// Open when it went in the cart; shut five minutes ago.
				'sale_end'      => ( new \DateTimeImmutable( '-5 minutes' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		// The event itself has not happened yet — only the sale window has closed.
		$occurrence = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 week +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->product_resolves_to( $ticket_type, $occurrence );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 2 );
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_holding( 555, 2 ) );

		$this->assertTrue(
			$errors->has_errors(),
			'A ticket whose sale window closed while it sat in the cart must not check out.'
		);
		$this->assertStringContainsString( 'Early Bird', (string) $errors->get_error_message() );
	}

	/**
	 * Test a ticket still on sale is left alone.
	 *
	 * The refusal above is worthless if it also refuses the shopper who did nothing wrong.
	 *
	 * @return void
	 */
	public function test_a_ticket_still_on_sale_passes_the_re_check(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'wc_product_id' => 555,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 week +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->product_resolves_to( $ticket_type, $occurrence );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 2 );
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_holding( 555, 2 ) );

		$this->assertFalse( $errors->has_errors(), 'A ticket still on sale must check out unimpeded.' );
	}

	/**
	 * Test the shopper is not refused on account of the seats they are themselves holding.
	 *
	 * The capacity check subtracts the shopper's own pending reservation. Without that, re-checking
	 * a cart would count its contents against the room a second time and refuse the last seats to
	 * the very person holding them.
	 *
	 * @return void
	 */
	public function test_the_shopper_is_not_refused_for_their_own_reservation(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'wc_product_id' => 555,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 week +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->product_resolves_to( $ticket_type, $occurrence );

		// The room is full — of this shopper's own two seats, which they hold a reservation for.
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 2 );
		$this->capacity_service->method( 'has_availability' )->willReturn( false );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_holding( 555, 2 ) );

		$this->assertFalse(
			$errors->has_errors(),
			'The seats a shopper is holding must not be counted against them a second time.'
		);
	}

	/**
	 * Test validate_ticket_purchase fails for ended event.
	 *
	 * @return void
	 */
	public function test_validate_ticket_purchase_fails_event_ended(): void {
		$ticket_type = TicketTypeFactory::create();
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '-2 days' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$result = $this->handler->validate_ticket_purchase( $ticket_type, $occurrence, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'event_ended', $result['error_code'] );
	}

	/**
	 * Test validate_ticket_purchase fails when not on sale.
	 *
	 * Note: This test checks that validation fails BEFORE reaching capacity check.
	 * The sale window is in the future, so is_on_sale() returns false.
	 *
	 * @return void
	 */
	public function test_validate_ticket_purchase_fails_not_on_sale(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+2 weeks' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+3 weeks' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+3 weeks +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		// Capacity check should NOT be reached since validation fails earlier.
		$this->capacity_service
			->expects( $this->never() )
			->method( 'has_availability' );

		$result = $this->handler->validate_ticket_purchase( $ticket_type, $occurrence, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'not_on_sale', $result['error_code'] );
	}

	/**
	 * Test validate_ticket_purchase fails below minimum.
	 *
	 * @return void
	 */
	public function test_validate_ticket_purchase_fails_below_minimum(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'min_per_order' => 5,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$result = $this->handler->validate_ticket_purchase( $ticket_type, $occurrence, 2 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'below_minimum', $result['error_code'] );
	}

	/**
	 * Test validate_ticket_purchase fails above maximum.
	 *
	 * @return void
	 */
	public function test_validate_ticket_purchase_fails_above_maximum(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'min_per_order' => 1,
				'max_per_order' => 4,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$result = $this->handler->validate_ticket_purchase( $ticket_type, $occurrence, 10 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'above_maximum', $result['error_code'] );
	}

	/**
	 * Test validate_ticket_purchase fails when sold out.
	 *
	 * @return void
	 */
	public function test_validate_ticket_purchase_fails_sold_out(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->capacity_service
			->method( 'has_availability' )
			->willReturn( false );

		$this->capacity_service
			->method( 'get_capacity_summary' )
			->willReturn(
				array(
					'is_sold_out'         => true,
					'effective_available' => 0,
				)
			);

		$result = $this->handler->validate_ticket_purchase( $ticket_type, $occurrence, 2 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'sold_out', $result['error_code'] );
	}

	/**
	 * Test validate_ticket_purchase fails with insufficient capacity.
	 *
	 * @return void
	 */
	public function test_validate_ticket_purchase_fails_insufficient_capacity(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->capacity_service
			->method( 'has_availability' )
			->willReturn( false );

		$this->capacity_service
			->method( 'get_capacity_summary' )
			->willReturn(
				array(
					'is_sold_out'         => false,
					'effective_available' => 3,
				)
			);

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( 3 );

		$result = $this->handler->validate_ticket_purchase( $ticket_type, $occurrence, 5 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'insufficient_capacity', $result['error_code'] );
		$this->assertStringContainsString( '3', $result['error'] );
	}

	/**
	 * Test validate_ticket_purchase considers cart quantity.
	 *
	 * @return void
	 */
	public function test_validate_ticket_purchase_considers_cart_quantity(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'min_per_order' => 1,
				'max_per_order' => 5,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		// Adding 3 tickets with 3 already in cart = 6 total, exceeds max of 5.
		$result = $this->handler->validate_ticket_purchase( $ticket_type, $occurrence, 3, 3 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'above_maximum', $result['error_code'] );
	}

	// =========================================================================
	// get_cart_quantity_for_ticket_type_from_cart Tests
	// =========================================================================

	/**
	 * Test get_cart_quantity_for_ticket_type_from_cart returns quantity.
	 *
	 * @return void
	 */
	public function test_get_cart_quantity_for_ticket_type_from_cart(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'wc_product_id' => 100,
			)
		);

		$cart = $this->createMock( \WC_Cart::class );
		$cart->method( 'get_cart' )->willReturn(
			array(
				'item1' => array( 'product_id' => 100, 'quantity' => 3 ),
				'item2' => array( 'product_id' => 200, 'quantity' => 2 ),
				'item3' => array( 'product_id' => 100, 'quantity' => 1 ),
			)
		);

		$this->ticket_type_repo
			->method( 'find' )
			->with( 1 )
			->willReturn( $ticket_type );

		$result = $this->handler->get_cart_quantity_for_ticket_type_from_cart( $cart, 1 );

		// Should sum quantities for product_id 100: 3 + 1 = 4.
		$this->assertSame( 4, $result );
	}

	/**
	 * Test get_cart_quantity_for_ticket_type_from_cart returns zero when not found.
	 *
	 * @return void
	 */
	public function test_get_cart_quantity_for_ticket_type_from_cart_not_found(): void {
		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( null );

		$cart = $this->createMock( \WC_Cart::class );

		$result = $this->handler->get_cart_quantity_for_ticket_type_from_cart( $cart, 999 );

		$this->assertSame( 0, $result );
	}

	// =========================================================================
	// get_cart_quantity_for_occurrence_from_cart Tests
	// =========================================================================

	/**
	 * Test get_cart_quantity_for_occurrence_from_cart returns quantity.
	 *
	 * @return void
	 */
	public function test_get_cart_quantity_for_occurrence_from_cart(): void {
		$occurrence = OccurrenceFactory::create( array( 'id' => 50 ) );

		$product1 = $this->createMock( \WC_Product::class );
		$product2 = $this->createMock( \WC_Product::class );

		$cart = $this->createMock( \WC_Cart::class );
		$cart->method( 'get_cart' )->willReturn(
			array(
				'item1' => array( 'data' => $product1, 'quantity' => 2 ),
				'item2' => array( 'data' => $product2, 'quantity' => 3 ),
			)
		);

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->product_manager
			->method( 'get_occurrence_from_product' )
			->willReturnOnConsecutiveCalls( $occurrence, OccurrenceFactory::create( array( 'id' => 99 ) ) );

		$result = $this->handler->get_cart_quantity_for_occurrence_from_cart( $cart, 50 );

		$this->assertSame( 2, $result );
	}

	// =========================================================================
	// cart_has_tickets_in_cart Tests
	// =========================================================================

	/**
	 * Test cart_has_tickets_in_cart returns true when tickets present.
	 *
	 * @return void
	 */
	public function test_cart_has_tickets_in_cart_true(): void {
		$product = $this->createMock( \WC_Product::class );

		$cart = $this->createMock( \WC_Cart::class );
		$cart->method( 'get_cart' )->willReturn(
			array(
				'item1' => array( 'data' => $product, 'quantity' => 1 ),
			)
		);

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$result = $this->handler->cart_has_tickets_in_cart( $cart );

		$this->assertTrue( $result );
	}

	/**
	 * Test cart_has_tickets_in_cart returns false when no tickets.
	 *
	 * @return void
	 */
	public function test_cart_has_tickets_in_cart_false(): void {
		$product = $this->createMock( \WC_Product::class );

		$cart = $this->createMock( \WC_Cart::class );
		$cart->method( 'get_cart' )->willReturn(
			array(
				'item1' => array( 'data' => $product, 'quantity' => 1 ),
			)
		);

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( false );

		$result = $this->handler->cart_has_tickets_in_cart( $cart );

		$this->assertFalse( $result );
	}

	/**
	 * Test cart_has_tickets_in_cart returns false for empty cart.
	 *
	 * @return void
	 */
	public function test_cart_has_tickets_in_cart_empty(): void {
		$cart = $this->createMock( \WC_Cart::class );
		$cart->method( 'get_cart' )->willReturn( array() );

		$result = $this->handler->cart_has_tickets_in_cart( $cart );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// handle_cart_item_removed Tests
	// =========================================================================

	/**
	 * Test handle_cart_item_removed returns early without removed item.
	 *
	 * @return void
	 */
	public function test_handle_cart_item_removed_returns_without_item(): void {
		$cart                         = $this->createMock( \WC_Cart::class );
		$cart->removed_cart_contents = array();

		$this->capacity_service->expects( $this->never() )
			->method( 'clear_pending_reservation' );

		$this->handler->handle_cart_item_removed( 'item_key', $cart );
	}

	/**
	 * Test handle_cart_item_removed returns when item is not a ticket.
	 *
	 * @return void
	 */
	public function test_handle_cart_item_removed_returns_for_non_ticket(): void {
		$product = $this->createMock( \WC_Product::class );

		$cart                         = $this->createMock( \WC_Cart::class );
		$cart->removed_cart_contents = array(
			'item_key' => array( 'data' => $product ),
		);

		$this->product_manager->method( 'is_event_ticket' )->willReturn( false );
		$this->capacity_service->expects( $this->never() )
			->method( 'clear_pending_reservation' );

		$this->handler->handle_cart_item_removed( 'item_key', $cart );
	}

	// =========================================================================
	// handle_cart_quantity_update Tests
	// =========================================================================

	// Note: handle_cart_quantity_update requires WC() singleton and is better
	// tested via integration tests.

	// =========================================================================
	// handle_cart_emptied Tests (additional)
	// =========================================================================

	/**
	 * Test handle_cart_emptied clears reservations.
	 *
	 * @return void
	 */
	public function test_handle_cart_emptied_completes(): void {
		// Method should complete without error even when WC() isn't available.
		$this->handler->handle_cart_emptied();

		$this->assertTrue( true );
	}

	// =========================================================================
	// modify_cart_item_name Tests (additional)
	// =========================================================================

	/**
	 * Test modify_cart_item_name returns original without product.
	 *
	 * @return void
	 */
	public function test_modify_cart_item_name_without_product(): void {
		$cart_item = array();

		$result = $this->handler->modify_cart_item_name( 'Test Product', $cart_item, 'item_key' );

		$this->assertEquals( 'Test Product', $result );
	}

	/**
	 * Test modify_cart_item_name returns original for non-ticket.
	 *
	 * @return void
	 */
	public function test_modify_cart_item_name_for_non_ticket(): void {
		$product   = $this->createMock( \WC_Product::class );
		$cart_item = array( 'data' => $product );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( false );

		$result = $this->handler->modify_cart_item_name( 'Test Product', $cart_item, 'item_key' );

		$this->assertEquals( 'Test Product', $result );
	}

	// =========================================================================
	// display_cart_item_data Tests (additional)
	// =========================================================================

	/**
	 * Test display_cart_item_data returns original without product.
	 *
	 * @return void
	 */
	public function test_display_cart_item_data_without_product(): void {
		$cart_item = array();
		$item_data = array( array( 'key' => 'Existing', 'value' => 'Data' ) );

		$result = $this->handler->display_cart_item_data( $item_data, $cart_item );

		$this->assertEquals( $item_data, $result );
	}

	/**
	 * Test display_cart_item_data returns original for non-ticket.
	 *
	 * @return void
	 */
	public function test_display_cart_item_data_for_non_ticket(): void {
		$product   = $this->createMock( \WC_Product::class );
		$cart_item = array( 'data' => $product );
		$item_data = array();

		$this->product_manager->method( 'is_event_ticket' )->willReturn( false );

		$result = $this->handler->display_cart_item_data( $item_data, $cart_item );

		$this->assertEquals( array(), $result );
	}

	/**
	 * Test display_cart_item_data adds event details.
	 *
	 * @return void
	 */
	public function test_display_cart_item_data_adds_event_details(): void {
		$product   = $this->createMock( \WC_Product::class );
		$cart_item = array( 'data' => $product );

		// Create mocked occurrence with necessary methods.
		$occurrence = \Mockery::mock( \NetterTechEvents\Models\Occurrence::class );
		$occurrence->shouldReceive( 'get_formatted_date' )->andReturn( 'January 1, 2026' );
		$occurrence->shouldReceive( 'get_formatted_time' )->andReturn( '7:00 PM' );
		$occurrence->shouldReceive( 'get_event' )->andReturn( null );

		$ticket_type = TicketTypeFactory::create( array( 'name' => 'General Admission' ) );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );

		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$result = $this->handler->display_cart_item_data( array(), $cart_item );

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 3, count( $result ) );
	}

	// =========================================================================
	// add_ticket_to_cart Tests
	// =========================================================================

	/**
	 * Test add_ticket_to_cart returns false when ticket type not found.
	 *
	 * @return void
	 */
	public function test_add_ticket_to_cart_returns_false_for_invalid_type(): void {
		$this->ticket_type_repo->method( 'find' )->willReturn( null );

		$result = $this->handler->add_ticket_to_cart( 999 );

		$this->assertFalse( $result );
	}

	/**
	 * Test add_ticket_to_cart returns false when no product ID.
	 *
	 * @return void
	 */
	public function test_add_ticket_to_cart_returns_false_without_product_id(): void {
		$ticket_type = TicketTypeFactory::create( array( 'wc_product_id' => null ) );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		$result = $this->handler->add_ticket_to_cart( $ticket_type->id );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// get_cart_quantity_for_ticket_type Tests
	// =========================================================================

	// Note: get_cart_quantity_for_ticket_type requires WC() singleton.
	// Use get_cart_quantity_for_ticket_type_from_cart for unit testing.

	// =========================================================================
	// get_cart_quantity_for_occurrence Tests
	// =========================================================================

	// Note: get_cart_quantity_for_occurrence requires WC() singleton.
	// Use get_cart_quantity_for_occurrence_from_cart for unit testing.

	// =========================================================================
	// cart_has_tickets Tests
	// =========================================================================

	// Note: cart_has_tickets requires WC() singleton.
	// Use cart_has_tickets_in_cart for unit testing.

	// =========================================================================
	// add_order_item_meta Tests
	// =========================================================================

	/**
	 * Test add_order_item_meta returns early for non-ticket product.
	 *
	 * @return void
	 */
	public function test_add_order_item_meta_returns_for_non_ticket(): void {
		$product = $this->createMock( \WC_Product::class );
		$item    = $this->createMock( \WC_Order_Item_Product::class );
		$order   = $this->createMock( \WC_Order::class );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( false );

		// add_meta_data should never be called.
		$item->expects( $this->never() )->method( 'add_meta_data' );

		$this->handler->add_order_item_meta(
			$item,
			'cart_key_123',
			array( 'data' => $product ),
			$order
		);
	}

	/**
	 * Test add_order_item_meta returns early without product data.
	 *
	 * @return void
	 */
	public function test_add_order_item_meta_returns_without_product(): void {
		$item  = $this->createMock( \WC_Order_Item_Product::class );
		$order = $this->createMock( \WC_Order::class );

		// add_meta_data should never be called.
		$item->expects( $this->never() )->method( 'add_meta_data' );

		$this->handler->add_order_item_meta(
			$item,
			'cart_key_123',
			array(), // No 'data' key.
			$order
		);
	}

	/**
	 * Test add_order_item_meta adds occurrence and ticket type meta.
	 *
	 * @return void
	 */
	public function test_add_order_item_meta_adds_occurrence_meta(): void {
		$product = $this->createMock( \WC_Product::class );
		$item    = $this->createMock( \WC_Order_Item_Product::class );
		$order   = $this->createMock( \WC_Order::class );

		// Create mocked occurrence with necessary methods.
		$occurrence = \Mockery::mock( \NetterTechEvents\Models\Occurrence::class );
		$occurrence->id = 50;
		$occurrence->event_id = 7;
		$occurrence->shouldReceive( 'get_formatted_date' )->andReturn( 'January 15, 2026' );
		$occurrence->shouldReceive( 'get_formatted_time' )->andReturn( '7:30 PM' );
		$occurrence->shouldReceive( 'get_event' )->andReturn( null );

		$ticket_type     = TicketTypeFactory::create( array( 'id' => 10, 'name' => 'VIP Pass' ) );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );

		Functions\when( '__' )->returnArg();

		// Expect meta: occurrence_id, date, time, event_id, ticket_type_id, ticket_type name = 6.
		$item->expects( $this->exactly( 6 ) )
			->method( 'add_meta_data' );

		$this->handler->add_order_item_meta(
			$item,
			'cart_key_123',
			array( 'data' => $product ),
			$order
		);
	}

	/**
	 * Test add_order_item_meta adds venue meta when event has venue.
	 *
	 * @return void
	 */
	public function test_add_order_item_meta_adds_venue_meta(): void {
		$product = $this->createMock( \WC_Product::class );
		$item    = $this->createMock( \WC_Order_Item_Product::class );
		$order   = $this->createMock( \WC_Order::class );

		// Create mocked event with venue_name.
		$event             = \Mockery::mock( \NetterTechEvents\Models\Event::class );
		$event->venue_name = 'The Grand Hall';

		// Create mocked occurrence with event.
		$occurrence = \Mockery::mock( \NetterTechEvents\Models\Occurrence::class );
		$occurrence->id = 50;
		$occurrence->event_id = 7;
		$occurrence->shouldReceive( 'get_formatted_date' )->andReturn( 'January 15, 2026' );
		$occurrence->shouldReceive( 'get_formatted_time' )->andReturn( '7:30 PM' );
		$occurrence->shouldReceive( 'get_event' )->andReturn( $event );

		$ticket_type = TicketTypeFactory::create( array( 'id' => 10, 'name' => 'General Admission' ) );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );

		Functions\when( '__' )->returnArg();

		// Expect 7 meta entries: occurrence_id, date, time, venue, event_id, ticket_type_id, ticket_type name.
		$item->expects( $this->exactly( 7 ) )
			->method( 'add_meta_data' );

		$this->handler->add_order_item_meta(
			$item,
			'cart_key_123',
			array( 'data' => $product ),
			$order
		);
	}

	// =========================================================================
	// modify_cart_item_name Tests (event link path)
	// =========================================================================

	/**
	 * Test modify_cart_item_name returns event link when occurrence has event.
	 *
	 * @return void
	 */
	public function test_modify_cart_item_name_returns_event_link(): void {
		$product = $this->createMock( \WC_Product::class );

		// Create mocked event with get_permalink().
		$event = \Mockery::mock( \NetterTechEvents\Models\Event::class );
		$event->shouldReceive( 'get_permalink' )->andReturn( 'https://example.com/event/123' );

		// Create mocked occurrence with event.
		$occurrence = \Mockery::mock( \NetterTechEvents\Models\Occurrence::class );
		$occurrence->shouldReceive( 'get_event' )->andReturn( $event );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );

		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$cart_item = array( 'data' => $product );

		// WooCommerce passes the name already wrapped in an <a> tag.
		$wc_name = '<a href="https://example.com/product/123">Test Event Ticket</a>';
		$result  = $this->handler->modify_cart_item_name( $wc_name, $cart_item, 'item_key' );

		$this->assertStringContainsString( '<a href="https://example.com/event/123">', $result );
		$this->assertStringContainsString( 'Test Event Ticket', $result );
		// Ensure the original WC link is stripped (no nested <a> tags).
		$this->assertStringNotContainsString( '&lt;a', $result );
	}

	/**
	 * Test modify_cart_item_name returns original when occurrence has no event.
	 *
	 * @return void
	 */
	public function test_modify_cart_item_name_returns_original_without_event(): void {
		$product = $this->createMock( \WC_Product::class );

		// Create mocked occurrence without event.
		$occurrence = \Mockery::mock( \NetterTechEvents\Models\Occurrence::class );
		$occurrence->shouldReceive( 'get_event' )->andReturn( null );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );

		$cart_item = array( 'data' => $product );

		$result = $this->handler->modify_cart_item_name( 'Test Event Ticket', $cart_item, 'item_key' );

		$this->assertEquals( 'Test Event Ticket', $result );
	}

	// =========================================================================
	// handle_cart_item_removed Tests (ticket removal path)
	// =========================================================================

	/**
	 * Test handle_cart_item_removed clears reservation when no tickets remain.
	 *
	 * @return void
	 */
	public function test_handle_cart_item_removed_clears_reservation(): void {
		$product     = $this->createMock( \WC_Product::class );
		$ticket_type = TicketTypeFactory::create( array( 'id' => 10, 'wc_product_id' => 100 ) );

		// Create a cart mock for the WC() singleton.
		$wc_cart = $this->createMock( \WC_Cart::class );
		$wc_cart->method( 'get_cart' )->willReturn( array() ); // Empty cart after removal.

		// Mock the WC() function to return an object with cart property.
		$wc_mock       = new \stdClass();
		$wc_mock->cart = $wc_cart;
		Functions\when( 'WC' )->justReturn( $wc_mock );

		$cart                         = $this->createMock( \WC_Cart::class );
		$cart->removed_cart_contents = array(
			'item_key' => array( 'data' => $product ),
		);

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		// Expect clear_pending_reservation to be called.
		$this->capacity_service->expects( $this->once() )
			->method( 'clear_pending_reservation' )
			->with( 10, $this->stringContains( 'nettertech_events_cart_' ) );

		$this->handler->handle_cart_item_removed( 'item_key', $cart );
	}

	/**
	 * Test handle_cart_item_removed updates reservation when tickets remain.
	 *
	 * @return void
	 */
	public function test_handle_cart_item_removed_updates_reservation(): void {
		$product     = $this->createMock( \WC_Product::class );
		$ticket_type = TicketTypeFactory::create( array( 'id' => 10, 'wc_product_id' => 100 ) );

		// Create a cart mock for the WC() singleton with remaining tickets.
		$wc_cart = $this->createMock( \WC_Cart::class );
		$wc_cart->method( 'get_cart' )->willReturn(
			array(
				'other_item' => array( 'product_id' => 100, 'quantity' => 2 ),
			)
		);

		// Mock the WC() function to return an object with cart property.
		$wc_mock       = new \stdClass();
		$wc_mock->cart = $wc_cart;
		Functions\when( 'WC' )->justReturn( $wc_mock );

		$cart                         = $this->createMock( \WC_Cart::class );
		$cart->removed_cart_contents = array(
			'item_key' => array( 'data' => $product ),
		);

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		// Expect create_pending_reservation with remaining quantity.
		$this->capacity_service->expects( $this->once() )
			->method( 'create_pending_reservation' )
			->with( 10, 2, $this->stringContains( 'nettertech_events_cart_' ) );

		$this->handler->handle_cart_item_removed( 'item_key', $cart );
	}

	/**
	 * Test handle_cart_item_removed returns when ticket type not found.
	 *
	 * @return void
	 */
	public function test_handle_cart_item_removed_returns_without_ticket_type(): void {
		$product = $this->createMock( \WC_Product::class );

		$cart                         = $this->createMock( \WC_Cart::class );
		$cart->removed_cart_contents = array(
			'item_key' => array( 'data' => $product ),
		);

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( null );

		// Neither method should be called.
		$this->capacity_service->expects( $this->never() )
			->method( 'clear_pending_reservation' );
		$this->capacity_service->expects( $this->never() )
			->method( 'create_pending_reservation' );

		$this->handler->handle_cart_item_removed( 'item_key', $cart );
	}

	// =========================================================================
	// display_cart_item_data Tests (venue path)
	// =========================================================================

	/**
	 * Test display_cart_item_data adds venue when event has venue_name.
	 *
	 * @return void
	 */
	public function test_display_cart_item_data_adds_venue(): void {
		$product = $this->createMock( \WC_Product::class );

		// Create mocked event with venue_name.
		$event             = \Mockery::mock( \NetterTechEvents\Models\Event::class );
		$event->venue_name = 'The Celtic Center';

		// Create mocked occurrence with event.
		$occurrence = \Mockery::mock( \NetterTechEvents\Models\Occurrence::class );
		$occurrence->shouldReceive( 'get_formatted_date' )->andReturn( 'March 17, 2026' );
		$occurrence->shouldReceive( 'get_formatted_time' )->andReturn( '6:00 PM' );
		$occurrence->shouldReceive( 'get_event' )->andReturn( $event );

		$ticket_type = TicketTypeFactory::create( array( 'name' => 'Standard' ) );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );

		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$cart_item = array( 'data' => $product );
		$result    = $this->handler->display_cart_item_data( array(), $cart_item );

		// Should have 4 entries: Date, Time, Venue, Ticket Type.
		$this->assertCount( 4, $result );

		// Find the Venue entry.
		$venue_found = false;
		foreach ( $result as $entry ) {
			if ( $entry['key'] === 'Venue' ) {
				$this->assertEquals( 'The Celtic Center', $entry['value'] );
				$venue_found = true;
			}
		}
		$this->assertTrue( $venue_found, 'Venue entry should be present' );
	}

	// =========================================================================
	// validate_add_to_cart Full Path Tests
	// =========================================================================

	/**
	 * Test validate_add_to_cart fails when event has ended.
	 *
	 * @return void
	 */
	public function test_validate_add_to_cart_fails_event_ended(): void {
		$ticket_type = TicketTypeFactory::create( array( 'id' => 1, 'wc_product_id' => 100 ) );
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '-2 days' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart( array() );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );

		// Reservation must NOT be created when validation fails.
		$this->capacity_service->expects( $this->never() )
			->method( 'create_pending_reservation' );

		$result = $this->handler->validate_add_to_cart( true, 100, 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test validate_add_to_cart fails when not on sale.
	 *
	 * @return void
	 */
	public function test_validate_add_to_cart_fails_not_on_sale(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'wc_product_id' => 100,
				'sale_start'    => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+2 weeks' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+3 weeks' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+3 weeks +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart( array() );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );

		// Reservation must NOT be created when validation fails.
		$this->capacity_service->expects( $this->never() )
			->method( 'create_pending_reservation' );

		$result = $this->handler->validate_add_to_cart( true, 100, 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test validate_add_to_cart fails below minimum per order.
	 *
	 * @return void
	 */
	public function test_validate_add_to_cart_fails_below_minimum(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'wc_product_id' => 100,
				'min_per_order' => 5,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart( array() );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );

		// Reservation must NOT be created when validation fails.
		$this->capacity_service->expects( $this->never() )
			->method( 'create_pending_reservation' );

		$result = $this->handler->validate_add_to_cart( true, 100, 2 ); // 2 < min 5

		$this->assertFalse( $result );
	}

	/**
	 * Test validate_add_to_cart fails above maximum per order.
	 *
	 * @return void
	 */
	public function test_validate_add_to_cart_fails_above_maximum(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'wc_product_id' => 100,
				'min_per_order' => 1,
				'max_per_order' => 4,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart( array() );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );

		// Reservation must NOT be created when validation fails.
		$this->capacity_service->expects( $this->never() )
			->method( 'create_pending_reservation' );

		$result = $this->handler->validate_add_to_cart( true, 100, 10 ); // 10 > max 4

		$this->assertFalse( $result );
	}

	/**
	 * Test validate_add_to_cart fails when sold out.
	 *
	 * @return void
	 */
	public function test_validate_add_to_cart_fails_sold_out(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'wc_product_id' => 100,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart( array() );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );

		$this->capacity_service->method( 'has_availability' )->willReturn( false );
		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'is_sold_out'         => true,
				'effective_available' => 0,
			)
		);

		// Reservation must NOT be created when validation fails.
		$this->capacity_service->expects( $this->never() )
			->method( 'create_pending_reservation' );

		$result = $this->handler->validate_add_to_cart( true, 100, 2 );

		$this->assertFalse( $result );
	}

	/**
	 * Test validate_add_to_cart fails with insufficient capacity.
	 *
	 * @return void
	 */
	public function test_validate_add_to_cart_fails_insufficient_capacity(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'wc_product_id' => 100,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart( array() );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );

		$this->capacity_service->method( 'has_availability' )->willReturn( false );
		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'is_sold_out'         => false,
				'effective_available' => 3,
			)
		);

		// Reservation must NOT be created when validation fails.
		$this->capacity_service->expects( $this->never() )
			->method( 'create_pending_reservation' );

		$result = $this->handler->validate_add_to_cart( true, 100, 5 );

		$this->assertFalse( $result );
	}

	/**
	 * Test validate_add_to_cart succeeds and creates reservation.
	 *
	 * @return void
	 */
	public function test_validate_add_to_cart_succeeds_creates_reservation(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'wc_product_id' => 100,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart_with_session( array() );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );

		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		// Expect reservation to be created only when validation passes.
		$this->capacity_service->expects( $this->once() )
			->method( 'create_pending_reservation' )
			->with( 1, 2, $this->stringContains( 'nettertech_events_cart_' ) );

		$result = $this->handler->validate_add_to_cart( true, 100, 2 );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_add_to_cart does not create reservation when validation fails.
	 *
	 * Verifies the separation of concerns: side effects (reservation creation)
	 * only occur after pure validation passes.
	 *
	 * @return void
	 */
	public function test_validate_add_to_cart_no_reservation_on_failure(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'wc_product_id' => 100,
				'min_per_order' => 1,
				'max_per_order' => 2,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart( array() );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );

		// Reservation must NEVER be created when validation fails.
		$this->capacity_service->expects( $this->never() )
			->method( 'create_pending_reservation' );

		$result = $this->handler->validate_add_to_cart( true, 100, 5 ); // 5 > max 2

		$this->assertFalse( $result );
	}

	// =========================================================================
	// handle_cart_quantity_update Tests
	// =========================================================================

	/**
	 * Test handle_cart_quantity_update returns when no cart.
	 *
	 * @return void
	 */
	public function test_handle_cart_quantity_update_returns_without_cart(): void {
		$wc_mock       = new \stdClass();
		$wc_mock->cart = null;
		Functions\when( 'WC' )->justReturn( $wc_mock );

		$this->capacity_service->expects( $this->never() )
			->method( 'create_pending_reservation' );

		$this->handler->handle_cart_quantity_update( 'item_key', 5 );
	}

	/**
	 * Test handle_cart_quantity_update returns when item not found.
	 *
	 * @return void
	 */
	public function test_handle_cart_quantity_update_returns_item_not_found(): void {
		$wc_cart = $this->createMock( \WC_Cart::class );
		$wc_cart->method( 'get_cart_item' )->willReturn( null );

		$wc_mock       = new \stdClass();
		$wc_mock->cart = $wc_cart;
		Functions\when( 'WC' )->justReturn( $wc_mock );

		$this->capacity_service->expects( $this->never() )
			->method( 'create_pending_reservation' );

		$this->handler->handle_cart_quantity_update( 'nonexistent_key', 5 );
	}

	/**
	 * Test handle_cart_quantity_update returns for non-ticket product.
	 *
	 * @return void
	 */
	public function test_handle_cart_quantity_update_returns_for_non_ticket(): void {
		$product = $this->createMock( \WC_Product::class );

		$wc_cart = $this->createMock( \WC_Cart::class );
		$wc_cart->method( 'get_cart_item' )->willReturn( array( 'data' => $product ) );

		$wc_mock       = new \stdClass();
		$wc_mock->cart = $wc_cart;
		Functions\when( 'WC' )->justReturn( $wc_mock );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( false );

		$this->capacity_service->expects( $this->never() )
			->method( 'create_pending_reservation' );

		$this->handler->handle_cart_quantity_update( 'item_key', 5 );
	}

	/**
	 * Test handle_cart_quantity_update updates reservation.
	 *
	 * @return void
	 */
	public function test_handle_cart_quantity_update_updates_reservation(): void {
		$product     = $this->createMock( \WC_Product::class );
		$ticket_type = TicketTypeFactory::create( array( 'id' => 10, 'wc_product_id' => 100 ) );

		$wc_cart = $this->createMock( \WC_Cart::class );
		$wc_cart->method( 'get_cart_item' )->willReturn( array( 'data' => $product ) );
		$wc_cart->method( 'get_cart' )->willReturn(
			array(
				'item_key' => array( 'product_id' => 100, 'quantity' => 5 ),
			)
		);

		$wc_session = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'get_customer_id' ) )
			->getMock();
		$wc_session->method( 'get_customer_id' )->willReturn( 'customer_123' );

		$wc_mock          = new \stdClass();
		$wc_mock->cart    = $wc_cart;
		$wc_mock->session = $wc_session;
		Functions\when( 'WC' )->justReturn( $wc_mock );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		$this->capacity_service->expects( $this->once() )
			->method( 'create_pending_reservation' )
			->with( 10, 5, $this->stringContains( 'nettertech_events_cart_' ) );

		$this->handler->handle_cart_quantity_update( 'item_key', 5 );
	}

	/**
	 * Test handle_cart_quantity_update clears reservation at zero.
	 *
	 * @return void
	 */
	public function test_handle_cart_quantity_update_clears_at_zero(): void {
		$product     = $this->createMock( \WC_Product::class );
		$ticket_type = TicketTypeFactory::create( array( 'id' => 10, 'wc_product_id' => 100 ) );

		$wc_cart = $this->createMock( \WC_Cart::class );
		$wc_cart->method( 'get_cart_item' )->willReturn( array( 'data' => $product ) );
		$wc_cart->method( 'get_cart' )->willReturn( array() ); // Empty cart.

		$wc_session = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'get_customer_id' ) )
			->getMock();
		$wc_session->method( 'get_customer_id' )->willReturn( 'customer_123' );

		$wc_mock          = new \stdClass();
		$wc_mock->cart    = $wc_cart;
		$wc_mock->session = $wc_session;
		Functions\when( 'WC' )->justReturn( $wc_mock );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		$this->capacity_service->expects( $this->once() )
			->method( 'clear_pending_reservation' )
			->with( 10, $this->stringContains( 'nettertech_events_cart_' ) );

		$this->handler->handle_cart_quantity_update( 'item_key', 0 );
	}

	// =========================================================================
	// get_cart_quantity_for_ticket_type Tests
	// =========================================================================

	/**
	 * Test get_cart_quantity_for_ticket_type returns zero without cart.
	 *
	 * @return void
	 */
	public function test_get_cart_quantity_for_ticket_type_without_cart(): void {
		$wc_mock       = new \stdClass();
		$wc_mock->cart = null;
		Functions\when( 'WC' )->justReturn( $wc_mock );

		$result = $this->handler->get_cart_quantity_for_ticket_type( 1 );

		$this->assertSame( 0, $result );
	}

	// =========================================================================
	// get_cart_quantity_for_occurrence Tests
	// =========================================================================

	/**
	 * Test get_cart_quantity_for_occurrence returns zero without cart.
	 *
	 * @return void
	 */
	public function test_get_cart_quantity_for_occurrence_without_cart(): void {
		$wc_mock       = new \stdClass();
		$wc_mock->cart = null;
		Functions\when( 'WC' )->justReturn( $wc_mock );

		$result = $this->handler->get_cart_quantity_for_occurrence( 1 );

		$this->assertSame( 0, $result );
	}

	// =========================================================================
	// cart_has_tickets Tests
	// =========================================================================

	/**
	 * Test cart_has_tickets returns false without cart.
	 *
	 * @return void
	 */
	public function test_cart_has_tickets_without_cart(): void {
		$wc_mock       = new \stdClass();
		$wc_mock->cart = null;
		Functions\when( 'WC' )->justReturn( $wc_mock );

		$result = $this->handler->cart_has_tickets();

		$this->assertFalse( $result );
	}

	// =========================================================================
	// handle_cart_emptied Tests (with WC session)
	// =========================================================================

	/**
	 * Test handle_cart_emptied clears tracked ticket types.
	 *
	 * @return void
	 */
	public function test_handle_cart_emptied_clears_tracked_types(): void {
		$wc_session = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'get_customer_id', 'get', 'set' ) )
			->getMock();
		$wc_session->method( 'get_customer_id' )->willReturn( 'customer_123' );
		$wc_session->method( 'get' )->willReturn( array( 10, 20 ) ); // Tracked types.
		$wc_session->expects( $this->once() )->method( 'set' )->with( 'nettertech_events_tracked_ticket_types', array() );

		$wc_mock          = new \stdClass();
		$wc_mock->session = $wc_session;
		Functions\when( 'WC' )->justReturn( $wc_mock );

		// Expect reservations to be cleared for each tracked type.
		$this->capacity_service->expects( $this->exactly( 2 ) )
			->method( 'clear_pending_reservation' );

		$this->handler->handle_cart_emptied();
	}

	// =========================================================================
	// validate_batch Tests
	// =========================================================================

	/**
	 * Test validate_batch returns valid for single ticket.
	 *
	 * @return void
	 */
	public function test_validate_batch_valid_single_ticket(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'occurrence_id' => 50,
				'wc_product_id' => 100,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'id'             => 50,
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart( array() );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->occurrence_repo->method( 'find' )->with( 50 )->willReturn( $occurrence );
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		$result = $this->handler->validate_batch( array( 1 => 2 ) );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
		$this->assertArrayHasKey( 1, $result['validated'] );
		$this->assertSame( 2, $result['validated'][1] );
	}

	/**
	 * Test validate_batch returns valid for multiple tickets.
	 *
	 * @return void
	 */
	public function test_validate_batch_valid_multiple_tickets(): void {
		$occurrence = OccurrenceFactory::create(
			array(
				'id'             => 50,
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$ticket_type_1 = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'occurrence_id' => 50,
				'wc_product_id' => 100,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$ticket_type_2 = TicketTypeFactory::create(
			array(
				'id'            => 2,
				'occurrence_id' => 50,
				'wc_product_id' => 101,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart( array() );

		$this->ticket_type_repo->method( 'find' )->willReturnMap(
			array(
				array( 1, $ticket_type_1 ),
				array( 2, $ticket_type_2 ),
			)
		);
		$this->occurrence_repo->method( 'find' )->with( 50 )->willReturn( $occurrence );
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		$result = $this->handler->validate_batch(
			array(
				1 => 2,
				2 => 3,
			)
		);

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
		$this->assertCount( 2, $result['validated'] );
		$this->assertSame( 2, $result['validated'][1] );
		$this->assertSame( 3, $result['validated'][2] );
	}

	/**
	 * Test validate_batch fails when ticket type not found.
	 *
	 * @return void
	 */
	public function test_validate_batch_ticket_type_not_found(): void {
		$this->ticket_type_repo->method( 'find' )->willReturn( null );

		$result = $this->handler->validate_batch( array( 999 => 2 ) );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 999, $result['errors'] );
		$this->assertStringContainsString( 'not found', $result['errors'][999] );
		$this->assertEmpty( $result['validated'] );
	}

	/**
	 * Test validate_batch fails when WC product ID missing.
	 *
	 * @return void
	 */
	public function test_validate_batch_missing_wc_product_id(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'wc_product_id' => null,
			)
		);

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		$result = $this->handler->validate_batch( array( 1 => 2 ) );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 1, $result['errors'] );
		$this->assertStringContainsString( 'not available', $result['errors'][1] );
		$this->assertEmpty( $result['validated'] );
	}

	/**
	 * Test validate_batch fails when validation error occurs.
	 *
	 * @return void
	 */
	public function test_validate_batch_validation_error(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'occurrence_id' => 50,
				'wc_product_id' => 100,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'id'             => 50,
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart( array() );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->occurrence_repo->method( 'find' )->with( 50 )->willReturn( $occurrence );

		$this->capacity_service->method( 'has_availability' )->willReturn( false );
		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'is_sold_out'         => true,
				'effective_available' => 0,
			)
		);

		$result = $this->handler->validate_batch( array( 1 => 2 ) );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 1, $result['errors'] );
		$this->assertEmpty( $result['validated'] );
	}

	/**
	 * Test validate_batch returns mixed results for multiple tickets.
	 *
	 * @return void
	 */
	public function test_validate_batch_mixed_results(): void {
		$occurrence = OccurrenceFactory::create(
			array(
				'id'             => 50,
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$ticket_type_valid   = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'occurrence_id' => 50,
				'wc_product_id' => 100,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$ticket_type_invalid = TicketTypeFactory::create(
			array(
				'id'            => 2,
				'wc_product_id' => null, // Missing product ID.
			)
		);

		$this->setup_wc_mock_for_cart( array() );

		$this->ticket_type_repo->method( 'find' )->willReturnMap(
			array(
				array( 1, $ticket_type_valid ),
				array( 2, $ticket_type_invalid ),
			)
		);
		$this->occurrence_repo->method( 'find' )->with( 50 )->willReturn( $occurrence );
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		$result = $this->handler->validate_batch(
			array(
				1 => 2,
				2 => 1,
			)
		);

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 2, $result['errors'] );
		$this->assertArrayNotHasKey( 1, $result['errors'] );
		$this->assertArrayHasKey( 1, $result['validated'] );
		$this->assertSame( 2, $result['validated'][1] );
	}

	/**
	 * Test validate_batch returns valid for empty array.
	 *
	 * @return void
	 */
	public function test_validate_batch_empty_array(): void {
		$result = $this->handler->validate_batch( array() );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
		$this->assertEmpty( $result['validated'] );
	}

	/**
	 * Test validate_batch considers cart quantity in validation.
	 *
	 * @return void
	 */
	public function test_validate_batch_considers_cart_quantity(): void {
		$occurrence  = OccurrenceFactory::create(
			array(
				'id'             => 50,
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'occurrence_id' => 50,
				'wc_product_id' => 100,
				'min_per_order' => 1,
				'max_per_order' => 5, // Max 5 per order.
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		// 3 already in cart + 3 requested = 6 > max 5.
		$this->setup_wc_mock_for_cart(
			array(
				'item1' => array(
					'product_id' => 100,
					'quantity'   => 3,
				),
			)
		);

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->occurrence_repo->method( 'find' )->with( 50 )->willReturn( $occurrence );

		// Even if availability exists, max per order should fail.
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		$result = $this->handler->validate_batch( array( 1 => 3 ) ); // 3 + 3 = 6 > 5.

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 1, $result['errors'] );
	}

	/**
	 * Test validate_batch fails when event ended.
	 *
	 * @return void
	 */
	public function test_validate_batch_fails_event_ended(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'occurrence_id' => 50,
				'wc_product_id' => 100,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 week' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		// Occurrence is in the past.
		$occurrence  = OccurrenceFactory::create(
			array(
				'id'             => 50,
				'start_datetime' => ( new \DateTimeImmutable( '-2 days' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart( array() );
		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->occurrence_repo->method( 'find' )->with( 50 )->willReturn( $occurrence );

		$result = $this->handler->validate_batch( array( 1 => 2 ) );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 1, $result['errors'] );
	}

	/**
	 * Test validate_batch fails below minimum per order.
	 *
	 * @return void
	 */
	public function test_validate_batch_fails_below_minimum(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'occurrence_id' => 50,
				'wc_product_id' => 100,
				'min_per_order' => 5, // Min 5.
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'id'             => 50,
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart( array() );
		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->occurrence_repo->method( 'find' )->with( 50 )->willReturn( $occurrence );

		$result = $this->handler->validate_batch( array( 1 => 2 ) ); // 2 < min 5.

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 1, $result['errors'] );
	}

	/**
	 * Test validate_batch fails above maximum per order.
	 *
	 * @return void
	 */
	public function test_validate_batch_fails_above_maximum(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'occurrence_id' => 50,
				'wc_product_id' => 100,
				'min_per_order' => 1,
				'max_per_order' => 4, // Max 4.
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'id'             => 50,
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart( array() );
		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->occurrence_repo->method( 'find' )->with( 50 )->willReturn( $occurrence );

		$result = $this->handler->validate_batch( array( 1 => 10 ) ); // 10 > max 4.

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 1, $result['errors'] );
	}

	/**
	 * Test validate_batch fails when sold out.
	 *
	 * @return void
	 */
	public function test_validate_batch_fails_sold_out(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'occurrence_id' => 50,
				'wc_product_id' => 100,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence  = OccurrenceFactory::create(
			array(
				'id'             => 50,
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->setup_wc_mock_for_cart( array() );
		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->occurrence_repo->method( 'find' )->with( 50 )->willReturn( $occurrence );

		$this->capacity_service->method( 'has_availability' )->willReturn( false );
		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'is_sold_out'         => true,
				'effective_available' => 0,
			)
		);

		$result = $this->handler->validate_batch( array( 1 => 2 ) );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 1, $result['errors'] );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Setup WC() mock for cart tests.
	 *
	 * @param array $cart_contents Cart contents.
	 * @return void
	 */
	private function setup_wc_mock_for_cart( array $cart_contents ): void {
		$wc_cart = $this->createMock( \WC_Cart::class );
		$wc_cart->method( 'get_cart' )->willReturn( $cart_contents );

		$wc_mock       = new \stdClass();
		$wc_mock->cart = $wc_cart;
		Functions\when( 'WC' )->justReturn( $wc_mock );
	}

	/**
	 * Setup WC() mock for cart tests with session.
	 *
	 * @param array $cart_contents Cart contents.
	 * @return void
	 */
	private function setup_wc_mock_for_cart_with_session( array $cart_contents ): void {
		$wc_cart = $this->createMock( \WC_Cart::class );
		$wc_cart->method( 'get_cart' )->willReturn( $cart_contents );

		$wc_session = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'get_customer_id', 'get', 'set' ) )
			->getMock();
		$wc_session->method( 'get_customer_id' )->willReturn( 'customer_123' );
		$wc_session->method( 'get' )->willReturn( array() );
		$wc_session->method( 'set' )->willReturn( null );

		$wc_mock          = new \stdClass();
		$wc_mock->cart    = $wc_cart;
		$wc_mock->session = $wc_session;
		Functions\when( 'WC' )->justReturn( $wc_mock );
	}

	// =========================================================================
	// unsellable_items() / collect_store_api_errors() mutation-hardening (NTE-151)
	//
	// These target surviving Infection mutants in the block-checkout re-check:
	// the error-code prefix, the (int) casts on product_id/quantity, the four
	// guard conditions on the "no longer available" line, the loop's continue,
	// the two null-coalesces on error_code/error, and the ArrayOneItem return.
	// =========================================================================

	/**
	 * A cart holding several ticket lines, keyed as WooCommerce keys them.
	 *
	 * @param array<string, array<string, mixed>> $items Raw cart lines.
	 * @return \WC_Cart
	 */
	private function cart_of( array $items ): \WC_Cart {
		$cart                = new \WC_Cart();
		$cart->cart_contents = $items;

		return $cart;
	}

	/**
	 * A ticket type that is open for sale now.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return \NetterTechEvents\Models\TicketType
	 */
	private function on_sale_ticket( array $overrides = array() ) {
		return TicketTypeFactory::create(
			array_merge(
				array(
					'id'            => 1,
					'name'          => 'General Admission',
					'wc_product_id' => 555,
					'min_per_order' => 1,
					'max_per_order' => 10,
					'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
					'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
				),
				$overrides
			)
		);
	}

	/**
	 * An occurrence that has not started yet.
	 *
	 * @return \NetterTechEvents\Models\Occurrence
	 */
	private function future_occurrence() {
		return OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 week +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * An occurrence that is already over.
	 *
	 * @return \NetterTechEvents\Models\Occurrence
	 */
	private function ended_occurrence() {
		return OccurrenceFactory::create(
			array(
				'start_datetime' => ( new \DateTimeImmutable( '-2 days' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Test the Store API error code is the exact prefixed reason, not a re-order or a swap.
	 *
	 * The concat builds `nettertech_events_` + the validator's reason code, and the message
	 * keeps the reason after the ticket name. A shopper refused at block checkout must be told
	 * *why*, under a stable code the front end can key on.
	 *
	 * Kills: Concat / ConcatOperandRemoval x2 / Ternary on the code (line 175), the error_code
	 * coalesce (line 222) and the error-text coalesce (line 230).
	 *
	 * @return void
	 */
	public function test_store_api_error_code_and_reason_are_exact(): void {
		// Open when added, shut five minutes ago: validator returns 'not_on_sale'.
		$ticket_type = $this->on_sale_ticket(
			array(
				'name'     => 'Early Bird',
				'sale_end' => ( new \DateTimeImmutable( '-5 minutes' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$this->product_resolves_to( $ticket_type, $this->future_occurrence() );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 2 );
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_of(
			array( 'abc' => array( 'product_id' => 555, 'quantity' => 2 ) )
		) );

		$this->assertSame(
			'nettertech_events_not_on_sale',
			$errors->get_error_code(),
			'The Store API code must be the prefix followed by the validator reason, in that order.'
		);
		$this->assertStringContainsString(
			'not currently on sale',
			(string) $errors->get_error_message(),
			'The refusal must carry the validator reason, not an empty second clause.'
		);
	}

	/**
	 * Test a cart line with no product_id is skipped without touching the product manager.
	 *
	 * The missing-key branch must resolve to 0 (falsy) so the line is skipped. A mutated -1 or 1
	 * is truthy and would drag a bogus id into is_event_ticket().
	 *
	 * Kills: DecrementInteger / IncrementInteger on the product_id fallback (line 193).
	 *
	 * @return void
	 */
	public function test_line_without_product_id_is_skipped(): void {
		Functions\when( 'WC' )->justReturn( null );

		$this->product_manager->expects( $this->never() )->method( 'is_event_ticket' );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_of(
			array( 'abc' => array( 'quantity' => 2 ) )
		) );

		$this->assertFalse( $errors->has_errors() );
	}

	/**
	 * Test the product_id is cast to int before it is used to identify the product.
	 *
	 * WooCommerce cart lines can carry a numeric-string id; the re-check must hand the manager
	 * a real int. Without the cast, is_event_ticket() would receive the string '555'.
	 *
	 * Kills: CastInt on product_id (line 193).
	 *
	 * @return void
	 */
	public function test_product_id_is_cast_to_int(): void {
		Functions\when( 'WC' )->justReturn( null );

		$this->product_manager
			->expects( $this->once() )
			->method( 'is_event_ticket' )
			->with( $this->identicalTo( 555 ) )
			->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $this->on_sale_ticket() );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $this->future_occurrence() );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_of(
			array( 'abc' => array( 'product_id' => '555', 'quantity' => 2 ) )
		) );

		$this->assertFalse( $errors->has_errors() );
	}

	/**
	 * Test a non-ticket product in the cart is left alone rather than refused.
	 *
	 * The guard is `! product_id || ! is_event_ticket`: a real product that is not a ticket must
	 * short-circuit and be skipped. Flipping the OR to AND would drive a non-ticket line into the
	 * ticket-only resolution below and manufacture a spurious refusal.
	 *
	 * Kills: LogicalOr (line 195).
	 *
	 * @return void
	 */
	public function test_non_ticket_product_is_not_refused(): void {
		Functions\when( 'WC' )->justReturn( null );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( false );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_of(
			array( 'abc' => array( 'product_id' => 555, 'quantity' => 2 ) )
		) );

		$this->assertFalse(
			$errors->has_errors(),
			'A non-ticket product must be skipped, not run through the ticket re-check.'
		);
	}

	/**
	 * Test the quantity is cast to int before it is validated and net-calculated.
	 *
	 * A numeric-string quantity must become an int; the validator's signature is strictly typed,
	 * so an un-cast string would be a TypeError rather than a clean pass.
	 *
	 * Kills: CastInt on quantity (line 199).
	 *
	 * @return void
	 */
	public function test_quantity_is_cast_to_int(): void {
		Functions\when( 'WC' )->justReturn( null );

		$this->product_resolves_to( $this->on_sale_ticket(), $this->future_occurrence() );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_of(
			array( 'abc' => array( 'product_id' => 555, 'quantity' => '2' ) )
		) );

		$this->assertFalse( $errors->has_errors() );
	}

	/**
	 * Test a line whose quantity key is missing resolves to 0 and is refused as unavailable.
	 *
	 * The missing-key fallback must be 0, which trips `$quantity < 1` and reports the generic
	 * unavailable reason. A mutated fallback of 1 would sail past the guard into the validator.
	 *
	 * Kills: IncrementInteger on the quantity fallback (line 199).
	 *
	 * @return void
	 */
	public function test_line_without_quantity_is_unavailable(): void {
		Functions\when( 'WC' )->justReturn( null );

		$this->product_resolves_to( $this->on_sale_ticket(), $this->future_occurrence() );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );

		// The validator must never be consulted: the zero-quantity guard fires first.
		$this->capacity_service->expects( $this->never() )->method( 'has_availability' );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_of(
			array( 'abc' => array( 'product_id' => 555 ) )
		) );

		$this->assertSame( 'nettertech_events_ticket_unavailable', $errors->get_error_code() );
	}

	/**
	 * Test a resolved ticket type with no occurrence is refused as unavailable.
	 *
	 * The guard `! ticket_type || ! occurrence || null === id || quantity < 1` must fire on the
	 * missing occurrence and stop before the strictly-typed validator call. Re-associating those
	 * OR clauses lets a null occurrence through to a TypeError.
	 *
	 * Kills: LogicalOr x2 (line 205, first and second operators).
	 *
	 * @return void
	 */
	public function test_missing_occurrence_is_unavailable_not_fatal(): void {
		Functions\when( 'WC' )->justReturn( null );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $this->on_sale_ticket() );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( null );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_of(
			array( 'abc' => array( 'product_id' => 555, 'quantity' => 2 ) )
		) );

		$this->assertSame( 'nettertech_events_ticket_unavailable', $errors->get_error_code() );
	}

	/**
	 * Test a quantity of exactly one clears the guard and reaches the validator.
	 *
	 * The boundary is `$quantity < 1`: one is allowed. Widening it to `<= 1` would wrongly brand
	 * a single, valid ticket as unavailable.
	 *
	 * Kills: LessThan (line 205).
	 *
	 * @return void
	 */
	public function test_single_ticket_clears_the_quantity_guard(): void {
		Functions\when( 'WC' )->justReturn( null );

		$this->product_resolves_to( $this->on_sale_ticket(), $this->future_occurrence() );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_of(
			array( 'abc' => array( 'product_id' => 555, 'quantity' => 1 ) )
		) );

		$this->assertFalse(
			$errors->has_errors(),
			'A single valid ticket must pass the re-check; the guard is strictly less-than one.'
		);
	}

	/**
	 * Test a zero-quantity line yields the generic unavailable code, not a validator code.
	 *
	 * With `null === id || quantity < 1` the last operator must stay an OR so a zero quantity on an
	 * otherwise-resolvable ticket short-circuits to 'ticket_unavailable' rather than falling through
	 * to the validator (which would answer 'below_minimum').
	 *
	 * Kills: LogicalOr (line 205, third operator).
	 *
	 * @return void
	 */
	public function test_zero_quantity_reports_generic_unavailable(): void {
		Functions\when( 'WC' )->justReturn( null );

		$this->product_resolves_to( $this->on_sale_ticket(), $this->future_occurrence() );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_of(
			array( 'abc' => array( 'product_id' => 555, 'quantity' => 0 ) )
		) );

		$this->assertSame(
			'nettertech_events_ticket_unavailable',
			$errors->get_error_code(),
			'A zero quantity must be refused generically, not routed through the validator.'
		);
	}

	/**
	 * Test the re-check calls the validator with a zero cart-quantity baseline.
	 *
	 * The fourth argument to validate_ticket_purchase() is the already-in-cart count, and here it
	 * is a hard 0 because the cart line itself carries the quantity. A mutated -1 shrinks the total
	 * below the minimum and the shopper would be told 'below_minimum' instead of the true 'sold_out'.
	 *
	 * Kills: DecrementInteger on the cart-quantity argument (line 214).
	 *
	 * @return void
	 */
	public function test_recheck_passes_zero_cart_quantity_to_validator(): void {
		Functions\when( 'WC' )->justReturn( null );

		$this->product_resolves_to( $this->on_sale_ticket(), $this->future_occurrence() );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );
		$this->capacity_service->method( 'has_availability' )->willReturn( false );
		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'is_sold_out'         => true,
				'effective_available' => 0,
			)
		);

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_of(
			array( 'abc' => array( 'product_id' => 555, 'quantity' => 1 ) )
		) );

		$this->assertSame(
			'nettertech_events_sold_out',
			$errors->get_error_code(),
			'A zero baseline keeps the total at the requested quantity, so a full room reads as sold out.'
		);
	}

	/**
	 * Test a valid ticket does not stop the re-check from examining the rest of the cart.
	 *
	 * The valid branch must `continue`, not `break`: a good early line must never mask a bad later
	 * one, or a shopper could park an unsellable ticket behind a sellable one and check out.
	 *
	 * Kills: Continue_ -> break (line 219).
	 *
	 * @return void
	 */
	public function test_valid_line_does_not_halt_the_recheck(): void {
		Functions\when( 'WC' )->justReturn( null );

		$good = $this->future_occurrence();
		$bad  = $this->ended_occurrence();

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $this->on_sale_ticket() );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturnCallback(
			static function ( $product_id ) use ( $good, $bad ) {
				return 666 === $product_id ? $bad : $good;
			}
		);
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_of(
			array(
				'good' => array( 'product_id' => 555, 'quantity' => 1 ),
				'bad'  => array( 'product_id' => 666, 'quantity' => 1 ),
			)
		) );

		$this->assertSame(
			'nettertech_events_event_ended',
			$errors->get_error_code(),
			'A valid earlier line must not break the loop before the ended later line is caught.'
		);
	}

	/**
	 * Test every distinct problem is reported, not just the first.
	 *
	 * The method returns the whole problems map. Truncating it to a single item would hide every
	 * fault after the first, so a cart with two different failures must surface two error codes.
	 *
	 * Kills: ArrayOneItem on the return (line 234).
	 *
	 * @return void
	 */
	public function test_all_distinct_problems_are_reported(): void {
		Functions\when( 'WC' )->justReturn( null );

		$ended   = $this->ended_occurrence();
		$future  = $this->future_occurrence();
		$on_sale = $this->on_sale_ticket();
		$closed  = $this->on_sale_ticket(
			array( 'sale_end' => ( new \DateTimeImmutable( '-5 minutes' ) )->format( 'Y-m-d H:i:s' ) )
		);

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturnCallback(
			static function ( $product_id ) use ( $on_sale, $closed ) {
				return 666 === $product_id ? $closed : $on_sale;
			}
		);
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturnCallback(
			static function ( $product_id ) use ( $ended, $future ) {
				return 666 === $product_id ? $future : $ended;
			}
		);
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_of(
			array(
				'ended'  => array( 'product_id' => 555, 'quantity' => 1 ),
				'closed' => array( 'product_id' => 666, 'quantity' => 1 ),
			)
		) );

		$this->assertCount(
			2,
			$errors->get_error_codes(),
			'Two different faults must produce two error codes, not a single truncated one.'
		);
		$this->assertContains( 'nettertech_events_event_ended', $errors->get_error_codes() );
		$this->assertContains( 'nettertech_events_not_on_sale', $errors->get_error_codes() );
	}

	// =========================================================================
	// Series-pass occurrence fallback (NTE-156)
	//
	// A series pass carries no occurrence of its own; both the add-to-cart gate
	// (validate_add_to_cart) and the block-checkout re-check (unsellable_items)
	// anchor its date checks to next_for_event(). These target the surviving
	// Infection mutants on the fallback condition
	//   ! $occurrence && $ticket_type && null !== $ticket_type->event_id
	// at lines 205 and 273 — the negation, the logical ANDs, the null-!== test,
	// and their sub-expression negations.
	// =========================================================================

	/**
	 * A tier standing in for a series pass: no product occurrence, but an event.
	 *
	 * @param int|null $event_id The event the pass spans, or null for a plain tier.
	 * @return \NetterTechEvents\Models\TicketType
	 */
	private function pass_tier( ?int $event_id ): object {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'            => 1,
				'wc_product_id' => 555,
				'min_per_order' => 1,
				'max_per_order' => 10,
				'sale_start'    => ( new \DateTimeImmutable( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
				'sale_end'      => ( new \DateTimeImmutable( '+1 week' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$ticket_type->event_id = $event_id;

		return $ticket_type;
	}

	/**
	 * Test add-to-cart resolves a series pass through next_for_event and proceeds.
	 *
	 * The pass has no product occurrence; the gate must anchor to the next upcoming
	 * date and then validate as normal. Kills the LogicalNot, NotIdentical, and
	 * all-sub-expression-negation mutants on the line-273 fallback: each of them
	 * leaves the occurrence unresolved, tripping the "no longer available" refusal.
	 *
	 * @return void
	 */
	public function test_validate_add_to_cart_resolves_series_pass_via_next_for_event(): void {
		$ticket_type = $this->pass_tier( 42 );

		$this->setup_wc_mock_for_cart_with_session( array() );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		// A pass carries no occurrence of its own.
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( null );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );
		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		// The fallback must anchor to the event's next upcoming date.
		$this->occurrence_repo->expects( $this->once() )
			->method( 'next_for_event' )
			->with( 42 )
			->willReturn( $this->future_occurrence() );

		$result = $this->handler->validate_add_to_cart( true, 100, 2 );

		$this->assertTrue(
			$result,
			'A series pass with a resolvable upcoming date must clear the add-to-cart gate.'
		);
	}

	/**
	 * Test add-to-cart refuses a series pass with no upcoming date left.
	 *
	 * next_for_event() returning null means nothing remains to admit to, so the
	 * occurrence stays unresolved and the gate refuses.
	 *
	 * @return void
	 */
	public function test_validate_add_to_cart_refuses_series_pass_with_no_upcoming_date(): void {
		$ticket_type = $this->pass_tier( 42 );

		$this->setup_wc_mock_for_cart( array() );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( null );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		$this->occurrence_repo->expects( $this->once() )
			->method( 'next_for_event' )
			->with( 42 )
			->willReturn( null );

		$this->capacity_service->expects( $this->never() )
			->method( 'create_pending_reservation' );

		$result = $this->handler->validate_add_to_cart( true, 100, 2 );

		$this->assertFalse(
			$result,
			'A series pass with no remaining date must be refused, not admitted.'
		);
	}

	/**
	 * Test add-to-cart does not reach the fallback for a plain tier without an occurrence.
	 *
	 * A non-pass tier (event_id null) whose occurrence cannot be resolved must be
	 * refused without ever consulting next_for_event. Kills the NotIdentical mutant
	 * (null === event_id), which would otherwise enter the fallback for event_id null.
	 *
	 * @return void
	 */
	public function test_validate_add_to_cart_skips_fallback_for_non_pass_without_occurrence(): void {
		$ticket_type = $this->pass_tier( null );

		$this->setup_wc_mock_for_cart( array() );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( null );

		$this->ticket_type_repo->method( 'find' )->willReturn( $ticket_type );

		$this->occurrence_repo->expects( $this->never() )
			->method( 'next_for_event' );

		$result = $this->handler->validate_add_to_cart( true, 100, 2 );

		$this->assertFalse(
			$result,
			'A plain tier with no occurrence must be refused without reaching the pass fallback.'
		);
	}

	/**
	 * Test the block-checkout re-check resolves a series pass through next_for_event.
	 *
	 * Mirror of the add-to-cart gate at line 205. Kills the LogicalNot, NotIdentical,
	 * single- and all-sub-expression-negation mutants: each leaves the occurrence
	 * unresolved, producing a spurious "no longer available" error.
	 *
	 * @return void
	 */
	public function test_recheck_resolves_series_pass_via_next_for_event(): void {
		$ticket_type = $this->pass_tier( 42 );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( null );

		$this->occurrence_repo->expects( $this->once() )
			->method( 'next_for_event' )
			->with( 42 )
			->willReturn( $this->future_occurrence() );

		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		Functions\when( 'WC' )->justReturn( null );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_holding( 555, 2 ) );

		$this->assertFalse(
			$errors->has_errors(),
			'A series pass with a resolvable upcoming date must survive the block-checkout re-check.'
		);
	}

	/**
	 * Test the block-checkout re-check refuses a series pass with no upcoming date.
	 *
	 * @return void
	 */
	public function test_recheck_refuses_series_pass_with_no_upcoming_date(): void {
		$ticket_type = $this->pass_tier( 42 );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( null );

		$this->occurrence_repo->expects( $this->once() )
			->method( 'next_for_event' )
			->with( 42 )
			->willReturn( null );

		Functions\when( 'WC' )->justReturn( null );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_holding( 555, 2 ) );

		$this->assertTrue(
			$errors->has_errors(),
			'A series pass with no remaining date must be flagged unsellable at re-check.'
		);
	}

	/**
	 * Test the re-check leaves a resolved product occurrence untouched by the fallback.
	 *
	 * When the product already resolves to a valid occurrence, the fallback must not
	 * fire — its guard leads with ! $occurrence. Kills the two LogicalAnd mutants,
	 * which relax the guard to (! occ || ticket) && id-set and (! occ && ticket) || id-set:
	 * both would overwrite the good occurrence with an ended one from next_for_event,
	 * turning a clean line into a spurious "event ended" error.
	 *
	 * @return void
	 */
	public function test_recheck_does_not_overwrite_a_resolved_occurrence(): void {
		$ticket_type = $this->pass_tier( 42 );

		// A perfectly good occurrence already resolved from the product.
		$valid = $this->future_occurrence();

		// What next_for_event would hand back if the fallback wrongly fired: a past date.
		$ended = $this->ended_occurrence();

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $valid );

		$this->occurrence_repo->method( 'next_for_event' )->willReturn( $ended );

		$this->capacity_service->method( 'get_pending_reservation' )->willReturn( 0 );
		$this->capacity_service->method( 'has_availability' )->willReturn( true );

		Functions\when( 'WC' )->justReturn( null );

		$errors = new \WP_Error();
		$this->handler->collect_store_api_errors( $errors, $this->cart_holding( 555, 2 ) );

		$this->assertFalse(
			$errors->has_errors(),
			'A line with a valid product occurrence must not be re-anchored to a past date by the pass fallback.'
		);
	}
}
