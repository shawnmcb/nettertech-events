<?php
/**
 * Snapshot test for the CSV import upload-step template (T4.2.4).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\CsvImport
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\CsvImport;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NetterTechEvents\Admin\CsvImport\Presenters\CsvImportUploadPresenter;
use NetterTechEvents\Tests\Snapshots\SnapshotTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pins the CSV import upload-step template output to a stored snapshot.
 *
 * @covers \NetterTechEvents\Admin\CsvImport\Presenters\CsvImportUploadPresenter
 */
final class CsvImportUploadSnapshotTest extends TestCase {

	use SnapshotTestTrait;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		// WP-core helpers used by the template.
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( $action ): void {
				echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'submit_button' )->alias(
			static function ( $text, $type = 'primary', $name = 'submit', $wrap = true ): void {
				echo '<p class="submit"><input type="submit" name="' . $name . '" value="' . $text . '" class="button button-' . $type . '" /></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Default upload step with the canonical NONCE_ACTION constant.
	 *
	 * @group decision
	 */
	public function test_renders_with_default_configuration(): void {
		$presenter = new CsvImportUploadPresenter( 'nettertech_events_csv_import' );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/csv-import/upload-step.php',
			$presenter,
			'default_configuration'
		);
	}

	/**
	 * Custom max-size label propagates into the description.
	 *
	 * @group decision
	 */
	public function test_renders_with_custom_max_size_label(): void {
		$presenter = new CsvImportUploadPresenter(
			'nettertech_events_csv_import',
			'.csv,.tsv,.txt',
			'10MB'
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/csv-import/upload-step.php',
			$presenter,
			'custom_max_size_label'
		);
	}

	/**
	 * Constrained accept list (CSV only).
	 *
	 * @group decision
	 */
	public function test_renders_with_csv_only_accept_list(): void {
		$presenter = new CsvImportUploadPresenter(
			'nettertech_events_csv_import',
			'.csv'
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/csv-import/upload-step.php',
			$presenter,
			'csv_only_accept_list'
		);
	}
}
