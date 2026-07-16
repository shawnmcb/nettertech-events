<?php
/**
 * RSVP email handler for non-WooCommerce email flows.
 *
 * Extracted from EmailService to separate RSVP flows
 * from WooCommerce order flows, reducing cognitive complexity.
 *
 * @package NetterTechEvents\Services
 * @since   1.1.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EmailTemplateRendererInterface;
use NetterTechEvents\Contracts\IcsGeneratorInterface;
use NetterTechEvents\Contracts\QRCodeServiceInterface;
use NetterTechEvents\Contracts\TicketCodeGeneratorInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\Ticket;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Handles RSVP confirmation and venue notification emails.
 *
 * @since 1.1.0
 */
class RsvpEmailHandler {

	/**
	 * QR code service (nullable; when null, no QR images are attached to emails).
	 *
	 * @var QRCodeServiceInterface|null
	 */
	private ?QRCodeServiceInterface $qr_service;

	/**
	 * Ticket code generator.
	 *
	 * @var TicketCodeGeneratorInterface
	 */
	private TicketCodeGeneratorInterface $code_generator;

	/**
	 * Ticket repository.
	 *
	 * @var TicketRepositoryInterface
	 */
	private TicketRepositoryInterface $ticket_repo;

	/**
	 * Email template renderer.
	 *
	 * @var EmailTemplateRendererInterface
	 */
	private EmailTemplateRendererInterface $renderer;

	/**
	 * Email configuration for shared settings and recipient resolution.
	 *
	 * @var EmailConfig
	 */
	private EmailConfig $email_config;

	/**
	 * ICS calendar-file generator.
	 *
	 * @var IcsGeneratorInterface
	 */
	private IcsGeneratorInterface $ics_generator;

	/**
	 * Constructor.
	 *
	 * @param ?QRCodeServiceInterface        $qr_service     QR code service (null when Pro is inactive).
	 * @param TicketCodeGeneratorInterface   $code_generator Ticket code generator.
	 * @param TicketRepositoryInterface      $ticket_repo    Ticket repository.
	 * @param EmailTemplateRendererInterface $renderer       Email template renderer.
	 * @param EmailConfig                    $email_config   Email configuration (settings + recipients).
	 * @param IcsGeneratorInterface          $ics_generator  ICS calendar-file generator.
	 */
	public function __construct(
		?QRCodeServiceInterface $qr_service,
		TicketCodeGeneratorInterface $code_generator,
		TicketRepositoryInterface $ticket_repo,
		EmailTemplateRendererInterface $renderer,
		EmailConfig $email_config,
		IcsGeneratorInterface $ics_generator
	) {
		$this->qr_service     = $qr_service;
		$this->code_generator = $code_generator;
		$this->ticket_repo    = $ticket_repo;
		$this->renderer       = $renderer;
		$this->email_config   = $email_config;
		$this->ics_generator  = $ics_generator;
	}

	/**
	 * Register RSVP email hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nettertech_events_rsvp_submitted', array( $this, 'handle_rsvp_submitted' ), 10, 2 );
	}

	/**
	 * Handle RSVP form submission.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $attendee_id Attendee ID.
	 * @param array<string, mixed> $form_data   Form submission data.
	 * @return void
	 */
	public function handle_rsvp_submitted( int $attendee_id, array $form_data ): void {
		$ticket = $this->create_rsvp_ticket( $attendee_id, $form_data );

		if ( ! $ticket ) {
			return;
		}

		// Send customer confirmation.
		if ( ! $this->email_config->is_customer_email_disabled() ) {
			$this->send_rsvp_confirmation( $ticket, $form_data );
		}

		// Send venue notifications.
		$this->send_rsvp_venue_notification( $ticket, $form_data );
	}

	/**
	 * Create a ticket for RSVP (non-WooCommerce).
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $attendee_id Attendee ID.
	 * @param array<string, mixed> $form_data   Form data.
	 * @return Ticket|null Created ticket or null.
	 */
	private function create_rsvp_ticket( int $attendee_id, array $form_data ): ?Ticket {
		$occurrence_id  = $form_data['occurrence_id'] ?? 0;
		$ticket_type_id = $form_data['ticket_type_id'] ?? 0;

		if ( ! $occurrence_id ) {
			return null;
		}

		$ticket                 = new Ticket();
		$ticket->ticket_type_id = $ticket_type_id;
		$ticket->occurrence_id  = $occurrence_id;
		$ticket->attendee_id    = $attendee_id;
		$ticket->ticket_code    = $this->code_generator->generate();
		$ticket->status         = 'confirmed';
		$ticket->price_paid     = 0.0;

		$this->ticket_repo->save( $ticket );

		// Generate QR code image (requires Pro QR service).
		if ( null !== $this->qr_service ) {
			$this->qr_service->generate_for_ticket( $ticket );
		}

		return $ticket;
	}

	/**
	 * Send RSVP confirmation email.
	 *
	 * @since 0.1.0
	 *
	 * @param Ticket               $ticket    Ticket.
	 * @param array<string, mixed> $form_data Form data (contains email, name, etc.).
	 * @return bool True if sent.
	 */
	private function send_rsvp_confirmation( Ticket $ticket, array $form_data ): bool {
		$to = $form_data['email'] ?? '';
		if ( ! is_email( $to ) ) {
			return false;
		}

		$attendee_name = $form_data['name'] ?? '';
		$subject       = $this->renderer->get_rsvp_confirmation_subject( $ticket );
		$body          = $this->renderer->render_rsvp_confirmation(
			$ticket,
			$this->email_config->get_template_settings(),
			$attendee_name
		);
		$headers       = $this->renderer->get_email_headers();

		// Generate ICS attachment.
		$attachments = array();
		$ics_file    = $this->ics_generator->generate_ics_file( array( $ticket ) );
		if ( $ics_file ) {
			$attachments[] = $ics_file;
		}

		try {
			$sent = wp_mail( $to, $subject, $body, $headers, $attachments );
		} catch ( \Throwable $e ) {
			DebugLogger::exception( $e, 'RsvpEmailHandler' );
			$sent = false;
		} finally {
			// Clean up temp ICS file.
			if ( $ics_file && file_exists( $ics_file ) ) {
				wp_delete_file( $ics_file );
			}
		}

		return $sent;
	}

	/**
	 * Send RSVP venue notification.
	 *
	 * @since 0.1.0
	 *
	 * @param Ticket               $ticket    Ticket.
	 * @param array<string, mixed> $form_data Form data.
	 * @return bool True if at least one email sent.
	 */
	private function send_rsvp_venue_notification( Ticket $ticket, array $form_data ): bool {
		$recipients = $this->email_config->get_venue_notification_recipients( array( $ticket ) );

		if ( empty( $recipients ) ) {
			return false;
		}

		$attendee_name  = $form_data['name'] ?? __( 'Guest', 'nettertech-events' );
		$attendee_email = $form_data['email'] ?? '';

		$subject = $this->renderer->get_rsvp_venue_notification_subject( $ticket, $attendee_name );
		$body    = $this->renderer->render_rsvp_venue_notification( $ticket, $attendee_name, $attendee_email );
		$headers = $this->renderer->get_email_headers();

		$sent = false;
		foreach ( $recipients as $recipient ) {
			try {
				if ( wp_mail( $recipient, $subject, $body, $headers ) ) {
					$sent = true;
				}
			} catch ( \Throwable $e ) {
				DebugLogger::exception( $e, 'RsvpEmailHandler' );
			}
		}

		return $sent;
	}
}
