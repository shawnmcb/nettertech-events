<?php
/**
 * Tests for AdminMenuRegistrar.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\AdminMenuRegistrar;
use NetterTechEvents\Admin\CategoryPage;
use NetterTechEvents\Admin\OrganizerPage;
use NetterTechEvents\Admin\SpacesPage;
use NetterTechEvents\Core\Hooks;
use NetterTechEventsTestCase;

/**
 * AdminMenuRegistrarTest covers menu registration extracted from AdminMenu.
 *
 * @covers \NetterTechEvents\Admin\AdminMenuRegistrar
 */
final class AdminMenuRegistrarTest extends NetterTechEventsTestCase {

	/**
	 * Captured calls to add_menu_page.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $menu_calls = array();

	/**
	 * Captured calls to add_submenu_page.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $submenu_calls = array();

	/**
	 * Captured calls to add_action.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $action_calls = array();

	/**
	 * Captured calls to do_action.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $do_action_calls = array();

	/**
	 * Set up shared WordPress menu function stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->menu_calls      = array();
		$this->submenu_calls   = array();
		$this->action_calls    = array();
		$this->do_action_calls = array();

		Functions\when( 'add_menu_page' )->alias(
			function ( ...$args ) {
				$this->menu_calls[] = $args;
				return 'toplevel_page_' . ( $args[3] ?? '' );
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			function ( ...$args ) {
				$this->submenu_calls[] = $args;
				return 'admin_page_' . ( $args[4] ?? 'unknown' );
			}
		);
		Functions\when( 'add_action' )->alias(
			function ( ...$args ) {
				$this->action_calls[] = $args;
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( ...$args ) {
				$this->do_action_calls[] = $args;
			}
		);
	}

	/**
	 * Test fire_register_admin_pages_hook fires the documented action name.
	 *
	 * Extension plugins (Pro) consume this hook by literal string and must
	 * not silently break if the hook name changes.
	 *
	 * @return void
	 */
	public function test_fire_register_admin_pages_hook_fires_action(): void {
		$admin_menu = $this->createMock( AdminMenu::class );
		$registrar  = new AdminMenuRegistrar( $admin_menu );

		$registrar->fire_register_admin_pages_hook();

		$this->assertCount( 1, $this->do_action_calls );
		$this->assertSame( 'nettertech_events_register_admin_pages', $this->do_action_calls[0][0] );
		$this->assertSame( Hooks::ACTION_REGISTER_ADMIN_PAGES, $this->do_action_calls[0][0] );
	}

	/**
	 * Test add_menu_pages registers the top-level menu with the expected slug and capability.
	 *
	 * @return void
	 */
	public function test_add_menu_pages_registers_top_level_menu(): void {
		$admin_menu = $this->createMock( AdminMenu::class );
		$registrar  = new AdminMenuRegistrar( $admin_menu );

		$registrar->add_menu_pages();

		$this->assertCount( 1, $this->menu_calls, 'Exactly one top-level menu should be registered.' );
		$top = $this->menu_calls[0];
		$this->assertSame( AdminMenu::CAPABILITY, $top[2], 'Capability mismatch on top-level menu.' );
		$this->assertSame( AdminMenu::MENU_SLUG, $top[3], 'Slug mismatch on top-level menu.' );
		$this->assertSame( '__return_empty_string', $top[4], 'Top-level page should defer rendering to submenu.' );
		$this->assertIsString( $top[5], 'Menu icon should be a string (SVG data URI).' );
		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $top[5] );
		$this->assertSame( 26, $top[6], 'Menu position should be 26 (after Comments).' );
	}

	/**
	 * Test add_menu_pages registers all expected primary submenu pages.
	 *
	 * Verifies slug stability for primary workflow items; extension plugins
	 * may register additional submenus under these slugs.
	 *
	 * @return void
	 */
	public function test_add_menu_pages_registers_primary_submenus(): void {
		$admin_menu = $this->createMock( AdminMenu::class );
		$registrar  = new AdminMenuRegistrar( $admin_menu );

		$registrar->add_menu_pages();

		$slugs = array_map(
			static function ( array $call ): string {
				return (string) ( $call[4] ?? '' );
			},
			$this->submenu_calls
		);

		$expected = array(
			AdminMenu::MENU_SLUG,           // All Events.
			AdminMenu::SUBMENU_NEW,         // Add New Event.
			OrganizerPage::PAGE_SLUG,       // Organizers.
			SpacesPage::PAGE_SLUG,          // Spaces.
			CategoryPage::PAGE_SLUG,        // Categories.
			AdminMenu::SUBMENU_ATTENDEES,        // Attendees.
			AdminMenu::SUBMENU_EDIT,             // Hidden edit.
			AdminMenu::SUBMENU_EDIT_OCCURRENCE,  // Hidden per-occurrence edit.
		);

		foreach ( $expected as $slug ) {
			$this->assertContains( $slug, $slugs, "Primary submenu missing: {$slug}" );
		}
		$this->assertCount( count( $expected ), $this->submenu_calls, 'Unexpected number of primary submenu registrations.' );
	}

	/**
	 * Test add_menu_pages enforces capability on every submenu.
	 *
	 * @return void
	 */
	public function test_add_menu_pages_enforces_capability_on_all_submenus(): void {
		$admin_menu = $this->createMock( AdminMenu::class );
		$registrar  = new AdminMenuRegistrar( $admin_menu );

		$registrar->add_menu_pages();

		foreach ( $this->submenu_calls as $i => $call ) {
			$this->assertSame(
				AdminMenu::CAPABILITY,
				$call[3],
				"Submenu at index {$i} (slug={$call[4]}) does not use AdminMenu::CAPABILITY."
			);
		}
	}

	/**
	 * Test add_menu_pages hidden edit page is registered under the empty parent.
	 *
	 * Hidden pages use parent='' so they don't show in the menu but remain
	 * routable. This is intentional for the legacy Edit Event flow.
	 *
	 * @return void
	 */
	public function test_add_menu_pages_hidden_edit_uses_empty_parent(): void {
		$admin_menu = $this->createMock( AdminMenu::class );
		$registrar  = new AdminMenuRegistrar( $admin_menu );

		$registrar->add_menu_pages();

		$hidden = null;
		foreach ( $this->submenu_calls as $call ) {
			if ( AdminMenu::SUBMENU_EDIT === ( $call[4] ?? null ) ) {
				$hidden = $call;
				break;
			}
		}
		$this->assertNotNull( $hidden, 'Hidden edit submenu should be registered.' );
		$this->assertSame( '', $hidden[0], 'Hidden edit page must use empty parent slug.' );

		$hidden_occurrence = null;
		foreach ( $this->submenu_calls as $call ) {
			if ( AdminMenu::SUBMENU_EDIT_OCCURRENCE === ( $call[4] ?? null ) ) {
				$hidden_occurrence = $call;
				break;
			}
		}
		$this->assertNotNull( $hidden_occurrence, 'Hidden per-occurrence edit submenu should be registered.' );
		$this->assertSame( '', $hidden_occurrence[0], 'Hidden per-occurrence edit page must use empty parent slug.' );
	}

	/**
	 * Test add_menu_pages wires the All Events load action when add_submenu_page returns a hook.
	 *
	 * @return void
	 */
	public function test_add_menu_pages_wires_load_events_action_when_hook_returned(): void {
		$admin_menu = $this->createMock( AdminMenu::class );
		$registrar  = new AdminMenuRegistrar( $admin_menu );

		$registrar->add_menu_pages();

		$found = false;
		foreach ( $this->action_calls as $call ) {
			$hook_tag = (string) ( $call[0] ?? '' );
			if ( str_starts_with( $hook_tag, 'load-' ) ) {
				$callback = $call[1] ?? null;
				$this->assertIsArray( $callback );
				$this->assertSame( 'load_events_page', $callback[1] ?? null );
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected at least one load- hook wiring for All Events.' );
	}

	/**
	 * Test add_utility_pages registers QR, CSV Import, Activity Log, and Settings.
	 *
	 * @return void
	 */
	public function test_add_utility_pages_registers_utility_submenus(): void {
		$admin_menu = $this->createMock( AdminMenu::class );
		$registrar  = new AdminMenuRegistrar( $admin_menu );

		$registrar->add_utility_pages();

		$slugs = array_map(
			static function ( array $call ): string {
				return (string) ( $call[4] ?? '' );
			},
			$this->submenu_calls
		);

		$expected = array(
			AdminMenu::SUBMENU_QR_GENERATOR,
			AdminMenu::SUBMENU_CSV_IMPORT,
			AdminMenu::SUBMENU_ACTIVITY_LOG,
			AdminMenu::SUBMENU_SETTINGS,
		);

		foreach ( $expected as $slug ) {
			$this->assertContains( $slug, $slugs, "Utility submenu missing: {$slug}" );
		}
		$this->assertCount( count( $expected ), $this->submenu_calls, 'Unexpected number of utility submenu registrations.' );
	}

	/**
	 * Test add_utility_pages routes render callbacks to AdminMenu methods.
	 *
	 * Verifies the render delegation contract — extension plugins that
	 * intercept these methods via inheritance rely on this wiring.
	 *
	 * @return void
	 */
	public function test_add_utility_pages_render_callbacks_target_admin_menu(): void {
		$admin_menu = $this->createMock( AdminMenu::class );
		$registrar  = new AdminMenuRegistrar( $admin_menu );

		$registrar->add_utility_pages();

		$expected = array(
			AdminMenu::SUBMENU_QR_GENERATOR  => 'render_qr_generator_page',
			AdminMenu::SUBMENU_CSV_IMPORT    => 'render_csv_import_page',
			AdminMenu::SUBMENU_ACTIVITY_LOG  => 'render_activity_log_page',
			AdminMenu::SUBMENU_SETTINGS      => 'render_settings_page',
		);

		foreach ( $this->submenu_calls as $call ) {
			$slug     = (string) ( $call[4] ?? '' );
			$callback = $call[5] ?? null;
			$this->assertIsArray( $callback, "Callback for {$slug} should be an array." );
			$this->assertSame( $admin_menu, $callback[0], "Callback for {$slug} should target the injected AdminMenu." );
			$this->assertArrayHasKey( $slug, $expected );
			$this->assertSame( $expected[ $slug ], $callback[1] );
		}
	}

	/**
	 * Test add_menu_pages render callbacks target the injected AdminMenu instance.
	 *
	 * @return void
	 */
	public function test_add_menu_pages_render_callbacks_target_admin_menu(): void {
		$admin_menu = $this->createMock( AdminMenu::class );
		$registrar  = new AdminMenuRegistrar( $admin_menu );

		$registrar->add_menu_pages();

		foreach ( $this->submenu_calls as $call ) {
			$callback = $call[5] ?? null;
			$this->assertIsArray( $callback );
			$this->assertSame( $admin_menu, $callback[0] );
		}
	}
}
