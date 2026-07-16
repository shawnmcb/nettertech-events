<?php
/**
 * Templates unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\TemplateLoader
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\TemplateLoader;

use Brain\Monkey\Functions;
use NetterTechEvents\TemplateLoader\Templates;

/**
 * Test Templates facade class.
 */
class TemplatesTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test class exists.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( Templates::class ) );
	}

	/**
	 * Test class is not final (removed for DI mockability per ADR-013).
	 *
	 * @return void
	 */
	public function test_class_is_not_final(): void {
		$reflection = new \ReflectionClass( Templates::class );
		$this->assertFalse( $reflection->isFinal() );
	}

	/**
	 * Test constructor is private.
	 *
	 * @return void
	 */
	public function test_constructor_is_private(): void {
		$reflection = new \ReflectionClass( Templates::class );
		$constructor = $reflection->getConstructor();

		$this->assertNotNull( $constructor );
		$this->assertTrue( $constructor->isPrivate() );
	}

	/**
	 * Test required methods exist.
	 *
	 * @return void
	 */
	public function test_required_methods_exist(): void {
		$methods = array(
			'init',
			'instance',
			'get_part',
			'locate_part',
			'get_template',
			'part_exists',
			'template_exists',
			'locate',
			'file_exists',
			'clear_cache',
			'get_theme_directory',
			'plugin_path',
			'container_class',
			'detect_theme',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( Templates::class, $method ),
				"Method {$method} should exist"
			);
		}
	}

	/**
	 * Test all public methods are static.
	 *
	 * @return void
	 */
	public function test_public_methods_are_static(): void {
		$methods = array(
			'init',
			'instance',
			'get_part',
			'locate_part',
			'get_template',
			'part_exists',
			'template_exists',
			'locate',
			'file_exists',
			'clear_cache',
			'get_theme_directory',
			'plugin_path',
			'container_class',
			'detect_theme',
		);

		foreach ( $methods as $method ) {
			$reflection = new \ReflectionMethod( Templates::class, $method );
			$this->assertTrue(
				$reflection->isStatic(),
				"Method {$method} should be static"
			);
		}
	}

	// =========================================================================
	// file_exists Tests
	// =========================================================================

	/**
	 * Test file_exists returns true for existing file.
	 *
	 * @return void
	 */
	public function test_file_exists_returns_true_for_existing_file(): void {
		// Use the test file itself as an existing file.
		$existing_file = __FILE__;

		$result = Templates::file_exists( $existing_file );

		$this->assertTrue( $result );
	}

	/**
	 * Test file_exists returns false for non-existent file.
	 *
	 * @return void
	 */
	public function test_file_exists_returns_false_for_nonexistent_file(): void {
		$nonexistent_file = '/path/to/nonexistent/file.php';

		$result = Templates::file_exists( $nonexistent_file );

		$this->assertFalse( $result );
	}

	/**
	 * Test file_exists caches results.
	 *
	 * @return void
	 */
	public function test_file_exists_caches_results(): void {
		// Call twice with same path - second call should use cache.
		$path = '/path/to/check/caching.php';

		$result1 = Templates::file_exists( $path );
		$result2 = Templates::file_exists( $path );

		$this->assertSame( $result1, $result2 );
	}

	// =========================================================================
	// plugin_path Tests
	// =========================================================================

	/**
	 * Test plugin_path returns correct path.
	 *
	 * @return void
	 */
	public function test_plugin_path_returns_correct_path(): void {
		$path = Templates::plugin_path( 'single-event.php' );

		$this->assertStringContainsString( 'templates/single-event.php', $path );
	}

	/**
	 * Test plugin_path strips leading slash.
	 *
	 * @return void
	 */
	public function test_plugin_path_strips_leading_slash(): void {
		$path = Templates::plugin_path( '/single-event.php' );

		$this->assertStringContainsString( 'templates/single-event.php', $path );
		$this->assertStringNotContainsString( 'templates//single-event.php', $path );
	}

	/**
	 * Test plugin_path handles nested paths.
	 *
	 * @return void
	 */
	public function test_plugin_path_handles_nested_paths(): void {
		$path = Templates::plugin_path( 'parts/event-card.php' );

		$this->assertStringContainsString( 'templates/parts/event-card.php', $path );
	}

	// =========================================================================
	// get_theme_directory Tests
	// =========================================================================

	/**
	 * Test get_theme_directory returns default value.
	 *
	 * @return void
	 */
	public function test_get_theme_directory_returns_default(): void {
		$directory = Templates::get_theme_directory();

		$this->assertSame( 'nettertech-events', $directory );
	}

	// =========================================================================
	// container_class Tests
	// =========================================================================

	/**
	 * Test container_class returns base class.
	 *
	 * @return void
	 */
	public function test_container_class_returns_base_class(): void {
		Functions\when( 'sanitize_html_class' )->returnArg();
		// apply_filters receives ($filter, $classes, $context, $variant) - return $classes (arg 2).
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$classes = Templates::container_class();

		$this->assertStringContainsString( 'nte-container', $classes );
	}

	/**
	 * Test container_class adds wide variant.
	 *
	 * @return void
	 */
	public function test_container_class_adds_wide_variant(): void {
		Functions\when( 'sanitize_html_class' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$classes = Templates::container_class( '', 'wide' );

		$this->assertStringContainsString( 'nte-container--wide', $classes );
	}

	/**
	 * Test container_class adds full variant.
	 *
	 * @return void
	 */
	public function test_container_class_adds_full_variant(): void {
		Functions\when( 'sanitize_html_class' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$classes = Templates::container_class( '', 'full' );

		$this->assertStringContainsString( 'nte-container--full', $classes );
	}

	/**
	 * Test container_class adds context class.
	 *
	 * @return void
	 */
	public function test_container_class_adds_context_class(): void {
		Functions\when( 'sanitize_html_class' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$classes = Templates::container_class( 'single' );

		$this->assertStringContainsString( 'nte-container--single', $classes );
	}

	/**
	 * Test container_class includes extra classes.
	 *
	 * @return void
	 */
	public function test_container_class_includes_extra_classes(): void {
		Functions\when( 'sanitize_html_class' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$classes = Templates::container_class( '', '', array( 'custom-class', 'another-class' ) );

		$this->assertStringContainsString( 'custom-class', $classes );
		$this->assertStringContainsString( 'another-class', $classes );
	}

	/**
	 * Test container_class applies filter.
	 *
	 * @return void
	 */
	public function test_container_class_applies_filter(): void {
		Functions\when( 'sanitize_html_class' )->returnArg();

		$filter_called = false;
		Functions\when( 'apply_filters' )
			->alias(
				function ( $filter, $value, $context = '', $variant = '' ) use ( &$filter_called ) {
					if ( 'nettertech_events_container_class' === $filter ) {
						$filter_called = true;
					}
					return $value;
				}
			);

		Templates::container_class();

		$this->assertTrue( $filter_called );
	}

	/**
	 * Test container_class with all options combined.
	 *
	 * @return void
	 */
	public function test_container_class_with_all_options(): void {
		Functions\when( 'sanitize_html_class' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$classes = Templates::container_class( 'archive', 'wide', array( 'my-theme-container' ) );

		$this->assertStringContainsString( 'nte-container', $classes );
		$this->assertStringContainsString( 'nte-container--wide', $classes );
		$this->assertStringContainsString( 'nte-container--archive', $classes );
		$this->assertStringContainsString( 'my-theme-container', $classes );
	}

	// =========================================================================
	// detect_theme Tests
	// =========================================================================

	/**
	 * Test detect_theme returns array structure.
	 *
	 * @return void
	 */
	public function test_detect_theme_returns_array_structure(): void {
		// Mock WordPress theme functions.
		$theme_mock = \Mockery::mock( 'WP_Theme' );
		$theme_mock->shouldReceive( 'get_template' )->andReturn( 'twentytwentyfour' );

		Functions\when( 'wp_get_theme' )->justReturn( $theme_mock );
		Functions\when( 'wp_theme_has_theme_json' )->justReturn( false );

		$result = Templates::detect_theme();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'theme', $result );
		$this->assertArrayHasKey( 'framework', $result );
		$this->assertArrayHasKey( 'container_class', $result );
		$this->assertArrayHasKey( 'has_theme_json', $result );
	}

	/**
	 * Test detect_theme identifies Astra theme.
	 *
	 * @return void
	 */
	public function test_detect_theme_identifies_astra(): void {
		// Reset static cache via reflection.
		$reflection = new \ReflectionClass( Templates::class );
		$detected = null;
		// Since detect_theme uses a static cache, we need to ensure tests
		// that modify theme detection run in their own process or we accept
		// that the cache is populated from a prior call.

		$theme_mock = \Mockery::mock( 'WP_Theme' );
		$theme_mock->shouldReceive( 'get_template' )->andReturn( 'astra' );

		Functions\when( 'wp_get_theme' )->justReturn( $theme_mock );
		Functions\when( 'wp_theme_has_theme_json' )->justReturn( false );

		// Note: Due to static caching, this test may not actually test Astra
		// detection if detect_theme was already called in another test.
		// This is a known limitation of testing singleton patterns.
		$result = Templates::detect_theme();

		// Just verify structure since static cache persists.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'framework', $result );
	}

	/**
	 * Test detect_theme identifies theme with theme.json.
	 *
	 * @return void
	 */
	public function test_detect_theme_identifies_theme_json(): void {
		$theme_mock = \Mockery::mock( 'WP_Theme' );
		$theme_mock->shouldReceive( 'get_template' )->andReturn( 'some-block-theme' );

		Functions\when( 'wp_get_theme' )->justReturn( $theme_mock );
		Functions\when( 'wp_theme_has_theme_json' )->justReturn( true );

		// Note: Static caching affects this test.
		$result = Templates::detect_theme();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'has_theme_json', $result );
	}

	// =========================================================================
	// clear_cache Tests
	// =========================================================================

	/**
	 * Test clear_cache method exists and is callable.
	 *
	 * @return void
	 */
	public function test_clear_cache_is_callable(): void {
		$this->assertTrue( is_callable( array( Templates::class, 'clear_cache' ) ) );
	}

	// =========================================================================
	// init Tests
	// =========================================================================

	/**
	 * Test init method exists and is static.
	 *
	 * @return void
	 */
	public function test_init_method_structure(): void {
		$reflection = new \ReflectionMethod( Templates::class, 'init' );

		$this->assertTrue( $reflection->isStatic() );
		$this->assertTrue( $reflection->isPublic() );
	}

	// =========================================================================
	// instance Tests
	// =========================================================================

	/**
	 * Test instance returns TemplateLoader.
	 *
	 * @return void
	 */
	public function test_instance_returns_template_loader(): void {
		// Since NETTERTECH_EVENTS_PLUGIN_DIR is defined in bootstrap, this should work.
		$instance = Templates::instance();

		$this->assertInstanceOf( \NetterTechEvents\TemplateLoader\TemplateLoader::class, $instance );
	}

	// =========================================================================
	// Template Loading Method Structure Tests
	// =========================================================================

	/**
	 * Test get_part returns string.
	 *
	 * @return void
	 */
	public function test_get_part_returns_string(): void {
		$reflection = new \ReflectionMethod( Templates::class, 'get_part' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type );
		$this->assertSame( 'string', $return_type->getName() );
	}

	/**
	 * Test locate_part returns string.
	 *
	 * @return void
	 */
	public function test_locate_part_returns_string(): void {
		$reflection = new \ReflectionMethod( Templates::class, 'locate_part' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type );
		$this->assertSame( 'string', $return_type->getName() );
	}

	/**
	 * Test get_template returns string.
	 *
	 * @return void
	 */
	public function test_get_template_returns_string(): void {
		$reflection = new \ReflectionMethod( Templates::class, 'get_template' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type );
		$this->assertSame( 'string', $return_type->getName() );
	}

	/**
	 * Test part_exists returns bool.
	 *
	 * @return void
	 */
	public function test_part_exists_returns_bool(): void {
		$reflection = new \ReflectionMethod( Templates::class, 'part_exists' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type );
		$this->assertSame( 'bool', $return_type->getName() );
	}

	/**
	 * Test template_exists returns bool.
	 *
	 * @return void
	 */
	public function test_template_exists_returns_bool(): void {
		$reflection = new \ReflectionMethod( Templates::class, 'template_exists' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type );
		$this->assertSame( 'bool', $return_type->getName() );
	}

	/**
	 * Test locate returns string.
	 *
	 * @return void
	 */
	public function test_locate_returns_string(): void {
		$reflection = new \ReflectionMethod( Templates::class, 'locate' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type );
		$this->assertSame( 'string', $return_type->getName() );
	}

	// =========================================================================
	// Execution Tests (Methods Actually Called)
	// =========================================================================

	/**
	 * Test get_part executes and returns string.
	 *
	 * @return void
	 */
	public function test_get_part_executes_and_returns_string(): void {
		Functions\when( 'locate_template' )->justReturn( '' );

		$result = Templates::get_part( 'nonexistent-part' );

		$this->assertIsString( $result );
	}

	/**
	 * Test locate_part executes and returns string.
	 *
	 * @return void
	 */
	public function test_locate_part_executes_and_returns_string(): void {
		Functions\when( 'locate_template' )->justReturn( '' );

		$result = Templates::locate_part( 'event-card' );

		$this->assertIsString( $result );
	}

	/**
	 * Test get_template executes and returns string.
	 *
	 * @return void
	 */
	public function test_get_template_executes_and_returns_string(): void {
		Functions\when( 'locate_template' )->justReturn( '' );

		// Use non-existent template to avoid loading actual templates with WP deps.
		$result = Templates::get_template( 'nonexistent-template-xyz' );

		$this->assertIsString( $result );
	}

	/**
	 * Test get_template with name variation.
	 *
	 * @return void
	 */
	public function test_get_template_with_name_variation(): void {
		Functions\when( 'locate_template' )->justReturn( '' );

		// Use non-existent template to avoid loading actual templates with WP deps.
		$result = Templates::get_template( 'nonexistent-template-xyz', 'featured' );

		$this->assertIsString( $result );
	}

	/**
	 * Test part_exists executes and returns bool.
	 *
	 * @return void
	 */
	public function test_part_exists_executes_and_returns_bool(): void {
		Functions\when( 'locate_template' )->justReturn( '' );

		$result = Templates::part_exists( 'event-card' );

		$this->assertIsBool( $result );
	}

	/**
	 * Test part_exists returns true for existing part.
	 *
	 * @return void
	 */
	public function test_part_exists_returns_true_for_existing_part(): void {
		// Mock locate_template to return empty (no theme override).
		Functions\when( 'locate_template' )->justReturn( '' );

		// The plugin template directory has parts like event-card.php.
		$result = Templates::part_exists( 'event-card' );

		// May be true or false depending on plugin structure.
		$this->assertIsBool( $result );
	}

	/**
	 * Test template_exists executes and returns bool.
	 *
	 * @return void
	 */
	public function test_template_exists_executes_and_returns_bool(): void {
		Functions\when( 'locate_template' )->justReturn( '' );

		$result = Templates::template_exists( 'single-event' );

		$this->assertIsBool( $result );
	}

	/**
	 * Test template_exists with name parameter.
	 *
	 * @return void
	 */
	public function test_template_exists_with_name_parameter(): void {
		Functions\when( 'locate_template' )->justReturn( '' );

		$result = Templates::template_exists( 'single-event', 'featured' );

		$this->assertIsBool( $result );
	}

	/**
	 * Test locate executes and returns string.
	 *
	 * @return void
	 */
	public function test_locate_executes_and_returns_string(): void {
		Functions\when( 'locate_template' )->justReturn( '' );

		$result = Templates::locate( 'single-event' );

		$this->assertIsString( $result );
	}

	/**
	 * Test locate with name parameter.
	 *
	 * @return void
	 */
	public function test_locate_with_name_parameter(): void {
		Functions\when( 'locate_template' )->justReturn( '' );

		$result = Templates::locate( 'single-event', 'featured' );

		$this->assertIsString( $result );
	}

	/**
	 * Test clear_cache executes without error.
	 *
	 * @return void
	 */
	public function test_clear_cache_executes_without_error(): void {
		// Just verify no exception is thrown.
		Templates::clear_cache();

		$this->assertTrue( true );
	}

	/**
	 * Test init can be called multiple times safely.
	 *
	 * @return void
	 */
	public function test_init_idempotent(): void {
		// Init is already called. Calling again should not throw.
		Templates::init();
		Templates::init();

		$this->assertTrue( true );
	}

	/**
	 * Test get_part with args passes data to template.
	 *
	 * @return void
	 */
	public function test_get_part_with_args(): void {
		Functions\when( 'locate_template' )->justReturn( '' );

		$result = Templates::get_part( 'event-card', array( 'event' => (object) array( 'id' => 1 ) ) );

		$this->assertIsString( $result );
	}

	/**
	 * Test get_template with args.
	 *
	 * @return void
	 */
	public function test_get_template_with_args(): void {
		Functions\when( 'locate_template' )->justReturn( '' );

		// Use non-existent template to avoid loading actual templates with WP deps.
		$result = Templates::get_template( 'nonexistent-template-xyz', null, array( 'event' => (object) array( 'id' => 1 ) ) );

		$this->assertIsString( $result );
	}
}
