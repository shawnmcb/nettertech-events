<?php
/**
 * Email service — settings and recipient resolution.
 *
 * Provides shared email settings management and venue notification recipient
 * resolution used by OrderEmailHandler and RsvpEmailHandler.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EmailServiceInterface;
use NetterTechEvents\Models\Ticket;

/**
 * Central email settings and recipient resolution service.
 *
 * Manages shared email settings and venue notification recipient resolution.
 * WC order flows are handled by OrderEmailHandler, RSVP flows by RsvpEmailHandler.
 *
 * @since 0.1.0
 * @api
 */
class EmailService implements EmailServiceInterface {

	/**
	 * Option key for email settings.
	 *
	 * @deprecated 2.1.0 Use EmailConfig::SETTINGS_KEY.
	 */
	public const SETTINGS_KEY = 'nettertech_events_email_settings';

	/**
	 * Email configuration (settings + recipient resolution).
	 *
	 * @var EmailConfig
	 */
	private EmailConfig $email_config;

	/**
	 * Order email handler.
	 *
	 * @var OrderEmailHandler|null
	 */
	private ?OrderEmailHandler $order_handler;

	/**
	 * RSVP email handler.
	 *
	 * @var RsvpEmailHandler|null
	 */
	private ?RsvpEmailHandler $rsvp_handler;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 * @since 2.1.0 Replaced repo params with EmailConfig; handlers now required at construction.
	 *
	 * @param EmailConfig            $email_config  Shared email configuration and recipient resolution.
	 * @param OrderEmailHandler|null $order_handler Order email handler.
	 * @param RsvpEmailHandler|null  $rsvp_handler  RSVP email handler.
	 */
	public function __construct(
		EmailConfig $email_config,
		?OrderEmailHandler $order_handler = null,
		?RsvpEmailHandler $rsvp_handler = null
	) {
		$this->email_config  = $email_config;
		$this->order_handler = $order_handler;
		$this->rsvp_handler  = $rsvp_handler;
	}

	/**
	 * Register email hooks via delegated handlers.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->order_handler ) {
			$this->order_handler->register();
		}
		if ( $this->rsvp_handler ) {
			$this->rsvp_handler->register();
		}
	}

	// =========================================================================
	// Delegation (backward-compatible public API)
	// =========================================================================

	/**
	 * Handle WooCommerce order completion (delegates to OrderEmailHandler).
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function handle_order_completed( int $order_id ): void {
		if ( $this->order_handler ) {
			$this->order_handler->handle_order_completed( $order_id );
		}
	}

	/**
	 * Handle RSVP form submission (delegates to RsvpEmailHandler).
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $attendee_id Attendee ID.
	 * @param array<string, mixed> $form_data   Form submission data.
	 * @return void
	 */
	public function handle_rsvp_submitted( int $attendee_id, array $form_data ): void {
		if ( $this->rsvp_handler ) {
			$this->rsvp_handler->handle_rsvp_submitted( $attendee_id, $form_data );
		}
	}

	/**
	 * Send customer confirmation email (delegates to OrderEmailHandler).
	 *
	 * @since 0.1.0
	 *
	 * @param \WC_Order $order   WooCommerce order.
	 * @param Ticket[]  $tickets Tickets in the order.
	 * @return bool True if email was sent.
	 */
	public function send_customer_confirmation( \WC_Order $order, array $tickets ): bool {
		if ( $this->order_handler ) {
			return $this->order_handler->send_customer_confirmation( $order, $tickets );
		}
		return false;
	}

	/**
	 * Send venue notification emails (delegates to OrderEmailHandler).
	 *
	 * @since 0.1.0
	 *
	 * @param \WC_Order $order   WooCommerce order.
	 * @param Ticket[]  $tickets Tickets in the order.
	 * @return bool True if at least one email was sent.
	 */
	public function send_venue_notifications( \WC_Order $order, array $tickets ): bool {
		if ( $this->order_handler ) {
			return $this->order_handler->send_venue_notifications( $order, $tickets );
		}
		return false;
	}

	/**
	 * Resend confirmation email (delegates to OrderEmailHandler).
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array{success: bool, message: string}
	 */
	public function resend_confirmation( int $order_id ): array {
		if ( $this->order_handler ) {
			return $this->order_handler->resend_confirmation( $order_id );
		}
		return array(
			'success' => false,
			'message' => __( 'Email handler not configured.', 'nettertech-events' ),
		);
	}

	/**
	 * Handle AJAX resend request (delegates to OrderEmailHandler).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle_ajax_resend(): void {
		if ( $this->order_handler ) {
			$this->order_handler->handle_ajax_resend();
		}
	}

	// =========================================================================
	// Settings Management (delegates to EmailConfig)
	// =========================================================================

	/**
	 * Get email settings.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return $this->email_config->get_settings();
	}

	/**
	 * Check if customer email is disabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_customer_email_disabled(): bool {
		return $this->email_config->is_customer_email_disabled();
	}

	/**
	 * Check if QR codes are disabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_qr_disabled(): bool {
		return $this->email_config->is_qr_disabled();
	}

	/**
	 * Get venue logo URL.
	 *
	 * @since 0.1.0
	 *
	 * @return string Logo URL or empty string.
	 */
	public function get_venue_logo(): string {
		return $this->email_config->get_venue_logo();
	}

	/**
	 * Get global venue contact emails.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string>
	 */
	public function get_venue_contacts(): array {
		return $this->email_config->get_venue_contacts();
	}

	/**
	 * Get cancellation policy text.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_cancellation_policy(): string {
		return $this->email_config->get_cancellation_policy();
	}

	/**
	 * Get the email accent color.
	 *
	 * Returns the admin-configured accent color, or falls back to the
	 * theme's primary color for brand consistency.
	 *
	 * @since 2.0.0
	 *
	 * @return string Hex color with # prefix.
	 */
	public function get_accent_color(): string {
		return $this->email_config->get_accent_color();
	}

	/**
	 * Get template settings for renderer.
	 *
	 * @since 0.9.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_template_settings(): array {
		return $this->email_config->get_template_settings();
	}

	// =========================================================================
	// Recipient Resolution (delegates to EmailConfig)
	// =========================================================================

	/**
	 * Get venue notification recipients for tickets.
	 *
	 * @since 0.1.0
	 *
	 * @param Ticket[] $tickets Tickets.
	 * @return array<string> Unique email addresses.
	 */
	public function get_venue_notification_recipients( array $tickets ): array {
		return $this->email_config->get_venue_notification_recipients( $tickets );
	}
}
