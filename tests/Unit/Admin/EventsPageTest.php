<?php
/**
 * EventsPage unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\EventsPage;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\RevisionRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Services\LayoutService;
use NetterTechEvents\Services\RecurrenceService;

/**
 * Test EventsPage rendering and routing.
 *
 * @covers \NetterTechEvents\Admin\EventsPage
 */
class EventsPageTest extends \NetterTechEventsTestCase {

	/**
	 * Create an EventsPage instance with mocked dependencies.
	 *
	 * @return EventsPage
	 */
	private function create_page(): EventsPage {
		return new EventsPage(
			$this->createMock( EventRepositoryInterface::class ),
			$this->createMock( OccurrenceRepositoryInterface::class ),
			$this->createMock( TicketTypeRepositoryInterface::class ),
			$this->createMock( CapacityServiceInterface::class ),
			$this->createMock( RecurrenceService::class ),
			$this->createMock( RevisionRepositoryInterface::class ),
			$this->createMock( AttendeeFieldRepositoryInterface::class ),
			$this->createMock( LayoutService::class ),
			$this->createMock( CategoryRepositoryInterface::class )
		);
	}

	/**
	 * Setup common mocks for render methods.
	 *
	 * @return void
	 */
	private function setup_render_mocks(): void {
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'settings_errors' )->justReturn( null );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	// =========================================================================
	// Instantiation
	// =========================================================================

	/**
	 * Test EventsPage can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$page = $this->create_page();

		$this->assertInstanceOf( EventsPage::class, $page );
	}

	// =========================================================================
	// render() Routing Tests
	// =========================================================================

	/**
	 * Test render() delegates to render_list when no action parameter.
	 *
	 * @return void
	 */
	public function test_render_shows_list_by_default(): void {
		$this->setup_render_mocks();

		$page = $this->create_page();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-skip-link', $output );
		$this->assertStringContainsString( 'id="nte-main-content"', $output );
	}

	/**
	 * Test render() routes to edit when action=edit and event_id present.
	 *
	 * Uses a partial mock to verify render() calls render_edit() instead
	 * of the list view, without invoking the full EventEditor chain.
	 *
	 * @return void
	 */
	public function test_render_routes_to_edit_on_action(): void {
		$_GET['action']   = 'edit';
		$_GET['event_id'] = '123';

		/** @var EventsPage&\PHPUnit\Framework\MockObject\MockObject $page */
		$page = $this->getMockBuilder( EventsPage::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'render_edit' ) )
			->getMock();

		$page->expects( $this->once() )->method( 'render_edit' );

		$page->render();
	}

	// =========================================================================
	// render_list() Output Tests
	// =========================================================================

	/**
	 * Test list view outputs skip link.
	 *
	 * @return void
	 */
	public function test_list_outputs_skip_link(): void {
		$this->setup_render_mocks();

		$page = $this->create_page();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-skip-link', $output );
		$this->assertStringContainsString( 'screen-reader-text', $output );
		$this->assertStringContainsString( '#nte-main-content', $output );
	}

	/**
	 * Test list view outputs main content wrapper.
	 *
	 * @return void
	 */
	public function test_list_outputs_wrap_div(): void {
		$this->setup_render_mocks();

		$page = $this->create_page();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="nte-main-content"', $output );
		$this->assertStringContainsString( 'class="wrap"', $output );
	}

	/**
	 * Test list view outputs events heading.
	 *
	 * @return void
	 */
	public function test_list_outputs_heading(): void {
		$this->setup_render_mocks();

		$page = $this->create_page();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<h1', $output );
		$this->assertStringContainsString( 'wp-heading-inline', $output );
	}

	/**
	 * Test list view outputs Add New button.
	 *
	 * @return void
	 */
	public function test_list_outputs_add_new_button(): void {
		$this->setup_render_mocks();

		$page = $this->create_page();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'page-title-action', $output );
		$this->assertStringContainsString( AdminMenu::MENU_SLUG . '-new', $output );
	}

	/**
	 * Test list view outputs a GET form with hidden page input.
	 *
	 * The list form must use method="get" (NTE-078) so filter, search,
	 * pagination, and column-sort state lands in $_GET, where
	 * EventsListTable::prepare_items() reads it. Bulk actions are re-routed to
	 * a JS-built POST form to avoid URL-length truncation.
	 *
	 * @return void
	 */
	public function test_list_outputs_form(): void {
		$this->setup_render_mocks();

		$page = $this->create_page();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'method="get"', $output );
		$this->assertStringContainsString( 'id="nte-events-list-form"', $output );
		$this->assertStringNotContainsString( '<form method="post">', $output );
		$this->assertStringContainsString( 'type="hidden"', $output );
		$this->assertStringContainsString( 'name="page"', $output );
		$this->assertStringContainsString( 'value="' . AdminMenu::MENU_SLUG . '"', $output );
	}

	// =========================================================================
	// render_admin_notices() Tests (via render)
	// =========================================================================

	/**
	 * Data provider for admin notice messages.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function admin_notice_messages_provider(): array {
		return array(
			'created message'    => array( 'created', 'success', 'Event created successfully.' ),
			'updated message'    => array( 'updated', 'success', 'Event updated successfully.' ),
			'duplicated message' => array( 'duplicated', 'success', 'Event duplicated.' ),
			'deleted message'    => array( 'deleted', 'success', 'Event deleted.' ),
			'error message'      => array( 'error', 'error', 'An error occurred.' ),
		);
	}

	/**
	 * Test render outputs correct admin notice messages.
	 *
	 * @dataProvider admin_notice_messages_provider
	 *
	 * @param string $message_code  The message code from URL.
	 * @param string $expected_type Expected notice type.
	 * @param string $expected_text Expected message text.
	 * @return void
	 */
	public function test_render_outputs_correct_admin_notice( string $message_code, string $expected_type, string $expected_text ): void {
		$this->setup_render_mocks();

		$_GET['message'] = $message_code;

		$page = $this->create_page();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-' . $expected_type, $output );
		$this->assertStringContainsString( $expected_text, $output );
	}

	/**
	 * Test render does not show notice for unknown message code.
	 *
	 * @return void
	 */
	public function test_render_ignores_unknown_message(): void {
		$this->setup_render_mocks();

		$_GET['message'] = 'unknown_message_code';

		$page = $this->create_page();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'is-dismissible', $output );
	}

	// =========================================================================
	// Public Method Existence Tests
	// =========================================================================

	/**
	 * Test public render methods exist.
	 *
	 * @return void
	 */
	public function test_public_render_methods_exist(): void {
		$page       = $this->create_page();
		$reflection = new \ReflectionClass( $page );

		$methods = array( 'render', 'render_edit_event', 'render_edit', 'set_events_list_table' );

		foreach ( $methods as $method_name ) {
			$this->assertTrue(
				$reflection->hasMethod( $method_name ),
				"{$method_name} should exist on EventsPage"
			);
			$method = $reflection->getMethod( $method_name );
			$this->assertTrue(
				$method->isPublic(),
				"{$method_name} should be public"
			);
		}
	}

	// =========================================================================
	// Cleanup
	// =========================================================================

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();

		parent::tearDown();
	}
}
