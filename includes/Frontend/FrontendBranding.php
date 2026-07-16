<?php
/**
 * Frontend branding class.
 *
 * Displays optional "Powered by NetterTech Events" branding on frontend
 * event displays when the site owner explicitly opts in.
 *
 * @package NetterTechEvents\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\NetterTechEventsSettings;
use NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput;

/**
 * Handles plugin branding on public-facing event displays.
 *
 * @since 0.9.4
 * @api
 */
class FrontendBranding {

	/**
	 * Plugin name for display.
	 *
	 * @var string
	 */
	public const PLUGIN_NAME = 'NetterTech Events';

	/**
	 * Developer name.
	 *
	 * @var string
	 */
	public const DEVELOPER_NAME = 'NetterTech';

	/**
	 * Developer URL.
	 *
	 * @var string
	 */
	public const DEVELOPER_URL = 'https://nettertech.com';

	/**
	 * Logo filename for frontend (smaller version).
	 *
	 * @var string
	 */
	public const LOGO_FILENAME = 'nettercap-logo.png';

	/**
	 * Flag indicating VE content was rendered on this page.
	 *
	 * @var bool
	 */
	private static bool $content_rendered = false;

	/**
	 * Initialize frontend branding hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// No-op: badges are now rendered per-component via render_badge().
	}

	/**
	 * Mark that VE content has been rendered on this page.
	 *
	 * Call this from templates, shortcodes, or blocks when rendering VE content.
	 *
	 * @return void
	 */
	public static function mark_content_rendered(): void {
		self::$content_rendered = true;
	}

	/**
	 * Check if VE content was rendered.
	 *
	 * @return bool True if VE content was rendered.
	 */
	public static function was_content_rendered(): bool {
		return self::$content_rendered;
	}

	/**
	 * Check if branding should be displayed.
	 *
	 * @return bool True if branding should be shown.
	 */
	public static function should_show_branding(): bool {
		$settings = NetterTechEventsSettings::from_option();

		// WordPress.org Guideline 10 requires public-facing credit links to be opt-in.
		return (bool) apply_filters( 'nettertech_events_show_frontend_branding', $settings->advanced->show_frontend_branding );
	}

	/**
	 * Conditionally render branding in footer.
	 *
	 * Only outputs if NTE content was rendered on the page.
	 *
	 * @return void
	 */
	public static function maybe_render_branding(): void {
		if ( ! self::$content_rendered ) {
			return;
		}

		if ( ! self::should_show_branding() ) {
			return;
		}

		self::render();
	}

	/**
	 * Get the logo URL.
	 *
	 * Falls back to main logo if small version doesn't exist.
	 *
	 * @return string Logo URL.
	 */
	public static function get_logo_url(): string {
		$small_logo = plugins_url( 'assets/images/' . self::LOGO_FILENAME, dirname( __DIR__ ) );
		$main_logo  = plugins_url( 'assets/images/nettercap-logo.png', dirname( __DIR__ ) );

		// Check if small logo file exists, fall back to main logo.
		$small_logo_path = plugin_dir_path( dirname( __DIR__ ) ) . 'assets/images/' . self::LOGO_FILENAME;

		return file_exists( $small_logo_path ) ? $small_logo : $main_logo;
	}

	/**
	 * Return branding badge HTML for a single component.
	 *
	 * Call from each VE component's render method. Styles are
	 * output only on the first call per page load.
	 *
	 * @since 1.0.2
	 *
	 * @return string Badge HTML, or empty string if branding is disabled.
	 */
	public static function render_badge(): string {
		if ( ! self::should_show_branding() ) {
			return '';
		}

		self::mark_content_rendered();

		$logo_url = self::get_logo_url();

		ob_start();
		?>
		<div class="nte-frontend-branding" aria-label="<?php esc_attr_e( 'Powered by NetterTech Events', 'nettertech-events' ); ?>">
			<a
				href="<?php echo esc_url( self::DEVELOPER_URL ); ?>"
				target="_blank"
				rel="noopener noreferrer"
				class="nte-frontend-branding__link"
				title="<?php echo esc_attr( self::PLUGIN_NAME . ' by ' . self::DEVELOPER_NAME ); ?>"
			>
				<img
					src="<?php echo esc_url( $logo_url ); ?>"
					alt="<?php echo esc_attr( self::DEVELOPER_NAME ); ?>"
					class="nte-frontend-branding__logo"
					width="16"
					height="16"
				>
				<span class="nte-frontend-branding__text">
					<?php
					printf(
						/* translators: %s: Plugin name */
						esc_html__( 'Powered by %s', 'nettertech-events' ),
						esc_html( self::PLUGIN_NAME )
					);
					?>
				</span>
			</a>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render the frontend branding badge (echo version).
	 *
	 * @return void
	 */
	public static function render(): void {
		echo wp_kses( self::render_badge(), ShortcodeOutput::get_allowlist() );
	}
}
