<?php
/**
 * WooCommerce Cart Handler.
 *
 * Orchestrates cart operations for event tickets: validation, reservation
 * management, display, and session tracking. Delegates to CartValidator
 * and CartPresenter for focused responsibilities (ADR-007).
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;

/**
 * Handles WooCommerce cart operations for event tickets.
 *
 * Validates capacity, displays event details in cart, and
 * stores ticket metadata on order items.
 *
 * @since 0.8.0
 * @api
 */
class CartHandler {

	/**
	 * Product manager.
	 *
	 * @var ProductManager
	 */
	private ProductManager $product_manager;

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Capacity service.
	 *
	 * @var CapacityServiceInterface
	 */
	private CapacityServiceInterface $capacity_service;

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Cart validator.
	 *
	 * @var CartValidator
	 */
	private CartValidator $validator;

	/**
	 * Cart presenter.
	 *
	 * @var CartPresenter
	 */
	private CartPresenter $presenter;

	/**
	 * Constructor.
	 *
	 * @param TicketTypeRepositoryInterface $ticket_type_repo  Ticket type repository.
	 * @param CapacityServiceInterface      $capacity_service  Capacity service.
	 * @param OccurrenceRepositoryInterface $occurrence_repo   Occurrence repository.
	 * @param ProductManager                $product_manager   Product manager.
	 */
	public function __construct(
		TicketTypeRepositoryInterface $ticket_type_repo,
		CapacityServiceInterface $capacity_service,
		OccurrenceRepositoryInterface $occurrence_repo,
		ProductManager $product_manager
	) {
		$this->product_manager  = $product_manager;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;
		$this->occurrence_repo  = $occurrence_repo;

		$this->validator = new CartValidator(
			$this->ticket_type_repo,
			$this->capacity_service,
			$this->occurrence_repo
		);
		$this->presenter = new CartPresenter( $this->product_manager );
	}

	/**
	 * Validate ticket purchase without side effects.
	 *
	 * @param TicketType $ticket_type    Ticket type being purchased.
	 * @param Occurrence $occurrence     Event occurrence.
	 * @param int        $quantity       Quantity being added.
	 * @param int        $cart_quantity  Quantity already in cart for this ticket type.
	 * @param int|null   $user_pending   User's existing pending reservation count, or null
	 *                                   to fall back to $cart_quantity for net calculation.
	 * @return array{valid: bool, error: string|null, error_code: string|null}
	 */
	public function validate_ticket_purchase(
		TicketType $ticket_type,
		Occurrence $occurrence,
		int $quantity,
		int $cart_quantity = 0,
		?int $user_pending = null
	): array {
		return $this->validator->validate_ticket_purchase( $ticket_type, $occurrence, $quantity, $cart_quantity, $user_pending );
	}

	/**
	 * Re-check what is already in the cart, on the classic cart and checkout pages.
	 *
	 * A ticket is validated when it goes into the cart and, until this existed, never again — and
	 * WooCommerce keeps a signed-in shopper's cart for days. So a ticket added while its sale window
	 * was open went on being purchasable after that window shut, at the price it had when it was
	 * added. Reproduced before this was written: an early-bird ticket checked out five minutes past
	 * its cutoff, at the early-bird price, with no error.
	 *
	 * Both directions of that are wrong. It is a revenue leak anyone can farm — fill a cart before
	 * the price rises, check out whenever — and worse, it sells a tier an operator has deliberately
	 * withdrawn.
	 *
	 * WooCommerce's own stock gate does not cover it. Stock tracks capacity, not the clock: a tier
	 * closed by a date still has seats, so `_stock` is healthy and Woo waves it through. (It does
	 * happen to catch a tier whose *quantity* ran out, which is why only the date-driven case
	 * leaked, and why the regression test aims squarely at the date.)
	 *
	 * The rule itself needed nothing. It was already right, and already timezone-aware. It was
	 * simply never asked a second time.
	 *
	 * @return void
	 */
	public function check_cart_items(): void {
		$cart = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart : null;

		if ( null === $cart ) {
			return;
		}

		foreach ( $this->unsellable_items( $cart ) as $message ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * The same re-check, for the block checkout.
	 *
	 * The Store API does still fire `woocommerce_check_cart_items`, and does still turn the notices
	 * it collects into errors — but its own docblock calls that path legacy and says it is on the
	 * way out. A block checkout is what most shops now use, and it is what the leak above was
	 * reproduced through, so the fix does not rest on a hook WooCommerce has announced it is
	 * retiring. This is the supported way to refuse a block cart, and it reports the same findings.
	 *
	 * @param \WP_Error $errors Errors to add to.
	 * @param \WC_Cart  $cart   The cart being validated.
	 * @return void
	 */
	public function collect_store_api_errors( \WP_Error $errors, \WC_Cart $cart ): void {
		foreach ( $this->unsellable_items( $cart ) as $code => $message ) {
			$errors->add( is_string( $code ) ? 'nettertech_events_' . $code : 'nettertech_events_ticket_unavailable', $message );
		}
	}

	/**
	 * Everything in the cart that could not be bought if the shopper tried now.
	 *
	 * The shopper's own reservation is passed back in, so they are never refused on account of the
	 * seats they are themselves holding — only on account of a window that has closed, an event that
	 * has ended, or a room that filled up while the cart sat.
	 *
	 * @param \WC_Cart $cart The cart to examine.
	 * @return array<string, string> Reason code to message.
	 */
	private function unsellable_items( \WC_Cart $cart ): array {
		$problems = array();

		foreach ( $cart->get_cart() as $cart_item ) {
			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;

			if ( ! $product_id || ! $this->product_manager->is_event_ticket( $product_id ) ) {
				continue;
			}

			$quantity    = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
			$ticket_type = $this->product_manager->get_ticket_type_from_product( $product_id );
			$occurrence  = $this->product_manager->get_occurrence_from_product( $product_id );

			// A series pass has no occurrence of its own; anchor its date checks to
			// the next upcoming one, as the add-to-cart gate does (NTE-156).
			if ( ! $occurrence && $ticket_type && null !== $ticket_type->event_id ) {
				$occurrence = $this->occurrence_repo->next_for_event( (int) $ticket_type->event_id );
			}

			$ticket_type_id = $ticket_type ? $ticket_type->id : null;

			if ( ! $ticket_type || ! $occurrence || null === $ticket_type_id || $quantity < 1 ) {
				$problems['ticket_unavailable'] = __( 'A ticket in your cart is no longer available. Please remove it to continue.', 'nettertech-events' );
				continue;
			}

			$validation = $this->validator->validate_ticket_purchase(
				$ticket_type,
				$occurrence,
				$quantity,
				0,
				$this->capacity_service->get_pending_reservation( $ticket_type_id, $this->get_session_key() )
			);

			if ( $validation['valid'] ) {
				continue;
			}

			$code = $validation['error_code'] ?? 'ticket_unavailable';

			// Named, and with the reason kept: a shopper told only "something in your cart is wrong"
			// at the payment step has been given a dead end, not an explanation.
			$problems[ $code ] = sprintf(
				/* translators: 1: ticket type name, 2: the reason it cannot be sold. */
				__( '%1$s can no longer be purchased. %2$s Please remove it from your cart to continue.', 'nettertech-events' ),
				$ticket_type->name,
				$validation['error'] ?? ''
			);
		}

		return $problems;
	}

	/**
	 * Validate add to cart for event tickets.
	 *
	 * Checks:
	 * - Event has not passed
	 * - Ticket type is still on sale
	 * - Capacity is available
	 *
	 * @param bool                 $passed       Current validation status.
	 * @param int                  $product_id   Product ID being added.
	 * @param int                  $quantity     Quantity being added.
	 * @param int                  $variation_id Variation ID (required by WooCommerce filter).
	 * @param array<string, mixed> $variations   Variation attributes (required by WooCommerce filter).
	 * @return bool
	 */
	public function validate_add_to_cart( bool $passed, int $product_id, int $quantity, int $variation_id = 0, array $variations = array() ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		if ( ! $passed ) {
			return false;
		}

		if ( ! $this->product_manager->is_event_ticket( $product_id ) ) {
			return $passed;
		}

		$ticket_type = $this->product_manager->get_ticket_type_from_product( $product_id );
		$occurrence  = $this->product_manager->get_occurrence_from_product( $product_id );

		// A series pass carries no occurrence of its own; its date checks anchor to
		// the next upcoming date, and none remaining means nothing left to admit
		// to (NTE-156). Capacity comes from the pass-aware calculator either way.
		if ( ! $occurrence && $ticket_type && null !== $ticket_type->event_id ) {
			$occurrence = $this->occurrence_repo->next_for_event( (int) $ticket_type->event_id );
		}

		$ticket_type_id = $ticket_type ? $ticket_type->id : null;
		if ( ! $ticket_type || ! $occurrence || null === $ticket_type_id ) {
			wc_add_notice(
				__( 'This ticket is no longer available.', 'nettertech-events' ),
				'error'
			);
			return false;
		}

		// Gather context for validation.
		$cart_quantity = $this->validator->get_cart_quantity_for_ticket_type( $ticket_type_id );
		$session_key   = $this->get_session_key();
		$user_pending  = $this->capacity_service->get_pending_reservation( $ticket_type_id, $session_key );

		// Pure validation — no side effects.
		$validation = $this->validator->validate_ticket_purchase(
			$ticket_type,
			$occurrence,
			$quantity,
			$cart_quantity,
			$user_pending
		);

		if ( ! $validation['valid'] ) {
			wc_add_notice( $validation['error'] ?? __( 'This ticket is no longer available.', 'nettertech-events' ), 'error' );
			return false;
		}

		// Side effects — only executed when validation passes.
		$total_quantity = $cart_quantity + $quantity;

		$this->capacity_service->create_pending_reservation(
			$ticket_type_id,
			$total_quantity,
			$session_key
		);

		$this->track_ticket_type( $ticket_type_id );

		return true;
	}

	/**
	 * Get unique session key for capacity reservations.
	 *
	 * Uses WooCommerce session or generates a fallback.
	 *
	 * @return string
	 */
	private function get_session_key(): string {
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( $wc && isset( $wc->session ) && $wc->session ) {
			$customer_id = $wc->session->get_customer_id();
			if ( $customer_id ) {
				return 'nettertech_events_cart_' . $customer_id;
			}
		}

		// Fallback to cookie-based key with strict validation.
		// Cart hash is an MD5 (32 hex chars) - validate to prevent injection.
		if ( isset( $_COOKIE['woocommerce_cart_hash'] ) ) {
			$hash = sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_cart_hash'] ) );
			if ( preg_match( '/^[a-f0-9]{32}$/i', $hash ) ) {
				return 'nettertech_events_cart_' . $hash;
			}
		}

		// Last resort: use PHP session ID.
		if ( session_id() ) {
			return 'nettertech_events_cart_' . session_id();
		}

		return 'nettertech_events_cart_' . wp_generate_uuid4();
	}

	/**
	 * Handle cart item removal - release pending reservation.
	 *
	 * @param string   $cart_item_key Removed item key.
	 * @param \WC_Cart $cart       Cart instance.
	 * @return void
	 */
	public function handle_cart_item_removed( string $cart_item_key, \WC_Cart $cart ): void {
		$removed_item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;

		if ( ! $removed_item ) {
			return;
		}

		$product = $removed_item['data'] ?? null;

		if ( ! $product || ! $this->product_manager->is_event_ticket( $product ) ) {
			return;
		}

		$ticket_type    = $this->product_manager->get_ticket_type_from_product( $product );
		$ticket_type_id = $ticket_type ? $ticket_type->id : null;

		if ( null === $ticket_type_id ) {
			return;
		}

		// Clear or reduce the pending reservation.
		$session_key        = $this->get_session_key();
		$remaining_quantity = $this->validator->get_cart_quantity_for_ticket_type( $ticket_type_id );

		if ( $remaining_quantity > 0 ) {
			// Update reservation with remaining quantity.
			$this->capacity_service->create_pending_reservation(
				$ticket_type_id,
				$remaining_quantity,
				$session_key
			);
		} else {
			// Clear reservation entirely.
			$this->capacity_service->clear_pending_reservation( $ticket_type_id, $session_key );
		}
	}

	/**
	 * Handle cart quantity update - adjust pending reservation.
	 *
	 * @param string $cart_item_key Item key.
	 * @param int    $quantity      New quantity (required by WooCommerce action).
	 * @return void
	 */
	public function handle_cart_quantity_update( string $cart_item_key, int $quantity ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		if ( ! WC()->cart ) {
			return;
		}

		$cart_item = WC()->cart->get_cart_item( $cart_item_key );

		if ( ! $cart_item ) {
			return;
		}

		$product = $cart_item['data'] ?? null;

		if ( ! $product || ! $this->product_manager->is_event_ticket( $product ) ) {
			return;
		}

		$ticket_type    = $this->product_manager->get_ticket_type_from_product( $product );
		$ticket_type_id = $ticket_type ? $ticket_type->id : null;

		if ( null === $ticket_type_id ) {
			return;
		}

		$session_key    = $this->get_session_key();
		$total_quantity = $this->validator->get_cart_quantity_for_ticket_type( $ticket_type_id );

		if ( $total_quantity > 0 ) {
			$this->capacity_service->create_pending_reservation(
				$ticket_type_id,
				$total_quantity,
				$session_key
			);
		} else {
			$this->capacity_service->clear_pending_reservation( $ticket_type_id, $session_key );
		}
	}

	/**
	 * Handle cart emptied - clear all pending reservations for this session.
	 *
	 * Clears ticket type reservations tracked in session for immediate
	 * capacity release rather than waiting for transient TTL expiration.
	 *
	 * @return void
	 */
	public function handle_cart_emptied(): void {
		$session_key = $this->get_session_key();

		// Get tracked ticket types from session.
		$tracked_types = $this->get_tracked_ticket_types();

		foreach ( $tracked_types as $ticket_type_id ) {
			$this->capacity_service->clear_pending_reservation(
				(int) $ticket_type_id,
				$session_key
			);
		}

		// Clear the tracking.
		$this->clear_tracked_ticket_types();
	}

	/**
	 * Track a ticket type ID in session for cleanup purposes.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return void
	 */
	private function track_ticket_type( int $ticket_type_id ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$tracked = WC()->session->get( 'nettertech_events_tracked_ticket_types', array() );

		if ( ! in_array( $ticket_type_id, $tracked, true ) ) {
			$tracked[] = $ticket_type_id;
			WC()->session->set( 'nettertech_events_tracked_ticket_types', $tracked );
		}
	}

	/**
	 * Get tracked ticket type IDs from session.
	 *
	 * @return array<int>
	 */
	private function get_tracked_ticket_types(): array {
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( ! $wc || ! $wc->session ) {
			return array();
		}

		return $wc->session->get( 'nettertech_events_tracked_ticket_types', array() );
	}

	/**
	 * Clear tracked ticket types from session.
	 *
	 * @return void
	 */
	private function clear_tracked_ticket_types(): void {
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( $wc && $wc->session ) {
			$wc->session->set( 'nettertech_events_tracked_ticket_types', array() );
		}
	}

	/**
	 * Modify cart item name to include event details.
	 *
	 * @param string               $name          Product name.
	 * @param array<string, mixed> $cart_item Cart item data.
	 * @param string               $cart_item_key Cart item key (required by WooCommerce filter).
	 * @return string
	 */
	public function modify_cart_item_name( string $name, array $cart_item, string $cart_item_key ): string {
		return $this->presenter->modify_cart_item_name( $name, $cart_item, $cart_item_key );
	}

	/**
	 * Display event details in cart item data.
	 *
	 * @param array<int, array<string, string>> $item_data Existing item data.
	 * @param array<string, mixed>              $cart_item Cart item.
	 * @return array<int, array<string, string>>
	 */
	public function display_cart_item_data( array $item_data, array $cart_item ): array {
		return $this->presenter->display_cart_item_data( $item_data, $cart_item );
	}

	/**
	 * Add NetterTechEvents meta to order line items.
	 *
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array<string, mixed>   $values        Cart item values.
	 * @param \WC_Order              $order         Order object (required by WooCommerce action).
	 * @return void
	 */
	public function add_order_item_meta( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ): void {
		$this->presenter->add_order_item_meta( $item, $cart_item_key, $values, $order );
	}

	/**
	 * Add ticket to cart programmatically.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity.
	 * @return bool True on success.
	 */
	public function add_ticket_to_cart( int $ticket_type_id, int $quantity = 1 ): bool {
		$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );

		if ( ! $ticket_type || ! $ticket_type->wc_product_id ) {
			return false;
		}

		try {
			WC()->cart->add_to_cart( $ticket_type->wc_product_id, $quantity );
			return true;
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only.
				error_log( 'NetterTechEvents: Failed to add ticket to WooCommerce cart - ' . $e->getMessage() );
			}
			return false;
		}
	}

	/**
	 * Validate multiple ticket types for batch cart addition.
	 *
	 * @param array<int, int> $tickets Map of ticket_type_id => quantity.
	 * @return array{valid: bool, errors: array<int, string>, validated: array<int, int>}
	 */
	public function validate_batch( array $tickets ): array {
		return $this->validator->validate_batch( $tickets );
	}

	/**
	 * Get quantity of a ticket type currently in cart.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int
	 */
	public function get_cart_quantity_for_ticket_type( int $ticket_type_id ): int {
		return $this->validator->get_cart_quantity_for_ticket_type( $ticket_type_id );
	}

	/**
	 * Get quantity of a ticket type from a cart object.
	 *
	 * @param \WC_Cart $cart           Cart object.
	 * @param int      $ticket_type_id Ticket type ID.
	 * @return int
	 */
	public function get_cart_quantity_for_ticket_type_from_cart( \WC_Cart $cart, int $ticket_type_id ): int {
		return $this->validator->get_cart_quantity_for_ticket_type_from_cart( $cart, $ticket_type_id );
	}

	/**
	 * Get total tickets in cart for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int
	 */
	public function get_cart_quantity_for_occurrence( int $occurrence_id ): int {
		if ( ! WC()->cart ) {
			return 0;
		}

		return $this->get_cart_quantity_for_occurrence_from_cart( WC()->cart, $occurrence_id );
	}

	/**
	 * Get total tickets in cart for an occurrence from a cart object.
	 *
	 * @param \WC_Cart $cart          Cart object.
	 * @param int      $occurrence_id Occurrence ID.
	 * @return int
	 */
	public function get_cart_quantity_for_occurrence_from_cart( \WC_Cart $cart, int $occurrence_id ): int {
		$quantity = 0;

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;

			if ( ! $product || ! $this->product_manager->is_event_ticket( $product ) ) {
				continue;
			}

			$occurrence = $this->product_manager->get_occurrence_from_product( $product );

			if ( $occurrence && $occurrence->id === $occurrence_id ) {
				$quantity += (int) $cart_item['quantity'];
			}
		}

		return $quantity;
	}

	/**
	 * Check if cart contains event tickets.
	 *
	 * @return bool
	 */
	public function cart_has_tickets(): bool {
		if ( ! WC()->cart ) {
			return false;
		}

		return $this->cart_has_tickets_in_cart( WC()->cart );
	}

	/**
	 * Check if a cart object contains event tickets.
	 *
	 * @param \WC_Cart $cart Cart object.
	 * @return bool
	 */
	public function cart_has_tickets_in_cart( \WC_Cart $cart ): bool {
		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;

			if ( $product && $this->product_manager->is_event_ticket( $product ) ) {
				return true;
			}
		}

		return false;
	}
}
