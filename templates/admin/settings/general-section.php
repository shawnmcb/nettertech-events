<?php
/**
 * Admin template: General Settings section.
 *
 * Rendered by `SettingsPage::render_general_settings_section()` after building
 * a `GeneralSettingsPresenter` and including this file. Template MUST NOT call
 * repositories, $wpdb, $_GET/$_POST, or business logic — those are in the
 * presenter. Template MUST call esc_*() on every dynamic output.
 *
 * @package NetterTechEvents\Admin
 *
 * @var \NetterTechEvents\Admin\Settings\Presenters\GeneralSettingsPresenter $presenter
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

?>
<div class="nte-settings__section">
	<div class="nte-settings__section-header">
		<h2 class="nte-settings__section-title"><?php echo esc_html( $presenter->section_title() ); ?></h2>
	</div>
	<div class="nte-settings__section-content">
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="default_view"><?php echo esc_html( $presenter->default_view_label() ); ?></label>
				</th>
				<td>
					<select name="nettertech_events_settings[default_view]" id="default_view">
						<?php foreach ( $presenter->default_view_options() as $nettertech_events_option ) : ?>
							<option value="<?php echo esc_attr( $nettertech_events_option['value'] ); ?>"
								<?php selected( $nettertech_events_option['selected'] ); ?>>
								<?php echo esc_html( $nettertech_events_option['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="events_per_page"><?php echo esc_html( $presenter->events_per_page_label() ); ?></label>
				</th>
				<td>
					<input type="number" name="nettertech_events_settings[events_per_page]" id="events_per_page"
						value="<?php echo esc_attr( (string) $presenter->events_per_page() ); ?>"
						min="1" max="100" class="small-text">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="timezone"><?php echo esc_html( $presenter->timezone_label() ); ?></label>
				</th>
				<td>
					<select name="nettertech_events_settings[timezone]" id="timezone">
						<?php echo wp_timezone_choice( $presenter->timezone() ); ?>
					</select>
					<p class="description">
						<?php echo esc_html( $presenter->timezone_description() ); ?>
					</p>
				</td>
			</tr>
		</table>
	</div>
</div>
