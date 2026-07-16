<?php
/**
 * Snapshot test for the General Settings admin template (T4.2 pilot).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Settings\Presenters\GeneralSettingsPresenter;
use NetterTechEvents\Tests\Snapshots\SnapshotTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pins the General Settings template output to a stored snapshot.
 *
 * Future renders must produce the same canonicalized HTML; any change requires
 * either a deliberate fix (and `UPDATE_SNAPSHOTS=1` regen) or surfaces as a
 * snapshot diff in CI / pre-push.
 *
 * @covers \NetterTechEvents\Admin\Settings\Presenters\GeneralSettingsPresenter
 */
final class GeneralSettingsSectionSnapshotTest extends TestCase {

	use SnapshotTestTrait;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		// WP-core helpers used by the template.
		Functions\when( 'selected' )->alias(
			static function ( $value, $compare = true, $echo = true ): string {
				$out = ( (string) $value === (string) $compare ) ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				return $out;
			}
		);
		Functions\when( 'wp_timezone_choice' )->alias(
			static fn( string $tz ): string => '<option value="' . $tz . '" selected="selected">' . $tz . '</option>'
		);
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Default settings: month view, 10 per page, UTC timezone.
	 *
	 * @group decision
	 */
	public function test_renders_with_default_settings(): void {
		$presenter = new GeneralSettingsPresenter( array() );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/settings/general-section.php',
			$presenter,
			'default_settings'
		);
	}

	/**
	 * Non-default settings: week view, 25 per page, America/Los_Angeles timezone.
	 *
	 * @group decision
	 */
	public function test_renders_with_week_view_and_custom_page_size(): void {
		$presenter = new GeneralSettingsPresenter(
			array(
				'default_view'    => 'week',
				'events_per_page' => 25,
				'timezone'        => 'America/Los_Angeles',
			)
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/settings/general-section.php',
			$presenter,
			'week_view_custom_page_size'
		);
	}

	/**
	 * Edge case: events_per_page is a non-numeric string. Presenter falls back to 10.
	 *
	 * @group decision
	 */
	public function test_renders_with_invalid_events_per_page(): void {
		$presenter = new GeneralSettingsPresenter(
			array(
				'events_per_page' => 'not-a-number',
			)
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/settings/general-section.php',
			$presenter,
			'invalid_events_per_page_falls_back_to_ten'
		);
	}
}
