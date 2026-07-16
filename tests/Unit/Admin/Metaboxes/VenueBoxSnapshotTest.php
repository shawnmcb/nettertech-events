<?php
/**
 * Snapshot test for the Venue metabox template (T4.2.4 Metaboxes cluster).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Metaboxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Metaboxes\Presenters\VenueBoxPresenter;
use NetterTechEvents\Tests\Snapshots\SnapshotTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Venue metabox template output to a stored snapshot.
 *
 * @covers \NetterTechEvents\Admin\Metaboxes\Presenters\VenueBoxPresenter
 */
final class VenueBoxSnapshotTest extends TestCase {

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
	 * Empty venue (new event, no defaults) renders empty inputs.
	 *
	 * @group decision
	 */
	public function test_renders_with_empty_venue(): void {
		$presenter = new VenueBoxPresenter( '', '' );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/venue-box.php',
			$presenter,
			'empty_venue'
		);
	}

	/**
	 * Populated venue renders name and multi-line address.
	 *
	 * @group decision
	 */
	public function test_renders_with_populated_venue(): void {
		$presenter = new VenueBoxPresenter(
			'The Bellwright',
			"123 Main Street\nSaint Paul, MN 55101"
		);

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/venue-box.php',
			$presenter,
			'populated_venue'
		);
	}

	/**
	 * Venue with HTML-like characters in name escapes properly in output.
	 *
	 * @group decision
	 */
	public function test_renders_with_html_in_venue_name(): void {
		$presenter = new VenueBoxPresenter( 'Joe & Sons <Hall>', '' );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/venue-box.php',
			$presenter,
			'html_in_venue_name'
		);
	}
}
