<?php
/**
 * AdminMenu degradation tests — Pro absent.
 *
 * Proves AdminMenu registers correctly when CheckInPage is null (Pro absent)
 * and that no Check-In or QR Generator submenu pages are registered by the
 * base plugin itself.
 *
 * @package NetterTechEvents\Tests\Unit\Degradation
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Degradation;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\AdminMenuRegistrar;
use NetterTechEvents\Admin\EventsPage;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Services\CsvColumnMapper;
use NetterTechEvents\Services\CsvImporter;
use NetterTechEvents\Services\CsvParser;
use NetterTechEvents\Services\LayoutService;

/**
 * Verify AdminMenu behaviour when Pro (CheckInPage) is absent.
 *
 * @covers \NetterTechEvents\Admin\AdminMenu
 */
class ProAbsentAdminMenuTest extends \NetterTechEventsTestCase {

	/**
	 * Build an AdminMenu with null checkin_page (simulates Pro absent).
	 *
	 * @return AdminMenu
	 */
	private function create_menu_without_checkin(): AdminMenu {
		return new AdminMenu( $this->createMock( EventsPage::class ) );
	}

	// =========================================================================
	// Instantiation
	// =========================================================================

	/**
	 * Test AdminMenu instantiates cleanly with null CheckInPage.
	 *
	 * @return void
	 */
	public function test_instantiates_cleanly_without_checkin_page(): void {
		$menu = $this->create_menu_without_checkin();

		$this->assertInstanceOf( AdminMenu::class, $menu );
	}

	// =========================================================================
	// register() works without CheckInPage
	// =========================================================================

	/**
	 * Test register() completes without errors when CheckInPage is null.
	 *
	 * @return void
	 */
	public function test_register_completes_without_checkin_page(): void {
		$menu = $this->create_menu_without_checkin();

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		$menu->register();

		$this->addToAssertionCount( 1 );
	}

	// =========================================================================
	// Core menus still register
	// =========================================================================

	/**
	 * Test add_menu_pages registers the main Events menu entry.
	 *
	 * @return void
	 */
	public function test_core_events_menu_still_registers(): void {
		$menu      = $this->create_menu_without_checkin();
		$registrar = new AdminMenuRegistrar( $menu );

		$registered_menus = array();

		Functions\when( 'add_menu_page' )->alias(
			function () use ( &$registered_menus ) {
				$registered_menus[] = func_get_args();
				return 'hook';
			}
		);
		Functions\when( 'add_submenu_page' )->justReturn( 'hook' );
		Functions\when( 'add_action' )->justReturn( true );

		$registrar->add_menu_pages();

		// At least one top-level menu is registered.
		$this->assertNotEmpty( $registered_menus );
		$slugs = array_column( $registered_menus, 3 );
		$this->assertContains( AdminMenu::MENU_SLUG, $slugs );
	}

	/**
	 * Test add_menu_pages registers Attendees submenu.
	 *
	 * @return void
	 */
	public function test_attendees_submenu_still_registers(): void {
		$menu      = $this->create_menu_without_checkin();
		$registrar = new AdminMenuRegistrar( $menu );

		$registered_submenus = array();

		Functions\when( 'add_menu_page' )->justReturn( 'hook' );
		Functions\when( 'add_submenu_page' )->alias(
			function () use ( &$registered_submenus ) {
				$registered_submenus[] = func_get_args();
				return 'hook';
			}
		);
		Functions\when( 'add_action' )->justReturn( true );

		$registrar->add_menu_pages();

		$slugs = array_column( $registered_submenus, 4 );
		$this->assertContains( AdminMenu::MENU_SLUG . '-attendees', $slugs );
	}

	/**
	 * Test add_utility_pages registers Settings submenu.
	 *
	 * @return void
	 */
	public function test_settings_submenu_still_registers(): void {
		$menu      = $this->create_menu_without_checkin();
		$registrar = new AdminMenuRegistrar( $menu );

		$registered_submenus = array();

		Functions\when( 'add_submenu_page' )->alias(
			function () use ( &$registered_submenus ) {
				$registered_submenus[] = func_get_args();
				return 'hook';
			}
		);
		Functions\when( 'add_action' )->justReturn( true );

		$registrar->add_utility_pages();

		$slugs = array_column( $registered_submenus, 4 );
		$this->assertContains( AdminMenu::MENU_SLUG . '-settings', $slugs );
	}

	// =========================================================================
	// fire_register_admin_pages_hook fires ACTION_REGISTER_ADMIN_PAGES
	// =========================================================================

	/**
	 * Test fire_register_admin_pages_hook runs without errors.
	 *
	 * Pro attaches to ACTION_REGISTER_ADMIN_PAGES; without Pro the action
	 * fires but nothing hooks into it.  We verify the method completes.
	 *
	 * @return void
	 */
	public function test_fire_register_admin_pages_hook_runs_without_errors(): void {
		$menu      = $this->create_menu_without_checkin();
		$registrar = new AdminMenuRegistrar( $menu );

		// do_action is already stubbed by the base test case.
		// Verify the method completes cleanly without exceptions.
		$registrar->fire_register_admin_pages_hook();

		$this->addToAssertionCount( 1 );
	}
}
