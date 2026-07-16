<?php
/**
 * TicketType model unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use NetterTechEvents\Models\TicketType;

/**
 * Test TicketType model functionality.
 */
class TicketTypeTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// from_row Tests
	// =========================================================================

	/**
	 * Test from_row creates TicketType from object.
	 *
	 * @return void
	 */
	public function test_from_row_creates_ticket_type_from_object(): void {
		$row = (object) array(
			'id'            => 1,
			'occurrence_id' => 10,
			'name'          => 'VIP',
			'price'         => 99.99,
			'capacity'      => 50,
			'sold_count'    => 10,
			'stock_status'  => 'in_stock',
			'min_per_order' => 1,
			'max_per_order' => 4,
			'status'        => 'active',
		);

		$type = TicketType::from_row( $row );

		$this->assertSame( 1, $type->id );
		$this->assertSame( 10, $type->occurrence_id );
		$this->assertSame( 'VIP', $type->name );
		$this->assertSame( 99.99, $type->price );
		$this->assertSame( 50, $type->capacity );
		$this->assertSame( 10, $type->sold_count );
		$this->assertSame( 1, $type->min_per_order );
		$this->assertSame( 4, $type->max_per_order );
	}

	/**
	 * Test from_row handles null capacity.
	 *
	 * @return void
	 */
	public function test_from_row_handles_null_capacity(): void {
		$row = (object) array(
			'id'       => 1,
			'capacity' => null,
		);

		$type = TicketType::from_row( $row );

		$this->assertNull( $type->capacity );
	}

	// =========================================================================
	// to_array Tests
	// =========================================================================

	/**
	 * Test to_array returns correct structure.
	 *
	 * @return void
	 */
	public function test_to_array_returns_correct_structure(): void {
		$type                = new TicketType();
		$type->occurrence_id = 5;
		$type->name          = 'General';
		$type->price         = 25.00;
		$type->capacity      = 100;

		$array = $type->to_array();

		$this->assertSame( 5, $array['occurrence_id'] );
		$this->assertSame( 'General', $array['name'] );
		$this->assertSame( 25.00, $array['price'] );
		$this->assertSame( 100, $array['capacity'] );
	}

	// =========================================================================
	// is_on_sale Tests
	// =========================================================================

	/**
	 * Build an active tier with the given sale window.
	 *
	 * @param string|null $start Sale start wall-clock, or null.
	 * @param string|null $end   Sale end wall-clock, or null.
	 * @return TicketType
	 */
	private function tier_with_window( ?string $start, ?string $end ): TicketType {
		$type             = new TicketType();
		$type->status     = 'active';
		$type->sale_start = $start;
		$type->sale_end   = $end;

		return $type;
	}

	/**
	 * Test the sale window is read in the event's timezone, not the site's.
	 *
	 * The regression NTE-148 was filed for. A window is stored as bare wall-clock; whose
	 * clock it means decides when the sale opens. Sydney is far enough ahead of Chicago
	 * that the same wall-clock string is a different moment entirely, so a cutoff five
	 * hours off in Chicago has already passed in Sydney.
	 *
	 * @return void
	 */
	public function test_is_on_sale_reads_the_window_in_the_events_timezone(): void {
		$chicago = new \DateTimeZone( 'America/Chicago' );
		$sydney  = new \DateTimeZone( 'Australia/Sydney' );

		\Brain\Monkey\Functions\when( 'wp_timezone' )->justReturn( $chicago );

		// A cutoff five hours from now, on the Chicago wall clock.
		$cutoff = ( new \DateTimeImmutable( 'now', $chicago ) )
			->modify( '+5 hours' )
			->format( 'Y-m-d H:i:s' );

		$tier = $this->tier_with_window( null, $cutoff );

		// Read on the site's clock: five hours left, still selling.
		$this->assertTrue( $tier->is_on_sale( $chicago ) );

		// Read on the event's clock: Sydney is ~15 hours ahead, so that same wall-clock
		// moment is long gone. The sale is closed there — and the event is what counts.
		$this->assertFalse( $tier->is_on_sale( $sydney ) );
	}

	/**
	 * Test the site timezone is the default, so existing callers are unaffected.
	 *
	 * @return void
	 */
	public function test_is_on_sale_defaults_to_the_site_timezone(): void {
		$chicago = new \DateTimeZone( 'America/Chicago' );

		\Brain\Monkey\Functions\when( 'wp_timezone' )->justReturn( $chicago );

		$past = ( new \DateTimeImmutable( 'now', $chicago ) )
			->modify( '-1 hour' )
			->format( 'Y-m-d H:i:s' );

		$this->assertFalse( $this->tier_with_window( null, $past )->is_on_sale() );
		$this->assertTrue( $this->tier_with_window( $past, null )->is_on_sale() );
	}

	/**
	 * Test an open-ended window sells, and an inactive tier never does.
	 *
	 * @return void
	 */
	public function test_is_on_sale_handles_absent_bounds_and_inactive_status(): void {
		\Brain\Monkey\Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$this->assertTrue( $this->tier_with_window( null, null )->is_on_sale() );

		$inactive         = $this->tier_with_window( null, null );
		$inactive->status = 'inactive';
		$this->assertFalse( $inactive->is_on_sale() );
	}

	/**
	 * Test an unreadable stored bound is treated as absent, not as a closed sale.
	 *
	 * A window we cannot parse must not silently refuse every purchase.
	 *
	 * @return void
	 */
	public function test_is_on_sale_ignores_an_unreadable_bound(): void {
		\Brain\Monkey\Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$this->assertTrue( $this->tier_with_window( null, 'not-a-datetime' )->is_on_sale() );
	}

	// =========================================================================
	// normalize_capacity Tests
	// =========================================================================

	/**
	 * Test a fixed tier keeps the capacity it owns.
	 *
	 * @return void
	 */
	public function test_normalize_capacity_keeps_a_fixed_tiers_capacity(): void {
		$type                = new TicketType();
		$type->capacity_type = 'fixed';
		$type->capacity      = 50;

		$type->normalize_capacity();

		$this->assertSame( 50, $type->capacity );
	}

	/**
	 * Test a non-fixed tier cannot carry a capacity.
	 *
	 * The admin form hid the capacity input without disabling it, so a value typed
	 * while the tier was fixed survived the switch to shared and was stored — read
	 * back later as a 999-seat allotment nobody granted (ADR-019).
	 *
	 * @dataProvider non_fixed_capacity_types
	 *
	 * @param string $capacity_type The tier's capacity type.
	 * @return void
	 */
	public function test_normalize_capacity_clears_a_stale_value( string $capacity_type ): void {
		$type                = new TicketType();
		$type->capacity_type = $capacity_type;
		$type->capacity      = 999;

		$type->normalize_capacity();

		$this->assertNull( $type->capacity );
	}

	/**
	 * Capacity types that own no capacity of their own.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function non_fixed_capacity_types(): array {
		return array(
			'shared'    => array( 'shared' ),
			'unlimited' => array( 'unlimited' ),
			'seated'    => array( 'seated' ),
		);
	}

	/**
	 * Test an unrecognised capacity type is treated as fixed and keeps its value.
	 *
	 * @return void
	 */
	public function test_normalize_capacity_leaves_an_unknown_type_alone(): void {
		$type                = new TicketType();
		$type->capacity_type = 'not-a-real-type';
		$type->capacity      = 25;

		$type->normalize_capacity();

		$this->assertSame( 25, $type->capacity );
	}

	// =========================================================================
	// validate Tests
	// =========================================================================

	/**
	 * Test validate returns empty for valid ticket type.
	 *
	 * @return void
	 */
	public function test_validate_returns_empty_for_valid_ticket_type(): void {
		$type                = new TicketType();
		$type->occurrence_id = 1;
		$type->name          = 'General';
		$type->price         = 25.00;
		$type->status        = 'active';

		$errors = $type->validate();

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate requires occurrence_id.
	 *
	 * @return void
	 */
	public function test_validate_requires_occurrence_id(): void {
		$type       = new TicketType();
		$type->name = 'General';

		$errors = $type->validate();

		$this->assertContains( 'Occurrence ID is required for occurrence-scoped tickets.', $errors );
	}

	/**
	 * Test validate requires name.
	 *
	 * @return void
	 */
	public function test_validate_requires_name(): void {
		$type                = new TicketType();
		$type->occurrence_id = 1;

		$errors = $type->validate();

		$this->assertContains( 'Ticket type name is required.', $errors );
	}

	/**
	 * Test validate rejects negative price.
	 *
	 * @return void
	 */
	public function test_validate_rejects_negative_price(): void {
		$type                = new TicketType();
		$type->occurrence_id = 1;
		$type->name          = 'Test';
		$type->price         = -10.00;

		$errors = $type->validate();

		$this->assertContains( 'Price cannot be negative.', $errors );
	}

	/**
	 * Test validate rejects invalid status.
	 *
	 * @return void
	 */
	public function test_validate_rejects_invalid_status(): void {
		$type                = new TicketType();
		$type->occurrence_id = 1;
		$type->name          = 'Test';
		$type->status        = 'invalid';

		$errors = $type->validate();

		$this->assertContains( 'Invalid ticket type status.', $errors );
	}

	// =========================================================================
	// Price Helper Tests
	// =========================================================================

	/**
	 * Test is_free returns true for zero price.
	 *
	 * @return void
	 */
	public function test_is_free_returns_true_for_zero_price(): void {
		$type        = new TicketType();
		$type->price = 0.00;

		$this->assertTrue( $type->is_free() );
	}

	/**
	 * Test is_free returns false for positive price.
	 *
	 * @return void
	 */
	public function test_is_free_returns_false_for_positive_price(): void {
		$type        = new TicketType();
		$type->price = 25.00;

		$this->assertFalse( $type->is_free() );
	}

	/**
	 * Test get_formatted_price returns Free for zero price.
	 *
	 * @return void
	 */
	public function test_get_formatted_price_returns_free_for_zero(): void {
		$type        = new TicketType();
		$type->price = 0.00;

		$formatted = $type->get_formatted_price();

		$this->assertSame( 'Free', $formatted );
	}

	/**
	 * Test get_formatted_price uses wc_price when available.
	 *
	 * @return void
	 */
	public function test_get_formatted_price_formats_price(): void {
		$type        = new TicketType();
		$type->price = 25.50;

		$formatted = $type->get_formatted_price();

		$this->assertSame( '$25.50', $formatted );
	}

	// =========================================================================
	// Stock Status Tests
	// =========================================================================

	/**
	 * Test is_sold_out returns true for out_of_stock.
	 *
	 * @return void
	 */
	public function test_is_sold_out_returns_true_for_out_of_stock(): void {
		$type               = new TicketType();
		$type->stock_status = 'out_of_stock';

		$this->assertTrue( $type->is_sold_out() );
	}

	/**
	 * Test is_sold_out returns false for in_stock.
	 *
	 * @return void
	 */
	public function test_is_sold_out_returns_false_for_in_stock(): void {
		$type               = new TicketType();
		$type->stock_status = 'in_stock';

		$this->assertFalse( $type->is_sold_out() );
	}

	/**
	 * Test is_low_stock returns true for low_stock.
	 *
	 * @return void
	 */
	public function test_is_low_stock_returns_true_for_low_stock(): void {
		$type               = new TicketType();
		$type->stock_status = 'low_stock';

		$this->assertTrue( $type->is_low_stock() );
	}

	// =========================================================================
	// Capacity Tests
	// =========================================================================

	/**
	 * Test get_available_count returns null for unlimited.
	 *
	 * @return void
	 */
	public function test_get_available_count_returns_null_for_unlimited(): void {
		$type           = new TicketType();
		$type->capacity = null;

		$this->assertNull( $type->get_available_count() );
	}

	/**
	 * Test get_available_count calculates correctly.
	 *
	 * @return void
	 */
	public function test_get_available_count_calculates_correctly(): void {
		$type             = new TicketType();
		$type->capacity   = 100;
		$type->sold_count = 75;

		$this->assertSame( 25, $type->get_available_count() );
	}

	/**
	 * Test get_available_count never goes negative.
	 *
	 * @return void
	 */
	public function test_get_available_count_never_negative(): void {
		$type             = new TicketType();
		$type->capacity   = 50;
		$type->sold_count = 100;

		$this->assertSame( 0, $type->get_available_count() );
	}

	/**
	 * Test has_availability returns true for unlimited.
	 *
	 * @return void
	 */
	public function test_has_availability_returns_true_for_unlimited(): void {
		$type           = new TicketType();
		$type->capacity = null;

		$this->assertTrue( $type->has_availability( 1000 ) );
	}

	/**
	 * Test has_availability returns true when available.
	 *
	 * @return void
	 */
	public function test_has_availability_returns_true_when_available(): void {
		$type             = new TicketType();
		$type->capacity   = 100;
		$type->sold_count = 50;

		$this->assertTrue( $type->has_availability( 25 ) );
	}

	/**
	 * Test has_availability returns false when insufficient.
	 *
	 * @return void
	 */
	public function test_has_availability_returns_false_when_insufficient(): void {
		$type             = new TicketType();
		$type->capacity   = 100;
		$type->sold_count = 90;

		$this->assertFalse( $type->has_availability( 25 ) );
	}

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test STATUSES constant contains expected values.
	 *
	 * @return void
	 */
	public function test_statuses_constant_contains_expected_values(): void {
		$this->assertContains( 'active', TicketType::STATUSES );
		$this->assertContains( 'inactive', TicketType::STATUSES );
		$this->assertContains( 'sold_out', TicketType::STATUSES );
	}

	// =========================================================================
	// get_formats Tests
	// =========================================================================

	/**
	 * Test get_formats returns correct number of format specifiers.
	 *
	 * @return void
	 */
	public function test_get_formats_returns_correct_count(): void {
		$type                = new TicketType();
		$type->occurrence_id = 1;
		$type->name          = 'General';
		$formats             = $type->get_formats();
		$array               = $type->to_array();

		$this->assertCount( count( $array ), $formats );
	}
}
