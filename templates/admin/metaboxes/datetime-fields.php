<?php
/**
 * Admin template: Date & Time fields (shared by the standalone metabox and the
 * consolidated Schedule box, NTE-159).
 *
 * Rendered with a `DateTimeBoxPresenter` in scope. Template MUST NOT call
 * repositories, $wpdb, $_GET/$_POST, or business logic — those are in the
 * presenter. Template MUST call esc_*() on every dynamic output.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 *
 * @var \NetterTechEvents\Admin\Metaboxes\Presenters\DateTimeBoxPresenter $presenter
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

?>
		<p>
			<label>
				<input type="checkbox" name="all_day" value="1" <?php checked( $presenter->is_all_day() ); ?>>
				<?php echo esc_html( $presenter->all_day_label() ); ?>
			</label>
		</p>

		<fieldset style="margin: 0; padding: 0; border: 0;">
			<legend><strong><?php echo esc_html( $presenter->start_legend() ); ?></strong></legend>
			<label for="start_date" class="screen-reader-text"><?php echo esc_html( $presenter->start_date_label() ); ?></label>
			<input type="date" name="start_date" id="start_date"
					value="<?php echo esc_attr( $presenter->start_date() ); ?>" style="width: 55%;"
					required>
			<label for="start_time" class="screen-reader-text"><?php echo esc_html( $presenter->start_time_label() ); ?></label>
			<input type="time" name="start_time" id="start_time"
					value="<?php echo esc_attr( $presenter->start_time() ); ?>" style="width: 40%;">
		</fieldset>

		<div class="nte-end-time-section" style="margin-top: 10px;">
			<button type="button"
					class="button button-secondary nte-end-time-toggle"
					id="nte-end-time-toggle"
					aria-expanded="<?php echo esc_attr( $presenter->aria_expanded() ); ?>"
					aria-controls="nte-end-time-fields">
				<span class="nte-toggle-show"><?php echo esc_html( $presenter->set_end_time_label() ); ?></span>
				<span class="nte-toggle-hide"><?php echo esc_html( $presenter->hide_end_time_label() ); ?></span>
			</button>

			<fieldset id="nte-end-time-fields"
					style="margin: 10px 0 0; padding: 0; border: 0;<?php echo esc_attr( $presenter->end_time_fieldset_style() ); ?>">
				<legend><strong><?php echo esc_html( $presenter->end_legend() ); ?></strong></legend>
				<label for="end_date" class="screen-reader-text"><?php echo esc_html( $presenter->end_date_label() ); ?></label>
				<input type="date" name="end_date" id="end_date"
						value="<?php echo esc_attr( $presenter->end_date() ); ?>" style="width: 55%;"
						<?php echo $presenter->require_end_time() ? 'required="required"' : ''; ?>>
				<label for="end_time" class="screen-reader-text"><?php echo esc_html( $presenter->end_time_label() ); ?></label>
				<input type="time" name="end_time" id="end_time"
						value="<?php echo esc_attr( $presenter->end_time() ); ?>" style="width: 40%;"
						<?php echo $presenter->require_end_time() ? 'required="required"' : ''; ?>>
			</fieldset>
		</div>

		<p>
			<label for="occurrence_capacity"><strong><?php echo esc_html( $presenter->capacity_label() ); ?></strong></label><br>
			<input type="number" name="occurrence_capacity" id="occurrence_capacity"
					value="<?php echo esc_attr( $presenter->capacity() ); ?>" min="0" style="width: 100%;"
					placeholder="<?php echo esc_attr( $presenter->capacity_placeholder() ); ?>">
		</p>
