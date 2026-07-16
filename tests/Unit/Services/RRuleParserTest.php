<?php
/**
 * RRuleParser unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Exceptions\RRuleException;
use NetterTechEvents\Models\RecurrenceRule;
use NetterTechEvents\Services\RRuleParser;

/**
 * Test RRuleParser functionality.
 */
class RRuleParserTest extends \NetterTechEventsTestCase {

	/**
	 * RRuleParser instance.
	 *
	 * @var RRuleParser
	 */
	private RRuleParser $parser;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parser = new RRuleParser();
	}

	// =========================================================================
	// FREQ Tests
	// =========================================================================

	/**
	 * Test parsing daily frequency.
	 *
	 * @return void
	 */
	public function test_parse_daily_frequency(): void {
		$rule = $this->parser->parse( 'FREQ=DAILY' );

		$this->assertSame( RecurrenceRule::FREQ_DAILY, $rule->freq );
		$this->assertSame( 1, $rule->interval );
		$this->assertNull( $rule->count );
		$this->assertNull( $rule->until );
	}

	/**
	 * Test parsing weekly frequency.
	 *
	 * @return void
	 */
	public function test_parse_weekly_frequency(): void {
		$rule = $this->parser->parse( 'FREQ=WEEKLY' );

		$this->assertSame( RecurrenceRule::FREQ_WEEKLY, $rule->freq );
	}

	/**
	 * Test parsing monthly frequency.
	 *
	 * @return void
	 */
	public function test_parse_monthly_frequency(): void {
		$rule = $this->parser->parse( 'FREQ=MONTHLY' );

		$this->assertSame( RecurrenceRule::FREQ_MONTHLY, $rule->freq );
	}

	/**
	 * Test parsing yearly frequency.
	 *
	 * @return void
	 */
	public function test_parse_yearly_frequency(): void {
		$rule = $this->parser->parse( 'FREQ=YEARLY' );

		$this->assertSame( RecurrenceRule::FREQ_YEARLY, $rule->freq );
	}

	// =========================================================================
	// BYDAY Tests
	// =========================================================================

	/**
	 * Test parsing weekly with BYDAY.
	 *
	 * @return void
	 */
	public function test_parse_weekly_with_byday(): void {
		$rule = $this->parser->parse( 'FREQ=WEEKLY;BYDAY=MO,WE,FR' );

		$this->assertSame( RecurrenceRule::FREQ_WEEKLY, $rule->freq );
		$this->assertCount( 3, $rule->by_day );
		$this->assertContains( 'MO', $rule->by_day );
		$this->assertContains( 'WE', $rule->by_day );
		$this->assertContains( 'FR', $rule->by_day );
	}

	/**
	 * Test parsing BYDAY with positional prefix.
	 *
	 * @return void
	 */
	public function test_parse_byday_with_position(): void {
		$rule = $this->parser->parse( 'FREQ=MONTHLY;BYDAY=1MO' );

		$this->assertSame( RecurrenceRule::FREQ_MONTHLY, $rule->freq );
		$this->assertContains( 'MO', $rule->by_day );
		$this->assertContains( 1, $rule->by_set_pos );
	}

	/**
	 * Test parsing BYDAY with negative position (last Friday).
	 *
	 * @return void
	 */
	public function test_parse_byday_with_negative_position(): void {
		$rule = $this->parser->parse( 'FREQ=MONTHLY;BYDAY=-1FR' );

		$this->assertContains( 'FR', $rule->by_day );
		$this->assertContains( -1, $rule->by_set_pos );
	}

	/**
	 * A positioned BYDAY must not have its position clobbered by a stray
	 * explicit BYSETPOS (the position the app encodes in BYDAY wins).
	 *
	 * @return void
	 */
	public function test_positioned_byday_not_clobbered_by_explicit_bysetpos(): void {
		$rule = $this->parser->parse( 'FREQ=MONTHLY;BYDAY=1MO;BYSETPOS=3' );

		$this->assertContains( 'MO', $rule->by_day );
		$this->assertSame( array( 1 ), array_values( $rule->by_set_pos ) );
	}

	/**
	 * An explicit BYSETPOS still applies when BYDAY carries no position.
	 *
	 * @return void
	 */
	public function test_explicit_bysetpos_applies_when_byday_unpositioned(): void {
		$rule = $this->parser->parse( 'FREQ=MONTHLY;BYDAY=MO;BYSETPOS=2' );

		$this->assertContains( 'MO', $rule->by_day );
		$this->assertSame( array( 2 ), array_values( $rule->by_set_pos ) );
	}

	// =========================================================================
	// BYMONTHDAY Tests
	// =========================================================================

	/**
	 * Test parsing monthly with BYMONTHDAY.
	 *
	 * @return void
	 */
	public function test_parse_monthly_with_bymonthday(): void {
		$rule = $this->parser->parse( 'FREQ=MONTHLY;BYMONTHDAY=15' );

		$this->assertSame( RecurrenceRule::FREQ_MONTHLY, $rule->freq );
		$this->assertContains( 15, $rule->by_month_day );
	}

	/**
	 * Test parsing BYMONTHDAY with multiple days.
	 *
	 * @return void
	 */
	public function test_parse_bymonthday_multiple(): void {
		$rule = $this->parser->parse( 'FREQ=MONTHLY;BYMONTHDAY=1,15' );

		$this->assertCount( 2, $rule->by_month_day );
		$this->assertContains( 1, $rule->by_month_day );
		$this->assertContains( 15, $rule->by_month_day );
	}

	// =========================================================================
	// UNTIL Tests
	// =========================================================================

	/**
	 * Test parsing UNTIL with date only format.
	 *
	 * @return void
	 */
	public function test_parse_until_date_only(): void {
		$rule = $this->parser->parse( 'FREQ=DAILY;UNTIL=20261231' );

		$this->assertNotNull( $rule->until );
		$this->assertSame( '2026', $rule->until->format( 'Y' ) );
		$this->assertSame( '12', $rule->until->format( 'm' ) );
		$this->assertSame( '31', $rule->until->format( 'd' ) );
	}

	/**
	 * Test parsing UNTIL with datetime format.
	 *
	 * @return void
	 */
	public function test_parse_until_datetime(): void {
		$rule = $this->parser->parse( 'FREQ=DAILY;UNTIL=20261231T235959' );

		$this->assertNotNull( $rule->until );
		$this->assertSame( '23', $rule->until->format( 'H' ) );
		$this->assertSame( '59', $rule->until->format( 'i' ) );
	}

	/**
	 * Test parsing UNTIL with UTC datetime format.
	 *
	 * @return void
	 */
	public function test_parse_until_datetime_utc(): void {
		$rule = $this->parser->parse( 'FREQ=DAILY;UNTIL=20261231T235959Z' );

		$this->assertNotNull( $rule->until );
		$this->assertSame( 'UTC', $rule->until->getTimezone()->getName() );
	}

	// =========================================================================
	// COUNT Tests
	// =========================================================================

	/**
	 * Test parsing COUNT limit.
	 *
	 * @return void
	 */
	public function test_parse_count(): void {
		$rule = $this->parser->parse( 'FREQ=DAILY;COUNT=10' );

		$this->assertSame( 10, $rule->count );
		$this->assertNull( $rule->until );
	}

	/**
	 * Test COUNT is at least 1.
	 *
	 * @return void
	 */
	public function test_parse_count_minimum(): void {
		$rule = $this->parser->parse( 'FREQ=DAILY;COUNT=0' );

		$this->assertSame( 1, $rule->count );
	}

	// =========================================================================
	// INTERVAL Tests
	// =========================================================================

	/**
	 * Test parsing INTERVAL.
	 *
	 * @return void
	 */
	public function test_parse_interval(): void {
		$rule = $this->parser->parse( 'FREQ=DAILY;INTERVAL=3' );

		$this->assertSame( 3, $rule->interval );
	}

	/**
	 * Test INTERVAL defaults to 1.
	 *
	 * @return void
	 */
	public function test_parse_interval_default(): void {
		$rule = $this->parser->parse( 'FREQ=DAILY' );

		$this->assertSame( 1, $rule->interval );
	}

	/**
	 * Test INTERVAL is at least 1.
	 *
	 * @return void
	 */
	public function test_parse_interval_minimum(): void {
		$rule = $this->parser->parse( 'FREQ=DAILY;INTERVAL=0' );

		$this->assertSame( 1, $rule->interval );
	}

	// =========================================================================
	// BYMONTH Tests
	// =========================================================================

	/**
	 * Test parsing BYMONTH.
	 *
	 * @return void
	 */
	public function test_parse_bymonth(): void {
		$rule = $this->parser->parse( 'FREQ=YEARLY;BYMONTH=1,6,12' );

		$this->assertCount( 3, $rule->by_month );
		$this->assertContains( 1, $rule->by_month );
		$this->assertContains( 6, $rule->by_month );
		$this->assertContains( 12, $rule->by_month );
	}

	// =========================================================================
	// WKST Tests
	// =========================================================================

	/**
	 * Test parsing WKST.
	 *
	 * @return void
	 */
	public function test_parse_wkst(): void {
		$rule = $this->parser->parse( 'FREQ=WEEKLY;WKST=SU' );

		$this->assertSame( 'SU', $rule->wkst );
	}

	/**
	 * Test WKST defaults to MO.
	 *
	 * @return void
	 */
	public function test_parse_wkst_default(): void {
		$rule = $this->parser->parse( 'FREQ=WEEKLY' );

		$this->assertSame( 'MO', $rule->wkst );
	}

	// =========================================================================
	// Invalid Input Tests
	// =========================================================================

	/**
	 * Test parsing empty string throws exception.
	 *
	 * @return void
	 */
	public function test_parse_empty_throws_exception(): void {
		$this->expectException( RRuleException::class );
		$this->expectExceptionMessage( 'RRULE string cannot be empty' );

		$this->parser->parse( '' );
	}

	/**
	 * Test parsing without FREQ throws exception.
	 *
	 * @return void
	 */
	public function test_parse_missing_freq_throws_exception(): void {
		$this->expectException( RRuleException::class );
		$this->expectExceptionMessage( 'RRULE must have a FREQ component' );

		$this->parser->parse( 'INTERVAL=2' );
	}

	/**
	 * Test parsing invalid date throws exception.
	 *
	 * @return void
	 */
	public function test_parse_invalid_until_throws_exception(): void {
		$this->expectException( RRuleException::class );
		$this->expectExceptionMessage( 'Invalid UNTIL value' );

		$this->parser->parse( 'FREQ=DAILY;UNTIL=invalid' );
	}

	// =========================================================================
	// try_parse Tests
	// =========================================================================

	/**
	 * Test try_parse returns null on invalid input.
	 *
	 * @return void
	 */
	public function test_try_parse_returns_null_on_invalid(): void {
		$result = $this->parser->try_parse( '' );

		$this->assertNull( $result );
	}

	/**
	 * Test try_parse returns rule on valid input.
	 *
	 * @return void
	 */
	public function test_try_parse_returns_rule_on_valid(): void {
		$result = $this->parser->try_parse( 'FREQ=DAILY' );

		$this->assertInstanceOf( RecurrenceRule::class, $result );
	}

	// =========================================================================
	// validate Tests
	// =========================================================================

	/**
	 * Test validate returns empty array for valid rule.
	 *
	 * @return void
	 */
	public function test_validate_valid_rule(): void {
		$errors = $this->parser->validate( 'FREQ=DAILY;COUNT=10' );

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate returns error for COUNT and UNTIL together.
	 *
	 * @return void
	 */
	public function test_validate_count_and_until_error(): void {
		$errors = $this->parser->validate( 'FREQ=DAILY;COUNT=10;UNTIL=20261231' );

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'COUNT and UNTIL cannot both be specified', $errors[0] );
	}

	/**
	 * Test validate returns error for COUNT over 365.
	 *
	 * @return void
	 */
	public function test_validate_count_exceeds_limit(): void {
		$errors = $this->parser->validate( 'FREQ=DAILY;COUNT=500' );

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'COUNT cannot exceed 365', $errors[0] );
	}

	// =========================================================================
	// build Tests
	// =========================================================================

	/**
	 * Test build creates valid RRULE string.
	 *
	 * @return void
	 */
	public function test_build_basic_rule(): void {
		$rrule = $this->parser->build( array( 'freq' => 'daily' ) );

		$this->assertSame( 'FREQ=DAILY', $rrule );
	}

	/**
	 * Test build with all components.
	 *
	 * @return void
	 */
	public function test_build_complex_rule(): void {
		$rrule = $this->parser->build(
			array(
				'freq'     => 'weekly',
				'interval' => 2,
				'count'    => 10,
				'byday'    => array( 'MO', 'WE', 'FR' ),
			)
		);

		$this->assertStringContainsString( 'FREQ=WEEKLY', $rrule );
		$this->assertStringContainsString( 'INTERVAL=2', $rrule );
		$this->assertStringContainsString( 'COUNT=10', $rrule );
		$this->assertStringContainsString( 'BYDAY=MO,WE,FR', $rrule );
	}

	/**
	 * Test build throws exception without FREQ.
	 *
	 * @return void
	 */
	public function test_build_missing_freq_throws_exception(): void {
		$this->expectException( RRuleException::class );

		$this->parser->build( array( 'interval' => 2 ) );
	}

	/**
	 * Test build throws exception with invalid FREQ.
	 *
	 * @return void
	 */
	public function test_build_invalid_freq_throws_exception(): void {
		$this->expectException( RRuleException::class );

		$this->parser->build( array( 'freq' => 'invalid' ) );
	}

	// =========================================================================
	// RRULE: Prefix Tests
	// =========================================================================

	/**
	 * Test parsing with RRULE: prefix.
	 *
	 * @return void
	 */
	public function test_parse_with_prefix(): void {
		$rule = $this->parser->parse( 'RRULE:FREQ=DAILY' );

		$this->assertSame( RecurrenceRule::FREQ_DAILY, $rule->freq );
	}

	/**
	 * Test original rule is stored.
	 *
	 * @return void
	 */
	public function test_original_rule_stored(): void {
		$rule = $this->parser->parse( 'FREQ=WEEKLY;BYDAY=MO,WE,FR' );

		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO,WE,FR', $rule->original_rule );
	}
}
