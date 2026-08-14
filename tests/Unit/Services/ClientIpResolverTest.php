<?php
/**
 * ClientIpResolver unit tests.
 *
 * NTE-142: the resolution matrix. Every trust decision the resolver can
 * make is pinned here, with particular attention to the spoofing
 * boundaries — no request content controlled by a direct client may ever
 * switch the resolver onto a forwarded header.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use NetterTechEvents\Services\ClientIpResolver;

/**
 * Test ClientIpResolver trust rules.
 */
class ClientIpResolverTest extends \NetterTechEventsTestCase {

	/**
	 * In-memory transient storage.
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

		$this->transients = array();

		Functions\when( 'get_transient' )->alias(
			function ( string $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);

		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $expiration = 0 ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
	}

	/**
	 * Tear down request state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$_SERVER['REMOTE_ADDR'],
			$_SERVER['HTTP_X_FORWARDED_FOR'],
			$_SERVER['HTTP_X_REAL_IP'],
			$_SERVER['HTTP_CF_CONNECTING_IP']
		);

		parent::tearDown();
	}

	/**
	 * Rule 3: a direct public client cannot pick its bucket via XFF.
	 *
	 * @return void
	 */
	public function test_public_remote_with_spoofed_xff_uses_remote_addr(): void {
		$_SERVER['REMOTE_ADDR']          = '9.9.9.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';

		$resolver = new ClientIpResolver();

		$this->assertSame( '9.9.9.9', $resolver->resolve() );
		$this->assertArrayHasKey( 'headers_ignored', $resolver->get_sample() );
	}

	/**
	 * Rule 3: spoofed CF-Connecting-IP from a non-Cloudflare source is ignored.
	 *
	 * @return void
	 */
	public function test_public_remote_with_spoofed_cf_header_uses_remote_addr(): void {
		$_SERVER['REMOTE_ADDR']            = '9.9.9.9';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.8.8';

		$resolver = new ClientIpResolver();

		$this->assertSame( '9.9.9.9', $resolver->resolve() );
	}

	/**
	 * Rule 1: private-source connection trusts the forwarded header.
	 *
	 * @return void
	 */
	public function test_private_remote_with_valid_forwarded_uses_forwarded(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';

		$resolver = new ClientIpResolver();

		$this->assertSame( '8.8.8.8', $resolver->resolve() );
		$this->assertArrayHasKey( 'private_source_proxy', $resolver->get_sample() );
	}

	/**
	 * Rule 1: CGNAT (RFC 6598) counts as non-public source space.
	 *
	 * Also pins the PHP 8.2 FILTER_FLAG_GLOBAL_RANGE dependency: CGNAT is
	 * not excluded by the older private/reserved flags.
	 *
	 * @return void
	 */
	public function test_cgnat_remote_counts_as_proxy_source(): void {
		$_SERVER['REMOTE_ADDR']          = '100.64.0.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';

		$resolver = new ClientIpResolver();

		$this->assertSame( '8.8.8.8', $resolver->resolve() );
	}

	/**
	 * Rule 1: loopback source (local reverse proxy) trusts headers.
	 *
	 * @return void
	 */
	public function test_loopback_remote_with_real_ip_header_requires_optin(): void {
		$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
		$_SERVER['HTTP_X_REAL_IP'] = '8.8.8.8';

		// Exactly one header is trusted per reason (NTE-SEC-2026-07-A);
		// X-Real-IP-only proxies opt in via the header filter. Without the
		// opt-in the header is ignored, not scanned as a fallback.
		$resolver = new ClientIpResolver();
		$this->assertSame( '127.0.0.1', $resolver->resolve(), 'Untrusted header must not be scanned' );
		$this->assertArrayHasKey( 'headers_ignored', $resolver->get_sample() );

		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, $value ) {
				return 'nettertech_events_forwarded_header' === $hook ? 'HTTP_X_REAL_IP' : $value;
			}
		);

		$opted_in = new ClientIpResolver();
		$this->assertSame( '8.8.8.8', $opted_in->resolve(), 'Filter opt-in trusts exactly that header' );
	}

	/**
	 * Rule 2: Cloudflare connects from PUBLIC egress ranges; the shipped
	 * range list must recognize it.
	 *
	 * @return void
	 */
	public function test_cloudflare_range_remote_trusts_cf_header(): void {
		$_SERVER['REMOTE_ADDR']            = '104.16.1.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.8.8';

		$resolver = new ClientIpResolver();

		$this->assertSame( '8.8.8.8', $resolver->resolve() );
		$this->assertArrayHasKey( 'known_proxy_range', $resolver->get_sample() );
	}

	/**
	 * Rule 2 (IPv6): Cloudflare's IPv6 ranges match too.
	 *
	 * @return void
	 */
	public function test_cloudflare_ipv6_range_remote_trusts_cf_header(): void {
		$_SERVER['REMOTE_ADDR']            = '2606:4700::1234';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '2a00:1450:4001::1';

		$resolver = new ClientIpResolver();

		$this->assertSame( '2a00:1450:4001::1', $resolver->resolve() );
	}

	/**
	 * No headers at all: plain direct resolution.
	 *
	 * @return void
	 */
	public function test_no_headers_uses_remote_addr(): void {
		$_SERVER['REMOTE_ADDR'] = '9.9.9.9';

		$resolver = new ClientIpResolver();

		$this->assertSame( '9.9.9.9', $resolver->resolve() );
		$this->assertArrayHasKey( 'direct', $resolver->get_sample() );
	}

	/**
	 * XFF uses the LAST hop — the entry appended by the fronting proxy —
	 * never the client-supplied first entry.
	 *
	 * @return void
	 */
	public function test_xff_uses_last_hop(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.4.4, 8.8.8.8';

		$resolver = new ClientIpResolver();

		$this->assertSame( '8.8.8.8', $resolver->resolve() );
	}

	/**
	 * In auto mode a non-public last hop is rejected (proxy chains end at
	 * the edge with a public client), falling back to REMOTE_ADDR.
	 *
	 * @return void
	 */
	public function test_auto_rejects_private_forwarded_value(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8, 192.168.1.50';

		$resolver = new ClientIpResolver();

		$this->assertSame( '10.0.0.5', $resolver->resolve() );
	}

	/**
	 * Malformed header values are ignored.
	 *
	 * @return void
	 */
	public function test_malformed_forwarded_value_is_ignored(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';

		$resolver = new ClientIpResolver();

		$this->assertSame( '10.0.0.5', $resolver->resolve() );
	}

	/**
	 * Header priority: CF-Connecting-IP wins over X-Forwarded-For.
	 *
	 * @return void
	 */
	public function test_generic_proxy_ignores_fabricated_cf_header(): void {
		// THE NTE-SEC-2026-07-A attack: behind a generic reverse proxy
		// (private source), the attacker fabricates CF-Connecting-IP,
		// which the proxy passes through untouched while appending the
		// real client to XFF. The priority-list scan let the fabricated
		// header win; a private source must trust only the generic-proxy
		// header (XFF last hop).
		$_SERVER['REMOTE_ADDR']            = '10.0.0.5';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.8.8';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '8.8.4.4';

		$resolver = new ClientIpResolver();

		$this->assertSame( '8.8.4.4', $resolver->resolve(), 'Fabricated CF header must not beat the proxy-written XFF hop' );
	}

	/**
	 * The converse spoof: a Cloudflare-range connection must consult only
	 * CF-Connecting-IP — a client-supplied XFF tail must not win when the
	 * CF header is somehow absent.
	 *
	 * @return void
	 */
	public function test_cloudflare_range_without_cf_header_falls_back_to_remote(): void {
		$_SERVER['REMOTE_ADDR']           = '104.16.1.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.4.4';

		$resolver = new ClientIpResolver();

		$this->assertSame( '104.16.1.1', $resolver->resolve(), 'CF-range trust reason must not scan other headers' );
		$this->assertArrayHasKey( 'headers_ignored', $resolver->get_sample() );
	}

	/**
	 * The ranges filter extends trust to operator-declared proxies.
	 *
	 * @return void
	 */
	public function test_filter_extended_ranges_are_trusted(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, $value ) {
				if ( 'nettertech_events_trusted_proxy_ranges' === $hook ) {
					$value[] = '9.9.9.0/24';
				}
				return $value;
			}
		);

		$_SERVER['REMOTE_ADDR']          = '9.9.9.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';

		$resolver = new ClientIpResolver();

		$this->assertSame( '8.8.8.8', $resolver->resolve() );
	}

	/**
	 * Setting mode 'direct' ignores headers even from a private source.
	 *
	 * @return void
	 */
	public function test_direct_setting_overrides_auto_rules(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';

		$resolver = new ClientIpResolver( ClientIpResolver::MODE_DIRECT );

		$this->assertSame( '10.0.0.5', $resolver->resolve() );
		$this->assertSame(
			array(
				'mode'   => ClientIpResolver::MODE_DIRECT,
				'source' => 'setting',
			),
			$resolver->effective_mode()
		);
	}

	/**
	 * Setting mode 'proxied' trusts headers even from a public source,
	 * and accepts private forwarded values (internal clients behind an
	 * operator-vouched LB).
	 *
	 * @return void
	 */
	public function test_proxied_setting_trusts_headers_from_public_source(): void {
		$_SERVER['REMOTE_ADDR']          = '9.9.9.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.50';

		$resolver = new ClientIpResolver( ClientIpResolver::MODE_PROXIED );

		$this->assertSame( '192.168.1.50', $resolver->resolve() );
	}

	/**
	 * An invalid settings value degrades to auto.
	 *
	 * @return void
	 */
	public function test_invalid_setting_mode_degrades_to_auto(): void {
		$resolver = new ClientIpResolver( 'bogus' );

		$this->assertSame(
			array(
				'mode'   => ClientIpResolver::MODE_AUTO,
				'source' => 'auto',
			),
			$resolver->effective_mode()
		);
	}

	/**
	 * The constant outranks the setting (back-compat with 1.1.1.3).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_constant_true_forces_proxied_over_setting(): void {
		define( 'NETTERTECH_EVENTS_TRUSTED_PROXY', true );

		$_SERVER['REMOTE_ADDR']          = '9.9.9.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';

		$resolver = new ClientIpResolver( ClientIpResolver::MODE_DIRECT );

		$this->assertSame( '8.8.8.8', $resolver->resolve() );
		$this->assertSame( 'constant', $resolver->effective_mode()['source'] );
	}

	/**
	 * A falsy constant forces direct mode even against auto rules.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_constant_false_forces_direct(): void {
		define( 'NETTERTECH_EVENTS_TRUSTED_PROXY', false );

		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';

		$resolver = new ClientIpResolver();

		$this->assertSame( '10.0.0.5', $resolver->resolve() );
	}

	/**
	 * Missing REMOTE_ADDR falls back to the documented loopback default.
	 *
	 * @return void
	 */
	public function test_missing_remote_addr_defaults_to_loopback(): void {
		$resolver = new ClientIpResolver( ClientIpResolver::MODE_DIRECT );

		$this->assertSame( '127.0.0.1', $resolver->resolve() );
	}

	/**
	 * Resolution counters accumulate per rule for Site Health.
	 *
	 * @return void
	 */
	public function test_sample_counters_accumulate(): void {
		$_SERVER['REMOTE_ADDR'] = '9.9.9.9';

		$resolver = new ClientIpResolver();
		$resolver->resolve();
		$resolver->resolve();

		$this->assertSame( array( 'direct' => 2 ), $resolver->get_sample() );
	}

	/**
	 * CIDR containment, including prefixes that do not land on a byte boundary.
	 *
	 * A trusted range is what promotes a forwarded header over REMOTE_ADDR, so
	 * a mis-computed subnet mask is a spoofing hole: too wide and an untrusted
	 * client is believed, too narrow and a real proxy is ignored. With a public
	 * REMOTE_ADDR and a public forwarded value, range membership is the only
	 * thing resolve() can be deciding on — the returned IP reads the predicate
	 * directly.
	 *
	 * @dataProvider cidr_membership_cases
	 * @param string $range     CIDR range declared trusted.
	 * @param string $remote    REMOTE_ADDR under test (always public).
	 * @param bool   $in_range  Whether $remote falls inside $range.
	 * @return void
	 */
	public function test_cidr_membership( string $range, string $remote, bool $in_range ): void {
		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, $value ) use ( $range ) {
				return 'nettertech_events_trusted_proxy_ranges' === $hook ? array( $range ) : $value;
			}
		);

		$_SERVER['REMOTE_ADDR']          = $remote;
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';

		$resolver = new ClientIpResolver();

		// In range → the proxy is trusted, so the forwarded client wins.
		// Out of range → the header proved nothing and REMOTE_ADDR stands.
		$this->assertSame( $in_range ? '8.8.8.8' : $remote, $resolver->resolve() );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function cidr_membership_cases(): array {
		return array(
			// Byte-aligned baselines.
			'/8 inside'                    => array( '9.0.0.0/8', '9.9.9.9', true ),
			'/24 inside'                   => array( '9.9.9.0/24', '9.9.9.9', true ),
			'/24 outside'                  => array( '9.9.9.0/24', '9.9.10.9', false ),

			// /20 → 2 whole bytes + 4 remainder bits (mask 0xF0). Range covers
			// 9.9.96.0 – 9.9.111.255; the edges are one address either side.
			'/20 lower edge inside'        => array( '9.9.96.0/20', '9.9.96.0', true ),
			'/20 upper edge inside'        => array( '9.9.96.0/20', '9.9.111.255', true ),
			'/20 one below is outside'     => array( '9.9.96.0/20', '9.9.95.255', false ),
			'/20 one above is outside'     => array( '9.9.96.0/20', '9.9.112.0', false ),

			// /12 → 1 whole byte + 4 remainder bits. Covers 9.16.0.0 – 9.31.255.255.
			'/12 upper edge inside'        => array( '9.16.0.0/12', '9.31.255.255', true ),
			'/12 one above is outside'     => array( '9.16.0.0/12', '9.32.0.0', false ),

			// The whole-byte prefix must match before the remainder mask is consulted.
			'/20 whole-byte prefix differs' => array( '9.9.96.0/20', '8.9.100.5', false ),

			// Single-host and degenerate prefixes.
			'/32 exact host match'         => array( '9.9.9.9/32', '9.9.9.9', true ),
			'/32 adjacent host misses'     => array( '9.9.9.9/32', '9.9.9.10', false ),

			// Malformed ranges must be skipped, never matched.
			'prefix above family maximum'  => array( '9.9.9.0/33', '9.9.9.9', false ),
			'negative prefix'              => array( '9.9.9.0/-1', '9.9.9.9', false ),
			'missing prefix separator'     => array( '9.9.9.9', '9.9.9.9', false ),

			// Address families must not be compared across each other.
			'ipv6 range vs ipv4 client'    => array( '2400:cb00::/32', '9.9.9.9', false ),
		);
	}

	/**
	 * The ranges filter is operator input: normalize it rather than trusting it.
	 *
	 * @return void
	 */
	public function test_trusted_ranges_are_normalized(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, $value ) {
				return 'nettertech_events_trusted_proxy_ranges' === $hook
					? array( '9.9.9.0/24', '', null, 0, '10.0.0.0/8' )
					: $value;
			}
		);

		$resolver = new ClientIpResolver();

		// Empties dropped, everything stringified, keys reindexed contiguously.
		$this->assertSame( array( '9.9.9.0/24', '10.0.0.0/8' ), $resolver->trusted_ranges() );
	}

	/**
	 * A garbage value in a higher-priority header must not short-circuit the
	 * search — the resolver falls through to the next header, it does not
	 * return the garbage.
	 *
	 * @return void
	 */
	public function test_unparseable_priority_header_falls_through(): void {
		$_SERVER['REMOTE_ADDR']           = '10.0.0.5';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '8.8.8.8';

		$resolver = new ClientIpResolver();

		$this->assertSame( '8.8.8.8', $resolver->resolve() );
	}
}
