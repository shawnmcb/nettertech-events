<?php
/**
 * Snapshot test for the Category admin-notices template (T4.2.4).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Categories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Categories;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Categories\Presenters\CategoryNoticesPresenter;
use NetterTechEvents\Tests\Snapshots\SnapshotTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Category notices template output to a stored snapshot.
 *
 * @covers \NetterTechEvents\Admin\Categories\Presenters\CategoryNoticesPresenter
 */
final class CategoryNoticesSnapshotTest extends TestCase {

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
	 * Success notice for the 'created' message key.
	 *
	 * @group decision
	 */
	public function test_renders_created_success_notice(): void {
		$presenter = new CategoryNoticesPresenter( 'created' );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/categories/notices.php',
			$presenter,
			'created_success'
		);
	}

	/**
	 * Error notice falls back to the generic message when no error_text is given.
	 *
	 * @group decision
	 */
	public function test_renders_error_notice_fallback_text(): void {
		$presenter = new CategoryNoticesPresenter( 'error' );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/categories/notices.php',
			$presenter,
			'error_fallback_text'
		);
	}

	/**
	 * Unknown message key → presenter returns empty message → template emits nothing.
	 *
	 * @group decision
	 */
	public function test_renders_nothing_for_unknown_message_key(): void {
		$presenter = new CategoryNoticesPresenter( 'something-else' );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/categories/notices.php',
			$presenter,
			'unknown_message_key_renders_nothing'
		);
	}
}
