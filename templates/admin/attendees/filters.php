<?php
/**
 * Admin template: Attendees filters form.
 *
 * Rendered by `AttendeesPage::render_filters()` after building an
 * `AttendeesFiltersPresenter` and including this file. Template MUST NOT call
 * repositories, $wpdb, $_GET/$_POST, or business logic — those are in the
 * presenter. Template MUST call esc_*() on every dynamic output.
 *
 * @package NetterTechEvents\Admin
 *
 * @var \NetterTechEvents\Admin\Attendees\Presenters\AttendeesFiltersPresenter $presenter
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

?>
<form method="get" class="nte-attendees-filters">
	<input type="hidden" name="page" value="<?php echo esc_attr( $presenter->page_slug() ); ?>">
	<?php if ( $presenter->event_id() > 0 ) : ?>
		<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $presenter->event_id() ); ?>">
	<?php endif; ?>

	<div class="nte-filter-group">
		<label for="occurrence_id"><?php echo esc_html( $presenter->event_label() ); ?></label>
		<select name="occurrence_id" id="occurrence_id">
			<option value=""><?php echo esc_html( $presenter->all_events_label() ); ?></option>
			<?php foreach ( $presenter->occurrence_options() as $nettertech_events_option ) : ?>
				<option value="<?php echo esc_attr( $nettertech_events_option['value'] ); ?>"
					<?php selected( $nettertech_events_option['selected'] ); ?>>
					<?php echo esc_html( $nettertech_events_option['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>

	<div class="nte-filter-group">
		<label for="status"><?php echo esc_html( $presenter->status_label() ); ?></label>
		<select name="status" id="status">
			<option value=""><?php echo esc_html( $presenter->all_statuses_label() ); ?></option>
			<?php foreach ( $presenter->status_options() as $nettertech_events_option ) : ?>
				<option value="<?php echo esc_attr( $nettertech_events_option['value'] ); ?>"
					<?php selected( $nettertech_events_option['selected'] ); ?>>
					<?php echo esc_html( $nettertech_events_option['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>

	<div class="nte-filter-group">
		<label for="placeholder"><?php echo esc_html( $presenter->placeholder_label() ); ?></label>
		<select name="placeholder" id="placeholder">
			<option value=""><?php echo esc_html( $presenter->all_attendees_label() ); ?></option>
			<?php foreach ( $presenter->placeholder_options() as $nettertech_events_option ) : ?>
				<option value="<?php echo esc_attr( $nettertech_events_option['value'] ); ?>"
					<?php selected( $nettertech_events_option['selected'] ); ?>>
					<?php echo esc_html( $nettertech_events_option['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>

	<div class="nte-filter-group">
		<label for="nte-filter-accessibility"><?php echo esc_html( $presenter->accessibility_label() ); ?></label>
		<select name="<?php echo esc_attr( $presenter->accessibility_param() ); ?>" id="nte-filter-accessibility">
			<option value=""><?php echo esc_html( $presenter->any_accessibility_label() ); ?></option>
			<?php foreach ( $presenter->accessibility_options() as $nettertech_events_option ) : ?>
				<option value="<?php echo esc_attr( $nettertech_events_option['value'] ); ?>"
					<?php selected( $nettertech_events_option['selected'] ); ?>>
					<?php echo esc_html( $nettertech_events_option['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>

	<div class="nte-filter-group">
		<label for="s"><?php echo esc_html( $presenter->search_label() ); ?></label>
		<input type="search" name="s" id="s" value="<?php echo esc_attr( $presenter->search() ); ?>"
			placeholder="<?php echo esc_attr( $presenter->search_placeholder() ); ?>">
	</div>

	<div class="nte-filter-actions">
		<button type="submit" class="button button-primary"><?php echo esc_html( $presenter->filter_button_label() ); ?></button>
		<?php if ( $presenter->any_filter_active() ) : ?>
			<a href="<?php echo esc_url( $presenter->clear_url() ); ?>" class="button">
				<?php echo esc_html( $presenter->clear_button_label() ); ?>
			</a>
		<?php endif; ?>
	</div>
</form>
