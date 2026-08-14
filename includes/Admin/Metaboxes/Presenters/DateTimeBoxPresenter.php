<?php
/**
 * Date & Time Box Presenter (T4.2.4 Metaboxes cluster).
 *
 * @package NetterTechEvents\Admin\Metaboxes\Presenters
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes\Presenters;

defined( 'ABSPATH' ) || exit;

/**
 * Pure data-prep value object for the Date & Time metabox form.
 *
 * The caller resolves the occurrence + display settings into primitives and
 * passes them in; the presenter holds them and exposes intent-revealing
 * accessors for the template. The settings-DTO reads, the occurrence-parsing
 * substr() calls, and the script/style enqueues stay in the caller — they are
 * side-effect-bearing operations that the presenter MUST NOT perform.
 *
 * Does NOT call `esc_*()` (template's job), does NOT output, does NOT read
 * from $_GET/$_POST/get_option, does NOT call $wpdb.
 *
 * @since 1.x.x
 */
final class DateTimeBoxPresenter {

	/**
	 * Wire the resolved date/time values + end-time toggle state.
	 *
	 * @param string $start_date        Start date in YYYY-MM-DD (may be empty).
	 * @param string $start_time        Start time in HH:MM (may be empty).
	 * @param string $end_date          End date in YYYY-MM-DD (may be empty).
	 * @param string $end_time          End time in HH:MM (may be empty).
	 * @param bool   $all_day           Whether the event is all-day.
	 * @param string $capacity          Occurrence capacity as string (empty = unlimited).
	 * @param bool   $end_time_expanded Whether end-time fields render expanded.
	 * @param bool   $require_end_time  Whether end-time inputs carry `required`.
	 * @param string $timezone_label    Timezone the wall-clock times are read in ('' hides the hint).
	 */
	public function __construct(
		private readonly string $start_date,
		private readonly string $start_time,
		private readonly string $end_date,
		private readonly string $end_time,
		private readonly bool $all_day,
		private readonly string $capacity,
		private readonly bool $end_time_expanded,
		private readonly bool $require_end_time,
		private readonly string $timezone_label = ''
	) {}

	/**
	 * Timezone the entered wall-clock times are interpreted in (FR-009,
	 * NTE-190). Empty string suppresses the hint.
	 *
	 * @return string
	 */
	public function timezone_label(): string {
		return $this->timezone_label;
	}

	/**
	 * Start date input value.
	 *
	 * @return string
	 */
	public function start_date(): string {
		return $this->start_date;
	}

	/**
	 * Start time input value.
	 *
	 * @return string
	 */
	public function start_time(): string {
		return $this->start_time;
	}

	/**
	 * End date input value.
	 *
	 * @return string
	 */
	public function end_date(): string {
		return $this->end_date;
	}

	/**
	 * End time input value.
	 *
	 * @return string
	 */
	public function end_time(): string {
		return $this->end_time;
	}

	/**
	 * Whether the all-day checkbox is checked.
	 *
	 * @return bool
	 */
	public function is_all_day(): bool {
		return $this->all_day;
	}

	/**
	 * Capacity input value (string; empty means unlimited).
	 *
	 * @return string
	 */
	public function capacity(): string {
		return $this->capacity;
	}

	/**
	 * `aria-expanded` attribute value for the end-time toggle.
	 *
	 * @return string Either 'true' or 'false'.
	 */
	public function aria_expanded(): string {
		return $this->end_time_expanded ? 'true' : 'false';
	}

	/**
	 * Inline style for the end-time fieldset (display:none when collapsed).
	 *
	 * Returns the empty string when expanded.
	 *
	 * @return string
	 */
	public function end_time_fieldset_style(): string {
		return $this->end_time_expanded ? '' : ' display: none;';
	}

	/**
	 * Whether end-date and end-time inputs are required attributes.
	 *
	 * @return bool
	 */
	public function require_end_time(): bool {
		return $this->require_end_time;
	}

	// ---- Translatable labels ----

	/**
	 * Metabox title ("Date & Time").
	 *
	 * @return string
	 */
	public function box_title(): string {
		return __( 'Date & Time', 'nettertech-events' );
	}

	/**
	 * "All-day event" checkbox label.
	 *
	 * @return string
	 */
	public function all_day_label(): string {
		return __( 'All-day event', 'nettertech-events' );
	}

	/**
	 * "Start:" fieldset legend.
	 *
	 * @return string
	 */
	public function start_legend(): string {
		return __( 'Start:', 'nettertech-events' );
	}

	/**
	 * "Start Date" screen-reader label.
	 *
	 * @return string
	 */
	public function start_date_label(): string {
		return __( 'Start Date', 'nettertech-events' );
	}

	/**
	 * "Start Time" screen-reader label.
	 *
	 * @return string
	 */
	public function start_time_label(): string {
		return __( 'Start Time', 'nettertech-events' );
	}

	/**
	 * "End:" fieldset legend.
	 *
	 * @return string
	 */
	public function end_legend(): string {
		return __( 'End:', 'nettertech-events' );
	}

	/**
	 * "End Date" screen-reader label.
	 *
	 * @return string
	 */
	public function end_date_label(): string {
		return __( 'End Date', 'nettertech-events' );
	}

	/**
	 * "End Time" screen-reader label.
	 *
	 * @return string
	 */
	public function end_time_label(): string {
		return __( 'End Time', 'nettertech-events' );
	}

	/**
	 * "Set end time" toggle text (shown when collapsed).
	 *
	 * @return string
	 */
	public function set_end_time_label(): string {
		return __( 'Set end time', 'nettertech-events' );
	}

	/**
	 * "Hide end time" toggle text (shown when expanded).
	 *
	 * @return string
	 */
	public function hide_end_time_label(): string {
		return __( 'Hide end time', 'nettertech-events' );
	}

	/**
	 * "Capacity:" label.
	 *
	 * @return string
	 */
	public function capacity_label(): string {
		return __( 'Capacity:', 'nettertech-events' );
	}

	/**
	 * Capacity input placeholder ("Unlimited").
	 *
	 * @return string
	 */
	public function capacity_placeholder(): string {
		return __( 'Unlimited', 'nettertech-events' );
	}
}
