<?php
/**
 * Admin template: Attendee field builder row (T4.2.4 Metaboxes cluster).
 *
 * Rendered by `AttendeeFieldsMetaboxHandler::render_field_row()` after building
 * an `AttendeeFieldRowPresenter` and including this file. Template MUST NOT
 * call repositories, $wpdb, $_GET/$_POST, or business logic — those are in the
 * presenter. Template MUST call esc_*() on every dynamic output.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 *
 * @var \NetterTechEvents\Admin\Metaboxes\Presenters\AttendeeFieldRowPresenter $presenter
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

?>
<div class="nte-attendee-field-row" style="border: 1px solid #ddd; padding: 12px; margin-bottom: 8px; background: #fafafa;">
	<input type="hidden" name="<?php echo esc_attr( $presenter->name_prefix() . '[id]' ); ?>"
		value="<?php echo esc_attr( $presenter->field_id() ); ?>">

	<div style="display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap;">
		<div style="flex: 2; min-width: 150px;">
			<label class="screen-reader-text"><?php echo esc_html( $presenter->label_sr_label() ); ?></label>
			<input type="text" name="<?php echo esc_attr( $presenter->name_prefix() . '[label]' ); ?>"
				value="<?php echo esc_attr( $presenter->label_value() ); ?>"
				placeholder="<?php echo esc_attr( $presenter->label_placeholder() ); ?>"
				class="widefat" required>
		</div>
		<div style="flex: 1; min-width: 120px;">
			<label class="screen-reader-text"><?php echo esc_html( $presenter->type_sr_label() ); ?></label>
			<select name="<?php echo esc_attr( $presenter->name_prefix() . '[field_type]' ); ?>" class="widefat nte-field-type-select">
				<?php foreach ( $presenter->field_type_options() as $nettertech_events_option ) : ?>
					<option value="<?php echo esc_attr( $nettertech_events_option['value'] ); ?>"
						<?php selected( $nettertech_events_option['selected'] ); ?>>
						<?php echo esc_html( $nettertech_events_option['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<div style="flex: 0 0 auto; display: flex; align-items: center; gap: 12px;">
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $presenter->name_prefix() . '[is_required]' ); ?>" value="1"
					<?php checked( $presenter->is_required() ); ?>>
				<?php echo esc_html( $presenter->required_label() ); ?>
			</label>
			<button type="button" class="button-link nte-remove-field" style="color: #b32d2e;">
				<?php echo esc_html( $presenter->remove_label() ); ?>
			</button>
		</div>
	</div>

	<div class="nte-field-options-wrap" style="<?php echo esc_attr( $presenter->options_wrap_style() ); ?>">
		<label class="screen-reader-text"><?php echo esc_html( $presenter->options_sr_label() ); ?></label>
		<textarea name="<?php echo esc_attr( $presenter->name_prefix() . '[options]' ); ?>"
			class="widefat" rows="3"
			placeholder="<?php echo esc_attr( $presenter->options_placeholder() ); ?>"
		><?php echo esc_textarea( $presenter->options_text() ); ?></textarea>
		<p class="description"><?php echo esc_html( $presenter->options_description() ); ?></p>
	</div>

	<div style="display: flex; gap: 8px; margin-top: 8px;">
		<div style="flex: 1;">
			<input type="text" name="<?php echo esc_attr( $presenter->name_prefix() . '[placeholder]' ); ?>"
				value="<?php echo esc_attr( $presenter->placeholder_value() ); ?>"
				placeholder="<?php echo esc_attr( $presenter->placeholder_input_placeholder() ); ?>"
				class="widefat">
		</div>
	</div>
</div>
