<?php
/**
 * WP-CLI command: normalize events.created_at to UTC (NTE-131).
 *
 * @package NetterTechEvents
 */

declare( strict_types=1 );

namespace NetterTechEvents\Cli;

use NetterTechEvents\Database\Schema;

/**
 * Normalizes existing `events.created_at` values from environment-local storage
 * to UTC.
 *
 * Background (NTE-131): `events.created_at` is declared `datetime DEFAULT
 * CURRENT_TIMESTAMP`. Historically the column was filled by MySQL using the DB
 * session timezone, which is environment-dependent:
 *
 *   - oz dev MySQL runs `session_tz = SYSTEM`, so CURRENT_TIMESTAMP wrote
 *     SITE-LOCAL (Central) values.
 *   - celticjunction.org (Flywheel) runs the DB session in UTC, so existing
 *     values are already UTC.
 *
 * The NTE-131 write-path fix now sets `created_at` explicitly with
 * `gmdate( 'Y-m-d H:i:s' )` (UTC) for all NEW events, and the admin renderers
 * read it back with `get_date_from_gmt()`. This command back-fills EXISTING
 * rows so they are consistent with the new write path.
 *
 * Environment awareness: the command detects whether MySQL is writing local or
 * UTC by comparing `NOW()` (session tz) with `UTC_TIMESTAMP()`. If the offset is
 * zero (UTC environment, e.g. prod) it makes NO changes — those rows are already
 * UTC and must not be shifted. Only environments whose MySQL stores local
 * (e.g. oz) are converted.
 *
 * Safety:
 *   - `--dry-run` is the DEFAULT. A shift only happens with explicit `--execute`.
 *   - An idempotency option (`nettertech_events_created_at_utc_normalized`) is set
 *     after a successful `--execute`, and re-runs refuse to shift again unless
 *     `--force` is passed. This prevents double-shifting rows (critical, because
 *     once the write-path fix is live, new UTC rows are indistinguishable by
 *     value from old local rows).
 *   - `updated_at` is preserved at its existing value (it is not surfaced in the
 *     admin and continues to be written local by MySQL ON UPDATE, so it is
 *     intentionally left un-normalized; the update merely avoids the automatic
 *     ON UPDATE bump).
 *
 * Intended use: run ONCE, at the same time the write-path fix is deployed,
 * BEFORE new events are created on the affected environment.
 */
class NormalizeCreatedAtCommand {

	/**
	 * Option flag recording that a successful normalization has already run.
	 *
	 * @var string
	 */
	private const DONE_OPTION = 'nettertech_events_created_at_utc_normalized';

	/**
	 * Normalize existing events.created_at values to UTC.
	 *
	 * ## OPTIONS
	 *
	 * [--execute]
	 * : Apply the changes. Without this flag the command runs in dry-run mode and
	 * makes no database writes.
	 *
	 * [--force]
	 * : Re-run the conversion even if it has already been applied once on this
	 * site. Use only if you are certain rows still need shifting.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview the conversion (no writes).
	 *     wp nettertech-events normalize-created-at
	 *
	 *     # Apply the conversion.
	 *     wp nettertech-events normalize-created-at --execute
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args (execute, force).
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		global $wpdb;

		$execute = isset( $assoc_args['execute'] );
		$force   = isset( $assoc_args['force'] );
		$table   = Schema::table( 'events' );

		// Detect the MySQL session storage timezone offset relative to UTC.
		// 0 => the session writes UTC (e.g. prod); non-zero => it writes local.
		$offset_seconds = (int) $wpdb->get_var( 'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())' );

		\WP_CLI::log( sprintf( 'Events table: %s', $table ) );
		\WP_CLI::log( sprintf( 'MySQL session offset from UTC: %d seconds.', $offset_seconds ) );

		if ( 0 === $offset_seconds ) {
			\WP_CLI::success(
				'MySQL session is UTC — existing created_at values are already UTC. No shift needed (this is the expected state on UTC environments such as production).'
			);
			return;
		}

		\WP_CLI::log(
			'MySQL session is NOT UTC — existing created_at values were stored site-local and will be converted to UTC.'
		);

		if ( $execute && ! $force && get_option( self::DONE_OPTION ) ) {
			\WP_CLI::warning(
				'Normalization has already been applied on this site (option "' . self::DONE_OPTION . '" is set). Refusing to shift again. Pass --force to override.'
			);
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name from trusted constant; one-time maintenance command.
		$rows = $wpdb->get_results( "SELECT id, created_at, updated_at FROM {$table} WHERE created_at IS NOT NULL ORDER BY id ASC" );

		if ( empty( $rows ) ) {
			\WP_CLI::success( 'No event rows with a created_at value found. Nothing to do.' );
			return;
		}

		$total     = count( $rows );
		$converted = 0;
		$sample    = array();

		foreach ( $rows as $row ) {
			$local = (string) $row->created_at;
			// get_gmt_from_date is the DST-aware inverse of get_date_from_gmt: it
			// interprets the value in the site timezone (wp_timezone) and returns UTC.
			$utc = get_gmt_from_date( $local );

			if ( $utc === $local ) {
				continue;
			}

			if ( count( $sample ) < 10 ) {
				$sample[] = array(
					'id'         => (int) $row->id,
					'created_at' => $local,
					'utc'        => $utc,
				);
			}

			if ( $execute ) {
				// Preserve updated_at explicitly so the ON UPDATE CURRENT_TIMESTAMP
				// trigger does not bump it during this maintenance write.
				$result = $wpdb->update(
					$table,
					array(
						'created_at' => $utc,
						'updated_at' => (string) $row->updated_at,
					),
					array( 'id' => (int) $row->id ),
					array( '%s', '%s' ),
					array( '%d' )
				);

				if ( false === $result ) {
					\WP_CLI::warning( sprintf( 'Failed to update event #%d.', (int) $row->id ) );
					continue;
				}
			}

			++$converted;
		}

		if ( ! empty( $sample ) ) {
			\WP_CLI::log( 'Sample of conversions (site-local -> UTC):' );
			\WP_CLI\Utils\format_items( 'table', $sample, array( 'id', 'created_at', 'utc' ) );
		}

		if ( $execute ) {
			update_option( self::DONE_OPTION, gmdate( 'Y-m-d H:i:s' ), false );
			\WP_CLI::success( sprintf( 'Converted %d of %d event created_at values to UTC.', $converted, $total ) );
		} else {
			\WP_CLI::success(
				sprintf( 'Dry run: %d of %d event created_at values WOULD be converted to UTC. Re-run with --execute to apply.', $converted, $total )
			);
		}
	}
}
