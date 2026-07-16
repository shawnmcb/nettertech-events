<?php
/**
 * RRULE parser service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Exceptions\RRuleException;
use NetterTechEvents\Models\RecurrenceRule;

/**
 * Parses RFC 5545 RRULE strings into RecurrenceRule objects.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc5545#section-3.3.10
 *
 * @since 0.8.0
 */
class RRuleParser {

	/**
	 * Parse an RRULE string.
	 *
	 * @param string $rrule RRULE string (with or without RRULE: prefix).
	 * @return RecurrenceRule
	 * @throws RRuleException If the rule is invalid.
	 */
	public function parse( string $rrule ): RecurrenceRule {
		// Remove RRULE: prefix if present.
		$rrule = preg_replace( '/^RRULE:/i', '', trim( $rrule ) );

		if ( empty( $rrule ) ) {
			throw RRuleException::emptyRule();
		}

		// Parse into key=value pairs.
		$parts = $this->parse_parts( $rrule );

		// FREQ is required.
		if ( ! isset( $parts['FREQ'] ) ) {
			throw RRuleException::missingComponent( 'FREQ', esc_html( $rrule ) );
		}

		$rule                = new RecurrenceRule( $parts['FREQ'] );
		$rule->original_rule = $rrule;

		// Parse optional components.
		if ( isset( $parts['INTERVAL'] ) ) {
			$rule->interval = max( 1, (int) $parts['INTERVAL'] );
		}

		if ( isset( $parts['COUNT'] ) ) {
			$rule->count = max( 1, (int) $parts['COUNT'] );
		}

		if ( isset( $parts['UNTIL'] ) ) {
			$rule->until = $this->parse_date( $parts['UNTIL'] );
		}

		if ( isset( $parts['BYDAY'] ) ) {
			$rule->by_day = $this->parse_by_day( $parts['BYDAY'], $rule );
		}

		if ( isset( $parts['BYMONTHDAY'] ) ) {
			$rule->by_month_day = $this->parse_int_list( $parts['BYMONTHDAY'] );
		}

		if ( isset( $parts['BYMONTH'] ) ) {
			$rule->by_month = $this->parse_int_list( $parts['BYMONTH'] );
		}

		// A positioned BYDAY (e.g. 1MO) already populates by_set_pos via
		// parse_by_day(). Only let an explicit BYSETPOS set positions when BYDAY
		// did not, so a stray BYSETPOS cannot silently clobber the positions the
		// app's own rules encode in BYDAY.
		if ( isset( $parts['BYSETPOS'] ) && empty( $rule->by_set_pos ) ) {
			$rule->by_set_pos = $this->parse_int_list( $parts['BYSETPOS'] );
		}

		if ( isset( $parts['WKST'] ) && in_array( $parts['WKST'], RecurrenceRule::DAYS, true ) ) {
			$rule->wkst = $parts['WKST'];
		}

		return $rule;
	}

	/**
	 * Try to parse an RRULE, returning null on failure.
	 *
	 * @param string $rrule RRULE string.
	 * @return RecurrenceRule|null
	 */
	public function try_parse( string $rrule ): ?RecurrenceRule {
		try {
			return $this->parse( $rrule );
		} catch ( RRuleException $e ) {
			return null;
		}
	}

	/**
	 * Validate an RRULE string.
	 *
	 * @param string $rrule RRULE string.
	 * @return array<string> Array of error messages, empty if valid.
	 */
	public function validate( string $rrule ): array {
		$errors = array();

		try {
			$rule = $this->parse( $rrule );

			// Additional validation.
			if ( null !== $rule->count && null !== $rule->until ) {
				$errors[] = __( 'COUNT and UNTIL cannot both be specified.', 'nettertech-events' );
			}

			if ( null !== $rule->count && $rule->count > 365 ) {
				$errors[] = __( 'COUNT cannot exceed 365 occurrences.', 'nettertech-events' );
			}

			// Check for endless recurrence without limit.
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedIf -- Intentional: no-op, generation is limited in OccurrenceGenerator.
			if ( ! $rule->has_end() ) {
				// This is allowed but we'll limit generation in OccurrenceGenerator.
			}
		} catch ( RRuleException $e ) {
			$errors[] = $e->getMessage();
		}

		return $errors;
	}

	/**
	 * Parse RRULE string into key=value parts.
	 *
	 * @param string $rrule RRULE string.
	 * @return array<string, string>
	 */
	private function parse_parts( string $rrule ): array {
		$parts    = array();
		$segments = explode( ';', $rrule );

		foreach ( $segments as $segment ) {
			$segment = trim( $segment );
			if ( empty( $segment ) ) {
				continue;
			}

			$pos = strpos( $segment, '=' );
			if ( false === $pos ) {
				continue;
			}

			$key   = strtoupper( substr( $segment, 0, $pos ) );
			$value = substr( $segment, $pos + 1 );

			$parts[ $key ] = $value;
		}

		return $parts;
	}

	/**
	 * Parse a date string from RRULE.
	 *
	 * Supports formats:
	 * - YYYYMMDD
	 * - YYYYMMDDTHHMMSS
	 * - YYYYMMDDTHHMMSSZ
	 *
	 * @param string $date Date string.
	 * @return \DateTimeImmutable
	 * @throws RRuleException If date is invalid.
	 */
	private function parse_date( string $date ): \DateTimeImmutable {
		// Try different formats.
		$formats = array(
			'Ymd\THis\Z',
			'Ymd\THis',
			'Ymd',
		);

		foreach ( $formats as $format ) {
			$parsed = \DateTimeImmutable::createFromFormat( $format, $date, new \DateTimeZone( 'UTC' ) );
			if ( false !== $parsed ) {
				return $parsed;
			}
		}

		throw RRuleException::invalidComponent( 'UNTIL', esc_html( $date ), array( 'Ymd', 'YmdTHis', 'YmdTHisZ' ) );
	}

	/**
	 * Parse BYDAY component.
	 *
	 * BYDAY can contain:
	 * - Simple days: MO,TU,WE
	 * - Days with position: 1MO,-1FR (first Monday, last Friday)
	 *
	 * @param string         $value BYDAY value.
	 * @param RecurrenceRule $rule  Rule to populate with positions.
	 * @return array<string>
	 */
	private function parse_by_day( string $value, RecurrenceRule $rule ): array {
		$days      = array();
		$positions = array();

		$parts = explode( ',', $value );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( empty( $part ) ) {
				continue;
			}

			// Check for position prefix (e.g., 1MO, -1FR, 2TU).
			if ( preg_match( '/^(-?\d+)([A-Z]{2})$/', $part, $matches ) ) {
				$positions[] = (int) $matches[1];
				$day         = $matches[2];
			} else {
				$day = $part;
			}

			if ( in_array( $day, RecurrenceRule::DAYS, true ) ) {
				$days[] = $day;
			}
		}

		// Store positions if found.
		if ( ! empty( $positions ) ) {
			$rule->by_set_pos = array_unique( $positions );
		}

		return array_unique( $days );
	}

	/**
	 * Parse a comma-separated list of integers.
	 *
	 * @param string $value Value string.
	 * @return array<int>
	 */
	private function parse_int_list( string $value ): array {
		$parts = explode( ',', $value );
		$ints  = array();

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( is_numeric( $part ) ) {
				$ints[] = (int) $part;
			}
		}

		return $ints;
	}

	/**
	 * Build an RRULE string from components.
	 *
	 * Helper method for creating rules programmatically.
	 *
	 * @param array<string, mixed> $components RRULE components.
	 * @return string
	 * @throws RRuleException If FREQ is missing or invalid.
	 */
	public function build( array $components ): string {
		$parts = array();

		// FREQ is required.
		if ( ! isset( $components['freq'] ) ) {
			throw RRuleException::missingComponent( 'FREQ' );
		}

		$freq = strtoupper( $components['freq'] );
		if ( ! in_array( $freq, RecurrenceRule::FREQUENCIES, true ) ) {
			throw RRuleException::invalidComponent( 'FREQ', esc_html( $freq ), array_map( 'esc_html', RecurrenceRule::FREQUENCIES ) );
		}

		$parts[] = 'FREQ=' . $freq;

		if ( isset( $components['interval'] ) && $components['interval'] > 1 ) {
			$parts[] = 'INTERVAL=' . (int) $components['interval'];
		}

		if ( isset( $components['count'] ) ) {
			$parts[] = 'COUNT=' . (int) $components['count'];
		}

		if ( isset( $components['until'] ) ) {
			if ( $components['until'] instanceof \DateTimeInterface ) {
				$parts[] = 'UNTIL=' . $components['until']->format( 'Ymd\THis\Z' );
			} else {
				$parts[] = 'UNTIL=' . $components['until'];
			}
		}

		if ( ! empty( $components['byday'] ) ) {
			$days    = is_array( $components['byday'] )
				? implode( ',', $components['byday'] )
				: $components['byday'];
			$parts[] = 'BYDAY=' . $days;
		}

		if ( ! empty( $components['bymonthday'] ) ) {
			$days    = is_array( $components['bymonthday'] )
				? implode( ',', $components['bymonthday'] )
				: $components['bymonthday'];
			$parts[] = 'BYMONTHDAY=' . $days;
		}

		if ( ! empty( $components['bymonth'] ) ) {
			$months  = is_array( $components['bymonth'] )
				? implode( ',', $components['bymonth'] )
				: $components['bymonth'];
			$parts[] = 'BYMONTH=' . $months;
		}

		return implode( ';', $parts );
	}
}
