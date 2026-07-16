<?php
/**
 * VEVENT parser for iCal import operations.
 *
 * Parses RFC 5545 VEVENT blocks into structured event data
 * using a data-driven approach for property handling.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Parses VEVENT content blocks from iCal files.
 *
 * Uses data-driven property mapping to reduce cyclomatic complexity
 * and make the parser easily extensible for additional properties.
 *
 * @since 0.9.3
 */
final class VEventParser {

	/**
	 * Simple properties that map directly to data keys.
	 *
	 * These properties have a 1:1 mapping from iCal property to output key
	 * and require only text unescaping.
	 *
	 * @var array<string, string>
	 */
	private const SIMPLE_PROPERTIES = array(
		'UID'         => 'uid',
		'SUMMARY'     => 'summary',
		'DESCRIPTION' => 'description',
		'LOCATION'    => 'location',
		'URL'         => 'url',
		'RRULE'       => 'rrule',
	);

	/**
	 * Parse a VEVENT block into event data.
	 *
	 * @param string $vevent_content VEVENT content (without BEGIN/END tags).
	 * @return array<string, mixed>|null Parsed event data or null if invalid.
	 */
	public function parse( string $vevent_content ): ?array {
		$lines = explode( "\n", trim( $vevent_content ) );
		$data  = array();

		foreach ( $lines as $line ) {
			$parsed = $this->parse_line( trim( $line ) );
			if ( null === $parsed ) {
				continue;
			}

			$this->apply_property( $parsed['property'], $parsed['value'], $parsed['params'], $data );
		}

		return $this->validate_and_finalize( $data );
	}

	/**
	 * Parse a single iCal property line.
	 *
	 * @param string $line The line to parse.
	 * @return array{property: string, value: string, params: array<string, string>}|null Parsed components or null.
	 */
	private function parse_line( string $line ): ?array {
		if ( empty( $line ) ) {
			return null;
		}

		// Parse property:value format.
		$colon_pos = strpos( $line, ':' );
		if ( false === $colon_pos ) {
			return null;
		}

		$property = substr( $line, 0, $colon_pos );
		$value    = substr( $line, $colon_pos + 1 );

		// Extract property parameters (e.g., DTSTART;VALUE=DATE:20260115).
		$params = $this->extract_parameters( $property );
		if ( ! empty( $params['params'] ) ) {
			$property = $params['property'];
		}

		return array(
			'property' => strtoupper( $property ),
			'value'    => $this->unescape_text( $value ),
			'params'   => $params['params'] ?? array(),
		);
	}

	/**
	 * Extract parameters from a property string.
	 *
	 * Handles property parameters like DTSTART;VALUE=DATE or DTSTART;TZID=America/Chicago.
	 *
	 * @param string $property The property string potentially containing parameters.
	 * @return array{property: string, params: array<string, string>} Property name and parameters.
	 */
	private function extract_parameters( string $property ): array {
		if ( strpos( $property, ';' ) === false ) {
			return array(
				'property' => $property,
				'params'   => array(),
			);
		}

		$parts       = explode( ';', $property );
		$params      = array();
		$parts_count = count( $parts );

		for ( $i = 1; $i < $parts_count; $i++ ) {
			if ( strpos( $parts[ $i ], '=' ) !== false ) {
				list( $key, $val ) = explode( '=', $parts[ $i ], 2 );
				$params[ $key ]    = $val;
			}
		}

		return array(
			'property' => $parts[0],
			'params'   => $params,
		);
	}

	/**
	 * Apply a parsed property to the data array.
	 *
	 * Uses data-driven approach: simple properties use lookup table,
	 * complex properties use dedicated handlers.
	 *
	 * @param string                $property The property name (uppercase).
	 * @param string                $value    The property value.
	 * @param array<string, string> $params   Property parameters.
	 * @param array<string, mixed>  $data     Reference to data array to populate.
	 */
	private function apply_property( string $property, string $value, array $params, array &$data ): void {
		// Handle simple properties via lookup table.
		if ( isset( self::SIMPLE_PROPERTIES[ $property ] ) ) {
			$data[ self::SIMPLE_PROPERTIES[ $property ] ] = $value;
			return;
		}

		// Handle complex properties that need special processing.
		match ( $property ) {
			'DTSTART'    => $this->handle_dtstart( $value, $params, $data ),
			'DTEND'      => $data['end_datetime'] = $this->parse_ical_datetime( $value, $params ),
			'CATEGORIES' => $data['categories']   = array_map( 'trim', explode( ',', $value ) ),
			default      => null, // Unknown properties are silently ignored.
		};
	}

	/**
	 * Handle DTSTART property (sets both start_datetime and all_day flag).
	 *
	 * @param string                $value  The datetime value.
	 * @param array<string, string> $params Property parameters.
	 * @param array<string, mixed>  $data   Reference to data array.
	 */
	private function handle_dtstart( string $value, array $params, array &$data ): void {
		$data['start_datetime'] = $this->parse_ical_datetime( $value, $params );
		$data['all_day']        = isset( $params['VALUE'] ) && 'DATE' === $params['VALUE'];
	}

	/**
	 * Validate required fields and finalize the parsed data.
	 *
	 * @param array<string, mixed> $data Parsed data array.
	 * @return array<string, mixed>|null Finalized data or null if invalid.
	 */
	private function validate_and_finalize( array $data ): ?array {
		// Require summary and start_datetime.
		if ( empty( $data['summary'] ) || empty( $data['start_datetime'] ) ) {
			return null;
		}

		// Default end to start if not provided.
		if ( empty( $data['end_datetime'] ) ) {
			$data['end_datetime'] = $data['start_datetime'];
		}

		return $data;
	}

	/**
	 * Parse iCal datetime format.
	 *
	 * Supports:
	 * - 20260115T190000Z (UTC)
	 * - 20260115T190000 (local)
	 * - 20260115 (date only)
	 *
	 * @param string                $value  DateTime string.
	 * @param array<string, string> $params Property parameters.
	 * @return string MySQL datetime format.
	 */
	private function parse_ical_datetime( string $value, array $params = array() ): string {
		// Date only (VALUE=DATE).
		if ( isset( $params['VALUE'] ) && 'DATE' === $params['VALUE'] ) {
			return $this->parse_date_only( $value );
		}

		return $this->parse_datetime( $value );
	}

	/**
	 * Parse date-only value (YYYYMMDD).
	 *
	 * @param string $value Date string.
	 * @return string MySQL datetime format with 00:00:00 time.
	 */
	private function parse_date_only( string $value ): string {
		$year  = substr( $value, 0, 4 );
		$month = substr( $value, 4, 2 );
		$day   = substr( $value, 6, 2 );
		return sprintf( '%s-%s-%s 00:00:00', $year, $month, $day );
	}

	/**
	 * Parse datetime value with optional UTC indicator.
	 *
	 * @param string $value DateTime string (YYYYMMDDTHHMMSS or YYYYMMDDTHHMMSSZ).
	 * @return string MySQL datetime format (converted to site timezone if UTC).
	 */
	private function parse_datetime( string $value ): string {
		$is_utc = str_ends_with( $value, 'Z' );
		$value  = rtrim( $value, 'Z' );

		$year   = substr( $value, 0, 4 );
		$month  = substr( $value, 4, 2 );
		$day    = substr( $value, 6, 2 );
		$hour   = substr( $value, 9, 2 );
		$minute = substr( $value, 11, 2 );
		$second = substr( $value, 13, 2 ) ? substr( $value, 13, 2 ) : '00';

		$datetime = sprintf( '%s-%s-%s %s:%s:%s', $year, $month, $day, $hour, $minute, $second );

		if ( $is_utc ) {
			return $this->convert_utc_to_site_timezone( $datetime );
		}

		return $datetime;
	}

	/**
	 * Convert UTC datetime to site timezone.
	 *
	 * @param string $datetime MySQL datetime string in UTC.
	 * @return string MySQL datetime string in site timezone.
	 */
	private function convert_utc_to_site_timezone( string $datetime ): string {
		$dt = new \DateTimeImmutable( $datetime, new \DateTimeZone( 'UTC' ) );
		$dt = $dt->setTimezone( wp_timezone() );
		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Unescape text from iCal format.
	 *
	 * RFC 5545 defines escaping for special characters.
	 *
	 * @param string $text Text to unescape.
	 * @return string Unescaped text.
	 */
	private function unescape_text( string $text ): string {
		$text = str_replace( '\\n', "\n", $text );
		$text = str_replace( '\\N', "\n", $text );
		$text = str_replace( '\,', ',', $text );
		$text = str_replace( '\;', ';', $text );
		$text = str_replace( '\\\\', '\\', $text );
		return $text;
	}
}
