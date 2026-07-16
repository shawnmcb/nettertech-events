<?php
/**
 * RateLimitService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use NetterTechEvents\Core\NetterTechEventsSettings;
use NetterTechEvents\Services\RateLimitService;

/**
 * Test RateLimitService functionality.
 */
class RateLimitServiceTest extends \NetterTechEventsTestCase {

	/**
	 * RateLimitService instance.
	 *
	 * @var RateLimitService
	 */
	private RateLimitService $service;

	/**
	 * In-memory transient storage for testing.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset transient storage.
		$this->transients = array();

		// Mock get_transient to use in-memory storage.
		Functions\when( 'get_transient' )->alias(
			function ( string $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);

		// Mock set_transient to use in-memory storage.
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $expiration = 0 ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);

		// Use small limit for testing (3 requests per 60 seconds).
		$this->service = new RateLimitService( NetterTechEventsSettings::from_option(), 3, 60 );
	}

	/**
	 * Test that default limit is returned.
	 *
	 * @return void
	 */
	public function test_get_limit_returns_configured_value(): void {
		$this->assertEquals( 3, $this->service->get_limit() );
	}

	/**
	 * Test that default window is returned.
	 *
	 * @return void
	 */
	public function test_get_window_returns_configured_value(): void {
		$this->assertEquals( 60, $this->service->get_window() );
	}

	/**
	 * Test that is_limited returns false when under limit.
	 *
	 * @return void
	 */
	public function test_is_limited_returns_false_when_under_limit(): void {
		$identifier = 'test_client_1';

		// First request should not be limited.
		$this->assertFalse( $this->service->is_limited( $identifier ) );
	}

	/**
	 * Test that is_limited returns true when at limit.
	 *
	 * @return void
	 */
	public function test_is_limited_returns_true_when_at_limit(): void {
		$identifier = 'test_client_2';

		// Make 3 requests (the limit).
		$this->service->increment( $identifier );
		$this->service->increment( $identifier );
		$this->service->increment( $identifier );

		// Should now be limited.
		$this->assertTrue( $this->service->is_limited( $identifier ) );
	}

	/**
	 * Test that increment returns correct count.
	 *
	 * @return void
	 */
	public function test_increment_returns_correct_count(): void {
		$identifier = 'test_client_3';

		$count1 = $this->service->increment( $identifier );
		$count2 = $this->service->increment( $identifier );
		$count3 = $this->service->increment( $identifier );

		$this->assertEquals( 1, $count1 );
		$this->assertEquals( 2, $count2 );
		$this->assertEquals( 3, $count3 );
	}

	/**
	 * Test that get_remaining returns correct value.
	 *
	 * @return void
	 */
	public function test_get_remaining_returns_correct_value(): void {
		$identifier = 'test_client_4';

		// Start with 3 remaining.
		$this->assertEquals( 3, $this->service->get_remaining( $identifier ) );

		// After 1 request, 2 remaining.
		$this->service->increment( $identifier );
		$this->assertEquals( 2, $this->service->get_remaining( $identifier ) );

		// After 2 requests, 1 remaining.
		$this->service->increment( $identifier );
		$this->assertEquals( 1, $this->service->get_remaining( $identifier ) );

		// After 3 requests, 0 remaining.
		$this->service->increment( $identifier );
		$this->assertEquals( 0, $this->service->get_remaining( $identifier ) );
	}

	/**
	 * Test that get_reset_time returns future timestamp.
	 *
	 * @return void
	 */
	public function test_get_reset_time_returns_future_timestamp(): void {
		$identifier = 'test_client_5';

		// Before any requests, reset time is in the future.
		$reset_time = $this->service->get_reset_time( $identifier );
		$this->assertGreaterThan( time(), $reset_time );

		// After a request, reset time should still be valid.
		$this->service->increment( $identifier );
		$reset_time_after = $this->service->get_reset_time( $identifier );
		$this->assertGreaterThanOrEqual( time(), $reset_time_after );
	}

	/**
	 * Test that different identifiers have independent limits.
	 *
	 * @return void
	 */
	public function test_different_identifiers_have_independent_limits(): void {
		$client_a = 'client_a';
		$client_b = 'client_b';

		// Hit the limit for client A.
		$this->service->increment( $client_a );
		$this->service->increment( $client_a );
		$this->service->increment( $client_a );

		// Client A is limited, but client B is not.
		$this->assertTrue( $this->service->is_limited( $client_a ) );
		$this->assertFalse( $this->service->is_limited( $client_b ) );
	}

	/**
	 * Test that check_and_increment returns null when under limit.
	 *
	 * @return void
	 */
	public function test_check_and_increment_returns_null_when_under_limit(): void {
		$identifier = 'test_client_6';

		$result = $this->service->check_and_increment( $identifier );

		$this->assertNull( $result );
	}

	/**
	 * Test that check_and_increment returns 429 response when at limit.
	 *
	 * @return void
	 */
	public function test_check_and_increment_returns_429_when_at_limit(): void {
		$identifier = 'test_client_7';

		// Use up all requests.
		$this->service->increment( $identifier );
		$this->service->increment( $identifier );
		$this->service->increment( $identifier );

		$result = $this->service->check_and_increment( $identifier );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertEquals( 429, $result->get_status() );
	}

	/**
	 * Test that should_bypass returns false for non-admin users.
	 *
	 * @return void
	 */
	public function test_should_bypass_returns_false_for_non_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$service = new RateLimitService( NetterTechEventsSettings::from_option(), 3, 60 );
		$this->assertFalse( $service->should_bypass() );
	}

	/**
	 * Test that should_bypass returns true for admin users.
	 *
	 * @return void
	 */
	public function test_should_bypass_returns_true_for_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$service = new RateLimitService( NetterTechEventsSettings::from_option(), 3, 60 );
		$this->assertTrue( $service->should_bypass() );
	}

	/**
	 * Test that limit of 1 is minimum enforced.
	 *
	 * @return void
	 */
	public function test_minimum_limit_is_one(): void {
		$service = new RateLimitService( NetterTechEventsSettings::from_option(), 0, 60 );
		$this->assertEquals( 1, $service->get_limit() );

		$service = new RateLimitService( NetterTechEventsSettings::from_option(), -5, 60 );
		$this->assertEquals( 1, $service->get_limit() );
	}

	/**
	 * Test that window of 1 is minimum enforced.
	 *
	 * @return void
	 */
	public function test_minimum_window_is_one(): void {
		$service = new RateLimitService( NetterTechEventsSettings::from_option(), 60, 0 );
		$this->assertEquals( 1, $service->get_window() );

		$service = new RateLimitService( NetterTechEventsSettings::from_option(), 60, -5 );
		$this->assertEquals( 1, $service->get_window() );
	}

	/**
	 * Test that add_headers adds rate limit headers to response.
	 *
	 * @return void
	 */
	public function test_add_headers_adds_rate_limit_headers(): void {
		$identifier = 'test_client_headers';
		$response   = new \WP_REST_Response( array( 'test' => 'data' ), 200 );

		$this->service->increment( $identifier );
		$result = $this->service->add_headers( $response, $identifier );

		$headers = $result->get_headers();

		$this->assertArrayHasKey( 'X-RateLimit-Limit', $headers );
		$this->assertArrayHasKey( 'X-RateLimit-Remaining', $headers );
		$this->assertArrayHasKey( 'X-RateLimit-Reset', $headers );
		$this->assertEquals( '3', $headers['X-RateLimit-Limit'] );
		$this->assertEquals( '2', $headers['X-RateLimit-Remaining'] );
	}

	// =========================================================================
	// get_client_identifier() — SEC-MED-01 / NTE-134 IP-spoof fix
	// =========================================================================

	/**
	 * Invoke the private get_client_identifier() method via reflection.
	 *
	 * @return string
	 */
	private function invoke_get_client_identifier(): string {
		$reflection = new \ReflectionClass( RateLimitService::class );
		$method     = $reflection->getMethod( 'get_client_identifier' );
		return $method->invoke( $this->service );
	}

	/**
	 * With NETTERTECH_EVENTS_TRUSTED_PROXY undefined (the default), a spoofed
	 * X-Forwarded-For header must be ignored in favor of REMOTE_ADDR.
	 *
	 * @return void
	 */
	public function test_get_client_identifier_ignores_spoofed_header_when_proxy_not_trusted(): void {
		$this->assertFalse( defined( 'NETTERTECH_EVENTS_TRUSTED_PROXY' ) );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
		$_SERVER['REMOTE_ADDR']          = '198.51.100.10';

		$identifier = $this->invoke_get_client_identifier();

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR'] );

		$this->assertSame( '198.51.100.10', $identifier );
	}

	/**
	 * With NETTERTECH_EVENTS_TRUSTED_PROXY undefined, a spoofed CF-Connecting-IP
	 * header must also be ignored — proves all three proxy headers are gated,
	 * not just X-Forwarded-For.
	 *
	 * @return void
	 */
	public function test_get_client_identifier_ignores_spoofed_cf_header_when_proxy_not_trusted(): void {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.55';
		$_SERVER['REMOTE_ADDR']           = '198.51.100.20';

		$identifier = $this->invoke_get_client_identifier();

		unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['REMOTE_ADDR'] );

		$this->assertSame( '198.51.100.20', $identifier );
	}

	/**
	 * With NETTERTECH_EVENTS_TRUSTED_PROXY defined and truthy, the proxy
	 * header IS honored (existing pre-fix behavior, now opt-in).
	 *
	 * Runs in a separate process because the constant, once defined, is
	 * process-global and cannot be undefined for later tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_get_client_identifier_honors_header_when_proxy_trusted(): void {
		define( 'NETTERTECH_EVENTS_TRUSTED_PROXY', true );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
		$_SERVER['REMOTE_ADDR']          = '198.51.100.10';

		$identifier = $this->invoke_get_client_identifier();

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR'] );

		$this->assertSame( '203.0.113.99', $identifier );
	}

	/**
	 * With NETTERTECH_EVENTS_TRUSTED_PROXY defined but falsy, proxy headers
	 * must still be ignored — the opt-in requires a truthy value, not merely
	 * definedness.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_get_client_identifier_ignores_header_when_proxy_constant_falsy(): void {
		define( 'NETTERTECH_EVENTS_TRUSTED_PROXY', false );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
		$_SERVER['REMOTE_ADDR']          = '198.51.100.10';

		$identifier = $this->invoke_get_client_identifier();

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR'] );

		$this->assertSame( '198.51.100.10', $identifier );
	}

	/**
	 * X-Forwarded-For with multiple IPs uses the LAST, when trusted.
	 *
	 * The last entry is appended by the proxy that actually fronted the
	 * request; the first entry is client-supplied whenever proxies append
	 * rather than overwrite, so trusting it would let callers pick their
	 * own rate-limit bucket (NTE-142; supersedes the first-hop behavior).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_get_client_identifier_uses_last_ip_in_forwarded_for_list_when_trusted(): void {
		define( 'NETTERTECH_EVENTS_TRUSTED_PROXY', true );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7, 10.0.0.1';
		$_SERVER['REMOTE_ADDR']          = '198.51.100.10';

		$identifier = $this->invoke_get_client_identifier();

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR'] );

		$this->assertSame( '10.0.0.1', $identifier );
	}

	/**
	 * With no proxy headers and no REMOTE_ADDR at all, falls back to the
	 * documented loopback default.
	 *
	 * @return void
	 */
	public function test_get_client_identifier_falls_back_to_loopback_when_remote_addr_missing(): void {
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['REMOTE_ADDR'] );

		$identifier = $this->invoke_get_client_identifier();

		$this->assertSame( '127.0.0.1', $identifier );
	}
}
