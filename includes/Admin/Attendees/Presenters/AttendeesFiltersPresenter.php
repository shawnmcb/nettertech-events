<?php
/**
 * Attendees Filters Presenter (T4.2.4 Pages cluster).
 *
 * @package NetterTechEvents\Admin\Attendees\Presenters
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Attendees\Presenters;

defined( 'ABSPATH' ) || exit;

/**
 * Pure data-prep value object for the Attendees filters form template.
 *
 * Holds the data the template needs and exposes intent-revealing accessors.
 * Does NOT call `esc_*()` (template's job), does NOT output, does NOT read
 * from $_GET/$_POST/get_option, does NOT call $wpdb.
 *
 * @since 1.x.x
 */
final class AttendeesFiltersPresenter {

	/**
	 * Wire current filter state and occurrence list.
	 *
	 * @param int                                        $occurrence_id      Current occurrence filter ID (0 = no filter).
	 * @param int                                        $event_id           Current event filter ID (0 = no filter); preserved across filtering.
	 * @param string                                     $search             Current search query.
	 * @param string                                     $status_filter      Current status filter value.
	 * @param string                                     $placeholder_filter Current placeholder filter ('yes'/'no'/'').
	 * @param array<\NetterTechEvents\Models\Occurrence> $occurrences        Available occurrences for filter dropdown.
	 * @param string                                     $page_slug          The admin page slug ('<menu>-attendees').
	 * @param string                                     $clear_url          Absolute URL that clears all filters.
	 */
	public function __construct(
		private readonly int $occurrence_id,
		private readonly int $event_id,
		private readonly string $search,
		private readonly string $status_filter,
		private readonly string $placeholder_filter,
		private readonly array $occurrences,
		private readonly string $page_slug,
		private readonly string $clear_url
	) {}

	/**
	 * The page slug input value (hidden form field).
	 *
	 * @return string
	 */
	public function page_slug(): string {
		return $this->page_slug;
	}

	/**
	 * Return the current search query.
	 *
	 * @return string
	 */
	public function search(): string {
		return $this->search;
	}

	/**
	 * Return the current event filter id (0 = none). Preserved as a hidden field
	 * so changing other filters does not drop the event scope.
	 *
	 * @return int
	 */
	public function event_id(): int {
		return $this->event_id;
	}

	/**
	 * Return the occurrence dropdown options.
	 *
	 * Each row is ready for template emission: value (id as string), label
	 * (event title + formatted date), and selected flag.
	 *
	 * @return list<array{value: string, label: string, selected: bool}>
	 */
	public function occurrence_options(): array {
		$out = array();
		foreach ( $this->occurrences as $occ ) {
			$event_title = $occ->get_event() ? $occ->get_event()->title : __( 'Event', 'nettertech-events' );
			$out[]       = array(
				'value'    => (string) $occ->id,
				'label'    => sprintf( '%s - %s', $event_title, $occ->get_formatted_date() ),
				'selected' => (int) $occ->id === $this->occurrence_id,
			);
		}
		return $out;
	}

	/**
	 * Return status filter options.
	 *
	 * @return list<array{value: string, label: string, selected: bool}>
	 */
	public function status_options(): array {
		$choices = array(
			'confirmed' => __( 'Confirmed', 'nettertech-events' ),
			'pending'   => __( 'Pending', 'nettertech-events' ),
			'cancelled' => __( 'Cancelled', 'nettertech-events' ),
			'refunded'  => __( 'Refunded', 'nettertech-events' ),
			'voided'    => __( 'Voided', 'nettertech-events' ),
		);

		$out = array();
		foreach ( $choices as $value => $label ) {
			$out[] = array(
				'value'    => $value,
				'label'    => $label,
				'selected' => $value === $this->status_filter,
			);
		}
		return $out;
	}

	/**
	 * Return placeholder filter options (data-quality dropdown).
	 *
	 * @return list<array{value: string, label: string, selected: bool}>
	 */
	public function placeholder_options(): array {
		$choices = array(
			'no'  => __( 'Real Data Only', 'nettertech-events' ),
			'yes' => __( 'Placeholder Data', 'nettertech-events' ),
		);

		$out = array();
		foreach ( $choices as $value => $label ) {
			$out[] = array(
				'value'    => $value,
				'label'    => $label,
				'selected' => $value === $this->placeholder_filter,
			);
		}
		return $out;
	}

	/**
	 * Whether any filter is currently active (drives Clear button visibility).
	 *
	 * @return bool
	 */
	public function any_filter_active(): bool {
		return $this->occurrence_id > 0
			|| $this->event_id > 0
			|| '' !== $this->search
			|| '' !== $this->status_filter
			|| '' !== $this->placeholder_filter;
	}

	/**
	 * URL to clear all filters.
	 *
	 * @return string
	 */
	public function clear_url(): string {
		return $this->clear_url;
	}

	// ---- Translatable labels (templates emit these escaped) ----

	/**
	 * "Event" label.
	 *
	 * @return string
	 */
	public function event_label(): string {
		return __( 'Event', 'nettertech-events' );
	}

	/**
	 * "All Events" option label.
	 *
	 * @return string
	 */
	public function all_events_label(): string {
		return __( 'All Events', 'nettertech-events' );
	}

	/**
	 * "Status" label.
	 *
	 * @return string
	 */
	public function status_label(): string {
		return __( 'Status', 'nettertech-events' );
	}

	/**
	 * "All Statuses" option label.
	 *
	 * @return string
	 */
	public function all_statuses_label(): string {
		return __( 'All Statuses', 'nettertech-events' );
	}

	/**
	 * "Data Quality" label.
	 *
	 * @return string
	 */
	public function placeholder_label(): string {
		return __( 'Data Quality', 'nettertech-events' );
	}

	/**
	 * "All Attendees" option label.
	 *
	 * @return string
	 */
	public function all_attendees_label(): string {
		return __( 'All Attendees', 'nettertech-events' );
	}

	/**
	 * "Search" label.
	 *
	 * @return string
	 */
	public function search_label(): string {
		return __( 'Search', 'nettertech-events' );
	}

	/**
	 * Search field placeholder.
	 *
	 * @return string
	 */
	public function search_placeholder(): string {
		return __( 'Name or email...', 'nettertech-events' );
	}

	/**
	 * "Filter" button label.
	 *
	 * @return string
	 */
	public function filter_button_label(): string {
		return __( 'Filter', 'nettertech-events' );
	}

	/**
	 * "Clear" button label.
	 *
	 * @return string
	 */
	public function clear_button_label(): string {
		return __( 'Clear', 'nettertech-events' );
	}
}
