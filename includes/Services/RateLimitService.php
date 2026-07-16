<?php
/**
 * Rate Limit Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\NetterTechEventsSettings;

/**
 * Service for managing API rate limiting.
 *
 * Implements sliding window rate limiting using WordPress transients.
 * Tracks requests per IP address with configurable limits and windows.
 *
 * @since 0.9.0
 * @api
 */
class RateLimitService {

	/**
	 * Transient prefix for rate limit tracking.
	 */
	private const TRANSIENT_PREFIX = 'nettertech_events_rate_limit_';

	/**
	 * Requests per window.
	 *
	 * @var int
	 */
	private int $limit;

	/**
	 * Window size in seconds.
	 *
	 * @var int
	 */
	private int $window;

	/**
	 * Client IP resolver (NTE-142).
	 *
	 * @var ClientIpResolver
	 */
	private ClientIpResolver $ip_resolver;

	/**
	 * Constructor.
	 *
	 * Loads rate limit configuration from:
	 * 1. Constructor parameters (highest priority)
	 * 2. Plugin settings via NetterTechEventsSettings DTO
	 * 3. Filter (nettertech_events_rate_limit_settings) for backward compatibility
	 * 4. Class defaults (lowest priority)
	 *
	 * @param NetterTechEventsSettings $settings    Settings DTO.
	 * @param int|null                 $limit       Requests per window (default: 60).
	 * @param int|null                 $window      Window size in seconds (default: 60).
	 * @param ClientIpResolver|null    $ip_resolver Optional resolver for dependency injection.
	 */
	public function __construct( NetterTechEventsSettings $settings, ?int $limit = null, ?int $window = null, ?ClientIpResolver $ip_resolver = null ) {

		$this->limit       = $limit ?? $settings->performance->rate_limit_requests;
		$this->window      = $window ?? $settings->performance->rate_limit_window;
		$this->ip_resolver = $ip_resolver ?? new ClientIpResolver( $settings->performance->rate_limit_proxy_mode );

		/**
		 * Filter the rate limit settings.
		 *
		 * Allows programmatic override of rate limit settings.
		 * This filter is applied after loading from plugin settings
		 * for backward compatibility.
		 *
		 * @since 1.0.2
		 *
		 * @param array{limit: int, window: int} $settings Rate limit settings.
		 */
		$settings = apply_filters(
			'nettertech_events_rate_limit_settings',
			array(
				'limit'  => $this->limit,
				'window' => $this->window,
			)
		);

		$this->limit  = max( 1, (int) $settings['limit'] );
		$this->window = max( 1, (int) $settings['window'] );
	}

	/**
	 * Check if the current request is rate limited.
	 *
	 * @param string|null $identifier Client identifier (default: IP address).
	 * @return bool True if rate limit exceeded, false otherwise.
	 */
	public function is_limited( ?string $identifier = null ): bool {
		$identifier = $identifier ?? $this->get_client_identifier();
		$count      = $this->get_request_count( $identifier );

		return $count >= $this->limit;
	}

	/**
	 * Increment the request count for a client.
	 *
	 * @param string|null $identifier Client identifier (default: IP address).
	 * @return int The new request count.
	 */
	public function increment( ?string $identifier = null ): int {
		$identifier    = $identifier ?? $this->get_client_identifier();
		$transient_key = $this->get_transient_key( $identifier );
		$current_data  = get_transient( $transient_key );

		if ( false === $current_data ) {
			$new_data = array(
				'count'   => 1,
				'start'   => time(),
				'expires' => time() + $this->window,
			);
			set_transient( $transient_key, $new_data, $this->window );
			return 1;
		}

		$current_data['count'] = (int) $current_data['count'] + 1;
		$remaining_ttl         = max( 1, (int) $current_data['expires'] - time() );
		set_transient( $transient_key, $current_data, $remaining_ttl );

		return $current_data['count'];
	}

	/**
	 * Get the remaining requests for a client.
	 *
	 * @param string|null $identifier Client identifier (default: IP address).
	 * @return int Remaining requests in the current window.
	 */
	public function get_remaining( ?string $identifier = null ): int {
		$identifier = $identifier ?? $this->get_client_identifier();
		$count      = $this->get_request_count( $identifier );

		return max( 0, $this->limit - $count );
	}

	/**
	 * Get the reset time for a client's rate limit window.
	 *
	 * @param string|null $identifier Client identifier (default: IP address).
	 * @return int Unix timestamp when the window resets.
	 */
	public function get_reset_time( ?string $identifier = null ): int {
		$identifier    = $identifier ?? $this->get_client_identifier();
		$transient_key = $this->get_transient_key( $identifier );
		$data          = get_transient( $transient_key );

		if ( false === $data || ! isset( $data['expires'] ) ) {
			return time() + $this->window;
		}

		return (int) $data['expires'];
	}

	/**
	 * Get the configured limit.
	 *
	 * @return int Requests per window.
	 */
	public function get_limit(): int {
		return $this->limit;
	}

	/**
	 * Get the configured window size.
	 *
	 * @return int Window size in seconds.
	 */
	public function get_window(): int {
		return $this->window;
	}

	/**
	 * Get the current request count for a client.
	 *
	 * @param string $identifier Client identifier.
	 * @return int Current request count.
	 */
	private function get_request_count( string $identifier ): int {
		$transient_key = $this->get_transient_key( $identifier );
		$data          = get_transient( $transient_key );

		if ( false === $data ) {
			return 0;
		}

		return (int) $data['count'];
	}

	/**
	 * Get the transient key for a client identifier.
	 *
	 * @param string $identifier Client identifier.
	 * @return string Transient key.
	 */
	private function get_transient_key( string $identifier ): string {
		return self::TRANSIENT_PREFIX . md5( $identifier );
	}

	/**
	 * Get the client identifier from the current request.
	 *
	 * Delegates to ClientIpResolver (NTE-142): forwarded headers are
	 * trusted only when the connection itself proves a proxy fronted it
	 * (non-public REMOTE_ADDR, or REMOTE_ADDR inside a known proxy range)
	 * or the operator forces it via the `NETTERTECH_EVENTS_TRUSTED_PROXY`
	 * constant / the "Proxy handling" setting. Headers remain ignored on
	 * direct connections — they're client-supplied and trivially
	 * spoofable (SEC-MED-01).
	 *
	 * @return string Client identifier (IP address).
	 */
	private function get_client_identifier(): string {
		return $this->ip_resolver->resolve();
	}

	/**
	 * Apply rate limiting to a REST API response.
	 *
	 * Checks the rate limit, increments the counter, and adds appropriate
	 * headers. Returns a 429 response if the limit is exceeded.
	 *
	 * @param string|null $identifier Client identifier (default: IP address).
	 * @return \WP_REST_Response|null Returns 429 response if limited, null otherwise.
	 */
	public function check_and_increment( ?string $identifier = null ): ?\WP_REST_Response {
		$identifier = $identifier ?? $this->get_client_identifier();

		// Check if already limited (before incrementing).
		if ( $this->is_limited( $identifier ) ) {
			return $this->create_rate_limit_response( $identifier );
		}

		// Increment for this request.
		$this->increment( $identifier );

		return null;
	}

	/**
	 * Create a 429 rate limit exceeded response.
	 *
	 * @param string $identifier Client identifier.
	 * @return \WP_REST_Response
	 */
	private function create_rate_limit_response( string $identifier ): \WP_REST_Response {
		$reset_time  = $this->get_reset_time( $identifier );
		$retry_after = max( 1, $reset_time - time() );

		$response = new \WP_REST_Response(
			array(
				'code'    => 'rate_limit_exceeded',
				'message' => __( 'Too many requests. Please try again later.', 'nettertech-events' ),
				'data'    => array(
					'status'      => 429,
					'retry_after' => $retry_after,
				),
			),
			429
		);

		$response->header( 'Retry-After', (string) $retry_after );
		$response->header( 'X-RateLimit-Limit', (string) $this->limit );
		$response->header( 'X-RateLimit-Remaining', '0' );
		$response->header( 'X-RateLimit-Reset', (string) $reset_time );

		return $response;
	}

	/**
	 * Add rate limit headers to a response.
	 *
	 * @param \WP_REST_Response $response   Response object.
	 * @param string|null       $identifier Client identifier (default: IP address).
	 * @return \WP_REST_Response Modified response with headers.
	 */
	public function add_headers( \WP_REST_Response $response, ?string $identifier = null ): \WP_REST_Response {
		$identifier = $identifier ?? $this->get_client_identifier();

		$response->header( 'X-RateLimit-Limit', (string) $this->limit );
		$response->header( 'X-RateLimit-Remaining', (string) $this->get_remaining( $identifier ) );
		$response->header( 'X-RateLimit-Reset', (string) $this->get_reset_time( $identifier ) );

		return $response;
	}

	/**
	 * Check if rate limiting should be bypassed.
	 *
	 * Admins and authenticated users with specific capabilities may be
	 * excluded from rate limiting.
	 *
	 * @return bool True if rate limiting should be bypassed.
	 */
	public function should_bypass(): bool {
		/**
		 * Filter whether to bypass rate limiting for the current request.
		 *
		 * @since 1.0.2
		 *
		 * @param bool $bypass Whether to bypass rate limiting.
		 */
		return apply_filters( 'nettertech_events_rate_limit_bypass', current_user_can( 'manage_options' ) );
	}
}
