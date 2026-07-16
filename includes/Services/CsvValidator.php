<?php
/**
 * CSV Validator.
 *
 * Validates mapped CSV rows before import, returning per-row errors.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Validates mapped CSV data rows for import readiness.
 *
 * @since 2.1.0
 */
class CsvValidator {

	/**
	 * Date formats to attempt parsing, in order of preference.
	 *
	 * @var array<string>
	 */
	private const DATE_FORMATS = array(
		'Y-m-d',
		'm/d/Y',
		'd/m/Y',
		'Y/m/d',
		'M d, Y',
		'F d, Y',
		'd-m-Y',
		'm-d-Y',
		'Ymd',
	);

	/**
	 * Time formats to attempt parsing.
	 *
	 * @var array<string>
	 */
	private const TIME_FORMATS = array(
		'H:i:s',
		'H:i',
		'g:i A',
		'g:iA',
		'g:i a',
		'g:ia',
		'G:i',
	);

	/**
	 * Valid event statuses.
	 *
	 * @var array<string>
	 */
	private const VALID_STATUSES = array( 'draft', 'published', 'cancelled', 'postponed' );

	/**
	 * Validate all rows and return per-row errors.
	 *
	 * @param array<array<string, string>> $rows    Mapped rows (NTE field => value).
	 * @param int                          $offset  Row number offset for error reporting (accounts for header row).
	 * @return array{valid: array<int, array<string, string>>, errors: array<int, array<string>>}
	 */
	public function validate( array $rows, int $offset = 2 ): array {
		$valid  = array();
		$errors = array();

		foreach ( $rows as $index => $row ) {
			$row_number = $index + $offset;
			$row_errors = $this->validate_row( $row );

			if ( count( $row_errors ) > 0 ) {
				$errors[ $row_number ] = $row_errors;
			} else {
				$valid[ $row_number ] = $row;
			}
		}

		return array(
			'valid'  => $valid,
			'errors' => $errors,
		);
	}

	/**
	 * Validate a single mapped row.
	 *
	 * @param array<string, string> $row Mapped row data.
	 * @return array<string> List of error messages for this row.
	 */
	private function validate_row( array $row ): array {
		$errors = array();

		// Required: title.
		if ( empty( $row['title'] ?? '' ) ) {
			$errors[] = 'Title is required.';
		}

		// Required: start_date.
		$start_date_raw = $row['start_date'] ?? '';
		if ( '' === $start_date_raw ) {
			$errors[] = 'Start date is required.';
		} else {
			$start_date = $this->parse_date( $start_date_raw );
			if ( null === $start_date ) {
				$errors[] = sprintf( 'Unrecognized start date format: "%s".', $start_date_raw );
			}
		}

		// Optional: start_time.
		$start_time_raw = $row['start_time'] ?? '';
		if ( '' !== $start_time_raw && null === $this->parse_time( $start_time_raw ) ) {
			$errors[] = sprintf( 'Unrecognized start time format: "%s".', $start_time_raw );
		}

		// Optional: end_date.
		$end_date_raw = $row['end_date'] ?? '';
		if ( '' !== $end_date_raw && null === $this->parse_date( $end_date_raw ) ) {
			$errors[] = sprintf( 'Unrecognized end date format: "%s".', $end_date_raw );
		}

		// Optional: end_time.
		$end_time_raw = $row['end_time'] ?? '';
		if ( '' !== $end_time_raw && null === $this->parse_time( $end_time_raw ) ) {
			$errors[] = sprintf( 'Unrecognized end time format: "%s".', $end_time_raw );
		}

		// Logical: end must be after start.
		if ( isset( $start_date ) && '' !== $end_date_raw ) {
			$end_date = $this->parse_date( $end_date_raw );
			if ( null !== $end_date ) {
				$start_dt = $this->build_datetime( $start_date, $this->parse_time( $start_time_raw ) );
				$end_dt   = $this->build_datetime( $end_date, $this->parse_time( $end_time_raw ) );
				if ( $end_dt <= $start_dt ) {
					$errors[] = 'End date/time must be after start date/time.';
				}
			}
		}

		// Optional: recurrence_rule (RRULE validation).
		$rrule = $row['recurrence_rule'] ?? '';
		if ( '' !== $rrule && ! $this->validate_rrule( $rrule ) ) {
			$errors[] = sprintf( 'Invalid recurrence rule: "%s". Expected RFC 5545 RRULE format.', $rrule );
		}

		// Optional: status.
		$status = $row['status'] ?? '';
		if ( '' !== $status && ! in_array( strtolower( $status ), self::VALID_STATUSES, true ) ) {
			$errors[] = sprintf(
				'Invalid status: "%s". Expected one of: %s.',
				$status,
				implode( ', ', self::VALID_STATUSES )
			);
		}

		// Optional: all_day.
		$all_day = $row['all_day'] ?? '';
		if ( '' !== $all_day && ! $this->is_boolean_value( $all_day ) ) {
			$errors[] = sprintf( 'Invalid all_day value: "%s". Expected yes/no, true/false, 1/0.', $all_day );
		}

		// Optional: image_url.
		$image_url = $row['image_url'] ?? '';
		if ( '' !== $image_url && ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
			$errors[] = sprintf( 'Invalid image URL: "%s".', $image_url );
		}

		return $errors;
	}

	/**
	 * Attempt to parse a date string in multiple formats.
	 *
	 * @param string $value Raw date string.
	 * @return \DateTimeImmutable|null Parsed date or null if unrecognized.
	 */
	public function parse_date( string $value ): ?\DateTimeImmutable {
		$value = trim( $value );

		foreach ( self::DATE_FORMATS as $format ) {
			$dt = \DateTimeImmutable::createFromFormat( $format, $value );
			if ( false !== $dt ) {
				// Verify the parsed date matches the input (catches invalid dates like Feb 30).
				if ( $dt->format( $format ) === $value ) {
					return $dt;
				}
			}
		}

		// Try strtotime as a fallback for natural language dates.
		$timestamp = strtotime( $value );
		if ( false !== $timestamp ) {
			return ( new \DateTimeImmutable() )->setTimestamp( $timestamp );
		}

		return null;
	}

	/**
	 * Attempt to parse a time string.
	 *
	 * @param string $value Raw time string.
	 * @return \DateTimeImmutable|null Parsed time or null if unrecognized.
	 */
	public function parse_time( string $value ): ?\DateTimeImmutable {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		foreach ( self::TIME_FORMATS as $format ) {
			$dt = \DateTimeImmutable::createFromFormat( $format, $value );
			if ( false !== $dt ) {
				return $dt;
			}
		}

		return null;
	}

	/**
	 * Build a full datetime from separate date and optional time.
	 *
	 * @param \DateTimeImmutable      $date Parsed date.
	 * @param \DateTimeImmutable|null $time Parsed time, or null for midnight.
	 * @return \DateTimeImmutable Combined datetime.
	 */
	public function build_datetime( \DateTimeImmutable $date, ?\DateTimeImmutable $time ): \DateTimeImmutable {
		if ( null === $time ) {
			return $date->setTime( 0, 0, 0 );
		}

		return $date->setTime(
			(int) $time->format( 'H' ),
			(int) $time->format( 'i' ),
			(int) $time->format( 's' )
		);
	}

	/**
	 * Basic RRULE format validation.
	 *
	 * Checks that the string starts with FREQ= and contains recognized components.
	 *
	 * @param string $rrule RRULE string (with or without "RRULE:" prefix).
	 * @return bool True if the format is plausible.
	 */
	private function validate_rrule( string $rrule ): bool {
		// Strip optional prefix.
		if ( str_starts_with( strtoupper( $rrule ), 'RRULE:' ) ) {
			$rrule = substr( $rrule, 6 );
		}

		// Must contain FREQ= at minimum.
		if ( ! str_contains( strtoupper( $rrule ), 'FREQ=' ) ) {
			return false;
		}

		// FREQ value must be recognized.
		$valid_freqs = array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' );
		$matched     = false;

		foreach ( $valid_freqs as $freq ) {
			if ( str_contains( strtoupper( $rrule ), 'FREQ=' . $freq ) ) {
				$matched = true;
				break;
			}
		}

		return $matched;
	}

	/**
	 * Check if a value represents a boolean.
	 *
	 * @param string $value Raw value.
	 * @return bool True if the value is a recognized boolean representation.
	 */
	private function is_boolean_value( string $value ): bool {
		$normalized = strtolower( trim( $value ) );
		return in_array( $normalized, array( 'yes', 'no', 'true', 'false', '1', '0', 'y', 'n' ), true );
	}

	/**
	 * Interpret a string as boolean.
	 *
	 * @param string $value Raw value.
	 * @return bool Interpreted boolean value.
	 */
	public function to_bool( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( 'yes', 'true', '1', 'y' ), true );
	}
}
