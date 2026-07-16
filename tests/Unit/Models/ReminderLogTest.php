<?php
/**
 * ReminderLog model unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use NetterTechEvents\Models\ReminderLog;

/**
 * Test ReminderLog model functionality.
 */
class ReminderLogTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// from_row Tests
	// =========================================================================

	/**
	 * Test from_row creates ReminderLog from valid data.
	 *
	 * @covers \NetterTechEvents\Models\ReminderLog::from_row
	 * @return void
	 */
	public function test_from_row_creates_reminder_log_from_valid_data(): void {
		$row = (object) array(
			'id'            => 42,
			'occurrence_id' => 10,
			'attendee_id'   => 25,
			'reminder_type' => '24h_before',
			'status'        => 'sent',
			'sent_at'       => '2026-02-01 08:00:00',
		);

		$log = ReminderLog::from_row( $row );

		$this->assertSame( 42, $log->id );
		$this->assertSame( 10, $log->occurrence_id );
		$this->assertSame( 25, $log->attendee_id );
		$this->assertSame( '24h_before', $log->reminder_type );
		$this->assertSame( 'sent', $log->status );
		$this->assertSame( '2026-02-01 08:00:00', $log->sent_at );
	}

	/**
	 * Test from_row handles null optional fields.
	 *
	 * @covers \NetterTechEvents\Models\ReminderLog::from_row
	 * @return void
	 */
	public function test_from_row_handles_null_optional_fields(): void {
		$row = (object) array(
			'id'            => null,
			'occurrence_id' => 5,
			'attendee_id'   => 12,
		);

		$log = ReminderLog::from_row( $row );

		$this->assertNull( $log->id );
		$this->assertSame( 5, $log->occurrence_id );
		$this->assertSame( 12, $log->attendee_id );
		$this->assertSame( '24h_before', $log->reminder_type );
		$this->assertSame( 'sent', $log->status );
		$this->assertNull( $log->sent_at );
	}

	/**
	 * Test from_row defaults missing fields.
	 *
	 * @covers \NetterTechEvents\Models\ReminderLog::from_row
	 * @return void
	 */
	public function test_from_row_defaults_missing_fields(): void {
		$row = (object) array();

		$log = ReminderLog::from_row( $row );

		$this->assertNull( $log->id );
		$this->assertSame( 0, $log->occurrence_id );
		$this->assertSame( 0, $log->attendee_id );
		$this->assertSame( '24h_before', $log->reminder_type );
		$this->assertSame( 'sent', $log->status );
		$this->assertNull( $log->sent_at );
	}

	// =========================================================================
	// to_array Tests
	// =========================================================================

	/**
	 * Test to_array round-trip preserves values.
	 *
	 * @covers \NetterTechEvents\Models\ReminderLog::to_array
	 * @return void
	 */
	public function test_to_array_round_trip_preserves_values(): void {
		$log                = new ReminderLog();
		$log->occurrence_id = 10;
		$log->attendee_id   = 25;
		$log->reminder_type = '24h_before';
		$log->status        = 'failed';

		$array = $log->to_array();

		$this->assertSame( 10, $array['occurrence_id'] );
		$this->assertSame( 25, $array['attendee_id'] );
		$this->assertSame( '24h_before', $array['reminder_type'] );
		$this->assertSame( 'failed', $array['status'] );
		$this->assertCount( 4, $array );
	}

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test TYPES constant contains expected values.
	 *
	 * @covers \NetterTechEvents\Models\ReminderLog
	 * @return void
	 */
	public function test_types_constant_contains_expected_values(): void {
		$this->assertContains( '24h_before', ReminderLog::TYPES );
		$this->assertCount( 1, ReminderLog::TYPES );
	}

	/**
	 * Test STATUSES constant contains expected values.
	 *
	 * @covers \NetterTechEvents\Models\ReminderLog
	 * @return void
	 */
	public function test_statuses_constant_contains_expected_values(): void {
		$this->assertContains( 'sent', ReminderLog::STATUSES );
		$this->assertContains( 'failed', ReminderLog::STATUSES );
		$this->assertCount( 2, ReminderLog::STATUSES );
	}

	// =========================================================================
	// get_formats Tests
	// =========================================================================

	/**
	 * Test get_formats returns correct count matching to_array.
	 *
	 * @covers \NetterTechEvents\Models\ReminderLog::get_formats
	 * @return void
	 */
	public function test_get_formats_returns_correct_count(): void {
		$log                = new ReminderLog();
		$log->occurrence_id = 1;
		$log->attendee_id   = 1;
		$formats            = $log->get_formats();
		$array              = $log->to_array();

		$this->assertCount( count( $array ), $formats );
	}
}
