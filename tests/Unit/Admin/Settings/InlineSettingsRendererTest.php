<?php
/**
 * InlineSettingsRenderer unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Settings;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Settings\InlineSettingsRenderer;
use NetterTechEvents\Services\LayoutService;

/**
 * Test InlineSettingsRenderer class.
 *
 * Covers the inline render methods that do not pull external templates:
 * - render_default_venue_section
 * - render_url_settings_section
 * - render_event_layout_section
 * - render_features_section
 * - render_tickets_capacity_section
 * - render_checkin_section
 * - render_layout_editor
 *
 * render_general_settings_section pulls a template file and is covered by
 * GeneralSettingsSectionSnapshotTest.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\Settings\InlineSettingsRenderer
 */
class InlineSettingsRendererTest extends \NetterTechEventsTestCase {

	/**
	 * Layout service mock.
	 *
	 * @var LayoutService|Mockery\MockInterface
	 */
	private $layout_service;

	/**
	 * Renderer under test.
	 *
	 * @var InlineSettingsRenderer
	 */
	private InlineSettingsRenderer $renderer;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr_e' )->echoArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'checked' )->alias(
			static function ( $a, $b = true, $echo = true ) {
				$out = (string) $a === (string) $b ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);
		Functions\when( 'selected' )->alias(
			static function ( $a, $b = true, $echo = true ) {
				$out = (string) $a === (string) $b ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_pages' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'http://example.test/sample/' );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'http://example.test' . $p );
		Functions\when( 'site_url' )->alias( static fn( $p = '' ) => 'http://example.test' . $p );

		$this->layout_service = Mockery::mock( LayoutService::class );
		$this->renderer       = new InlineSettingsRenderer( $this->layout_service );
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Test constructor.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$this->assertInstanceOf( InlineSettingsRenderer::class, $this->renderer );
	}

	/**
	 * Test render_default_venue_section emits checkbox and venue fields.
	 *
	 * @return void
	 */
	public function test_render_default_venue_section(): void {
		ob_start();
		$this->renderer->render_default_venue_section( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-settings__section', $output );
		$this->assertStringContainsString( 'default_venue_enabled', $output );
		$this->assertStringContainsString( 'default_venue_name', $output );
	}

	/**
	 * Test render_default_venue_section reflects checked state.
	 *
	 * @return void
	 */
	public function test_render_default_venue_section_checked_when_enabled(): void {
		ob_start();
		$this->renderer->render_default_venue_section( array( 'default_venue_enabled' => true ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'checked="checked"', $output );
	}

	/**
	 * Test render_url_settings_section emits URL configuration inputs.
	 *
	 * @return void
	 */
	public function test_render_url_settings_section(): void {
		ob_start();
		$this->renderer->render_url_settings_section( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'events_base_path', $output );
		$this->assertStringContainsString( 'spaces_base_path', $output );
	}

	/**
	 * Test render_url_settings_section respects saved paths.
	 *
	 * @return void
	 */
	public function test_render_url_settings_section_uses_saved_paths(): void {
		ob_start();
		$this->renderer->render_url_settings_section(
			array(
				'events_base_path' => 'happenings',
				'spaces_base_path' => 'venues',
			)
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'happenings', $output );
		$this->assertStringContainsString( 'venues', $output );
	}

	/**
	 * Test render_event_layout_section delegates to layout editor.
	 *
	 * @return void
	 */
	public function test_render_event_layout_section(): void {
		$this->layout_service->shouldReceive( 'get_components' )->andReturn( array() );
		$this->layout_service->shouldReceive( 'get_layout' )->andReturn(
			array(
				'order'      => array(),
				'visibility' => array(),
			)
		);
		$this->layout_service->shouldReceive( 'get_global_default' )->andReturn( null );
		$this->layout_service->shouldReceive( 'get_hardcoded_default' )->andReturn(
			array(
				'order'      => array(),
				'visibility' => array(),
			)
		);
		$this->layout_service->shouldReceive( 'get_visible_components' )->andReturn( array() );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );

		ob_start();
		$this->renderer->render_event_layout_section( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-settings__section', $output );
	}

	/**
	 * Test render_features_section.
	 *
	 * @return void
	 */
	public function test_render_features_section(): void {
		ob_start();
		$this->renderer->render_features_section( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-settings__section', $output );
		$this->assertStringContainsString( 'enable_rsvp', $output );
		$this->assertStringContainsString( 'enable_tickets', $output );
	}

	/**
	 * Test render_features_section reflects feature toggles.
	 *
	 * @return void
	 */
	public function test_render_features_section_with_features_on(): void {
		ob_start();
		$this->renderer->render_features_section(
			array(
				'enable_rsvp'    => true,
				'enable_tickets' => true,
			)
		);
		$output = ob_get_clean();

		// At least two checkboxes should be checked.
		$this->assertGreaterThanOrEqual(
			2,
			substr_count( $output, 'checked="checked"' ),
			'Expected at least RSVP + tickets checkboxes both checked.'
		);
	}

	/**
	 * Test render_tickets_capacity_section.
	 *
	 * @return void
	 */
	public function test_render_tickets_capacity_section(): void {
		ob_start();
		$this->renderer->render_tickets_capacity_section( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-settings__section', $output );
	}

	/**
	 * Test render_checkin_section.
	 *
	 * @return void
	 */
	public function test_render_checkin_section(): void {
		ob_start();
		$this->renderer->render_checkin_section( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-settings__section', $output );
	}

	/**
	 * Test render_layout_editor.
	 *
	 * @return void
	 */
	public function test_render_layout_editor(): void {
		$this->layout_service->shouldReceive( 'get_components' )->andReturn( array() );
		$this->layout_service->shouldReceive( 'get_layout' )->andReturn(
			array(
				'order'      => array(),
				'visibility' => array(),
			)
		);
		$this->layout_service->shouldReceive( 'get_global_default' )->andReturn( null );
		$this->layout_service->shouldReceive( 'get_hardcoded_default' )->andReturn(
			array(
				'order'      => array(),
				'visibility' => array(),
			)
		);
		$this->layout_service->shouldReceive( 'get_visible_components' )->andReturn( array() );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );

		ob_start();
		$this->renderer->render_layout_editor();
		$output = ob_get_clean();

		// The editor outputs something — assert it produced HTML.
		$this->assertNotEmpty( $output );
	}
}
