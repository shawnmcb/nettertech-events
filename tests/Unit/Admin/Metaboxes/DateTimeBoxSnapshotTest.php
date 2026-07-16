<?php
/**
 * Snapshot test for the Date & Time metabox template (T4.2.4 Metaboxes cluster).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Metaboxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Metaboxes\Presenters\DateTimeBoxPresenter;
use NetterTechEvents\Tests\Snapshots\SnapshotTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Date & Time metabox template output to a stored snapshot.
 *
 * @covers \NetterTechEvents\Admin\Metaboxes\Presenters\DateTimeBoxPresenter
 */
final class DateTimeBoxSnapshotTest extends TestCase {

	use SnapshotTestTrait;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		// WP-core `checked()` helper used by the template.
		Functions\when( 'checked' )->alias(
			static function ( $value, $compare = true, $echo = true ): string {
				$out = ( (string) $value === (string) $compare ) ? ' checked="checked"' : '';
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
	 * New event with no occurrence: empty inputs, all-day unchecked,
	 * end-time collapsed (default settings).
	 *
	 * @group decision
	 */
	public function test_renders_empty_new_event(): void {
		$presenter = new DateTimeBoxPresenter(
			'', '', '', '', false, '', false, false
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/datetime-box.php',
			$presenter,
			'empty_new_event'
		);
	}

	/**
	 * Populated single all-day occurrence with capacity and end-time expanded.
	 *
	 * @group decision
	 */
	public function test_renders_populated_all_day_with_capacity(): void {
		$presenter = new DateTimeBoxPresenter(
			'2026-06-15', '09:00', '2026-06-15', '17:00',
			true, '250', true, false
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/datetime-box.php',
			$presenter,
			'populated_all_day_with_capacity'
		);
	}

	/**
	 * Settings force require_end_time: end-date and end-time carry required attributes.
	 *
	 * @group decision
	 */
	public function test_renders_with_required_end_time(): void {
		$presenter = new DateTimeBoxPresenter(
			'2026-07-04', '18:00', '2026-07-04', '22:00',
			false, '', true, true
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/datetime-box.php',
			$presenter,
			'required_end_time'
		);
	}
}
