<?php
/**
 * WP-CLI command: audit orphaned ticket products (NTE-228).
 *
 * @package NetterTechEvents
 */

declare( strict_types=1 );

namespace NetterTechEvents\Cli;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Integrations\WooCommerce\TicketProductAuditor;

/**
 * `wp nettertech-events products audit [--all] [--skus] [--format=<format>]`.
 *
 * Read-only. Lists the ticket products whose ticket type no longer points at
 * them, grouped by event, with the number of order line items each carries.
 */
class ProductAuditCommand {

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
	 * List orphaned ticket products, grouped by event, with order counts.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Include products whose ticket type still points at them.
	 *
	 * [--skus]
	 * : Print the suffixed-SKU report instead: ticket products whose SKU is a
	 * `-N` collision variant of another product's SKU.
	 *
	 * [--format=<format>]
	 * : Output format.
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
	 *     wp nettertech-events products audit
	 *     wp nettertech-events products audit --format=csv > orphans.csv
	 *     wp nettertech-events products audit --skus
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$rows   = $this->auditor->audit();

		if ( isset( $assoc_args['skus'] ) ) {
			$this->print_sku_report( $rows, $format );
			return;
		}

		$listed = isset( $assoc_args['all'] ) ? $rows : $this->auditor->orphans( $rows );

		if ( empty( $listed ) ) {
			\WP_CLI::success( 'No orphaned ticket products found.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			$format,
			array_map( array( $this, 'display_row' ), $listed ),
			array( 'event', 'product_id', 'title', 'sku', 'status', 'ticket_type_id', 'classification', 'linked_product_id', 'orders', 'order_lines' )
		);

		if ( 'table' === $format ) {
			$this->print_summary( $rows );
		}
	}

	/**
	 * Flatten an audit row for display.
	 *
	 * @param array<string, mixed> $row Audit row.
	 * @return array<string, mixed>
	 */
	private function display_row( array $row ): array {
		$event = '' !== $row['event_title'] ? $row['event_title'] : '(event ' . $row['event_id'] . ')';
		if ( $row['is_series_pass'] ) {
			$event .= ' [series pass]';
		}

		return array(
			'event'             => $event,
			'product_id'        => $row['product_id'],
			'title'             => $row['title'],
			'sku'               => $row['sku'],
			'status'            => $row['status'],
			'ticket_type_id'    => $row['ticket_type_id'],
			'classification'    => $row['classification'],
			'linked_product_id' => $row['linked_product_id'] > 0 ? $row['linked_product_id'] : '',
			'orders'            => $row['orders_known'] ? $row['order_count'] : '?',
			'order_lines'       => $row['orders_known'] ? $row['order_lines'] : '?',
		);
	}

	/**
	 * Print classification totals.
	 *
	 * @param array<int, array<string, mixed>> $rows Output of audit().
	 * @return void
	 */
	private function print_summary( array $rows ): void {
		$counts              = array(
			TicketProductAuditor::LINKED        => 0,
			TicketProductAuditor::TYPE_MISSING  => 0,
			TicketProductAuditor::TYPE_RELINKED => 0,
		);
		$orphans_with_orders = 0;
		$orders_known        = true;

		foreach ( $rows as $row ) {
			++$counts[ $row['classification'] ];
			if ( ! $row['orders_known'] ) {
				$orders_known = false;
			} elseif ( TicketProductAuditor::LINKED !== $row['classification'] && $row['order_lines'] > 0 ) {
				++$orphans_with_orders;
			}
		}

		\WP_CLI::log( '' );
		\WP_CLI::log(
			sprintf(
				'%d ticket product(s): %d linked, %d with a missing ticket type, %d whose ticket type points elsewhere.',
				count( $rows ),
				$counts[ TicketProductAuditor::LINKED ],
				$counts[ TicketProductAuditor::TYPE_MISSING ],
				$counts[ TicketProductAuditor::TYPE_RELINKED ]
			)
		);

		if ( $orders_known ) {
			\WP_CLI::log( sprintf( '%d orphan(s) carry order line items and will never be pruned automatically.', $orphans_with_orders ) );
		} else {
			\WP_CLI::warning( 'Order counts unavailable (wc_order_product_lookup missing); prune would skip everything.' );
		}
	}

	/**
	 * Print the suffixed-SKU report.
	 *
	 * @param array<int, array<string, mixed>> $rows   Output of audit().
	 * @param string                           $format Output format.
	 * @return void
	 */
	private function print_sku_report( array $rows, string $format ): void {
		$report = $this->auditor->suffixed_skus( $rows );

		if ( empty( $report ) ) {
			\WP_CLI::success( 'No suffixed ticket SKUs found.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$report,
			array( 'product_id', 'sku', 'base_sku', 'base_product_id', 'suffix', 'classification', 'order_lines' )
		);
	}
}
