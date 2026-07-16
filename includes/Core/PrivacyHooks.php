<?php
/**
 * Privacy Hooks registration.
 *
 * Registers WordPress Privacy Tools exporters and erasers
 * and schedules the activity log retention cron.
 *
 * @package NetterTechEvents\Core
 * @since   1.1.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Services\PrivacyService;

/**
 * Registers WordPress Privacy Tools hooks for GDPR compliance.
 */
class PrivacyHooks {

	/**
	 * Privacy service instance.
	 *
	 * @var PrivacyService
	 */
	private PrivacyService $service;

	/**
	 * Cron hook name for activity log retention.
	 *
	 * @var string
	 */
	public const RETENTION_CRON_HOOK = Hooks::PURGE_ACTIVITY_LOG_PII_CRON;

	/**
	 * Constructor.
	 *
	 * @param PrivacyService $service Privacy service instance.
	 */
	public function __construct( PrivacyService $service ) {
		$this->service = $service;
	}

	/**
	 * Register all privacy-related hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
		add_action( self::RETENTION_CRON_HOOK, array( $this, 'run_retention_purge' ) );
		add_action( 'admin_init', array( $this, 'register_privacy_policy_content' ) );

		// Schedule daily retention cron if not already scheduled.
		if ( ! wp_next_scheduled( self::RETENTION_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::RETENTION_CRON_HOOK );
		}
	}

	/**
	 * Register privacy policy content for the WordPress Privacy Policy page.
	 *
	 * Describes what personal data this plugin collects and why, so site
	 * owners can include it in their privacy policy.
	 *
	 * @return void
	 */
	public function register_privacy_policy_content(): void {
		$policy_text = '<h2>' . esc_html__( 'NetterTech Events', 'nettertech-events' ) . '</h2>';

		$policy_text .= '<p>' . esc_html__( 'When you register for an event or purchase a ticket, we may collect and store the following personal data:', 'nettertech-events' ) . '</p>';

		$policy_text .= '<ul>';
		$policy_text .= '<li>' . esc_html__( 'Name and email address (required for ticket and RSVP registration)', 'nettertech-events' ) . '</li>';
		$policy_text .= '<li>' . esc_html__( 'Phone number (if provided during registration)', 'nettertech-events' ) . '</li>';
		$policy_text .= '<li>' . esc_html__( 'Custom attendee field responses (e.g. dietary requirements, accessibility needs)', 'nettertech-events' ) . '</li>';
		$policy_text .= '<li>' . esc_html__( 'Ticket purchase records including ticket type, price, and order reference', 'nettertech-events' ) . '</li>';
		$policy_text .= '<li>' . esc_html__( 'RSVP records including attendance status and submission timestamp', 'nettertech-events' ) . '</li>';
		$policy_text .= '<li>' . esc_html__( 'Waitlist entries including position and join timestamp', 'nettertech-events' ) . '</li>';
		$policy_text .= '<li>' . esc_html__( 'Check-in timestamps recorded when attending an event', 'nettertech-events' ) . '</li>';
		$policy_text .= '<li>' . esc_html__( 'Activity log entries associated with your account actions', 'nettertech-events' ) . '</li>';
		$policy_text .= '</ul>';

		$policy_text .= '<p>' . esc_html__( 'This data is used solely to manage event registrations, process ticket orders, and communicate event information. Personal data in activity logs is automatically anonymized after a configurable retention period (default: 90 days). You may request export or erasure of your personal data via the tools on your account page or by contacting the site administrator.', 'nettertech-events' ) . '</p>';

		wp_add_privacy_policy_content( 'NetterTech Events', wp_kses_post( $policy_text ) );
	}

	/**
	 * Register personal data exporters.
	 *
	 * @param array<string, array<string, mixed>> $exporters Existing exporters.
	 * @return array<string, array<string, mixed>> Modified exporters array.
	 */
	public function register_exporters( array $exporters ): array {
		$exporters['nettertech-events-attendees'] = array(
			'exporter_friendly_name' => __( 'NetterTech Events — Attendee Data', 'nettertech-events' ),
			'callback'               => array( $this->service, 'export_attendee_data' ),
		);

		$exporters['nettertech-events-activity-log'] = array(
			'exporter_friendly_name' => __( 'NetterTech Events — Activity Log', 'nettertech-events' ),
			'callback'               => array( $this->service, 'export_activity_log_data' ),
		);

		$exporters['nettertech-events-organizers'] = array(
			'exporter_friendly_name' => __( 'NetterTech Events — Organizer Data', 'nettertech-events' ),
			'callback'               => array( $this->service, 'export_organizer_data' ),
		);

		$exporters['nettertech-events-waitlist'] = array(
			'exporter_friendly_name' => __( 'NetterTech Events — Waitlist Data', 'nettertech-events' ),
			'callback'               => array( $this->service, 'export_waitlist_data' ),
		);

		return $exporters;
	}

	/**
	 * Register personal data erasers.
	 *
	 * @param array<string, array<string, mixed>> $erasers Existing erasers.
	 * @return array<string, array<string, mixed>> Modified erasers array.
	 */
	public function register_erasers( array $erasers ): array {
		$erasers['nettertech-events-attendees'] = array(
			'eraser_friendly_name' => __( 'NetterTech Events — Attendee Data', 'nettertech-events' ),
			'callback'             => array( $this->service, 'erase_attendee_data' ),
		);

		$erasers['nettertech-events-activity-log'] = array(
			'eraser_friendly_name' => __( 'NetterTech Events — Activity Log', 'nettertech-events' ),
			'callback'             => array( $this->service, 'erase_activity_log_data' ),
		);

		$erasers['nettertech-events-organizers'] = array(
			'eraser_friendly_name' => __( 'NetterTech Events — Organizer Data', 'nettertech-events' ),
			'callback'             => array( $this->service, 'erase_organizer_data' ),
		);

		$erasers['nettertech-events-waitlist'] = array(
			'eraser_friendly_name' => __( 'NetterTech Events — Waitlist Data', 'nettertech-events' ),
			'callback'             => array( $this->service, 'erase_waitlist_data' ),
		);

		return $erasers;
	}

	/**
	 * Run the activity log retention purge.
	 *
	 * Called by wp_cron daily to anonymize PII in old log entries.
	 *
	 * @return void
	 */
	public function run_retention_purge(): void {
		/**
		 * Filters the number of days to retain activity log PII.
		 *
		 * @since 1.0.2
		 *
		 * @param int $days Number of days to keep PII (default 90).
		 */
		$days = (int) apply_filters( 'nettertech_events_activity_log_retention_days', 90 );

		$this->service->purge_old_activity_log_pii( $days );
	}
}
