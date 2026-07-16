<?php
/**
 * Check-in settings sub-group DTO.
 *
 * Contains check-in report and counter settings.
 *
 * @package NetterTechEvents\Core\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Typed, read-only check-in settings value object.
 *
 * @since 1.7.0
 */
final readonly class CheckInSettings {

	/**
	 * Constructor with property promotion.
	 *
	 * @param string        $checkin_completion_email System check-in report email.
	 * @param array<string> $checkin_counters         Check-in counter labels.
	 */
	public function __construct(
		public string $checkin_completion_email = '',
		public array $checkin_counters = array(),
	) {
	}
}
