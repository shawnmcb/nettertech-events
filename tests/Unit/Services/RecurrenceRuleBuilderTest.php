<?php
/**
 * RecurrenceRuleBuilder unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use NetterTechEvents\Services\RecurrenceRuleBuilder;

/**
 * Test RecurrenceRuleBuilder functionality.
 */
class RecurrenceRuleBuilderTest extends \NetterTechEventsTestCase {

	/**
	 * RecurrenceRuleBuilder instance.
	 *
	 * @var RecurrenceRuleBuilder
	 */
	private RecurrenceRuleBuilder $builder;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->builder = new RecurrenceRuleBuilder();

		// Mock WordPress functions.
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	// =========================================================================
	// Preset RRULE Tests
	// =========================================================================

	/**
	 * Test build_from_post returns preset directly.
	 *
	 * @return void
	 */
	public function test_build_from_post_returns_preset(): void {
		$post_data = array(
			'recurrence_preset'   => 'FREQ=WEEKLY;BYDAY=MO,WE,FR',
			'recurrence_end_type' => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO,WE,FR', $result );
	}

	/**
	 * Test preset with COUNT end condition.
	 *
	 * @return void
	 */
	public function test_build_from_post_preset_with_count(): void {
		$post_data = array(
			'recurrence_preset'   => 'FREQ=DAILY',
			'recurrence_end_type' => 'count',
			'recurrence_count'    => 10,
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=DAILY;COUNT=10', $result );
	}

	/**
	 * Test preset with UNTIL end condition.
	 *
	 * @return void
	 */
	public function test_build_from_post_preset_with_until(): void {
		$post_data = array(
			'recurrence_preset'   => 'FREQ=MONTHLY',
			'recurrence_end_type' => 'until',
			'recurrence_until'    => '2026-12-31',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=MONTHLY;UNTIL=20261231T235959Z', $result );
	}

	/**
	 * Test COUNT is bounded to valid range.
	 *
	 * @return void
	 */
	public function test_build_from_post_count_bounded(): void {
		$post_data = array(
			'recurrence_preset'   => 'FREQ=DAILY',
			'recurrence_end_type' => 'count',
			'recurrence_count'    => 999,
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=DAILY;COUNT=365', $result );
	}

	/**
	 * Test COUNT minimum is 1.
	 *
	 * @return void
	 */
	public function test_build_from_post_count_minimum_is_one(): void {
		$post_data = array(
			'recurrence_preset'   => 'FREQ=DAILY',
			'recurrence_end_type' => 'count',
			'recurrence_count'    => 0,
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=DAILY;COUNT=1', $result );
	}

	// =========================================================================
	// Custom Rule Tests
	// =========================================================================

	/**
	 * Test custom rule is returned directly.
	 *
	 * @return void
	 */
	public function test_build_from_post_custom_rule(): void {
		$post_data = array(
			'recurrence_preset' => 'custom',
			'recurrence_rule'   => 'FREQ=YEARLY;BYMONTH=1;BYMONTHDAY=1',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=YEARLY;BYMONTH=1;BYMONTHDAY=1', $result );
	}

	// =========================================================================
	// Field-based RRULE Tests
	// =========================================================================

	/**
	 * Test building from individual fields.
	 *
	 * @return void
	 */
	public function test_build_from_fields_weekly(): void {
		$post_data = array(
			'recurrence_preset'   => '',
			'recurrence_freq'     => 'WEEKLY',
			'recurrence_interval' => 1,
			'recurrence_end_type' => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=WEEKLY', $result );
	}

	/**
	 * Test building with interval.
	 *
	 * @return void
	 */
	public function test_build_from_fields_with_interval(): void {
		$post_data = array(
			'recurrence_preset'   => '',
			'recurrence_freq'     => 'DAILY',
			'recurrence_interval' => 2,
			'recurrence_end_type' => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=DAILY;INTERVAL=2', $result );
	}

	/**
	 * Test building with BYDAY for weekly frequency.
	 *
	 * @return void
	 */
	public function test_build_from_fields_with_byday(): void {
		$post_data = array(
			'recurrence_preset'   => '',
			'recurrence_freq'     => 'WEEKLY',
			'recurrence_interval' => 1,
			'recurrence_byday'    => array( 'MO', 'WE', 'FR' ),
			'recurrence_end_type' => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO,WE,FR', $result );
	}

	/**
	 * Test BYDAY is ignored for non-weekly frequency.
	 *
	 * @return void
	 */
	public function test_build_from_fields_byday_ignored_for_daily(): void {
		$post_data = array(
			'recurrence_preset'   => '',
			'recurrence_freq'     => 'DAILY',
			'recurrence_interval' => 1,
			'recurrence_byday'    => array( 'MO', 'WE', 'FR' ),
			'recurrence_end_type' => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=DAILY', $result );
		$this->assertStringNotContainsString( 'BYDAY', $result );
	}

	/**
	 * Test invalid days are filtered out.
	 *
	 * @return void
	 */
	public function test_build_from_fields_filters_invalid_days(): void {
		$post_data = array(
			'recurrence_preset'   => '',
			'recurrence_freq'     => 'WEEKLY',
			'recurrence_interval' => 1,
			'recurrence_byday'    => array( 'MO', 'INVALID', 'FR', 'XX' ),
			'recurrence_end_type' => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO,FR', $result );
	}

	/**
	 * Test invalid frequency defaults to WEEKLY.
	 *
	 * @return void
	 */
	public function test_build_from_fields_invalid_freq_defaults_to_weekly(): void {
		$post_data = array(
			'recurrence_preset'   => '',
			'recurrence_freq'     => 'INVALID',
			'recurrence_interval' => 1,
			'recurrence_end_type' => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=WEEKLY', $result );
	}

	/**
	 * Test building with count end condition.
	 *
	 * @return void
	 */
	public function test_build_from_fields_with_count(): void {
		$post_data = array(
			'recurrence_preset'   => '',
			'recurrence_freq'     => 'MONTHLY',
			'recurrence_interval' => 1,
			'recurrence_end_type' => 'count',
			'recurrence_count'    => 12,
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=MONTHLY;COUNT=12', $result );
	}

	/**
	 * Test building with until end condition.
	 *
	 * @return void
	 */
	public function test_build_from_fields_with_until(): void {
		$post_data = array(
			'recurrence_preset'   => '',
			'recurrence_freq'     => 'YEARLY',
			'recurrence_interval' => 1,
			'recurrence_end_type' => 'until',
			'recurrence_until'    => '2030-01-01',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=YEARLY;UNTIL=20300101T235959Z', $result );
	}

	// =========================================================================
	// Edge Cases
	// =========================================================================

	/**
	 * Test empty post data returns default WEEKLY.
	 *
	 * @return void
	 */
	public function test_build_from_post_empty_data_returns_default(): void {
		$result = $this->builder->build_from_post( array() );

		$this->assertSame( 'FREQ=WEEKLY', $result );
	}

	/**
	 * Test 'custom' preset without rule uses fields.
	 *
	 * @return void
	 */
	public function test_build_from_post_custom_without_rule_uses_fields(): void {
		$post_data = array(
			'recurrence_preset'   => 'custom',
			'recurrence_rule'     => '',
			'recurrence_freq'     => 'DAILY',
			'recurrence_interval' => 3,
			'recurrence_end_type' => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=DAILY;INTERVAL=3', $result );
	}

	/**
	 * Test interval of 1 is not included in output.
	 *
	 * @return void
	 */
	public function test_build_from_post_interval_one_not_included(): void {
		$post_data = array(
			'recurrence_preset'   => '',
			'recurrence_freq'     => 'WEEKLY',
			'recurrence_interval' => 1,
			'recurrence_end_type' => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertStringNotContainsString( 'INTERVAL', $result );
	}

	/**
	 * Test empty until date is ignored.
	 *
	 * @return void
	 */
	public function test_build_from_post_empty_until_ignored(): void {
		$post_data = array(
			'recurrence_preset'   => '',
			'recurrence_freq'     => 'WEEKLY',
			'recurrence_interval' => 1,
			'recurrence_end_type' => 'until',
			'recurrence_until'    => '',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertStringNotContainsString( 'UNTIL', $result );
	}

	// =========================================================================
	// MONTHLY nth-weekday (BYDAY with ordinal prefix)
	// =========================================================================

	/**
	 * Test "first and third Monday of each month" -> BYDAY=1MO,3MO.
	 *
	 * @return void
	 */
	public function test_build_from_fields_monthly_nth_weekday_first_and_third_monday(): void {
		$post_data = array(
			'recurrence_preset'           => '',
			'recurrence_freq'             => 'MONTHLY',
			'recurrence_interval'         => 1,
			'recurrence_monthly_type'     => 'nth_weekday',
			'recurrence_monthly_ordinals' => array( '1', '3' ),
			'recurrence_monthly_byday'    => array( 'MO' ),
			'recurrence_end_type'         => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=MONTHLY;BYDAY=1MO,3MO', $result );
	}

	/**
	 * Test "last Friday of each month" -> BYDAY=-1FR.
	 *
	 * @return void
	 */
	public function test_build_from_fields_monthly_nth_weekday_last_friday(): void {
		$post_data = array(
			'recurrence_preset'           => '',
			'recurrence_freq'             => 'MONTHLY',
			'recurrence_interval'         => 1,
			'recurrence_monthly_type'     => 'nth_weekday',
			'recurrence_monthly_ordinals' => array( '-1' ),
			'recurrence_monthly_byday'    => array( 'FR' ),
			'recurrence_end_type'         => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=MONTHLY;BYDAY=-1FR', $result );
	}

	/**
	 * Cartesian product: [2, 4] x [TU, TH] -> "2TU,2TH,4TU,4TH".
	 *
	 * @return void
	 */
	public function test_build_from_fields_monthly_nth_weekday_cartesian_product(): void {
		$post_data = array(
			'recurrence_preset'           => '',
			'recurrence_freq'             => 'MONTHLY',
			'recurrence_interval'         => 1,
			'recurrence_monthly_type'     => 'nth_weekday',
			'recurrence_monthly_ordinals' => array( '2', '4' ),
			'recurrence_monthly_byday'    => array( 'TU', 'TH' ),
			'recurrence_end_type'         => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=MONTHLY;BYDAY=2TU,2TH,4TU,4TH', $result );
	}

	/**
	 * MONTHLY with day_of_month type emits no BYDAY (default behavior).
	 *
	 * @return void
	 */
	public function test_build_from_fields_monthly_day_of_month_no_byday(): void {
		$post_data = array(
			'recurrence_preset'           => '',
			'recurrence_freq'             => 'MONTHLY',
			'recurrence_interval'         => 1,
			'recurrence_monthly_type'     => 'day_of_month',
			'recurrence_monthly_ordinals' => array( '1', '3' ), // Ignored.
			'recurrence_monthly_byday'    => array( 'MO' ),     // Ignored.
			'recurrence_end_type'         => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=MONTHLY', $result );
		$this->assertStringNotContainsString( 'BYDAY', $result );
	}

	/**
	 * MONTHLY nth_weekday with no ordinals or no days emits no BYDAY.
	 *
	 * @return void
	 */
	public function test_build_from_fields_monthly_nth_weekday_empty_selection_emits_no_byday(): void {
		$post_data = array(
			'recurrence_preset'           => '',
			'recurrence_freq'             => 'MONTHLY',
			'recurrence_interval'         => 1,
			'recurrence_monthly_type'     => 'nth_weekday',
			'recurrence_monthly_ordinals' => array(),
			'recurrence_monthly_byday'    => array( 'MO' ),
			'recurrence_end_type'         => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertStringNotContainsString( 'BYDAY', $result );
	}

	/**
	 * Invalid ordinal values (e.g., 5, 0, -2) are filtered out.
	 *
	 * @return void
	 */
	public function test_build_from_fields_monthly_nth_weekday_rejects_invalid_ordinals(): void {
		$post_data = array(
			'recurrence_preset'           => '',
			'recurrence_freq'             => 'MONTHLY',
			'recurrence_interval'         => 1,
			'recurrence_monthly_type'     => 'nth_weekday',
			'recurrence_monthly_ordinals' => array( '0', '5', '2', '-2' ), // Only 2 is valid.
			'recurrence_monthly_byday'    => array( 'MO' ),
			'recurrence_end_type'         => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=MONTHLY;BYDAY=2MO', $result );
	}

	/**
	 * Invalid day codes are filtered out (only canonical 2-letter codes accepted).
	 *
	 * @return void
	 */
	public function test_build_from_fields_monthly_nth_weekday_rejects_invalid_days(): void {
		$post_data = array(
			'recurrence_preset'           => '',
			'recurrence_freq'             => 'MONTHLY',
			'recurrence_interval'         => 1,
			'recurrence_monthly_type'     => 'nth_weekday',
			'recurrence_monthly_ordinals' => array( '1' ),
			'recurrence_monthly_byday'    => array( 'MON', 'TUESDAY', 'FR' ), // Only FR is valid.
			'recurrence_end_type'         => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=MONTHLY;BYDAY=1FR', $result );
	}

	/**
	 * WEEKLY frequency ignores monthly_ordinals + monthly_byday entirely.
	 *
	 * @return void
	 */
	public function test_build_from_fields_weekly_ignores_monthly_nth_weekday_fields(): void {
		$post_data = array(
			'recurrence_preset'           => '',
			'recurrence_freq'             => 'WEEKLY',
			'recurrence_interval'         => 1,
			'recurrence_byday'            => array( 'MO' ),
			'recurrence_monthly_type'     => 'nth_weekday',
			'recurrence_monthly_ordinals' => array( '1' ),
			'recurrence_monthly_byday'    => array( 'TU' ),
			'recurrence_end_type'         => 'never',
		);

		$result = $this->builder->build_from_post( $post_data );

		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO', $result );
	}
}
