<?php
/**
 * Calendar routing service.
 *
 * Registers custom query vars for the calendar shortcode's
 * "show this month/date on initial render" parameters, and
 * 301-redirects legacy `?month=` / `?date=` URLs to the
 * prefixed query-var format.
 *
 * Replacing the prior `$_GET` reads in CalendarShortcode::get_initial_date()
 * lets the shortcode satisfy WordPress.Security.NonceVerification.Recommended
 * with zero suppressions: registered query vars are read via
 * get_query_var(), and the legacy-URL redirect uses filter_input() at
 * a documented routing boundary.
 *
 * @package NetterTechEvents\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Calendar URL routing — query vars and legacy-URL 301 redirect.
 *
 * @since 1.0.2
 */
class CalendarRouting {

	/**
	 * Prefixed query var for the initial month (format: YYYY-MM).
	 *
	 * @var string
	 */
	public const QUERY_VAR_MONTH = 'nettertech_events_calendar_month';

	/**
	 * Prefixed query var for the initial date (format: YYYY-MM-DD).
	 *
	 * @var string
	 */
	public const QUERY_VAR_DATE = 'nettertech_events_calendar_date';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_legacy_query' ), 1 );
	}

	/**
	 * Register the calendar's custom public query vars.
	 *
	 * @param array<string> $vars Existing query vars.
	 * @return array<string>
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR_MONTH;
		$vars[] = self::QUERY_VAR_DATE;
		return $vars;
	}

	/**
	 * Redirect legacy `?month=YYYY-MM` and `?date=YYYY-MM-DD` URLs to the
	 * prefixed query-var format so old bookmarks/shared links keep working.
	 *
	 * Only triggers on pages whose content contains the calendar shortcode,
	 * to avoid interfering with other plugins that may use `?month=`.
	 *
	 * Reads superglobals via filter_input() — the per-field input-sanitization
	 * call satisfies WordPress.Security.ValidatedSanitizedInput without a
	 * suppression. Strict regex validation rejects unexpected formats; only
	 * the validated, sanitized value is appended to the redirect URL.
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy_query(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! $this->current_page_has_calendar_shortcode() ) {
			return;
		}

		$new_args     = array();
		$legacy_month = (string) filter_input( INPUT_GET, 'month', FILTER_DEFAULT );
		$legacy_date  = (string) filter_input( INPUT_GET, 'date', FILTER_DEFAULT );

		if ( '' !== $legacy_month && preg_match( '/^\d{4}-\d{2}$/', $legacy_month ) ) {
			$new_args[ self::QUERY_VAR_MONTH ] = sanitize_text_field( $legacy_month );
		}

		if ( '' !== $legacy_date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $legacy_date ) ) {
			$new_args[ self::QUERY_VAR_DATE ] = sanitize_text_field( $legacy_date );
		}

		if ( array() === $new_args ) {
			return;
		}

		$target = add_query_arg( $new_args, remove_query_arg( array( 'month', 'date' ) ) );

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Check whether the queried object renders the calendar shortcode.
	 *
	 * @return bool
	 */
	private function current_page_has_calendar_shortcode(): bool {
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		return has_shortcode( $post->post_content, 'nettertech_events_calendar' );
	}
}
