<?php
/**
 * Snapshot test for the Tickets "save event first" notice template (T4.2.4 Metaboxes cluster).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Metaboxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Metaboxes\Presenters\SaveFirstNoticePresenter;
use NetterTechEvents\Tests\Snapshots\SnapshotTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Tickets save-first-notice template output to a stored snapshot.
 *
 * @covers \NetterTechEvents\Admin\Metaboxes\Presenters\SaveFirstNoticePresenter
 */
final class SaveFirstNoticeSnapshotTest extends TestCase {

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
	 * Default English locale renders the notice text verbatim.
	 *
	 * The notice is intentionally state-less — its single concern is to instruct
	 * the user to save the event before configuring tickets. One snapshot is
	 * sufficient because there are no variant inputs to exercise.
	 *
	 * @group decision
	 */
	public function test_renders_default_notice(): void {
		$presenter = new SaveFirstNoticePresenter();

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/tickets-save-first-notice.php',
			$presenter,
			'default_notice'
		);
	}

	/**
	 * Notice text comes from `__()`; if a translation introduces special characters,
	 * `esc_html()` neutralizes them. Brain\Monkey stubs make `__()` pass through;
	 * the filter on `gettext` substitutes a sentinel string with `&` and `<` to
	 * pin the template's escape behavior.
	 *
	 * @group decision
	 */
	public function test_escapes_special_characters_in_translated_notice(): void {
		Functions\when( 'esc_html__' )->returnArg();
		// Override the translation lookup the presenter performs (via __()).
		// Brain\Monkey's stubTranslationFunctions() exposes `__()` as identity;
		// re-stub to inject our sentinel for this single test.
		Functions\when( '__' )->alias(
			static fn( string $text, string $domain = '' ): string =>
				'Save the event first to configure ticket types.' === $text
					? 'Save & configure <ticket> types.'
					: $text
		);

		$presenter = new SaveFirstNoticePresenter();

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/tickets-save-first-notice.php',
			$presenter,
			'notice_with_special_chars_escaped'
		);
	}
}
