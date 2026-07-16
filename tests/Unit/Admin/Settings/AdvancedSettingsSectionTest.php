<?php
/**
 * AdvancedSettingsSection unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Settings;

use NetterTechEvents\Admin\Settings\AdvancedSettingsSection;
use NetterTechEvents\Admin\Settings\SettingsSectionInterface;
use Brain\Monkey\Functions;

/**
 * Test AdvancedSettingsSection class.
 *
 * @since 1.1.0
 * @coversDefaultClass \NetterTechEvents\Admin\Settings\AdvancedSettingsSection
 */
class AdvancedSettingsSectionTest extends \NetterTechEventsTestCase {

	/**
	 * Section instance.
	 *
	 * @var AdvancedSettingsSection
	 */
	private AdvancedSettingsSection $section;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->section = new AdvancedSettingsSection();
	}

	// =========================================================================
	// Interface Tests
	// =========================================================================

	/**
	 * Test implements interface.
	 */
	public function test_implements_settings_section_interface(): void {
		$this->assertInstanceOf( SettingsSectionInterface::class, $this->section );
	}

	// =========================================================================
	// get_id() Tests
	// =========================================================================

	/**
	 * @covers ::get_id
	 */
	public function test_get_id_returns_advanced(): void {
		$this->assertEquals( 'advanced', $this->section->get_id() );
	}

	/**
	 * @covers ::get_id
	 */
	public function test_get_id_returns_string(): void {
		$this->assertIsString( $this->section->get_id() );
	}

	// =========================================================================
	// get_title() Tests
	// =========================================================================

	/**
	 * @covers ::get_title
	 */
	public function test_get_title_returns_advanced(): void {
		Functions\when( '__' )->returnArg();

		$this->assertEquals( 'Advanced', $this->section->get_title() );
	}

	/**
	 * @covers ::get_title
	 */
	public function test_get_title_returns_string(): void {
		Functions\when( '__' )->returnArg();

		$this->assertIsString( $this->section->get_title() );
	}

	// =========================================================================
	// render() Tests - Basic Structure
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_outputs_section_wrapper(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-settings__section', $output );
	}

	/**
	 * @covers ::render
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
	 * @covers ::render
	 */
	public function test_render_outputs_section_content(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-settings__section-content', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_outputs_form_table(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'form-table', $output );
	}

	// =========================================================================
	// render() Tests - Caution Notice
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_caution_notice(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'Caution:', $output );
	}

	// =========================================================================
	// render() Tests - Occurrence Horizon Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_occurrence_horizon_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'occurrence_horizon', $output );
		$this->assertStringContainsString( 'Recurring Event Lookahead', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_occurrence_horizon_default_value(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="365"', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_occurrence_horizon_custom_value(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'occurrence_horizon' => 730 ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="730"', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_occurrence_horizon_has_min_max(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'min="30"', $output );
		$this->assertStringContainsString( 'max="730"', $output );
	}

	// =========================================================================
	// render() Tests - Rate Limit Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_rate_limit_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'rate_limit_requests', $output );
		$this->assertStringContainsString( 'rate_limit_window', $output );
		$this->assertStringContainsString( 'API Rate Limit', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_rate_limit_default_values(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// Default: 60 requests per 60 seconds.
		$this->assertMatchesRegularExpression( '/rate_limit_requests[^>]*value="60"/', $output );
		$this->assertMatchesRegularExpression( '/rate_limit_window[^>]*value="60"/', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_rate_limit_custom_values(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render(
			array(
				'rate_limit_requests' => 100,
				'rate_limit_window'   => 120,
			)
		);
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/rate_limit_requests[^>]*value="100"/', $output );
		$this->assertMatchesRegularExpression( '/rate_limit_window[^>]*value="120"/', $output );
	}

	// =========================================================================
	// render() Tests - Cart Hold Time Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_cart_hold_time_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'pending_hold_time', $output );
		$this->assertStringContainsString( 'Cart Hold Time', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_cart_hold_time_default_value(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// Default: 900 seconds (15 minutes).
		$this->assertMatchesRegularExpression( '/pending_hold_time[^>]*value="900"/', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_cart_hold_time_custom_value(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'pending_hold_time' => 1800 ) );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/pending_hold_time[^>]*value="1800"/', $output );
	}

	// =========================================================================
	// render() Tests - Category Cache Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_category_cache_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'category_cache_ttl', $output );
		$this->assertStringContainsString( 'Category Cache Duration', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_category_cache_default_value(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// Default: 3600 seconds (1 hour).
		$this->assertMatchesRegularExpression( '/category_cache_ttl[^>]*value="3600"/', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_category_cache_custom_value(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'category_cache_ttl' => 7200 ) );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/category_cache_ttl[^>]*value="7200"/', $output );
	}

	// =========================================================================
	// render() Tests - Delete Data Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_delete_data_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'delete_data_on_uninstall', $output );
		$this->assertStringContainsString( 'Delete Data on Uninstall', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_delete_data_checkbox_unchecked_by_default(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// Should not have checked attribute.
		$this->assertStringNotContainsString( 'checked', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_delete_data_checkbox_checked_when_enabled(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'delete_data_on_uninstall' => true ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'checked', $output );
	}

	// =========================================================================
	// render() Tests - Frontend Branding Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_frontend_branding_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'show_frontend_branding', $output );
		$this->assertStringContainsString( 'Help others discover NetterTech Events', $output );
		$this->assertStringContainsString( 'Disabled by default', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_frontend_branding_checkbox_checked_when_enabled(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'show_frontend_branding' => true ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'show_frontend_branding', $output );
		$this->assertStringContainsString( 'checked', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_delete_data_includes_warning(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Warning:', $output );
		$this->assertStringContainsString( 'permanently delete', $output );
	}

	// =========================================================================
	// render() Tests - Field Types
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_number_inputs_have_small_text_class(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'small-text', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_includes_description_elements(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="description"', $output );
	}

	// =========================================================================
	// render() Tests - Labels and Accessibility
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_labels_with_for_attributes(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'for="occurrence_horizon"', $output );
		$this->assertStringContainsString( 'for="rate_limit_requests"', $output );
		$this->assertStringContainsString( 'for="pending_hold_time"', $output );
		$this->assertStringContainsString( 'for="category_cache_ttl"', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_table_rows_have_proper_scope(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'scope="row"', $output );
	}

	// =========================================================================
	// parse_embed_sources() Tests — CSP frame-src allowlist validation
	// =========================================================================

	/**
	 * Test a plain https origin is accepted with no warnings/rejections.
	 */
	public function test_parse_embed_sources_accepts_https_origin(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_embed_sources( 'https://video.example.com', false );
		$this->assertSame( array( 'https://video.example.com' ), $r['sources'] );
		$this->assertSame( array(), $r['rejected'] );
		$this->assertSame( array(), $r['warnings'] );
	}

	/**
	 * Test only the origin is kept — paths and queries are dropped.
	 */
	public function test_parse_embed_sources_drops_path_keeps_origin(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_embed_sources( 'https://example.com/embed/123?x=1', false );
		$this->assertSame( array( 'https://example.com' ), $r['sources'] );
	}

	/**
	 * Test http origins are rejected unless insecure sources are permitted.
	 */
	public function test_parse_embed_sources_rejects_http_when_insecure_disallowed(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_embed_sources( 'http://insecure.example.com', false );
		$this->assertSame( array(), $r['sources'] );
		$this->assertCount( 1, $r['rejected'] );
	}

	/**
	 * Test http origins are accepted with a warning when explicitly allowed.
	 */
	public function test_parse_embed_sources_accepts_http_with_warning_when_allowed(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_embed_sources( 'http://insecure.example.com', true );
		$this->assertSame( array( 'http://insecure.example.com' ), $r['sources'] );
		$this->assertCount( 1, $r['warnings'] );
	}

	/**
	 * Test a bare host (no scheme) is rejected.
	 */
	public function test_parse_embed_sources_rejects_bare_host(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_embed_sources( 'example.com', false );
		$this->assertSame( array(), $r['sources'] );
		$this->assertCount( 1, $r['rejected'] );
	}

	/**
	 * Test lone wildcard, scheme-only, and CSP keyword tokens are all rejected.
	 */
	public function test_parse_embed_sources_rejects_keywords_and_lone_wildcard(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_embed_sources( "*\n'self'\nhttps:", false );
		$this->assertSame( array(), $r['sources'] );
		$this->assertCount( 3, $r['rejected'] );
	}

	/**
	 * Test a leading-subdomain wildcard is accepted with a warning.
	 */
	public function test_parse_embed_sources_accepts_leading_subdomain_wildcard_with_warning(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_embed_sources( 'https://*.example.com', false );
		$this->assertSame( array( 'https://*.example.com' ), $r['sources'] );
		$this->assertCount( 1, $r['warnings'] );
	}

	/**
	 * Test a mid-host wildcard (not a leading subdomain) is rejected.
	 */
	public function test_parse_embed_sources_rejects_midhost_wildcard(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_embed_sources( 'https://ex*ample.com', false );
		$this->assertSame( array(), $r['sources'] );
		$this->assertCount( 1, $r['rejected'] );
	}

	/**
	 * Test duplicate origins (including via differing paths) are de-duplicated.
	 */
	public function test_parse_embed_sources_dedupes(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_embed_sources( "https://a.com\nhttps://a.com/x\nhttps://a.com", false );
		$this->assertSame( array( 'https://a.com' ), $r['sources'] );
	}

	// =========================================================================
	// save() Tests — embed-source persistence
	// =========================================================================

	/**
	 * Test save() stores validated sources and the insecure flag.
	 */
	public function test_save_stores_validated_sources_and_flag(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$out = $this->section->save(
			array(
				'allowed_embed_sources'        => "https://a.com\nhttp://b.com",
				'allow_insecure_embed_sources' => '1',
			),
			array()
		);

		$this->assertSame( array( 'https://a.com', 'http://b.com' ), $out['allowed_embed_sources'] );
		$this->assertTrue( $out['allow_insecure_embed_sources'] );
	}

	/**
	 * Test save() drops http sources when the insecure flag is off.
	 */
	public function test_save_rejects_http_when_flag_off(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$out = $this->section->save(
			array(
				'allowed_embed_sources'        => "https://a.com\nhttp://b.com",
				'allow_insecure_embed_sources' => '',
			),
			array()
		);

		$this->assertSame( array( 'https://a.com' ), $out['allowed_embed_sources'] );
		$this->assertFalse( $out['allow_insecure_embed_sources'] );
	}

	/**
	 * Test the insecure flag is registered as a boolean field (so it is
	 * force-set to false when the checkbox is unchecked).
	 */
	public function test_get_bool_fields_includes_allow_insecure(): void {
		$this->assertContains( 'allow_insecure_embed_sources', $this->section->get_bool_fields() );
	}

	// =========================================================================
	// parse_script_sources() Tests — CSP script-src allowlist validation (NTE-127)
	// =========================================================================

	/**
	 * Test a plain https origin is accepted with no warnings/rejections.
	 */
	public function test_parse_script_sources_accepts_https_origin(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_script_sources( 'https://cdn.example.com' );
		$this->assertSame( array( 'https://cdn.example.com' ), $r['sources'] );
		$this->assertSame( array(), $r['rejected'] );
		$this->assertSame( array(), $r['warnings'] );
	}

	/**
	 * Test only the origin is kept — paths and queries are dropped.
	 */
	public function test_parse_script_sources_drops_path_keeps_origin(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_script_sources( 'https://cdn.example.com/lib/app.js?v=2' );
		$this->assertSame( array( 'https://cdn.example.com' ), $r['sources'] );
	}

	/**
	 * Test http origins are always rejected for scripts (no insecure opt-in).
	 */
	public function test_parse_script_sources_always_rejects_http(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_script_sources( 'http://insecure.example.com' );
		$this->assertSame( array(), $r['sources'] );
		$this->assertCount( 1, $r['rejected'] );
	}

	/**
	 * Test a bare host (no scheme) is rejected.
	 */
	public function test_parse_script_sources_rejects_bare_host(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_script_sources( 'cdn.example.com' );
		$this->assertSame( array(), $r['sources'] );
		$this->assertCount( 1, $r['rejected'] );
	}

	/**
	 * Test lone wildcard, scheme-only, and CSP keyword tokens are all rejected.
	 */
	public function test_parse_script_sources_rejects_keywords_and_lone_wildcard(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_script_sources( "*\n'self'\nhttps:" );
		$this->assertSame( array(), $r['sources'] );
		$this->assertCount( 3, $r['rejected'] );
	}

	/**
	 * Test a leading-subdomain wildcard is accepted with a warning.
	 */
	public function test_parse_script_sources_accepts_leading_subdomain_wildcard_with_warning(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_script_sources( 'https://*.example.com' );
		$this->assertSame( array( 'https://*.example.com' ), $r['sources'] );
		$this->assertCount( 1, $r['warnings'] );
	}

	/**
	 * Test a mid-host wildcard (not a leading subdomain) is rejected.
	 */
	public function test_parse_script_sources_rejects_midhost_wildcard(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_script_sources( 'https://ex*ample.com' );
		$this->assertSame( array(), $r['sources'] );
		$this->assertCount( 1, $r['rejected'] );
	}

	/**
	 * Test duplicate origins (including via differing paths) are de-duplicated.
	 */
	public function test_parse_script_sources_dedupes(): void {
		Functions\when( '__' )->returnArg();
		$r = AdvancedSettingsSection::parse_script_sources( "https://a.com\nhttps://a.com/x\nhttps://a.com" );
		$this->assertSame( array( 'https://a.com' ), $r['sources'] );
	}

	/**
	 * Test save() stores validated script sources and drops http origins.
	 */
	public function test_save_stores_validated_script_sources(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$out = $this->section->save(
			array(
				'allowed_script_sources' => "https://cdn.example.com\nhttp://insecure.example.com",
			),
			array()
		);

		$this->assertSame( array( 'https://cdn.example.com' ), $out['allowed_script_sources'] );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Set up common WordPress function mocks for render tests.
	 *
	 * @return void
	 */
	private function setup_render_mocks(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text;
			}
		);
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'checked' )->alias(
			function ( $value ) {
				if ( $value ) {
					echo ' checked';
				}
			}
		);
	}
}
