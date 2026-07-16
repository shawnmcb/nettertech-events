<?php
/**
 * Recurrence Rule Builder Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Builds RFC 5545 RRULE strings from form POST data.
 *
 * Extracted from EventEditor to reduce complexity and improve testability.
 *
 * @since 0.9.0
 */
class RecurrenceRuleBuilder {

	/**
	 * Valid frequency values.
	 *
	 * @var array<string>
	 */
	private const VALID_FREQUENCIES = array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' );

	/**
	 * Valid day abbreviations.
	 *
	 * @var array<string>
	 */
	private const VALID_DAYS = array( 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' );

	/**
	 * Build RRULE from POST data.
	 *
	 * Caller must pass already-unslashed POST data after verifying nonce at the
	 * request boundary. The service does not read superglobals directly.
	 *
	 * @param array<string, mixed> $post_data POST data array (required).
	 * @return string RRULE string.
	 */
	public function build_from_post( array $post_data ): string {
		// If using a preset, return it with end condition appended.
		$preset = $this->sanitize_text( $post_data['recurrence_preset'] ?? '' );
		if ( ! empty( $preset ) && 'custom' !== $preset ) {
			return $this->append_end_condition( $preset, $post_data );
		}

		// Build custom rule if provided.
		if ( ! empty( $post_data['recurrence_rule'] ) ) {
			return $this->sanitize_text( $post_data['recurrence_rule'] );
		}

		// Build from individual fields.
		return $this->build_from_fields( $post_data );
	}

	/**
	 * Build RRULE from individual form fields.
	 *
	 * @param array<string, mixed> $post_data POST data.
	 * @return string RRULE string.
	 */
	private function build_from_fields( array $post_data ): string {
		$parts = array();

		// Frequency.
		$freq = $this->sanitize_text( $post_data['recurrence_freq'] ?? 'WEEKLY' );
		if ( ! in_array( $freq, self::VALID_FREQUENCIES, true ) ) {
			$freq = 'WEEKLY';
		}
		$parts[] = 'FREQ=' . $freq;

		// Interval.
		$interval = absint( $post_data['recurrence_interval'] ?? 1 );
		if ( $interval > 1 ) {
			$parts[] = 'INTERVAL=' . $interval;
		}

		// Days of week (for WEEKLY frequency).
		if ( 'WEEKLY' === $freq && ! empty( $post_data['recurrence_byday'] ) ) {
			$days = array_intersect( (array) $post_data['recurrence_byday'], self::VALID_DAYS );
			if ( ! empty( $days ) ) {
				$parts[] = 'BYDAY=' . implode( ',', $days );
			}
		}

		// nth-weekday-of-month (for MONTHLY frequency with nth_weekday type).
		// Example: "first and third Monday of each month" -> BYDAY=1MO,3MO.
		// Cartesian product of selected ordinals x selected weekdays.
		if ( 'MONTHLY' === $freq ) {
			$monthly_type = $this->sanitize_text( $post_data['recurrence_monthly_type'] ?? 'day_of_month' );
			if ( 'nth_weekday' === $monthly_type ) {
				$valid_ordinals = array( 1, 2, 3, 4, -1 );
				$ordinals       = array_filter(
					array_map( 'intval', (array) ( $post_data['recurrence_monthly_ordinals'] ?? array() ) ),
					static function ( $n ) use ( $valid_ordinals ) {
						return in_array( $n, $valid_ordinals, true );
					}
				);
				$days           = array_intersect(
					(array) ( $post_data['recurrence_monthly_byday'] ?? array() ),
					self::VALID_DAYS
				);
				if ( ! empty( $ordinals ) && ! empty( $days ) ) {
					$byday_parts = array();
					foreach ( $ordinals as $ord ) {
						foreach ( $days as $day ) {
							$byday_parts[] = $ord . $day;
						}
					}
					$parts[] = 'BYDAY=' . implode( ',', $byday_parts );
				}
			}
		}

		// End condition.
		$end_condition = $this->build_end_condition( $post_data );
		if ( ! empty( $end_condition ) ) {
			$parts[] = $end_condition;
		}

		return implode( ';', $parts );
	}

	/**
	 * Append end condition to a preset RRULE.
	 *
	 * @param string               $preset    Preset RRULE.
	 * @param array<string, mixed> $post_data POST data.
	 * @return string RRULE with end condition.
	 */
	private function append_end_condition( string $preset, array $post_data ): string {
		$end_condition = $this->build_end_condition( $post_data );
		if ( ! empty( $end_condition ) ) {
			return $preset . ';' . $end_condition;
		}
		return $preset;
	}

	/**
	 * Build end condition (COUNT or UNTIL) from POST data.
	 *
	 * @param array<string, mixed> $post_data POST data.
	 * @return string End condition string or empty.
	 */
	private function build_end_condition( array $post_data ): string {
		$end_type = $this->sanitize_text( $post_data['recurrence_end_type'] ?? 'never' );

		if ( 'count' === $end_type ) {
			$count = absint( $post_data['recurrence_count'] ?? 10 );
			$count = min( max( $count, 1 ), 365 );
			return 'COUNT=' . $count;
		}

		if ( 'until' === $end_type ) {
			$until = $this->sanitize_text( $post_data['recurrence_until'] ?? '' );
			if ( $until ) {
				return 'UNTIL=' . str_replace( '-', '', $until ) . 'T235959Z';
			}
		}

		return '';
	}

	/**
	 * Sanitize text field.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized string.
	 */
	private function sanitize_text( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( $value ) );
	}
}
