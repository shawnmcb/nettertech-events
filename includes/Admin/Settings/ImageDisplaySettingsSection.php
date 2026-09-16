<?php
/**
 * Image Display Settings Section.
 *
 * @package NetterTechEvents\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Handles image display settings section rendering.
 *
 * Configures aspect ratios for event card images with:
 * - Site-wide default ratio
 * - Per-view overrides (cards, list, carousel)
 * - Custom ratio support
 *
 * @since 1.2.0
 * @api
 */
class ImageDisplaySettingsSection implements SettingsSectionInterface {

	/**
	 * Aspect ratio presets available for selection.
	 *
	 * @var array<string, string>
	 */
	private const RATIO_PRESETS = array(
		'16:9'     => '16:9 (Widescreen)',
		'3:2'      => '3:2 (Classic Photo)',
		'4:3'      => '4:3 (Traditional)',
		'1:1'      => '1:1 (Square)',
		'original' => 'Original (Natural)',
		'custom'   => 'Custom...',
	);

	/**
	 * Get the section identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'image-display';
	}

	/**
	 * Get the section title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Image Display', 'nettertech-events' );
	}

	/**
	 * Render the image display settings section.
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
				<p class="description" style="margin-bottom: 15px;">
					<?php esc_html_e( 'Configure the aspect ratio for event card images. Per-view settings override the site default.', 'nettertech-events' ); ?>
				</p>

				<table class="form-table">
					<?php
					$this->render_default_ratio_field( $settings );
					$this->render_view_ratio_field( 'single', __( 'Event Page', 'nettertech-events' ), $settings );
					$this->render_view_ratio_field( 'cards', __( 'Cards Layout', 'nettertech-events' ), $settings );
					$this->render_view_ratio_field( 'list', __( 'List Layout', 'nettertech-events' ), $settings );
					$this->render_view_ratio_field( 'carousel', __( 'Carousel', 'nettertech-events' ), $settings );
					$this->render_view_ratio_field( 'calendar', __( 'Calendar Tooltip', 'nettertech-events' ), $settings );
					$this->render_date_badge_color_field( $settings );
					$this->render_default_ticket_image_field( $settings );
					?>
				</table>
			</div>
		</div>

		<?php
	}

	/**
	 * Save image display settings from form input.
	 *
	 * Handles date badge color and event layout configuration.
	 *
	 * @param array<string, mixed> $input            Raw form input.
	 * @param array<string, mixed> $current_settings Current stored settings.
	 * @return array<string, mixed> Modified settings for this section's fields.
	 */
	public function save( array $input, array $current_settings ): array {
		// Resolve from container (closes SA-19 DI-bypass): SettingsSanitizer is a
		// stateless singleton registered in AdminServiceProvider.
		$sanitizer = \NetterTechEvents\nettertech_events_container()->get( \NetterTechEvents\Admin\SettingsSanitizer::class );

		// Date badge color — always persist the color value so it can be re-enabled without re-entering.
		$current_settings['date_badge_color_custom'] = ! empty( $input['date_badge_color_custom'] );
		$current_settings['date_badge_color']        = $sanitizer->sanitize_hex_color( $input['date_badge_color'] ?? null, '#2563eb' );

		// Default ticket product image (NTE-219): a valid image attachment or nothing.
		$image_id                                    = absint( $input['default_ticket_image_id'] ?? 0 );
		$current_settings['default_ticket_image_id'] = \NetterTechEvents\Utilities\ImageHelper::is_valid_image_attachment( $image_id ) ? $image_id : 0;

		// Handle event layout configuration.
		$this->process_layout_settings( $input, $current_settings );

		return $current_settings;
	}

	/**
	 * Process layout settings from input.
	 *
	 * @param array<string, mixed> $input    The raw input.
	 * @param array<string, mixed> $settings The settings array to modify.
	 * @return void
	 */
	private function process_layout_settings( array $input, array &$settings ): void {
		$layout_order_raw      = sanitize_text_field( $input['event_layout_order'] ?? '' );
		$layout_visibility_raw = sanitize_text_field( $input['event_layout_visibility'] ?? '' );

		if ( empty( $layout_order_raw ) ) {
			return;
		}

		// Resolve from container (closes SA-19 DI-bypass): LayoutService is a
		// singleton registered in CoreServiceProvider.
		$layout_service = \NetterTechEvents\nettertech_events_container()->get( \NetterTechEvents\Services\LayoutService::class );

		// Parse order from comma-separated string.
		$layout_order = array_filter( array_map( 'trim', explode( ',', $layout_order_raw ) ) );

		// Parse visibility from JSON.
		$layout_visibility = json_decode( $layout_visibility_raw, true );
		if ( ! is_array( $layout_visibility ) ) {
			$layout_visibility = array();
		}

		// Build and validate the config.
		$layout_config = array(
			'order'      => $layout_order,
			'visibility' => $layout_visibility,
		);

		// Sanitize and save via LayoutService.
		if ( $layout_service->validate_config( $layout_config ) ) {
			$settings['event_layout'] = $layout_service->sanitize_config( $layout_config );
		}
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
	 * Render the default aspect ratio field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_default_ratio_field( array $settings ): void {
		$current_preset = $settings['image_aspect_ratio'] ?? '16:9';
		$current_custom = $settings['image_aspect_ratio_custom'] ?? '';
		?>
		<tr>
			<th scope="row">
				<label for="image_aspect_ratio"><?php esc_html_e( 'Site Default', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<select
					name="nettertech_events_settings[image_aspect_ratio]"
					id="image_aspect_ratio"
					class="nte-ratio-select"
				>
					<?php foreach ( self::RATIO_PRESETS as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_preset, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input
					type="text"
					name="nettertech_events_settings[image_aspect_ratio_custom]"
					class="nte-ratio-custom small-text"
					value="<?php echo esc_attr( $current_custom ); ?>"
					placeholder="5:4"
					pattern="[0-9]+:[0-9]+"
					style="<?php echo 'custom' !== $current_preset ? 'display:none;' : ''; ?>"
				>
				<p class="description">
					<?php esc_html_e( 'Default aspect ratio applied to all event card images.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a per-view aspect ratio field.
	 *
	 * @param string               $view     View identifier (cards, list, carousel).
	 * @param string               $label    Display label for the view.
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_view_ratio_field( string $view, string $label, array $settings ): void {
		$field_name     = 'image_aspect_ratio_' . $view;
		$custom_name    = 'image_aspect_ratio_' . $view . '_custom';
		$current_preset = $settings[ $field_name ] ?? '';
		$current_custom = $settings[ $custom_name ] ?? '';

		$options = array_merge(
			array( '' => __( 'Use Site Default', 'nettertech-events' ) ),
			self::RATIO_PRESETS
		);
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $field_name ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<select
					name="nettertech_events_settings[<?php echo esc_attr( $field_name ); ?>]"
					id="<?php echo esc_attr( $field_name ); ?>"
					class="nte-ratio-select"
				>
					<?php foreach ( $options as $value => $option_label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_preset, $value ); ?>>
							<?php echo esc_html( $option_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input
					type="text"
					name="nettertech_events_settings[<?php echo esc_attr( $custom_name ); ?>]"
					class="nte-ratio-custom small-text"
					value="<?php echo esc_attr( $current_custom ); ?>"
					placeholder="5:4"
					pattern="[0-9]+:[0-9]+"
					style="<?php echo 'custom' !== $current_preset ? 'display:none;' : ''; ?>"
				>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the date badge color picker field.
	 *
	 * Allows customizing the background color of the date badge on event cards.
	 * The value is output as the --nte-date-bg CSS custom property on the frontend.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_date_badge_color_field( array $settings ): void {
		$current_color = $settings['date_badge_color'] ?? '';
		?>
		<tr>
			<th scope="row">
				<label for="date_badge_color"><?php esc_html_e( 'Date Badge Color', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input type="color" name="nettertech_events_settings[date_badge_color]" id="date_badge_color"
						value="<?php echo esc_attr( ! empty( $current_color ) ? $current_color : '#2563eb' ); ?>">
				<label style="margin-left: 8px;">
					<input type="checkbox" name="nettertech_events_settings[date_badge_color_custom]" value="1"
						<?php checked( ! empty( $settings['date_badge_color_custom'] ) ); ?>>
					<?php esc_html_e( 'Use custom color', 'nettertech-events' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Background color for the date badge on event cards and the series-page active tab. Uncheck to use your theme\'s primary color. Choose a color dark enough for white text — WCAG AA requires a 4.5:1 contrast ratio.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * "Default ticket product image" attachment picker (NTE-219).
	 *
	 * Same `.nte-media-picker` widget the Spaces form uses; the settings page
	 * enqueues the media library + picker script for it.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return void
	 */
	private function render_default_ticket_image_field( array $settings ): void {
		$image_id = absint( $settings['default_ticket_image_id'] ?? 0 );
		?>
		<tr>
			<th scope="row">
				<label for="default_ticket_image_id"><?php esc_html_e( 'Default Ticket Product Image', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input type="hidden" name="nettertech_events_settings[default_ticket_image_id]" id="default_ticket_image_id"
					value="<?php echo esc_attr( $image_id ? (string) $image_id : '' ); ?>">
				<div class="nte-media-picker" data-target="default_ticket_image_id" data-mode="single">
					<div class="nte-media-picker__preview">
						<?php if ( $image_id ) : ?>
							<?php echo wp_get_attachment_image( $image_id, 'medium' ); // wp_get_attachment_image() returns pre-escaped HTML. ?>
						<?php endif; ?>
					</div>
					<button type="button" class="button nte-media-picker__select">
						<?php esc_html_e( 'Choose image', 'nettertech-events' ); ?>
					</button>
					<button type="button" class="button-link nte-media-picker__clear" <?php echo $image_id ? '' : 'hidden'; ?>>
						<?php esc_html_e( 'Remove', 'nettertech-events' ); ?>
					</button>
				</div>
				<p class="description">
					<?php esc_html_e( 'Shown for ticket products in the cart, checkout, and order screens when the event has no featured image. Leave empty to use the built-in ticket icon.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}
}
