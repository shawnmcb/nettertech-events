<?php
/**
 * FrontendBranding unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use NetterTechEvents\Frontend\FrontendBranding;

/**
 * Test FrontendBranding branding visibility and badge rendering.
 *
 * Covers should_show_branding(), render_badge(), mark_content_rendered(),
 * and opt-in filter behavior.
 */
class FrontendBrandingTest extends \NetterTechEventsTestCase {

	/**
	 * Reset static state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset static flags via reflection.
		$class = new \ReflectionClass( FrontendBranding::class );

		$content_rendered = $class->getProperty( 'content_rendered' );
		$content_rendered->setValue( null, false );

		// Default: get_option returns empty array (all defaults).
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'plugins_url' )->justReturn( 'http://example.com/plugin/assets/images/nettercap-logo.png' );
		Functions\when( 'plugin_dir_path' )->justReturn( '/tmp/plugin/' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias( function ( string $text ) {
			echo $text;
		} );
		Functions\when( 'esc_html__' )->returnArg();
	}

	// =========================================================================
	// mark_content_rendered / was_content_rendered Tests
	// =========================================================================

	/**
	 * Test was_content_rendered is false before mark_content_rendered.
	 *
	 * @return void
	 */
	public function test_was_content_rendered_false_initially(): void {
		$this->assertFalse( FrontendBranding::was_content_rendered() );
	}

	/**
	 * Test mark_content_rendered sets the flag to true.
	 *
	 * @return void
	 */
	public function test_mark_content_rendered_sets_flag(): void {
		FrontendBranding::mark_content_rendered();

		$this->assertTrue( FrontendBranding::was_content_rendered() );
	}

	// =========================================================================
	// should_show_branding() Tests
	// =========================================================================

	/**
	 * Test should_show_branding returns false by default.
	 *
	 * @return void
	 */
	public function test_should_show_branding_false_by_default(): void {
		$result = FrontendBranding::should_show_branding();

		$this->assertFalse( $result );
	}

	/**
	 * Test should_show_branding returns true when filter explicitly enables it.
	 *
	 * @return void
	 */
	public function test_should_show_branding_true_when_filter_enables(): void {
		Functions\when( 'apply_filters' )->alias( function ( string $tag, $value ) {
			if ( $tag === \NetterTechEvents\Core\Hooks::SHOW_FRONTEND_BRANDING ) {
				return true;
			}
			return $value;
		} );

		$result = FrontendBranding::should_show_branding();

		$this->assertTrue( $result );
	}

	/**
	 * Test should_show_branding returns true when the site owner opts in.
	 *
	 * @return void
	 */
	public function test_should_show_branding_true_when_setting_enabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'show_frontend_branding' => '1' ) );

		$result = FrontendBranding::should_show_branding();

		$this->assertTrue( $result );
	}

	/**
	 * Test the filter can disable branding even when the setting is enabled.
	 *
	 * @return void
	 */
	public function test_should_show_branding_false_when_filter_disables_setting(): void {
		Functions\when( 'get_option' )->justReturn( array( 'show_frontend_branding' => '1' ) );
		Functions\when( 'apply_filters' )->alias( function ( string $tag, $value ) {
			if ( $tag === \NetterTechEvents\Core\Hooks::SHOW_FRONTEND_BRANDING ) {
				return false;
			}
			return $value;
		} );

		$result = FrontendBranding::should_show_branding();

		$this->assertFalse( $result );
	}

	// =========================================================================
	// render_badge() Tests
	// =========================================================================

	/**
	 * Test render_badge returns empty string when branding disabled.
	 *
	 * @return void
	 */
	public function test_render_badge_empty_when_branding_disabled(): void {
		$result = FrontendBranding::render_badge();

		$this->assertSame( '', $result );
	}

	/**
	 * Test render_badge returns HTML containing the developer URL.
	 *
	 * @return void
	 */
	public function test_render_badge_contains_developer_url(): void {
		$this->enable_frontend_branding();

		$result = FrontendBranding::render_badge();

		$this->assertStringContainsString( FrontendBranding::DEVELOPER_URL, $result );
	}

	/**
	 * Test render_badge contains branding wrapper class.
	 *
	 * @return void
	 */
	public function test_render_badge_contains_branding_class(): void {
		$this->enable_frontend_branding();

		$result = FrontendBranding::render_badge();

		$this->assertStringContainsString( 'nte-frontend-branding', $result );
	}

	/**
	 * Test render_badge marks content as rendered.
	 *
	 * @return void
	 */
	public function test_render_badge_marks_content_rendered(): void {
		$this->enable_frontend_branding();
		$this->assertFalse( FrontendBranding::was_content_rendered() );

		FrontendBranding::render_badge();

		$this->assertTrue( FrontendBranding::was_content_rendered() );
	}

	/**
	 * Test render_badge does not emit inline styles.
	 *
	 * Styles are no longer emitted as inline <style> blocks in the HTML;
	 * they live in the public stylesheet.
	 *
	 * @return void
	 */
	public function test_render_badge_has_no_inline_styles(): void {
		$this->enable_frontend_branding();

		$first  = FrontendBranding::render_badge();
		$second = FrontendBranding::render_badge();

		// CSS is in base.css, not inline. No <style> tags should appear.
		$this->assertStringNotContainsString( '<style>', $first );
		$this->assertStringNotContainsString( '<style>', $second );
	}

	/**
	 * Test render_badge contains plugin name text.
	 *
	 * @return void
	 */
	public function test_render_badge_contains_plugin_name(): void {
		$this->enable_frontend_branding();

		$result = FrontendBranding::render_badge();

		$this->assertStringContainsString( FrontendBranding::PLUGIN_NAME, $result );
	}

	// =========================================================================
	// maybe_render_branding() Tests
	// =========================================================================

	/**
	 * Test maybe_render_branding outputs nothing when content not rendered.
	 *
	 * @return void
	 */
	public function test_maybe_render_branding_silent_when_no_content(): void {
		ob_start();
		FrontendBranding::maybe_render_branding();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Test maybe_render_branding renders when content was rendered.
	 *
	 * @return void
	 */
	public function test_maybe_render_branding_outputs_when_content_rendered(): void {
		$this->enable_frontend_branding();

		FrontendBranding::mark_content_rendered();

		ob_start();
		FrontendBranding::maybe_render_branding();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-frontend-branding', $output );
	}

	/**
	 * Enable optional public branding for tests that assert rendered badge markup.
	 *
	 * @return void
	 */
	private function enable_frontend_branding(): void {
		Functions\when( 'apply_filters' )->alias( function ( string $tag, $value ) {
			if ( $tag === \NetterTechEvents\Core\Hooks::SHOW_FRONTEND_BRANDING ) {
				return true;
			}
			return $value;
		} );
	}
}
