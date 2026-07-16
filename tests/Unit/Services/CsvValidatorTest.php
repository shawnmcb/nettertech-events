<?php
/**
 * CsvValidator unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Services\CsvValidator;

/**
 * Test CsvValidator functionality.
 *
 * Covers required field validation, date/time parsing, status validation,
 * RRULE validation, boolean values, and URL validation.
 */
class CsvValidatorTest extends \NetterTechEventsTestCase {

	/**
	 * CsvValidator instance.
	 *
	 * @var CsvValidator
	 */
	private CsvValidator $validator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new CsvValidator();
	}

	// =========================================================================
	// Valid Row Tests
	// =========================================================================

	/**
	 * Test a fully valid row passes validation.
	 *
	 * @return void
	 */
	public function test_valid_row_passes(): void {
		$rows = array(
			array(
				'title'      => 'Test Event',
				'start_date' => '2026-01-15',
				'start_time' => '10:00',
				'end_date'   => '2026-01-15',
				'end_time'   => '14:00',
				'status'     => 'draft',
				'all_day'    => 'no',
				'image_url'  => 'https://example.com/image.jpg',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertCount( 1, $result['valid'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Test minimal valid row with only required fields.
	 *
	 * @return void
	 */
	public function test_minimal_valid_row_passes(): void {
		$rows = array(
			array(
				'title'      => 'Minimal Event',
				'start_date' => '2026-03-01',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertCount( 1, $result['valid'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Test multiple valid rows all pass.
	 *
	 * @return void
	 */
	public function test_multiple_valid_rows_pass(): void {
		$rows = array(
			array(
				'title'      => 'Event A',
				'start_date' => '2026-01-01',
			),
			array(
				'title'      => 'Event B',
				'start_date' => '2026-02-01',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertCount( 2, $result['valid'] );
		$this->assertEmpty( $result['errors'] );
	}

	// =========================================================================
	// Missing Required Fields
	// =========================================================================

	/**
	 * Test missing title produces error.
	 *
	 * @return void
	 */
	public function test_missing_title_produces_error(): void {
		$rows = array(
			array(
				'title'      => '',
				'start_date' => '2026-01-15',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertEmpty( $result['valid'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'Title is required', $result['errors'][2][0] );
	}

	/**
	 * Test missing start_date produces error.
	 *
	 * @return void
	 */
	public function test_missing_start_date_produces_error(): void {
		$rows = array(
			array(
				'title'      => 'No Date Event',
				'start_date' => '',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertEmpty( $result['valid'] );
		$this->assertStringContainsString( 'Start date is required', $result['errors'][2][0] );
	}

	/**
	 * Test missing both required fields produces multiple errors.
	 *
	 * @return void
	 */
	public function test_missing_both_required_fields(): void {
		$rows = array(
			array(
				'title'      => '',
				'start_date' => '',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertEmpty( $result['valid'] );
		$this->assertCount( 2, $result['errors'][2] );
	}

	// =========================================================================
	// Date Format Validation
	// =========================================================================

	/**
	 * Test various valid date formats.
	 *
	 * @return void
	 */
	public function test_valid_date_formats(): void {
		$valid_dates = array(
			'2026-01-15',
			'01/15/2026',
			'2026/01/15',
		);

		foreach ( $valid_dates as $date ) {
			$rows   = array(
				array(
					'title'      => 'Date Test',
					'start_date' => $date,
				),
			);
			$result = $this->validator->validate( $rows );

			$this->assertCount(
				1,
				$result['valid'],
				sprintf( 'Date "%s" should be valid', $date )
			);
		}
	}

	/**
	 * Test invalid date format produces error.
	 *
	 * @return void
	 */
	public function test_invalid_date_format_produces_error(): void {
		$rows = array(
			array(
				'title'      => 'Bad Date',
				'start_date' => 'not-a-date',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertEmpty( $result['valid'] );
		$errors = $result['errors'][2];
		$found  = false;
		foreach ( $errors as $error ) {
			if ( str_contains( $error, 'Unrecognized start date format' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Should contain unrecognized date format error' );
	}

	/**
	 * Test invalid end date produces error.
	 *
	 * @return void
	 */
	public function test_invalid_end_date_produces_error(): void {
		$rows = array(
			array(
				'title'      => 'Bad End Date',
				'start_date' => '2026-01-15',
				'end_date'   => 'invalid',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertEmpty( $result['valid'] );
		$errors = $result['errors'][2];
		$found  = false;
		foreach ( $errors as $error ) {
			if ( str_contains( $error, 'Unrecognized end date format' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Should contain unrecognized end date format error' );
	}

	// =========================================================================
	// Time Format Validation
	// =========================================================================

	/**
	 * Test valid time formats.
	 *
	 * @return void
	 */
	public function test_valid_time_formats(): void {
		$valid_times = array(
			'10:00',
			'14:30',
			'09:00:00',
			'2:30 PM',
			'2:30PM',
		);

		foreach ( $valid_times as $time ) {
			$rows   = array(
				array(
					'title'      => 'Time Test',
					'start_date' => '2026-01-15',
					'start_time' => $time,
				),
			);
			$result = $this->validator->validate( $rows );

			$this->assertCount(
				1,
				$result['valid'],
				sprintf( 'Time "%s" should be valid', $time )
			);
		}
	}

	/**
	 * Test invalid time format produces error.
	 *
	 * @return void
	 */
	public function test_invalid_time_format_produces_error(): void {
		$rows = array(
			array(
				'title'      => 'Bad Time',
				'start_date' => '2026-01-15',
				'start_time' => 'noon-ish',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertEmpty( $result['valid'] );
		$errors = $result['errors'][2];
		$found  = false;
		foreach ( $errors as $error ) {
			if ( str_contains( $error, 'Unrecognized start time format' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Should contain unrecognized start time format error' );
	}

	// =========================================================================
	// End Before Start Validation
	// =========================================================================

	/**
	 * Test end date before start date produces error.
	 *
	 * @return void
	 */
	public function test_end_before_start_produces_error(): void {
		$rows = array(
			array(
				'title'      => 'Time Travel Event',
				'start_date' => '2026-02-15',
				'end_date'   => '2026-01-15',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertEmpty( $result['valid'] );
		$errors = $result['errors'][2];
		$found  = false;
		foreach ( $errors as $error ) {
			if ( str_contains( $error, 'End date/time must be after start' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Should detect end before start' );
	}

	// =========================================================================
	// Status Validation
	// =========================================================================

	/**
	 * Test valid statuses pass.
	 *
	 * @return void
	 */
	public function test_valid_statuses_pass(): void {
		$valid_statuses = array( 'draft', 'published', 'cancelled', 'postponed' );

		foreach ( $valid_statuses as $status ) {
			$rows   = array(
				array(
					'title'      => 'Status Test',
					'start_date' => '2026-01-15',
					'status'     => $status,
				),
			);
			$result = $this->validator->validate( $rows );

			$this->assertCount(
				1,
				$result['valid'],
				sprintf( 'Status "%s" should be valid', $status )
			);
		}
	}

	/**
	 * Test invalid status produces error.
	 *
	 * @return void
	 */
	public function test_invalid_status_produces_error(): void {
		$rows = array(
			array(
				'title'      => 'Bad Status',
				'start_date' => '2026-01-15',
				'status'     => 'invalid_status',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertEmpty( $result['valid'] );
		$errors = $result['errors'][2];
		$found  = false;
		foreach ( $errors as $error ) {
			if ( str_contains( $error, 'Invalid status' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Should detect invalid status' );
	}

	/**
	 * Test empty status is valid (optional field).
	 *
	 * @return void
	 */
	public function test_empty_status_is_valid(): void {
		$rows = array(
			array(
				'title'      => 'No Status',
				'start_date' => '2026-01-15',
				'status'     => '',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertCount( 1, $result['valid'] );
	}

	// =========================================================================
	// All Day Validation
	// =========================================================================

	/**
	 * Test valid all_day values pass.
	 *
	 * @return void
	 */
	public function test_valid_all_day_values_pass(): void {
		$valid_values = array( 'yes', 'no', 'true', 'false', '1', '0', 'y', 'n' );

		foreach ( $valid_values as $value ) {
			$rows   = array(
				array(
					'title'      => 'All Day Test',
					'start_date' => '2026-01-15',
					'all_day'    => $value,
				),
			);
			$result = $this->validator->validate( $rows );

			$this->assertCount(
				1,
				$result['valid'],
				sprintf( 'All day value "%s" should be valid', $value )
			);
		}
	}

	/**
	 * Test invalid all_day value produces error.
	 *
	 * @return void
	 */
	public function test_invalid_all_day_produces_error(): void {
		$rows = array(
			array(
				'title'      => 'Bad All Day',
				'start_date' => '2026-01-15',
				'all_day'    => 'maybe',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertEmpty( $result['valid'] );
		$errors = $result['errors'][2];
		$found  = false;
		foreach ( $errors as $error ) {
			if ( str_contains( $error, 'Invalid all_day value' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Should detect invalid all_day value' );
	}

	// =========================================================================
	// RRULE Validation
	// =========================================================================

	/**
	 * Test valid RRULE passes.
	 *
	 * @return void
	 */
	public function test_valid_rrule_passes(): void {
		$valid_rrules = array(
			'FREQ=DAILY;COUNT=5',
			'FREQ=WEEKLY;BYDAY=MO,WE,FR',
			'FREQ=MONTHLY;BYMONTHDAY=15',
			'FREQ=YEARLY;BYMONTH=1;BYMONTHDAY=1',
			'RRULE:FREQ=DAILY;COUNT=10',
		);

		foreach ( $valid_rrules as $rrule ) {
			$rows   = array(
				array(
					'title'           => 'RRULE Test',
					'start_date'      => '2026-01-15',
					'recurrence_rule' => $rrule,
				),
			);
			$result = $this->validator->validate( $rows );

			$this->assertCount(
				1,
				$result['valid'],
				sprintf( 'RRULE "%s" should be valid', $rrule )
			);
		}
	}

	/**
	 * Test invalid RRULE produces error.
	 *
	 * @return void
	 */
	public function test_invalid_rrule_produces_error(): void {
		$rows = array(
			array(
				'title'           => 'Bad RRULE',
				'start_date'      => '2026-01-15',
				'recurrence_rule' => 'not-a-valid-rrule',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertEmpty( $result['valid'] );
		$errors = $result['errors'][2];
		$found  = false;
		foreach ( $errors as $error ) {
			if ( str_contains( $error, 'Invalid recurrence rule' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Should detect invalid RRULE' );
	}

	// =========================================================================
	// Image URL Validation
	// =========================================================================

	/**
	 * Test valid image URL passes.
	 *
	 * @return void
	 */
	public function test_valid_image_url_passes(): void {
		$rows = array(
			array(
				'title'      => 'Image Test',
				'start_date' => '2026-01-15',
				'image_url'  => 'https://example.com/photo.jpg',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertCount( 1, $result['valid'] );
	}

	/**
	 * Test invalid image URL produces error.
	 *
	 * @return void
	 */
	public function test_invalid_image_url_produces_error(): void {
		$rows = array(
			array(
				'title'      => 'Bad Image',
				'start_date' => '2026-01-15',
				'image_url'  => 'not a url at all',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertEmpty( $result['valid'] );
		$errors = $result['errors'][2];
		$found  = false;
		foreach ( $errors as $error ) {
			if ( str_contains( $error, 'Invalid image URL' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Should detect invalid image URL' );
	}

	// =========================================================================
	// Row Offset
	// =========================================================================

	/**
	 * Test error row numbers use correct offset.
	 *
	 * @return void
	 */
	public function test_row_offset_in_errors(): void {
		$rows = array(
			array(
				'title'      => 'Good Event',
				'start_date' => '2026-01-01',
			),
			array(
				'title'      => '',
				'start_date' => '2026-01-02',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertArrayHasKey( 2, $result['valid'] );
		$this->assertArrayHasKey( 3, $result['errors'] );
	}

	/**
	 * Test custom offset for error reporting.
	 *
	 * @return void
	 */
	public function test_custom_offset(): void {
		$rows = array(
			array(
				'title'      => '',
				'start_date' => '2026-01-01',
			),
		);

		$result = $this->validator->validate( $rows, 5 );

		$this->assertArrayHasKey( 5, $result['errors'] );
	}

	// =========================================================================
	// Mixed Valid and Invalid Rows
	// =========================================================================

	/**
	 * Test mix of valid and invalid rows.
	 *
	 * @return void
	 */
	public function test_mixed_valid_and_invalid_rows(): void {
		$rows = array(
			array(
				'title'      => 'Good Event',
				'start_date' => '2026-01-15',
			),
			array(
				'title'      => '',
				'start_date' => '2026-01-15',
			),
			array(
				'title'      => 'Another Good',
				'start_date' => '2026-02-01',
			),
			array(
				'title'      => 'Bad Status',
				'start_date' => '2026-03-01',
				'status'     => 'invalid',
			),
		);

		$result = $this->validator->validate( $rows );

		$this->assertCount( 2, $result['valid'] );
		$this->assertCount( 2, $result['errors'] );
	}

	// =========================================================================
	// parse_date() Public Method
	// =========================================================================

	/**
	 * Test parse_date with ISO format.
	 *
	 * @return void
	 */
	public function test_parse_date_iso_format(): void {
		$result = $this->validator->parse_date( '2026-01-15' );

		$this->assertInstanceOf( \DateTimeImmutable::class, $result );
		$this->assertEquals( '2026-01-15', $result->format( 'Y-m-d' ) );
	}

	/**
	 * Test parse_date returns null for garbage.
	 *
	 * @return void
	 */
	public function test_parse_date_returns_null_for_garbage(): void {
		$result = $this->validator->parse_date( 'xyzzy' );

		$this->assertNull( $result );
	}

	// =========================================================================
	// parse_time() Public Method
	// =========================================================================

	/**
	 * Test parse_time with 24h format.
	 *
	 * @return void
	 */
	public function test_parse_time_24h_format(): void {
		$result = $this->validator->parse_time( '14:30' );

		$this->assertInstanceOf( \DateTimeImmutable::class, $result );
		$this->assertEquals( '14', $result->format( 'H' ) );
		$this->assertEquals( '30', $result->format( 'i' ) );
	}

	/**
	 * Test parse_time returns null for empty string.
	 *
	 * @return void
	 */
	public function test_parse_time_returns_null_for_empty(): void {
		$result = $this->validator->parse_time( '' );

		$this->assertNull( $result );
	}

	// =========================================================================
	// build_datetime() Public Method
	// =========================================================================

	/**
	 * Test build_datetime with time.
	 *
	 * @return void
	 */
	public function test_build_datetime_with_time(): void {
		$date = new \DateTimeImmutable( '2026-01-15' );
		$time = \DateTimeImmutable::createFromFormat( 'H:i', '14:30' );

		$result = $this->validator->build_datetime( $date, $time ?: null );

		$this->assertEquals( '14', $result->format( 'H' ) );
		$this->assertEquals( '30', $result->format( 'i' ) );
		$this->assertEquals( '2026-01-15', $result->format( 'Y-m-d' ) );
	}

	/**
	 * Test build_datetime without time defaults to midnight.
	 *
	 * @return void
	 */
	public function test_build_datetime_without_time_is_midnight(): void {
		$date = new \DateTimeImmutable( '2026-01-15' );

		$result = $this->validator->build_datetime( $date, null );

		$this->assertEquals( '00', $result->format( 'H' ) );
		$this->assertEquals( '00', $result->format( 'i' ) );
		$this->assertEquals( '00', $result->format( 's' ) );
	}

	// =========================================================================
	// to_bool() Public Method
	// =========================================================================

	/**
	 * Test to_bool with truthy values.
	 *
	 * @return void
	 */
	public function test_to_bool_truthy(): void {
		$this->assertTrue( $this->validator->to_bool( 'yes' ) );
		$this->assertTrue( $this->validator->to_bool( 'true' ) );
		$this->assertTrue( $this->validator->to_bool( '1' ) );
		$this->assertTrue( $this->validator->to_bool( 'y' ) );
		$this->assertTrue( $this->validator->to_bool( 'YES' ) );
		$this->assertTrue( $this->validator->to_bool( 'True' ) );
	}

	/**
	 * Test to_bool with falsy values.
	 *
	 * @return void
	 */
	public function test_to_bool_falsy(): void {
		$this->assertFalse( $this->validator->to_bool( 'no' ) );
		$this->assertFalse( $this->validator->to_bool( 'false' ) );
		$this->assertFalse( $this->validator->to_bool( '0' ) );
		$this->assertFalse( $this->validator->to_bool( 'n' ) );
		$this->assertFalse( $this->validator->to_bool( '' ) );
		$this->assertFalse( $this->validator->to_bool( 'random' ) );
	}
}
