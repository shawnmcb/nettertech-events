<?php
/**
 * CartValidator unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Integrations\WooCommerce\CartValidator;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;

/**
 * Test CartValidator business logic.
 */
class CartValidatorTest extends \NetterTechEventsTestCase {

	/**
	 * Ticket type repository mock.
	 *
	 * @var TicketTypeRepositoryInterface&\Mockery\MockInterface
	 */
	private $ticket_type_repo;

	/**
	 * Capacity service mock.
	 *
	 * @var CapacityServiceInterface&\Mockery\MockInterface
	 */
	private $capacity_service;

	/**
	 * Occurrence repository mock.
	 *
	 * @var OccurrenceRepositoryInterface&\Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * System under test.
	 *
	 * @var CartValidator
	 */
	private CartValidator $sut;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->ticket_type_repo = Mockery::mock( TicketTypeRepositoryInterface::class );
		$this->capacity_service = Mockery::mock( CapacityServiceInterface::class );
		$this->occurrence_repo  = Mockery::mock( OccurrenceRepositoryInterface::class );

		$this->sut = new CartValidator(
			$this->ticket_type_repo,
			$this->capacity_service,
			$this->occurrence_repo
		);

		// Default WordPress i18n stubs.
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias(
			function ( $single, $plural, $number ) {
				return $number === 1 ? $single : $plural;
			}
		);
	}

	// =========================================================================
	// validate_ticket_purchase() — Happy Path
	// =========================================================================

	/**
	 * Test valid purchase returns success.
	 *
	 * @return void
	 */
	public function test_valid_purchase_returns_success(): void {
		$ticket_type = $this->make_ticket_type( 1, 10 );
		$occurrence  = $this->make_occurrence( false );

		$this->capacity_service
			->shouldReceive( 'has_availability' )
			->once()
			->with( $ticket_type->id, 2, true )
			->andReturn( true );

		$result = $this->sut->validate_ticket_purchase( $ticket_type, $occurrence, 2 );

		$this->assertTrue( $result['valid'] );
		$this->assertNull( $result['error'] );
		$this->assertNull( $result['error_code'] );
	}

	/**
	 * Test valid purchase with existing cart quantity.
	 *
	 * @return void
	 */
	public function test_valid_purchase_with_cart_quantity(): void {
		$ticket_type = $this->make_ticket_type( 1, 10 );
		$occurrence  = $this->make_occurrence( false );

		// Net quantity = total - cart = (3+2) - 3 = 2.
		$this->capacity_service
			->shouldReceive( 'has_availability' )
			->once()
			->with( $ticket_type->id, 2, true )
			->andReturn( true );

		$result = $this->sut->validate_ticket_purchase( $ticket_type, $occurrence, 2, 3 );

		$this->assertTrue( $result['valid'] );
	}

	// =========================================================================
	// validate_ticket_purchase() — Error Cases
	// =========================================================================

	/**
	 * Test event ended returns error.
	 *
	 * @return void
	 */
	public function test_event_ended_returns_error(): void {
		$ticket_type = $this->make_ticket_type( 1, 10 );
		$occurrence  = $this->make_occurrence( true );

		$result = $this->sut->validate_ticket_purchase( $ticket_type, $occurrence, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'event_ended', $result['error_code'] );
	}

	/**
	 * Test not on sale returns error.
	 *
	 * @return void
	 */
	public function test_not_on_sale_returns_error(): void {
		$ticket_type = $this->make_ticket_type( 1, 10, false );
		$occurrence  = $this->make_occurrence( false );

		$result = $this->sut->validate_ticket_purchase( $ticket_type, $occurrence, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'not_on_sale', $result['error_code'] );
	}

	/**
	 * Test below minimum returns error.
	 *
	 * @return void
	 */
	public function test_below_minimum_returns_error(): void {
		$ticket_type = $this->make_ticket_type( 3, 10 );
		$occurrence  = $this->make_occurrence( false );

		$result = $this->sut->validate_ticket_purchase( $ticket_type, $occurrence, 2 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'below_minimum', $result['error_code'] );
	}

	/**
	 * Test above maximum returns error.
	 *
	 * @return void
	 */
	public function test_above_maximum_returns_error(): void {
		$ticket_type = $this->make_ticket_type( 1, 5 );
		$occurrence  = $this->make_occurrence( false );

		$result = $this->sut->validate_ticket_purchase( $ticket_type, $occurrence, 6 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'above_maximum', $result['error_code'] );
	}

	/**
	 * Test above maximum with cart quantity returns error.
	 *
	 * @return void
	 */
	public function test_above_maximum_with_cart_returns_error(): void {
		$ticket_type = $this->make_ticket_type( 1, 5 );
		$occurrence  = $this->make_occurrence( false );

		// Cart has 3, adding 3 = 6 > max 5.
		$result = $this->sut->validate_ticket_purchase( $ticket_type, $occurrence, 3, 3 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'above_maximum', $result['error_code'] );
	}

	/**
	 * Test sold out returns error.
	 *
	 * @return void
	 */
	public function test_sold_out_returns_error(): void {
		$ticket_type = $this->make_ticket_type( 1, 10 );
		$occurrence  = $this->make_occurrence( false );

		$this->capacity_service
			->shouldReceive( 'has_availability' )
			->once()
			->andReturn( false );

		$this->capacity_service
			->shouldReceive( 'get_capacity_summary' )
			->once()
			->andReturn( array(
				'is_sold_out'         => true,
				'effective_available' => 0,
			) );

		$result = $this->sut->validate_ticket_purchase( $ticket_type, $occurrence, 2, 0 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'sold_out', $result['error_code'] );
	}

	/**
	 * Test insufficient capacity returns error.
	 *
	 * @return void
	 */
	public function test_insufficient_capacity_returns_error(): void {
		$ticket_type = $this->make_ticket_type( 1, 10 );
		$occurrence  = $this->make_occurrence( false );

		$this->capacity_service
			->shouldReceive( 'has_availability' )
			->once()
			->andReturn( false );

		$this->capacity_service
			->shouldReceive( 'get_capacity_summary' )
			->once()
			->andReturn( array(
				'is_sold_out'         => false,
				'effective_available' => 3,
			) );

		// The quoted figure comes from the same source the gate refused on.
		$this->capacity_service
			->shouldReceive( 'get_available_count' )
			->andReturn( 3 );

		$result = $this->sut->validate_ticket_purchase( $ticket_type, $occurrence, 5, 0 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'insufficient_capacity', $result['error_code'] );
		$this->assertStringContainsString( '3', $result['error'] );
	}

	/**
	 * Test insufficient capacity shows available + cart quantity.
	 *
	 * @return void
	 */
	public function test_insufficient_capacity_shows_total_available(): void {
		$ticket_type = $this->make_ticket_type( 1, 20 );
		$occurrence  = $this->make_occurrence( false );

		$this->capacity_service
			->shouldReceive( 'has_availability' )
			->andReturn( false );

		$this->capacity_service
			->shouldReceive( 'get_capacity_summary' )
			->andReturn( array(
				'is_sold_out'         => false,
				'effective_available' => 2,
			) );

		$this->capacity_service
			->shouldReceive( 'get_available_count' )
			->andReturn( 2 );

		// Cart has 3, effective 2 → total shown = 5.
		$result = $this->sut->validate_ticket_purchase( $ticket_type, $occurrence, 10, 3 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'insufficient_capacity', $result['error_code'] );
		// Error message should contain "5" (the total available).
		$this->assertStringContainsString( '5', $result['error'] );
	}

	/**
	 * Test sold out with items already in cart returns insufficient_capacity not sold_out.
	 *
	 * @return void
	 */
	public function test_sold_out_with_cart_items_returns_insufficient_capacity(): void {
		$ticket_type = $this->make_ticket_type( 1, 10 );
		$occurrence  = $this->make_occurrence( false );

		$this->capacity_service
			->shouldReceive( 'has_availability' )
			->andReturn( false );

		$this->capacity_service
			->shouldReceive( 'get_capacity_summary' )
			->andReturn( array(
				'is_sold_out'         => true,
				'effective_available' => 0,
			) );

		$this->capacity_service
			->shouldReceive( 'get_available_count' )
			->andReturn( 0 );

		// Already has 2 in cart — should show insufficient_capacity, not sold_out.
		$result = $this->sut->validate_ticket_purchase( $ticket_type, $occurrence, 1, 2 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'insufficient_capacity', $result['error_code'] );
	}

	/**
	 * Test net zero quantity skips capacity check.
	 *
	 * @return void
	 */
	public function test_net_zero_quantity_skips_capacity_check(): void {
		$ticket_type = $this->make_ticket_type( 1, 10 );
		$occurrence  = $this->make_occurrence( false );

		// Cart already has 2, adding 0 more — no capacity check needed.
		$this->capacity_service->shouldNotReceive( 'has_availability' );

		$result = $this->sut->validate_ticket_purchase( $ticket_type, $occurrence, 0, 2 );

		$this->assertTrue( $result['valid'] );
	}

	// =========================================================================
	// validate_ticket_purchase() — Boundary Cases
	// =========================================================================

	/**
	 * Test exactly at minimum passes.
	 *
	 * @return void
	 */
	public function test_exactly_at_minimum_passes(): void {
		$ticket_type = $this->make_ticket_type( 3, 10 );
		$occurrence  = $this->make_occurrence( false );

		$this->capacity_service
			->shouldReceive( 'has_availability' )
			->andReturn( true );

		$result = $this->sut->validate_ticket_purchase( $ticket_type, $occurrence, 3 );

		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Test exactly at maximum passes.
	 *
	 * @return void
	 */
	public function test_exactly_at_maximum_passes(): void {
		$ticket_type = $this->make_ticket_type( 1, 5 );
		$occurrence  = $this->make_occurrence( false );

		$this->capacity_service
			->shouldReceive( 'has_availability' )
			->andReturn( true );

		$result = $this->sut->validate_ticket_purchase( $ticket_type, $occurrence, 5 );

		$this->assertTrue( $result['valid'] );
	}

	// =========================================================================
	// validate_batch()
	// =========================================================================

	/**
	 * Test batch validation with valid tickets.
	 *
	 * @return void
	 */
	public function test_batch_valid_tickets(): void {
		$ticket_type = $this->make_ticket_type( 1, 10 );
		$ticket_type->occurrence_id = 100;
		$ticket_type->wc_product_id = 50;

		$occurrence = $this->make_occurrence( false );

		$this->ticket_type_repo
			->shouldReceive( 'find' )
			->with( 1 )
			->andReturn( $ticket_type );

		$this->occurrence_repo
			->shouldReceive( 'find' )
			->with( 100 )
			->andReturn( $occurrence );

		$this->capacity_service
			->shouldReceive( 'has_availability' )
			->andReturn( true );

		// Mock WC()->cart for get_cart_quantity_for_ticket_type.
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => null ) );

		$result = $this->sut->validate_batch( array( 1 => 2 ) );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
		$this->assertSame( array( 1 => 2 ), $result['validated'] );
	}

	/**
	 * Test batch validation with ticket type not found.
	 *
	 * @return void
	 */
	public function test_batch_ticket_type_not_found(): void {
		$this->ticket_type_repo
			->shouldReceive( 'find' )
			->with( 999 )
			->andReturn( null );

		$result = $this->sut->validate_batch( array( 999 => 1 ) );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 999, $result['errors'] );
		$this->assertEmpty( $result['validated'] );
	}

	/**
	 * Test batch validation with no WC product ID.
	 *
	 * @return void
	 */
	public function test_batch_no_wc_product_id(): void {
		$ticket_type = $this->make_ticket_type( 1, 10 );
		$ticket_type->wc_product_id = null;

		$this->ticket_type_repo
			->shouldReceive( 'find' )
			->with( 1 )
			->andReturn( $ticket_type );

		$result = $this->sut->validate_batch( array( 1 => 2 ) );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 1, $result['errors'] );
	}

	/**
	 * Test batch validation with no occurrence found.
	 *
	 * @return void
	 */
	public function test_batch_no_occurrence(): void {
		$ticket_type = $this->make_ticket_type( 1, 10 );
		$ticket_type->wc_product_id = 50;
		$ticket_type->occurrence_id = null;
		$ticket_type->event_id      = 10;

		$this->ticket_type_repo
			->shouldReceive( 'find' )
			->with( 1 )
			->andReturn( $ticket_type );

		$this->occurrence_repo
			->shouldReceive( 'next_for_event' )
			->with( 10 )
			->andReturn( null );

		$result = $this->sut->validate_batch( array( 1 => 1 ) );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 1, $result['errors'] );
	}

	/**
	 * Test batch with event-scoped ticket uses next occurrence.
	 *
	 * @return void
	 */
	public function test_batch_event_scoped_uses_next_occurrence(): void {
		$ticket_type = $this->make_ticket_type( 1, 10 );
		$ticket_type->wc_product_id = 50;
		$ticket_type->occurrence_id = null;
		$ticket_type->event_id      = 10;

		$occurrence = $this->make_occurrence( false );
		$occurrence->id = 200;

		$this->ticket_type_repo
			->shouldReceive( 'find' )
			->with( 1 )
			->andReturn( $ticket_type );

		$this->occurrence_repo
			->shouldReceive( 'next_for_event' )
			->with( 10 )
			->andReturn( $occurrence );

		$this->capacity_service
			->shouldReceive( 'has_availability' )
			->andReturn( true );

		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => null ) );

		$result = $this->sut->validate_batch( array( 1 => 2 ) );

		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Test batch mixed valid and invalid tickets.
	 *
	 * @return void
	 */
	public function test_batch_mixed_valid_and_invalid(): void {
		$valid_type = $this->make_ticket_type( 1, 10 );
		$valid_type->wc_product_id = 50;
		$valid_type->occurrence_id = 100;

		$occurrence = $this->make_occurrence( false );

		$this->ticket_type_repo
			->shouldReceive( 'find' )
			->with( 1 )
			->andReturn( $valid_type );

		$this->ticket_type_repo
			->shouldReceive( 'find' )
			->with( 2 )
			->andReturn( null );

		$this->occurrence_repo
			->shouldReceive( 'find' )
			->with( 100 )
			->andReturn( $occurrence );

		$this->capacity_service
			->shouldReceive( 'has_availability' )
			->andReturn( true );

		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => null ) );

		$result = $this->sut->validate_batch( array( 1 => 2, 2 => 1 ) );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 2, $result['errors'] );
		$this->assertSame( array( 1 => 2 ), $result['validated'] );
	}

	// =========================================================================
	// get_cart_quantity_for_ticket_type_from_cart()
	// =========================================================================

	/**
	 * Test cart quantity with matching items.
	 *
	 * @return void
	 */
	public function test_cart_quantity_with_matching_items(): void {
		$ticket_type = new TicketType();
		$ticket_type->id             = 1;
		$ticket_type->wc_product_id  = 50;

		$this->ticket_type_repo
			->shouldReceive( 'find' )
			->with( 1 )
			->andReturn( $ticket_type );

		$cart = Mockery::mock( \WC_Cart::class );
		$cart->shouldReceive( 'get_cart' )
			->once()
			->andReturn( array(
				array( 'product_id' => 50, 'quantity' => 3 ),
				array( 'product_id' => 99, 'quantity' => 1 ),
				array( 'product_id' => 50, 'quantity' => 2 ),
			) );

		$result = $this->sut->get_cart_quantity_for_ticket_type_from_cart( $cart, 1 );

		$this->assertSame( 5, $result );
	}

	/**
	 * Test cart quantity with no matching items.
	 *
	 * @return void
	 */
	public function test_cart_quantity_with_no_matching_items(): void {
		$ticket_type = new TicketType();
		$ticket_type->id             = 1;
		$ticket_type->wc_product_id  = 50;

		$this->ticket_type_repo
			->shouldReceive( 'find' )
			->with( 1 )
			->andReturn( $ticket_type );

		$cart = Mockery::mock( \WC_Cart::class );
		$cart->shouldReceive( 'get_cart' )
			->once()
			->andReturn( array(
				array( 'product_id' => 99, 'quantity' => 1 ),
			) );

		$result = $this->sut->get_cart_quantity_for_ticket_type_from_cart( $cart, 1 );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test cart quantity with unknown ticket type returns zero.
	 *
	 * @return void
	 */
	public function test_cart_quantity_unknown_ticket_type_returns_zero(): void {
		$this->ticket_type_repo
			->shouldReceive( 'find' )
			->with( 999 )
			->andReturn( null );

		$cart = Mockery::mock( \WC_Cart::class );

		$result = $this->sut->get_cart_quantity_for_ticket_type_from_cart( $cart, 999 );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test cart quantity with no wc_product_id returns zero.
	 *
	 * @return void
	 */
	public function test_cart_quantity_no_product_id_returns_zero(): void {
		$ticket_type = new TicketType();
		$ticket_type->id             = 1;
		$ticket_type->wc_product_id  = null;

		$this->ticket_type_repo
			->shouldReceive( 'find' )
			->with( 1 )
			->andReturn( $ticket_type );

		$cart = Mockery::mock( \WC_Cart::class );

		$result = $this->sut->get_cart_quantity_for_ticket_type_from_cart( $cart, 1 );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test cart quantity with empty cart returns zero.
	 *
	 * @return void
	 */
	public function test_cart_quantity_empty_cart_returns_zero(): void {
		$ticket_type = new TicketType();
		$ticket_type->id             = 1;
		$ticket_type->wc_product_id  = 50;

		$this->ticket_type_repo
			->shouldReceive( 'find' )
			->with( 1 )
			->andReturn( $ticket_type );

		$cart = Mockery::mock( \WC_Cart::class );
		$cart->shouldReceive( 'get_cart' )
			->once()
			->andReturn( array() );

		$result = $this->sut->get_cart_quantity_for_ticket_type_from_cart( $cart, 1 );

		$this->assertSame( 0, $result );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Create a TicketType with configurable limits.
	 *
	 * @param int  $min     Min per order.
	 * @param int  $max     Max per order.
	 * @param bool $on_sale Whether ticket is on sale.
	 * @return TicketType&\Mockery\MockInterface
	 */
	private function make_ticket_type( int $min, int $max, bool $on_sale = true ) {
		$ticket_type = Mockery::mock( TicketType::class )->makePartial();
		$ticket_type->id            = 1;
		$ticket_type->min_per_order = $min;
		$ticket_type->max_per_order = $max;
		$ticket_type->wc_product_id = 50;
		$ticket_type->occurrence_id = 100;
		$ticket_type->event_id      = 10;

		$ticket_type->shouldReceive( 'is_on_sale' )->andReturn( $on_sale );

		return $ticket_type;
	}

	/**
	 * Create an Occurrence mock.
	 *
	 * @param bool $has_ended Whether the occurrence has ended.
	 * @return Occurrence&\Mockery\MockInterface
	 */
	private function make_occurrence( bool $has_ended ) {
		$occurrence = Mockery::mock( Occurrence::class )->makePartial();
		$occurrence->id = 100;
		$occurrence->shouldReceive( 'has_ended' )->andReturn( $has_ended );

		return $occurrence;
	}
}
