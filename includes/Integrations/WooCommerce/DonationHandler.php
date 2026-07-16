<?php
/**
 * WooCommerce Donation Handler.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Handles checkout donations for venue support.
 *
 * Provides round-up and fixed donation options at checkout,
 * applies donations as cart fees, and stores donation data on orders.
 *
 * @since 0.8.0
 */
class DonationHandler {

	/**
	 * Cart handler for checking ticket presence.
	 *
	 * @var CartHandler
	 */
	private CartHandler $cart_handler;

	/**
	 * Rate limit service.
	 *
	 * @var \NetterTechEvents\Services\RateLimitService
	 */
	private \NetterTechEvents\Services\RateLimitService $rate_limit_service;

	/**
	 * Session key for donation amount.
	 *
	 * @var string
	 */
	private const SESSION_KEY = 'nettertech_events_donation_amount';

	/**
	 * Session key for donation type.
	 *
	 * @var string
	 */
	private const SESSION_TYPE_KEY = 'nettertech_events_donation_type';

	/**
	 * Constructor.
	 *
	 * @param CartHandler                                 $cart_handler       Cart handler instance.
	 * @param \NetterTechEvents\Services\RateLimitService $rate_limit_service Rate limit service.
	 */
	public function __construct( CartHandler $cart_handler, \NetterTechEvents\Services\RateLimitService $rate_limit_service ) {
		$this->cart_handler       = $cart_handler;
		$this->rate_limit_service = $rate_limit_service;
	}

	/**
	 * Get donation settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$dto     = \NetterTechEvents\Core\NetterTechEventsSettings::from_option();
		$tickets = $dto->tickets;

		return array(
			'enabled'      => $tickets->enable_donations,
			'cause'        => $tickets->donation_cause,
			'roundup_to'   => $tickets->roundup_to,
			'presets'      => $tickets->donation_presets,
			'allow_custom' => $tickets->allow_custom_donation,
			'max_donation' => $tickets->max_donation,
		);
	}

	/**
	 * Check if donations are enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$settings = $this->get_settings();
		return $settings['enabled'] && $this->cart_handler->cart_has_tickets();
	}

	/**
	 * Calculate round-up amount based on cart total.
	 *
	 * @param float $total Cart total.
	 * @return float Round-up donation amount.
	 */
	public function get_roundup_amount( float $total ): float {
		$settings = $this->get_settings();

		switch ( $settings['roundup_to'] ) {
			case 'five':
				$rounded = ceil( $total / 5 ) * 5;
				break;
			case 'ten':
				$rounded = ceil( $total / 10 ) * 10;
				break;
			default: // Dollar.
				$rounded = ceil( $total );
				break;
		}

		$amount = $rounded - $total;

		// If amount is 0, round up to next increment.
		if ( $amount < 0.01 ) {
			switch ( $settings['roundup_to'] ) {
				case 'five':
					$amount = 5.00;
					break;
				case 'ten':
					$amount = 10.00;
					break;
				default:
					$amount = 1.00;
					break;
			}
		}

		return round( $amount, 2 );
	}

	/**
	 * Get the current donation amount from session.
	 *
	 * @return float
	 */
	public function get_session_donation(): float {
		if ( ! WC()->session ) {
			return 0.0;
		}

		return (float) WC()->session->get( self::SESSION_KEY, 0 );
	}

	/**
	 * Get the current donation type from session.
	 *
	 * @return string 'roundup'|'fixed'|'custom'|''
	 */
	public function get_session_donation_type(): string {
		if ( ! WC()->session ) {
			return '';
		}

		return (string) WC()->session->get( self::SESSION_TYPE_KEY, '' );
	}

	/**
	 * Set donation in session.
	 *
	 * @param float  $amount Donation amount.
	 * @param string $type   Donation type (roundup, fixed, custom).
	 * @return void
	 */
	public function set_session_donation( float $amount, string $type ): void {
		if ( ! WC()->session ) {
			return;
		}

		$settings = $this->get_settings();

		// Validate and cap amount.
		$amount = max( 0, min( $amount, $settings['max_donation'] ) );

		WC()->session->set( self::SESSION_KEY, $amount );
		WC()->session->set( self::SESSION_TYPE_KEY, $type );
	}

	/**
	 * Clear donation from session.
	 *
	 * @return void
	 */
	public function clear_session_donation(): void {
		if ( ! WC()->session ) {
			return;
		}

		WC()->session->set( self::SESSION_KEY, 0 );
		WC()->session->set( self::SESSION_TYPE_KEY, '' );
	}

	/**
	 * Render donation field on checkout.
	 *
	 * @param \WC_Checkout $checkout Checkout instance (required by WooCommerce action).
	 * @return void
	 */
	public function render_donation_field( $checkout ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by external API or interface contract; cannot remove parameter.
		if ( ! $this->is_enabled() ) {
			return;
		}

		$settings       = $this->get_settings();
		$cart_total     = (float) WC()->cart->get_subtotal();
		$roundup_amount = $this->get_roundup_amount( $cart_total );
		$roundup_total  = $cart_total + $roundup_amount;
		$current_amount = $this->get_session_donation();
		$current_type   = $this->get_session_donation_type();

		?>
		<div class="nte-donation-field" id="nte-donation-field">
			<h3 class="nte-donation-field__title">
				<span class="nte-donation-field__heart" aria-hidden="true">&#10084;</span>
				<?php esc_html_e( 'Add a Donation', 'nettertech-events' ); ?>
			</h3>
			<p class="nte-donation-field__cause"><?php echo esc_html( $settings['cause'] ); ?></p>

			<div class="nte-donation-field__options">
				<!-- Round-up option -->
				<label class="nte-donation-field__option">
					<input type="radio" name="nettertech_events_donation_option" class="nte-donation-option"
							value="roundup" data-amount="<?php echo esc_attr( (string) $roundup_amount ); ?>"
							<?php checked( $current_type, 'roundup' ); ?>>
					<span class="nte-donation-field__label">
						<?php
						printf(
							/* translators: 1: rounded total, 2: donation amount */
							esc_html__( 'Round up to %1$s (+%2$s)', 'nettertech-events' ),
							wp_kses_post( wc_price( $roundup_total ) ),
							wp_kses_post( wc_price( $roundup_amount ) )
						);
						?>
					</span>
				</label>

				<!-- Preset amounts -->
				<?php foreach ( $settings['presets'] as $preset ) : ?>
					<label class="nte-donation-field__option">
						<input type="radio" name="nettertech_events_donation_option" class="nte-donation-option"
								value="fixed" data-amount="<?php echo esc_attr( (string) $preset ); ?>"
								<?php checked( 'fixed' === $current_type && abs( $current_amount - $preset ) < 0.01 ); ?>>
						<span class="nte-donation-field__label">
							<?php echo wp_kses_post( wc_price( $preset ) ); ?>
						</span>
					</label>
				<?php endforeach; ?>

				<!-- Custom amount -->
				<?php if ( $settings['allow_custom'] ) : ?>
					<label class="nte-donation-field__option nte-donation-field__option--custom">
						<input type="radio" name="nettertech_events_donation_option" class="nte-donation-option"
								value="custom" data-amount=""
								<?php checked( $current_type, 'custom' ); ?>>
						<span class="nte-donation-field__label">
							<?php esc_html_e( 'Other:', 'nettertech-events' ); ?>
						</span>
						<span class="nte-donation-field__currency"><?php echo esc_html( get_woocommerce_currency_symbol() ); ?></span>
						<input type="number" name="nettertech_events_donation_custom_amount" class="nte-donation-custom-amount"
								value="<?php echo 'custom' === $current_type ? esc_attr( (string) $current_amount ) : ''; ?>"
								min="1" max="<?php echo esc_attr( (string) $settings['max_donation'] ); ?>"
								step="1" placeholder="0">
					</label>
				<?php endif; ?>

				<!-- No thanks -->
				<label class="nte-donation-field__option">
					<input type="radio" name="nettertech_events_donation_option" class="nte-donation-option"
							value="none" data-amount="0"
							<?php checked( empty( $current_type ) || 'none' === $current_type ); ?>>
					<span class="nte-donation-field__label">
						<?php esc_html_e( 'No thanks', 'nettertech-events' ); ?>
					</span>
				</label>
			</div>
		</div>
		<?php
	}

	/**
	 * Calculate and add donation fee to cart.
	 *
	 * Hooked to woocommerce_cart_calculate_fees.
	 *
	 * @return void
	 */
	public function calculate_donation_fee(): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! $this->is_enabled() ) {
			return;
		}

		$donation_amount = $this->get_session_donation();

		if ( $donation_amount > 0 ) {
			WC()->cart->add_fee(
				__( 'Donation', 'nettertech-events' ),
				$donation_amount,
				false // Not taxable.
			);
		}
	}

	/**
	 * Save donation metadata to order.
	 *
	 * Hooked to woocommerce_checkout_update_order_meta.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function save_donation_meta( int $order_id ): void {
		$donation_amount = $this->get_session_donation();
		$donation_type   = $this->get_session_donation_type();

		if ( $donation_amount > 0 ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$order->update_meta_data( '_nettertech_events_donation_amount', (string) $donation_amount );
				$order->update_meta_data( '_nettertech_events_donation_type', $donation_type );
				$order->save();
			}
		}

		// Clear session after saving.
		$this->clear_session_donation();
	}

	/**
	 * Handle AJAX donation update.
	 *
	 * @security No capability check required. This handler is registered on both
	 *           wp_ajax_ and wp_ajax_nopriv_ hooks to support guest checkout.
	 *           Security model: nonce verification (nettertech_events_donation_nonce) + session-scoped
	 *           data only. The user can only modify their own WC cart session.
	 *
	 * @return void
	 */
	public function handle_ajax_update(): void {
		// Rate limiting (nopriv endpoint — protect against abuse).
		if ( ! $this->rate_limit_service->should_bypass() ) {
			$limited = $this->rate_limit_service->check_and_increment();
			if ( $limited ) {
				wp_send_json_error(
					array( 'message' => __( 'Too many requests. Please try again later.', 'nettertech-events' ) ),
					429
				);
			}
		}

		// Context validation BEFORE nonce check to prevent timing attacks.
		// If donations aren't enabled, fail fast without revealing nonce validity.
		// Assessed in audit GAP-017 (2026-02-24): order confirmed intentional.
		// Feature state is visible in checkout UI; nonce validity is not.
		if ( ! $this->is_enabled() ) {
			wp_send_json_error(
				array( 'message' => __( 'Donations are not enabled.', 'nettertech-events' ) ),
				403
			);
		}

		check_ajax_referer( 'nettertech_events_donation_nonce', 'nonce' );

		$option = isset( $_POST['option'] ) ? sanitize_text_field( wp_unslash( $_POST['option'] ) ) : 'none';
		$amount = isset( $_POST['amount'] ) ? floatval( wp_unslash( $_POST['amount'] ) ) : 0;

		switch ( $option ) {
			case 'roundup':
				$cart_total = WC()->cart->get_subtotal();
				$amount     = $this->get_roundup_amount( $cart_total );
				$this->set_session_donation( $amount, 'roundup' );
				break;

			case 'fixed':
				$this->set_session_donation( $amount, 'fixed' );
				break;

			case 'custom':
				$this->set_session_donation( $amount, 'custom' );
				break;

			default: // None.
				$this->clear_session_donation();
				break;
		}

		wp_send_json_success(
			array(
				'amount' => $this->get_session_donation(),
				'type'   => $this->get_session_donation_type(),
			)
		);
	}

	/**
	 * Display donation info in admin order view.
	 *
	 * @param \WC_Order $order Order object.
	 * @return void
	 */
	public function display_donation_admin( $order ): void {
		$donation_amount = $order->get_meta( '_nettertech_events_donation_amount', true );
		$donation_type   = $order->get_meta( '_nettertech_events_donation_type', true );

		if ( empty( $donation_amount ) ) {
			return;
		}

		$type_labels = array(
			'roundup' => __( 'Round-up', 'nettertech-events' ),
			'fixed'   => __( 'Preset', 'nettertech-events' ),
			'custom'  => __( 'Custom', 'nettertech-events' ),
		);

		$type_label = $type_labels[ $donation_type ] ?? $donation_type;

		echo '<div class="nte-donation-admin" style="margin-top: 15px; padding: 10px; background: #d4edda; border-left: 4px solid #28a745;">';
		echo '<h4 style="margin: 0 0 5px; color: #155724;">' . esc_html__( 'Donation', 'nettertech-events' ) . '</h4>';
		echo '<p style="margin: 0;">';
		printf(
			/* translators: 1: donation amount, 2: donation type */
			esc_html__( 'Amount: %1$s (%2$s)', 'nettertech-events' ),
			wp_kses_post( wc_price( $donation_amount ) ),
			esc_html( $type_label )
		);
		echo '</p>';
		echo '</div>';
	}
}
