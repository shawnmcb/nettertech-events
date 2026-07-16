<?php
/**
 * Reduced-motion CSS static analysis tests.
 *
 * Verifies that all public-facing CSS transitions and animations have
 * corresponding prefers-reduced-motion overrides, supporting the
 * readme.txt claim that the plugin respects reduced motion preferences.
 *
 * @package NetterTechEvents\Tests\Unit\Accessibility
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Accessibility;

/**
 * Test reduced-motion CSS coverage for public-facing stylesheets.
 *
 * Admin CSS files (assets/css/admin/) are excluded — the claim in
 * readme.txt is scoped to public-facing views only.
 */
class ReducedMotionCssTest extends \NetterTechEventsTestCase {

	/**
	 * Public CSS files to audit (relative to plugin root, excluding admin/).
	 *
	 * @return array<array{string}>
	 */
	public static function public_css_provider(): array {
		$plugin_root = dirname( __DIR__, 3 );
		$css_dir     = $plugin_root . '/assets/css';

		$files = array();
		if ( ! is_dir( $css_dir ) ) {
			return $files;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $css_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$path = $file->getRealPath();
			// Exclude minified files.
			if ( str_ends_with( $path, '.min.css' ) ) {
				continue;
			}
			// Exclude admin-only stylesheets.
			if ( str_contains( $path, '/assets/css/admin/' ) ) {
				continue;
			}
			$files[] = array( $path );
		}

		return $files;
	}

	/**
	 * @dataProvider public_css_provider
	 * @param string $css_file Absolute path to the CSS file.
	 */
	public function test_public_css_has_reduced_motion_if_animated( string $css_file ): void {
		$content = file_get_contents( $css_file );
		$this->assertNotFalse( $content, "Could not read CSS file: {$css_file}" );

		$has_motion = (
			preg_match( '/\banimation\s*:/i', $content ) ||
			preg_match( '/\btransition\s*:/i', $content ) ||
			preg_match( '/@keyframes\b/i', $content )
		);

		if ( ! $has_motion ) {
			$this->addToAssertionCount( 1 );
			return;
		}

		$has_override = (bool) preg_match( '/prefers-reduced-motion/i', $content );

		$this->assertTrue(
			$has_override,
			sprintf(
				'Public CSS file %s declares animations/transitions but has no @media (prefers-reduced-motion) override.',
				basename( $css_file )
			)
		);
	}
}
