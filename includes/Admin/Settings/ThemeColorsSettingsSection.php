<?php
/**
 * Theme Colors Settings Section.
 *
 * @package NetterTechEvents\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Settings;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Services\PaletteResolver;

/**
 * Handles theme color settings section rendering.
 *
 * Shows auto-detected palette colors as preview swatches and
 * provides optional manual color overrides for each semantic role.
 *
 * @since 1.4.0
 * @api
 */
class ThemeColorsSettingsSection implements SettingsSectionInterface {

	/**
	 * Semantic color roles and their display labels.
	 *
	 * @var array<string, string>
	 */
	private const COLOR_ROLES = array(
		'primary'        => 'Primary',
		'primary_hover'  => 'Primary Hover',
		'text'           => 'Text',
		'text_muted'     => 'Text Muted',
		'border'         => 'Border',
		'background'     => 'Background',
		'background_alt' => 'Background Alt',
	);

	/**
	 * Fallback defaults when no theme palette is detected.
	 *
	 * @var array<string, string>
	 */
	private const FALLBACK_DEFAULTS = array(
		'primary'        => '#2563eb',
		'primary_hover'  => '#1d4ed8',
		'text'           => '#1f2937',
		'text_muted'     => '#6b7280',
		'border'         => '#e5e7eb',
		'background'     => '#ffffff',
		'background_alt' => '#f9fafb',
	);

	/**
	 * Palette resolver for theme color detection.
	 *
	 * @var PaletteResolver
	 */
	private PaletteResolver $palette_resolver;

	/**
	 * Constructor.
	 *
	 * @param PaletteResolver $palette_resolver Palette resolver.
	 */
	public function __construct( PaletteResolver $palette_resolver ) {
		$this->palette_resolver = $palette_resolver;
	}

	/**
	 * Get the section identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'theme-colors';
	}

	/**
	 * Get the section title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Theme Colors', 'nettertech-events' );
	}

	/**
	 * Render the theme colors settings section.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	public function render( array $settings ): void {
		$theme_colors = $settings['theme_colors'] ?? array();
		$customize    = ! empty( $theme_colors['customize'] );
		$detected     = $this->palette_resolver->resolve();

		?>
		<div class="nte-settings__section">
			<div class="nte-settings__section-header">
				<h2 class="nte-settings__section-title"><?php echo esc_html( $this->get_title() ); ?></h2>
			</div>
			<div class="nte-settings__section-content">
				<p class="description" style="margin-bottom: 15px;">
					<?php esc_html_e( 'NetterTech Events automatically inherits your theme\'s color palette. Use the swatches below to verify detected colors or customize them manually.', 'nettertech-events' ); ?>
				</p>

				<?php $this->render_detected_swatches( $detected ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Customize Colors', 'nettertech-events' ); ?></th>
						<td>
							<label>
								<input type="checkbox"
									name="nettertech_events_settings[theme_colors][customize]"
									id="nettertech_events_theme_colors_customize"
									value="1"
									<?php checked( $customize ); ?>>
								<?php esc_html_e( 'Override auto-detected colors with custom values', 'nettertech-events' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<div id="nte-theme-colors-custom" style="<?php echo ! $customize ? 'display:none;' : ''; ?>">
					<table class="form-table">
						<?php
						foreach ( self::COLOR_ROLES as $role => $label ) {
							$this->render_color_field( $role, $label, $theme_colors, $detected );
						}
						?>
					</table>
					<p>
						<a href="#" id="nte-theme-colors-reset" class="button button-secondary">
							<?php esc_html_e( 'Reset to auto-detected', 'nettertech-events' ); ?>
						</a>
					</p>
				</div>
			</div>
		</div>

		<?php
	}

	/**
	 * Save theme color settings from form input.
	 *
	 * Processes the theme_colors sub-array: customize toggle and per-role hex colors.
	 *
	 * @param array<string, mixed> $input            Raw form input.
	 * @param array<string, mixed> $current_settings Current stored settings.
	 * @return array<string, mixed> Modified settings for this section's fields.
	 */
	public function save( array $input, array $current_settings ): array {
		// Resolve from container (closes SA-19 DI-bypass): SettingsSanitizer is a
		// stateless singleton registered in AdminServiceProvider.
		$sanitizer = \NetterTechEvents\nettertech_events_container()->get( \NetterTechEvents\Admin\SettingsSanitizer::class );

		$theme_colors_input = $input['theme_colors'] ?? array();
		$theme_colors       = array( 'customize' => ! empty( $theme_colors_input['customize'] ) );

		foreach ( array_keys( self::COLOR_ROLES ) as $role ) {
			$theme_colors[ $role ] = $sanitizer->sanitize_hex_color( $theme_colors_input[ $role ] ?? null, '' );
		}

		$current_settings['theme_colors'] = $theme_colors;

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

	/**
	 * Render the auto-detected color swatches.
	 *
	 * @param array<string, string> $detected Detected role => hex color pairs.
	 * @return void
	 */
	private function render_detected_swatches( array $detected ): void {
		if ( empty( $detected ) ) {
			?>
			<p class="description" style="color: #d63638;">
				<span class="dashicons dashicons-warning" style="color: #d63638;"></span>
				<?php esc_html_e( 'No theme palette detected. Using built-in defaults. Consider customizing colors below.', 'nettertech-events' ); ?>
			</p>
			<?php
			return;
		}

		?>
		<div class="nte-theme-swatches" style="display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
			<?php foreach ( self::COLOR_ROLES as $role => $label ) : ?>
				<?php $color = $detected[ $role ] ?? self::FALLBACK_DEFAULTS[ $role ]; ?>
				<?php if ( ! empty( $color ) ) : ?>
					<div style="text-align: center;">
						<div style="width: 48px; height: 48px; border-radius: 6px; border: 1px solid #ddd; background: <?php echo esc_attr( $color ); ?>;"></div>
						<div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo esc_html( $label ); ?></div>
						<div style="font-size: 10px; color: #999; font-family: monospace;"><?php echo esc_html( $color ); ?></div>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render a single color picker field.
	 *
	 * @param string                $role          Color role key.
	 * @param string                $label         Display label.
	 * @param array<string, mixed>  $theme_colors  Saved theme color settings.
	 * @param array<string, string> $detected     Auto-detected colors.
	 * @return void
	 */
	private function render_color_field( string $role, string $label, array $theme_colors, array $detected ): void {
		$saved_value   = $theme_colors[ $role ] ?? '';
		$default_value = $detected[ $role ] ?? ( self::FALLBACK_DEFAULTS[ $role ] ?? '#000000' );
		$display_value = ! empty( $saved_value ) ? $saved_value : $default_value;
		?>
		<tr>
			<th scope="row">
				<label for="nettertech_events_theme_color_<?php echo esc_attr( $role ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<input type="color"
					name="nettertech_events_settings[theme_colors][<?php echo esc_attr( $role ); ?>]"
					id="nettertech_events_theme_color_<?php echo esc_attr( $role ); ?>"
					class="nte-theme-color-picker"
					value="<?php echo esc_attr( $display_value ); ?>"
					data-default="<?php echo esc_attr( $default_value ); ?>">
				<code style="margin-left: 8px; font-size: 12px;"><?php echo esc_html( $display_value ); ?></code>
			</td>
		</tr>
		<?php
	}
}
