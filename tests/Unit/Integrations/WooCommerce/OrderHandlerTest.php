<?php
/**
 * OrderHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Integrations\WooCommerce\OrderHandler;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\TicketRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\CapacityService;
use NetterTechEvents\Services\TicketCodeGenerator;
use NetterTechEvents\Tests\Factories\AttendeeFactory;

/**
 * Test OrderHandler functionality.
 *
 * Tests the refactored public methods that accept WC_Order objects directly,
 * enabling unit testing with PHPUnit mocks without needing full WooCommerce.
 */
class OrderHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * OrderHandler instance.
	 *
	 * @var OrderHandler
	 */
	private OrderHandler $handler;

	/**
	 * Mock ProductManager.
	 *
	 * @var ProductManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $product_manager;

	/**
	 * Mock AttendeeRepository.
	 *
	 * @var AttendeeRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $attendee_repo;

	/**
	 * Mock TicketTypeRepository.
	 *
	 * @var TicketTypeRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * Mock TicketRepository.
	 *
	 * @var TicketRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_repo;

	/**
	 * Mock TicketCodeGenerator.
	 *
	 * @var TicketCodeGenerator|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $code_generator;

	/**
	 * Mock OccurrenceRepository.
	 *
	 * @var OccurrenceRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Mock CapacityService.
	 *
	 * @var CapacityService|\PHPUnit\Framework\MockObject\MockObject
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
		$this->attendee_repo    = $this->createMock( AttendeeRepository::class );
		$this->ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$this->ticket_repo      = $this->createMock( TicketRepository::class );
		$this->code_generator   = $this->createMock( TicketCodeGenerator::class );
		$this->occurrence_repo  = $this->createMock( OccurrenceRepository::class );
		$this->capacity_service = $this->createMock( CapacityService::class );

		$this->handler = new OrderHandler(
			$this->attendee_repo,
			$this->ticket_type_repo,
			$this->ticket_repo,
			$this->code_generator,
			$this->occurrence_repo,
			$this->capacity_service,
			$this->product_manager,
			$this->createMock( \NetterTechEvents\Services\AttendeeFieldService::class )
		);

		AttendeeFactory::reset();
	}

	// =========================================================================
	// get_attendees_for_order Tests
	// =========================================================================

	/**
	 * Test get_attendees_for_order delegates to repository.
	 *
	 * @return void
	 */
	public function test_get_attendees_for_order_delegates_to_repo(): void {
		$attendees = array(
			AttendeeFactory::create( array( 'wc_order_id' => 123 ) ),
			AttendeeFactory::create( array( 'wc_order_id' => 123 ) ),
		);

		$this->attendee_repo
			->expects( $this->once() )
			->method( 'find_all_by_order' )
			->with( 123 )
			->willReturn( $attendees );

		$result = $this->handler->get_attendees_for_order( 123 );

		$this->assertCount( 2, $result );
		$this->assertSame( $attendees, $result );
	}

	/**
	 * Test get_attendees_for_order returns empty array when no attendees.
	 *
	 * @return void
	 */
	public function test_get_attendees_for_order_returns_empty_when_none(): void {
		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$result = $this->handler->get_attendees_for_order( 999 );

		$this->assertEmpty( $result );
	}

	/**
	 * Test get_attendees_for_order returns Attendee instances.
	 *
	 * @return void
	 */
	public function test_get_attendees_for_order_returns_attendee_instances(): void {
		$attendees = array(
			AttendeeFactory::create( array( 'id' => 1, 'name' => 'Alice' ) ),
			AttendeeFactory::create( array( 'id' => 2, 'name' => 'Bob' ) ),
			AttendeeFactory::create( array( 'id' => 3, 'name' => 'Charlie' ) ),
		);

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( $attendees );

		$result = $this->handler->get_attendees_for_order( 100 );

		$this->assertCount( 3, $result );
		$this->assertContainsOnlyInstancesOf( Attendee::class, $result );
		$this->assertSame( 'Alice', $result[0]->name );
		$this->assertSame( 'Bob', $result[1]->name );
		$this->assertSame( 'Charlie', $result[2]->name );
	}

	// =========================================================================
	// order_has_tickets Tests
	// Note: order_has_tickets requires WC_Order objects and wc_get_order(),
	// which need full WooCommerce environment. These tests are better
	// suited for integration tests.
	// =========================================================================

	/**
	 * Test order_has_tickets returns false when wc_get_order returns null.
	 *
	 * @return void
	 */
	public function test_order_has_tickets_returns_false_for_invalid_order(): void {
		// wc_get_order is stubbed to return null by default.
		$result = $this->handler->order_has_tickets( 999 );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test OrderHandler can be instantiated with all dependencies.
	 *
	 * @return void
	 */
	public function test_handler_can_be_instantiated(): void {
		$handler = new OrderHandler(
			$this->attendee_repo,
			$this->ticket_type_repo,
			$this->ticket_repo,
			$this->code_generator,
			$this->occurrence_repo,
			$this->capacity_service,
			$this->product_manager,
			$this->createMock( \NetterTechEvents\Services\AttendeeFieldService::class )
		);

		$this->assertInstanceOf( OrderHandler::class, $handler );
	}

	// =========================================================================
	// void_attendees_for_order Tests (accepts WC_Order object directly)
	// =========================================================================

	/**
	 * Test void_attendees_for_order updates attendee status to voided.
	 *
	 * @return void
	 */
	public function test_void_attendees_for_order_updates_status(): void {
		$attendee = AttendeeFactory::create(
			array(
				'id'             => 1,
				'wc_order_id'    => 100,
				'ticket_type_id' => 5,
				'quantity'       => 2,
			)
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->with( 100 )
			->willReturn( array( $attendee ) );

		$this->attendee_repo
			->expects( $this->once() )
			->method( 'update_status' )
			->with( 1, 'voided' );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'cancel_all_tickets_for_attendee' )
			->with( 1 );

		$this->handler->void_attendees_for_order( $order );
	}

	/**
	 * Test void_attendees_for_order releases capacity.
	 *
	 * @return void
	 */
	public function test_void_attendees_for_order_releases_capacity(): void {
		$attendee = AttendeeFactory::create(
			array(
				'id'             => 1,
				'wc_order_id'    => 100,
				'ticket_type_id' => 5,
				'quantity'       => 3,
			)
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( array( $attendee ) );

		// void_attendees_for_order uses recalculate_sold_count (idempotent
		// reconciliation from source data) instead of release_capacity
		// (relative decrement) to avoid double-decrement when both
		// woocommerce_refund_created and woocommerce_order_status_refunded
		// fire on the same full refund.
		$this->ticket_type_repo
			->expects( $this->once() )
			->method( 'recalculate_sold_count' )
			->with( 5 );

		$this->product_manager
			->expects( $this->once() )
			->method( 'sync_stock' )
			->with( 5 );

		$this->handler->void_attendees_for_order( $order );
	}

	/**
	 * Test void_attendees_for_order handles multiple attendees.
	 *
	 * @return void
	 */
	public function test_void_attendees_for_order_handles_multiple_attendees(): void {
		$attendees = array(
			AttendeeFactory::create(
				array(
					'id'             => 1,
					'ticket_type_id' => 5,
					'quantity'       => 2,
				)
			),
			AttendeeFactory::create(
				array(
					'id'             => 2,
					'ticket_type_id' => 5,
					'quantity'       => 1,
				)
			),
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( $attendees );

		$this->attendee_repo
			->expects( $this->exactly( 2 ) )
			->method( 'update_status' );

		$this->ticket_repo
			->expects( $this->exactly( 2 ) )
			->method( 'cancel_all_tickets_for_attendee' );

		// Reconciles sold_count from source data (once per ticket type).
		$this->ticket_type_repo
			->expects( $this->once() )
			->method( 'recalculate_sold_count' )
			->with( 5 );

		$this->handler->void_attendees_for_order( $order );
	}

	/**
	 * Test void_attendees_for_order handles empty attendees list.
	 *
	 * @return void
	 */
	public function test_void_attendees_for_order_handles_no_attendees(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->attendee_repo
			->expects( $this->never() )
			->method( 'update_status' );

		$this->capacity_service
			->expects( $this->never() )
			->method( 'release_capacity' );

		$this->handler->void_attendees_for_order( $order );
	}

	// =========================================================================
	// process_order_object Tests (accepts WC_Order object directly)
	// =========================================================================

	/**
	 * Test process_order_object skips when order meta already set (backward compat).
	 *
	 * Orders processed by older plugin versions have _nte_attendees_created = 'yes'
	 * set via WC CRUD. The backward-compat check catches this before the atomic claim.
	 *
	 * @return void
	 */
	public function test_process_order_object_skips_already_processed_via_meta(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );

		// Order meta already set — backward compat path.
		$order->method( 'get_meta' )->willReturn( 'yes' );

		// Should not process items if meta already set.
		$order->expects( $this->never() )
			->method( 'get_items' );

		$this->handler->process_order_object( $order );
	}

	/**
	 * Test process_order_object skips when atomic claim fails.
	 *
	 * Atomic INSERT IGNORE into wp_options returns 0 (rows_affected)
	 * when the option_name already exists — another request got there first.
	 *
	 * @return void
	 */
	public function test_process_order_object_skips_already_processed_via_lock(): void {
		global $wpdb;
		$original_wpdb = $wpdb;

		// Mock $wpdb->query() to return 0 (INSERT IGNORE found existing row).
		$mock_wpdb          = \Mockery::mock( 'wpdb' );
		$mock_wpdb->prefix  = 'wp_';
		$mock_wpdb->options = 'wp_options';
		$mock_wpdb->shouldReceive( 'prepare' )->andReturn( 'prepared_query' );
		$mock_wpdb->shouldReceive( 'query' )->andReturn( 0 );
		$wpdb = $mock_wpdb;

		try {
			$order = $this->createMock( \WC_Order::class );
			$order->method( 'get_id' )->willReturn( 100 );

			// get_meta returns '' (no backward-compat meta), falls through to atomic claim.
			$order->method( 'get_meta' )->willReturn( '' );

			// Should not process items if atomic claim fails.
			$order->expects( $this->never() )
				->method( 'get_items' );

			$this->handler->process_order_object( $order );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test process_order_object claims processing atomically.
	 *
	 * Uses INSERT IGNORE into wp_options for atomic idempotency.
	 * The default mock $wpdb->query() returns 1 (success), so no override needed.
	 *
	 * @return void
	 */
	public function test_process_order_object_marks_order_processed(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( 'john@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( '555-1234' );

		// Default mock $wpdb->query() returns 1 (claim succeeded = not yet processed).
		$order->expects( $this->atLeastOnce() )
			->method( 'get_items' )
			->willReturn( array() );

		$this->handler->process_order_object( $order );
	}

	/**
	 * Test process_order_object skips non-ticket products.
	 *
	 * @return void
	 */
	public function test_process_order_object_skips_non_ticket_products(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 99 );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );
		$order->method( 'get_items' )->willReturn( array( $item ) );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( 'john@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( '' );

		$this->product_manager
			->method( 'is_event_ticket' )
			->with( 99 )
			->willReturn( false );

		// Should not create attendees for non-ticket products.
		$this->attendee_repo
			->expects( $this->never() )
			->method( 'save' );

		$this->handler->process_order_object( $order );
	}

	// =========================================================================
	// order_has_tickets with WC_Order object Tests
	// =========================================================================

	/**
	 * Test order_has_tickets accepts WC_Order object directly.
	 *
	 * @return void
	 */
	public function test_order_has_tickets_accepts_order_object(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 42 );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array( $item ) );

		$this->product_manager
			->method( 'is_event_ticket' )
			->with( 42 )
			->willReturn( true );

		$result = $this->handler->order_has_tickets( $order );

		$this->assertTrue( $result );
	}

	/**
	 * Test order_has_tickets returns false when no ticket items.
	 *
	 * @return void
	 */
	public function test_order_has_tickets_returns_false_no_tickets(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 42 );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array( $item ) );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( false );

		$result = $this->handler->order_has_tickets( $order );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// get_ticket_summary Tests
	// =========================================================================

	/**
	 * Test get_ticket_summary returns empty array for invalid order.
	 *
	 * @return void
	 */
	public function test_get_ticket_summary_returns_empty_for_invalid_order(): void {
		// wc_get_order returns null by default for invalid orders.
		$result = $this->handler->get_ticket_summary( 999 );

		$this->assertSame( array(), $result );
	}

	/**
	 * Test get_ticket_summary accepts WC_Order object directly.
	 *
	 * @return void
	 */
	public function test_get_ticket_summary_accepts_order_object(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array() );

		$result = $this->handler->get_ticket_summary( $order );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_ticket_summary returns empty for non-ticket items.
	 *
	 * @return void
	 */
	public function test_get_ticket_summary_skips_non_ticket_items(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 42 );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array( $item ) );

		$this->product_manager
			->method( 'is_event_ticket' )
			->with( 42 )
			->willReturn( false );

		$result = $this->handler->get_ticket_summary( $order );

		$this->assertEmpty( $result );
	}

	/**
	 * Test get_ticket_summary returns ticket data.
	 *
	 * @return void
	 */
	public function test_get_ticket_summary_returns_ticket_data(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 42 );
		$item->method( 'get_quantity' )->willReturn( 2 );
		$item->method( 'get_total' )->willReturn( '50.00' );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array( $item ) );

		$this->product_manager
			->method( 'is_event_ticket' )
			->with( 42 )
			->willReturn( true );

		$this->product_manager
			->method( 'get_occurrence_from_product' )
			->with( 42 )
			->willReturn( null );

		$this->product_manager
			->method( 'get_ticket_type_from_product' )
			->with( 42 )
			->willReturn( null );

		$result = $this->handler->get_ticket_summary( $order );

		$this->assertCount( 1, $result );
		$this->assertSame( 2, $result[0]['quantity'] );
		$this->assertSame( '50.00', $result[0]['total'] );
	}

	// =========================================================================
	// Handler Wrapper Method Tests
	// =========================================================================

	/**
	 * Test handle_payment_complete calls wc_get_order.
	 *
	 * @return void
	 */
	public function test_handle_payment_complete_calls_wc_get_order(): void {
		$called_with = null;

		\Brain\Monkey\Functions\when( 'wc_get_order' )
			->alias(
				function ( $order_id ) use ( &$called_with ) {
					$called_with = $order_id;
					return null;
				}
			);

		$this->handler->handle_payment_complete( 123 );

		$this->assertSame( 123, $called_with );
	}

	/**
	 * Test handle_order_completed calls wc_get_order.
	 *
	 * @return void
	 */
	public function test_handle_order_completed_calls_wc_get_order(): void {
		$called_with = null;

		\Brain\Monkey\Functions\when( 'wc_get_order' )
			->alias(
				function ( $order_id ) use ( &$called_with ) {
					$called_with = $order_id;
					return null;
				}
			);

		$this->handler->handle_order_completed( 456 );

		$this->assertSame( 456, $called_with );
	}

	/**
	 * Test handle_order_processing calls wc_get_order.
	 *
	 * @return void
	 */
	public function test_handle_order_processing_calls_wc_get_order(): void {
		$called_with = null;

		\Brain\Monkey\Functions\when( 'wc_get_order' )
			->alias(
				function ( $order_id ) use ( &$called_with ) {
					$called_with = $order_id;
					return null;
				}
			);

		$this->handler->handle_order_processing( 789 );

		$this->assertSame( 789, $called_with );
	}

	/**
	 * Test handle_order_cancelled calls wc_get_order.
	 *
	 * @return void
	 */
	public function test_handle_order_cancelled_calls_wc_get_order(): void {
		$called_with = null;

		\Brain\Monkey\Functions\when( 'wc_get_order' )
			->alias(
				function ( $order_id ) use ( &$called_with ) {
					$called_with = $order_id;
					return null;
				}
			);

		$this->handler->handle_order_cancelled( 111 );

		$this->assertSame( 111, $called_with );
	}

	/**
	 * Test handle_order_refunded calls wc_get_order.
	 *
	 * @return void
	 */
	public function test_handle_order_refunded_calls_wc_get_order(): void {
		$called_with = null;

		\Brain\Monkey\Functions\when( 'wc_get_order' )
			->alias(
				function ( $order_id ) use ( &$called_with ) {
					$called_with = $order_id;
					return null;
				}
			);

		$this->handler->handle_order_refunded( 222 );

		$this->assertSame( 222, $called_with );
	}

	/**
	 * Test handle_order_failed voids the order's attendees.
	 *
	 * Regression guard for NTE-202. Attendees are created once an order reaches
	 * processing or completed, so a later failure — a declined capture, a COD
	 * order failed at the door — used to leave a confirmed attendee holding a
	 * checkable-in ticket and occupying capacity, because 'failed' was the one
	 * unpaid terminal status with no listener. Asserts the void actually happens
	 * rather than merely that the order was looked up.
	 *
	 * @return void
	 */
	public function test_handle_order_failed_voids_attendees(): void {
		$attendee = AttendeeFactory::create(
			array(
				'id'             => 7,
				'wc_order_id'    => 333,
				'ticket_type_id' => 5,
				'quantity'       => 1,
			)
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 333 );

		\Brain\Monkey\Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->with( 333 )
			->willReturn( array( $attendee ) );

		$this->attendee_repo
			->expects( $this->once() )
			->method( 'update_status' )
			->with( 7, 'voided' );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'cancel_all_tickets_for_attendee' )
			->with( 7 );

		$this->handler->handle_order_failed( 333 );
	}

	// =========================================================================
	// handle_refund_created Tests
	// =========================================================================

	/**
	 * Test handle_refund_created returns early for invalid refund.
	 *
	 * @return void
	 */
	public function test_handle_refund_created_returns_early_for_invalid_refund(): void {
		\Brain\Monkey\Functions\when( 'wc_get_order' )
			->justReturn( null );

		// Should not throw - just return early.
		$this->handler->handle_refund_created( 999, array() );

		$this->assertTrue( true ); // No exception thrown.
	}

	/**
	 * Test handle_refund_created returns early for non-refund object.
	 *
	 * @return void
	 */
	public function test_handle_refund_created_returns_early_for_non_refund(): void {
		// Return a regular order instead of refund.
		$order = $this->createMock( \WC_Order::class );

		\Brain\Monkey\Functions\when( 'wc_get_order' )
			->justReturn( $order );

		// Should not throw - just return early.
		$this->handler->handle_refund_created( 999, array() );

		$this->assertTrue( true ); // No exception thrown.
	}

	// =========================================================================
	// process_refund Tests
	// =========================================================================

	/**
	 * Test process_refund skips already processed refunds.
	 *
	 * Uses HPOS-compatible $order->get_meta() idempotency check.
	 *
	 * @return void
	 */
	public function test_process_refund_skips_already_processed(): void {
		$refund = $this->createMock( \WC_Order_Refund::class );
		$refund->method( 'get_id' )->willReturn( 50 );

		$parent_order = $this->createMock( \WC_Order::class );
		$parent_order->method( 'get_id' )->willReturn( 100 );
		$parent_order->method( 'get_status' )->willReturn( 'completed' );

		// Order meta indicates this refund was already processed.
		$parent_order->method( 'get_meta' )->willReturn( 'yes' );

		// Should not get items if already processed.
		$refund->expects( $this->never() )
			->method( 'get_items' );

		$this->handler->process_refund( $refund, $parent_order );
	}

	/**
	 * Test process_refund skips fully refunded orders.
	 *
	 * @return void
	 */
	public function test_process_refund_skips_fully_refunded(): void {
		$refund = $this->createMock( \WC_Order_Refund::class );
		$refund->method( 'get_id' )->willReturn( 50 );

		$parent_order = $this->createMock( \WC_Order::class );
		$parent_order->method( 'get_id' )->willReturn( 100 );
		$parent_order->method( 'get_status' )->willReturn( 'refunded' );

		// add_post_meta returns true = not yet processed.
		\Brain\Monkey\Functions\when( 'add_post_meta' )
			->justReturn( true );

		// Should not process items for fully refunded orders.
		$refund->expects( $this->never() )
			->method( 'get_items' );

		$this->handler->process_refund( $refund, $parent_order );
	}

	/**
	 * Test process_refund processes partial refund items.
	 *
	 * @return void
	 */
	public function test_process_refund_processes_items(): void {
		$refund_item = $this->createMock( \WC_Order_Item_Product::class );
		$refund_item->method( 'get_product_id' )->willReturn( 42 );
		$refund_item->method( 'get_quantity' )->willReturn( -1 );
		$refund_item->method( 'get_meta' )->willReturn( '' );

		$refund = $this->createMock( \WC_Order_Refund::class );
		$refund->method( 'get_id' )->willReturn( 50 );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		$parent_order = $this->createMock( \WC_Order::class );
		$parent_order->method( 'get_id' )->willReturn( 100 );
		$parent_order->method( 'get_status' )->willReturn( 'completed' );
		$parent_order->method( 'get_items' )->willReturn( array() );

		// add_post_meta returns true = not yet processed.
		\Brain\Monkey\Functions\when( 'add_post_meta' )
			->justReturn( true );

		// Product is an event ticket.
		$this->product_manager
			->method( 'is_event_ticket' )
			->with( 42 )
			->willReturn( true );

		$this->handler->process_refund( $refund, $parent_order );

		// Assertion to make test not risky.
		$this->assertTrue( true );
	}

	// =========================================================================
	// void_attendees_for_order Aggregation Tests
	// =========================================================================

	/**
	 * Test void_attendees_for_order aggregates capacity by ticket type.
	 *
	 * @return void
	 */
	public function test_void_attendees_aggregates_capacity_by_type(): void {
		$attendees = array(
			AttendeeFactory::create(
				array(
					'id'             => 1,
					'ticket_type_id' => 5,
					'quantity'       => 2,
				)
			),
			AttendeeFactory::create(
				array(
					'id'             => 2,
					'ticket_type_id' => 6, // Different ticket type.
					'quantity'       => 3,
				)
			),
			AttendeeFactory::create(
				array(
					'id'             => 3,
					'ticket_type_id' => 5, // Same as first.
					'quantity'       => 1,
				)
			),
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( $attendees );

		// Should recalculate sold_count for each distinct ticket type.
		$recalc_calls = array();
		$this->ticket_type_repo
			->method( 'recalculate_sold_count' )
			->willReturnCallback(
				function ( $type_id ) use ( &$recalc_calls ) {
					$recalc_calls[] = $type_id;
					return true;
				}
			);

		$this->handler->void_attendees_for_order( $order );

		// Both ticket type IDs (5 and 6) should be recalculated.
		$this->assertContains( 5, $recalc_calls );
		$this->assertContains( 6, $recalc_calls );
		$this->assertCount( 2, $recalc_calls );
	}

	/**
	 * Test void_attendees_for_order skips capacity for null ticket_type_id.
	 *
	 * @return void
	 */
	public function test_void_attendees_skips_null_ticket_type(): void {
		// Create attendee manually to ensure ticket_type_id is truly null.
		$attendee                  = new Attendee();
		$attendee->id              = 1;
		$attendee->occurrence_id   = 10;
		$attendee->ticket_type_id  = null; // No ticket type.
		$attendee->wc_order_id     = 100;
		$attendee->name            = 'Test';
		$attendee->email           = 'test@example.com';
		$attendee->quantity        = 2;
		$attendee->status          = 'confirmed';

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( array( $attendee ) );

		// Should not call release_capacity for null ticket type.
		$this->capacity_service
			->expects( $this->never() )
			->method( 'release_capacity' );

		$this->handler->void_attendees_for_order( $order );
	}

	// =========================================================================
	// order_has_tickets Edge Cases
	// =========================================================================

	/**
	 * Test order_has_tickets skips non-product items.
	 *
	 * Uses stdClass as stand-in for non-WC_Order_Item_Product items.
	 *
	 * @return void
	 */
	public function test_order_has_tickets_skips_non_product_items(): void {
		// Create a non-product item (shipping, fee, etc.) using stdClass.
		$fee_item = new \stdClass();

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array( $fee_item ) );

		// Product manager should not be called for non-product items.
		$this->product_manager
			->expects( $this->never() )
			->method( 'is_event_ticket' );

		$result = $this->handler->order_has_tickets( $order );

		$this->assertFalse( $result );
	}

	/**
	 * Test order_has_tickets returns true on first ticket match.
	 *
	 * @return void
	 */
	public function test_order_has_tickets_returns_true_on_first_match(): void {
		$item1 = $this->createMock( \WC_Order_Item_Product::class );
		$item1->method( 'get_product_id' )->willReturn( 10 );

		$item2 = $this->createMock( \WC_Order_Item_Product::class );
		$item2->method( 'get_product_id' )->willReturn( 20 );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array( $item1, $item2 ) );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturnMap(
				array(
					array( 10, false ),
					array( 20, true ),
				)
			);

		$result = $this->handler->order_has_tickets( $order );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// process_order_object Capacity Validation Tests
	// =========================================================================

	/**
	 * Test process_order_object validates capacity for ticket items.
	 *
	 * @return void
	 */
	public function test_process_order_object_validates_capacity(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 42 );
		$item->method( 'get_meta' )->willReturn( '5' ); // ticket_type_id.
		$item->method( 'get_quantity' )->willReturn( 2 );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );
		$order->method( 'get_items' )->willReturn( array( $item ) );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( 'john@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( '' );

		// Not an event ticket, so should not call capacity service.
		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( false );

		$this->capacity_service
			->expects( $this->never() )
			->method( 'get_available_count' );

		$this->handler->process_order_object( $order );
	}

	// =========================================================================
	// process_order_object with Occurrence Items Tests
	// =========================================================================

	/**
	 * Test process_order_object creates attendee for occurrence item.
	 *
	 * @return void
	 */
	public function test_process_order_object_creates_attendee_for_occurrence(): void {
		$meta_values = array(
			'_nettertech_events_ticket_type_id' => 5,
			'_nettertech_events_occurrence_id'  => 10,
			'_nettertech_events_is_series_pass' => 'no',
		);

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 42 );
		$item->method( 'get_quantity' )->willReturn( 2 );
		$item->method( 'get_total' )->willReturn( 50.00 );
		$item->method( 'get_id' )->willReturn( 1001 );
		$item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );
		$order->method( 'get_items' )->willReturn( array( $item ) );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( 'john@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( '555-1234' );

		$this->product_manager
			->method( 'is_event_ticket' )
			->with( 42 )
			->willReturn( true );

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'TICKET123' );

		// Should save attendee and tickets.
		$this->attendee_repo
			->expects( $this->once() )
			->method( 'save' );

		$this->ticket_repo
			->expects( $this->exactly( 2 ) ) // quantity = 2.
			->method( 'save' );

		$this->handler->process_order_object( $order );
	}

	/**
	 * Test process_order_object skips existing attendee for occurrence.
	 *
	 * @return void
	 */
	public function test_process_order_object_skips_existing_attendee(): void {
		$meta_values = array(
			'_nettertech_events_ticket_type_id' => 5,
			'_nettertech_events_occurrence_id'  => 10,
			'_nettertech_events_is_series_pass' => 'no',
		);

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 42 );
		$item->method( 'get_quantity' )->willReturn( 2 );
		$item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );
		$order->method( 'get_items' )->willReturn( array( $item ) );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( 'john@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( '' );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		// Existing attendee for this occurrence.
		$existing = AttendeeFactory::create(
			array(
				'occurrence_id' => 10,
				'wc_order_id'   => 100,
			)
		);
		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( array( $existing ) );

		// Should NOT save new attendee.
		$this->attendee_repo
			->expects( $this->never() )
			->method( 'save' );

		$this->handler->process_order_object( $order );
	}

	/**
	 * Test process_order_object skips items without occurrence_id.
	 *
	 * @return void
	 */
	public function test_process_order_object_skips_items_without_occurrence(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 42 );
		$item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) {
				// No occurrence_id, no series pass.
				if ( '_nettertech_events_occurrence_id' === $key ) {
					return 0;
				}
				if ( '_nettertech_events_is_series_pass' === $key ) {
					return 'no';
				}
				return '';
			}
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );
		$order->method( 'get_items' )->willReturn( array( $item ) );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( 'john@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( '' );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( array() );

		// Should NOT save attendee.
		$this->attendee_repo
			->expects( $this->never() )
			->method( 'save' );

		$this->handler->process_order_object( $order );
	}

	// =========================================================================
	// Series Pass Processing Tests
	// =========================================================================

	/**
	 * Test process_order_object creates attendees for series pass.
	 *
	 * @return void
	 */
	public function test_process_order_object_creates_attendees_for_series_pass(): void {
		$meta_values = array(
			'_nettertech_events_ticket_type_id' => 5,
			'_nettertech_events_event_id'       => 99,
			'_nettertech_events_is_series_pass' => 'yes',
		);

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 42 );
		$item->method( 'get_quantity' )->willReturn( 1 );
		$item->method( 'get_total' )->willReturn( 100.00 );
		$item->method( 'get_id' )->willReturn( 1001 );
		$item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );
		$order->method( 'get_items' )->willReturn( array( $item ) );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( 'john@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( '' );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( array() );

		// Mock 3 occurrences for the event.
		$occurrence1     = new \stdClass();
		$occurrence1->id = 10;
		$occurrence2     = new \stdClass();
		$occurrence2->id = 11;
		$occurrence3     = new \stdClass();
		$occurrence3->id = 12;

		$this->occurrence_repo
			->method( 'for_event' )
			->with( 99 )
			->willReturn( array( $occurrence1, $occurrence2, $occurrence3 ) );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'TICKET123' );

		// Should create 3 attendees (one per occurrence).
		$this->attendee_repo
			->expects( $this->exactly( 3 ) )
			->method( 'save' );

		// Should create 3 tickets (1 quantity × 3 occurrences).
		$this->ticket_repo
			->expects( $this->exactly( 3 ) )
			->method( 'save' );

		$this->handler->process_order_object( $order );
	}

	/**
	 * Test process_order_object skips series pass without event_id.
	 *
	 * @return void
	 */
	public function test_process_order_object_skips_series_pass_without_event(): void {
		$meta_values = array(
			'_nettertech_events_ticket_type_id' => 5,
			'_nettertech_events_event_id'       => 0, // No event ID.
			'_nettertech_events_is_series_pass' => 'yes',
		);

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 42 );
		$item->method( 'get_quantity' )->willReturn( 1 );
		$item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );
		$order->method( 'get_items' )->willReturn( array( $item ) );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( 'john@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( '' );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( array() );

		// Should NOT fetch occurrences or create attendees.
		$this->occurrence_repo
			->expects( $this->never() )
			->method( 'for_event' );

		$this->attendee_repo
			->expects( $this->never() )
			->method( 'save' );

		$this->handler->process_order_object( $order );
	}

	// =========================================================================
	// Capacity Validation Tests
	// =========================================================================

	/**
	 * Test process_order_object validates capacity and handles issues.
	 *
	 * @return void
	 */
	public function test_process_order_object_handles_capacity_issues(): void {
		$meta_values = array(
			'_nettertech_events_ticket_type_id' => 5,
			'_nettertech_events_occurrence_id'  => 10,
			'_nettertech_events_is_series_pass' => 'no',
		);

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 42 );
		$item->method( 'get_quantity' )->willReturn( 5 );
		$item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );
		$order->method( 'get_items' )->willReturn( array( $item ) );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( 'john@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( '' );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( array() );

		// Mock ticket type.
		$ticket_type       = $this->createMock( \NetterTechEvents\Models\TicketType::class );
		$ticket_type->name = 'General Admission';
		$this->ticket_type_repo
			->method( 'find' )
			->with( 5 )
			->willReturn( $ticket_type );

		// Only 2 available but 5 requested.
		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( 2 );

		// Order should receive a note about capacity issue.
		$order->expects( $this->once() )
			->method( 'add_order_note' );

		$this->handler->process_order_object( $order );
	}

	/**
	 * Test process_order_object skips capacity check for non-ticket types.
	 *
	 * @return void
	 */
	public function test_process_order_object_skips_capacity_check_without_ticket_type(): void {
		$meta_values = array(
			'_nettertech_events_ticket_type_id' => 0, // No ticket type.
			'_nettertech_events_occurrence_id'  => 10,
			'_nettertech_events_is_series_pass' => 'no',
		);

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 42 );
		$item->method( 'get_quantity' )->willReturn( 2 );
		$item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );
		$order->method( 'get_items' )->willReturn( array( $item ) );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( 'john@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( '' );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( array() );

		// Should not check capacity for items without ticket_type_id.
		$this->capacity_service
			->expects( $this->never() )
			->method( 'get_available_count' );

		$this->handler->process_order_object( $order );
	}

	/**
	 * Test process_order_object allows unlimited capacity (null).
	 *
	 * @return void
	 */
	public function test_process_order_object_allows_unlimited_capacity(): void {
		$meta_values = array(
			'_nettertech_events_ticket_type_id' => 5,
			'_nettertech_events_occurrence_id'  => 10,
			'_nettertech_events_is_series_pass' => 'no',
		);

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 42 );
		$item->method( 'get_quantity' )->willReturn( 100 );
		$item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );
		$order->method( 'get_items' )->willReturn( array( $item ) );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( 'john@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( '' );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$ticket_type       = $this->createMock( \NetterTechEvents\Models\TicketType::class );
		$ticket_type->name = 'General Admission';
		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		// Unlimited capacity returns null.
		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null );

		// Should NOT add order note for unlimited capacity.
		$order->expects( $this->never() )
			->method( 'add_order_note' );

		$this->handler->process_order_object( $order );
	}

	// =========================================================================
	// Refund with Attendee Update Tests
	// =========================================================================

	/**
	 * Test process_refund updates attendee for partial refund.
	 *
	 * @return void
	 */
	public function test_process_refund_partial_updates_attendee_quantity(): void {
		$meta_values = array(
			'_refunded_item_id'  => 1001,
			'_nettertech_events_occurrence_id'  => 10,
			'_nettertech_events_ticket_type_id' => 5,
		);

		$refund_item = $this->createMock( \WC_Order_Item_Product::class );
		$refund_item->method( 'get_product_id' )->willReturn( 42 );
		$refund_item->method( 'get_quantity' )->willReturn( -1 ); // Refund 1.
		$refund_item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$original_item = $this->createMock( \WC_Order_Item_Product::class );
		$original_item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$refund = $this->createMock( \WC_Order_Refund::class );
		$refund->method( 'get_id' )->willReturn( 50 );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		$parent_order = $this->createMock( \WC_Order::class );
		$parent_order->method( 'get_id' )->willReturn( 100 );
		$parent_order->method( 'get_status' )->willReturn( 'completed' );
		$parent_order->method( 'get_item' )->with( 1001 )->willReturn( $original_item );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$attendee = AttendeeFactory::create(
			array(
				'id'             => 1,
				'occurrence_id'  => 10,
				'wc_order_id'    => 100,
				'quantity'       => 3,
				'ticket_type_id' => 5,
			)
		);
		$this->attendee_repo
			->method( 'find_by_order_and_occurrence' )
			->with( 100, 10 )
			->willReturn( $attendee );

		// Partial refund - should update quantity, not status.
		$this->attendee_repo
			->expects( $this->once() )
			->method( 'update_quantity' )
			->with( 1, 2 ); // 3 - 1 = 2.

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'cancel_tickets_for_attendee' )
			->with( 1, 1 );

		$this->capacity_service
			->expects( $this->once() )
			->method( 'release_capacity' )
			->with( 5, 1 );

		$this->handler->process_refund( $refund, $parent_order );
	}

	/**
	 * Test process_refund cancels attendee for full refund.
	 *
	 * @return void
	 */
	public function test_process_refund_full_cancels_attendee(): void {
		$meta_values = array(
			'_refunded_item_id'  => 1001,
			'_nettertech_events_occurrence_id'  => 10,
			'_nettertech_events_ticket_type_id' => 5,
		);

		$refund_item = $this->createMock( \WC_Order_Item_Product::class );
		$refund_item->method( 'get_product_id' )->willReturn( 42 );
		$refund_item->method( 'get_quantity' )->willReturn( -2 ); // Refund all 2.
		$refund_item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$original_item = $this->createMock( \WC_Order_Item_Product::class );
		$original_item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$refund = $this->createMock( \WC_Order_Refund::class );
		$refund->method( 'get_id' )->willReturn( 50 );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		$parent_order = $this->createMock( \WC_Order::class );
		$parent_order->method( 'get_id' )->willReturn( 100 );
		$parent_order->method( 'get_status' )->willReturn( 'completed' );
		$parent_order->method( 'get_item' )->with( 1001 )->willReturn( $original_item );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$attendee = AttendeeFactory::create(
			array(
				'id'             => 1,
				'occurrence_id'  => 10,
				'wc_order_id'    => 100,
				'quantity'       => 2, // Same as refund.
				'ticket_type_id' => 5,
			)
		);
		$this->attendee_repo
			->method( 'find_by_order_and_occurrence' )
			->willReturn( $attendee );

		// Full refund - should update status to refunded.
		$this->attendee_repo
			->expects( $this->once() )
			->method( 'update_status' )
			->with( 1, 'refunded' );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'cancel_all_tickets_for_attendee' )
			->with( 1 );

		$this->handler->process_refund( $refund, $parent_order );
	}

	/**
	 * Test process_refund finds original item by product ID fallback.
	 *
	 * @return void
	 */
	public function test_process_refund_finds_item_by_product_fallback(): void {
		$refund_item = $this->createMock( \WC_Order_Item_Product::class );
		$refund_item->method( 'get_product_id' )->willReturn( 42 );
		$refund_item->method( 'get_quantity' )->willReturn( -1 );
		$refund_item->method( 'get_meta' )->willReturn( '' ); // No _refunded_item_id.

		$original_item = $this->createMock( \WC_Order_Item_Product::class );
		$original_item->method( 'get_product_id' )->willReturn( 42 );
		$original_item->method( 'get_id' )->willReturn( 1001 );
		$original_item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) {
				if ( '_nettertech_events_occurrence_id' === $key ) {
					return 10;
				}
				if ( '_nettertech_events_ticket_type_id' === $key ) {
					return 5;
				}
				return '';
			}
		);

		$refund = $this->createMock( \WC_Order_Refund::class );
		$refund->method( 'get_id' )->willReturn( 50 );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		$parent_order = $this->createMock( \WC_Order::class );
		$parent_order->method( 'get_id' )->willReturn( 100 );
		$parent_order->method( 'get_status' )->willReturn( 'completed' );
		$parent_order->method( 'get_items' )->willReturn( array( $original_item ) );
		$parent_order->method( 'get_item' )->with( 1001 )->willReturn( $original_item );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$attendee = AttendeeFactory::create(
			array(
				'id'             => 1,
				'occurrence_id'  => 10,
				'wc_order_id'    => 100,
				'quantity'       => 2,
				'ticket_type_id' => 5,
			)
		);
		$this->attendee_repo
			->method( 'find_by_order_and_occurrence' )
			->willReturn( $attendee );

		// Should still process via product ID fallback.
		$this->attendee_repo
			->expects( $this->once() )
			->method( 'update_quantity' );

		$this->handler->process_refund( $refund, $parent_order );
	}

	/**
	 * Test process_refund skips items without occurrence_id.
	 *
	 * @return void
	 */
	public function test_process_refund_skips_items_without_occurrence(): void {
		$meta_values = array(
			'_refunded_item_id'  => 1001,
			'_nettertech_events_occurrence_id'  => 0, // No occurrence.
			'_nettertech_events_ticket_type_id' => 5,
		);

		$refund_item = $this->createMock( \WC_Order_Item_Product::class );
		$refund_item->method( 'get_product_id' )->willReturn( 42 );
		$refund_item->method( 'get_quantity' )->willReturn( -1 );
		$refund_item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$original_item = $this->createMock( \WC_Order_Item_Product::class );
		$original_item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$refund = $this->createMock( \WC_Order_Refund::class );
		$refund->method( 'get_id' )->willReturn( 50 );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		$parent_order = $this->createMock( \WC_Order::class );
		$parent_order->method( 'get_id' )->willReturn( 100 );
		$parent_order->method( 'get_status' )->willReturn( 'completed' );
		$parent_order->method( 'get_item' )->willReturn( $original_item );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		// Should not try to find attendee.
		$this->attendee_repo
			->expects( $this->never() )
			->method( 'find_by_order_and_occurrence' );

		$this->handler->process_refund( $refund, $parent_order );
	}

	/**
	 * Test process_refund skips when attendee not found.
	 *
	 * @return void
	 */
	public function test_process_refund_skips_when_attendee_not_found(): void {
		$meta_values = array(
			'_refunded_item_id'  => 1001,
			'_nettertech_events_occurrence_id'  => 10,
			'_nettertech_events_ticket_type_id' => 5,
		);

		$refund_item = $this->createMock( \WC_Order_Item_Product::class );
		$refund_item->method( 'get_product_id' )->willReturn( 42 );
		$refund_item->method( 'get_quantity' )->willReturn( -1 );
		$refund_item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$original_item = $this->createMock( \WC_Order_Item_Product::class );
		$original_item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta_values ) {
				return $meta_values[ $key ] ?? '';
			}
		);

		$refund = $this->createMock( \WC_Order_Refund::class );
		$refund->method( 'get_id' )->willReturn( 50 );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		$parent_order = $this->createMock( \WC_Order::class );
		$parent_order->method( 'get_id' )->willReturn( 100 );
		$parent_order->method( 'get_status' )->willReturn( 'completed' );
		$parent_order->method( 'get_item' )->willReturn( $original_item );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		// No attendee found.
		$this->attendee_repo
			->method( 'find_by_order_and_occurrence' )
			->willReturn( null );

		// Should not update status.
		$this->attendee_repo
			->expects( $this->never() )
			->method( 'update_status' );

		$this->attendee_repo
			->expects( $this->never() )
			->method( 'update_quantity' );

		$this->handler->process_refund( $refund, $parent_order );
	}

	/**
	 * Test process_refund skips refund items with zero quantity.
	 *
	 * @return void
	 */
	public function test_process_refund_skips_zero_quantity_items(): void {
		$refund_item = $this->createMock( \WC_Order_Item_Product::class );
		$refund_item->method( 'get_product_id' )->willReturn( 42 );
		$refund_item->method( 'get_quantity' )->willReturn( 0 ); // Zero quantity.

		$refund = $this->createMock( \WC_Order_Refund::class );
		$refund->method( 'get_id' )->willReturn( 50 );
		$refund->method( 'get_items' )->willReturn( array( $refund_item ) );

		$parent_order = $this->createMock( \WC_Order::class );
		$parent_order->method( 'get_id' )->willReturn( 100 );
		$parent_order->method( 'get_status' )->willReturn( 'completed' );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		// Should not process zero quantity items.
		$this->attendee_repo
			->expects( $this->never() )
			->method( 'find_by_order_and_occurrence' );

		$this->handler->process_refund( $refund, $parent_order );
	}

	// =========================================================================
	// get_ticket_summary with Occurrence Data Tests
	// =========================================================================

	/**
	 * Test get_ticket_summary includes occurrence data.
	 *
	 * @return void
	 */
	public function test_get_ticket_summary_includes_occurrence_data(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( 42 );
		$item->method( 'get_quantity' )->willReturn( 2 );
		$item->method( 'get_total' )->willReturn( '50.00' );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array( $item ) );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		// Mock occurrence with event data.
		$event = $this->createMock( \NetterTechEvents\Models\Event::class );
		$event->title = 'Test Event';
		$event->venue_name = 'Test Venue';

		$occurrence = $this->createMock( \NetterTechEvents\Models\Occurrence::class );
		$occurrence->id = 10;
		$occurrence->method( 'get_event' )->willReturn( $event );
		$occurrence->method( 'get_formatted_date' )->willReturn( 'January 15, 2024' );
		$occurrence->method( 'get_formatted_time' )->willReturn( '7:00 PM' );

		$this->product_manager
			->method( 'get_occurrence_from_product' )
			->with( 42 )
			->willReturn( $occurrence );

		// Mock ticket type.
		$ticket_type       = $this->createMock( \NetterTechEvents\Models\TicketType::class );
		$ticket_type->name = 'VIP';

		$this->product_manager
			->method( 'get_ticket_type_from_product' )
			->with( 42 )
			->willReturn( $ticket_type );

		$result = $this->handler->get_ticket_summary( $order );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Test Event', $result[0]['event_title'] );
		$this->assertSame( 'January 15, 2024', $result[0]['event_date'] );
		$this->assertSame( '7:00 PM', $result[0]['event_time'] );
		$this->assertSame( 'Test Venue', $result[0]['venue'] );
		$this->assertSame( 'VIP', $result[0]['ticket_type'] );
		$this->assertSame( 10, $result[0]['occurrence_id'] );
	}
}
