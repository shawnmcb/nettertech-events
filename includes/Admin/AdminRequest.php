<?php
/**
 * Admin display-state request helper.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Centralized boundary for reading bookmarkable read-only admin URL state.
 *
 * The WordPress admin uses bookmarkable URL parameters for list-table filters,
 * search, sort, and pagination (`?status=draft&orderby=name&order=asc&paged=2&s=foo`).
 * WordPress core itself reads these in `WP_Posts_List_Table`, `WP_Comments_List_Table`,
 * etc. without nonce verification — by convention, read-only display state that
 * mutates no persistent data does not require a nonce. PHPCS still emits the
 * `NonceVerification.Recommended` warning for any direct `$_GET` read in this
 * context.
 *
 * This class wraps the unavoidable superglobal read into one narrow, audited
 * boundary so that consumers (list tables, admin pages) make no direct
 * superglobal access. The single suppression lives in {@see self::get_request()};
 * every call site uses typed accessors that sanitize and allowlist values
 * appropriately for display.
 *
 * Boundary contract:
 *  - Read-only: this helper never returns data that is then written to the
 *    database. Mutating handlers must verify nonces inline at their own
 *    entry points and not rely on this helper.
 *  - Capability-gated: admin pages that consume this helper are themselves
 *    gated by `current_user_can()` checks before render. This helper does not
 *    re-check capability.
 *  - Sanitized on the way out: every accessor applies a sanitizer
 *    (`sanitize_text_field`, `sanitize_key`, `absint`) plus an allowlist
 *    where the caller provides one.
 *
 * @since 1.0.2
 */
final class AdminRequest {

	/**
	 * Read the unslashed $_GET array.
	 *
	 * Single audited boundary for read-only admin display-state superglobal
	 * access. See class docblock for the contract.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_request(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display-state boundary (list-table filters, search, sort, pagination). Per WP convention (cf. WP_Posts_List_Table), bookmarkable display state is not nonce-protected. Sanitized on the way out via this class's typed accessors. Mutating handlers verify nonces inline at their own entry points.
		return isset( $_GET ) && is_array( $_GET ) ? wp_unslash( $_GET ) : array();
	}

	/**
	 * Get a sanitized text field from $_GET.
	 *
	 * @param string $key     Parameter name.
	 * @param string $fallback Default value when absent or non-string.
	 * @return string
	 */
	public static function get_text( string $key, string $fallback = '' ): string {
		$raw = self::get_request()[ $key ] ?? null;
		return is_string( $raw ) ? sanitize_text_field( $raw ) : $fallback;
	}

	/**
	 * Get a sanitized key (lowercase, dashes/underscores) from $_GET.
	 *
	 * @param string $key     Parameter name.
	 * @param string $fallback Default value when absent or non-string.
	 * @return string
	 */
	public static function get_key( string $key, string $fallback = '' ): string {
		$raw = self::get_request()[ $key ] ?? null;
		return is_string( $raw ) ? sanitize_key( $raw ) : $fallback;
	}

	/**
	 * Get a non-negative integer from $_GET.
	 *
	 * @param string $key     Parameter name.
	 * @param int    $fallback Default value when absent or non-numeric.
	 * @return int
	 */
	public static function get_absint( string $key, int $fallback = 0 ): int {
		$raw = self::get_request()[ $key ] ?? null;
		return is_numeric( $raw ) ? absint( $raw ) : $fallback;
	}

	/**
	 * Get an allowlisted orderby key from $_GET.
	 *
	 * Returns $fallback unless the GET param matches one of $allowed.
	 *
	 * @param string        $fallback Fallback value when absent or not allowlisted.
	 * @param array<string> $allowed Allowed orderby keys.
	 * @return string
	 */
	public static function get_orderby( string $fallback, array $allowed ): string {
		$raw = self::get_request()['orderby'] ?? null;
		$val = is_string( $raw ) ? sanitize_key( $raw ) : $fallback;
		return in_array( $val, $allowed, true ) ? $val : $fallback;
	}

	/**
	 * Get an allowlisted order direction ('asc' or 'desc') from $_GET.
	 *
	 * @param string $fallback Fallback value ('asc' or 'desc').
	 * @return string
	 */
	public static function get_order( string $fallback = 'asc' ): string {
		$raw = self::get_request()['order'] ?? null;
		$val = is_string( $raw ) ? strtolower( sanitize_key( $raw ) ) : $fallback;
		return in_array( $val, array( 'asc', 'desc' ), true ) ? $val : $fallback;
	}

	/**
	 * Test whether a $_GET key is set.
	 *
	 * @param string $key Parameter name.
	 * @return bool
	 */
	public static function has( string $key ): bool {
		return array_key_exists( $key, self::get_request() );
	}
}
