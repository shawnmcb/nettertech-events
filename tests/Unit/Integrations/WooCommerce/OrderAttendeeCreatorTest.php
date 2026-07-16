<?php
/**
 * OrderAttendeeCreator unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use NetterTechEvents\Contracts\AttendeeOrderInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Enums\AttendeeStatus;
use NetterTechEvents\Integrations\WooCommerce\OrderAttendeeCreator;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Models\Ticket;
use NetterTechEvents\Services\AttendeeFieldService;
use NetterTechEvents\Services\TicketCodeGenerator;
use NetterTechEvents\Tests\Factories\AttendeeFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;

/**
 * Test OrderAttendeeCreator functionality.
 *
 * Tests attendee and ticket creation from WooCommerce orders,
 * including occurrence-scoped tickets and series passes.
 */
class OrderAttendeeCreatorTest extends \NetterTechEventsTestCase {

	/**
	 * OrderAttendeeCreator instance.
	 *
	 * @var OrderAttendeeCreator
	 */
	private OrderAttendeeCreator $creator;

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
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->product_manager = $this->createMock( ProductManager::class );
		$this->attendee_repo   = $this->createMock( AttendeeRepositoryInterface::class );
		$this->attendee_order  = $this->createMock( AttendeeOrderInterface::class );
		$this->ticket_repo     = $this->createMock( TicketRepositoryInterface::class );
		$this->code_generator  = $this->createMock( TicketCodeGenerator::class );
		$this->occurrence_repo = $this->createMock( OccurrenceRepositoryInterface::class );

		$this->creator = new OrderAttendeeCreator(
			$this->product_manager,
			$this->attendee_repo,
			$this->attendee_order,
			$this->ticket_repo,
			$this->code_generator,
			$this->occurrence_repo,
		);

		AttendeeFactory::reset();
		OccurrenceFactory::reset();
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test OrderAttendeeCreator can be instantiated with all dependencies.
	 *
	 * @return void
	 */
	public function test_can_be_instantiated(): void {
		$this->assertInstanceOf( OrderAttendeeCreator::class, $this->creator );
	}

	// =========================================================================
	// process() — Single Occurrence Ticket Tests
	// =========================================================================

	/**
	 * Test process creates attendee for a single occurrence ticket.
	 *
	 * @return void
	 */
	public function test_process_creates_attendee_for_single_ticket(): void {
		$item  = $this->create_mock_item( 100, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->with( 100 )
			->willReturn( true );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'ABCD-1234-EFGH-5678' );

		$this->attendee_repo
			->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback( function ( Attendee $attendee ): bool {
					return 10 === $attendee->occurrence_id
						&& 5 === $attendee->ticket_type_id
						&& 1 === $attendee->wc_order_id
						&& 'John Doe' === $attendee->name
						&& 'john@example.com' === $attendee->email
						&& '555-1234' === $attendee->phone
						&& 1 === $attendee->quantity
						&& AttendeeStatus::CONFIRMED->value === $attendee->status;
				} )
			)
			->willReturnCallback( function ( Attendee $attendee ): Attendee {
				$attendee->id = 1;
				return $attendee;
			} );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback( function ( Ticket $ticket ): bool {
					return 5 === $ticket->ticket_type_id
						&& 10 === $ticket->occurrence_id
						&& 1 === $ticket->attendee_id
						&& 1 === $ticket->wc_order_id
						&& 'ABCD-1234-EFGH-5678' === $ticket->ticket_code
						&& 'confirmed' === $ticket->status
						&& 25.0 === $ticket->price_paid;
				} )
			);

		$result = $this->creator->process( $order );

		$this->assertArrayHasKey( 5, $result );
		$this->assertSame( 1, $result[5] );
	}

	/**
	 * Test process creates multiple tickets for quantity > 1.
	 *
	 * @return void
	 */
	public function test_process_creates_multiple_tickets_for_quantity(): void {
		$item  = $this->create_mock_item( 100, 3, '75.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-0001-CODE-0001' );

		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ): Attendee {
				$attendee->id = 1;
				return $attendee;
			} );

		// 3 tickets created (one per unit in quantity).
		$this->ticket_repo
			->expects( $this->exactly( 3 ) )
			->method( 'save' )
			->with(
				$this->callback( function ( Ticket $ticket ): bool {
					// Price per ticket: 75 / 3 = 25.
					return 25.0 === $ticket->price_paid;
				} )
			);

		$result = $this->creator->process( $order );

		$this->assertSame( 3, $result[5] );
	}

	/**
	 * Test process handles order with multiple ticket items.
	 *
	 * @return void
	 */
	public function test_process_handles_multiple_ticket_items(): void {
		$item1 = $this->create_mock_item( 100, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		), 41 );

		$item2 = $this->create_mock_item( 101, 2, '60.00', array(
			MetaKeys::TICKET_TYPE_ID => 6,
			MetaKeys::OCCURRENCE_ID  => 20,
			MetaKeys::IS_SERIES_PASS => 'no',
		), 42 );

		$order = $this->create_mock_order( 1, array( $item1, $item2 ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$attendee_id = 0;
		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ) use ( &$attendee_id ): Attendee {
				$attendee->id = ++$attendee_id;
				return $attendee;
			} );

		// 2 attendees saved (one per ticket item).
		$this->attendee_repo
			->expects( $this->exactly( 2 ) )
			->method( 'save' );

		// 3 tickets total (1 + 2).
		$this->ticket_repo
			->expects( $this->exactly( 3 ) )
			->method( 'save' );

		$result = $this->creator->process( $order );

		$this->assertArrayHasKey( 5, $result );
		$this->assertArrayHasKey( 6, $result );
		$this->assertSame( 1, $result[5] );
		$this->assertSame( 2, $result[6] );
	}

	// =========================================================================
	// process() — Non-Ticket Item Filtering
	// =========================================================================

	/**
	 * Test process skips non-ticket products.
	 *
	 * @return void
	 */
	public function test_process_skips_non_ticket_products(): void {
		$item  = $this->create_mock_item( 200, 1, '10.00', array(
			MetaKeys::TICKET_TYPE_ID => 0,
			MetaKeys::OCCURRENCE_ID  => 0,
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->with( 200 )
			->willReturn( false );

		$this->attendee_repo
			->expects( $this->never() )
			->method( 'save' );

		$this->ticket_repo
			->expects( $this->never() )
			->method( 'save' );

		$result = $this->creator->process( $order );

		$this->assertEmpty( $result );
	}

	/**
	 * Test process skips ticket items with no occurrence ID.
	 *
	 * @return void
	 */
	public function test_process_skips_ticket_with_no_occurrence_id(): void {
		$item  = $this->create_mock_item( 100, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 0,
			MetaKeys::IS_SERIES_PASS => 'no',
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->attendee_repo
			->expects( $this->never() )
			->method( 'save' );

		$result = $this->creator->process( $order );

		// Ticket type is still tracked even though no attendee was created.
		$this->assertArrayHasKey( 5, $result );
	}

	/**
	 * Test process handles order with mixed ticket and non-ticket items.
	 *
	 * @return void
	 */
	public function test_process_filters_mixed_ticket_and_non_ticket_items(): void {
		$ticket_item = $this->create_mock_item( 100, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		), 41 );

		$merch_item = $this->create_mock_item( 300, 2, '40.00', array(), 42 );

		$order = $this->create_mock_order( 1, array( $ticket_item, $merch_item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturnMap( array(
				array( 100, true ),
				array( 300, false ),
			) );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ): Attendee {
				$attendee->id = 1;
				return $attendee;
			} );

		// Only 1 attendee created (from the ticket item).
		$this->attendee_repo
			->expects( $this->once() )
			->method( 'save' );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'save' );

		$result = $this->creator->process( $order );

		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 5, $result );
	}

	/**
	 * Test process creates separate attendees from per-attendee checkout data.
	 *
	 * @return void
	 */
	public function test_process_creates_individual_attendees_with_custom_fields(): void {
		$meta_calls = array();
		$item       = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_id' )->willReturn( 41 );
		$item->method( 'get_product_id' )->willReturn( 100 );
		$item->method( 'get_quantity' )->willReturn( 2 );
		$item->method( 'get_total' )->willReturn( '70.00' );
		$item->method( 'get_meta' )->willReturnCallback(
			function ( string $key ) {
				$meta = array(
					MetaKeys::TICKET_TYPE_ID => 5,
					MetaKeys::OCCURRENCE_ID  => 10,
					MetaKeys::EVENT_ID       => 99,
					MetaKeys::IS_SERIES_PASS => 'no',
					MetaKeys::ATTENDEE_DATA  => wp_json_encode(
						array(
							array(
								'name'          => 'Alice Example',
								'email'         => 'alice@example.com',
								'phone'         => '555-0101',
								'custom_fields' => array( 'meal' => 'vegan' ),
							),
							array(
								'name'          => 'Bob Example',
								'email'         => 'bob@example.com',
								'custom_fields' => array( 'meal' => 'standard' ),
							),
						)
					),
				);

				return $meta[ $key ] ?? '';
			}
		);
		$item->method( 'add_meta_data' )->willReturnCallback(
			function ( $key, $value ) use ( &$meta_calls ): void {
				$meta_calls[ $key ] = $value;
			}
		);
		$item->expects( $this->once() )->method( 'save_meta_data' );

		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->with( 100 )
			->willReturn( true );

		$this->code_generator
			->method( 'generate' )
			->willReturnOnConsecutiveCalls( 'CODE-0001-CODE-0001', 'CODE-0002-CODE-0002' );

		$saved_attendees = array();
		$this->attendee_repo
			->expects( $this->exactly( 2 ) )
			->method( 'save' )
			->willReturnCallback(
				function ( Attendee $attendee ) use ( &$saved_attendees ): Attendee {
					$attendee->id     = count( $saved_attendees ) + 1;
					$saved_attendees[] = clone $attendee;
					return $attendee;
				}
			);

		$saved_tickets = array();
		$this->ticket_repo
			->expects( $this->exactly( 2 ) )
			->method( 'save' )
			->willReturnCallback(
				function ( Ticket $ticket ) use ( &$saved_tickets ): Ticket {
					$ticket->id      = count( $saved_tickets ) + 10;
					$saved_tickets[] = clone $ticket;
					return $ticket;
				}
			);

		$field_service = $this->createMock( AttendeeFieldService::class );
		$field_service
			->expects( $this->exactly( 2 ) )
			->method( 'save_field_values' )
			->with(
				$this->logicalOr( 1, 2 ),
				99,
				$this->logicalOr(
					array( 'meal' => 'vegan' ),
					array( 'meal' => 'standard' )
				)
			);

		$creator = new OrderAttendeeCreator(
			$this->product_manager,
			$this->attendee_repo,
			$this->attendee_order,
			$this->ticket_repo,
			$this->code_generator,
			$this->occurrence_repo,
			$field_service,
		);

		$result = $creator->process( $order );

		$this->assertSame( 2, $result[5] );
		$this->assertCount( 2, $saved_attendees );
		$this->assertSame( 'Alice Example', $saved_attendees[0]->name );
		$this->assertSame( 'alice@example.com', $saved_attendees[0]->email );
		$this->assertSame( '555-0101', $saved_attendees[0]->phone );
		$this->assertSame( 1, $saved_attendees[0]->quantity );
		$this->assertSame( 'Bob Example', $saved_attendees[1]->name );
		$this->assertNull( $saved_attendees[1]->phone );
		$this->assertSame( 35.0, $saved_tickets[0]->price_paid );
		$this->assertSame( 35.0, $saved_tickets[1]->price_paid );
		$this->assertSame( array( 10, 11 ), $meta_calls['_nettertech_events_ticket_ids'] );
	}

	/**
	 * Test get_ticket_items filters out non-WC_Order_Item_Product items.
	 *
	 * Items that are not instanceof WC_Order_Item_Product are skipped.
	 *
	 * @return void
	 */
	public function test_process_skips_non_product_order_items(): void {
		// A plain WC_Order_Item (not _Product) should be filtered out.
		$non_product_item = $this->createMock( \WC_Order_Item::class );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 1 );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( 'john@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( '555-1234' );
		$order->method( 'get_meta' )->willReturn( '' );
		$order->method( 'get_items' )->willReturn( array( $non_product_item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->expects( $this->never() )
			->method( 'is_event_ticket' );

		$this->attendee_repo
			->expects( $this->never() )
			->method( 'save' );

		$result = $this->creator->process( $order );

		$this->assertEmpty( $result );
	}

	// =========================================================================
	// process() — Idempotency (Existing Attendees)
	// =========================================================================

	/**
	 * Test process skips occurrence that already has an attendee for this order.
	 *
	 * @return void
	 */
	public function test_process_skips_existing_attendee_for_occurrence(): void {
		$existing_attendee = AttendeeFactory::create( array(
			'occurrence_id' => 10,
			'wc_order_id'   => 1,
		) );

		$item  = $this->create_mock_item( 100, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array( $existing_attendee ) );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->attendee_repo
			->expects( $this->never() )
			->method( 'save' );

		$this->ticket_repo
			->expects( $this->never() )
			->method( 'save' );

		$result = $this->creator->process( $order );

		// Ticket type is still tracked.
		$this->assertArrayHasKey( 5, $result );
	}

	/**
	 * Test process creates attendee for new occurrence but skips existing one.
	 *
	 * @return void
	 */
	public function test_process_creates_only_for_new_occurrences(): void {
		$existing_attendee = AttendeeFactory::create( array(
			'occurrence_id' => 10,
			'wc_order_id'   => 1,
		) );

		$item_existing = $this->create_mock_item( 100, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		), 41 );

		$item_new = $this->create_mock_item( 101, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 6,
			MetaKeys::OCCURRENCE_ID  => 20,
			MetaKeys::IS_SERIES_PASS => 'no',
		), 42 );

		$order = $this->create_mock_order( 1, array( $item_existing, $item_new ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array( $existing_attendee ) );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		// Only 1 attendee created (occurrence 20; occurrence 10 skipped).
		$this->attendee_repo
			->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback( function ( Attendee $attendee ): bool {
					return 20 === $attendee->occurrence_id;
				} )
			)
			->willReturnCallback( function ( Attendee $attendee ): Attendee {
				$attendee->id = 2;
				return $attendee;
			} );

		$result = $this->creator->process( $order );

		$this->assertArrayHasKey( 5, $result );
		$this->assertArrayHasKey( 6, $result );
	}

	// =========================================================================
	// process() — Series Pass Tests
	// =========================================================================

	/**
	 * Test process creates attendees for all occurrences in a series pass.
	 *
	 * @return void
	 */
	public function test_process_creates_attendees_for_series_pass(): void {
		$occurrences = array(
			OccurrenceFactory::create( array( 'id' => 10, 'event_id' => 1 ) ),
			OccurrenceFactory::create( array( 'id' => 11, 'event_id' => 1 ) ),
			OccurrenceFactory::create( array( 'id' => 12, 'event_id' => 1 ) ),
		);

		$item  = $this->create_mock_item( 100, 1, '60.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::IS_SERIES_PASS => 'yes',
			MetaKeys::EVENT_ID       => 1,
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->occurrence_repo
			->method( 'for_event' )
			->with( 1 )
			->willReturn( $occurrences );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$attendee_id = 0;
		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ) use ( &$attendee_id ): Attendee {
				$attendee->id = ++$attendee_id;
				return $attendee;
			} );

		// 3 attendees created (one per occurrence).
		$this->attendee_repo
			->expects( $this->exactly( 3 ) )
			->method( 'save' );

		// 3 tickets created (one per occurrence, quantity=1).
		$this->ticket_repo
			->expects( $this->exactly( 3 ) )
			->method( 'save' );

		$result = $this->creator->process( $order );

		$this->assertArrayHasKey( 5, $result );
		$this->assertSame( 1, $result[5] );
	}

	/**
	 * Test series pass splits price evenly across occurrences.
	 *
	 * @return void
	 */
	public function test_series_pass_splits_price_across_occurrences(): void {
		$occurrences = array(
			OccurrenceFactory::create( array( 'id' => 10, 'event_id' => 1 ) ),
			OccurrenceFactory::create( array( 'id' => 11, 'event_id' => 1 ) ),
			OccurrenceFactory::create( array( 'id' => 12, 'event_id' => 1 ) ),
		);

		$item  = $this->create_mock_item( 100, 1, '60.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::IS_SERIES_PASS => 'yes',
			MetaKeys::EVENT_ID       => 1,
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->occurrence_repo
			->method( 'for_event' )
			->with( 1 )
			->willReturn( $occurrences );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$attendee_id = 0;
		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ) use ( &$attendee_id ): Attendee {
				$attendee->id = ++$attendee_id;
				return $attendee;
			} );

		$saved_prices = array();
		$this->ticket_repo
			->method( 'save' )
			->willReturnCallback( function ( Ticket $ticket ) use ( &$saved_prices ): Ticket {
				$saved_prices[] = $ticket->price_paid;
				$ticket->id = count( $saved_prices );
				return $ticket;
			} );

		$this->creator->process( $order );

		// $60 / 3 occurrences = $20 per ticket per occurrence.
		$this->assertCount( 3, $saved_prices );
		foreach ( $saved_prices as $price ) {
			$this->assertEqualsWithDelta( 20.0, $price, 0.01 );
		}
	}

	/**
	 * Test series pass skips occurrences that already have attendees.
	 *
	 * @return void
	 */
	public function test_series_pass_skips_existing_occurrence_attendees(): void {
		$existing_attendee = AttendeeFactory::create( array(
			'occurrence_id' => 10,
			'wc_order_id'   => 1,
		) );

		$occurrences = array(
			OccurrenceFactory::create( array( 'id' => 10, 'event_id' => 1 ) ),
			OccurrenceFactory::create( array( 'id' => 11, 'event_id' => 1 ) ),
			OccurrenceFactory::create( array( 'id' => 12, 'event_id' => 1 ) ),
		);

		$item  = $this->create_mock_item( 100, 1, '60.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::IS_SERIES_PASS => 'yes',
			MetaKeys::EVENT_ID       => 1,
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array( $existing_attendee ) );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( $occurrences );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$saved_occurrence_ids = array();
		$attendee_id          = 0;
		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ) use ( &$saved_occurrence_ids, &$attendee_id ): Attendee {
				$saved_occurrence_ids[] = $attendee->occurrence_id;
				$attendee->id = ++$attendee_id;
				return $attendee;
			} );

		// Only 2 attendees (occurrence 10 already exists).
		$this->attendee_repo
			->expects( $this->exactly( 2 ) )
			->method( 'save' );

		$this->creator->process( $order );

		$this->assertNotContains( 10, $saved_occurrence_ids );
		$this->assertContains( 11, $saved_occurrence_ids );
		$this->assertContains( 12, $saved_occurrence_ids );
	}

	/**
	 * Test series pass with no event ID creates no attendees.
	 *
	 * @return void
	 */
	public function test_series_pass_with_no_event_id_creates_nothing(): void {
		$item  = $this->create_mock_item( 100, 1, '60.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::IS_SERIES_PASS => 'yes',
			MetaKeys::EVENT_ID       => 0,
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->attendee_repo
			->expects( $this->never() )
			->method( 'save' );

		$this->occurrence_repo
			->expects( $this->never() )
			->method( 'for_event' );

		$result = $this->creator->process( $order );

		$this->assertArrayHasKey( 5, $result );
	}

	/**
	 * Test series pass with multiple quantity creates multiple tickets per occurrence.
	 *
	 * @return void
	 */
	public function test_series_pass_with_quantity_creates_multiple_tickets(): void {
		$occurrences = array(
			OccurrenceFactory::create( array( 'id' => 10, 'event_id' => 1 ) ),
			OccurrenceFactory::create( array( 'id' => 11, 'event_id' => 1 ) ),
		);

		$item  = $this->create_mock_item( 100, 2, '80.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::IS_SERIES_PASS => 'yes',
			MetaKeys::EVENT_ID       => 1,
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( $occurrences );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$attendee_id = 0;
		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ) use ( &$attendee_id ): Attendee {
				$attendee->id = ++$attendee_id;
				return $attendee;
			} );

		// 2 attendees (one per occurrence).
		$this->attendee_repo
			->expects( $this->exactly( 2 ) )
			->method( 'save' );

		// 4 tickets total (2 occurrences x 2 quantity).
		$this->ticket_repo
			->expects( $this->exactly( 4 ) )
			->method( 'save' );

		$this->creator->process( $order );
	}

	/**
	 * Test series pass price split with multiple quantity.
	 *
	 * For a series pass of quantity 2 at $80 for 2 occurrences:
	 * - price_per_ticket = 80/2 = $40
	 * - price_per_ticket /= 2 occurrences = $20
	 *
	 * @return void
	 */
	public function test_series_pass_price_split_with_quantity(): void {
		$occurrences = array(
			OccurrenceFactory::create( array( 'id' => 10, 'event_id' => 1 ) ),
			OccurrenceFactory::create( array( 'id' => 11, 'event_id' => 1 ) ),
		);

		$item  = $this->create_mock_item( 100, 2, '80.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::IS_SERIES_PASS => 'yes',
			MetaKeys::EVENT_ID       => 1,
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( $occurrences );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$attendee_id = 0;
		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ) use ( &$attendee_id ): Attendee {
				$attendee->id = ++$attendee_id;
				return $attendee;
			} );

		$saved_prices = array();
		$this->ticket_repo
			->method( 'save' )
			->willReturnCallback( function ( Ticket $ticket ) use ( &$saved_prices ): Ticket {
				$saved_prices[] = $ticket->price_paid;
				$ticket->id = count( $saved_prices );
				return $ticket;
			} );

		$this->creator->process( $order );

		// $80 / 2 qty = $40 per ticket, then /2 occurrences = $20.
		$this->assertCount( 4, $saved_prices );
		foreach ( $saved_prices as $price ) {
			$this->assertEqualsWithDelta( 20.0, $price, 0.01 );
		}
	}

	// =========================================================================
	// process() — Empty Order / Edge Cases
	// =========================================================================

	/**
	 * Test process returns empty array for order with no items.
	 *
	 * @return void
	 */
	public function test_process_returns_empty_for_order_with_no_items(): void {
		$order = $this->create_mock_order( 1, array() );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->attendee_repo
			->expects( $this->never() )
			->method( 'save' );

		$result = $this->creator->process( $order );

		$this->assertEmpty( $result );
	}

	/**
	 * Test process handles RuntimeException from attendee_repo save.
	 *
	 * @return void
	 */
	public function test_process_handles_runtime_exception_on_save(): void {
		$item  = $this->create_mock_item( 100, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->attendee_repo
			->method( 'save' )
			->willThrowException( new \RuntimeException( 'Database error' ) );

		// No tickets should be saved since attendee save failed.
		$this->ticket_repo
			->expects( $this->never() )
			->method( 'save' );

		// Should not throw -- the exception is caught internally.
		$result = $this->creator->process( $order );

		// Ticket type is still tracked.
		$this->assertArrayHasKey( 5, $result );
	}

	// =========================================================================
	// process() — Billing Data Extraction
	// =========================================================================

	/**
	 * Test attendee is created with correct billing data from order.
	 *
	 * @return void
	 */
	public function test_attendee_has_correct_billing_data(): void {
		$item = $this->create_mock_item( 100, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		) );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 1 );
		$order->method( 'get_billing_first_name' )->willReturn( 'Jane' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Smith' );
		$order->method( 'get_billing_email' )->willReturn( 'jane.smith@test.org' );
		$order->method( 'get_billing_phone' )->willReturn( '555-9876' );
		$order->method( 'get_meta' )->willReturnCallback( function ( string $key ) {
			if ( MetaKeys::ACCESSIBILITY_NOTES === $key ) {
				return 'Wheelchair accessible seating needed';
			}
			return '';
		} );
		$order->method( 'get_items' )->willReturn( array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$saved_attendee = null;
		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ) use ( &$saved_attendee ): Attendee {
				$saved_attendee = clone $attendee;
				$attendee->id   = 1;
				return $attendee;
			} );

		$this->creator->process( $order );

		$this->assertNotNull( $saved_attendee );
		$this->assertSame( 'Jane Smith', $saved_attendee->name );
		$this->assertSame( 'jane.smith@test.org', $saved_attendee->email );
		$this->assertSame( '555-9876', $saved_attendee->phone );
		$this->assertSame( 'Wheelchair accessible seating needed', $saved_attendee->accessibility_notes );
	}

	/**
	 * Test attendee with empty phone number stores null.
	 *
	 * @return void
	 */
	public function test_attendee_with_empty_phone_stores_null(): void {
		$item = $this->create_mock_item( 100, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		) );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 1 );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( 'john@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( '' );
		$order->method( 'get_meta' )->willReturn( '' );
		$order->method( 'get_items' )->willReturn( array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$saved_attendee = null;
		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ) use ( &$saved_attendee ): Attendee {
				$saved_attendee = clone $attendee;
				$attendee->id   = 1;
				return $attendee;
			} );

		$this->creator->process( $order );

		$this->assertNull( $saved_attendee->phone );
	}

	/**
	 * Test attendee with no accessibility notes stores null.
	 *
	 * @return void
	 */
	public function test_attendee_with_no_accessibility_notes_stores_null(): void {
		$item  = $this->create_mock_item( 100, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$saved_attendee = null;
		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ) use ( &$saved_attendee ): Attendee {
				$saved_attendee = clone $attendee;
				$attendee->id   = 1;
				return $attendee;
			} );

		$this->creator->process( $order );

		$this->assertNull( $saved_attendee->accessibility_notes );
	}

	// =========================================================================
	// process() — Ticket Record Details
	// =========================================================================

	/**
	 * Test ticket records have correct order item ID.
	 *
	 * @return void
	 */
	public function test_ticket_records_have_correct_order_item_id(): void {
		$item  = $this->create_mock_item( 100, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		), 42 );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ): Attendee {
				$attendee->id = 1;
				return $attendee;
			} );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback( function ( Ticket $ticket ): bool {
					return 42 === $ticket->wc_order_item_id;
				} )
			);

		$this->creator->process( $order );
	}

	/**
	 * Test ticket with zero ticket_type_id stores 0 on ticket, null on attendee.
	 *
	 * @return void
	 */
	public function test_ticket_with_zero_ticket_type_stores_correctly(): void {
		$item  = $this->create_mock_item( 100, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 0,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$saved_attendee = null;
		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ) use ( &$saved_attendee ): Attendee {
				$saved_attendee = clone $attendee;
				$attendee->id   = 1;
				return $attendee;
			} );

		$this->ticket_repo
			->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback( function ( Ticket $ticket ): bool {
					return 0 === $ticket->ticket_type_id;
				} )
			);

		$this->creator->process( $order );

		// Attendee ticket_type_id is null when ticket_type_id is 0 (falsy).
		$this->assertNull( $saved_attendee->ticket_type_id );
	}

	/**
	 * Test no tickets created for zero-quantity item (division protection).
	 *
	 * @return void
	 */
	public function test_no_tickets_for_zero_quantity_item(): void {
		$item  = $this->create_mock_item( 100, 0, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ): Attendee {
				$attendee->id = 1;
				return $attendee;
			} );

		// No tickets created (0 iterations in the for loop).
		$this->ticket_repo
			->expects( $this->never() )
			->method( 'save' );

		$this->creator->process( $order );
	}

	// =========================================================================
	// process() — Return Value (ticket_types_to_sync)
	// =========================================================================

	/**
	 * Test return value maps ticket type IDs to quantities.
	 *
	 * @return void
	 */
	public function test_return_value_maps_ticket_types_to_quantities(): void {
		$item1 = $this->create_mock_item( 100, 2, '50.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		), 41 );

		$item2 = $this->create_mock_item( 101, 3, '90.00', array(
			MetaKeys::TICKET_TYPE_ID => 7,
			MetaKeys::OCCURRENCE_ID  => 20,
			MetaKeys::IS_SERIES_PASS => 'no',
		), 42 );

		$order = $this->create_mock_order( 1, array( $item1, $item2 ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$attendee_id = 0;
		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ) use ( &$attendee_id ): Attendee {
				$attendee->id = ++$attendee_id;
				return $attendee;
			} );

		$result = $this->creator->process( $order );

		$this->assertSame( 2, $result[5] );
		$this->assertSame( 3, $result[7] );
	}

	/**
	 * Test return value excludes zero ticket type IDs.
	 *
	 * @return void
	 */
	public function test_return_value_excludes_zero_ticket_type_ids(): void {
		$item  = $this->create_mock_item( 100, 1, '10.00', array(
			MetaKeys::TICKET_TYPE_ID => 0,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ): Attendee {
				$attendee->id = 1;
				return $attendee;
			} );

		$result = $this->creator->process( $order );

		// ticket_type_id 0 is falsy, so it should NOT be in the result.
		$this->assertArrayNotHasKey( 0, $result );
	}

	// =========================================================================
	// process() — Hook Firing
	// =========================================================================

	/**
	 * Test ATTENDEE_CREATED action is fired after attendee creation.
	 *
	 * Uses a capturing callback since do_action is stubbed by the base test case.
	 *
	 * @return void
	 */
	public function test_attendee_created_action_is_fired(): void {
		$item  = $this->create_mock_item( 100, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ): Attendee {
				$attendee->id = 1;
				return $attendee;
			} );

		$fired_hooks = array();
		\Brain\Monkey\Functions\when( 'do_action' )->alias( function () use ( &$fired_hooks ) {
			$args              = func_get_args();
			$fired_hooks[]     = $args[0];
		} );

		$this->creator->process( $order );

		$this->assertContains( 'nettertech_events_attendee_created', $fired_hooks );
	}

	/**
	 * Test failure action is fired when attendee save throws RuntimeException.
	 *
	 * Uses a capturing callback since do_action is stubbed by the base test case.
	 *
	 * @return void
	 */
	public function test_failure_action_fired_on_runtime_exception(): void {
		$item  = $this->create_mock_item( 100, 1, '25.00', array(
			MetaKeys::TICKET_TYPE_ID => 5,
			MetaKeys::OCCURRENCE_ID  => 10,
			MetaKeys::IS_SERIES_PASS => 'no',
		) );
		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->attendee_repo
			->method( 'save' )
			->willThrowException( new \RuntimeException( 'DB error' ) );

		$fired_hooks = array();
		\Brain\Monkey\Functions\when( 'do_action' )->alias( function () use ( &$fired_hooks ) {
			$args              = func_get_args();
			$fired_hooks[]     = $args[0];
		} );

		$this->creator->process( $order );

		$this->assertContains( 'nettertech_events_attendee_creation_failed', $fired_hooks );
	}

	// =========================================================================
	// process() — Ticket ID Meta Storage
	// =========================================================================

	/**
	 * Test that _ve_ticket_ids meta is saved on the order item after ticket creation.
	 *
	 * The seating plugin reads this meta to map seats to tickets.
	 *
	 * @return void
	 */
	public function test_ticket_ids_meta_saved_on_order_item(): void {
		$meta_calls = array();
		$item       = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_id' )->willReturn( 1 );
		$item->method( 'get_product_id' )->willReturn( 100 );
		$item->method( 'get_quantity' )->willReturn( 2 );
		$item->method( 'get_total' )->willReturn( '50.00' );
		$item->method( 'get_meta' )->willReturnCallback( function ( string $key ) {
			$meta = array(
				MetaKeys::TICKET_TYPE_ID => 5,
				MetaKeys::OCCURRENCE_ID  => 10,
				MetaKeys::IS_SERIES_PASS => 'no',
			);
			return $meta[ $key ] ?? '';
		} );
		$item->method( 'add_meta_data' )->willReturnCallback(
			function ( $key, $value ) use ( &$meta_calls ) {
				$meta_calls[ $key ] = $value;
			}
		);
		$item->expects( $this->once() )->method( 'save_meta_data' );

		$order = $this->create_mock_order( 1, array( $item ) );

		$this->attendee_order
			->method( 'find_all_by_order' )
			->willReturn( array() );

		$this->product_manager
			->method( 'is_event_ticket' )
			->willReturn( true );

		$this->code_generator
			->method( 'generate' )
			->willReturn( 'CODE-XXXX-XXXX-XXXX' );

		$this->attendee_repo
			->method( 'save' )
			->willReturnCallback( function ( Attendee $attendee ): Attendee {
				$attendee->id = 1;
				return $attendee;
			} );

		$ticket_id = 0;
		$this->ticket_repo
			->method( 'save' )
			->willReturnCallback( function ( Ticket $ticket ) use ( &$ticket_id ): Ticket {
				$ticket->id = ++$ticket_id;
				return $ticket;
			} );

		$this->creator->process( $order );

		$this->assertArrayHasKey( '_nettertech_events_ticket_ids', $meta_calls );
		$this->assertSame( array( 1, 2 ), $meta_calls['_nettertech_events_ticket_ids'] );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Create a PHPUnit mock of \WC_Order with standard billing details.
	 *
	 * @param int                                                                       $order_id Order ID.
	 * @param array<\WC_Order_Item_Product|\PHPUnit\Framework\MockObject\MockObject> $items    Order items.
	 * @return \WC_Order|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_order( int $order_id, array $items ) {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( $order_id );
		$order->method( 'get_billing_first_name' )->willReturn( 'John' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_billing_email' )->willReturn( 'john@example.com' );
		$order->method( 'get_billing_phone' )->willReturn( '555-1234' );
		$order->method( 'get_meta' )->willReturn( '' );
		$order->method( 'get_items' )->willReturn( $items );

		return $order;
	}

	/**
	 * Create a PHPUnit mock of \WC_Order_Item_Product with metadata.
	 *
	 * @param int                  $product_id Product ID.
	 * @param int                  $quantity   Quantity.
	 * @param string               $total      Item total.
	 * @param array<string, mixed> $meta       Meta key-value pairs.
	 * @param int                  $item_id    Item ID.
	 * @return \WC_Order_Item_Product|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_item(
		int $product_id,
		int $quantity,
		string $total,
		array $meta,
		int $item_id = 1,
	) {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_id' )->willReturn( $item_id );
		$item->method( 'get_product_id' )->willReturn( $product_id );
		$item->method( 'get_quantity' )->willReturn( $quantity );
		$item->method( 'get_total' )->willReturn( $total );
		$item->method( 'get_meta' )->willReturnCallback( function ( string $key ) use ( $meta ) {
			return $meta[ $key ] ?? '';
		} );

		return $item;
	}
}
