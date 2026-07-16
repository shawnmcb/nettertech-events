<?php
/**
 * Admin branding class.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin branding throughout the admin interface.
 *
 * @since 0.8.0
 * @api
 */
class Branding {

	/**
	 * Plugin name.
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
	 * Logo filename.
	 *
	 * @var string
	 */
	public const LOGO_FILENAME = 'nettercap-logo.png';

	/**
	 * Get the logo URL.
	 *
	 * @return string Logo URL.
	 */
	public static function get_logo_url(): string {
		return plugins_url( 'assets/images/' . self::LOGO_FILENAME, dirname( __DIR__ ) );
	}

	/**
	 * Render the branding header.
	 *
	 * Displays the NetterTech logo with plugin name and developer credit.
	 * Use this at the top of all admin pages for consistent branding.
	 *
	 * @return void
	 */
	public static function render_header(): void {
		$logo_url = self::get_logo_url();
		?>
		<div class="nte-branding-header" style="margin-bottom: 20px;">
			<a href="<?php echo esc_url( self::DEVELOPER_URL ); ?>" target="_blank" rel="noopener noreferrer" style="display: inline-flex; align-items: center; gap: 10px; padding: 8px 14px 8px 8px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; text-decoration: none; color: #1d2327; font-size: 14px; font-weight: 600;">
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( self::DEVELOPER_NAME ); ?>" style="height: 32px; width: auto;">
				<?php echo esc_html( self::PLUGIN_NAME ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Render a compact branding footer.
	 *
	 * Displays a smaller credit line suitable for page footers.
	 *
	 * @return void
	 */
	public static function render_footer(): void {
		?>
		<div class="nte-branding-footer" style="margin-top: 20px; padding-top: 12px; border-top: 1px solid #dcdcde; text-align: right;">
			<span style="color: #50575e; font-size: 12px;">
				<?php echo esc_html( self::PLUGIN_NAME ); ?>
				<?php esc_html_e( 'by', 'nettertech-events' ); ?>
				<a href="<?php echo esc_url( self::DEVELOPER_URL ); ?>" target="_blank" rel="noopener noreferrer" style="color: #2271b1; text-decoration: none;">
					<?php echo esc_html( self::DEVELOPER_NAME ); ?>
				</a>
			</span>
		</div>
		<?php
	}
}