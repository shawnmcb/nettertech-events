<?php
/**
 * AdminMenu container-resolution integration test.
 *
 * Regression guard for the namespace-resolution P0 fixed 2026-05-20.
 * `AdminMenu.php` (namespace `NetterTechEvents\Admin`) called
 * `nettertech_events_container()` unqualified at 8 page-callback sites.
 * PHP resolved that to the nonexistent
 * `NetterTechEvents\Admin\nettertech_events_container()` and raised a
 * fatal `Error` on every base Events admin page (Events list, Organizers,
 * Spaces, Categories, Attendees, CSV Import, Activity Log, Settings).
 * The function lives in the `NetterTechEvents` namespace, so the calls
 * must be written `\NetterTechEvents\nettertech_events_container()`.
 *
 * Introduced by commit 5f62826 (2026-05-16). The unit-level AdminMenu
 * tests cover menu registration, not the load/render callbacks, so the
 * fatal shipped undetected. This integration test exercises those
 * callbacks in a real WordPress admin runtime so the regression cannot
 * return silently.
 *
 * @package NetterTechEvents\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Admin;

use NetterTechEvents\Admin\AdminMenu;

/**
 * Integration test: AdminMenu page callbacks resolve the DI container.
 *
 * @group wiring
 */
class AdminMenuContainerResolutionTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * The `wp_die_handler` filter callback installed for the test, kept so
	 * tearDown() can remove it.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $wp_die_filter = null;

	/**
	 * Load the wp-admin includes, neutralize wp_die(), and run as an
	 * administrator so the AdminMenu callbacks reach their container calls.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		// AdminMenu page callbacks call wp_die() on capability failure. The
		// default handler would terminate the PHPUnit process, so swap in a
		// throwing handler: the callback's wp_die() then surfaces as a
		// catchable exception instead of killing the run.
		$this->wp_die_filter = static function (): callable {
			return static function ( $message ): void {
				throw new \RuntimeException(
					'wp_die() called: ' . ( is_string( $message ) ? $message : 'admin callback' )
				);
			};
		};
		add_filter( 'wp_die_handler', $this->wp_die_filter );

		// Run as an administrator so capability checks inside the page
		// renderers pass and the callbacks exercise their full body.
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		if ( ! empty( $admins ) ) {
			wp_set_current_user( (int) $admins[0] );
		}
	}

	/**
	 * Restore wp_die() and the current user.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null !== $this->wp_die_filter ) {
			remove_filter( 'wp_die_handler', $this->wp_die_filter );
			$this->wp_die_filter = null;
		}
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Every AdminMenu page callback that resolves a service from the DI
	 * container must reach the container without a PHP `Error`.
	 *
	 * If `\NetterTechEvents\nettertech_events_container()` is ever written
	 * unqualified again, PHP raises `Error: Call to undefined function`
	 * and this test fails. Throwables other than that namespace regression
	 * (render-context exceptions, the test's wp_die() shim) are out of
	 * scope for this guard and are tolerated.
	 *
	 * @return void
	 */
	public function test_admin_menu_callbacks_resolve_the_container(): void {
		$admin_menu = \NetterTechEvents\nettertech_events_container()->get( AdminMenu::class );
		$this->assertInstanceOf( AdminMenu::class, $admin_menu );

		$callbacks = array(
			'load_events_page',
			'render_organizers_page',
			'render_spaces_page',
			'render_categories_page',
			'render_attendees_page',
			'render_csv_import_page',
			'render_activity_log_page',
			'render_settings_page',
		);

		$regressions = array();
		$base_level  = ob_get_level();

		foreach ( $callbacks as $callback ) {
			try {
				ob_start();
				$admin_menu->$callback();
			} catch ( \Error $e ) {
				$message = $e->getMessage();
				if ( false !== strpos( $message, 'nettertech_events_container' )
					|| false !== stripos( $message, 'undefined function' ) ) {
					$regressions[] = "AdminMenu::{$callback}() raised: {$message}";
				}
			} catch ( \Throwable $e ) {
				unset( $e ); // Render-context throwables are out of scope here.
			} finally {
				while ( ob_get_level() > $base_level ) {
					ob_end_clean();
				}
			}
		}

		$this->assertSame(
			array(),
			$regressions,
			'AdminMenu has an unqualified nettertech_events_container() call. It must be '
			. 'written \\NetterTechEvents\\nettertech_events_container() — an unqualified '
			. 'call resolves to the nonexistent NetterTechEvents\\Admin\\ function and '
			. 'fatals the base Events admin pages.'
		);
	}
}
