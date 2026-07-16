<?php
/**
 * QRGeneratorPage unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\QRGeneratorPage;

/**
 * Test QRGeneratorPage functionality.
 *
 * Tests QR code generator admin page.
 */
class QRGeneratorPageTest extends \NetterTechEventsTestCase {

	/**
	 * Tracks enqueued styles.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $enqueued_styles = array();

	/**
	 * Tracks enqueued scripts.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $enqueued_scripts = array();

	/**
	 * Tracks localized script data.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $localized_scripts = array();

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset tracking arrays.
		$this->enqueued_styles    = array();
		$this->enqueued_scripts   = array();
		$this->localized_scripts  = array();

		// Set up common WordPress function mocks.
		$this->mock_common_wp_functions();
	}

	/**
	 * Mock common WordPress functions.
	 *
	 * @return void
	 */
	private function mock_common_wp_functions(): void {
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_enqueue_media' )->justReturn( null );
		Functions\when( 'get_theme_mod' )->justReturn( '' );
		Functions\when( 'checked' )->alias(
			function ( $checked, $current = true, $echo = true ) {
				$result = $checked == $current ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'home_url' )->justReturn( 'http://example.com/' );
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce_123' );

		// Mock asset enqueueing functions to track calls.
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
				$this->enqueued_styles[ $handle ] = array(
					'src'   => $src,
					'deps'  => $deps,
					'ver'   => $ver,
					'media' => $media,
				);
			}
		);

		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
				$this->enqueued_scripts[ $handle ] = array(
					'src'       => $src,
					'deps'      => $deps,
					'ver'       => $ver,
					'in_footer' => $in_footer,
				);
			}
		);

		Functions\when( 'wp_localize_script' )->alias(
			function ( $handle, $object_name, $data ) {
				$this->localized_scripts[ $handle ] = array(
					'object_name' => $object_name,
					'data'        => $data,
				);
				return true;
			}
		);

		Functions\when( 'disabled' )->alias(
			function ( $disabled, $current = true, $echo = true ) {
				$result = $disabled == $current ? ' disabled="disabled"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
	}

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test COLOR_PALETTE constant exists.
	 *
	 * @return void
	 */
	public function test_color_palette_constant_exists(): void {
		$this->assertTrue( defined( QRGeneratorPage::class . '::COLOR_PALETTE' ) );
		$this->assertIsArray( QRGeneratorPage::COLOR_PALETTE );
	}

	/**
	 * Test COLOR_PALETTE contains expected colors.
	 *
	 * @return void
	 */
	public function test_color_palette_contains_black(): void {
		$this->assertArrayHasKey( '000000', QRGeneratorPage::COLOR_PALETTE );
		$this->assertEquals( 'Black', QRGeneratorPage::COLOR_PALETTE['000000'] );
	}

	/**
	 * Test COLOR_PALETTE contains navy.
	 *
	 * @return void
	 */
	public function test_color_palette_contains_navy(): void {
		$this->assertArrayHasKey( '1e3a5f', QRGeneratorPage::COLOR_PALETTE );
		$this->assertEquals( 'Navy', QRGeneratorPage::COLOR_PALETTE['1e3a5f'] );
	}

	/**
	 * Test COLOR_PALETTE contains burgundy.
	 *
	 * @return void
	 */
	public function test_color_palette_contains_burgundy(): void {
		$this->assertArrayHasKey( '722f37', QRGeneratorPage::COLOR_PALETTE );
		$this->assertEquals( 'Burgundy', QRGeneratorPage::COLOR_PALETTE['722f37'] );
	}

	/**
	 * Test COLOR_PALETTE contains forest green.
	 *
	 * @return void
	 */
	public function test_color_palette_contains_forest_green(): void {
		$this->assertArrayHasKey( '228b22', QRGeneratorPage::COLOR_PALETTE );
		$this->assertEquals( 'Forest Green', QRGeneratorPage::COLOR_PALETTE['228b22'] );
	}

	/**
	 * Test COLOR_PALETTE contains deep purple.
	 *
	 * @return void
	 */
	public function test_color_palette_contains_deep_purple(): void {
		$this->assertArrayHasKey( '4b0082', QRGeneratorPage::COLOR_PALETTE );
		$this->assertEquals( 'Deep Purple', QRGeneratorPage::COLOR_PALETTE['4b0082'] );
	}

	/**
	 * Test COLOR_PALETTE contains dark brown.
	 *
	 * @return void
	 */
	public function test_color_palette_contains_dark_brown(): void {
		$this->assertArrayHasKey( '3d2314', QRGeneratorPage::COLOR_PALETTE );
		$this->assertEquals( 'Dark Brown', QRGeneratorPage::COLOR_PALETTE['3d2314'] );
	}

	/**
	 * Test COLOR_PALETTE has correct count.
	 *
	 * @return void
	 */
	public function test_color_palette_has_six_colors(): void {
		$this->assertCount( 6, QRGeneratorPage::COLOR_PALETTE );
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test QRGeneratorPage can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$page = new QRGeneratorPage();

		$this->assertInstanceOf( QRGeneratorPage::class, $page );
	}

	// =========================================================================
	// handle_get_page_title() Tests
	// =========================================================================

	/**
	 * Test handle_get_page_title dies without permission.
	 *
	 * @return void
	 */
	public function test_handle_get_page_title_dies_without_permission(): void {
		$page = new QRGeneratorPage();

		Functions\when( 'current_user_can' )->justReturn( false );

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ) {
				$died = true;
				throw new \Exception( 'wp_die' );
			}
		);

		try {
			$page->handle_get_page_title();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died );
	}

	/**
	 * Test handle_get_page_title checks nonce after permission.
	 *
	 * @return void
	 */
	public function test_handle_get_page_title_checks_nonce(): void {
		$page = new QRGeneratorPage();

		Functions\when( 'current_user_can' )->justReturn( true );

		$nonce_checked = false;
		Functions\when( 'check_ajax_referer' )->alias(
			function () use ( &$nonce_checked ) {
				$nonce_checked = true;
				return true;
			}
		);

		Functions\when( 'wp_send_json_error' )->alias(
			function () {
				throw new \Exception( 'json_error' );
			}
		);

		$_POST['url'] = '';

		try {
			$page->handle_get_page_title();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertTrue( $nonce_checked );
	}

	/**
	 * Test handle_get_page_title returns error for empty URL.
	 *
	 * @return void
	 */
	public function test_handle_get_page_title_error_for_empty_url(): void {
		$page = new QRGeneratorPage();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		$error_message = null;
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data ) use ( &$error_message ) {
				$error_message = $data['message'] ?? null;
				throw new \Exception( 'json_error' );
			}
		);

		$_POST['url'] = '';

		try {
			$page->handle_get_page_title();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertEquals( 'No URL provided.', $error_message );
	}

	/**
	 * Test handle_get_page_title returns success with post title.
	 *
	 * @return void
	 */
	public function test_handle_get_page_title_returns_post_title(): void {
		$page = new QRGeneratorPage();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'url_to_postid' )->justReturn( 123 );
		Functions\when( 'get_the_title' )->justReturn( 'Test Page Title' );

		$success_data = null;
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) use ( &$success_data ) {
				$success_data = $data;
				throw new \Exception( 'json_success' );
			}
		);

		$_POST['url'] = 'http://example.com/test-page';

		try {
			$page->handle_get_page_title();
		} catch ( \Exception $e ) {
			$this->assertEquals( 'json_success', $e->getMessage() );
		}

		$this->assertNotNull( $success_data );
		$this->assertEquals( 'Test Page Title', $success_data['title'] );
	}

	/**
	 * Test handle_get_page_title returns error when page not found.
	 *
	 * @return void
	 */
	public function test_handle_get_page_title_error_when_page_not_found(): void {
		$page = new QRGeneratorPage();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'url_to_postid' )->justReturn( 0 );

		$error_message = null;
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data ) use ( &$error_message ) {
				$error_message = $data['message'] ?? null;
				throw new \Exception( 'json_error' );
			}
		);

		$_POST['url'] = 'http://example.com/nonexistent';

		try {
			$page->handle_get_page_title();
		} catch ( \Exception $e ) {
			// Expected.
		}

		$this->assertEquals( 'Page not found.', $error_message );
	}

	// =========================================================================
	// render() Tests
	// =========================================================================

	/**
	 * Test render outputs HTML with wrap class.
	 *
	 * @return void
	 */
	public function test_render_outputs_wrap_class(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="wrap"', $output );
	}

	/**
	 * Test render outputs page heading.
	 *
	 * @return void
	 */
	public function test_render_outputs_heading(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'QR Code Generator', $output );
	}

	/**
	 * Test render outputs description.
	 *
	 * @return void
	 */
	public function test_render_outputs_description(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Generate QR codes for any text or URL', $output );
	}

	/**
	 * Test render outputs content input field.
	 *
	 * @return void
	 */
	public function test_render_outputs_content_input(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="nte-qr-content"', $output );
		$this->assertStringContainsString( 'Enter URL or text...', $output );
	}

	/**
	 * Test render outputs color palette.
	 *
	 * @return void
	 */
	public function test_render_outputs_color_palette(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-qr-color-palette', $output );
		$this->assertStringContainsString( 'nte-qr-color-option', $output );
		$this->assertStringContainsString( 'nte-qr-color-swatch', $output );
	}

	/**
	 * Test render outputs all color options.
	 *
	 * @return void
	 */
	public function test_render_outputs_all_color_options(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		// Check for all colors.
		$this->assertStringContainsString( '000000', $output );
		$this->assertStringContainsString( '1e3a5f', $output );
		$this->assertStringContainsString( '722f37', $output );
		$this->assertStringContainsString( '228b22', $output );
		$this->assertStringContainsString( '4b0082', $output );
		$this->assertStringContainsString( '3d2314', $output );
	}

	/**
	 * Test render outputs generate button.
	 *
	 * @return void
	 */
	public function test_render_outputs_generate_button(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="nte-qr-generate"', $output );
		$this->assertStringContainsString( 'Generate QR Code', $output );
	}

	/**
	 * Test render outputs preview area.
	 *
	 * @return void
	 */
	public function test_render_outputs_preview_area(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="nte-qr-preview"', $output );
		$this->assertStringContainsString( 'nte-qr-preview', $output );
		$this->assertStringContainsString( 'QR code preview will appear here', $output );
	}

	/**
	 * Test render outputs download button (hidden).
	 *
	 * @return void
	 */
	public function test_render_outputs_download_button(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="nte-qr-download"', $output );
		$this->assertStringContainsString( 'Download QR Code', $output );
		$this->assertStringContainsString( 'display: none', $output );
	}

	/**
	 * Test render enqueues CSS stylesheet.
	 *
	 * @return void
	 */
	public function test_render_enqueues_css_stylesheet(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		ob_get_clean();

		$this->assertArrayHasKey( 'nettertech-events-qr-generator', $this->enqueued_styles );
		$this->assertStringContainsString( 'qr-generator.css', $this->enqueued_styles['nettertech-events-qr-generator']['src'] );
	}

	/**
	 * Test render enqueues JavaScript.
	 *
	 * @return void
	 */
	public function test_render_enqueues_javascript(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		ob_get_clean();

		$this->assertArrayHasKey( 'nettertech-events-qr-generator', $this->enqueued_scripts );
		$this->assertStringContainsString( 'qr-generator.js', $this->enqueued_scripts['nettertech-events-qr-generator']['src'] );
	}

	/**
	 * Test render enqueues script with wp-i18n dependency.
	 *
	 * @return void
	 */
	public function test_render_enqueues_script_with_i18n_dependency(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		ob_get_clean();

		$this->assertArrayHasKey( 'nettertech-events-qr-generator', $this->enqueued_scripts );
		$this->assertContains( 'wp-i18n', $this->enqueued_scripts['nettertech-events-qr-generator']['deps'] );
	}

	/**
	 * Test render outputs skip link for accessibility.
	 *
	 * @return void
	 */
	public function test_render_outputs_skip_link(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-skip-link', $output );
		$this->assertStringContainsString( 'screen-reader-text', $output );
		$this->assertStringContainsString( 'Skip to main content', $output );
	}

	/**
	 * Test render outputs main content ID for skip link target.
	 *
	 * @return void
	 */
	public function test_render_outputs_main_content_id(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="nte-main-content"', $output );
		$this->assertStringContainsString( 'href="#nte-main-content"', $output );
	}

	/**
	 * Test render outputs form table structure.
	 *
	 * @return void
	 */
	public function test_render_outputs_form_table(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="form-table"', $output );
		$this->assertStringContainsString( '<th scope="row">', $output );
	}

	/**
	 * Test render outputs color radio inputs.
	 *
	 * @return void
	 */
	public function test_render_outputs_color_radio_inputs(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="radio"', $output );
		$this->assertStringContainsString( 'name="nte-qr-color"', $output );
	}

	/**
	 * Test render outputs screen reader text for colors.
	 *
	 * @return void
	 */
	public function test_render_outputs_screen_reader_text_for_colors(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		// Check for screen reader text labels.
		$this->assertStringContainsString( 'class="screen-reader-text"', $output );
	}

	/**
	 * Test render localizes script data.
	 *
	 * @return void
	 */
	public function test_render_localizes_script_data(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		ob_get_clean();

		$this->assertArrayHasKey( 'nettertech-events-qr-generator', $this->localized_scripts );
		$this->assertEquals( 'nettertechEventsQRGenerator', $this->localized_scripts['nettertech-events-qr-generator']['object_name'] );
	}

	/**
	 * Test render localizes nonce in script data.
	 *
	 * @return void
	 */
	public function test_render_localizes_nonce(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		ob_get_clean();

		$this->assertArrayHasKey( 'nettertech-events-qr-generator', $this->localized_scripts );
		$data = $this->localized_scripts['nettertech-events-qr-generator']['data'];
		$this->assertArrayHasKey( 'nonce', $data );
		$this->assertEquals( 'test_nonce_123', $data['nonce'] );
	}

	/**
	 * Test render localizes admin ajax URL.
	 *
	 * @return void
	 */
	public function test_render_localizes_ajax_url(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		ob_get_clean();

		$this->assertArrayHasKey( 'nettertech-events-qr-generator', $this->localized_scripts );
		$data = $this->localized_scripts['nettertech-events-qr-generator']['data'];
		$this->assertArrayHasKey( 'ajaxUrl', $data );
		$this->assertStringContainsString( 'admin-ajax.php', $data['ajaxUrl'] );
	}

	/**
	 * Test render localizes site URL.
	 *
	 * @return void
	 */
	public function test_render_localizes_site_url(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		ob_get_clean();

		$this->assertArrayHasKey( 'nettertech-events-qr-generator', $this->localized_scripts );
		$data = $this->localized_scripts['nettertech-events-qr-generator']['data'];
		$this->assertArrayHasKey( 'siteUrl', $data );
		$this->assertStringContainsString( 'example.com', $data['siteUrl'] );
	}

	/**
	 * Test render outputs first color as checked.
	 *
	 * @return void
	 */
	public function test_render_outputs_first_color_checked(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		// The first color (black - 000000) should be checked.
		$this->assertStringContainsString( 'checked="checked"', $output );
	}

	/**
	 * Test render outputs color labels in title attributes.
	 *
	 * @return void
	 */
	public function test_render_outputs_color_labels_in_titles(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		foreach ( QRGeneratorPage::COLOR_PALETTE as $label ) {
			$this->assertStringContainsString( $label, $output );
		}
	}

	/**
	 * Test render outputs generator wrapper.
	 *
	 * @return void
	 */
	public function test_render_outputs_generator_wrapper(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="nte-qr-generator"', $output );
		$this->assertStringContainsString( 'nte-qr-generator__form', $output );
		$this->assertStringContainsString( 'nte-qr-generator__preview', $output );
	}

	/**
	 * Test render outputs submit button class.
	 *
	 * @return void
	 */
	public function test_render_outputs_submit_button_class(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="submit"', $output );
		$this->assertStringContainsString( 'button button-primary', $output );
	}

	// =========================================================================
	// Script Localization Tests
	// =========================================================================

	/**
	 * Test render localizes i18n strings.
	 *
	 * @return void
	 */
	public function test_render_localizes_i18n_strings(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		ob_get_clean();

		$this->assertArrayHasKey( 'nettertech-events-qr-generator', $this->localized_scripts );
		$data = $this->localized_scripts['nettertech-events-qr-generator']['data'];
		$this->assertArrayHasKey( 'i18n', $data );
		$this->assertArrayHasKey( 'selectLogo', $data['i18n'] );
		$this->assertArrayHasKey( 'generating', $data['i18n'] );
		$this->assertArrayHasKey( 'generateFailed', $data['i18n'] );
	}

	/**
	 * Test render enqueues script in footer.
	 *
	 * @return void
	 */
	public function test_render_enqueues_script_in_footer(): void {
		$page = new QRGeneratorPage();

		ob_start();
		$page->render();
		ob_get_clean();

		$this->assertArrayHasKey( 'nettertech-events-qr-generator', $this->enqueued_scripts );
		$this->assertTrue( $this->enqueued_scripts['nettertech-events-qr-generator']['in_footer'] );
	}

	// =========================================================================
	// Cleanup
	// =========================================================================

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}
}
