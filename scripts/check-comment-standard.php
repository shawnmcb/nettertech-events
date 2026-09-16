<?php
/**
 * Reject comments that carry history, provenance, or a moment instead of a constraint.
 *
 * A comment must still be true after the next three releases ship. A comment
 * that dates itself is stale the moment it is written and misleads the next
 * maintainer. Rationale tied to a moment belongs in the commit message; issue
 * linkage belongs in the branch name.
 *
 * Scans comment text only. Strings, code, `@since` tags and `translators:`
 * lines are never matched. The full rule set lives in docs/DEVELOPER-GUIDE.md
 * under "Comments".
 *
 * @package NetterTechEventsRentals\Tooling
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions -- CLI tooling script; no web context, local file reads only.

/**
 * Directories scanned, relative to the plugin root.
 *
 * @var string[]
 */
$nettertech_events_roots = array(
	'includes',
	'templates',
	'assets/js',
	'assets/css',
	'blocks',
	'tests',
	'scripts',
	'.githooks',
);

/**
 * Deny patterns, applied to comment text only.
 *
 * Each entry is label => PCRE. The label is what a failing line reports, so it
 * names the rule that was broken rather than the regex that caught it.
 *
 * @var array<string, string>
 */
$nettertech_events_deny = array(
	'tracker id'         => '/\b(?:NTE|SEC|ARCH|GAP|ADR|SA|QG|ENH|INV|BUG|FR|TICKET|ISSUE|Q)-[A-Z]?\d+\b|\bspec-\d+\b/i',
	'plan or task label' => '/\bT\d+\.\d+\b|\b(?:chunk|wave|phase)\s*[0-9]+[a-z]?\b|\brehearsal\s+(?:run|pass|phase|step)\b|\bthe plan\b|\bbacklog item\b/i',
	'audit cycle'        => '/\baudit\s+(?:19|20)\d{2}|\baudit\s+(?:GAP|ARCH|SEC|SA|G)-|\baudit\s+finding\b/i',
	'dated rationale'    => '/(?:audit|ruling|ruled|as of|decided|resolved|fixed|added|updated|shipped|landed|reviewed|since)\s+(?:on\s+)?(?:19|20)\d{2}-\d{2}-\d{2}|\((?:19|20)\d{2}-\d{2}-\d{2}\)/i',
	'moment word'        => '/\b(?:currently|for now|for the time being|at present|at the moment|going forward|temporarily|as things stand)\b/i',
	'history'            => '/(?<!\bbe )(?<!\bbeen )(?<!\bbeing )\bused to\b|\b(?:no longer|was never|previously|originally|ported from|back then|in the old)\b|\b(?:before|prior to) this\s+(?:change|fix|class|patch|release|commit|version|landed|shipped)\b/i',
	'pending-work note'  => '/\buntil\s+\S+\s+(?:lands|ships|arrives|is merged|is built|is fixed|exists)\b|\buntil then\b/i',
	'sync note'          => '/\bkeep(?:ing)?\s+in\s+sync\b/i',
	'precedent'          => '/\b(?:mirrors?|mirroring|matches|matching|same as|identical to|already does)\s+(?:the\s+)?(?:pro|seating|migrator|rentals|base plugin|core plugin)\b/i',
	'tracker link'       => '#https?://\S*(?:gitlab|github)\S*/(?:issues|merge_requests|pull|-/work_items)#i',
);

/**
 * Lines exempt from every pattern: metadata, not prose.
 *
 * @var string
 */
$nettertech_events_exempt = '/^\s*\*?\s*@since\b|translators:/i';

/**
 * Collect the comment text of one file as line number => text.
 *
 * PHP is tokenised so that a match can never come from a string literal.
 * Other languages fall back to line shapes, with `://` skipped so a URL in a
 * string is not mistaken for a trailing comment, and `${var#trim}` skipped so
 * a shell parameter expansion is not mistaken for a shell comment.
 *
 * @param string $nettertech_events_path File to read.
 * @param bool   $nettertech_events_shell Treat `#` as the comment marker.
 * @return array<int, string> Line number => comment text on that line.
 */
function nettertech_events_comment_lines( string $nettertech_events_path, bool $nettertech_events_shell = false ): array {
	$nettertech_events_src   = (string) file_get_contents( $nettertech_events_path );
	$nettertech_events_lines = array();
	$nettertech_events_ext   = strtolower( (string) pathinfo( $nettertech_events_path, PATHINFO_EXTENSION ) );

	if ( $nettertech_events_shell ) {
		foreach ( explode( "\n", $nettertech_events_src ) as $nettertech_events_i => $nettertech_events_raw ) {
			if ( 0 === $nettertech_events_i && str_starts_with( $nettertech_events_raw, '#!' ) ) {
				continue;
			}
			if ( preg_match( '/(?:^|\s)#(.*)$/', $nettertech_events_raw, $nettertech_events_m ) && '' !== trim( $nettertech_events_m[1] ) ) {
				$nettertech_events_lines[ $nettertech_events_i + 1 ] = $nettertech_events_m[1];
			}
		}

		return $nettertech_events_lines;
	}

	if ( 'php' === $nettertech_events_ext ) {
		foreach ( token_get_all( $nettertech_events_src ) as $nettertech_events_token ) {
			if ( ! is_array( $nettertech_events_token ) ) {
				continue;
			}
			if ( T_COMMENT !== $nettertech_events_token[0] && T_DOC_COMMENT !== $nettertech_events_token[0] ) {
				continue;
			}
			$nettertech_events_no = $nettertech_events_token[2];
			foreach ( explode( "\n", $nettertech_events_token[1] ) as $nettertech_events_i => $nettertech_events_text ) {
				$nettertech_events_lines[ $nettertech_events_no + $nettertech_events_i ] = $nettertech_events_text;
			}
		}

		return $nettertech_events_lines;
	}

	$nettertech_events_in_block = false;
	foreach ( explode( "\n", $nettertech_events_src ) as $nettertech_events_i => $nettertech_events_raw ) {
		$nettertech_events_no   = $nettertech_events_i + 1;
		$nettertech_events_text = '';

		if ( $nettertech_events_in_block ) {
			$nettertech_events_text = $nettertech_events_raw;
			if ( false !== strpos( $nettertech_events_raw, '*/' ) ) {
				$nettertech_events_in_block = false;
			}
		} elseif ( preg_match( '#/\*#', $nettertech_events_raw ) ) {
			$nettertech_events_text     = $nettertech_events_raw;
			$nettertech_events_in_block = ! preg_match( '#\*/#', $nettertech_events_raw );
		} elseif ( preg_match( '#(^|[^:\w])//(.*)$#', $nettertech_events_raw, $nettertech_events_m ) ) {
			$nettertech_events_text = $nettertech_events_m[2];
		}

		if ( '' !== trim( $nettertech_events_text ) ) {
			$nettertech_events_lines[ $nettertech_events_no ] = $nettertech_events_text;
		}
	}

	return $nettertech_events_lines;
}

$nettertech_events_root = dirname( __DIR__ );
$nettertech_events_bad  = array();

foreach ( $nettertech_events_roots as $nettertech_events_rel ) {
	$nettertech_events_dir = $nettertech_events_root . '/' . $nettertech_events_rel;
	if ( ! is_dir( $nettertech_events_dir ) ) {
		continue;
	}

	$nettertech_events_it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $nettertech_events_dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $nettertech_events_it as $nettertech_events_file ) {
		$nettertech_events_path = (string) $nettertech_events_file;

		if ( preg_match( '#/(?:node_modules|vendor|build|dist|coverage|test-results|playwright-report)/#', $nettertech_events_path ) ) {
			continue;
		}
		if ( preg_match( '/\.min\.(?:js|css)$/', $nettertech_events_path ) ) {
			continue;
		}
		$nettertech_events_hook  = (bool) preg_match( '#/\.githooks/#', $nettertech_events_path );
		$nettertech_events_shell = $nettertech_events_hook || (bool) preg_match( '/\.sh$/', $nettertech_events_path );

		if ( ! preg_match( '/\.(?:php|js|ts|css|sh)$/', $nettertech_events_path ) && ! $nettertech_events_hook ) {
			continue;
		}

		foreach ( nettertech_events_comment_lines( $nettertech_events_path, $nettertech_events_shell ) as $nettertech_events_no => $nettertech_events_text ) {
			if ( preg_match( $nettertech_events_exempt, $nettertech_events_text ) ) {
				continue;
			}
			foreach ( $nettertech_events_deny as $nettertech_events_label => $nettertech_events_pattern ) {
				if ( preg_match( $nettertech_events_pattern, $nettertech_events_text ) ) {
					$nettertech_events_bad[] = sprintf(
						'%s:%d: %s — %s',
						ltrim( str_replace( $nettertech_events_root . '/', '', $nettertech_events_path ), '/' ),
						$nettertech_events_no,
						$nettertech_events_label,
						trim( $nettertech_events_text )
					);
					break;
				}
			}
		}
	}
}

if ( $nettertech_events_bad ) {
	sort( $nettertech_events_bad );
	echo "check-comment-standard: comments carrying history, provenance, or a moment:\n  "
		. implode( "\n  ", $nettertech_events_bad ) . "\n\n"
		. "State the constraint the code must satisfy, not the story of how it got there.\n"
		. "See docs/DEVELOPER-GUIDE.md, \"Comments\".\n";
	exit( 1 );
}

echo "check-comment-standard: clean.\n";
