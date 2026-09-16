<?php
/**
 * WP-CLI: backfill attendee notes from WooCommerce order customer notes.
 *
 * @package NetterTechEvents\Cli
 */

declare( strict_types=1 );

namespace NetterTechEvents\Cli;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Database\Schema;
use NetterTechEvents\Integrations\WooCommerce\OrderAttendeeCreator;

/**
 * Copies each order's checkout "Order notes" onto the attendees that were
 * created before the plugin recorded them (NTE-226).
 *
 * Only attendees whose `notes` column is empty and that belong to a
 * WooCommerce order are candidates; attendees whose order has no customer
 * note are left untouched. Dry-run by default.
 *
 * ## OPTIONS
 *
 * [--execute]
 * : Write the notes. Without it the command only reports what it would do.
 *
 * ## EXAMPLES
 *
 *     wp nettertech-events backfill-attendee-notes
 *     wp nettertech-events backfill-attendee-notes --execute
 *
 * @since 1.4.7
 */
class BackfillAttendeeNotesCommand {

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Run the backfill.
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI command signature.
		$execute = isset( $assoc_args['execute'] );
		$result  = $this->run( $execute );

		\WP_CLI::log( sprintf( 'Attendees without notes on a WooCommerce order: %d across %d orders.', $result['candidates'], $result['orders'] ) );
		\WP_CLI::log( sprintf( 'Orders carrying a customer note: %d (%d attendees).', $result['orders_with_note'], $result['matched'] ) );

		if ( ! $execute ) {
			\WP_CLI::success( sprintf( 'Dry run: %d attendee(s) would receive a note. Re-run with --execute to write them.', $result['matched'] ) );
			return;
		}

		\WP_CLI::success( sprintf( 'Updated %d attendee(s).', $result['updated'] ) );
	}

	/**
	 * Resolve candidates and (optionally) write the notes.
	 *
	 * @param bool $execute Whether to write.
	 * @return array{candidates: int, orders: int, orders_with_note: int, matched: int, updated: int}
	 */
	public function run( bool $execute ): array {
		$table = Schema::table( 'attendees' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from the schema constant; one-off maintenance command.
		$rows = $this->db->get_results( "SELECT id, wc_order_id FROM {$table} WHERE wc_order_id > 0 AND ( notes IS NULL OR notes = '' ) ORDER BY wc_order_id ASC, id ASC" );

		$by_order = array();
		foreach ( (array) $rows as $row ) {
			$by_order[ (int) $row->wc_order_id ][] = (int) $row->id;
		}

		$result = array(
			'candidates'       => count( (array) $rows ),
			'orders'           => count( $by_order ),
			'orders_with_note' => 0,
			'matched'          => 0,
			'updated'          => 0,
		);

		foreach ( $by_order as $order_id => $attendee_ids ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$note = OrderAttendeeCreator::sanitize_customer_note( $order->get_customer_note() );
			if ( null === $note ) {
				continue;
			}

			++$result['orders_with_note'];
			$result['matched'] += count( $attendee_ids );

			if ( ! $execute ) {
				continue;
			}

			foreach ( $attendee_ids as $attendee_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off maintenance command.
				$done = $this->db->update( $table, array( 'notes' => $note ), array( 'id' => $attendee_id ), array( '%s' ), array( '%d' ) );
				if ( false !== $done ) {
					++$result['updated'];
				}
			}
		}

		return $result;
	}
}
