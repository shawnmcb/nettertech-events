<?php
/**
 * TemplateLoader unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\TemplateLoader
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\TemplateLoader;

use NetterTechEvents\TemplateLoader\TemplateLoader;
use NetterTechEvents\TemplateLoader\TemplateLoaderConfig;
use NetterTechEvents\TemplateLoader\Contracts\TemplateResolverInterface;
use Brain\Monkey\Functions;

/**
 * Test TemplateLoader functionality.
 */
class TemplateLoaderTest extends \NetterTechEventsTestCase {

	/**
	 * TemplateLoader instance.
	 *
	 * @var TemplateLoader
	 */
	private TemplateLoader $loader;

	/**
	 * Config instance.
	 *
	 * @var TemplateLoaderConfig
	 */
	private TemplateLoaderConfig $config;

	/**
	 * Mock resolver.
	 *
	 * @var TemplateResolverInterface|\Mockery\MockInterface
	 */
	private $resolver_mock;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->config = new TemplateLoaderConfig(
			theme_template_directory: 'nettertech-events',
			plugin_directory: '/path/to/plugin',
			plugin_template_directory: 'templates'
		);

		$this->resolver_mock = \Mockery::mock( TemplateResolverInterface::class );
		$this->loader        = new TemplateLoader( $this->config, $this->resolver_mock );
	}

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test TemplateLoader class exists.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( TemplateLoader::class ) );
	}

	/**
	 * Test TemplateLoaderConfig class exists.
	 *
	 * @return void
	 */
	public function test_config_class_exists(): void {
		$this->assertTrue( class_exists( TemplateLoaderConfig::class ) );
	}

	/**
	 * Test can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$this->assertInstanceOf( TemplateLoader::class, $this->loader );
	}

	/**
	 * Test required methods exist.
	 *
	 * @return void
	 */
	public function test_required_methods_exist(): void {
		$methods = array(
			'get_template_part',
			'locate_template',
			'template_exists',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( TemplateLoader::class, $method ),
				"Method {$method} should exist"
			);
		}
	}

	// =========================================================================
	// get_template_part Tests
	// =========================================================================

	/**
	 * Test get_template_part returns template path.
	 *
	 * @return void
	 */
	public function test_get_template_part_returns_template_path(): void {
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias( fn( $hook, $data ) => $data );

		$this->resolver_mock->shouldReceive( 'resolve' )
			->once()
			->with( [ 'event-single.php', 'event.php' ] )
			->andReturn( '/theme/nettertech-events/event-single.php' );

		$result = $this->loader->get_template_part( 'event', 'single', [], false );

		$this->assertSame( '/theme/nettertech-events/event-single.php', $result );
	}

	/**
	 * Test get_template_part returns empty string when template not found.
	 *
	 * @return void
	 */
	public function test_get_template_part_returns_empty_string_when_not_found(): void {
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias( fn( $hook, $data ) => $data );

		$this->resolver_mock->shouldReceive( 'resolve' )
			->once()
			->andReturn( null );

		$result = $this->loader->get_template_part( 'nonexistent', null, [], false );

		$this->assertSame( '', $result );
	}

	/**
	 * Test get_template_part loads template when load parameter is true.
	 *
	 * @return void
	 */
	public function test_get_template_part_loads_template_when_load_true(): void {
		$template_path = __DIR__ . '/../../Fixtures/test-template.php';

		// Create fixture template file.
		if ( ! is_dir( __DIR__ . '/../../Fixtures' ) ) {
			mkdir( __DIR__ . '/../../Fixtures', 0755, true );
		}
		file_put_contents( $template_path, '<?php echo "Template loaded";' );

		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias( fn( $hook, $data ) => $data );

		$this->resolver_mock->shouldReceive( 'resolve' )
			->once()
			->andReturn( $template_path );

		ob_start();
		$this->loader->get_template_part( 'test', null, [], true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Template loaded', $output );

		// Clean up.
		if ( file_exists( $template_path ) ) {
			unlink( $template_path );
		}
	}

	// =========================================================================
	// locate_template Tests
	// =========================================================================

	/**
	 * Test locate_template returns resolved path.
	 *
	 * @return void
	 */
	public function test_locate_template_returns_resolved_path(): void {
		$this->resolver_mock->shouldReceive( 'resolve' )
			->once()
			->with( [ 'template.php' ] )
			->andReturn( '/path/to/template.php' );

		$result = $this->loader->locate_template( 'template.php' );

		$this->assertSame( '/path/to/template.php', $result );
	}

	/**
	 * Test locate_template returns empty string when not found.
	 *
	 * @return void
	 */
	public function test_locate_template_returns_empty_when_not_found(): void {
		$this->resolver_mock->shouldReceive( 'resolve' )
			->once()
			->andReturn( null );

		$result = $this->loader->locate_template( 'missing.php' );

		$this->assertSame( '', $result );
	}

	/**
	 * Test locate_template accepts array of templates.
	 *
	 * @return void
	 */
	public function test_locate_template_accepts_array(): void {
		$this->resolver_mock->shouldReceive( 'resolve' )
			->once()
			->with( [ 'template-1.php', 'template-2.php' ] )
			->andReturn( '/path/to/template-1.php' );

		$result = $this->loader->locate_template( [ 'template-1.php', 'template-2.php' ] );

		$this->assertSame( '/path/to/template-1.php', $result );
	}

	// =========================================================================
	// template_exists Tests
	// =========================================================================

	/**
	 * Test template_exists returns true when template found.
	 *
	 * @return void
	 */
	public function test_template_exists_returns_true_when_found(): void {
		Functions\when( 'apply_filters' )->alias( fn( $hook, $data ) => $data );

		$this->resolver_mock->shouldReceive( 'resolve' )
			->once()
			->andReturn( '/path/to/template.php' );

		$result = $this->loader->template_exists( 'event' );

		$this->assertTrue( $result );
	}

	/**
	 * Test template_exists returns false when template not found.
	 *
	 * @return void
	 */
	public function test_template_exists_returns_false_when_not_found(): void {
		Functions\when( 'apply_filters' )->alias( fn( $hook, $data ) => $data );

		$this->resolver_mock->shouldReceive( 'resolve' )
			->once()
			->andReturn( null );

		$result = $this->loader->template_exists( 'missing' );

		$this->assertFalse( $result );
	}

	/**
	 * Test template_exists checks specific template variation.
	 *
	 * @return void
	 */
	public function test_template_exists_checks_variation(): void {
		Functions\when( 'apply_filters' )->alias( fn( $hook, $data ) => $data );

		$this->resolver_mock->shouldReceive( 'resolve' )
			->once()
			->with( [ 'event-single.php', 'event.php' ] )
			->andReturn( '/path/to/event-single.php' );

		$result = $this->loader->template_exists( 'event', 'single' );

		$this->assertTrue( $result );
	}
}
