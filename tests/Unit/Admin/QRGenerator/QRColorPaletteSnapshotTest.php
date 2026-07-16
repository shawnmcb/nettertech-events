<?php
/**
 * Snapshot test for the QR Generator color-palette template (T4.2.4).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\QRGenerator
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\QRGenerator;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NetterTechEvents\Admin\QRGenerator\Presenters\QRColorPalettePresenter;
use NetterTechEvents\Admin\QRGeneratorPage;
use NetterTechEvents\Tests\Snapshots\SnapshotTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pins the QR color-palette template output to a stored snapshot.
 *
 * @covers \NetterTechEvents\Admin\QRGenerator\Presenters\QRColorPalettePresenter
 */
final class QRColorPaletteSnapshotTest extends TestCase {

	use SnapshotTestTrait;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		// WP-core helpers used by the template.
		Functions\when( 'checked' )->alias(
			static function ( $value, $compare = true, $echo = true ): string {
				$out = ( (bool) $value === (bool) $compare ) ? ' checked="checked"' : '';
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
	 * Default color matches first palette entry (black).
	 *
	 * @group decision
	 */
	public function test_renders_with_black_default(): void {
		$presenter = new QRColorPalettePresenter( '000000', QRGeneratorPage::COLOR_PALETTE );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/qr-generator/color-palette.php',
			$presenter,
			'black_default'
		);
	}

	/**
	 * Default color matches a mid-palette entry (burgundy).
	 *
	 * @group decision
	 */
	public function test_renders_with_burgundy_default(): void {
		$presenter = new QRColorPalettePresenter( '722f37', QRGeneratorPage::COLOR_PALETTE );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/qr-generator/color-palette.php',
			$presenter,
			'burgundy_default'
		);
	}

	/**
	 * Custom palette with a single entry → just one preset row renders.
	 *
	 * @group decision
	 */
	public function test_renders_with_single_entry_palette(): void {
		$presenter = new QRColorPalettePresenter(
			'cc0000',
			array( 'cc0000' => 'Brand Red' )
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/qr-generator/color-palette.php',
			$presenter,
			'single_entry_palette'
		);
	}
}
