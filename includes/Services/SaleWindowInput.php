<?php
/**
 * Sale-window form-input composition.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Recombines split sale-window form fields into the legacy wire format.
 *
 * The admin ticket forms submit each boundary as separate `{key}_date` +
 * `{key}_time` parts (NTE-190); every save path recombines them here into the
 * exact `Y-m-d\TH:i` string the old datetime-local control produced, so
 * stored values are indistinguishable from legacy saves. Combined `{key}`
 * values remain honored for REST clients and extensions.
 *
 * @since 1.4.0
 */
final class SaleWindowInput {

	/**
	 * Default time for a start boundary given as date-only.
	 */
	public const DEFAULT_START_TIME = '00:00';

	/**
	 * Default time for an end boundary given as date-only.
	 */
	public const DEFAULT_END_TIME = '23:59';

	/**
	 * Compose a sale-window boundary from submitted form data.
	 *
	 * A date without a time uses $default_time; a time without a date is
	 * ignored (no boundary). Returns '' when the boundary is unset.
	 *
	 * @param array<string,mixed> $data         Submitted ticket data.
	 * @param string              $key          Boundary key: 'sale_start' or 'sale_end'.
	 * @param string              $default_time HH:MM used when only a date is given.
	 * @return string Legacy-format boundary string, or '' when unset.
	 */
	public static function compose( array $data, string $key, string $default_time ): string {
		$date = isset( $data[ $key . '_date' ] ) ? sanitize_text_field( (string) $data[ $key . '_date' ] ) : '';
		$time = isset( $data[ $key . '_time' ] ) ? sanitize_text_field( (string) $data[ $key . '_time' ] ) : '';

		if ( '' !== $date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			if ( '' === $time || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
				$time = $default_time;
			}
			return $date . 'T' . $time;
		}

		// Backwards compatibility: combined value from older forms/integrations.
		if ( ! empty( $data[ $key ] ) ) {
			return sanitize_text_field( (string) $data[ $key ] );
		}

		return '';
	}
}
