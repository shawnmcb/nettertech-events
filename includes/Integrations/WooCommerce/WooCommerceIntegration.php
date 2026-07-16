<?php
/**
 * WooCommerce Integration.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Frontend\TicketCartAjax;
use NetterTechEvents\Integrations\WooCommerce\Blocks\BlockIntegration;
use NetterTechEvents\Integrations\WooCommerce\Blocks\StoreApiExtension;
use NetterTechEvents\Contracts\EmailServiceInterface;
use NetterTechEvents\Contracts\TicketCodeGeneratorInterface;
use NetterTechEvents\Services\AttendeeFieldService;
use NetterTechEvents\Services\PaletteResolver;

/**
 * Main WooCommerce integration class.
 *
 * Coordinates ticketing functionality with WooCommerce:
 * - Product creation for ticket types
 * - Cart validation for event capacity
 * - Order processing to create attendee records
 *
 * @since 0.8.0
 * @api
 */
class WooCommerceIntegration {

	/**
	 * Product manager instance.
	 *
	 * @var ProductManager
	 */
	private ProductManager $product_manager;

	/**
	 * Cart handler instance.
	 *
	 * @var CartHandler
	 */
	private CartHandler $cart_handler;

	/**
	 * Order handler instance.
	 *
	 * @var OrderHandler
	 */
	private OrderHandler $order_handler;

	/**
	 * Accessibility notes handler instance.
	 *
	 * @var AccessibilityNotesHandler
	 */
	private AccessibilityNotesHandler $accessibility_handler;

	/**
	 * Donation handler instance.
	 *
	 * @var DonationHandler
	 */
	private DonationHandler $donation_handler;

	/**
	 * Attendee fields checkout handler instance.
	 *
	 * @var AttendeeFieldsCheckoutHandler
	 */
	private AttendeeFieldsCheckoutHandler $attendee_fields_handler;

	/**
	 * Per-attendee checkout handler instance.
	 *
	 * @var PerAttendeeCheckoutHandler
	 */
	private PerAttendeeCheckoutHandler $per_attendee_handler;

	/**
	 * Order failure handler instance.
	 *
	 * @var OrderFailureHandler
	 */
	private OrderFailureHandler $failure_handler;

	/**
	 * Email service instance.
	 *
	 * @var EmailServiceInterface
	 */
	private EmailServiceInterface $email_service;

	/**
	 * Whether WooCommerce is active.
	 *
	 * @var bool
	 */
	private bool $is_active = false;

	/**
	 * Injected dependencies for sub-handler construction.
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
	 * Attendee repository.
	 *
	 * @var AttendeeRepositoryInterface
	 */
	private AttendeeRepositoryInterface $attendee_repo;

	/**
	 * Ticket repository.
	 *
	 * @var TicketRepositoryInterface
	 */
	private TicketRepositoryInterface $ticket_repo;

	/**
	 * Ticket code generator.
	 *
	 * @var TicketCodeGeneratorInterface
	 */
	private TicketCodeGeneratorInterface $code_generator;

	/**
	 * Activity log service.
	 *
	 * @var ActivityLogServiceInterface
	 */
	private ActivityLogServiceInterface $activity_log;

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Attendee field repository.
	 *
	 * @var AttendeeFieldRepositoryInterface
	 */
	private AttendeeFieldRepositoryInterface $attendee_field_repo;

	/**
	 * Rate limit service.
	 *
	 * @var \NetterTechEvents\Services\RateLimitService
	 */
	private \NetterTechEvents\Services\RateLimitService $rate_limit_service;

	/**
	 * Attendee field service.
	 *
	 * @var AttendeeFieldService
	 */
	private AttendeeFieldService $field_service;

	/**
	 * Palette resolver for checkout asset enqueuing.
	 *
	 * @var PaletteResolver
	 */
	private PaletteResolver $palette_resolver;

	/**
	 * Constructor.
	 *
	 * @param TicketTypeRepositoryInterface               $ticket_type_repo    Ticket type repository.
	 * @param CapacityServiceInterface                    $capacity_service    Capacity service.
	 * @param OccurrenceRepositoryInterface               $occurrence_repo     Occurrence repository.
	 * @param AttendeeRepositoryInterface                 $attendee_repo       Attendee repository.
	 * @param TicketRepositoryInterface                   $ticket_repo         Ticket repository.
	 * @param TicketCodeGeneratorInterface                $code_generator      Ticket code generator.
	 * @param EmailServiceInterface                       $email_service       Email service.
	 * @param ProductManager                              $product_manager     Product manager.
	 * @param PaletteResolver                             $palette_resolver    Palette resolver for checkout assets.
	 * @param ActivityLogServiceInterface                 $activity_log        Activity log service.
	 * @param AttendeeFieldService                        $field_service       Attendee field service.
	 * @param EventRepositoryInterface                    $event_repo           Event repository.
	 * @param AttendeeFieldRepositoryInterface            $attendee_field_repo  Attendee field repository.
	 * @param \NetterTechEvents\Services\RateLimitService $rate_limit_service Rate limit service.
	 */
	public function __construct(
		TicketTypeRepositoryInterface $ticket_type_repo,
		CapacityServiceInterface $capacity_service,
		OccurrenceRepositoryInterface $occurrence_repo,
		AttendeeRepositoryInterface $attendee_repo,
		TicketRepositoryInterface $ticket_repo,
		TicketCodeGeneratorInterface $code_generator,
		EmailServiceInterface $email_service,
		ProductManager $product_manager,
		PaletteResolver $palette_resolver,
		ActivityLogServiceInterface $activity_log,
		AttendeeFieldService $field_service,
		EventRepositoryInterface $event_repo,
		AttendeeFieldRepositoryInterface $attendee_field_repo,
		\NetterTechEvents\Services\RateLimitService $rate_limit_service
	) {
		$this->ticket_type_repo    = $ticket_type_repo;
		$this->capacity_service    = $capacity_service;
		$this->occurrence_repo     = $occurrence_repo;
		$this->attendee_repo       = $attendee_repo;
		$this->ticket_repo         = $ticket_repo;
		$this->code_generator      = $code_generator;
		$this->email_service       = $email_service;
		$this->product_manager     = $product_manager;
		$this->palette_resolver    = $palette_resolver;
		$this->activity_log        = $activity_log;
		$this->field_service       = $field_service;
		$this->event_repo          = $event_repo;
		$this->attendee_field_repo = $attendee_field_repo;
		$this->rate_limit_service  = $rate_limit_service;
		$this->is_active           = $this->check_woocommerce_active();
	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool
	 */
	private function check_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
	}

	/**
	 * Check if integration is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->is_active;
	}

	/**
	 * Initialize the integration.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! $this->is_active ) {
			return;
		}

		// Initialize sub-handlers with shared dependencies.
		$this->cart_handler = new CartHandler(
			$this->ticket_type_repo,
			$this->capacity_service,
			$this->occurrence_repo,
			$this->product_manager
		);

		$this->order_handler = new OrderHandler(
			$this->attendee_repo,
			$this->ticket_type_repo,
			$this->ticket_repo,
			$this->code_generator,
			$this->occurrence_repo,
			$this->capacity_service,
			$this->product_manager,
			$this->field_service
		);

		$this->donation_handler        = new DonationHandler( $this->cart_handler, $this->rate_limit_service );
		$this->accessibility_handler   = new AccessibilityNotesHandler( $this->cart_handler );
		$this->attendee_fields_handler = new AttendeeFieldsCheckoutHandler(
			$this->cart_handler,
			$this->product_manager,
			$this->field_service
		);
		$this->per_attendee_handler    = new PerAttendeeCheckoutHandler(
			$this->cart_handler,
			$this->product_manager,
			$this->event_repo,
			$this->attendee_field_repo
		);
		$this->failure_handler         = new OrderFailureHandler( $this->activity_log );

		// Register batch ticket AJAX handler.
		$ticket_cart_ajax = new TicketCartAjax(
			$this->cart_handler,
			$this->rate_limit_service
		);
		$ticket_cart_ajax->register();

		// Register order failure handler (P0 billing integrity).
		$this->failure_handler->register();

		// Register hooks.
		$this->register_hooks();

		// Register WooCommerce Blocks integration (Store API + frontend scripts).
		$this->register_block_integration();

		// Register email service hooks (confirmation emails, venue notifications).
		$this->email_service->register();
	}

	/**
	 * Register WordPress/WooCommerce hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// Product manager hooks.
		add_action( 'nettertech_events_ticket_type_sync_product', array( $this->product_manager, 'sync_product' ), 10, 2 );
		add_action( 'nettertech_events_ticket_type_deleted', array( $this->product_manager, 'delete_product' ) );

		// NTE-129: re-sync mirrored product categories when an event changes
		// (its category set may have been edited). Late priority so category
		// persistence has completed.
		add_action( 'nettertech_events_event_updated', array( $this, 'resync_event_product_categories' ), 20, 1 );

		// Cart handler hooks.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this->cart_handler, 'validate_add_to_cart' ), 10, 5 );

		// And again for what is already in the cart (NTE-151). Validating a ticket only on its way
		// into the cart leaves it saleable for as long as the cart survives — which, for a signed-in
		// shopper, is days. Both surfaces: the classic pages, and the block checkout most shops now
		// use, whose own path through the legacy hook WooCommerce has said it is retiring.
		add_action( 'woocommerce_check_cart_items', array( $this->cart_handler, 'check_cart_items' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( $this->cart_handler, 'collect_store_api_errors' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_name', array( $this->cart_handler, 'modify_cart_item_name' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this->cart_handler, 'display_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( $this, 'filter_ticket_thumbnail' ), 10, 3 );
		add_filter( 'woocommerce_admin_order_item_thumbnail', array( $this, 'filter_admin_order_item_thumbnail' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this->cart_handler, 'add_order_item_meta' ), 10, 4 );

		// Cart capacity reservation hooks.
		add_action( 'woocommerce_cart_item_removed', array( $this->cart_handler, 'handle_cart_item_removed' ), 10, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this->cart_handler, 'handle_cart_quantity_update' ), 10, 2 );
		add_action( 'woocommerce_cart_emptied', array( $this->cart_handler, 'handle_cart_emptied' ) );

		// Order handler hooks.
		add_action( 'woocommerce_payment_complete', array( $this->order_handler, 'handle_payment_complete' ) );
		add_action( 'woocommerce_order_status_completed', array( $this->order_handler, 'handle_order_completed' ) );
		add_action( 'woocommerce_order_status_processing', array( $this->order_handler, 'handle_order_processing' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this->order_handler, 'handle_order_cancelled' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this->order_handler, 'handle_order_refunded' ) );

		// Handle partial refunds (when refund is created but order status doesn't change).
		add_action( 'woocommerce_refund_created', array( $this->order_handler, 'handle_refund_created' ), 10, 2 );

		// Admin hooks.
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_data_panel' ) );
		add_action( 'woocommerce_product_options_stock', array( $this, 'render_stock_notice_for_managed_products' ) );

		// Checkout hooks for accessibility notes.
		add_action( 'woocommerce_after_order_notes', array( $this->accessibility_handler, 'render_field' ) );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this->accessibility_handler, 'save_notes' ) );

		// Checkout hooks for custom attendee registration fields.
		add_action( 'woocommerce_after_order_notes', array( $this->attendee_fields_handler, 'render_fields' ), 15 );
		add_action( 'woocommerce_after_checkout_validation', array( $this->attendee_fields_handler, 'validate_fields' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this->attendee_fields_handler, 'save_fields' ), 15 );

		// Per-attendee checkout hooks (qty > 1 with collect_individual_attendees).
		add_action( 'woocommerce_after_order_notes', array( $this->per_attendee_handler, 'render_forms' ), 12 );
		add_action( 'woocommerce_after_checkout_validation', array( $this->per_attendee_handler, 'validate_forms' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this->per_attendee_handler, 'save_order_item_data' ), 20, 3 );

		// Display accessibility notes in admin order view.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this->accessibility_handler, 'display_admin' ) );

		// Donation hooks.
		add_action( 'woocommerce_after_order_notes', array( $this->donation_handler, 'render_donation_field' ), 20 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this->donation_handler, 'calculate_donation_fee' ) );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this->donation_handler, 'save_donation_meta' ), 20 );
		add_action( 'wp_ajax_nettertech_events_update_donation', array( $this->donation_handler, 'handle_ajax_update' ) );
		add_action( 'wp_ajax_nopriv_nettertech_events_update_donation', array( $this->donation_handler, 'handle_ajax_update' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this->donation_handler, 'display_donation_admin' ), 20 );

		// Enqueue checkout assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_checkout_assets' ) );
	}

	/**
	 * Register WooCommerce Blocks integration.
	 *
	 * Extends the Store API with ticket metadata and registers frontend
	 * scripts for rendering event details in Block cart/checkout.
	 *
	 * @return void
	 */
	private function register_block_integration(): void {
		add_action(
			'woocommerce_blocks_loaded',
			function (): void {
				// Extend Store API cart items with ticket metadata + custom fields.
				$store_api = new StoreApiExtension( $this->product_manager, $this->field_service );
				$store_api->register();

				$donations_enabled = $this->donation_handler->is_enabled();
				$integration       = new BlockIntegration( $donations_enabled );

				// Register for both Cart and Checkout blocks.
				add_action(
					'woocommerce_blocks_cart_block_registration',
					function ( $registry ) use ( $integration ): void {
						$registry->register( $integration );
					}
				);

				add_action(
					'woocommerce_blocks_checkout_block_registration',
					function ( $registry ) use ( $integration ): void {
						$registry->register( $integration );
					}
				);
			}
		);

		// Register update callback for accessibility notes from Block checkout.
		add_action(
			'woocommerce_blocks_loaded',
			function (): void {
				woocommerce_store_api_register_update_callback(
					array(
						'namespace' => StoreApiExtension::NAMESPACE,
						'callback'  => function ( array $data ): void {
							if ( isset( $data['accessibility_notes'] ) ) {
								WC()->session->set(
									'nettertech_events_accessibility_notes',
									sanitize_textarea_field( $data['accessibility_notes'] )
								);
							}

							// Custom attendee field data from Block checkout.
							if ( isset( $data['custom_field_data'] ) && is_string( $data['custom_field_data'] ) ) {
								WC()->session->set(
									'nettertech_events_custom_field_data',
									sanitize_text_field( $data['custom_field_data'] )
								);
							}
						},
					)
				);
			},
			20
		);

		// Save accessibility notes from Block checkout session to order meta.
		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			function ( \WC_Order $order ): void {
				$notes = WC()->session->get( 'nettertech_events_accessibility_notes', '' );
				if ( ! empty( $notes ) ) {
					$order->update_meta_data( '_nettertech_events_accessibility_notes', $notes );
				}

				// Save custom field data from Block checkout session.
				$custom_field_data = WC()->session->get( 'nettertech_events_custom_field_data', '' );
				if ( ! empty( $custom_field_data ) ) {
					$order->update_meta_data( MetaKeys::CUSTOM_FIELD_DATA, $custom_field_data );
				}
			}
		);
	}

	/**
	 * Enqueue checkout assets when donations are enabled.
	 *
	 * @return void
	 */
	public function maybe_enqueue_checkout_assets(): void {
		if ( ! $this->donation_handler->is_enabled() ) {
			return;
		}

		$assets = new \NetterTechEvents\Core\Assets( $this->palette_resolver, \NetterTechEvents\Core\NetterTechEventsSettings::from_option() );
		$assets->enqueue_checkout();
	}

	/**
	 * Get the product manager.
	 *
	 * @return ProductManager
	 */
	public function get_product_manager(): ProductManager {
		return $this->product_manager;
	}

	/**
	 * Get the cart handler.
	 *
	 * @return CartHandler
	 */
	public function get_cart_handler(): CartHandler {
		return $this->cart_handler;
	}

	/**
	 * Get the order handler.
	 *
	 * @return OrderHandler
	 */
	public function get_order_handler(): OrderHandler {
		return $this->order_handler;
	}

	/**
	 * Get the donation handler.
	 *
	 * @return DonationHandler
	 */
	public function get_donation_handler(): DonationHandler {
		return $this->donation_handler;
	}

	/**
	 * Get the email service.
	 *
	 * @return EmailServiceInterface
	 */
	public function get_email_service(): EmailServiceInterface {
		return $this->email_service;
	}

	/**
	 * Re-sync mirrored product categories for an event's ticket products (NTE-129).
	 *
	 * Hooked to {@see Hooks::EVENT_UPDATED} so a change to the event's category
	 * set propagates to its hidden ticket products' `product_cat` assignments.
	 *
	 * @param int|mixed $event_id Event ID (action passes the ID first).
	 * @return void
	 */
	public function resync_event_product_categories( $event_id ): void {
		$event_id = (int) $event_id;
		if ( $event_id > 0 ) {
			$this->product_manager->resync_event_product_categories( $event_id );
		}
	}

	/**
	 * Add nettertech events tab to product data.
	 *
	 * Thin facade over {@see WCProductDataPanelRenderer::add_product_data_tab()}.
	 *
	 * @param array<string, array<string, mixed>> $tabs Existing tabs.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_product_data_tab( array $tabs ): array {
		return $this->product_data_renderer()->add_product_data_tab( $tabs );
	}

	/**
	 * Render product data panel.
	 *
	 * Thin facade over {@see WCProductDataPanelRenderer::render_product_data_panel()}.
	 *
	 * @return void
	 */
	public function render_product_data_panel(): void {
		$this->product_data_renderer()->render_product_data_panel();
	}

	/**
	 * Render a stock management notice for NTE-managed products.
	 *
	 * Hides WC stock fields and displays an informational notice with a link
	 * to the NTE event editor where capacity is actually managed.
	 *
	 * Hooked to `woocommerce_product_options_stock`. Thin facade over
	 * {@see WCProductDataPanelRenderer::render_stock_notice_for_managed_products()}.
	 *
	 * @return void
	 */
	public function render_stock_notice_for_managed_products(): void {
		$this->product_data_renderer()->render_stock_notice_for_managed_products();
	}

	/**
	 * Lazily build the WCProductDataPanelRenderer collaborator.
	 *
	 * @return WCProductDataPanelRenderer
	 */
	private function product_data_renderer(): WCProductDataPanelRenderer {
		return new WCProductDataPanelRenderer( $this->product_manager );
	}

	/**
	 * Add to cart for a ticket type.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $quantity       Quantity.
	 * @return bool True on success.
	 */
	public function add_to_cart( int $ticket_type_id, int $quantity = 1 ): bool {
		if ( ! $this->is_active ) {
			return false;
		}

		return $this->cart_handler->add_ticket_to_cart( $ticket_type_id, $quantity );
	}

	/**
	 * Get cart URL for ticket purchase.
	 *
	 * @return string
	 */
	public function get_cart_url(): string {
		if ( ! $this->is_active || ! function_exists( 'wc_get_cart_url' ) ) {
			return '';
		}

		return wc_get_cart_url();
	}

	/**
	 * Get checkout URL for ticket purchase.
	 *
	 * @return string
	 */
	public function get_checkout_url(): string {
		if ( ! $this->is_active || ! function_exists( 'wc_get_checkout_url' ) ) {
			return '';
		}

		return wc_get_checkout_url();
	}

	/**
	 * Filter cart item thumbnail for event tickets.
	 *
	 * Thin facade over {@see WCTicketThumbnailFilter::filter_ticket_thumbnail()}.
	 *
	 * @param string               $thumbnail     Default thumbnail HTML.
	 * @param array<string, mixed> $cart_item     Cart item data.
	 * @param string               $cart_item_key Cart item key.
	 * @return string Filtered thumbnail HTML.
	 */
	public function filter_ticket_thumbnail( string $thumbnail, array $cart_item, string $cart_item_key ): string {
		return $this->thumbnail_filter()->filter_ticket_thumbnail( $thumbnail, $cart_item, $cart_item_key );
	}

	/**
	 * Filter admin order item thumbnail for event tickets.
	 *
	 * Thin facade over {@see WCTicketThumbnailFilter::filter_admin_order_item_thumbnail()}.
	 *
	 * @param string               $thumbnail Default thumbnail HTML.
	 * @param int                  $item_id   Order item ID.
	 * @param \WC_Order_Item|mixed $item      Order item object.
	 * @return string Filtered thumbnail HTML.
	 */
	public function filter_admin_order_item_thumbnail( string $thumbnail, int $item_id, $item ): string {
		return $this->thumbnail_filter()->filter_admin_order_item_thumbnail( $thumbnail, $item_id, $item );
	}

	/**
	 * Lazily build the WCTicketThumbnailFilter collaborator.
	 *
	 * @return WCTicketThumbnailFilter
	 */
	private function thumbnail_filter(): WCTicketThumbnailFilter {
		return new WCTicketThumbnailFilter(
			$this->product_manager,
			dirname( __DIR__, 3 ) . '/nettertech-events.php'
		);
	}
}
