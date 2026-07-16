<?php
/**
 * Display settings sub-group DTO.
 *
 * Contains all visual/layout settings: calendar view, archive display,
 * image aspect ratios, venue defaults, date badge, and description heading.
 *
 * @package NetterTechEvents\Core\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Typed, read-only display settings value object.
 *
 * @since 1.7.0
 */
final readonly class DisplaySettings {

	/**
	 * Constructor with property promotion.
	 *
	 * @param string                    $default_view                      Default calendar view.
	 * @param int                       $events_per_page                   Events per page.
	 * @param string                    $timezone                          Timezone string.
	 * @param bool                      $default_venue_enabled             Pre-populate default venue.
	 * @param string                    $default_venue_name                Default venue name.
	 * @param string                    $default_venue_address             Default venue address.
	 * @param string                    $description_heading               Event description heading.
	 * @param string                    $events_archive_intro              Intro text shown beneath the Upcoming Events heading on the public events archive.
	 * @param string                    $default_event_start_time          Default start time for new events (HH:MM); applied when author leaves the time field blank.
	 * @param int                       $default_event_duration_minutes    Default duration in minutes for new events; applied when author leaves the end time blank.
	 * @param bool                      $show_end_time_by_default          Show end time by default.
	 * @param bool                      $require_end_time                  Require end time.
	 * @param string                    $image_aspect_ratio                Default image aspect ratio preset.
	 * @param string                    $image_aspect_ratio_custom         Custom aspect ratio value.
	 * @param string                    $image_aspect_ratio_single          Single event-page aspect ratio.
	 * @param string                    $image_aspect_ratio_single_custom   Single custom aspect ratio.
	 * @param string                    $image_aspect_ratio_cards           Cards view aspect ratio.
	 * @param string                    $image_aspect_ratio_cards_custom    Cards custom aspect ratio.
	 * @param string                    $image_aspect_ratio_list            List view aspect ratio.
	 * @param string                    $image_aspect_ratio_list_custom     List custom aspect ratio.
	 * @param string                    $image_aspect_ratio_carousel        Carousel view aspect ratio.
	 * @param string                    $image_aspect_ratio_carousel_custom Carousel custom aspect ratio.
	 * @param string                    $image_aspect_ratio_calendar        Calendar view aspect ratio.
	 * @param string                    $image_aspect_ratio_calendar_custom Calendar custom aspect ratio.
	 * @param string                    $archive_layout                    Archive layout (grid|list|cards).
	 * @param int                       $archive_columns                   Archive columns.
	 * @param int                       $archive_limit                     Archive items limit.
	 * @param bool                      $date_badge_color_custom           Use custom date badge color.
	 * @param string                    $date_badge_color                  Date badge hex color.
	 * @param array<string, mixed>      $theme_colors                      Theme color overrides.
	 * @param array<string, mixed>|null $event_layout                      Event layout configuration.
	 * @param bool                      $archive_show_filters              Show filter bar on archive pages.
	 * @param bool                      $archive_show_search               Show search input in filter bar.
	 * @param bool                      $archive_show_category             Show category filter in filter bar.
	 * @param bool                      $archive_show_tag                  Show tag filter in filter bar.
	 * @param bool                      $archive_show_date_range           Show date range picker in filter bar.
	 */
	public function __construct(
		public string $default_view = 'month',
		public int $events_per_page = 10,
		public string $timezone = '',
		public bool $default_venue_enabled = false,
		public string $default_venue_name = '',
		public string $default_venue_address = '',
		public string $description_heading = 'About This Event',
		public string $events_archive_intro = '',
		public string $default_event_start_time = '19:00',
		public int $default_event_duration_minutes = 120,
		public bool $show_end_time_by_default = false,
		public bool $require_end_time = false,
		public string $image_aspect_ratio = '16:9',
		public string $image_aspect_ratio_custom = '',
		public string $image_aspect_ratio_single = '',
		public string $image_aspect_ratio_single_custom = '',
		public string $image_aspect_ratio_cards = '',
		public string $image_aspect_ratio_cards_custom = '',
		public string $image_aspect_ratio_list = '',
		public string $image_aspect_ratio_list_custom = '',
		public string $image_aspect_ratio_carousel = '',
		public string $image_aspect_ratio_carousel_custom = '',
		public string $image_aspect_ratio_calendar = '',
		public string $image_aspect_ratio_calendar_custom = '',
		public string $archive_layout = 'cards',
		public int $archive_columns = 3,
		public int $archive_limit = 12,
		public bool $date_badge_color_custom = false,
		public string $date_badge_color = '#2563eb',
		public array $theme_colors = array(),
		public ?array $event_layout = null,
		public bool $archive_show_filters = true,
		public bool $archive_show_search = true,
		public bool $archive_show_category = true,
		public bool $archive_show_tag = true,
		public bool $archive_show_date_range = true,
	) {
	}

	/**
	 * Get the raw settings array for the image aspect ratio of a specific view.
	 *
	 * Helper to reduce boilerplate when iterating over view-specific ratios.
	 *
	 * @param string $view View name (single, cards, list, carousel, calendar).
	 * @return array{preset: string, custom: string}
	 */
	public function get_view_aspect_ratio( string $view ): array {
		$preset_prop = 'image_aspect_ratio_' . $view;
		$custom_prop = 'image_aspect_ratio_' . $view . '_custom';

		return array(
			'preset' => property_exists( $this, $preset_prop ) ? $this->{$preset_prop} : '',
			'custom' => property_exists( $this, $custom_prop ) ? $this->{$custom_prop} : '',
		);
	}
}
