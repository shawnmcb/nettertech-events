<?php
/**
 * WooCommerce Order Handler.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeOrderInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Contracts\TicketCodeGeneratorInterface;
use NetterTechEvents\Services\AttendeeFieldService;

/**
 * Handles WooCommerce order events for ticket purchases.
 *
 * Orchestrates order processing by delegating to specialized handlers:
 * - OrderAttendeeCreator: Creates attendee and ticket records
 * - OrderRefundProcessor: Handles partial and full refunds
 * - OrderCapacityValidator: Validates capacity at payment time
 *
 * @since 0.1.0
 * @api
 */
class OrderHandler {

	/**
	 * Product manager.
	 *
	 * @var ProductManager
	 */
	private readonly ProductManager $product_manager;

	/**
	 * Attendee repository for core CRUD operations.
	 *
	 * @var AttendeeRepositoryInterface
	 */
	private readonly AttendeeRepositoryInterface $attendee_repo;

	/**
	 * Attendee order operations.
	 *
	 * @var AttendeeOrderInterface
	 */
	private readonly AttendeeOrderInterface $attendee_order;

	/**
	 * Ticket repository.
	 *
	 * @var TicketRepositoryInterface
	 */
	private readonly TicketRepositoryInterface $ticket_repo;

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private readonly TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Capacity service.
	 *
	 * @var CapacityServiceInterface
	 */
	private readonly CapacityServiceInterface $capacity_service;

	/**
	 * Attendee creator.
	 *
	 * @var OrderAttendeeCreator
	 */
	private readonly OrderAttendeeCreator $attendee_creator;

	/**
	 * Refund processor.
	 *
	 * @var OrderRefundProcessor
	 */
	private readonly OrderRefundProcessor $refund_processor;

	/**
	 * Capacity validator.
	 *
	 * @var OrderCapacityValidator
	 */
	private readonly OrderCapacityValidator $capacity_validator;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param AttendeeRepositoryInterface   $attendee_repo       Attendee repository.
	 * @param TicketTypeRepositoryInterface $ticket_type_repo    Ticket type repository.
	 * @param TicketRepositoryInterface     $ticket_repo         Ticket repository.
	 * @param TicketCodeGeneratorInterface  $code_generator      Ticket code generator.
	 * @param OccurrenceRepositoryInterface $occurrence_repo     Occurrence repository.
	 * @param CapacityServiceInterface      $capacity_service    Capacity service.
	 * @param ProductManager                $product_manager     Product manager.
	 * @param AttendeeFieldService          $field_service       Attendee field service.
	 * @param OrderAttendeeCreator|null     $attendee_creator    Attendee creator.
	 * @param OrderRefundProcessor|null     $refund_processor    Refund processor.
	 * @param OrderCapacityValidator|null   $capacity_validator  Capacity validator.
	 * @throws \InvalidArgumentException When attendee_repo does not implement AttendeeOrderInterface.
	 */
	public function __construct(
		AttendeeRepositoryInterface $attendee_repo,
		TicketTypeRepositoryInterface $ticket_type_repo,
		TicketRepositoryInterface $ticket_repo,
		TicketCodeGeneratorInterface $code_generator,
		OccurrenceRepositoryInterface $occurrence_repo,
		CapacityServiceInterface $capacity_service,
		ProductManager $product_manager,
		AttendeeFieldService $field_service,
		?OrderAttendeeCreator $attendee_creator = null,
		?OrderRefundProcessor $refund_processor = null,
		?OrderCapacityValidator $capacity_validator = null,
	) {
		$this->product_manager = $product_manager;

		// AttendeeRepository implements both AttendeeRepositoryInterface and AttendeeOrderInterface (ISP).
		$this->attendee_repo = $attendee_repo;
		if ( ! $attendee_repo instanceof AttendeeOrderInterface ) {
			throw new \InvalidArgumentException( 'Expected attendee_repo to implement AttendeeOrderInterface.' );
		}
		$this->attendee_order = $attendee_repo;

		$this->ticket_type_repo = $ticket_type_repo;
		$this->ticket_repo      = $ticket_repo;
		$this->capacity_service = $capacity_service;

		// Initialize specialized handlers.
		$this->attendee_creator = $attendee_creator ?? new OrderAttendeeCreator(
			$this->product_manager,
			$this->attendee_repo,
			$this->attendee_order,
			$this->ticket_repo,
			$code_generator,
			$occurrence_repo,
			$field_service,
		);

		$this->refund_processor = $refund_processor ?? new OrderRefundProcessor(
			$this->product_manager,
			$this->attendee_repo,
			$this->attendee_order,
			$this->ticket_repo,
			$this->capacity_service,
		);

		$this->capacity_validator = $capacity_validator ?? new OrderCapacityValidator(
			$this->product_manager,
			$ticket_type_repo,
			$this->capacity_service,
		);
	}

	/**
	 * Handle payment complete (for most payment gateways).
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function handle_payment_complete( int $order_id ): void {
		$this->process_order( $order_id );
	}

	/**
	 * Handle order status changed to completed.
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function handle_order_completed( int $order_id ): void {
		$this->process_order( $order_id );
	}

	/**
	 * Handle order status changed to processing.
	 *
	 * For payment methods that go to processing first.
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function handle_order_processing( int $order_id ): void {
		$this->process_order( $order_id );
	}

	/**
	 * Handle order cancellation.
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function handle_order_cancelled( int $order_id ): void {
		$this->void_attendees( $order_id );
	}

	/**
	 * Handle order refund (full refund - order status changed to refunded).
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function handle_order_refunded( int $order_id ): void {
		$this->void_attendees( $order_id );
	}

	/**
	 * Handle refund created (partial or full refund).
	 *
	 * This fires when a refund is created, even for partial refunds
	 * where the order status doesn't change to "refunded".
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $refund_id Refund ID.
	 * @param array<string, mixed> $args      Refund arguments (unused).
	 * @return void
	 */
	public function handle_refund_created( int $refund_id, array $args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WC hook signature.
		$refund = wc_get_order( $refund_id );

		if ( ! $refund || ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$parent_order_id = $refund->get_parent_id();
		$parent_order    = wc_get_order( $parent_order_id );

		if ( ! $parent_order instanceof \WC_Order ) {
			return;
		}

		$this->process_refund( $refund, $parent_order );
	}

	/**
	 * Process a refund to update attendee and ticket records.
	 *
	 * Handles partial and full refunds by delegating to OrderRefundProcessor.
	 *
	 * Public for testing and direct use when order objects are already available.
	 *
	 * @since 0.9.0
	 *
	 * @param \WC_Order_Refund $refund       WooCommerce refund object.
	 * @param \WC_Order        $parent_order Parent order object.
	 * @return void
	 */
	public function process_refund( \WC_Order_Refund $refund, \WC_Order $parent_order ): void {
		$ticket_types_to_sync = $this->refund_processor->process( $refund, $parent_order );

		// Batch sync stock for all affected ticket types.
		foreach ( $ticket_types_to_sync as $ticket_type_id ) {
			$this->product_manager->sync_stock( $ticket_type_id );
		}
	}

	/**
	 * Process an order by ID to create attendee records.
	 *
	 * Fetches the order and delegates to process_order_object().
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	private function process_order( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->process_order_object( $order );
	}

	/**
	 * Process an order object to create attendee records.
	 *
	 * Validates capacity and delegates attendee creation to OrderAttendeeCreator.
	 *
	 * Public for testing and direct use when order object is already available.
	 *
	 * @since 0.9.0
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return void
	 */
	public function process_order_object( \WC_Order $order ): void {
		$order_id = $order->get_id();

		// Atomic idempotency claim — eliminates TOCTOU race condition.
		// Uses INSERT IGNORE into wp_options (unique index on option_name)
		// to serialize concurrent webhook deliveries.
		if ( ! $this->claim_order_processing( $order ) ) {
			return;
		}

		// Validate capacity with fresh data before processing.
		// This catches edge cases where pending reservation expired during checkout.
		$capacity_issues = $this->capacity_validator->validate( $order );

		if ( ! empty( $capacity_issues ) ) {
			$this->capacity_validator->handle_issues( $order, $capacity_issues );
		}

		// Create attendees and tickets via OrderAttendeeCreator.
		$ticket_types_with_quantities = $this->attendee_creator->process( $order );

		// Reserve capacity and sync stock for all ticket types.
		foreach ( $ticket_types_with_quantities as $ticket_type_id => $quantity ) {
			$this->capacity_service->reserve_capacity( $ticket_type_id, $quantity );
			$this->product_manager->sync_stock( $ticket_type_id );
		}
	}

	/**
	 * Atomically claim order processing to prevent duplicate attendee creation.
	 *
	 * Uses INSERT IGNORE into wp_options with a unique option_name per order.
	 * The UNIQUE index on option_name guarantees exactly one request succeeds —
	 * concurrent INSERT IGNORE attempts for the same key return rows_affected=0
	 * for the loser. This eliminates the TOCTOU race condition inherent in a
	 * separate read-then-write pattern (e.g., get_meta then update_meta).
	 *
	 * Uses wp_options (not order meta) because the UNIQUE index is required for
	 * atomicity, and neither wp_postmeta nor wp_wc_orders_meta have one. This
	 * also makes the lock HPOS-independent. The permanent order meta is set via
	 * WC CRUD after the claim succeeds.
	 *
	 * Backward compatible: if order meta was already set to 'yes' by older code
	 * (before the atomic pattern), the INSERT IGNORE won't exist yet, but the
	 * WC CRUD meta check below catches it.
	 *
	 * @since 1.2.0
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return bool True if this request claimed the lock, false if already processed.
	 */
	private function claim_order_processing( \WC_Order $order ): bool {
		global $wpdb;

		$order_id = $order->get_id();

		// Backward compat: check existing order meta first (HPOS-safe via WC CRUD).
		// Covers orders processed by older plugin versions that set meta directly.
		if ( 'yes' === $order->get_meta( MetaKeys::ATTENDEES_CREATED ) ) {
			return false;
		}

		// Atomic claim via INSERT IGNORE into wp_options.
		// The UNIQUE index on option_name ensures only one concurrent request
		// succeeds; the rest get rows_affected = 0 and bail out.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic idempotency lock; caching would defeat the purpose.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				'_nettertech_events_order_lock_' . $order_id,
				'yes'
			)
		);

		if ( 0 === (int) $claimed ) {
			// Another request already claimed this order.
			return false;
		}

		// We hold the lock — set the permanent order meta via HPOS-safe WC CRUD.
		$order->update_meta_data( MetaKeys::ATTENDEES_CREATED, 'yes' );
		$order->save();

		return true;
	}

	/**
	 * Void attendee records for an order by ID.
	 *
	 * Fetches the order and delegates to void_attendees_for_order().
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	private function void_attendees( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->void_attendees_for_order( $order );
	}

	/**
	 * Void attendee records for an order object.
	 *
	 * Cancels all attendees and tickets, releases capacity, and syncs stock.
	 *
	 * Public for testing and direct use when order object is already available.
	 *
	 * @since 0.9.0
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return void
	 */
	public function void_attendees_for_order( \WC_Order $order ): void {
		$order_id = $order->get_id();

		// Find all attendees for this order (using repository).
		$attendees = $this->attendee_order->find_all_by_order( $order_id );

		// Track ticket types for batch stock sync and released capacity.
		$ticket_types_to_sync    = array();
		$ticket_types_to_release = array();

		foreach ( $attendees as $attendee ) {
			$attendee_id = $attendee->id;
			if ( null === $attendee_id ) {
				continue;
			}

			// Update status to voided and cancel all tickets.
			$this->attendee_repo->update_status( $attendee_id, 'voided' );
			$this->ticket_repo->cancel_all_tickets_for_attendee( $attendee_id );

			// Track capacity to release (aggregate by ticket type).
			if ( $attendee->ticket_type_id ) {
				if ( ! isset( $ticket_types_to_release[ $attendee->ticket_type_id ] ) ) {
					$ticket_types_to_release[ $attendee->ticket_type_id ] = 0;
				}
				$ticket_types_to_release[ $attendee->ticket_type_id ] += $attendee->quantity;
				$ticket_types_to_sync[ $attendee->ticket_type_id ]     = true;
			}

			/**
			 * Fires when a registration is voided due to order cancellation/refund.
			 */
			do_action( 'nettertech_events_registration_voided', $attendee, $order );
		}

		// Reconcile sold_count from source data rather than applying a
		// relative decrement. Two WC hooks compete on full refunds:
		// woocommerce_refund_created (fires first, may or may not decrement
		// depending on HPOS status-transition timing) and
		// woocommerce_order_status_refunded (fires second, runs this method).
		// If the refund processor already decremented, a second relative
		// decrement would undercount. If it was skipped (HPOS early
		// transition), the attendee quantity may already be 0 (set by
		// apply_refund_to_attendee), causing release_capacity(tt, 0) to
		// no-op. recalculate_sold_count reads confirmed attendee rows
		// directly and is always correct regardless of hook ordering.
		foreach ( array_keys( $ticket_types_to_sync ) as $ticket_type_id ) {
			$this->ticket_type_repo->recalculate_sold_count( $ticket_type_id );
			$this->product_manager->sync_stock( $ticket_type_id );
		}
	}

	/**
	 * Get attendees for an order.
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array<Attendee>
	 */
	public function get_attendees_for_order( int $order_id ): array {
		return $this->attendee_order->find_all_by_order( $order_id );
	}

	/**
	 * Check if order has event tickets.
	 *
	 * @since 0.1.0
	 *
	 * @param int|\WC_Order $order Order ID or object.
	 * @return bool
	 */
	public function order_has_tickets( int|\WC_Order $order ): bool {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order ) {
			return false;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			if ( $this->product_manager->is_event_ticket( $item->get_product_id() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get order ticket summary for display.
	 *
	 * @since 0.1.0
	 *
	 * @param int|\WC_Order $order Order ID or object.
	 * @return array<array<string, mixed>>
	 */
	public function get_ticket_summary( int|\WC_Order $order ): array {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order ) {
			return array();
		}

		$summary = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();

			if ( ! $this->product_manager->is_event_ticket( $product_id ) ) {
				continue;
			}

			$occurrence  = $this->product_manager->get_occurrence_from_product( $product_id );
			$ticket_type = $this->product_manager->get_ticket_type_from_product( $product_id );

			$summary[] = array(
				'event_title'   => $occurrence && $occurrence->get_event() ? $occurrence->get_event()->title : '',
				'event_date'    => $occurrence ? $occurrence->get_formatted_date() : '',
				'event_time'    => $occurrence ? $occurrence->get_formatted_time() : '',
				'venue'         => $occurrence && $occurrence->get_event() ? $occurrence->get_event()->venue_name : '',
				'ticket_type'   => $ticket_type ? $ticket_type->name : '',
				'quantity'      => $item->get_quantity(),
				'total'         => $item->get_total(),
				'occurrence_id' => $occurrence ? $occurrence->id : null,
			);
		}

		return $summary;
	}
}
