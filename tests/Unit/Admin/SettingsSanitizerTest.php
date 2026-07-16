<?php
/**
 * SettingsSanitizer unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\SettingsSanitizer;

/**
 * Test SettingsSanitizer functionality.
 *
 * Covers sanitize(), sanitize_hex_color(), parse_donation_presets(),
 * parse_checkin_counters(), get_aspect_ratio_css(), and all private
 * sanitization methods exercised through the public interface.
 */
class SettingsSanitizerTest extends \NetterTechEventsTestCase {

	/**
	 * The sanitizer instance under test.
	 *
	 * @var SettingsSanitizer
	 */
	private SettingsSanitizer $sanitizer;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->sanitizer = new SettingsSanitizer();

		// Mock wp_timezone_string (not in base stubs).
		Functions\when( 'wp_timezone_string' )->justReturn( 'America/Chicago' );

		// Mock sanitize_hex_color (not in base stubs).
		Functions\when( 'sanitize_hex_color' )->alias(
			function ( $color ) {
				if ( is_string( $color ) && preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color ) ) {
					return $color;
				}
				return '';
			}
		);
	}

	// =========================================================================
	// sanitize_bounded_int (via sanitize())
	// =========================================================================

	/**
	 * Test bounded int below minimum clamps to minimum.
	 *
	 * @return void
	 */
	public function test_bounded_int_below_min_clamps_to_min(): void {
		$input  = array( 'default_min_per_order' => 0 );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 1, $result['default_min_per_order'] );
	}

	/**
	 * Test bounded int above maximum clamps to maximum.
	 *
	 * @return void
	 */
	public function test_bounded_int_above_max_clamps_to_max(): void {
		$input  = array( 'default_max_per_order' => 999 );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 100, $result['default_max_per_order'] );
	}

	/**
	 * Test bounded int within range passes through.
	 *
	 * @return void
	 */
	public function test_bounded_int_in_range_passes_through(): void {
		$input  = array( 'low_stock_threshold' => 25 );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 25, $result['low_stock_threshold'] );
	}

	/**
	 * Test iCal feed horizon accepts 0 as the opt-out value (NTE-014).
	 *
	 * Unlike most advanced bounded ints, this field has min 0 so an operator
	 * can disable the cap entirely. Zero must survive sanitization.
	 *
	 * @return void
	 */
	public function test_ical_feed_horizon_allows_zero_optout(): void {
		$input  = array( 'ical_feed_horizon_days' => 0 );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 0, $result['ical_feed_horizon_days'] );
	}

	/**
	 * Test iCal feed horizon clamps above its maximum (NTE-014).
	 *
	 * @return void
	 */
	public function test_ical_feed_horizon_clamps_above_max(): void {
		$input  = array( 'ical_feed_horizon_days' => 99999 );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 3650, $result['ical_feed_horizon_days'] );
	}

	/**
	 * Test bounded int null value uses default.
	 *
	 * @return void
	 */
	public function test_bounded_int_null_uses_default(): void {
		$input  = array(); // default_min_per_order not provided.
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 1, $result['default_min_per_order'] );
	}

	/**
	 * Test bounded int at exact minimum boundary.
	 *
	 * @return void
	 */
	public function test_bounded_int_at_exact_min(): void {
		$input  = array( 'qr_scale' => 3 );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 3, $result['qr_scale'] );
	}

	/**
	 * Test bounded int at exact maximum boundary.
	 *
	 * @return void
	 */
	public function test_bounded_int_at_exact_max(): void {
		$input  = array( 'qr_scale' => 20 );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 20, $result['qr_scale'] );
	}

	/**
	 * Test bounded int with negative value clamps to min (absint makes it positive first).
	 *
	 * @return void
	 */
	public function test_bounded_int_negative_value(): void {
		$input  = array( 'qr_bg_opacity' => -50 );
		$result = $this->sanitizer->sanitize( $input );

		// absint(-50) = 50, which is in [0, 100].
		$this->assertSame( 50, $result['qr_bg_opacity'] );
	}

	// =========================================================================
	// sanitize_enum (via sanitize())
	// =========================================================================

	/**
	 * Test enum with valid value passes through.
	 *
	 * @return void
	 */
	public function test_enum_valid_value_passes(): void {
		$input  = array( 'roundup_to' => 'five' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 'five', $result['roundup_to'] );
	}

	/**
	 * Test enum with invalid value returns default.
	 *
	 * @return void
	 */
	public function test_enum_invalid_value_returns_default(): void {
		$input  = array( 'roundup_to' => 'hundred' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 'dollar', $result['roundup_to'] );
	}

	/**
	 * Test enum with null value returns default.
	 *
	 * @return void
	 */
	public function test_enum_null_returns_default(): void {
		$input  = array();
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 'dollar', $result['roundup_to'] );
	}

	/**
	 * Test archive_layout enum accepts all valid values.
	 *
	 * @return void
	 */
	public function test_enum_archive_layout_valid_values(): void {
		foreach ( array( 'grid', 'list', 'cards' ) as $layout ) {
			$input  = array( 'archive_layout' => $layout );
			$result = $this->sanitizer->sanitize( $input );

			$this->assertSame( $layout, $result['archive_layout'] );
		}
	}

	/**
	 * Test qr_default_logo_mode enum accepts all valid values.
	 *
	 * @return void
	 */
	public function test_enum_qr_logo_mode_valid_values(): void {
		foreach ( array( 'none', 'site', 'custom' ) as $mode ) {
			$input  = array( 'qr_default_logo_mode' => $mode );
			$result = $this->sanitizer->sanitize( $input );

			$this->assertSame( $mode, $result['qr_default_logo_mode'] );
		}
	}

	// =========================================================================
	// sanitize_hex_color()
	// =========================================================================

	/**
	 * Test valid hex color passes through.
	 *
	 * @return void
	 */
	public function test_hex_color_valid_passes(): void {
		$result = $this->sanitizer->sanitize_hex_color( '#ff0000' );
		$this->assertSame( '#ff0000', $result );
	}

	/**
	 * Test valid 3-digit hex color passes through.
	 *
	 * @return void
	 */
	public function test_hex_color_valid_shorthand_passes(): void {
		$result = $this->sanitizer->sanitize_hex_color( '#abc' );
		$this->assertSame( '#abc', $result );
	}

	/**
	 * Test invalid hex color returns default.
	 *
	 * @return void
	 */
	public function test_hex_color_invalid_returns_default(): void {
		$result = $this->sanitizer->sanitize_hex_color( 'not-a-color' );
		$this->assertSame( '#000000', $result );
	}

	/**
	 * Test empty string hex color returns default.
	 *
	 * @return void
	 */
	public function test_hex_color_empty_returns_default(): void {
		$result = $this->sanitizer->sanitize_hex_color( '' );
		$this->assertSame( '#000000', $result );
	}

	/**
	 * Test null hex color returns default.
	 *
	 * @return void
	 */
	public function test_hex_color_null_returns_default(): void {
		$result = $this->sanitizer->sanitize_hex_color( null );
		$this->assertSame( '#000000', $result );
	}

	/**
	 * Test hex color with custom default.
	 *
	 * @return void
	 */
	public function test_hex_color_custom_default(): void {
		$result = $this->sanitizer->sanitize_hex_color( 'invalid', '#ffffff' );
		$this->assertSame( '#ffffff', $result );
	}

	/**
	 * Test hex color without hash is invalid.
	 *
	 * @return void
	 */
	public function test_hex_color_without_hash_is_invalid(): void {
		$result = $this->sanitizer->sanitize_hex_color( 'ff0000' );
		$this->assertSame( '#000000', $result );
	}

	// =========================================================================
	// parse_donation_presets()
	// =========================================================================

	/**
	 * Test valid comma-separated presets parse correctly.
	 *
	 * @return void
	 */
	public function test_donation_presets_valid_csv(): void {
		$result = $this->sanitizer->parse_donation_presets( '5,10,25' );
		$this->assertSame( array( 5.0, 10.0, 25.0 ), $result );
	}

	/**
	 * Test empty string returns defaults.
	 *
	 * @return void
	 */
	public function test_donation_presets_empty_returns_defaults(): void {
		$result = $this->sanitizer->parse_donation_presets( '' );
		$this->assertSame( array( 5.0, 10.0, 25.0 ), $result );
	}

	/**
	 * Test null returns defaults.
	 *
	 * @return void
	 */
	public function test_donation_presets_null_returns_defaults(): void {
		$result = $this->sanitizer->parse_donation_presets( null );
		$this->assertSame( array( 5.0, 10.0, 25.0 ), $result );
	}

	/**
	 * Test zero and negative values are filtered out.
	 *
	 * @return void
	 */
	public function test_donation_presets_filters_zero_and_negative(): void {
		$result = $this->sanitizer->parse_donation_presets( '0,-5,10' );
		$this->assertSame( array( 10.0 ), $result );
	}

	/**
	 * Test all-invalid values return defaults.
	 *
	 * @return void
	 */
	public function test_donation_presets_all_invalid_returns_defaults(): void {
		$result = $this->sanitizer->parse_donation_presets( '0,0,-1' );
		$this->assertSame( array( 5.0, 10.0, 25.0 ), $result );
	}

	/**
	 * Test non-numeric values are treated as zero and filtered out.
	 *
	 * @return void
	 */
	public function test_donation_presets_non_numeric_filtered(): void {
		$result = $this->sanitizer->parse_donation_presets( 'abc,def' );
		$this->assertSame( array( 5.0, 10.0, 25.0 ), $result );
	}

	/**
	 * Test decimal values are preserved.
	 *
	 * @return void
	 */
	public function test_donation_presets_decimal_values(): void {
		$result = $this->sanitizer->parse_donation_presets( '5.50,10.25,25.99' );
		$this->assertSame( array( 5.50, 10.25, 25.99 ), $result );
	}

	/**
	 * Test whitespace around values is trimmed.
	 *
	 * @return void
	 */
	public function test_donation_presets_whitespace_trimmed(): void {
		$result = $this->sanitizer->parse_donation_presets( ' 5 , 10 , 25 ' );
		$this->assertSame( array( 5.0, 10.0, 25.0 ), $result );
	}

	/**
	 * Test single valid preset.
	 *
	 * @return void
	 */
	public function test_donation_presets_single_value(): void {
		$result = $this->sanitizer->parse_donation_presets( '50' );
		$this->assertSame( array( 50.0 ), $result );
	}

	// =========================================================================
	// parse_checkin_counters()
	// =========================================================================

	/**
	 * Test valid comma-separated counters parse correctly.
	 *
	 * @return void
	 */
	public function test_checkin_counters_valid_csv(): void {
		$result = $this->sanitizer->parse_checkin_counters( 'Foo, Bar , Baz' );
		$this->assertSame( array( 'Foo', 'Bar', 'Baz' ), $result );
	}

	/**
	 * Test empty string returns empty array.
	 *
	 * @return void
	 */
	public function test_checkin_counters_empty_returns_empty_array(): void {
		$result = $this->sanitizer->parse_checkin_counters( '' );
		$this->assertSame( array(), $result );
	}

	/**
	 * Test null returns empty array.
	 *
	 * @return void
	 */
	public function test_checkin_counters_null_returns_empty_array(): void {
		$result = $this->sanitizer->parse_checkin_counters( null );
		$this->assertSame( array(), $result );
	}

	/**
	 * Test single counter label.
	 *
	 * @return void
	 */
	public function test_checkin_counters_single_value(): void {
		$result = $this->sanitizer->parse_checkin_counters( 'Main Entrance' );
		$this->assertSame( array( 'Main Entrance' ), $result );
	}

	/**
	 * Test whitespace-only entries are filtered out.
	 *
	 * @return void
	 */
	public function test_checkin_counters_whitespace_only_filtered(): void {
		$result = $this->sanitizer->parse_checkin_counters( 'Foo, , ,Bar' );
		$this->assertSame( array( 'Foo', 'Bar' ), $result );
	}

	// =========================================================================
	// sanitize_aspect_ratio_custom (via sanitize())
	// =========================================================================

	/**
	 * Test valid custom aspect ratio passes through.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_custom_valid(): void {
		$input  = array( 'image_aspect_ratio_custom' => '16:9' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '16:9', $result['image_aspect_ratio_custom'] );
	}

	/**
	 * Test the single (event-page) aspect-ratio fields are registered and sanitized.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_single_registered(): void {
		$input  = array(
			'image_aspect_ratio_single'        => '3:2',
			'image_aspect_ratio_single_custom' => '21:9',
		);
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '3:2', $result['image_aspect_ratio_single'] );
		$this->assertSame( '21:9', $result['image_aspect_ratio_single_custom'] );
	}

	/**
	 * Test an invalid single custom aspect ratio is rejected to empty string.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_single_custom_invalid(): void {
		$input  = array( 'image_aspect_ratio_single_custom' => 'abc' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '', $result['image_aspect_ratio_single_custom'] );
	}

	/**
	 * Test invalid custom aspect ratio returns empty string.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_custom_invalid_text(): void {
		$input  = array( 'image_aspect_ratio_custom' => 'abc' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '', $result['image_aspect_ratio_custom'] );
	}

	/**
	 * Test zero dimensions returns empty string.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_custom_zero_dimensions(): void {
		$input  = array( 'image_aspect_ratio_custom' => '0:0' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '', $result['image_aspect_ratio_custom'] );
	}

	/**
	 * Test zero width returns empty string.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_custom_zero_width(): void {
		$input  = array( 'image_aspect_ratio_custom' => '0:9' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '', $result['image_aspect_ratio_custom'] );
	}

	/**
	 * Test zero height returns empty string.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_custom_zero_height(): void {
		$input  = array( 'image_aspect_ratio_custom' => '16:0' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '', $result['image_aspect_ratio_custom'] );
	}

	/**
	 * Test empty custom aspect ratio returns empty string.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_custom_empty(): void {
		$input  = array( 'image_aspect_ratio_custom' => '' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '', $result['image_aspect_ratio_custom'] );
	}

	/**
	 * Test null custom aspect ratio returns empty string.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_custom_null(): void {
		$input  = array();
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '', $result['image_aspect_ratio_custom'] );
	}

	/**
	 * Test custom aspect ratio with decimal values is invalid.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_custom_decimal_invalid(): void {
		$input  = array( 'image_aspect_ratio_custom' => '16.5:9' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '', $result['image_aspect_ratio_custom'] );
	}

	/**
	 * Test custom aspect ratio with negative values is invalid.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_custom_negative_invalid(): void {
		$input  = array( 'image_aspect_ratio_custom' => '-16:9' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '', $result['image_aspect_ratio_custom'] );
	}

	// =========================================================================
	// sanitize_aspect_ratio_preset (via sanitize())
	// =========================================================================

	/**
	 * Test all valid aspect ratio preset values.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_preset_valid_values(): void {
		$valid_presets = array( '', '16:9', '3:2', '4:3', '1:1', 'original', 'custom' );

		foreach ( $valid_presets as $preset ) {
			$input  = array( 'image_aspect_ratio' => $preset );
			$result = $this->sanitizer->sanitize( $input );

			$this->assertSame( $preset, $result['image_aspect_ratio'], "Preset '{$preset}' should pass through" );
		}
	}

	/**
	 * Test invalid aspect ratio preset returns default.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_preset_invalid_returns_default(): void {
		$input  = array( 'image_aspect_ratio' => '21:9' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '16:9', $result['image_aspect_ratio'] );
	}

	/**
	 * Test aspect ratio preset with empty default field (image_aspect_ratio_cards).
	 *
	 * @return void
	 */
	public function test_aspect_ratio_preset_empty_default_field(): void {
		$input  = array( 'image_aspect_ratio_cards' => 'invalid' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '', $result['image_aspect_ratio_cards'] );
	}

	// =========================================================================
	// get_aspect_ratio_css()
	// =========================================================================

	/**
	 * Test empty preset returns empty string.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_css_empty_preset(): void {
		$result = $this->sanitizer->get_aspect_ratio_css( '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test 'original' preset returns 'auto'.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_css_original(): void {
		$result = $this->sanitizer->get_aspect_ratio_css( 'original' );
		$this->assertSame( 'auto', $result );
	}

	/**
	 * Test standard ratio preset returns CSS format.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_css_standard_ratio(): void {
		$result = $this->sanitizer->get_aspect_ratio_css( '16:9' );
		$this->assertSame( '16 / 9', $result );
	}

	/**
	 * Test 'custom' preset with valid custom value.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_css_custom_with_value(): void {
		$result = $this->sanitizer->get_aspect_ratio_css( 'custom', '5:4' );
		$this->assertSame( '5 / 4', $result );
	}

	/**
	 * Test 'custom' preset with empty custom value returns empty string.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_css_custom_empty_value(): void {
		$result = $this->sanitizer->get_aspect_ratio_css( 'custom', '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test 'custom' preset with invalid custom value returns empty string.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_css_custom_invalid_value(): void {
		$result = $this->sanitizer->get_aspect_ratio_css( 'custom', 'bad' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test all standard ratio presets convert to CSS correctly.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_css_all_standard_ratios(): void {
		$expected = array(
			'16:9' => '16 / 9',
			'3:2'  => '3 / 2',
			'4:3'  => '4 / 3',
			'1:1'  => '1 / 1',
		);

		foreach ( $expected as $preset => $css ) {
			$result = $this->sanitizer->get_aspect_ratio_css( $preset );
			$this->assertSame( $css, $result, "Preset '{$preset}' should produce '{$css}'" );
		}
	}

	// =========================================================================
	// sanitize() — Text Fields
	// =========================================================================

	/**
	 * Test text field sanitization.
	 *
	 * @return void
	 */
	public function test_text_field_sanitized(): void {
		$input  = array( 'default_view' => 'week' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 'week', $result['default_view'] );
	}

	/**
	 * Test text field null uses default.
	 *
	 * @return void
	 */
	public function test_text_field_null_uses_default(): void {
		$input  = array();
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 'month', $result['default_view'] );
	}

	// =========================================================================
	// sanitize() — Textarea Fields
	// =========================================================================

	/**
	 * Test textarea field sanitization.
	 *
	 * @return void
	 */
	public function test_textarea_field_sanitized(): void {
		$input  = array( 'default_venue_address' => "123 Main St\nSuite 100" );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertIsString( $result['default_venue_address'] );
	}

	/**
	 * Test textarea field null uses default.
	 *
	 * @return void
	 */
	public function test_textarea_field_null_uses_default(): void {
		$input  = array();
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '', $result['default_venue_address'] );
	}

	// =========================================================================
	// sanitize() — Int Fields
	// =========================================================================

	/**
	 * Test int field sanitization.
	 *
	 * @return void
	 */
	public function test_int_field_sanitized(): void {
		$input  = array( 'events_per_page' => 20 );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 20, $result['events_per_page'] );
	}

	/**
	 * Test int field null uses default.
	 *
	 * @return void
	 */
	public function test_int_field_null_uses_default(): void {
		$input  = array();
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 10, $result['events_per_page'] );
	}

	/**
	 * Test int field with string number.
	 *
	 * @return void
	 */
	public function test_int_field_string_number(): void {
		$input  = array( 'events_per_page' => '15' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 15, $result['events_per_page'] );
	}

	// =========================================================================
	// sanitize() — Bool Fields
	// =========================================================================

	/**
	 * Test bool field with truthy value.
	 *
	 * @return void
	 */
	public function test_bool_field_truthy(): void {
		$input  = array( 'enable_rsvp' => '1' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertTrue( $result['enable_rsvp'] );
	}

	/**
	 * Test bool field with falsy value.
	 *
	 * @return void
	 */
	public function test_bool_field_falsy(): void {
		$input  = array( 'enable_rsvp' => '' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertFalse( $result['enable_rsvp'] );
	}

	/**
	 * Test bool field absent defaults to false.
	 *
	 * @return void
	 */
	public function test_bool_field_absent_defaults_false(): void {
		$input  = array();
		$result = $this->sanitizer->sanitize( $input );

		$this->assertFalse( $result['enable_rsvp'] );
	}

	/**
	 * Test bool field with null is false.
	 *
	 * @return void
	 */
	public function test_bool_field_null_is_false(): void {
		$input  = array( 'enable_tickets' => null );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertFalse( $result['enable_tickets'] );
	}

	// =========================================================================
	// sanitize() — Email Fields
	// =========================================================================

	/**
	 * Test email field sanitization.
	 *
	 * @return void
	 */
	public function test_email_field_sanitized(): void {
		$input  = array( 'checkin_completion_email' => 'test@example.com' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 'test@example.com', $result['checkin_completion_email'] );
	}

	/**
	 * Test email field null uses default.
	 *
	 * @return void
	 */
	public function test_email_field_null_uses_default(): void {
		$input  = array();
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( '', $result['checkin_completion_email'] );
	}

	// =========================================================================
	// sanitize() — Timezone / get_default()
	// =========================================================================

	/**
	 * Test timezone field uses wp_timezone_string() as default.
	 *
	 * @return void
	 */
	public function test_timezone_default_uses_wp_timezone_string(): void {
		$input  = array();
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 'America/Chicago', $result['timezone'] );
	}

	/**
	 * Test timezone field with explicit value uses provided value.
	 *
	 * @return void
	 */
	public function test_timezone_explicit_value(): void {
		$input  = array( 'timezone' => 'America/New_York' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 'America/New_York', $result['timezone'] );
	}

	// =========================================================================
	// sanitize() — Full Integration
	// =========================================================================

	/**
	 * Test sanitize with a full set of known fields.
	 *
	 * @return void
	 */
	public function test_sanitize_full_integration(): void {
		$input = array(
			'default_view'              => 'week',
			'events_per_page'           => 20,
			'timezone'                  => 'America/New_York',
			'default_venue_enabled'     => '1',
			'default_venue_name'        => 'Test Venue',
			'default_venue_address'     => '123 Main St',
			'description_heading'       => 'Details',
			'enable_rsvp'               => '1',
			'enable_tickets'            => '',
			'show_end_time_by_default'  => '1',
			'require_end_time'          => '',
			'default_min_per_order'     => 2,
			'default_max_per_order'     => 8,
			'low_stock_threshold'       => 5,
			'enable_donations'          => '1',
			'donation_cause'            => 'Help us grow',
			'roundup_to'               => 'ten',
			'allow_custom_donation'     => '1',
			'max_donation'              => 500,
			'qr_scale'                  => 10,
			'qr_bg_opacity'             => 80,
			'qr_default_logo_mode'      => 'site',
			'qr_default_logo_id'        => 42,
			'checkin_completion_email'  => 'admin@test.com',
			'occurrence_horizon'        => 180,
			'rate_limit_requests'       => 100,
			'rate_limit_window'         => 120,
			'pending_hold_time'         => 600,
			'category_cache_ttl'        => 7200,
			'activity_log_retention_days' => 30,
			'delete_data_on_uninstall'  => '',
			'show_frontend_branding'    => '1',
			'image_aspect_ratio'        => '4:3',
			'image_aspect_ratio_custom' => '5:4',
			'archive_layout'            => 'list',
			'archive_columns'           => 4,
			'archive_limit'             => 24,
		);

		$result = $this->sanitizer->sanitize( $input );

		// Text fields.
		$this->assertSame( 'week', $result['default_view'] );
		$this->assertSame( 'America/New_York', $result['timezone'] );
		$this->assertSame( 'Test Venue', $result['default_venue_name'] );
		$this->assertSame( 'Details', $result['description_heading'] );
		$this->assertSame( 'Help us grow', $result['donation_cause'] );

		// Int fields.
		$this->assertSame( 20, $result['events_per_page'] );
		$this->assertSame( 42, $result['qr_default_logo_id'] );

		// Bool fields.
		$this->assertTrue( $result['default_venue_enabled'] );
		$this->assertTrue( $result['enable_rsvp'] );
		$this->assertFalse( $result['enable_tickets'] );
		$this->assertTrue( $result['show_end_time_by_default'] );
		$this->assertFalse( $result['require_end_time'] );
		$this->assertTrue( $result['enable_donations'] );
		$this->assertTrue( $result['allow_custom_donation'] );
		$this->assertFalse( $result['delete_data_on_uninstall'] );
		$this->assertTrue( $result['show_frontend_branding'] );

		// Bounded int fields.
		$this->assertSame( 2, $result['default_min_per_order'] );
		$this->assertSame( 8, $result['default_max_per_order'] );
		$this->assertSame( 5, $result['low_stock_threshold'] );
		$this->assertSame( 500, $result['max_donation'] );
		$this->assertSame( 10, $result['qr_scale'] );
		$this->assertSame( 80, $result['qr_bg_opacity'] );
		$this->assertSame( 180, $result['occurrence_horizon'] );
		$this->assertSame( 100, $result['rate_limit_requests'] );
		$this->assertSame( 120, $result['rate_limit_window'] );
		$this->assertSame( 600, $result['pending_hold_time'] );
		$this->assertSame( 7200, $result['category_cache_ttl'] );
		$this->assertSame( 30, $result['activity_log_retention_days'] );
		$this->assertSame( 4, $result['archive_columns'] );
		$this->assertSame( 24, $result['archive_limit'] );

		// Enum fields.
		$this->assertSame( 'ten', $result['roundup_to'] );
		$this->assertSame( 'site', $result['qr_default_logo_mode'] );
		$this->assertSame( 'list', $result['archive_layout'] );

		// Email field.
		$this->assertSame( 'admin@test.com', $result['checkin_completion_email'] );

		// Aspect ratio fields.
		$this->assertSame( '4:3', $result['image_aspect_ratio'] );
		$this->assertSame( '5:4', $result['image_aspect_ratio_custom'] );
	}

	/**
	 * Test sanitize with empty input applies all defaults.
	 *
	 * @return void
	 */
	public function test_sanitize_empty_input_applies_defaults(): void {
		$result = $this->sanitizer->sanitize( array() );

		// Text defaults.
		$this->assertSame( 'month', $result['default_view'] );
		$this->assertSame( 'America/Chicago', $result['timezone'] );
		$this->assertSame( '', $result['default_venue_name'] );
		$this->assertSame( '', $result['default_venue_address'] );
		$this->assertSame( 'About This Event', $result['description_heading'] );
		$this->assertSame( 'Support our venue', $result['donation_cause'] );

		// Int defaults.
		$this->assertSame( 10, $result['events_per_page'] );
		$this->assertSame( 0, $result['qr_default_logo_id'] );

		// Bool defaults (all false when not provided).
		$this->assertFalse( $result['default_venue_enabled'] );
		$this->assertFalse( $result['enable_rsvp'] );
		$this->assertFalse( $result['enable_tickets'] );
		$this->assertFalse( $result['show_end_time_by_default'] );
		$this->assertFalse( $result['require_end_time'] );
		$this->assertFalse( $result['enable_donations'] );
		$this->assertFalse( $result['allow_custom_donation'] );
		$this->assertFalse( $result['delete_data_on_uninstall'] );
		$this->assertFalse( $result['show_frontend_branding'] );

		// Bounded int defaults.
		$this->assertSame( 1, $result['default_min_per_order'] );
		$this->assertSame( 10, $result['default_max_per_order'] );
		$this->assertSame( 10, $result['low_stock_threshold'] );
		$this->assertSame( 100, $result['max_donation'] );
		$this->assertSame( 5, $result['qr_scale'] );
		$this->assertSame( 100, $result['qr_bg_opacity'] );
		$this->assertSame( 365, $result['occurrence_horizon'] );
		$this->assertSame( 60, $result['rate_limit_requests'] );
		$this->assertSame( 60, $result['rate_limit_window'] );
		$this->assertSame( 900, $result['pending_hold_time'] );
		$this->assertSame( 3600, $result['category_cache_ttl'] );
		$this->assertSame( 90, $result['activity_log_retention_days'] );
		$this->assertSame( 3, $result['archive_columns'] );
		$this->assertSame( 12, $result['archive_limit'] );

		// Enum defaults.
		$this->assertSame( 'dollar', $result['roundup_to'] );
		$this->assertSame( 'none', $result['qr_default_logo_mode'] );
		$this->assertSame( 'cards', $result['archive_layout'] );

		// Email default.
		$this->assertSame( '', $result['checkin_completion_email'] );

		// Aspect ratio defaults.
		$this->assertSame( '16:9', $result['image_aspect_ratio'] );
		$this->assertSame( '', $result['image_aspect_ratio_custom'] );
	}

	/**
	 * Test sanitize returns all expected field keys.
	 *
	 * @return void
	 */
	public function test_sanitize_returns_all_field_keys(): void {
		$result = $this->sanitizer->sanitize( array() );

		$expected_keys = array(
			'default_view',
			'events_per_page',
			'timezone',
			'default_venue_enabled',
			'default_venue_name',
			'default_venue_address',
			'description_heading',
			'events_archive_intro',
			'enable_rsvp',
			'enable_tickets',
			'show_end_time_by_default',
			'require_end_time',
			'default_min_per_order',
			'default_max_per_order',
			'low_stock_threshold',
			'enable_donations',
			'donation_cause',
			'roundup_to',
			'allow_custom_donation',
			'max_donation',
			'qr_scale',
			'qr_bg_opacity',
			'qr_default_logo_mode',
			'qr_default_logo_id',
			'checkin_completion_email',
			'occurrence_horizon',
			'rate_limit_requests',
			'rate_limit_window',
			'pending_hold_time',
			'category_cache_ttl',
			'activity_log_retention_days',
			'delete_data_on_uninstall',
			'show_frontend_branding',
			'image_aspect_ratio',
			'image_aspect_ratio_custom',
			'image_aspect_ratio_cards',
			'image_aspect_ratio_cards_custom',
			'image_aspect_ratio_list',
			'image_aspect_ratio_list_custom',
			'image_aspect_ratio_carousel',
			'image_aspect_ratio_carousel_custom',
			'archive_layout',
			'archive_columns',
			'archive_limit',
			'archive_show_filters',
			'archive_show_search',
			'archive_show_category',
			'archive_show_tag',
			'archive_show_date_range',
		);

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $result, "Expected key '{$key}' missing from sanitize output" );
		}
	}

	/**
	 * Regression: events_archive_intro is whitelisted and persists through sanitize.
	 *
	 * Pre-1.0.4 the field was added to DisplaySettings DTO, InlineSettingsRenderer UI,
	 * NetterTechEventsSettings hydration, and the archive-events.php template, but
	 * missed from FIELD_CONFIGS. The sanitizer iterates only over allow-listed keys,
	 * so the field's POST value was silently dropped on save.
	 *
	 * @return void
	 */
	public function test_events_archive_intro_passes_through_sanitize(): void {
		$input  = array( 'events_archive_intro' => "Welcome to our events.\nJoin us!" );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertArrayHasKey( 'events_archive_intro', $result );
		$this->assertSame( "Welcome to our events.\nJoin us!", $result['events_archive_intro'] );
	}

	/**
	 * Regression: events_archive_intro defaults to empty string when input absent.
	 *
	 * @return void
	 */
	public function test_events_archive_intro_defaults_to_empty_string(): void {
		$result = $this->sanitizer->sanitize( array() );

		$this->assertArrayHasKey( 'events_archive_intro', $result );
		$this->assertSame( '', $result['events_archive_intro'] );
	}

	/**
	 * Test sanitize ignores unknown input keys.
	 *
	 * @return void
	 */
	public function test_sanitize_ignores_unknown_keys(): void {
		$input  = array( 'unknown_field' => 'some_value' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertArrayNotHasKey( 'unknown_field', $result );
	}

	// =========================================================================
	// Edge Cases
	// =========================================================================

	/**
	 * Test bounded int with string input.
	 *
	 * @return void
	 */
	public function test_bounded_int_string_input(): void {
		$input  = array( 'qr_scale' => '10' );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 10, $result['qr_scale'] );
	}

	/**
	 * Test bounded int with non-numeric string uses zero then clamps to min.
	 *
	 * @return void
	 */
	public function test_bounded_int_non_numeric_string(): void {
		$input  = array( 'qr_scale' => 'abc' );
		$result = $this->sanitizer->sanitize( $input );

		// absint('abc') = 0, clamped to min of 3.
		$this->assertSame( 3, $result['qr_scale'] );
	}

	/**
	 * Test bool field with various truthy values.
	 *
	 * @return void
	 */
	public function test_bool_field_various_truthy(): void {
		$truthy_values = array( '1', 'yes', 'true', 'on', 1 );

		foreach ( $truthy_values as $truthy ) {
			$input  = array( 'enable_rsvp' => $truthy );
			$result = $this->sanitizer->sanitize( $input );

			$this->assertTrue( $result['enable_rsvp'], "Value " . var_export( $truthy, true ) . " should be truthy" );
		}
	}

	/**
	 * Test bool field with various falsy values.
	 *
	 * @return void
	 */
	public function test_bool_field_various_falsy(): void {
		$falsy_values = array( '', '0', 0, null, false );

		foreach ( $falsy_values as $falsy ) {
			$input  = array( 'enable_rsvp' => $falsy );
			$result = $this->sanitizer->sanitize( $input );

			$this->assertFalse( $result['enable_rsvp'], "Value " . var_export( $falsy, true ) . " should be falsy" );
		}
	}

	/**
	 * Test aspect ratio CSS with 'custom' but no custom value falls through to ratio_to_css.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_css_custom_without_value(): void {
		$result = $this->sanitizer->get_aspect_ratio_css( 'custom' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test aspect ratio CSS with non-ratio string returns empty.
	 *
	 * @return void
	 */
	public function test_aspect_ratio_css_invalid_preset_string(): void {
		$result = $this->sanitizer->get_aspect_ratio_css( 'invalid' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test qr_bg_opacity bounded int accepts zero as minimum.
	 *
	 * @return void
	 */
	public function test_qr_bg_opacity_zero_is_valid(): void {
		$input  = array( 'qr_bg_opacity' => 0 );
		$result = $this->sanitizer->sanitize( $input );

		$this->assertSame( 0, $result['qr_bg_opacity'] );
	}

	// =========================================================================
	// Archive filter visibility bool fields (NTE-069)
	// =========================================================================

	/**
	 * Test archive filter bool fields are present in sanitize() output.
	 *
	 * @return void
	 */
	public function test_archive_filter_bool_keys_are_in_output(): void {
		$result = $this->sanitizer->sanitize( array() );

		$this->assertArrayHasKey( 'archive_show_filters', $result );
		$this->assertArrayHasKey( 'archive_show_search', $result );
		$this->assertArrayHasKey( 'archive_show_category', $result );
		$this->assertArrayHasKey( 'archive_show_tag', $result );
		$this->assertArrayHasKey( 'archive_show_date_range', $result );
	}

	/**
	 * Test archive filter bool fields are false when absent from input.
	 *
	 * Absent checkbox = unchecked submission. The sanitizer must yield false
	 * (not a default true) so SettingsSaveHandler can then decide whether to
	 * persist false (via the bool fields mechanism in get_bool_fields()).
	 *
	 * @return void
	 */
	public function test_archive_filter_bool_fields_absent_yield_false(): void {
		$result = $this->sanitizer->sanitize( array() );

		$this->assertFalse( $result['archive_show_filters'] );
		$this->assertFalse( $result['archive_show_search'] );
		$this->assertFalse( $result['archive_show_category'] );
		$this->assertFalse( $result['archive_show_tag'] );
		$this->assertFalse( $result['archive_show_date_range'] );
	}

	/**
	 * Test archive filter bool fields are true when submitted with value "1".
	 *
	 * @return void
	 */
	public function test_archive_filter_bool_fields_with_value_1_yield_true(): void {
		$input = array(
			'archive_show_filters'    => '1',
			'archive_show_search'     => '1',
			'archive_show_category'   => '1',
			'archive_show_tag'        => '1',
			'archive_show_date_range' => '1',
		);

		$result = $this->sanitizer->sanitize( $input );

		$this->assertTrue( $result['archive_show_filters'] );
		$this->assertTrue( $result['archive_show_search'] );
		$this->assertTrue( $result['archive_show_category'] );
		$this->assertTrue( $result['archive_show_tag'] );
		$this->assertTrue( $result['archive_show_date_range'] );
	}
}
