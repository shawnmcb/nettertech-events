<?php
/**
 * Attendee list filtering by the WooCommerce order outcome behind a record.
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Attendees;

/**
 * Builds the SQL join + predicates that let the Attendees/Purchases list tell
 * a seat voided by a *failed or cancelled* order (never paid — noise on a
 * purchases view) from a seat voided by a *refund* (a real purchase, which
 * operators still want to see). Both carry attendee status `voided`, so the
 * distinction has to come from the order's own status (NTE-212).
 *
 * Shared by the list page and the CSV exporter so both agree on what a
 * "purchase" is. Degrades to no-op when WooCommerce is not active (RSVP-only
 * installs) or a record has no order.
 *
 * @since 1.4.4
 */
final class AttendeeOrderStatusFilter {

	/**
	 * Pseudo status-filter value that selects only failed/cancelled-order rows.
	 */
	public const FILTER_FAILED_ORDER = 'failed_order';

	/**
	 * Order statuses that mean the purchase never happened.
	 *
	 * @var array<string>
	 */
	private const UNPAID_ORDER_STATUSES = array( 'wc-failed', 'wc-cancelled' );

	/**
	 * Constructor.
	 *
	 * @param \wpdb     $db        WordPress database.
	 * @param bool|null $available Override for "WooCommerce is active" (null = detect).
	 * @param bool|null $hpos      Override for "orders live in HPOS tables" (null = detect).
	 */
	public function __construct(
		private readonly \wpdb $db,
		private readonly ?bool $available = null,
		private readonly ?bool $hpos = null
	) {
	}

	/**
	 * LEFT JOIN clause resolving each attendee's order status into `wo`.
	 *
	 * HPOS-aware: joins `wc_orders` when custom order tables are in use, else
	 * `posts` (`shop_order`). Empty string when WooCommerce is unavailable.
	 *
	 * @param string $attendee_alias Alias of the attendees table in the query.
	 * @return string
	 */
	public function join_clause( string $attendee_alias = 'a' ): string {
		if ( ! $this->is_available() ) {
			return '';
		}

		if ( $this->uses_hpos() ) {
			$table = $this->db->prefix . 'wc_orders';
			return "LEFT JOIN {$table} wo ON wo.id = {$attendee_alias}.wc_order_id";
		}

		return "LEFT JOIN {$this->db->posts} wo ON wo.ID = {$attendee_alias}.wc_order_id AND wo.post_type = 'shop_order'";
	}

	/**
	 * Predicate hiding rows voided by an unpaid (failed/cancelled) order.
	 *
	 * Applied when the operator has not chosen a status filter, so the default
	 * Purchases view reflects orders that actually received payment while
	 * refund-voided seats stay visible. `1=1` when WooCommerce is unavailable.
	 *
	 * @param string $attendee_alias Alias of the attendees table in the query.
	 * @return string
	 */
	public function exclude_unpaid_predicate( string $attendee_alias = 'a' ): string {
		if ( ! $this->is_available() ) {
			return '1=1';
		}

		return "NOT ({$attendee_alias}.status = 'voided' AND {$this->status_column()} IN ({$this->status_list()}))";
	}

	/**
	 * Predicate selecting only rows voided by an unpaid (failed/cancelled) order.
	 *
	 * @param string $attendee_alias Alias of the attendees table in the query.
	 * @return string `1=0` when WooCommerce is unavailable (nothing can match).
	 */
	public function only_unpaid_predicate( string $attendee_alias = 'a' ): string {
		if ( ! $this->is_available() ) {
			return '1=0';
		}

		return "({$attendee_alias}.status = 'voided' AND {$this->status_column()} IN ({$this->status_list()}))";
	}

	/**
	 * Whether the order tables can be joined at all.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->available ?? function_exists( 'WC' );
	}

	/**
	 * Whether WooCommerce stores orders in custom (HPOS) tables.
	 *
	 * @return bool
	 */
	private function uses_hpos(): bool {
		if ( null !== $this->hpos ) {
			return $this->hpos;
		}

		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Column holding the order status for the joined alias.
	 *
	 * @return string
	 */
	private function status_column(): string {
		return $this->uses_hpos() ? 'wo.status' : 'wo.post_status';
	}

	/**
	 * Quoted, comma-separated unpaid statuses (constant list, no user input).
	 *
	 * @return string
	 */
	private function status_list(): string {
		return "'" . implode( "','", self::UNPAID_ORDER_STATUSES ) . "'";
	}
}
