<?php
/**
 * TemplateResolver unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\TemplateLoader
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\TemplateLoader;

use Brain\Monkey\Functions;
use NetterTechEvents\TemplateLoader\TemplateLoaderConfig;
use NetterTechEvents\TemplateLoader\TemplateResolver;

/**
 * Test TemplateResolver functionality.
 */
class TemplateResolverTest extends \NetterTechEventsTestCase {

	/**
	 * Test configuration.
	 *
	 * @var TemplateLoaderConfig
	 */
	private TemplateLoaderConfig $config;

	/**
	 * Temporary directory for test templates.
	 *
	 * @var string
	 */
	private string $temp_dir;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Create temp directories for testing.
		$this->temp_dir = sys_get_temp_dir() . '/nte-template-resolver-test-' . uniqid();
		mkdir( $this->temp_dir . '/plugin/templates', 0755, true );
		mkdir( $this->temp_dir . '/theme/nettertech-events', 0755, true );
		mkdir( $this->temp_dir . '/child-theme/nettertech-events', 0755, true );

		$this->config = new TemplateLoaderConfig(
			'nettertech-events',
			$this->temp_dir . '/plugin',
			'templates'
		);

		// Mock WordPress functions.
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'trailingslashit' )->alias(
			function ( $string ) {
				return rtrim( $string, '/\\' ) . '/';
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// Clean up temp directories.
		$this->remove_directory( $this->temp_dir );
		parent::tearDown();
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function remove_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			if ( is_dir( $path ) ) {
				$this->remove_directory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test TemplateResolver can be instantiated.
	 *
	 * @return void
	 */
	public function test_resolver_can_be_instantiated(): void {
		$resolver = new TemplateResolver( $this->config );

		$this->assertInstanceOf( TemplateResolver::class, $resolver );
	}

	// =========================================================================
	// resolve() Tests
	// =========================================================================

	/**
	 * Test resolve returns null for empty templates array.
	 *
	 * @return void
	 */
	public function test_resolve_returns_null_for_empty_templates(): void {
		$resolver = new TemplateResolver( $this->config );

		$result = $resolver->resolve( array() );

		$this->assertNull( $result );
	}

	/**
	 * Test resolve returns null for array of empty strings.
	 *
	 * @return void
	 */
	public function test_resolve_returns_null_for_empty_strings(): void {
		$resolver = new TemplateResolver( $this->config );

		$result = $resolver->resolve( array( '', '' ) );

		$this->assertNull( $result );
	}

	/**
	 * Test resolve finds template in plugin directory.
	 *
	 * @return void
	 */
	public function test_resolve_finds_plugin_template(): void {
		// Create a template file in the plugin templates directory.
		$template_path = $this->temp_dir . '/plugin/templates/event-card.php';
		file_put_contents( $template_path, '<?php // Event card template' );

		// Mock theme directory functions to return non-existent paths.
		Functions\when( 'get_template_directory' )->justReturn( $this->temp_dir . '/theme' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( $this->temp_dir . '/theme' );

		$resolver = new TemplateResolver( $this->config );
		$result   = $resolver->resolve( array( 'event-card.php' ) );

		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'event-card.php', $result );
	}

	/**
	 * Test resolve prefers theme template over plugin template.
	 *
	 * @return void
	 */
	public function test_resolve_prefers_theme_over_plugin(): void {
		// Create template in both locations.
		file_put_contents(
			$this->temp_dir . '/plugin/templates/event-card.php',
			'<?php // Plugin version'
		);
		file_put_contents(
			$this->temp_dir . '/theme/nettertech-events/event-card.php',
			'<?php // Theme version'
		);

		// Mock theme directory functions.
		Functions\when( 'get_template_directory' )->justReturn( $this->temp_dir . '/theme' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( $this->temp_dir . '/theme' );

		$resolver = new TemplateResolver( $this->config );
		$result   = $resolver->resolve( array( 'event-card.php' ) );

		$this->assertNotNull( $result );
		$this->assertStringContainsString( '/theme/', $result );
		$this->assertStringNotContainsString( '/plugin/', $result );
	}

	/**
	 * Test resolve prefers child theme over parent theme.
	 *
	 * @return void
	 */
	public function test_resolve_prefers_child_theme_over_parent(): void {
		// Create template in both theme locations.
		file_put_contents(
			$this->temp_dir . '/theme/nettertech-events/event-card.php',
			'<?php // Parent theme version'
		);
		file_put_contents(
			$this->temp_dir . '/child-theme/nettertech-events/event-card.php',
			'<?php // Child theme version'
		);

		// Mock theme directory functions (child theme active).
		Functions\when( 'get_template_directory' )->justReturn( $this->temp_dir . '/theme' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( $this->temp_dir . '/child-theme' );

		$resolver = new TemplateResolver( $this->config );
		$result   = $resolver->resolve( array( 'event-card.php' ) );

		$this->assertNotNull( $result );
		$this->assertStringContainsString( '/child-theme/', $result );
	}

	/**
	 * Test resolve returns null when template not found.
	 *
	 * @return void
	 */
	public function test_resolve_returns_null_when_not_found(): void {
		Functions\when( 'get_template_directory' )->justReturn( $this->temp_dir . '/theme' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( $this->temp_dir . '/theme' );

		$resolver = new TemplateResolver( $this->config );
		$result   = $resolver->resolve( array( 'nonexistent-template.php' ) );

		$this->assertNull( $result );
	}

	/**
	 * Test resolve uses cache on second call.
	 *
	 * @return void
	 */
	public function test_resolve_caches_results(): void {
		file_put_contents(
			$this->temp_dir . '/plugin/templates/cached-template.php',
			'<?php // Cached template'
		);

		Functions\when( 'get_template_directory' )->justReturn( $this->temp_dir . '/theme' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( $this->temp_dir . '/theme' );

		$resolver = new TemplateResolver( $this->config );

		// First call.
		$result1 = $resolver->resolve( array( 'cached-template.php' ) );

		// Delete the file (but cache should still return the path).
		unlink( $this->temp_dir . '/plugin/templates/cached-template.php' );

		// Second call should return cached result.
		$result2 = $resolver->resolve( array( 'cached-template.php' ) );

		$this->assertEquals( $result1, $result2 );
	}

	/**
	 * Test resolve tries multiple template candidates in order.
	 *
	 * @return void
	 */
	public function test_resolve_tries_candidates_in_order(): void {
		// Only create the second candidate.
		file_put_contents(
			$this->temp_dir . '/plugin/templates/event-fallback.php',
			'<?php // Fallback'
		);

		Functions\when( 'get_template_directory' )->justReturn( $this->temp_dir . '/theme' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( $this->temp_dir . '/theme' );

		$resolver = new TemplateResolver( $this->config );
		$result   = $resolver->resolve( array( 'event-specific.php', 'event-fallback.php' ) );

		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'event-fallback.php', $result );
	}

	// =========================================================================
	// get_template_paths() Tests
	// =========================================================================

	/**
	 * Test get_template_paths returns correct paths.
	 *
	 * @return void
	 */
	public function test_get_template_paths_returns_plugin_path(): void {
		Functions\when( 'get_template_directory' )->justReturn( $this->temp_dir . '/theme' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( $this->temp_dir . '/theme' );

		$resolver = new TemplateResolver( $this->config );
		$paths    = $resolver->get_template_paths();

		// Should contain plugin templates path.
		$plugin_path_found = false;
		foreach ( $paths as $path ) {
			if ( strpos( $path, '/plugin/templates' ) !== false ) {
				$plugin_path_found = true;
				break;
			}
		}

		$this->assertTrue( $plugin_path_found, 'Plugin templates path should be in paths array' );
	}

	/**
	 * Test get_template_paths includes child theme when active.
	 *
	 * @return void
	 */
	public function test_get_template_paths_includes_child_theme(): void {
		Functions\when( 'get_template_directory' )->justReturn( $this->temp_dir . '/theme' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( $this->temp_dir . '/child-theme' );

		$resolver = new TemplateResolver( $this->config );
		$paths    = $resolver->get_template_paths();

		// Should contain child theme path.
		$child_path_found = false;
		foreach ( $paths as $path ) {
			if ( strpos( $path, '/child-theme/' ) !== false ) {
				$child_path_found = true;
				break;
			}
		}

		$this->assertTrue( $child_path_found, 'Child theme path should be in paths array when active' );
	}

	// =========================================================================
	// clear_cache() Tests
	// =========================================================================

	/**
	 * Test clear_cache clears the cache.
	 *
	 * @return void
	 */
	public function test_clear_cache_clears_cached_results(): void {
		file_put_contents(
			$this->temp_dir . '/plugin/templates/clearable.php',
			'<?php // Original'
		);

		Functions\when( 'get_template_directory' )->justReturn( $this->temp_dir . '/theme' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( $this->temp_dir . '/theme' );

		$resolver = new TemplateResolver( $this->config );

		// First call populates cache.
		$result1 = $resolver->resolve( array( 'clearable.php' ) );
		$this->assertNotNull( $result1 );

		// Delete the file.
		unlink( $this->temp_dir . '/plugin/templates/clearable.php' );

		// Clear cache.
		$resolver->clear_cache();

		// Now resolve should return null since file doesn't exist.
		$result2 = $resolver->resolve( array( 'clearable.php' ) );
		$this->assertNull( $result2 );
	}

	// =========================================================================
	// clear_all_caches() Tests
	// =========================================================================

	/**
	 * Test clear_all_caches clears all resolver instances.
	 *
	 * @return void
	 */
	public function test_clear_all_caches_clears_all_instances(): void {
		Functions\when( 'get_template_directory' )->justReturn( $this->temp_dir . '/theme' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( $this->temp_dir . '/theme' );

		// Create a template file.
		file_put_contents(
			$this->temp_dir . '/plugin/templates/all-cache-test.php',
			'<?php // Test'
		);

		// Create two resolver instances.
		$resolver1 = new TemplateResolver( $this->config );
		$resolver2 = new TemplateResolver( $this->config );

		// Populate caches.
		$resolver1->resolve( array( 'all-cache-test.php' ) );
		$resolver2->resolve( array( 'all-cache-test.php' ) );

		// Delete the file.
		unlink( $this->temp_dir . '/plugin/templates/all-cache-test.php' );

		// Clear all caches.
		TemplateResolver::clear_all_caches();

		// Both should now return null.
		$this->assertNull( $resolver1->resolve( array( 'all-cache-test.php' ) ) );
		$this->assertNull( $resolver2->resolve( array( 'all-cache-test.php' ) ) );
	}
}
