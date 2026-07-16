<?php
/**
 * General Settings Presenter (T4.2 pilot).
 *
 * @package NetterTechEvents\Admin\Settings\Presenters
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Settings\Presenters;

defined( 'ABSPATH' ) || exit;

/**
 * Pure data-prep value object for the General Settings render template.
 *
 * Holds the data the template needs and exposes intent-revealing accessors.
 * Does NOT call `esc_*()` (template's job), does NOT output, does NOT read
 * from $_GET/$_POST/get_option, does NOT call $wpdb.
 *
 * @since 1.x.x
 */
final class GeneralSettingsPresenter {

	/**
	 * Wire the current settings array.
	 *
	 * @param array<string, mixed> $settings Current settings (from get_option / Section save).
	 */
	public function __construct(
		private readonly array $settings
	) {}

	/**
	 * Return the four default-view options with selected flag for each.
	 *
	 * @return list<array{value: string, label: string, selected: bool}>
	 */
	public function default_view_options(): array {
		$current = (string) ( $this->settings['default_view'] ?? 'month' );
		$choices = array(
			'month' => __( 'Month', 'nettertech-events' ),
			'week'  => __( 'Week', 'nettertech-events' ),
			'day'   => __( 'Day', 'nettertech-events' ),
			'list'  => __( 'List', 'nettertech-events' ),
		);

		$out = array();
		foreach ( $choices as $value => $label ) {
			$out[] = array(
				'value'    => $value,
				'label'    => $label,
				'selected' => $value === $current,
			);
		}
		return $out;
	}

	/**
	 * Return the events-per-page input value.
	 *
	 * @return int
	 */
	public function events_per_page(): int {
		$raw = $this->settings['events_per_page'] ?? 10;
		return is_numeric( $raw ) ? (int) $raw : 10;
	}

	/**
	 * Return the timezone string to seed wp_timezone_choice().
	 *
	 * Template calls wp_timezone_choice( $presenter->timezone() ) directly —
	 * that WP-core function is escaping-safe and emits its own HTML.
	 *
	 * @return string
	 */
	public function timezone(): string {
		$raw = (string) ( $this->settings['timezone'] ?? '' );
		return '' !== $raw ? $raw : wp_timezone_string();
	}

	/**
	 * Return the section title (translatable).
	 *
	 * @return string
	 */
	public function section_title(): string {
		return __( 'General Settings', 'nettertech-events' );
	}

	/**
	 * Return the events-per-page label (translatable).
	 *
	 * @return string
	 */
	public function events_per_page_label(): string {
		return __( 'Events Per Page', 'nettertech-events' );
	}

	/**
	 * Return the default-view label (translatable).
	 *
	 * @return string
	 */
	public function default_view_label(): string {
		return __( 'Default Calendar View', 'nettertech-events' );
	}

	/**
	 * Return the timezone label (translatable).
	 *
	 * @return string
	 */
	public function timezone_label(): string {
		return __( 'Timezone', 'nettertech-events' );
	}

	/**
	 * Return the timezone description text (translatable).
	 *
	 * @return string
	 */
	public function timezone_description(): string {
		return __( 'Default timezone for events.', 'nettertech-events' );
	}
}
