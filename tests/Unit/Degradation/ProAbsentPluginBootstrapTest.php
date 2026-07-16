<?php
/**
 * Plugin bootstrap degradation tests — Pro absent.
 *
 * Proves Plugin can be instantiated and that it fires the admin/frontend
 * ready hooks even without Pro services installed.
 *
 * @package NetterTechEvents\Tests\Unit\Degradation
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Degradation;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\Plugin;
use NetterTechEvents\Core\ServiceRegistry;

/**
 * Verify Plugin bootstrap operates cleanly without Pro.
 *
 * @covers \NetterTechEvents\Core\Plugin
 */
class ProAbsentPluginBootstrapTest extends \NetterTechEventsTestCase {

	/**
	 * Reset ServiceRegistry after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ServiceRegistry::reset();
		parent::tearDown();
	}

	// =========================================================================
	// Instantiation
	// =========================================================================

	/**
	 * Test Plugin instantiates cleanly without Pro.
	 *
	 * @return void
	 */
	public function test_plugin_instantiates_without_pro(): void {
		$plugin = new Plugin();

		$this->assertInstanceOf( Plugin::class, $plugin );
	}

	/**
	 * Test get_container returns a Container after construction.
	 *
	 * @return void
	 */
	public function test_get_container_returns_container(): void {
		$plugin = new Plugin();

		$container = $plugin->get_container();

		$this->assertInstanceOf( \NetterTechEvents\Core\Container::class, $container );
	}

	/**
	 * Test get_loader returns a Loader after construction.
	 *
	 * @return void
	 */
	public function test_get_loader_returns_loader(): void {
		$plugin = new Plugin();

		$this->assertInstanceOf( \NetterTechEvents\Core\Loader::class, $plugin->get_loader() );
	}

	// =========================================================================
	// ACTION_ADMIN_READY hook constant is defined
	// =========================================================================

	/**
	 * Test ACTION_ADMIN_READY hook constant is defined on Hooks class.
	 *
	 * init_admin() fires this hook; without Pro nothing listens but the
	 * action itself must exist.
	 *
	 * @return void
	 */
	public function test_action_admin_ready_constant_defined(): void {
		$this->assertSame( 'nettertech_events_admin_ready', Hooks::ACTION_ADMIN_READY );
	}

	/**
	 * Test ACTION_FRONTEND_READY hook constant is defined on Hooks class.
	 *
	 * @return void
	 */
	public function test_action_frontend_ready_constant_defined(): void {
		$this->assertSame( 'nettertech_events_frontend_ready', Hooks::ACTION_FRONTEND_READY );
	}

	// =========================================================================
	// Container binds QRCodeServiceInterface in base (NTE-041)
	// =========================================================================

	/**
	 * Test container binds QRCodeServiceInterface after base init.
	 *
	 * @return void
	 */
	public function test_container_has_qr_service_after_base_init(): void {
		$plugin    = new Plugin();
		$container = $plugin->get_container();

		$this->assertTrue(
			$container->has( \NetterTechEvents\Contracts\QRCodeServiceInterface::class ),
			'QRCodeServiceInterface must be bound in the base container per NTE-041'
		);
	}

	/**
	 * Test required plugin methods exist on the Plugin class.
	 *
	 * @return void
	 */
	public function test_required_methods_exist(): void {
		$methods = array(
			'init',
			'get_loader',
			'get_cache_manager',
			'get_version',
			'get_container',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( Plugin::class, $method ),
				"Plugin must have method: {$method}"
			);
		}
	}
}
