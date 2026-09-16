<?php
/**
 * Assert that every changed executable line in a push diff is covered by tests.
 *
 * Infection's git-diff mode is line-aware: a diff that touches only comments,
 * docblocks or whitespace inside mutable files generates zero mutants, and
 * 0-of-0 reads as MSI 0%. The pre-push hook exempts that case from the mutation
 * gate, and this check is what makes the exemption safe: a zero-mutant diff is
 * only unenforceable when the lines it changed are already exercised by the
 * suite. A changed line that is executable and unexercised means the gate has
 * nothing to enforce because the code has no tests, which is the opposite of a
 * pass.
 *
 * Usage:
 *   php scripts/check-diff-coverage.php <diff-base> <coverage-dir> [dir...]
 *   php scripts/check-diff-coverage.php <diff-base> <coverage-dir> \
 *       --lines-file=<path> [dir...]
 *
 * <coverage-dir> is the PHPUnit XML coverage report directory that Infection's
 * initial test run writes (`<tmpDir>/infection/coverage-xml`); the directory
 * holding index.xml, or its parent, are both accepted. The trailing directories
 * scope the diff and must match infection.json5 source.directories.
 *
 * --lines-file replaces the git diff with an explicit list of changed lines,
 * one `path:line` or `path:start-end` entry per line. It exists so the two
 * outcomes of this check can be exercised without a mutation run.
 *
 * Exit 0: every changed line is non-executable or covered.
 * Exit 1: at least one changed executable line has no covering test, or the
 * coverage report could not be read.
 *
 * @package NetterTechEvents\Tooling
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.PHP.DiscouragedPHPFunctions -- CLI tooling script; no web context, local file reads only.

use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingFileAnalyser;

$nettertech_events_plugin_dir = dirname( __DIR__ );

require_once $nettertech_events_plugin_dir . '/vendor/autoload.php';

/**
 * Read the covered line numbers of every file in a PHPUnit XML coverage report.
 *
 * The report lists a `<line>` element only for lines that at least one test
 * executed, so absence from the returned set means "not covered", never
 * "not executable" — executability is decided separately from the source.
 *
 * @param string $index_path Absolute path to the report's index.xml.
 * @return array<string, array<int, bool>> Absolute source path => covered line set.
 */
function nettertech_events_read_coverage( string $index_path ): array {
	libxml_use_internal_errors( true );

	$index = new DOMDocument();
	if ( ! $index->load( $index_path ) ) {
		return array();
	}

	$projects = $index->getElementsByTagName( 'project' );
	if ( 0 === $projects->length ) {
		return array();
	}

	$source_root = rtrim( $projects->item( 0 )->getAttribute( 'source' ), '/' );
	$report_dir  = dirname( $index_path );
	$covered     = array();

	foreach ( $index->getElementsByTagName( 'file' ) as $file_node ) {
		$href = $file_node->getAttribute( 'href' );
		if ( '' === $href || '.xml' !== substr( $href, -4 ) ) {
			continue;
		}

		$source_path = $source_root . '/' . substr( $href, 0, -4 );
		$report_path = $report_dir . '/' . $href;
		$lines       = array();

		$report = new DOMDocument();
		if ( $report->load( $report_path ) ) {
			$xpath = new DOMXPath( $report );
			$xpath->registerNamespace( 'p', 'https://schema.phpunit.de/coverage/1.0' );

			$line_nodes = $xpath->query( '//p:coverage/p:line' );
			if ( false === $line_nodes || 0 === $line_nodes->length ) {
				$line_nodes = $xpath->query( '//coverage/line' );
			}

			foreach ( ( false === $line_nodes ? array() : $line_nodes ) as $line_node ) {
				$number = (int) $line_node->getAttribute( 'nr' );
				if ( $number > 0 ) {
					$lines[ $number ] = true;
				}
			}
		}

		$covered[ $source_path ] = $lines;
	}

	return $covered;
}

/**
 * Collect the changed line numbers of a diff, keyed by repository-relative path.
 *
 * @param string   $diff_base   Revision the push is measured against.
 * @param string[] $scope_dirs  Directories the diff is limited to.
 * @return array<string, array<int, bool>> Relative path => changed line set.
 */
function nettertech_events_changed_lines_from_git( string $diff_base, array $scope_dirs ): array {
	$command = 'git diff -U0 ' . escapeshellarg( $diff_base . '...HEAD' );
	if ( array() !== $scope_dirs ) {
		$command .= ' --';
		foreach ( $scope_dirs as $dir ) {
			$command .= ' ' . escapeshellarg( $dir );
		}
	}

	$output = array();
	exec( $command . ' 2>/dev/null', $output );

	return nettertech_events_parse_unified_diff( $output );
}

/**
 * Extract the added-line ranges of a unified diff produced with -U0.
 *
 * @param string[] $diff_lines Raw diff output.
 * @return array<string, array<int, bool>> Relative path => changed line set.
 */
function nettertech_events_parse_unified_diff( array $diff_lines ): array {
	$changed = array();
	$current = null;

	foreach ( $diff_lines as $line ) {
		if ( 0 === strpos( $line, '+++ ' ) ) {
			$path    = substr( $line, 4 );
			$current = ( '/dev/null' === $path ) ? null : preg_replace( '#^b/#', '', $path );
			continue;
		}

		if ( null === $current || 0 !== strpos( $line, '@@' ) ) {
			continue;
		}

		if ( 1 !== preg_match( '/^@@ -\S+ \+(\d+)(?:,(\d+))? @@/', $line, $matches ) ) {
			continue;
		}

		$start = (int) $matches[1];
		$count = isset( $matches[2] ) ? (int) $matches[2] : 1;
		for ( $offset = 0; $offset < $count; $offset++ ) {
			$changed[ $current ][ $start + $offset ] = true;
		}
	}

	return $changed;
}

/**
 * Read changed lines from an explicit `path:line` / `path:start-end` list.
 *
 * @param string $lines_file Absolute or relative path to the list.
 * @return array<string, array<int, bool>> Relative path => changed line set.
 */
function nettertech_events_changed_lines_from_file( string $lines_file ): array {
	$changed = array();
	$raw     = file( $lines_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

	foreach ( (array) $raw as $entry ) {
		$entry = trim( $entry );
		if ( '' === $entry || '#' === $entry[0] ) {
			continue;
		}

		$split = strrpos( $entry, ':' );
		if ( false === $split ) {
			continue;
		}

		$path  = substr( $entry, 0, $split );
		$range = explode( '-', substr( $entry, $split + 1 ) );
		$start = (int) $range[0];
		$end   = isset( $range[1] ) ? (int) $range[1] : $start;

		for ( $number = $start; $number <= $end; $number++ ) {
			$changed[ $path ][ $number ] = true;
		}
	}

	return $changed;
}

$nettertech_events_args       = array_slice( $argv, 1 );
$nettertech_events_lines_file = '';
$nettertech_events_positional = array();

foreach ( $nettertech_events_args as $nettertech_events_arg ) {
	if ( 0 === strpos( $nettertech_events_arg, '--lines-file=' ) ) {
		$nettertech_events_lines_file = substr( $nettertech_events_arg, strlen( '--lines-file=' ) );
		continue;
	}
	$nettertech_events_positional[] = $nettertech_events_arg;
}

if ( count( $nettertech_events_positional ) < 2 ) {
	fwrite( STDERR, "usage: check-diff-coverage.php <diff-base> <coverage-dir> [--lines-file=<path>] [dir...]\n" );
	exit( 1 );
}

$nettertech_events_diff_base    = array_shift( $nettertech_events_positional );
$nettertech_events_coverage_dir = rtrim( array_shift( $nettertech_events_positional ), '/' );
$nettertech_events_scope_dirs   = $nettertech_events_positional;

$nettertech_events_index = $nettertech_events_coverage_dir . '/index.xml';
if ( ! is_file( $nettertech_events_index ) ) {
	$nettertech_events_index = $nettertech_events_coverage_dir . '/coverage-xml/index.xml';
}

if ( ! is_file( $nettertech_events_index ) ) {
	fwrite( STDERR, "check-diff-coverage: no coverage index under {$nettertech_events_coverage_dir} — cannot prove the changed lines are covered\n" );
	exit( 1 );
}

$nettertech_events_covered = nettertech_events_read_coverage( $nettertech_events_index );
if ( array() === $nettertech_events_covered ) {
	fwrite( STDERR, "check-diff-coverage: coverage index {$nettertech_events_index} lists no files\n" );
	exit( 1 );
}

$nettertech_events_changed = '' !== $nettertech_events_lines_file
	? nettertech_events_changed_lines_from_file( $nettertech_events_lines_file )
	: nettertech_events_changed_lines_from_git( $nettertech_events_diff_base, $nettertech_events_scope_dirs );

$nettertech_events_analyser  = new ParsingFileAnalyser( false, false );
$nettertech_events_uncovered = array();

foreach ( $nettertech_events_changed as $nettertech_events_path => $nettertech_events_lines ) {
	$nettertech_events_absolute = ( '/' === $nettertech_events_path[0] )
		? $nettertech_events_path
		: $nettertech_events_plugin_dir . '/' . $nettertech_events_path;

	// A path the diff deleted has no line left to exercise, so it cannot hold
	// an unexercised one.
	if ( ! is_file( $nettertech_events_absolute ) ) {
		continue;
	}

	$nettertech_events_real = realpath( $nettertech_events_absolute );

	try {
		$nettertech_events_executable = $nettertech_events_analyser->executableLinesIn( $nettertech_events_real );
	} catch ( Throwable $nettertech_events_error ) {
		fwrite( STDERR, "check-diff-coverage: cannot analyse {$nettertech_events_path}: " . $nettertech_events_error->getMessage() . "\n" );
		exit( 1 );
	}

	$nettertech_events_file_covered = $nettertech_events_covered[ $nettertech_events_real ] ?? null;

	foreach ( array_keys( $nettertech_events_lines ) as $nettertech_events_line ) {
		if ( ! isset( $nettertech_events_executable[ $nettertech_events_line ] ) ) {
			continue;
		}

		// A file absent from the report went unloaded by the suite, so every
		// executable line in it is unexercised.
		if ( null === $nettertech_events_file_covered || ! isset( $nettertech_events_file_covered[ $nettertech_events_line ] ) ) {
			$nettertech_events_uncovered[] = $nettertech_events_path . ':' . $nettertech_events_line;
		}
	}
}

if ( array() !== $nettertech_events_uncovered ) {
	fwrite( STDERR, "check-diff-coverage: changed executable lines with no covering test:\n" );
	foreach ( $nettertech_events_uncovered as $nettertech_events_entry ) {
		fwrite( STDERR, '  ' . $nettertech_events_entry . "\n" );
	}
	exit( 1 );
}

echo "check-diff-coverage: every changed executable line is covered\n";
exit( 0 );
