<?php
/**
 * ShortcodeOutput unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend\Shortcodes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend\Shortcodes;

use Brain\Monkey\Functions;
use NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput;

/**
 * Test ShortcodeOutput allowlist construction.
 *
 * The full kses round-trip contract lives in the integration suite
 * (AllowlistContractTest); these tests pin the allowlist array itself so
 * the style-strip is unit-verifiable without loading WordPress.
 *
 * @coversDefaultClass \NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput
 */
class ShortcodeOutputTest extends \NetterTechEventsTestCase {

	/**
	 * Inline style is stripped from every element the core seed allows it on,
	 * while other attributes survive (NTE-131: behavior belongs to classes in
	 * markup that renders inside third-party pages; WP 7.0's kses would
	 * otherwise let style through).
	 *
	 * @covers ::get_allowlist
	 *
	 * @return void
	 */
	public function test_get_allowlist_strips_style_from_core_seed(): void {
		Functions\when( 'wp_kses_allowed_html' )->justReturn(
			array(
				'div'  => array(
					'style' => true,
					'class' => true,
					'id'    => true,
				),
				'span' => array(
					'style' => true,
					'class' => true,
				),
				'em'   => array(
					'style' => true,
				),
			)
		);

		$allowed = ShortcodeOutput::get_allowlist();

		$this->assertArrayNotHasKey( 'style', $allowed['div'] );
		$this->assertArrayNotHasKey( 'style', $allowed['span'] );
		$this->assertArrayNotHasKey( 'style', $allowed['em'] );
		$this->assertArrayHasKey( 'class', $allowed['div'] );
		$this->assertArrayHasKey( 'id', $allowed['div'] );
	}

	/**
	 * The plugin's own additions (SVG icon support) are merged on top of the
	 * core seed and never carry a style attribute themselves.
	 *
	 * @covers ::get_allowlist
	 *
	 * @return void
	 */
	public function test_get_allowlist_adds_svg_support_without_style(): void {
		Functions\when( 'wp_kses_allowed_html' )->justReturn( array() );

		$allowed = ShortcodeOutput::get_allowlist();

		$this->assertArrayHasKey( 'svg', $allowed );
		$this->assertArrayHasKey( 'path', $allowed );
		$this->assertArrayNotHasKey( 'style', $allowed['svg'] );
		$this->assertTrue( $allowed['svg']['viewBox'] );
	}
}
