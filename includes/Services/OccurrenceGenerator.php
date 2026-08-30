<?php
/**
 * Occurrence generator service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\RecurrenceRule;

/**
 * Generates Occurrence objects from a RecurrenceRule.
 *
 * This is the core engine that takes an event with a recurrence rule
 * and generates individual occurrence instances.
 *
 * @since 0.8.0
 * @api
 */
class OccurrenceGenerator {

	/**
	 * Maximum occurrences to generate (safety limit).
	 *
	 * @var int
	 */
	public const MAX_OCCURRENCES = 365;

	/**
	 * Hard cap on rule expansion for uncounted rules (runaway guard).
	 *
	 * Expansion always starts at the rule's original DTSTART (NTE-200: moving
	 * the anchor forward changes a BYDAY-less rule's weekday and restarts
	 * COUNT), so an old series legitimately walks through years of past dates
	 * before reaching the horizon. The horizon terminates every sane rule;
	 * this cap only stops pathological input from spinning.
	 *
	 * @var int
	 */
	public const EXPANSION_HARD_CAP = 5000;

	/**
	 * Default horizon in days for generating occurrences.
	 *
	 * @var int
	 */
	public const DEFAULT_HORIZON_DAYS = 365;

	/**
	 * Generate occurrences for an event.
	 *
	 * @param Event                   $event        The event.
	 * @param \DateTimeInterface      $start_date   First occurrence start date/time (rule DTSTART — never relocated; see NTE-200).
	 * @param \DateTimeInterface      $end_date     First occurrence end date/time.
	 * @param RecurrenceRule          $rule         The recurrence rule.
	 * @param \DateTimeInterface|null $horizon      Optional end horizon (defaults to 1 year).
	 * @param \DateTimeInterface|null $collect_from Optional collection boundary: dates before it are still
	 *                                              expanded (consuming COUNT and sequence numbers per RFC 5545)
	 *                                              but not returned as rows. Used by future-only regeneration.
	 * @return array<Occurrence>
	 */
	public function generate(
		Event $event,
		\DateTimeInterface $start_date,
		\DateTimeInterface $end_date,
		RecurrenceRule $rule,
		?\DateTimeInterface $horizon = null,
		?\DateTimeInterface $collect_from = null
	): array {
		$event_id = $event->id;
		if ( null === $event_id ) {
			return array();
		}

		// Calculate duration between start and end.
		$duration = $start_date->diff( $end_date );

		// Set horizon.
		if ( null === $horizon ) {
			$horizon_days = $this->get_horizon_days();
			// Site wall-clock, to match the naive site-local dates being generated.
			$site_now = new \DateTimeImmutable( current_time( 'mysql' ) );
			$modified = $site_now->modify( "+{$horizon_days} days" );
			$horizon  = false !== $modified ? $modified : $site_now;
		}

		// Generate dates based on frequency.
		$dates = match ( $rule->freq ) {
			RecurrenceRule::FREQ_DAILY   => $this->generate_daily( $start_date, $rule, $horizon ),
			RecurrenceRule::FREQ_WEEKLY  => $this->generate_weekly( $start_date, $rule, $horizon ),
			RecurrenceRule::FREQ_MONTHLY => $this->generate_monthly( $start_date, $rule, $horizon ),
			RecurrenceRule::FREQ_YEARLY  => $this->generate_yearly( $start_date, $rule, $horizon ),
			default => array(),
		};

		// Convert to Occurrence objects. Dates before $collect_from are skipped
		// AFTER expansion, so they still consume their COUNT slot and their
		// sequence number ($i is the full-rule position) — filtering the output
		// instead of relocating DTSTART is what keeps a BYDAY-less rule on its
		// original weekday and COUNT meaning "N total ever" (NTE-200).
		$occurrences = array();
		$collected   = 0;
		foreach ( $dates as $i => $date ) {
			if ( null !== $collect_from && $date < $collect_from ) {
				continue;
			}
			if ( $collected >= self::MAX_OCCURRENCES ) {
				break;
			}
			++$collected;
			$occurrence                 = new Occurrence();
			$occurrence->event_id       = $event_id;
			$occurrence->start_datetime = $date->format( 'Y-m-d H:i:s' );

			// Calculate end time by adding duration.
			$occurrence_end           = $date->add( $duration );
			$occurrence->end_datetime = $occurrence_end->format( 'Y-m-d H:i:s' );

			// 1-based sequence in generation (date) order. Required for original-slot
			// recovery in iCal export (RECURRENCE-ID / EXDATE).
			$occurrence->sequence_number = $i + 1;

			// The rule slot this row was generated at; survives later moves (NTE-182).
			$occurrence->origin_start_datetime = $occurrence->start_datetime;

			$occurrence->status = 'scheduled';
			$occurrences[]      = $occurrence;
		}

		return $occurrences;
	}

	/**
	 * Generate daily occurrences.
	 *
	 * @param \DateTimeInterface $start   Start date.
	 * @param RecurrenceRule     $rule    Rule.
	 * @param \DateTimeInterface $horizon End horizon.
	 * @return array<\DateTimeImmutable>
	 */
	private function generate_daily(
		\DateTimeInterface $start,
		RecurrenceRule $rule,
		\DateTimeInterface $horizon
	): array {
		$dates   = array();
		$current = \DateTimeImmutable::createFromInterface( $start );
		$count   = 0;
		$max     = $rule->count ?? self::EXPANSION_HARD_CAP;
		$until   = $rule->until ?? $horizon;

		while ( $count < $max && $current <= $until && $current <= $horizon ) {
			$dates[] = $current;
			++$count;
			$current = $current->modify( "+{$rule->interval} days" );
		}

		return $dates;
	}

	/**
	 * Generate weekly occurrences.
	 *
	 * @param \DateTimeInterface $start   Start date.
	 * @param RecurrenceRule     $rule    Rule.
	 * @param \DateTimeInterface $horizon End horizon.
	 * @return array<\DateTimeImmutable>
	 */
	private function generate_weekly(
		\DateTimeInterface $start,
		RecurrenceRule $rule,
		\DateTimeInterface $horizon
	): array {
		$dates   = array();
		$current = \DateTimeImmutable::createFromInterface( $start );
		$count   = 0;
		$max     = $rule->count ?? self::EXPANSION_HARD_CAP;
		$until   = $rule->until ?? $horizon;

		$by_day = $this->get_weekly_days( $current, $rule->by_day );

		// Start from the beginning of the week containing start date.
		$week_start = $this->get_week_start( $current, $rule->wkst );

		while ( $count < $max && ! $this->exceeds_limits( $week_start, $until, $horizon ) ) {
			$result = $this->generate_week_dates(
				$week_start,
				$by_day,
				$rule->wkst,
				$start,
				$until,
				$horizon,
				$count,
				$max
			);

			$dates = array_merge( $dates, $result['dates'] );
			$count = $result['count'];

			if ( $result['break'] ) {
				break;
			}

			// Move to next week (or interval of weeks).
			$week_start = $week_start->modify( "+{$rule->interval} weeks" );
		}

		// Sort by date.
		usort( $dates, fn( $a, $b ) => $a <=> $b );

		return $dates;
	}

	/**
	 * Get the days to generate for weekly recurrence.
	 *
	 * @param \DateTimeImmutable $current Current date.
	 * @param array<string>|null $by_day  BYDAY rule values.
	 * @return array<string>
	 */
	private function get_weekly_days( \DateTimeImmutable $current, ?array $by_day ): array {
		if ( ! empty( $by_day ) ) {
			return $by_day;
		}
		$php_day = (int) $current->format( 'w' );
		return array( RecurrenceRule::PHP_DAY_MAP[ $php_day ] );
	}

	/**
	 * Generate dates for a single week.
	 *
	 * @param \DateTimeImmutable $week_start Week start date.
	 * @param array<string>      $by_day     Days to generate.
	 * @param string             $wkst       Week start day.
	 * @param \DateTimeInterface $start      Original start date.
	 * @param \DateTimeInterface $until      Until limit.
	 * @param \DateTimeInterface $horizon    Horizon limit.
	 * @param int                $count      Current count.
	 * @param int                $max        Maximum count.
	 * @return array{dates: array<\DateTimeImmutable>, count: int, break: bool}
	 */
	private function generate_week_dates(
		\DateTimeImmutable $week_start,
		array $by_day,
		string $wkst,
		\DateTimeInterface $start,
		\DateTimeInterface $until,
		\DateTimeInterface $horizon,
		int $count,
		int $max
	): array {
		$dates        = array();
		$should_break = false;

		foreach ( $by_day as $day ) {
			if ( $count >= $max ) {
				$should_break = true;
				break;
			}

			$day_offset      = $this->get_day_offset( $day, $wkst );
			$occurrence_date = $week_start->modify( "+{$day_offset} days" );
			$occurrence_date = $this->apply_time_from( $occurrence_date, $start );

			if ( $occurrence_date < $start ) {
				continue;
			}

			if ( $this->exceeds_limits( $occurrence_date, $until, $horizon ) ) {
				$should_break = true;
				break;
			}

			$dates[] = $occurrence_date;
			++$count;
		}

		return array(
			'dates' => $dates,
			'count' => $count,
			'break' => $should_break,
		);
	}

	/**
	 * Generate monthly occurrences.
	 *
	 * @param \DateTimeInterface $start   Start date.
	 * @param RecurrenceRule     $rule    Rule.
	 * @param \DateTimeInterface $horizon End horizon.
	 * @return array<\DateTimeImmutable>
	 */
	private function generate_monthly(
		\DateTimeInterface $start,
		RecurrenceRule $rule,
		\DateTimeInterface $horizon
	): array {
		$dates   = array();
		$current = \DateTimeImmutable::createFromInterface( $start );
		$count   = 0;
		$max     = $rule->count ?? self::EXPANSION_HARD_CAP;
		$until   = $rule->until ?? $horizon;

		// Determine the type of monthly recurrence.
		$use_by_day = ! empty( $rule->by_day ) && ! empty( $rule->by_set_pos );

		// Start from beginning of start month.
		$month_start = $current->modify( 'first day of this month' )->setTime( 0, 0, 0 );

		while ( $count < $max && ! $this->exceeds_limits( $month_start, $until, $horizon ) ) {
			$result = $use_by_day
				? $this->generate_monthly_by_weekday( $month_start, $rule, $start, $until, $horizon, $count, $max )
				: $this->generate_monthly_by_day( $month_start, $rule, $current, $start, $until, $horizon, $count, $max );

			$dates = array_merge( $dates, $result['dates'] );
			$count = $result['count'];

			if ( $result['break'] ) {
				break;
			}

			// Move to next month (or interval of months).
			$month_start = $month_start->modify( "+{$rule->interval} months" );
		}

		// Sort by date.
		usort( $dates, fn( $a, $b ) => $a <=> $b );

		return $dates;
	}

	/**
	 * Generate monthly dates by weekday position (e.g., first Monday).
	 *
	 * @param \DateTimeImmutable $month_start First day of month.
	 * @param RecurrenceRule     $rule        Recurrence rule.
	 * @param \DateTimeInterface $start       Original start date.
	 * @param \DateTimeInterface $until       Until limit.
	 * @param \DateTimeInterface $horizon     Horizon limit.
	 * @param int                $count       Current count.
	 * @param int                $max         Maximum count.
	 * @return array{dates: array<\DateTimeImmutable>, count: int, break: bool}
	 */
	private function generate_monthly_by_weekday(
		\DateTimeImmutable $month_start,
		RecurrenceRule $rule,
		\DateTimeInterface $start,
		\DateTimeInterface $until,
		\DateTimeInterface $horizon,
		int $count,
		int $max
	): array {
		$dates        = array();
		$should_break = false;

		foreach ( $rule->by_day as $day ) {
			foreach ( $rule->by_set_pos as $pos ) {
				if ( $count >= $max ) {
					$should_break = true;
					break 2;
				}

				$occurrence_date = $this->get_nth_weekday_of_month( $month_start, $day, $pos );
				if ( null === $occurrence_date ) {
					continue;
				}

				$occurrence_date = $this->apply_time_from( $occurrence_date, $start );

				if ( $occurrence_date < $start ) {
					continue;
				}

				if ( $this->exceeds_limits( $occurrence_date, $until, $horizon ) ) {
					$should_break = true;
					break 2;
				}

				$dates[] = $occurrence_date;
				++$count;
			}
		}

		return array(
			'dates' => $dates,
			'count' => $count,
			'break' => $should_break,
		);
	}

	/**
	 * Generate monthly dates by day number.
	 *
	 * @param \DateTimeImmutable $month_start First day of month.
	 * @param RecurrenceRule     $rule        Recurrence rule.
	 * @param \DateTimeImmutable $current     Current date for default day.
	 * @param \DateTimeInterface $start       Original start date.
	 * @param \DateTimeInterface $until       Until limit.
	 * @param \DateTimeInterface $horizon     Horizon limit.
	 * @param int                $count       Current count.
	 * @param int                $max         Maximum count.
	 * @return array{dates: array<\DateTimeImmutable>, count: int, break: bool}
	 */
	private function generate_monthly_by_day(
		\DateTimeImmutable $month_start,
		RecurrenceRule $rule,
		\DateTimeImmutable $current,
		\DateTimeInterface $start,
		\DateTimeInterface $until,
		\DateTimeInterface $horizon,
		int $count,
		int $max
	): array {
		$dates        = array();
		$should_break = false;

		$by_month_day = $rule->by_month_day;
		if ( empty( $by_month_day ) ) {
			$by_month_day = array( (int) $current->format( 'j' ) );
		}

		foreach ( $by_month_day as $day ) {
			if ( $count >= $max ) {
				$should_break = true;
				break;
			}

			$occurrence_date = $this->get_day_of_month( $month_start, $day );
			if ( null === $occurrence_date ) {
				continue;
			}

			$occurrence_date = $this->apply_time_from( $occurrence_date, $start );

			if ( $occurrence_date < $start ) {
				continue;
			}

			if ( $this->exceeds_limits( $occurrence_date, $until, $horizon ) ) {
				$should_break = true;
				break;
			}

			$dates[] = $occurrence_date;
			++$count;
		}

		return array(
			'dates' => $dates,
			'count' => $count,
			'break' => $should_break,
		);
	}

	/**
	 * Generate yearly occurrences.
	 *
	 * @param \DateTimeInterface $start   Start date.
	 * @param RecurrenceRule     $rule    Rule.
	 * @param \DateTimeInterface $horizon End horizon.
	 * @return array<\DateTimeImmutable>
	 */
	private function generate_yearly(
		\DateTimeInterface $start,
		RecurrenceRule $rule,
		\DateTimeInterface $horizon
	): array {
		$count = 0;
		$max   = $rule->count ?? self::EXPANSION_HARD_CAP;
		$until = $rule->until ?? $horizon;

		// Without BYMONTH, recur once per year on the start date.
		if ( empty( $rule->by_month ) ) {
			$dates   = array();
			$current = \DateTimeImmutable::createFromInterface( $start );

			while ( $count < $max && $current <= $until && $current <= $horizon ) {
				$dates[] = $current;
				++$count;
				$current = $current->modify( "+{$rule->interval} years" );
			}

			return $dates;
		}

		// With BYMONTH, expand to each listed month per year, keeping the start
		// date's day-of-month and time. Months that lack that day (e.g. day 31 in
		// February) are skipped, matching the monthly-by-day behavior.
		$dates     = array();
		$start_day = (int) $start->format( 'j' );
		$months    = $rule->by_month;
		sort( $months );

		$year = (int) $start->format( 'Y' );

		while ( $count < $max ) {
			$year_start = \DateTimeImmutable::createFromInterface( $start )
				->setDate( $year, 1, 1 )
				->setTime( 0, 0, 0 );
			if ( $year_start > $horizon || $year_start > $until ) {
				break;
			}

			foreach ( $months as $month ) {
				if ( $count >= $max ) {
					break;
				}
				if ( $month < 1 || $month > 12 ) {
					continue;
				}

				$month_start     = \DateTimeImmutable::createFromInterface( $start )
					->setDate( $year, $month, 1 )
					->setTime( 0, 0, 0 );
				$occurrence_date = $this->get_day_of_month( $month_start, $start_day );
				if ( null === $occurrence_date ) {
					continue;
				}

				$occurrence_date = $this->apply_time_from( $occurrence_date, $start );

				if ( $occurrence_date < $start ) {
					continue;
				}

				// Months ascend within a year and years ascend, so once a date
				// exceeds the limits no later date qualifies.
				if ( $this->exceeds_limits( $occurrence_date, $until, $horizon ) ) {
					break 2;
				}

				$dates[] = $occurrence_date;
				++$count;
			}

			$year += $rule->interval;
		}

		return $dates;
	}

	/**
	 * Get the start of the week containing a date.
	 *
	 * @param \DateTimeInterface $date Date.
	 * @param string             $wkst Week start day.
	 * @return \DateTimeImmutable
	 */
	private function get_week_start( \DateTimeInterface $date, string $wkst ): \DateTimeImmutable {
		$current     = \DateTimeImmutable::createFromInterface( $date )->setTime( 0, 0, 0 );
		$current_day = (int) $current->format( 'w' );
		$wkst_day    = RecurrenceRule::RRULE_DAY_MAP[ $wkst ];

		$diff = ( $current_day - $wkst_day + 7 ) % 7;
		return $current->modify( "-{$diff} days" );
	}

	/**
	 * Get day offset from week start.
	 *
	 * @param string $day  Target day.
	 * @param string $wkst Week start day.
	 * @return int
	 */
	private function get_day_offset( string $day, string $wkst ): int {
		$target = RecurrenceRule::RRULE_DAY_MAP[ $day ];
		$start  = RecurrenceRule::RRULE_DAY_MAP[ $wkst ];
		return ( $target - $start + 7 ) % 7;
	}

	/**
	 * Get the Nth weekday of a month.
	 *
	 * @param \DateTimeInterface $month_start First day of month.
	 * @param string             $day         Target day (MO, TU, etc).
	 * @param int                $position    Position (1-5 or -1 for last).
	 * @return \DateTimeImmutable|null
	 */
	private function get_nth_weekday_of_month(
		\DateTimeInterface $month_start,
		string $day,
		int $position
	): ?\DateTimeImmutable {
		$target_day = RecurrenceRule::RRULE_DAY_MAP[ $day ];
		$month      = $month_start->format( 'Y-m' );

		if ( $position > 0 ) {
			// Find Nth occurrence from start.
			$current = \DateTimeImmutable::createFromInterface( $month_start );
			$found   = 0;

			while ( $current->format( 'Y-m' ) === $month ) {
				if ( (int) $current->format( 'w' ) === $target_day ) {
					++$found;
					if ( $found === $position ) {
						return $current;
					}
				}
				$current = $current->modify( '+1 day' );
			}
		} else {
			// Find from end (e.g., -1 = last, -2 = second to last).
			$last_day        = \DateTimeImmutable::createFromInterface( $month_start )
				->modify( 'last day of this month' );
			$current         = $last_day;
			$found           = 0;
			$target_position = abs( $position );

			while ( $current->format( 'Y-m' ) === $month ) {
				if ( (int) $current->format( 'w' ) === $target_day ) {
					++$found;
					if ( $found === $target_position ) {
						return $current;
					}
				}
				$current = $current->modify( '-1 day' );
			}
		}

		return null;
	}

	/**
	 * Get a specific day of a month.
	 *
	 * @param \DateTimeInterface $month_start First day of month.
	 * @param int                $day         Day number (1-31 or negative from end).
	 * @return \DateTimeImmutable|null
	 */
	private function get_day_of_month(
		\DateTimeInterface $month_start,
		int $day
	): ?\DateTimeImmutable {
		$days_in_month = (int) $month_start->format( 't' );

		if ( $day > 0 ) {
			if ( $day > $days_in_month ) {
				return null;
			}
			return \DateTimeImmutable::createFromInterface( $month_start )
				->modify( '+' . ( $day - 1 ) . ' days' );
		} else {
			// Negative = from end (-1 = last day).
			$target = $days_in_month + $day + 1;
			if ( $target < 1 ) {
				return null;
			}
			return \DateTimeImmutable::createFromInterface( $month_start )
				->modify( '+' . ( $target - 1 ) . ' days' );
		}
	}

	/**
	 * Get the occurrence horizon from settings.
	 *
	 * @return int Days.
	 */
	private function get_horizon_days(): int {
		return \NetterTechEvents\Core\NetterTechEventsSettings::from_option()->performance->occurrence_horizon;
	}

	/**
	 * Apply time from a source date to a target date.
	 *
	 * @param \DateTimeImmutable $target Target date to modify.
	 * @param \DateTimeInterface $source Source date with time to copy.
	 * @return \DateTimeImmutable
	 */
	private function apply_time_from( \DateTimeImmutable $target, \DateTimeInterface $source ): \DateTimeImmutable {
		return $target->setTime(
			(int) $source->format( 'H' ),
			(int) $source->format( 'i' ),
			(int) $source->format( 's' )
		);
	}

	/**
	 * Check if a date exceeds the generation limits.
	 *
	 * @param \DateTimeInterface $date    Date to check.
	 * @param \DateTimeInterface $until   Until limit from rule.
	 * @param \DateTimeInterface $horizon Horizon limit.
	 * @return bool True if date exceeds limits.
	 */
	private function exceeds_limits(
		\DateTimeInterface $date,
		\DateTimeInterface $until,
		\DateTimeInterface $horizon
	): bool {
		return $date > $until || $date > $horizon;
	}
}
