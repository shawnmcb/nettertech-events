<?php
/**
 * Snapshot test for the ticket-row header template (T4.2.4).
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Metaboxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Metaboxes\Presenters\TicketRowHeaderPresenter;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Tests\Snapshots\SnapshotTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pins the ticket-row header template output to a stored snapshot.
 *
 * @covers \NetterTechEvents\Admin\Metaboxes\Presenters\TicketRowHeaderPresenter
 */
final class TicketRowHeaderSnapshotTest extends TestCase {

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
	 * New (empty-name) occurrence-scope ticket falls back to "New Ticket".
	 *
	 * @group decision
	 */
	public function test_renders_new_ticket_occurrence_scope(): void {
		$presenter = new TicketRowHeaderPresenter( '', TicketTypeScope::OCCURRENCE );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/ticket-row-header.php',
			$presenter,
			'new_ticket_occurrence_scope'
		);
	}

	/**
	 * Named event-scope ticket renders display name and EVENT badge.
	 *
	 * @group decision
	 */
	public function test_renders_named_event_scope_ticket(): void {
		$presenter = new TicketRowHeaderPresenter( 'General Admission', TicketTypeScope::EVENT );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/ticket-row-header.php',
			$presenter,
			'named_event_scope_ticket'
		);
	}

	/**
	 * Template-scope ticket renders TEMPLATE badge and the configured name.
	 *
	 * @group decision
	 */
	public function test_renders_template_scope_ticket(): void {
		$presenter = new TicketRowHeaderPresenter( 'VIP Pass', TicketTypeScope::TEMPLATE );

		$this->assertSnapshot(
			dirname( __DIR__, 4 ) . '/templates/admin/metaboxes/ticket-row-header.php',
			$presenter,
			'template_scope_vip_pass'
		);
	}
}
