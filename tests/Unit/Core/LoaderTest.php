<?php
/**
 * Loader class unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\Loader;
use Brain\Monkey\Functions;

/**
 * Test Loader class functionality.
 */
class LoaderTest extends \NetterTechEventsTestCase {

	/**
	 * Loader instance.
	 *
	 * @var Loader
	 */
	private Loader $loader;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->loader = new Loader();
	}

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test Loader class exists.
	 *
	 * @return void
	 */
	public function test_loader_class_exists(): void {
		$this->assertTrue( class_exists( Loader::class ) );
	}

	/**
	 * Test Loader can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$this->assertInstanceOf( Loader::class, $this->loader );
	}

	/**
	 * Test required methods exist.
	 *
	 * @return void
	 */
	public function test_required_methods_exist(): void {
		$methods = array( 'add_action', 'add_filter', 'run' );

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( $this->loader, $method ),
				"Method {$method} should exist"
			);
		}
	}

	// =========================================================================
	// add_action Tests
	// =========================================================================

	/**
	 * Test add_action stores action hook.
	 *
	 * @return void
	 */
	public function test_add_action_stores_hook(): void {
		$component = new \stdClass();

		$registered = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority, $args ) use ( &$registered ) {
				$registered[] = array(
					'hook'     => $hook,
					'priority' => $priority,
					'args'     => $args,
				);
			}
		);

		$this->loader->add_action( 'init', $component, 'callback' );
		$this->loader->run();

		$this->assertCount( 1, $registered );
		$this->assertEquals( 'init', $registered[0]['hook'] );
		$this->assertEquals( 10, $registered[0]['priority'] );
		$this->assertEquals( 1, $registered[0]['args'] );
	}

	/**
	 * Test add_action with custom priority.
	 *
	 * @return void
	 */
	public function test_add_action_with_custom_priority(): void {
		$component = new \stdClass();

		$registered_priority = null;
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority ) use ( &$registered_priority ) {
				$registered_priority = $priority;
			}
		);

		$this->loader->add_action( 'init', $component, 'callback', 20 );
		$this->loader->run();

		$this->assertEquals( 20, $registered_priority );
	}

	/**
	 * Test add_action with custom accepted args.
	 *
	 * @return void
	 */
	public function test_add_action_with_custom_accepted_args(): void {
		$component = new \stdClass();

		$registered_args = null;
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority, $args ) use ( &$registered_args ) {
				$registered_args = $args;
			}
		);

		$this->loader->add_action( 'init', $component, 'callback', 10, 3 );
		$this->loader->run();

		$this->assertEquals( 3, $registered_args );
	}

	// =========================================================================
	// add_filter Tests
	// =========================================================================

	/**
	 * Test add_filter stores filter hook.
	 *
	 * @return void
	 */
	public function test_add_filter_stores_hook(): void {
		$component = new \stdClass();

		$registered = array();
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback, $priority, $args ) use ( &$registered ) {
				$registered[] = array(
					'hook'     => $hook,
					'priority' => $priority,
					'args'     => $args,
				);
			}
		);

		$this->loader->add_filter( 'the_content', $component, 'modify_content' );
		$this->loader->run();

		$this->assertCount( 1, $registered );
		$this->assertEquals( 'the_content', $registered[0]['hook'] );
		$this->assertEquals( 10, $registered[0]['priority'] );
		$this->assertEquals( 1, $registered[0]['args'] );
	}

	/**
	 * Test add_filter with custom priority.
	 *
	 * @return void
	 */
	public function test_add_filter_with_custom_priority(): void {
		$component = new \stdClass();

		$registered_priority = null;
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback, $priority ) use ( &$registered_priority ) {
				$registered_priority = $priority;
			}
		);

		$this->loader->add_filter( 'the_title', $component, 'modify_title', 5 );
		$this->loader->run();

		$this->assertEquals( 5, $registered_priority );
	}

	// =========================================================================
	// run Tests
	// =========================================================================

	/**
	 * Test run registers all actions.
	 *
	 * @return void
	 */
	public function test_run_registers_all_actions(): void {
		$component = new \stdClass();

		$action_count = 0;
		Functions\when( 'add_action' )->alias(
			function () use ( &$action_count ) {
				$action_count++;
			}
		);

		$this->loader->add_action( 'init', $component, 'callback1' );
		$this->loader->add_action( 'wp_loaded', $component, 'callback2' );
		$this->loader->run();

		$this->assertEquals( 2, $action_count );
	}

	/**
	 * Test run registers all filters.
	 *
	 * @return void
	 */
	public function test_run_registers_all_filters(): void {
		$component = new \stdClass();

		$filter_count = 0;
		Functions\when( 'add_filter' )->alias(
			function () use ( &$filter_count ) {
				$filter_count++;
			}
		);

		$this->loader->add_filter( 'the_content', $component, 'filter1' );
		$this->loader->add_filter( 'the_title', $component, 'filter2' );
		$this->loader->run();

		$this->assertEquals( 2, $filter_count );
	}

	/**
	 * Test run registers both actions and filters.
	 *
	 * @return void
	 */
	public function test_run_registers_actions_and_filters(): void {
		$component = new \stdClass();

		$action_count = 0;
		$filter_count = 0;

		Functions\when( 'add_action' )->alias(
			function () use ( &$action_count ) {
				$action_count++;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function () use ( &$filter_count ) {
				$filter_count++;
			}
		);

		$this->loader->add_action( 'init', $component, 'action_callback' );
		$this->loader->add_filter( 'the_content', $component, 'filter_callback' );
		$this->loader->run();

		$this->assertEquals( 1, $action_count );
		$this->assertEquals( 1, $filter_count );
	}

	/**
	 * Test run with no hooks registered.
	 *
	 * @return void
	 */
	public function test_run_with_no_hooks(): void {
		$any_calls = false;

		Functions\when( 'add_action' )->alias(
			function () use ( &$any_calls ) {
				$any_calls = true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function () use ( &$any_calls ) {
				$any_calls = true;
			}
		);

		$this->loader->run();

		$this->assertFalse( $any_calls, 'No hooks should be registered' );
	}

	/**
	 * Test complex hook registration scenario.
	 *
	 * @return void
	 */
	public function test_complex_hook_registration(): void {
		$component1 = new \stdClass();
		$component2 = new \stdClass();

		$actions = array();
		$filters = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority ) use ( &$actions ) {
				$actions[] = array( 'hook' => $hook, 'priority' => $priority );
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback, $priority ) use ( &$filters ) {
				$filters[] = array( 'hook' => $hook, 'priority' => $priority );
			}
		);

		// Register multiple hooks with various priorities.
		$this->loader->add_action( 'init', $component1, 'init_early', 1 );
		$this->loader->add_action( 'init', $component2, 'init_late', 999 );
		$this->loader->add_filter( 'the_content', $component1, 'filter_content', 10 );
		$this->loader->add_filter( 'the_title', $component2, 'filter_title', 5 );

		$this->loader->run();

		// Should register 2 actions and 2 filters.
		$this->assertCount( 2, $actions );
		$this->assertCount( 2, $filters );
	}
}
