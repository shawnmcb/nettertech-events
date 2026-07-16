<?php
/**
 * PHPUnit bootstrap file for integration tests.
 *
 * Loads WordPress for integration testing with a real database.
 * Uses a simpler approach than wp-phpunit's WP_UnitTestCase for
 * compatibility with PHPUnit 10.
 *
 * @package NetterTechEvents\Tests
 */

declare(strict_types=1);

$_tests_dir = dirname( __FILE__ );

// Load test configuration.
require_once $_tests_dir . '/wp-tests-config.php';

// Store original $_SERVER values.
$_server_backup = $_SERVER;

// Set up minimal $_SERVER for WordPress.
$_SERVER['HTTP_HOST']       = WP_TESTS_DOMAIN;
$_SERVER['SERVER_NAME']     = WP_TESTS_DOMAIN;
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PORT']     = '80';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';

/*
 * NTE-038: Eliminate worktree class-shadowing by preventing WordPress from
 * loading the sibling `nettertech-events/` plugin during the integration
 * test bootstrap.
 *
 * The hazard: when integration tests run from a git worktree (e.g.
 * plugins/nte-036-rsvp-sold-count/), `wp-settings.php` reads active_plugins
 * and activates the main `plugins/nettertech-events/` plugin. The main
 * plugin's Composer autoloader then races with (and beats) the worktree's
 * autoloader — per-install Composer ClassLoader instances are distinct,
 * and whichever one resolves a class name first wins the cache for its
 * file. `includes/` changes in the worktree have zero effect on test
 * behavior until the shadow is lifted. Diagnosed in NTE-036 via
 * `ReflectionClass::getFileName()` and a file-marker constant, which
 * showed `EventRepository` loading from main's `includes/` while the
 * worktree's test file was the one instantiating it.
 *
 * Structural fix (Option A from NTE-038):
 *   1. Load the worktree's Composer autoloader FIRST, so all worktree
 *      classes resolve to this tree's `includes/`.
 *   2. Pre-populate `$GLOBALS['wp_filter']['pre_option_active_plugins']`
 *      with a short-circuit filter that strips `nettertech-events/
 *      nettertech-events.php` from the active plugin list. When
 *      `wp-settings.php` → `wp-includes/plugin.php` runs, `WP_Hook::
 *      build_preinitialized_hooks()` promotes the preinit array into a
 *      real WP_Hook, so the filter is live by the time
 *      `wp_get_active_and_valid_plugins()` is called at line 545.
 *      WooCommerce and every other active plugin remain loaded; only
 *      the sibling nettertech-events copy is suppressed.
 *   3. After `wp-settings.php` finishes (which includes the
 *      `plugins_loaded` action at line 593), explicitly require the
 *      worktree's own `nettertech-events.php` entry file and invoke
 *      `nettertech_events()->init()` directly, because the
 *      `plugins_loaded` hook has already fired by that point.
 *
 * This replaces NTE-036's hand-picked force-load list: since the sibling
 * plugin is never activated, its autoloader is never registered, and the
 * race ceases to exist for every file in `includes/` — not just the
 * handful that NTE-036 enumerated.
 *
 * A canary integration test at tests/Integration/Bootstrap/
 * ClassResolutionTest.php asserts via ReflectionClass::getFileName()
 * that core classes resolve to the worktree. If the shadow ever returns,
 * that test fails loudly.
 */

// Step 1: load the worktree's Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Step 2: preinitialize the pre_option_active_plugins filter to strip the
// sibling nettertech-events plugin from the activation list. The closure
// uses a static recursion guard because it calls get_option() which
// re-enters the filter stack; without the guard, this would infinite-loop.
$GLOBALS['wp_filter']['pre_option_active_plugins'] = array(
	10 => array(
		'nte038_suppress_sibling_plugin' => array(
			'function'      => static function ( $pre_value ) {
				static $in_filter = false;
				if ( $in_filter ) {
					return $pre_value;
				}
				$in_filter = true;
				try {
					$active = get_option( 'active_plugins', array() );
				} finally {
					$in_filter = false;
				}
				if ( ! is_array( $active ) ) {
					return $pre_value;
				}
				$filtered = array();
				foreach ( $active as $plugin ) {
					if ( 'nettertech-events/nettertech-events.php' === $plugin ) {
						continue;
					}
					$filtered[] = $plugin;
				}
				return $filtered;
			},
			'accepted_args' => 1,
		),
	),
);

// Load WordPress. Because the filter above is now in $wp_filter, when
// wp-includes/plugin.php runs its WP_Hook::build_preinitialized_hooks()
// conversion, our filter becomes a live WP_Hook and blocks the sibling
// plugin from ever being required.
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-settings.php';

// Activate our plugin for testing.
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

// Step 3: explicitly bootstrap the worktree's plugin. wp-settings.php has
// already fired `plugins_loaded`, so requiring the entry file alone is not
// enough — we must also call `Plugin::init()` directly. The entry file's
// own `add_action('plugins_loaded', ...)` registration will register
// harmlessly against a hook that has already run and will not fire.
require_once dirname( __DIR__ ) . '/nettertech-events.php';
\NetterTechEvents\nettertech_events()->init();

// Load test factories.
require_once $_tests_dir . '/Factories/EventFactory.php';
require_once $_tests_dir . '/Factories/OccurrenceFactory.php';
require_once $_tests_dir . '/Factories/TicketTypeFactory.php';
require_once $_tests_dir . '/Factories/AttendeeFactory.php';
require_once $_tests_dir . '/Factories/WooCommerceFactory.php';

// Load shared integration test support classes.
require_once $_tests_dir . '/Integration/Support/FixtureFactory.php';

/**
 * Base test case for integration tests.
 *
 * Extends PHPUnit TestCase directly for PHPUnit 10 compatibility.
 * Uses WordPress's actual wpdb for database operations.
 */
abstract class NetterTechEventsIntegrationTestCase extends \PHPUnit\Framework\TestCase {

	/**
	 * Test transaction handle.
	 *
	 * @var bool
	 */
	protected static bool $use_transactions = true;

	/**
	 * Set up before class (once per test class).
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// Start a transaction for test isolation.
		if ( self::$use_transactions ) {
			global $wpdb;
			$wpdb->query( 'START TRANSACTION' );
		}
	}

	/**
	 * Tear down after class.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		// Rollback transaction to restore database state.
		if ( self::$use_transactions ) {
			global $wpdb;
			$wpdb->query( 'ROLLBACK' );
		}

		parent::tearDownAfterClass();
	}

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset factories.
		\NetterTechEvents\Tests\Factories\EventFactory::reset();
		\NetterTechEvents\Tests\Factories\OccurrenceFactory::reset();
		\NetterTechEvents\Tests\Factories\TicketTypeFactory::reset();
		\NetterTechEvents\Tests\Factories\AttendeeFactory::reset();
		\NetterTechEvents\Tests\Factories\WooCommerceFactory::reset();
	}

	/**
	 * Get fresh instance of $wpdb.
	 *
	 * @return \wpdb
	 */
	protected function get_wpdb(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Create database savepoint for nested transaction support.
	 *
	 * @param string $name Savepoint name.
	 * @return void
	 */
	protected function create_savepoint( string $name ): void {
		global $wpdb;
		$wpdb->query( "SAVEPOINT {$name}" );
	}

	/**
	 * Rollback to a savepoint.
	 *
	 * @param string $name Savepoint name.
	 * @return void
	 */
	protected function rollback_to_savepoint( string $name ): void {
		global $wpdb;
		$wpdb->query( "ROLLBACK TO SAVEPOINT {$name}" );
	}
}
