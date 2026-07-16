<?php
/**
 * AttendeeStatus enum unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Enums;

use NetterTechEvents\Enums\AttendeeStatus;

/**
 * Test AttendeeStatus enum functionality.
 *
 * @coversDefaultClass \NetterTechEvents\Enums\AttendeeStatus
 */
class AttendeeStatusTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Data Providers
	// =========================================================================

	/**
	 * Provide enum case name, expected value, and label substring.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function case_provider(): array {
		return array(
			'PENDING'   => array( 'PENDING', 'pending', 'Pending' ),
			'CONFIRMED' => array( 'CONFIRMED', 'confirmed', 'Confirmed' ),
			'CANCELLED' => array( 'CANCELLED', 'cancelled', 'Cancelled' ),
			'REFUNDED'  => array( 'REFUNDED', 'refunded', 'Refunded' ),
			'VOIDED'    => array( 'VOIDED', 'voided', 'Voided' ),
		);
	}

	/**
	 * Provide enum value and expected is_active result.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function active_status_provider(): array {
		return array(
			'PENDING'   => array( 'pending', true ),
			'CONFIRMED' => array( 'confirmed', true ),
			'CANCELLED' => array( 'cancelled', false ),
			'REFUNDED'  => array( 'refunded', false ),
			'VOIDED'    => array( 'voided', false ),
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
	 * @param string $case_name       The enum case name (unused, for readability).
	 * @param string $expected_value   The expected backing value.
	 * @param string $label_substring  Substring expected in the label.
	 * @return void
	 */
	public function test_case_has_correct_value( string $case_name, string $expected_value, string $label_substring ): void {
		$status = AttendeeStatus::from( $expected_value );

		$this->assertSame( $expected_value, $status->value );
		$this->assertStringContainsString( $label_substring, $status->label() );
	}

	/**
	 * Test is_active returns the expected result for each status.
	 *
	 * @dataProvider active_status_provider
	 *
	 * @param string $value    The enum backing value.
	 * @param bool   $expected The expected is_active result.
	 * @return void
	 */
	public function test_is_active( string $value, bool $expected ): void {
		$this->assertSame( $expected, AttendeeStatus::from( $value )->is_active() );
	}

	// =========================================================================
	// Standalone Tests
	// =========================================================================

	/**
	 * Test labels are returned for all statuses.
	 *
	 * @return void
	 */
	public function test_labels_are_returned(): void {
		$this->assertNotEmpty( AttendeeStatus::PENDING->label() );
		$this->assertNotEmpty( AttendeeStatus::CONFIRMED->label() );
		$this->assertNotEmpty( AttendeeStatus::CANCELLED->label() );
		$this->assertNotEmpty( AttendeeStatus::REFUNDED->label() );
		$this->assertNotEmpty( AttendeeStatus::VOIDED->label() );
	}

	/**
	 * Test values returns all status values.
	 *
	 * @return void
	 */
	public function test_values_returns_all_statuses(): void {
		$values = AttendeeStatus::values();

		$this->assertCount( 5, $values );
		$this->assertContains( 'pending', $values );
		$this->assertContains( 'confirmed', $values );
		$this->assertContains( 'cancelled', $values );
		$this->assertContains( 'refunded', $values );
		$this->assertContains( 'voided', $values );
	}

	/**
	 * Test tryFrom returns null for invalid value.
	 *
	 * @return void
	 */
	public function test_try_from_returns_null_for_invalid(): void {
		$result = AttendeeStatus::tryFrom( 'invalid' );

		$this->assertNull( $result );
	}

	/**
	 * Test cases returns all enum cases.
	 *
	 * @return void
	 */
	public function test_cases_returns_all_cases(): void {
		$cases = AttendeeStatus::cases();

		$this->assertCount( 5, $cases );
		$this->assertContains( AttendeeStatus::PENDING, $cases );
		$this->assertContains( AttendeeStatus::CONFIRMED, $cases );
		$this->assertContains( AttendeeStatus::CANCELLED, $cases );
		$this->assertContains( AttendeeStatus::REFUNDED, $cases );
		$this->assertContains( AttendeeStatus::VOIDED, $cases );
	}
}
