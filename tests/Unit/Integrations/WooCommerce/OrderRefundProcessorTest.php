<?php
/**
 * OrderRefundProcessor unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey\Functions;
use NetterTechEvents\Contracts\AttendeeOrderInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Integrations\WooCommerce\OrderRefundProcessor;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Tests\Factories\AttendeeFactory;
use NetterTechEvents\Tests\Factories\WooCommerceFactory;

/**
 * Test OrderRefundProcessor functionality.
 *
 * Tests refund processing logic including partial/full refunds,
 * attendee status updates, capacity release, and edge cases.
 */
class OrderRefundProcessorTest extends \NetterTechEventsTestCase {

	/**
	 * OrderRefundProcessor instance.
	 *
	 * @var OrderRefundProcessor
	 */
	private OrderRefundProcessor $processor;

	/**
	 * Mock ProductManager.
	 *
	 * @var ProductManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $product_manager;

	/**
	 * Mock AttendeeRepositoryInterface.
	 *
	 * @var AttendeeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $attendee_repo;

	/**
	 * Mock AttendeeOrderInterface.
	 *
	 * @var AttendeeOrderInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $attendee_order;

	/**
	 * Mock TicketRepositoryInterface.
	 *
	 * @var TicketRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_repo;

	/**
	 * Mock CapacityServiceInterface.
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
		$this->attendee_repo    = $this->createMock( AttendeeRepositoryInterface::class );
		$this->attendee_order   = $this->createMock( AttendeeOrderInterface::class );
		$this->ticket_repo      = $this->createMock( TicketRepositoryInterface::class );
		$this->capacity_service = $this->createMock( CapacityServiceInterface::class );

		$this->processor = new OrderRefundProcessor(
			$this->product_manager,
			$this->attendee_repo,
			$this->attendee_order,
			$this->ticket_repo,
			$this->capacity_service
		);

		AttendeeFactory::reset();
		WooCommerceFactory::reset();
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test OrderRefundProcessor can be instantiated with all dependencies.
	 *
	 * @return void
	 */
	public function test_can_be_instantiated(): void {
		$processor = new OrderRefundProcessor(
			$this->product_manager,
			$this->attendee_repo,
			$this->attendee_order,
			$this->ticket_repo,
			$this->capacity_service
		);

		$this->assertInstanceOf( OrderRefundProcessor::class, $processor );
	}

	// =========================================================================
	// process() Tests — Idempotency
	// =========================================================================

	/**
	 * Test process returns empty array when refund already processed.
	 *
	 * The HPOS-compatible idempotency check uses $parent_order->get_meta()
	 * with the refund-specific meta key. When the meta already contains 'yes',
	 * the refund is skipped.
	 *
	 * @return void
	 */
	public function test_process_returns_empty_when_already_processed(): void {
		$refund       = $this->create_mock_refund( 50 );
		$parent_order = $this->create_mock_order( 100, 'processing' );

		// Simulate already-processed: get_meta returns 'yes' for the refund key.
		$parent_order->method( 'get_meta' )->willReturn( 'yes' );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array(), $result );
	}

	// =========================================================================
	// process() Tests — Fully Refunded Order
	// =========================================================================

	/**
	 * Test process returns empty array when order status is fully refunded.
	 *
	 * When the order status is 'refunded', the full-refund handler
	 * (woocommerce_order_status_refunded) handles it. This processor
	 * only handles partial refunds.
	 *
	 * @return void
	 */
	public function test_process_returns_empty_for_fully_refunded_order(): void {
		$refund       = $this->create_mock_refund( 50 );
		$parent_order = $this->create_mock_order( 100, 'refunded' );

		// add_post_meta succeeds (not yet processed).
		Functions\when( 'add_post_meta' )->justReturn( 1 );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array(), $result );
	}

	// =========================================================================
	// process() Tests — Full Refund of Single Ticket
	// =========================================================================

	/**
	 * Test full refund of single ticket cancels attendee and releases capacity.
	 *
	 * Scenario: Order has 1 ticket (qty 1), refund is for qty 1.
	 * Expected: Attendee status set to 'refunded', all tickets cancelled,
	 *           capacity released, hook fired.
	 *
	 * @return void
	 */
	public function test_full_refund_of_single_ticket(): void {
		$product_id     = 200;
		$occurrence_id  = 10;
		$ticket_type_id = 5;
		$order_id       = 100;
		$refund_id      = 50;
		$original_item_id = 301;

		$refund       = $this->create_mock_refund( $refund_id );
		$parent_order = $this->create_mock_order( $order_id, 'processing' );

		// Refund item: qty -1 (refund quantities are negative in WooCommerce).
		$refund_item = $this->create_mock_order_item( 401, $product_id, -1 );
		$refund_item->add_meta_data( '_refunded_item_id', $original_item_id );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		// Original order item with occurrence and ticket type meta.
		$original_item = $this->create_mock_order_item( $original_item_id, $product_id, 1 );
		$original_item->add_meta_data( MetaKeys::OCCURRENCE_ID, $occurrence_id );
		$original_item->add_meta_data( MetaKeys::TICKET_TYPE_ID, $ticket_type_id );
		$parent_order->method( 'get_item' )->with( $original_item_id )->willReturn( $original_item );

		// Product is an event ticket.
		$this->product_manager->method( 'is_event_ticket' )->with( $product_id )->willReturn( true );

		// add_post_meta succeeds (not yet processed).
		Functions\when( 'add_post_meta' )->justReturn( 1 );

		// Attendee: quantity 1 (full refund will zero it out).
		$attendee = AttendeeFactory::create(
			array(
				'id'             => 1,
				'wc_order_id'    => $order_id,
				'occurrence_id'  => $occurrence_id,
				'ticket_type_id' => $ticket_type_id,
				'quantity'       => 1,
			)
		);

		$this->attendee_order
			->expects( $this->once() )
			->method( 'find_by_order_and_occurrence' )
			->with( $order_id, $occurrence_id )
			->willReturn( $attendee );

		// Full refund: new_quantity = max(0, 1 - 1) = 0 -> status to 'refunded'.
		$this->attendee_repo
			->expects( $this->once() )
			->method( 'update_status' )
			->with( 1, 'refunded' );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'cancel_all_tickets_for_attendee' )
			->with( 1 );

		// Partial update should NOT be called for full refund.
		$this->attendee_order
			->expects( $this->never() )
			->method( 'update_quantity' );

		$this->ticket_repo
			->expects( $this->never() )
			->method( 'cancel_tickets_for_attendee' );

		// Capacity released.
		$this->capacity_service
			->expects( $this->once() )
			->method( 'release_capacity' )
			->with( $ticket_type_id, 1 );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array( $ticket_type_id ), $result );
	}

	// =========================================================================
	// process() Tests — Partial Refund (Reduce Quantity)
	// =========================================================================

	/**
	 * Test partial refund reduces attendee quantity.
	 *
	 * Scenario: Order has 4 tickets, refund is for 2.
	 * Expected: Attendee quantity updated to 2, 2 tickets cancelled,
	 *           capacity released for 2.
	 *
	 * @return void
	 */
	public function test_partial_refund_reduces_quantity(): void {
		$product_id     = 200;
		$occurrence_id  = 10;
		$ticket_type_id = 5;
		$order_id       = 100;
		$refund_id      = 51;
		$original_item_id = 302;

		$refund       = $this->create_mock_refund( $refund_id );
		$parent_order = $this->create_mock_order( $order_id, 'processing' );

		// Refund item: qty -2 (partial refund of 2 out of 4).
		$refund_item = $this->create_mock_order_item( 402, $product_id, -2 );
		$refund_item->add_meta_data( '_refunded_item_id', $original_item_id );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		$original_item = $this->create_mock_order_item( $original_item_id, $product_id, 4 );
		$original_item->add_meta_data( MetaKeys::OCCURRENCE_ID, $occurrence_id );
		$original_item->add_meta_data( MetaKeys::TICKET_TYPE_ID, $ticket_type_id );
		$parent_order->method( 'get_item' )->with( $original_item_id )->willReturn( $original_item );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );

		Functions\when( 'add_post_meta' )->justReturn( 1 );

		$attendee = AttendeeFactory::create(
			array(
				'id'             => 2,
				'wc_order_id'    => $order_id,
				'occurrence_id'  => $occurrence_id,
				'ticket_type_id' => $ticket_type_id,
				'quantity'       => 4,
			)
		);

		$this->attendee_order
			->expects( $this->once() )
			->method( 'find_by_order_and_occurrence' )
			->with( $order_id, $occurrence_id )
			->willReturn( $attendee );

		// Partial refund: new_quantity = max(0, 4 - 2) = 2.
		$this->attendee_order
			->expects( $this->once() )
			->method( 'update_quantity' )
			->with( 2, 2 );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'cancel_tickets_for_attendee' )
			->with( 2, 2 );

		// Full cancel methods should NOT be called.
		$this->attendee_repo
			->expects( $this->never() )
			->method( 'update_status' );

		$this->ticket_repo
			->expects( $this->never() )
			->method( 'cancel_all_tickets_for_attendee' );

		$this->capacity_service
			->expects( $this->once() )
			->method( 'release_capacity' )
			->with( $ticket_type_id, 2 );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array( $ticket_type_id ), $result );
	}

	// =========================================================================
	// process() Tests — Non-Ticket Items Skipped
	// =========================================================================

	/**
	 * Test refund with non-ticket items skips them.
	 *
	 * Scenario: Refund contains a non-ticket product.
	 * Expected: No attendee/ticket operations occur, empty result.
	 *
	 * @return void
	 */
	public function test_non_ticket_items_are_skipped(): void {
		$product_id = 300;
		$refund     = $this->create_mock_refund( 52 );
		$parent_order = $this->create_mock_order( 100, 'processing' );

		$refund_item = $this->create_mock_order_item( 403, $product_id, -1 );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		// Product is NOT an event ticket.
		$this->product_manager->method( 'is_event_ticket' )->with( $product_id )->willReturn( false );

		Functions\when( 'add_post_meta' )->justReturn( 1 );

		// No repository interactions should occur.
		$this->attendee_order->expects( $this->never() )->method( 'find_by_order_and_occurrence' );
		$this->attendee_repo->expects( $this->never() )->method( 'update_status' );
		$this->ticket_repo->expects( $this->never() )->method( 'cancel_all_tickets_for_attendee' );
		$this->capacity_service->expects( $this->never() )->method( 'release_capacity' );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array(), $result );
	}

	/**
	 * Test refund with mixed ticket and non-ticket items processes only tickets.
	 *
	 * Scenario: Refund has 1 ticket product and 1 non-ticket product.
	 * Expected: Only the ticket item triggers attendee/capacity operations.
	 *
	 * @return void
	 */
	public function test_mixed_items_processes_only_tickets(): void {
		$ticket_product_id    = 200;
		$nonticket_product_id = 300;
		$occurrence_id        = 10;
		$ticket_type_id       = 5;
		$order_id             = 100;
		$original_item_id     = 301;

		$refund       = $this->create_mock_refund( 53 );
		$parent_order = $this->create_mock_order( $order_id, 'processing' );

		$ticket_refund_item = $this->create_mock_order_item( 404, $ticket_product_id, -1 );
		$ticket_refund_item->add_meta_data( '_refunded_item_id', $original_item_id );

		$nonticket_refund_item = $this->create_mock_order_item( 405, $nonticket_product_id, -2 );

		$refund->method( 'get_items' )->willReturn( array( $ticket_refund_item, $nonticket_refund_item ) );

		$this->product_manager->method( 'is_event_ticket' )
			->willReturnCallback(
				function ( int $product_id ) use ( $ticket_product_id ) {
					return $product_id === $ticket_product_id;
				}
			);

		$original_item = $this->create_mock_order_item( $original_item_id, $ticket_product_id, 1 );
		$original_item->add_meta_data( MetaKeys::OCCURRENCE_ID, $occurrence_id );
		$original_item->add_meta_data( MetaKeys::TICKET_TYPE_ID, $ticket_type_id );
		$parent_order->method( 'get_item' )->with( $original_item_id )->willReturn( $original_item );

		Functions\when( 'add_post_meta' )->justReturn( 1 );

		$attendee = AttendeeFactory::create(
			array(
				'id'            => 3,
				'wc_order_id'   => $order_id,
				'occurrence_id' => $occurrence_id,
				'quantity'      => 1,
			)
		);

		$this->attendee_order
			->expects( $this->once() )
			->method( 'find_by_order_and_occurrence' )
			->with( $order_id, $occurrence_id )
			->willReturn( $attendee );

		$this->attendee_repo
			->expects( $this->once() )
			->method( 'update_status' )
			->with( 3, 'refunded' );

		$this->capacity_service
			->expects( $this->once() )
			->method( 'release_capacity' )
			->with( $ticket_type_id, 1 );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array( $ticket_type_id ), $result );
	}

	// =========================================================================
	// process() Tests — Attendee Not Found
	// =========================================================================

	/**
	 * Test refund when attendee not found is handled gracefully.
	 *
	 * Scenario: Valid ticket product in refund, but no attendee exists
	 *           for the order+occurrence combination.
	 * Expected: No status updates, no capacity release, empty result.
	 *
	 * @return void
	 */
	public function test_attendee_not_found_handled_gracefully(): void {
		$product_id       = 200;
		$occurrence_id    = 10;
		$ticket_type_id   = 5;
		$order_id         = 100;
		$original_item_id = 301;

		$refund       = $this->create_mock_refund( 54 );
		$parent_order = $this->create_mock_order( $order_id, 'processing' );

		$refund_item = $this->create_mock_order_item( 406, $product_id, -1 );
		$refund_item->add_meta_data( '_refunded_item_id', $original_item_id );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		$original_item = $this->create_mock_order_item( $original_item_id, $product_id, 1 );
		$original_item->add_meta_data( MetaKeys::OCCURRENCE_ID, $occurrence_id );
		$original_item->add_meta_data( MetaKeys::TICKET_TYPE_ID, $ticket_type_id );
		$parent_order->method( 'get_item' )->with( $original_item_id )->willReturn( $original_item );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );

		Functions\when( 'add_post_meta' )->justReturn( 1 );

		// Attendee not found.
		$this->attendee_order
			->expects( $this->once() )
			->method( 'find_by_order_and_occurrence' )
			->with( $order_id, $occurrence_id )
			->willReturn( null );

		// No updates should occur.
		$this->attendee_repo->expects( $this->never() )->method( 'update_status' );
		$this->attendee_order->expects( $this->never() )->method( 'update_quantity' );
		$this->ticket_repo->expects( $this->never() )->method( 'cancel_all_tickets_for_attendee' );
		$this->ticket_repo->expects( $this->never() )->method( 'cancel_tickets_for_attendee' );
		$this->capacity_service->expects( $this->never() )->method( 'release_capacity' );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array(), $result );
	}

	// =========================================================================
	// process() Tests — Original Order Item Not Found
	// =========================================================================

	/**
	 * Test refund when original order item not found via meta reference.
	 *
	 * Scenario: Refund item has _refunded_item_id meta, but the parent
	 *           order doesn't contain that item.
	 * Expected: No attendee operations, empty result.
	 *
	 * @return void
	 */
	public function test_original_item_not_found_handled_gracefully(): void {
		$product_id = 200;
		$order_id   = 100;

		$refund       = $this->create_mock_refund( 55 );
		$parent_order = $this->create_mock_order( $order_id, 'processing' );

		$refund_item = $this->create_mock_order_item( 407, $product_id, -1 );
		$refund_item->add_meta_data( '_refunded_item_id', 999 );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		// Parent order doesn't have item 999.
		$parent_order->method( 'get_item' )->with( 999 )->willReturn( null );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );

		Functions\when( 'add_post_meta' )->justReturn( 1 );

		// No attendee operations.
		$this->attendee_order->expects( $this->never() )->method( 'find_by_order_and_occurrence' );
		$this->capacity_service->expects( $this->never() )->method( 'release_capacity' );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array(), $result );
	}

	/**
	 * Test refund item without _refunded_item_id falls back to product ID match.
	 *
	 * Scenario: Refund item has no _refunded_item_id meta, so the processor
	 *           falls back to find_item_id_by_product using the parent order items.
	 * Expected: Finds original item by product ID, processes normally.
	 *
	 * @return void
	 */
	public function test_fallback_to_product_id_match(): void {
		$product_id     = 200;
		$occurrence_id  = 10;
		$ticket_type_id = 5;
		$order_id       = 100;
		$original_item_id = 301;

		$refund       = $this->create_mock_refund( 56 );
		$parent_order = $this->create_mock_order( $order_id, 'processing' );

		// Refund item: no _refunded_item_id meta.
		$refund_item = $this->create_mock_order_item( 408, $product_id, -1 );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		// Original order item found by product ID match.
		$original_item = $this->create_mock_order_item( $original_item_id, $product_id, 2 );
		$original_item->add_meta_data( MetaKeys::OCCURRENCE_ID, $occurrence_id );
		$original_item->add_meta_data( MetaKeys::TICKET_TYPE_ID, $ticket_type_id );

		// get_items returns order items for fallback search.
		$parent_order->method( 'get_items' )->willReturn( array( $original_item ) );
		$parent_order->method( 'get_item' )->with( $original_item_id )->willReturn( $original_item );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );

		Functions\when( 'add_post_meta' )->justReturn( 1 );

		$attendee = AttendeeFactory::create(
			array(
				'id'            => 4,
				'wc_order_id'   => $order_id,
				'occurrence_id' => $occurrence_id,
				'quantity'      => 2,
			)
		);

		$this->attendee_order
			->expects( $this->once() )
			->method( 'find_by_order_and_occurrence' )
			->willReturn( $attendee );

		// Partial refund: 2 - 1 = 1.
		$this->attendee_order
			->expects( $this->once() )
			->method( 'update_quantity' )
			->with( 4, 1 );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'cancel_tickets_for_attendee' )
			->with( 4, 1 );

		$this->capacity_service
			->expects( $this->once() )
			->method( 'release_capacity' )
			->with( $ticket_type_id, 1 );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array( $ticket_type_id ), $result );
	}

	/**
	 * Test fallback search finds no matching product in order items.
	 *
	 * Scenario: Refund item has no _refunded_item_id meta, and no order
	 *           item matches the product ID.
	 * Expected: Gracefully skipped, empty result.
	 *
	 * @return void
	 */
	public function test_fallback_no_product_match_handled_gracefully(): void {
		$product_id = 200;
		$order_id   = 100;

		$refund       = $this->create_mock_refund( 57 );
		$parent_order = $this->create_mock_order( $order_id, 'processing' );

		// Refund item: no _refunded_item_id, product_id 200.
		$refund_item = $this->create_mock_order_item( 409, $product_id, -1 );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		// Parent order has items but none match product_id 200.
		$other_item = $this->create_mock_order_item( 310, 999, 1 );
		$parent_order->method( 'get_items' )->willReturn( array( $other_item ) );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );

		Functions\when( 'add_post_meta' )->justReturn( 1 );

		$this->attendee_order->expects( $this->never() )->method( 'find_by_order_and_occurrence' );
		$this->capacity_service->expects( $this->never() )->method( 'release_capacity' );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array(), $result );
	}

	// =========================================================================
	// process() Tests — Missing Occurrence ID
	// =========================================================================

	/**
	 * Test refund item with no occurrence ID on original item is skipped.
	 *
	 * Scenario: Original order item exists but has no occurrence_id meta.
	 * Expected: Skipped, no attendee operations.
	 *
	 * @return void
	 */
	public function test_missing_occurrence_id_is_skipped(): void {
		$product_id       = 200;
		$order_id         = 100;
		$original_item_id = 301;

		$refund       = $this->create_mock_refund( 58 );
		$parent_order = $this->create_mock_order( $order_id, 'processing' );

		$refund_item = $this->create_mock_order_item( 410, $product_id, -1 );
		$refund_item->add_meta_data( '_refunded_item_id', $original_item_id );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		// Original item has no OCCURRENCE_ID meta (defaults to empty string -> cast to 0).
		$original_item = $this->create_mock_order_item( $original_item_id, $product_id, 1 );
		$parent_order->method( 'get_item' )->with( $original_item_id )->willReturn( $original_item );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );

		Functions\when( 'add_post_meta' )->justReturn( 1 );

		$this->attendee_order->expects( $this->never() )->method( 'find_by_order_and_occurrence' );
		$this->capacity_service->expects( $this->never() )->method( 'release_capacity' );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array(), $result );
	}

	// =========================================================================
	// process() Tests — Multiple Ticket Items
	// =========================================================================

	/**
	 * Test refund with multiple ticket items processes each.
	 *
	 * Scenario: Refund has 2 different ticket products for different occurrences.
	 * Expected: Each is processed independently, both ticket_type_ids returned.
	 *
	 * @return void
	 */
	public function test_multiple_ticket_items_each_processed(): void {
		$product_id_a     = 200;
		$product_id_b     = 201;
		$occurrence_id_a  = 10;
		$occurrence_id_b  = 11;
		$ticket_type_id_a = 5;
		$ticket_type_id_b = 6;
		$order_id         = 100;
		$orig_item_id_a   = 301;
		$orig_item_id_b   = 302;

		$refund       = $this->create_mock_refund( 59 );
		$parent_order = $this->create_mock_order( $order_id, 'processing' );

		$refund_item_a = $this->create_mock_order_item( 411, $product_id_a, -1 );
		$refund_item_a->add_meta_data( '_refunded_item_id', $orig_item_id_a );

		$refund_item_b = $this->create_mock_order_item( 412, $product_id_b, -2 );
		$refund_item_b->add_meta_data( '_refunded_item_id', $orig_item_id_b );

		$refund->method( 'get_items' )->willReturn( array( $refund_item_a, $refund_item_b ) );

		$original_item_a = $this->create_mock_order_item( $orig_item_id_a, $product_id_a, 1 );
		$original_item_a->add_meta_data( MetaKeys::OCCURRENCE_ID, $occurrence_id_a );
		$original_item_a->add_meta_data( MetaKeys::TICKET_TYPE_ID, $ticket_type_id_a );

		$original_item_b = $this->create_mock_order_item( $orig_item_id_b, $product_id_b, 3 );
		$original_item_b->add_meta_data( MetaKeys::OCCURRENCE_ID, $occurrence_id_b );
		$original_item_b->add_meta_data( MetaKeys::TICKET_TYPE_ID, $ticket_type_id_b );

		$parent_order->method( 'get_item' )->willReturnMap(
			array(
				array( $orig_item_id_a, $original_item_a ),
				array( $orig_item_id_b, $original_item_b ),
			)
		);

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );

		Functions\when( 'add_post_meta' )->justReturn( 1 );

		$attendee_a = AttendeeFactory::create(
			array(
				'id'            => 10,
				'wc_order_id'   => $order_id,
				'occurrence_id' => $occurrence_id_a,
				'quantity'      => 1,
			)
		);

		$attendee_b = AttendeeFactory::create(
			array(
				'id'            => 11,
				'wc_order_id'   => $order_id,
				'occurrence_id' => $occurrence_id_b,
				'quantity'      => 3,
			)
		);

		$this->attendee_order
			->expects( $this->exactly( 2 ) )
			->method( 'find_by_order_and_occurrence' )
			->willReturnMap(
				array(
					array( $order_id, $occurrence_id_a, $attendee_a ),
					array( $order_id, $occurrence_id_b, $attendee_b ),
				)
			);

		// Attendee A: full refund (1 - 1 = 0).
		$this->attendee_repo
			->expects( $this->once() )
			->method( 'update_status' )
			->with( 10, 'refunded' );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'cancel_all_tickets_for_attendee' )
			->with( 10 );

		// Attendee B: partial refund (3 - 2 = 1).
		$this->attendee_order
			->expects( $this->once() )
			->method( 'update_quantity' )
			->with( 11, 1 );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'cancel_tickets_for_attendee' )
			->with( 11, 2 );

		// Capacity released for both.
		$this->capacity_service
			->expects( $this->exactly( 2 ) )
			->method( 'release_capacity' )
			->willReturnCallback(
				function ( int $type_id, int $qty ) use ( $ticket_type_id_a, $ticket_type_id_b ) {
					if ( $type_id === $ticket_type_id_a ) {
						$this->assertSame( 1, $qty );
					} elseif ( $type_id === $ticket_type_id_b ) {
						$this->assertSame( 2, $qty );
					} else {
						$this->fail( 'Unexpected ticket_type_id: ' . $type_id );
					}
					return true;
				}
			);

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertCount( 2, $result );
		$this->assertContains( $ticket_type_id_a, $result );
		$this->assertContains( $ticket_type_id_b, $result );
	}

	/**
	 * Test duplicate ticket type IDs are deduplicated in result.
	 *
	 * Scenario: Two refund items reference the same ticket type.
	 * Expected: ticket_type_id appears only once in the result array.
	 *
	 * @return void
	 */
	public function test_duplicate_ticket_type_ids_deduplicated(): void {
		$product_id_a     = 200;
		$product_id_b     = 201;
		$occurrence_id_a  = 10;
		$occurrence_id_b  = 11;
		$ticket_type_id   = 5; // Same ticket type for both.
		$order_id         = 100;
		$orig_item_id_a   = 301;
		$orig_item_id_b   = 302;

		$refund       = $this->create_mock_refund( 60 );
		$parent_order = $this->create_mock_order( $order_id, 'processing' );

		$refund_item_a = $this->create_mock_order_item( 413, $product_id_a, -1 );
		$refund_item_a->add_meta_data( '_refunded_item_id', $orig_item_id_a );

		$refund_item_b = $this->create_mock_order_item( 414, $product_id_b, -1 );
		$refund_item_b->add_meta_data( '_refunded_item_id', $orig_item_id_b );

		$refund->method( 'get_items' )->willReturn( array( $refund_item_a, $refund_item_b ) );

		$original_item_a = $this->create_mock_order_item( $orig_item_id_a, $product_id_a, 1 );
		$original_item_a->add_meta_data( MetaKeys::OCCURRENCE_ID, $occurrence_id_a );
		$original_item_a->add_meta_data( MetaKeys::TICKET_TYPE_ID, $ticket_type_id );

		$original_item_b = $this->create_mock_order_item( $orig_item_id_b, $product_id_b, 1 );
		$original_item_b->add_meta_data( MetaKeys::OCCURRENCE_ID, $occurrence_id_b );
		$original_item_b->add_meta_data( MetaKeys::TICKET_TYPE_ID, $ticket_type_id );

		$parent_order->method( 'get_item' )->willReturnMap(
			array(
				array( $orig_item_id_a, $original_item_a ),
				array( $orig_item_id_b, $original_item_b ),
			)
		);

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		Functions\when( 'add_post_meta' )->justReturn( 1 );

		$attendee_a = AttendeeFactory::create(
			array(
				'id'            => 20,
				'occurrence_id' => $occurrence_id_a,
				'quantity'      => 1,
			)
		);
		$attendee_b = AttendeeFactory::create(
			array(
				'id'            => 21,
				'occurrence_id' => $occurrence_id_b,
				'quantity'      => 1,
			)
		);

		$this->attendee_order->method( 'find_by_order_and_occurrence' )
			->willReturnMap(
				array(
					array( $order_id, $occurrence_id_a, $attendee_a ),
					array( $order_id, $occurrence_id_b, $attendee_b ),
				)
			);

		$result = $this->processor->process( $refund, $parent_order );

		// Same ticket type should appear only once (deduplication via array_keys).
		$this->assertSame( array( $ticket_type_id ), $result );
	}

	// =========================================================================
	// process() Tests — Zero Quantity Refund Item
	// =========================================================================

	/**
	 * Test refund item with zero quantity is skipped.
	 *
	 * Scenario: Refund item has quantity 0 (e.g., amount-only refund).
	 * Expected: Skipped in get_refund_ticket_items generator.
	 *
	 * @return void
	 */
	public function test_zero_quantity_refund_item_skipped(): void {
		$product_id = 200;

		$refund       = $this->create_mock_refund( 61 );
		$parent_order = $this->create_mock_order( 100, 'processing' );

		$refund_item = $this->create_mock_order_item( 415, $product_id, 0 );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );

		Functions\when( 'add_post_meta' )->justReturn( 1 );

		$this->attendee_order->expects( $this->never() )->method( 'find_by_order_and_occurrence' );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array(), $result );
	}

	// =========================================================================
	// process() Tests — No Ticket Type ID (Capacity Not Released)
	// =========================================================================

	/**
	 * Test refund item with no ticket_type_id skips capacity release.
	 *
	 * Scenario: Original order item has occurrence_id but no ticket_type_id.
	 * Expected: Attendee is still updated, but capacity release is skipped.
	 *           The result still includes ticket_type_id=0 in the return.
	 *
	 * @return void
	 */
	public function test_no_ticket_type_id_skips_capacity_release(): void {
		$product_id       = 200;
		$occurrence_id    = 10;
		$order_id         = 100;
		$original_item_id = 301;

		$refund       = $this->create_mock_refund( 62 );
		$parent_order = $this->create_mock_order( $order_id, 'processing' );

		$refund_item = $this->create_mock_order_item( 416, $product_id, -1 );
		$refund_item->add_meta_data( '_refunded_item_id', $original_item_id );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		// Original item has occurrence but no ticket_type_id (empty string -> 0).
		$original_item = $this->create_mock_order_item( $original_item_id, $product_id, 1 );
		$original_item->add_meta_data( MetaKeys::OCCURRENCE_ID, $occurrence_id );
		$parent_order->method( 'get_item' )->with( $original_item_id )->willReturn( $original_item );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		Functions\when( 'add_post_meta' )->justReturn( 1 );

		$attendee = AttendeeFactory::create(
			array(
				'id'            => 5,
				'wc_order_id'   => $order_id,
				'occurrence_id' => $occurrence_id,
				'quantity'      => 1,
			)
		);

		$this->attendee_order->method( 'find_by_order_and_occurrence' )->willReturn( $attendee );

		// Attendee is still cancelled.
		$this->attendee_repo
			->expects( $this->once() )
			->method( 'update_status' )
			->with( 5, 'refunded' );

		// Capacity release is NOT called (ticket_type_id is 0).
		$this->capacity_service
			->expects( $this->never() )
			->method( 'release_capacity' );

		$result = $this->processor->process( $refund, $parent_order );

		// ticket_type_id 0 is falsy, so it won't be in the result.
		// The condition `$result && $result['ticket_type_id']` checks truthiness.
		$this->assertSame( array(), $result );
	}

	// =========================================================================
	// process() Tests — do_action Hook Fired
	// =========================================================================

	/**
	 * Test TICKETS_REFUNDED action is fired with correct arguments.
	 *
	 * @return void
	 */
	public function test_tickets_refunded_action_fired(): void {
		$product_id       = 200;
		$occurrence_id    = 10;
		$ticket_type_id   = 5;
		$order_id         = 100;
		$refund_id        = 63;
		$original_item_id = 301;

		$refund       = $this->create_mock_refund( $refund_id );
		$parent_order = $this->create_mock_order( $order_id, 'processing' );

		$refund_item = $this->create_mock_order_item( 417, $product_id, -2 );
		$refund_item->add_meta_data( '_refunded_item_id', $original_item_id );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		$original_item = $this->create_mock_order_item( $original_item_id, $product_id, 5 );
		$original_item->add_meta_data( MetaKeys::OCCURRENCE_ID, $occurrence_id );
		$original_item->add_meta_data( MetaKeys::TICKET_TYPE_ID, $ticket_type_id );
		$parent_order->method( 'get_item' )->with( $original_item_id )->willReturn( $original_item );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		Functions\when( 'add_post_meta' )->justReturn( 1 );

		$attendee = AttendeeFactory::create(
			array(
				'id'            => 6,
				'wc_order_id'   => $order_id,
				'occurrence_id' => $occurrence_id,
				'quantity'      => 5,
			)
		);

		$this->attendee_order->method( 'find_by_order_and_occurrence' )->willReturn( $attendee );

		// Capture do_action calls to verify the hook fires with correct args.
		// Brain Monkey's Actions\expectDone conflicts with the base class do_action stub,
		// so we override the stub and capture args directly.
		$captured_actions = array();
		Functions\when( 'do_action' )->alias(
			function () use ( &$captured_actions ) {
				$captured_actions[] = func_get_args();
			}
		);

		$this->processor->process( $refund, $parent_order );

		// Find the TICKETS_REFUNDED action call.
		$refund_actions = array_filter(
			$captured_actions,
			fn( $args ) => $args[0] === Hooks::TICKETS_REFUNDED
		);

		$this->assertCount( 1, $refund_actions, 'TICKETS_REFUNDED action should fire exactly once.' );

		$action_args = array_values( $refund_actions )[0];
		// Args: hook_name, attendee, refunded_qty, new_quantity, parent_order, refund.
		$this->assertSame( Hooks::TICKETS_REFUNDED, $action_args[0] );
		$this->assertSame( $attendee, $action_args[1] );
		$this->assertSame( 2, $action_args[2] );   // refunded_qty
		$this->assertSame( 3, $action_args[3] );   // new_quantity = max(0, 5-2)
		$this->assertSame( $parent_order, $action_args[4] );
		$this->assertSame( $refund, $action_args[5] );
	}

	// =========================================================================
	// process() Tests — Refund with Non-Product Items
	// =========================================================================

	/**
	 * Test non-WC_Order_Item_Product items in refund are skipped.
	 *
	 * Scenario: Refund contains an item that is not WC_Order_Item_Product
	 *           (e.g., a shipping refund or fee refund).
	 * Expected: Item is ignored in the generator.
	 *
	 * @return void
	 */
	public function test_non_product_refund_items_skipped(): void {
		$refund       = $this->create_mock_refund( 64 );
		$parent_order = $this->create_mock_order( 100, 'processing' );

		// Return a non-WC_Order_Item_Product object.
		$non_product_item = $this->createMock( \WC_Order_Item::class );
		$refund->method( 'get_items' )->willReturn( array( $non_product_item ) );

		Functions\when( 'add_post_meta' )->justReturn( 1 );

		$this->product_manager->expects( $this->never() )->method( 'is_event_ticket' );
		$this->attendee_order->expects( $this->never() )->method( 'find_by_order_and_occurrence' );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array(), $result );
	}

	// =========================================================================
	// process() Tests — Refund Quantity Exceeds Attendee Quantity
	// =========================================================================

	/**
	 * Test refund quantity larger than attendee quantity caps at zero.
	 *
	 * Scenario: Attendee has quantity 2, refund is for 5.
	 * Expected: new_quantity = max(0, 2 - 5) = 0, treated as full refund.
	 *
	 * @return void
	 */
	public function test_refund_quantity_exceeding_attendee_caps_at_zero(): void {
		$product_id       = 200;
		$occurrence_id    = 10;
		$ticket_type_id   = 5;
		$order_id         = 100;
		$original_item_id = 301;

		$refund       = $this->create_mock_refund( 65 );
		$parent_order = $this->create_mock_order( $order_id, 'processing' );

		// Refund qty: -5, but attendee only has 2.
		$refund_item = $this->create_mock_order_item( 418, $product_id, -5 );
		$refund_item->add_meta_data( '_refunded_item_id', $original_item_id );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		$original_item = $this->create_mock_order_item( $original_item_id, $product_id, 2 );
		$original_item->add_meta_data( MetaKeys::OCCURRENCE_ID, $occurrence_id );
		$original_item->add_meta_data( MetaKeys::TICKET_TYPE_ID, $ticket_type_id );
		$parent_order->method( 'get_item' )->with( $original_item_id )->willReturn( $original_item );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		Functions\when( 'add_post_meta' )->justReturn( 1 );

		$attendee = AttendeeFactory::create(
			array(
				'id'            => 7,
				'wc_order_id'   => $order_id,
				'occurrence_id' => $occurrence_id,
				'quantity'      => 2,
			)
		);

		$this->attendee_order->method( 'find_by_order_and_occurrence' )->willReturn( $attendee );

		// max(0, 2 - 5) = 0 -> full refund path.
		$this->attendee_repo
			->expects( $this->once() )
			->method( 'update_status' )
			->with( 7, 'refunded' );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'cancel_all_tickets_for_attendee' )
			->with( 7 );

		// Capacity released for the full refunded qty (5), not capped.
		$this->capacity_service
			->expects( $this->once() )
			->method( 'release_capacity' )
			->with( $ticket_type_id, 5 );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array( $ticket_type_id ), $result );
	}

	// =========================================================================
	// process() Tests — Empty Refund (No Items)
	// =========================================================================

	/**
	 * Test refund with no items returns empty array.
	 *
	 * @return void
	 */
	public function test_empty_refund_returns_empty(): void {
		$refund       = $this->create_mock_refund( 66 );
		$parent_order = $this->create_mock_order( 100, 'processing' );

		$refund->method( 'get_items' )->willReturn( array() );

		Functions\when( 'add_post_meta' )->justReturn( 1 );

		$result = $this->processor->process( $refund, $parent_order );

		$this->assertSame( array(), $result );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Create a mock WC_Order_Refund.
	 *
	 * @param int $refund_id Refund ID.
	 * @return \WC_Order_Refund|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_refund( int $refund_id ) {
		$refund = $this->createMock( \WC_Order_Refund::class );
		$refund->method( 'get_id' )->willReturn( $refund_id );

		return $refund;
	}

	/**
	 * Create a mock WC_Order.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status   Order status.
	 * @return \WC_Order|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_order( int $order_id, string $status = 'processing' ) {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( $order_id );
		$order->method( 'get_status' )->willReturn( $status );

		return $order;
	}

	/**
	 * Create a mock WC_Order_Item_Product using the WooCommerceFactory.
	 *
	 * Uses the real MockWCOrderItemProduct from the factory, which extends
	 * neither the bootstrap WC_Order_Item_Product nor WC_Order_Item.
	 * Since the source code checks `instanceof \WC_Order_Item_Product`,
	 * we use createMock for type satisfaction and delegate meta to a real store.
	 *
	 * @param int $item_id    Item ID.
	 * @param int $product_id Product ID.
	 * @param int $quantity   Quantity (negative for refund items).
	 * @return \WC_Order_Item_Product
	 */
	private function create_mock_order_item( int $item_id, int $product_id, int $quantity ): \WC_Order_Item_Product {
		$item = new \WC_Order_Item_Product();

		// Use reflection to set protected properties on the bootstrap mock class.
		$ref = new \ReflectionClass( $item );

		$id_prop = $ref->getProperty( 'id' );
		$id_prop->setValue( $item, $item_id );

		$product_prop = $ref->getProperty( 'product_id' );
		$product_prop->setValue( $item, $product_id );

		$qty_prop = $ref->getProperty( 'quantity' );
		$qty_prop->setValue( $item, $quantity );

		return $item;
	}
}
