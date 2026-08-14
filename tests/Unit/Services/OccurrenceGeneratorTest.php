<?php
/**
 * OccurrenceGenerator unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\RecurrenceRule;
use NetterTechEvents\Services\OccurrenceGenerator;
use NetterTechEvents\Tests\Factories\EventFactory;

/**
 * Test OccurrenceGenerator functionality.
 *
 * Tests RFC 5545 RRULE expansion including:
 * - Daily, weekly, monthly, yearly frequencies
 * - COUNT and UNTIL limits
 * - BYDAY, BYMONTHDAY, BYSETPOS modifiers
 * - Interval handling
 * - Edge cases (month boundaries, DST, leap years)
 */
class OccurrenceGeneratorTest extends \NetterTechEventsTestCase {

	/**
	 * OccurrenceGenerator instance.
	 *
	 * @var OccurrenceGenerator
	 */
	private OccurrenceGenerator $generator;

	/**
	 * Test event.
	 *
	 * @var Event
	 */
	private Event $event;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Mock get_option for horizon settings.
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'occurrence_horizon' => 365 );
				}
				return $default;
			}
		);

		$this->generator = new OccurrenceGenerator();
		$this->event     = EventFactory::create( array( 'id' => 1 ) );

		EventFactory::reset();
	}

	// =========================================================================
	// DAILY RECURRENCE TESTS
	// =========================================================================

	/**
	 * Test daily recurrence generates correct number of occurrences.
	 *
	 * @return void
	 */
	public function test_daily_generates_correct_occurrences(): void {
		$start   = new \DateTimeImmutable( '2026-01-01 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-01 12:00:00' );
		$rule    = RecurrenceRule::daily()->with_count( 5 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 5, $occurrences );
		$this->assertDatesEqual( '2026-01-01 10:00:00', $occurrences[0]->start_datetime );
		$this->assertDatesEqual( '2026-01-02 10:00:00', $occurrences[1]->start_datetime );
		$this->assertDatesEqual( '2026-01-03 10:00:00', $occurrences[2]->start_datetime );
		$this->assertDatesEqual( '2026-01-04 10:00:00', $occurrences[3]->start_datetime );
		$this->assertDatesEqual( '2026-01-05 10:00:00', $occurrences[4]->start_datetime );
	}

	/**
	 * Test daily recurrence with interval.
	 *
	 * @return void
	 */
	public function test_daily_with_interval(): void {
		$start   = new \DateTimeImmutable( '2026-01-01 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-01 12:00:00' );
		$rule    = RecurrenceRule::daily( 3 )->with_count( 4 ); // Every 3 days.
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 4, $occurrences );
		$this->assertDatesEqual( '2026-01-01 10:00:00', $occurrences[0]->start_datetime );
		$this->assertDatesEqual( '2026-01-04 10:00:00', $occurrences[1]->start_datetime );
		$this->assertDatesEqual( '2026-01-07 10:00:00', $occurrences[2]->start_datetime );
		$this->assertDatesEqual( '2026-01-10 10:00:00', $occurrences[3]->start_datetime );
	}

	/**
	 * Test daily recurrence with UNTIL limit.
	 *
	 * @return void
	 */
	public function test_daily_with_until(): void {
		$start   = new \DateTimeImmutable( '2026-01-01 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-01 12:00:00' );
		$until   = new \DateTimeImmutable( '2026-01-05 23:59:59' );
		$rule    = RecurrenceRule::daily()->with_until( $until );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 5, $occurrences );
		$this->assertDatesEqual( '2026-01-05 10:00:00', $occurrences[4]->start_datetime );
	}

	/**
	 * Test daily recurrence preserves duration.
	 *
	 * @return void
	 */
	public function test_daily_preserves_duration(): void {
		$start   = new \DateTimeImmutable( '2026-01-01 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-01 14:30:00' ); // 4.5 hours.
		$rule    = RecurrenceRule::daily()->with_count( 3 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 3, $occurrences );

		foreach ( $occurrences as $occurrence ) {
			$start_time = new \DateTime( $occurrence->start_datetime );
			$end_time   = new \DateTime( $occurrence->end_datetime );
			$diff       = $start_time->diff( $end_time );

			// 4 hours, 30 minutes.
			$this->assertEquals( 4, $diff->h );
			$this->assertEquals( 30, $diff->i );
		}
	}

	// =========================================================================
	// WEEKLY RECURRENCE TESTS
	// =========================================================================

	/**
	 * Test weekly recurrence on specific day.
	 *
	 * @return void
	 */
	public function test_weekly_on_same_day(): void {
		$start   = new \DateTimeImmutable( '2026-01-05 10:00:00' ); // Monday.
		$end     = new \DateTimeImmutable( '2026-01-05 12:00:00' );
		$rule    = RecurrenceRule::weekly()->with_count( 4 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 4, $occurrences );
		$this->assertDatesEqual( '2026-01-05 10:00:00', $occurrences[0]->start_datetime );
		$this->assertDatesEqual( '2026-01-12 10:00:00', $occurrences[1]->start_datetime );
		$this->assertDatesEqual( '2026-01-19 10:00:00', $occurrences[2]->start_datetime );
		$this->assertDatesEqual( '2026-01-26 10:00:00', $occurrences[3]->start_datetime );
	}

	/**
	 * Test generate sets a 1-based sequence_number in generation (date) order.
	 *
	 * Regression for NTE-077: sequence_number was never set (defaulted to 1),
	 * which broke iCal original-slot recovery (RECURRENCE-ID / EXDATE).
	 *
	 * @return void
	 */
	public function test_generate_sets_incremental_sequence_number(): void {
		$start   = new \DateTimeImmutable( '2026-01-05 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-05 12:00:00' );
		$rule    = RecurrenceRule::weekly()->with_count( 5 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 5, $occurrences );
		foreach ( $occurrences as $i => $occurrence ) {
			$this->assertSame(
				$i + 1,
				$occurrence->sequence_number,
				"Occurrence at index {$i} should have sequence_number " . ( $i + 1 )
			);
		}
	}

	/**
	 * Test weekly recurrence with BYDAY.
	 *
	 * @return void
	 */
	public function test_weekly_with_byday(): void {
		$start = new \DateTimeImmutable( '2026-01-05 10:00:00' ); // Monday.
		$end   = new \DateTimeImmutable( '2026-01-05 12:00:00' );
		// Every week on Monday and Wednesday.
		$rule    = RecurrenceRule::weekly( 1, array( RecurrenceRule::DAY_MO, RecurrenceRule::DAY_WE ) )
			->with_count( 6 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 6, $occurrences );
		$this->assertDatesEqual( '2026-01-05 10:00:00', $occurrences[0]->start_datetime ); // Mon.
		$this->assertDatesEqual( '2026-01-07 10:00:00', $occurrences[1]->start_datetime ); // Wed.
		$this->assertDatesEqual( '2026-01-12 10:00:00', $occurrences[2]->start_datetime ); // Mon.
		$this->assertDatesEqual( '2026-01-14 10:00:00', $occurrences[3]->start_datetime ); // Wed.
	}

	/**
	 * Test weekly recurrence with interval.
	 *
	 * @return void
	 */
	public function test_weekly_with_interval(): void {
		$start   = new \DateTimeImmutable( '2026-01-05 10:00:00' ); // Monday.
		$end     = new \DateTimeImmutable( '2026-01-05 12:00:00' );
		$rule    = RecurrenceRule::weekly( 2 )->with_count( 4 ); // Every 2 weeks.
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 4, $occurrences );
		$this->assertDatesEqual( '2026-01-05 10:00:00', $occurrences[0]->start_datetime );
		$this->assertDatesEqual( '2026-01-19 10:00:00', $occurrences[1]->start_datetime );
		$this->assertDatesEqual( '2026-02-02 10:00:00', $occurrences[2]->start_datetime );
		$this->assertDatesEqual( '2026-02-16 10:00:00', $occurrences[3]->start_datetime );
	}

	// =========================================================================
	// MONTHLY RECURRENCE TESTS
	// =========================================================================

	/**
	 * Test monthly recurrence by day of month.
	 *
	 * @return void
	 */
	public function test_monthly_by_day_of_month(): void {
		$start   = new \DateTimeImmutable( '2026-01-15 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-15 12:00:00' );
		$rule    = RecurrenceRule::monthly( 1, array( 15 ) )->with_count( 4 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 4, $occurrences );
		$this->assertDatesEqual( '2026-01-15 10:00:00', $occurrences[0]->start_datetime );
		$this->assertDatesEqual( '2026-02-15 10:00:00', $occurrences[1]->start_datetime );
		$this->assertDatesEqual( '2026-03-15 10:00:00', $occurrences[2]->start_datetime );
		$this->assertDatesEqual( '2026-04-15 10:00:00', $occurrences[3]->start_datetime );
	}

	/**
	 * Test monthly recurrence defaults to start date day.
	 *
	 * @return void
	 */
	public function test_monthly_defaults_to_start_day(): void {
		$start   = new \DateTimeImmutable( '2026-01-20 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-20 12:00:00' );
		$rule    = RecurrenceRule::monthly()->with_count( 3 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 3, $occurrences );
		$this->assertDatesEqual( '2026-01-20 10:00:00', $occurrences[0]->start_datetime );
		$this->assertDatesEqual( '2026-02-20 10:00:00', $occurrences[1]->start_datetime );
		$this->assertDatesEqual( '2026-03-20 10:00:00', $occurrences[2]->start_datetime );
	}

	/**
	 * Test monthly recurrence skips months without that day.
	 *
	 * @return void
	 */
	public function test_monthly_skips_invalid_days(): void {
		$start   = new \DateTimeImmutable( '2026-01-31 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-31 12:00:00' );
		$rule    = RecurrenceRule::monthly( 1, array( 31 ) )->with_count( 5 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		// Only months with 31 days: Jan, Mar, May, Jul, Aug, Oct, Dec.
		$this->assertCount( 5, $occurrences );
		$this->assertDatesEqual( '2026-01-31 10:00:00', $occurrences[0]->start_datetime );
		$this->assertDatesEqual( '2026-03-31 10:00:00', $occurrences[1]->start_datetime );
		$this->assertDatesEqual( '2026-05-31 10:00:00', $occurrences[2]->start_datetime );
		$this->assertDatesEqual( '2026-07-31 10:00:00', $occurrences[3]->start_datetime );
		$this->assertDatesEqual( '2026-08-31 10:00:00', $occurrences[4]->start_datetime );
	}

	/**
	 * Test monthly recurrence by first weekday (first Monday).
	 *
	 * @return void
	 */
	public function test_monthly_first_monday(): void {
		$start   = new \DateTimeImmutable( '2026-01-05 10:00:00' ); // First Monday of Jan 2026.
		$end     = new \DateTimeImmutable( '2026-01-05 12:00:00' );
		$rule    = RecurrenceRule::monthly_by_day( 1, RecurrenceRule::DAY_MO, 1 )
			->with_count( 4 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 4, $occurrences );
		$this->assertDatesEqual( '2026-01-05 10:00:00', $occurrences[0]->start_datetime ); // 1st Mon Jan.
		$this->assertDatesEqual( '2026-02-02 10:00:00', $occurrences[1]->start_datetime ); // 1st Mon Feb.
		$this->assertDatesEqual( '2026-03-02 10:00:00', $occurrences[2]->start_datetime ); // 1st Mon Mar.
		$this->assertDatesEqual( '2026-04-06 10:00:00', $occurrences[3]->start_datetime ); // 1st Mon Apr.
	}

	/**
	 * Test monthly recurrence by second Tuesday.
	 *
	 * @return void
	 */
	public function test_monthly_second_tuesday(): void {
		$start   = new \DateTimeImmutable( '2026-01-13 10:00:00' ); // Second Tuesday of Jan 2026.
		$end     = new \DateTimeImmutable( '2026-01-13 12:00:00' );
		$rule    = RecurrenceRule::monthly_by_day( 1, RecurrenceRule::DAY_TU, 2 )
			->with_count( 4 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 4, $occurrences );
		$this->assertDatesEqual( '2026-01-13 10:00:00', $occurrences[0]->start_datetime );
		$this->assertDatesEqual( '2026-02-10 10:00:00', $occurrences[1]->start_datetime );
		$this->assertDatesEqual( '2026-03-10 10:00:00', $occurrences[2]->start_datetime );
		$this->assertDatesEqual( '2026-04-14 10:00:00', $occurrences[3]->start_datetime );
	}

	/**
	 * Test monthly recurrence by last Friday.
	 *
	 * @return void
	 */
	public function test_monthly_last_friday(): void {
		$start   = new \DateTimeImmutable( '2026-01-30 10:00:00' ); // Last Friday of Jan 2026.
		$end     = new \DateTimeImmutable( '2026-01-30 12:00:00' );
		$rule    = RecurrenceRule::monthly_by_day( 1, RecurrenceRule::DAY_FR, -1 )
			->with_count( 4 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 4, $occurrences );
		$this->assertDatesEqual( '2026-01-30 10:00:00', $occurrences[0]->start_datetime ); // Last Fri Jan.
		$this->assertDatesEqual( '2026-02-27 10:00:00', $occurrences[1]->start_datetime ); // Last Fri Feb.
		$this->assertDatesEqual( '2026-03-27 10:00:00', $occurrences[2]->start_datetime ); // Last Fri Mar.
		$this->assertDatesEqual( '2026-04-24 10:00:00', $occurrences[3]->start_datetime ); // Last Fri Apr.
	}

	/**
	 * Test monthly recurrence by last Sunday.
	 *
	 * @return void
	 */
	public function test_monthly_last_sunday(): void {
		$start   = new \DateTimeImmutable( '2026-01-25 10:00:00' ); // Last Sunday of Jan 2026.
		$end     = new \DateTimeImmutable( '2026-01-25 12:00:00' );
		$rule    = RecurrenceRule::monthly_by_day( 1, RecurrenceRule::DAY_SU, -1 )
			->with_count( 6 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 6, $occurrences );
		$this->assertDatesEqual( '2026-01-25 10:00:00', $occurrences[0]->start_datetime ); // Last Sun Jan.
		$this->assertDatesEqual( '2026-02-22 10:00:00', $occurrences[1]->start_datetime ); // Last Sun Feb.
		$this->assertDatesEqual( '2026-03-29 10:00:00', $occurrences[2]->start_datetime ); // Last Sun Mar.
		$this->assertDatesEqual( '2026-04-26 10:00:00', $occurrences[3]->start_datetime ); // Last Sun Apr.
		$this->assertDatesEqual( '2026-05-31 10:00:00', $occurrences[4]->start_datetime ); // Last Sun May.
		$this->assertDatesEqual( '2026-06-28 10:00:00', $occurrences[5]->start_datetime ); // Last Sun Jun.
	}

	/**
	 * Test monthly recurrence with interval.
	 *
	 * @return void
	 */
	public function test_monthly_with_interval(): void {
		$start   = new \DateTimeImmutable( '2026-01-15 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-15 12:00:00' );
		$rule    = RecurrenceRule::monthly( 2, array( 15 ) )->with_count( 4 ); // Every 2 months.
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 4, $occurrences );
		$this->assertDatesEqual( '2026-01-15 10:00:00', $occurrences[0]->start_datetime );
		$this->assertDatesEqual( '2026-03-15 10:00:00', $occurrences[1]->start_datetime );
		$this->assertDatesEqual( '2026-05-15 10:00:00', $occurrences[2]->start_datetime );
		$this->assertDatesEqual( '2026-07-15 10:00:00', $occurrences[3]->start_datetime );
	}

	// =========================================================================
	// YEARLY RECURRENCE TESTS
	// =========================================================================

	/**
	 * Test yearly recurrence.
	 *
	 * @return void
	 */
	public function test_yearly_generates_correct_occurrences(): void {
		$start   = new \DateTimeImmutable( '2026-03-15 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-03-15 12:00:00' );
		$rule    = RecurrenceRule::yearly()->with_count( 3 );
		$horizon = new \DateTimeImmutable( '2030-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 3, $occurrences );
		$this->assertDatesEqual( '2026-03-15 10:00:00', $occurrences[0]->start_datetime );
		$this->assertDatesEqual( '2027-03-15 10:00:00', $occurrences[1]->start_datetime );
		$this->assertDatesEqual( '2028-03-15 10:00:00', $occurrences[2]->start_datetime );
	}

	/**
	 * Test yearly recurrence with interval.
	 *
	 * @return void
	 */
	public function test_yearly_with_interval(): void {
		$start   = new \DateTimeImmutable( '2026-01-01 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-01 12:00:00' );
		$rule    = RecurrenceRule::yearly( 2 )->with_count( 3 ); // Every 2 years.
		$horizon = new \DateTimeImmutable( '2035-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 3, $occurrences );
		$this->assertDatesEqual( '2026-01-01 10:00:00', $occurrences[0]->start_datetime );
		$this->assertDatesEqual( '2028-01-01 10:00:00', $occurrences[1]->start_datetime );
		$this->assertDatesEqual( '2030-01-01 10:00:00', $occurrences[2]->start_datetime );
	}

	// =========================================================================
	// LIMIT AND BOUNDARY TESTS
	// =========================================================================

	/**
	 * Test that horizon limits occurrences.
	 *
	 * @return void
	 */
	public function test_horizon_limits_occurrences(): void {
		$start   = new \DateTimeImmutable( '2026-01-01 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-01 12:00:00' );
		$rule    = RecurrenceRule::daily()->with_count( 100 );
		$horizon = new \DateTimeImmutable( '2026-01-10 23:59:59' ); // End of day 10.

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 10, $occurrences );
	}

	/**
	 * Test maximum occurrence limit is enforced when no COUNT specified.
	 *
	 * @return void
	 */
	public function test_max_occurrence_limit(): void {
		$start = new \DateTimeImmutable( '2026-01-01 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-01-01 12:00:00' );
		$rule  = RecurrenceRule::daily(); // No count - should hit MAX_OCCURRENCES.
		// Set until far in future.
		$rule    = $rule->with_until( new \DateTimeImmutable( '2030-12-31' ) );
		$horizon = new \DateTimeImmutable( '2030-12-31' ); // Long horizon.

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertLessThanOrEqual( OccurrenceGenerator::MAX_OCCURRENCES, count( $occurrences ) );
	}

	/**
	 * Test invalid frequency returns empty array.
	 *
	 * @return void
	 */
	public function test_invalid_frequency_returns_empty(): void {
		$start = new \DateTimeImmutable( '2026-01-01 10:00:00' );
		$end   = new \DateTimeImmutable( '2026-01-01 12:00:00' );

		// Create a rule with valid freq, then modify it.
		$rule       = RecurrenceRule::daily();
		$reflection = new \ReflectionProperty( $rule, 'freq' );
		$reflection->setValue( $rule, 'INVALID' );

		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertEmpty( $occurrences );
	}

	// =========================================================================
	// OCCURRENCE OBJECT TESTS
	// =========================================================================

	/**
	 * Test occurrence has correct event ID.
	 *
	 * @return void
	 */
	public function test_occurrence_has_event_id(): void {
		$event   = EventFactory::create( array( 'id' => 42 ) );
		$start   = new \DateTimeImmutable( '2026-01-01 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-01 12:00:00' );
		$rule    = RecurrenceRule::daily()->with_count( 1 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $event, $start, $end, $rule, $horizon );

		$this->assertCount( 1, $occurrences );
		$this->assertEquals( 42, $occurrences[0]->event_id );
	}

	/**
	 * Test occurrence has scheduled status.
	 *
	 * @return void
	 */
	public function test_occurrence_has_scheduled_status(): void {
		$start   = new \DateTimeImmutable( '2026-01-01 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-01 12:00:00' );
		$rule    = RecurrenceRule::daily()->with_count( 3 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		foreach ( $occurrences as $occurrence ) {
			$this->assertEquals( 'scheduled', $occurrence->status );
		}
	}

	/**
	 * Test occurrence is of correct type.
	 *
	 * @return void
	 */
	public function test_occurrences_are_occurrence_objects(): void {
		$start   = new \DateTimeImmutable( '2026-01-01 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-01 12:00:00' );
		$rule    = RecurrenceRule::daily()->with_count( 3 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		foreach ( $occurrences as $occurrence ) {
			$this->assertInstanceOf( Occurrence::class, $occurrence );
		}
	}

	// =========================================================================
	// EDGE CASE TESTS
	// =========================================================================

	/**
	 * Test leap year February 29.
	 *
	 * PHP's DateTimeImmutable::modify('+1 year') on Feb 29 results in March 1
	 * on non-leap years. This tests that behavior is consistent.
	 *
	 * @return void
	 */
	public function test_leap_year_february(): void {
		$start   = new \DateTimeImmutable( '2024-02-29 10:00:00' ); // Leap year.
		$end     = new \DateTimeImmutable( '2024-02-29 12:00:00' );
		$rule    = RecurrenceRule::yearly()->with_count( 3 );
		$horizon = new \DateTimeImmutable( '2030-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		// PHP's +1 year on Feb 29 goes to March 1 on non-leap years.
		$this->assertCount( 3, $occurrences );
		$this->assertDatesEqual( '2024-02-29 10:00:00', $occurrences[0]->start_datetime );
		$this->assertDatesEqual( '2025-03-01 10:00:00', $occurrences[1]->start_datetime );
		$this->assertDatesEqual( '2026-03-01 10:00:00', $occurrences[2]->start_datetime );
	}

	/**
	 * Test single occurrence with COUNT=1.
	 *
	 * @return void
	 */
	public function test_single_occurrence(): void {
		$start   = new \DateTimeImmutable( '2026-01-01 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-01 12:00:00' );
		$rule    = RecurrenceRule::daily()->with_count( 1 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 1, $occurrences );
		$this->assertDatesEqual( '2026-01-01 10:00:00', $occurrences[0]->start_datetime );
	}

	/**
	 * Test multi-day event duration is preserved.
	 *
	 * @return void
	 */
	public function test_multi_day_event_duration(): void {
		$start   = new \DateTimeImmutable( '2026-01-01 10:00:00' );
		$end     = new \DateTimeImmutable( '2026-01-03 12:00:00' ); // 2 day event.
		$rule    = RecurrenceRule::weekly()->with_count( 2 );
		$horizon = new \DateTimeImmutable( '2026-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		$this->assertCount( 2, $occurrences );
		$this->assertDatesEqual( '2026-01-01 10:00:00', $occurrences[0]->start_datetime );
		$this->assertDatesEqual( '2026-01-03 12:00:00', $occurrences[0]->end_datetime );
		$this->assertDatesEqual( '2026-01-08 10:00:00', $occurrences[1]->start_datetime );
		$this->assertDatesEqual( '2026-01-10 12:00:00', $occurrences[1]->end_datetime );
	}

	// =========================================================================
	// HELPER METHODS
	// =========================================================================

	/**
	 * Assert two date strings are equal.
	 *
	 * @param string $expected Expected date.
	 * @param string $actual   Actual date.
	 * @return void
	 */
	/**
	 * Yearly recurrence with BYMONTH expands to each listed month per year,
	 * keeping the start date's day-of-month and time. Months before the start
	 * date are excluded.
	 *
	 * @return void
	 */
	public function test_yearly_with_by_month_expands_to_listed_months(): void {
		$start          = new \DateTimeImmutable( '2026-03-15 10:00:00' );
		$end            = new \DateTimeImmutable( '2026-03-15 12:00:00' );
		$rule           = RecurrenceRule::yearly()->with_count( 4 );
		$rule->by_month = array( 7, 1 ); // Intentionally unsorted: Jan + Jul.
		$horizon        = new \DateTimeImmutable( '2030-12-31' );

		$occurrences = $this->generator->generate( $this->event, $start, $end, $rule, $horizon );

		// 2026 Jan 15 is before the March 15 start, so it is skipped; the series
		// begins at Jul 2026 and alternates Jan/Jul thereafter.
		$this->assertCount( 4, $occurrences );
		$this->assertDatesEqual( '2026-07-15 10:00:00', $occurrences[0]->start_datetime );
		$this->assertDatesEqual( '2027-01-15 10:00:00', $occurrences[1]->start_datetime );
		$this->assertDatesEqual( '2027-07-15 10:00:00', $occurrences[2]->start_datetime );
		$this->assertDatesEqual( '2028-01-15 10:00:00', $occurrences[3]->start_datetime );
	}

	// =========================================================================
	// Future-only collection Tests (NTE-200)
	// =========================================================================

	/**
	 * A bare FREQ=WEEKLY rule collected from a mid-series boundary keeps its
	 * weekday, its time-of-day, its COUNT totality, and its sequence numbers
	 * (NTE-200).
	 *
	 * The boundary is deterministic (anchor + 5 weeks − 1 hour) so the test
	 * never depends on the wall clock: exactly 5 of the 8 expanded Mondays
	 * fall before it and must be consumed-but-not-collected.
	 *
	 * @return void
	 */
	public function test_weekly_collect_from_keeps_weekday_count_and_sequence(): void {
		$anchor   = new \DateTimeImmutable( '2026-05-04 19:00:00' ); // Monday.
		$end      = $anchor->modify( '+2 hours' );
		$boundary = $anchor->modify( '+5 weeks' )->modify( '-1 hour' );
		$rule     = RecurrenceRule::weekly()->with_count( 8 );

		$occurrences = $this->generator->generate( $this->event, $anchor, $end, $rule, null, $boundary );

		$this->assertCount( 3, $occurrences, 'COUNT=8 minus 5 consumed past slots leaves 3 — never 8 more (the COUNT-restart defect)' );

		$expected_sequence = array( 6, 7, 8 );
		foreach ( $occurrences as $i => $occurrence ) {
			$start = new \DateTimeImmutable( $occurrence->start_datetime );
			$this->assertSame( '1', $start->format( 'N' ), 'A BYDAY-less weekly rule must stay on its DTSTART weekday (Monday)' );
			$this->assertSame( '19:00:00', $start->format( 'H:i:s' ), 'Original time-of-day must be preserved (11:18 pm regression)' );
			$this->assertSame( $expected_sequence[ $i ], $occurrence->sequence_number, 'Sequence numbers are full-rule positions, not restarted' );
		}

		$this->assertDatesEqual( '2026-06-08 19:00:00', $occurrences[0]->start_datetime );
	}

	/**
	 * An uncounted series whose anchor is years past still reaches the
	 * horizon: past expansion must not exhaust the safety cap (NTE-200).
	 *
	 * @return void
	 */
	public function test_uncounted_old_weekly_series_still_reaches_horizon(): void {
		$now    = new \DateTimeImmutable( 'monday this week 19:00:00' );
		$anchor = $now->modify( '-104 weeks' ); // Two years of past Mondays.
		$end    = $anchor->modify( '+2 hours' );
		$rule   = RecurrenceRule::weekly();

		$occurrences = $this->generator->generate( $this->event, $anchor, $end, $rule, null, new \DateTimeImmutable() );

		$this->assertNotEmpty( $occurrences, 'Old series must still produce future rows (uncounted cap must not be eaten by the past)' );
		$this->assertGreaterThan( 40, count( $occurrences ), 'Roughly a year of weekly rows expected to the horizon' );

		$boundary = new \DateTimeImmutable();
		foreach ( $occurrences as $occurrence ) {
			$start = new \DateTimeImmutable( $occurrence->start_datetime );
			$this->assertSame( '1', $start->format( 'N' ), 'Weekday must remain the anchor weekday' );
			$this->assertGreaterThanOrEqual( $boundary->getTimestamp(), $start->getTimestamp() + 1, 'No past rows may be collected' );
		}
	}

	/**
	 * @param string $expected Expected date.
	 * @param string $actual   Actual date.
	 * @return void
	 */
	private function assertDatesEqual( string $expected, string $actual ): void {
		$this->assertEquals(
			( new \DateTime( $expected ) )->format( 'Y-m-d H:i:s' ),
			( new \DateTime( $actual ) )->format( 'Y-m-d H:i:s' ),
			"Expected date $expected but got $actual"
		);
	}
}
