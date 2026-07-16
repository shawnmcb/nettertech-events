<?php
/**
 * Email Template Renderer Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\Ticket;

/**
 * Interface for email template rendering services.
 *
 * Defines methods for rendering confirmation emails, venue notifications,
 * RSVP emails, and generating ICS calendar files.
 *
 * @since 0.9.4
 * @api
 */
interface EmailTemplateRendererInterface {

	/**
	 * Get email headers for HTML emails.
	 *
	 * @return array<string>
	 */
	public function get_email_headers(): array;

	/**
	 * Get customer email subject.
	 *
	 * @param \WC_Order $order   Order.
	 * @param Ticket[]  $tickets Tickets.
	 * @return string
	 */
	public function get_customer_email_subject( \WC_Order $order, array $tickets ): string;

	/**
	 * Get venue notification email subject.
	 *
	 * @param \WC_Order $order   Order.
	 * @param Ticket[]  $tickets Tickets.
	 * @return string
	 */
	public function get_venue_email_subject( \WC_Order $order, array $tickets ): string;

	/**
	 * Render customer confirmation email HTML.
	 *
	 * @param \WC_Order            $order   Order.
	 * @param Ticket[]             $tickets Tickets.
	 * @param array<string, mixed> $settings Email settings (venue_logo, show_qr_codes, cancellation_policy).
	 * @return string HTML email content.
	 */
	public function render_customer_email( \WC_Order $order, array $tickets, array $settings = array() ): string;

	/**
	 * Render venue notification email HTML.
	 *
	 * @param \WC_Order $order   Order.
	 * @param Ticket[]  $tickets Tickets.
	 * @return string HTML email content.
	 */
	public function render_venue_email( \WC_Order $order, array $tickets ): string;

	/**
	 * Render an email template.
	 *
	 * Supports theme overrides via nettertech-events/emails/ directory.
	 *
	 * @param string               $template Template path (relative to templates/).
	 * @param array<string, mixed> $data     Template data.
	 * @return string Rendered HTML.
	 */
	public function render_email_template( string $template, array $data ): string;

	/**
	 * Render RSVP confirmation email HTML.
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
	): string;

	/**
	 * Render RSVP venue notification email HTML.
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
	): string;

	/**
	 * Get RSVP confirmation email subject.
	 *
	 * @param Ticket $ticket Ticket.
	 * @return string Email subject.
	 */
	public function get_rsvp_confirmation_subject( Ticket $ticket ): string;

	/**
	 * Get RSVP venue notification email subject.
	 *
	 * @param Ticket $ticket        Ticket.
	 * @param string $attendee_name Attendee name.
	 * @return string Email subject.
	 */
	public function get_rsvp_venue_notification_subject( Ticket $ticket, string $attendee_name ): string;

	/**
	 * Group tickets by event/occurrence.
	 *
	 * @param Ticket[] $tickets Tickets.
	 * @return array<int, array{event: \NetterTechEvents\Models\Event|null, occurrence: \NetterTechEvents\Models\Occurrence|null, tickets: Ticket[], ticket_type: \NetterTechEvents\Models\TicketType|null}>
	 */
	public function group_tickets_by_event( array $tickets ): array;

	/**
	 * Get reminder email subject.
	 *
	 * @since 0.9.5
	 *
	 * @param Event      $event      Event.
	 * @param Occurrence $occurrence Occurrence.
	 * @return string Email subject.
	 */
	public function get_reminder_subject( Event $event, Occurrence $occurrence ): string;

	/**
	 * Render event reminder email HTML.
	 *
	 * @since 0.9.5
	 *
	 * @param Occurrence           $occurrence Occurrence.
	 * @param Event                $event      Event.
	 * @param Attendee             $attendee   Attendee.
	 * @param array<string, mixed> $settings   Email settings.
	 * @return string HTML email content.
	 */
	public function render_reminder_email(
		Occurrence $occurrence,
		Event $event,
		Attendee $attendee,
		array $settings = array()
	): string;
}
