<?php
/**
 * Email Service Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Ticket;

/**
 * Interface for EmailService implementations.
 *
 * Provides email settings management, recipient resolution, and
 * delegation to order/RSVP email handlers for Pro extensibility.
 *
 * @since 2.0.0
 * @api
 */
interface EmailServiceInterface {

	/**
	 * Register email hooks via delegated handlers.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void;

	/**
	 * Handle WooCommerce order completion (delegates to OrderEmailHandler).
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function handle_order_completed( int $order_id ): void;

	/**
	 * Handle RSVP form submission (delegates to RsvpEmailHandler).
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $attendee_id Attendee ID.
	 * @param array<string, mixed> $form_data   Form submission data.
	 * @return void
	 */
	public function handle_rsvp_submitted( int $attendee_id, array $form_data ): void;

	/**
	 * Send customer confirmation email (delegates to OrderEmailHandler).
	 *
	 * @since 0.1.0
	 *
	 * @param \WC_Order $order   WooCommerce order.
	 * @param Ticket[]  $tickets Tickets in the order.
	 * @return bool True if email was sent.
	 */
	public function send_customer_confirmation( \WC_Order $order, array $tickets ): bool;

	/**
	 * Send venue notification emails (delegates to OrderEmailHandler).
	 *
	 * @since 0.1.0
	 *
	 * @param \WC_Order $order   WooCommerce order.
	 * @param Ticket[]  $tickets Tickets in the order.
	 * @return bool True if at least one email was sent.
	 */
	public function send_venue_notifications( \WC_Order $order, array $tickets ): bool;

	/**
	 * Resend confirmation email (delegates to OrderEmailHandler).
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array{success: bool, message: string}
	 */
	public function resend_confirmation( int $order_id ): array;

	/**
	 * Handle AJAX resend request (delegates to OrderEmailHandler).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle_ajax_resend(): void;

	/**
	 * Get email settings.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array;

	/**
	 * Check if customer email is disabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_customer_email_disabled(): bool;

	/**
	 * Check if QR codes are disabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_qr_disabled(): bool;

	/**
	 * Get venue logo URL.
	 *
	 * @since 0.1.0
	 *
	 * @return string Logo URL or empty string.
	 */
	public function get_venue_logo(): string;

	/**
	 * Get global venue contact emails.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string>
	 */
	public function get_venue_contacts(): array;

	/**
	 * Get cancellation policy text.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_cancellation_policy(): string;

	/**
	 * Get the email accent color.
	 *
	 * @since 2.0.0
	 *
	 * @return string Hex color with # prefix.
	 */
	public function get_accent_color(): string;

	/**
	 * Get template settings for renderer.
	 *
	 * @since 0.9.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_template_settings(): array;

	/**
	 * Get venue notification recipients for tickets.
	 *
	 * @since 0.1.0
	 *
	 * @param Ticket[] $tickets Tickets.
	 * @return array<string> Unique email addresses.
	 */
	public function get_venue_notification_recipients( array $tickets ): array;
}
