<?php
/**
 * Time formatting for iCalendar files.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Writes date-times and VTIMEZONE components for calendar files.
 *
 * Every calendar file NetterTech Events writes takes its times from here, so a
 * stored wall-clock is never printed as if it were UTC.
 *
 * @since 1.5.3
 */
final class IcsTime {

	private const DAYS = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );

	private const DAY = 86400;

	/**
	 * Years past the range a yearly rule must keep matching the zone's real transitions.
	 */
	private const RULE_CHECK_YEARS = 8;

	/**
	 * An instant as a UTC DATE-TIME value.
	 *
	 * @since 1.5.3
	 *
	 * @param DateTimeInterface $moment The instant.
	 * @return string e.g. 20270315T000000Z
	 */
	public static function utc( DateTimeInterface $moment ): string {
		return gmdate( 'Ymd\THis\Z', $moment->getTimestamp() );
	}

	/**
	 * An instant as a local DATE-TIME value in the given zone (no suffix).
	 *
	 * @since 1.5.3
	 *
	 * @param DateTimeInterface $moment The instant.
	 * @param DateTimeZone      $zone   The zone to read it in.
	 * @return string e.g. 20270314T190000
	 */
	public static function local( DateTimeInterface $moment, DateTimeZone $zone ): string {
		return DateTimeImmutable::createFromInterface( $moment )->setTimezone( $zone )->format( 'Ymd\THis' );
	}

	/**
	 * Whether a zone has a region/city name a calendar client can look up.
	 *
	 * UTC and fixed offsets have no daylight time to follow, so times in them are written in UTC.
	 *
	 * @since 1.5.3
	 *
	 * @param DateTimeZone $zone The zone.
	 * @return bool
	 */
	public static function is_named( DateTimeZone $zone ): bool {
		return str_contains( $zone->getName(), '/' );
	}

	/**
	 * A VTIMEZONE component for a zone, valid from `$from` to `$to`.
	 *
	 * Outlook and Exchange build a zone from one STANDARD and one DAYLIGHT
	 * sub-component, each with a yearly RRULE, and older Exchange versions reject
	 * a VTIMEZONE holding several of either. So the zone's rules are written that
	 * way whenever one yearly rule per observance reproduces every transition from
	 * the range start to some years past its end (a client applies the rule
	 * indefinitely, and a short range cannot tell "4th Sunday" from "last Sunday").
	 * Only a range that spans a change in the zone's rules gets one sub-component
	 * per transition.
	 *
	 * @since 1.5.3
	 *
	 * @param DateTimeZone      $zone A named zone.
	 * @param DateTimeInterface $from Earliest instant the calendar uses in this zone.
	 * @param DateTimeInterface $to   Latest instant the calendar uses in this zone.
	 * @return string The component, CRLF-terminated.
	 */
	public static function vtimezone( DateTimeZone $zone, DateTimeInterface $from, DateTimeInterface $to ): string {
		$from_ts = $from->getTimestamp();
		$to_ts   = max( $from_ts, $to->getTimestamp() );

		$check_ts = $to_ts + self::RULE_CHECK_YEARS * 366 * self::DAY;

		// Two years back finds the onsets of both observances in force at $from.
		$transitions = $zone->getTransitions( $from_ts - 2 * 366 * self::DAY, $check_ts );
		$onsets      = self::onsets( $transitions );

		$lines = array( 'BEGIN:VTIMEZONE', 'TZID:' . $zone->getName() );

		if ( empty( $onsets ) ) {
			$offset = $zone->getOffset( DateTimeImmutable::createFromInterface( $from ) );
			$lines  = array_merge(
				$lines,
				self::observance(
					'STANDARD',
					'19700101T000000',
					$offset,
					$offset,
					DateTimeImmutable::createFromInterface( $from )->setTimezone( $zone )->format( 'T' ),
					null
				)
			);
		} else {
			$lines = array_merge( $lines, self::observances( $onsets, $from_ts, $to_ts, $check_ts ) );
		}

		$lines[] = 'END:VTIMEZONE';

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Each real transition with the offset in force before it.
	 *
	 * @param array<int, array{ts: int, offset: int, isdst: bool, abbr: string}> $transitions From getTransitions(); the first entry is the state at the range start.
	 * @return array<int, array{ts: int, from: int, to: int, dst: bool, abbr: string}>
	 */
	private static function onsets( array $transitions ): array {
		$onsets = array();
		$count  = count( $transitions );
		for ( $i = 1; $i < $count; $i++ ) {
			$onsets[] = array(
				'ts'   => (int) $transitions[ $i ]['ts'],
				'from' => (int) $transitions[ $i - 1 ]['offset'],
				'to'   => (int) $transitions[ $i ]['offset'],
				'dst'  => (bool) $transitions[ $i ]['isdst'],
				'abbr' => (string) $transitions[ $i ]['abbr'],
			);
		}

		return $onsets;
	}

	/**
	 * The STANDARD and DAYLIGHT sub-components covering `$from_ts`..`$to_ts`.
	 *
	 * @param array<int, array{ts: int, from: int, to: int, dst: bool, abbr: string}> $onsets  Transitions, oldest first.
	 * @param int                                                                     $from_ts  Range start.
	 * @param int                                                                     $to_ts    Range end.
	 * @param int                                                                     $check_ts How far a yearly rule must hold.
	 * @return array<int, string> Content lines.
	 */
	private static function observances( array $onsets, int $from_ts, int $to_ts, int $check_ts ): array {
		$bases = array();
		foreach ( array( false, true ) as $dst ) {
			$base = self::base_onset( $onsets, $dst, $from_ts );
			if ( null !== $base ) {
				$bases[] = $base;
			}
		}

		$lines = array();
		foreach ( $bases as $base ) {
			$rule = self::yearly_rule( $onsets, $base, $check_ts );
			if ( null === $rule ) {
				return self::explicit_observances( $onsets, $bases, $to_ts );
			}
			$lines = array_merge(
				$lines,
				self::observance(
					$base['dst'] ? 'DAYLIGHT' : 'STANDARD',
					gmdate( 'Ymd\THis', $base['ts'] + $base['from'] ),
					$base['from'],
					$base['to'],
					$base['abbr'],
					$rule
				)
			);
		}

		return $lines;
	}

	/**
	 * The onset of one observance in force at `$from_ts`, or its first one after.
	 *
	 * @param array<int, array{ts: int, from: int, to: int, dst: bool, abbr: string}> $onsets  Transitions, oldest first.
	 * @param bool                                                                    $dst     Which observance.
	 * @param int                                                                     $from_ts Range start.
	 * @return array{ts: int, from: int, to: int, dst: bool, abbr: string}|null
	 */
	private static function base_onset( array $onsets, bool $dst, int $from_ts ): ?array {
		$base = null;
		foreach ( $onsets as $onset ) {
			if ( $onset['dst'] !== $dst ) {
				continue;
			}
			if ( $onset['ts'] <= $from_ts || null === $base ) {
				$base = $onset;
			}
			if ( $onset['ts'] > $from_ts ) {
				break;
			}
		}

		return $base;
	}

	/**
	 * A yearly RRULE that reproduces exactly this observance's onsets from `$base` to `$to_ts`, or null.
	 *
	 * @param array<int, array{ts: int, from: int, to: int, dst: bool, abbr: string}> $onsets Transitions, oldest first.
	 * @param array{ts: int, from: int, to: int, dst: bool, abbr: string}             $base   The first onset the rule starts from.
	 * @param int                                                                     $to_ts  Range end.
	 * @return string|null
	 */
	private static function yearly_rule( array $onsets, array $base, int $to_ts ): ?string {
		$actual = array();
		foreach ( $onsets as $onset ) {
			if ( $onset['dst'] !== $base['dst'] || $onset['ts'] < $base['ts'] || $onset['ts'] > $to_ts ) {
				continue;
			}
			if ( $onset['from'] !== $base['from'] || $onset['to'] !== $base['to'] ) {
				return null;
			}
			$actual[] = $onset['ts'];
		}

		$local   = $base['ts'] + $base['from'];
		$month   = (int) gmdate( 'n', $local );
		$day     = (int) gmdate( 'j', $local );
		$weekday = (int) gmdate( 'w', $local );
		$seconds = $local % self::DAY;

		$candidates = array( intdiv( $day - 1, 7 ) + 1 );
		if ( $day + 7 > (int) gmdate( 't', $local ) ) {
			$candidates[] = -1;
		}

		foreach ( $candidates as $nth ) {
			$predicted = array();
			for ( $year = (int) gmdate( 'Y', $local ); ; $year++ ) {
				$date = self::nth_weekday( $year, $month, $weekday, $nth );
				if ( null === $date ) {
					break;
				}
				$ts = $date + $seconds - $base['from'];
				if ( $ts > $to_ts ) {
					break;
				}
				$predicted[] = $ts;
			}

			if ( $predicted === $actual ) {
				return sprintf( 'FREQ=YEARLY;BYMONTH=%d;BYDAY=%d%s', $month, $nth, self::DAYS[ $weekday ] );
			}
		}

		return null;
	}

	/**
	 * Midnight (as a UTC timestamp of that calendar date) of the nth weekday of a month.
	 *
	 * @param int $year    Year.
	 * @param int $month   Month.
	 * @param int $weekday 0 = Sunday.
	 * @param int $nth     1-based, or -1 for the last.
	 * @return int|null Null when the month has no such day.
	 */
	private static function nth_weekday( int $year, int $month, int $weekday, int $nth ): ?int {
		$first_of_month = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), new DateTimeZone( 'UTC' ) );
		$days           = (int) $first_of_month->format( 't' );
		$first          = 1 + ( $weekday - (int) $first_of_month->format( 'w' ) + 7 ) % 7;
		$day            = $nth > 0 ? $first + 7 * ( $nth - 1 ) : $first + 7 * intdiv( $days - $first, 7 );

		return $day <= $days ? $first_of_month->getTimestamp() + ( $day - 1 ) * self::DAY : null;
	}

	/**
	 * One sub-component per transition, from the earliest base onset onward.
	 *
	 * @param array<int, array{ts: int, from: int, to: int, dst: bool, abbr: string}> $onsets Transitions, oldest first.
	 * @param array<int, array{ts: int, from: int, to: int, dst: bool, abbr: string}> $bases  The onsets in force at the range start.
	 * @param int                                                                     $to_ts  Range end.
	 * @return array<int, string> Content lines.
	 */
	private static function explicit_observances( array $onsets, array $bases, int $to_ts ): array {
		$start = PHP_INT_MAX;
		foreach ( $bases as $base ) {
			$start = min( $start, $base['ts'] );
		}

		$lines = array();
		foreach ( $onsets as $onset ) {
			if ( $onset['ts'] < $start || $onset['ts'] > $to_ts ) {
				continue;
			}
			$lines = array_merge(
				$lines,
				self::observance(
					$onset['dst'] ? 'DAYLIGHT' : 'STANDARD',
					gmdate( 'Ymd\THis', $onset['ts'] + $onset['from'] ),
					$onset['from'],
					$onset['to'],
					$onset['abbr'],
					null
				)
			);
		}

		return $lines;
	}

	/**
	 * A STANDARD or DAYLIGHT sub-component.
	 *
	 * @param string      $kind   STANDARD or DAYLIGHT.
	 * @param string      $start  Local onset, Ymd\THis.
	 * @param int         $from   Offset before, seconds.
	 * @param int         $to     Offset after, seconds.
	 * @param string      $abbr   Zone abbreviation.
	 * @param string|null $rrule  Yearly rule, or null for a single onset.
	 * @return array<int, string> Content lines.
	 */
	private static function observance( string $kind, string $start, int $from, int $to, string $abbr, ?string $rrule ): array {
		$lines = array(
			'BEGIN:' . $kind,
			'DTSTART:' . $start,
			'TZOFFSETFROM:' . self::offset( $from ),
			'TZOFFSETTO:' . self::offset( $to ),
		);
		if ( null !== $rrule ) {
			$lines[] = 'RRULE:' . $rrule;
		}
		// A numeric abbreviation (e.g. "+03") is no name; leave TZNAME out rather than repeat the offset.
		if ( '' !== $abbr && ! preg_match( '/^[+-]\d+$/', $abbr ) ) {
			$lines[] = 'TZNAME:' . $abbr;
		}
		$lines[] = 'END:' . $kind;

		return $lines;
	}

	/**
	 * A UTC offset in iCalendar form.
	 *
	 * @param int $seconds Offset from UTC.
	 * @return string e.g. -0500, +0530, +003000 for a seconds component.
	 */
	private static function offset( int $seconds ): string {
		$sign    = $seconds < 0 ? '-' : '+';
		$seconds = abs( $seconds );
		$out     = sprintf( '%s%02d%02d', $sign, intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ) );

		return 0 !== $seconds % 60 ? $out . sprintf( '%02d', $seconds % 60 ) : $out;
	}
}
