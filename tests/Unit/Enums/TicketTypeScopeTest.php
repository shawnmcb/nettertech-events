<?php
/**
 * TicketTypeScope enum unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Enums;

use NetterTechEvents\Enums\TicketTypeScope;

/**
 * Test TicketTypeScope enum functionality.
 *
 * @coversDefaultClass \NetterTechEvents\Enums\TicketTypeScope
 */
class TicketTypeScopeTest extends \NetterTechEventsTestCase {

	/**
	 * Data provider for enum cases.
	 *
	 * @return array<string, array{string}>
	 */
	public static function case_provider(): array {
		return array(
			'OCCURRENCE' => array( 'occurrence' ),
			'EVENT'      => array( 'event' ),
			'TEMPLATE'   => array( 'template' ),
		);
	}

	/**
	 * Data provider for boolean behavior methods.
	 *
	 * @return array<string, array{string, bool, bool}>
	 */
	public static function behavior_provider(): array {
		return array(
			'OCCURRENCE' => array( 'occurrence', true, false ),
			'EVENT'      => array( 'event', false, true ),
			'TEMPLATE'   => array( 'template', false, false ),
		);
	}

	/**
	 * Test each case has the correct value and round-trips through from().
	 *
	 * @dataProvider case_provider
	 *
	 * @param string $value Expected enum value.
	 * @return void
	 */
	public function test_case_has_correct_value( string $value ): void {
		$scope = TicketTypeScope::from( $value );

		$this->assertSame( $value, $scope->value );
	}

	/**
	 * Test boolean behavior methods return expected results per scope.
	 *
	 * @dataProvider behavior_provider
	 *
	 * @param string $value               Enum value string.
	 * @param bool   $requires_occurrence Expected requires_occurrence() result.
	 * @param bool   $is_series_pass      Expected is_series_pass() result.
	 * @return void
	 */
	public function test_boolean_behavior( string $value, bool $requires_occurrence, bool $is_series_pass ): void {
		$scope = TicketTypeScope::from( $value );

		$this->assertSame( $requires_occurrence, $scope->requires_occurrence() );
		$this->assertSame( $is_series_pass, $scope->is_series_pass() );
	}

	/**
	 * Test label returns localized string.
	 *
	 * @return void
	 */
	public function test_labels_are_returned(): void {
		$this->assertNotEmpty( TicketTypeScope::OCCURRENCE->label() );
		$this->assertNotEmpty( TicketTypeScope::EVENT->label() );
		$this->assertNotEmpty( TicketTypeScope::TEMPLATE->label() );
	}

	/**
	 * Test description returns localized string.
	 *
	 * @return void
	 */
	public function test_descriptions_are_returned(): void {
		$this->assertNotEmpty( TicketTypeScope::OCCURRENCE->description() );
		$this->assertNotEmpty( TicketTypeScope::EVENT->description() );
		$this->assertNotEmpty( TicketTypeScope::TEMPLATE->description() );
	}

	/**
	 * Test values returns all scope values.
	 *
	 * @return void
	 */
	public function test_values_returns_all_scopes(): void {
		$values = TicketTypeScope::values();

		$this->assertCount( 3, $values );
		$this->assertContains( 'occurrence', $values );
		$this->assertContains( 'event', $values );
		$this->assertContains( 'template', $values );
	}

	/**
	 * Test tryFrom returns null for invalid value.
	 *
	 * @return void
	 */
	public function test_try_from_returns_null_for_invalid(): void {
		$result = TicketTypeScope::tryFrom( 'invalid' );

		$this->assertNull( $result );
	}
}
