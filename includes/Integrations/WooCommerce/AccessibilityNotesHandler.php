<?php
/**
 * Accessibility Notes Handler.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Handles accessibility notes field on WooCommerce checkout.
 *
 * Renders a textarea for accessibility requirements when the cart contains
 * event tickets, saves the value to order meta, and displays it in the
 * admin order view.
 *
 * @since 1.0.0
 */
class AccessibilityNotesHandler {

	/**
	 * Cart handler instance.
	 *
	 * Used to determine whether the cart contains event tickets before
	 * rendering the field — avoids showing it on non-event orders.
	 *
	 * @var CartHandler
	 */
	private CartHandler $cart_handler;

	/**
	 * Constructor.
	 *
	 * @param CartHandler $cart_handler Cart handler for ticket detection.
	 */
	public function __construct( CartHandler $cart_handler ) {
		$this->cart_handler = $cart_handler;
	}

	/**
	 * Render accessibility notes field on checkout.
	 *
	 * Only displays if cart contains event tickets.
	 *
	 * @param \WC_Checkout $checkout Checkout instance.
	 * @return void
	 */
	public function render_field( $checkout ): void {
		if ( ! $this->cart_handler->cart_has_tickets() ) {
			return;
		}

		echo '<div class="nte-accessibility-notes-field">';

		woocommerce_form_field(
			'nettertech_events_accessibility_notes',
			array(
				'type'        => 'textarea',
				'class'       => array( 'form-row-wide' ),
				'label'       => __( 'Accessibility Requirements', 'nettertech-events' ),
				'placeholder' => __( 'Please let us know about any accessibility needs or accommodations we can provide (wheelchair access, ASL interpreter, etc.)', 'nettertech-events' ),
				'required'    => false,
			),
			$checkout->get_value( 'nettertech_events_accessibility_notes' )
		);

		echo '</div>';
	}

	/**
	 * Save accessibility notes to order meta.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function save_notes( int $order_id ): void {
		// Read the field through WooCommerce's checkout API, which exposes
		// already-validated posted data after WC has verified its own
		// woocommerce-process-checkout-nonce. No raw $_POST access here.
		$checkout = function_exists( 'WC' ) ? WC()->checkout() : null;
		if ( ! $checkout ) {
			return;
		}

		$raw_notes = $checkout->get_value( 'nettertech_events_accessibility_notes' );
		if ( ! is_string( $raw_notes ) || '' === $raw_notes ) {
			return;
		}

		$notes = sanitize_textarea_field( $raw_notes );
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$order->update_meta_data( '_nettertech_events_accessibility_notes', $notes );
			$order->save();
		}
	}

	/**
	 * Display accessibility notes in admin order view.
	 *
	 * @param \WC_Order $order Order object.
	 * @return void
	 */
	public function display_admin( $order ): void {
		$notes = $order->get_meta( '_nettertech_events_accessibility_notes', true );

		if ( empty( $notes ) ) {
			return;
		}

		echo '<div class="nte-accessibility-notes-admin" style="margin-top: 15px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">';
		echo '<h4 style="margin: 0 0 5px; color: #856404;">' . esc_html__( 'Accessibility Notes', 'nettertech-events' ) . '</h4>';
		echo '<p style="margin: 0; white-space: pre-wrap;">' . esc_html( $notes ) . '</p>';
		echo '</div>';
	}
}
