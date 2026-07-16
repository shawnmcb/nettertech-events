<?php
/**
 * AttendeeFieldValue model unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use NetterTechEvents\Models\AttendeeFieldValue;

/**
 * Test AttendeeFieldValue model functionality.
 *
 * @coversDefaultClass \NetterTechEvents\Models\AttendeeFieldValue
 */
class AttendeeFieldValueTest extends \NetterTechEventsTestCase {

	/**
	 * Test from_row populates all properties.
	 *
	 * @return void
	 */
	public function test_from_row_populates_properties(): void {
		$row = (object) array(
			'id'          => 99,
			'attendee_id' => 42,
			'field_id'    => 7,
			'field_value' => 'Vegetarian',
			'created_at'  => '2026-03-30 12:00:00',
		);

		$value = AttendeeFieldValue::from_row( $row );

		$this->assertSame( 99, $value->id );
		$this->assertSame( 42, $value->attendee_id );
		$this->assertSame( 7, $value->field_id );
		$this->assertSame( 'Vegetarian', $value->field_value );
		$this->assertSame( '2026-03-30 12:00:00', $value->created_at );
	}

	/**
	 * Test from_row with array input.
	 *
	 * @return void
	 */
	public function test_from_row_accepts_array(): void {
		$row = array(
			'id'          => 1,
			'attendee_id' => 2,
			'field_id'    => 3,
			'field_value' => 'test',
		);

		$value = AttendeeFieldValue::from_row( $row );

		$this->assertSame( 1, $value->id );
		$this->assertSame( 2, $value->attendee_id );
	}

	/**
	 * Test from_row with missing fields uses defaults.
	 *
	 * @return void
	 */
	public function test_from_row_uses_defaults(): void {
		$row = (object) array();

		$value = AttendeeFieldValue::from_row( $row );

		$this->assertNull( $value->id );
		$this->assertSame( 0, $value->attendee_id );
		$this->assertSame( 0, $value->field_id );
		$this->assertNull( $value->field_value );
	}

	/**
	 * Test to_array returns correct structure.
	 *
	 * @return void
	 */
	public function test_to_array(): void {
		$value              = new AttendeeFieldValue();
		$value->attendee_id = 42;
		$value->field_id    = 7;
		$value->field_value = 'Vegan';

		$array = $value->to_array();

		$this->assertSame( 42, $array['attendee_id'] );
		$this->assertSame( 7, $array['field_id'] );
		$this->assertSame( 'Vegan', $array['field_value'] );
	}

	/**
	 * Test get_formats returns correct type specifiers.
	 *
	 * @return void
	 */
	public function test_get_formats(): void {
		$value   = new AttendeeFieldValue();
		$formats = $value->get_formats();

		$this->assertCount( 3, $formats );
		$this->assertSame( '%d', $formats[0] ); // attendee_id.
		$this->assertSame( '%d', $formats[1] ); // field_id.
		$this->assertSame( '%s', $formats[2] ); // field_value.
	}

	/**
	 * Test null field_value is allowed.
	 *
	 * @return void
	 */
	public function test_null_value_allowed(): void {
		$value              = new AttendeeFieldValue();
		$value->field_value = null;

		$array = $value->to_array();
		$this->assertNull( $array['field_value'] );
	}
}
