<?php
/**
 * WP-CLI command: backfill mirrored product categories (NTE-129).
 *
 * @package NetterTechEvents\Cli
 */

declare(strict_types=1);

namespace NetterTechEvents\Cli;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Integrations\WooCommerce\ProductManager;

/**
 * Assigns mirrored WooCommerce `product_cat` terms to already-synced ticket
 * products so category-scoped coupons apply to them (FR-007).
 *
 * @since 1.1.2
 */
class ProductCategoryBackfillCommand {

	/**
	 * Product manager.
	 *
	 * @var ProductManager
	 */
	private ProductManager $product_manager;

	/**
	 * Constructor.
	 *
	 * @param ProductManager $product_manager Product manager.
	 */
	public function __construct( ProductManager $product_manager ) {
		$this->product_manager = $product_manager;
	}

	/**
	 * Backfill mirrored product categories onto existing ticket products.
	 *
	 * ## OPTIONS
	 *
	 * [--event=<id>]
	 * : Limit the backfill to a single event's ticket products.
	 *
	 * [--dry-run]
	 * : Report what would change without writing. Enabled by default; pass
	 *   --no-dry-run to apply the changes.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview the full backfill (default, no changes written)
	 *     wp nettertech-events backfill-product-cats
	 *
	 *     # Apply the backfill for one event
	 *     wp nettertech-events backfill-product-cats --event=42 --no-dry-run
	 *
	 * @param array<int, string>   $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$event_id = isset( $assoc_args['event'] ) ? (int) $assoc_args['event'] : null;
		$dry_run  = array_key_exists( 'dry-run', $assoc_args ) ? (bool) $assoc_args['dry-run'] : true;

		$summary = $this->product_manager->backfill_product_categories( $event_id, $dry_run );

		$mode = $dry_run ? 'DRY RUN (no changes written)' : 'LIVE';
		\WP_CLI::log(
			sprintf(
				'%s — scanned %d ticket product(s); %d %s updated.',
				$mode,
				(int) $summary['products'],
				(int) $summary['updated'],
				$dry_run ? 'would be' : 'were'
			)
		);

		if ( $dry_run ) {
			\WP_CLI::log( 'Re-run with --no-dry-run to apply these changes.' );
		}

		\WP_CLI::success( 'Product-category backfill complete.' );
	}
}
