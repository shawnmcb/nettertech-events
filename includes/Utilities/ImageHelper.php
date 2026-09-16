<?php
/**
 * Image helper utility class.
 *
 * Provides validated wrapper functions for WordPress attachment image operations.
 * Addresses SEC-M001: Adds wp_attachment_is_image() validation before image operations.
 *
 * @package NetterTechEvents\Utilities
 */

declare(strict_types=1);

namespace NetterTechEvents\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes image validation and retrieval for events.
 *
 * @since 0.9.0
 * @api
 */
class ImageHelper {

	/**
	 * Get attachment image HTML with validation.
	 *
	 * Validates that the attachment ID is a valid image before calling
	 * wp_get_attachment_image(). Prevents expensive image operations
	 * on invalid attachments and exposure of private attachments.
	 *
	 * @param int                   $attachment_id Image attachment ID.
	 * @param string|int[]          $size          Optional. Image size. Accepts any registered image size name,
	 *                                             or an array of width and height values in pixels (in that order).
	 *                                             Default 'thumbnail'.
	 * @param bool                  $icon          Optional. Whether the image should be treated as an icon.
	 *                                             Default false.
	 * @param array<string, string> $attr          Optional. Attributes for the image markup. Default empty array.
	 * @phpstan-param array{src?: string, class?: string, alt?: string, srcset?: string, sizes?: string, loading?: string|false, decoding?: string, fetchpriority?: string} $attr
	 * @return string HTML img element or empty string if invalid.
	 */
	public static function get_attachment_image(
		int $attachment_id,
		$size = 'thumbnail',
		bool $icon = false,
		array $attr = array()
	): string {
		if ( ! self::is_valid_image_attachment( $attachment_id ) ) {
			return '';
		}

		return wp_get_attachment_image( $attachment_id, $size, $icon, $attr );
	}

	/**
	 * Get attachment image URL with validation.
	 *
	 * Validates that the attachment ID is a valid image before calling
	 * wp_get_attachment_image_url().
	 *
	 * @param int          $attachment_id Image attachment ID.
	 * @param string|int[] $size          Optional. Image size. Default 'thumbnail'.
	 * @return string|null Image URL or null if invalid.
	 */
	public static function get_attachment_image_url( int $attachment_id, $size = 'thumbnail' ): ?string {
		if ( ! self::is_valid_image_attachment( $attachment_id ) ) {
			return null;
		}

		$url = wp_get_attachment_image_url( $attachment_id, $size );
		return $url ? $url : null;
	}

	/**
	 * Get attachment image source array with validation.
	 *
	 * Validates that the attachment ID is a valid image before calling
	 * wp_get_attachment_image_src().
	 *
	 * @param int          $attachment_id Image attachment ID.
	 * @param string|int[] $size          Optional. Image size. Default 'thumbnail'.
	 * @return array<int|string, mixed>|false Array of image data, or false on failure.
	 */
	public static function get_attachment_image_src( int $attachment_id, $size = 'thumbnail' ) {
		if ( ! self::is_valid_image_attachment( $attachment_id ) ) {
			return false;
		}

		return wp_get_attachment_image_src( $attachment_id, $size );
	}

	/**
	 * Validate that an attachment ID is a valid image.
	 *
	 * @param int $attachment_id Attachment ID to validate.
	 * @return bool True if valid image attachment, false otherwise.
	 */
	public static function is_valid_image_attachment( int $attachment_id ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}

		return wp_attachment_is_image( $attachment_id );
	}

	/**
	 * Get the theme's site logo URL (Customizer / Site Editor `custom_logo`).
	 *
	 * Returns the full-size attachment URL so email clients, which cannot
	 * pick from a srcset, get the best copy; callers constrain display size
	 * with markup.
	 *
	 * @since 1.4.7
	 *
	 * @return string Absolute URL, or empty string when no logo is set.
	 */
	public static function get_site_logo_url(): string {
		$logo_id = (int) get_theme_mod( 'custom_logo', 0 );

		return self::get_attachment_image_url( $logo_id, 'full' ) ?? '';
	}
}
