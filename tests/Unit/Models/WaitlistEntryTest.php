<?php
/**
 * Tests for WaitlistEntry model.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use NetterTechEvents\Models\WaitlistEntry;
use Brain\Monkey\Functions;

/**
 * @coversDefaultClass \NetterTechEvents\Models\WaitlistEntry
 */
class WaitlistEntryTest extends \NetterTechEventsTestCase {

	public function test_from_row_with_object(): void {
		$row                  = new \stdClass();
		$row->id              = '5';
		$row->occurrence_id   = '42';
		$row->ticket_type_id  = '10';
		$row->email           = 'test@example.com';
		$row->name            = 'Jane Doe';
		$row->phone           = '555-1234';
		$row->position        = '3';
		$row->status          = 'waiting';
		$row->notified_at     = null;
		$row->created_at      = '2026-03-15 10:00:00';
		$row->updated_at      = '2026-03-15 10:00:00';

		$entry = WaitlistEntry::from_row( $row );

		$this->assertSame( 5, $entry->id );
		$this->assertSame( 42, $entry->occurrence_id );
		$this->assertSame( 10, $entry->ticket_type_id );
		$this->assertSame( 'test@example.com', $entry->email );
		$this->assertSame( 'Jane Doe', $entry->name );
		$this->assertSame( '555-1234', $entry->phone );
		$this->assertSame( 3, $entry->position );
		$this->assertSame( 'waiting', $entry->status );
		$this->assertNull( $entry->notified_at );
	}

	public function test_from_row_with_array(): void {
		$row = array(
			'id'             => '1',
			'occurrence_id'  => '10',
			'ticket_type_id' => null,
			'email'          => 'user@test.com',
			'name'           => 'John',
			'phone'          => null,
			'position'       => '1',
			'status'         => 'notified',
			'notified_at'    => '2026-03-16 12:00:00',
			'created_at'     => '2026-03-15 10:00:00',
			'updated_at'     => '2026-03-16 12:00:00',
		);

		$entry = WaitlistEntry::from_row( $row );

		$this->assertSame( 1, $entry->id );
		$this->assertNull( $entry->ticket_type_id );
		$this->assertNull( $entry->phone );
		$this->assertSame( 'notified', $entry->status );
		$this->assertSame( '2026-03-16 12:00:00', $entry->notified_at );
	}

	public function test_to_array(): void {
		$entry                 = new WaitlistEntry();
		$entry->occurrence_id  = 42;
		$entry->ticket_type_id = 5;
		$entry->email          = 'test@example.com';
		$entry->name           = 'Jane';
		$entry->phone          = '555-0000';
		$entry->position       = 2;
		$entry->status         = 'waiting';
		$entry->notified_at    = null;

		$array = $entry->to_array();

		$this->assertSame( 42, $array['occurrence_id'] );
		$this->assertSame( 5, $array['ticket_type_id'] );
		$this->assertSame( 'test@example.com', $array['email'] );
		$this->assertSame( 'Jane', $array['name'] );
		$this->assertSame( '555-0000', $array['phone'] );
		$this->assertSame( 2, $array['position'] );
		$this->assertSame( 'waiting', $array['status'] );
		$this->assertNull( $array['notified_at'] );
		$this->assertArrayNotHasKey( 'id', $array );
		$this->assertArrayNotHasKey( 'created_at', $array );
	}

	public function test_get_formats(): void {
		$entry   = new WaitlistEntry();
		$formats = $entry->get_formats();

		$this->assertCount( 8, $formats );
		$this->assertSame( '%d', $formats[0] ); // occurrence_id.
		$this->assertSame( '%s', $formats[2] ); // email.
	}

	public function test_validate_valid_entry(): void {
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();

		$entry                = new WaitlistEntry();
		$entry->occurrence_id = 1;
		$entry->email         = 'test@example.com';
		$entry->name          = 'Test User';

		$errors = $entry->validate();

		$this->assertEmpty( $errors );
	}

	public function test_validate_missing_occurrence_id(): void {
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();

		$entry        = new WaitlistEntry();
		$entry->email = 'test@example.com';
		$entry->name  = 'Test';

		$errors = $entry->validate();

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Occurrence ID', $errors[0] );
	}

	public function test_validate_invalid_email(): void {
		Functions\when( 'is_email' )->justReturn( false );
		Functions\when( '__' )->returnArg();

		$entry                = new WaitlistEntry();
		$entry->occurrence_id = 1;
		$entry->email         = 'not-an-email';
		$entry->name          = 'Test';

		$errors = $entry->validate();

		$this->assertNotEmpty( $errors );
	}

	public function test_validate_empty_name(): void {
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();

		$entry                = new WaitlistEntry();
		$entry->occurrence_id = 1;
		$entry->email         = 'test@example.com';
		$entry->name          = '';

		$errors = $entry->validate();

		$this->assertNotEmpty( $errors );
	}

	public function test_validate_invalid_status(): void {
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( '__' )->returnArg();

		$entry                = new WaitlistEntry();
		$entry->occurrence_id = 1;
		$entry->email         = 'test@example.com';
		$entry->name          = 'Test';
		$entry->status        = 'invalid_status';

		$errors = $entry->validate();

		$this->assertNotEmpty( $errors );
	}

	public function test_is_waiting(): void {
		$entry         = new WaitlistEntry();
		$entry->status = 'waiting';
		$this->assertTrue( $entry->is_waiting() );

		$entry->status = 'notified';
		$this->assertFalse( $entry->is_waiting() );
	}

	public function test_is_notified(): void {
		$entry         = new WaitlistEntry();
		$entry->status = 'notified';
		$this->assertTrue( $entry->is_notified() );

		$entry->status = 'waiting';
		$this->assertFalse( $entry->is_notified() );
	}

	public function test_is_converted(): void {
		$entry         = new WaitlistEntry();
		$entry->status = 'converted';
		$this->assertTrue( $entry->is_converted() );

		$entry->status = 'waiting';
		$this->assertFalse( $entry->is_converted() );
	}

	public function test_default_values(): void {
		$entry = new WaitlistEntry();

		$this->assertNull( $entry->id );
		$this->assertSame( 0, $entry->occurrence_id );
		$this->assertNull( $entry->ticket_type_id );
		$this->assertSame( '', $entry->email );
		$this->assertSame( '', $entry->name );
		$this->assertNull( $entry->phone );
		$this->assertSame( 0, $entry->position );
		$this->assertSame( 'waiting', $entry->status );
		$this->assertNull( $entry->notified_at );
	}
}
