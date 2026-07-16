<?php
/**
 * Advanced Settings Section.
 *
 * @package NetterTechEvents\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Handles advanced settings section rendering.
 *
 * Extracted from SettingsPage to reduce class complexity.
 * Manages system-level settings like occurrence horizon, rate limiting,
 * cart holds, and cache configuration.
 *
 * @since 1.1.0
 * @api
 */
class AdvancedSettingsSection implements SettingsSectionInterface {

	/**
	 * Get the section identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'advanced';
	}

	/**
	 * Get the section title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Advanced', 'nettertech-events' );
	}

	/**
	 * Render the advanced settings section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	public function render( array $settings ): void {
		?>
		<div class="nte-settings__section">
			<div class="nte-settings__section-header">
				<h2 class="nte-settings__section-title"><?php echo esc_html( $this->get_title() ); ?></h2>
			</div>
			<div class="nte-settings__section-content">
				<?php $this->render_caution_notice(); ?>
				<table class="form-table">
					<?php
					$this->render_occurrence_horizon_field( $settings );
					$this->render_ical_feed_horizon_field( $settings );
					$this->render_ical_static_feed_field( $settings );
					$this->render_rate_limit_field( $settings );
					$this->render_rate_limit_proxy_field( $settings );
					$this->render_embed_sources_field( $settings );
					$this->render_script_sources_field( $settings );
					$this->render_cart_hold_time_field( $settings );
					$this->render_category_cache_field( $settings );
					$this->render_log_retention_field( $settings );
					$this->render_frontend_branding_field( $settings );
					$this->render_delete_data_field( $settings );
					?>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Save advanced settings from form input.
	 *
	 * @param array<string, mixed> $input            Raw form input.
	 * @param array<string, mixed> $current_settings Current stored settings.
	 * @return array<string, mixed> Modified settings for this section's fields.
	 */
	public function save( array $input, array $current_settings ): array {
		$raw            = isset( $input['allowed_embed_sources'] ) ? (string) $input['allowed_embed_sources'] : '';
		$allow_insecure = ! empty( $input['allow_insecure_embed_sources'] );

		$result = self::parse_embed_sources( $raw, $allow_insecure );

		$current_settings['allowed_embed_sources']        = $result['sources'];
		$current_settings['allow_insecure_embed_sources'] = $allow_insecure;

		// CSP script-src allowlist (https only — no http opt-in for scripts).
		$raw_scripts   = isset( $input['allowed_script_sources'] ) ? (string) $input['allowed_script_sources'] : '';
		$script_result = self::parse_script_sources( $raw_scripts );

		$current_settings['allowed_script_sources'] = $script_result['sources'];

		// Surface validation feedback (rejected entries + risk warnings) as an
		// admin notice on the post-save redirect. Read by SettingsPage.
		$messages = array_merge( $result['rejected'], $result['warnings'], $script_result['rejected'], $script_result['warnings'] );
		if ( ! empty( $messages ) ) {
			set_transient( 'nettertech_events_settings_embed_notices', $messages, 30 );
		} else {
			delete_transient( 'nettertech_events_settings_embed_notices' );
		}

		return $current_settings;
	}

	/**
	 * Get boolean field keys for this section.
	 *
	 * @return array<string> List of boolean field keys.
	 */
	public function get_bool_fields(): array {
		return array( 'delete_data_on_uninstall', 'show_frontend_branding', 'ical_feed_static_mode', 'allow_insecure_embed_sources' );
	}

	/**
	 * Parse and validate the admin-entered video embed source allowlist.
	 *
	 * Accepts one origin per line. Each must be a full `scheme://host` origin.
	 * Rules:
	 * - https origins are accepted.
	 * - http origins are rejected unless `$allow_insecure` is true; when allowed
	 *   they are accepted with a warning.
	 * - Leading-subdomain wildcards (`https://*.example.com`) are accepted with a
	 *   warning; any other wildcard use is rejected.
	 * - Bare hosts, non-http(s) schemes, lone `*`, and CSP keyword tokens are
	 *   rejected. Paths/queries are dropped — only the origin is kept.
	 *
	 * Pure (no WordPress option/DB access) so it is unit-testable in isolation.
	 *
	 * @param string $raw            Raw textarea value (one origin per line).
	 * @param bool   $allow_insecure Whether http:// origins are permitted.
	 * @return array{sources: array<string>, warnings: array<string>, rejected: array<string>}
	 */
	public static function parse_embed_sources( string $raw, bool $allow_insecure ): array {
		$sources  = array();
		$warnings = array();
		$rejected = array();

		$lines = preg_split( '/[\r\n]+/', $raw );
		if ( false === $lines ) {
			$lines = array();
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			// Reject CSP keyword tokens and bare wildcards outright.
			$lower = strtolower( $line );
			if ( in_array( $lower, array( '*', 'https:', 'http:', 'data:', 'blob:' ), true ) || str_starts_with( $lower, "'" ) ) {
				/* translators: %s: the rejected source as entered. */
				$rejected[] = sprintf( __( '"%s" was not added: it is too broad or not a valid origin.', 'nettertech-events' ), $line );
				continue;
			}

			// Require a full http(s) origin: scheme://host[:port][/...].
			if ( ! preg_match( '#^(https?)://([a-z0-9.\-*]+)(?::(\d+))?(?:/.*)?$#i', $line, $m ) ) {
				/* translators: %s: the rejected source as entered. */
				$rejected[] = sprintf( __( '"%s" was not added: enter a full origin including https:// (for example, https://example.com).', 'nettertech-events' ), $line );
				continue;
			}

			$scheme = strtolower( $m[1] );
			$host   = strtolower( $m[2] );
			$port   = isset( $m[3] ) && '' !== $m[3] ? ':' . (int) $m[3] : '';

			// Wildcard handling: only a single leading-subdomain wildcard is allowed.
			if ( str_contains( $host, '*' ) && ! preg_match( '/^\*\.[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}$/', $host ) ) {
				/* translators: %s: the rejected source as entered. */
				$rejected[] = sprintf( __( '"%s" was not added: a wildcard is only allowed as a leading subdomain (for example, https://*.example.com).', 'nettertech-events' ), $line );
				continue;
			}

			if ( 'http' === $scheme && ! $allow_insecure ) {
				/* translators: %s: the rejected source as entered. */
				$rejected[] = sprintf( __( '"%s" was not added: insecure HTTP origins require the "Allow insecure (HTTP) embed sources" box to be checked.', 'nettertech-events' ), $line );
				continue;
			}

			$origin = $scheme . '://' . $host . $port;

			if ( 'http' === $scheme ) {
				/* translators: %s: the accepted origin. */
				$warnings[] = sprintf( __( '"%s" was added as an insecure (HTTP) source — it can be altered in transit and may be blocked as mixed content.', 'nettertech-events' ), $origin );
			}

			if ( str_starts_with( $host, '*.' ) ) {
				/* translators: %s: the accepted origin. */
				$warnings[] = sprintf( __( '"%s" was added with a wildcard host — the browser may frame any matching subdomain, which widens your attack surface.', 'nettertech-events' ), $origin );
			}

			if ( ! in_array( $origin, $sources, true ) ) {
				$sources[] = $origin;
			}
		}

		return array(
			'sources'  => $sources,
			'warnings' => $warnings,
			'rejected' => $rejected,
		);
	}

	/**
	 * Parse and validate the admin-entered CSP script-src allowlist.
	 *
	 * Accepts one origin per line. Each must be a full `https://host` origin.
	 * Rules (stricter than embeds — scripts are a code-execution surface):
	 * - https origins are accepted. http origins are always rejected (no opt-in).
	 * - Leading-subdomain wildcards (`https://*.example.com`) are accepted with a
	 *   warning; any other wildcard use is rejected.
	 * - Bare hosts, non-https schemes, lone `*`, and CSP keyword tokens are
	 *   rejected. Paths/queries are dropped — only the origin is kept.
	 *
	 * Pure (no WordPress option/DB access) so it is unit-testable in isolation.
	 *
	 * @param string $raw Raw textarea value (one origin per line).
	 * @return array{sources: array<string>, warnings: array<string>, rejected: array<string>}
	 */
	public static function parse_script_sources( string $raw ): array {
		$sources  = array();
		$warnings = array();
		$rejected = array();

		$lines = preg_split( '/[\r\n]+/', $raw );
		if ( false === $lines ) {
			$lines = array();
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			// Reject CSP keyword tokens and bare wildcards outright.
			$lower = strtolower( $line );
			if ( in_array( $lower, array( '*', 'https:', 'http:', 'data:', 'blob:' ), true ) || str_starts_with( $lower, "'" ) ) {
				/* translators: %s: the rejected source as entered. */
				$rejected[] = sprintf( __( '"%s" was not added: it is too broad or not a valid origin.', 'nettertech-events' ), $line );
				continue;
			}

			// Require a full https origin: https://host[:port][/...]. http is never allowed for scripts.
			if ( ! preg_match( '#^(https)://([a-z0-9.\-*]+)(?::(\d+))?(?:/.*)?$#i', $line, $m ) ) {
				/* translators: %s: the rejected source as entered. */
				$rejected[] = sprintf( __( '"%s" was not added: enter a full https origin (for example, https://cdn.example.com). HTTP script sources are not allowed.', 'nettertech-events' ), $line );
				continue;
			}

			$host = strtolower( $m[2] );
			$port = isset( $m[3] ) ? ':' . (int) $m[3] : '';

			// Wildcard handling: only a single leading-subdomain wildcard is allowed.
			if ( str_contains( $host, '*' ) && ! preg_match( '/^\*\.[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}$/', $host ) ) {
				/* translators: %s: the rejected source as entered. */
				$rejected[] = sprintf( __( '"%s" was not added: a wildcard is only allowed as a leading subdomain (for example, https://*.example.com).', 'nettertech-events' ), $line );
				continue;
			}

			$origin = 'https://' . $host . $port;

			if ( str_starts_with( $host, '*.' ) ) {
				/* translators: %s: the accepted origin. */
				$warnings[] = sprintf( __( '"%s" was added with a wildcard host — the browser may load scripts from any matching subdomain, which widens your attack surface.', 'nettertech-events' ), $origin );
			}

			if ( ! in_array( $origin, $sources, true ) ) {
				$sources[] = $origin;
			}
		}

		return array(
			'sources'  => $sources,
			'warnings' => $warnings,
			'rejected' => $rejected,
		);
	}

	/**
	 * Render caution notice.
	 *
	 * @return void
	 */
	private function render_caution_notice(): void {
		?>
		<div class="notice notice-warning inline" style="margin: 0 0 15px;">
			<p>
				<strong><?php esc_html_e( 'Caution:', 'nettertech-events' ); ?></strong>
				<?php esc_html_e( 'These settings affect system performance and behavior. Only modify if you understand the implications.', 'nettertech-events' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render occurrence horizon field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_occurrence_horizon_field( array $settings ): void {
		?>
		<tr>
			<th scope="row">
				<label for="occurrence_horizon"><?php esc_html_e( 'Recurring Event Lookahead', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input type="number" name="nettertech_events_settings[occurrence_horizon]" id="occurrence_horizon"
						value="<?php echo esc_attr( $settings['occurrence_horizon'] ?? 365 ); ?>"
						min="30" max="730" class="small-text">
				<?php esc_html_e( 'days', 'nettertech-events' ); ?>
				<p class="description">
					<?php esc_html_e( 'How far ahead to generate occurrences for recurring events.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render iCal feed horizon field.
	 *
	 * Caps the forward UNTIL emitted for open-ended (never-ending) recurring
	 * events in iCal feeds so subscribing clients do not expand the series
	 * indefinitely. Events that already define their own end (UNTIL/COUNT) are
	 * unaffected. A value of 0 disables the cap.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_ical_feed_horizon_field( array $settings ): void {
		?>
		<tr>
			<th scope="row">
				<label for="ical_feed_horizon_days"><?php esc_html_e( 'iCal Feed Horizon', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input type="number" name="nettertech_events_settings[ical_feed_horizon_days]" id="ical_feed_horizon_days"
						value="<?php echo esc_attr( $settings['ical_feed_horizon_days'] ?? 730 ); ?>"
						min="0" max="3650" class="small-text">
				<?php esc_html_e( 'days', 'nettertech-events' ); ?>
				<p class="description">
					<?php esc_html_e( 'Forward limit for never-ending recurring events in iCal feeds, so subscribers do not expand them forever. Events with their own end date are unaffected. Set to 0 for no limit.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the static iCal feed toggle and manual rebuild control.
	 *
	 * Opt-in (default off). When enabled, the feed is served from a prebuilt
	 * file regenerated (debounced) on event changes; the dynamic endpoint
	 * remains a fallback. See NTE-016.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_ical_static_feed_field( array $settings ): void {
		$enabled     = ! empty( $settings['ical_feed_static_mode'] );
		$base_path   = (string) ( $settings['events_base_path'] ?? 'events' );
		$feed_url    = home_url( '/' . $base_path . '.ics' );
		$rebuild_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=nettertech_events_regenerate_ical' ),
			'nettertech_events_regenerate_ical'
		);

		// Read-only status flag set by our own nonce-verified rebuild handler redirect.
		$rebuilt = null;
		if ( isset( $_GET['nte_ical_rebuilt'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after a nonce-verified admin-post action.
			$rebuilt = '1' === sanitize_text_field( wp_unslash( $_GET['nte_ical_rebuilt'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after a nonce-verified admin-post action.
		}
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Static iCal Feed', 'nettertech-events' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="nettertech_events_settings[ical_feed_static_mode]" value="1"
						<?php checked( $enabled ); ?>>
					<?php esc_html_e( 'Serve the calendar feed from a prebuilt static file', 'nettertech-events' ); ?>
				</label>
				<p class="description">
					<?php
					printf(
						/* translators: %s: feed URL */
						esc_html__( 'When enabled, %s is served from a file on disk (rebuilt automatically when events change) for near-zero overhead per request. The dynamic feed remains available as a fallback. Disabled by default.', 'nettertech-events' ),
						'<code>' . esc_html( $feed_url ) . '</code>'
					);
					?>
				</p>
				<?php if ( $enabled ) : ?>
					<p>
						<a href="<?php echo esc_url( $rebuild_url ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Regenerate now', 'nettertech-events' ); ?>
						</a>
						<?php if ( true === $rebuilt ) : ?>
							<span class="description" style="color:#008a20;"><?php esc_html_e( 'Static feed rebuilt.', 'nettertech-events' ); ?></span>
						<?php elseif ( false === $rebuilt ) : ?>
							<span class="description" style="color:#d63638;"><?php esc_html_e( 'Could not write the feed file. Check uploads directory permissions; the dynamic feed is still serving.', 'nettertech-events' ); ?></span>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render rate limit field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_rate_limit_field( array $settings ): void {
		?>
		<tr>
			<th scope="row">
				<label for="rate_limit_requests"><?php esc_html_e( 'API Rate Limit', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input type="number" name="nettertech_events_settings[rate_limit_requests]" id="rate_limit_requests"
						value="<?php echo esc_attr( $settings['rate_limit_requests'] ?? 60 ); ?>"
						min="10" max="1000" class="small-text">
				<?php esc_html_e( 'requests per', 'nettertech-events' ); ?>
				<input type="number" name="nettertech_events_settings[rate_limit_window]" id="rate_limit_window"
						value="<?php echo esc_attr( $settings['rate_limit_window'] ?? 60 ); ?>"
						min="10" max="3600" class="small-text">
				<?php esc_html_e( 'seconds', 'nettertech-events' ); ?>
				<p class="description">
					<?php esc_html_e( 'Maximum API requests per time window. Default: 60 requests per 60 seconds.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the rate-limit proxy handling field (NTE-142).
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_rate_limit_proxy_field( array $settings ): void {
		$mode     = (string) ( $settings['rate_limit_proxy_mode'] ?? 'auto' );
		$constant = defined( 'NETTERTECH_EVENTS_TRUSTED_PROXY' );
		$modes    = array(
			'auto'    => __( 'Auto-detect (recommended)', 'nettertech-events' ),
			'direct'  => __( 'Direct — always use the connection address', 'nettertech-events' ),
			'proxied' => __( 'Behind a proxy — trust forwarded headers', 'nettertech-events' ),
		);
		?>
		<tr>
			<th scope="row">
				<label for="rate_limit_proxy_mode"><?php esc_html_e( 'Proxy Handling', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<select name="nettertech_events_settings[rate_limit_proxy_mode]" id="rate_limit_proxy_mode" <?php disabled( $constant ); ?>>
					<?php foreach ( $modes as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $mode, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					<?php esc_html_e( 'How rate limiting identifies visitors. Auto-detect recognizes local reverse proxies and Cloudflare automatically; choose "Behind a proxy" only if Site Health reports forwarded headers being ignored on your CDN.', 'nettertech-events' ); ?>
					<?php if ( $constant ) : ?>
						<br><strong><?php esc_html_e( 'Currently overridden by the NETTERTECH_EVENTS_TRUSTED_PROXY constant in wp-config.php.', 'nettertech-events' ); ?></strong>
					<?php endif; ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the allowed video embed sources field.
	 *
	 * Lets the admin extend the CSP frame-src allowlist beyond the built-in
	 * YouTube/Vimeo defaults, with an explicit opt-in for insecure (HTTP)
	 * origins. Validation, warnings, and rejections happen on save (see
	 * self::parse_embed_sources() and self::save()).
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_embed_sources_field( array $settings ): void {
		$sources = $settings['allowed_embed_sources'] ?? array();
		if ( ! is_array( $sources ) ) {
			$sources = array();
		}
		$value          = implode( "\n", array_map( 'strval', $sources ) );
		$allow_insecure = ! empty( $settings['allow_insecure_embed_sources'] );
		?>
		<tr>
			<th scope="row">
				<label for="allowed_embed_sources"><?php esc_html_e( 'Allowed Video Embed Sources', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<textarea name="nettertech_events_settings[allowed_embed_sources]" id="allowed_embed_sources"
						rows="4" cols="50" class="large-text code"
						placeholder="https://video.example.com"><?php echo esc_textarea( $value ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'YouTube and Vimeo embeds work out of the box. Add one origin per line (scheme + host, e.g. https://video.example.com) to permit video embeds from additional providers in event descriptions. Each origin you add is something the browser will be allowed to load in a frame, so add only sources you trust. Paths are ignored — only the origin is used.', 'nettertech-events' ); ?>
				</p>
				<label style="display:block; margin-top:8px;">
					<input type="checkbox" name="nettertech_events_settings[allow_insecure_embed_sources]" value="1"
						<?php checked( $allow_insecure ); ?>>
					<?php esc_html_e( 'Allow insecure (HTTP) embed sources', 'nettertech-events' ); ?>
				</label>
				<p class="description" style="color:#d63638;">
					<?php esc_html_e( 'Leave this off unless you specifically need an http:// source. Insecure origins can be altered in transit and may be blocked as mixed content on HTTPS pages. http:// origins are rejected on save unless you turn this on.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the CSP script-src allowlist field.
	 *
	 * Lets the admin extend the public Content-Security-Policy script-src
	 * allowlist beyond the built-in 'self' 'unsafe-inline' keywords. Only https
	 * origins are accepted — there is no insecure (HTTP) opt-in for scripts, since
	 * an attacker-controlled script origin is a direct code-execution risk.
	 * Validation, warnings, and rejections happen on save (see
	 * self::parse_script_sources() and self::save()).
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_script_sources_field( array $settings ): void {
		$sources = $settings['allowed_script_sources'] ?? array();
		if ( ! is_array( $sources ) ) {
			$sources = array();
		}
		$value = implode( "\n", array_map( 'strval', $sources ) );
		?>
		<tr>
			<th scope="row">
				<label for="allowed_script_sources"><?php esc_html_e( 'Allowed Script Sources', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<textarea name="nettertech_events_settings[allowed_script_sources]" id="allowed_script_sources"
						rows="4" cols="50" class="large-text code"
						placeholder="https://cdn.example.com"><?php echo esc_textarea( $value ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'Scripts from your own site work out of the box. Add one https origin per line (scheme + host, e.g. https://cdn.example.com) to permit scripts the browser may load and execute from additional providers. Every origin you add can run code on your public pages, so add only sources you fully trust. HTTP origins are not allowed. Paths are ignored — only the origin is used.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render cart hold time field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_cart_hold_time_field( array $settings ): void {
		?>
		<tr>
			<th scope="row">
				<label for="pending_hold_time"><?php esc_html_e( 'Cart Hold Time', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input type="number" name="nettertech_events_settings[pending_hold_time]" id="pending_hold_time"
						value="<?php echo esc_attr( $settings['pending_hold_time'] ?? 900 ); ?>"
						min="60" max="3600" class="small-text">
				<?php esc_html_e( 'seconds', 'nettertech-events' ); ?>
				<p class="description">
					<?php esc_html_e( 'How long to hold ticket capacity while items are in cart. Default: 900 (15 minutes).', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render category cache TTL field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_category_cache_field( array $settings ): void {
		?>
		<tr>
			<th scope="row">
				<label for="category_cache_ttl"><?php esc_html_e( 'Category Cache Duration', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input type="number" name="nettertech_events_settings[category_cache_ttl]" id="category_cache_ttl"
						value="<?php echo esc_attr( $settings['category_cache_ttl'] ?? 3600 ); ?>"
						min="60" max="86400" class="small-text">
				<?php esc_html_e( 'seconds', 'nettertech-events' ); ?>
				<p class="description">
					<?php esc_html_e( 'How long to cache category dropdowns. Default: 3600 (1 hour).', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render activity log retention field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_log_retention_field( array $settings ): void {
		?>
		<tr>
			<th scope="row">
				<label for="activity_log_retention_days"><?php esc_html_e( 'Activity Log Retention', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input type="number" name="nettertech_events_settings[activity_log_retention_days]" id="activity_log_retention_days"
						value="<?php echo esc_attr( $settings['activity_log_retention_days'] ?? 90 ); ?>"
						min="7" max="365" class="small-text">
				<?php esc_html_e( 'days', 'nettertech-events' ); ?>
				<p class="description">
					<?php esc_html_e( 'How long to keep activity log entries before automatic cleanup. Default: 90 days.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render optional frontend branding field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_frontend_branding_field( array $settings ): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Help others discover NetterTech Events', 'nettertech-events' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="nettertech_events_settings[show_frontend_branding]" value="1"
						<?php checked( $settings['show_frontend_branding'] ?? false ); ?>>
					<?php esc_html_e( 'Show a small "Powered by NetterTech Events" badge below event displays', 'nettertech-events' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Adds a small public linked badge below event displays. Disabled by default.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render delete data on uninstall field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_delete_data_field( array $settings ): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Delete Data on Uninstall', 'nettertech-events' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="nettertech_events_settings[delete_data_on_uninstall]" value="1"
						<?php checked( $settings['delete_data_on_uninstall'] ?? false ); ?>>
					<?php esc_html_e( 'Remove all plugin data when uninstalling', 'nettertech-events' ); ?>
				</label>
				<p class="description" style="color: #d63638;">
					<?php esc_html_e( 'Warning: This will permanently delete all events, tickets, and attendee data.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}
}
