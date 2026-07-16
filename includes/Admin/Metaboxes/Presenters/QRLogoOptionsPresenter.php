<?php
/**
 * QR Logo Options Presenter (T4.2.4 Metaboxes cluster).
 *
 * @package NetterTechEvents\Admin\Metaboxes\Presenters
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes\Presenters;

defined( 'ABSPATH' ) || exit;

/**
 * Pure data-prep value object for the QR-code logo-options radiogroup.
 *
 * The strip shows four mutually-exclusive logo options: Default, None, Site,
 * and Custom. The Site option may be disabled when no site logo is available;
 * the Custom option shows a thumbnail of the saved custom-logo attachment.
 *
 * The caller (`QRCodeMetaboxHandler::render`) resolves the logo URLs + IDs +
 * settings-derived description into primitives and passes them in; the presenter
 * surfaces per-option `selected` / `disabled` flags + translatable labels.
 *
 * Does NOT call `esc_*()` (template's job), does NOT output, does NOT read
 * from $_GET/$_POST/get_option, does NOT call $wpdb.
 *
 * @since 1.x.x
 */
final class QRLogoOptionsPresenter {

	/**
	 * Wire the resolved logo-options state.
	 *
	 * @param string $event_logo_mode     Current event-level mode: 'default', 'none', 'site', 'custom'.
	 * @param int    $event_logo_id       Custom logo attachment ID (0 if unset).
	 * @param string $event_logo_url      Custom logo thumbnail URL (empty if no custom).
	 * @param string $site_logo_url       Site logo thumbnail URL (empty if no site logo).
	 * @param string $default_description Human-readable description of the plugin-default mode (e.g., "Site Logo", "No Logo").
	 */
	public function __construct(
		private readonly string $event_logo_mode,
		private readonly int $event_logo_id,
		private readonly string $event_logo_url,
		private readonly string $site_logo_url,
		private readonly string $default_description
	) {}

	/**
	 * Current event logo mode for the hidden input value.
	 *
	 * @return string
	 */
	public function event_logo_mode(): string {
		return $this->event_logo_mode;
	}

	/**
	 * Custom logo attachment ID as string (for hidden input).
	 *
	 * @return string
	 */
	public function event_logo_id(): string {
		return (string) $this->event_logo_id;
	}

	/**
	 * Whether the given mode is the currently-selected one.
	 *
	 * @param string $mode One of 'default', 'none', 'site', 'custom'.
	 * @return bool
	 */
	public function is_selected( string $mode ): bool {
		return $mode === $this->event_logo_mode;
	}

	/**
	 * `aria-checked` attribute value for a logo option ('true' or 'false').
	 *
	 * @param string $mode One of 'default', 'none', 'site', 'custom'.
	 * @return string
	 */
	public function aria_checked( string $mode ): string {
		return $this->is_selected( $mode ) ? 'true' : 'false';
	}

	/**
	 * CSS class suffix to mark a label as selected.
	 *
	 * @param string $mode One of 'default', 'none', 'site', 'custom'.
	 * @return string Either ' nte-event-qr-logo-opt--selected' or empty string.
	 */
	public function selected_class( string $mode ): string {
		return $this->is_selected( $mode ) ? ' nte-event-qr-logo-opt--selected' : '';
	}

	/**
	 * Whether the Site Logo option must be disabled (no site logo configured).
	 *
	 * @return bool
	 */
	public function site_disabled(): bool {
		return '' === $this->site_logo_url;
	}

	/**
	 * CSS class suffix for the Site option when disabled.
	 *
	 * @return string Either ' nte-event-qr-logo-opt--disabled' or empty string.
	 */
	public function site_disabled_class(): string {
		return $this->site_disabled() ? ' nte-event-qr-logo-opt--disabled' : '';
	}

	/**
	 * Site logo URL (empty if unset).
	 *
	 * @return string
	 */
	public function site_logo_url(): string {
		return $this->site_logo_url;
	}

	/**
	 * Whether a site logo URL is available.
	 *
	 * @return bool
	 */
	public function has_site_logo(): bool {
		return '' !== $this->site_logo_url;
	}

	/**
	 * Custom logo URL (empty if unset).
	 *
	 * @return string
	 */
	public function event_logo_url(): string {
		return $this->event_logo_url;
	}

	/**
	 * Whether a custom logo URL is available.
	 *
	 * @return bool
	 */
	public function has_custom_logo(): bool {
		return '' !== $this->event_logo_url;
	}

	/**
	 * Default-mode description (rendered inline in the "Default: X" footer).
	 *
	 * @return string
	 */
	public function default_description(): string {
		return $this->default_description;
	}

	// ---- Translatable labels ----

	/**
	 * "Logo" section heading.
	 *
	 * @return string
	 */
	public function logo_heading(): string {
		return __( 'Logo', 'nettertech-events' );
	}

	/**
	 * Radiogroup ARIA label ("QR Code Logo").
	 *
	 * @return string
	 */
	public function radiogroup_aria_label(): string {
		return __( 'QR Code Logo', 'nettertech-events' );
	}

	/**
	 * "Default" option label.
	 *
	 * @return string
	 */
	public function default_label(): string {
		return __( 'Default', 'nettertech-events' );
	}

	/**
	 * "None" option label.
	 *
	 * @return string
	 */
	public function none_label(): string {
		return __( 'None', 'nettertech-events' );
	}

	/**
	 * "Site" option label.
	 *
	 * @return string
	 */
	public function site_label(): string {
		return __( 'Site', 'nettertech-events' );
	}

	/**
	 * "Custom" option label.
	 *
	 * @return string
	 */
	public function custom_label(): string {
		return __( 'Custom', 'nettertech-events' );
	}
}
