<?php
/**
 * RecurrenceRule model unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use NetterTechEvents\Models\RecurrenceRule;

/**
 * Test RecurrenceRule model functionality.
 */
class RecurrenceRuleTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test constructor accepts valid frequency.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_valid_frequency(): void {
		$rule = new RecurrenceRule( RecurrenceRule::FREQ_DAILY );

		$this->assertSame( 'DAILY', $rule->freq );
	}

	/**
	 * Test constructor rejects invalid frequency.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_invalid_frequency(): void {
		$this->expectException( \NetterTechEvents\Exceptions\RRuleException::class );
		$this->expectExceptionMessage( 'Invalid FREQ value: HOURLY' );

		new RecurrenceRule( 'HOURLY' );
	}

	/**
	 * Test constructor default values.
	 *
	 * @return void
	 */
	public function test_constructor_default_values(): void {
		$rule = new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY );

		$this->assertSame( 1, $rule->interval );
		$this->assertNull( $rule->count );
		$this->assertNull( $rule->until );
		$this->assertEmpty( $rule->by_day );
		$this->assertEmpty( $rule->by_month_day );
		$this->assertEmpty( $rule->by_month );
		$this->assertSame( 'MO', $rule->wkst );
	}

	// =========================================================================
	// Factory Method Tests
	// =========================================================================

	/**
	 * Test daily() creates daily rule.
	 *
	 * @return void
	 */
	public function test_daily_creates_daily_rule(): void {
		$rule = RecurrenceRule::daily();

		$this->assertSame( 'DAILY', $rule->freq );
		$this->assertSame( 1, $rule->interval );
	}

	/**
	 * Test daily() with interval.
	 *
	 * @return void
	 */
	public function test_daily_with_interval(): void {
		$rule = RecurrenceRule::daily( 3 );

		$this->assertSame( 3, $rule->interval );
	}

	/**
	 * Test daily() clamps negative interval.
	 *
	 * @return void
	 */
	public function test_daily_clamps_negative_interval(): void {
		$rule = RecurrenceRule::daily( -1 );

		$this->assertSame( 1, $rule->interval );
	}

	/**
	 * Test weekly() creates weekly rule.
	 *
	 * @return void
	 */
	public function test_weekly_creates_weekly_rule(): void {
		$rule = RecurrenceRule::weekly();

		$this->assertSame( 'WEEKLY', $rule->freq );
		$this->assertSame( 1, $rule->interval );
	}

	/**
	 * Test weekly() with days.
	 *
	 * @return void
	 */
	public function test_weekly_with_days(): void {
		$rule = RecurrenceRule::weekly( 1, array( 'MO', 'WE', 'FR' ) );

		$this->assertSame( array( 'MO', 'WE', 'FR' ), $rule->by_day );
	}

	/**
	 * Test weekly() filters invalid days.
	 *
	 * @return void
	 */
	public function test_weekly_filters_invalid_days(): void {
		$rule = RecurrenceRule::weekly( 1, array( 'MO', 'INVALID', 'FR' ) );

		// array_intersect preserves keys, so use array_values for comparison.
		$this->assertSame( array( 'MO', 'FR' ), array_values( $rule->by_day ) );
	}

	/**
	 * Test monthly() creates monthly rule.
	 *
	 * @return void
	 */
	public function test_monthly_creates_monthly_rule(): void {
		$rule = RecurrenceRule::monthly();

		$this->assertSame( 'MONTHLY', $rule->freq );
	}

	/**
	 * Test monthly() with days.
	 *
	 * @return void
	 */
	public function test_monthly_with_days(): void {
		$rule = RecurrenceRule::monthly( 1, array( 1, 15 ) );

		$this->assertSame( array( 1, 15 ), $rule->by_month_day );
	}

	/**
	 * Test monthly() filters invalid days.
	 *
	 * @return void
	 */
	public function test_monthly_filters_invalid_days(): void {
		$rule = RecurrenceRule::monthly( 1, array( 1, 32, 15 ) );

		// array_filter preserves keys, so use array_values for comparison.
		$this->assertSame( array( 1, 15 ), array_values( $rule->by_month_day ) );
	}

	/**
	 * Test monthly_by_day() creates monthly by weekday rule.
	 *
	 * @return void
	 */
	public function test_monthly_by_day_creates_rule(): void {
		$rule = RecurrenceRule::monthly_by_day( 1, 'MO', 1 );

		$this->assertSame( 'MONTHLY', $rule->freq );
		$this->assertSame( array( 'MO' ), $rule->by_day );
		$this->assertSame( array( 1 ), $rule->by_set_pos );
	}

	/**
	 * Test yearly() creates yearly rule.
	 *
	 * @return void
	 */
	public function test_yearly_creates_yearly_rule(): void {
		$rule = RecurrenceRule::yearly();

		$this->assertSame( 'YEARLY', $rule->freq );
	}

	/**
	 * Test yearly() with months.
	 *
	 * @return void
	 */
	public function test_yearly_with_months(): void {
		$rule = RecurrenceRule::yearly( 1, array( 1, 6, 12 ) );

		$this->assertSame( array( 1, 6, 12 ), $rule->by_month );
	}

	/**
	 * Test yearly() filters invalid months.
	 *
	 * @return void
	 */
	public function test_yearly_filters_invalid_months(): void {
		$rule = RecurrenceRule::yearly( 1, array( 1, 13, 12 ) );

		// array_filter preserves keys, so use array_values for comparison.
		$this->assertSame( array( 1, 12 ), array_values( $rule->by_month ) );
	}

	// =========================================================================
	// with_* Method Tests
	// =========================================================================

	/**
	 * Test with_count() sets count.
	 *
	 * @return void
	 */
	public function test_with_count_sets_count(): void {
		$rule = RecurrenceRule::daily()->with_count( 10 );

		$this->assertSame( 10, $rule->count );
		$this->assertNull( $rule->until );
	}

	/**
	 * Test with_count() clamps negative count.
	 *
	 * @return void
	 */
	public function test_with_count_clamps_negative(): void {
		$rule = RecurrenceRule::daily()->with_count( -5 );

		$this->assertSame( 1, $rule->count );
	}

	/**
	 * Test with_count() clears until.
	 *
	 * @return void
	 */
	public function test_with_count_clears_until(): void {
		$until = new \DateTimeImmutable( '2026-12-31' );
		$rule  = RecurrenceRule::daily()->with_until( $until )->with_count( 5 );

		$this->assertSame( 5, $rule->count );
		$this->assertNull( $rule->until );
	}

	/**
	 * Test with_until() sets until date.
	 *
	 * @return void
	 */
	public function test_with_until_sets_until(): void {
		$until = new \DateTimeImmutable( '2026-12-31' );
		$rule  = RecurrenceRule::daily()->with_until( $until );

		$this->assertInstanceOf( \DateTimeImmutable::class, $rule->until );
		$this->assertSame( '2026-12-31', $rule->until->format( 'Y-m-d' ) );
		$this->assertNull( $rule->count );
	}

	/**
	 * Test with_until() clears count.
	 *
	 * @return void
	 */
	public function test_with_until_clears_count(): void {
		$until = new \DateTimeImmutable( '2026-12-31' );
		$rule  = RecurrenceRule::daily()->with_count( 10 )->with_until( $until );

		$this->assertNotNull( $rule->until );
		$this->assertNull( $rule->count );
	}

	/**
	 * Test with_interval() sets interval.
	 *
	 * @return void
	 */
	public function test_with_interval_sets_interval(): void {
		$rule = RecurrenceRule::daily()->with_interval( 5 );

		$this->assertSame( 5, $rule->interval );
	}

	/**
	 * Test with_interval() clamps negative.
	 *
	 * @return void
	 */
	public function test_with_interval_clamps_negative(): void {
		$rule = RecurrenceRule::daily()->with_interval( -3 );

		$this->assertSame( 1, $rule->interval );
	}

	/**
	 * Test with_by_day() sets days.
	 *
	 * @return void
	 */
	public function test_with_by_day_sets_days(): void {
		$rule = RecurrenceRule::weekly()->with_by_day( array( 'TU', 'TH' ) );

		$this->assertSame( array( 'TU', 'TH' ), $rule->by_day );
	}

	/**
	 * Test with_by_day() filters invalid.
	 *
	 * @return void
	 */
	public function test_with_by_day_filters_invalid(): void {
		$rule = RecurrenceRule::weekly()->with_by_day( array( 'TU', 'INVALID' ) );

		$this->assertSame( array( 'TU' ), $rule->by_day );
	}

	/**
	 * Test with_* methods return new instance (immutable).
	 *
	 * @return void
	 */
	public function test_with_methods_are_immutable(): void {
		$original = RecurrenceRule::daily();
		$modified = $original->with_count( 5 );

		$this->assertNotSame( $original, $modified );
		$this->assertNull( $original->count );
		$this->assertSame( 5, $modified->count );
	}

	// =========================================================================
	// to_string Tests
	// =========================================================================

	/**
	 * Test to_string() basic daily.
	 *
	 * @return void
	 */
	public function test_to_string_basic_daily(): void {
		$rule = RecurrenceRule::daily();

		$this->assertSame( 'FREQ=DAILY', $rule->to_string() );
	}

	/**
	 * Test to_string() with interval.
	 *
	 * @return void
	 */
	public function test_to_string_with_interval(): void {
		$rule = RecurrenceRule::daily( 2 );

		$this->assertSame( 'FREQ=DAILY;INTERVAL=2', $rule->to_string() );
	}

	/**
	 * Test to_string() with count.
	 *
	 * @return void
	 */
	public function test_to_string_with_count(): void {
		$rule = RecurrenceRule::daily()->with_count( 10 );

		$this->assertSame( 'FREQ=DAILY;COUNT=10', $rule->to_string() );
	}

	/**
	 * Test to_string() with until.
	 *
	 * @return void
	 */
	public function test_to_string_with_until(): void {
		$until = new \DateTimeImmutable( '2026-12-31 23:59:59', new \DateTimeZone( 'UTC' ) );
		$rule  = RecurrenceRule::daily()->with_until( $until );

		$this->assertStringContainsString( 'UNTIL=20261231T', $rule->to_string() );
	}

	/**
	 * Test to_string() weekly with days.
	 *
	 * @return void
	 */
	public function test_to_string_weekly_with_days(): void {
		$rule = RecurrenceRule::weekly( 1, array( 'MO', 'WE', 'FR' ) );

		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO,WE,FR', $rule->to_string() );
	}

	/**
	 * Test to_string() monthly with day of month.
	 *
	 * @return void
	 */
	public function test_to_string_monthly_with_day(): void {
		$rule = RecurrenceRule::monthly( 1, array( 15 ) );

		$this->assertSame( 'FREQ=MONTHLY;BYMONTHDAY=15', $rule->to_string() );
	}

	/**
	 * Test to_string() monthly by weekday (first Monday).
	 *
	 * @return void
	 */
	public function test_to_string_monthly_by_weekday(): void {
		$rule = RecurrenceRule::monthly_by_day( 1, 'MO', 1 );

		$this->assertSame( 'FREQ=MONTHLY;BYDAY=1MO', $rule->to_string() );
	}

	/**
	 * Test to_string() yearly with months.
	 *
	 * @return void
	 */
	public function test_to_string_yearly_with_months(): void {
		$rule = RecurrenceRule::yearly( 1, array( 1, 7 ) );

		$this->assertSame( 'FREQ=YEARLY;BYMONTH=1,7', $rule->to_string() );
	}

	/**
	 * Test to_string() with non-MO week start.
	 *
	 * @return void
	 */
	public function test_to_string_with_wkst(): void {
		$rule       = RecurrenceRule::weekly();
		$rule->wkst = 'SU';

		$this->assertSame( 'FREQ=WEEKLY;WKST=SU', $rule->to_string() );
	}

	/**
	 * Test __toString() returns same as to_string().
	 *
	 * @return void
	 */
	public function test_magic_tostring(): void {
		$rule = RecurrenceRule::daily( 2 )->with_count( 5 );

		$this->assertSame( $rule->to_string(), (string) $rule );
	}

	// =========================================================================
	// has_end Tests
	// =========================================================================

	/**
	 * Test has_end() returns false when no end.
	 *
	 * @return void
	 */
	public function test_has_end_returns_false_when_no_end(): void {
		$rule = RecurrenceRule::daily();

		$this->assertFalse( $rule->has_end() );
	}

	/**
	 * Test has_end() returns true with count.
	 *
	 * @return void
	 */
	public function test_has_end_returns_true_with_count(): void {
		$rule = RecurrenceRule::daily()->with_count( 10 );

		$this->assertTrue( $rule->has_end() );
	}

	/**
	 * Test has_end() returns true with until.
	 *
	 * @return void
	 */
	public function test_has_end_returns_true_with_until(): void {
		$until = new \DateTimeImmutable( '2026-12-31' );
		$rule  = RecurrenceRule::daily()->with_until( $until );

		$this->assertTrue( $rule->has_end() );
	}

	// =========================================================================
	// get_description Tests
	// =========================================================================

	/**
	 * Test get_description() for daily.
	 *
	 * @return void
	 */
	public function test_get_description_daily(): void {
		$rule = RecurrenceRule::daily();

		$this->assertSame( 'Every day', $rule->get_description() );
	}

	/**
	 * Test get_description() for daily with interval.
	 *
	 * @return void
	 */
	public function test_get_description_daily_interval(): void {
		$rule = RecurrenceRule::daily( 3 );

		$this->assertSame( 'Every 3 days', $rule->get_description() );
	}

	/**
	 * Test get_description() for weekly.
	 *
	 * @return void
	 */
	public function test_get_description_weekly(): void {
		$rule = RecurrenceRule::weekly();

		$this->assertSame( 'Every week', $rule->get_description() );
	}

	/**
	 * Test get_description() for weekly with interval.
	 *
	 * @return void
	 */
	public function test_get_description_weekly_interval(): void {
		$rule = RecurrenceRule::weekly( 2 );

		$this->assertSame( 'Every 2 weeks', $rule->get_description() );
	}

	/**
	 * Test get_description() for weekly with days.
	 *
	 * @return void
	 */
	public function test_get_description_weekly_with_days(): void {
		$rule = RecurrenceRule::weekly( 1, array( 'MO', 'FR' ) );

		$this->assertStringContainsString( 'Every week', $rule->get_description() );
		$this->assertStringContainsString( 'Monday', $rule->get_description() );
		$this->assertStringContainsString( 'Friday', $rule->get_description() );
	}

	/**
	 * Test get_description() for monthly.
	 *
	 * @return void
	 */
	public function test_get_description_monthly(): void {
		$rule = RecurrenceRule::monthly();

		$this->assertSame( 'Every month', $rule->get_description() );
	}

	/**
	 * Test get_description() for monthly with interval.
	 *
	 * @return void
	 */
	public function test_get_description_monthly_interval(): void {
		$rule = RecurrenceRule::monthly( 2 );

		$this->assertSame( 'Every 2 months', $rule->get_description() );
	}

	/**
	 * Test get_description() for monthly with day.
	 *
	 * @return void
	 */
	public function test_get_description_monthly_with_day(): void {
		$rule = RecurrenceRule::monthly( 1, array( 15 ) );

		$this->assertStringContainsString( 'Every month', $rule->get_description() );
		$this->assertStringContainsString( 'day 15', $rule->get_description() );
	}

	/**
	 * Test get_description() for yearly.
	 *
	 * @return void
	 */
	public function test_get_description_yearly(): void {
		$rule = RecurrenceRule::yearly();

		$this->assertSame( 'Every year', $rule->get_description() );
	}

	/**
	 * Test get_description() for yearly with interval.
	 *
	 * @return void
	 */
	public function test_get_description_yearly_interval(): void {
		$rule = RecurrenceRule::yearly( 2 );

		$this->assertSame( 'Every 2 years', $rule->get_description() );
	}

	/**
	 * Test get_description() with count.
	 *
	 * @return void
	 */
	public function test_get_description_with_count(): void {
		$rule = RecurrenceRule::daily()->with_count( 5 );

		$this->assertStringContainsString( '5 times', $rule->get_description() );
	}

	/**
	 * Test get_description() with until.
	 *
	 * @return void
	 */
	public function test_get_description_with_until(): void {
		// Mock get_option to return a valid date format.
		\Brain\Monkey\Functions\when( 'get_option' )
			->justReturn( 'Y-m-d' );

		$until = new \DateTimeImmutable( '2026-12-31' );
		$rule  = RecurrenceRule::daily()->with_until( $until );

		$this->assertStringContainsString( 'until', $rule->get_description() );
	}

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test FREQUENCIES constant.
	 *
	 * @return void
	 */
	public function test_frequencies_constant(): void {
		$this->assertContains( 'DAILY', RecurrenceRule::FREQUENCIES );
		$this->assertContains( 'WEEKLY', RecurrenceRule::FREQUENCIES );
		$this->assertContains( 'MONTHLY', RecurrenceRule::FREQUENCIES );
		$this->assertContains( 'YEARLY', RecurrenceRule::FREQUENCIES );
	}

	/**
	 * Test DAYS constant.
	 *
	 * @return void
	 */
	public function test_days_constant(): void {
		$this->assertContains( 'MO', RecurrenceRule::DAYS );
		$this->assertContains( 'TU', RecurrenceRule::DAYS );
		$this->assertContains( 'WE', RecurrenceRule::DAYS );
		$this->assertContains( 'TH', RecurrenceRule::DAYS );
		$this->assertContains( 'FR', RecurrenceRule::DAYS );
		$this->assertContains( 'SA', RecurrenceRule::DAYS );
		$this->assertContains( 'SU', RecurrenceRule::DAYS );
	}

	/**
	 * Test PHP_DAY_MAP constant.
	 *
	 * @return void
	 */
	public function test_php_day_map_constant(): void {
		$this->assertSame( 'SU', RecurrenceRule::PHP_DAY_MAP[0] );
		$this->assertSame( 'MO', RecurrenceRule::PHP_DAY_MAP[1] );
		$this->assertSame( 'SA', RecurrenceRule::PHP_DAY_MAP[6] );
	}

	/**
	 * Test RRULE_DAY_MAP constant.
	 *
	 * @return void
	 */
	public function test_rrule_day_map_constant(): void {
		$this->assertSame( 0, RecurrenceRule::RRULE_DAY_MAP['SU'] );
		$this->assertSame( 1, RecurrenceRule::RRULE_DAY_MAP['MO'] );
		$this->assertSame( 6, RecurrenceRule::RRULE_DAY_MAP['SA'] );
	}
}
