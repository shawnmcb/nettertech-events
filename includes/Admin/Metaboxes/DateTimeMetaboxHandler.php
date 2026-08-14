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
	 * @param int               $event_id    The event id (0 = unsaved; kept for signature parity with the
	 *                                       standalone Dates metabox entry point — the add-a-date fields
	 *                                       now render unconditionally per FR-001, so this is unused here).
	 * @return void
	 */
	public function render_schedule( ?Occurrence $occurrence, array $occurrences, int $event_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- kept for call-site signature parity with EventMetaboxHandler::render_schedule_box(); FR-001 removed the last use inside this method
		$is_recurring = 'recurring' === $this->event->event_type;
		$multi_date   = count( $occurrences ) > 1;
		$presenter    = $this->build_presenter( $occurrence );

		$current_rule        = (string) ( $this->event->recurrence_rule ?? '' );
		$has_pattern         = '' !== $current_rule;
		$recurrence_expanded = ! $has_pattern;

		// Computed once here and threaded through to render_recurrence_fields() below —
		// both the collapsed summary and the expanded "Current pattern:" block describe
		// the same rule, so a single describe_rule()/count_occurrences() pair covers both.
		$rule_description   = $has_pattern ? $this->recurrence_service->describe_rule( $current_rule ) : '';
		$occurrence_count   = $this->event->id ? $this->recurrence_service->count_occurrences( $this->event->id ) : 0;
		$recurrence_summary = $has_pattern ? $this->build_recurrence_summary( $rule_description, $occurrence_count ) : '';

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
					<div class="nte-recurrence-header">
						<h3 class="nte-schedule-section__title"><?php esc_html_e( 'Recurrence Pattern', 'nettertech-events' ); ?></h3>
						<?php if ( $has_pattern ) : ?>
							<button
								type="button"
								id="nte-recurrence-toggle"
								class="button-link nte-recurrence-toggle"
								aria-expanded="<?php echo $recurrence_expanded ? 'true' : 'false'; ?>"
								aria-controls="nte-recurrence-body"
							>
								<span class="nte-toggle-show"><?php esc_html_e( 'Edit pattern', 'nettertech-events' ); ?></span>
								<span class="nte-toggle-hide"><?php esc_html_e( 'Collapse', 'nettertech-events' ); ?></span>
							</button>
						<?php endif; ?>
					</div>
					<?php if ( $has_pattern && '' !== $recurrence_summary ) : ?>
						<p class="nte-recurrence-summary" id="nte-recurrence-summary"><?php echo esc_html( $recurrence_summary ); ?></p>
					<?php endif; ?>
					<div id="nte-recurrence-body" style="<?php echo ( $has_pattern && ! $recurrence_expanded ) ? 'display: none;' : ''; ?>">
						<?php $this->render_recurrence_fields( $current_rule, $rule_description, $occurrence_count ); ?>
					</div>
				</div>

				<div class="nte-schedule-section">
					<h3 class="nte-schedule-section__title"><?php esc_html_e( 'Dates', 'nettertech-events' ); ?></h3>
					<?php $this->render_upcoming_dates_inner( $occurrences ); ?>
				</div>
			</div>
		</div>
		<?php
		$this->render_styles();
		$this->render_scripts();
		self::enqueue_time_combobox_assets();
	}

	/**
	 * Register and enqueue the shared time-combobox + inline-validation assets.
	 *
	 * Shared by every admin surface with time entry (Schedule box, Add-a-date
	 * rows, occurrence editor, ticket sale windows) — callers just invoke this
	 * static after rendering inputs marked with data-nte-time-combobox.
	 *
	 * @return void
	 */
	public static function enqueue_time_combobox_assets(): void {
		if ( wp_script_is( 'nettertech-events-time-combobox', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'nettertech-events-time-combobox',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/css/admin/time-combobox.css',
			array(),
			NETTERTECH_EVENTS_VERSION
		);

		wp_enqueue_script(
			'nettertech-events-time-combobox',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/time-combobox.js',
			array(),
			NETTERTECH_EVENTS_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_enqueue_script(
			'nettertech-events-datetime-validation',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/datetime-validation.js',
			array( 'wp-a11y' ),
			NETTERTECH_EVENTS_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		// 12-hour display when the site's time format uses am/pm tokens.
		$time_format = get_option( 'time_format', 'g:i a' );
		$time_format = is_string( $time_format ) && '' !== $time_format ? $time_format : 'g:i a';
		$is_12_hour  = (bool) preg_match( '/[gh]/', $time_format );

		wp_localize_script(
			'nettertech-events-time-combobox',
			'nettertechEventsTimeCombobox',
			array(
				'stepMinutes' => 15,
				'is12Hour'    => $is_12_hour,
				'i18n'        => array(
					'listLabel'     => __( 'Time suggestions', 'nettertech-events' ),
					'noSuggestions' => __( 'No matching times — any valid time can still be typed', 'nettertech-events' ),
					'am'            => __( 'am', 'nettertech-events' ),
					'pm'            => __( 'pm', 'nettertech-events' ),
					'hour'          => __( 'hr', 'nettertech-events' ),
					'hours'         => __( 'hrs', 'nettertech-events' ),
					'minute'        => __( 'min', 'nettertech-events' ),
					'minutes'       => __( 'mins', 'nettertech-events' ),
				),
			)
		);

		wp_localize_script(
			'nettertech-events-datetime-validation',
			'nettertechEventsDatetimeValidation',
			array(
				'i18n' => array(
					'invalidTime'    => __( 'Enter a valid time, for example 7:30 pm.', 'nettertech-events' ),
					'endBeforeStart' => __( 'End time must be after the start time.', 'nettertech-events' ),
				),
			)
		);
	}

	/**
	 * Build the one-line, human-readable summary shown when the pattern section is collapsed (FR-009).
	 *
	 * Takes the already-computed description/count rather than recomputing them, so
	 * the collapsed summary and the expanded "Current pattern:" block (which needs
	 * the same two values) never make two separate round trips through the service.
	 *
	 * @param string $description Rule description from RecurrenceService::describe_rule() (may be empty).
	 * @param int    $count       Occurrence count from RecurrenceService::count_occurrences().
	 * @return string Summary, or empty string if there's no description to summarize.
	 */
	private function build_recurrence_summary( string $description, int $count ): string {
		if ( '' === $description ) {
			return '';
		}

		if ( $count <= 0 ) {
			return $description;
		}

		/* translators: 1: pattern description, 2: number of occurrences */
		$format = _n( '%1$s (%2$d occurrence)', '%1$s (%2$d occurrences)', $count, 'nettertech-events' );

		return sprintf( $format, $description, $count );
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
			(bool) $require_end_time,
			$occurrence && '' !== (string) $occurrence->timezone
				? (string) $occurrence->timezone
				: wp_timezone_string()
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
	 * @return void
	 */
	private function render_upcoming_dates_inner( array $occurrences ): void {
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

				<?php $this->render_add_date_fields(); ?>
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
	 * Repeatable since NTE-177 (FR-002): an operator can append any number of rows in one
	 * sitting, remove a row before saving, and all remaining rows post together as
	 * `nettertech_events_manual_dates[N][date|start_time|end_time]` — an explicit per-row
	 * index. An unkeyed `[]` array was tried first, but the browser assigns a fresh `[]`
	 * index to *every* bracket occurrence rather than grouping by row, so a row's three
	 * inputs arrived as three separate one-key arrays and `EventSaveHandler` silently
	 * dropped every row as incomplete. The clone JS stamps the next index on each
	 * appended row's three inputs; a removed row leaves a gap, which the backend's
	 * `foreach` tolerates. Available on a brand-new (unsaved) event too (FR-001) — the
	 * "saved event only" gate is gone.
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

			<div class="nte-add-date__rows" id="nte-add-date-rows" data-nte-next-index="1">
				<?php $this->render_manual_date_row( 0 ); ?>
			</div>

			<p>
				<button type="button" class="button nte-add-date__add-row" id="nte-add-date-add-row">
					<?php esc_html_e( '+ Add another date', 'nettertech-events' ); ?>
				</button>
			</p>

			<template id="nte-add-date-row-template">
				<?php $this->render_manual_date_row( '__INDEX__' ); ?>
			</template>

			<p class="description">
				<?php esc_html_e( 'Dates are added when you update the event.', 'nettertech-events' ); ?>
			</p>
		</details>
		<?php
	}

	/**
	 * One repeatable date/time row for the "Add a date" list.
	 *
	 * Fields are label-wrapped (implicit association) rather than `id`/`for` pairs, because this
	 * markup is cloned by JS — duplicate `id` attributes across cloned rows would be invalid HTML
	 * and would break the association anyway. `$index` is either a real row number (initial row)
	 * or the literal token `__INDEX__` (template row) that the clone JS text-replaces with the
	 * next real index before appending — see the `nte-add-date-row-template` usage above.
	 *
	 * @param int|string $index Row index for the `nettertech_events_manual_dates[<index>][...]`
	 *                          field names, or the `__INDEX__` placeholder for the template row.
	 * @return void
	 */
	private function render_manual_date_row( $index ): void {
		?>
		<div class="nte-add-date__row" data-nte-manual-date-row>
			<p>
				<label>
					<?php esc_html_e( 'Date', 'nettertech-events' ); ?><br>
					<input
						type="date"
						name="nettertech_events_manual_dates[<?php echo esc_attr( (string) $index ); ?>][date]"
						class="widefat"
					>
				</label>
			</p>

			<p class="nte-add-date__times">
				<span>
					<label>
						<?php esc_html_e( 'Start', 'nettertech-events' ); ?><br>
						<input
							type="time"
							name="nettertech_events_manual_dates[<?php echo esc_attr( (string) $index ); ?>][start_time]"
							data-nte-time-combobox
						>
					</label>
				</span>
				<span>
					<label>
						<?php esc_html_e( 'End', 'nettertech-events' ); ?><br>
						<input
							type="time"
							name="nettertech_events_manual_dates[<?php echo esc_attr( (string) $index ); ?>][end_time]"
							data-nte-time-combobox
						>
					</label>
				</span>
			</p>

			<p>
				<button type="button" class="button-link nte-add-date__remove" data-nte-remove-row>
					<?php esc_html_e( 'Remove this date', 'nettertech-events' ); ?>
				</button>
			</p>
		</div>
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
.nte-add-date__row + .nte-add-date__row { margin-top: 12px; padding-top: 10px; border-top: 1px dashed #dcdcde; }
.nte-add-date__remove { color: #b32d2e; cursor: pointer; min-height: 24px; padding: 0; }
.nte-add-date__remove:hover { color: #8a2424; text-decoration: underline; }
.nte-add-date__remove:focus-visible { outline: 2px solid var(--wp-admin-theme-color, #2271b1); outline-offset: 1px; }
.nte-add-date__add-row:focus-visible { outline: 2px solid var(--wp-admin-theme-color, #2271b1); outline-offset: 1px; }
.nte-schedule-section { margin-top: 16px; padding-top: 12px; border-top: 1px solid #dcdcde; }
#nte-schedule-box .nte-schedule-section__title { margin: 0 0 8px; font-size: 13px; }
.nte-recurrence-header { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.nte-recurrence-header .nte-schedule-section__title { margin: 0; }
.nte-recurrence-summary { margin: 6px 0 10px; color: #50575e; }
.nte-recurrence-toggle { background: none; border: none; padding: 0; margin: 0; color: var(--wp-admin-theme-color, #2271b1); cursor: pointer; text-decoration: underline; font-size: 13px; min-height: 24px; }
.nte-recurrence-toggle:focus-visible { outline: 2px solid var(--wp-admin-theme-color, #2271b1); outline-offset: 1px; }
.nte-recurrence-toggle .nte-toggle-show { display: inline; }
.nte-recurrence-toggle .nte-toggle-hide { display: none; }
.nte-recurrence-toggle[aria-expanded="true"] .nte-toggle-show { display: none; }
.nte-recurrence-toggle[aria-expanded="true"] .nte-toggle-hide { display: inline; }'
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
			// Announce the programmatic fill so enhancements (time combobox)
			// mirror the new value into their display field.
			startTime.dispatchEvent(new Event('change', { bubbles: true }));
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
			endTime.dispatchEvent(new Event('change', { bubbles: true }));
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
	// Inline, announced validation (NTE-190) — falls back to alert() only if the
	// validation module failed to load, so submit is never silently blocked.
	function reportError(field, message) {
		var v = window.nettertechEventsDatetimeValidation;
		if (v && v.showError) {
			v.showError(field, message);
			v.focusField(field);
		} else {
			alert(message); // eslint-disable-line no-alert
			field.focus();
		}
	}
	function clearReportedError(field) {
		var v = window.nettertechEventsDatetimeValidation;
		if (v && v.clearError) {
			v.clearError(field);
		}
	}
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
					reportError(endTime || endDate, window.nettertechEventsDateTimeMetabox.minDurationMessage);
					return false;
				}
				clearReportedError(endTime || endDate);
			}

			if (capacity && capacity.value !== '') {
				var capVal = parseInt(capacity.value, 10);
				if (isNaN(capVal) || capVal < 0) {
					e.preventDefault();
					reportError(capacity, window.nettertechEventsDateTimeMetabox.capacityMessage);
					return false;
				}
				clearReportedError(capacity);
			}
		});
	}

	if (capacity) {
		capacity.addEventListener('input', function() {
			this.value = this.value.replace(/[^0-9]/g, '');
		});
	}

	// Recurrence Pattern disclosure (FR-009): collapsed by default when a pattern
	// is already saved; the toggle only exists when there is something to collapse.
	var recurrenceToggle = document.getElementById('nte-recurrence-toggle');
	var recurrenceBody = document.getElementById('nte-recurrence-body');
	if (recurrenceToggle && recurrenceBody) {
		recurrenceToggle.addEventListener('click', function() {
			var isHidden = recurrenceBody.style.display === 'none';
			recurrenceBody.style.display = isHidden ? '' : 'none';
			recurrenceToggle.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
		});
	}

	// Repeatable ad-hoc date rows (FR-002): clone the inert <template> row, stamp the next
	// explicit index onto its three field names, and append it. An index is required —
	// PHP's `foo[]` assigns a fresh array key to *every* bracket occurrence rather than
	// grouping by row, so a row's date/start/end inputs would arrive as three separate
	// one-key arrays instead of one row; `nte-add-date-rows`'s data-nte-next-index counter
	// is what makes each appended row's three names agree on the same index.
	var addDateRows = document.getElementById('nte-add-date-rows');
	var addDateTemplate = document.getElementById('nte-add-date-row-template');
	var addDateAddRow = document.getElementById('nte-add-date-add-row');

	if (addDateAddRow && addDateTemplate && addDateRows && addDateTemplate.content) {
		addDateAddRow.addEventListener('click', function() {
			var nextIndex = parseInt(addDateRows.getAttribute('data-nte-next-index'), 10) || 0;
			var clone = addDateTemplate.content.cloneNode(true);
			var fields = clone.querySelectorAll('[name*="__INDEX__"]');
			for (var i = 0; i < fields.length; i++) {
				fields[i].name = fields[i].name.replace('__INDEX__', String(nextIndex));
			}
			addDateRows.appendChild(clone);
			addDateRows.setAttribute('data-nte-next-index', String(nextIndex + 1));
		});
	}

	if (addDateRows) {
		addDateRows.addEventListener('click', function(e) {
			var removeButton = e.target.closest('[data-nte-remove-row]');
			if (!removeButton) {
				return;
			}
			var row = removeButton.closest('[data-nte-manual-date-row]');
			if (row) {
				row.remove();
			}
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
	 * Rendered inside the Schedule box's `#recurrence-box` section (NTE-159). The
	 * description/count are passed in from render_schedule(), which already computed
	 * them for the collapsed-state summary (FR-009) — avoids a second round trip
	 * through RecurrenceService for the same rule.
	 *
	 * @param string $current_rule      Current RRULE string (may be empty).
	 * @param string $rule_description  Human-readable description of $current_rule (may be empty).
	 * @param int    $occurrence_count  Occurrences already generated for this event.
	 * @return void
	 */
	private function render_recurrence_fields( string $current_rule, string $rule_description, int $occurrence_count ): void {
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

		$presets = RecurrenceService::get_presets();

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
