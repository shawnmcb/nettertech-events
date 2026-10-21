<?php
/**
 * WooCommerce Donation Handler.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use NetterTechEvents\Contracts\DonationLedgerInterface;

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
	 * Reads an order's donation, refunds included.
	 *
	 * @var DonationLedgerInterface
	 */
	private DonationLedgerInterface $ledger;

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
	 * Cart fee id of the donation.
	 *
	 * Set explicitly because WooCommerce otherwise derives the id from the
	 * fee name, which is translated.
	 *
	 * @var string
	 */
	public const FEE_ID = 'nettertech_events_donation';

	/**
	 * Order fee item meta marking the line as the donation.
	 *
	 * @var string
	 */
	public const FEE_ITEM_FLAG_META = '_nettertech_events_donation';

	/**
	 * Order fee item meta holding the donation type (roundup, fixed, custom).
	 *
	 * @var string
	 */
	public const FEE_ITEM_TYPE_META = '_nettertech_events_donation_type';

	/**
	 * Priority of calculate_donation_fee() on woocommerce_cart_calculate_fees.
	 *
	 * Late, so discounts and fees added at the default priority (Pro's promo
	 * codes among them) are already in the cart when round-up is worked out.
	 * Fee coverage that must include the donation belongs after this.
	 *
	 * @var int
	 */
	public const FEE_PRIORITY = 1000;

	/**
	 * Constructor.
	 *
	 * @param CartHandler                                 $cart_handler       Cart handler instance.
	 * @param \NetterTechEvents\Services\RateLimitService $rate_limit_service Rate limit service.
	 * @param DonationLedgerInterface|null                $ledger             Donation reader; a DonationLedger when omitted.
	 */
	public function __construct( CartHandler $cart_handler, \NetterTechEvents\Services\RateLimitService $rate_limit_service, ?DonationLedgerInterface $ledger = null ) {
		$this->cart_handler       = $cart_handler;
		$this->rate_limit_service = $rate_limit_service;
		$this->ledger             = $ledger ?? new DonationLedger();
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
		$current_amount = $this->get_session_donation();
		$current_type   = $this->get_session_donation_type();

		?>
		<div class="nte-donation-field" id="nte-donation-field">
			<h3 class="nte-donation-field__title" id="nte-donation-field-title">
				<span class="nte-donation-field__heart" aria-hidden="true">&#10084;</span>
				<?php esc_html_e( 'Add a Donation', 'nettertech-events' ); ?>
			</h3>
			<p class="nte-donation-field__cause"><?php echo esc_html( $settings['cause'] ); ?></p>

			<div class="nte-donation-field__options" role="radiogroup" aria-labelledby="nte-donation-field-title">
				<!-- Round-up option -->
				<label class="nte-donation-field__option">
					<input type="radio" name="nettertech_events_donation_option" class="nte-donation-option"
							value="roundup" data-amount="0"
							<?php checked( $current_type, 'roundup' ); ?>>
					<?php echo $this->render_roundup_label(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_roundup_label(). ?>
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
								step="1" placeholder="0"
								aria-label="<?php esc_attr_e( 'Donation amount', 'nettertech-events' ); ?>">
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
	 * The round-up label, rounded total and amount.
	 *
	 * Rendered in the donation field and re-sent as a checkout fragment, so
	 * both come from the same markup.
	 *
	 * @return string Escaped HTML.
	 */
	private function render_roundup_label(): string {
		$basis  = $this->get_roundup_basis( WC()->cart );
		$amount = $this->get_roundup_amount( $basis );

		return '<span class="nte-donation-field__label nte-donation-field__roundup-label">'
			. sprintf(
				/* translators: 1: rounded total, 2: donation amount */
				esc_html__( 'Round up to %1$s (+%2$s)', 'nettertech-events' ),
				wp_kses_post( wc_price( $basis + $amount ) ),
				wp_kses_post( wc_price( $amount ) )
			)
			. '</span>';
	}

	/**
	 * Send the round-up label with each classic-checkout refresh.
	 *
	 * Hooked to woocommerce_update_order_review_fragments. The donation field
	 * sits outside the order review WooCommerce re-renders, so without this
	 * the label would keep the totals from page load after a coupon, promo or
	 * shipping change.
	 *
	 * @param array<string, string> $fragments Selector => HTML.
	 * @return array<string, string>
	 */
	public function add_roundup_label_fragment( array $fragments ): array {
		if ( ! $this->is_enabled() ) {
			return $fragments;
		}

		$fragments['.nte-donation-field__roundup-label'] = $this->render_roundup_label();
		return $fragments;
	}

	/**
	 * What the buyer pays before the donation.
	 *
	 * Contents after coupons, their tax, shipping and its tax, plus every fee
	 * already added except the donation. Tax on taxable fees isn't calculated
	 * until after fees are added, so it is not included.
	 *
	 * @param \WC_Cart $cart Cart with item and shipping totals calculated.
	 * @return float Never below zero.
	 */
	public function get_roundup_basis( \WC_Cart $cart ): float {
		$basis = (float) $cart->get_cart_contents_total()
			+ (float) $cart->get_cart_contents_tax()
			+ (float) $cart->get_shipping_total()
			+ (float) $cart->get_shipping_tax();

		foreach ( $cart->fees_api()->get_fees() as $fee ) {
			if ( self::FEE_ID !== $fee->id ) {
				$basis += (float) $fee->amount;
			}
		}

		return max( 0.0, round( $basis, 2 ) );
	}

	/**
	 * Calculate and add donation fee to cart.
	 *
	 * Hooked to woocommerce_cart_calculate_fees at FEE_PRIORITY. A round-up is
	 * worked out here from the current totals on every calculation, so it
	 * follows coupons, promos, tax and shipping; the session keeps the result
	 * so the order meta records what was charged.
	 *
	 * @param \WC_Cart|null $cart Cart being calculated; WC()->cart when omitted.
	 * @return void
	 */
	public function calculate_donation_fee( $cart = null ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! $this->is_enabled() ) {
			return;
		}

		$cart = $cart instanceof \WC_Cart ? $cart : WC()->cart;

		if ( 'roundup' === $this->get_session_donation_type() ) {
			$this->set_session_donation( $this->get_roundup_amount( $this->get_roundup_basis( $cart ) ), 'roundup' );
		}

		$donation_amount = $this->get_session_donation();

		if ( $donation_amount > 0 ) {
			$cart->fees_api()->add_fee(
				array(
					'id'      => self::FEE_ID,
					'name'    => __( 'Donation', 'nettertech-events' ),
					'amount'  => $donation_amount,
					'taxable' => false,
				)
			);
		}
	}

	/**
	 * Tag the donation's order fee line as it is created.
	 *
	 * Hooked to woocommerce_checkout_create_order_fee_item, which both the
	 * classic and the Store API checkouts fire. The tag is how the donation
	 * line is found later: its name is translated and can be edited.
	 *
	 * @param \WC_Order_Item_Fee $item    Fee line being added to the order.
	 * @param int|string         $fee_key Cart fee id.
	 * @return void
	 */
	public function tag_donation_fee_item( \WC_Order_Item_Fee $item, $fee_key ): void {
		if ( self::FEE_ID !== (string) $fee_key ) {
			return;
		}

		$item->add_meta_data( self::FEE_ITEM_FLAG_META, '1', true );

		$type = $this->get_session_donation_type();
		if ( '' !== $type ) {
			$item->add_meta_data( self::FEE_ITEM_TYPE_META, $type, true );
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
				$this->persist_donation( $order, $donation_amount, $donation_type );
			}
		}

		// Clear session after saving.
		$this->clear_session_donation();
	}

	/**
	 * Save donation metadata to an order created through the Store API.
	 *
	 * Block checkout never fires woocommerce_checkout_update_order_meta, so the
	 * classic path above does not run for it. Hooked to
	 * woocommerce_store_api_checkout_update_order_from_request, which hands over
	 * the order object before it is saved.
	 *
	 * The session is left intact when there is no donation so an unrelated
	 * Store API request cannot wipe a pending amount.
	 *
	 * @param \WC_Order $order Order being built from the Store API request.
	 * @return void
	 */
	public function save_donation_meta_from_store_api( \WC_Order $order ): void {
		$donation_amount = $this->get_session_donation();

		if ( $donation_amount <= 0 ) {
			return;
		}

		$this->persist_donation( $order, $donation_amount, $this->get_session_donation_type() );
		$this->clear_session_donation();
	}

	/**
	 * Write donation meta to an order.
	 *
	 * Single persistence path for the classic and Store API checkouts so the
	 * two cannot drift on key names or value shapes.
	 *
	 * @param \WC_Order $order  Order to write to.
	 * @param float     $amount Donation amount.
	 * @param string    $type   Donation type (roundup, fixed, custom).
	 * @return void
	 */
	private function persist_donation( \WC_Order $order, float $amount, string $type ): void {
		$order->update_meta_data( '_nettertech_events_donation_amount', (string) $amount );
		$order->update_meta_data( '_nettertech_events_donation_type', $type );
		$order->save();
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

		// Context validation BEFORE nonce check: if donations aren't enabled,
		// fail fast without revealing nonce validity. The feature state is
		// visible in the checkout UI; nonce validity is not.
		if ( ! $this->is_enabled() ) {
			wp_send_json_error(
				array( 'message' => __( 'Donations are not enabled.', 'nettertech-events' ) ),
				403
			);
		}

		check_ajax_referer( 'nettertech_events_donation_nonce', 'nonce' );

		$option = isset( $_POST['option'] ) ? sanitize_text_field( wp_unslash( $_POST['option'] ) ) : 'none';
		$amount = isset( $_POST['amount'] ) ? floatval( wp_unslash( $_POST['amount'] ) ) : 0;

		$this->apply_donation_choice( $option, $amount );

		if ( 'roundup' === $option ) {
			// The response reports the amount, which only exists once the cart
			// has been calculated.
			WC()->cart->calculate_totals();
		}

		wp_send_json_success(
			array(
				'amount' => $this->get_session_donation(),
				'type'   => $this->get_session_donation_type(),
			)
		);
	}

	/**
	 * Store a buyer's donation choice in the session.
	 *
	 * Shared by the classic AJAX handler and the block checkout's Store API
	 * callback, so both checkouts accept exactly the same choices. A round-up
	 * stores only its type: calculate_donation_fee() works out the amount on
	 * the next cart calculation.
	 *
	 * @param string $option roundup, fixed, custom; anything else clears the donation.
	 * @param float  $amount Amount for fixed and custom; capped by set_session_donation().
	 * @return void
	 */
	public function apply_donation_choice( string $option, float $amount ): void {
		switch ( $option ) {
			case 'roundup':
				$this->set_session_donation( 0, 'roundup' );
				break;

			case 'fixed':
			case 'custom':
				$this->set_session_donation( $amount, $option );
				break;

			default:
				$this->clear_session_donation();
				break;
		}
	}

	/**
	 * Apply a donation choice sent by the block checkout.
	 *
	 * Called from the Store API cart/extensions update callback, which has
	 * already verified the Store API nonce and recalculates the cart once this
	 * returns. Rate limiting and eligibility match handle_ajax_update().
	 *
	 * @param array<string, mixed> $data Extension data: donation_option, donation_amount.
	 * @return void
	 * @throws RouteException 429 when rate-limited, 403 when this cart cannot take a donation.
	 */
	public function handle_store_api_update( array $data ): void {
		if ( ! $this->rate_limit_service->should_bypass() && $this->rate_limit_service->check_and_increment() ) {
			throw new RouteException(
				'nettertech_events_donation_rate_limited',
				esc_html__( 'Too many requests. Please try again later.', 'nettertech-events' ),
				429
			);
		}

		if ( ! $this->is_enabled() ) {
			throw new RouteException(
				'nettertech_events_donation_unavailable',
				esc_html__( 'Donations are not enabled.', 'nettertech-events' ),
				403
			);
		}

		$option = isset( $data['donation_option'] ) && is_string( $data['donation_option'] ) ? sanitize_key( $data['donation_option'] ) : 'none';
		$amount = isset( $data['donation_amount'] ) && is_numeric( $data['donation_amount'] ) ? (float) $data['donation_amount'] : 0.0;

		$this->apply_donation_choice( $option, $amount );
	}

	/**
	 * The block checkout picker's data for the current cart.
	 *
	 * Store API cart extension data, rebuilt with every cart response, so the
	 * picker appears only for carts with tickets and its round-up label always
	 * matches the totals the buyer sees. Prices are formatted here, as plain
	 * text, so the block and classic pickers word them the same way.
	 *
	 * @return array<string, mixed>
	 */
	public function get_store_api_data(): array {
		$data = array(
			'available'       => false,
			'cause'           => '',
			'selected'        => 'none',
			'amount'          => 0.0,
			'roundup_label'   => '',
			'presets'         => array(),
			'allow_custom'    => false,
			'max'             => 0.0,
			'currency_symbol' => '',
		);

		$cart = WC()->cart;
		if ( ! $cart instanceof \WC_Cart || ! $this->is_enabled() ) {
			return $data;
		}

		$settings = $this->get_settings();
		$basis    = $this->get_roundup_basis( $cart );
		$roundup  = $this->get_roundup_amount( $basis );
		$type     = $this->get_session_donation_type();

		$presets = array();
		foreach ( $settings['presets'] as $preset ) {
			$presets[] = array(
				'amount' => (float) $preset,
				'label'  => self::price_text( (float) $preset ),
			);
		}

		return array(
			'available'       => true,
			'cause'           => (string) $settings['cause'],
			'selected'        => '' === $type ? 'none' : $type,
			'amount'          => $this->get_session_donation(),
			'roundup_label'   => sprintf(
				/* translators: 1: rounded total, 2: donation amount */
				__( 'Round up to %1$s (+%2$s)', 'nettertech-events' ),
				self::price_text( $basis + $roundup ),
				self::price_text( $roundup )
			),
			'presets'         => $presets,
			'allow_custom'    => (bool) $settings['allow_custom'],
			'max'             => (float) $settings['max_donation'],
			'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
		);
	}

	/**
	 * Schema of get_store_api_data().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_store_api_schema(): array {
		$field = static function ( string $type, string $description ): array {
			return array(
				'description' => $description,
				'type'        => $type,
				'readonly'    => true,
			);
		};

		return array(
			'available'       => $field( 'boolean', __( 'Whether this cart can take a donation.', 'nettertech-events' ) ),
			'cause'           => $field( 'string', __( 'What donations support.', 'nettertech-events' ) ),
			'selected'        => $field( 'string', __( 'Chosen donation option: roundup, fixed, custom or none.', 'nettertech-events' ) ),
			'amount'          => $field( 'number', __( 'Donation amount in the cart.', 'nettertech-events' ) ),
			'roundup_label'   => $field( 'string', __( 'Round-up option label with the rounded total and amount.', 'nettertech-events' ) ),
			'presets'         => array(
				'description' => __( 'Preset donation amounts.', 'nettertech-events' ),
				'type'        => 'array',
				'readonly'    => true,
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'amount' => $field( 'number', __( 'Preset amount.', 'nettertech-events' ) ),
						'label'  => $field( 'string', __( 'Formatted preset amount.', 'nettertech-events' ) ),
					),
				),
			),
			'allow_custom'    => $field( 'boolean', __( 'Whether buyers may enter their own amount.', 'nettertech-events' ) ),
			'max'             => $field( 'number', __( 'Largest donation accepted.', 'nettertech-events' ) ),
			'currency_symbol' => $field( 'string', __( 'Store currency symbol.', 'nettertech-events' ) ),
		);
	}

	/**
	 * A price as plain text, formatted the way wc_price() formats it.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function price_text( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Display the order's donation on the order edit screen.
	 *
	 * Figures come from the ledger, so a refund of the donation line shows
	 * here as refunded and net.
	 *
	 * @param \WC_Order $order Order object.
	 * @return void
	 */
	public function display_donation_admin( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$donation = $this->ledger->for_order( $order );
		if ( null === $donation ) {
			return;
		}

		$type_labels = array(
			'roundup' => __( 'Round-up', 'nettertech-events' ),
			'fixed'   => __( 'Preset', 'nettertech-events' ),
			'custom'  => __( 'Custom', 'nettertech-events' ),
		);

		$currency = array( 'currency' => $donation->currency );
		$donated  = wc_price( $donation->amount, $currency );
		if ( isset( $type_labels[ $donation->type ] ) ) {
			$donated = sprintf(
				/* translators: 1: donation amount, 2: donation type */
				__( '%1$s (%2$s)', 'nettertech-events' ),
				$donated,
				esc_html( $type_labels[ $donation->type ] )
			);
		}

		echo '<div class="nte-donation-admin">';
		echo '<h4 class="nte-donation-admin__title">' . esc_html__( 'Donation', 'nettertech-events' ) . '</h4>';
		echo '<dl class="nte-donation-admin__figures">';
		echo '<div><dt>' . esc_html__( 'Donated', 'nettertech-events' ) . '</dt><dd>' . wp_kses_post( $donated ) . '</dd></div>';
		if ( $donation->refunded > 0 ) {
			echo '<div><dt>' . esc_html__( 'Refunded', 'nettertech-events' ) . '</dt><dd>' . wp_kses_post( wc_price( $donation->refunded, $currency ) ) . '</dd></div>';
			echo '<div><dt>' . esc_html__( 'Net', 'nettertech-events' ) . '</dt><dd><strong>' . wp_kses_post( wc_price( $donation->net, $currency ) ) . '</strong></dd></div>';
		}
		echo '</dl>';
		echo '</div>';
	}

	/**
	 * Load the donation box stylesheet on the order edit screen.
	 *
	 * Hooked to admin_enqueue_scripts. wc_get_page_screen_id() gives the
	 * order screen's id under both HPOS and the posts-table store.
	 *
	 * @return void
	 */
	public function enqueue_admin_styles(): void {
		if ( ! function_exists( 'wc_get_page_screen_id' ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || wc_get_page_screen_id( 'shop-order' ) !== $screen->id ) {
			return;
		}

		wp_enqueue_style(
			'nettertech-events-order-donation',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/css/admin/order-donation.css',
			array(),
			NETTERTECH_EVENTS_VERSION
		);
	}
}
