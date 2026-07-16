<?php
/**
 * SeatingModel enum unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Enums;

use NetterTechEvents\Enums\SeatingModel;

/**
 * Test SeatingModel enum functionality.
 *
 * @coversDefaultClass \NetterTechEvents\Enums\SeatingModel
 */
class SeatingModelTest extends \NetterTechEventsTestCase {

	/**
	 * Provide each enum case with its expected value and label substring.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function case_provider(): array {
		return array(
			'FREE'     => array( 'free', 'Free' ),
			'ASSIGNED' => array( 'assigned', 'Assigned' ),
			'MIXED'    => array( 'mixed', 'Mixed' ),
		);
	}

	/**
	 * Test each case has the expected value and label.
	 *
	 * @dataProvider case_provider
	 *
	 * @param string $value           The expected enum value.
	 * @param string $label_substring The expected label substring.
	 * @return void
	 */
	public function test_case_has_correct_value_and_label( string $value, string $label_substring ): void {
		$model = SeatingModel::from( $value );

		$this->assertSame( $value, $model->value );
		$this->assertStringContainsString( $label_substring, $model->label() );
	}

	/**
	 * Test values() returns all string values.
	 *
	 * @return void
	 */
	public function test_values_returns_all_cases(): void {
		$values = SeatingModel::values();

		$this->assertCount( 3, $values );
		$this->assertContains( 'free', $values );
		$this->assertContains( 'assigned', $values );
		$this->assertContains( 'mixed', $values );
	}

	/**
	 * Test from() throws on unknown value.
	 *
	 * @return void
	 */
	public function test_from_throws_on_unknown_value(): void {
		$this->expectException( \ValueError::class );
		SeatingModel::from( 'not-a-real-model' );
	}
}
