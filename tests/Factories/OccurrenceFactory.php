<?php
/**
 * Occurrence factory for tests.
 *
 * @package NetterTechEvents\Tests\Factories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Factories;

use NetterTechEvents\Models\Occurrence;

/**
 * Factory for creating Occurrence test fixtures.
 */
class OccurrenceFactory {

	/**
	 * Counter for unique IDs.
	 *
	 * @var int
	 */
	private static int $counter = 0;

	/**
	 * Create an Occurrence instance.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return Occurrence
	 */
	public static function create( array $attributes = [] ): Occurrence {
		++self::$counter;

		$start = $attributes['start_datetime'] ?? gmdate( 'Y-m-d 19:00:00', strtotime( '+1 week' ) );
		$end   = $attributes['end_datetime'] ?? gmdate( 'Y-m-d 21:00:00', strtotime( '+1 week' ) );

		$occurrence                   = new Occurrence();
		$occurrence->id               = $attributes['id'] ?? self::$counter;
		$occurrence->event_id         = $attributes['event_id'] ?? 1;
		$occurrence->start_datetime   = $start;
		$occurrence->end_datetime     = $end;
		$occurrence->status           = $attributes['status'] ?? 'scheduled';

		return $occurrence;
	}

	/**
	 * Create an occurrence for a specific date.
	 *
	 * @param string               $date       Date string (Y-m-d format).
	 * @param string               $start_time Start time (H:i format).
	 * @param string               $end_time   End time (H:i format).
	 * @param array<string, mixed> $attributes Additional attributes.
	 * @return Occurrence
	 */
	public static function forDate(
		string $date,
		string $start_time = '19:00',
		string $end_time = '21:00',
		array $attributes = []
	): Occurrence {
		return self::create(
			array_merge(
				[
					'start_datetime' => "{$date} {$start_time}:00",
					'end_datetime'   => "{$date} {$end_time}:00",
				],
				$attributes
			)
		);
	}

	/**
	 * Create a past occurrence.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return Occurrence
	 */
	public static function past( array $attributes = [] ): Occurrence {
		return self::create(
			array_merge(
				[
					'start_datetime' => gmdate( 'Y-m-d 19:00:00', strtotime( '-1 week' ) ),
					'end_datetime'   => gmdate( 'Y-m-d 21:00:00', strtotime( '-1 week' ) ),
				],
				$attributes
			)
		);
	}

	/**
	 * Create a cancelled occurrence.
	 *
	 * @param array<string, mixed> $attributes Override attributes.
	 * @return Occurrence
	 */
	public static function cancelled( array $attributes = [] ): Occurrence {
		return self::create( array_merge( [ 'status' => 'cancelled' ], $attributes ) );
	}

	/**
	 * Reset the counter.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$counter = 0;
	}
}
