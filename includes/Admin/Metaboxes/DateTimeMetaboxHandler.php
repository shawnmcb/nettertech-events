<?php
/**
 * Date/Time Metabox Handler.
 *
 * Renders date/time and recurrence metaboxes for the event editor.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\OccurrenceEditor;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Services\RecurrenceService;

/**
 * Handles rendering of date/time and recurrence metaboxes.
 *
 * @since 1.1.0
 */
class DateTimeMetaboxHandler {

	/**
	 * Event being edited.
	 *
	 * @var Event
	 */
	private Event $event;

	/**
	 * Recurrence service for rule description and occurrence counting.
	 *
	 * @var RecurrenceService
	 */
	private RecurrenceService $recurrence_service;

	/**
	 * Constructor.
	 *
	 * @param Event             $event              Event being edited.
	 * @param RecurrenceService $recurrence_service Recurrence service.
	 */
	public function __construct( Event $event, RecurrenceService $recurrence_service ) {
		$this->event              = $event;
		$this->recurrence_service = $recurrence_service;
	}

	/**
	 * Render everything about *when* the event happens as one Schedule box (NTE-159).
	 *
	 * One postbox holding the date/time fields, the recurrence pattern, and the
	 * dates list with "Add a date" — the three pieces that previously rendered as
	 * separate boxes. Keeping them together makes the relationship legible: the
	 * date fields are the pattern's seed, the pattern generates dates, and the
	 * dates list is the result.
	 *
	 * The inner `#recurrence-box` wrapper is preserved because
	 * event-recurrence.js slides it in and out on event-type change.
	 *
	 * @param Occurrence|null   $occurrence  First/current occurrence (seed for the date fields).
	 * @param array<Occurrence> $occurrences Upcoming occurrences for the dates list.
	 * @param int               $event_id    The event, so a date can be added to it (0 = unsaved).
	 * @return void
	 */
	public function render_schedule( ?Occurrence $occurrence, array $occurrences, int $event_id ): void {
		$is_recurring = 'recurring' === $this->event->event_type;
		$multi_date   = count( $occurrences ) > 1;
		$presenter    = $this->build_presenter( $occurrence );

		$this->render_upcoming_dates_styles();
		?>
		<div class="postbox" id="nte-schedule-box">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Schedule', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<h3 class="nte-schedule-section__title">
					<?php
					if ( $is_recurring || $multi_date ) {
						esc_html_e( 'First date', 'nettertech-events' );
					} else {
						esc_html_e( 'Date & Time', 'nettertech-events' );
					}
					?>
				</h3>
				<?php if ( $is_recurring ) : ?>
					<p class="description">
						<?php esc_html_e( 'This date seeds the recurrence pattern — changing it moves the pattern-generated dates.', 'nettertech-events' ); ?>
					</p>
				<?php endif; ?>
				<?php require dirname( __DIR__, 3 ) . '/templates/admin/metaboxes/datetime-fields.php'; ?>

				<div id="recurrence-box" class="nte-schedule-section" style="<?php echo $is_recurring ? '' : 'display: none;'; ?>">
					<h3 class="nte-schedule-section__title"><?php esc_html_e( 'Recurrence Pattern', 'nettertech-events' ); ?></h3>
					<?php $this->render_recurrence_fields(); ?>
				</div>

				<?php if ( $event_id > 0 ) : ?>
					<div class="nte-schedule-section">
						<h3 class="nte-schedule-section__title"><?php esc_html_e( 'Dates', 'nettertech-events' ); ?></h3>
						<?php $this->render_upcoming_dates_inner( $occurrences, $event_id ); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		$this->render_styles();
		$this->render_scripts();
	}

	/**
	 * Build the presenter for the date/time fields.
	 *
	 * @param Occurrence|null $occurrence Existing occurrence.
	 * @return Presenters\DateTimeBoxPresenter
	 */
	private function build_presenter( ?Occurrence $occurrence ): Presenters\DateTimeBoxPresenter {
		$start_date = '';
		$start_time = '';
		$end_date   = '';
		$end_time   = '';
		$all_day    = false;
		$capacity   = '';

		if ( $occurrence ) {
			$start_date = substr( $occurrence->start_datetime, 0, 10 );
			$start_time = substr( $occurrence->start_datetime, 11, 5 );
			$end_date   = substr( $occurrence->end_datetime, 0, 10 );
			$end_time   = substr( $occurrence->end_datetime, 11, 5 );
			$all_day    = $occurrence->all_day;
			$capacity   = null !== $occurrence->capacity ? (string) $occurrence->capacity : '';
		}

		// Get settings for end time toggle behavior.
		$dto                      = \NetterTechEvents\Core\NetterTechEventsSettings::from_option();
		$show_end_time_by_default = $dto->display->show_end_time_by_default;
		$require_end_time         = $dto->display->require_end_time;
		$end_time_expanded        = $require_end_time || $show_end_time_by_default;

		return new Presenters\DateTimeBoxPresenter(
			(string) $start_date,
			(string) $start_time,
			(string) $end_date,
			(string) $end_time,
			(bool) $all_day,
			(string) $capacity,
			(bool) $end_time_expanded,
			(bool) $require_end_time
		);
	}

	/**
	 * The dates list + "Add a date" fields, without a box wrapper.
	 *
	 * Rendered for **any** saved event, not only a recurring one — a date left behind by a
	 * conversion must stay findable rather than remain live on the site with nowhere to
	 * manage it (NTE-153).
	 *
	 * Shared by the standalone Dates metabox and the Schedule box (NTE-159).
	 *
	 * @param array<Occurrence> $occurrences Upcoming occurrences (any status).
	 * @param int               $event_id    The event, so a date can be added to it.
	 * @return void
	 */
	private function render_upcoming_dates_inner( array $occurrences, int $event_id ): void {
		?>
				<?php if ( empty( $occurrences ) ) : ?>
					<p class="description"><?php esc_html_e( 'No upcoming dates yet.', 'nettertech-events' ); ?></p>
				<?php else : ?>
					<ul class="nte-upcoming-dates">
						<?php foreach ( $occurrences as $occurrence ) : ?>
							<?php
							$edit_url = admin_url(
								'admin.php?page=' . AdminMenu::SUBMENU_EDIT_OCCURRENCE . '&occurrence_id=' . (int) $occurrence->id
							);
							$when     = $occurrence->get_formatted_date() . ' · ' . $occurrence->get_formatted_time();

							// A link, not a form: the Dates box lives inside the event editor's form, and a
							// nested <form> is not valid HTML — the parser hands its fields to the outer form
							// and a second `action` steals every event save. Nonced per date, as WordPress's
							// own row actions are.
							$delete_url = wp_nonce_url(
								admin_url(
									'admin-post.php?action=' . OccurrenceEditor::DELETE_ACTION . '&occurrence_id=' . (int) $occurrence->id
								),
								OccurrenceEditor::DELETE_ACTION . '_' . (int) $occurrence->id
							);

							$item_classes = array( 'nte-upcoming-dates__item' );
							if ( $occurrence->is_cancelled() ) {
								$item_classes[] = 'nte-upcoming-dates__item--cancelled';
							}
							if ( $occurrence->is_override ) {
								$item_classes[] = 'nte-upcoming-dates__item--override';
							}
							?>
							<li class="<?php echo esc_attr( implode( ' ', $item_classes ) ); ?>">
								<span class="nte-upcoming-dates__when"><?php echo esc_html( $when ); ?></span>
								<?php if ( $occurrence->is_cancelled() ) : ?>
									<span class="nte-upcoming-dates__badge nte-upcoming-dates__badge--cancelled"><?php esc_html_e( 'Cancelled', 'nettertech-events' ); ?></span>
								<?php endif; ?>
								<?php if ( $occurrence->is_override ) : ?>
									<span class="nte-upcoming-dates__badge nte-upcoming-dates__badge--override" title="<?php esc_attr_e( 'Edited or added outside the pattern — regenerating the pattern will not change this date.', 'nettertech-events' ); ?>"><?php esc_html_e( 'Custom', 'nettertech-events' ); ?></span>
								<?php endif; ?>
								<a class="nte-upcoming-dates__edit" href="<?php echo esc_url( $edit_url ); ?>">
									<?php esc_html_e( 'Edit', 'nettertech-events' ); ?>
									<span class="screen-reader-text"><?php echo esc_html( $when ); ?></span>
								</a>
								<a class="nte-upcoming-dates__delete" href="<?php echo esc_url( $delete_url ); ?>">
									<?php esc_html_e( 'Remove', 'nettertech-events' ); ?>
									<span class="screen-reader-text"><?php echo esc_html( $when ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( $event_id > 0 ) : ?>
					<?php $this->render_add_date_fields(); ?>
				<?php endif; ?>
		<?php
	}

	/**
	 * Fields for putting one more date on the event, outside its pattern.
	 *
	 * The date an operator adds here is stamped `is_override`, so the recurrence machinery leaves
	 * it alone: an event can run every Tuesday *and* on one Saturday. A pattern is a source of
	 * dates, not the only one.
	 *
	 * Plain fields on the event form, not a form of their own. A nested `<form>` is not valid HTML —
	 * the parser drops the inner tag and hands its controls to the outer form, so a second
	 * `action` field would ride along on every event save and steal the submission.
	 *
	 * @since 1.1.2
	 *
	 * @return void
	 */
	private function render_add_date_fields(): void {
		?>
		<details class="nte-add-date">
			<summary class="nte-add-date__summary">
				<?php esc_html_e( 'Add a date', 'nettertech-events' ); ?>
			</summary>

			<p class="description nte-add-date__hint">
				<?php esc_html_e( 'A date added here sits outside the recurrence pattern, and saving the pattern again will not remove it.', 'nettertech-events' ); ?>
			</p>

			<div class="nte-add-date__fields">
				<p>
					<label for="nettertech_events_new_date">
						<?php esc_html_e( 'Date', 'nettertech-events' ); ?>
					</label><br>
					<input
						type="date"
						id="nettertech_events_new_date"
						name="nettertech_events_new_date"
						class="widefat"
					>
				</p>

				<p class="nte-add-date__times">
					<span>
						<label for="nettertech_events_new_start_time">
							<?php esc_html_e( 'Start', 'nettertech-events' ); ?>
						</label><br>
						<input
							type="time"
							id="nettertech_events_new_start_time"
							name="nettertech_events_new_start_time"
						>
					</span>
					<span>
						<label for="nettertech_events_new_end_time">
							<?php esc_html_e( 'End', 'nettertech-events' ); ?>
						</label><br>
						<input
							type="time"
							id="nettertech_events_new_end_time"
							name="nettertech_events_new_end_time"
						>
					</span>
				</p>

				<p class="description">
					<?php esc_html_e( 'The date is added when you update the event.', 'nettertech-events' ); ?>
				</p>
			</div>
		</details>
		<?php
	}

	/**
	 * Register inline styles for the upcoming-dates metabox.
	 *
	 * @return void
	 */
	private function render_upcoming_dates_styles(): void {
		$handle = 'nettertech-events-upcoming-dates';

		wp_register_style( $handle, false, array(), NETTERTECH_EVENTS_VERSION );
		wp_enqueue_style( $handle );
		wp_add_inline_style(
			$handle,
			'.nte-upcoming-dates { margin: 0; }
.nte-upcoming-dates__item { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; padding: 8px 0; border-bottom: 1px solid #f0f0f1; }
.nte-upcoming-dates__item:last-child { border-bottom: 0; }
.nte-upcoming-dates__when { flex: 1 1 auto; }
.nte-upcoming-dates__item--cancelled .nte-upcoming-dates__when { text-decoration: line-through; color: #757575; }
.nte-upcoming-dates__badge { font-size: 11px; line-height: 1.6; padding: 0 6px; border-radius: 3px; }
.nte-upcoming-dates__badge--cancelled { background: #f6c9c9; color: #8a1f1f; }
.nte-upcoming-dates__badge--override { background: #d7e8d2; color: #2a5a1f; }
.nte-upcoming-dates__delete { color: #b32d2e; text-decoration: none; margin-left: 8px; }
.nte-upcoming-dates__delete:hover { color: #8a2424; text-decoration: underline; }
.nte-upcoming-dates__edit { display: inline-flex; align-items: center; min-height: 24px; padding: 0 4px; }
.nte-upcoming-dates__edit:focus-visible { outline: 2px solid var(--wp-admin-theme-color, #2271b1); outline-offset: 1px; }
.nte-add-date { margin-top: 12px; padding-top: 10px; border-top: 1px solid #f0f0f1; }
.nte-add-date__summary { cursor: pointer; min-height: 24px; padding: 4px 0; font-weight: 600; }
.nte-add-date__summary:focus-visible { outline: 2px solid var(--wp-admin-theme-color, #2271b1); outline-offset: 1px; }
.nte-add-date__hint { margin: 6px 0 10px; }
.nte-add-date__times { display: flex; gap: 10px; }
.nte-add-date__times span { flex: 1 1 0; }
.nte-add-date__times input { width: 100%; }
.nte-schedule-section { margin-top: 16px; padding-top: 12px; border-top: 1px solid #dcdcde; }
#nte-schedule-box .nte-schedule-section__title { margin: 0 0 8px; font-size: 13px; }'
		);
	}

	/**
	 * Render date/time metabox styles.
	 *
	 * @return void
	 */
	private function render_styles(): void {
		$handle = 'nettertech-events-datetime-metabox';

		wp_register_style( $handle, false, array(), NETTERTECH_EVENTS_VERSION );
		wp_enqueue_style( $handle );
		wp_add_inline_style(
			$handle,
			'.nte-end-time-toggle .nte-toggle-show { display: inline; }
.nte-end-time-toggle .nte-toggle-hide { display: none; }
.nte-end-time-toggle[aria-expanded="true"] .nte-toggle-show { display: none; }
.nte-end-time-toggle[aria-expanded="true"] .nte-toggle-hide { display: inline; }'
		);
	}

	/**
	 * Render date/time metabox scripts.
	 *
	 * @return void
	 */
	private function render_scripts(): void {
		$handle = 'nettertech-events-datetime-metabox';

		wp_register_script( $handle, false, array(), NETTERTECH_EVENTS_VERSION, true );
		wp_enqueue_script( $handle );

		$display = \NetterTechEvents\Core\NetterTechEventsSettings::from_option()->display;
		$data    = array(
			'minDurationMessage'     => __( 'Event must be at least 10 minutes long. Please adjust the end time.', 'nettertech-events' ),
			'capacityMessage'        => __( 'Capacity must be a positive number or left empty for unlimited.', 'nettertech-events' ),
			'defaultStartTime'       => $display->default_event_start_time,
			'defaultDurationMinutes' => $display->default_event_duration_minutes,
		);

		$script = 'window.nettertechEventsDateTimeMetabox = ' . wp_json_encode( $data ) . ";\n" . <<<'JS'
(function() {
	'use strict';

	var toggle = document.getElementById('nte-end-time-toggle');
	var fields = document.getElementById('nte-end-time-fields');
	var startDate = document.getElementById('start_date');
	var startTime = document.getElementById('start_time');
	var endDate = document.getElementById('end_date');
	var endTime = document.getElementById('end_time');
	var capacity = document.getElementById('occurrence_capacity');

	if (toggle && fields) {
		toggle.addEventListener('click', function() {
			var isHidden = fields.style.display === 'none';
			fields.style.display = isHidden ? '' : 'none';
			toggle.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
		});
	}

	var allDay = document.querySelector('input[name="all_day"]');

	function softFillTimes() {
		// Soft-fill (don't overwrite anything the user typed).
		// Skip entirely for all-day events — those want blank/midnight pattern.
		if (allDay && allDay.checked) {
			return;
		}
		var defaults = window.nettertechEventsDateTimeMetabox || {};
		var defaultStartTime = defaults.defaultStartTime || '19:00';
		var defaultDuration = parseInt(defaults.defaultDurationMinutes, 10) || 120;
		if (startTime && !startTime.value) {
			startTime.value = defaultStartTime;
		}
		if (endTime && !endTime.value && startTime && startTime.value) {
			var parts = startTime.value.split(':');
			var startMinutes = (parseInt(parts[0], 10) * 60) + parseInt(parts[1], 10);
			var endMinutes = startMinutes + defaultDuration;
			// Clamp to same-day if it would overflow past 23:59.
			if (endMinutes >= 24 * 60) {
				endMinutes = 23 * 60 + 59;
			}
			var hh = String(Math.floor(endMinutes / 60)).padStart(2, '0');
			var mm = String(endMinutes % 60).padStart(2, '0');
			endTime.value = hh + ':' + mm;
		}
	}

	if (startDate && endDate) {
		startDate.addEventListener('change', function() {
			if (!endDate.value || endDate.value < startDate.value) {
				endDate.value = startDate.value;
			}
			endDate.min = startDate.value;
			softFillTimes();
		});

		if (startDate.value) {
			endDate.min = startDate.value;
		}
	}

	if (startTime) {
		// If the user types a start time without a date yet, still respect it
		// — but if they later fill the date, the date handler triggers fill.
		startTime.addEventListener('change', softFillTimes);
	}

	var form = document.getElementById('nettertech-events-editor');
	var minDurationMs = 10 * 60 * 1000;
	if (form && startDate && endDate) {
		form.addEventListener('submit', function(e) {
			var startStr = startDate.value + 'T' + (startTime ? startTime.value : '00:00');
			var endStr = endDate.value + 'T' + (endTime ? endTime.value : '23:59');

			if (endDate.value && fields && fields.style.display !== 'none') {
				var startMs = new Date(startStr).getTime();
				var endMs = new Date(endStr).getTime();
				var durationMs = endMs - startMs;

				if (durationMs < minDurationMs) {
					e.preventDefault();
					alert(window.nettertechEventsDateTimeMetabox.minDurationMessage);
					endTime ? endTime.focus() : endDate.focus();
					return false;
				}
			}

			if (capacity && capacity.value !== '') {
				var capVal = parseInt(capacity.value, 10);
				if (isNaN(capVal) || capVal < 0) {
					e.preventDefault();
					alert(window.nettertechEventsDateTimeMetabox.capacityMessage);
					capacity.focus();
					return false;
				}
			}
		});
	}

	if (capacity) {
		capacity.addEventListener('input', function() {
			this.value = this.value.replace(/[^0-9]/g, '');
		});
	}
})();
JS;

		wp_add_inline_script(
			$handle,
			$script
		);
	}

	/**
	 * The recurrence pattern controls, without a box wrapper.
	 *
	 * Rendered inside the Schedule box's `#recurrence-box` section (NTE-159).
	 *
	 * @return void
	 */
	private function render_recurrence_fields(): void {
		// Enqueue recurrence pattern builder script.
		wp_enqueue_script(
			'nettertech-events-event-recurrence',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/event-recurrence.js',
			array( 'jquery' ),
			NETTERTECH_EVENTS_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_localize_script(
			'nettertech-events-event-recurrence',
			'nettertechEventsEventRecurrence',
			array(
				'i18n' => array(
					'days'   => __( 'day(s)', 'nettertech-events' ),
					'weeks'  => __( 'week(s)', 'nettertech-events' ),
					'months' => __( 'month(s)', 'nettertech-events' ),
					'years'  => __( 'year(s)', 'nettertech-events' ),
				),
			)
		);

		$presets      = RecurrenceService::get_presets();
		$current_rule = $this->event->recurrence_rule ?? '';

		// Parse current rule to get description.
		$rule_description = '';
		if ( $current_rule ) {
			$rule_description = $this->recurrence_service->describe_rule( $current_rule );
		}

		// Count existing occurrences.
		$occurrence_count = 0;
		if ( $this->event->id ) {
			$occurrence_count = $this->recurrence_service->count_occurrences( $this->event->id );
		}

		?>
				<p>
					<label for="recurrence_preset"><strong><?php esc_html_e( 'Repeat:', 'nettertech-events' ); ?></strong></label>
					<select name="recurrence_preset" id="recurrence_preset" style="width: 100%; margin-top: 4px;">
						<option value=""><?php esc_html_e( 'Select pattern...', 'nettertech-events' ); ?></option>
						<?php foreach ( $presets as $key => $preset ) : ?>
							<option value="<?php echo esc_attr( $preset['rrule'] ); ?>"
								<?php selected( $current_rule, $preset['rrule'] ); ?>>
								<?php echo esc_html( $preset['label'] ); ?>
							</option>
						<?php endforeach; ?>
						<option value="custom" <?php selected( ! empty( $current_rule ) && ! in_array( $current_rule, array_column( $presets, 'rrule' ), true ) ); ?>>
							<?php esc_html_e( 'Custom...', 'nettertech-events' ); ?>
						</option>
					</select>
				</p>

				<div id="custom-recurrence-fields" style="<?php echo ( ! empty( $current_rule ) && ! in_array( $current_rule, array_column( $presets, 'rrule' ), true ) ) ? '' : 'display: none;'; ?>">
					<p>
						<label for="recurrence_freq"><strong><?php esc_html_e( 'Frequency:', 'nettertech-events' ); ?></strong></label>
						<select name="recurrence_freq" id="recurrence_freq" style="width: 100%; margin-top: 4px;">
							<option value="DAILY"><?php esc_html_e( 'Daily', 'nettertech-events' ); ?></option>
							<option value="WEEKLY"><?php esc_html_e( 'Weekly', 'nettertech-events' ); ?></option>
							<option value="MONTHLY"><?php esc_html_e( 'Monthly', 'nettertech-events' ); ?></option>
							<option value="YEARLY"><?php esc_html_e( 'Yearly', 'nettertech-events' ); ?></option>
						</select>
					</p>

					<p>
						<label for="recurrence_interval">
							<strong><?php esc_html_e( 'Every:', 'nettertech-events' ); ?></strong>
						</label><br>
						<input type="number" name="recurrence_interval" id="recurrence_interval"
								value="1" min="1" max="99" style="width: 60px;">
						<span id="interval-unit"><?php esc_html_e( 'day(s)', 'nettertech-events' ); ?></span>
					</p>

					<div id="weekly-days" style="display: none;">
						<p><strong><?php esc_html_e( 'On days:', 'nettertech-events' ); ?></strong></p>
						<div style="display: flex; flex-wrap: wrap; gap: 8px;">
							<?php
							$days = array(
								'MO' => __( 'Mon', 'nettertech-events' ),
								'TU' => __( 'Tue', 'nettertech-events' ),
								'WE' => __( 'Wed', 'nettertech-events' ),
								'TH' => __( 'Thu', 'nettertech-events' ),
								'FR' => __( 'Fri', 'nettertech-events' ),
								'SA' => __( 'Sat', 'nettertech-events' ),
								'SU' => __( 'Sun', 'nettertech-events' ),
							);
							foreach ( $days as $code => $label ) :
								?>
								<label style="display: inline-flex; align-items: center; gap: 4px;">
									<input type="checkbox" name="recurrence_byday[]" value="<?php echo esc_attr( $code ); ?>">
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<div id="monthly-pattern" style="display: none;">
						<p><strong><?php esc_html_e( 'Monthly pattern:', 'nettertech-events' ); ?></strong></p>
						<p>
							<label>
								<input type="radio" name="recurrence_monthly_type" value="day_of_month" checked>
								<?php esc_html_e( 'Same day of month (from start date)', 'nettertech-events' ); ?>
							</label>
						</p>
						<p>
							<label>
								<input type="radio" name="recurrence_monthly_type" value="nth_weekday">
								<?php esc_html_e( 'Specific weekdays of the month', 'nettertech-events' ); ?>
							</label>
						</p>
						<div id="monthly-nth-weekday" style="display: none; padding-left: 24px; margin-top: 8px;">
							<p><strong><?php esc_html_e( 'On the:', 'nettertech-events' ); ?></strong></p>
							<div style="display: flex; flex-wrap: wrap; gap: 8px;">
								<?php
								$ordinals = array(
									'1'  => __( 'First', 'nettertech-events' ),
									'2'  => __( 'Second', 'nettertech-events' ),
									'3'  => __( 'Third', 'nettertech-events' ),
									'4'  => __( 'Fourth', 'nettertech-events' ),
									'-1' => __( 'Last', 'nettertech-events' ),
								);
								foreach ( $ordinals as $value => $label ) :
									?>
									<label style="display: inline-flex; align-items: center; gap: 4px;">
										<input type="checkbox" name="recurrence_monthly_ordinals[]" value="<?php echo esc_attr( (string) $value ); ?>">
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</div>
							<p style="margin-top: 8px;"><strong><?php esc_html_e( 'Weekday(s):', 'nettertech-events' ); ?></strong></p>
							<div style="display: flex; flex-wrap: wrap; gap: 8px;">
								<?php foreach ( $days as $code => $label ) : ?>
									<label style="display: inline-flex; align-items: center; gap: 4px;">
										<input type="checkbox" name="recurrence_monthly_byday[]" value="<?php echo esc_attr( $code ); ?>">
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="description" style="margin-top: 6px;">
								<?php esc_html_e( 'Example: Check First and Third under "On the" and Mon under "Weekday(s)" for "first and third Monday of each month".', 'nettertech-events' ); ?>
							</p>
						</div>
					</div>
				</div>

				<hr style="margin: 15px 0;">

				<p>
					<label><strong><?php esc_html_e( 'End recurrence:', 'nettertech-events' ); ?></strong></label>
				</p>
				<p>
					<label>
						<input type="radio" name="recurrence_end_type" value="never" checked>
						<?php esc_html_e( 'One year (maximum)', 'nettertech-events' ); ?>
					</label>
				</p>
				<p>
					<label>
						<input type="radio" name="recurrence_end_type" value="count">
						<?php esc_html_e( 'After', 'nettertech-events' ); ?>
						<input type="number" name="recurrence_count" id="recurrence_count"
								value="10" min="1" max="365" style="width: 60px;">
						<?php esc_html_e( 'occurrences', 'nettertech-events' ); ?>
					</label>
				</p>
				<p>
					<label>
						<input type="radio" name="recurrence_end_type" value="until">
						<?php esc_html_e( 'On date:', 'nettertech-events' ); ?>
						<input type="date" name="recurrence_until" id="recurrence_until" style="width: 140px;">
					</label>
				</p>

				<?php if ( $current_rule ) : ?>
					<hr style="margin: 15px 0;">
					<div style="background: #f0f0f1; padding: 10px; border-radius: 4px;">
						<strong><?php esc_html_e( 'Current pattern:', 'nettertech-events' ); ?></strong><br>
						<?php echo esc_html( $rule_description ); ?>
						<?php if ( $occurrence_count > 0 ) : ?>
							<br>
							<span style="color: #666;">
								<?php
								printf(
									/* translators: %d: number of occurrences */
									esc_html( _n( '%d occurrence generated', '%d occurrences generated', $occurrence_count, 'nettertech-events' ) ),
									(int) $occurrence_count
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<input type="hidden" name="recurrence_rule" id="recurrence_rule"
						value="<?php echo esc_attr( $current_rule ); ?>">
		<?php
	}
}
