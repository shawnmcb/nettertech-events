<?php
/**
 * QR code settings sub-group DTO.
 *
 * Contains all QR code styling and generation settings.
 *
 * @package NetterTechEvents\Core\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Typed, read-only QR code settings value object.
 *
 * @since 1.7.0
 */
final readonly class QRSettings {

	/**
	 * Constructor with property promotion.
	 *
	 * @param int    $qr_scale             QR code scale (px per module).
	 * @param int    $qr_bg_opacity        QR background opacity (0-100).
	 * @param string $qr_default_logo_mode QR logo mode (none|site|custom).
	 * @param int    $qr_default_logo_id   QR custom logo attachment ID.
	 * @param string $qr_foreground_color  QR foreground hex color.
	 * @param string $qr_background_color  QR background hex color.
	 * @param string $qr_dot_style         QR dot shape (rounded|square).
	 * @param string $qr_finder_style      QR finder corner shape.
	 */
	public function __construct(
		public int $qr_scale = 5,
		public int $qr_bg_opacity = 100,
		public string $qr_default_logo_mode = 'none',
		public int $qr_default_logo_id = 0,
		public string $qr_foreground_color = '#000000',
		public string $qr_background_color = '#ffffff',
		public string $qr_dot_style = 'rounded',
		public string $qr_finder_style = 'square',
	) {
	}
}
