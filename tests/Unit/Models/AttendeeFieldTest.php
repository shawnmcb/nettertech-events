<?php
/**
 * AttendeeField model unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use NetterTechEvents\Models\AttendeeField;
use NetterTechEvents\Enums\FieldType;

/**
 * Test AttendeeField model functionality.
 *
 * @coversDefaultClass \NetterTechEvents\Models\AttendeeField
 */
class AttendeeFieldTest extends \NetterTechEventsTestCase {

	/**
	 * Test from_row populates all properties.
	 *
	 * @return void
	 */
	public function test_from_row_populates_properties(): void {
		$row = (object) array(
			'id'               => 42,
			'event_id'         => 10,
			'field_key'        => 'dietary-requirements',
			'field_type'       => 'select',
			'label'            => 'Dietary Requirements',
			'placeholder'      => 'Choose one',
			'description'      => 'Let us know your dietary needs',
			'options'          => '["Vegetarian","Vegan","Gluten-free","None"]',
			'is_required'      => 1,
			'sort_order'       => 2,
			'validation_rules' => '{"max_length":500}',
			'created_at'       => '2026-03-30 10:00:00',
			'updated_at'       => '2026-03-30 10:00:00',
		);

		$field = AttendeeField::from_row( $row );

		$this->assertSame( 42, $field->id );
		$this->assertSame( 10, $field->event_id );
		$this->assertSame( 'dietary-requirements', $field->field_key );
		$this->assertSame( 'select', $field->field_type );
		$this->assertSame( 'Dietary Requirements', $field->label );
		$this->assertSame( 'Choose one', $field->placeholder );
		$this->assertTrue( $field->is_required );
		$this->assertSame( 2, $field->sort_order );
	}

	/**
	 * Test from_row with array input.
	 *
	 * @return void
	 */
	public function test_from_row_accepts_array(): void {
		$row = array(
			'id'         => 1,
			'event_id'   => 5,
			'field_key'  => 'notes',
			'field_type' => 'textarea',
			'label'      => 'Notes',
		);

		$field = AttendeeField::from_row( $row );

		$this->assertSame( 1, $field->id );
		$this->assertSame( 5, $field->event_id );
		$this->assertSame( 'textarea', $field->field_type );
	}

	/**
	 * Test from_row with missing fields uses defaults.
	 *
	 * @return void
	 */
	public function test_from_row_uses_defaults(): void {
		$row = (object) array();

		$field = AttendeeField::from_row( $row );

		$this->assertNull( $field->id );
		$this->assertSame( 0, $field->event_id );
		$this->assertSame( '', $field->field_key );
		$this->assertSame( 'text', $field->field_type );
		$this->assertSame( '', $field->label );
		$this->assertFalse( $field->is_required );
		$this->assertSame( 0, $field->sort_order );
	}

	/**
	 * Test to_array returns correct structure.
	 *
	 * @return void
	 */
	public function test_to_array(): void {
		$field              = new AttendeeField();
		$field->event_id    = 10;
		$field->field_key   = 'test-field';
		$field->field_type  = 'text';
		$field->label       = 'Test Field';
		$field->is_required = true;
		$field->sort_order  = 0;

		$array = $field->to_array();

		$this->assertSame( 10, $array['event_id'] );
		$this->assertSame( 'test-field', $array['field_key'] );
		$this->assertSame( 'text', $array['field_type'] );
		$this->assertSame( 'Test Field', $array['label'] );
		$this->assertSame( 1, $array['is_required'] );
	}

	/**
	 * Test get_formats returns correct type specifiers.
	 *
	 * @return void
	 */
	public function test_get_formats(): void {
		$field   = new AttendeeField();
		$formats = $field->get_formats();

		$this->assertCount( count( $field->to_array() ), $formats );
		$this->assertSame( '%d', $formats[0] ); // event_id.
		$this->assertSame( '%s', $formats[1] ); // field_key.
	}

	/**
	 * Test validate requires event_id and label.
	 *
	 * @return void
	 */
	public function test_validate_requires_event_id_and_label(): void {
		$field = new AttendeeField();

		$errors = $field->validate();

		$this->assertNotEmpty( $errors );
		$this->assertGreaterThanOrEqual( 2, count( $errors ) );
	}

	/**
	 * Test validate passes for valid field.
	 *
	 * @return void
	 */
	public function test_validate_passes_for_valid_field(): void {
		$field             = new AttendeeField();
		$field->event_id   = 1;
		$field->label      = 'Test';
		$field->field_type = 'text';

		$errors = $field->validate();

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate rejects invalid field type.
	 *
	 * @return void
	 */
	public function test_validate_rejects_invalid_field_type(): void {
		$field             = new AttendeeField();
		$field->event_id   = 1;
		$field->label      = 'Test';
		$field->field_type = 'invalid_type';

		$errors = $field->validate();

		$this->assertNotEmpty( $errors );
	}

	/**
	 * Test validate requires options for select/radio/checkbox types.
	 *
	 * @return void
	 */
	public function test_validate_requires_options_for_option_types(): void {
		$field             = new AttendeeField();
		$field->event_id   = 1;
		$field->label      = 'Dropdown';
		$field->field_type = 'select';
		// No options set.

		$errors = $field->validate();

		$this->assertNotEmpty( $errors );
	}

	/**
	 * Test get_options decodes JSON.
	 *
	 * @return void
	 */
	public function test_get_options_decodes_json(): void {
		$field          = new AttendeeField();
		$field->options = '["Option A","Option B","Option C"]';

		$options = $field->get_options();

		$this->assertCount( 3, $options );
		$this->assertSame( 'Option A', $options[0] );
	}

	/**
	 * Test get_options returns empty array for null.
	 *
	 * @return void
	 */
	public function test_get_options_returns_empty_for_null(): void {
		$field          = new AttendeeField();
		$field->options = null;

		$this->assertSame( array(), $field->get_options() );
	}

	/**
	 * Test set_options encodes to JSON.
	 *
	 * @return void
	 */
	public function test_set_options_encodes_json(): void {
		$field = new AttendeeField();
		$field->set_options( array( 'A', 'B', 'C' ) );

		$this->assertSame( '["A","B","C"]', $field->options );
	}

	/**
	 * Test set_options with empty array sets null.
	 *
	 * @return void
	 */
	public function test_set_options_empty_sets_null(): void {
		$field = new AttendeeField();
		$field->set_options( array() );

		$this->assertNull( $field->options );
	}

	/**
	 * Test get_validation_rules decodes JSON.
	 *
	 * @return void
	 */
	public function test_get_validation_rules(): void {
		$field                   = new AttendeeField();
		$field->validation_rules = '{"min_length":2,"max_length":500}';

		$rules = $field->get_validation_rules();

		$this->assertSame( 2, $rules['min_length'] );
		$this->assertSame( 500, $rules['max_length'] );
	}

	/**
	 * Test get_field_type returns FieldType enum.
	 *
	 * @return void
	 */
	public function test_get_field_type_returns_enum(): void {
		$field             = new AttendeeField();
		$field->field_type = 'select';

		$type = $field->get_field_type();

		$this->assertSame( FieldType::SELECT, $type );
	}

	/**
	 * Test get_field_type returns null for invalid type.
	 *
	 * @return void
	 */
	public function test_get_field_type_returns_null_for_invalid(): void {
		$field             = new AttendeeField();
		$field->field_type = 'invalid';

		$this->assertNull( $field->get_field_type() );
	}
}
