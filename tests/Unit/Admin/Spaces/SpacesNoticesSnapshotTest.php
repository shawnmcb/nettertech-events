<?php
/**
 * Snapshot test for the Spaces admin-notices template (T4.2.4).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Spaces
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Spaces;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Spaces\Presenters\SpacesNoticesPresenter;
use NetterTechEvents\Tests\Snapshots\SnapshotTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Spaces notices template output to a stored snapshot.
 *
 * @covers \NetterTechEvents\Admin\Spaces\Presenters\SpacesNoticesPresenter
 */
final class SpacesNoticesSnapshotTest extends TestCase {

	use SnapshotTestTrait;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Success notice for the 'updated' message key.
	 *
	 * @group decision
	 */
	public function test_renders_updated_success_notice(): void {
		$presenter = new SpacesNoticesPresenter( 'updated' );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/spaces/notices.php',
			$presenter,
			'updated_success'
		);
	}

	/**
	 * Error notice with custom error text.
	 *
	 * @group decision
	 */
	public function test_renders_error_notice_with_custom_text(): void {
		$presenter = new SpacesNoticesPresenter( 'error', 'Slug must be unique.' );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/spaces/notices.php',
			$presenter,
			'error_with_custom_text'
		);
	}

	/**
	 * Unknown message key → presenter returns empty message → template emits nothing.
	 *
	 * @group decision
	 */
	public function test_renders_nothing_for_unknown_message_key(): void {
		$presenter = new SpacesNoticesPresenter( 'something-else' );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/spaces/notices.php',
			$presenter,
			'unknown_message_key_renders_nothing'
		);
	}
}
