<?php
/**
 * Shared derivation and validation for occurrence start/end times.
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

use NetterTechEvents\Core\NetterTechEventsSettings;
use NetterTechEvents\Exceptions\ValidationException;

/**
 * One derivation-and-validation path for every place an occurrence's times are
 * built from operator input: the event editor (EventSaveHandler), the occurrence
 * editor (OccurrenceSaveHandler), and hand-picked manual date rows. Keeping this
 * in one place is the whole point — three copies of "blank end means start + the
 * default duration" drifted apart and one of them (the occurrence editor) instead
 * produced a zero-length event (NTE-189).
 */
final class OccurrenceTimeResolver {

	/**
	 * Minimum event length in seconds (10 minutes). Also rejects inverted spans,
	 * where a derived or entered end lands at or before the start.
	 */
	public const MIN_DURATION_SECONDS = 600;

	/**
	 * Fill blank start/end times from the site defaults.
	 *
	 * Timed events: a blank start uses `default_event_start_time`; a blank end uses
	 * start + `default_event_duration_minutes`. All-day events span 00:00–23:59.
	 * A blank end while `require_end_time` is enabled is a validation error, not a
	 * derivation.
	 *
	 * @param string $start_date    Date component the end derivation is anchored to (Y-m-d).
	 * @param string $start_time    Raw start time (H:i) or '' to derive.
	 * @param string $end_time      Raw end time (H:i) or '' to derive.
	 * @param bool   $all_day       Whether the occurrence is all-day.
	 * @param string $error_context Optional label (e.g. a date) prefixed to the require-end error.
	 * @return array{start_time: string, end_time: string, derived: array<int, string>}
	 * @throws ValidationException When the end is blank and require_end_time is enabled.
	 */
	public static function derive_times(
		string $start_date,
		string $start_time,
		string $end_time,
		bool $all_day,
		string $error_context = ''
	): array {
		$derived = array();

		if ( $all_day ) {
			return array(
				'start_time' => '' !== $start_time ? $start_time : '00:00',
				'end_time'   => '' !== $end_time ? $end_time : '23:59',
				'derived'    => $derived,
			);
		}

		$display          = NetterTechEventsSettings::from_option()->display;
		$default_start    = $display->default_event_start_time;
		$default_duration = $display->default_event_duration_minutes;

		if ( '' === $start_time ) {
			$start_time = $default_start;
			$derived[]  = __( 'start time', 'nettertech-events' );
		}

		if ( '' === $end_time ) {
			if ( $display->require_end_time ) {
				$message = '' !== $error_context
					? sprintf(
						/* translators: %s: the date (or other label) missing an end time. */
						esc_html__( 'Added date %s needs an end time (end time is required in Settings → Display).', 'nettertech-events' ),
						esc_html( $error_context )
					)
					: esc_html__( 'An end time is required (Settings → Display).', 'nettertech-events' );

				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message is built entirely from esc_html__()/esc_html() above.
				throw ValidationException::fromErrors( array( $message ) );
			}

			$end_time  = ( new \DateTimeImmutable( $start_date . ' ' . $start_time . ':00' ) )
				->modify( '+' . $default_duration . ' minutes' )
				->format( 'H:i' );
			$derived[] = __( 'end time', 'nettertech-events' );
		}

		return array(
			'start_time' => $start_time,
			'end_time'   => $end_time,
			'derived'    => $derived,
		);
	}

	/**
	 * Reject a span shorter than the 10-minute minimum.
	 *
	 * This one check covers both "too short" and "inverted" (end at or before start),
	 * because an inverted span has a negative duration and fails the same comparison.
	 *
	 * @param \DateTimeImmutable $start Start instant.
	 * @param \DateTimeImmutable $end   End instant.
	 * @return void
	 * @throws ValidationException When the span is under the minimum.
	 */
	public static function validate_span( \DateTimeImmutable $start, \DateTimeImmutable $end ): void {
		if ( $end->getTimestamp() - $start->getTimestamp() < self::MIN_DURATION_SECONDS ) {
			throw ValidationException::fromErrors(
				array( esc_html__( 'Event must be at least 10 minutes long. Please adjust the end time.', 'nettertech-events' ) )
			);
		}
	}
}
