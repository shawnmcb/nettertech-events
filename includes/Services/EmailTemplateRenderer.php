<?php
/**
 * Email Template Renderer Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EmailTemplateRendererInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\Ticket;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;

/**
 * Handles email template rendering and ICS file generation.
 *
 * Extracted from EmailService to separate template rendering concerns
 * from email orchestration and settings management.
 *
 * @since 0.9.0
 */
class EmailTemplateRenderer implements EmailTemplateRendererInterface {

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Constructor.
	 *
	 * @since 0.9.0
	 *
	 * @param OccurrenceRepositoryInterface $occurrence_repo  Occurrence repository.
	 * @param EventRepositoryInterface      $event_repo       Event repository.
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 */
	public function __construct(
		OccurrenceRepositoryInterface $occurrence_repo,
		EventRepositoryInterface $event_repo,
		TicketTypeRepositoryInterface $ticket_type_repo
	) {
		$this->occurrence_repo  = $occurrence_repo;
		$this->event_repo       = $event_repo;
		$this->ticket_type_repo = $ticket_type_repo;
	}

	/**
	 * Get email headers for HTML emails.
	 *
	 * @since 0.9.0
	 *
	 * @return array<string>
	 */
	public function get_email_headers(): array {
		$from_name  = get_bloginfo( 'name' );
		$from_email = get_option( 'admin_email' );

		return array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
		);
	}

	/**
	 * Get customer email subject.
	 *
	 * @since 0.9.0
	 *
	 * @param \WC_Order $order   Order.
	 * @param Ticket[]  $tickets Tickets.
	 * @return string
	 */
	public function get_customer_email_subject( \WC_Order $order, array $tickets ): string {
		$ticket_count = count( $tickets );
		$event_title  = '';

		// Get first event title for subject.
		if ( ! empty( $tickets ) ) {
			$occurrence = $this->occurrence_repo->find( $tickets[0]->occurrence_id );
			if ( $occurrence ) {
				$event = $this->event_repo->find( $occurrence->event_id );
				if ( $event ) {
					$event_title = $event->title;
				}
			}
		}

		if ( $ticket_count > 1 ) {
			return sanitize_text_field(
				sprintf(
					/* translators: %d: number of tickets */
					__( 'Your %d Tickets - Order Confirmation', 'nettertech-events' ),
					$ticket_count
				)
			);
		}

		if ( $event_title ) {
			return sanitize_text_field(
				sprintf(
					/* translators: %s: event title */
					__( 'Your Ticket for %s', 'nettertech-events' ),
					$event_title
				)
			);
		}

		return __( 'Your Ticket Confirmation', 'nettertech-events' );
	}

	/**
	 * Get venue notification email subject.
	 *
	 * @since 0.9.0
	 *
	 * @param \WC_Order $order   Order.
	 * @param Ticket[]  $tickets Tickets.
	 * @return string
	 */
	public function get_venue_email_subject( \WC_Order $order, array $tickets ): string {
		$ticket_count = count( $tickets );
		$buyer_name   = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		return sanitize_text_field(
			sprintf(
				/* translators: 1: buyer name, 2: ticket count */
				__( 'New Ticket Purchase: %1$s (%2$d tickets)', 'nettertech-events' ),
				$buyer_name,
				$ticket_count
			)
		);
	}

	/**
	 * Render customer confirmation email HTML.
	 *
	 * @since 0.9.0
	 *
	 * @param \WC_Order            $order   Order.
	 * @param Ticket[]             $tickets Tickets.
	 * @param array<string, mixed> $settings Email settings (venue_logo, show_qr_codes, cancellation_policy).
	 * @return string HTML email content.
	 */
	public function render_customer_email( \WC_Order $order, array $tickets, array $settings = array() ): string {
		// Group tickets by event/occurrence.
		$grouped = $this->group_tickets_by_event( $tickets );

		// Build template data.
		$data = array(
			'order'               => $order,
			'tickets'             => $tickets,
			'grouped_tickets'     => $grouped,
			'venue_logo'          => $settings['venue_logo'] ?? '',
			'show_qr_codes'       => $settings['show_qr_codes'] ?? true,
			'cancellation_policy' => $settings['cancellation_policy'] ?? '',
			'accent_color'        => $settings['accent_color'] ?? '#333333',
			'site_name'           => get_bloginfo( 'name' ),
			'site_url'            => home_url(),
		);

		return $this->render_email_template( 'emails/customer-confirmation', $data );
	}

	/**
	 * Render venue notification email HTML.
	 *
	 * @since 0.9.0
	 *
	 * @param \WC_Order $order   Order.
	 * @param Ticket[]  $tickets Tickets.
	 * @return string HTML email content.
	 */
	public function render_venue_email( \WC_Order $order, array $tickets ): string {
		// Group tickets by event/occurrence for summary.
		$grouped = $this->group_tickets_by_event( $tickets );

		// Calculate totals.
		$total_revenue = array_reduce(
			$tickets,
			fn( float $sum, Ticket $t ) => $sum + $t->price_paid,
			0.0
		);

		$email_settings = get_option( 'nettertech_events_email_settings', array() );

		$data = array(
			'order'           => $order,
			'tickets'         => $tickets,
			'grouped_tickets' => $grouped,
			'total_revenue'   => $total_revenue,
			'buyer_name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'buyer_email'     => $order->get_billing_email(),
			'accent_color'    => $email_settings['accent_color'] ?? '#333333',
			'site_name'       => get_bloginfo( 'name' ),
		);

		return $this->render_email_template( 'emails/venue-notification', $data );
	}

	/**
	 * Render an email template.
	 *
	 * Supports theme overrides via nettertech-events/emails/ directory.
	 *
	 * @since 0.9.0
	 *
	 * @param string               $template Template path (relative to templates/).
	 * @param array<string, mixed> $data     Template data.
	 * @return string Rendered HTML.
	 */
	public function render_email_template( string $template, array $data ): string {
		// Check for theme override.
		$theme_template  = locate_template( 'nettertech-events/' . $template . '.php' );
		$plugin_template = NETTERTECH_EVENTS_PLUGIN_DIR . 'templates/' . $template . '.php';

		$template_file = $theme_template ? $theme_template : $plugin_template;

		if ( ! file_exists( $template_file ) ) {
			return '';
		}

		// Email templates receive the data wrapped in EmailContext so they
		// can read property-style (e.g. $context->order) and use helpers
		// like $context->accent_color(). See includes/TemplateLoader/EmailContext.php.
		$context = new \NetterTechEvents\TemplateLoader\EmailContext( $data );

		ob_start();
		include $template_file;
		return (string) ob_get_clean();
	}

	/**
	 * Render RSVP confirmation email HTML.
	 *
	 * @since 0.9.0
	 *
	 * @param Ticket               $ticket   Ticket.
	 * @param array<string, mixed> $settings Email settings.
	 * @param string               $attendee_name Attendee name.
	 * @return string HTML email content.
	 */
	public function render_rsvp_confirmation(
		Ticket $ticket,
		array $settings = array(),
		string $attendee_name = ''
	): string {
		$occurrence = $this->occurrence_repo->find( $ticket->occurrence_id );
		$event      = $occurrence ? $this->event_repo->find( $occurrence->event_id ) : null;

		$data = array(
			'ticket'              => $ticket,
			'occurrence'          => $occurrence,
			'event'               => $event,
			'attendee_name'       => $attendee_name,
			'venue_logo'          => $settings['venue_logo'] ?? '',
			'show_qr_codes'       => $settings['show_qr_codes'] ?? true,
			'cancellation_policy' => $settings['cancellation_policy'] ?? '',
			'accent_color'        => $settings['accent_color'] ?? '#333333',
			'site_name'           => get_bloginfo( 'name' ),
			'site_url'            => home_url(),
		);

		return $this->render_email_template( 'emails/rsvp-confirmation', $data );
	}

	/**
	 * Render RSVP venue notification email HTML.
	 *
	 * @since 0.9.0
	 *
	 * @param Ticket $ticket        Ticket.
	 * @param string $attendee_name Attendee name.
	 * @param string $attendee_email Attendee email.
	 * @return string HTML email content.
	 */
	public function render_rsvp_venue_notification(
		Ticket $ticket,
		string $attendee_name = '',
		string $attendee_email = ''
	): string {
		$occurrence = $this->occurrence_repo->find( $ticket->occurrence_id );
		$event      = $occurrence ? $this->event_repo->find( $occurrence->event_id ) : null;

		$email_settings = get_option( 'nettertech_events_email_settings', array() );

		$data = array(
			'ticket'         => $ticket,
			'occurrence'     => $occurrence,
			'event'          => $event,
			'attendee_name'  => $attendee_name,
			'attendee_email' => $attendee_email,
			'accent_color'   => $email_settings['accent_color'] ?? '#333333',
			'site_name'      => get_bloginfo( 'name' ),
		);

		return $this->render_email_template( 'emails/rsvp-venue-notification', $data );
	}

	/**
	 * Get RSVP confirmation email subject.
	 *
	 * @since 0.9.0
	 *
	 * @param Ticket $ticket Ticket.
	 * @return string Email subject.
	 */
	public function get_rsvp_confirmation_subject( Ticket $ticket ): string {
		$occurrence = $this->occurrence_repo->find( $ticket->occurrence_id );
		$event      = $occurrence ? $this->event_repo->find( $occurrence->event_id ) : null;

		if ( $event ) {
			return sanitize_text_field(
				/* translators: %s: event title */
				sprintf( __( 'Your RSVP Confirmation for %s', 'nettertech-events' ), $event->title )
			);
		}

		return __( 'Your RSVP Confirmation', 'nettertech-events' );
	}

	/**
	 * Get RSVP venue notification email subject.
	 *
	 * @since 0.9.0
	 *
	 * @param Ticket $ticket        Ticket.
	 * @param string $attendee_name Attendee name.
	 * @return string Email subject.
	 */
	public function get_rsvp_venue_notification_subject( Ticket $ticket, string $attendee_name ): string {
		$occurrence = $this->occurrence_repo->find( $ticket->occurrence_id );
		$event      = $occurrence ? $this->event_repo->find( $occurrence->event_id ) : null;

		return sanitize_text_field(
			sprintf(
				/* translators: 1: attendee name, 2: event title or 'an event' */
				__( 'New RSVP: %1$s for %2$s', 'nettertech-events' ),
				$attendee_name ? $attendee_name : __( 'Guest', 'nettertech-events' ),
				$event ? $event->title : __( 'an event', 'nettertech-events' )
			)
		);
	}

	/**
	 * Get reminder email subject.
	 *
	 * @since 0.9.5
	 *
	 * @param Event      $event      Event.
	 * @param Occurrence $occurrence Occurrence.
	 * @return string Email subject.
	 */
	public function get_reminder_subject( Event $event, Occurrence $occurrence ): string {
		$date = $occurrence->get_formatted_date();

		/**
		 * Filters the reminder email subject line.
		 *
		 * @since 1.0.2
		 *
		 * @param string     $subject    Default subject line.
		 * @param Event      $event      Event object.
		 * @param Occurrence $occurrence Occurrence object.
		 */
		return sanitize_text_field(
			(string) apply_filters(
				'nettertech_events_reminder_email_subject',
				sprintf(
					/* translators: 1: event title, 2: event date */
					__( 'Reminder: %1$s — %2$s', 'nettertech-events' ),
					$event->title,
					$date
				),
				$event,
				$occurrence
			)
		);
	}

	/**
	 * Render event reminder email HTML.
	 *
	 * @since 0.9.5
	 *
	 * @param Occurrence           $occurrence Occurrence.
	 * @param Event                $event      Event.
	 * @param Attendee             $attendee   Attendee.
	 * @param array<string, mixed> $settings   Email settings (venue_logo).
	 * @return string HTML email content.
	 */
	public function render_reminder_email(
		Occurrence $occurrence,
		Event $event,
		Attendee $attendee,
		array $settings = array()
	): string {
		$data = array(
			'occurrence'   => $occurrence,
			'event'        => $event,
			'attendee'     => $attendee,
			'venue_logo'   => $settings['venue_logo'] ?? '',
			'accent_color' => $settings['accent_color'] ?? '#333333',
			'site_name'    => get_bloginfo( 'name' ),
			'site_url'     => home_url(),
			'event_url'    => $event->get_permalink(),
		);

		/**
		 * Filters the reminder email template data.
		 *
		 * Allows Pro Email Builder to override template variables.
		 *
		 * @since 1.0.2
		 *
		 * @param array<string, mixed> $data       Template data.
		 * @param Occurrence           $occurrence Occurrence object.
		 * @param Event                $event      Event object.
		 * @param Attendee             $attendee   Attendee object.
		 */
		$data = apply_filters( 'nettertech_events_reminder_email_data', $data, $occurrence, $event, $attendee );

		$html = $this->render_email_template( 'emails/event-reminder', $data );

		/**
		 * Filters the rendered reminder email HTML.
		 *
		 * Allows Pro Email Builder to completely replace the template.
		 *
		 * @since 1.0.2
		 *
		 * @param string     $html       Rendered HTML.
		 * @param Occurrence $occurrence Occurrence object.
		 * @param Event      $event      Event object.
		 * @param Attendee   $attendee   Attendee object.
		 */
		return apply_filters( 'nettertech_events_reminder_email_content', $html, $occurrence, $event, $attendee );
	}

	/**
	 * Group tickets by event/occurrence.
	 *
	 * @since 0.9.0
	 *
	 * @param Ticket[] $tickets Tickets.
	 * @return array<int, array{event: \NetterTechEvents\Models\Event|null, occurrence: \NetterTechEvents\Models\Occurrence|null, tickets: Ticket[], ticket_type: \NetterTechEvents\Models\TicketType|null}>
	 */
	public function group_tickets_by_event( array $tickets ): array {
		$grouped = array();

		foreach ( $tickets as $ticket ) {
			$occ_id = $ticket->occurrence_id;

			if ( ! isset( $grouped[ $occ_id ] ) ) {
				$occurrence  = $this->occurrence_repo->find( $occ_id );
				$event       = $occurrence ? $this->event_repo->find( $occurrence->event_id ) : null;
				$ticket_type = $ticket->ticket_type_id ? $this->ticket_type_repo->find( $ticket->ticket_type_id ) : null;

				$grouped[ $occ_id ] = array(
					'event'       => $event,
					'occurrence'  => $occurrence,
					'ticket_type' => $ticket_type,
					'tickets'     => array(),
				);
			}

			$grouped[ $occ_id ]['tickets'][] = $ticket;
		}

		return $grouped;
	}
}
