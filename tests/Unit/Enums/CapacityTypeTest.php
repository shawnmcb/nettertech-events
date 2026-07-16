<?php
/**
 * CapacityType enum unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Enums;

use NetterTechEvents\Enums\CapacityType;
use NetterTechEvents\Enums\TicketTypeScope;

/**
 * Test CapacityType enum functionality.
 *
 * @coversDefaultClass \NetterTechEvents\Enums\CapacityType
 */
class CapacityTypeTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Data Providers
	// =========================================================================

	/**
	 * Provides each enum case value for value correctness and from-string roundtrip.
	 *
	 * @return array<string, array{string}>
	 */
	public static function case_value_provider(): array {
		return array(
			'FIXED'     => array( 'fixed' ),
			'UNLIMITED' => array( 'unlimited' ),
			'SHARED'    => array( 'shared' ),
			'SEATED'    => array( 'seated' ),
		);
	}

	/**
	 * Provides each enum case with expected boolean behavior flags.
	 *
	 * @return array<string, array{string, bool, bool, bool, bool}>
	 */
	public static function behavior_provider(): array {
		return array(
			'FIXED'     => array( 'fixed', true, false, false, false ),
			'UNLIMITED' => array( 'unlimited', false, true, false, false ),
			'SHARED'    => array( 'shared', false, false, true, true ),
			'SEATED'    => array( 'seated', false, false, false, true ),
		);
	}

	// =========================================================================
	// Provider-Driven Tests
	// =========================================================================

	/**
	 * Test each case has the correct backing value and roundtrips through from().
	 *
	 * @dataProvider case_value_provider
	 *
	 * @param string $value Expected backing value.
	 * @return void
	 */
	public function test_case_has_correct_value( string $value ): void {
		$case = CapacityType::from( $value );

		$this->assertSame( $value, $case->value );
	}

	/**
	 * Test boolean behavior methods for each capacity type.
	 *
	 * @dataProvider behavior_provider
	 *
	 * @param string $value                    The backing string value.
	 * @param bool   $uses_capacity_column      Expected uses_capacity_column() result.
	 * @param bool   $is_unlimited              Expected is_unlimited() result.
	 * @param bool   $is_shared                 Expected is_shared() result.
	 * @param bool   $requires_occurrence_scope Expected requires_occurrence_scope() result.
	 * @return void
	 */
	public function test_boolean_behavior(
		string $value,
		bool $uses_capacity_column,
		bool $is_unlimited,
		bool $is_shared,
		bool $requires_occurrence_scope
	): void {
		$case = CapacityType::from( $value );

		$this->assertSame( $uses_capacity_column, $case->uses_capacity_column(), 'uses_capacity_column()' );
		$this->assertSame( $is_unlimited, $case->is_unlimited(), 'is_unlimited()' );
		$this->assertSame( $is_shared, $case->is_shared(), 'is_shared()' );
		$this->assertSame( $requires_occurrence_scope, $case->requires_occurrence_scope(), 'requires_occurrence_scope()' );
	}

	// =========================================================================
	// Aggregate Tests
	// =========================================================================

	/**
	 * Test label returns localized string.
	 *
	 * @return void
	 */
	public function test_labels_are_returned(): void {
		$this->assertNotEmpty( CapacityType::FIXED->label() );
		$this->assertNotEmpty( CapacityType::UNLIMITED->label() );
		$this->assertNotEmpty( CapacityType::SHARED->label() );
		$this->assertNotEmpty( CapacityType::SEATED->label() );
	}

	/**
	 * Test description returns localized string.
	 *
	 * @return void
	 */
	public function test_descriptions_are_returned(): void {
		$this->assertNotEmpty( CapacityType::FIXED->description() );
		$this->assertNotEmpty( CapacityType::UNLIMITED->description() );
		$this->assertNotEmpty( CapacityType::SHARED->description() );
		$this->assertNotEmpty( CapacityType::SEATED->description() );
	}

	/**
	 * Test values returns all capacity type values.
	 *
	 * @return void
	 */
	public function test_values_returns_all_types(): void {
		$values = CapacityType::values();

		$this->assertCount( 4, $values );
		$this->assertContains( 'fixed', $values );
		$this->assertContains( 'unlimited', $values );
		$this->assertContains( 'shared', $values );
		$this->assertContains( 'seated', $values );
	}

	/**
	 * Test tryFrom returns null for invalid value.
	 *
	 * @return void
	 */
	public function test_try_from_returns_null_for_invalid(): void {
		$result = CapacityType::tryFrom( 'invalid' );

		$this->assertNull( $result );
	}

	// =========================================================================
	// for_scope() Tests
	// =========================================================================

	/**
	 * Test for_scope returns all types for OCCURRENCE scope.
	 *
	 * @return void
	 */
	public function test_for_scope_occurrence_returns_all_types(): void {
		$types = CapacityType::for_scope( TicketTypeScope::OCCURRENCE );

		$this->assertCount( 4, $types );
		$this->assertContains( CapacityType::FIXED, $types );
		$this->assertContains( CapacityType::UNLIMITED, $types );
		$this->assertContains( CapacityType::SHARED, $types );
		$this->assertContains( CapacityType::SEATED, $types );
	}

	/**
	 * Test for_scope excludes SHARED for EVENT scope.
	 *
	 * @return void
	 */
	public function test_for_scope_event_excludes_shared(): void {
		$types = CapacityType::for_scope( TicketTypeScope::EVENT );

		$this->assertCount( 2, $types );
		$this->assertContains( CapacityType::FIXED, $types );
		$this->assertContains( CapacityType::UNLIMITED, $types );
		$this->assertNotContains( CapacityType::SHARED, $types );
		$this->assertNotContains( CapacityType::SEATED, $types );
	}

	/**
	 * Test for_scope excludes SHARED for TEMPLATE scope.
	 *
	 * @return void
	 */
	public function test_for_scope_template_excludes_shared(): void {
		$types = CapacityType::for_scope( TicketTypeScope::TEMPLATE );

		$this->assertCount( 2, $types );
		$this->assertContains( CapacityType::FIXED, $types );
		$this->assertContains( CapacityType::UNLIMITED, $types );
		$this->assertNotContains( CapacityType::SHARED, $types );
		$this->assertNotContains( CapacityType::SEATED, $types );
	}
}
