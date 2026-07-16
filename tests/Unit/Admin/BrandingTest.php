<?php
/**
 * Branding unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Branding;

/**
 * Test Branding functionality.
 *
 * Tests constants and branding output methods.
 */
class BrandingTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test PLUGIN_NAME constant is defined.
	 *
	 * @return void
	 */
	public function test_plugin_name_constant_exists(): void {
		$this->assertEquals( 'NetterTech Events', Branding::PLUGIN_NAME );
	}

	/**
	 * Test DEVELOPER_NAME constant is defined.
	 *
	 * @return void
	 */
	public function test_developer_name_constant_exists(): void {
		$this->assertEquals( 'NetterTech', Branding::DEVELOPER_NAME );
	}

	/**
	 * Test DEVELOPER_URL constant is defined.
	 *
	 * @return void
	 */
	public function test_developer_url_constant_exists(): void {
		$this->assertEquals( 'https://nettertech.com', Branding::DEVELOPER_URL );
	}

	/**
	 * Test LOGO_FILENAME constant is defined.
	 *
	 * @return void
	 */
	public function test_logo_filename_constant_exists(): void {
		$this->assertEquals( 'nettercap-logo.png', Branding::LOGO_FILENAME );
	}

	// =========================================================================
	// get_logo_url() Tests
	// =========================================================================

	/**
	 * Test get_logo_url returns URL string.
	 *
	 * @return void
	 */
	public function test_get_logo_url_returns_url(): void {
		Functions\when( 'plugins_url' )->justReturn( 'http://example.com/wp-content/plugins/nettertech-events/assets/images/nettercap-logo.png' );

		$url = Branding::get_logo_url();

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'nettercap-logo.png', $url );
	}

	/**
	 * Test get_logo_url calls plugins_url with correct arguments.
	 *
	 * @return void
	 */
	public function test_get_logo_url_calls_plugins_url(): void {
		$plugins_url_called = false;
		$passed_path        = '';

		Functions\when( 'plugins_url' )->alias(
			function ( $path, $plugin ) use ( &$plugins_url_called, &$passed_path ) {
				$plugins_url_called = true;
				$passed_path        = $path;
				return 'http://example.com/' . $path;
			}
		);

		Branding::get_logo_url();

		$this->assertTrue( $plugins_url_called );
		$this->assertEquals( 'assets/images/' . Branding::LOGO_FILENAME, $passed_path );
	}

	// =========================================================================
	// render_header() Tests
	// =========================================================================

	/**
	 * Test render_header outputs HTML.
	 *
	 * @return void
	 */
	public function test_render_header_outputs_html(): void {
		Functions\when( 'plugins_url' )->justReturn( 'http://example.com/logo.png' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );

		ob_start();
		Branding::render_header();
		$output = ob_get_clean();

		$this->assertIsString( $output );
		$this->assertNotEmpty( $output );
	}

	/**
	 * Test render_header contains branding class.
	 *
	 * @return void
	 */
	public function test_render_header_contains_branding_class(): void {
		Functions\when( 'plugins_url' )->justReturn( 'http://example.com/logo.png' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );

		ob_start();
		Branding::render_header();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-branding-header', $output );
	}

	/**
	 * Test render_header contains plugin name.
	 *
	 * @return void
	 */
	public function test_render_header_contains_plugin_name(): void {
		Functions\when( 'plugins_url' )->justReturn( 'http://example.com/logo.png' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );

		ob_start();
		Branding::render_header();
		$output = ob_get_clean();

		$this->assertStringContainsString( Branding::PLUGIN_NAME, $output );
	}

	/**
	 * Test render_header contains developer URL.
	 *
	 * @return void
	 */
	public function test_render_header_contains_developer_url(): void {
		Functions\when( 'plugins_url' )->justReturn( 'http://example.com/logo.png' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );

		ob_start();
		Branding::render_header();
		$output = ob_get_clean();

		$this->assertStringContainsString( Branding::DEVELOPER_URL, $output );
	}

	/**
	 * Test render_header contains developer name.
	 *
	 * @return void
	 */
	public function test_render_header_contains_developer_name(): void {
		Functions\when( 'plugins_url' )->justReturn( 'http://example.com/logo.png' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );

		ob_start();
		Branding::render_header();
		$output = ob_get_clean();

		$this->assertStringContainsString( Branding::DEVELOPER_NAME, $output );
	}

	/**
	 * Test render_header contains logo image tag.
	 *
	 * @return void
	 */
	public function test_render_header_contains_logo_img(): void {
		Functions\when( 'plugins_url' )->justReturn( 'http://example.com/logo.png' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );

		ob_start();
		Branding::render_header();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<img', $output );
		$this->assertStringContainsString( 'http://example.com/logo.png', $output );
	}

	// =========================================================================
	// render_footer() Tests
	// =========================================================================

	/**
	 * Test render_footer outputs HTML.
	 *
	 * @return void
	 */
	public function test_render_footer_outputs_html(): void {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );

		ob_start();
		Branding::render_footer();
		$output = ob_get_clean();

		$this->assertIsString( $output );
		$this->assertNotEmpty( $output );
	}

	/**
	 * Test render_footer contains branding class.
	 *
	 * @return void
	 */
	public function test_render_footer_contains_branding_class(): void {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );

		ob_start();
		Branding::render_footer();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-branding-footer', $output );
	}

	/**
	 * Test render_footer contains plugin name.
	 *
	 * @return void
	 */
	public function test_render_footer_contains_plugin_name(): void {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );

		ob_start();
		Branding::render_footer();
		$output = ob_get_clean();

		$this->assertStringContainsString( Branding::PLUGIN_NAME, $output );
	}

	/**
	 * Test render_footer contains developer URL.
	 *
	 * @return void
	 */
	public function test_render_footer_contains_developer_url(): void {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );

		ob_start();
		Branding::render_footer();
		$output = ob_get_clean();

		$this->assertStringContainsString( Branding::DEVELOPER_URL, $output );
	}

	/**
	 * Test render_footer contains developer name.
	 *
	 * @return void
	 */
	public function test_render_footer_contains_developer_name(): void {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );

		ob_start();
		Branding::render_footer();
		$output = ob_get_clean();

		$this->assertStringContainsString( Branding::DEVELOPER_NAME, $output );
	}

	// =========================================================================
	// Static Method Tests
	// =========================================================================

	/**
	 * Test all methods are static.
	 *
	 * @return void
	 */
	public function test_all_methods_are_static(): void {
		$reflection = new \ReflectionClass( Branding::class );
		$methods    = $reflection->getMethods( \ReflectionMethod::IS_PUBLIC );

		foreach ( $methods as $method ) {
			$this->assertTrue(
				$method->isStatic(),
				"Method {$method->getName()} should be static"
			);
		}
	}
}
