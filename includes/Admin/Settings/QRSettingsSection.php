<?php
/**
 * QR Settings Section.
 *
 * @package NetterTechEvents\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Settings;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\QRGeneratorPage;
use NetterTechEvents\Utilities\ImageHelper;

/**
 * Handles QR code settings section rendering.
 *
 * Extracted from SettingsPage to reduce class complexity.
 * Manages QR code appearance, logo options, and preview functionality.
 *
 * @since 1.1.0
 * @api
 */
class QRSettingsSection implements SettingsSectionInterface {

	/**
	 * Get the section identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'qr-codes';
	}

	/**
	 * Get the section title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'QR Codes', 'nettertech-events' );
	}

	/**
	 * Render the QR codes settings section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	public function render( array $settings ): void {
		// Get current logo settings.
		$logo_mode = $settings['qr_default_logo_mode'] ?? 'none';
		$logo_id   = absint( $settings['qr_default_logo_id'] ?? 0 );

		// Get site logo info (custom_logo → site_logo → site_icon fallback).
		$site_logo_id = get_theme_mod( 'custom_logo' );
		if ( empty( $site_logo_id ) ) {
			$site_logo_id = get_option( 'site_logo' );
		}
		if ( empty( $site_logo_id ) ) {
			$site_logo_id = get_option( 'site_icon' );
		}
		$site_logo_url = $site_logo_id ? ( ImageHelper::get_attachment_image_url( (int) $site_logo_id, 'thumbnail' ) ?? '' ) : '';
		$has_site_logo = ! empty( $site_logo_url );

		// Get custom logo URL.
		$custom_logo_url = $logo_id ? ( ImageHelper::get_attachment_image_url( $logo_id, 'thumbnail' ) ?? '' ) : '';

		$this->enqueue_assets( $logo_mode, $logo_id, $site_logo_url, $has_site_logo, $custom_logo_url );

		?>
		<div class="nte-settings__section">
			<div class="nte-settings__section-header">
				<h2 class="nte-settings__section-title"><?php echo esc_html( $this->get_title() ); ?></h2>
			</div>
			<div class="nte-settings__section-content">
				<p class="description" style="margin-bottom: 15px;">
					<?php esc_html_e( 'Customize the default appearance of QR codes on tickets and check-in materials.', 'nettertech-events' ); ?>
				</p>

				<div class="nte-qr-settings-grid" style="display: grid; grid-template-columns: 1fr 200px; gap: 30px; align-items: start;">
					<div class="nte-qr-settings-form">
						<table class="form-table">
							<?php
							$this->render_foreground_color_field( $settings );
							$this->render_background_color_field( $settings );
							$this->render_background_opacity_field( $settings );
							$this->render_scale_field( $settings );
							$this->render_dot_style_field( $settings );
							$this->render_finder_style_field( $settings );
							$this->render_logo_options_field( $logo_mode, $has_site_logo, $site_logo_url, $custom_logo_url );
							?>
						</table>
					</div>

					<?php $this->render_preview_panel(); ?>
				</div>
			</div>
		</div>

		<?php
	}

	/**
	 * Enqueue required assets.
	 *
	 * @param string $logo_mode       Current logo mode.
	 * @param int    $logo_id         Custom logo attachment ID.
	 * @param string $site_logo_url   Site logo URL.
	 * @param bool   $has_site_logo   Whether site has a logo.
	 * @param string $custom_logo_url Custom logo URL.
	 * @return void
	 */
	private function enqueue_assets(
		string $logo_mode,
		int $logo_id,
		string $site_logo_url,
		bool $has_site_logo,
		string $custom_logo_url
	): void {
		wp_enqueue_media();

		wp_enqueue_script(
			'nettertech-events-settings-qr-logo',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/settings-qr-logo.js',
			array( 'jquery' ),
			NETTERTECH_EVENTS_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_localize_script(
			'nettertech-events-settings-qr-logo',
			'nettertechEventsQrLogoSettings',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'nettertech_events_qr_generator' ),
				'siteLogoUrl'   => $site_logo_url,
				'hasSiteLogo'   => $has_site_logo,
				'customLogoUrl' => $custom_logo_url,
				'logoMode'      => $logo_mode,
				'logoId'        => $logo_id,
				'i18n'          => array(
					'selectLogo'     => __( 'Select Logo', 'nettertech-events' ),
					'useLogo'        => __( 'Use this logo', 'nettertech-events' ),
					'noSiteLogo'     => __( 'No site logo configured', 'nettertech-events' ),
					'generating'     => __( 'Generating...', 'nettertech-events' ),
					'previewContent' => home_url( '/' ),
				),
			)
		);
	}

	/**
	 * Render foreground color field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_foreground_color_field( array $settings ): void {
		$stored_color = ltrim( $settings['qr_foreground_color'] ?? '#000000', '#' );
		$palette      = QRGeneratorPage::COLOR_PALETTE;

		// Determine if stored color is a custom (non-palette) hex.
		$is_custom_color = false;
		if ( ! array_key_exists( $stored_color, $palette ) ) {
			// Validate as a 6-char hex; if valid, treat as custom; otherwise fall back to black.
			if ( preg_match( '/^[0-9a-fA-F]{6}$/', $stored_color ) ) {
				$is_custom_color = true;
			} else {
				$stored_color = '000000';
			}
		}

		?>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Foreground Color', 'nettertech-events' ); ?>
			</th>
			<td>
				<input type="hidden" name="nettertech_events_settings[qr_foreground_color]"
						id="qr_foreground_color"
						value="#<?php echo esc_attr( $stored_color ); ?>"
						class="nte-qr-setting-input">
				<div class="nte-qr-color-palette" role="radiogroup"
						aria-label="<?php esc_attr_e( 'Foreground Color', 'nettertech-events' ); ?>">
					<?php foreach ( $palette as $hex => $label ) : ?>
						<label class="nte-qr-color-option">
							<input type="radio"
								name="nte-qr-settings-color"
								value="<?php echo esc_attr( $hex ); ?>"
								<?php checked( ! $is_custom_color && $stored_color === $hex ); ?>>
							<span class="nte-qr-color-swatch"
								style="background-color: #<?php echo esc_attr( $hex ); ?>;"
								title="<?php echo esc_attr( $label ); ?>"></span>
							<span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
					<label class="nte-qr-color-option">
						<input type="radio"
							name="nte-qr-settings-color"
							value="<?php echo $is_custom_color ? esc_attr( $stored_color ) : ''; ?>"
							class="nte-qr-custom-color-radio"
							<?php checked( $is_custom_color ); ?>>
						<span class="nte-qr-color-swatch nte-qr-color-swatch--custom<?php echo $is_custom_color ? ' has-color' : ''; ?>"
							title="<?php esc_attr_e( 'Custom', 'nettertech-events' ); ?>"
							<?php if ( $is_custom_color ) : ?>
								style="background-color: #<?php echo esc_attr( $stored_color ); ?>;"
							<?php endif; ?>>
							<span class="dashicons dashicons-color-picker"></span>
						</span>
						<span class="screen-reader-text"><?php esc_html_e( 'Custom', 'nettertech-events' ); ?></span>
						<input type="color"
							value="#<?php echo esc_attr( $is_custom_color ? $stored_color : '000000' ); ?>"
							class="nte-qr-custom-color-picker"
							style="position: absolute; opacity: 0; pointer-events: none;"
							aria-hidden="true" tabindex="-1">
					</label>
				</div>
				<p class="description">
					<?php esc_html_e( 'The color of the QR code modules. Default: black.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render background color field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_background_color_field( array $settings ): void {
		?>
		<tr>
			<th scope="row">
				<label for="qr_background_color"><?php esc_html_e( 'Background Color', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input type="color" name="nettertech_events_settings[qr_background_color]" id="qr_background_color"
						value="<?php echo esc_attr( $settings['qr_background_color'] ?? '#ffffff' ); ?>"
						class="nte-qr-setting-input">
				<p class="description">
					<?php esc_html_e( 'The background color of QR codes. Default: white.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render background opacity field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_background_opacity_field( array $settings ): void {
		$opacity = (int) ( $settings['qr_bg_opacity'] ?? 100 );
		?>
		<tr>
			<th scope="row">
				<label for="qr_bg_opacity"><?php esc_html_e( 'Background Opacity', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<div style="display: flex; align-items: center; gap: 10px;">
					<input type="range"
						name="nettertech_events_settings[qr_bg_opacity]"
						id="qr_bg_opacity"
						min="0" max="100" step="1"
						value="<?php echo esc_attr( (string) $opacity ); ?>"
						class="nte-qr-setting-input"
						style="width: 200px;">
					<span id="qr_bg_opacity_value" style="font-weight: 500; min-width: 40px;"><?php echo esc_html( $opacity . '%' ); ?></span>
				</div>
				<p class="description">
					<?php esc_html_e( 'Background transparency. 100% = fully opaque, 0% = fully transparent.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render scale field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_scale_field( array $settings ): void {
		?>
		<tr>
			<th scope="row">
				<label for="qr_scale"><?php esc_html_e( 'Scale', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<select name="nettertech_events_settings[qr_scale]" id="qr_scale" class="nte-qr-setting-input">
					<?php
					$current_scale = (int) ( $settings['qr_scale'] ?? 5 );
					for ( $i = 3; $i <= 20; $i++ ) {
						printf(
							'<option value="%d"%s>%d px</option>',
							(int) $i,
							selected( $current_scale, $i, false ),
							(int) $i
						);
					}
					?>
				</select>
				<p class="description">
					<?php esc_html_e( 'Size of each dot in the QR code. Higher values produce larger images.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render dot style field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_dot_style_field( array $settings ): void {
		$dot_style = $settings['qr_dot_style'] ?? 'rounded';
		?>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Dot Shape', 'nettertech-events' ); ?>
			</th>
			<td>
				<fieldset>
					<label style="margin-right: 16px;">
						<input type="radio"
							name="nettertech_events_settings[qr_dot_style]"
							value="rounded"
							class="nte-qr-setting-input"
							<?php checked( $dot_style, 'rounded' ); ?>>
						<?php esc_html_e( 'Rounded', 'nettertech-events' ); ?>
					</label>
					<label>
						<input type="radio"
							name="nettertech_events_settings[qr_dot_style]"
							value="square"
							class="nte-qr-setting-input"
							<?php checked( $dot_style, 'square' ); ?>>
						<?php esc_html_e( 'Square', 'nettertech-events' ); ?>
					</label>
				</fieldset>
				<p class="description">
					<?php esc_html_e( 'Shape of the individual QR code modules. Default: rounded.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render finder style field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_finder_style_field( array $settings ): void {
		$finder_style = $settings['qr_finder_style'] ?? 'square';
		?>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Finder Corners', 'nettertech-events' ); ?>
			</th>
			<td>
				<fieldset>
					<label style="margin-right: 16px;">
						<input type="radio"
							name="nettertech_events_settings[qr_finder_style]"
							value="square"
							class="nte-qr-setting-input"
							<?php checked( $finder_style, 'square' ); ?>>
						<?php esc_html_e( 'Square', 'nettertech-events' ); ?>
					</label>
					<label>
						<input type="radio"
							name="nettertech_events_settings[qr_finder_style]"
							value="rounded"
							class="nte-qr-setting-input"
							<?php checked( $finder_style, 'rounded' ); ?>>
						<?php esc_html_e( 'Rounded', 'nettertech-events' ); ?>
					</label>
				</fieldset>
				<p class="description">
					<?php esc_html_e( 'Shape of the three large corner squares in the QR code. Default: square.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render logo options field.
	 *
	 * @param string $logo_mode       Current logo mode.
	 * @param bool   $has_site_logo   Whether site has a logo.
	 * @param string $site_logo_url   Site logo URL.
	 * @param string $custom_logo_url Custom logo URL.
	 * @return void
	 */
	private function render_logo_options_field(
		string $logo_mode,
		bool $has_site_logo,
		string $site_logo_url,
		string $custom_logo_url
	): void {
		?>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Default Logo', 'nettertech-events' ); ?>
			</th>
			<td>
				<input type="hidden" name="nettertech_events_settings[qr_default_logo_mode]"
						id="qr_default_logo_mode" value="<?php echo esc_attr( $logo_mode ); ?>">
				<input type="hidden" name="nettertech_events_settings[qr_default_logo_id]"
						id="qr_default_logo_id" value="">

				<div class="nte-qr-logo-options" role="radiogroup" aria-label="<?php esc_attr_e( 'Logo options', 'nettertech-events' ); ?>">
					<?php
					$this->render_no_logo_option( $logo_mode );
					$this->render_site_logo_option( $logo_mode, $has_site_logo, $site_logo_url );
					$this->render_custom_logo_option( $logo_mode, $custom_logo_url );
					?>
				</div>

				<p class="description" style="margin-top: 12px;">
					<?php esc_html_e( 'Logo will be centered in the QR code. Works best with simple, high-contrast logos.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render no logo option.
	 *
	 * @param string $logo_mode Current logo mode.
	 * @return void
	 */
	private function render_no_logo_option( string $logo_mode ): void {
		?>
		<label class="nte-qr-logo-option <?php echo 'none' === $logo_mode ? 'nte-qr-logo-option--selected' : ''; ?>"
				data-mode="none" tabindex="0" role="radio"
				aria-checked="<?php echo 'none' === $logo_mode ? 'true' : 'false'; ?>">
			<span class="nte-qr-logo-option__preview nte-qr-logo-option__preview--icon">
				<span class="dashicons dashicons-no-alt"></span>
			</span>
			<span class="nte-qr-logo-option__label"><?php esc_html_e( 'No Logo', 'nettertech-events' ); ?></span>
			<span class="nte-qr-logo-option__desc"><?php esc_html_e( 'QR code without logo', 'nettertech-events' ); ?></span>
		</label>
		<?php
	}

	/**
	 * Render site logo option.
	 *
	 * @param string $logo_mode     Current logo mode.
	 * @param bool   $has_site_logo Whether site has a logo.
	 * @param string $site_logo_url Site logo URL.
	 * @return void
	 */
	private function render_site_logo_option( string $logo_mode, bool $has_site_logo, string $site_logo_url ): void {
		?>
		<label class="nte-qr-logo-option <?php echo 'site' === $logo_mode ? 'nte-qr-logo-option--selected' : ''; ?> <?php echo ! $has_site_logo ? 'nte-qr-logo-option--disabled' : ''; ?>"
				data-mode="site" tabindex="0" role="radio"
				aria-checked="<?php echo 'site' === $logo_mode ? 'true' : 'false'; ?>"
				<?php echo ! $has_site_logo ? 'aria-disabled="true"' : ''; ?>>
			<span class="nte-qr-logo-option__preview">
				<?php if ( $has_site_logo ) : ?>
					<img src="<?php echo esc_url( $site_logo_url ); ?>" alt="<?php esc_attr_e( 'Site logo', 'nettertech-events' ); ?>">
				<?php else : ?>
					<span class="dashicons dashicons-format-image"></span>
				<?php endif; ?>
			</span>
			<span class="nte-qr-logo-option__label"><?php esc_html_e( 'Site Logo', 'nettertech-events' ); ?></span>
			<span class="nte-qr-logo-option__desc">
				<?php if ( $has_site_logo ) : ?>
					<?php esc_html_e( 'Uses theme customizer logo', 'nettertech-events' ); ?>
				<?php else : ?>
					<span class="nte-qr-logo-option__warning"><?php esc_html_e( 'Not configured', 'nettertech-events' ); ?></span>
				<?php endif; ?>
			</span>
		</label>
		<?php
	}

	/**
	 * Render custom logo option.
	 *
	 * @param string $logo_mode       Current logo mode.
	 * @param string $custom_logo_url Custom logo URL.
	 * @return void
	 */
	private function render_custom_logo_option( string $logo_mode, string $custom_logo_url ): void {
		?>
		<label class="nte-qr-logo-option <?php echo 'custom' === $logo_mode ? 'nte-qr-logo-option--selected' : ''; ?>"
				data-mode="custom" tabindex="0" role="radio"
				aria-checked="<?php echo 'custom' === $logo_mode ? 'true' : 'false'; ?>">
			<span class="nte-qr-logo-option__preview" id="nte-qr-custom-logo-preview">
				<?php if ( $custom_logo_url ) : ?>
					<img src="<?php echo esc_url( $custom_logo_url ); ?>" alt="<?php esc_attr_e( 'Custom logo', 'nettertech-events' ); ?>">
				<?php else : ?>
					<span class="dashicons dashicons-plus-alt2"></span>
				<?php endif; ?>
			</span>
			<span class="nte-qr-logo-option__label"><?php esc_html_e( 'Custom Logo', 'nettertech-events' ); ?></span>
			<span class="nte-qr-logo-option__desc">
				<button type="button" class="button button-small" id="nte-qr-select-logo">
					<?php echo $custom_logo_url ? esc_html__( 'Change', 'nettertech-events' ) : esc_html__( 'Select', 'nettertech-events' ); ?>
				</button>
				<?php if ( $custom_logo_url ) : ?>
					<button type="button" class="button-link" id="nte-qr-remove-logo" style="margin-left: 8px; color: #b32d2e;">
						<?php esc_html_e( 'Remove', 'nettertech-events' ); ?>
					</button>
				<?php endif; ?>
			</span>
		</label>
		<?php
	}

	/**
	 * Render preview panel.
	 *
	 * @return void
	 */
	private function render_preview_panel(): void {
		?>
		<div class="nte-qr-preview-panel">
			<h4 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 600;"><?php esc_html_e( 'Preview', 'nettertech-events' ); ?></h4>
			<div id="nte-qr-settings-preview" style="background-color: #f0f0f1; border-radius: 4px; padding: 15px; text-align: center; position: relative; min-height: 150px;">
				<div id="nte-qr-preview-loading" style="display: none; position: absolute; inset: 0; background: rgba(255,255,255,0.8); align-items: center; justify-content: center;">
					<span class="spinner is-active" style="float: none;"></span>
				</div>
				<img id="nte-qr-preview-image" src="" alt="<?php esc_attr_e( 'QR Code Preview', 'nettertech-events' ); ?>" style="max-width: 100%; height: auto;">
			</div>
			<p class="description" style="margin-top: 8px; text-align: center; font-size: 11px;">
				<?php esc_html_e( 'Sample QR with current settings', 'nettertech-events' ); ?>
				<br>
				<span id="nte-qr-preview-dimensions" style="color: #787c82;"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Save QR code settings from form input.
	 *
	 * Handles foreground color hex validation and background color sanitization.
	 *
	 * @param array<string, mixed> $input            Raw form input.
	 * @param array<string, mixed> $current_settings Current stored settings.
	 * @return array<string, mixed> Modified settings for this section's fields.
	 */
	public function save( array $input, array $current_settings ): array {
		// Resolve from container (closes SA-19 DI-bypass): SettingsSanitizer is a
		// stateless singleton registered in AdminServiceProvider.
		$sanitizer = \NetterTechEvents\nettertech_events_container()->get( \NetterTechEvents\Admin\SettingsSanitizer::class );

		$fg_color_raw = strtolower( ltrim( $input['qr_foreground_color'] ?? '', '#' ) );
		if ( ! preg_match( '/^[0-9a-f]{6}$/', $fg_color_raw ) ) {
			$fg_color_raw = '000000';
		}
		$current_settings['qr_foreground_color'] = '#' . $fg_color_raw;
		$current_settings['qr_background_color'] = $sanitizer->sanitize_hex_color( $input['qr_background_color'] ?? null, '#ffffff' );

		return $current_settings;
	}

	/**
	 * Get boolean field keys for this section.
	 *
	 * @return array<string> List of boolean field keys.
	 */
	public function get_bool_fields(): array {
		return array();
	}
}
