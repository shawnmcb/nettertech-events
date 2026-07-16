<?php
/**
 * Snapshot test for the Attendees filters admin template (T4.2.4).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Attendees
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Attendees;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Attendees\Presenters\AttendeesFiltersPresenter;
use NetterTechEvents\Tests\Snapshots\SnapshotTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Attendees filters template output to a stored snapshot.
 *
 * Future renders must produce the same canonicalized HTML; any change requires
 * either a deliberate fix (and `UPDATE_SNAPSHOTS=1` regen) or surfaces as a
 * snapshot diff in CI / pre-push.
 *
 * @covers \NetterTechEvents\Admin\Attendees\Presenters\AttendeesFiltersPresenter
 */
final class AttendeesFiltersSnapshotTest extends TestCase {

	use SnapshotTestTrait;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		// WP-core helper used by the template.
		Functions\when( 'selected' )->alias(
			static function ( $value, $compare = true, $echo = true ): string {
				$out = ( (string) $value === (string) $compare ) ? ' selected="selected"' : '';
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
	 * No filters active, no occurrences available.
	 *
	 * @group decision
	 */
	public function test_renders_with_no_filters_and_no_occurrences(): void {
		$presenter = new AttendeesFiltersPresenter(
			0,
			0,
			'',
			'',
			'',
			array(),
			'nettertech-events-attendees',
			'http://example.test/wp-admin/admin.php?page=nettertech-events-attendees'
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/attendees/filters.php',
			$presenter,
			'no_filters_no_occurrences'
		);
	}

	/**
	 * Status filter active + search query active; Clear button should render.
	 *
	 * @group decision
	 */
	public function test_renders_with_status_and_search_filters_active(): void {
		$presenter = new AttendeesFiltersPresenter(
			0,
			0,
			'jane',
			'confirmed',
			'',
			array(),
			'nettertech-events-attendees',
			'http://example.test/wp-admin/admin.php?page=nettertech-events-attendees'
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/attendees/filters.php',
			$presenter,
			'status_and_search_active'
		);
	}

	/**
	 * Placeholder-data filter set to "yes"; no other filters.
	 *
	 * @group decision
	 */
	public function test_renders_with_placeholder_filter_active(): void {
		$presenter = new AttendeesFiltersPresenter(
			0,
			0,
			'',
			'',
			'yes',
			array(),
			'nettertech-events-attendees',
			'http://example.test/wp-admin/admin.php?page=nettertech-events-attendees'
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/attendees/filters.php',
			$presenter,
			'placeholder_filter_active'
		);
	}
}
