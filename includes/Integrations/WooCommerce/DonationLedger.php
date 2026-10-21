<?php
/**
 * Donation Ledger.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\DonationLedgerInterface;

/**
 * Reads checkout donations from the order's donation fee line.
 *
 * The fee line is authoritative because it is what the buyer paid and what
 * WooCommerce refunds against. The order meta written at checkout only says
 * that an order had a donation; its amount does not follow refunds.
 *
 * @since 1.5.0
 */
final class DonationLedger implements DonationLedgerInterface {

	/**
	 * Orders fetched per query in in_range().
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Order meta recording the donation amount at checkout.
	 *
	 * @var string
	 */
	private const ORDER_AMOUNT_META = '_nettertech_events_donation_amount';

	/**
	 * Order meta recording the donation type at checkout.
	 *
	 * @var string
	 */
	private const ORDER_TYPE_META = '_nettertech_events_donation_type';

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Order $order Order to read.
	 * @return Donation|null
	 */
	public function for_order( \WC_Order $order ): ?Donation {
		$item = $this->find_donation_line( $order );
		if ( null === $item ) {
			return null;
		}

		$item_id  = $item->get_id();
		$amount   = round( (float) $item->get_total(), 2 );
		$refunded = round( abs( (float) $order->get_total_refunded_for_item( $item_id, 'fee' ) ), 2 );

		// The order meta type is written when the order is placed; the line's tag
		// can be older, because the Store API keeps fee lines while the fee total
		// is unchanged.
		$type = (string) $order->get_meta( self::ORDER_TYPE_META, true );
		if ( '' === $type ) {
			$type = (string) $item->get_meta( DonationHandler::FEE_ITEM_TYPE_META, true );
		}

		return new Donation(
			(int) $order->get_id(),
			$item_id,
			$amount,
			$refunded,
			max( 0.0, round( $amount - $refunded, 2 ) ),
			$type,
			(string) $order->get_currency(),
			$this->utc_date( $order->get_date_created() )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string        $start_utc Range start, UTC, 'Y-m-d H:i:s'.
	 * @param string        $end_utc   Range end, UTC, 'Y-m-d H:i:s'.
	 * @param array<string> $statuses  Order statuses to include.
	 * @return array<int, Donation>
	 */
	public function in_range( string $start_utc, string $end_utc, array $statuses ): array {
		$utc   = new \DateTimeZone( 'UTC' );
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $start_utc, $utc );
		$end   = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $end_utc, $utc );
		if ( false === $start || false === $end || $start > $end ) {
			return array();
		}

		$donations = array();
		$page      = 1;
		do {
			// meta_key/meta_compare rather than meta_query: the posts-table order
			// store drops a meta_query argument, which would return every order.
			$orders  = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'status'       => $statuses,
					'date_created' => $start->getTimestamp() . '...' . $end->getTimestamp(),
					'meta_key'     => self::ORDER_AMOUNT_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the donation meta is the only index for donation orders; the query is date-bounded and paged.
					'meta_compare' => 'EXISTS',
					'orderby'      => 'date',
					'order'        => 'ASC',
					'limit'        => self::PAGE_SIZE,
					'page'         => $page,
					'return'       => 'objects',
				)
			);
			$orders  = is_array( $orders ) ? $orders : array();
			$fetched = count( $orders );

			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				$donation = $this->for_order( $order );
				if ( null !== $donation ) {
					$donations[] = $donation;
				}
			}

			++$page;
		} while ( self::PAGE_SIZE === $fetched );

		return $donations;
	}

	/**
	 * The order's donation fee line.
	 *
	 * The tag set at checkout identifies it. Orders placed before lines were
	 * tagged are matched on the amount recorded at checkout; when two lines
	 * have that amount, the one with the donation's name wins, and if that
	 * doesn't settle it the order is left unmatched.
	 *
	 * @param \WC_Order $order Order to search.
	 * @return \WC_Order_Item_Fee|null
	 */
	private function find_donation_line( \WC_Order $order ): ?\WC_Order_Item_Fee {
		$fees = array();
		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Fee ) {
				continue;
			}
			if ( '1' === (string) $item->get_meta( DonationHandler::FEE_ITEM_FLAG_META, true ) ) {
				return $item;
			}
			$fees[] = $item;
		}

		$recorded = (float) $order->get_meta( self::ORDER_AMOUNT_META, true );
		if ( $recorded <= 0 ) {
			return null;
		}

		$matches = array_values(
			array_filter(
				$fees,
				static fn( \WC_Order_Item_Fee $item ): bool => abs( (float) $item->get_total() - $recorded ) < 0.005
			)
		);
		if ( 1 === count( $matches ) ) {
			return $matches[0];
		}

		$name  = __( 'Donation', 'nettertech-events' );
		$named = array_values(
			array_filter(
				$matches,
				static fn( \WC_Order_Item_Fee $item ): bool => $item->get_name() === $name
			)
		);
		return 1 === count( $named ) ? $named[0] : null;
	}

	/**
	 * Format an order date as UTC 'Y-m-d H:i:s'.
	 *
	 * @param \DateTimeInterface|null $date Order date.
	 * @return string '' when the order has no date.
	 */
	private function utc_date( ?\DateTimeInterface $date ): string {
		if ( null === $date ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', $date->getTimestamp() );
	}
}
