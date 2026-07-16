<?php
/**
 * ColorUtility class unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Utilities
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Utilities;

use NetterTechEvents\Utilities\ColorUtility;

/**
 * Test ColorUtility pure color science methods.
 */
class ColorUtilityTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// hex_to_rgb Tests
	// =========================================================================

	/**
	 * Test hex_to_rgb parses standard 6-char hex.
	 *
	 * @return void
	 */
	public function test_hex_to_rgb_standard_hex(): void {
		$this->assertSame( array( 255, 0, 0 ), ColorUtility::hex_to_rgb( '#FF0000' ) );
		$this->assertSame( array( 0, 255, 0 ), ColorUtility::hex_to_rgb( '#00FF00' ) );
		$this->assertSame( array( 0, 0, 255 ), ColorUtility::hex_to_rgb( '#0000FF' ) );
	}

	/**
	 * Test hex_to_rgb parses 6-char hex without hash.
	 *
	 * @return void
	 */
	public function test_hex_to_rgb_without_hash(): void {
		$this->assertSame( array( 255, 255, 255 ), ColorUtility::hex_to_rgb( 'FFFFFF' ) );
		$this->assertSame( array( 0, 0, 0 ), ColorUtility::hex_to_rgb( '000000' ) );
	}

	/**
	 * Test hex_to_rgb expands 3-char shorthand.
	 *
	 * @return void
	 */
	public function test_hex_to_rgb_shorthand(): void {
		$this->assertSame( array( 255, 255, 255 ), ColorUtility::hex_to_rgb( '#FFF' ) );
		$this->assertSame( array( 0, 0, 0 ), ColorUtility::hex_to_rgb( '#000' ) );
		$this->assertSame( array( 17, 34, 51 ), ColorUtility::hex_to_rgb( '#123' ) );
	}

	/**
	 * Test hex_to_rgb returns null for invalid input.
	 *
	 * @return void
	 */
	public function test_hex_to_rgb_invalid_input(): void {
		$this->assertNull( ColorUtility::hex_to_rgb( '' ) );
		$this->assertNull( ColorUtility::hex_to_rgb( 'not-a-color' ) );
		$this->assertNull( ColorUtility::hex_to_rgb( '#12' ) );
		$this->assertNull( ColorUtility::hex_to_rgb( '#12345' ) );
		$this->assertNull( ColorUtility::hex_to_rgb( '#1234567' ) );
	}

	/**
	 * Test hex_to_rgb parses lowercase hex.
	 *
	 * @return void
	 */
	public function test_hex_to_rgb_lowercase(): void {
		$this->assertSame( array( 171, 205, 239 ), ColorUtility::hex_to_rgb( '#abcdef' ) );
	}

	// =========================================================================
	// relative_luminance Tests
	// =========================================================================

	/**
	 * Test relative_luminance for black.
	 *
	 * @return void
	 */
	public function test_relative_luminance_black(): void {
		$this->assertEqualsWithDelta( 0.0, ColorUtility::relative_luminance( 0, 0, 0 ), 0.001 );
	}

	/**
	 * Test relative_luminance for white.
	 *
	 * @return void
	 */
	public function test_relative_luminance_white(): void {
		$this->assertEqualsWithDelta( 1.0, ColorUtility::relative_luminance( 255, 255, 255 ), 0.001 );
	}

	/**
	 * Test relative_luminance for mid-gray.
	 *
	 * @return void
	 */
	public function test_relative_luminance_mid_gray(): void {
		// #808080 = RGB(128, 128, 128), luminance ~0.216.
		$luminance = ColorUtility::relative_luminance( 128, 128, 128 );
		$this->assertGreaterThan( 0.1, $luminance );
		$this->assertLessThan( 0.3, $luminance );
	}

	/**
	 * Test relative_luminance green channel dominates.
	 *
	 * @return void
	 */
	public function test_relative_luminance_green_dominates(): void {
		// Pure green should have higher luminance than pure red or blue.
		$green = ColorUtility::relative_luminance( 0, 255, 0 );
		$red   = ColorUtility::relative_luminance( 255, 0, 0 );
		$blue  = ColorUtility::relative_luminance( 0, 0, 255 );

		$this->assertGreaterThan( $red, $green );
		$this->assertGreaterThan( $blue, $green );
	}

	// =========================================================================
	// contrast_ratio Tests (migrated from AssetsTest)
	// =========================================================================

	/**
	 * Test contrast_ratio returns correct ratio for black on white.
	 *
	 * @return void
	 */
	public function test_contrast_ratio_black_on_white(): void {
		$ratio = ColorUtility::contrast_ratio( '#000000', '#FFFFFF' );
		$this->assertEqualsWithDelta( 21.0, $ratio, 0.1 );
	}

	/**
	 * Test contrast_ratio returns 1.0 for identical colors.
	 *
	 * @return void
	 */
	public function test_contrast_ratio_identical_colors(): void {
		$ratio = ColorUtility::contrast_ratio( '#808080', '#808080' );
		$this->assertEqualsWithDelta( 1.0, $ratio, 0.01 );
	}

	/**
	 * Test contrast_ratio returns 0.0 for invalid hex input.
	 *
	 * @return void
	 */
	public function test_contrast_ratio_invalid_input(): void {
		$ratio = ColorUtility::contrast_ratio( 'not-a-color', '#FFFFFF' );
		$this->assertSame( 0.0, $ratio );
	}

	/**
	 * Test contrast_ratio handles shorthand hex.
	 *
	 * @return void
	 */
	public function test_contrast_ratio_shorthand_hex(): void {
		$ratio = ColorUtility::contrast_ratio( '#000', '#FFF' );
		$this->assertEqualsWithDelta( 21.0, $ratio, 0.1 );
	}

	/**
	 * Test contrast_ratio for Bellwright failing pair (brown on teal).
	 *
	 * @return void
	 */
	public function test_contrast_ratio_bellwright_failing_pair(): void {
		// #8B4513 (saddle brown) on #3D6B5E (teal) — should fail WCAG AA.
		$ratio = ColorUtility::contrast_ratio( '#8B4513', '#3D6B5E' );
		$this->assertLessThan( 4.5, $ratio );
	}

	// =========================================================================
	// adjust_brightness Tests
	// =========================================================================

	/**
	 * Test adjust_brightness darkens a color.
	 *
	 * @return void
	 */
	public function test_adjust_brightness_darken(): void {
		$result = ColorUtility::adjust_brightness( '#FFFFFF', -50 );
		// 50% darker white should be a mid-gray.
		$rgb = ColorUtility::hex_to_rgb( $result );
		$this->assertNotNull( $rgb );
		$this->assertSame( 128, $rgb[0] );
		$this->assertSame( 128, $rgb[1] );
		$this->assertSame( 128, $rgb[2] );
	}

	/**
	 * Test adjust_brightness lightens a color.
	 *
	 * @return void
	 */
	public function test_adjust_brightness_lighten(): void {
		$result = ColorUtility::adjust_brightness( '#808080', 50 );
		$rgb    = ColorUtility::hex_to_rgb( $result );
		$this->assertNotNull( $rgb );
		// 128 * 1.5 = 192.
		$this->assertSame( 192, $rgb[0] );
	}

	/**
	 * Test adjust_brightness clamps to 255.
	 *
	 * @return void
	 */
	public function test_adjust_brightness_clamp_max(): void {
		$result = ColorUtility::adjust_brightness( '#FFFFFF', 50 );
		$this->assertSame( '#ffffff', $result );
	}

	/**
	 * Test adjust_brightness clamps to 0.
	 *
	 * @return void
	 */
	public function test_adjust_brightness_clamp_min(): void {
		$result = ColorUtility::adjust_brightness( '#000000', -50 );
		$this->assertSame( '#000000', $result );
	}

	/**
	 * Test adjust_brightness returns input on invalid hex.
	 *
	 * @return void
	 */
	public function test_adjust_brightness_invalid_hex(): void {
		$this->assertSame( 'invalid', ColorUtility::adjust_brightness( 'invalid', 10 ) );
	}

	/**
	 * Test adjust_brightness with zero percent is identity.
	 *
	 * @return void
	 */
	public function test_adjust_brightness_zero_percent(): void {
		$result = ColorUtility::adjust_brightness( '#abcdef', 0 );
		$this->assertSame( '#abcdef', $result );
	}

	// =========================================================================
	// saturation Tests
	// =========================================================================

	/**
	 * Test saturation for pure red.
	 *
	 * @return void
	 */
	public function test_saturation_pure_red(): void {
		$this->assertEqualsWithDelta( 1.0, ColorUtility::saturation( 255, 0, 0 ), 0.01 );
	}

	/**
	 * Test saturation for pure green.
	 *
	 * @return void
	 */
	public function test_saturation_pure_green(): void {
		$this->assertEqualsWithDelta( 1.0, ColorUtility::saturation( 0, 255, 0 ), 0.01 );
	}

	/**
	 * Test saturation for pure blue.
	 *
	 * @return void
	 */
	public function test_saturation_pure_blue(): void {
		$this->assertEqualsWithDelta( 1.0, ColorUtility::saturation( 0, 0, 255 ), 0.01 );
	}

	/**
	 * Test saturation for gray (desaturated).
	 *
	 * @return void
	 */
	public function test_saturation_gray(): void {
		$this->assertSame( 0.0, ColorUtility::saturation( 128, 128, 128 ) );
	}

	/**
	 * Test saturation for black.
	 *
	 * @return void
	 */
	public function test_saturation_black(): void {
		$this->assertSame( 0.0, ColorUtility::saturation( 0, 0, 0 ) );
	}

	/**
	 * Test saturation for white.
	 *
	 * @return void
	 */
	public function test_saturation_white(): void {
		$this->assertSame( 0.0, ColorUtility::saturation( 255, 255, 255 ) );
	}

	/**
	 * Test saturation for partially saturated color.
	 *
	 * @return void
	 */
	public function test_saturation_partial(): void {
		// A muted color should have saturation between 0 and 1.
		$sat = ColorUtility::saturation( 100, 50, 50 );
		$this->assertGreaterThan( 0.0, $sat );
		$this->assertLessThan( 1.0, $sat );
	}

	// =========================================================================
	// linearize_channel Tests
	// =========================================================================

	/**
	 * Test linearize_channel for 0 (black).
	 *
	 * @return void
	 */
	public function test_linearize_channel_zero(): void {
		$this->assertSame( 0.0, ColorUtility::linearize_channel( 0.0 ) );
	}

	/**
	 * Test linearize_channel for 1 (white).
	 *
	 * @return void
	 */
	public function test_linearize_channel_one(): void {
		$this->assertEqualsWithDelta( 1.0, ColorUtility::linearize_channel( 1.0 ), 0.001 );
	}

	/**
	 * Test linearize_channel below sRGB threshold.
	 *
	 * @return void
	 */
	public function test_linearize_channel_below_threshold(): void {
		// 0.04045 / 12.92 ~= 0.003130.
		$this->assertEqualsWithDelta( 0.003130, ColorUtility::linearize_channel( 0.04045 ), 0.00001 );
	}
}
