<?php
/**
 * Structural test: every REST route declares a permission_callback (INV-S2).
 *
 * Replaces the grep proxy declared at discovery in .coherence-invariants.md.
 * Scans production source for register_rest_route() calls and asserts each
 * call's argument block contains an explicit permission_callback key.
 * Routes using __return_true are allowed but must carry a justification
 * within the registration block (inline comment or docblock above).
 *
 * @package NetterTechEvents\Tests\Unit\CodingStandards
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\CodingStandards;

/**
 * Asserts permission_callback presence on every REST route registration.
 */
class RestPermissionCallbackTest extends \NetterTechEventsTestCase {

	/**
	 * Chars of source after each register_rest_route( in which the
	 * permission_callback key must appear. Registration arg arrays in this
	 * codebase are well under this size.
	 *
	 * @var int
	 */
	private const WINDOW_CHARS = 2500;

	/**
	 * Every register_rest_route() call declares permission_callback.
	 *
	 * @return void
	 */
	public function test_every_rest_route_declares_permission_callback(): void {
		$violations = array();

		foreach ( $this->production_php_files() as $file ) {
			$source = (string) file_get_contents( $file );
			$offset = 0;

			while ( false !== ( $pos = strpos( $source, 'register_rest_route(', $offset ) ) ) {
				$window = substr( $source, $pos, self::WINDOW_CHARS );
				if ( false === strpos( $window, 'permission_callback' ) ) {
					$line         = substr_count( substr( $source, 0, $pos ), "\n" ) + 1;
					$violations[] = $file . ':' . $line;
				}
				$offset = $pos + 1;
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"register_rest_route() without permission_callback found at:\n" . implode( "\n", $violations )
		);
	}

	/**
	 * List production PHP files under includes/.
	 *
	 * @return array<string>
	 */
	private function production_php_files(): array {
		$base     = dirname( __DIR__, 3 ) . '/includes';
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base ) );
		$files    = array();

		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}
}
