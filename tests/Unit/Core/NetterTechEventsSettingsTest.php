<?php
/**
 * NetterTechEventsSettings DTO unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\NetterTechEventsSettings;
use Brain\Monkey\Functions;

/**
 * Test NetterTechEventsSettings DTO functionality.
 */
class NetterTechEventsSettingsTest extends \NetterTechEventsTestCase {

	/**
	 * Test from_option returns defaults when option is empty.
	 *
	 * @return void
	 */
	public function test_from_option_empty_returns_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$settings = NetterTechEventsSettings::from_option();

		// Display settings.
		$this->assertSame( 'month', $settings->display->default_view );
		$this->assertSame( 10, $settings->display->events_per_page );
		$this->assertSame( '', $settings->display->timezone );
		$this->assertFalse( $settings->display->default_venue_enabled );
		$this->assertSame( '', $settings->display->default_venue_name );
		$this->assertSame( '', $settings->display->default_venue_address );
		$this->assertSame( 'About This Event', $settings->display->description_heading );
		$this->assertSame( '', $settings->display->events_archive_intro, 'events_archive_intro defaults to empty so template falls back to the localized default copy' );
		$this->assertSame( '19:00', $settings->display->default_event_start_time, 'default_event_start_time ships as 7:00 PM' );
		$this->assertSame( 120, $settings->display->default_event_duration_minutes, 'default_event_duration_minutes ships as 2 hours' );
		$this->assertFalse( $settings->display->show_end_time_by_default );
		$this->assertFalse( $settings->display->require_end_time );
		$this->assertSame( '16:9', $settings->display->image_aspect_ratio );
		$this->assertSame( 'cards', $settings->display->archive_layout );
		$this->assertSame( 3, $settings->display->archive_columns );
		$this->assertSame( 12, $settings->display->archive_limit );
		$this->assertFalse( $settings->display->date_badge_color_custom );
		$this->assertSame( '#2563eb', $settings->display->date_badge_color );
		$this->assertSame( array(), $settings->display->theme_colors );
		$this->assertNull( $settings->display->event_layout );

		// Ticket settings.
		$this->assertFalse( $settings->tickets->enable_rsvp );
		$this->assertFalse( $settings->tickets->enable_tickets );
		$this->assertSame( 1, $settings->tickets->default_min_per_order );
		$this->assertSame( 10, $settings->tickets->default_max_per_order );
		$this->assertSame( 10, $settings->tickets->low_stock_threshold );
		$this->assertFalse( $settings->tickets->enable_donations );
		$this->assertSame( 'Support our venue', $settings->tickets->donation_cause );
		$this->assertSame( 'dollar', $settings->tickets->roundup_to );
		$this->assertTrue( $settings->tickets->allow_custom_donation );
		$this->assertSame( 100, $settings->tickets->max_donation );
		$this->assertSame( array( 5.0, 10.0, 25.0 ), $settings->tickets->donation_presets );

		// QR settings.
		$this->assertSame( 5, $settings->qr->qr_scale );
		$this->assertSame( 100, $settings->qr->qr_bg_opacity );
		$this->assertSame( 'none', $settings->qr->qr_default_logo_mode );
		$this->assertSame( 0, $settings->qr->qr_default_logo_id );
		$this->assertSame( '#000000', $settings->qr->qr_foreground_color );
		$this->assertSame( '#ffffff', $settings->qr->qr_background_color );
		$this->assertSame( 'rounded', $settings->qr->qr_dot_style );
		$this->assertSame( 'square', $settings->qr->qr_finder_style );

		// Check-in settings.
		$this->assertSame( '', $settings->checkin->checkin_completion_email );
		$this->assertSame( array(), $settings->checkin->checkin_counters );

		// Performance settings.
		$this->assertSame( 365, $settings->performance->occurrence_horizon );
		$this->assertSame( 60, $settings->performance->rate_limit_requests );
		$this->assertSame( 60, $settings->performance->rate_limit_window );
		$this->assertSame( 900, $settings->performance->pending_hold_time );
		$this->assertSame( 3600, $settings->performance->category_cache_ttl );
		$this->assertSame( 730, $settings->performance->ical_feed_horizon_days );
		$this->assertFalse( $settings->performance->ical_feed_static_mode );

		// Advanced settings.
		$this->assertSame( 90, $settings->advanced->activity_log_retention_days );
		$this->assertFalse( $settings->advanced->delete_data_on_uninstall );
		$this->assertSame( 'events', $settings->advanced->events_base_path );
		$this->assertSame( '', $settings->advanced->events_archive_path );
		$this->assertSame( 'spaces', $settings->advanced->spaces_base_path );
		$this->assertFalse( $settings->advanced->show_frontend_branding );
		$this->assertSame( array(), $settings->advanced->allowed_embed_sources );
		$this->assertFalse( $settings->advanced->allow_insecure_embed_sources );
	}

	/**
	 * Test embed-source allowlist settings hydrate from the option, and that a
	 * non-array stored value falls back to an empty list.
	 *
	 * @return void
	 */
	public function test_from_option_hydrates_embed_sources(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'allowed_embed_sources'        => array( 'https://video.example.com', 'https://*.cdn.example' ),
				'allow_insecure_embed_sources' => '1',
			)
		);

		$settings = NetterTechEventsSettings::from_option();

		$this->assertSame(
			array( 'https://video.example.com', 'https://*.cdn.example' ),
			$settings->advanced->allowed_embed_sources
		);
		$this->assertTrue( $settings->advanced->allow_insecure_embed_sources );
	}

	/**
	 * Test a non-array allowed_embed_sources value degrades to an empty list.
	 *
	 * @return void
	 */
	public function test_from_option_embed_sources_non_array_falls_back(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'allowed_embed_sources' => 'not-an-array' )
		);

		$settings = NetterTechEventsSettings::from_option();

		$this->assertSame( array(), $settings->advanced->allowed_embed_sources );
	}

	/**
	 * Test from_option returns correct values with full settings.
	 *
	 * @return void
	 */
	public function test_from_option_full_settings(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'default_view'             => 'list',
				'events_per_page'          => 25,
				'timezone'                 => 'America/Chicago',
				'default_venue_enabled'    => '1',
				'default_venue_name'       => 'Test Venue',
				'default_venue_address'    => '123 Main St',
				'description_heading'      => 'Details',
				'events_archive_intro'     => 'Welcome to our calendar — find something to love.',
				'default_event_start_time' => '10:30',
				'default_event_duration_minutes' => 60,
				'enable_rsvp'              => '1',
				'enable_tickets'           => '1',
				'show_end_time_by_default' => '1',
				'require_end_time'         => '1',
				'default_min_per_order'    => 2,
				'default_max_per_order'    => 5,
				'low_stock_threshold'      => 15,
				'enable_donations'         => '1',
				'donation_cause'           => 'Help us grow',
				'roundup_to'               => 'five',
				'allow_custom_donation'    => '1',
				'max_donation'             => 500,
				'qr_scale'                 => 10,
				'qr_bg_opacity'            => 50,
				'qr_default_logo_mode'     => 'site',
				'qr_default_logo_id'       => 42,
				'qr_foreground_color'      => '#ff0000',
				'qr_background_color'      => '#eeeeee',
				'qr_dot_style'             => 'square',
				'qr_finder_style'          => 'rounded',
				'checkin_completion_email'  => 'admin@test.com',
				'occurrence_horizon'       => 180,
				'rate_limit_requests'      => 100,
				'rate_limit_window'        => 120,
				'pending_hold_time'        => 1800,
				'category_cache_ttl'       => 7200,
				'ical_feed_horizon_days'   => 365,
				'ical_feed_static_mode'    => true,
				'activity_log_retention_days' => 30,
				'delete_data_on_uninstall' => '1',
				'archive_layout'           => 'list',
				'archive_columns'          => 4,
				'archive_limit'            => 24,
				'events_base_path'         => 'shows',
				'events_archive_path'      => 'past-shows',
				'spaces_base_path'         => 'our-spaces',
				'date_badge_color_custom'  => '1',
				'date_badge_color'         => '#ff5500',
				'donation_presets'         => array( 10.0, 20.0, 50.0 ),
				'checkin_counters'         => array( 'Adults', 'Children' ),
				'theme_colors'             => array( 'customize' => true, 'primary' => '#123456' ),
				'event_layout'             => array( 'order' => array( 'header', 'description' ) ),
				'show_frontend_branding'   => '1',
			)
		);

		$settings = NetterTechEventsSettings::from_option();

		// Display settings.
		$this->assertSame( 'list', $settings->display->default_view );
		$this->assertSame( 25, $settings->display->events_per_page );
		$this->assertSame( 'America/Chicago', $settings->display->timezone );
		$this->assertTrue( $settings->display->default_venue_enabled );
		$this->assertSame( 'Test Venue', $settings->display->default_venue_name );
		$this->assertSame( '123 Main St', $settings->display->default_venue_address );
		$this->assertSame( 'Details', $settings->display->description_heading );
		$this->assertSame( 'Welcome to our calendar — find something to love.', $settings->display->events_archive_intro );
		$this->assertSame( '10:30', $settings->display->default_event_start_time );
		$this->assertSame( 60, $settings->display->default_event_duration_minutes );
		$this->assertTrue( $settings->display->show_end_time_by_default );
		$this->assertTrue( $settings->display->require_end_time );
		$this->assertSame( 'list', $settings->display->archive_layout );
		$this->assertSame( 4, $settings->display->archive_columns );
		$this->assertSame( 24, $settings->display->archive_limit );
		$this->assertTrue( $settings->display->date_badge_color_custom );
		$this->assertSame( '#ff5500', $settings->display->date_badge_color );
		$this->assertSame( array( 'customize' => true, 'primary' => '#123456' ), $settings->display->theme_colors );
		$this->assertSame( array( 'order' => array( 'header', 'description' ) ), $settings->display->event_layout );

		// Ticket settings.
		$this->assertTrue( $settings->tickets->enable_rsvp );
		$this->assertTrue( $settings->tickets->enable_tickets );
		$this->assertSame( 2, $settings->tickets->default_min_per_order );
		$this->assertSame( 5, $settings->tickets->default_max_per_order );
		$this->assertSame( 15, $settings->tickets->low_stock_threshold );
		$this->assertTrue( $settings->tickets->enable_donations );
		$this->assertSame( 'Help us grow', $settings->tickets->donation_cause );
		$this->assertSame( 'five', $settings->tickets->roundup_to );
		$this->assertTrue( $settings->tickets->allow_custom_donation );
		$this->assertSame( 500, $settings->tickets->max_donation );
		$this->assertSame( array( 10.0, 20.0, 50.0 ), $settings->tickets->donation_presets );

		// QR settings.
		$this->assertSame( 10, $settings->qr->qr_scale );
		$this->assertSame( 50, $settings->qr->qr_bg_opacity );
		$this->assertSame( 'site', $settings->qr->qr_default_logo_mode );
		$this->assertSame( 42, $settings->qr->qr_default_logo_id );
		$this->assertSame( '#ff0000', $settings->qr->qr_foreground_color );
		$this->assertSame( '#eeeeee', $settings->qr->qr_background_color );
		$this->assertSame( 'square', $settings->qr->qr_dot_style );
		$this->assertSame( 'rounded', $settings->qr->qr_finder_style );

		// Check-in settings.
		$this->assertSame( 'admin@test.com', $settings->checkin->checkin_completion_email );
		$this->assertSame( array( 'Adults', 'Children' ), $settings->checkin->checkin_counters );

		// Performance settings.
		$this->assertSame( 180, $settings->performance->occurrence_horizon );
		$this->assertSame( 100, $settings->performance->rate_limit_requests );
		$this->assertSame( 120, $settings->performance->rate_limit_window );
		$this->assertSame( 1800, $settings->performance->pending_hold_time );
		$this->assertSame( 7200, $settings->performance->category_cache_ttl );
		$this->assertSame( 365, $settings->performance->ical_feed_horizon_days );
		$this->assertTrue( $settings->performance->ical_feed_static_mode );

		// Advanced settings.
		$this->assertSame( 30, $settings->advanced->activity_log_retention_days );
		$this->assertTrue( $settings->advanced->delete_data_on_uninstall );
		$this->assertSame( 'shows', $settings->advanced->events_base_path );
		$this->assertSame( 'past-shows', $settings->advanced->events_archive_path );
		$this->assertSame( 'our-spaces', $settings->advanced->spaces_base_path );
		$this->assertTrue( $settings->advanced->show_frontend_branding );
	}

	/**
	 * Test boolean fields cast from string '1'/'0'.
	 *
	 * @return void
	 */
	public function test_boolean_fields_cast_from_strings(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'default_venue_enabled'    => '1',
				'enable_rsvp'              => '0',
				'enable_tickets'           => '',
				'show_end_time_by_default' => '1',
				'enable_donations'         => 0,
				'delete_data_on_uninstall' => 1,
			)
		);

		$settings = NetterTechEventsSettings::from_option();

		$this->assertTrue( $settings->display->default_venue_enabled );
		$this->assertFalse( $settings->tickets->enable_rsvp );
		$this->assertFalse( $settings->tickets->enable_tickets );
		$this->assertTrue( $settings->display->show_end_time_by_default );
		$this->assertFalse( $settings->tickets->enable_donations );
		$this->assertTrue( $settings->advanced->delete_data_on_uninstall );
	}

	/**
	 * Test array fields are preserved.
	 *
	 * @return void
	 */
	public function test_array_fields_preserved(): void {
		$theme_colors = array(
			'customize' => true,
			'primary'   => '#2563eb',
			'text'      => '#1f2937',
		);
		$layout = array(
			'order'      => array( 'header', 'featured_image', 'description' ),
			'visibility' => array( 'header' => true ),
		);

		Functions\when( 'get_option' )->justReturn(
			array(
				'theme_colors'     => $theme_colors,
				'event_layout'     => $layout,
				'donation_presets' => array( 1.0, 2.0, 5.0 ),
				'checkin_counters' => array( 'Adults', 'Seniors' ),
			)
		);

		$settings = NetterTechEventsSettings::from_option();

		$this->assertSame( $theme_colors, $settings->display->theme_colors );
		$this->assertSame( $layout, $settings->display->event_layout );
		$this->assertSame( array( 1.0, 2.0, 5.0 ), $settings->tickets->donation_presets );
		$this->assertSame( array( 'Adults', 'Seniors' ), $settings->checkin->checkin_counters );
	}

	/**
	 * Test missing keys use defaults.
	 *
	 * @return void
	 */
	public function test_missing_keys_use_defaults(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'default_view' => 'week',
			)
		);

		$settings = NetterTechEventsSettings::from_option();

		$this->assertSame( 'week', $settings->display->default_view );
		$this->assertSame( 10, $settings->display->events_per_page );
		$this->assertSame( 5, $settings->qr->qr_scale );
		$this->assertSame( 'events', $settings->advanced->events_base_path );
		$this->assertSame( array( 5.0, 10.0, 25.0 ), $settings->tickets->donation_presets );
	}

	/**
	 * Test from_option handles non-array option value.
	 *
	 * @return void
	 */
	public function test_from_option_handles_non_array(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$settings = NetterTechEventsSettings::from_option();

		$this->assertSame( 'month', $settings->display->default_view );
		$this->assertFalse( $settings->tickets->enable_tickets );
	}

	/**
	 * Sub-minute durations clamp to the 10-minute floor — guards against
	 * a misconfigured stored option starving the soft-fill safety check
	 * in the metabox JS / EventSaveHandler.
	 *
	 * @return void
	 */
	public function test_from_option_clamps_default_duration_to_ten_minutes(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'default_event_duration_minutes' => 3,
			)
		);

		$settings = NetterTechEventsSettings::from_option();

		$this->assertSame( 10, $settings->display->default_event_duration_minutes );
	}

	/**
	 * Test get_view_aspect_ratio returns correct values.
	 *
	 * @return void
	 */
	public function test_get_view_aspect_ratio(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'image_aspect_ratio_single'       => '3:2',
				'image_aspect_ratio_single_custom' => '',
				'image_aspect_ratio_cards'        => '4:3',
				'image_aspect_ratio_cards_custom'  => '',
				'image_aspect_ratio_list'          => 'custom',
				'image_aspect_ratio_list_custom'   => '5:4',
			)
		);

		$settings = NetterTechEventsSettings::from_option();

		$single = $settings->display->get_view_aspect_ratio( 'single' );
		$this->assertSame( '3:2', $single['preset'] );
		$this->assertSame( '', $single['custom'] );

		$cards = $settings->display->get_view_aspect_ratio( 'cards' );
		$this->assertSame( '4:3', $cards['preset'] );
		$this->assertSame( '', $cards['custom'] );

		$list = $settings->display->get_view_aspect_ratio( 'list' );
		$this->assertSame( 'custom', $list['preset'] );
		$this->assertSame( '5:4', $list['custom'] );

		// Unknown view returns empty strings.
		$unknown = $settings->display->get_view_aspect_ratio( 'unknown' );
		$this->assertSame( '', $unknown['preset'] );
		$this->assertSame( '', $unknown['custom'] );
	}

	/**
	 * Test DTO is readonly — properties cannot be modified.
	 *
	 * @return void
	 */
	public function test_dto_is_readonly(): void {
		$settings = new NetterTechEventsSettings();

		$this->expectException( \Error::class );
		// @phpstan-ignore-next-line -- Testing runtime readonly enforcement.
		$settings->display = new \NetterTechEvents\Core\Settings\DisplaySettings( default_view: 'week' );
	}

	/**
	 * Test constructor defaults without from_option.
	 *
	 * @return void
	 */
	public function test_constructor_defaults(): void {
		$settings = new NetterTechEventsSettings();

		$this->assertSame( 'month', $settings->display->default_view );
		$this->assertSame( 10, $settings->display->events_per_page );
		$this->assertFalse( $settings->tickets->enable_tickets );
		$this->assertSame( 5, $settings->qr->qr_scale );
	}

	/**
	 * Test non-array donation_presets falls back to default.
	 *
	 * @return void
	 */
	public function test_non_array_donation_presets_fallback(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'donation_presets' => 'not_an_array',
			)
		);

		$settings = NetterTechEventsSettings::from_option();

		$this->assertSame( array( 5.0, 10.0, 25.0 ), $settings->tickets->donation_presets );
	}

	/**
	 * Test non-array event_layout returns null.
	 *
	 * @return void
	 */
	public function test_non_array_event_layout_returns_null(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'event_layout' => 'not_an_array',
			)
		);

		$settings = NetterTechEventsSettings::from_option();

		$this->assertNull( $settings->display->event_layout );
	}

	/**
	 * Test sub-DTOs are accessible as public properties.
	 *
	 * @return void
	 */
	public function test_sub_dtos_are_accessible(): void {
		$settings = new NetterTechEventsSettings();

		$this->assertInstanceOf( \NetterTechEvents\Core\Settings\DisplaySettings::class, $settings->display );
		$this->assertInstanceOf( \NetterTechEvents\Core\Settings\TicketSettings::class, $settings->tickets );
		$this->assertInstanceOf( \NetterTechEvents\Core\Settings\QRSettings::class, $settings->qr );
		$this->assertInstanceOf( \NetterTechEvents\Core\Settings\CheckInSettings::class, $settings->checkin );
		$this->assertInstanceOf( \NetterTechEvents\Core\Settings\PerformanceSettings::class, $settings->performance );
		$this->assertInstanceOf( \NetterTechEvents\Core\Settings\AdvancedSettings::class, $settings->advanced );
	}

	// =========================================================================
	// Archive filter visibility settings (NTE-069)
	// =========================================================================

	/**
	 * Missing archive filter visibility keys default to true (back-compat for upgrades).
	 *
	 * Sites upgrading from <1.1.0 have no stored value for these keys;
	 * the ! isset() || ! empty() pattern must yield true so behaviour is unchanged.
	 *
	 * @return void
	 */
	public function test_archive_filter_keys_missing_default_to_true(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$settings = NetterTechEventsSettings::from_option();

		$this->assertTrue( $settings->display->archive_show_filters );
		$this->assertTrue( $settings->display->archive_show_search );
		$this->assertTrue( $settings->display->archive_show_category );
		$this->assertTrue( $settings->display->archive_show_tag );
		$this->assertTrue( $settings->display->archive_show_date_range );
	}

	/**
	 * Explicit false survives the hydration round trip.
	 *
	 * When a stored option contains '0' / false, from_option() must return false
	 * (not coerce it back to true via the isset() branch).
	 *
	 * @return void
	 */
	public function test_archive_filter_explicit_false_survives_round_trip(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'archive_show_filters'    => false,
				'archive_show_search'     => false,
				'archive_show_category'   => false,
				'archive_show_tag'        => false,
				'archive_show_date_range' => false,
			)
		);

		$settings = NetterTechEventsSettings::from_option();

		$this->assertFalse( $settings->display->archive_show_filters );
		$this->assertFalse( $settings->display->archive_show_search );
		$this->assertFalse( $settings->display->archive_show_category );
		$this->assertFalse( $settings->display->archive_show_tag );
		$this->assertFalse( $settings->display->archive_show_date_range );
	}

	/**
	 * Explicit true survives the hydration round trip.
	 *
	 * @return void
	 */
	public function test_archive_filter_explicit_true_survives_round_trip(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'archive_show_filters'    => '1',
				'archive_show_search'     => '1',
				'archive_show_category'   => '1',
				'archive_show_tag'        => '1',
				'archive_show_date_range' => '1',
			)
		);

		$settings = NetterTechEventsSettings::from_option();

		$this->assertTrue( $settings->display->archive_show_filters );
		$this->assertTrue( $settings->display->archive_show_search );
		$this->assertTrue( $settings->display->archive_show_category );
		$this->assertTrue( $settings->display->archive_show_tag );
		$this->assertTrue( $settings->display->archive_show_date_range );
	}

	/**
	 * Mixed per-field state is preserved correctly.
	 *
	 * Verifies that individual fields can be set independently —
	 * e.g. show_filters=true but show_search=false (hide search only).
	 *
	 * @return void
	 */
	public function test_archive_filter_mixed_state_preserved(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'archive_show_filters'    => '1',
				'archive_show_search'     => false,
				'archive_show_category'   => '1',
				'archive_show_tag'        => false,
				'archive_show_date_range' => '1',
			)
		);

		$settings = NetterTechEventsSettings::from_option();

		$this->assertTrue( $settings->display->archive_show_filters );
		$this->assertFalse( $settings->display->archive_show_search );
		$this->assertTrue( $settings->display->archive_show_category );
		$this->assertFalse( $settings->display->archive_show_tag );
		$this->assertTrue( $settings->display->archive_show_date_range );
	}
}
