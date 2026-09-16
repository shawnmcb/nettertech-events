<?php
/**
 * Ticket and commerce settings sub-group DTO.
 *
 * Contains ticket, RSVP, and donation settings.
 *
 * @package NetterTechEvents\Core\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Typed, read-only ticket and commerce settings value object.
 *
 * @since 1.7.0
 */
final readonly class TicketSettings {

	/**
	 * Constructor with property promotion.
	 *
	 * @param bool         $enable_rsvp            RSVP feature toggle.
	 * @param bool         $enable_tickets          Tickets feature toggle.
	 * @param int          $default_min_per_order   Default min tickets per order.
	 * @param int          $default_max_per_order   Default max tickets per order.
	 * @param int          $low_stock_threshold     Low stock warning threshold.
	 * @param bool         $enable_donations        Donations feature toggle.
	 * @param string       $donation_cause          Donation cause text.
	 * @param string       $roundup_to              Roundup target (dollar|five|ten).
	 * @param bool         $allow_custom_donation   Allow custom donation amounts.
	 * @param int          $max_donation            Maximum donation amount.
	 * @param array<float> $donation_presets        Donation preset amounts.
	 * @param int          $default_ticket_image_id Site-wide default ticket product image attachment ID (0 = none).
	 */
	public function __construct(
		public bool $enable_rsvp = false,
		public bool $enable_tickets = false,
		public int $default_min_per_order = 1,
		public int $default_max_per_order = 10,
		public int $low_stock_threshold = 10,
		public bool $enable_donations = false,
		public string $donation_cause = 'Support our venue',
		public string $roundup_to = 'dollar',
		public bool $allow_custom_donation = true,
		public int $max_donation = 100,
		public array $donation_presets = array( 5.0, 10.0, 25.0 ),
		public int $default_ticket_image_id = 0,
	) {
	}
}
