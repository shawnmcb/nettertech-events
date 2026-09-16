<?php
/**
 * Email Settings Section.
 *
 * @package NetterTechEvents\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Settings;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Services\EmailConfig;

/**
 * Handles email notification settings section rendering.
 *
 * Extracted from SettingsPage to reduce class complexity.
 * Manages customer and venue notification email settings.
 *
 * @since 1.1.0
 * @api
 */
class EmailSettingsSection implements SettingsSectionInterface {

	/**
	 * Email configuration.
	 *
	 * @var EmailConfig
	 */
	private EmailConfig $email_config;

	/**
	 * Constructor.
	 *
	 * @param EmailConfig $email_config Email configuration.
	 */
	public function __construct( EmailConfig $email_config ) {
		$this->email_config = $email_config;
	}

	/**
	 * Get the section identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'email';
	}

	/**
	 * Get the section title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Email', 'nettertech-events' );
	}

	/**
	 * Render the email settings section.
	 *
	 * Note: This section uses 'nettertech_events_email_settings' option, not the main settings array.
	 *
	 * @param array<string, mixed> $settings Email settings from nettertech_events_email_settings option.
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
					<?php esc_html_e( 'Configure email notifications sent to customers and staff.', 'nettertech-events' ); ?>
				</p>
				<table class="form-table">
					<?php
					$this->render_accent_color_field( $settings );
					$this->render_enable_reminders_field( $settings );
					$this->render_disable_customer_email_field( $settings );
					$this->render_disable_qr_codes_field( $settings );
					$this->render_venue_contacts_field( $settings );
					$this->render_cancellation_policy_field( $settings );
					?>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Save email settings from form input.
	 *
	 * Handles the separate 'nettertech_events_email_settings' option. The
	 * email-specific POST data is provided by SettingsPage::save_settings()
	 * via the `_email_settings_post` key of the $input array, which is
	 * extracted from $_POST at the nonce-verified boundary. The $input array
	 * otherwise carries the main settings tree; $current_settings is the
	 * stored settings, passed through unchanged because email settings live
	 * in a separate option.
	 *
	 * @param array<string, mixed> $input            Form input. The `_email_settings_post` key, if present, carries the per-leaf-sanitized email-settings POST block from the nonce-verified boundary in SettingsPage::save_settings(); other keys belong to the main settings tree and are ignored here.
	 * @param array<string, mixed> $current_settings Main stored settings (passed through unchanged).
	 * @return array<string, mixed> The main settings array, unmodified.
	 */
	public function save( array $input, array $current_settings ): array {
		$email_input = $input['_email_settings_post'] ?? null;
		if ( ! is_array( $email_input ) ) {
			return $current_settings;
		}

		// Per-field type-specific tightening on the per-leaf-sanitized
		// data provided by SettingsPage::save_settings().
		$email_settings = array(
			'accent_color'           => sanitize_hex_color( $email_input['accent_color'] ?? '' ),
			'enable_reminders'       => ! empty( $email_input['enable_reminders'] ),
			'disable_customer_email' => ! empty( $email_input['disable_customer_email'] ),
			'disable_qr_codes'       => ! empty( $email_input['disable_qr_codes'] ),
			'venue_contacts'         => sanitize_text_field( $email_input['venue_contacts'] ?? '' ),
			'cancellation_policy'    => wp_kses_post( $email_input['cancellation_policy'] ?? '' ),
		);
		update_option( 'nettertech_events_email_settings', $email_settings );

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
	 * Render accent color field with WordPress color picker.
	 *
	 * @param array<string, mixed> $settings Email settings.
	 * @return void
	 */
	private function render_accent_color_field( array $settings ): void {
		$value       = $settings['accent_color'] ?? '';
		$placeholder = $this->get_theme_primary_color();
		?>
		<tr>
			<th scope="row">
				<label for="nettertech_events_email_accent_color"><?php esc_html_e( 'Email Accent Color', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input type="text" name="nettertech_events_email_settings[accent_color]" id="nettertech_events_email_accent_color"
						value="<?php echo esc_attr( $value ); ?>"
						class="nte-color-picker"
						data-default-color="<?php echo esc_attr( $placeholder ); ?>">
				<p class="description">
					<?php
					printf(
						/* translators: %s: the detected theme color hex value */
						esc_html__( 'Used for headings, accents, and links in all email templates. Leave blank to inherit the WooCommerce email base color, or your theme primary color when WooCommerce is not active (currently %s).', 'nettertech-events' ),
						esc_html( $placeholder )
					);
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Get the theme's primary color for the default email accent.
	 *
	 * Checks global styles (block themes), then editor palette for a
	 * "primary" slug, then falls back to a neutral default.
	 *
	 * @return string Hex color with # prefix.
	 */
	private function get_theme_primary_color(): string {
		// Block themes: check global styles palette for a "primary" slug.
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$palette = wp_get_global_settings( array( 'color', 'palette', 'theme' ) );
			if ( is_array( $palette ) ) {
				foreach ( $palette as $entry ) {
					$slug = $entry['slug'] ?? '';
					if ( in_array( $slug, array( 'primary', 'accent' ), true ) ) {
						$color = $entry['color'] ?? '';
						if ( preg_match( '/^#[a-fA-F0-9]{6}$/', $color ) ) {
							return $color;
						}
					}
				}
			}
		}

		// Use EmailConfig's resolver (handles Kadence/Astra CSS var() references).
		return $this->email_config->get_accent_color();
	}

	/**
	 * Render enable reminders field.
	 *
	 * @param array<string, mixed> $settings Email settings.
	 * @return void
	 */
	private function render_enable_reminders_field( array $settings ): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Reminder Emails', 'nettertech-events' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="nettertech_events_email_settings[enable_reminders]" value="1"
						<?php checked( $settings['enable_reminders'] ?? true ); ?>>
					<?php esc_html_e( 'Send reminder emails to attendees 24 hours before their event', 'nettertech-events' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Individual events can override this setting.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render disable customer email field.
	 *
	 * @param array<string, mixed> $settings Email settings.
	 * @return void
	 */
	private function render_disable_customer_email_field( array $settings ): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Disable Customer Emails', 'nettertech-events' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="nettertech_events_email_settings[disable_customer_email]" value="1"
						<?php checked( $settings['disable_customer_email'] ?? false ); ?>>
					<?php esc_html_e( 'Do not send confirmation emails to customers', 'nettertech-events' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render exclude QR codes field.
	 *
	 * @param array<string, mixed> $settings Email settings.
	 * @return void
	 */
	private function render_disable_qr_codes_field( array $settings ): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Exclude QR Codes', 'nettertech-events' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="nettertech_events_email_settings[disable_qr_codes]" value="1"
						<?php checked( $settings['disable_qr_codes'] ?? false ); ?>>
					<?php esc_html_e( 'Do not include QR codes in confirmation emails', 'nettertech-events' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render venue contacts field.
	 *
	 * @param array<string, mixed> $settings Email settings.
	 * @return void
	 */
	private function render_venue_contacts_field( array $settings ): void {
		?>
		<tr>
			<th scope="row">
				<label for="venue_contacts"><?php esc_html_e( 'Notification Emails', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input type="text" name="nettertech_events_email_settings[venue_contacts]" id="venue_contacts"
						value="<?php echo esc_attr( $settings['venue_contacts'] ?? '' ); ?>"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'staff@venue.com, manager@venue.com', 'nettertech-events' ); ?>">
				<p class="description">
					<?php esc_html_e( 'Comma-separated email addresses to receive RSVP and order notifications.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render cancellation policy field.
	 *
	 * @param array<string, mixed> $settings Email settings.
	 * @return void
	 */
	private function render_cancellation_policy_field( array $settings ): void {
		?>
		<tr>
			<th scope="row">
				<label for="cancellation_policy"><?php esc_html_e( 'Cancellation Policy', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<textarea name="nettertech_events_email_settings[cancellation_policy]" id="cancellation_policy"
						rows="4" class="large-text"><?php echo esc_textarea( $settings['cancellation_policy'] ?? '' ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'Displayed in confirmation emails. HTML allowed.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}
}
