<?php
/**
 * Advanced settings sub-group DTO.
 *
 * Contains URL paths, data retention, and branding settings.
 *
 * @package NetterTechEvents\Core\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Typed, read-only advanced settings value object.
 *
 * @since 1.7.0
 */
final readonly class AdvancedSettings {

	/**
	 * Constructor with property promotion.
	 *
	 * @param int           $activity_log_retention_days  Activity log retention (days).
	 * @param bool          $delete_data_on_uninstall     Delete data on uninstall.
	 * @param string        $events_base_path             Events URL base path.
	 * @param string        $events_archive_path          Events archive URL path.
	 * @param string        $spaces_base_path             Spaces URL base path.
	 * @param bool          $show_frontend_branding       Show optional frontend branding.
	 * @param array<string> $allowed_embed_sources        Extra CSP frame-src origins (scheme://host) for video embeds.
	 * @param bool          $allow_insecure_embed_sources Whether http:// embed sources are permitted.
	 * @param array<string> $allowed_script_sources       Extra CSP script-src origins (https://host only) for third-party scripts.
	 */
	public function __construct(
		public int $activity_log_retention_days = 90,
		public bool $delete_data_on_uninstall = false,
		public string $events_base_path = 'events',
		public string $events_archive_path = '',
		public string $spaces_base_path = 'spaces',
		public bool $show_frontend_branding = false,
		public array $allowed_embed_sources = array(),
		public bool $allow_insecure_embed_sources = false,
		public array $allowed_script_sources = array(),
	) {
	}
}
