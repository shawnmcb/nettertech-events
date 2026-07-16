<?php
/**
 * Read-only settings DTO for nettertech-events plugin settings.
 *
 * Centralizes access to the 'nettertech_events_settings' option with
 * typed properties, proper defaults, and boolean casting. Only wraps
 * the READ path — SettingsPage::save_settings() continues writing raw
 * arrays via update_option().
 *
 * Composed of sub-group DTOs for logical organization.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Settings\AdvancedSettings;
use NetterTechEvents\Core\Settings\CheckInSettings;
use NetterTechEvents\Core\Settings\DisplaySettings;
use NetterTechEvents\Core\Settings\PerformanceSettings;
use NetterTechEvents\Core\Settings\QRSettings;
use NetterTechEvents\Core\Settings\TicketSettings;

/**
 * Typed, read-only settings value object.
 *
 * Properties are organized into sub-group DTOs:
 * - display:     View defaults, aspect ratios, archive layout, venue, theme colors
 * - tickets:     Ticket/RSVP toggles, order limits, donations
 * - qr:          QR code styling and logo settings
 * - checkin:     Check-in report email and counter labels
 * - performance: Rate limits, caching, occurrence horizon
 * - advanced:    URL paths, data retention, branding
 *
 * @since 1.6.0
 * @since 1.7.0 Decomposed into sub-group DTOs.
 */
final readonly class NetterTechEventsSettings {

	/**
	 * Constructor with property promotion.
	 *
	 * @param DisplaySettings     $display     Display and layout settings.
	 * @param TicketSettings      $tickets     Ticket and commerce settings.
	 * @param QRSettings          $qr          QR code settings.
	 * @param CheckInSettings     $checkin     Check-in settings.
	 * @param PerformanceSettings $performance Performance and caching settings.
	 * @param AdvancedSettings    $advanced    Advanced settings.
	 */
	public function __construct(
		public DisplaySettings $display = new DisplaySettings(),
		public TicketSettings $tickets = new TicketSettings(),
		public QRSettings $qr = new QRSettings(),
		public CheckInSettings $checkin = new CheckInSettings(),
		public PerformanceSettings $performance = new PerformanceSettings(),
		public AdvancedSettings $advanced = new AdvancedSettings(),
	) {
	}

	/**
	 * Create from the WordPress option.
	 *
	 * @return self
	 */
	public static function from_option(): self {
		$s = get_option( 'nettertech_events_settings', array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}

		$display = new DisplaySettings(
			default_view: (string) ( $s['default_view'] ?? 'month' ),
			events_per_page: (int) ( $s['events_per_page'] ?? 10 ),
			timezone: (string) ( $s['timezone'] ?? '' ),
			default_venue_enabled: ! empty( $s['default_venue_enabled'] ),
			default_venue_name: (string) ( $s['default_venue_name'] ?? '' ),
			default_venue_address: (string) ( $s['default_venue_address'] ?? '' ),
			description_heading: (string) ( $s['description_heading'] ?? 'About This Event' ),
			events_archive_intro: (string) ( $s['events_archive_intro'] ?? '' ),
			default_event_start_time: (string) ( $s['default_event_start_time'] ?? '19:00' ),
			default_event_duration_minutes: max( 10, (int) ( $s['default_event_duration_minutes'] ?? 120 ) ),
			show_end_time_by_default: ! empty( $s['show_end_time_by_default'] ),
			require_end_time: ! empty( $s['require_end_time'] ),
			image_aspect_ratio: (string) ( $s['image_aspect_ratio'] ?? '16:9' ),
			image_aspect_ratio_custom: (string) ( $s['image_aspect_ratio_custom'] ?? '' ),
			image_aspect_ratio_single: (string) ( $s['image_aspect_ratio_single'] ?? '' ),
			image_aspect_ratio_single_custom: (string) ( $s['image_aspect_ratio_single_custom'] ?? '' ),
			image_aspect_ratio_cards: (string) ( $s['image_aspect_ratio_cards'] ?? '' ),
			image_aspect_ratio_cards_custom: (string) ( $s['image_aspect_ratio_cards_custom'] ?? '' ),
			image_aspect_ratio_list: (string) ( $s['image_aspect_ratio_list'] ?? '' ),
			image_aspect_ratio_list_custom: (string) ( $s['image_aspect_ratio_list_custom'] ?? '' ),
			image_aspect_ratio_carousel: (string) ( $s['image_aspect_ratio_carousel'] ?? '' ),
			image_aspect_ratio_carousel_custom: (string) ( $s['image_aspect_ratio_carousel_custom'] ?? '' ),
			image_aspect_ratio_calendar: (string) ( $s['image_aspect_ratio_calendar'] ?? '' ),
			image_aspect_ratio_calendar_custom: (string) ( $s['image_aspect_ratio_calendar_custom'] ?? '' ),
			archive_layout: (string) ( $s['archive_layout'] ?? 'cards' ),
			archive_columns: (int) ( $s['archive_columns'] ?? 3 ),
			archive_limit: (int) ( $s['archive_limit'] ?? 12 ),
			archive_show_filters: ! isset( $s['archive_show_filters'] ) || ! empty( $s['archive_show_filters'] ),
			archive_show_search: ! isset( $s['archive_show_search'] ) || ! empty( $s['archive_show_search'] ),
			archive_show_category: ! isset( $s['archive_show_category'] ) || ! empty( $s['archive_show_category'] ),
			archive_show_tag: ! isset( $s['archive_show_tag'] ) || ! empty( $s['archive_show_tag'] ),
			archive_show_date_range: ! isset( $s['archive_show_date_range'] ) || ! empty( $s['archive_show_date_range'] ),
			date_badge_color_custom: ! empty( $s['date_badge_color_custom'] ),
			date_badge_color: (string) ( $s['date_badge_color'] ?? '#2563eb' ),
			theme_colors: is_array( $s['theme_colors'] ?? null ) ? $s['theme_colors'] : array(),
			event_layout: isset( $s['event_layout'] ) && is_array( $s['event_layout'] ) ? $s['event_layout'] : null,
		);

		$tickets = new TicketSettings(
			enable_rsvp: ! empty( $s['enable_rsvp'] ),
			enable_tickets: ! empty( $s['enable_tickets'] ),
			default_min_per_order: (int) ( $s['default_min_per_order'] ?? 1 ),
			default_max_per_order: (int) ( $s['default_max_per_order'] ?? 10 ),
			low_stock_threshold: (int) ( $s['low_stock_threshold'] ?? 10 ),
			enable_donations: ! empty( $s['enable_donations'] ),
			donation_cause: (string) ( $s['donation_cause'] ?? 'Support our venue' ),
			roundup_to: (string) ( $s['roundup_to'] ?? 'dollar' ),
			allow_custom_donation: ! isset( $s['allow_custom_donation'] ) || ! empty( $s['allow_custom_donation'] ),
			max_donation: (int) ( $s['max_donation'] ?? 100 ),
			donation_presets: is_array( $s['donation_presets'] ?? null ) ? $s['donation_presets'] : array( 5.0, 10.0, 25.0 ),
		);

		$qr = new QRSettings(
			qr_scale: (int) ( $s['qr_scale'] ?? 5 ),
			qr_bg_opacity: (int) ( $s['qr_bg_opacity'] ?? 100 ),
			qr_default_logo_mode: (string) ( $s['qr_default_logo_mode'] ?? 'none' ),
			qr_default_logo_id: (int) ( $s['qr_default_logo_id'] ?? 0 ),
			qr_foreground_color: (string) ( $s['qr_foreground_color'] ?? '#000000' ),
			qr_background_color: (string) ( $s['qr_background_color'] ?? '#ffffff' ),
			qr_dot_style: (string) ( $s['qr_dot_style'] ?? 'rounded' ),
			qr_finder_style: (string) ( $s['qr_finder_style'] ?? 'square' ),
		);

		$checkin = new CheckInSettings(
			checkin_completion_email: (string) ( $s['checkin_completion_email'] ?? '' ),
			checkin_counters: is_array( $s['checkin_counters'] ?? null ) ? $s['checkin_counters'] : array(),
		);

		$performance = new PerformanceSettings(
			occurrence_horizon: (int) ( $s['occurrence_horizon'] ?? 365 ),
			rate_limit_requests: (int) ( $s['rate_limit_requests'] ?? 60 ),
			rate_limit_window: (int) ( $s['rate_limit_window'] ?? 60 ),
			pending_hold_time: (int) ( $s['pending_hold_time'] ?? 900 ),
			category_cache_ttl: (int) ( $s['category_cache_ttl'] ?? 3600 ),
			ical_feed_horizon_days: (int) ( $s['ical_feed_horizon_days'] ?? 730 ),
			ical_feed_static_mode: ! empty( $s['ical_feed_static_mode'] ),
			rate_limit_proxy_mode: (string) ( $s['rate_limit_proxy_mode'] ?? 'auto' ),
		);

		$advanced = new AdvancedSettings(
			activity_log_retention_days: (int) ( $s['activity_log_retention_days'] ?? 90 ),
			delete_data_on_uninstall: ! empty( $s['delete_data_on_uninstall'] ),
			events_base_path: (string) ( $s['events_base_path'] ?? 'events' ),
			events_archive_path: (string) ( $s['events_archive_path'] ?? '' ),
			spaces_base_path: (string) ( $s['spaces_base_path'] ?? 'spaces' ),
			show_frontend_branding: ! empty( $s['show_frontend_branding'] ),
			allowed_embed_sources: ( isset( $s['allowed_embed_sources'] ) && is_array( $s['allowed_embed_sources'] ) )
				? array_values( array_filter( array_map( 'strval', $s['allowed_embed_sources'] ) ) )
				: array(),
			allow_insecure_embed_sources: ! empty( $s['allow_insecure_embed_sources'] ),
			allowed_script_sources: ( isset( $s['allowed_script_sources'] ) && is_array( $s['allowed_script_sources'] ) )
				? array_values( array_filter( array_map( 'strval', $s['allowed_script_sources'] ) ) )
				: array(),
		);

		return new self(
			display: $display,
			tickets: $tickets,
			qr: $qr,
			checkin: $checkin,
			performance: $performance,
			advanced: $advanced,
		);
	}
}
