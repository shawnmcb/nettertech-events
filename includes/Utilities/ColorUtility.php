<?php
/**
 * Color utility class for color science operations.
 *
 * Pure functions for hex/RGB conversion, luminance calculation,
 * contrast ratio measurement, and HSL saturation — extracted from Assets
 * for reuse across PaletteResolver and other consumers.
 *
 * @package NetterTechEvents\Utilities
 * @since   1.6.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Static helper for color math operations.
 */
class ColorUtility {

	/**
	 * Parse a hex color string into RGB components.
	 *
	 * Accepts 3-char (#RGB) or 6-char (#RRGGBB) hex, with or without #.
	 *
	 * @since 1.4.0
	 *
	 * @param string $hex Hex color (with or without #, 3 or 6 chars).
	 * @return array{0: int, 1: int, 2: int}|null RGB array or null on invalid input.
	 */
	public static function hex_to_rgb( string $hex ): ?array {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return null;
		}

		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Calculate the relative luminance of an RGB color.
	 *
	 * Per WCAG 2.2 definition using sRGB linearization.
	 *
	 * @since 1.4.0
	 *
	 * @param int $r Red (0-255).
	 * @param int $g Green (0-255).
	 * @param int $b Blue (0-255).
	 * @return float Relative luminance (0.0-1.0).
	 */
	public static function relative_luminance( int $r, int $g, int $b ): float {
		$r_lin = self::linearize_channel( $r / 255 );
		$g_lin = self::linearize_channel( $g / 255 );
		$b_lin = self::linearize_channel( $b / 255 );

		return 0.2126 * $r_lin + 0.7152 * $g_lin + 0.0722 * $b_lin;
	}

	/**
	 * Linearize an sRGB channel value.
	 *
	 * @since 1.4.0
	 *
	 * @param float $channel sRGB channel (0.0-1.0).
	 * @return float Linear channel value.
	 */
	public static function linearize_channel( float $channel ): float {
		return ( $channel <= 0.04045 )
			? $channel / 12.92
			: pow( ( $channel + 0.055 ) / 1.055, 2.4 );
	}

	/**
	 * Calculate the WCAG contrast ratio between two hex colors.
	 *
	 * @since 1.5.0
	 *
	 * @param string $hex1 First hex color.
	 * @param string $hex2 Second hex color.
	 * @return float Contrast ratio (1.0-21.0), or 0.0 on invalid input.
	 */
	public static function contrast_ratio( string $hex1, string $hex2 ): float {
		$rgb1 = self::hex_to_rgb( $hex1 );
		$rgb2 = self::hex_to_rgb( $hex2 );

		if ( null === $rgb1 || null === $rgb2 ) {
			return 0.0;
		}

		$l1 = self::relative_luminance( $rgb1[0], $rgb1[1], $rgb1[2] );
		$l2 = self::relative_luminance( $rgb2[0], $rgb2[1], $rgb2[2] );

		$lighter = max( $l1, $l2 );
		$darker  = min( $l1, $l2 );

		return ( $lighter + 0.05 ) / ( $darker + 0.05 );
	}

	/**
	 * Adjust a hex color's brightness by a percentage.
	 *
	 * @since 1.4.0
	 *
	 * @param string $hex     Hex color (with or without #).
	 * @param int    $percent Brightness adjustment (-100 to 100). Negative darkens.
	 * @return string Adjusted hex color with # prefix.
	 */
	public static function adjust_brightness( string $hex, int $percent ): string {
		$rgb = self::hex_to_rgb( $hex );
		if ( null === $rgb ) {
			return $hex;
		}

		$factor = 1 + ( $percent / 100 );
		$r      = max( 0, min( 255, (int) round( $rgb[0] * $factor ) ) );
		$g      = max( 0, min( 255, (int) round( $rgb[1] * $factor ) ) );
		$b      = max( 0, min( 255, (int) round( $rgb[2] * $factor ) ) );

		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}

	/**
	 * Calculate the HSL saturation of an RGB color.
	 *
	 * @since 1.4.0
	 *
	 * @param int $r Red (0-255).
	 * @param int $g Green (0-255).
	 * @param int $b Blue (0-255).
	 * @return float Saturation (0.0-1.0).
	 */
	public static function saturation( int $r, int $g, int $b ): float {
		$r_norm = $r / 255;
		$g_norm = $g / 255;
		$b_norm = $b / 255;
		$max    = max( $r_norm, $g_norm, $b_norm );
		$min    = min( $r_norm, $g_norm, $b_norm );
		$delta  = (float) ( $max - $min );

		if ( 0.0 === $delta ) {
			return 0.0;
		}

		$lightness = ( $max + $min ) / 2;

		return ( $lightness > 0.5 )
			? $delta / ( 2 - $max - $min )
			: $delta / ( $max + $min );
	}
}
