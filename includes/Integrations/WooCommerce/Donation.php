<?php
/**
 * Donation value object.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * One order's checkout donation, as charged and as refunded.
 *
 * Built from the order's donation fee line, which is what the buyer paid,
 * so refunds made against that line are reflected in $refunded and $net.
 *
 * @since 1.5.0
 * @api
 */
final readonly class Donation {

	/**
	 * Constructor.
	 *
	 * @param int    $order_id     Order ID.
	 * @param int    $fee_item_id  ID of the order's donation fee line.
	 * @param float  $amount       Amount charged.
	 * @param float  $refunded     Amount refunded against the donation line (positive).
	 * @param float  $net          Amount charged less amount refunded, never below zero.
	 * @param string $type         'roundup', 'fixed', 'custom', or '' when unknown.
	 * @param string $currency     Order currency code.
	 * @param string $date_created Order creation time, UTC, 'Y-m-d H:i:s'; '' when the order has none.
	 */
	public function __construct(
		public int $order_id,
		public int $fee_item_id,
		public float $amount,
		public float $refunded,
		public float $net,
		public string $type,
		public string $currency,
		public string $date_created,
	) {}
}
