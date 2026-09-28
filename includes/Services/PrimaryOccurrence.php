<?php
/**
 * Which date is a non-recurring event's own date.
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Models\Occurrence;

/**
 * The row a non-recurring event's Date & Time fields stand for. The editor that displays those
 * fields and every save path that writes them must resolve the same row, or a save updates one
 * date while the form's tickets are bound to another.
 *
 * Not for recurring events: their date fields seed the pattern from the first row of any status.
 */
final class PrimaryOccurrence {

	/**
	 * Statuses a date can have and still be the one the operator is editing.
	 */
	private const LIVE_STATUSES = array( 'scheduled', 'rescheduled', 'postponed' );

	/**
	 * The event's own date: the earliest live date that was not hand-added, else the
	 * earliest live date of any kind.
	 *
	 * @param OccurrenceRepositoryInterface $repo     Occurrence repository.
	 * @param int                           $event_id Event id (events-table id).
	 * @return Occurrence|null Null when the event has no live date.
	 */
	public static function find( OccurrenceRepositoryInterface $repo, int $event_id ): ?Occurrence {
		$live = array_values(
			array_filter(
				$repo->for_event(
					$event_id,
					array(
						'orderby' => 'start_datetime ASC, id',
						'order'   => 'ASC',
						'limit'   => PHP_INT_MAX,
					)
				),
				static fn( Occurrence $occurrence ): bool => in_array( $occurrence->status, self::LIVE_STATUSES, true )
			)
		);

		foreach ( $live as $occurrence ) {
			if ( ! $occurrence->is_override ) {
				return $occurrence;
			}
		}

		return $live[0] ?? null;
	}

	/**
	 * The event's own date if it has one, else its first date of any status, so the editor
	 * still has something to show for an event whose every date is cancelled.
	 *
	 * @param OccurrenceRepositoryInterface $repo     Occurrence repository.
	 * @param int                           $event_id Event id (events-table id).
	 * @return Occurrence|null Null when the event has no dates at all.
	 */
	public static function find_for_display( OccurrenceRepositoryInterface $repo, int $event_id ): ?Occurrence {
		$primary = self::find( $repo, $event_id );
		if ( null !== $primary ) {
			return $primary;
		}

		$first = $repo->for_event( $event_id, array( 'limit' => 1 ) );

		return $first[0] ?? null;
	}
}
