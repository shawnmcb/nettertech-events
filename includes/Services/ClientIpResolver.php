<?php
/**
 * Client IP Resolver.
 *
 * Resolves the client IP for rate limiting with zero-config proxy
 * awareness (NTE-142). Statelessly applies spoof-proof trust rules per
 * request instead of requiring operators to declare proxy topology.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the rate-limit client identifier.
 *
 * Trust can never be inferred from request content the client controls
 * (header values, cardinality patterns) — an attacker could steer any
 * such inference and reopen the spoofing hole NTE-134 closed. The auto
 * rules therefore rely only on signals an internet client cannot forge:
 *
 * 1. A non-public REMOTE_ADDR (RFC 1918 / CGNAT / loopback): packets from
 *    the internet cannot source from these ranges, so the connection came
 *    from local infrastructure — trust its forwarded header.
 * 2. A REMOTE_ADDR inside a known proxy range (shipped Cloudflare list,
 *    extendable via filter): a TCP handshake cannot be completed from a
 *    spoofed source, so the connection genuinely came from that proxy.
 * 3. Otherwise the connection is direct — use REMOTE_ADDR and ignore all
 *    forwarded headers.
 *
 * Operators can override: the NETTERTECH_EVENTS_TRUSTED_PROXY constant
 * (highest precedence, kept for back-compat with 1.1.1.3) or the
 * "Proxy handling" setting (auto / direct / proxied).
 *
 * @since 1.1.2
 */
final class ClientIpResolver {

	/**
	 * Auto mode: apply the trust rules per request.
	 */
	public const MODE_AUTO = 'auto';

	/**
	 * Direct mode: always REMOTE_ADDR, ignore forwarded headers.
	 */
	public const MODE_DIRECT = 'direct';

	/**
	 * Proxied mode: always prefer forwarded headers.
	 */
	public const MODE_PROXIED = 'proxied';

	/**
	 * Transient collecting per-rule resolution counters for Site Health.
	 */
	public const SAMPLE_TRANSIENT = 'nettertech_events_ip_resolution_sample';

	/**
	 * Sample retention window: 7 days in seconds (literal — WP time
	 * constants aren't defined at class-load time in the unit suite).
	 */
	private const SAMPLE_TTL = 604800;

	/**
	 * Forwarded headers in trust priority order.
	 */
	private const FORWARDED_HEADERS = array(
		'HTTP_CF_CONNECTING_IP', // Cloudflare.
		'HTTP_X_REAL_IP',        // Nginx reverse proxy.
		'HTTP_X_FORWARDED_FOR',  // Standard proxy chain.
	);

	/**
	 * Published Cloudflare IP ranges (www.cloudflare.com/ips, retrieved
	 * 2026-07-01). Cloudflare connects to origins from these PUBLIC
	 * addresses, so the private-source rule alone would miss it.
	 */
	private const CLOUDFLARE_RANGES = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Proxy-handling mode from plugin settings.
	 *
	 * @var string
	 */
	private string $settings_mode;

	/**
	 * Constructor.
	 *
	 * @since 1.1.2
	 *
	 * @param string $settings_mode Proxy-handling mode from settings (auto|direct|proxied).
	 */
	public function __construct( string $settings_mode = self::MODE_AUTO ) {
		$valid               = array( self::MODE_AUTO, self::MODE_DIRECT, self::MODE_PROXIED );
		$this->settings_mode = in_array( $settings_mode, $valid, true ) ? $settings_mode : self::MODE_AUTO;
	}

	/**
	 * The effective mode and where it came from.
	 *
	 * Precedence: constant > setting (non-auto) > auto.
	 *
	 * @since 1.1.2
	 *
	 * @return array{mode: string, source: string} Mode and its source (constant|setting|auto).
	 */
	public function effective_mode(): array {
		if ( defined( 'NETTERTECH_EVENTS_TRUSTED_PROXY' ) ) {
			return array(
				'mode'   => NETTERTECH_EVENTS_TRUSTED_PROXY ? self::MODE_PROXIED : self::MODE_DIRECT,
				'source' => 'constant',
			);
		}

		if ( self::MODE_AUTO !== $this->settings_mode ) {
			return array(
				'mode'   => $this->settings_mode,
				'source' => 'setting',
			);
		}

		return array(
			'mode'   => self::MODE_AUTO,
			'source' => 'auto',
		);
	}

	/**
	 * Resolve the client IP for rate limiting.
	 *
	 * @since 1.1.2
	 *
	 * @return string Client IP address.
	 */
	public function resolve(): string {
		$remote = $this->remote_addr();
		$mode   = $this->effective_mode()['mode'];

		if ( self::MODE_DIRECT === $mode ) {
			$this->record( 'forced_direct' );
			return $remote;
		}

		if ( self::MODE_PROXIED === $mode ) {
			// Operator vouched for the proxy: accept any valid forwarded
			// IP, including private ones (internal clients behind an LB).
			$forwarded = $this->forwarded_ip( false );
			$this->record( 'forced_proxied' );
			return $forwarded ?? $remote;
		}

		// Auto rules. Forwarded values must be public: reserved-space
		// header values have no meaning from a real proxy chain edge.
		$forwarded = $this->forwarded_ip( true );

		if ( null === $forwarded ) {
			$this->record( 'direct' );
			return $remote;
		}

		if ( ! $this->is_public_ip( $remote ) ) {
			$this->record( 'private_source_proxy' );
			return $forwarded;
		}

		if ( $this->ip_in_ranges( $remote, $this->trusted_ranges() ) ) {
			$this->record( 'known_proxy_range' );
			return $forwarded;
		}

		// Headers present but the source proved nothing — the unknown-CDN
		// signature Site Health watches for.
		$this->record( 'headers_ignored' );
		return $remote;
	}

	/**
	 * Trusted proxy CIDR ranges.
	 *
	 * @since 1.1.2
	 *
	 * @return array<int, string> CIDR strings.
	 */
	public function trusted_ranges(): array {
		/**
		 * Filters the CIDR ranges treated as trusted proxies.
		 *
		 * When REMOTE_ADDR falls inside one of these ranges, forwarded
		 * headers are trusted in auto mode. Ships with Cloudflare's
		 * published ranges; add your load balancer or CDN egress ranges
		 * here for zero-config operation behind other proxies.
		 *
		 * @since 1.1.2
		 *
		 * @param array<int, string> $ranges CIDR ranges (IPv4 and IPv6).
		 */
		$ranges = apply_filters( 'nettertech_events_trusted_proxy_ranges', self::CLOUDFLARE_RANGES );

		return array_values( array_filter( array_map( 'strval', (array) $ranges ) ) );
	}

	/**
	 * Read the per-rule resolution counters (for Site Health).
	 *
	 * @since 1.1.2
	 *
	 * @return array<string, int> Rule name => count.
	 */
	public function get_sample(): array {
		$sample = get_transient( self::SAMPLE_TRANSIENT );

		return is_array( $sample ) ? array_map( 'intval', $sample ) : array();
	}

	/**
	 * Sanitized REMOTE_ADDR.
	 *
	 * @return string
	 */
	private function remote_addr(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '127.0.0.1';

		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '127.0.0.1';
	}

	/**
	 * First usable IP from the forwarded headers, in priority order.
	 *
	 * X-Forwarded-For uses the LAST hop: proxies append the connecting
	 * address, so the final entry is the one written by the proxy that
	 * actually fronted this request. The first entry is client-supplied
	 * whenever the proxy appends rather than overwrites.
	 *
	 * @param bool $require_public Whether the forwarded IP must be globally routable.
	 * @return string|null Valid IP or null.
	 */
	private function forwarded_ip( bool $require_public ): ?string {
		foreach ( self::FORWARDED_HEADERS as $header ) {
			if ( ! isset( $_SERVER[ $header ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

			if ( '' === $value ) {
				continue;
			}

			if ( str_contains( $value, ',' ) ) {
				$parts = array_map( 'trim', explode( ',', $value ) );
				$value = (string) end( $parts );
			}

			if ( ! filter_var( $value, FILTER_VALIDATE_IP ) ) {
				continue;
			}

			if ( $require_public && ! $this->is_public_ip( $value ) ) {
				continue;
			}

			return $value;
		}

		return null;
	}

	/**
	 * Whether an IP is globally routable.
	 *
	 * FILTER_FLAG_GLOBAL_RANGE (PHP 8.2+) rejects private, reserved,
	 * CGNAT (100.64.0.0/10), link-local, and ULA space per the IANA
	 * special-purpose registries.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private function is_public_ip( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE );
	}

	/**
	 * Whether an IP falls inside any of the CIDR ranges.
	 *
	 * @param string             $ip     IP address.
	 * @param array<int, string> $ranges CIDR strings (IPv4 and IPv6).
	 * @return bool
	 */
	private function ip_in_ranges( string $ip, array $ranges ): bool {
		$packed = inet_pton( $ip );

		if ( false === $packed ) {
			return false;
		}

		foreach ( $ranges as $range ) {
			if ( ! str_contains( $range, '/' ) ) {
				continue;
			}

			list( $subnet, $bits ) = explode( '/', $range, 2 );

			$subnet_packed = inet_pton( $subnet );
			$bits          = (int) $bits;

			// Address families must match (4 bytes vs 16).
			if ( false === $subnet_packed || strlen( $subnet_packed ) !== strlen( $packed ) ) {
				continue;
			}

			$max_bits = strlen( $packed ) * 8;

			if ( $bits < 0 || $bits > $max_bits ) {
				continue;
			}

			$whole_bytes = intdiv( $bits, 8 );
			$remainder   = $bits % 8;

			if ( $whole_bytes > 0 && 0 !== substr_compare( $packed, $subnet_packed, 0, $whole_bytes ) ) {
				continue;
			}

			if ( 0 !== $remainder ) {
				$mask = 0xFF << ( 8 - $remainder ) & 0xFF;
				if ( ( ord( $packed[ $whole_bytes ] ) & $mask ) !== ( ord( $subnet_packed[ $whole_bytes ] ) & $mask ) ) {
					continue;
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * Record which rule resolved this request (rolling counters for
	 * Site Health; transient-backed, so the cost matches the transient
	 * reads the rate limiter already performs).
	 *
	 * @param string $rule Rule identifier.
	 * @return void
	 */
	private function record( string $rule ): void {
		$sample = $this->get_sample();

		$sample[ $rule ] = ( $sample[ $rule ] ?? 0 ) + 1;

		set_transient( self::SAMPLE_TRANSIENT, $sample, self::SAMPLE_TTL );
	}
}
