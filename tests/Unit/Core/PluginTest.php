<?php
/**
 * Plugin class unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\Plugin;
use NetterTechEvents\Core\Loader;
use NetterTechEvents\Core\CacheManager;

/**
 * Test Plugin class structure and getters.
 *
 * Note: Tests that require mocking static class methods (Schema::needs_migration)
 * or PHP built-in functions (file_exists) are excluded as they require special
 * patchwork configuration. Integration tests should verify the full flow.
 *
 * @group structural
 */
class PluginTest extends \NetterTechEventsTestCase {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->plugin = new Plugin();
	}

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test Plugin class exists.
	 *
	 * @return void
	 */
	public function test_plugin_class_exists(): void {
		$this->assertTrue( class_exists( Plugin::class ) );
	}

	/**
	 * Test Plugin can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$this->assertInstanceOf( Plugin::class, $this->plugin );
	}

	/**
	 * Test required methods exist.
	 *
	 * @return void
	 */
	public function test_required_methods_exist(): void {
		$methods = array(
			'init',
			'get_loader',
			'get_cache_manager',
			'get_version',
			'register_blocks',
			'register_rest_routes',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( $this->plugin, $method ),
				"Method {$method} should exist"
			);
		}
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test constructor initializes loader.
	 *
	 * @return void
	 */
	public function test_constructor_initializes_loader(): void {
		$loader = $this->plugin->get_loader();

		$this->assertInstanceOf( Loader::class, $loader );
	}

	/**
	 * Test cache manager is initialized in the constructor.
	 *
	 * @return void
	 */
	public function test_cache_manager_initialized_in_constructor(): void {
		$this->assertInstanceOf( CacheManager::class, $this->plugin->get_cache_manager() );
	}

	// =========================================================================
	// Getter Tests
	// =========================================================================

	/**
	 * Test get_loader returns Loader instance.
	 *
	 * @return void
	 */
	public function test_get_loader_returns_loader(): void {
		$loader = $this->plugin->get_loader();

		$this->assertInstanceOf( Loader::class, $loader );
	}

	/**
	 * Test get_cache_manager method exists and is public.
	 *
	 * CacheManager requires init() to be called first, which depends on
	 * Templates and other services not available in unit tests.
	 *
	 * @return void
	 */
	public function test_get_cache_manager_method_exists(): void {
		$this->assertTrue( method_exists( $this->plugin, 'get_cache_manager' ) );
	}

	/**
	 * Test get_version returns version constant.
	 *
	 * @return void
	 */
	public function test_get_version_returns_version(): void {
		$version = $this->plugin->get_version();

		$this->assertSame( NETTERTECH_EVENTS_VERSION, $version );
		$this->assertIsString( $version );
	}

	/**
	 * Test get_loader always returns same instance.
	 *
	 * @return void
	 */
	public function test_get_loader_returns_same_instance(): void {
		$loader1 = $this->plugin->get_loader();
		$loader2 = $this->plugin->get_loader();

		$this->assertSame( $loader1, $loader2 );
	}

	/**
	 * Test cache_manager property is typed as CacheManager.
	 *
	 * Verifies the property exists and has the correct type declaration.
	 * Full initialization requires init() which depends on services
	 * not available in unit tests.
	 *
	 * @return void
	 */
	public function test_cache_manager_property_is_typed(): void {
		$reflection = new \ReflectionProperty( Plugin::class, 'cache_manager' );
		$type       = $reflection->getType();

		$this->assertNotNull( $type );
		$this->assertSame( CacheManager::class, $type->getName() );
	}

	// =========================================================================
	// Method Accessibility Tests
	// =========================================================================

	/**
	 * Test register_blocks is callable.
	 *
	 * @return void
	 */
	public function test_register_blocks_is_callable(): void {
		$this->assertTrue( is_callable( array( $this->plugin, 'register_blocks' ) ) );
	}

	/**
	 * Test register_rest_routes is callable.
	 *
	 * @return void
	 */
	public function test_register_rest_routes_is_callable(): void {
		$this->assertTrue( is_callable( array( $this->plugin, 'register_rest_routes' ) ) );
	}

	/**
	 * Test init is callable.
	 *
	 * @return void
	 */
	public function test_init_is_callable(): void {
		$this->assertTrue( is_callable( array( $this->plugin, 'init' ) ) );
	}
}
