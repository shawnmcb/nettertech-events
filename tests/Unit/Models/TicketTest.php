<?php
/**
 * Ticket model unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use NetterTechEvents\Models\Ticket;

/**
 * Test Ticket model functionality.
 *
 * @covers \NetterTechEvents\Models\Ticket
 */
class TicketTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// from_row Tests
	// =========================================================================

	/**
	 * Test from_row creates Ticket from valid data.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::from_row
	 * @return void
	 */
	public function test_from_row_creates_ticket_from_valid_data(): void {
		$row = (object) array(
			'id'               => 1,
			'ticket_type_id'   => 5,
			'occurrence_id'    => 10,
			'attendee_id'      => 20,
			'wc_order_id'      => 100,
			'wc_order_item_id' => 200,
			'ticket_code'      => 'ABC123',
			'qr_code_url'      => 'http://example.com/qr/ABC123.png',
			'status'           => 'confirmed',
			'checked_in_at'    => null,
			'price_paid'       => 25.00,
			'created_at'       => '2026-01-01 00:00:00',
			'updated_at'       => '2026-01-02 00:00:00',
		);

		$ticket = Ticket::from_row( $row );

		$this->assertSame( 1, $ticket->id );
		$this->assertSame( 5, $ticket->ticket_type_id );
		$this->assertSame( 10, $ticket->occurrence_id );
		$this->assertSame( 20, $ticket->attendee_id );
		$this->assertSame( 100, $ticket->wc_order_id );
		$this->assertSame( 200, $ticket->wc_order_item_id );
		$this->assertSame( 'ABC123', $ticket->ticket_code );
		$this->assertSame( 'http://example.com/qr/ABC123.png', $ticket->qr_code_url );
		$this->assertSame( 'confirmed', $ticket->status );
		$this->assertNull( $ticket->checked_in_at );
		$this->assertSame( 25.00, $ticket->price_paid );
		$this->assertSame( '2026-01-01 00:00:00', $ticket->created_at );
		$this->assertSame( '2026-01-02 00:00:00', $ticket->updated_at );
	}

	/**
	 * Test from_row handles missing optional attendee_id.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::from_row
	 * @return void
	 */
	public function test_from_row_handles_missing_attendee_id(): void {
		$row = (object) array(
			'id'             => 2,
			'ticket_type_id' => 3,
			'occurrence_id'  => 7,
		);

		$ticket = Ticket::from_row( $row );

		$this->assertNull( $ticket->attendee_id );
	}

	/**
	 * Test from_row handles missing optional wc_order_id.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::from_row
	 * @return void
	 */
	public function test_from_row_handles_missing_wc_order_id(): void {
		$row = (object) array(
			'id'             => 3,
			'ticket_type_id' => 1,
			'occurrence_id'  => 2,
		);

		$ticket = Ticket::from_row( $row );

		$this->assertNull( $ticket->wc_order_id );
		$this->assertNull( $ticket->wc_order_item_id );
	}

	/**
	 * Test from_row handles missing optional qr_code_url.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::from_row
	 * @return void
	 */
	public function test_from_row_handles_missing_qr_code_url(): void {
		$row = (object) array(
			'id'             => 4,
			'ticket_type_id' => 1,
			'occurrence_id'  => 2,
		);

		$ticket = Ticket::from_row( $row );

		$this->assertNull( $ticket->qr_code_url );
	}

	/**
	 * Test from_row defaults status to pending.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::from_row
	 * @return void
	 */
	public function test_from_row_defaults_status_to_pending(): void {
		$row = (object) array(
			'id'             => 5,
			'ticket_type_id' => 1,
			'occurrence_id'  => 2,
		);

		$ticket = Ticket::from_row( $row );

		$this->assertSame( 'pending', $ticket->status );
	}

	// =========================================================================
	// is_confirmed Tests
	// =========================================================================

	/**
	 * Test is_confirmed returns true for confirmed status.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::is_confirmed
	 * @return void
	 */
	public function test_is_confirmed_returns_true_for_confirmed(): void {
		$ticket         = new Ticket();
		$ticket->status = 'confirmed';

		$this->assertTrue( $ticket->is_confirmed() );
	}

	/**
	 * Test is_confirmed returns false for other statuses.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::is_confirmed
	 * @return void
	 */
	public function test_is_confirmed_returns_false_for_pending(): void {
		$ticket         = new Ticket();
		$ticket->status = 'pending';

		$this->assertFalse( $ticket->is_confirmed() );
	}

	// =========================================================================
	// is_checked_in Tests
	// =========================================================================

	/**
	 * Test is_checked_in returns true for checked_in status.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::is_checked_in
	 * @return void
	 */
	public function test_is_checked_in_returns_true_for_checked_in_status(): void {
		$ticket         = new Ticket();
		$ticket->status = 'checked_in';

		$this->assertTrue( $ticket->is_checked_in() );
	}

	/**
	 * Test is_checked_in returns true when checked_in_at is set.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::is_checked_in
	 * @return void
	 */
	public function test_is_checked_in_returns_true_when_checked_in_at_set(): void {
		$ticket                = new Ticket();
		$ticket->status        = 'confirmed';
		$ticket->checked_in_at = '2026-01-15 14:30:00';

		$this->assertTrue( $ticket->is_checked_in() );
	}

	/**
	 * Test is_checked_in returns false when neither condition met.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::is_checked_in
	 * @return void
	 */
	public function test_is_checked_in_returns_false_when_not_checked_in(): void {
		$ticket         = new Ticket();
		$ticket->status = 'confirmed';

		$this->assertFalse( $ticket->is_checked_in() );
	}

	// =========================================================================
	// is_cancelled Tests
	// =========================================================================

	/**
	 * Test is_cancelled returns true for cancelled status.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::is_cancelled
	 * @return void
	 */
	public function test_is_cancelled_returns_true_for_cancelled(): void {
		$ticket         = new Ticket();
		$ticket->status = 'cancelled';

		$this->assertTrue( $ticket->is_cancelled() );
	}

	/**
	 * Test is_cancelled returns true for refunded status.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::is_cancelled
	 * @return void
	 */
	public function test_is_cancelled_returns_true_for_refunded(): void {
		$ticket         = new Ticket();
		$ticket->status = 'refunded';

		$this->assertTrue( $ticket->is_cancelled() );
	}

	/**
	 * Test is_cancelled returns false for confirmed status.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::is_cancelled
	 * @return void
	 */
	public function test_is_cancelled_returns_false_for_confirmed(): void {
		$ticket         = new Ticket();
		$ticket->status = 'confirmed';

		$this->assertFalse( $ticket->is_cancelled() );
	}

	// =========================================================================
	// is_valid_for_entry Tests
	// =========================================================================

	/**
	 * Test is_valid_for_entry returns true when confirmed and not checked in.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::is_valid_for_entry
	 * @return void
	 */
	public function test_is_valid_for_entry_returns_true_when_confirmed_not_checked_in(): void {
		$ticket         = new Ticket();
		$ticket->status = 'confirmed';

		$this->assertTrue( $ticket->is_valid_for_entry() );
	}

	/**
	 * Test is_valid_for_entry returns false when not confirmed.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::is_valid_for_entry
	 * @return void
	 */
	public function test_is_valid_for_entry_returns_false_when_not_confirmed(): void {
		$ticket         = new Ticket();
		$ticket->status = 'pending';

		$this->assertFalse( $ticket->is_valid_for_entry() );
	}

	/**
	 * Test is_valid_for_entry returns false when already checked in.
	 *
	 * @covers \NetterTechEvents\Models\Ticket::is_valid_for_entry
	 * @return void
	 */
	public function test_is_valid_for_entry_returns_false_when_checked_in(): void {
		$ticket         = new Ticket();
		$ticket->status = 'checked_in';

		$this->assertFalse( $ticket->is_valid_for_entry() );
	}

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test STATUSES constant contains expected values.
	 *
	 * @covers \NetterTechEvents\Models\Ticket
	 * @return void
	 */
	public function test_statuses_constant_contains_expected_values(): void {
		$this->assertContains( 'pending', Ticket::STATUSES );
		$this->assertContains( 'confirmed', Ticket::STATUSES );
		$this->assertContains( 'cancelled', Ticket::STATUSES );
		$this->assertContains( 'refunded', Ticket::STATUSES );
		$this->assertContains( 'checked_in', Ticket::STATUSES );
	}
}
