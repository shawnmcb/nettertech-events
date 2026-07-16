<?php
/**
 * Image utility helpers.
 *
 * @package NetterTechEvents\Utilities
 * @since   2.1.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers for image-related shortcode attribute processing.
 */
class ImageUtility {

	/**
	 * Sanitize an image_ratio shortcode attribute value.
	 *
	 * Converts a shortcode attribute (preset name or custom W:H) into a
	 * CSS aspect-ratio value. Returns an empty string for invalid or empty input.
	 *
	 * Preset map:
	 *   16:9     => 16 / 9
	 *   3:2      => 3 / 2
	 *   4:3      => 4 / 3
	 *   1:1      => 1
	 *   original => auto
	 *
	 * Custom format: any positive integer ratio "W:H" is accepted.
	 *
	 * @since 2.1.0
	 *
	 * @param string $value The raw shortcode attribute value.
	 * @return string CSS aspect-ratio value, or empty string when input is invalid.
	 */
	public static function sanitize_image_ratio( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$presets = array(
			'16:9'     => '16 / 9',
			'3:2'      => '3 / 2',
			'4:3'      => '4 / 3',
			'1:1'      => '1',
			'original' => 'auto',
		);

		if ( isset( $presets[ $value ] ) ) {
			return $presets[ $value ];
		}

		// Handle custom W:H format.
		if ( preg_match( '/^(\d+):(\d+)$/', $value, $matches ) ) {
			$width  = (int) $matches[1];
			$height = (int) $matches[2];

			if ( $width > 0 && $height > 0 ) {
				return $width . ' / ' . $height;
			}
		}

		return '';
	}
}
