<?php
/**
 * Assets class unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\Assets;
use NetterTechEvents\Services\PaletteResolver;
use Brain\Monkey\Functions;

/**
 * Test Assets class functionality.
 *
 * Palette detection and color science tests are in PaletteResolverTest
 * and ColorUtilityTest respectively.
 */
class AssetsTest extends \NetterTechEventsTestCase {

	/**
	 * Assets instance.
	 *
	 * @var Assets
	 */
	private Assets $assets;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Inject a mock PaletteResolver that returns empty (no palette overrides).
		$mock_resolver = \Mockery::mock( PaletteResolver::class );
		$mock_resolver->shouldReceive( 'resolve' )->andReturn( array() )->byDefault();

		$settings = new \NetterTechEvents\Core\NetterTechEventsSettings();

		$this->assets = new Assets( $mock_resolver, $settings );
	}

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test Assets class exists.
	 *
	 * @return void
	 */
	public function test_assets_class_exists(): void {
		$this->assertTrue( class_exists( Assets::class ) );
	}

	/**
	 * Test Assets can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$this->assertInstanceOf( Assets::class, $this->assets );
	}

	/**
	 * Test Assets can be instantiated without arguments (backward compatibility).
	 *
	 * @return void
	 */
	public function test_can_instantiate_without_arguments(): void {
		$mock_resolver = \Mockery::mock( PaletteResolver::class );
		$mock_resolver->shouldReceive( 'resolve' )->andReturn( array() )->byDefault();

		$assets = new Assets( $mock_resolver, new \NetterTechEvents\Core\NetterTechEventsSettings() );
		$this->assertInstanceOf( Assets::class, $assets );
	}

	/**
	 * Test required methods exist.
	 *
	 * @return void
	 */
	public function test_required_methods_exist(): void {
		$methods = array(
			'register',
			'enqueue_checkout',
			'maybe_enqueue_frontend',
			'enqueue_admin',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( $this->assets, $method ),
				"Method {$method} should exist"
			);
		}
	}

	// =========================================================================
	// register Tests
	// =========================================================================

	/**
	 * Test register calls wp_register_style for all styles.
	 *
	 * @return void
	 */
	public function test_register_registers_styles(): void {
		$registered_styles = array();

		Functions\when( 'wp_register_style' )->alias(
			function ( $handle ) use ( &$registered_styles ) {
				$registered_styles[] = $handle;
			}
		);
		Functions\when( 'wp_register_script' )->justReturn( true );

		$this->assets->register();

		// Should register multiple styles.
		$this->assertGreaterThanOrEqual( 6, count( $registered_styles ) );
		$this->assertContains( 'nettertech-events-base', $registered_styles );
	}

	/**
	 * Test register calls wp_register_script for all scripts.
	 *
	 * @return void
	 */
	public function test_register_registers_scripts(): void {
		$registered_scripts = array();

		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_register_script' )->alias(
			function ( $handle ) use ( &$registered_scripts ) {
				$registered_scripts[] = $handle;
			}
		);

		$this->assets->register();

		// Should register multiple scripts.
		$this->assertGreaterThanOrEqual( 4, count( $registered_scripts ) );
	}

	/**
	 * Test register registers checkout assets.
	 *
	 * @return void
	 */
	public function test_register_registers_checkout_assets(): void {
		$registered_handles = array();

		Functions\when( 'wp_register_style' )->alias(
			function ( $handle ) use ( &$registered_handles ) {
				$registered_handles[] = $handle;
			}
		);
		Functions\when( 'wp_register_script' )->alias(
			function ( $handle ) use ( &$registered_handles ) {
				$registered_handles[] = $handle;
			}
		);

		$this->assets->register();

		$this->assertContains( 'nettertech-events-checkout', $registered_handles );
	}

	/**
	 * Test register registers admin assets.
	 *
	 * @return void
	 */
	public function test_register_registers_admin_assets(): void {
		$registered_handles = array();

		Functions\when( 'wp_register_style' )->alias(
			function ( $handle ) use ( &$registered_handles ) {
				$registered_handles[] = $handle;
			}
		);
		Functions\when( 'wp_register_script' )->alias(
			function ( $handle ) use ( &$registered_handles ) {
				$registered_handles[] = $handle;
			}
		);

		$this->assets->register();

		$this->assertContains( 'nettertech-events-admin', $registered_handles );
	}

	// =========================================================================
	// enqueue_checkout Tests
	// =========================================================================

	/**
	 * Test enqueue_checkout only enqueues on checkout page.
	 *
	 * @return void
	 */
	public function test_enqueue_checkout_only_on_checkout_page(): void {
		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );

		// Should not throw exceptions.
		$this->assets->enqueue_checkout();
		$this->assertTrue( true );
	}

	/**
	 * Test enqueue_checkout does nothing if not checkout page.
	 *
	 * @return void
	 */
	public function test_enqueue_checkout_does_nothing_when_not_checkout(): void {
		Functions\when( 'is_checkout' )->justReturn( false );

		$enqueued = false;
		Functions\when( 'wp_enqueue_style' )->alias(
			function () use ( &$enqueued ) {
				$enqueued = true;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function () use ( &$enqueued ) {
				$enqueued = true;
			}
		);

		$this->assets->enqueue_checkout();

		$this->assertFalse( $enqueued, 'Nothing should be enqueued when not checkout page' );
	}

	// =========================================================================
	// maybe_enqueue_frontend Tests
	// =========================================================================

	/**
	 * Test maybe_enqueue_frontend does nothing without detected views.
	 *
	 * @return void
	 */
	public function test_maybe_enqueue_frontend_checks_conditions(): void {
		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_post_type_archive' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( '' );

		$enqueued = false;
		Functions\when( 'wp_enqueue_style' )->alias(
			function () use ( &$enqueued ) {
				$enqueued = true;
			}
		);

		$this->assets->maybe_enqueue_frontend();

		$this->assertFalse( $enqueued, 'Nothing should be enqueued without matching conditions' );
	}

	/**
	 * Test maybe_enqueue_frontend enqueues on single event.
	 *
	 * @return void
	 */
	public function test_maybe_enqueue_frontend_enqueues_on_single_event(): void {
		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_post_type_archive' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\when( 'apply_filters' )->alias( fn( $hook, $data ) => $data );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'rest_url' )->justReturn( 'http://example.com/wp-json/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( '__' )->alias( fn( $text ) => $text );

		// Should not throw exceptions.
		$this->assets->maybe_enqueue_frontend();
		$this->assertTrue( true );
	}

	// =========================================================================
	// enqueue_admin Tests
	// =========================================================================

	/**
	 * Test enqueue_admin does nothing on non-NTE pages.
	 *
	 * @return void
	 */
	public function test_enqueue_admin_does_nothing_on_other_pages(): void {
		Functions\when( 'get_current_screen' )->justReturn(
			(object) array( 'post_type' => 'post' )
		);

		$enqueued = false;
		Functions\when( 'wp_enqueue_style' )->alias(
			function () use ( &$enqueued ) {
				$enqueued = true;
			}
		);

		$this->assets->enqueue_admin( 'edit.php' );

		$this->assertFalse( $enqueued, 'Nothing should be enqueued on non-NTE pages' );
	}

	/**
	 * Test enqueue_admin enqueues on nettertech events pages.
	 *
	 * @return void
	 */
	public function test_enqueue_admin_enqueues_on_nte_pages(): void {
		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'get_current_screen' )->justReturn(
			(object) array( 'post_type' => 'nettertech_event' )
		);
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'rest_url' )->justReturn( 'http://example.com/wp-json/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/' );

		// Should not throw exceptions.
		$this->assets->enqueue_admin( 'edit.php' );
		$this->assertTrue( true );
	}

	/**
	 * Test enqueue_admin recognizes nettertech events admin pages by hook suffix.
	 *
	 * @return void
	 */
	public function test_enqueue_admin_recognizes_admin_pages_by_hook(): void {
		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'get_current_screen' )->justReturn( null );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'rest_url' )->justReturn( 'http://example.com/wp-json/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/' );

		// Should not throw exceptions for nettertech-events page.
		$this->assets->enqueue_admin( 'toplevel_page_nettertech-events' );
		$this->assertTrue( true );
	}
}
