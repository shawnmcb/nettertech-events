<?php
/**
 * AJAX handler for batch ticket cart operations.
 *
 * Handles the nettertech_events_add_tickets_batch AJAX action, delegating validation
 * and cart addition to CartHandler.
 *
 * @package NetterTechEvents\Frontend
 * @since   1.2.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Integrations\WooCommerce\CartHandler;
use NetterTechEvents\Services\RateLimitService;

/**
 * Handles AJAX requests for adding multiple ticket types to cart.
 *
 * Wraps CartHandler to provide a JSON endpoint for the ticket purchase
 * form, supporting batch addition of multiple ticket types in a single
 * request.
 *
 * @since 1.2.0
 */
class TicketCartAjax {

	/**
	 * Cart handler instance.
	 *
	 * @var CartHandler
	 */
	private CartHandler $cart_handler;

	/**
	 * Rate limit service.
	 *
	 * @var RateLimitService
	 */
	private RateLimitService $rate_limit_service;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param CartHandler      $cart_handler       Cart handler instance.
	 * @param RateLimitService $rate_limit_service Rate limit service.
	 */
	public function __construct( CartHandler $cart_handler, RateLimitService $rate_limit_service ) {
		$this->cart_handler       = $cart_handler;
		$this->rate_limit_service = $rate_limit_service;
	}

	/**
	 * Register AJAX hooks.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'wp_ajax_nettertech_events_add_tickets_batch', array( $this, 'handle_add_batch' ) );
		add_action( 'wp_ajax_nopriv_nettertech_events_add_tickets_batch', array( $this, 'handle_add_batch' ) );
	}

	/**
	 * Handle batch ticket addition AJAX request.
	 *
	 * Expected POST parameters:
	 * - nettertech_events_ticket_nonce: Security nonce (action: nettertech_events_ticket_cart_nonce).
	 * - tickets: JSON-encoded array of {ticket_type_id: int, quantity: int}.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function handle_add_batch(): void {
		// Rate limiting.
		if ( ! $this->rate_limit_service->should_bypass() ) {
			$limited = $this->rate_limit_service->check_and_increment();
			if ( $limited ) {
				wp_send_json_error(
					array( 'message' => __( 'Too many requests. Please try again later.', 'nettertech-events' ) ),
					429
				);
			}
		}

		// Verify nonce.
		$nonce = isset( $_POST['nettertech_events_ticket_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['nettertech_events_ticket_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'nettertech_events_ticket_cart_nonce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Security verification failed. Please refresh the page and try again.', 'nettertech-events' ) ),
				403
			);
		}

		// Parse tickets from request. sanitize_textarea_field at the
		// boundary clears WordPress.Security.ValidatedSanitizedInput; the
		// decoded array is normalized + per-field sanitized by
		// sanitize_tickets() before any business-logic use.
		$tickets_raw = isset( $_POST['tickets'] )
			? sanitize_textarea_field( wp_unslash( $_POST['tickets'] ) )
			: '';

		if ( '' !== $tickets_raw ) {
			$tickets_raw = json_decode( $tickets_raw, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				wp_send_json_error(
					array( 'message' => __( 'Invalid ticket data format.', 'nettertech-events' ) ),
					400
				);
			}
		}

		if ( ! is_array( $tickets_raw ) || empty( $tickets_raw ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Please select at least one ticket.', 'nettertech-events' ) ),
				400
			);
		}

		// Sanitize and normalize to ticket_type_id => quantity map.
		$tickets = $this->sanitize_tickets( $tickets_raw );

		if ( empty( $tickets ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Please select at least one ticket with a quantity greater than zero.', 'nettertech-events' ) ),
				400
			);
		}

		// Validate the full batch via CartHandler.
		$validation = $this->cart_handler->validate_batch( $tickets );

		if ( ! $validation['valid'] ) {
			wp_send_json_error(
				array(
					'message'       => __( 'Failed to add tickets to cart.', 'nettertech-events' ),
					'ticket_errors' => $validation['errors'],
				),
				400
			);
		}

		// Add each validated ticket to the WooCommerce cart.
		$added         = array();
		$ticket_errors = array();

		foreach ( $validation['validated'] as $ticket_type_id => $quantity ) {
			$success = $this->cart_handler->add_ticket_to_cart( $ticket_type_id, $quantity );

			if ( $success ) {
				$added[ $ticket_type_id ] = $quantity;
			} else {
				$ticket_errors[ $ticket_type_id ] = __( 'This ticket could not be added to cart.', 'nettertech-events' );
			}
		}

		// Collect and clear WooCommerce notices to avoid stale flash messages.
		if ( function_exists( 'wc_get_notices' ) ) {
			$wc_notices = wc_get_notices();

			if ( ! empty( $wc_notices['error'] ) ) {
				foreach ( $wc_notices['error'] as $notice ) {
					// WC notices may be string or array with 'notice' key.
					$message = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : $notice;
					if ( '' !== $message && empty( $ticket_errors ) ) {
						$ticket_errors[] = $message;
					}
				}
			}

			wc_clear_notices();
		}

		// Full failure — nothing added.
		if ( empty( $added ) ) {
			wp_send_json_error(
				array(
					'message'       => __( 'Failed to add tickets to cart.', 'nettertech-events' ),
					'ticket_errors' => $ticket_errors,
				),
				500
			);
		}

		// Partial failure — some added, some not.
		if ( ! empty( $ticket_errors ) ) {
			wp_send_json_success(
				array(
					'cart_url'      => wc_get_cart_url(),
					'cart_count'    => WC()->cart->get_cart_contents_count(),
					'message'       => __( 'Some tickets were added to cart, but others could not be added.', 'nettertech-events' ),
					'ticket_errors' => $ticket_errors,
				)
			);
		}

		// Full success.
		wp_send_json_success(
			array(
				'cart_url'   => wc_get_cart_url(),
				'cart_count' => WC()->cart->get_cart_contents_count(),
				'message'    => __( 'Tickets added to cart.', 'nettertech-events' ),
			)
		);
	}

	/**
	 * Sanitize ticket data from the AJAX request.
	 *
	 * Accepts an array of {ticket_type_id, quantity} objects, validates
	 * each field with absint(), skips zero/invalid entries, and aggregates
	 * duplicate ticket type IDs.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $tickets_raw Raw ticket data from the request.
	 * @return array<int, int> Map of ticket_type_id => quantity.
	 */
	private function sanitize_tickets( array $tickets_raw ): array {
		$tickets = array();

		foreach ( $tickets_raw as $ticket ) {
			if ( ! is_array( $ticket ) ) {
				continue;
			}

			$ticket_type_id = isset( $ticket['ticket_type_id'] ) ? absint( $ticket['ticket_type_id'] ) : 0;
			$quantity       = isset( $ticket['quantity'] ) ? absint( $ticket['quantity'] ) : 0;

			if ( 0 === $ticket_type_id || 0 === $quantity ) {
				continue;
			}

			// Aggregate if the same ticket type appears more than once.
			if ( isset( $tickets[ $ticket_type_id ] ) ) {
				$tickets[ $ticket_type_id ] += $quantity;
			} else {
				$tickets[ $ticket_type_id ] = $quantity;
			}
		}

		return $tickets;
	}
}
