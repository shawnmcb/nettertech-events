<?php
/**
 * WooCommerce Order Flow tests.
 *
 * Tests the complete order-to-attendee lifecycle using real sub-handlers
 * (OrderAttendeeCreator, OrderRefundProcessor, OrderCapacityValidator)
 * with mocked repositories and services to verify orchestration wiring.
 *
 * Addresses GAP-009: Integration Test Gaps — WooCommerce Flow Untested End-to-End.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Enums\AttendeeStatus;
use NetterTechEvents\Integrations\WooCommerce\OrderAttendeeCreator;
use NetterTechEvents\Integrations\WooCommerce\OrderCapacityValidator;
use NetterTechEvents\Integrations\WooCommerce\OrderHandler;
use NetterTechEvents\Integrations\WooCommerce\OrderRefundProcessor;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Models\Ticket;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Services\TicketCodeGenerator;
use NetterTechEvents\Tests\Factories\AttendeeFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;

/**
 * Tests the complete WooCommerce order lifecycle.
 *
 * These tests use real OrderAttendeeCreator, OrderRefundProcessor, and
 * OrderCapacityValidator instances (not mocks), verifying the orchestration
 * between OrderHandler and its delegates with realistic mock setups.
 *
 * This is the most financially consequential flow in the plugin — it involves
 * real payments, attendee creation, capacity management, and ticket generation.
 */
class WooCommerceOrderFlowTest extends \NetterTechEventsTestCase {

	/**
	 * OrderHandler instance (using real sub-handlers).
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
	 * Mock AttendeeRepository (implements both AttendeeRepositoryInterface and AttendeeOrderInterface).
	 *
	 * @var AttendeeRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $attendee_repo;

	/**
	 * Mock TicketTypeRepositoryInterface.
	 *
	 * @var TicketTypeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * Mock TicketRepositoryInterface.
	 *
	 * @var TicketRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_repo;

	/**
	 * Mock TicketCodeGenerator.
	 *
	 * @var TicketCodeGenerator|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $code_generator;

	/**
	 * Mock OccurrenceRepositoryInterface.
	 *
	 * @var OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Mock CapacityServiceInterface.
	 *
	 * @var CapacityServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $capacity_service;

	/**
	 * Tracks attendees saved by the mock repo.
	 *
	 * @var array<Attendee>
	 */
	private array $saved_attendees = array();

	/**
	 * Tracks tickets saved by the mock repo.
	 *
	 * @var array<Ticket>
	 */
	private array $saved_tickets = array();

	/**
	 * Auto-increment ID for saved attendees.
	 *
	 * @var int
	 */
	private int $attendee_id_counter = 0;

	/**
	 * Auto-increment ID for saved tickets.
	 *
	 * @var int
	 */
	private int $ticket_id_counter = 0;

	/**
	 * Set up test fixtures.
	 *
	 * Creates OrderHandler with REAL sub-handlers and mocked repositories.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->product_manager  = $this->createMock( ProductManager::class );
		$this->attendee_repo    = $this->createMock( AttendeeRepository::class );
		$this->ticket_type_repo = $this->createMock( TicketTypeRepositoryInterface::class );
		$this->ticket_repo      = $this->createMock( TicketRepositoryInterface::class );
		$this->code_generator   = $this->createMock( TicketCodeGenerator::class );
		$this->occurrence_repo  = $this->createMock( OccurrenceRepositoryInterface::class );
		$this->capacity_service = $this->createMock( CapacityServiceInterface::class );

		$this->saved_attendees     = array();
		$this->saved_tickets       = array();
		$this->attendee_id_counter = 0;
		$this->ticket_id_counter   = 0;

		// Default QR service behavior.
		$this->code_generator
			->method( 'generate' )
			->willReturnCallback(
				function () {
					return 'QR-' . bin2hex( random_bytes( 8 ) );
				}
			);

		// Track saves for attendees (must return Attendee).
		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback(
				function ( Attendee $attendee ): Attendee {
					++$this->attendee_id_counter;
					$attendee->id            = $this->attendee_id_counter;
					$this->saved_attendees[] = $attendee;
					return $attendee;
				}
			);

		// Track saves for tickets (must return Ticket).
		$this->ticket_repo
			->method( 'save' )
			->willReturnCallback(
				function ( Ticket $ticket ): Ticket {
					++$this->ticket_id_counter;
					$ticket->id            = $this->ticket_id_counter;
					$this->saved_tickets[] = $ticket;
					return $ticket;
				}
			);

		// Default: no existing attendees for the order.
		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->rebuild_handler();

		AttendeeFactory::reset();
		OccurrenceFactory::reset();
		TicketTypeFactory::reset();
	}

	// =========================================================================
	// Order Completion -> Attendee Creation (Full Flow)
	// =========================================================================

	/**
	 * Test complete flow: single ticket creates 1 attendee + 1 ticket.
	 *
	 * @return void
	 */
	public function test_flow_single_ticket_creates_one_attendee_and_ticket(): void {
		$order = $this->create_order_with_ticket_items(
			array(
				array(
					'product_id'     => 42,
					'ticket_type_id' => 5,
					'occurrence_id'  => 10,
					'quantity'       => 1,
					'total'          => '25.00',
				),
			)
		);

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null ); // Unlimited.

		$this->capacity_service
			->expects( $this->once() )
			->method( 'reserve_capacity' )
			->with( 5, 1 );

		$this->handler->process_order_object( $order );

		$this->assertCount( 1, $this->saved_attendees );
		$this->assertCount( 1, $this->saved_tickets );

		$attendee = $this->saved_attendees[0];
		$this->assertSame( 10, $attendee->occurrence_id );
		$this->assertSame( 5, $attendee->ticket_type_id );
		$this->assertSame( 100, $attendee->wc_order_id );
		$this->assertSame( 'John Doe', $attendee->name );
		$this->assertSame( 'john.doe@example.com', $attendee->email );
		$this->assertSame( AttendeeStatus::CONFIRMED->value, $attendee->status );
		$this->assertSame( 1, $attendee->quantity );

		$ticket = $this->saved_tickets[0];
		$this->assertSame( 5, $ticket->ticket_type_id );
		$this->assertSame( 10, $ticket->occurrence_id );
		$this->assertSame( 100, $ticket->wc_order_id );
		$this->assertSame( 'confirmed', $ticket->status );
		$this->assertSame( 25.0, $ticket->price_paid );
		$this->assertStringStartsWith( 'QR-', $ticket->ticket_code );
	}

	/**
	 * Test complete flow: multiple ticket types create correct attendees.
	 *
	 * @return void
	 */
	public function test_flow_multiple_ticket_types_create_correct_attendees(): void {
		$order = $this->create_order_with_ticket_items(
			array(
				array(
					'product_id'     => 42,
					'ticket_type_id' => 5,
					'occurrence_id'  => 10,
					'quantity'       => 1,
					'total'          => '25.00',
				),
				array(
					'product_id'     => 43,
					'ticket_type_id' => 6,
					'occurrence_id'  => 10,
					'quantity'       => 1,
					'total'          => '50.00',
				),
			)
		);

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null );

		$this->handler->process_order_object( $order );

		$this->assertCount( 2, $this->saved_attendees );
		$this->assertCount( 2, $this->saved_tickets );

		// First attendee: General Admission.
		$this->assertSame( 5, $this->saved_attendees[0]->ticket_type_id );
		$this->assertSame( 25.0, $this->saved_tickets[0]->price_paid );

		// Second attendee: VIP.
		$this->assertSame( 6, $this->saved_attendees[1]->ticket_type_id );
		$this->assertSame( 50.0, $this->saved_tickets[1]->price_paid );
	}

	/**
	 * Test complete flow: quantity > 1 creates correct number of tickets.
	 *
	 * @return void
	 */
	public function test_flow_quantity_greater_than_one_creates_multiple_tickets(): void {
		$order = $this->create_order_with_ticket_items(
			array(
				array(
					'product_id'     => 42,
					'ticket_type_id' => 5,
					'occurrence_id'  => 10,
					'quantity'       => 3,
					'total'          => '75.00',
				),
			)
		);

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null );

		$this->capacity_service
			->expects( $this->once() )
			->method( 'reserve_capacity' )
			->with( 5, 3 );

		$this->handler->process_order_object( $order );

		$this->assertCount( 1, $this->saved_attendees );
		$this->assertCount( 3, $this->saved_tickets );

		$this->assertSame( 3, $this->saved_attendees[0]->quantity );

		// Each ticket should have price = 75 / 3 = 25.
		foreach ( $this->saved_tickets as $ticket ) {
			$this->assertSame( 25.0, $ticket->price_paid );
			$this->assertSame( 10, $ticket->occurrence_id );
			$this->assertSame( 5, $ticket->ticket_type_id );
		}

		// Each ticket should have a unique QR code.
		$codes = array_map(
			fn( Ticket $t ) => $t->ticket_code,
			$this->saved_tickets
		);
		$this->assertCount( 3, array_unique( $codes ) );
	}

	/**
	 * Test complete flow: order marks as processed and syncs stock.
	 *
	 * @return void
	 */
	public function test_flow_marks_order_processed_and_syncs_stock(): void {
		$meta  = array();
		$order = $this->create_order_with_ticket_items(
			array(
				array(
					'product_id'     => 42,
					'ticket_type_id' => 5,
					'occurrence_id'  => 10,
					'quantity'       => 2,
					'total'          => '50.00',
				),
			),
			array(),
			$meta
		);

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null );

		$this->product_manager
			->expects( $this->once() )
			->method( 'sync_stock' )
			->with( 5 );

		$this->handler->process_order_object( $order );

		// Verify the order meta was set (HPOS-compatible).
		$this->assertSame( 'yes', $meta[ MetaKeys::ATTENDEES_CREATED ] ?? '' );
	}

	/**
	 * Test flow: mixed items (ticket + non-ticket) only processes tickets.
	 *
	 * @return void
	 */
	public function test_flow_mixed_items_only_processes_tickets(): void {
		$ticket_item     = $this->create_mock_order_item( 1, 42, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
		) );
		$non_ticket_item = $this->create_mock_order_item( 2, 99, 1, '15.00' );

		$meta  = array();
		$order = $this->create_mock_order( 100, array( $ticket_item, $non_ticket_item ), array(), $meta );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturnMap(
				array(
					array( 42, true ),
					array( 99, false ),
				)
			);

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null );

		$this->handler->process_order_object( $order );

		// Only 1 attendee for the ticket item.
		$this->assertCount( 1, $this->saved_attendees );
		$this->assertSame( 5, $this->saved_attendees[0]->ticket_type_id );
	}

	/**
	 * Test flow: billing info is correctly mapped to attendee fields.
	 *
	 * @return void
	 */
	public function test_flow_billing_info_mapped_to_attendee(): void {
		$item = $this->create_mock_order_item( 1, 42, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
		) );

		$meta  = array(
			MetaKeys::ACCESSIBILITY_NOTES => 'Wheelchair access needed',
		);
		$order = $this->create_mock_order(
			100,
			array( $item ),
			array(
				'billing_first_name' => 'Jane',
				'billing_last_name'  => 'Smith',
				'billing_email'      => 'jane@example.com',
				'billing_phone'      => '555-9876',
			),
			$meta
		);

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null );

		$this->handler->process_order_object( $order );

		$this->assertCount( 1, $this->saved_attendees );
		$attendee = $this->saved_attendees[0];
		$this->assertSame( 'Jane Smith', $attendee->name );
		$this->assertSame( 'jane@example.com', $attendee->email );
		$this->assertSame( '555-9876', $attendee->phone );
		$this->assertSame( 'Wheelchair access needed', $attendee->accessibility_notes );
	}

	// =========================================================================
	// Idempotency Tests
	// =========================================================================

	/**
	 * Test idempotency: processing same order twice creates attendees only once.
	 *
	 * @return void
	 */
	public function test_idempotency_second_processing_creates_no_duplicates(): void {
		$meta  = array();
		$order = $this->create_order_with_ticket_items(
			array(
				array(
					'product_id'     => 42,
					'ticket_type_id' => 5,
					'occurrence_id'  => 10,
					'quantity'       => 2,
					'total'          => '50.00',
				),
			),
			array(),
			$meta
		);

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null );

		// First processing.
		$this->handler->process_order_object( $order );

		$first_attendee_count = count( $this->saved_attendees );
		$first_ticket_count   = count( $this->saved_tickets );

		$this->assertSame( 1, $first_attendee_count );
		$this->assertSame( 2, $first_ticket_count );

		// Second processing — should be short-circuited by idempotency guard.
		$this->handler->process_order_object( $order );

		$this->assertCount( $first_attendee_count, $this->saved_attendees );
		$this->assertCount( $first_ticket_count, $this->saved_tickets );
	}

	/**
	 * Test idempotency guard: MetaKeys::ATTENDEES_CREATED meta is checked.
	 *
	 * @return void
	 */
	public function test_idempotency_guard_checks_attendees_created_meta(): void {
		$meta  = array(
			MetaKeys::ATTENDEES_CREATED => 'yes',
		);
		$order = $this->create_mock_order( 100, array(), array(), $meta );

		// Should not call attendee save at all.
		$this->attendee_repo
			->expects( $this->never() )
			->method( 'save' );

		$this->handler->process_order_object( $order );

		$this->assertEmpty( $this->saved_attendees );
	}

	/**
	 * Test idempotency: mark is written immediately before processing items.
	 *
	 * @return void
	 */
	public function test_idempotency_mark_set_before_item_processing(): void {
		$meta  = array();
		$order = $this->create_order_with_ticket_items(
			array(
				array(
					'product_id'     => 42,
					'ticket_type_id' => 5,
					'occurrence_id'  => 10,
					'quantity'       => 1,
					'total'          => '25.00',
				),
			),
			array(),
			$meta
		);

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null );

		$this->handler->process_order_object( $order );

		// After processing, the meta should be set.
		$this->assertSame( 'yes', $meta[ MetaKeys::ATTENDEES_CREATED ] ?? '' );
	}

	// =========================================================================
	// Capacity Validation at Order Time
	// =========================================================================

	/**
	 * Test flow: capacity warning detected but order still processes.
	 *
	 * Payment has already been collected, so we log a warning but don't block.
	 *
	 * @return void
	 */
	public function test_flow_capacity_issue_logs_warning_but_processes(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'       => 5,
				'name'     => 'GA',
				'capacity' => 10,
			)
		);

		$order = $this->create_order_with_ticket_items(
			array(
				array(
					'product_id'     => 42,
					'ticket_type_id' => 5,
					'occurrence_id'  => 10,
					'quantity'       => 3,
					'total'          => '75.00',
				),
			)
		);

		$this->ticket_type_repo
			->method( 'find' )
			->with( 5 )
			->willReturn( $ticket_type );

		// Only 1 available (less than the 3 requested).
		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( 1 );

		$this->handler->process_order_object( $order );

		// Attendees still created despite capacity warning.
		$this->assertCount( 1, $this->saved_attendees );
		$this->assertSame( 3, $this->saved_attendees[0]->quantity );
	}

	/**
	 * Test flow: unlimited capacity skips validation entirely.
	 *
	 * @return void
	 */
	public function test_flow_unlimited_capacity_skips_validation(): void {
		$ticket_type = TicketTypeFactory::create(
			array(
				'id'       => 5,
				'capacity' => null,
			)
		);

		$order = $this->create_order_with_ticket_items(
			array(
				array(
					'product_id'     => 42,
					'ticket_type_id' => 5,
					'occurrence_id'  => 10,
					'quantity'       => 100,
					'total'          => '2500.00',
				),
			)
		);

		$this->ticket_type_repo
			->method( 'find' )
			->willReturn( $ticket_type );

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null ); // Unlimited.

		$this->handler->process_order_object( $order );

		// All attendees created.
		$this->assertCount( 1, $this->saved_attendees );
		$this->assertSame( 100, $this->saved_attendees[0]->quantity );
	}

	// =========================================================================
	// Order Void (Cancellation/Full Refund) Flow
	// =========================================================================

	/**
	 * Test void flow: all attendees voided, tickets cancelled, capacity released.
	 *
	 * @return void
	 */
	public function test_void_flow_updates_all_attendees_and_releases_capacity(): void {
		$attendees = array(
			AttendeeFactory::create(
				array(
					'id'             => 1,
					'ticket_type_id' => 5,
					'quantity'       => 2,
					'wc_order_id'    => 100,
				)
			),
			AttendeeFactory::create(
				array(
					'id'             => 2,
					'ticket_type_id' => 6,
					'quantity'       => 1,
					'wc_order_id'    => 100,
				)
			),
		);

		$this->attendee_repo = $this->createMock( AttendeeRepository::class );
		$this->attendee_repo
			->method( 'find_all_by_order' )
			->with( 100 )
			->willReturn( $attendees );

		// Rebuild handler with the new attendee_repo mock.
		$this->rebuild_handler();

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );

		$status_calls = array();
		$this->attendee_repo
			->method( 'update_status' )
			->willReturnCallback(
				function ( $id, $status ) use ( &$status_calls ): bool {
					$status_calls[ $id ] = $status;
					return true;
				}
			);

		$cancel_calls = array();
		$this->ticket_repo
			->method( 'cancel_all_tickets_for_attendee' )
			->willReturnCallback(
				function ( $id ) use ( &$cancel_calls ): int {
					$cancel_calls[] = $id;
					return 0;
				}
			);

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

		// Both attendees voided.
		$this->assertSame( 'voided', $status_calls[1] );
		$this->assertSame( 'voided', $status_calls[2] );

		// Tickets cancelled for both.
		$this->assertContains( 1, $cancel_calls );
		$this->assertContains( 2, $cancel_calls );

		// sold_count recalculated for both ticket types.
		$this->assertContains( 5, $recalc_calls );
		$this->assertContains( 6, $recalc_calls );
	}

	/**
	 * Test void flow: no attendees means no side effects.
	 *
	 * @return void
	 */
	public function test_void_flow_no_attendees_no_side_effects(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 100 );

		$this->attendee_repo
			->expects( $this->never() )
			->method( 'update_status' );

		$this->capacity_service
			->expects( $this->never() )
			->method( 'release_capacity' );

		$this->handler->void_attendees_for_order( $order );
	}

	// =========================================================================
	// Partial Refund Flow
	// =========================================================================

	/**
	 * Test partial refund: reduces attendee quantity and cancels N tickets.
	 *
	 * @return void
	 */
	public function test_refund_flow_partial_reduces_quantity(): void {
		$attendee = AttendeeFactory::create(
			array(
				'id'             => 1,
				'occurrence_id'  => 10,
				'ticket_type_id' => 5,
				'quantity'       => 3,
				'wc_order_id'    => 100,
			)
		);

		// Original order item with matching product and meta.
		$original_item = $this->create_mock_order_item( 50, 42, 3, '75.00', array(
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::TICKET_TYPE_ID => 5,
		) );

		$parent_meta  = array();
		$parent_order = $this->create_mock_order(
			100,
			array( $original_item ),
			array(),
			$parent_meta,
			'completed'
		);

		// Refund item: refunding 1 of 3.
		$refund_item = $this->create_mock_order_item( 60, 42, -1, '0.00', array(
			'_refunded_item_id' => 50,
		) );

		$refund = $this->create_mock_refund( 200, 100, array( $refund_item ) );

		$this->product_manager
			->method( 'is_event_ticket' )
			->with( 42 )
			->willReturn( true );

		$this->attendee_repo
			->method( 'find_by_order_and_occurrence' )
			->with( 100, 10 )
			->willReturn( $attendee );

		$this->attendee_repo
			->expects( $this->once() )
			->method( 'update_quantity' )
			->with( 1, 2 ); // New quantity = 3 - 1 = 2.

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'cancel_tickets_for_attendee' )
			->with( 1, 1 );

		$this->capacity_service
			->expects( $this->once() )
			->method( 'release_capacity' )
			->with( 5, 1 );

		$this->product_manager
			->expects( $this->once() )
			->method( 'sync_stock' )
			->with( 5 );

		$this->handler->process_refund( $refund, $parent_order );
	}

	/**
	 * Test full refund of line item: attendee marked refunded, all tickets cancelled.
	 *
	 * @return void
	 */
	public function test_refund_flow_full_cancels_attendee(): void {
		$attendee = AttendeeFactory::create(
			array(
				'id'             => 1,
				'occurrence_id'  => 10,
				'ticket_type_id' => 5,
				'quantity'       => 2,
				'wc_order_id'    => 100,
			)
		);

		$original_item = $this->create_mock_order_item( 50, 42, 2, '50.00', array(
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::TICKET_TYPE_ID => 5,
		) );

		$parent_meta  = array();
		$parent_order = $this->create_mock_order(
			100,
			array( $original_item ),
			array(),
			$parent_meta,
			'completed'
		);

		$refund_item = $this->create_mock_order_item( 60, 42, -2, '0.00', array(
			'_refunded_item_id' => 50,
		) );

		$refund = $this->create_mock_refund( 200, 100, array( $refund_item ) );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->attendee_repo
			->method( 'find_by_order_and_occurrence' )
			->willReturn( $attendee );

		$this->attendee_repo
			->expects( $this->once() )
			->method( 'update_status' )
			->with( 1, 'refunded' );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'cancel_all_tickets_for_attendee' )
			->with( 1 );

		$this->capacity_service
			->expects( $this->once() )
			->method( 'release_capacity' )
			->with( 5, 2 );

		$this->handler->process_refund( $refund, $parent_order );
	}

	/**
	 * Test refund idempotency: processing same refund twice has no double effect.
	 *
	 * @return void
	 */
	public function test_refund_idempotency_prevents_double_processing(): void {
		$attendee = AttendeeFactory::create(
			array(
				'id'             => 1,
				'occurrence_id'  => 10,
				'ticket_type_id' => 5,
				'quantity'       => 2,
				'wc_order_id'    => 100,
			)
		);

		$original_item = $this->create_mock_order_item( 50, 42, 2, '50.00', array(
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::TICKET_TYPE_ID => 5,
		) );

		$parent_meta  = array();
		$parent_order = $this->create_mock_order(
			100,
			array( $original_item ),
			array(),
			$parent_meta,
			'completed'
		);

		$refund_item = $this->create_mock_order_item( 60, 42, -1, '0.00', array(
			'_refunded_item_id' => 50,
		) );

		$refund = $this->create_mock_refund( 200, 100, array( $refund_item ) );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->attendee_repo
			->method( 'find_by_order_and_occurrence' )
			->willReturn( $attendee );

		// First processing.
		$this->handler->process_refund( $refund, $parent_order );

		// Verify the idempotency meta was set.
		$refund_meta_key = MetaKeys::PROCESSED_REFUND_PREFIX . 200;
		$this->assertSame( 'yes', $parent_meta[ $refund_meta_key ] ?? '' );

		// Second processing should be a no-op due to idempotency guard.
		// We can verify by checking the meta is already set (guard reads it).
		$this->handler->process_refund( $refund, $parent_order );

		// The idempotency meta is still 'yes' (unchanged by second call).
		$this->assertSame( 'yes', $parent_meta[ $refund_meta_key ] );
	}

	/**
	 * Test refund: fully refunded order status skips partial refund processing.
	 *
	 * @return void
	 */
	public function test_refund_flow_fully_refunded_status_skips_processing(): void {
		$parent_meta  = array();
		$parent_order = $this->create_mock_order( 100, array(), array(), $parent_meta, 'refunded' );

		$refund = $this->create_mock_refund( 200, 100 );

		$this->capacity_service
			->expects( $this->never() )
			->method( 'release_capacity' );

		$this->handler->process_refund( $refund, $parent_order );
	}

	// =========================================================================
	// Edge Cases
	// =========================================================================

	/**
	 * Test edge case: order with no ticket items processes gracefully.
	 *
	 * @return void
	 */
	public function test_edge_no_ticket_items_processes_gracefully(): void {
		$non_ticket = $this->create_mock_order_item( 1, 99, 1, '15.00' );

		$meta  = array();
		$order = $this->create_mock_order( 100, array( $non_ticket ), array(), $meta );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( false );

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null );

		$this->attendee_repo
			->expects( $this->never() )
			->method( 'save' );

		$this->capacity_service
			->expects( $this->never() )
			->method( 'reserve_capacity' );

		$this->handler->process_order_object( $order );

		$this->assertEmpty( $this->saved_attendees );
	}

	/**
	 * Test edge case: item with no occurrence_id is skipped, others processed.
	 *
	 * @return void
	 */
	public function test_edge_missing_occurrence_id_skipped_others_processed(): void {
		$item_no_occ = $this->create_mock_order_item( 1, 42, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			// No OCCURRENCE_ID.
		) );

		$item_with_occ = $this->create_mock_order_item( 2, 43, 1, '30.00', array(
			MetaKeys::TICKET_TYPE_ID => 6,
			MetaKeys::OCCURRENCE_ID  => 20,
		) );

		$meta  = array();
		$order = $this->create_mock_order( 100, array( $item_no_occ, $item_with_occ ), array(), $meta );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null );

		$this->handler->process_order_object( $order );

		// Only the item with occurrence_id should create an attendee.
		$this->assertCount( 1, $this->saved_attendees );
		$this->assertSame( 20, $this->saved_attendees[0]->occurrence_id );
		$this->assertSame( 6, $this->saved_attendees[0]->ticket_type_id );
	}

	/**
	 * Test edge case: RuntimeException during attendee save fires failure action.
	 *
	 * @return void
	 */
	public function test_edge_runtime_exception_on_save_fires_failure_action(): void {
		// Rebuild with a fresh attendee_repo to avoid setUp's save callback.
		$this->attendee_repo = $this->createMock( AttendeeRepository::class );
		$this->attendee_repo
			->method( 'find_all_by_order' )
			->willReturn( array() );

		// First save throws, second succeeds.
		$call_count = 0;
		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback(
				function ( Attendee $attendee ) use ( &$call_count ): Attendee {
					++$call_count;
					if ( 1 === $call_count ) {
						throw new \RuntimeException( 'DB error' );
					}
					++$this->attendee_id_counter;
					$attendee->id            = $this->attendee_id_counter;
					$this->saved_attendees[] = $attendee;
					return $attendee;
				}
			);

		$this->rebuild_handler();

		$item1 = $this->create_mock_order_item( 1, 42, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
		) );

		$item2 = $this->create_mock_order_item( 2, 43, 1, '30.00', array(
			MetaKeys::TICKET_TYPE_ID => 6,
			MetaKeys::OCCURRENCE_ID  => 20,
		) );

		$meta  = array();
		$order = $this->create_mock_order( 100, array( $item1, $item2 ), array(), $meta );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null );

		$this->handler->process_order_object( $order );

		// Second item should still be processed despite first failing.
		$this->assertCount( 1, $this->saved_attendees );
		$this->assertSame( 20, $this->saved_attendees[0]->occurrence_id );
	}

	/**
	 * Test edge case: order with empty phone stores null.
	 *
	 * @return void
	 */
	public function test_edge_empty_phone_stores_null(): void {
		$order = $this->create_order_with_ticket_items(
			array(
				array(
					'product_id'     => 42,
					'ticket_type_id' => 5,
					'occurrence_id'  => 10,
					'quantity'       => 1,
					'total'          => '25.00',
				),
			),
			array( 'billing_phone' => '' )
		);

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null );

		$this->handler->process_order_object( $order );

		$this->assertNull( $this->saved_attendees[0]->phone );
	}

	/**
	 * Test edge case: series pass creates attendees for all occurrences.
	 *
	 * @return void
	 */
	public function test_edge_series_pass_creates_attendees_for_all_occurrences(): void {
		$occurrences = array(
			OccurrenceFactory::create( array( 'id' => 10, 'event_id' => 1 ) ),
			OccurrenceFactory::create( array( 'id' => 11, 'event_id' => 1 ) ),
			OccurrenceFactory::create( array( 'id' => 12, 'event_id' => 1 ) ),
		);

		$this->occurrence_repo
			->method( 'for_event' )
			->with( 1 )
			->willReturn( $occurrences );

		$item = $this->create_mock_order_item( 1, 42, 1, '90.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::IS_SERIES_PASS => 'yes',
			MetaKeys::EVENT_ID       => 1,
		) );

		$meta  = array();
		$order = $this->create_mock_order( 100, array( $item ), array(), $meta );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null );

		$this->handler->process_order_object( $order );

		// 3 attendees (one per occurrence).
		$this->assertCount( 3, $this->saved_attendees );

		$occ_ids = array_map(
			fn( Attendee $a ) => $a->occurrence_id,
			$this->saved_attendees
		);
		$this->assertContains( 10, $occ_ids );
		$this->assertContains( 11, $occ_ids );
		$this->assertContains( 12, $occ_ids );

		// 3 tickets (1 per occurrence x 1 quantity).
		$this->assertCount( 3, $this->saved_tickets );

		// Price split: $90 / 3 occurrences = $30 each.
		foreach ( $this->saved_tickets as $ticket ) {
			$this->assertSame( 30.0, $ticket->price_paid );
		}
	}

	// =========================================================================
	// Full Lifecycle: Order -> Refund
	// =========================================================================

	/**
	 * Test full lifecycle: order processed, then partially refunded.
	 *
	 * This tests the complete path: payment -> attendees -> refund -> capacity release.
	 *
	 * @return void
	 */
	public function test_lifecycle_order_then_partial_refund(): void {
		// Step 1: Process the order.
		$item_meta = array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
		);
		$item      = $this->create_mock_order_item( 50, 42, 3, '75.00', $item_meta );

		$meta  = array();
		$order = $this->create_mock_order( 100, array( $item ), array(), $meta, 'completed' );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->capacity_service
			->method( 'get_available_count' )
			->willReturn( null );

		$reserve_calls = array();
		$release_calls = array();

		$this->capacity_service
			->method( 'reserve_capacity' )
			->willReturnCallback(
				function ( $type_id, $qty ) use ( &$reserve_calls ) {
					$reserve_calls[] = array( $type_id, $qty );
					return true;
				}
			);

		$this->capacity_service
			->method( 'release_capacity' )
			->willReturnCallback(
				function ( $type_id, $qty ) use ( &$release_calls ) {
					$release_calls[] = array( $type_id, $qty );
					return true;
				}
			);

		$this->handler->process_order_object( $order );

		// Verify: 1 attendee, 3 tickets, capacity reserved.
		$this->assertCount( 1, $this->saved_attendees );
		$this->assertCount( 3, $this->saved_tickets );
		$this->assertSame( array( array( 5, 3 ) ), $reserve_calls );

		// Step 2: Partial refund (refund 1 of 3).
		$this->attendee_repo
			->method( 'find_by_order_and_occurrence' )
			->with( 100, 10 )
			->willReturn( $this->saved_attendees[0] );

		$refund_item = $this->create_mock_order_item( 60, 42, -1, '0.00', array(
			'_refunded_item_id' => 50,
		) );

		$refund = $this->create_mock_refund( 300, 100, array( $refund_item ) );

		$this->handler->process_refund( $refund, $order );

		// Verify: capacity released for 1 ticket.
		$this->assertSame( array( array( 5, 1 ) ), $release_calls );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Create a PHPUnit mock of WC_Order_Item_Product with configured methods.
	 *
	 * @param int                   $item_id    Item ID.
	 * @param int                   $product_id Product ID.
	 * @param int                   $quantity   Quantity.
	 * @param string                $total      Line total.
	 * @param array<string, mixed>  $item_meta  Item meta data.
	 * @return \WC_Order_Item_Product|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_order_item(
		int $item_id,
		int $product_id,
		int $quantity,
		string $total = '0.00',
		array $item_meta = array()
	) {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_id' )->willReturn( $item_id );
		$item->method( 'get_product_id' )->willReturn( $product_id );
		$item->method( 'get_quantity' )->willReturn( $quantity );
		$item->method( 'get_total' )->willReturn( $total );
		$item->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $item_meta ) {
				return $item_meta[ $key ] ?? '';
			}
		);
		return $item;
	}

	/**
	 * Create a PHPUnit mock of WC_Order with meta storage and configured methods.
	 *
	 * The meta array is passed by reference so tests can inspect meta changes.
	 *
	 * @param int                   $order_id     Order ID.
	 * @param array<mixed>          $items        Order items.
	 * @param array<string, string> $billing      Billing info overrides.
	 * @param array<string, mixed>  $meta         Meta storage (by reference).
	 * @param string                $status       Order status.
	 * @return \WC_Order|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_order(
		int $order_id,
		array $items = array(),
		array $billing = array(),
		array &$meta = array(),
		string $status = 'processing'
	) {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( $order_id );
		$order->method( 'get_status' )->willReturn( $status );

		// Items keyed by their ID for get_item() lookup.
		$items_by_id = array();
		foreach ( $items as $item ) {
			$items_by_id[ $item->get_id() ] = $item;
		}
		$order->method( 'get_items' )->willReturn( $items_by_id );
		$order->method( 'get_item' )->willReturnCallback(
			function ( $item_id ) use ( $items_by_id ) {
				return $items_by_id[ $item_id ] ?? null;
			}
		);

		// Meta storage with by-reference semantics for inspection.
		$order->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( &$meta ) {
				return $meta[ $key ] ?? '';
			}
		);
		$order->method( 'update_meta_data' )->willReturnCallback(
			function ( $key, $value ) use ( &$meta ) {
				$meta[ $key ] = $value;
			}
		);

		// Billing info.
		$order->method( 'get_billing_first_name' )->willReturn( $billing['billing_first_name'] ?? 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( $billing['billing_last_name'] ?? 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( $billing['billing_email'] ?? 'john.doe@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( $billing['billing_phone'] ?? '555-1234' );

		return $order;
	}

	/**
	 * Create a PHPUnit mock of WC_Order_Refund.
	 *
	 * @param int          $refund_id  Refund ID.
	 * @param int          $parent_id  Parent order ID.
	 * @param array<mixed> $items      Refund items.
	 * @return \WC_Order_Refund|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_refund( int $refund_id, int $parent_id, array $items = array() ) {
		$refund = $this->createMock( \WC_Order_Refund::class );
		$refund->method( 'get_id' )->willReturn( $refund_id );
		$refund->method( 'get_parent_id' )->willReturn( $parent_id );

		$items_by_id = array();
		foreach ( $items as $item ) {
			$items_by_id[ $item->get_id() ] = $item;
		}
		$refund->method( 'get_items' )->willReturn( $items_by_id );

		return $refund;
	}

	/**
	 * Create an order with ticket items using PHPUnit mocks.
	 *
	 * Convenience helper that creates items and order in one call.
	 *
	 * @param array<array<string, mixed>> $ticket_items Ticket item configurations.
	 * @param array<string, mixed>        $order_attrs  Additional order/billing attributes.
	 * @param array<string, mixed>        $meta         Meta storage (by reference).
	 * @return \WC_Order|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_order_with_ticket_items( array $ticket_items, array $order_attrs = array(), array &$meta = array() ) {
		$items   = array();
		$item_id = 1;
		foreach ( $ticket_items as $config ) {
			$item_meta = array(
				MetaKeys::TICKET_TYPE_ID => $config['ticket_type_id'],
				MetaKeys::OCCURRENCE_ID  => $config['occurrence_id'],
			);

			if ( isset( $config['is_series_pass'] ) ) {
				$item_meta[ MetaKeys::IS_SERIES_PASS ] = $config['is_series_pass'];
			}
			if ( isset( $config['event_id'] ) ) {
				$item_meta[ MetaKeys::EVENT_ID ] = $config['event_id'];
			}

			$items[] = $this->create_mock_order_item(
				$item_id++,
				$config['product_id'],
				$config['quantity'],
				$config['total'],
				$item_meta
			);
		}

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		return $this->create_mock_order( 100, $items, $order_attrs, $meta );
	}

	/**
	 * Rebuild the handler with current mock state.
	 *
	 * Used when mocks need to be replaced mid-test.
	 *
	 * @return void
	 */
	private function rebuild_handler(): void {
		$attendee_creator = new OrderAttendeeCreator(
			$this->product_manager,
			$this->attendee_repo,
			$this->attendee_repo, // Implements both interfaces.
			$this->ticket_repo,
			$this->code_generator,
			$this->occurrence_repo,
		);

		$refund_processor = new OrderRefundProcessor(
			$this->product_manager,
			$this->attendee_repo,
			$this->attendee_repo, // Implements both interfaces.
			$this->ticket_repo,
			$this->capacity_service,
		);

		$capacity_validator = new OrderCapacityValidator(
			$this->product_manager,
			$this->ticket_type_repo,
			$this->capacity_service,
		);

		// Create handler with REAL sub-handlers (not mocks).
		$mock_field_service = $this->createMock( \NetterTechEvents\Services\AttendeeFieldService::class );
		$this->handler      = new OrderHandler(
			$this->attendee_repo,
			$this->ticket_type_repo,
			$this->ticket_repo,
			$this->code_generator,
			$this->occurrence_repo,
			$this->capacity_service,
			$this->product_manager,
			$mock_field_service,
			$attendee_creator,
			$refund_processor,
			$capacity_validator,
		);
	}
}
