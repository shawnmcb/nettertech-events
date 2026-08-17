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
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\WaitlistRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
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
	 * Event repository, used to resolve the parent Event of an occurrence.
	 *
	 * @var EventRepositoryInterface|null
	 */
	private ?EventRepositoryInterface $event_repo;

	/**
	 * Constructor.
	 *
	 * @param WaitlistRepositoryInterface    $waitlist_repo   Waitlist repository.
	 * @param OccurrenceRepositoryInterface  $occurrence_repo Occurrence repository.
	 * @param EmailTemplateRendererInterface $renderer        Email template renderer.
	 * @param EmailConfig                    $email_config    Email configuration (settings).
	 * @param EventRepositoryInterface|null  $event_repo      Event repository (NTE-210: resolves the
	 *                                                        events-table row; `Occurrence->event_id`
	 *                                                        is NOT a WP post ID).
	 */
	public function __construct(
		WaitlistRepositoryInterface $waitlist_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		EmailTemplateRendererInterface $renderer,
		EmailConfig $email_config,
		?EventRepositoryInterface $event_repo = null
	) {
		$this->waitlist_repo   = $waitlist_repo;
		$this->occurrence_repo = $occurrence_repo;
		$this->renderer        = $renderer;
		$this->event_repo      = $event_repo;
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

		// NTE-210: `event_id` is the custom events-table row id, not a WP post ID —
		// `get_the_title( $occurrence->event_id )` returned an unrelated post's title.
		$event      = $this->resolve_event( $occurrence );
		$event_name = null !== $event && '' !== $event->title ? $event->title : __( 'Event', 'nettertech-events' );

		$booking_url = add_query_arg(
			'waitlist_token',
			(string) $entry->id,
			$this->resolve_booking_url( $occurrence, $event )
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
	/**
	 * Resolve the parent Event of an occurrence from the events table.
	 *
	 * Prefers the occurrence's own lazy-loader (set when the repository was
	 * built with an event repository), then the injected event repository.
	 * Never consults wp_posts by `event_id` (NTE-210).
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @return Event|null Resolved event, or null when unavailable.
	 */
	private function resolve_event( Occurrence $occurrence ): ?Event {
		$event = $occurrence->get_event();
		if ( null === $event && null !== $this->event_repo && $occurrence->event_id > 0 ) {
			$event = $this->event_repo->find( $occurrence->event_id );
			if ( null !== $event ) {
				$occurrence->set_event( $event );
			}
		}
		return $event;
	}

	/**
	 * Resolve the page the promoted customer should land on to buy.
	 *
	 * Occurrence URL first (event page for single-date events, dated URL for
	 * multi-date ones); the event permalink as a fallback; the site root when
	 * the event cannot be resolved at all.
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @param Event|null $event      The resolved event, if any.
	 * @return string Absolute URL.
	 */
	private function resolve_booking_url( Occurrence $occurrence, ?Event $event ): string {
		if ( null === $event ) {
			return home_url( '/' );
		}
		$url = $occurrence->get_url();
		return '' !== $url ? $url : $event->get_permalink();
	}
}
