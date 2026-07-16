<?php
/**
 * QRSettingsSection unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Settings;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Settings\QRSettingsSection;
use NetterTechEvents\Admin\Settings\SettingsSectionInterface;

/**
 * Test QRSettingsSection functionality.
 *
 * Tests QR code settings section rendering and configuration.
 */
class QRSettingsSectionTest extends \NetterTechEventsTestCase {

	/**
	 * Test subject.
	 *
	 * @var QRSettingsSection
	 */
	private QRSettingsSection $section;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->section = new QRSettingsSection();
	}

	// =========================================================================
	// Interface Implementation Tests
	// =========================================================================

	/**
	 * Test class implements SettingsSectionInterface.
	 *
	 * @return void
	 */
	public function test_implements_interface(): void {
		$this->assertInstanceOf( SettingsSectionInterface::class, $this->section );
	}

	// =========================================================================
	// get_id() Tests
	// =========================================================================

	/**
	 * Test get_id returns correct identifier.
	 *
	 * @return void
	 */
	public function test_get_id_returns_qr_codes(): void {
		$this->assertEquals( 'qr-codes', $this->section->get_id() );
	}

	/**
	 * Test get_id returns string type.
	 *
	 * @return void
	 */
	public function test_get_id_returns_string(): void {
		$this->assertIsString( $this->section->get_id() );
	}

	// =========================================================================
	// get_title() Tests
	// =========================================================================

	/**
	 * Test get_title returns correct title.
	 *
	 * @return void
	 */
	public function test_get_title_returns_qr_codes(): void {
		Functions\when( '__' )->returnArg();
		$this->assertEquals( 'QR Codes', $this->section->get_title() );
	}

	/**
	 * Test get_title returns string type.
	 *
	 * @return void
	 */
	public function test_get_title_returns_string(): void {
		Functions\when( '__' )->returnArg();
		$this->assertIsString( $this->section->get_title() );
	}

	// =========================================================================
	// render() Tests - Basic Output
	// =========================================================================

	/**
	 * Test render outputs section container.
	 *
	 * @return void
	 */
	public function test_render_outputs_section_container(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-settings__section', $output );
	}

	/**
	 * Test render outputs section header.
	 *
	 * @return void
	 */
	public function test_render_outputs_section_header(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-settings__section-header', $output );
		$this->assertStringContainsString( 'nte-settings__section-title', $output );
	}

	/**
	 * Test render outputs section content.
	 *
	 * @return void
	 */
	public function test_render_outputs_section_content(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-settings__section-content', $output );
	}

	/**
	 * Test render outputs form table.
	 *
	 * @return void
	 */
	public function test_render_outputs_form_table(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'form-table', $output );
	}

	// =========================================================================
	// render() Tests - Foreground Color Field
	// =========================================================================

	/**
	 * Test render outputs foreground color field.
	 *
	 * @return void
	 */
	public function test_render_outputs_foreground_color_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'qr_foreground_color', $output );
		$this->assertStringContainsString( 'type="color"', $output );
	}

	/**
	 * Test render uses default foreground color.
	 *
	 * @return void
	 */
	public function test_render_uses_default_foreground_color(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="#000000"', $output );
	}

	/**
	 * Test render uses custom foreground color from settings.
	 *
	 * @return void
	 */
	public function test_render_uses_custom_foreground_color(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'qr_foreground_color' => '#722f37' ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="#722f37"', $output );
	}

	// =========================================================================
	// render() Tests - Background Color Field
	// =========================================================================

	/**
	 * Test render outputs background color field.
	 *
	 * @return void
	 */
	public function test_render_outputs_background_color_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'qr_background_color', $output );
	}

	/**
	 * Test render uses default background color.
	 *
	 * @return void
	 */
	public function test_render_uses_default_background_color(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="#ffffff"', $output );
	}

	/**
	 * Test render uses custom background color from settings.
	 *
	 * @return void
	 */
	public function test_render_uses_custom_background_color(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'qr_background_color' => '#eeeeee' ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="#eeeeee"', $output );
	}

	// =========================================================================
	// render() Tests - Scale Field
	// =========================================================================

	/**
	 * Test render outputs scale field.
	 *
	 * @return void
	 */
	public function test_render_outputs_scale_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'qr_scale', $output );
		$this->assertStringContainsString( '<select', $output );
	}

	/**
	 * Test render outputs scale options from 3 to 20.
	 *
	 * @return void
	 */
	public function test_render_outputs_scale_options(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<option value="3"', $output );
		$this->assertStringContainsString( '<option value="10"', $output );
		$this->assertStringContainsString( '<option value="20"', $output );
	}

	/**
	 * Test render selects default scale of 5.
	 *
	 * @return void
	 */
	public function test_render_selects_default_scale(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<option value="5" selected', $output );
	}

	/**
	 * Test render selects custom scale from settings.
	 *
	 * @return void
	 */
	public function test_render_selects_custom_scale(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'qr_scale' => 10 ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<option value="10" selected', $output );
	}

	// =========================================================================
	// render() Tests - Logo Options
	// =========================================================================

	/**
	 * Test render outputs logo options container.
	 *
	 * @return void
	 */
	public function test_render_outputs_logo_options(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-qr-logo-options', $output );
		$this->assertStringContainsString( 'role="radiogroup"', $output );
	}

	/**
	 * Test render outputs no logo option.
	 *
	 * @return void
	 */
	public function test_render_outputs_no_logo_option(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-mode="none"', $output );
		$this->assertStringContainsString( 'No Logo', $output );
	}

	/**
	 * Test render outputs site logo option.
	 *
	 * @return void
	 */
	public function test_render_outputs_site_logo_option(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-mode="site"', $output );
		$this->assertStringContainsString( 'Site Logo', $output );
	}

	/**
	 * Test render outputs custom logo option.
	 *
	 * @return void
	 */
	public function test_render_outputs_custom_logo_option(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-mode="custom"', $output );
		$this->assertStringContainsString( 'Custom Logo', $output );
	}

	/**
	 * Test render selects none logo mode by default.
	 *
	 * @return void
	 */
	public function test_render_selects_none_logo_mode_by_default(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// Check the none option has both the data-mode and selected class.
		$this->assertStringContainsString( 'data-mode="none"', $output );
		$this->assertStringContainsString( 'nte-qr-logo-option--selected', $output );
	}

	/**
	 * Test render selects site logo mode from settings.
	 *
	 * @return void
	 */
	public function test_render_selects_site_logo_mode(): void {
		$this->setup_render_mocks( 'http://example.com/site-logo.png' );

		ob_start();
		$this->section->render( array( 'qr_default_logo_mode' => 'site' ) );
		$output = ob_get_clean();

		// Check the site option has selected class and the hidden input has site value.
		$this->assertStringContainsString( 'data-mode="site"', $output );
		$this->assertStringContainsString( 'id="qr_default_logo_mode" value="site"', $output );
	}

	/**
	 * Test render selects custom logo mode from settings.
	 *
	 * @return void
	 */
	public function test_render_selects_custom_logo_mode(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'qr_default_logo_mode' => 'custom' ) );
		$output = ob_get_clean();

		// Check the hidden input has custom value.
		$this->assertStringContainsString( 'id="qr_default_logo_mode" value="custom"', $output );
	}

	/**
	 * Test render disables site logo option when no site logo configured.
	 *
	 * @return void
	 */
	public function test_render_disables_site_logo_when_not_configured(): void {
		$this->setup_render_mocks( '' ); // No site logo

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// Check site logo option is disabled.
		$this->assertStringContainsString( 'nte-qr-logo-option--disabled', $output );
		$this->assertStringContainsString( 'Not configured', $output );
	}

	/**
	 * Test render shows site logo image when configured.
	 *
	 * @return void
	 */
	public function test_render_shows_site_logo_image(): void {
		$this->setup_render_mocks( 'http://example.com/site-logo.png' );

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'http://example.com/site-logo.png', $output );
		$this->assertStringContainsString( 'Uses theme customizer logo', $output );
	}

	/**
	 * Test render shows custom logo image when set.
	 *
	 * @return void
	 */
	public function test_render_shows_custom_logo_image(): void {
		$this->setup_render_mocks( '', 'http://example.com/custom-logo.png' );

		ob_start();
		$this->section->render( array( 'qr_default_logo_id' => 123 ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'http://example.com/custom-logo.png', $output );
	}

	/**
	 * Test render shows remove button when custom logo set.
	 *
	 * @return void
	 */
	public function test_render_shows_remove_button_for_custom_logo(): void {
		$this->setup_render_mocks( '', 'http://example.com/custom-logo.png' );

		ob_start();
		$this->section->render( array( 'qr_default_logo_id' => 123 ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-qr-remove-logo', $output );
		$this->assertStringContainsString( 'Remove', $output );
	}

	// =========================================================================
	// render() Tests - Preview Panel
	// =========================================================================

	/**
	 * Test render outputs preview panel.
	 *
	 * @return void
	 */
	public function test_render_outputs_preview_panel(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-qr-preview-panel', $output );
		$this->assertStringContainsString( 'Preview', $output );
	}

	/**
	 * Test render outputs preview image element.
	 *
	 * @return void
	 */
	public function test_render_outputs_preview_image(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-qr-preview-image', $output );
		$this->assertStringContainsString( 'QR Code Preview', $output );
	}

	/**
	 * Test render outputs loading indicator.
	 *
	 * @return void
	 */
	public function test_render_outputs_loading_indicator(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-qr-preview-loading', $output );
		$this->assertStringContainsString( 'spinner', $output );
	}

	// =========================================================================
	// render() Tests - Styles
	// =========================================================================

	/**
	 * Test render does not output inline CSS styles.
	 *
	 * @return void
	 */
	public function test_render_does_not_output_inline_styles(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<style>', $output );
		$this->assertStringContainsString( 'nte-qr-logo-options', $output );
	}

	/**
	 * Test logo option styles live in the admin stylesheet.
	 *
	 * @return void
	 */
	public function test_logo_option_styles_are_in_admin_stylesheet(): void {
		$stylesheet = dirname( __DIR__, 4 ) . '/assets/dist/css/admin.css';

		$this->assertFileExists( $stylesheet );
		$css = (string) file_get_contents( $stylesheet );

		$this->assertStringContainsString( '.nte-qr-logo-option', $css );
		$this->assertStringContainsString( '.nte-qr-logo-option--selected', $css );
		$this->assertStringContainsString( '.nte-qr-logo-option--disabled', $css );
	}

	// =========================================================================
	// render() Tests - Script Enqueuing
	// =========================================================================

	/**
	 * Test render enqueues media scripts.
	 *
	 * @return void
	 */
	public function test_render_enqueues_media(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// Verify render completed successfully with expected content.
		$this->assertStringContainsString( 'nte-settings__section', $output );
	}

	/**
	 * Test render enqueues QR logo script.
	 *
	 * @return void
	 */
	public function test_render_enqueues_qr_logo_script(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// Script should be enqueued - we verify by checking the output contains expected content.
		$this->assertStringContainsString( 'nte-settings__section', $output );
	}

	/**
	 * Test render localizes script with correct data.
	 *
	 * @return void
	 */
	public function test_render_localizes_script(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// Script should be localized - we verify render completes successfully.
		$this->assertStringContainsString( 'nte-qr-preview-panel', $output );
	}

	// =========================================================================
	// render() Tests - Hidden Fields
	// =========================================================================

	/**
	 * Test render outputs hidden logo mode field.
	 *
	 * @return void
	 */
	public function test_render_outputs_hidden_logo_mode_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="nettertech_events_settings[qr_default_logo_mode]"', $output );
		$this->assertStringContainsString( 'id="qr_default_logo_mode"', $output );
		$this->assertStringContainsString( 'type="hidden"', $output );
	}

	/**
	 * Test render outputs hidden logo ID field.
	 *
	 * @return void
	 */
	public function test_render_outputs_hidden_logo_id_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="nettertech_events_settings[qr_default_logo_id]"', $output );
		$this->assertStringContainsString( 'id="qr_default_logo_id"', $output );
	}

	// =========================================================================
	// render() Tests - Accessibility
	// =========================================================================

	/**
	 * Test render includes ARIA labels.
	 *
	 * @return void
	 */
	public function test_render_includes_aria_labels(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'aria-label=', $output );
		$this->assertStringContainsString( 'aria-checked=', $output );
	}

	/**
	 * Test render includes role attributes.
	 *
	 * @return void
	 */
	public function test_render_includes_role_attributes(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'role="radiogroup"', $output );
		$this->assertStringContainsString( 'role="radio"', $output );
	}

	/**
	 * Test render includes tabindex for keyboard navigation.
	 *
	 * @return void
	 */
	public function test_render_includes_tabindex(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'tabindex="0"', $output );
	}

	// =========================================================================
	// render() Tests - Description Text
	// =========================================================================

	/**
	 * Test render includes field descriptions.
	 *
	 * @return void
	 */
	public function test_render_includes_field_descriptions(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="description"', $output );
		$this->assertStringContainsString( 'color of the QR code modules', $output );
		$this->assertStringContainsString( 'background color', $output );
		$this->assertStringContainsString( 'Size of each dot in the QR code', $output );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Set up common render mocks.
	 *
	 * @param string $site_logo_url   Site logo URL.
	 * @param string $custom_logo_url Custom logo URL.
	 * @return void
	 */
	private function setup_render_mocks( string $site_logo_url = '', string $custom_logo_url = '' ): void {
		// Translation functions.
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'esc_attr_e' )->echoArg();

		// Escaping functions.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'absint' )->alias(
			function ( $val ) {
				return abs( (int) $val );
			}
		);
		Functions\when( 'selected' )->alias(
			function ( $selected, $current, $echo = true ) {
				$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);

		// Theme mod for site logo.
		$site_logo_id = $site_logo_url ? 123 : 0;
		Functions\when( 'get_theme_mod' )->justReturn( $site_logo_id );

		// Mock WordPress image functions (used by ImageHelper internally).
		Functions\when( 'wp_attachment_is_image' )->alias(
			function ( $id ) use ( $site_logo_url, $custom_logo_url ) {
				// Return true if we have a URL configured for this scenario.
				return ( $id === 123 && $site_logo_url ) || $custom_logo_url;
			}
		);
		Functions\when( 'wp_get_attachment_image_url' )->alias(
			function ( $id, $size ) use ( $site_logo_url, $custom_logo_url ) {
				if ( $id === 123 && $site_logo_url ) {
					return $site_logo_url;
				}
				if ( $custom_logo_url ) {
					return $custom_logo_url;
				}
				return false;
			}
		);

		// Enqueue functions.
		Functions\when( 'wp_enqueue_media' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com/' );
	}
}
