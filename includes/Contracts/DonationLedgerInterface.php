<?php
/**
 * Donation Ledger Interface.
 *
 * The one read path for checkout donations: order screens, reports and
 * add-ons all take their figures from here so they agree with each other.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Integrations\WooCommerce\Donation;

/**
 * Reads checkout donations from WooCommerce orders.
 *
 * Resolve through ServiceRegistry::container()->get( DonationLedgerInterface::class ).
 *
 * @since 1.5.0
 * @api
 */
interface DonationLedgerInterface {

	/**
	 * The order's donation, or null when the order has none.
	 *
	 * @since 1.5.0
	 *
	 * @param \WC_Order $order Order to read.
	 * @return Donation|null
	 */
	public function for_order( \WC_Order $order ): ?Donation;

	/**
	 * Donations on orders created in a UTC time range, oldest first.
	 *
	 * Both bounds are inclusive. An order recorded as having a donation but
	 * with no identifiable donation line is left out.
	 *
	 * @since 1.5.0
	 *
	 * @param string        $start_utc Range start, UTC, 'Y-m-d H:i:s'.
	 * @param string        $end_utc   Range end, UTC, 'Y-m-d H:i:s'.
	 * @param array<string> $statuses  Order statuses to include, e.g. array( 'completed', 'processing' ).
	 * @return array<int, Donation>
	 */
	public function in_range( string $start_utc, string $end_utc, array $statuses ): array;
}
