<?php
/**
 * CartPresenter unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey\Functions;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Integrations\WooCommerce\CartPresenter;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;
use NetterTechEvents\Tests\Factories\TicketTypeFactory;

/**
 * Test CartPresenter functionality.
 *
 * Tests cart item name modification, item data display, and order item meta.
 */
class CartPresenterTest extends \NetterTechEventsTestCase {

	/**
	 * Presenter under test.
	 *
	 * @var CartPresenter
	 */
	private CartPresenter $presenter;

	/**
	 * Mock product manager.
	 *
	 * @var ProductManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $product_manager;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->product_manager = $this->createMock( ProductManager::class );
		$this->presenter       = new CartPresenter( $this->product_manager );

		OccurrenceFactory::reset();
		TicketTypeFactory::reset();

		// Occurrence::get_formatted_date/time need date/time format options.
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			return match ( $key ) {
				'date_format' => 'F j, Y',
				'time_format' => 'g:i a',
				default       => $default,
			};
		} );
	}

	// =========================================================================
	// modify_cart_item_name() Tests
	// =========================================================================

	/**
	 * Test returns name unchanged when product is null.
	 *
	 * @return void
	 */
	public function test_modify_cart_item_name_returns_unchanged_when_no_product(): void {
		$result = $this->presenter->modify_cart_item_name( 'Test Product', [], 'key-1' );

		$this->assertSame( 'Test Product', $result );
	}

	/**
	 * Test returns name unchanged when product is not event ticket.
	 *
	 * @return void
	 */
	public function test_modify_cart_item_name_returns_unchanged_for_non_ticket(): void {
		$product = $this->createMock( \WC_Product::class );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( false );

		$result = $this->presenter->modify_cart_item_name(
			'Regular Product',
			[ 'data' => $product ],
			'key-1'
		);

		$this->assertSame( 'Regular Product', $result );
	}

	/**
	 * Test wraps name in link when product is event ticket with occurrence.
	 *
	 * @return void
	 */
	public function test_modify_cart_item_name_wraps_in_link_for_ticket(): void {
		$product    = $this->createMock( \WC_Product::class );
		$event      = EventFactory::create();
		$occurrence = OccurrenceFactory::create();
		$occurrence->set_event( $event );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );

		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/event/test' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$result = $this->presenter->modify_cart_item_name(
			'Event Ticket',
			[ 'data' => $product ],
			'key-1'
		);

		$this->assertStringContainsString( '<a href=', $result );
		$this->assertStringContainsString( 'Event Ticket', $result );
	}

	// =========================================================================
	// display_cart_item_data() Tests
	// =========================================================================

	/**
	 * Test returns item data unchanged when no product.
	 *
	 * @return void
	 */
	public function test_display_cart_item_data_returns_unchanged_when_no_product(): void {
		$result = $this->presenter->display_cart_item_data( [], [] );

		$this->assertEmpty( $result );
	}

	/**
	 * Test returns item data unchanged for non-ticket.
	 *
	 * @return void
	 */
	public function test_display_cart_item_data_returns_unchanged_for_non_ticket(): void {
		$product = $this->createMock( \WC_Product::class );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( false );

		$result = $this->presenter->display_cart_item_data( [], [ 'data' => $product ] );

		$this->assertEmpty( $result );
	}

	/**
	 * Test adds date, time, and ticket type for event ticket.
	 *
	 * @return void
	 */
	public function test_display_cart_item_data_adds_event_details(): void {
		$product     = $this->createMock( \WC_Product::class );
		$event       = EventFactory::create();
		$occurrence  = OccurrenceFactory::create();
		$occurrence->set_event( $event );
		$ticket_type = TicketTypeFactory::create( [ 'name' => 'VIP' ] );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );

		$result = $this->presenter->display_cart_item_data( [], [ 'data' => $product ] );

		$this->assertGreaterThanOrEqual( 3, count( $result ) );

		$keys = array_column( $result, 'key' );
		$this->assertContains( 'Date', $keys );
		$this->assertContains( 'Time', $keys );
		$this->assertContains( 'Ticket Type', $keys );
	}

	/**
	 * Test adds venue when event has venue name.
	 *
	 * @return void
	 */
	public function test_display_cart_item_data_adds_venue_when_present(): void {
		$product    = $this->createMock( \WC_Product::class );
		$event      = EventFactory::create( [ 'venue_name' => 'The Grand Hall' ] );
		$occurrence = OccurrenceFactory::create();
		$occurrence->set_event( $event );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( null );

		$result = $this->presenter->display_cart_item_data( [], [ 'data' => $product ] );

		$keys = array_column( $result, 'key' );
		$this->assertContains( 'Venue', $keys );
	}

	// =========================================================================
	// add_order_item_meta() Tests
	// =========================================================================

	/**
	 * Test does nothing for non-ticket product.
	 *
	 * @return void
	 */
	public function test_add_order_item_meta_does_nothing_for_non_ticket(): void {
		$item    = $this->createMock( \WC_Order_Item_Product::class );
		$order   = $this->createMock( \WC_Order::class );
		$product = $this->createMock( \WC_Product::class );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( false );

		$item->expects( $this->never() )->method( 'add_meta_data' );

		$this->presenter->add_order_item_meta(
			$item,
			'key-1',
			[ 'data' => $product ],
			$order
		);
	}

	/**
	 * Test adds occurrence and ticket type meta for ticket.
	 *
	 * @return void
	 */
	public function test_add_order_item_meta_adds_meta_for_ticket(): void {
		$item    = $this->createMock( \WC_Order_Item_Product::class );
		$order   = $this->createMock( \WC_Order::class );
		$product = $this->createMock( \WC_Product::class );

		$event       = EventFactory::create();
		$occurrence  = OccurrenceFactory::create();
		$occurrence->set_event( $event );
		$ticket_type = TicketTypeFactory::create( [ 'name' => 'GA' ] );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( $ticket_type );

		$item->expects( $this->atLeast( 3 ) )->method( 'add_meta_data' );

		$this->presenter->add_order_item_meta(
			$item,
			'key-1',
			[ 'data' => $product ],
			$order
		);
	}

	/**
	 * Test a series-pass product (no occurrence) stamps the pass marker and event id.
	 *
	 * The order pipeline reads these to fan one attendee out to every date the pass
	 * spans (NTE-156).
	 *
	 * @return void
	 */
	public function test_add_order_item_meta_stamps_series_pass_marker(): void {
		$item    = $this->createMock( \WC_Order_Item_Product::class );
		$order   = $this->createMock( \WC_Order::class );
		$product = $this->createMock( \WC_Product::class );

		$product->method( 'get_meta' )->willReturnCallback(
			static function ( $key ) {
				return match ( $key ) {
					MetaKeys::IS_SERIES_PASS => 'yes',
					MetaKeys::EVENT_ID       => '5',
					default                  => '',
				};
			}
		);

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		// A pass has no single occurrence.
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( null );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( null );

		Functions\when( '__' )->alias( static fn( $text ) => $text );

		$stamped = array();
		$item->method( 'add_meta_data' )->willReturnCallback(
			static function ( $key, $value ) use ( &$stamped ) {
				$stamped[ $key ] = $value;
			}
		);

		$this->presenter->add_order_item_meta(
			$item,
			'key-1',
			array( 'data' => $product ),
			$order
		);

		$this->assertSame( 'yes', $stamped[ MetaKeys::IS_SERIES_PASS ] );
		$this->assertSame( '5', $stamped[ MetaKeys::EVENT_ID ] );
	}

	/**
	 * Test a non-pass product with no occurrence receives neither pass meta.
	 *
	 * @return void
	 */
	public function test_add_order_item_meta_skips_non_pass_without_occurrence(): void {
		$item    = $this->createMock( \WC_Order_Item_Product::class );
		$order   = $this->createMock( \WC_Order::class );
		$product = $this->createMock( \WC_Product::class );

		// Not a series pass, and no occurrence resolvable.
		$product->method( 'get_meta' )->willReturn( '' );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( null );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( null );

		$item->expects( $this->never() )->method( 'add_meta_data' );

		$this->presenter->add_order_item_meta(
			$item,
			'key-1',
			array( 'data' => $product ),
			$order
		);
	}

	// =========================================================================
	// Series-pass stamping — mutation hardening (NTE-156)
	//
	// These target the surviving Infection mutants on the pass-stamping block
	// (lines 141-147): the two get_meta( ..., true ) single-value flags, the
	// (int) cast on the event id, the $event_id > 0 guard, and the label's
	// add_meta_data( ..., true ) unique flag plus its outright removal.
	// =========================================================================

	/**
	 * A product whose pass meta is only correct when read as a single value.
	 *
	 * get_meta() with $single = false returns an array in WooCommerce, so a stamping
	 * block that drops the single flag reads the wrong shape. Modelling that here lets
	 * the TrueValue mutants (get_meta( ..., false )) be observed: they mis-read the
	 * marker and the event id and stamp nothing.
	 *
	 * @param string $event_id_raw Raw event-id meta to return for the single read.
	 * @return \WC_Product&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function pass_product( string $event_id_raw ): object {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_meta' )->willReturnCallback(
			static function ( $key, $single = false ) use ( $event_id_raw ) {
				if ( true !== $single ) {
					// Wrong (array) shape — a dropped single flag lands here.
					return array();
				}

				return match ( $key ) {
					MetaKeys::IS_SERIES_PASS => 'yes',
					MetaKeys::EVENT_ID       => $event_id_raw,
					default                  => '',
				};
			}
		);

		return $product;
	}

	/**
	 * Test the pass block stamps the exact marker, cast event id, and human label.
	 *
	 * Kills: both get_meta TrueValue mutants (dropping the single flag mis-reads the
	 * marker and id, stamping nothing); the CastInt mutant (raw '05' would stamp as
	 * '05' rather than the cast '5'); the label MethodCallRemoval; and the label
	 * add_meta_data TrueValue (the unique flag must be true).
	 *
	 * @return void
	 */
	public function test_series_pass_stamps_exact_meta_keys_values_and_label(): void {
		$item    = $this->createMock( \WC_Order_Item_Product::class );
		$order   = $this->createMock( \WC_Order::class );
		// Raw meta '05' proves the (int) cast fires: the stamped value must be '5'.
		$product = $this->pass_product( '05' );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( null );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( null );

		Functions\when( '__' )->alias( static fn( $text ) => $text );

		$stamped = array();
		$item->method( 'add_meta_data' )->willReturnCallback(
			static function ( $key, $value, $unique = false ) use ( &$stamped ) {
				$stamped[] = array(
					'key'    => $key,
					'value'  => $value,
					'unique' => $unique,
				);
			}
		);

		$this->presenter->add_order_item_meta(
			$item,
			'key-1',
			array( 'data' => $product ),
			$order
		);

		$by_key = array();
		foreach ( $stamped as $entry ) {
			$by_key[ $entry['key'] ] = $entry;
		}

		$this->assertArrayHasKey( MetaKeys::IS_SERIES_PASS, $by_key );
		$this->assertSame( 'yes', $by_key[ MetaKeys::IS_SERIES_PASS ]['value'] );

		$this->assertArrayHasKey( MetaKeys::EVENT_ID, $by_key );
		$this->assertSame( '5', $by_key[ MetaKeys::EVENT_ID ]['value'], 'The event id must be cast before stamping.' );

		$this->assertArrayHasKey( 'Ticket', $by_key, 'The human-readable pass label must be stamped.' );
		$this->assertSame(
			'Series Pass — valid for every date of this event',
			$by_key['Ticket']['value']
		);
		$this->assertTrue( $by_key['Ticket']['unique'], 'The label must be stamped as a unique meta entry.' );
	}

	/**
	 * Test a series pass with a zero event id stamps nothing.
	 *
	 * The block guards on $event_id > 0. Kills the GreaterThan mutant ($event_id >= 0),
	 * which would enter the block for event id 0 and stamp meta that anchors to no event.
	 *
	 * @return void
	 */
	public function test_series_pass_with_zero_event_id_stamps_nothing(): void {
		$item    = $this->createMock( \WC_Order_Item_Product::class );
		$order   = $this->createMock( \WC_Order::class );
		$product = $this->pass_product( '0' );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( null );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( null );

		Functions\when( '__' )->alias( static fn( $text ) => $text );

		$item->expects( $this->never() )->method( 'add_meta_data' );

		$this->presenter->add_order_item_meta(
			$item,
			'key-1',
			array( 'data' => $product ),
			$order
		);
	}

	/**
	 * Test the pass block never fires when the product already has an occurrence.
	 *
	 * The guard leads with ! $occurrence. Kills the LogicalAnd mutant that relaxes it
	 * to ! $occurrence || 'yes' === ...: with an occurrence present, that mutant would
	 * still stamp the series-pass marker onto an ordinary single-date line.
	 *
	 * @return void
	 */
	public function test_series_pass_marker_not_stamped_when_occurrence_present(): void {
		$item    = $this->createMock( \WC_Order_Item_Product::class );
		$order   = $this->createMock( \WC_Order::class );
		// Pass meta is present, but so is a real occurrence — the marker must NOT be stamped.
		$product = $this->pass_product( '7' );

		$occurrence = OccurrenceFactory::create(
			array(
				'id'             => 50,
				'start_datetime' => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => ( new \DateTimeImmutable( '+1 day +2 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$occurrence->set_event( EventFactory::create() );

		$this->product_manager->method( 'is_event_ticket' )->willReturn( true );
		$this->product_manager->method( 'get_occurrence_from_product' )->willReturn( $occurrence );
		$this->product_manager->method( 'get_ticket_type_from_product' )->willReturn( null );

		Functions\when( '__' )->alias( static fn( $text ) => $text );

		$stamped_keys = array();
		$item->method( 'add_meta_data' )->willReturnCallback(
			static function ( $key ) use ( &$stamped_keys ) {
				$stamped_keys[] = $key;
			}
		);

		$this->presenter->add_order_item_meta(
			$item,
			'key-1',
			array( 'data' => $product ),
			$order
		);

		$this->assertNotContains(
			MetaKeys::IS_SERIES_PASS,
			$stamped_keys,
			'A line with its own occurrence must not be stamped as a series pass.'
		);
	}
}
