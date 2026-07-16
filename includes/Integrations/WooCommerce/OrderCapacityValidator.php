<?php
/**
 * WooCommerce Order Capacity Validator.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\MetaKeys;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Handles capacity validation for WooCommerce ticket orders.
 *
 * Extracted from OrderHandler to reduce readonly class complexity.
 * Validates order capacity at payment time and handles oversell detection.
 *
 * @since 1.1.0
 */
readonly class OrderCapacityValidator {

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
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param ProductManager                $product_manager  Product manager.
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 * @param CapacityServiceInterface      $capacity_service Capacity service.
	 */
	public function __construct(
		ProductManager $product_manager,
		TicketTypeRepositoryInterface $ticket_type_repo,
		CapacityServiceInterface $capacity_service,
	) {
		$this->product_manager  = $product_manager;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;
	}

	/**
	 * Validate capacity for all ticket items in an order.
	 *
	 * Uses skip_cache=true to ensure fresh data at payment time,
	 * catching edge cases where pending reservations expired.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return array<array{ticket_type_id: int, name: string, requested: int, available: int|null}>
	 */
	public function validate( \WC_Order $order ): array {
		$issues = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();

			if ( ! $this->product_manager->is_event_ticket( $product_id ) ) {
				continue;
			}

			$ticket_type_id = (int) $item->get_meta( MetaKeys::TICKET_TYPE_ID );

			if ( ! $ticket_type_id ) {
				continue;
			}

			$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );

			if ( ! $ticket_type ) {
				continue;
			}

			// Get fresh capacity data (skip_cache=true for payment-critical validation).
			// Exclude pending reservations since this order's reservation is being converted.
			$available = $this->capacity_service->get_available_count(
				$ticket_type_id,
				include_pending: false,
				skip_cache: true
			);

			// Unlimited capacity (null) is always available.
			if ( null === $available ) {
				continue;
			}

			$requested = $item->get_quantity();

			if ( $available < $requested ) {
				$issues[] = array(
					'ticket_type_id' => $ticket_type_id,
					'name'           => $ticket_type->name,
					'requested'      => $requested,
					'available'      => $available,
				);
			}
		}

		return $issues;
	}

	/**
	 * Handle capacity issues detected during order processing.
	 *
	 * Logs a warning and adds an order note. Does not prevent order processing
	 * since payment has already been collected - alerts staff to review.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order                        $order  WooCommerce order object.
	 * @param array<int, array<string, mixed>> $issues Capacity issues array.
	 * @return void
	 */
	public function handle_issues( \WC_Order $order, array $issues ): void {
		$order_id = $order->get_id();

		// Build human-readable issue list.
		$issue_lines = array();
		foreach ( $issues as $issue ) {
			$issue_lines[] = sprintf(
				'%s: requested %d, only %d available',
				$issue['name'],
				$issue['requested'],
				$issue['available'] ?? 0
			);
		}

		$issue_text = implode( '; ', $issue_lines );

		// Log warning for development monitoring (order note handles production alerting).
		DebugLogger::log(
			sprintf( 'Potential oversell detected for order #%d: %s', $order_id, $issue_text ),
			'OrderCapacityValidator'
		);

		// Add order note to alert staff.
		$order->add_order_note(
			sprintf(
				/* translators: %s: list of capacity issues */
				__( '⚠️ Capacity warning: %s. Please verify ticket availability and contact customer if needed.', 'nettertech-events' ),
				$issue_text
			),
			0, // Not sent to customer.
			true // Added by system.
		);

		/**
		 * Fires when a potential oversell is detected during order processing.
		 *
		 * Allows integrations to take additional action (email alerts, etc.).
		 *
		 * @since 1.0.2
		 *
		 * @param \WC_Order $order  The order being processed.
		 * @param array     $issues Array of capacity issues detected.
		 */
		do_action( 'nettertech_events_capacity_oversell_detected', $order, $issues );
	}
}
