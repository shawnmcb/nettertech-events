<?php
/**
 * SecurityHeaders unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\SecurityHeaders;
use Brain\Monkey\Functions;

/**
 * Test SecurityHeaders CSP policy building and page detection.
 *
 * Note: PHP's header() function cannot be mocked in unit tests.
 * These tests verify policy construction and detection logic, not
 * the actual header sending.
 */
class SecurityHeadersTest extends \NetterTechEventsTestCase {

	/**
	 * SecurityHeaders instance under test.
	 *
	 * @var SecurityHeaders
	 */
	private SecurityHeaders $headers;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Mock wp_generate_password() — used for CSP nonce generation.
		// Returns a predictable-length alphanumeric string for testing.
		$counter = 0;
		Functions\when( 'wp_generate_password' )->alias(
			function ( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ) use ( &$counter ) {
				++$counter;
				return substr( str_repeat( 'abcdefghijklmnopqrstuvwx', 3 ), $counter, $length );
			}
		);

		// Mock rest_url() — used for CSP report-uri directive.
		Functions\when( 'rest_url' )->alias(
			function ( string $path = '' ): string {
				return 'http://example.com/wp-json/' . ltrim( $path, '/' );
			}
		);

		// get_option() backs NetterTechEventsSettings::from_option(), which the
		// frame-src directive reads for admin-configured embed origins. Default
		// to no stored settings; individual tests override as needed.
		Functions\when( 'get_option' )->justReturn( array() );

		SecurityHeaders::reset_csp_nonce();
		$this->headers = new SecurityHeaders();
	}

	/**
	 * Test init hooks current_screen for admin header sending.
	 *
	 * @return void
	 */
	public function test_init_hooks_current_screen(): void {
		$hooks_added = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$hooks_added ) {
				$hooks_added[] = array(
					'hook'     => $hook,
					'priority' => $priority,
				);
				return true;
			}
		);

		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$hooks_added ) {
				$hooks_added[] = array(
					'hook'     => $hook,
					'priority' => $priority,
				);
				return true;
			}
		);

		$this->headers->init();

		$admin_hook = array_filter(
			$hooks_added,
			function ( $h ) {
				return 'current_screen' === $h['hook'];
			}
		);

		$this->assertNotEmpty( $admin_hook, 'current_screen hook should be registered' );
	}

	/**
	 * Test init hooks send_headers for public header sending.
	 *
	 * @return void
	 */
	public function test_init_hooks_send_headers(): void {
		$hooks_added = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$hooks_added ) {
				$hooks_added[] = array(
					'hook'     => $hook,
					'priority' => $priority,
				);
				return true;
			}
		);

		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$hooks_added ) {
				$hooks_added[] = array(
					'hook'     => $hook,
					'priority' => $priority,
				);
				return true;
			}
		);

		$this->headers->init();

		$public_hook = array_filter(
			$hooks_added,
			function ( $h ) {
				return 'send_headers' === $h['hook'];
			}
		);

		$this->assertNotEmpty( $public_hook, 'send_headers hook should be registered' );
	}

	/**
	 * Test init registers CSP nonce script attribute filters.
	 *
	 * @return void
	 */
	public function test_init_hooks_script_nonce_filters(): void {
		$filters_added = array();

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$filters_added ) {
				$filters_added[] = $hook;
				return true;
			}
		);

		$this->headers->init();

		$this->assertContains( 'wp_script_attributes', $filters_added );
		$this->assertContains( 'wp_inline_script_attributes', $filters_added );
	}

	/**
	 * Test admin CSP policy contains unsafe-inline in script-src.
	 *
	 * @return void
	 */
	public function test_admin_csp_contains_unsafe_inline(): void {
		$policy = $this->headers->get_csp_policy();

		$this->assertStringContainsString( "'unsafe-inline'", $policy );
		$this->assertStringContainsString( "script-src 'self' 'unsafe-inline' 'unsafe-eval'", $policy );
	}

	/**
	 * Test admin CSP policy contains unsafe-eval in script-src.
	 *
	 * @return void
	 */
	public function test_admin_csp_contains_unsafe_eval(): void {
		$policy = $this->headers->get_csp_policy();

		$this->assertStringContainsString( "'unsafe-eval'", $policy );
	}

	/**
	 * Test admin CSP includes frame-src self for media library.
	 *
	 * @return void
	 */
	public function test_admin_csp_includes_frame_src(): void {
		$policy = $this->headers->get_csp_policy();

		$this->assertStringContainsString( "frame-src 'self'", $policy );
	}

	/**
	 * Test public CSP script-src uses unsafe-inline without nonce.
	 *
	 * A nonce was intentionally removed because CSP Level 2+ browsers ignore
	 * unsafe-inline when a nonce is present, which broke third-party inline
	 * scripts from WP core, the active theme, and WooCommerce that the plugin
	 * cannot filter. See NTE-010.
	 *
	 * @return void
	 */
	public function test_public_csp_script_src_uses_unsafe_inline_without_nonce(): void {
		SecurityHeaders::reset_csp_nonce();
		$policy = $this->headers->get_public_csp_policy();

		preg_match( '/script-src\s+([^;]+)/', $policy, $matches );
		$script_src = $matches[1] ?? '';

		$this->assertStringContainsString( "'self'", $script_src );
		$this->assertStringContainsString( "'unsafe-inline'", $script_src );
		$this->assertStringNotContainsString( 'nonce-', $script_src );
	}

	/**
	 * Test public CSP policy does NOT contain unsafe-eval in script-src.
	 *
	 * @return void
	 */
	public function test_public_csp_no_unsafe_eval_in_script_src(): void {
		$policy = $this->headers->get_public_csp_policy();

		preg_match( '/script-src\s+([^;]+)/', $policy, $matches );
		$script_src = $matches[1] ?? '';

		$this->assertStringNotContainsString( 'unsafe-eval', $script_src );
	}

	/**
	 * Test public CSP allows unsafe-inline for styles and Google Fonts.
	 *
	 * @return void
	 */
	public function test_public_csp_allows_unsafe_inline_in_style_src(): void {
		$policy = $this->headers->get_public_csp_policy();

		$this->assertStringContainsString( "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com", $policy );
	}

	/**
	 * Test public CSP font-src includes Google Fonts CDN.
	 *
	 * @return void
	 */
	public function test_public_csp_font_src_includes_google_fonts(): void {
		$policy = $this->headers->get_public_csp_policy();

		$this->assertStringContainsString( 'https://fonts.gstatic.com', $policy );
	}

	/**
	 * Test CSP nonce is consistent within a single request.
	 *
	 * @return void
	 */
	public function test_csp_nonce_is_consistent_per_request(): void {
		SecurityHeaders::reset_csp_nonce();
		$nonce1 = SecurityHeaders::get_csp_nonce();
		$nonce2 = SecurityHeaders::get_csp_nonce();

		$this->assertSame( $nonce1, $nonce2 );
	}

	/**
	 * Test CSP nonce changes after reset (simulates new request).
	 *
	 * @return void
	 */
	public function test_csp_nonce_changes_after_reset(): void {
		SecurityHeaders::reset_csp_nonce();
		$nonce1 = SecurityHeaders::get_csp_nonce();

		SecurityHeaders::reset_csp_nonce();
		$nonce2 = SecurityHeaders::get_csp_nonce();

		$this->assertNotSame( $nonce1, $nonce2 );
	}

	/**
	 * Test CSP nonce has sufficient length for security.
	 *
	 * @return void
	 */
	public function test_csp_nonce_has_sufficient_length(): void {
		SecurityHeaders::reset_csp_nonce();
		$nonce = SecurityHeaders::get_csp_nonce();

		// 24 characters provides ~143 bits of entropy (alphanumeric).
		$this->assertGreaterThanOrEqual( 24, strlen( $nonce ) );
	}

	/**
	 * Test CSP nonce getter still returns a value (used on script tag
	 * attributes via the wp_script_attributes filter) even though the nonce
	 * is no longer included in the CSP directive itself. See NTE-010.
	 *
	 * @return void
	 */
	public function test_csp_nonce_getter_still_works(): void {
		SecurityHeaders::reset_csp_nonce();
		$nonce = SecurityHeaders::get_csp_nonce();

		$this->assertNotEmpty( $nonce );
		$this->assertGreaterThanOrEqual( 24, strlen( $nonce ) );
	}

	/**
	 * Test public CSP uses frame-ancestors self (allows same-origin iframe
	 * embedding for the admin event editor's Layout Preview).
	 *
	 * @return void
	 */
	public function test_public_csp_uses_frame_ancestors_self(): void {
		$policy = $this->headers->get_public_csp_policy();

		$this->assertStringContainsString( "frame-ancestors 'self'", $policy );
	}

	/**
	 * Test public CSP includes a frame-src directive allowlisting the default
	 * video-embed providers so oEmbed iframes (YouTube, Vimeo) render instead
	 * of being blocked by the default-src 'self' fallback.
	 *
	 * @return void
	 */
	public function test_public_csp_frame_src_allows_video_providers(): void {
		$policy = $this->headers->get_public_csp_policy();

		$this->assertStringContainsString( "frame-src 'self'", $policy );
		$this->assertStringContainsString( 'https://www.youtube.com', $policy );
		$this->assertStringContainsString( 'https://www.youtube-nocookie.com', $policy );
		$this->assertStringContainsString( 'https://player.vimeo.com', $policy );
	}

	/**
	 * Test the nettertech_events_csp_frame_src filter can extend the allowlist,
	 * and that injected control characters are stripped from origins.
	 *
	 * @return void
	 */
	public function test_public_csp_frame_src_is_filterable_and_sanitized(): void {
		// Simulate a filter that adds a provider and attempts header injection.
		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, mixed $value = null ) {
				if ( 'nettertech_events_csp_frame_src' === $hook && is_array( $value ) ) {
					$value[] = 'https://example.com';
					$value[] = "https://evil.com\r\nContent-Type: text/x"; // Injection attempt.
				}
				return $value;
			}
		);

		$policy = $this->headers->get_public_csp_policy();

		// The added provider is allowlisted.
		$this->assertStringContainsString( 'https://example.com', $policy );
		// CR/LF (the header-injection vector) are stripped, so a malicious
		// filter cannot break out of the header into a new one.
		$this->assertStringNotContainsString( "\r", $policy );
		$this->assertStringNotContainsString( "\n", $policy );
	}

	/**
	 * Test admin-configured embed origins (Settings → Advanced) are merged into
	 * the public frame-src directive alongside the built-in providers.
	 *
	 * @return void
	 */
	public function test_public_csp_frame_src_includes_admin_configured_sources(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'allowed_embed_sources' => array( 'https://video.example.org' ) )
		);

		$policy = $this->headers->get_public_csp_policy();

		$this->assertStringContainsString( 'https://video.example.org', $policy );
		// Built-in defaults remain present.
		$this->assertStringContainsString( 'https://www.youtube.com', $policy );
	}

	/**
	 * Test that with no admin-configured script origins the script-src directive
	 * is exactly the built-in 'self' 'unsafe-inline' default (NTE-127). Guards
	 * against the allowlist plumbing accidentally widening the default policy.
	 *
	 * @return void
	 */
	public function test_public_csp_script_src_empty_setting_uses_default(): void {
		// setUp stubs get_option() to array() — no configured script sources.
		$policy = $this->headers->get_public_csp_policy();

		preg_match( '/script-src\s+([^;]+)/', $policy, $matches );
		$script_src = trim( $matches[1] ?? '' );

		// $matches[1] is the value after "script-src "; with no configured origins
		// it must be exactly the built-in keywords and nothing more.
		$this->assertSame( "'self' 'unsafe-inline'", $script_src );
	}

	/**
	 * Test admin-configured script origins (Settings → Advanced) are merged into
	 * the public script-src directive alongside the built-in keywords (NTE-127).
	 *
	 * @return void
	 */
	public function test_public_csp_script_src_includes_admin_configured_sources(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'allowed_script_sources' => array( 'https://cdn.example.org' ) )
		);

		$policy = $this->headers->get_public_csp_policy();

		preg_match( '/script-src\s+([^;]+)/', $policy, $matches );
		$script_src = $matches[1] ?? '';

		$this->assertStringContainsString( "'self'", $script_src );
		$this->assertStringContainsString( "'unsafe-inline'", $script_src );
		$this->assertStringContainsString( 'https://cdn.example.org', $script_src );
	}

	/**
	 * Test the nettertech_events_csp_script_src filter can extend the allowlist,
	 * and that injected control characters are stripped from origins (NTE-127).
	 *
	 * @return void
	 */
	public function test_public_csp_script_src_is_filterable_and_sanitized(): void {
		// Simulate a filter that adds an origin and attempts header injection.
		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, mixed $value = null ) {
				if ( 'nettertech_events_csp_script_src' === $hook && is_array( $value ) ) {
					$value[] = 'https://scripts.example.com';
					$value[] = "https://evil.com\r\nContent-Type: text/x"; // Injection attempt.
				}
				return $value;
			}
		);

		$policy = $this->headers->get_public_csp_policy();

		// The added origin is allowlisted.
		$this->assertStringContainsString( 'https://scripts.example.com', $policy );
		// CR/LF (the header-injection vector) are stripped, so a malicious filter
		// cannot break out of the header into a new one.
		$this->assertStringNotContainsString( "\r", $policy );
		$this->assertStringNotContainsString( "\n", $policy );
	}

	/**
	 * Test public CSP includes object-src none.
	 *
	 * @return void
	 */
	public function test_public_csp_includes_object_src_none(): void {
		$policy = $this->headers->get_public_csp_policy();

		$this->assertStringContainsString( "object-src 'none'", $policy );
	}

	/**
	 * Test public CSP includes base-uri self.
	 *
	 * @return void
	 */
	public function test_public_csp_includes_base_uri_self(): void {
		$policy = $this->headers->get_public_csp_policy();

		$this->assertStringContainsString( "base-uri 'self'", $policy );
	}

	/**
	 * Test public CSP includes form-action self.
	 *
	 * @return void
	 */
	public function test_public_csp_includes_form_action_self(): void {
		$policy = $this->headers->get_public_csp_policy();

		$this->assertStringContainsString( "form-action 'self'", $policy );
	}

	/**
	 * Test nettertech_events_public_csp_directives filter is applied.
	 *
	 * @return void
	 */
	public function test_public_csp_filter_is_applied(): void {
		$filter_called = false;

		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) use ( &$filter_called ) {
				if ( 'nettertech_events_public_csp_directives' === $tag ) {
					$filter_called = true;
					// Add a custom directive via the filter.
					$value[] = "report-uri /csp-report";
				}
				return $value;
			}
		);

		$policy = $this->headers->get_public_csp_policy();

		$this->assertTrue( $filter_called, 'nettertech_events_public_csp_directives filter should be applied' );
		$this->assertStringContainsString( 'report-uri /csp-report', $policy );
	}

	/**
	 * Test nettertech_events_csp_directives filter is applied on admin policy.
	 *
	 * @return void
	 */
	public function test_admin_csp_filter_is_applied(): void {
		$filter_called = false;

		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) use ( &$filter_called ) {
				if ( 'nettertech_events_csp_directives' === $tag ) {
					$filter_called = true;
				}
				return $value;
			}
		);

		$this->headers->get_csp_policy();

		$this->assertTrue( $filter_called, 'nettertech_events_csp_directives filter should be applied' );
	}

	/**
	 * Test public and admin filters are independent.
	 *
	 * @return void
	 */
	public function test_public_and_admin_filters_are_independent(): void {
		$filters_invoked = array();

		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) use ( &$filters_invoked ) {
				$filters_invoked[] = $tag;
				return $value;
			}
		);

		$this->headers->get_csp_policy();
		$this->headers->get_public_csp_policy();

		$this->assertContains( 'nettertech_events_csp_directives', $filters_invoked );
		$this->assertContains( 'nettertech_events_public_csp_directives', $filters_invoked );
	}

	/**
	 * Test is_nettertech_events_public_page detects singular nettertech_event.
	 *
	 * @return void
	 */
	public function test_public_page_detection_singular_event(): void {
		Functions\when( 'is_singular' )->alias(
			function ( $post_type = '' ) {
				return 'nettertech_event' === $post_type;
			}
		);
		Functions\when( 'is_post_type_archive' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( '' );

		$this->assertTrue( $this->headers->is_nettertech_events_public_page() );
	}

	/**
	 * Test is_nettertech_events_public_page detects post type archive.
	 *
	 * @return void
	 */
	public function test_public_page_detection_post_type_archive(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_post_type_archive' )->alias(
			function ( $post_type = '' ) {
				return 'nettertech_event' === $post_type;
			}
		);
		Functions\when( 'get_query_var' )->justReturn( '' );

		$this->assertTrue( $this->headers->is_nettertech_events_public_page() );
	}

	/**
	 * Test is_nettertech_events_public_page detects nettertech_events_event_slug query var.
	 *
	 * @return void
	 */
	public function test_public_page_detection_event_slug_query_var(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_post_type_archive' )->justReturn( false );
		Functions\when( 'get_query_var' )->alias(
			function ( $var, $default = '' ) {
				if ( 'nettertech_events_event_slug' === $var ) {
					return 'summer-concert';
				}
				return $default;
			}
		);

		$this->assertTrue( $this->headers->is_nettertech_events_public_page() );
	}

	/**
	 * Test is_nettertech_events_public_page detects nettertech_events_archive query var.
	 *
	 * @return void
	 */
	public function test_public_page_detection_archive_query_var(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_post_type_archive' )->justReturn( false );
		Functions\when( 'get_query_var' )->alias(
			function ( $var, $default = '' ) {
				if ( 'nettertech_events_archive' === $var ) {
					return '1';
				}
				return $default;
			}
		);

		$this->assertTrue( $this->headers->is_nettertech_events_public_page() );
	}

	/**
	 * Test is_nettertech_events_public_page detects nettertech_events_past_archive query var.
	 *
	 * @return void
	 */
	public function test_public_page_detection_past_archive_query_var(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_post_type_archive' )->justReturn( false );
		Functions\when( 'get_query_var' )->alias(
			function ( $var, $default = '' ) {
				if ( 'nettertech_events_past_archive' === $var ) {
					return '1';
				}
				return $default;
			}
		);

		$this->assertTrue( $this->headers->is_nettertech_events_public_page() );
	}

	/**
	 * Test is_nettertech_events_public_page returns false for non-NTE pages.
	 *
	 * @return void
	 */
	public function test_public_page_detection_returns_false_for_non_nte_page(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_post_type_archive' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( '' );

		$this->assertFalse( $this->headers->is_nettertech_events_public_page() );
	}

	/**
	 * Test admin CSP policy default-src is self.
	 *
	 * @return void
	 */
	public function test_admin_csp_default_src_is_self(): void {
		$policy = $this->headers->get_csp_policy();

		$this->assertStringContainsString( "default-src 'self'", $policy );
	}

	/**
	 * Test public CSP policy default-src is self.
	 *
	 * @return void
	 */
	public function test_public_csp_default_src_is_self(): void {
		$policy = $this->headers->get_public_csp_policy();

		$this->assertStringContainsString( "default-src 'self'", $policy );
	}

	/**
	 * Test public CSP allows HTTPS images.
	 *
	 * @return void
	 */
	public function test_public_csp_allows_https_images(): void {
		$policy = $this->headers->get_public_csp_policy();

		$this->assertStringContainsString( "img-src 'self' data: https:", $policy );
	}

	/**
	 * Test public CSP connect-src is self only.
	 *
	 * @return void
	 */
	public function test_public_csp_connect_src_is_self(): void {
		$policy = $this->headers->get_public_csp_policy();

		$this->assertStringContainsString( "connect-src 'self'", $policy );
	}

	/**
	 * Test public CSP filter can modify directives.
	 *
	 * @return void
	 */
	public function test_public_csp_filter_can_modify_directives(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				if ( 'nettertech_events_public_csp_directives' === $tag ) {
					// Replace script-src to allow a CDN.
					foreach ( $value as $i => $directive ) {
						if ( str_starts_with( $directive, 'script-src' ) ) {
							$value[ $i ] = "script-src 'self' https://cdn.example.com";
						}
					}
				}
				return $value;
			}
		);

		$policy = $this->headers->get_public_csp_policy();

		$this->assertStringContainsString( "script-src 'self' https://cdn.example.com", $policy );
	}

	// =========================================================================
	// /csp-report hardening (REST-1)
	// =========================================================================

	/**
	 * Build a CSP-report request with the given body.
	 *
	 * @param string $body Raw request body.
	 * @return \WP_REST_Request
	 */
	private function make_csp_request( string $body ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/nettertech-events/v1/csp-report' );
		$request->set_body( $body );
		return $request;
	}

	/**
	 * Test a normal report returns 204 and consumes one rate-bucket slot.
	 *
	 * @return void
	 */
	public function test_csp_report_returns_204_and_increments_rate_bucket(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$writes                 = array();
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$writes ) {
				$writes[] = array( $key, $value, $ttl );
				return true;
			}
		);

		$body     = '{"csp-report":{"blocked-uri":"https://evil.example"}}';
		$response = $this->headers->handle_csp_report( $this->make_csp_request( $body ) );

		$this->assertSame( 204, $response->status );
		$this->assertCount( 1, $writes, 'One rate-bucket write expected.' );
		$this->assertSame( 1, $writes[0][1], 'Bucket increments from 0 to 1.' );
		$this->assertSame( MINUTE_IN_SECONDS, $writes[0][2] );
	}

	/**
	 * Test reports beyond the per-IP rate limit are dropped without a bucket write.
	 *
	 * @return void
	 */
	public function test_csp_report_drops_when_rate_limited(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$writes                 = array();
		Functions\when( 'get_transient' )->justReturn( 10 );
		Functions\when( 'set_transient' )->alias(
			function () use ( &$writes ) {
				$writes[] = func_get_args();
				return true;
			}
		);

		$response = $this->headers->handle_csp_report( $this->make_csp_request( '{"csp-report":{}}' ) );

		$this->assertSame( 204, $response->status );
		$this->assertCount( 0, $writes, 'Rate-limited request must not write the bucket.' );
	}

	/**
	 * Test oversized bodies still return 204 (no oracle) and are not parsed.
	 *
	 * @return void
	 */
	public function test_csp_report_drops_oversized_body(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\when( 'set_transient' )->justReturn( true );

		$response = $this->headers->handle_csp_report( $this->make_csp_request( str_repeat( 'A', 9000 ) ) );

		$this->assertSame( 204, $response->status );
	}

	/**
	 * Test logged report fields are sanitized and capped at 256 chars.
	 *
	 * @return void
	 */
	public function test_cap_report_field_truncates_and_sanitizes(): void {
		$method = ( new \ReflectionClass( SecurityHeaders::class ) )->getMethod( 'cap_report_field' );

		$this->assertSame( 256, strlen( $method->invoke( null, str_repeat( 'x', 5000 ) ) ) );
		$this->assertSame( 'unknown', $method->invoke( null, array( 'not' => 'a string' ) ) );
		$this->assertSame( 'unknown', $method->invoke( null, '' ) );
	}
}
