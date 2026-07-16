<?php
/**
 * WooCommerce Order Refund Processor.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeOrderInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Models\Attendee;

/**
 * Handles WooCommerce refund processing for ticket purchases.
 *
 * Extracted from OrderHandler to reduce readonly class complexity.
 * Manages partial and full refunds, updates attendee quantities,
 * cancels tickets, and releases capacity.
 *
 * @since 1.1.0
 */
readonly class OrderRefundProcessor {

	/**
	 * Product manager.
	 *
	 * @var ProductManager
	 */
	private ProductManager $product_manager;

	/**
	 * Attendee repository for core CRUD operations.
	 *
	 * @var AttendeeRepositoryInterface
	 */
	private AttendeeRepositoryInterface $attendee_repo;

	/**
	 * Attendee order operations.
	 *
	 * @var AttendeeOrderInterface
	 */
	private AttendeeOrderInterface $attendee_order;

	/**
	 * Ticket repository.
	 *
	 * @var TicketRepositoryInterface
	 */
	private TicketRepositoryInterface $ticket_repo;

	/**
	 * Capacity service.
	 *
	 * @var CapacityServiceInterface
	 */
	private CapacityServiceInterface $capacity_service;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param ProductManager              $product_manager  Product manager.
	 * @param AttendeeRepositoryInterface $attendee_repo    Attendee repository.
	 * @param AttendeeOrderInterface      $attendee_order   Attendee order operations.
	 * @param TicketRepositoryInterface   $ticket_repo      Ticket repository.
	 * @param CapacityServiceInterface    $capacity_service Capacity service.
	 */
	public function __construct(
		ProductManager $product_manager,
		AttendeeRepositoryInterface $attendee_repo,
		AttendeeOrderInterface $attendee_order,
		TicketRepositoryInterface $ticket_repo,
		CapacityServiceInterface $capacity_service,
	) {
		$this->product_manager  = $product_manager;
		$this->attendee_repo    = $attendee_repo;
		$this->attendee_order   = $attendee_order;
		$this->ticket_repo      = $ticket_repo;
		$this->capacity_service = $capacity_service;
	}

	/**
	 * Process a refund to update attendee and ticket records.
	 *
	 * Handles partial and full refunds by updating attendee quantities,
	 * cancelling tickets, and releasing capacity.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order_Refund $refund       WooCommerce refund object.
	 * @param \WC_Order        $parent_order Parent order object.
	 * @return array<int> Ticket type IDs that were affected (for stock sync).
	 */
	public function process( \WC_Order_Refund $refund, \WC_Order $parent_order ): array {
		$refund_id       = $refund->get_id();
		$parent_order_id = $parent_order->get_id();

		// HPOS-compatible idempotency check: use a unique meta key per refund.
		// Uses order meta API instead of add_post_meta for compatibility with
		// WooCommerce High-Performance Order Storage (custom tables).
		$refund_meta_key   = MetaKeys::PROCESSED_REFUND_PREFIX . $refund_id;
		$already_processed = 'yes' === $parent_order->get_meta( $refund_meta_key );

		if ( ! $already_processed ) {
			// Mark as processed immediately to reduce concurrent processing window.
			$parent_order->update_meta_data( $refund_meta_key, 'yes' );
			$parent_order->save();
		}

		if ( $already_processed ) {
			return array();
		}

		// If order is fully refunded, cancel_attendees will handle it via woocommerce_order_status_refunded.
		// We only need to handle partial refunds here.
		if ( 'refunded' === $parent_order->get_status() ) {
			return array();
		}

		// Track ticket types for batch stock sync.
		$ticket_types_to_sync = array();

		// Process refunded line items.
		foreach ( $this->get_refund_ticket_items( $refund ) as $refund_data ) {
			$result = $this->process_refund_item( $refund_data, $parent_order, $refund );

			if ( $result && $result['ticket_type_id'] ) {
				$ticket_types_to_sync[ $result['ticket_type_id'] ] = true;
			}
		}

		return array_keys( $ticket_types_to_sync );
	}

	/**
	 * Yield valid refund ticket items with quantity.
	 *
	 * Filters refund items to only return valid event ticket products with refunded quantity.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order_Refund $refund WooCommerce refund object.
	 * @return \Generator<array{item: \WC_Order_Item_Product, product_id: int, refunded_qty: int}>
	 */
	private function get_refund_ticket_items( \WC_Order_Refund $refund ): \Generator {
		foreach ( $refund->get_items() as $refund_item ) {
			if ( ! $refund_item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $refund_item->get_product_id();

			if ( ! $this->product_manager->is_event_ticket( $product_id ) ) {
				continue;
			}

			// Refund quantities are negative, so we need the absolute value.
			$refunded_qty = abs( $refund_item->get_quantity() );

			if ( $refunded_qty <= 0 ) {
				continue;
			}

			yield array(
				'item'         => $refund_item,
				'product_id'   => $product_id,
				'refunded_qty' => $refunded_qty,
			);
		}
	}

	/**
	 * Process a single refund item.
	 *
	 * Updates attendee quantity, cancels tickets, and releases capacity.
	 *
	 * @since 1.1.0
	 *
	 * @param array{item: \WC_Order_Item_Product, product_id: int, refunded_qty: int} $refund_data  Refund item data.
	 * @param \WC_Order                                                               $parent_order Parent order.
	 * @param \WC_Order_Refund                                                        $refund       Refund object.
	 * @return array{ticket_type_id: int}|null Result with ticket type ID, or null if skipped.
	 */
	private function process_refund_item( array $refund_data, \WC_Order $parent_order, \WC_Order_Refund $refund ): ?array {
		$refund_item  = $refund_data['item'];
		$product_id   = $refund_data['product_id'];
		$refunded_qty = $refund_data['refunded_qty'];

		// Find the original order item.
		$original_item = $this->find_original_order_item( $refund_item, $parent_order, $product_id );

		if ( ! $original_item ) {
			return null;
		}

		$occurrence_id  = (int) $original_item->get_meta( MetaKeys::OCCURRENCE_ID );
		$ticket_type_id = (int) $original_item->get_meta( MetaKeys::TICKET_TYPE_ID );

		if ( ! $occurrence_id ) {
			return null;
		}

		// Find attendee for this order and occurrence.
		$attendee = $this->attendee_order->find_by_order_and_occurrence( $parent_order->get_id(), $occurrence_id );

		if ( ! $attendee ) {
			return null;
		}

		// Update attendee and tickets based on refund quantity.
		$new_quantity = $this->apply_refund_to_attendee( $attendee, $refunded_qty );

		// Release capacity.
		if ( $ticket_type_id ) {
			$this->capacity_service->release_capacity( $ticket_type_id, $refunded_qty );
		}

		/**
		 * Fires when tickets are refunded (partial or full).
		 */
		do_action( 'nettertech_events_tickets_refunded', $attendee, $refunded_qty, $new_quantity, $parent_order, $refund );

		return array( 'ticket_type_id' => $ticket_type_id );
	}

	/**
	 * Find the original order item for a refund item.
	 *
	 * Attempts to find by meta reference, then falls back to product ID match.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order_Item_Product $refund_item  Refund item.
	 * @param \WC_Order              $parent_order Parent order.
	 * @param int                    $product_id   Product ID.
	 * @return \WC_Order_Item|false|null Original item or null if not found.
	 */
	private function find_original_order_item(
		\WC_Order_Item_Product $refund_item,
		\WC_Order $parent_order,
		int $product_id
	): \WC_Order_Item|false|null {
		// Try to get from refund item meta first.
		$original_item_id = $refund_item->get_meta( '_refunded_item_id' );

		// Fallback: find by product ID match.
		if ( ! $original_item_id ) {
			$original_item_id = $this->find_item_id_by_product( $parent_order, $product_id );
		}

		if ( ! $original_item_id ) {
			return null;
		}

		return $parent_order->get_item( $original_item_id );
	}

	/**
	 * Find order item ID by product ID.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order $order      Order object.
	 * @param int       $product_id Product ID to find.
	 * @return int|null Item ID or null if not found.
	 */
	private function find_item_id_by_product( \WC_Order $order, int $product_id ): ?int {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $item->get_product_id() === $product_id ) {
				return $item->get_id();
			}
		}

		return null;
	}

	/**
	 * Apply refund to attendee record.
	 *
	 * Updates status or quantity and cancels tickets accordingly.
	 *
	 * @since 1.1.0
	 *
	 * @param Attendee $attendee     Attendee record.
	 * @param int      $refunded_qty Quantity being refunded.
	 * @return int New quantity after refund.
	 */
	private function apply_refund_to_attendee( Attendee $attendee, int $refunded_qty ): int {
		$new_quantity = max( 0, $attendee->quantity - $refunded_qty );

		$attendee_id = $attendee->id;
		if ( null === $attendee_id ) {
			return $new_quantity;
		}

		if ( 0 === $new_quantity ) {
			// Fully refunded - cancel the attendee and all tickets.
			$this->attendee_repo->update_status( $attendee_id, 'refunded' );
			$this->ticket_repo->cancel_all_tickets_for_attendee( $attendee_id );
		} else {
			// Partially refunded - update quantity and cancel N tickets.
			$this->attendee_order->update_quantity( $attendee_id, $new_quantity );
			$this->ticket_repo->cancel_tickets_for_attendee( $attendee_id, $refunded_qty );
		}

		return $new_quantity;
	}
}
