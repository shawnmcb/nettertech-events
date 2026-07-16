<?php
/**
 * Beaver Themer current-page context detector.
 *
 * @package NetterTechEvents\Integrations\BeaverBuilder\Themer
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\BeaverBuilder\Themer;

defined( 'ABSPATH' ) || exit;

/**
 * Detects which NetterTech Events virtual context (if any) the current request
 * resolves to. NTE serves events and spaces via custom rewrite rules registered
 * by Frontend\Router, not as native WordPress post-type archives or singulars,
 * so Beaver Themer's own location matcher cannot see them. This class is the
 * authoritative source of truth for "which NTE context is rendering right now?"
 * consumed by LayoutAssignments and LayoutInjector.
 *
 * @since 1.1.0
 */
class Context {

	public const EVENTS_ARCHIVE      = 'events_archive';
	public const PAST_EVENTS_ARCHIVE = 'past_events_archive';
	public const SINGLE_EVENT        = 'single_event';
	public const SINGLE_OCCURRENCE   = 'single_occurrence';
	public const SINGLE_SPACE        = 'single_space';

	/**
	 * All recognized context keys, in display order.
	 *
	 * @var array<int, string>
	 */
	public const ALL_KEYS = array(
		self::EVENTS_ARCHIVE,
		self::PAST_EVENTS_ARCHIVE,
		self::SINGLE_EVENT,
		self::SINGLE_OCCURRENCE,
		self::SINGLE_SPACE,
	);

	/**
	 * Human-readable label for a context key, suitable for admin UI.
	 *
	 * @param string $key Context key.
	 * @return string Localized label, or the key itself if unknown.
	 */
	public static function label( string $key ): string {
		$labels = array(
			self::EVENTS_ARCHIVE      => __( 'Events archive (upcoming)', 'nettertech-events' ),
			self::PAST_EVENTS_ARCHIVE => __( 'Events archive (past)', 'nettertech-events' ),
			self::SINGLE_EVENT        => __( 'Single event / series page', 'nettertech-events' ),
			self::SINGLE_OCCURRENCE   => __( 'Single occurrence (recurring)', 'nettertech-events' ),
			self::SINGLE_SPACE        => __( 'Single space', 'nettertech-events' ),
		);

		return $labels[ $key ] ?? $key;
	}

	/**
	 * Detect the NTE context for the current request.
	 *
	 * Resolution order follows route specificity: past archive (a sub-path of
	 * the events base) before plain archive; single occurrence (event slug +
	 * datetime) before single event (event slug alone).
	 *
	 * @return string|null One of the self::* constants, or null if no NTE context matches.
	 */
	public static function detect(): ?string {
		if ( self::query_var_set( 'nettertech_events_past_archive' ) ) {
			return self::PAST_EVENTS_ARCHIVE;
		}

		if ( self::query_var_set( 'nettertech_events_archive' ) ) {
			return self::EVENTS_ARCHIVE;
		}

		$event_slug = (string) get_query_var( 'nettertech_events_event_slug', '' );
		if ( '' !== $event_slug ) {
			$occurrence_datetime = (string) get_query_var( 'nettertech_events_occurrence_datetime', '' );

			return '' !== $occurrence_datetime ? self::SINGLE_OCCURRENCE : self::SINGLE_EVENT;
		}

		$space_slug = (string) get_query_var( 'nettertech_events_space_slug', '' );
		if ( '' !== $space_slug ) {
			return self::SINGLE_SPACE;
		}

		return null;
	}

	/**
	 * Whether a boolean-ish query var is considered "set" by NTE's router.
	 *
	 * Router writes integers (e.g. `nte_archive=1`) via add_rewrite_rule, so
	 * the safe check is non-empty + non-zero rather than `!== ''`.
	 *
	 * @param string $query_var Query var name.
	 * @return bool
	 */
	private static function query_var_set( string $query_var ): bool {
		$value = get_query_var( $query_var, '' );

		return ! empty( $value ) && '0' !== (string) $value;
	}
}
