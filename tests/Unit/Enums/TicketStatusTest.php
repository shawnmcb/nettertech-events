<?php
/**
 * TicketStatus enum unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Enums
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Enums;

use NetterTechEvents\Enums\TicketStatus;

/**
 * Test TicketStatus enum functionality.
 *
 * @coversDefaultClass \NetterTechEvents\Enums\TicketStatus
 */
class TicketStatusTest extends \NetterTechEventsTestCase {

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
			'PENDING'    => array( 'pending', 'Pending' ),
			'CONFIRMED'  => array( 'confirmed', 'Confirmed' ),
			'CHECKED_IN' => array( 'checked_in', 'Checked In' ),
			'CANCELLED'  => array( 'cancelled', 'Cancelled' ),
		);
	}

	/**
	 * Provide enum cases with their expected is_issued behavior.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function issued_provider(): array {
		return array(
			'PENDING'    => array( 'pending', false ),
			'CONFIRMED'  => array( 'confirmed', true ),
			'CHECKED_IN' => array( 'checked_in', true ),
			'CANCELLED'  => array( 'cancelled', false ),
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
		$status = TicketStatus::from( $value );

		$this->assertSame( $value, $status->value );
		$this->assertStringContainsString( $label_substring, $status->label() );
	}

	/**
	 * Test each case reports the correct is_issued boolean.
	 *
	 * @dataProvider issued_provider
	 *
	 * @param string $value     The enum value to test.
	 * @param bool   $is_issued Whether the status should count as issued.
	 *
	 * @return void
	 */
	public function test_is_issued( string $value, bool $is_issued ): void {
		$status = TicketStatus::from( $value );

		$this->assertSame( $is_issued, $status->is_issued() );
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
		$this->assertNotEmpty( TicketStatus::PENDING->label() );
		$this->assertNotEmpty( TicketStatus::CONFIRMED->label() );
		$this->assertNotEmpty( TicketStatus::CHECKED_IN->label() );
		$this->assertNotEmpty( TicketStatus::CANCELLED->label() );
	}

	/**
	 * Test values returns all status values.
	 *
	 * @return void
	 */
	public function test_values_returns_all_statuses(): void {
		$values = TicketStatus::values();

		$this->assertCount( 4, $values );
		$this->assertContains( 'pending', $values );
		$this->assertContains( 'confirmed', $values );
		$this->assertContains( 'checked_in', $values );
		$this->assertContains( 'cancelled', $values );
	}

	/**
	 * Test tryFrom returns null for invalid value.
	 *
	 * Guards against the prior taxonomy drift where 'active', 'refunded',
	 * and other non-canonical values were written to tickets.status.
	 *
	 * @return void
	 */
	public function test_try_from_returns_null_for_invalid(): void {
		$this->assertNull( TicketStatus::tryFrom( 'active' ) );
		$this->assertNull( TicketStatus::tryFrom( 'refunded' ) );
		$this->assertNull( TicketStatus::tryFrom( 'invalid' ) );
		$this->assertNull( TicketStatus::tryFrom( '' ) );
	}

	/**
	 * Test cases returns all enum cases.
	 *
	 * @return void
	 */
	public function test_cases_returns_all_cases(): void {
		$cases = TicketStatus::cases();

		$this->assertCount( 4, $cases );
		$this->assertContains( TicketStatus::PENDING, $cases );
		$this->assertContains( TicketStatus::CONFIRMED, $cases );
		$this->assertContains( TicketStatus::CHECKED_IN, $cases );
		$this->assertContains( TicketStatus::CANCELLED, $cases );
	}
}
