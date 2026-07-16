<?php
/**
 * Coding standards: ignore comment justification test.
 *
 * Asserts that every phpcs:ignore, phpcs:disable, phpstan-ignore-line,
 * phpstan-ignore-next-line, @phpstan-ignore-line, and @phpstan-ignore-next-line
 * comment in production code carries a trailing "-- " justification of at
 * least 10 characters. phpcs:enable lines are exempt (they close a block).
 *
 * @package NetterTechEvents\Tests\Unit\CodingStandards
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\CodingStandards;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Verify every suppression comment has a human-readable justification.
 */
class IgnoreJustificationTest extends TestCase {

	/**
	 * Directories under the plugin root that are scanned for suppressions.
	 *
	 * tests/ is intentionally excluded: test fixtures may legitimately suppress
	 * rules to exercise edge-case code paths.
	 *
	 * @var string[]
	 */
	private static array $scan_dirs = array( 'includes', 'templates', 'blocks' );

	/**
	 * Regex that matches any suppression keyword we care about.
	 * phpcs:enable is excluded — it closes a disable block, no justification needed.
	 */
	private const SUPPRESSION_PATTERN = '/phpcs:ignore|phpcs:disable|phpstan-ignore-line|phpstan-ignore-next-line|@phpstan-ignore/';

	/**
	 * A justification is present when " -- " appears after the keyword and is
	 * followed by at least 10 characters of text (spaces included), OR — for
	 * @phpstan-ignore specifically — when a parenthesized comment of at least
	 * 10 characters follows the identifier. PHPStan's own parser REJECTS the
	 * " -- " form on @phpstan-ignore (ignore.parseError, non-ignorable), so
	 * parentheses are the only justification syntax PHPStan accepts; this went
	 * unnoticed while the PHPStan gate was dead (see INV-M1 in
	 * .coherence-invariants.md).
	 *
	 * Examples that PASS:
	 *   // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built via prepare(); safe.
	 *   // @phpstan-ignore return.type (mixed bag resolved by caller, see #123)
	 *
	 * Examples that FAIL:
	 *   // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	 *   // phpcs:disable Generic.CodeAnalysis.DuplicateCode.Found
	 *   // @phpstan-ignore return.type -- PHPStan cannot parse this form.
	 */
	private const JUSTIFICATION_PATTERN = '/--\s*.{10}|@phpstan-ignore[^(]*\(.{10}/';

	/**
	 * Ratchet baseline: number of suppression comments currently lacking justification.
	 *
	 * This value must never increase. To reduce it: add '-- <reason>' to each bare
	 * suppression and lower this constant accordingly. Target is 0.
	 *
	 * @var int
	 */
	private const VIOLATION_BASELINE = 0;

	/**
	 * Every suppression comment in production code must carry a justification.
	 *
	 * Uses a ratchet: the count of unjustified suppressions may not exceed
	 * VIOLATION_BASELINE. When new code is added, new suppressions must include
	 * a justification or the test fails. Existing violations are tracked via the
	 * baseline and must be remediated over time (lower the constant as you fix them).
	 *
	 * @return void
	 */
	public function test_suppression_comment_violations_do_not_increase(): void {
		$plugin_dir = rtrim( NETTERTECH_EVENTS_PLUGIN_DIR, '/' );
		$violations = array();

		foreach ( self::$scan_dirs as $dir ) {
			$abs_dir = $plugin_dir . '/' . $dir;

			if ( ! is_dir( $abs_dir ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $abs_dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);

			/** @var SplFileInfo $file */
			foreach ( $iterator as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}

				$path  = $file->getRealPath();
				$lines = file( $path, FILE_IGNORE_NEW_LINES );

				if ( false === $lines ) {
					continue;
				}

				foreach ( $lines as $index => $line ) {
					// Skip phpcs:enable — closing a disable block; no justification required.
					if ( false !== strpos( $line, 'phpcs:enable' ) ) {
						continue;
					}

					if ( ! preg_match( self::SUPPRESSION_PATTERN, $line ) ) {
						continue;
					}

					if ( ! preg_match( self::JUSTIFICATION_PATTERN, $line ) ) {
						$rel_path     = str_replace( $plugin_dir . '/', '', $path );
						$line_number  = $index + 1;
						$violations[] = sprintf( '%s:%d: %s', $rel_path, $line_number, trim( $line ) );
					}
				}
			}
		}

		$count = count( $violations );

		$this->assertLessThanOrEqual(
			self::VIOLATION_BASELINE,
			$count,
			sprintf(
				"Suppression comment violations increased from baseline %d to %d.\n" .
				"Every new phpcs:ignore / phpcs:disable / @phpstan-ignore must include '-- <reason>'.\n" .
				"New violation(s):\n\n%s",
				self::VIOLATION_BASELINE,
				$count,
				implode( "\n", $violations )
			)
		);

		if ( $count < self::VIOLATION_BASELINE ) {
			$this->addWarning(
				sprintf(
					'Violation count (%d) is below baseline (%d). Lower VIOLATION_BASELINE to %d to lock in the improvement.',
					$count,
					self::VIOLATION_BASELINE,
					$count
				)
			);
		}
	}

	/**
	 * Smoke-test: verify the scanner actually finds suppressions (guards against
	 * a misconfigured NETTERTECH_EVENTS_PLUGIN_DIR or missing directories returning a false pass).
	 *
	 * @return void
	 */
	public function test_scanner_finds_suppressions_in_scope(): void {
		$plugin_dir        = rtrim( NETTERTECH_EVENTS_PLUGIN_DIR, '/' );
		$total_found       = 0;

		foreach ( self::$scan_dirs as $dir ) {
			$abs_dir = $plugin_dir . '/' . $dir;

			if ( ! is_dir( $abs_dir ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $abs_dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);

			/** @var SplFileInfo $file */
			foreach ( $iterator as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}

				$path  = $file->getRealPath();
				$lines = file( $path, FILE_IGNORE_NEW_LINES );

				if ( false === $lines ) {
					continue;
				}

				foreach ( $lines as $line ) {
					if ( false !== strpos( $line, 'phpcs:enable' ) ) {
						continue;
					}
					if ( preg_match( self::SUPPRESSION_PATTERN, $line ) ) {
						++$total_found;
					}
				}
			}
		}

		$this->assertGreaterThan(
			0,
			$total_found,
			'Scanner found zero suppression comments — NETTERTECH_EVENTS_PLUGIN_DIR may be misconfigured or all suppression comments were removed.'
		);
	}
}
