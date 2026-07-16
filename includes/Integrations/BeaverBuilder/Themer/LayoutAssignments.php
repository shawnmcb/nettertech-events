<?php
/**
 * Themer layout assignment storage.
 *
 * @package NetterTechEvents\Integrations\BeaverBuilder\Themer
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\BeaverBuilder\Themer;

defined( 'ABSPATH' ) || exit;

/**
 * Option-backed store mapping NTE virtual contexts to Beaver Themer layouts.
 *
 * Shape persisted to {@see self::OPTION_KEY}:
 *
 * <code>
 * array(
 *     'events_archive' => array( 'header' => 123, 'footer' => 456 ),
 *     'single_event'   => array( 'header' => 0,   'footer' => 0   ),
 *     // ...one entry per Context::ALL_KEYS member.
 * )
 * </code>
 *
 * Layout IDs are positive `fl-theme-layout` post IDs. A value of `0` (or a
 * missing slot) means "no layout assigned" — the request falls through to
 * Beaver Themer's own location matching or the active theme's default.
 *
 * All inputs are normalised through {@see self::sanitize()} before persisting:
 * keys are restricted to {@see Context::ALL_KEYS} and {@see self::SLOTS}, and
 * values are coerced to non-negative integers. This class makes no judgement
 * about whether the referenced layout post exists or is published — that
 * verification belongs to LayoutInjector at render time, which must tolerate
 * stale IDs without warning.
 *
 * @since 1.1.0
 */
class LayoutAssignments {

	/**
	 * WordPress option key.
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'nettertech_events_themer_assignments';

	/**
	 * Header slot identifier.
	 *
	 * @var string
	 */
	public const SLOT_HEADER = 'header';

	/**
	 * Footer slot identifier.
	 *
	 * @var string
	 */
	public const SLOT_FOOTER = 'footer';

	/**
	 * All recognised slot identifiers, in display order.
	 *
	 * @var array<int, string>
	 */
	public const SLOTS = array(
		self::SLOT_HEADER,
		self::SLOT_FOOTER,
	);

	/**
	 * Load the full assignments map from the option.
	 *
	 * Always returns a fully populated structure — every context key in
	 * {@see Context::ALL_KEYS} is present, with every slot in {@see self::SLOTS}
	 * defaulting to `0`. Callers can index without isset() guards.
	 *
	 * @return array<string, array<string, int>>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );

		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Persist a sanitised assignments map, replacing any previous value.
	 *
	 * Input is fully sanitised before write — unknown context keys and slot
	 * identifiers are stripped, and layout IDs are coerced to non-negative
	 * integers. Calling with an empty array effectively clears all assignments.
	 *
	 * @param array<string, array<string, int|string>> $assignments Raw input map.
	 * @return bool Whether `update_option` reports a change.
	 */
	public static function save( array $assignments ): bool {
		return (bool) update_option( self::OPTION_KEY, self::sanitize( $assignments ), false );
	}

	/**
	 * Normalise an arbitrary input array to the canonical assignments shape.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, array<string, int>>
	 */
	public static function sanitize( array $input ): array {
		$result = array();

		foreach ( Context::ALL_KEYS as $context_key ) {
			$result[ $context_key ] = array();

			foreach ( self::SLOTS as $slot ) {
				$raw = $input[ $context_key ][ $slot ] ?? 0;

				$result[ $context_key ][ $slot ] = max( 0, (int) $raw );
			}
		}

		return $result;
	}
}
