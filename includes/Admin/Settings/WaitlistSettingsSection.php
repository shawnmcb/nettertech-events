<?php
/**
 * Waitlist settings section (Ticketing tab).
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide default for the waitlist (NTE-214).
 *
 * The waitlist used to be always-on: any sold-out ticket type or full RSVP
 * showed the join form and accepted joins. This section adds the site default;
 * each event can override it from its Tickets settings.
 *
 * @since 1.4.4
 */
class WaitlistSettingsSection implements SettingsSectionInterface {

	/**
	 * Settings key for the site-wide default.
	 */
	public const OPTION_KEY = 'enable_waitlist';

	/**
	 * Get section ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'waitlist';
	}

	/**
	 * Get section title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Waitlist', 'nettertech-events' );
	}

	/**
	 * Render the section.
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
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Waitlist', 'nettertech-events' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="nettertech_events_settings[<?php echo esc_attr( self::OPTION_KEY ); ?>]" value="1"
									<?php checked( $settings[ self::OPTION_KEY ] ?? true ); ?>>
								<?php esc_html_e( 'Let visitors join a waitlist when a ticket type or RSVP is sold out', 'nettertech-events' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'This is the site default. Each event can turn the waitlist on or off for itself under its ticket settings.', 'nettertech-events' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Save section-specific settings (none beyond the bool field).
	 *
	 * @param array<string, mixed> $input            Raw input.
	 * @param array<string, mixed> $current_settings Current settings.
	 * @return array<string, mixed>
	 */
	public function save( array $input, array $current_settings ): array {
		unset( $input );
		return $current_settings;
	}

	/**
	 * Boolean fields owned by this section (unchecked = false must persist).
	 *
	 * @return array<string>
	 */
	public function get_bool_fields(): array {
		return array( self::OPTION_KEY );
	}
}
