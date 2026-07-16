<?php
/**
 * OccurrenceStatus enum unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Enums;

use NetterTechEvents\Enums\OccurrenceStatus;

/**
 * Test OccurrenceStatus enum functionality.
 *
 * @coversDefaultClass \NetterTechEvents\Enums\OccurrenceStatus
 */
class OccurrenceStatusTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Data Providers
	// =========================================================================

	/**
	 * Provide enum cases with their expected value and label substring.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function case_provider(): array {
		return array(
			'SCHEDULED' => array( 'scheduled', 'Scheduled' ),
			'CANCELLED' => array( 'cancelled', 'Cancelled' ),
			'POSTPONED' => array( 'postponed', 'Postponed' ),
			'COMPLETED' => array( 'completed', 'Completed' ),
		);
	}

	/**
	 * Provide enum cases with their expected boolean behaviors.
	 *
	 * @return array<string, array{string, bool, bool}>
	 */
	public static function behavior_provider(): array {
		return array(
			'SCHEDULED' => array( 'scheduled', true, true ),
			'CANCELLED' => array( 'cancelled', false, false ),
			'POSTPONED' => array( 'postponed', true, false ),
			'COMPLETED' => array( 'completed', false, false ),
		);
	}

	// =========================================================================
	// Provider-Driven Tests
	// =========================================================================

	/**
	 * Test each case has the correct value and label.
	 *
	 * @dataProvider case_provider
	 *
	 * @param string $value           The expected enum value.
	 * @param string $label_substring The expected substring within the label.
	 *
	 * @return void
	 */
	public function test_case_has_correct_value( string $value, string $label_substring ): void {
		$status = OccurrenceStatus::from( $value );

		$this->assertSame( $value, $status->value );
		$this->assertStringContainsString( $label_substring, $status->label() );
	}

	/**
	 * Test each case reports correct is_active and is_purchasable behavior.
	 *
	 * @dataProvider behavior_provider
	 *
	 * @param string $value          The enum value to test.
	 * @param bool   $is_active      Whether the status should be active.
	 * @param bool   $is_purchasable Whether the status should be purchasable.
	 *
	 * @return void
	 */
	public function test_boolean_behavior( string $value, bool $is_active, bool $is_purchasable ): void {
		$status = OccurrenceStatus::from( $value );

		$this->assertSame( $is_active, $status->is_active() );
		$this->assertSame( $is_purchasable, $status->is_purchasable() );
	}

	// =========================================================================
	// Aggregate Tests
	// =========================================================================

	/**
	 * Test labels are returned for all statuses.
	 *
	 * @return void
	 */
	public function test_labels_are_returned(): void {
		$this->assertNotEmpty( OccurrenceStatus::SCHEDULED->label() );
		$this->assertNotEmpty( OccurrenceStatus::CANCELLED->label() );
		$this->assertNotEmpty( OccurrenceStatus::POSTPONED->label() );
		$this->assertNotEmpty( OccurrenceStatus::COMPLETED->label() );
	}

	/**
	 * Test values returns all status values.
	 *
	 * @return void
	 */
	public function test_values_returns_all_statuses(): void {
		$values = OccurrenceStatus::values();

		$this->assertCount( 4, $values );
		$this->assertContains( 'scheduled', $values );
		$this->assertContains( 'cancelled', $values );
		$this->assertContains( 'postponed', $values );
		$this->assertContains( 'completed', $values );
	}

	/**
	 * Test tryFrom returns null for invalid value.
	 *
	 * @return void
	 */
	public function test_try_from_returns_null_for_invalid(): void {
		$result = OccurrenceStatus::tryFrom( 'invalid' );

		$this->assertNull( $result );
	}

	/**
	 * Test cases returns all enum cases.
	 *
	 * @return void
	 */
	public function test_cases_returns_all_cases(): void {
		$cases = OccurrenceStatus::cases();

		$this->assertCount( 4, $cases );
		$this->assertContains( OccurrenceStatus::SCHEDULED, $cases );
		$this->assertContains( OccurrenceStatus::CANCELLED, $cases );
		$this->assertContains( OccurrenceStatus::POSTPONED, $cases );
		$this->assertContains( OccurrenceStatus::COMPLETED, $cases );
	}
}
