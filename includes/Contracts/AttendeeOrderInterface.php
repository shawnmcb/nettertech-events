<?php
/**
 * Attendee Order Interface.
 *
 * Defines WooCommerce order-related operations for attendees.
 * Part of the segregated AttendeeRepository interface design (ISP compliance).
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Attendee;

/**
 * Interface for attendee order operations.
 *
 * This interface segregates WooCommerce/order functionality from the core repository,
 * following the Interface Segregation Principle. Consumers that only need
 * order lookups (OrderHandler, WooCommerce integration) should depend on
 * this interface rather than the full AttendeeRepositoryInterface.
 *
 * @since 0.9.3
 * @api
 */
interface AttendeeOrderInterface {

	/**
	 * Find attendee by WooCommerce order ID.
	 *
	 * Returns the first attendee associated with the order.
	 * For orders with multiple attendees, use find_all_by_order().
	 *
	 * @since 0.9.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return Attendee|null
	 */
	public function find_by_order( int $order_id ): ?Attendee;

	/**
	 * Find all attendees by WooCommerce order ID.
	 *
	 * Returns all attendees created from a single order.
	 * Useful for orders containing tickets to multiple occurrences.
	 *
	 * @since 0.9.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array<Attendee>
	 */
	public function find_all_by_order( int $order_id ): array;

	/**
	 * Find attendee by WooCommerce order ID and occurrence ID.
	 *
	 * More specific lookup when order contains multiple occurrences.
	 *
	 * @since 0.9.0
	 *
	 * @param int $order_id      WooCommerce order ID.
	 * @param int $occurrence_id Occurrence ID.
	 * @return Attendee|null
	 */
	public function find_by_order_and_occurrence( int $order_id, int $occurrence_id ): ?Attendee;

	/**
	 * Update attendee quantity.
	 *
	 * Used when order quantities change (partial refunds, modifications).
	 *
	 * @since 0.9.0
	 *
	 * @param int $id       Attendee ID.
	 * @param int $quantity New quantity.
	 * @return bool True on success.
	 */
	public function update_quantity( int $id, int $quantity ): bool;
}
