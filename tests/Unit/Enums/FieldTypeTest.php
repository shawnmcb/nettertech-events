<?php
/**
 * FieldType enum unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Enums;

use NetterTechEvents\Enums\FieldType;

/**
 * Test FieldType enum functionality.
 *
 * @coversDefaultClass \NetterTechEvents\Enums\FieldType
 */
class FieldTypeTest extends \NetterTechEventsTestCase {

	/**
	 * Provide enum case name, expected value, and label substring.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function case_provider(): array {
		return array(
			'TEXT'     => array( 'text', 'Text' ),
			'TEXTAREA' => array( 'textarea', 'Textarea' ),
			'SELECT'   => array( 'select', 'Dropdown' ),
			'CHECKBOX' => array( 'checkbox', 'Checkbox' ),
			'RADIO'    => array( 'radio', 'Radio' ),
			'EMAIL'    => array( 'email', 'Email' ),
			'PHONE'    => array( 'phone', 'Phone' ),
			'NUMBER'   => array( 'number', 'Number' ),
			'DATE'     => array( 'date', 'Date' ),
			'URL'      => array( 'url', 'URL' ),
		);
	}

	/**
	 * Provide field types that support options.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function has_options_provider(): array {
		return array(
			'text'     => array( 'text', false ),
			'textarea' => array( 'textarea', false ),
			'select'   => array( 'select', true ),
			'checkbox' => array( 'checkbox', true ),
			'radio'    => array( 'radio', true ),
			'email'    => array( 'email', false ),
			'phone'    => array( 'phone', false ),
			'number'   => array( 'number', false ),
			'date'     => array( 'date', false ),
			'url'      => array( 'url', false ),
		);
	}

	/**
	 * Test each case has the correct value and label.
	 *
	 * @dataProvider case_provider
	 *
	 * @param string $value          The backing value.
	 * @param string $label_contains Substring expected in the label.
	 * @return void
	 */
	public function test_case_has_correct_value_and_label( string $value, string $label_contains ): void {
		$type = FieldType::from( $value );

		$this->assertSame( $value, $type->value );
		$this->assertStringContainsString( $label_contains, $type->label() );
	}

	/**
	 * Test has_options returns correct result per type.
	 *
	 * @dataProvider has_options_provider
	 *
	 * @param string $value    The backing value.
	 * @param bool   $expected Expected has_options result.
	 * @return void
	 */
	public function test_has_options( string $value, bool $expected ): void {
		$this->assertSame( $expected, FieldType::from( $value )->has_options() );
	}

	/**
	 * Test values returns all 10 field types.
	 *
	 * @return void
	 */
	public function test_values_returns_all_types(): void {
		$values = FieldType::values();

		$this->assertCount( 10, $values );
		$this->assertContains( 'text', $values );
		$this->assertContains( 'select', $values );
		$this->assertContains( 'url', $values );
	}

	/**
	 * Test input_type returns correct HTML input type.
	 *
	 * @return void
	 */
	public function test_input_type_returns_html_type(): void {
		$this->assertSame( 'text', FieldType::TEXT->input_type() );
		$this->assertSame( 'textarea', FieldType::TEXTAREA->input_type() );
		$this->assertSame( 'select', FieldType::SELECT->input_type() );
		$this->assertSame( 'tel', FieldType::PHONE->input_type() );
		$this->assertSame( 'email', FieldType::EMAIL->input_type() );
	}

	/**
	 * Test tryFrom returns null for invalid value.
	 *
	 * @return void
	 */
	public function test_try_from_returns_null_for_invalid(): void {
		$this->assertNull( FieldType::tryFrom( 'invalid_type' ) );
	}
}
