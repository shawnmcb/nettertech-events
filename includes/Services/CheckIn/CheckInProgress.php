<?php
/**
 * Check-in progress for one occurrence.
 *
 * @package NetterTechEvents\Services\CheckIn
 */

declare(strict_types=1);

namespace NetterTechEvents\Services\CheckIn;

defined( 'ABSPATH' ) || exit;

/**
 * How far through the door an occurrence is: checked-in admissions over eligible admissions.
 *
 * Every check-in screen shows this figure, and each used to derive it on its own
 * (a PHP round() here, a DOM recount there). The rounding and completion rules
 * live here so the three screens and their refresh payloads cannot disagree.
 *
 * The unit is the admission, not the purchase record: one attendee row carries
 * `quantity` admissions, and the repository caps `checked_in_count` at that
 * quantity, so a duplicate scan cannot push the numerator past the denominator.
 * Callers pass the occurrence totals from AttendeeCheckInInterface::get_check_in_stats(),
 * which counts confirmed rows only — the same rows the check-in controls accept.
 *
 * @since 1.5.3
 */
final class CheckInProgress {

	/**
	 * Eligible admissions for the occurrence.
	 *
	 * @var int
	 */
	private int $eligible;

	/**
	 * Admissions checked in, clamped to [0, eligible].
	 *
	 * @var int
	 */
	private int $checked_in;

	/**
	 * Constructor.
	 *
	 * @param int $eligible   Eligible admissions.
	 * @param int $checked_in Admissions checked in.
	 */
	public function __construct( int $eligible, int $checked_in ) {
		$this->eligible   = max( 0, $eligible );
		$this->checked_in = min( max( 0, $checked_in ), $this->eligible );
	}

	/**
	 * Build from the stats array returned by get_check_in_stats().
	 *
	 * @param array<string, int> $stats Occurrence check-in stats.
	 * @return self
	 */
	public static function from_stats( array $stats ): self {
		return new self( (int) ( $stats['total_guests'] ?? 0 ), (int) ( $stats['checked_in_guests'] ?? 0 ) );
	}

	/**
	 * Eligible admissions.
	 *
	 * @return int
	 */
	public function eligible(): int {
		return $this->eligible;
	}

	/**
	 * Admissions checked in.
	 *
	 * @return int
	 */
	public function checked_in(): int {
		return $this->checked_in;
	}

	/**
	 * Whether there is nobody to check in.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return 0 === $this->eligible;
	}

	/**
	 * Whether every eligible admission is checked in.
	 *
	 * Decided on the counts, never on the rounded percentage: 199 of 200 rounds
	 * to 100 but leaves one person outside.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return ! $this->is_empty() && $this->checked_in === $this->eligible;
	}

	/**
	 * Whole-number percentage, or null when there is nobody to check in.
	 *
	 * Held to 1–99 while the door is part-way through, so 1 of 500 does not read
	 * as a genuine 0% and 199 of 200 does not read as done. 0 and 100 mean exactly
	 * nobody and everybody.
	 *
	 * @return int|null
	 */
	public function percent(): ?int {
		if ( $this->is_empty() ) {
			return null;
		}

		if ( 0 === $this->checked_in ) {
			return 0;
		}

		if ( $this->is_complete() ) {
			return 100;
		}

		$rounded = (int) round( ( $this->checked_in / $this->eligible ) * 100 );

		return min( 99, max( 1, $rounded ) );
	}

	/**
	 * The visible percentage label.
	 *
	 * @return string "75%", or an em dash when there is nobody to check in.
	 */
	public function percent_label(): string {
		$percent = $this->percent();

		if ( null === $percent ) {
			return _x( '—', 'check-in percentage unavailable', 'nettertech-events' );
		}

		/* translators: %d: whole-number percentage of ticket holders checked in. */
		return sprintf( __( '%d%%', 'nettertech-events' ), $percent );
	}

	/**
	 * The accessible description, e.g. "75% checked in, 150 of 200 ticket holders".
	 *
	 * @return string
	 */
	public function description(): string {
		if ( $this->is_empty() ) {
			return __( 'No ticket holders', 'nettertech-events' );
		}

		return sprintf(
			/* translators: 1: whole-number percentage, 2: ticket holders checked in, 3: total ticket holders. */
			_n(
				'%1$d%% checked in, %2$d of %3$d ticket holder',
				'%1$d%% checked in, %2$d of %3$d ticket holders',
				$this->eligible,
				'nettertech-events'
			),
			(int) $this->percent(),
			$this->checked_in,
			$this->eligible
		);
	}

	/**
	 * The payload a check-in screen needs to redraw its progress row.
	 *
	 * Sent with every check-in response so the client renders server figures
	 * and never counts rows itself.
	 *
	 * @return array{eligible: int, checkedIn: int, percent: int|null, complete: bool, empty: bool, percentLabel: string, description: string}
	 */
	public function to_array(): array {
		return array(
			'eligible'     => $this->eligible,
			'checkedIn'    => $this->checked_in,
			'percent'      => $this->percent(),
			'complete'     => $this->is_complete(),
			'empty'        => $this->is_empty(),
			'percentLabel' => $this->percent_label(),
			'description'  => $this->description(),
		);
	}
}
