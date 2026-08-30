<?php
/**
 * Attendee list filtering by "has accessibility notes".
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Attendees;

/**
 * The one SQL definition of "this attendee told us about an accessibility
 * need", shared by the Attendees list page and "Export All" so the badge, the
 * filter and the export agree (NTE-217).
 *
 * @since 1.4.5
 */
final class AccessibilityNotesFilter {

	/**
	 * Request parameter name (GET on the list, hidden POST field for Export All).
	 */
	public const PARAM = 'accessibility';

	/**
	 * Filter value meaning "only attendees with notes".
	 */
	public const FILTER_HAS_NOTES = 'yes';

	/**
	 * Filter value meaning "only attendees without notes".
	 */
	public const FILTER_NO_NOTES = 'no';

	/**
	 * Whether a request value is one this filter understands.
	 *
	 * @param string $value Raw filter value.
	 * @return bool
	 */
	public static function is_valid( string $value ): bool {
		return in_array( $value, array( self::FILTER_HAS_NOTES, self::FILTER_NO_NOTES ), true );
	}

	/**
	 * WHERE predicate for the given filter value, or null when no filter applies.
	 *
	 * NULL and '' both mean "nothing entered": the column predates the shared
	 * sanitizer, so older rows may hold empty strings.
	 *
	 * @param string $value          Filter value ('yes' | 'no' | anything else = no filter).
	 * @param string $attendee_alias Alias of the attendees table in the query.
	 * @return string|null
	 */
	public static function predicate( string $value, string $attendee_alias = 'a' ): ?string {
		$col = $attendee_alias . '.accessibility_notes';

		if ( self::FILTER_HAS_NOTES === $value ) {
			return "({$col} IS NOT NULL AND {$col} <> '')";
		}

		if ( self::FILTER_NO_NOTES === $value ) {
			return "({$col} IS NULL OR {$col} = '')";
		}

		return null;
	}

	/**
	 * Whether a stored value counts as "has notes" — the PHP twin of predicate().
	 *
	 * @param mixed $stored Raw column value.
	 * @return bool
	 */
	public static function has_notes( mixed $stored ): bool {
		return is_string( $stored ) && '' !== trim( $stored );
	}
}
