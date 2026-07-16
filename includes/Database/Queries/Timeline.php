<?php
/**
 * SQL predicates for "has this occurrence happened yet".
 *
 * @package NetterTechEvents\Database\Queries
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Queries;

/**
 * The one place that knows how the database answers "is it over".
 *
 * Every listing, filter and sort in the plugin asks some version of this question, and before
 * this class each asked it in its own words — comparing a *wall-clock* column against the
 * *site's* clock. That is wrong the moment an occurrence is in a different zone from the site:
 * a Sydney show read against a Chicago clock is judged fifteen hours out. It is the same defect
 * as NTE-148, and it does not announce itself — the calendar simply shows the wrong things.
 *
 * The answer lives in `end_utc` / `start_utc`: real instants, derived from the wall-clock and
 * the occurrence's own zone at the storage boundary (Occurrence::to_array()), compared against
 * UTC now. No zone arithmetic happens in SQL, because SQL cannot do it — CONVERT_TZ() with a
 * named zone needs MySQL's time_zone tables, which ship empty, and returns NULL when they are.
 *
 * **Null tolerance is deliberate.** A row whose instant was never stamped — written by direct
 * SQL, or by a migration that could not read its wall-clock — must still *appear*. A predicate
 * that quietly drops it would empty a calendar without saying so, which is precisely the failure
 * these columns exist to avoid. So an unstamped row is treated as not-yet-ended: it shows up,
 * where someone can see it is wrong, instead of disappearing where no one can.
 *
 * The predicates are constants rather than assembled strings because `wpdb::prepare()` requires
 * a literal, and because a predicate you can only select — never build — is one no caller can
 * quietly get wrong.
 *
 * @since 1.1.2
 */
final class Timeline {

	/**
	 * Not yet ended, for a query that joins occurrences as `o`.
	 */
	private const NOT_ENDED_ALIASED = '(o.end_utc IS NULL OR o.end_utc >= %s)';

	/**
	 * Not yet ended, for a query with the occurrences table unaliased.
	 */
	private const NOT_ENDED_BARE = '(end_utc IS NULL OR end_utc >= %s)';

	/**
	 * Over, for a query that joins occurrences as `o`.
	 */
	private const ENDED_ALIASED = '(o.end_utc IS NOT NULL AND o.end_utc < %s)';

	/**
	 * Over, for a query with the occurrences table unaliased.
	 */
	private const ENDED_BARE = '(end_utc IS NOT NULL AND end_utc < %s)';

	/**
	 * The occurrence has not ended yet — it is upcoming, or under way right now.
	 *
	 * Bind the value from `now()` to the single placeholder.
	 *
	 * @param bool $aliased Whether the occurrences table is joined as `o`.
	 * @return literal-string SQL fragment with one %s placeholder.
	 */
	public static function not_ended( bool $aliased = true ): string {
		return $aliased ? self::NOT_ENDED_ALIASED : self::NOT_ENDED_BARE;
	}

	/**
	 * The occurrence is over.
	 *
	 * An unstamped row is not reported as past: we would rather show something that has
	 * finished than hide something that has not.
	 *
	 * @param bool $aliased Whether the occurrences table is joined as `o`.
	 * @return literal-string SQL fragment with one %s placeholder.
	 */
	public static function ended( bool $aliased = true ): string {
		return $aliased ? self::ENDED_ALIASED : self::ENDED_BARE;
	}

	/**
	 * Starts at or after a bound, tolerating an unstamped row.
	 *
	 * @param bool $aliased Whether the occurrences table is joined as `o`.
	 * @return literal-string SQL fragment with one %s placeholder.
	 */
	public static function starts_from( bool $aliased = true ): string {
		return $aliased
			? '(o.start_utc IS NULL OR o.start_utc >= %s)'
			: '(start_utc IS NULL OR start_utc >= %s)';
	}

	/**
	 * Starts at or before a bound, tolerating an unstamped row.
	 *
	 * @param bool $aliased Whether the occurrences table is joined as `o`.
	 * @return literal-string SQL fragment with one %s placeholder.
	 */
	public static function starts_until( bool $aliased = true ): string {
		return $aliased
			? '(o.start_utc IS NULL OR o.start_utc <= %s)'
			: '(start_utc IS NULL OR start_utc <= %s)';
	}

	/**
	 * Now, as the database stores an instant.
	 *
	 * Not `current_time('mysql')` — that is the *site's* wall-clock, and comparing it against a
	 * UTC column is how this bug is reintroduced.
	 *
	 * @return string
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Read a wall-clock bound in a zone and render the instant it names, in UTC.
	 *
	 * Callers hand this a window an operator expressed in local terms ("June", "from today")
	 * so it can be compared against the stored instants.
	 *
	 * @param string             $wall_clock Datetime as 'Y-m-d H:i:s'.
	 * @param \DateTimeZone|null $zone       Zone the wall-clock is meant in. Defaults to the site's.
	 * @return string|null The UTC datetime, or null when the value cannot be read.
	 */
	public static function to_utc( string $wall_clock, ?\DateTimeZone $zone = null ): ?string {
		if ( '' === $wall_clock ) {
			return null;
		}

		try {
			return ( new \DateTimeImmutable( $wall_clock, $zone ?? wp_timezone() ) )
				->setTimezone( new \DateTimeZone( 'UTC' ) )
				->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			unset( $e );
			return null;
		}
	}
}
