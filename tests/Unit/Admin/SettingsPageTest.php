<?php
/**
 * SettingsPage Test.
 *
 * @package NetterTechEvents\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use NetterTechEvents\Admin\SettingsPage;

/**
 * Tests for the SettingsPage class.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\SettingsPage
 */
class SettingsPageTest extends TestCase {

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_POST = array();
		$_GET  = array();

		// Common WordPress function mocks.
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text;
			}
		);
		Functions\when( 'esc_attr_e' )->alias(
			function ( $text ) {
				echo $text;
			}
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		// wp_unslash + sanitize_key are read across many render branches now
		// that $_GET['tab'] is read with sanitize_key( wp_unslash() ).
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		// map_deep applies a callback recursively to array/object leaves.
		// Mirrors WordPress core's implementation; needed because the
		// bootstrap stub is shadowed by per-test Brain Monkey setUp calls.
		Functions\when( 'map_deep' )->alias(
			function ( $value, $callback ) {
				if ( is_array( $value ) ) {
					foreach ( $value as $index => $item ) {
						$value[ $index ] = is_array( $item )
							? array_map( $callback, $item )
							: call_user_func( $callback, $item );
					}
					return $value;
				}
				return call_user_func( $callback, $value );
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			function () {
				echo '<input type="hidden" name="nettertech_events_settings_nonce" value="test_nonce">';
			}
		);
		Functions\when( 'submit_button' )->alias(
			function () {
				echo '<input type="submit" value="Save Changes">';
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			function ( ...$args ) {
				// Simple implementation: add_query_arg( 'key', 'value', 'url' ) or add_query_arg( array, 'url' ).
				if ( is_array( $args[0] ) ) {
					$url = $args[1] ?? '';
					$params = $args[0];
				} else {
					$url = $args[2] ?? '';
					$params = array( $args[0] => $args[1] );
				}
				$separator = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
				return $url . $separator . http_build_query( $params );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new \RuntimeException( 'redirect' );
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( fn( $key ) => strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ) );
		Functions\when( 'settings_errors' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
		Functions\when( 'wp_nonce_url' )->returnArg();
		Functions\when( 'checked' )->alias(
			function ( $checked, $current = true, $echo = true ) {
				$result = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		Functions\when( 'selected' )->alias(
			function ( $selected, $current = true, $echo = true ) {
				$result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		Functions\when( 'disabled' )->alias(
			function ( $disabled, $current = true, $echo = true ) {
				$result = ( (string) $disabled === (string) $current ) ? ' disabled="disabled"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		Functions\when( 'wp_timezone_string' )->justReturn( 'America/Chicago' );
		Functions\when( 'wp_timezone_choice' )->alias(
			function ( $selected ) {
				return '<option value="America/Chicago"' . ( 'America/Chicago' === $selected ? ' selected' : '' ) . '>America/Chicago</option>';
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_theme_mod' )->justReturn( 0 );
		Functions\when( 'plugins_url' )->justReturn( 'https://example.com/wp-content/plugins/nettertech-events' );
		Functions\when( 'wp_enqueue_media' )->justReturn( null );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( null );
		Functions\when( 'get_theme_support' )->justReturn( false );

		// Transient function mocks (used by PathConflictDetector).
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		// PathConflictDetector function mocks.
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'get_post_type_object' )->justReturn( null );
		Functions\when( '__' )->returnArg();
	}

	/**
	 * Tear down test environment.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		$_POST = array();
		$_GET  = array();
		parent::tearDown();
	}

	/**
	 * Create a SettingsPage instance with required dependencies.
	 *
	 * @return SettingsPage
	 */
	private function create_page(): SettingsPage {
		$mock_layout = Mockery::mock( \NetterTechEvents\Services\LayoutService::class );
		$mock_layout->shouldReceive( 'get_components' )->andReturn( array(
			'header'         => array( 'label' => 'Header', 'description' => 'Header', 'default_visible' => true ),
			'featured_image' => array( 'label' => 'Featured Image', 'description' => 'Image', 'default_visible' => true ),
			'description'    => array( 'label' => 'Description', 'description' => 'Desc', 'default_visible' => true ),
			'tickets'        => array( 'label' => 'Tickets', 'description' => 'Tickets', 'default_visible' => true ),
		) );
		$mock_layout->shouldReceive( 'get_layout' )->andReturn( array(
			'order'      => array( 'header', 'featured_image', 'description', 'tickets' ),
			'visibility' => array( 'header' => true, 'featured_image' => true, 'description' => true, 'tickets' => true ),
		) );
		$mock_layout->shouldIgnoreMissing();
		$email_config = $this->createMock( \NetterTechEvents\Services\EmailConfig::class );
		$email_config->method( 'get_accent_color' )->willReturn( '#333333' );
		return new SettingsPage(
			$mock_layout,
			new \NetterTechEvents\Admin\SettingsSanitizer(),
			$email_config
		);
	}

	// =========================================================================
	// Instantiation Tests
	// =========================================================================

	/**
	 * Test SettingsPage can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate(): void {
		$page = $this->create_page();
		$this->assertInstanceOf( SettingsPage::class, $page );
	}

	/**
	 * Test render method exists.
	 *
	 * @return void
	 */
	public function test_render_method_exists(): void {
		$page = $this->create_page();
		$this->assertTrue( method_exists( $page, 'render' ) );
	}

	// =========================================================================
	// Render Tests
	// =========================================================================

	/**
	 * Test render outputs page title.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_page_title(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'NetterTech Events Settings', $output );
	}

	/**
	 * Test render outputs form element.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_form_element(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( '<form method="post"', $output );
	}

	/**
	 * Test render outputs nonce field.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_nonce_field(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'nettertech_events_settings_nonce', $output );
	}

	/**
	 * Test render outputs submit button.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_submit_button(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'Save Changes', $output );
	}

	/**
	 * Test render outputs skip to main content link.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_skip_link(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'nte-skip-link', $output );
		$this->assertStringContainsString( 'Skip to main content', $output );
	}

	/**
	 * Test render outputs main content area with tabindex.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_main_content_with_tabindex(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'id="nte-main-content"', $output );
		$this->assertStringContainsString( 'tabindex="-1"', $output );
	}

	// =========================================================================
	// Settings Section Tests
	// =========================================================================

	/**
	 * Test render outputs General Settings section.
	 *
	 * @covers ::render
	 * @covers ::render_general_settings_section
	 * @return void
	 */
	public function test_render_outputs_general_settings_section(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'General Settings', $output );
		$this->assertStringContainsString( 'Default Calendar View', $output );
		$this->assertStringContainsString( 'Events Per Page', $output );
		$this->assertStringContainsString( 'Timezone', $output );
	}

	/**
	 * Test render outputs Default Venue section.
	 *
	 * @covers ::render
	 * @covers ::render_default_venue_section
	 * @return void
	 */
	public function test_render_outputs_default_venue_section(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'Default Venue', $output );
		$this->assertStringContainsString( 'Enable Default Venue', $output );
		$this->assertStringContainsString( 'Venue Name', $output );
		$this->assertStringContainsString( 'Venue Address', $output );
	}

	/**
	 * Test render outputs URL Settings section.
	 *
	 * @covers ::render
	 * @covers ::render_url_settings_section
	 * @return void
	 */
	public function test_render_outputs_url_settings_section(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'URL Settings', $output );
		$this->assertStringContainsString( 'Events Base Path', $output );
	}

	/**
	 * Test render outputs Event Layout section.
	 *
	 * @covers ::render
	 * @covers ::render_event_layout_section
	 * @return void
	 */
	public function test_render_outputs_event_layout_section(): void {
		$_GET['tab'] = 'display';
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'Event Page Layout', $output );
	}

	/**
	 * Test render outputs Features section.
	 *
	 * @covers ::render
	 * @covers ::render_features_section
	 * @return void
	 */
	public function test_render_outputs_features_section(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'Features', $output );
	}

	/**
	 * Test render outputs Tickets & Capacity section.
	 *
	 * @covers ::render
	 * @covers ::render_tickets_capacity_section
	 * @return void
	 */
	public function test_render_outputs_tickets_capacity_section(): void {
		$_GET['tab'] = 'ticketing';
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'Tickets & Capacity', $output );
	}

	/**
	 * Test render outputs Donations section.
	 *
	 * @covers ::render
	 * @covers \NetterTechEvents\Admin\Settings\DonationsSettingsSection::render
	 * @return void
	 */
	public function test_render_outputs_donations_section(): void {
		$_GET['tab'] = 'ticketing';
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'Donations', $output );
	}

	/**
	 * Test render outputs QR Codes section.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_qr_codes_section(): void {
		$_GET['tab'] = 'ticketing';
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'QR Codes', $output );
	}

	/**
	 * Test render outputs Check-in section.
	 *
	 * @covers ::render
	 * @covers ::render_checkin_section
	 * @return void
	 */
	public function test_render_outputs_checkin_section(): void {
		$_GET['tab'] = 'ticketing';
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'Check-In', $output );
	}

	/**
	 * Test render outputs Email section.
	 *
	 * @covers ::render
	 * @covers \NetterTechEvents\Admin\Settings\EmailSettingsSection::render
	 * @return void
	 */
	public function test_render_outputs_email_section(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'Email', $output );
	}

	/**
	 * Test render outputs Advanced section.
	 *
	 * @covers ::render
	 * @covers \NetterTechEvents\Admin\Settings\AdvancedSettingsSection::render
	 * @return void
	 */
	public function test_render_outputs_advanced_section(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'Advanced', $output );
	}

	// =========================================================================
	// Settings Values Display Tests
	// =========================================================================

	/**
	 * Test render displays saved default view setting.
	 *
	 * @covers ::render
	 * @covers ::render_general_settings_section
	 * @return void
	 */
	public function test_render_displays_saved_default_view(): void {
		Functions\when( 'get_option' )->alias(
			function ( $key ) {
				if ( 'nettertech_events_settings' === $key ) {
					return array( 'default_view' => 'week' );
				}
				return array();
			}
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		// The 'week' option should be selected.
		$this->assertStringContainsString( 'value="week"', $output );
	}

	/**
	 * Test render displays saved events per page.
	 *
	 * @covers ::render
	 * @covers ::render_general_settings_section
	 * @return void
	 */
	public function test_render_displays_saved_events_per_page(): void {
		Functions\when( 'get_option' )->alias(
			function ( $key ) {
				if ( 'nettertech_events_settings' === $key ) {
					return array( 'events_per_page' => 25 );
				}
				return array();
			}
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'value="25"', $output );
	}

	/**
	 * Test render displays saved venue name.
	 *
	 * @covers ::render
	 * @covers ::render_default_venue_section
	 * @return void
	 */
	public function test_render_displays_saved_venue_name(): void {
		Functions\when( 'get_option' )->alias(
			function ( $key ) {
				if ( 'nettertech_events_settings' === $key ) {
					return array( 'default_venue_name' => 'Test Venue' );
				}
				return array();
			}
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'value="Test Venue"', $output );
	}

	/**
	 * Test render displays saved events base path.
	 *
	 * @covers ::render
	 * @covers ::render_url_settings_section
	 * @return void
	 */
	public function test_render_displays_saved_events_base_path(): void {
		Functions\when( 'get_option' )->alias(
			function ( $key ) {
				if ( 'nettertech_events_settings' === $key ) {
					return array( 'events_base_path' => 'calendar' );
				}
				return array();
			}
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'value="calendar"', $output );
	}

	// =========================================================================
	// Form Submission Tests
	// =========================================================================

	/**
	 * Test render processes form when nonce is valid.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_processes_form_when_nonce_valid(): void {
		$_POST['nettertech_events_settings_nonce'] = 'valid_nonce';
		$_POST['nettertech_events_settings']       = array();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_settings_error' )->justReturn( null );

		$settings_saved = false;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$settings_saved ) {
				if ( 'nettertech_events_settings' === $key ) {
					$settings_saved = true;
				}
				return true;
			}
		);

		$page = $this->create_page();
		try {
			$this->captureRenderOutput( $page );
		} catch ( \RuntimeException $e ) {
			// Expected: wp_safe_redirect throws to prevent exit.
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		$this->assertTrue( $settings_saved );
	}

	/**
	 * Test render does not process form without nonce.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_does_not_process_form_without_nonce(): void {
		// No nonce in POST.
		$_POST['nettertech_events_settings'] = array();

		Functions\when( 'get_option' )->justReturn( array() );

		$settings_saved = false;
		Functions\when( 'update_option' )->alias(
			function () use ( &$settings_saved ) {
				$settings_saved = true;
				return true;
			}
		);

		$page = $this->create_page();
		$this->captureRenderOutput( $page );

		$this->assertFalse( $settings_saved );
	}

	/**
	 * Test render does not process form when nonce is invalid.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_does_not_process_form_with_invalid_nonce(): void {
		$_POST['nettertech_events_settings_nonce'] = 'invalid_nonce';
		$_POST['nettertech_events_settings']       = array();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );

		$settings_saved = false;
		Functions\when( 'update_option' )->alias(
			function () use ( &$settings_saved ) {
				$settings_saved = true;
				return true;
			}
		);

		$page = $this->create_page();
		$this->captureRenderOutput( $page );

		$this->assertFalse( $settings_saved );
	}

	/**
	 * Test render does not process form when user lacks capability.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_does_not_process_form_without_capability(): void {
		$_POST['nettertech_events_settings_nonce'] = 'valid_nonce';
		$_POST['nettertech_events_settings']       = array();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );

		$settings_saved = false;
		Functions\when( 'update_option' )->alias(
			function () use ( &$settings_saved ) {
				$settings_saved = true;
				return true;
			}
		);

		$page = $this->create_page();
		$this->captureRenderOutput( $page );

		$this->assertFalse( $settings_saved );
	}

	// =========================================================================
	// save_settings Tests
	// =========================================================================

	/**
	 * Test save_settings checks nonce.
	 *
	 * @covers ::save_settings
	 * @return void
	 */
	public function test_save_settings_checks_nonce(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( false );

		$page       = $this->create_page();
		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'save_settings' );

		// Should return early without calling update_option (capability passes, nonce fails).
		$method->invoke( $page );

		$this->assertTrue( true );
	}

	/**
	 * Test save_settings returns early when user lacks capability.
	 *
	 * @covers ::save_settings
	 * @return void
	 */
	public function test_save_settings_checks_capability(): void {
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		$page       = $this->create_page();
		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'save_settings' );

		// Should return early without calling update_option.
		$method->invoke( $page );

		$this->assertTrue( true );
	}

	/**
	 * Test save_settings saves settings with defaults.
	 *
	 * @covers ::save_settings
	 * @return void
	 */
	public function test_save_settings_saves_with_defaults(): void {
		$_POST['nettertech_events_settings'] = array();

		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'wp_timezone_string' )->justReturn( 'America/Chicago' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_settings_error' )->justReturn( null );

		$saved_settings = null;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$saved_settings ) {
				if ( 'nettertech_events_settings' === $key ) {
					$saved_settings = $value;
				}
				return true;
			}
		);

		$page       = $this->create_page();
		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'save_settings' );

		try {
			$method->invoke( $page );
		} catch ( \RuntimeException $e ) {
			// Expected: wp_safe_redirect throws to prevent exit.
		}

		$this->assertIsArray( $saved_settings );
		$this->assertArrayHasKey( 'events_base_path', $saved_settings );
		$this->assertEquals( 'events', $saved_settings['events_base_path'] );
	}

	/**
	 * Test save_settings parses donation presets.
	 *
	 * @covers ::save_settings
	 * @return void
	 */
	public function test_save_settings_parses_donation_presets(): void {
		$_POST['nettertech_events_active_tab']         = 'ticketing';
		$_POST['nettertech_events_settings'] = array(
			'donation_presets' => '10, 20, 50',
		);

		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_settings_error' )->justReturn( null );

		$saved_settings = null;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$saved_settings ) {
				if ( 'nettertech_events_settings' === $key ) {
					$saved_settings = $value;
				}
				return true;
			}
		);

		$page       = $this->create_page();
		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'save_settings' );

		try {
			$method->invoke( $page );
		} catch ( \RuntimeException $e ) {
			// Expected: wp_safe_redirect throws to prevent exit.
		}

		$this->assertIsArray( $saved_settings );
		$this->assertEquals( array( 10.0, 20.0, 50.0 ), $saved_settings['donation_presets'] );
	}

	/**
	 * Test save_settings parses check-in counters.
	 *
	 * @covers ::save_settings
	 * @return void
	 */
	public function test_save_settings_parses_checkin_counters(): void {
		$_POST['nettertech_events_active_tab']         = 'ticketing';
		$_POST['nettertech_events_settings'] = array(
			'checkin_counters' => 'Adults, Children, VIP',
		);

		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_settings_error' )->justReturn( null );

		$saved_settings = null;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$saved_settings ) {
				if ( 'nettertech_events_settings' === $key ) {
					$saved_settings = $value;
				}
				return true;
			}
		);

		$page       = $this->create_page();
		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'save_settings' );

		try {
			$method->invoke( $page );
		} catch ( \RuntimeException $e ) {
			// Expected: wp_safe_redirect throws to prevent exit.
		}

		$this->assertIsArray( $saved_settings );
		$this->assertEquals( array( 'Adults', 'Children', 'VIP' ), $saved_settings['checkin_counters'] );
	}

	/**
	 * Test save_settings triggers rewrite flush when paths change.
	 *
	 * @covers ::save_settings
	 * @return void
	 */
	public function test_save_settings_triggers_rewrite_flush_on_path_change(): void {
		$_POST['nettertech_events_settings'] = array(
			'events_base_path' => 'new-path',
		);

		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array( 'events_base_path' => 'old-path' ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_settings_error' )->justReturn( null );

		$rewrite_flag_set = false;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$rewrite_flag_set ) {
				if ( 'nettertech_events_flush_rewrite_rules' === $key && true === $value ) {
					$rewrite_flag_set = true;
				}
				return true;
			}
		);

		$page       = $this->create_page();
		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'save_settings' );

		try {
			$method->invoke( $page );
		} catch ( \RuntimeException $e ) {
			// Expected: wp_safe_redirect throws to prevent exit.
		}

		$this->assertTrue( $rewrite_flag_set );
	}

	// =========================================================================
	// HTML Structure Tests
	// =========================================================================

	/**
	 * Test render outputs proper nte-settings container.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_settings_container(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'class="nte-settings"', $output );
	}

	/**
	 * Test render outputs section headers.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_section_headers(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'nte-settings__section-header', $output );
		$this->assertStringContainsString( 'nte-settings__section-title', $output );
	}

	/**
	 * Test render outputs form tables.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_form_tables(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'class="form-table"', $output );
	}

	// =========================================================================
	// Input Field Tests
	// =========================================================================

	/**
	 * Test render outputs calendar view select.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_calendar_view_select(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'name="nettertech_events_settings[default_view]"', $output );
		$this->assertStringContainsString( 'id="default_view"', $output );
	}

	/**
	 * Test render outputs events per page input.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_events_per_page_input(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'name="nettertech_events_settings[events_per_page]"', $output );
		$this->assertStringContainsString( 'id="events_per_page"', $output );
		$this->assertStringContainsString( 'type="number"', $output );
	}

	/**
	 * Test render outputs timezone select.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_timezone_select(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'name="nettertech_events_settings[timezone]"', $output );
		$this->assertStringContainsString( 'id="timezone"', $output );
	}

	/**
	 * Test render outputs venue name input.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_venue_name_input(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'name="nettertech_events_settings[default_venue_name]"', $output );
		$this->assertStringContainsString( 'id="default_venue_name"', $output );
	}

	/**
	 * Test render outputs venue address textarea.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_venue_address_textarea(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'name="nettertech_events_settings[default_venue_address]"', $output );
		$this->assertStringContainsString( 'id="default_venue_address"', $output );
	}

	/**
	 * Test render outputs events base path input.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_events_base_path_input(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'name="nettertech_events_settings[events_base_path]"', $output );
		$this->assertStringContainsString( 'id="events_base_path"', $output );
	}

	// =========================================================================
	// render_admin_notices Tests
	// =========================================================================

	/**
	 * Test render_admin_notices displays success notice from transient.
	 *
	 * @covers ::render_admin_notices
	 * @return void
	 */
	public function test_render_admin_notices_displays_success_from_transient(): void {
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				if ( 'nettertech_events_settings_notice' === $key ) {
					return 'settings_updated';
				}
				return false;
			}
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );

		$transient_deleted = false;
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) use ( &$transient_deleted ) {
				if ( 'nettertech_events_settings_notice' === $key ) {
					$transient_deleted = true;
				}
				return true;
			}
		);

		$notice_added = false;
		Functions\when( 'add_settings_error' )->alias(
			function ( $setting, $code, $message, $type ) use ( &$notice_added ) {
				if ( 'settings_updated' === $code && 'success' === $type ) {
					$notice_added = true;
				}
			}
		);

		$page = $this->create_page();
		$this->captureRenderOutput( $page );

		$this->assertTrue( $transient_deleted );
		$this->assertTrue( $notice_added );
	}

	// =========================================================================
	// Tab Rendering Tests
	// =========================================================================

	/**
	 * Test render outputs display tab content.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_display_tab_content(): void {
		$_GET['tab'] = 'display';
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'Event Page Layout', $output );
		$this->assertStringContainsString( 'nte-layout-editor', $output );
	}

	/**
	 * Test render outputs QR Codes tab content.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_qr_codes_tab_content(): void {
		$_GET['tab'] = 'qr_codes';
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'QR', $output );
	}

	/**
	 * Test render outputs email tab content.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_outputs_email_tab_content(): void {
		$_GET['tab'] = 'email';
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'Advanced', $output );
	}

	/**
	 * Test render falls back to general for invalid tab.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_falls_back_to_general_for_invalid_tab(): void {
		$_GET['tab'] = 'nonexistent_tab';
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'General Settings', $output );
	}

	/**
	 * Test render outputs tab navigation.
	 *
	 * @covers ::render_tabs
	 * @return void
	 */
	public function test_render_outputs_tab_navigation(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'nav-tab-wrapper', $output );
		$this->assertStringContainsString( 'nav-tab-active', $output );
		$this->assertStringContainsString( 'General', $output );
		$this->assertStringContainsString( 'Display', $output );
		$this->assertStringContainsString( 'Ticketing', $output );
	}

	/**
	 * Test render marks active tab with aria-current.
	 *
	 * @covers ::render_tabs
	 * @return void
	 */
	public function test_render_marks_active_tab_with_aria_current(): void {
		$_GET['tab'] = 'display';
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'aria-current="page"', $output );
	}

	// =========================================================================
	// save_settings — QR Color Tests
	// =========================================================================

	/**
	 * Test save_settings sanitizes QR foreground color.
	 *
	 * @covers ::save_settings
	 * @return void
	 */
	// =========================================================================
	// save_settings — Date Badge Color Tests
	// =========================================================================

	/**
	 * Test save_settings saves custom date badge color when checkbox checked.
	 *
	 * @covers ::save_settings
	 * @return void
	 */
	public function test_save_settings_saves_date_badge_color_when_custom(): void {
		$_POST['nettertech_events_active_tab']         = 'display';
		$_POST['nettertech_events_settings'] = array(
			'date_badge_color_custom' => '1',
			'date_badge_color'        => '#ff0000',
		);

		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_settings_error' )->justReturn( null );

		$saved_settings = null;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$saved_settings ) {
				if ( 'nettertech_events_settings' === $key ) {
					$saved_settings = $value;
				}
				return true;
			}
		);

		$page       = $this->create_page();
		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'save_settings' );

		try {
			$method->invoke( $page );
		} catch ( \RuntimeException $e ) {
			// Expected: wp_safe_redirect throws.
		}

		$this->assertIsArray( $saved_settings );
		$this->assertTrue( $saved_settings['date_badge_color_custom'] );
		$this->assertEquals( '#ff0000', $saved_settings['date_badge_color'] );
	}

	/**
	 * Test save_settings persists date badge color but disables flag when custom not checked.
	 *
	 * @covers ::save_settings
	 * @return void
	 */
	public function test_save_settings_persists_date_badge_color_when_not_custom(): void {
		$_POST['nettertech_events_active_tab']         = 'display';
		$_POST['nettertech_events_settings'] = array(
			'date_badge_color' => '#ff0000',
		);

		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_settings_error' )->justReturn( null );

		$saved_settings = null;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$saved_settings ) {
				if ( 'nettertech_events_settings' === $key ) {
					$saved_settings = $value;
				}
				return true;
			}
		);

		$page       = $this->create_page();
		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'save_settings' );

		try {
			$method->invoke( $page );
		} catch ( \RuntimeException $e ) {
			// Expected: wp_safe_redirect throws.
		}

		$this->assertIsArray( $saved_settings );
		$this->assertFalse( $saved_settings['date_badge_color_custom'] );
		$this->assertEquals( '#ff0000', $saved_settings['date_badge_color'] );
	}

	// =========================================================================
	// save_settings — Extension Tab Routing Tests
	// =========================================================================

	/**
	 * Test save_settings fires the canonical SETTINGS_TAB_SAVE action with
	 * the active tab slug as argument when an extension tab is saved.
	 *
	 * Replaces an earlier assertion against the dynamic-name compat hook
	 * `nettertech_events_settings_save_{$tab}`, which was removed before the
	 * initial WP.org submission. Extensions register against the canonical
	 * action and switch on the $tab argument.
	 *
	 * @covers ::save_settings
	 * @return void
	 */
	public function test_save_settings_fires_action_for_extension_tab(): void {
		$_POST['nettertech_events_settings'] = array();
		$_POST['nettertech_events_active_tab']         = 'custom_pro_tab';

		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_settings_error' )->justReturn( null );
		Functions\when( 'update_option' )->justReturn( true );

		$canonical_fired_with = null;
		Functions\when( 'do_action' )->alias(
			function ( $tag, ...$args ) use ( &$canonical_fired_with ) {
				if ( 'nettertech_events_settings_tab_save' === $tag ) {
					$canonical_fired_with = $args[0] ?? null;
				}
			}
		);

		$page       = $this->create_page();
		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'save_settings' );

		try {
			$method->invoke( $page );
		} catch ( \RuntimeException $e ) {
			// Expected: wp_safe_redirect throws.
		}

		$this->assertSame( 'custom_pro_tab', $canonical_fired_with );
	}

	/**
	 * Test save_settings redirects back to the active tab.
	 *
	 * @covers ::save_settings
	 * @return void
	 */
	public function test_save_settings_redirects_to_active_tab(): void {
		$_POST['nettertech_events_settings'] = array();
		$_POST['nettertech_events_active_tab']         = 'ticketing';

		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_settings_error' )->justReturn( null );
		Functions\when( 'update_option' )->justReturn( true );

		$redirect_url = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect_url ) {
				$redirect_url = $url;
				throw new \RuntimeException( 'redirect' );
			}
		);

		$page       = $this->create_page();
		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'save_settings' );

		try {
			$method->invoke( $page );
		} catch ( \RuntimeException $e ) {
			// Expected: wp_safe_redirect throws.
		}

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'tab=ticketing', $redirect_url );
	}

	// =========================================================================
	// EmailSettingsSection::save() Tests (moved from SettingsPage::sante_email_settings)
	// =========================================================================

	/**
	 * Test EmailSettingsSection::save() saves email settings from POST.
	 *
	 * @return void
	 */
	public function test_sante_email_settings_saves_from_post(): void {
		$email_post_block = array(
			'enable_reminders'       => '1',
			'disable_customer_email' => '',
			'disable_qr_codes'       => '1',
			'venue_contacts'         => 'info@venue.com',
			'cancellation_policy'    => '<p>No refunds.</p>',
		);

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();

		$saved_email = null;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$saved_email ) {
				if ( 'nettertech_events_email_settings' === $key ) {
					$saved_email = $value;
				}
				return true;
			}
		);

		$email_config = $this->createMock( \NetterTechEvents\Services\EmailConfig::class );
		$email_config->method( 'get_accent_color' )->willReturn( '#333333' );
		$section      = new \NetterTechEvents\Admin\Settings\EmailSettingsSection( $email_config );
		$section->save( array( '_email_settings_post' => $email_post_block ), array() );

		$this->assertIsArray( $saved_email );
		$this->assertTrue( $saved_email['enable_reminders'] );
		$this->assertFalse( $saved_email['disable_customer_email'] );
		$this->assertTrue( $saved_email['disable_qr_codes'] );
		$this->assertEquals( 'info@venue.com', $saved_email['venue_contacts'] );
		$this->assertEquals( '<p>No refunds.</p>', $saved_email['cancellation_policy'] );
	}

	/**
	 * Test EmailSettingsSection::save() returns settings unchanged without POST data.
	 *
	 * @return void
	 */
	public function test_sante_email_settings_returns_early_without_post(): void {
		// No nettertech_events_email_settings in POST.
		$email_saved = false;
		Functions\when( 'update_option' )->alias(
			function ( $key ) use ( &$email_saved ) {
				if ( 'nettertech_events_email_settings' === $key ) {
					$email_saved = true;
				}
				return true;
			}
		);

		$email_config = $this->createMock( \NetterTechEvents\Services\EmailConfig::class );
		$email_config->method( 'get_accent_color' )->willReturn( '#333333' );
		$section      = new \NetterTechEvents\Admin\Settings\EmailSettingsSection( $email_config );
		$result       = $section->save( array(), array( 'existing' => 'value' ) );

		$this->assertFalse( $email_saved );
		$this->assertEquals( array( 'existing' => 'value' ), $result );
	}

	// =========================================================================
	// ImageDisplaySettingsSection::save() — Layout Tests (moved from SettingsPage)
	// =========================================================================

	/**
	 * Test ImageDisplaySettingsSection::save() returns early with empty layout order.
	 *
	 * @return void
	 */
	public function test_process_layout_settings_returns_early_with_empty_order(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();

		$section  = new \NetterTechEvents\Admin\Settings\ImageDisplaySettingsSection();
		$settings = $section->save( array(), array() );

		$this->assertArrayNotHasKey( 'event_layout', $settings );
	}

	/**
	 * Test ImageDisplaySettingsSection::save() processes valid layout data.
	 *
	 * @return void
	 */
	public function test_process_layout_settings_processes_valid_layout(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );

		$input = array(
			'event_layout_order'      => 'header,description,featured_image',
			'event_layout_visibility' => '{"header":true,"description":true,"featured_image":false}',
		);

		$section  = new \NetterTechEvents\Admin\Settings\ImageDisplaySettingsSection();
		$settings = $section->save( $input, array() );

		$this->assertArrayHasKey( 'event_layout', $settings );
		$this->assertArrayHasKey( 'order', $settings['event_layout'] );
		$this->assertArrayHasKey( 'visibility', $settings['event_layout'] );
		$this->assertContains( 'header', $settings['event_layout']['order'] );
	}

	/**
	 * Test ImageDisplaySettingsSection::save() handles invalid JSON visibility.
	 *
	 * @return void
	 */
	public function test_process_layout_settings_handles_invalid_json(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );

		$input = array(
			'event_layout_order'      => 'header,description',
			'event_layout_visibility' => 'not-valid-json',
		);

		$section  = new \NetterTechEvents\Admin\Settings\ImageDisplaySettingsSection();
		$settings = $section->save( $input, array() );

		// Should still process — visibility defaults to empty array, validation may fail.
		// Either way, no exception is thrown.
		$this->assertTrue( true );
	}

	// =========================================================================
	// render_layout_editor Tests
	// =========================================================================

	/**
	 * Test render_layout_editor outputs layout editor HTML.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_layout_editor_outputs_html(): void {
		$_GET['tab'] = 'display';
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'nte-layout-editor', $output );
		$this->assertStringContainsString( 'nte-layout-order', $output );
		$this->assertStringContainsString( 'nte-layout-visibility', $output );
		$this->assertStringContainsString( 'nte-layout-list', $output );
		$this->assertStringContainsString( 'nte-layout-reset', $output );
	}

	/**
	 * Test render_layout_editor lists all layout components.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_layout_editor_lists_components(): void {
		$_GET['tab'] = 'display';
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$page   = $this->create_page();
		$output = $this->captureRenderOutput( $page );

		$this->assertStringContainsString( 'data-component-id="header"', $output );
		$this->assertStringContainsString( 'data-component-id="featured_image"', $output );
		$this->assertStringContainsString( 'data-component-id="description"', $output );
	}

	/**
	 * Test render_layout_editor enqueues assets.
	 *
	 * @covers ::render
	 * @return void
	 */
	public function test_render_layout_editor_enqueues_assets(): void {
		$_GET['tab'] = 'display';
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$style_enqueued  = false;
		$script_enqueued = false;
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle ) use ( &$style_enqueued ) {
				if ( 'nettertech-events-layout-editor' === $handle ) {
					$style_enqueued = true;
				}
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) use ( &$script_enqueued ) {
				if ( 'nettertech-events-layout-editor' === $handle ) {
					$script_enqueued = true;
				}
			}
		);

		$page = $this->create_page();
		$this->captureRenderOutput( $page );

		$this->assertTrue( $style_enqueued );
		$this->assertTrue( $script_enqueued );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Capture render output.
	 *
	 * @param SettingsPage $page Settings page instance.
	 * @return string Captured output.
	 */
	private function captureRenderOutput( SettingsPage $page ): string {
		ob_start();
		$page->render();
		return ob_get_clean();
	}
}
