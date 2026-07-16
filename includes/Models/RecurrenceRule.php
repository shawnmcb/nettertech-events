<?php
/**
 * Recurrence rule value object.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Exceptions\RRuleException;

/**
 * Represents a parsed RFC 5545 RRULE.
 *
 * This is a value object that holds the components of a recurrence rule.
 * It does not perform parsing - that's handled by RRuleParser.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc5545#section-3.3.10
 *
 * @since 0.8.0
 * @api
 */
class RecurrenceRule {

	/**
	 * Frequency constants.
	 */
	public const FREQ_DAILY   = 'DAILY';
	public const FREQ_WEEKLY  = 'WEEKLY';
	public const FREQ_MONTHLY = 'MONTHLY';
	public const FREQ_YEARLY  = 'YEARLY';

	/**
	 * Valid frequencies.
	 *
	 * @var array<string>
	 */
	public const FREQUENCIES = array(
		self::FREQ_DAILY,
		self::FREQ_WEEKLY,
		self::FREQ_MONTHLY,
		self::FREQ_YEARLY,
	);

	/**
	 * Day constants.
	 */
	public const DAY_MO = 'MO';
	public const DAY_TU = 'TU';
	public const DAY_WE = 'WE';
	public const DAY_TH = 'TH';
	public const DAY_FR = 'FR';
	public const DAY_SA = 'SA';
	public const DAY_SU = 'SU';

	/**
	 * Valid days.
	 *
	 * @var array<string>
	 */
	public const DAYS = array(
		self::DAY_MO,
		self::DAY_TU,
		self::DAY_WE,
		self::DAY_TH,
		self::DAY_FR,
		self::DAY_SA,
		self::DAY_SU,
	);

	/**
	 * Map PHP day of week (0=Sun) to RRULE day.
	 *
	 * @var array<int, string>
	 */
	public const PHP_DAY_MAP = array(
		0 => self::DAY_SU,
		1 => self::DAY_MO,
		2 => self::DAY_TU,
		3 => self::DAY_WE,
		4 => self::DAY_TH,
		5 => self::DAY_FR,
		6 => self::DAY_SA,
	);

	/**
	 * Map RRULE day to PHP day of week.
	 *
	 * @var array<string, int>
	 */
	public const RRULE_DAY_MAP = array(
		self::DAY_SU => 0,
		self::DAY_MO => 1,
		self::DAY_TU => 2,
		self::DAY_WE => 3,
		self::DAY_TH => 4,
		self::DAY_FR => 5,
		self::DAY_SA => 6,
	);

	/**
	 * Recurrence frequency.
	 *
	 * @var string One of FREQ_* constants
	 */
	public string $freq;

	/**
	 * Interval between occurrences.
	 *
	 * @var int Default 1
	 */
	public int $interval = 1;

	/**
	 * Number of occurrences (COUNT).
	 *
	 * @var int|null Null means no count limit
	 */
	public ?int $count = null;

	/**
	 * End date (UNTIL).
	 *
	 * @var \DateTimeImmutable|null Null means no end date
	 */
	public ?\DateTimeImmutable $until = null;

	/**
	 * Days of the week (BYDAY).
	 *
	 * @var array<string> Array of DAY_* constants
	 */
	public array $by_day = array();

	/**
	 * Days of the month (BYMONTHDAY).
	 *
	 * @var array<int> 1-31 or -31 to -1
	 */
	public array $by_month_day = array();

	/**
	 * Months (BYMONTH).
	 *
	 * @var array<int> 1-12
	 */
	public array $by_month = array();

	/**
	 * Week positions (BYSETPOS).
	 *
	 * @var array<int> 1-5 or -5 to -1
	 */
	public array $by_set_pos = array();

	/**
	 * Week start day (WKST).
	 *
	 * @var string Default MO
	 */
	public string $wkst = self::DAY_MO;

	/**
	 * Original RRULE string.
	 *
	 * @var string|null
	 */
	public ?string $original_rule = null;

	/**
	 * Constructor.
	 *
	 * @param string $freq Frequency.
	 * @throws RRuleException If frequency is invalid.
	 */
	public function __construct( string $freq ) {
		if ( ! in_array( $freq, self::FREQUENCIES, true ) ) {
			throw RRuleException::invalidComponent( 'FREQ', esc_html( $freq ), array_map( 'esc_html', self::FREQUENCIES ) );
		}
		$this->freq = $freq;
	}

	/**
	 * Create a daily rule.
	 *
	 * @param int $interval Every N days.
	 * @return self
	 */
	public static function daily( int $interval = 1 ): self {
		$rule           = new self( self::FREQ_DAILY );
		$rule->interval = max( 1, $interval );
		return $rule;
	}

	/**
	 * Create a weekly rule.
	 *
	 * @param int           $interval Every N weeks.
	 * @param array<string> $days     Days of week (DAY_* constants).
	 * @return self
	 */
	public static function weekly( int $interval = 1, array $days = array() ): self {
		$rule           = new self( self::FREQ_WEEKLY );
		$rule->interval = max( 1, $interval );
		$rule->by_day   = array_intersect( $days, self::DAYS );
		return $rule;
	}

	/**
	 * Create a monthly rule (by day of month).
	 *
	 * @param int        $interval  Every N months.
	 * @param array<int> $days      Days of month (1-31).
	 * @return self
	 */
	public static function monthly( int $interval = 1, array $days = array() ): self {
		$rule               = new self( self::FREQ_MONTHLY );
		$rule->interval     = max( 1, $interval );
		$rule->by_month_day = array_filter( $days, fn( $d ) => $d >= 1 && $d <= 31 );
		return $rule;
	}

	/**
	 * Create a monthly rule (by weekday, e.g., "first Monday").
	 *
	 * @param int    $interval  Every N months.
	 * @param string $day       Day of week (DAY_* constant).
	 * @param int    $position  Position (1-5 or -1 for last).
	 * @return self
	 */
	public static function monthly_by_day( int $interval, string $day, int $position ): self {
		$rule             = new self( self::FREQ_MONTHLY );
		$rule->interval   = max( 1, $interval );
		$rule->by_day     = array( $day );
		$rule->by_set_pos = array( $position );
		return $rule;
	}

	/**
	 * Create a yearly rule.
	 *
	 * @param int        $interval Every N years.
	 * @param array<int> $months   Months (1-12).
	 * @return self
	 */
	public static function yearly( int $interval = 1, array $months = array() ): self {
		$rule           = new self( self::FREQ_YEARLY );
		$rule->interval = max( 1, $interval );
		$rule->by_month = array_filter( $months, fn( $m ) => $m >= 1 && $m <= 12 );
		return $rule;
	}

	/**
	 * Set the count limit.
	 *
	 * @param int $count Number of occurrences.
	 * @return self
	 */
	public function with_count( int $count ): self {
		$clone        = clone $this;
		$clone->count = max( 1, $count );
		$clone->until = null; // COUNT and UNTIL are mutually exclusive.
		return $clone;
	}

	/**
	 * Set the until date.
	 *
	 * @param \DateTimeInterface $until End date.
	 * @return self
	 */
	public function with_until( \DateTimeInterface $until ): self {
		$clone        = clone $this;
		$clone->until = \DateTimeImmutable::createFromInterface( $until );
		$clone->count = null; // COUNT and UNTIL are mutually exclusive.
		return $clone;
	}

	/**
	 * Set the interval.
	 *
	 * @param int $interval Interval.
	 * @return self
	 */
	public function with_interval( int $interval ): self {
		$clone           = clone $this;
		$clone->interval = max( 1, $interval );
		return $clone;
	}

	/**
	 * Set the days of week.
	 *
	 * @param array<string> $days Days.
	 * @return self
	 */
	public function with_by_day( array $days ): self {
		$clone         = clone $this;
		$clone->by_day = array_intersect( $days, self::DAYS );
		return $clone;
	}

	/**
	 * Convert to RRULE string.
	 *
	 * @return string RFC 5545 RRULE string (without RRULE: prefix)
	 */
	public function to_string(): string {
		$parts = array( 'FREQ=' . $this->freq );

		if ( $this->interval > 1 ) {
			$parts[] = 'INTERVAL=' . $this->interval;
		}

		if ( null !== $this->count ) {
			$parts[] = 'COUNT=' . $this->count;
		}

		if ( null !== $this->until ) {
			$parts[] = 'UNTIL=' . $this->until->format( 'Ymd\THis\Z' );
		}

		if ( ! empty( $this->by_day ) ) {
			if ( ! empty( $this->by_set_pos ) && self::FREQ_MONTHLY === $this->freq ) {
				// For monthly by weekday, prepend position to day.
				$days_with_pos = array();
				foreach ( $this->by_day as $day ) {
					foreach ( $this->by_set_pos as $pos ) {
						$days_with_pos[] = $pos . $day;
					}
				}
				$parts[] = 'BYDAY=' . implode( ',', $days_with_pos );
			} else {
				$parts[] = 'BYDAY=' . implode( ',', $this->by_day );
			}
		}

		if ( ! empty( $this->by_month_day ) ) {
			$parts[] = 'BYMONTHDAY=' . implode( ',', $this->by_month_day );
		}

		if ( ! empty( $this->by_month ) ) {
			$parts[] = 'BYMONTH=' . implode( ',', $this->by_month );
		}

		if ( self::DAY_MO !== $this->wkst ) {
			$parts[] = 'WKST=' . $this->wkst;
		}

		return implode( ';', $parts );
	}

	/**
	 * Magic string conversion.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return $this->to_string();
	}

	/**
	 * Check if rule has an end condition.
	 *
	 * @return bool
	 */
	public function has_end(): bool {
		return null !== $this->count || null !== $this->until;
	}

	/**
	 * Get human-readable description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		$desc = '';

		switch ( $this->freq ) {
			case self::FREQ_DAILY:
				$desc = 1 === $this->interval
					? __( 'Every day', 'nettertech-events' )
					: sprintf(
						/* translators: %d: number of days */
						__( 'Every %d days', 'nettertech-events' ),
						(int) $this->interval
					);
				break;

			case self::FREQ_WEEKLY:
				if ( 1 === $this->interval ) {
					$desc = __( 'Every week', 'nettertech-events' );
				} else {
					$desc = sprintf(
						/* translators: %d: number of weeks */
						__( 'Every %d weeks', 'nettertech-events' ),
						(int) $this->interval
					);
				}
				if ( ! empty( $this->by_day ) ) {
					$day_names = array_map( array( $this, 'get_day_name' ), $this->by_day );
					$desc     .= ' ' . sprintf(
						/* translators: %s: day names */
						__( 'on %s', 'nettertech-events' ),
						esc_html( implode( ', ', $day_names ) )
					);
				}
				break;

			case self::FREQ_MONTHLY:
				if ( 1 === $this->interval ) {
					$desc = __( 'Every month', 'nettertech-events' );
				} else {
					$desc = sprintf(
						/* translators: %d: number of months */
						__( 'Every %d months', 'nettertech-events' ),
						(int) $this->interval
					);
				}
				if ( ! empty( $this->by_month_day ) ) {
					$desc .= ' ' . sprintf(
						/* translators: %s: day numbers */
						__( 'on day %s', 'nettertech-events' ),
						esc_html( implode( ', ', $this->by_month_day ) )
					);
				}
				break;

			case self::FREQ_YEARLY:
				$desc = 1 === $this->interval
					? __( 'Every year', 'nettertech-events' )
					: sprintf(
						/* translators: %d: number of years */
						__( 'Every %d years', 'nettertech-events' ),
						(int) $this->interval
					);
				break;
		}

		if ( null !== $this->count ) {
			$desc .= ', ' . sprintf(
				/* translators: %d: count of occurrences */
				__( '%d times', 'nettertech-events' ),
				(int) $this->count
			);
		} elseif ( null !== $this->until ) {
			$desc .= ', ' . sprintf(
				/* translators: %s: end date */
				__( 'until %s', 'nettertech-events' ),
				esc_html( $this->until->format( get_option( 'date_format' ) ) )
			);
		}

		return $desc;
	}

	/**
	 * Get day name from constant.
	 *
	 * @param string $day Day constant.
	 * @return string
	 */
	private function get_day_name( string $day ): string {
		$names = array(
			self::DAY_MO => __( 'Monday', 'nettertech-events' ),
			self::DAY_TU => __( 'Tuesday', 'nettertech-events' ),
			self::DAY_WE => __( 'Wednesday', 'nettertech-events' ),
			self::DAY_TH => __( 'Thursday', 'nettertech-events' ),
			self::DAY_FR => __( 'Friday', 'nettertech-events' ),
			self::DAY_SA => __( 'Saturday', 'nettertech-events' ),
			self::DAY_SU => __( 'Sunday', 'nettertech-events' ),
		);
		return $names[ $day ] ?? $day;
	}
}
