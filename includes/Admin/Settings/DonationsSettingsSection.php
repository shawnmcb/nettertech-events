<?php
/**
 * Donations Settings Section.
 *
 * @package NetterTechEvents\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Handles donation settings section rendering.
 *
 * Extracted from SettingsPage to reduce class complexity.
 * Manages checkout donation options, round-up settings, and preset amounts.
 *
 * @since 1.1.0
 * @api
 */
class DonationsSettingsSection implements SettingsSectionInterface {

	/**
	 * Get the section identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'donations';
	}

	/**
	 * Get the section title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Donations', 'nettertech-events' );
	}

	/**
	 * Render the donations settings section.
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
				<table class="form-table">
					<?php
					$this->render_enable_donations_field( $settings );
					$this->render_donation_cause_field( $settings );
					$this->render_roundup_field( $settings );
					$this->render_preset_amounts_field( $settings );
					$this->render_custom_amount_field( $settings );
					$this->render_max_donation_field( $settings );
					?>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Save donation settings from form input.
	 *
	 * Parses donation presets and check-in counter labels from comma-separated strings.
	 *
	 * @param array<string, mixed> $input            Raw form input.
	 * @param array<string, mixed> $current_settings Current stored settings.
	 * @return array<string, mixed> Modified settings for this section's fields.
	 */
	public function save( array $input, array $current_settings ): array {
		// Resolve from container (closes SA-19 DI-bypass): SettingsSanitizer is a
		// stateless singleton registered in AdminServiceProvider. Direct `new` here
		// would re-introduce the audit finding even though the instance is trivial,
		// because the pattern (Admin/-scoped FQCN `new`) is what the audit greps for.
		$sanitizer = \NetterTechEvents\nettertech_events_container()->get( \NetterTechEvents\Admin\SettingsSanitizer::class );

		$current_settings['donation_presets'] = $sanitizer->parse_donation_presets( $input['donation_presets'] ?? null );
		$current_settings['checkin_counters'] = $sanitizer->parse_checkin_counters( $input['checkin_counters'] ?? null );

		return $current_settings;
	}

	/**
	 * Get boolean field keys for this section.
	 *
	 * @return array<string> List of boolean field keys.
	 */
	public function get_bool_fields(): array {
		return array( 'enable_donations', 'allow_custom_donation' );
	}

	/**
	 * Render enable donations checkbox.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_enable_donations_field( array $settings ): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable Donations', 'nettertech-events' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="nettertech_events_settings[enable_donations]" value="1"
						<?php checked( $settings['enable_donations'] ?? false ); ?>>
					<?php esc_html_e( 'Show donation options at checkout', 'nettertech-events' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render donation cause field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_donation_cause_field( array $settings ): void {
		?>
		<tr>
			<th scope="row">
				<label for="donation_cause"><?php esc_html_e( 'Cause/Message', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input type="text" name="nettertech_events_settings[donation_cause]" id="donation_cause"
						value="<?php echo esc_attr( $settings['donation_cause'] ?? __( 'Support our venue', 'nettertech-events' ) ); ?>"
						class="regular-text">
				<p class="description">
					<?php esc_html_e( 'Displayed to customers at checkout.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render round-up radio options.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_roundup_field( array $settings ): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Round-Up To', 'nettertech-events' ); ?></th>
			<td>
				<fieldset>
					<label>
						<input type="radio" name="nettertech_events_settings[roundup_to]" value="dollar"
							<?php checked( $settings['roundup_to'] ?? 'dollar', 'dollar' ); ?>>
						<?php esc_html_e( 'Nearest $1', 'nettertech-events' ); ?>
					</label><br>
					<label>
						<input type="radio" name="nettertech_events_settings[roundup_to]" value="five"
							<?php checked( $settings['roundup_to'] ?? '', 'five' ); ?>>
						<?php esc_html_e( 'Nearest $5', 'nettertech-events' ); ?>
					</label><br>
					<label>
						<input type="radio" name="nettertech_events_settings[roundup_to]" value="ten"
							<?php checked( $settings['roundup_to'] ?? '', 'ten' ); ?>>
						<?php esc_html_e( 'Nearest $10', 'nettertech-events' ); ?>
					</label>
				</fieldset>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render preset amounts field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_preset_amounts_field( array $settings ): void {
		$presets     = $settings['donation_presets'] ?? array( 5, 10, 25 );
		$presets_str = implode( ', ', $presets );
		?>
		<tr>
			<th scope="row">
				<label for="donation_presets"><?php esc_html_e( 'Preset Amounts', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input type="text" name="nettertech_events_settings[donation_presets]" id="donation_presets"
						value="<?php echo esc_attr( $presets_str ); ?>"
						class="regular-text">
				<p class="description">
					<?php esc_html_e( 'Comma-separated amounts (e.g., 5, 10, 25).', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render custom amount checkbox.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_custom_amount_field( array $settings ): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Custom Amount', 'nettertech-events' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="nettertech_events_settings[allow_custom_donation]" value="1"
						<?php checked( $settings['allow_custom_donation'] ?? true ); ?>>
					<?php esc_html_e( 'Allow customers to enter a custom donation amount', 'nettertech-events' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render max donation field.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function render_max_donation_field( array $settings ): void {
		?>
		<tr>
			<th scope="row">
				<label for="max_donation"><?php esc_html_e( 'Maximum Donation', 'nettertech-events' ); ?></label>
			</th>
			<td>
				<input type="number" name="nettertech_events_settings[max_donation]" id="max_donation"
						value="<?php echo esc_attr( $settings['max_donation'] ?? 100 ); ?>"
						min="1" max="10000" class="small-text">
				<p class="description">
					<?php esc_html_e( 'Maximum allowed donation amount.', 'nettertech-events' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}
}
