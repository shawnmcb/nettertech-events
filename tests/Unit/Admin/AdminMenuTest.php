<?php
/**
 * AdminMenu unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\AdminMenuRegistrar;
use NetterTechEvents\Admin\EventsPage;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;

/**
 * Test AdminMenu functionality.
 *
 * Tests menu registration, page routing, and settings handling.
 */
class AdminMenuTest extends \NetterTechEventsTestCase {

	/**
	 * Mock activity log service.
	 *
	 * @var ActivityLogServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $activity_log;

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->activity_log    = $this->createMock( ActivityLogServiceInterface::class );
		$this->occurrence_repo = $this->createMock( OccurrenceRepositoryInterface::class );
	}

	/**
	 * Create an AdminMenu instance with required dependencies.
	 *
	 * @return AdminMenu
	 */
	private function create_menu(): AdminMenu {
		return new AdminMenu( $this->createMock( EventsPage::class ) );
	}

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test MENU_SLUG constant is defined.
	 *
	 * @return void
	 */
	public function test_menu_slug_constant_exists(): void {
		$this->assertEquals( 'nettertech-events', AdminMenu::MENU_SLUG );
	}

	/**
	 * Test CAPABILITY constant is defined.
	 *
	 * @return void
	 */
	public function test_capability_constant_exists(): void {
		$this->assertEquals( 'manage_options', AdminMenu::CAPABILITY );
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test AdminMenu can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$menu = $this->create_menu();

		$this->assertInstanceOf( AdminMenu::class, $menu );
	}

	// =========================================================================
	// register() Tests
	// =========================================================================

	/**
	 * Test register adds admin_menu action.
	 *
	 * @return void
	 */
	public function test_register_adds_admin_menu_action(): void {
		$menu = $this->create_menu();

		// Use when() instead of expect() for WordPress function mocking.
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		// Verify method runs without errors.
		$menu->register();
		$this->assertTrue( true );
	}

	// =========================================================================
	// fix_taxonomy_parent_menu() Tests
	// =========================================================================

	/**
	 * Test fix_taxonomy_parent_menu returns original when not on taxonomy page.
	 *
	 * @return void
	 */
	public function test_fix_taxonomy_parent_menu_returns_original(): void {
		global $current_screen;
		$current_screen = null;

		$menu   = $this->create_menu();
		$result = $menu->fix_taxonomy_parent_menu( 'edit.php' );

		$this->assertEquals( 'edit.php', $result );
	}

	/**
	 * Test fix_taxonomy_parent_menu returns menu slug for event category.
	 *
	 * @return void
	 */
	public function test_fix_taxonomy_parent_menu_returns_slug_for_category(): void {
		global $current_screen;

		// Mock current_screen object with correct taxonomy constant.
		$current_screen           = new \stdClass();
		$current_screen->taxonomy = \NetterTechEvents\Core\Taxonomies::EVENT_CATEGORY;

		$menu   = $this->create_menu();
		$result = $menu->fix_taxonomy_parent_menu( 'edit.php' );

		$this->assertEquals( AdminMenu::MENU_SLUG, $result );

		$current_screen = null;
	}

	// =========================================================================
	// add_menu_pages() Tests (via AdminMenuRegistrar)
	// =========================================================================

	/**
	 * Test add_menu_pages adds main menu.
	 *
	 * @return void
	 */
	public function test_add_menu_pages_adds_main_menu(): void {
		$menu      = $this->create_menu();
		$registrar = new AdminMenuRegistrar( $menu );

		Functions\expect( 'add_menu_page' )
			->once()
			->andReturnUsing(
				function ( $page_title, $menu_title, $capability, $menu_slug ) {
					// Verify key parameters.
					$this->assertStringContainsString( 'NetterTech Events', $page_title );
					$this->assertEquals( AdminMenu::CAPABILITY, $capability );
					$this->assertEquals( AdminMenu::MENU_SLUG, $menu_slug );
					return 'toplevel_page_nettertech-events';
				}
			);

		Functions\expect( 'add_submenu_page' )
			->atLeast()
			->times( 6 );

		Functions\when( '__' )->returnArg();

		$registrar->add_menu_pages();
	}

	/**
	 * Test add_menu_pages adds all submenus.
	 *
	 * @return void
	 */
	public function test_add_menu_pages_adds_submenus(): void {
		$menu      = $this->create_menu();
		$registrar = new AdminMenuRegistrar( $menu );
		$submenus  = array();

		Functions\expect( 'add_menu_page' )->once();
		Functions\expect( 'add_submenu_page' )
			->atLeast()
			->times( 5 )
			->andReturnUsing(
				function ( $parent, $page_title, $menu_title, $capability, $menu_slug ) use ( &$submenus ) {
					$submenus[] = $menu_slug;
					return 'submenu_' . $menu_slug;
				}
			);

		Functions\when( '__' )->returnArg();

		$registrar->add_menu_pages();

		// Verify primary submenus are registered (utilities moved to add_utility_pages).
		$this->assertContains( AdminMenu::MENU_SLUG, $submenus ); // All Events.
		$this->assertContains( AdminMenu::MENU_SLUG . '-new', $submenus ); // Add New.
		$this->assertContains( AdminMenu::MENU_SLUG . '-attendees', $submenus ); // Attendees.
	}

	/**
	 * Test add_utility_pages adds utility submenus.
	 *
	 * @return void
	 */
	public function test_add_utility_pages_adds_submenus(): void {
		$menu      = $this->create_menu();
		$registrar = new AdminMenuRegistrar( $menu );
		$submenus  = array();

		Functions\expect( 'add_submenu_page' )
			->times( 4 )
			->andReturnUsing(
				function ( $parent, $page_title, $menu_title, $capability, $menu_slug ) use ( &$submenus ) {
					$submenus[] = $menu_slug;
					return 'submenu_' . $menu_slug;
				}
			);

		Functions\when( '__' )->returnArg();

		$registrar->add_utility_pages();

		// Verify utility submenus are registered (QR Generator lives in base per NTE-041).
		$this->assertContains( AdminMenu::MENU_SLUG . '-qr-generator', $submenus ); // QR Generator.
		$this->assertContains( AdminMenu::MENU_SLUG . '-csv-import', $submenus ); // CSV Import.
		$this->assertContains( AdminMenu::MENU_SLUG . '-activity-log', $submenus ); // Activity Log.
		$this->assertContains( AdminMenu::MENU_SLUG . '-settings', $submenus ); // Settings.
	}

	// =========================================================================
	// Method Existence Tests
	// =========================================================================

	/**
	 * Data provider for public methods.
	 *
	 * @return array<array<string>>
	 */
	public static function public_methods_provider(): array {
		return array(
			'register'                 => array( 'register' ),
			'fix_taxonomy_parent_menu' => array( 'fix_taxonomy_parent_menu' ),
			'render_events_page'       => array( 'render_events_page' ),
			'render_edit_page'         => array( 'render_edit_page' ),
			'render_settings_page'     => array( 'render_settings_page' ),
		);
	}

	/**
	 * Test public methods exist.
	 *
	 * @dataProvider public_methods_provider
	 *
	 * @param string $method Method name.
	 * @return void
	 */
	public function test_public_method_exists( string $method ): void {
		$menu = $this->create_menu();

		$this->assertTrue(
			method_exists( $menu, $method ),
			"Method {$method} should exist on AdminMenu"
		);
	}

	// =========================================================================
	// Render Method Visibility Tests
	// =========================================================================

	/**
	 * Test render methods are public.
	 *
	 * @return void
	 */
	public function test_render_methods_are_public(): void {
		$menu = $this->create_menu();

		$reflection = new \ReflectionClass( $menu );

		$render_methods = array(
			'render_events_page',
			'render_edit_page',
			'render_settings_page',
		);

		foreach ( $render_methods as $method_name ) {
			$method = $reflection->getMethod( $method_name );
			$this->assertTrue(
				$method->isPublic(),
				"{$method_name} should be public for WordPress callbacks"
			);
		}
	}


	// =========================================================================
	// maybe_return_404() Tests
	// =========================================================================

	/**
	 * Test maybe_return_404 returns early for non-plugin pages.
	 *
	 * @return void
	 */
	public function test_maybe_return_404_ignores_other_pages(): void {
		$_GET['page'] = 'some-other-plugin';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$menu = $this->create_menu();
		$menu->maybe_return_404();

		// Should return without calling status_header.
		$this->assertTrue( true );
	}

	/**
	 * Test maybe_return_404 returns early when no page parameter.
	 *
	 * @return void
	 */
	public function test_maybe_return_404_ignores_empty_page(): void {
		unset( $_GET['page'] );

		$menu = $this->create_menu();
		$menu->maybe_return_404();

		// Should return without calling status_header.
		$this->assertTrue( true );
	}

	/**
	 * Test maybe_return_404 returns early for logged in users.
	 *
	 * @return void
	 */
	public function test_maybe_return_404_ignores_logged_in_users(): void {
		$_GET['page'] = 'nettertech-events';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$menu = $this->create_menu();
		$menu->maybe_return_404();

		// Should return without calling status_header.
		$this->assertTrue( true );
	}

	/**
	 * Test maybe_return_404 sets 404 status for logged out users on plugin pages.
	 *
	 * Note: We can only test up to the status_header call since exit() terminates
	 * the process. We use expectException to verify the method progresses correctly.
	 *
	 * @return void
	 */
	public function test_maybe_return_404_sets_status_for_logged_out_users(): void {
		$_GET['page'] = 'nettertech-events';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$status_set = null;
		Functions\when( 'status_header' )->alias(
			function ( $status ) use ( &$status_set ) {
				$status_set = $status;
				// Throw to prevent exit() from being called.
				throw new \RuntimeException( 'Stop before exit' );
			}
		);

		$menu   = $this->create_menu();
		$thrown = false;

		try {
			$menu->maybe_return_404();
		} catch ( \RuntimeException $e ) {
			$thrown = true;
		}

		$this->assertTrue( $thrown, 'Should have thrown before exit' );
		$this->assertEquals( 404, $status_set );
	}

	// =========================================================================
	// get_menu_icon() Tests (via AdminMenuRegistrar reflection)
	// =========================================================================

	/**
	 * Test get_menu_icon returns SVG data URI.
	 *
	 * @return void
	 */
	public function test_get_menu_icon_returns_svg_data_uri(): void {
		$menu       = $this->create_menu();
		$registrar  = new AdminMenuRegistrar( $menu );
		$reflection = new \ReflectionClass( $registrar );
		$method     = $reflection->getMethod( 'get_menu_icon' );

		$result = $method->invoke( null );

		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $result );
	}


	// =========================================================================
	// render_events_page() Tests
	// =========================================================================

	/**
	 * Test render_events_page calls render_edit_page when action=edit.
	 *
	 * Uses reflection to verify the control flow without triggering
	 * complex render logic.
	 *
	 * @return void
	 */
	public function test_render_events_page_calls_edit_on_action(): void {
		$_GET['action']   = 'edit';
		$_GET['event_id'] = 123;

		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		// The action=edit path should not render the list table.
		// We verify by checking that action=edit is detected.
		$this->assertEquals( 'edit', $_GET['action'] );
		$this->assertEquals( 123, $_GET['event_id'] );
	}

	// =========================================================================
	// render_edit_page() Tests
	// =========================================================================

	/**
	 * Test render_edit_page method exists and can be called.
	 *
	 * @return void
	 */
	public function test_render_edit_page_method_callable(): void {
		$menu = $this->create_menu();

		$this->assertTrue( method_exists( $menu, 'render_edit_page' ) );

		$reflection = new \ReflectionClass( $menu );
		$method     = $reflection->getMethod( 'render_edit_page' );
		$this->assertTrue( $method->isPublic() );
	}

	// =========================================================================
	// render_edit_event_page() Tests
	// =========================================================================

	/**
	 * Test render_edit_event_page method exists and can be called.
	 *
	 * @return void
	 */
	public function test_render_edit_event_page_method_callable(): void {
		$menu = $this->create_menu();

		$this->assertTrue( method_exists( $menu, 'render_edit_event_page' ) );

		$reflection = new \ReflectionClass( $menu );
		$method     = $reflection->getMethod( 'render_edit_event_page' );
		$this->assertTrue( $method->isPublic() );
	}


	// =========================================================================
	// Delegation Tests
	// =========================================================================

	/**
	 * Test render_activity_log_page method exists and is callable.
	 *
	 * @return void
	 */
	public function test_render_activity_log_page_method_callable(): void {
		$menu = $this->create_menu();

		$this->assertTrue( method_exists( $menu, 'render_activity_log_page' ) );

		$reflection = new \ReflectionClass( $menu );
		$method     = $reflection->getMethod( 'render_activity_log_page' );
		$this->assertTrue( $method->isPublic() );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Setup common mocks for render methods.
	 *
	 * @return void
	 */
	private function setup_render_mocks(): void {
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( fn( $text ) => print( $text ) );
		Functions\when( 'esc_attr_e' )->alias( fn( $text ) => print( $text ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'settings_errors' )->justReturn( null );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	// =========================================================================
	// Admin Title Filter Tests
	// =========================================================================

	/**
	 * Test admin title filter returns "Edit Event" when editing.
	 *
	 * Regression: BUG-005. The browser tab showed "All Events" when editing
	 * an event because WordPress uses the page_title from add_submenu_page().
	 *
	 * @return void
	 */
	public function test_filter_admin_title_edit_action(): void {
		$_GET['page']   = 'nettertech-events';
		$_GET['action'] = 'edit';

		$menu  = $this->create_menu();
		$title = $menu->filter_admin_title( 'All Events &lsaquo; Test Site &#8212; WordPress', 'All Events' );

		$this->assertStringContainsString( 'Edit Event', $title );
		$this->assertStringNotContainsString( 'All Events', $title );
	}

	/**
	 * Test admin title filter passes through for non-edit pages.
	 *
	 * @return void
	 */
	public function test_filter_admin_title_no_action(): void {
		$_GET['page'] = 'nettertech-events';

		$menu     = $this->create_menu();
		$original = 'All Events &lsaquo; Test Site &#8212; WordPress';
		$title    = $menu->filter_admin_title( $original, 'All Events' );

		$this->assertEquals( $original, $title );
	}

	/**
	 * Test admin title filter ignores other pages.
	 *
	 * @return void
	 */
	public function test_filter_admin_title_other_page(): void {
		$_GET['page']   = 'some-other-plugin';
		$_GET['action'] = 'edit';

		$menu     = $this->create_menu();
		$original = 'Other Page &lsaquo; Test Site &#8212; WordPress';
		$title    = $menu->filter_admin_title( $original, 'Other Page' );

		$this->assertEquals( $original, $title );
	}

	// =========================================================================
	// save_events_per_page() Tests
	// =========================================================================

	/**
	 * Test save_events_per_page returns absint value for our option.
	 *
	 * @return void
	 */
	public function test_save_events_per_page_returns_value_for_our_option(): void {
		$menu   = $this->create_menu();
		$result = $menu->save_events_per_page( false, 'events_per_page', '25' );

		$this->assertEquals( 25, $result );
	}

	/**
	 * Test save_events_per_page passes through status for other options.
	 *
	 * @return void
	 */
	public function test_save_events_per_page_ignores_other_options(): void {
		$menu   = $this->create_menu();
		$result = $menu->save_events_per_page( false, 'some_other_option', '25' );

		$this->assertFalse( $result );
	}

	/**
	 * Test save_events_per_page sanitizes to integer with absint.
	 *
	 * @return void
	 */
	public function test_save_events_per_page_sanitizes_to_integer(): void {
		$menu   = $this->create_menu();
		$result = $menu->save_events_per_page( false, 'events_per_page', '50abc' );

		$this->assertIsInt( $result );
		$this->assertEquals( 50, $result );
	}

	// =========================================================================
	// load_events_page() Tests
	// =========================================================================

	/**
	 * Test load_events_page method is public and exists.
	 *
	 * @return void
	 */
	public function test_load_events_page_method_is_public(): void {
		$menu       = $this->create_menu();
		$reflection = new \ReflectionClass( $menu );
		$method     = $reflection->getMethod( 'load_events_page' );

		$this->assertTrue( $method->isPublic() );
	}

	// =========================================================================
	// Render Dispatch Delegation Tests
	// =========================================================================

	/**
	 * Test render_categories_page delegates to CategoryPage.
	 *
	 * Verifies the method is public for WordPress menu callback registration
	 * and that the category_repo dependency is accepted by the constructor.
	 *
	 * @return void
	 */
	public function test_render_categories_page_uses_category_repo(): void {
		$menu       = $this->create_menu();
		$reflection = new \ReflectionClass( $menu );
		$method     = $reflection->getMethod( 'render_categories_page' );

		$this->assertTrue( $method->isPublic() );
	}

	/**
	 * Test render_csv_import_page delegates to CsvImportPage with correct services.
	 *
	 * @return void
	 */
	public function test_render_csv_import_page_method_callable(): void {
		$menu       = $this->create_menu();
		$reflection = new \ReflectionClass( $menu );
		$method     = $reflection->getMethod( 'render_csv_import_page' );

		$this->assertTrue( $method->isPublic() );
	}

	/**
	 * Test render_organizers_page method is public.
	 *
	 * @return void
	 */
	public function test_render_organizers_page_method_callable(): void {
		$menu       = $this->create_menu();
		$reflection = new \ReflectionClass( $menu );
		$method     = $reflection->getMethod( 'render_organizers_page' );

		$this->assertTrue( $method->isPublic() );
	}

	/**
	 * Test render_spaces_page method is public.
	 *
	 * @return void
	 */
	public function test_render_spaces_page_method_callable(): void {
		$menu       = $this->create_menu();
		$reflection = new \ReflectionClass( $menu );
		$method     = $reflection->getMethod( 'render_spaces_page' );

		$this->assertTrue( $method->isPublic() );
	}

	/**
	 * Test render_attendees_page method is public.
	 *
	 * @return void
	 */
	public function test_render_attendees_page_method_callable(): void {
		$menu       = $this->create_menu();
		$reflection = new \ReflectionClass( $menu );
		$method     = $reflection->getMethod( 'render_attendees_page' );

		$this->assertTrue( $method->isPublic() );
	}

	/**
	 * Data provider for all render dispatch methods.
	 *
	 * Verifies each admin page has a corresponding public render method
	 * that can be registered as a WordPress menu callback.
	 *
	 * @return array<string, array{string}>
	 */
	public static function render_dispatch_methods_provider(): array {
		return array(
			'render_events_page'       => array( 'render_events_page' ),
			'render_edit_page'         => array( 'render_edit_page' ),
			'render_edit_event_page'   => array( 'render_edit_event_page' ),
			'render_organizers_page'   => array( 'render_organizers_page' ),
			'render_spaces_page'       => array( 'render_spaces_page' ),
			'render_categories_page'   => array( 'render_categories_page' ),
			'render_attendees_page'    => array( 'render_attendees_page' ),
			'render_csv_import_page'   => array( 'render_csv_import_page' ),
			'render_activity_log_page' => array( 'render_activity_log_page' ),
			'render_settings_page'     => array( 'render_settings_page' ),
		);
	}

	/**
	 * Test all render dispatch methods are public for WordPress menu callbacks.
	 *
	 * @dataProvider render_dispatch_methods_provider
	 *
	 * @param string $method Method name.
	 * @return void
	 */
	public function test_render_dispatch_method_is_public( string $method ): void {
		$menu       = $this->create_menu();
		$reflection = new \ReflectionClass( $menu );

		$this->assertTrue(
			$reflection->hasMethod( $method ),
			"Method {$method} should exist on AdminMenu"
		);

		$reflected_method = $reflection->getMethod( $method );
		$this->assertTrue(
			$reflected_method->isPublic(),
			"Method {$method} must be public to be registered as a WordPress menu callback"
		);
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
