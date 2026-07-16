<?php
/**
 * Contract test: every registered NTE shortcode must appear in PageContextDetector's map.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

/**
 * Asserts the registration-vs-detector contract for NTE shortcodes.
 *
 * Reads plugin PHP source files statically (no WordPress bootstrap, no shortcode
 * execution). Two assertions are made:
 *
 * 1. Forward: every shortcode registered via add_shortcode() in Plugin.php is
 *    present as a key in PageContextDetector::page_has_nettertech_events_content()'s $shortcodes
 *    map, unless it appears in the self-enqueuing allowlist.
 *
 * 2. Reverse: every key in the detector map corresponds to a shortcode registered
 *    via add_shortcode() in Plugin.php, unless it appears in the dead-entry
 *    allowlist.
 *
 * Both allowlists must be updated whenever production code changes. A failure here
 * means either (a) a new shortcode was added without updating the detector, or
 * (b) a shortcode was removed from production but its detector entry was not
 * cleaned up.
 *
 * @group structural
 */
class PageContextDetectorContractTest extends \NetterTechEventsTestCase {

	/**
	 * Absolute path to the plugin root.
	 *
	 * @var string
	 */
	private string $plugin_root;

	/**
	 * Set up paths.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->plugin_root = dirname( __DIR__, 3 );
	}

	/**
	 * Shortcodes that are registered via add_shortcode() but intentionally absent
	 * from the PageContextDetector map because they self-enqueue their own assets
	 * at render time rather than relying on the detector gate.
	 *
	 * Update this list when a shortcode's enqueue strategy changes.
	 *
	 * @var array<string, string>
	 */
	private const SELF_ENQUEUING_ALLOWLIST = array();

	/**
	 * Keys present in the detector map that have no corresponding add_shortcode()
	 * registration in the plugin source. These are either legacy aliases kept for
	 * forward-compatibility detection, or stale dead entries that should be removed.
	 *
	 * Update this list when registrations are added or dead entries are pruned.
	 *
	 * @var array<string, string>
	 */
	private const DETECTOR_DEAD_ENTRY_ALLOWLIST = array();

	/**
	 * Parse all add_shortcode() calls in a PHP source file and return the tag names.
	 *
	 * Matches: add_shortcode( 'tag_name', ... ) or add_shortcode( "tag_name", ... )
	 *
	 * @param string $file_path Absolute path to the PHP file.
	 * @return array<string>
	 */
	private function extract_registered_shortcodes( string $file_path ): array {
		$source = file_get_contents( $file_path );
		if ( false === $source ) {
			return array();
		}

		$tags = array();
		// Match add_shortcode( 'tag' or add_shortcode( "tag" with optional whitespace.
		if ( preg_match_all(
			"/add_shortcode\s*\(\s*['\"]([^'\"]+)['\"]/",
			$source,
			$matches
		) ) {
			$tags = $matches[1];
		}

		return $tags;
	}

	/**
	 * Parse the $shortcodes array keys from PageContextDetector::page_has_nettertech_events_content().
	 *
	 * Uses regex on source rather than reflection or execution to avoid requiring
	 * a WordPress environment.
	 *
	 * @return array<string>
	 */
	private function extract_detector_map_keys(): array {
		$detector_file = $this->plugin_root . '/includes/Core/PageContextDetector.php';
		$source        = file_get_contents( $detector_file );
		if ( false === $source ) {
			$this->fail( 'Could not read PageContextDetector.php at: ' . $detector_file );
		}

		// Locate the $shortcodes = array( ... ) block inside page_has_nettertech_events_content().
		// Strategy: find the assignment, then extract the array literal up to the
		// closing ); using a multiline match.
		$keys = array();
		if ( preg_match(
			'/\$shortcodes\s*=\s*array\s*\(([^)]+)\)/s',
			$source,
			$block_match
		) ) {
			// Extract quoted keys (left-hand side of each => pair).
			if ( preg_match_all(
				"/['\"]([^'\"]+)['\"]\s*=>/",
				$block_match[1],
				$key_matches
			) ) {
				$keys = $key_matches[1];
			}
		}

		$this->assertNotEmpty(
			$keys,
			'Failed to parse $shortcodes array from PageContextDetector::page_has_nettertech_events_content(). ' .
			'If the method was renamed or the array restructured, update the regex in this test.'
		);

		return $keys;
	}

	/**
	 * Collect all shortcode tags registered via add_shortcode() across scanned
	 * plugin source files.
	 *
	 * Scans Plugin.php (the canonical registration point) plus the Shortcodes/
	 * directory and FrontendServiceProvider in case registration ever moves.
	 *
	 * @return array<string>
	 */
	private function collect_all_registered_shortcodes(): array {
		$scan_files = array(
			$this->plugin_root . '/includes/Core/Plugin.php',
			$this->plugin_root . '/includes/Core/Providers/FrontendServiceProvider.php',
		);

		// Also scan every file in Frontend/Shortcodes/.
		$shortcode_dir = $this->plugin_root . '/includes/Frontend/Shortcodes/';
		if ( is_dir( $shortcode_dir ) ) {
			$iterator = new \DirectoryIterator( $shortcode_dir );
			foreach ( $iterator as $file_info ) {
				if ( $file_info->isFile() && 'php' === $file_info->getExtension() ) {
					$scan_files[] = $file_info->getPathname();
				}
			}
		}

		$all_tags = array();
		foreach ( $scan_files as $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$tags      = $this->extract_registered_shortcodes( $path );
			$all_tags  = array_merge( $all_tags, $tags );
		}

		// Filter to NTE-prefixed shortcodes only (nte_ or nettertech_).
		$all_tags = array_filter(
			$all_tags,
			function ( string $tag ): bool {
				return str_starts_with( $tag, 'nettertech_events_' ) || str_starts_with( $tag, 'nettertech_' );
			}
		);

		return array_values( array_unique( $all_tags ) );
	}

	/**
	 * Forward contract: every registered shortcode is in the detector map (or
	 * declared self-enqueuing).
	 *
	 * A failure here means a new shortcode was added without updating the detector,
	 * which will cause base.css to not load on pages that contain only that shortcode.
	 *
	 * @return void
	 */
	public function test_every_registered_shortcode_is_in_detector_map_or_self_enqueues(): void {
		$registered   = $this->collect_all_registered_shortcodes();
		$detector_map = $this->extract_detector_map_keys();
		$allowlisted  = array_keys( self::SELF_ENQUEUING_ALLOWLIST );

		$this->assertNotEmpty(
			$registered,
			'No registered NTE shortcodes found — scanner may be broken or Plugin.php moved.'
		);

		$missing = array();
		foreach ( $registered as $tag ) {
			if ( ! in_array( $tag, $detector_map, true ) && ! in_array( $tag, $allowlisted, true ) ) {
				$missing[] = $tag;
			}
		}

		$this->assertEmpty(
			$missing,
			sprintf(
				"The following shortcodes are registered via add_shortcode() but are missing from " .
				"PageContextDetector::\$shortcodes map and are not in SELF_ENQUEUING_ALLOWLIST:\n  %s\n\n" .
				"Fix: add each missing tag to PageContextDetector::page_has_nettertech_events_content()'s \$shortcodes " .
				"array, or add it to SELF_ENQUEUING_ALLOWLIST in this test if it genuinely self-enqueues.",
				implode( "\n  ", $missing )
			)
		);
	}

	/**
	 * Reverse contract: every detector map key corresponds to a registered shortcode
	 * (or is declared as an allowlisted dead entry).
	 *
	 * A failure here means a shortcode was removed from production but its detector
	 * entry was not cleaned up — not a safety issue, but a dead-code smell that
	 * could confuse future maintainers.
	 *
	 * @return void
	 */
	public function test_every_detector_map_key_has_a_registered_shortcode_or_is_allowlisted(): void {
		$registered    = $this->collect_all_registered_shortcodes();
		$detector_map  = $this->extract_detector_map_keys();
		$dead_allowed  = array_keys( self::DETECTOR_DEAD_ENTRY_ALLOWLIST );

		$unregistered = array();
		foreach ( $detector_map as $key ) {
			if ( ! in_array( $key, $registered, true ) && ! in_array( $key, $dead_allowed, true ) ) {
				$unregistered[] = $key;
			}
		}

		$this->assertEmpty(
			$unregistered,
			sprintf(
				"The following keys are in PageContextDetector::\$shortcodes but have no corresponding " .
				"add_shortcode() registration and are not in DETECTOR_DEAD_ENTRY_ALLOWLIST:\n  %s\n\n" .
				"Fix: either register the shortcode via add_shortcode(), remove the dead detector entry, " .
				"or add it to DETECTOR_DEAD_ENTRY_ALLOWLIST in this test with an explanation.",
				implode( "\n  ", $unregistered )
			)
		);
	}

	/**
	 * Sanity: the allowlists themselves do not contain stale entries.
	 *
	 * If an entry in SELF_ENQUEUING_ALLOWLIST is added to the detector map,
	 * or an entry in DETECTOR_DEAD_ENTRY_ALLOWLIST is given a real registration,
	 * the allowlist entry becomes stale and should be removed.
	 *
	 * @return void
	 */
	public function test_allowlists_do_not_contain_stale_entries(): void {
		$registered   = $this->collect_all_registered_shortcodes();
		$detector_map = $this->extract_detector_map_keys();

		// A self-enqueuing allowlist entry becomes stale if the tag is now in the
		// detector map (someone added it there — the exception no longer applies).
		$stale_self_enqueuing = array();
		foreach ( array_keys( self::SELF_ENQUEUING_ALLOWLIST ) as $tag ) {
			if ( in_array( $tag, $detector_map, true ) ) {
				$stale_self_enqueuing[] = $tag;
			}
		}

		$this->assertEmpty(
			$stale_self_enqueuing,
			sprintf(
				"SELF_ENQUEUING_ALLOWLIST contains tags that are now in the detector map — remove them " .
				"from the allowlist:\n  %s",
				implode( "\n  ", $stale_self_enqueuing )
			)
		);

		// A dead-entry allowlist entry becomes stale if the tag is now registered.
		$stale_dead_entries = array();
		foreach ( array_keys( self::DETECTOR_DEAD_ENTRY_ALLOWLIST ) as $tag ) {
			if ( in_array( $tag, $registered, true ) ) {
				$stale_dead_entries[] = $tag;
			}
		}

		$this->assertEmpty(
			$stale_dead_entries,
			sprintf(
				"DETECTOR_DEAD_ENTRY_ALLOWLIST contains tags that are now registered via add_shortcode() — " .
				"remove them from the allowlist:\n  %s",
				implode( "\n  ", $stale_dead_entries )
			)
		);
	}
}
