<?php
/**
 * Snapshot test for the QR-code logo-options template (T4.2.4 Metaboxes cluster).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Metaboxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Metaboxes\Presenters\QRLogoOptionsPresenter;
use NetterTechEvents\Tests\Snapshots\SnapshotTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pins the QR-code logo-options template output to a stored snapshot.
 *
 * @covers \NetterTechEvents\Admin\Metaboxes\Presenters\QRLogoOptionsPresenter
 */
final class QRLogoOptionsSnapshotTest extends TestCase {

	use SnapshotTestTrait;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		// WP-core helpers used by the template.
		Functions\when( 'checked' )->alias(
			static function ( $value, $compare = true, $echo = true ): string {
				$out = ( (string) $value === (string) $compare ) ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				return $out;
			}
		);
		Functions\when( 'disabled' )->alias(
			static function ( $value, $compare = true, $echo = true ): string {
				$out = ( (string) $value === (string) $compare ) ? ' disabled="disabled"' : '';
				if ( $echo ) {
					echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				return $out;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Default mode with no site logo: Site option is disabled, Default is selected.
	 *
	 * @group decision
	 */
	public function test_renders_default_mode_no_site_logo(): void {
		$presenter = new QRLogoOptionsPresenter(
			'default', 0, '', '', 'No Logo'
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/qr-logo-options.php',
			$presenter,
			'default_no_site_logo'
		);
	}

	/**
	 * Custom mode with attached logo: Custom option is selected and thumbnail renders.
	 *
	 * @group decision
	 */
	public function test_renders_custom_mode_with_logo(): void {
		$presenter = new QRLogoOptionsPresenter(
			'custom',
			42,
			'https://example.test/wp-content/uploads/2026/custom.png',
			'https://example.test/wp-content/uploads/2026/site-logo.png',
			'Site Logo'
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/qr-logo-options.php',
			$presenter,
			'custom_mode_with_logo'
		);
	}

	/**
	 * Site mode with site-logo URL available: Site option is selected and shows img thumbnail.
	 *
	 * @group decision
	 */
	public function test_renders_site_mode_with_site_logo(): void {
		$presenter = new QRLogoOptionsPresenter(
			'site',
			0,
			'',
			'https://example.test/wp-content/uploads/2026/site-logo.png',
			'Site Logo'
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/qr-logo-options.php',
			$presenter,
			'site_mode_with_site_logo'
		);
	}
}
