<?php
/**
 * WP-CLI command: collapse repeated sync prefixes on ticket product titles (NTE-235).
 *
 * @package NetterTechEvents
 */

declare( strict_types=1 );

namespace NetterTechEvents\Cli;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Integrations\WooCommerce\ProductTitleNormalizer;

/**
 * `wp nettertech-events products normalize-titles [--execute] [--report=<file>]`.
 *
 * Dry-run by default: prints the rows that would change and a summary, and
 * writes nothing. `--execute` applies the plan. `--report` writes the full
 * plan (including unchanged and unresolved rows) as CSV for the operator.
 */
class ProductTitleNormalizeCommand {

	/**
	 * Normalizer.
	 *
	 * @var ProductTitleNormalizer
	 */
	private ProductTitleNormalizer $normalizer;

	/**
	 * Constructor.
	 *
	 * @param ProductTitleNormalizer $normalizer Normalizer.
	 */
	public function __construct( ProductTitleNormalizer $normalizer ) {
		$this->normalizer = $normalizer;
	}

	/**
	 * Collapse repeated "{event} - {date} - " prefixes on ticket product titles
	 * and strip them from the tier names they were copied into.
	 *
	 * ## OPTIONS
	 *
	 * [--execute]
	 * : Apply the changes. Without this flag the command is a dry run and makes
	 * no database writes.
	 *
	 * [--report=<file>]
	 * : Also write the full plan (every ticket product, changed or not) as CSV
	 * to this path.
	 *
	 * [--format=<format>]
	 * : Output format for the rows that would change.
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
	 *     # Preview, with a CSV of the whole scan.
	 *     wp nettertech-events products normalize-titles --report=titles.csv
	 *
	 *     # Apply.
	 *     wp nettertech-events products normalize-titles --execute
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$execute = isset( $assoc_args['execute'] );
		$format  = (string) ( $assoc_args['format'] ?? 'table' );
		$report  = isset( $assoc_args['report'] ) ? (string) $assoc_args['report'] : '';

		$plan = $this->normalizer->plan();

		$changing   = array_values( array_filter( $plan, static fn( array $r ) => str_starts_with( (string) $r['action'], 'rename' ) ) );
		$unresolved = array_filter( $plan, static fn( array $r ) => ProductTitleNormalizer::ACTION_UNRESOLVED === $r['action'] );
		$too_long   = array_filter( $plan, static fn( array $r ) => strlen( (string) $r['title'] ) > 255 || strlen( (string) $r['name'] ) > 255 );

		if ( ! empty( $changing ) ) {
			\WP_CLI\Utils\format_items(
				$format,
				$changing,
				array( 'ticket_type_id', 'product_id', 'action', 'name_copies', 'title_copies', 'new_name', 'new_title' )
			);
		}

		if ( '' !== $report ) {
			$this->write_csv( $report, $plan );
			\WP_CLI::log( sprintf( 'Full plan written to %s (%d rows).', $report, count( $plan ) ) );
		}

		\WP_CLI::log(
			sprintf(
				'%s — scanned %d ticket product(s): %d to rename, %d unresolved (event/occurrence missing), %d with a name or title over 255 characters.',
				$execute ? 'LIVE' : 'DRY RUN (no changes written)',
				count( $plan ),
				count( $changing ),
				count( $unresolved ),
				count( $too_long )
			)
		);

		if ( ! $execute ) {
			\WP_CLI::log( 'Re-run with --execute to apply.' );
			return;
		}

		$changed = $this->normalizer->apply( $plan );
		\WP_CLI::success( sprintf( '%d ticket product(s) normalized.', $changed ) );
	}

	/**
	 * Write the plan as CSV.
	 *
	 * @param string                           $path Destination path.
	 * @param array<int, array<string, mixed>> $plan Plan rows.
	 * @return void
	 */
	private function write_csv( string $path, array $plan ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- operator-requested local report file from a CLI command; WP_Filesystem is not initialised in WP-CLI.
		$handle = fopen( $path, 'w' );
		if ( false === $handle ) {
			\WP_CLI::warning( sprintf( 'Could not open %s for writing; report skipped.', $path ) );
			return;
		}

		$fields = array( 'ticket_type_id', 'product_id', 'action', 'prefix', 'name_copies', 'name', 'new_name', 'title_copies', 'title', 'new_title' );
		fputcsv( $handle, $fields );
		foreach ( $plan as $row ) {
			$line = array();
			foreach ( $fields as $field ) {
				$line[] = (string) ( $row[ $field ] ?? '' );
			}
			fputcsv( $handle, $line );
		}
		fclose( $handle );
		// phpcs:enable
	}
}
