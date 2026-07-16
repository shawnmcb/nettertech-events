<?php
/**
 * Snapshot Test Trait for admin template extraction (T4.2).
 *
 * @package NetterTechEvents\Tests
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Snapshots;

use PHPUnit\Framework\Assert;
use ReflectionClass;

/**
 * Roll-your-own snapshot-assertion helper for admin templates.
 *
 * Use case: render a template with a stub/real presenter, capture the output via
 * ob_start(), compare to a stored snapshot file. First run writes the snapshot;
 * subsequent runs assert equality.
 *
 * Convention: snapshot files live at `tests/Snapshots/<TestClassShortName>/<name>.snap`.
 * Regenerate via `UPDATE_SNAPSHOTS=1 composer test`.
 *
 * Whitespace canonicalization is applied (collapse runs of whitespace within a
 * line, trim each line, remove blank lines). Attribute order is NOT canonicalized.
 *
 */
trait SnapshotTestTrait {

	/**
	 * Render the template with the given presenter and assert output matches stored snapshot.
	 *
	 * @param string $template_path Absolute path to the template file.
	 * @param object $presenter     Bound to $presenter inside the template.
	 * @param string $snapshot_name Identifier (e.g., 'default_layout_grid').
	 * @return void
	 */
	protected function assertSnapshot( string $template_path, object $presenter, string $snapshot_name ): void {
		$snapshot_path = $this->snapshotPath( $snapshot_name );

		ob_start();
		include $template_path;
		$captured = (string) ob_get_clean();
		$actual   = $this->canonicalize( $captured );

		if ( '1' === getenv( 'UPDATE_SNAPSHOTS' ) || ! file_exists( $snapshot_path ) ) {
			if ( ! is_dir( dirname( $snapshot_path ) ) ) {
				mkdir( dirname( $snapshot_path ), 0755, true );
			}
			file_put_contents( $snapshot_path, $actual );
			Assert::assertTrue( true, "Snapshot written: {$snapshot_path}" );
			return;
		}

		$expected = $this->canonicalize( (string) file_get_contents( $snapshot_path ) );
		Assert::assertSame(
			$expected,
			$actual,
			"Snapshot mismatch for {$snapshot_name}. Set UPDATE_SNAPSHOTS=1 to regenerate."
		);
	}

	/**
	 * Compute the snapshot file path for the current test class + name.
	 *
	 * @param string $name Snapshot identifier.
	 * @return string Absolute path under tests/Snapshots/<TestClassShortName>/.
	 */
	private function snapshotPath( string $name ): string {
		$reflection  = new ReflectionClass( static::class );
		$class_short = $reflection->getShortName();
		$file        = (string) $reflection->getFileName();
		// Convention: snapshots live at <plugin>/tests/Snapshots/<TestClassShortName>/<name>.snap.
		// $reflection->getFileName() is in <plugin>/tests/Unit/... ; walk to /tests/, then descend.
		$tests_dir = dirname( $file );
		while ( basename( $tests_dir ) !== 'tests' && $tests_dir !== dirname( $tests_dir ) ) {
			$tests_dir = dirname( $tests_dir );
		}
		return $tests_dir . "/Snapshots/{$class_short}/{$name}.snap";
	}

	/**
	 * Canonicalize HTML for snapshot comparison.
	 *
	 * Collapses runs of whitespace within a line, trims each line, removes blank
	 * lines. Does NOT normalize attribute order, quote style, or self-closing
	 * void elements.
	 *
	 * @param string $html Raw output.
	 * @return string Canonicalized form for stable snapshot comparison.
	 */
	private function canonicalize( string $html ): string {
		$lines = preg_split( '/\R/', $html );
		if ( false === $lines ) {
			return $html;
		}
		$lines = array_map(
			static fn( string $line ): string => (string) preg_replace( '/\s+/', ' ', trim( $line ) ),
			$lines
		);
		$lines = array_filter(
			$lines,
			static fn( string $line ): bool => '' !== $line
		);
		return implode( "\n", $lines );
	}
}
