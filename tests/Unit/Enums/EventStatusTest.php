<?php
/**
 * EventStatus enum unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Enums;

use NetterTechEvents\Enums\EventStatus;

/**
 * Test EventStatus enum functionality.
 *
 * @coversDefaultClass \NetterTechEvents\Enums\EventStatus
 */
class EventStatusTest extends \NetterTechEventsTestCase {

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
			'DRAFT'     => array( 'draft', 'Draft' ),
			'PUBLISHED' => array( 'published', 'Published' ),
			'CANCELLED' => array( 'cancelled', 'Cancelled' ),
			'POSTPONED' => array( 'postponed', 'Postponed' ),
		);
	}

	/**
	 * Provide enum cases with their expected boolean behaviors.
	 *
	 * @return array<string, array{string, bool, bool}>
	 */
	public static function behavior_provider(): array {
		return array(
			'DRAFT'     => array( 'draft', true, false ),
			'PUBLISHED' => array( 'published', true, true ),
			'CANCELLED' => array( 'cancelled', false, false ),
			'POSTPONED' => array( 'postponed', true, false ),
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
	 * @param string $label_substring The expected label substring.
	 * @return void
	 */
	public function test_case_has_correct_value( string $value, string $label_substring ): void {
		$status = EventStatus::from( $value );

		$this->assertSame( $value, $status->value );
		$this->assertStringContainsString( $label_substring, $status->label() );
	}

	/**
	 * Test each case reports correct is_active and is_public booleans.
	 *
	 * @dataProvider behavior_provider
	 *
	 * @param string $value     The enum value to test.
	 * @param bool   $is_active Whether the status should be active.
	 * @param bool   $is_public Whether the status should be public.
	 * @return void
	 */
	public function test_boolean_behavior( string $value, bool $is_active, bool $is_public ): void {
		$status = EventStatus::from( $value );

		$this->assertSame( $is_active, $status->is_active() );
		$this->assertSame( $is_public, $status->is_public() );
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
		$this->assertNotEmpty( EventStatus::DRAFT->label() );
		$this->assertNotEmpty( EventStatus::PUBLISHED->label() );
		$this->assertNotEmpty( EventStatus::CANCELLED->label() );
		$this->assertNotEmpty( EventStatus::POSTPONED->label() );
	}

	/**
	 * Test values returns all status values.
	 *
	 * @return void
	 */
	public function test_values_returns_all_statuses(): void {
		$values = EventStatus::values();

		$this->assertCount( 4, $values );
		$this->assertContains( 'draft', $values );
		$this->assertContains( 'published', $values );
		$this->assertContains( 'cancelled', $values );
		$this->assertContains( 'postponed', $values );
	}

	/**
	 * Test tryFrom returns null for invalid value.
	 *
	 * @return void
	 */
	public function test_try_from_returns_null_for_invalid(): void {
		$result = EventStatus::tryFrom( 'invalid' );

		$this->assertNull( $result );
	}

	/**
	 * Test cases returns all enum cases.
	 *
	 * @return void
	 */
	public function test_cases_returns_all_cases(): void {
		$cases = EventStatus::cases();

		$this->assertCount( 4, $cases );
		$this->assertContains( EventStatus::DRAFT, $cases );
		$this->assertContains( EventStatus::PUBLISHED, $cases );
		$this->assertContains( EventStatus::CANCELLED, $cases );
		$this->assertContains( EventStatus::POSTPONED, $cases );
	}
}
