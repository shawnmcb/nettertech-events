<?php
/**
 * Site Health integration.
 *
 * Surfaces the rate-limit proxy-handling status (NTE-142) so operators
 * learn about topology mismatches from WordPress itself instead of from
 * throttled visitors.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Services\ClientIpResolver;

/**
 * Registers NetterTech Events checks on the Site Health screen.
 *
 * @since 1.1.2
 */
final class SiteHealth {

	/**
	 * Client IP resolver.
	 *
	 * @var ClientIpResolver
	 */
	private ClientIpResolver $resolver;

	/**
	 * Constructor.
	 *
	 * @since 1.1.2
	 *
	 * @param ClientIpResolver $resolver Client IP resolver.
	 */
	public function __construct( ClientIpResolver $resolver ) {
		$this->resolver = $resolver;
	}

	/**
	 * Hook into Site Health.
	 *
	 * @since 1.1.2
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	/**
	 * Register the proxy-topology test.
	 *
	 * @since 1.1.2
	 *
	 * @param array<string, array<string, mixed>> $tests Site Health tests.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_tests( array $tests ): array {
		$tests['direct']['nettertech_events_proxy_topology'] = array(
			'label' => __( 'NetterTech Events visitor identification', 'nettertech-events' ),
			'test'  => array( $this, 'test_proxy_topology' ),
		);

		return $tests;
	}

	/**
	 * Run the proxy-topology test.
	 *
	 * Reports how rate limiting identifies visitors and flags the
	 * unknown-CDN signature: forwarded headers arriving in volume while
	 * auto-detection has no proof a trusted proxy sent them.
	 *
	 * @since 1.1.2
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_proxy_topology(): array {
		$mode    = $this->resolver->effective_mode();
		$sample  = $this->resolver->get_sample();
		$ignored = $sample['headers_ignored'] ?? 0;

		$source_labels = array(
			'constant' => __( 'the NETTERTECH_EVENTS_TRUSTED_PROXY constant in wp-config.php', 'nettertech-events' ),
			'setting'  => __( 'the Proxy Handling setting', 'nettertech-events' ),
			'auto'     => __( 'automatic detection', 'nettertech-events' ),
		);

		$rule_labels = array(
			'direct'               => __( 'Direct connections', 'nettertech-events' ),
			'private_source_proxy' => __( 'Local reverse proxy detected', 'nettertech-events' ),
			'known_proxy_range'    => __( 'Known proxy range (e.g. Cloudflare)', 'nettertech-events' ),
			'headers_ignored'      => __( 'Forwarded headers ignored (unrecognized source)', 'nettertech-events' ),
			'forced_direct'        => __( 'Forced direct by override', 'nettertech-events' ),
			'forced_proxied'       => __( 'Forced proxied by override', 'nettertech-events' ),
		);

		$description = '<p>' . sprintf(
			/* translators: %s: configuration source, e.g. "automatic detection". */
			esc_html__( 'Rate limiting identifies visitors using %s.', 'nettertech-events' ),
			esc_html( $source_labels[ $mode['source'] ] ?? $mode['source'] )
		) . '</p>';

		if ( array() !== $sample ) {
			$description .= '<p>' . esc_html__( 'Recent rate-limited requests resolved as:', 'nettertech-events' ) . '</p><ul>';

			foreach ( $sample as $rule => $count ) {
				$description .= '<li>' . sprintf(
					'%1$s: %2$s',
					esc_html( $rule_labels[ $rule ] ?? $rule ),
					esc_html( number_format_i18n( $count ) )
				) . '</li>';
			}

			$description .= '</ul>';
		}

		$result = array(
			'label'       => __( 'Visitor identification for rate limiting is working', 'nettertech-events' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'nettertech-events' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => '',
			'test'        => 'nettertech_events_proxy_topology',
		);

		// The unknown-CDN signature: auto mode is discarding forwarded
		// headers in volume. Either they're spoofed (fine) or the site
		// sits behind a proxy auto-detection doesn't recognize (visitors
		// then share one rate-limit bucket and get throttled together).
		if ( 'auto' === $mode['source'] && $ignored >= 10 ) {
			$result['label']        = __( 'Your site may be behind an unrecognized proxy', 'nettertech-events' );
			$result['status']       = 'recommended';
			$result['description'] .= '<p>' . esc_html__( 'Requests are arriving with forwarded-IP headers that automatic detection cannot verify. If this site is behind a CDN or reverse proxy other than Cloudflare, rate limiting is currently treating all visitors as one client, which can throttle legitimate traffic. Set Proxy Handling to "Behind a proxy" in the plugin settings, or add your proxy IP ranges via the nettertech_events_trusted_proxy_ranges filter. If you do not use a proxy, no action is needed — the headers are spoofed and correctly ignored.', 'nettertech-events' ) . '</p>';
			$result['actions']      = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=' . AdminMenu::SUBMENU_SETTINGS ) ),
				esc_html__( 'Review Proxy Handling setting', 'nettertech-events' )
			);
		}

		return $result;
	}
}
