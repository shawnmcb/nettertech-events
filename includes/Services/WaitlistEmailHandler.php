<?php
/**
 * Waitlist email handler.
 *
 * Sends promotion notification emails when customers are promoted from
 * the waitlist. This is a base-plugin handler, ensuring free users get
 * complete waitlist functionality without requiring Pro.
 *
 * @package NetterTechEvents\Services
 * @since   1.7.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EmailTemplateRendererInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\WaitlistRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\WaitlistEntry;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Handles waitlist promotion notification emails.
 *
 * Listens to the WAITLIST_PROMOTED hook and sends an email notifying
 * the customer that a spot has opened up for their event.
 *
 * @since 1.7.0
 */
class WaitlistEmailHandler {

	/**
	 * Waitlist repository.
	 *
	 * @var WaitlistRepositoryInterface
	 */
	private WaitlistRepositoryInterface $waitlist_repo;

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Email template renderer.
	 *
	 * @var EmailTemplateRendererInterface
	 */
	private EmailTemplateRendererInterface $renderer;

	/**
	 * Email configuration for shared settings.
	 *
	 * @var EmailConfig
	 */
	private EmailConfig $email_config;

	/**
	 * Constructor.
	 *
	 * @param WaitlistRepositoryInterface    $waitlist_repo   Waitlist repository.
	 * @param OccurrenceRepositoryInterface  $occurrence_repo Occurrence repository.
	 * @param EmailTemplateRendererInterface $renderer        Email template renderer.
	 * @param EmailConfig                    $email_config    Email configuration (settings).
	 */
	public function __construct(
		WaitlistRepositoryInterface $waitlist_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		EmailTemplateRendererInterface $renderer,
		EmailConfig $email_config
	) {
		$this->waitlist_repo   = $waitlist_repo;
		$this->occurrence_repo = $occurrence_repo;
		$this->renderer        = $renderer;
		$this->email_config    = $email_config;
	}

	/**
	 * Register the promotion hook listener.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nettertech_events_waitlist_promoted', array( $this, 'handle_promoted' ), 10, 2 );
	}

	/**
	 * Handle waitlist promotion event.
	 *
	 * Sends a notification email to the promoted customer. Wrapped in
	 * try/catch so email failures never break the promotion flow.
	 *
	 * @since 1.7.0
	 *
	 * @param WaitlistEntry $entry         The promoted waitlist entry.
	 * @param int           $occurrence_id The occurrence ID.
	 * @return void
	 */
	public function handle_promoted( WaitlistEntry $entry, int $occurrence_id ): void {
		try {
			$this->send_promotion_notification( $entry, $occurrence_id );
		} catch ( \Throwable $e ) {
			DebugLogger::exception( $e, 'WaitlistEmailHandler' );
		}
	}

	/**
	 * Send a promotion notification email.
	 *
	 * @since 1.7.0
	 *
	 * @param WaitlistEntry $entry         The promoted waitlist entry.
	 * @param int           $occurrence_id The occurrence ID.
	 * @return bool True if email was sent successfully.
	 */
	public function send_promotion_notification( WaitlistEntry $entry, int $occurrence_id ): bool {
		$occurrence = $this->occurrence_repo->find( $occurrence_id );
		if ( ! $occurrence ) {
			return false;
		}

		$event_name = get_the_title( $occurrence->event_id );
		if ( empty( $event_name ) ) {
			$event_name = __( 'Event', 'nettertech-events' );
		}

		$booking_url = add_query_arg(
			'waitlist_token',
			(string) $entry->id,
			get_permalink( $occurrence->event_id )
		);

		$subject = sprintf(
			/* translators: %s: event name */
			__( 'A spot is available for %s!', 'nettertech-events' ),
			$event_name
		);

		/**
		 * Filters the waitlist promotion email subject.
		 *
		 * @since 1.0.2
		 *
		 * @param string        $subject       Email subject.
		 * @param WaitlistEntry $entry         The waitlist entry.
		 * @param int           $occurrence_id The occurrence ID.
		 */
		$subject = sanitize_text_field( (string) apply_filters( 'nettertech_events_waitlist_email_subject', $subject, $entry, $occurrence_id ) );

		$settings = $this->email_config->get_template_settings();

		$body = $this->renderer->render_email_template(
			'emails/waitlist-promotion',
			array(
				'entry'        => $entry,
				'occurrence'   => $occurrence,
				'event_name'   => $event_name,
				'booking_url'  => $booking_url,
				'venue_logo'   => $settings['venue_logo'] ?? '',
				'accent_color' => $settings['accent_color'] ?? '#333333',
				'site_name'    => get_bloginfo( 'name' ),
				'site_url'     => home_url(),
			)
		);

		/**
		 * Filters the waitlist promotion email body.
		 *
		 * @since 1.0.2
		 *
		 * @param string        $body          Email body (HTML).
		 * @param WaitlistEntry $entry         The waitlist entry.
		 * @param int           $occurrence_id The occurrence ID.
		 */
		$body = apply_filters( 'nettertech_events_waitlist_email_body', $body, $entry, $occurrence_id );

		$headers = $this->renderer->get_email_headers();

		$sent = wp_mail( $entry->email, $subject, $body, $headers );

		// Update notified_at timestamp on success.
		if ( $sent && null !== $entry->id ) {
			$entry->notified_at = current_time( 'mysql' );
			$this->waitlist_repo->save( $entry );
		}

		/**
		 * Fires after a waitlist notification email is sent (or attempted).
		 *
		 * @since 1.0.2
		 *
		 * @param WaitlistEntry $entry         The waitlist entry.
		 * @param int           $occurrence_id The occurrence ID.
		 * @param bool          $sent          Whether the email was sent.
		 */
		do_action( 'nettertech_events_waitlist_notification_sent', $entry, $occurrence_id, $sent );

		return $sent;
	}
}
