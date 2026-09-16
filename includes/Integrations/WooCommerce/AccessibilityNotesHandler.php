<?php
/**
 * Accessibility Notes Handler.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Frontend\AccessibilityNotesField;

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
	 * Style handle for the checkout field (classic and block).
	 *
	 * @var string
	 */
	public const STYLE_HANDLE = 'nettertech-events-accessibility-notes';

	/**
	 * Enqueue the field's small stylesheet on the checkout page.
	 *
	 * A src-less handle with inline CSS: the plugin's checkout stylesheet is
	 * the donations sheet and only loads when donations are on, so the
	 * accessibility field carries its own few rules — enough that the purpose
	 * line reads as part of the field and the placeholder stays legible on
	 * themes that paint placeholders in a pale palette colour.
	 *
	 * @return void
	 */
	public function enqueue_styles(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$css = '
			.nte-accessibility-notes-field .description,
			.nte-wc-blocks-field__purpose { display: block; margin: 4px 0 0; font-size: 0.9em; line-height: 1.4; color: #50575e; }
			.nte-wc-blocks-field__heading { display: block; margin: 0 0 8px; font-weight: 600; }
			.nte-wc-blocks-field__textarea { width: 100%; font: inherit; }
			.nte-accessibility-notes-field textarea::placeholder { color: #6b6b6b; opacity: 1; }
		';

		wp_register_style( self::STYLE_HANDLE, false, array(), NETTERTECH_EVENTS_VERSION );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_inline_style( self::STYLE_HANDLE, $css );
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

		// Wording, limit and input name come from the shared field definition so
		// this surface, the block checkout and the RSVP form stay identical.
		// WooCommerce renders `description` as the field's aria-describedby text.
		woocommerce_form_field(
			AccessibilityNotesField::input_name(),
			array(
				'type'              => 'textarea',
				'class'             => array( 'form-row-wide' ),
				'label'             => AccessibilityNotesField::label(),
				'placeholder'       => AccessibilityNotesField::placeholder(),
				'description'       => AccessibilityNotesField::purpose_text(),
				'required'          => false,
				'custom_attributes' => array(
					'maxlength' => (string) AccessibilityNotesField::MAX_LENGTH,
				),
			),
			$checkout->get_value( AccessibilityNotesField::input_name() )
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

		$notes = AccessibilityNotesField::sanitize( $checkout->get_value( AccessibilityNotesField::input_name() ) );
		if ( null === $notes ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$order->update_meta_data( MetaKeys::ACCESSIBILITY_NOTES, $notes );
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
		$notes = $order->get_meta( MetaKeys::ACCESSIBILITY_NOTES, true );

		if ( empty( $notes ) ) {
			return;
		}

		echo '<div class="nte-accessibility-notes-admin" style="margin-top: 15px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">';
		echo '<h4 style="margin: 0 0 5px; color: #856404;">' . esc_html__( 'Accessibility Notes', 'nettertech-events' ) . '</h4>';
		echo '<p style="margin: 0; white-space: pre-wrap;">' . esc_html( $notes ) . '</p>';
		echo '</div>';
	}

	/**
	 * Show the buyer their own accessibility note on the Order Received page.
	 *
	 * Runs on `woocommerce_order_details_after_order_table`, which both the
	 * classic thank-you template and the block Order Confirmation render, so
	 * it inherits WooCommerce's own order-key / logged-in gate untouched. The
	 * note is Art. 9 data: this surface shows the data subject their own
	 * submission only; nothing here changes the no-email, no-activity-log
	 * rules from NTE-217, and nothing new is exposed to other roles.
	 *
	 * @since 1.4.7
	 *
	 * @param \WC_Order|mixed $order Order object.
	 * @return void
	 */
	public function display_order_received( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$notes = $order->get_meta( MetaKeys::ACCESSIBILITY_NOTES, true );
		if ( ! is_string( $notes ) || '' === trim( $notes ) ) {
			return;
		}

		echo '<section class="woocommerce-column nte-accessibility-notes-received">';
		echo '<h2 class="woocommerce-column__title">' . esc_html( AccessibilityNotesField::label() ) . '</h2>';
		echo '<p class="nte-accessibility-notes-received__note" style="white-space: pre-wrap;">' . esc_html( $notes ) . '</p>';
		echo '<p class="nte-accessibility-notes-received__purpose"><small>' . esc_html( AccessibilityNotesField::purpose_text() ) . '</small></p>';
		echo '</section>';
	}
}
