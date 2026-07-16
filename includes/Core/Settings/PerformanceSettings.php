<?php
/**
 * Performance settings sub-group DTO.
 *
 * Contains rate limiting, caching, and generation horizon settings.
 *
 * @package NetterTechEvents\Core\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Typed, read-only performance settings value object.
 *
 * @since 1.7.0
 */
final readonly class PerformanceSettings {

	/**
	 * Constructor with property promotion.
	 *
	 * @param int    $occurrence_horizon     Occurrence generation horizon (days).
	 * @param int    $rate_limit_requests    Rate limit requests per window.
	 * @param int    $rate_limit_window      Rate limit window (seconds).
	 * @param int    $pending_hold_time      Reservation hold time (seconds).
	 * @param int    $category_cache_ttl     Category cache TTL (seconds).
	 * @param int    $ical_feed_horizon_days Forward UNTIL cap (days) applied to open-ended iCal RRULEs; 0 disables.
	 * @param bool   $ical_feed_static_mode  Whether the iCal feed is served from a prebuilt static file (NTE-016).
	 * @param string $rate_limit_proxy_mode  Proxy handling for rate-limit client IP: auto|direct|proxied (NTE-142).
	 */
	public function __construct(
		public int $occurrence_horizon = 365,
		public int $rate_limit_requests = 60,
		public int $rate_limit_window = 60,
		public int $pending_hold_time = 900,
		public int $category_cache_ttl = 3600,
		public int $ical_feed_horizon_days = 730,
		public bool $ical_feed_static_mode = false,
		public string $rate_limit_proxy_mode = 'auto',
	) {
	}
}
