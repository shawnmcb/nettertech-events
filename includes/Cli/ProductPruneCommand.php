<?php
/**
 * WP-CLI command: trash orphaned ticket products that carry no orders (NTE-228).
 *
 * @package NetterTechEvents
 */

declare( strict_types=1 );

namespace NetterTechEvents\Cli;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Integrations\WooCommerce\TicketProductAuditor;

/**
 * `wp nettertech-events products prune [--confirm] [--dry-run] [--format=<format>]`.
 *
 * Dry run unless `--confirm` is given. Trashes (never deletes) orphaned ticket
 * products with zero order line items; orphans with orders are listed for a
 * manual decision and left alone.
 */
class ProductPruneCommand {

	/**
	 * Auditor.
	 *
	 * @var TicketProductAuditor
	 */
	private TicketProductAuditor $auditor;

	/**
	 * Constructor.
	 *
	 * @param TicketProductAuditor $auditor Auditor.
	 */
	public function __construct( TicketProductAuditor $auditor ) {
		$this->auditor = $auditor;
	}

	/**
	 * Trash orphaned ticket products that have no order line items.
	 *
	 * ## OPTIONS
	 *
	 * [--confirm]
	 * : Actually trash the products. Without it the command is a dry run and
	 * makes no database writes.
	 *
	 * [--dry-run]
	 * : Force a dry run even when --confirm is present.
	 *
	 * [--format=<format>]
	 * : Output format for the product lists.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview.
	 *     wp nettertech-events products prune
	 *
	 *     # Trash the zero-order orphans.
	 *     wp nettertech-events products prune --confirm
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$format  = (string) ( $assoc_args['format'] ?? 'table' );
		$execute = isset( $assoc_args['confirm'] ) && ! isset( $assoc_args['dry-run'] );

		$rows    = $this->auditor->audit();
		$orphans = $this->auditor->orphans( $rows );
		$by_id   = array_column( $orphans, null, 'product_id' );
		$result  = $this->auditor->prune( $orphans, $execute );

		$acted = $execute ? $result['trashed'] : $result['would_trash'];

		if ( ! empty( $acted ) ) {
			\WP_CLI::log( $execute ? 'Trashed:' : 'Would trash (no orders):' );
			$this->print_products( $acted, $by_id, $format );
		}

		if ( ! empty( $result['skipped'] ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Skipped, orders attached (needs a manual merge decision):' );
			$this->print_products( $result['skipped'], $by_id, $format );
		}

		if ( ! empty( $result['failed'] ) ) {
			\WP_CLI::warning( sprintf( 'Could not trash %d product(s): %s', count( $result['failed'] ), implode( ', ', $result['failed'] ) ) );
		}

		\WP_CLI::log( '' );
		if ( $execute ) {
			\WP_CLI::success( sprintf( '%d product(s) trashed, %d skipped.', count( $result['trashed'] ), count( $result['skipped'] ) ) );
			return;
		}

		\WP_CLI::log( sprintf( 'Dry run: %d product(s) would be trashed, %d skipped. Re-run with --confirm to apply.', count( $result['would_trash'] ), count( $result['skipped'] ) ) );
	}

	/**
	 * Print a product list in the requested format.
	 *
	 * @param int[]                            $ids    Product ids.
	 * @param array<int, array<string, mixed>> $by_id  Orphan rows keyed by product id.
	 * @param string                           $format Output format.
	 * @return void
	 */
	private function print_products( array $ids, array $by_id, string $format ): void {
		$items = array();
		foreach ( $ids as $id ) {
			$row     = $by_id[ $id ] ?? array();
			$items[] = array(
				'product_id'     => $id,
				'event'          => (string) ( $row['event_title'] ?? '' ),
				'title'          => (string) ( $row['title'] ?? '' ),
				'sku'            => (string) ( $row['sku'] ?? '' ),
				'classification' => (string) ( $row['classification'] ?? '' ),
				'orders'         => isset( $row['orders_known'] ) && $row['orders_known'] ? (int) $row['order_count'] : '?',
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'product_id', 'event', 'title', 'sku', 'classification', 'orders' ) );
	}
}
