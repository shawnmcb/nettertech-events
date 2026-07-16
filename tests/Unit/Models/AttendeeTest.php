<?php
/**
 * Attendee model unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use NetterTechEvents\Models\Attendee;

/**
 * Test Attendee model functionality.
 */
class AttendeeTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// from_row Tests
	// =========================================================================

	/**
	 * Test from_row creates Attendee from object.
	 *
	 * @return void
	 */
	public function test_from_row_creates_attendee_from_object(): void {
		$row = (object) array(
			'id'               => 1,
			'occurrence_id'    => 10,
			'ticket_type_id'   => 5,
			'wc_order_id'      => 100,
			'name'             => 'John Doe',
			'email'            => 'john@example.com',
			'phone'            => '555-1234',
			'quantity'         => 2,
			'status'           => 'confirmed',
			'checked_in'       => 1,
			'checked_in_count' => 2,
		);

		$attendee = Attendee::from_row( $row );

		$this->assertSame( 1, $attendee->id );
		$this->assertSame( 10, $attendee->occurrence_id );
		$this->assertSame( 5, $attendee->ticket_type_id );
		$this->assertSame( 100, $attendee->wc_order_id );
		$this->assertSame( 'John Doe', $attendee->name );
		$this->assertSame( 'john@example.com', $attendee->email );
		$this->assertSame( 2, $attendee->quantity );
		$this->assertTrue( $attendee->checked_in );
		$this->assertSame( 2, $attendee->checked_in_count );
	}

	/**
	 * Test from_row handles null wc_order_id (RSVP).
	 *
	 * @return void
	 */
	public function test_from_row_handles_null_order_id(): void {
		$row = (object) array(
			'id'          => 1,
			'wc_order_id' => null,
		);

		$attendee = Attendee::from_row( $row );

		$this->assertNull( $attendee->wc_order_id );
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
		$attendee                = new Attendee();
		$attendee->occurrence_id = 5;
		$attendee->name          = 'Jane Doe';
		$attendee->email         = 'jane@example.com';
		$attendee->quantity      = 3;
		$attendee->checked_in    = true;

		$array = $attendee->to_array();

		$this->assertSame( 5, $array['occurrence_id'] );
		$this->assertSame( 'Jane Doe', $array['name'] );
		$this->assertSame( 'jane@example.com', $array['email'] );
		$this->assertSame( 3, $array['quantity'] );
		$this->assertSame( 1, $array['checked_in'] );
	}

	// =========================================================================
	// validate Tests
	// =========================================================================

	/**
	 * Test validate returns empty for valid attendee.
	 *
	 * @return void
	 */
	public function test_validate_returns_empty_for_valid_attendee(): void {
		$attendee                = new Attendee();
		$attendee->occurrence_id = 1;
		$attendee->name          = 'John Doe';
		$attendee->email         = 'john@example.com';
		$attendee->quantity      = 1;
		$attendee->status        = 'confirmed';

		$errors = $attendee->validate();

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate requires occurrence_id.
	 *
	 * @return void
	 */
	public function test_validate_requires_occurrence_id(): void {
		$attendee        = new Attendee();
		$attendee->name  = 'John';
		$attendee->email = 'john@example.com';

		$errors = $attendee->validate();

		$this->assertContains( 'Occurrence ID is required.', $errors );
	}

	/**
	 * Test validate requires name.
	 *
	 * @return void
	 */
	public function test_validate_requires_name(): void {
		$attendee                = new Attendee();
		$attendee->occurrence_id = 1;
		$attendee->email         = 'john@example.com';

		$errors = $attendee->validate();

		$this->assertContains( 'Name is required.', $errors );
	}

	/**
	 * Test validate requires email.
	 *
	 * @return void
	 */
	public function test_validate_requires_email(): void {
		$attendee                = new Attendee();
		$attendee->occurrence_id = 1;
		$attendee->name          = 'John';

		$errors = $attendee->validate();

		$this->assertContains( 'Email is required.', $errors );
	}

	/**
	 * Test validate rejects invalid email.
	 *
	 * @return void
	 */
	public function test_validate_rejects_invalid_email(): void {
		$attendee                = new Attendee();
		$attendee->occurrence_id = 1;
		$attendee->name          = 'John';
		$attendee->email         = 'not-an-email';

		$errors = $attendee->validate();

		$this->assertContains( 'Invalid email address.', $errors );
	}

	/**
	 * Test validate rejects zero quantity.
	 *
	 * @return void
	 */
	public function test_validate_rejects_zero_quantity(): void {
		$attendee                = new Attendee();
		$attendee->occurrence_id = 1;
		$attendee->name          = 'John';
		$attendee->email         = 'john@example.com';
		$attendee->quantity      = 0;

		$errors = $attendee->validate();

		$this->assertContains( 'Party size must be at least 1.', $errors );
	}

	/**
	 * Test validate rejects invalid status.
	 *
	 * @return void
	 */
	public function test_validate_rejects_invalid_status(): void {
		$attendee                = new Attendee();
		$attendee->occurrence_id = 1;
		$attendee->name          = 'John';
		$attendee->email         = 'john@example.com';
		$attendee->status        = 'invalid';

		$errors = $attendee->validate();

		$this->assertContains( 'Invalid attendee status.', $errors );
	}

	// =========================================================================
	// Check-in Tests
	// =========================================================================

	/**
	 * Test mark_checked_in sets all flags.
	 *
	 * @return void
	 */
	public function test_mark_checked_in_sets_all_flags(): void {
		$attendee           = new Attendee();
		$attendee->quantity = 3;

		$attendee->mark_checked_in();

		$this->assertTrue( $attendee->checked_in );
		$this->assertSame( 3, $attendee->checked_in_count );
		$this->assertNotNull( $attendee->checked_in_at );
	}

	/**
	 * Test mark_not_checked_in resets all flags.
	 *
	 * @return void
	 */
	public function test_mark_not_checked_in_resets_all_flags(): void {
		$attendee                   = new Attendee();
		$attendee->checked_in       = true;
		$attendee->checked_in_count = 3;
		$attendee->checked_in_at    = '2026-01-01 12:00:00';

		$attendee->mark_not_checked_in();

		$this->assertFalse( $attendee->checked_in );
		$this->assertSame( 0, $attendee->checked_in_count );
		$this->assertNull( $attendee->checked_in_at );
	}

	/**
	 * Test toggle_checked_in toggles state.
	 *
	 * @return void
	 */
	public function test_toggle_checked_in_toggles_state(): void {
		$attendee           = new Attendee();
		$attendee->quantity = 2;

		$result1 = $attendee->toggle_checked_in();
		$this->assertTrue( $result1 );
		$this->assertTrue( $attendee->checked_in );

		$result2 = $attendee->toggle_checked_in();
		$this->assertFalse( $result2 );
		$this->assertFalse( $attendee->checked_in );
	}

	/**
	 * Test increment_checked_in increments count.
	 *
	 * @return void
	 */
	public function test_increment_checked_in_increments_count(): void {
		$attendee           = new Attendee();
		$attendee->quantity = 3;

		$count1 = $attendee->increment_checked_in();
		$this->assertSame( 1, $count1 );
		$this->assertFalse( $attendee->checked_in );

		$count2 = $attendee->increment_checked_in();
		$this->assertSame( 2, $count2 );

		$count3 = $attendee->increment_checked_in();
		$this->assertSame( 3, $count3 );
		$this->assertTrue( $attendee->checked_in );
	}

	/**
	 * Test increment_checked_in does not exceed quantity.
	 *
	 * @return void
	 */
	public function test_increment_checked_in_does_not_exceed_quantity(): void {
		$attendee                   = new Attendee();
		$attendee->quantity         = 2;
		$attendee->checked_in_count = 2;

		$count = $attendee->increment_checked_in();

		$this->assertSame( 2, $count );
	}

	/**
	 * Test decrement_checked_in decrements count.
	 *
	 * @return void
	 */
	public function test_decrement_checked_in_decrements_count(): void {
		$attendee                   = new Attendee();
		$attendee->quantity         = 3;
		$attendee->checked_in_count = 2;
		$attendee->checked_in_at    = '2026-01-01 12:00:00';

		$count = $attendee->decrement_checked_in();

		$this->assertSame( 1, $count );
		$this->assertFalse( $attendee->checked_in );
	}

	/**
	 * Test decrement_checked_in does not go below zero.
	 *
	 * @return void
	 */
	public function test_decrement_checked_in_does_not_go_negative(): void {
		$attendee                   = new Attendee();
		$attendee->checked_in_count = 0;

		$count = $attendee->decrement_checked_in();

		$this->assertSame( 0, $count );
	}

	/**
	 * Test set_checked_in_count sets correct count.
	 *
	 * @return void
	 */
	public function test_set_checked_in_count_sets_correct_count(): void {
		$attendee           = new Attendee();
		$attendee->quantity = 5;

		$count = $attendee->set_checked_in_count( 3 );

		$this->assertSame( 3, $count );
		$this->assertFalse( $attendee->checked_in );

		$count = $attendee->set_checked_in_count( 5 );
		$this->assertSame( 5, $count );
		$this->assertTrue( $attendee->checked_in );
	}

	/**
	 * Test set_checked_in_count clamps to valid range.
	 *
	 * @return void
	 */
	public function test_set_checked_in_count_clamps_to_valid_range(): void {
		$attendee           = new Attendee();
		$attendee->quantity = 3;

		$count_high = $attendee->set_checked_in_count( 10 );
		$this->assertSame( 3, $count_high );

		$count_low = $attendee->set_checked_in_count( -5 );
		$this->assertSame( 0, $count_low );
	}

	// =========================================================================
	// Check-in Status Tests
	// =========================================================================

	/**
	 * Test is_fully_checked_in returns true when all checked in.
	 *
	 * @return void
	 */
	public function test_is_fully_checked_in_returns_true_when_all_checked(): void {
		$attendee                   = new Attendee();
		$attendee->quantity         = 3;
		$attendee->checked_in_count = 3;

		$this->assertTrue( $attendee->is_fully_checked_in() );
	}

	/**
	 * Test is_partially_checked_in returns true for partial.
	 *
	 * @return void
	 */
	public function test_is_partially_checked_in_returns_true_for_partial(): void {
		$attendee                   = new Attendee();
		$attendee->quantity         = 3;
		$attendee->checked_in_count = 2;

		$this->assertTrue( $attendee->is_partially_checked_in() );
	}

	/**
	 * Test is_partially_checked_in returns false when none.
	 *
	 * @return void
	 */
	public function test_is_partially_checked_in_returns_false_when_none(): void {
		$attendee                   = new Attendee();
		$attendee->quantity         = 3;
		$attendee->checked_in_count = 0;

		$this->assertFalse( $attendee->is_partially_checked_in() );
	}

	/**
	 * Test get_remaining_count calculates correctly.
	 *
	 * @return void
	 */
	public function test_get_remaining_count_calculates_correctly(): void {
		$attendee                   = new Attendee();
		$attendee->quantity         = 5;
		$attendee->checked_in_count = 2;

		$this->assertSame( 3, $attendee->get_remaining_count() );
	}

	// =========================================================================
	// Payment Status Tests
	// =========================================================================

	/**
	 * Test is_paid returns true with order ID.
	 *
	 * @return void
	 */
	public function test_is_paid_returns_true_with_order_id(): void {
		$attendee              = new Attendee();
		$attendee->wc_order_id = 100;

		$this->assertTrue( $attendee->is_paid() );
	}

	/**
	 * Test is_rsvp returns true when source is rsvp.
	 *
	 * @return void
	 */
	public function test_is_rsvp_returns_true_when_source_is_rsvp(): void {
		$attendee         = new Attendee();
		$attendee->source = 'rsvp';

		$this->assertTrue( $attendee->is_rsvp() );
	}

	/**
	 * Test is_rsvp returns false when source is woocommerce.
	 *
	 * @return void
	 */
	public function test_is_rsvp_returns_false_when_source_is_woocommerce(): void {
		$attendee         = new Attendee();
		$attendee->source = 'woocommerce';

		$this->assertFalse( $attendee->is_rsvp() );
	}

	// =========================================================================
	// Display Helper Tests
	// =========================================================================

	/**
	 * Test get_display_name returns first name.
	 *
	 * @return void
	 */
	public function test_get_display_name_returns_first_name(): void {
		$attendee       = new Attendee();
		$attendee->name = 'John Michael Doe';

		$this->assertSame( 'John', $attendee->get_display_name() );
	}

	/**
	 * Test get_check_in_line returns correct format.
	 *
	 * @return void
	 */
	public function test_get_check_in_line_returns_correct_format(): void {
		$attendee           = new Attendee();
		$attendee->name     = 'John Doe';
		$attendee->email    = 'john@example.com';
		$attendee->quantity = 3;

		$line = $attendee->get_check_in_line();

		$this->assertStringContainsString( 'John Doe', $line );
		$this->assertStringContainsString( 'john@example.com', $line );
		$this->assertStringContainsString( '3', $line );
		$this->assertStringContainsString( '▢▢▢', $line );
	}

	/**
	 * Test get_check_in_line_with_status includes checkbox indicator.
	 *
	 * @return void
	 */
	public function test_get_check_in_line_with_status_includes_indicator(): void {
		$attendee             = new Attendee();
		$attendee->name       = 'John';
		$attendee->email      = 'john@example.com';
		$attendee->quantity   = 1;
		$attendee->checked_in = true;

		$line = $attendee->get_check_in_line_with_status();

		$this->assertStringContainsString( '☑', $line );

		$attendee->checked_in = false;
		$line                 = $attendee->get_check_in_line_with_status();

		$this->assertStringContainsString( '☐', $line );
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
		$this->assertContains( 'pending', Attendee::STATUSES );
		$this->assertContains( 'confirmed', Attendee::STATUSES );
		$this->assertContains( 'cancelled', Attendee::STATUSES );
		$this->assertContains( 'refunded', Attendee::STATUSES );
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
		$attendee                = new Attendee();
		$attendee->occurrence_id = 1;
		$attendee->email         = 'test@example.com';
		$formats                 = $attendee->get_formats();
		$array                   = $attendee->to_array();

		$this->assertCount( count( $array ), $formats );
	}
}
