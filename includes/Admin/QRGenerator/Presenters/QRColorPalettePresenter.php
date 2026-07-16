<?php
/**
 * QR Generator Color Palette Presenter (T4.2.4 Pages cluster).
 *
 * @package NetterTechEvents\Admin\QRGenerator\Presenters
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\QRGenerator\Presenters;

defined( 'ABSPATH' ) || exit;

/**
 * Pure data-prep value object for the QR color-palette section of the
 * QR Generator admin page.
 *
 * Owns the color-palette dictionary, the current default, and the
 * label translation for each preset.
 *
 * Does NOT call `esc_*()` (template's job), does NOT output, does NOT read
 * from $_GET/$_POST/get_option, does NOT call $wpdb.
 *
 * @since 1.x.x
 */
final class QRColorPalettePresenter {

	/**
	 * Wire the active default color and palette dictionary.
	 *
	 * @param string                $default_color  Hex string without leading '#' (six chars, lowercase).
	 * @param array<string, string> $palette        Hex-to-label dictionary (e.g. ['000000' => 'Black']).
	 */
	public function __construct(
		private readonly string $default_color,
		private readonly array $palette
	) {}

	/**
	 * Palette rows ready for template emission.
	 *
	 * Each row has hex (no '#'), the translatable label, and a checked flag
	 * indicating whether this preset matches the default.
	 *
	 * @return list<array{hex: string, label: string, checked: bool}>
	 */
	public function palette_rows(): array {
		$out = array();
		foreach ( $this->palette as $hex => $label ) {
			$out[] = array(
				'hex'     => $hex,
				'label'   => $label,
				'checked' => $hex === $this->default_color,
			);
		}
		return $out;
	}

	// ---- Translatable labels ----

	/**
	 * ARIA label for the color radiogroup.
	 *
	 * @return string
	 */
	public function radiogroup_aria_label(): string {
		return __( 'QR Code Color', 'nettertech-events' );
	}

	/**
	 * Default-swatch tooltip / SR text.
	 *
	 * @return string
	 */
	public function default_label(): string {
		return __( 'Default', 'nettertech-events' );
	}

	/**
	 * Custom-swatch tooltip / SR text.
	 *
	 * @return string
	 */
	public function custom_label(): string {
		return __( 'Custom', 'nettertech-events' );
	}
}
