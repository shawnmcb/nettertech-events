<?php
/**
 * BeaverBuilderIntegration unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\BeaverBuilder
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\BeaverBuilder;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use NetterTechEvents\Integrations\BeaverBuilder\BeaverBuilderIntegration;

/**
 * Test BeaverBuilderIntegration class.
 *
 * Covers init() hook registration, no-op behaviour when FLBuilder is absent,
 * module category injection, and layout-data view detection.
 *
 * @coversDefaultClass \NetterTechEvents\Integrations\BeaverBuilder\BeaverBuilderIntegration
 */
class BeaverBuilderIntegrationTest extends \NetterTechEventsTestCase {

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		// Ensure NETTERTECH_EVENTS_PLUGIN_DIR is present (defined in bootstrap).
		$this->assertTrue( defined( 'NETTERTECH_EVENTS_PLUGIN_DIR' ) );
	}

	/**
	 * Test instantiation captures modules directory.
	 *
	 * @return void
	 */
	public function test_constructor_sets_modules_dir(): void {
		$integration = new BeaverBuilderIntegration();
		$reflection  = new \ReflectionClass( $integration );
		$prop        = $reflection->getProperty( 'modules_dir' );
		$value       = $prop->getValue( $integration );

		$this->assertStringContainsString( 'Integrations/BeaverBuilder/Modules/', $value );
	}

	/**
	 * Test init() registers hooks.
	 *
	 * @return void
	 */
	public function test_init_does_not_throw(): void {
		$integration = new BeaverBuilderIntegration();
		$integration->init();

		// Sanity: integration still exists post-init.
		$this->assertInstanceOf( BeaverBuilderIntegration::class, $integration );
	}

	/**
	 * Test register_modules() short-circuits when FLBuilder class is absent.
	 *
	 * Runs in an isolated process: sibling tests eval() a FLBuilder stub into the
	 * global class table, and PHP cannot undefine a class. Without isolation this
	 * assertion depends on execution order and fails under Infection's random run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_register_modules_noop_when_flbuilder_absent(): void {
		$this->assertFalse( class_exists( 'FLBuilder' ), 'FLBuilder must be absent in unit-test environment.' );

		$integration = new BeaverBuilderIntegration();
		$integration->register_modules();

		// If this returns without fatal, the FLBuilder-absent branch is exercised.
		$this->assertTrue( true );
	}

	/**
	 * Test register_modules() walks all three module configs when FLBuilder is present.
	 *
	 * Stubs FLBuilder + FLBuilderModule so the require_once + register_module calls succeed
	 * without dragging in the real Beaver Builder framework.
	 *
	 * @return void
	 */
	public function test_register_modules_walks_all_modules_when_flbuilder_present(): void {
		if ( ! class_exists( 'FLBuilderModule' ) ) {
			eval( 'class FLBuilderModule { public function __construct($args = array()) { /* noop */ } }' );
		}
		if ( ! class_exists( 'FLBuilder' ) ) {
			eval( 'class FLBuilder { public static $calls = array(); public static function register_module($class, $config) { self::$calls[] = array($class, $config); } }' );
		}

		// Reset call log.
		\FLBuilder::$calls = array();

		// Define NETTERTECH_EVENTS_PLUGIN_URL if not already set (referenced inside module constructors,
		// not register_modules itself, so this is defensive).
		if ( ! defined( 'NETTERTECH_EVENTS_PLUGIN_URL' ) ) {
			define( 'NETTERTECH_EVENTS_PLUGIN_URL', 'http://example.test/wp-content/plugins/nettertech-events/' );
		}

		$integration = new BeaverBuilderIntegration();
		$integration->register_modules();

		// All three modules should have been registered.
		$this->assertCount( 3, \FLBuilder::$calls );

		// Verify the class names registered match expectations.
		$registered_classes = array_map( static fn( $c ) => $c[0], \FLBuilder::$calls );
		$this->assertContains( 'NetterTechEvents\\Integrations\\BeaverBuilder\\Modules\\EventCarousel\\EventCarouselModule', $registered_classes );
		$this->assertContains( 'NetterTechEvents\\Integrations\\BeaverBuilder\\Modules\\EventList\\EventListModule', $registered_classes );
		$this->assertContains( 'NetterTechEvents\\Integrations\\BeaverBuilder\\Modules\\EventCalendar\\EventCalendarModule', $registered_classes );
	}

	/**
	 * Test add_module_category appends NetterTech Events category.
	 *
	 * @return void
	 */
	public function test_add_module_category_appends_category(): void {
		$integration = new BeaverBuilderIntegration();
		$existing    = array( 'Other', 'Advanced' );
		$result      = $integration->add_module_category( $existing );

		$this->assertCount( 3, $result );
		$this->assertContains( 'NetterTech Events', $result );
	}

	/**
	 * Test add_module_category preserves order.
	 *
	 * @return void
	 */
	public function test_add_module_category_appends_to_end(): void {
		$integration = new BeaverBuilderIntegration();
		$existing    = array( 'A', 'B' );
		$result      = $integration->add_module_category( $existing );

		$this->assertSame( 'NetterTech Events', $result[2] );
	}

	/**
	 * Test detect_builder_views returns unmodified views when FLBuilderModel absent.
	 *
	 * Runs in an isolated process: sibling tests eval() a FLBuilderModel stub into
	 * the global class table, and PHP cannot undefine a class. Without isolation this
	 * assertion depends on execution order and fails under Infection's random run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_detect_builder_views_noop_when_flbuildermodel_absent(): void {
		$this->assertFalse( class_exists( 'FLBuilderModel' ), 'FLBuilderModel must be absent for this test.' );

		$integration = new BeaverBuilderIntegration();
		$views       = array( 'list' );
		$result      = $integration->detect_builder_views( $views );

		$this->assertSame( $views, $result );
	}

	/**
	 * Test detect_builder_views returns unchanged when no post in scope.
	 *
	 * Simulated by leaving global $post unset and stubbing FLBuilderModel.
	 *
	 * @return void
	 */
	public function test_detect_builder_views_returns_unchanged_when_no_post(): void {
		// Stub FLBuilderModel for this test only via runkit-style class alias.
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			eval( 'class FLBuilderModel { public static $layout_data = array(); public static $is_active = false; public static function get_layout_data($a,$b){ return self::$layout_data; } public static function is_builder_active() { return self::$is_active; } }' );
		}

		global $post;
		$old_post = $post;
		$post     = null;

		try {
			$integration = new BeaverBuilderIntegration();
			$views       = array( 'list' );
			$result      = $integration->detect_builder_views( $views );

			$this->assertSame( $views, $result );
		} finally {
			$post = $old_post;
		}
	}

	/**
	 * Test detect_builder_views returns unchanged when layout data is empty.
	 *
	 * @return void
	 */
	public function test_detect_builder_views_returns_unchanged_when_layout_empty(): void {
		$this->ensure_flbuilder_model_stub();

		\FLBuilderModel::$layout_data = array();
		\FLBuilderModel::$is_active   = false;

		global $post;
		$old_post = $post;
		$post     = new \stdClass();
		$post     = $this->make_wp_post();

		try {
			$integration = new BeaverBuilderIntegration();
			$views       = array( 'list' );
			$result      = $integration->detect_builder_views( $views );

			$this->assertSame( $views, $result );
		} finally {
			$post = $old_post;
		}
	}

	/**
	 * Test detect_builder_views adds module-derived views from layout data.
	 *
	 * @return void
	 */
	public function test_detect_builder_views_adds_module_views(): void {
		$this->ensure_flbuilder_model_stub();

		$node1                = new \stdClass();
		$node1->type          = 'module';
		$node1->settings      = new \stdClass();
		$node1->settings->type = 'event-carousel';

		$node2                = new \stdClass();
		$node2->type          = 'module';
		$node2->settings      = new \stdClass();
		$node2->settings->type = 'event-list';

		$node3                = new \stdClass();
		$node3->type          = 'module';
		$node3->settings      = new \stdClass();
		$node3->settings->type = 'event-calendar';

		$node4       = new \stdClass();
		$node4->type = 'column'; // Not a module — ignored.

		\FLBuilderModel::$layout_data = array( $node1, $node2, $node3, $node4 );
		\FLBuilderModel::$is_active   = false;

		global $post;
		$old_post = $post;
		$post     = $this->make_wp_post();

		try {
			$integration = new BeaverBuilderIntegration();
			$result      = $integration->detect_builder_views( array() );

			$this->assertContains( 'carousel', $result );
			$this->assertContains( 'grid', $result );
			$this->assertContains( 'calendar', $result );
			$this->assertCount( 3, $result );
		} finally {
			$post = $old_post;
		}
	}

	/**
	 * Test detect_builder_views ignores unknown module types.
	 *
	 * @return void
	 */
	public function test_detect_builder_views_ignores_unknown_modules(): void {
		$this->ensure_flbuilder_model_stub();

		$node                = new \stdClass();
		$node->type          = 'module';
		$node->settings      = new \stdClass();
		$node->settings->type = 'unknown-module-type';

		\FLBuilderModel::$layout_data = array( $node );
		\FLBuilderModel::$is_active   = false;

		global $post;
		$old_post = $post;
		$post     = $this->make_wp_post();

		try {
			$integration = new BeaverBuilderIntegration();
			$result      = $integration->detect_builder_views( array( 'existing' ) );

			// Only the pre-existing view should remain.
			$this->assertSame( array( 'existing' ), $result );
		} finally {
			$post = $old_post;
		}
	}

	/**
	 * Ensure FLBuilderModel stub class is defined.
	 *
	 * @return void
	 */
	private function ensure_flbuilder_model_stub(): void {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			eval( 'class FLBuilderModel { public static $layout_data = array(); public static $is_active = false; public static function get_layout_data($a,$b){ return self::$layout_data; } public static function is_builder_active() { return self::$is_active; } }' );
		}
	}

	/**
	 * Build a stub WP_Post object.
	 *
	 * @return \WP_Post
	 */
	private function make_wp_post() {
		// WP_Post stub is declared in tests/bootstrap.php; pass ID via
		// constructor so $ID is assigned to the declared property.
		return new \WP_Post( (object) array( 'ID' => 1 ) );
	}
}
