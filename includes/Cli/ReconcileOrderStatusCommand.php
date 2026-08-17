<?php
/**
 * WP-CLI: void attendees whose WooCommerce order never completed payment.
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

namespace NetterTechEvents\Cli;

use NetterTechEvents\Database\Schema;
use NetterTechEvents\Integrations\WooCommerce\OrderHandler;

/**
 * Reconciles attendee status with the outcome of the order that created it.
 *
 * Attendees are voided when their order fails, is cancelled, or is refunded —
 * but the failed-order listener only exists since 1.4.0, so attendees created
 * by orders that failed before that release still sit `confirmed`: they count
 * as issued tickets, hold capacity, and can be checked in (NTE-212). This
 * command finds every `confirmed` attendee whose order is currently
 * failed/cancelled and routes it through the same OrderHandler::void_attendees_for_order()
 * path the live listener uses, so tickets are cancelled and sold counts /
 * stock resynced exactly as if the status change had been observed.
 *
 * Dry-run is the DEFAULT; writes happen only with `--execute`.
 *
 * @since 1.4.4
 */
final class ReconcileOrderStatusCommand {

	/**
	 * Order statuses whose attendees must not remain confirmed.
	 *
	 * @var array<string>
	 */
	private const UNPAID_STATUSES = array( 'failed', 'cancelled' );

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param OrderHandler $order_handler WooCommerce order handler (owns the void path).
	 * @param \wpdb|null   $db            Database (default global).
	 */
	public function __construct(
		private readonly OrderHandler $order_handler,
		?\wpdb $db = null
	) {
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}
		$this->db = $db;
	}

	/**
	 * Void confirmed attendees whose WooCommerce order is failed or cancelled.
	 *
	 * ## OPTIONS
	 *
	 * [--execute]
	 * : Apply the changes. Without this flag the command runs in dry-run mode,
	 * prints counts and a sample, and writes nothing.
	 *
	 * [--limit=<n>]
	 * : Maximum number of orders to process in this run (default: all).
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview: how many attendees/orders would be voided.
	 *     wp nettertech-events reconcile-order-status
	 *
	 *     # Apply.
	 *     wp nettertech-events reconcile-order-status --execute
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args (execute, limit).
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		if ( ! function_exists( 'wc_get_order' ) ) {
			\WP_CLI::error( 'WooCommerce is not active; there are no orders to reconcile.' );
		}

		$execute = isset( $assoc_args['execute'] );
		$limit   = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;

		$rows = $this->find_confirmed_attendees_on_unpaid_orders();

		if ( empty( $rows ) ) {
			\WP_CLI::success( 'No confirmed attendees on failed or cancelled orders. Nothing to do.' );
			return;
		}

		$by_order = array();
		foreach ( $rows as $row ) {
			$by_order[ (int) $row->wc_order_id ][] = $row;
		}
		if ( $limit > 0 ) {
			$by_order = array_slice( $by_order, 0, $limit, true );
		}

		$attendee_total = 0;
		foreach ( $by_order as $list ) {
			$attendee_total += count( $list );
		}

		\WP_CLI::log( sprintf( 'Found %d confirmed attendee(s) across %d failed/cancelled order(s).', $attendee_total, count( $by_order ) ) );
		\WP_CLI::log( 'Sample:' );
		foreach ( array_slice( $by_order, 0, 10, true ) as $order_id => $list ) {
			$first = $list[0];
			\WP_CLI::log(
				sprintf(
					'  order #%d (%s): %d attendee(s), e.g. #%d %s <%s> qty %d',
					$order_id,
					(string) $first->order_status,
					count( $list ),
					(int) $first->id,
					(string) $first->name,
					(string) $first->email,
					(int) $first->quantity
				)
			);
		}

		if ( ! $execute ) {
			\WP_CLI::warning( 'Dry run — no changes made. Re-run with --execute to void these attendees.' );
			return;
		}

		$voided_orders = 0;
		foreach ( array_keys( $by_order ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				\WP_CLI::warning( sprintf( 'Order #%d could not be loaded; skipped.', $order_id ) );
				continue;
			}
			// Same path the live status listeners use: voids attendees, cancels
			// tickets, releases capacity, resyncs sold counts + product stock.
			$this->order_handler->void_attendees_for_order( $order );
			++$voided_orders;
		}

		\WP_CLI::success( sprintf( 'Voided attendees on %d of %d order(s).', $voided_orders, count( $by_order ) ) );
	}

	/**
	 * Confirmed attendees whose order is failed/cancelled (HPOS or legacy storage).
	 *
	 * @return array<int, \stdClass> Rows with id, wc_order_id, name, email, quantity, order_status.
	 */
	private function find_confirmed_attendees_on_unpaid_orders(): array {
		$attendees = Schema::table( 'attendees' );
		$statuses  = "'" . implode( "','", array_map( fn( string $s ) => 'wc-' . $s, self::UNPAID_STATUSES ) ) . "'";

		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders = $this->db->prefix . 'wc_orders';
			$sql    = "SELECT a.id, a.wc_order_id, a.name, a.email, a.quantity, o.status AS order_status
				FROM {$attendees} a
				INNER JOIN {$orders} o ON o.id = a.wc_order_id
				WHERE a.status = 'confirmed' AND o.status IN ({$statuses})
				ORDER BY a.wc_order_id, a.id";
		} else {
			$sql = "SELECT a.id, a.wc_order_id, a.name, a.email, a.quantity, p.post_status AS order_status
				FROM {$attendees} a
				INNER JOIN {$this->db->posts} p ON p.ID = a.wc_order_id AND p.post_type = 'shop_order'
				WHERE a.status = 'confirmed' AND p.post_status IN ({$statuses})
				ORDER BY a.wc_order_id, a.id";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table names from Schema/wpdb, status list is a class constant; maintenance command.
		$rows = $this->db->get_results( $sql );

		return $rows ? $rows : array();
	}
}
