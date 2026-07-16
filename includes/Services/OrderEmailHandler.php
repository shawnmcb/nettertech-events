<?php
/**
 * Order email handler for WooCommerce-triggered emails.
 *
 * Extracted from EmailService to separate WooCommerce order flows
 * from RSVP flows, reducing cognitive complexity.
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
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Models\Ticket;
use NetterTechEvents\Services\PdfTicketService;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Handles WooCommerce order confirmation and venue notification emails.
 *
 * @since 1.1.0
 * @api
 */
class OrderEmailHandler {

	/**
	 * QR code service (nullable; when null, emails send without QR codes).
	 *
	 * @var QRCodeServiceInterface|null
	 */
	private ?QRCodeServiceInterface $qr_service;

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
	 * PDF ticket service (optional, lazy-loaded).
	 *
	 * @var PdfTicketService|null
	 */
	private ?PdfTicketService $pdf_service = null;

	/**
	 * ICS calendar-file generator.
	 *
	 * @var IcsGeneratorInterface
	 */
	private IcsGeneratorInterface $ics_generator;

	/**
	 * Constructor.
	 *
	 * @param ?QRCodeServiceInterface        $qr_service    QR code service (null when Pro is inactive).
	 * @param TicketRepositoryInterface      $ticket_repo   Ticket repository.
	 * @param EmailTemplateRendererInterface $renderer      Email template renderer.
	 * @param EmailConfig                    $email_config  Email configuration (settings + recipients).
	 * @param IcsGeneratorInterface          $ics_generator ICS calendar-file generator.
	 */
	public function __construct(
		?QRCodeServiceInterface $qr_service,
		TicketRepositoryInterface $ticket_repo,
		EmailTemplateRendererInterface $renderer,
		EmailConfig $email_config,
		IcsGeneratorInterface $ics_generator
	) {
		$this->qr_service    = $qr_service;
		$this->ticket_repo   = $ticket_repo;
		$this->renderer      = $renderer;
		$this->email_config  = $email_config;
		$this->ics_generator = $ics_generator;
	}

	/**
	 * Set the PDF ticket service for email attachments.
	 *
	 * @param PdfTicketService $pdf_service PDF ticket service.
	 * @return void
	 */
	public function set_pdf_service( PdfTicketService $pdf_service ): void {
		$this->pdf_service = $pdf_service;
	}

	/**
	 * Register WooCommerce email hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_order_completed' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed' ), 20 );
		add_action( 'wp_ajax_nettertech_events_resend_confirmation_email', array( $this, 'handle_ajax_resend' ) );
	}

	/**
	 * Handle WooCommerce order completion.
	 *
	 * Sends customer confirmation and venue notification emails.
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function handle_order_completed( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( 'yes' === $order->get_meta( MetaKeys::CONFIRMATION_EMAIL_SENT ) ) {
			return;
		}

		$tickets = $this->ticket_repo->find_by_order( $order_id );

		if ( empty( $tickets ) ) {
			return;
		}

		// Ensure all tickets have QR codes (requires Pro QR service).
		if ( null !== $this->qr_service ) {
			foreach ( $tickets as $ticket ) {
				if ( empty( $ticket->qr_code_url ) ) {
					$this->qr_service->generate_for_ticket( $ticket );
				}
			}
		}

		// Send customer confirmation email.
		$customer_sent = false;
		if ( ! $this->email_config->is_customer_email_disabled() ) {
			$customer_sent = $this->send_customer_confirmation( $order, $tickets );
		}

		// Send venue notification emails.
		$venue_sent = $this->send_venue_notifications( $order, $tickets );

		// Mark as sent.
		if ( $customer_sent || $venue_sent ) {
			$order->update_meta_data( MetaKeys::CONFIRMATION_EMAIL_SENT, 'yes' );
			$order->update_meta_data( MetaKeys::CONFIRMATION_EMAIL_SENT_AT, current_time( 'mysql' ) );
			$order->save();
		}

		/**
		 * Fires after confirmation emails are sent for an order.
		 *
		 * @since 1.0.2
		 *
		 * @param int      $order_id     WooCommerce order ID.
		 * @param Ticket[] $tickets      Tickets in the order.
		 * @param bool     $customer_sent Whether customer email was sent.
		 * @param bool     $venue_sent    Whether venue notifications were sent.
		 */
		do_action( 'nettertech_events_confirmation_emails_sent', $order_id, $tickets, $customer_sent, $venue_sent );
	}

	/**
	 * Send customer confirmation email for a WooCommerce order.
	 *
	 * @since 0.1.0
	 *
	 * @param \WC_Order $order   WooCommerce order.
	 * @param Ticket[]  $tickets Tickets in the order.
	 * @return bool True if email was sent.
	 */
	public function send_customer_confirmation( \WC_Order $order, array $tickets ): bool {
		$to      = $order->get_billing_email();
		$subject = $this->renderer->get_customer_email_subject( $order, $tickets );
		$body    = $this->renderer->render_customer_email(
			$order,
			$tickets,
			$this->email_config->get_template_settings()
		);
		$headers = $this->renderer->get_email_headers();

		// Add .ics attachment.
		$attachments = array();
		$ics_file    = $this->ics_generator->generate_ics_file( $tickets );
		if ( $ics_file ) {
			$attachments[] = $ics_file;
		}

		// Generate and attach PDF tickets.
		$pdf_file = null;
		if ( $this->pdf_service ) {
			$pdf_content = $this->pdf_service->generate_pdf( $tickets );
			if ( false !== $pdf_content ) {
				$pdf_file = $this->pdf_service->save_to_temp(
					$pdf_content,
					'tickets-order-' . $order->get_id() . '.pdf'
				);
				if ( false !== $pdf_file ) {
					$attachments[] = $pdf_file;
				}
			}
		}

		try {
			$sent = wp_mail( $to, $subject, $body, $headers, $attachments );
		} catch ( \Throwable $e ) {
			DebugLogger::exception( $e, 'OrderEmailHandler' );
			$sent = false;
		} finally {
			// Clean up temp files.
			if ( $ics_file && file_exists( $ics_file ) ) {
				wp_delete_file( $ics_file );
			}
			if ( $pdf_file && file_exists( $pdf_file ) ) {
				wp_delete_file( $pdf_file );
			}
		}

		return $sent;
	}

	/**
	 * Send venue notification emails for a WooCommerce order.
	 *
	 * @since 0.1.0
	 *
	 * @param \WC_Order $order   WooCommerce order.
	 * @param Ticket[]  $tickets Tickets in the order.
	 * @return bool True if at least one email was sent.
	 */
	public function send_venue_notifications( \WC_Order $order, array $tickets ): bool {
		$recipients = $this->email_config->get_venue_notification_recipients( $tickets );

		if ( empty( $recipients ) ) {
			return false;
		}

		$subject = $this->renderer->get_venue_email_subject( $order, $tickets );
		$body    = $this->renderer->render_venue_email( $order, $tickets );
		$headers = $this->renderer->get_email_headers();

		$sent = false;
		foreach ( $recipients as $recipient ) {
			try {
				if ( wp_mail( $recipient, $subject, $body, $headers ) ) {
					$sent = true;
				}
			} catch ( \Throwable $e ) {
				DebugLogger::exception( $e, 'OrderEmailHandler' );
			}
		}

		return $sent;
	}

	/**
	 * Resend confirmation email for an order.
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array{success: bool, message: string}
	 */
	public function resend_confirmation( int $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return array(
				'success' => false,
				'message' => __( 'Order not found.', 'nettertech-events' ),
			);
		}

		$tickets = $this->ticket_repo->find_by_order( $order_id );
		if ( empty( $tickets ) ) {
			return array(
				'success' => false,
				'message' => __( 'No tickets found for this order.', 'nettertech-events' ),
			);
		}

		// Force regenerate QR codes if needed (requires Pro QR service).
		if ( null !== $this->qr_service ) {
			foreach ( $tickets as $ticket ) {
				if ( empty( $ticket->qr_code_url ) ) {
					$this->qr_service->generate_for_ticket( $ticket );
				}
			}
		}

		$sent = $this->send_customer_confirmation( $order, $tickets );

		if ( $sent ) {
			$order->update_meta_data( MetaKeys::CONFIRMATION_EMAIL_RESENT_AT, current_time( 'mysql' ) );
			$order->save();

			return array(
				'success' => true,
				'message' => __( 'Confirmation email resent successfully.', 'nettertech-events' ),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Failed to send email. Please check your email configuration.', 'nettertech-events' ),
		);
	}

	/**
	 * Handle AJAX resend request.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle_ajax_resend(): void {
		// Check capability FIRST (before nonce to avoid timing attacks).
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- edit_shop_orders is a WooCommerce capability.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'nettertech-events' ), 403 );
		}

		check_ajax_referer( 'nettertech_events_resend_email', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'nettertech-events' ) ) );
		}

		$result = $this->resend_confirmation( $order_id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}
}
