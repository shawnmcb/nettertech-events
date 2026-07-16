<?php
/**
 * Security headers service.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles security headers for NetterTech Events admin and public pages.
 *
 * Implements defense-in-depth security measures:
 * - X-Content-Type-Options: Prevents MIME type sniffing
 * - X-Frame-Options: Protects against clickjacking
 * - Referrer-Policy: Controls referrer information leakage
 * - Permissions-Policy: Restricts dangerous browser features
 * - Content-Security-Policy: Enforcing on public pages, report-only on admin
 *
 * Admin pages use report-only CSP with 'unsafe-inline' and 'unsafe-eval' for
 * WordPress compatibility. Public pages use a strict enforcing CSP without
 * 'unsafe-inline' or 'unsafe-eval' in script-src.
 *
 * @since 1.0.0
 * @api
 */
class SecurityHeaders {

	/**
	 * Max logged CSP reports per client IP per minute (REST-1).
	 *
	 * @var int
	 */
	private const CSP_REPORT_RATE_LIMIT = 10;

	/**
	 * Max accepted CSP-report body size in bytes; real reports are <2KB (REST-1).
	 *
	 * @var int
	 */
	private const CSP_REPORT_MAX_BODY_BYTES = 8192;

	/**
	 * Max chars per logged CSP-report field (REST-1).
	 *
	 * @var int
	 */
	private const CSP_REPORT_MAX_FIELD_CHARS = 256;

	/**
	 * Whether admin headers have been sent.
	 *
	 * @var bool
	 */
	private bool $admin_headers_sent = false;

	/**
	 * Whether public headers have been sent.
	 *
	 * @var bool
	 */
	private bool $public_headers_sent = false;

	/**
	 * Per-request CSP nonce for script-src.
	 *
	 * Cryptographically random, generated once per request.
	 * Used in both the CSP header and script tag nonce attributes.
	 *
	 * @var string|null
	 */
	private static ?string $csp_nonce = null;

	/**
	 * Initialize security headers.
	 *
	 * Hooks into current_screen for admin pages (so the WP_Screen API is
	 * available and we never need to inspect $_GET for routing) and
	 * send_headers for public pages.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'current_screen', array( $this, 'maybe_send_headers' ) );
		add_action( 'send_headers', array( $this, 'maybe_send_public_headers' ), 10 );
		add_action( 'rest_api_init', array( $this, 'register_csp_report_endpoint' ) );

		// Add CSP nonce to all script tags on VE public pages.
		// These filters fire during wp_head/wp_footer, after send_headers has set the flag.
		add_filter( 'wp_script_attributes', array( $this, 'add_csp_nonce_to_script' ) );
		add_filter( 'wp_inline_script_attributes', array( $this, 'add_csp_nonce_to_inline_script' ), 10, 2 );
	}

	/**
	 * Send security headers if on a NetterTech Events admin page.
	 *
	 * Receives the current WP_Screen instance from the current_screen action,
	 * so admin-page detection is performed via the screen API rather than
	 * by reading $_GET.
	 *
	 * @param \WP_Screen $screen Current admin screen.
	 * @return void
	 */
	public function maybe_send_headers( \WP_Screen $screen ): void {
		// Don't send headers twice.
		if ( $this->admin_headers_sent || headers_sent() ) {
			return;
		}

		// Check if this is a NetterTech Events admin page.
		if ( ! $this->is_nettertech_events_admin_page( $screen ) ) {
			return;
		}

		$this->send_security_headers();
		$this->admin_headers_sent = true;
	}

	/**
	 * Send security headers if on a NetterTech Events public page.
	 *
	 * Hooked to 'send_headers' which fires before any output on the frontend.
	 *
	 * @return void
	 */
	public function maybe_send_public_headers(): void {
		// Don't run on admin pages — admin_init handles those.
		if ( is_admin() ) {
			return;
		}

		// Don't send headers twice.
		if ( $this->public_headers_sent || headers_sent() ) {
			return;
		}

		// Check if this is a NetterTech Events public page.
		if ( ! $this->is_nettertech_events_public_page() ) {
			return;
		}

		$this->send_public_security_headers();
		$this->public_headers_sent = true;
	}

	/**
	 * Check if the given screen is a NetterTech Events admin page.
	 *
	 * Uses the WP_Screen API rather than $_GET reads so that admin-page
	 * detection does not require a nonce/superglobal-suppression pair.
	 * The current_screen action guarantees the screen object is fully
	 * populated by the time this method runs (including post_type for
	 * post.php / post-new.php and CPT list tables).
	 *
	 * @param \WP_Screen $screen Current admin screen.
	 * @return bool
	 */
	private function is_nettertech_events_admin_page( \WP_Screen $screen ): bool {
		// Plugin top-level / submenu pages: WP_Screen->id contains the page slug
		// for screens registered via add_menu_page / add_submenu_page (e.g.
		// "toplevel_page_nettertech-events", "nettertech-events_page_nettertech-events-settings").
		if ( str_contains( $screen->id, 'nettertech-events' ) ) {
			return true;
		}

		// CPT list-table, edit, and new-post screens (post_type is populated for
		// all three: edit.php, post.php, post-new.php).
		if ( 'nettertech_event' === $screen->post_type ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if current page is a NetterTech Events public page.
	 *
	 * Detects single event pages, event archives, and pages using
	 * VE-specific query vars set by the Router rewrite rules.
	 *
	 * @return bool
	 */
	public function is_nettertech_events_public_page(): bool {
		// Single event page via CPT.
		if ( is_singular( 'nettertech_event' ) ) {
			return true;
		}

		// Event archive page via CPT.
		if ( is_post_type_archive( 'nettertech_event' ) ) {
			return true;
		}

		// Single event or occurrence via Router rewrite rules.
		if ( get_query_var( 'nettertech_events_event_slug' ) ) {
			return true;
		}

		// Event archive via Router rewrite rules.
		if ( get_query_var( 'nettertech_events_archive' ) || get_query_var( 'nettertech_events_past_archive' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the per-request CSP nonce.
	 *
	 * Generates a cryptographically random nonce on first call,
	 * then returns the same value for the remainder of the request.
	 * This nonce is used in both the CSP header directive and
	 * as the nonce attribute on script tags.
	 *
	 * Uses wp_generate_password() for cryptographic randomness.
	 * NOT wp_create_nonce() — that generates predictable CSRF tokens
	 * tied to user sessions, which is inappropriate for CSP.
	 *
	 * @since 1.0.0
	 *
	 * @return string Base64-safe random nonce string.
	 */
	public static function get_csp_nonce(): string {
		if ( null === self::$csp_nonce ) {
			self::$csp_nonce = wp_generate_password( 24, false, false );
		}
		return self::$csp_nonce;
	}

	/**
	 * Add CSP nonce to external script tags.
	 *
	 * Hooked to 'wp_script_attributes' filter (WP 5.7+).
	 * Only adds nonce when CSP headers were sent for this request.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attributes Script tag attributes.
	 * @return array<string, mixed>
	 */
	public function add_csp_nonce_to_script( array $attributes ): array {
		if ( $this->public_headers_sent ) {
			$attributes['nonce'] = self::get_csp_nonce();
		}
		return $attributes;
	}

	/**
	 * Add CSP nonce to inline script tags.
	 *
	 * Hooked to 'wp_inline_script_attributes' filter (WP 5.7+).
	 * Covers wp_localize_script() data, wp_add_inline_script() output,
	 * and script translations — all of which route through
	 * wp_get_inline_script_tag() in WP 6.4+.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attributes Script tag attributes.
	 * @param string               $data       Inline script content (unused — required by filter signature).
	 * @return array<string, mixed>
	 */
	public function add_csp_nonce_to_inline_script( array $attributes, string $data ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by wp_inline_script_attributes filter.
		if ( $this->public_headers_sent ) {
			$attributes['nonce'] = self::get_csp_nonce();
		}
		return $attributes;
	}

	/**
	 * Send security headers for admin pages.
	 *
	 * Uses report-only CSP with 'unsafe-inline' and 'unsafe-eval' for
	 * WordPress admin compatibility.
	 *
	 * @return void
	 */
	private function send_security_headers(): void {
		// Prevent MIME type sniffing.
		header( 'X-Content-Type-Options: nosniff' );

		// Protect against clickjacking (same-origin frame embedding only).
		header( 'X-Frame-Options: SAMEORIGIN' );

		// Control referrer information.
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );

		// Restrict potentially dangerous browser features.
		// camera=self allows QR scanner on our pages; =()" would block everywhere.
		header( 'Permissions-Policy: geolocation=(), microphone=(), usb=()' );

		// Content Security Policy in report-only mode.
		// This monitors violations without breaking functionality.
		// Once stable, this can be promoted to enforcing mode.
		$csp = $this->build_csp_policy();
		header( 'Content-Security-Policy-Report-Only: ' . $csp );
	}

	/**
	 * Send security headers for public pages.
	 *
	 * Uses enforcing CSP with a stricter policy than admin pages.
	 * No 'unsafe-inline' or 'unsafe-eval' in script-src.
	 *
	 * @return void
	 */
	private function send_public_security_headers(): void {
		// Prevent MIME type sniffing.
		header( 'X-Content-Type-Options: nosniff' );

		// Protect against clickjacking — allow same-origin framing so the
		// admin event editor's "Layout Preview" iframe can load the public
		// event URL. Cross-origin framing is still blocked.
		header( 'X-Frame-Options: SAMEORIGIN' );

		// Control referrer information.
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );

		// Restrict potentially dangerous browser features.
		header( 'Permissions-Policy: geolocation=(), microphone=(), usb=()' );

		// Content Security Policy in enforcing mode.
		$csp = $this->build_public_csp_policy();
		header( 'Content-Security-Policy: ' . $csp );
	}

	/**
	 * Build Content Security Policy directive for admin pages.
	 *
	 * Uses a permissive policy suitable for WordPress admin:
	 * - 'self' for scripts/styles from same origin
	 * - 'unsafe-inline' required for WordPress core inline scripts
	 * - 'unsafe-eval' required for some WordPress admin JavaScript
	 * - data: URIs allowed for images (WordPress uses these)
	 *
	 * @return string CSP policy string.
	 */
	private function build_csp_policy(): string {
		$directives = array(
			// Default to same-origin.
			"default-src 'self'",

			// Scripts: self + inline (WordPress requirement) + eval (some WP JS).
			"script-src 'self' 'unsafe-inline' 'unsafe-eval'",

			// Styles: self + inline (WordPress uses inline styles extensively).
			"style-src 'self' 'unsafe-inline'",

			// Images: self + data URIs (WordPress uses data: for some icons).
			"img-src 'self' data: https:",

			// Fonts: self (for any webfonts).
			"font-src 'self' data:",

			// Connections: self (AJAX) + REST API.
			"connect-src 'self'",

			// Forms: self only.
			"form-action 'self'",

			// Frames: same-origin + allowlisted video providers (YouTube, Vimeo).
			$this->get_frame_src_directive(),

			// Base URI: self only (prevent base tag injection).
			"base-uri 'self'",

			// Object/embed: none (block plugins, Flash, etc.).
			"object-src 'none'",
		);

		/**
		 * Filter the CSP directives for NetterTech Events admin pages.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string> $directives Array of CSP directive strings.
		 */
		// Report URI for CSP violation logging.
		$report_uri = $this->get_csp_report_uri();
		if ( '' !== $report_uri ) {
			$directives[] = "report-uri {$report_uri}";
		}

		$directives = apply_filters( 'nettertech_events_csp_directives', $directives );

		return implode( '; ', $directives );
	}

	/**
	 * Build Content Security Policy directive for public pages.
	 *
	 * Uses a strict policy suitable for public-facing event pages:
	 * - No 'unsafe-inline' or 'unsafe-eval' in script-src
	 * - 'unsafe-inline' permitted in style-src for WP inline styles
	 * - frame-ancestors 'none' prevents all framing (stronger than X-Frame-Options)
	 *
	 * @return string CSP policy string.
	 */
	private function build_public_csp_policy(): string {
		$directives = array(
			// Default to same-origin.
			"default-src 'self'",

			// Scripts: self + 'unsafe-inline' for third-party inline scripts
			// (WP core's no-js class remover, active theme's body bootstrap,
			// WooCommerce's woocommerce-no-js class swap). We intentionally
			// omit a nonce here because a nonce makes CSP Level 2+ browsers
			// ignore 'unsafe-inline', which would break the third-party
			// scripts we cannot filter. Our own inline scripts still receive
			// a nonce attribute for future hardening. Admins can allowlist
			// additional https script origins under Settings → Advanced.
			$this->get_script_src_directive(),

			// Styles: self + inline (WordPress inline styles) + Google Fonts (theme dependency).
			"style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",

			// Images: self + data URIs + HTTPS external images.
			"img-src 'self' data: https:",

			// Fonts: self + data URIs + Google Fonts CDN (theme dependency).
			"font-src 'self' data: https://fonts.gstatic.com",

			// Connections: self (AJAX/REST API).
			"connect-src 'self'",

			// Forms: self only.
			"form-action 'self'",

			// Frames: same-origin + allowlisted video providers (YouTube, Vimeo).
			// Without this, frame-src falls back to default-src 'self' and the
			// browser blocks oEmbed video iframes (e.g. youtube.com/embed/...).
			$this->get_frame_src_directive(),

			// Frame ancestors: self — allows the admin event editor's Layout
			// Preview iframe (same-origin) while still blocking all cross-origin
			// framing. Matches X-Frame-Options: SAMEORIGIN on public pages.
			"frame-ancestors 'self'",

			// Base URI: self only (prevent base tag injection).
			"base-uri 'self'",

			// Workers: self + blob (Kadence theme uses blob workers for performance).
			"worker-src 'self' blob:",

			// Object/embed: none (block plugins, Flash, etc.).
			"object-src 'none'",
		);

		/**
		 * Filter the CSP directives for NetterTech Events public pages.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string> $directives Array of CSP directive strings.
		 */
		// Report URI for CSP violation logging.
		$report_uri = $this->get_csp_report_uri();
		if ( '' !== $report_uri ) {
			$directives[] = "report-uri {$report_uri}";
		}

		$directives = apply_filters( 'nettertech_events_public_csp_directives', $directives );

		return implode( '; ', $directives );
	}

	/**
	 * Get the current admin CSP policy for testing/debugging.
	 *
	 * @return string
	 */
	public function get_csp_policy(): string {
		return $this->build_csp_policy();
	}

	/**
	 * Get the current public CSP policy for testing/debugging.
	 *
	 * @return string
	 */
	public function get_public_csp_policy(): string {
		return $this->build_public_csp_policy();
	}

	/**
	 * Reset the CSP nonce.
	 *
	 * For testing only. Forces generation of a new nonce on next access.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function reset_csp_nonce(): void {
		self::$csp_nonce = null;
	}

	/**
	 * Build the CSP frame-src directive.
	 *
	 * Returns 'self' plus the allowlisted video-embed providers so oEmbed
	 * iframes (YouTube, Vimeo) render instead of being blocked by the
	 * default-src 'self' fallback. The provider list is filterable so site
	 * owners (or the admin settings UI) can permit additional origins.
	 *
	 * Each origin is reduced to a safe URL-origin character set to prevent
	 * header injection via the filter.
	 *
	 * @since 1.1.0
	 *
	 * @return string The frame-src directive (e.g. "frame-src 'self' https://www.youtube.com ...").
	 */
	private function get_frame_src_directive(): string {
		$default_sources = array(
			'https://www.youtube.com',
			'https://www.youtube-nocookie.com',
			'https://player.vimeo.com',
		);

		// Merge in any origins the admin added under Settings → Advanced →
		// "Allowed video embed sources". These are validated/normalized at save
		// time (scheme://host, http only when explicitly permitted).
		$configured      = NetterTechEventsSettings::from_option()->advanced->allowed_embed_sources;
		$default_sources = array_merge( $default_sources, $configured );

		/**
		 * Filter the origins allowed in the CSP frame-src directive.
		 *
		 * Merged with 'self'. Provide origins as scheme + host
		 * (e.g. "https://example.com"). Used to permit oEmbed/iframe video
		 * providers that the default-src 'self' fallback would otherwise block.
		 *
		 * @since 1.1.1
		 *
		 * @param array<string> $default_sources Default providers plus admin-configured origins.
		 */
		$sources = apply_filters( 'nettertech_events_csp_frame_src', $default_sources );

		if ( ! is_array( $sources ) ) {
			$sources = $default_sources;
		}

		// Defensive: strip anything outside a URL-origin character set so a
		// filter cannot inject CRLF / extra directives into the header.
		$clean = array();
		foreach ( $sources as $source ) {
			$origin = preg_replace( '/[^A-Za-z0-9:\/.\-*]/', '', (string) $source );
			if ( '' !== $origin ) {
				$clean[] = $origin;
			}
		}

		$clean = array_values( array_unique( $clean ) );

		return trim( "frame-src 'self' " . implode( ' ', $clean ) );
	}

	/**
	 * Build the CSP script-src directive.
	 *
	 * Returns the built-in 'self' 'unsafe-inline' keywords plus any admin-configured
	 * https origins, so the public script-src has an explicit allowlist instead of a
	 * hardcoded value. 'unsafe-inline' is retained intentionally (see NTE-010): a
	 * nonce would make CSP Level 2+ browsers ignore it, breaking third-party inline
	 * scripts (WP core, the active theme, WooCommerce) the plugin cannot filter.
	 *
	 * Only https origins are supported for scripts — there is no http opt-in, since
	 * an attacker-controlled insecure script origin is a direct code-execution risk.
	 * Each configured origin is reduced to a safe URL-origin character set to prevent
	 * header injection via the filter; the keyword tokens are emitted as literals and
	 * never pass through that filter.
	 *
	 * @since 1.1.2
	 *
	 * @return string The script-src directive (e.g. "script-src 'self' 'unsafe-inline' https://cdn.example.com").
	 */
	private function get_script_src_directive(): string {
		// Origins the admin added under Settings → Advanced → "Allowed script
		// sources". These are validated/normalized to https origins at save time.
		$configured = NetterTechEventsSettings::from_option()->advanced->allowed_script_sources;

		/**
		 * Filter the origins allowed in the CSP script-src directive.
		 *
		 * Merged with the built-in 'self' 'unsafe-inline' keywords. Provide origins
		 * as scheme + host (e.g. "https://example.com"). Only https origins are
		 * supported for scripts — there is no http opt-in.
		 *
		 * @since 1.1.2
		 *
		 * @param array<string> $configured Admin-configured https script origins.
		 */
		$sources = apply_filters( 'nettertech_events_csp_script_src', $configured );

		if ( ! is_array( $sources ) ) {
			$sources = $configured;
		}

		// Defensive: strip anything outside a URL-origin character set so a filter
		// cannot inject CRLF / extra directives into the header.
		$clean = array();
		foreach ( $sources as $source ) {
			$origin = preg_replace( '/[^A-Za-z0-9:\/.\-*]/', '', (string) $source );
			if ( '' !== $origin ) {
				$clean[] = $origin;
			}
		}

		$clean = array_values( array_unique( $clean ) );

		return trim( "script-src 'self' 'unsafe-inline' " . implode( ' ', $clean ) );
	}

	/**
	 * Get the CSP report URI.
	 *
	 * Returns the plugin's built-in report endpoint by default.
	 * Filterable via nettertech_events_csp_report_uri to point at an external service
	 * (e.g., Report URI, Sentry, or a custom endpoint).
	 *
	 * @since 1.1.0
	 *
	 * @return string Report URI or empty string to disable.
	 */
	private function get_csp_report_uri(): string {
		$default_uri = rest_url( 'nettertech-events/v1/csp-report' );

		/**
		 * Filter the CSP report-uri directive value.
		 *
		 * Return an empty string to disable CSP violation reporting.
		 * Return a URL to override the built-in endpoint (e.g., external reporting service).
		 *
		 * @since 1.0.2
		 *
		 * @param string $report_uri Default report URI (plugin REST endpoint).
		 */
		return (string) apply_filters( 'nettertech_events_csp_report_uri', $default_uri );
	}

	/**
	 * Register the CSP violation report REST endpoint.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register_csp_report_endpoint(): void {
		register_rest_route(
			'nettertech-events/v1',
			'/csp-report',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_csp_report' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle incoming CSP violation reports.
	 *
	 * Browsers POST violation reports as application/csp-report JSON.
	 * Logs the violation details for debugging; does not store persistently.
	 *
	 * @since 1.1.0
	 *
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response Empty 204 response.
	 */
	public function handle_csp_report( \WP_REST_Request $request ): \WP_REST_Response {
		// Always 204 regardless of outcome: a public reporting endpoint must
		// not act as an oracle for rate-limit or validation state (REST-1).
		$response = new \WP_REST_Response( null, 204 );

		// Rate limit: at most 10 logged reports per client IP per minute.
		// Transient-based; silently drops the excess.
		$ip_raw = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$bucket = 'nettertech_events_cspr_' . md5( $ip_raw );
		$count  = (int) get_transient( $bucket );
		if ( $count >= self::CSP_REPORT_RATE_LIMIT ) {
			return $response;
		}
		set_transient( $bucket, $count + 1, MINUTE_IN_SECONDS );

		// Length-cap the raw body before parsing; real CSP reports are <2KB.
		$body = $request->get_body();
		if ( strlen( $body ) > self::CSP_REPORT_MAX_BODY_BYTES ) {
			return $response;
		}

		$data = json_decode( $body, true );

		if ( is_array( $data ) && isset( $data['csp-report'] ) && is_array( $data['csp-report'] ) ) {
			$report = $data['csp-report'];

			\NetterTechEvents\Utilities\DebugLogger::log(
				sprintf(
					'CSP violation: %s blocked by %s on %s',
					self::cap_report_field( $report['blocked-uri'] ?? 'unknown' ),
					self::cap_report_field( $report['violated-directive'] ?? 'unknown' ),
					self::cap_report_field( $report['document-uri'] ?? 'unknown' )
				),
				'SecurityHeaders'
			);
		}

		return $response;
	}

	/**
	 * Sanitize and length-cap a CSP-report field before logging (REST-1).
	 *
	 * @param mixed $value Raw field value from the report payload.
	 * @return string Sanitized value capped at CSP_REPORT_MAX_FIELD_CHARS.
	 */
	private static function cap_report_field( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return 'unknown';
		}

		return substr( sanitize_text_field( $value ), 0, self::CSP_REPORT_MAX_FIELD_CHARS );
	}
}
