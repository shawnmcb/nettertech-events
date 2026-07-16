<?php
/**
 * AdvancedSettingsSection render coverage tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Settings;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Settings\AdvancedSettingsSection;

/**
 * Render coverage for AdvancedSettingsSection.
 *
 * Existing AdvancedSettingsSectionTest exercises only a subset of render
 * branches because its WP-stub set is incomplete. This suite supplies the
 * remaining stubs and runs render() with various input shapes.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\Settings\AdvancedSettingsSection
 */
class AdvancedSettingsRenderCoverageTest extends \NetterTechEventsTestCase {

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
	}

	/**
	 * Test render with defaults emits all fields.
	 *
	 * @return void
	 */
	public function test_render_with_defaults(): void {
		$section = new AdvancedSettingsSection();

		ob_start();
		$section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'occurrence_horizon', $output );
		$this->assertStringContainsString( 'ical_feed_horizon_days', $output );
		$this->assertStringContainsString( 'rate_limit_requests', $output );
		$this->assertStringContainsString( 'pending_hold_time', $output );
		$this->assertStringContainsString( 'show_frontend_branding', $output );
		$this->assertStringContainsString( 'delete_data_on_uninstall', $output );
	}

	/**
	 * Test render with all-on settings shows checked boxes.
	 *
	 * @return void
	 */
	public function test_render_with_settings_set(): void {
		$section = new AdvancedSettingsSection();

		ob_start();
		$section->render(
			array(
				'occurrence_horizon'        => 180,
				'rate_limit_requests'       => 100,
				'rate_limit_window'         => 60,
				'pending_hold_time'         => 1800,
				'category_cache_ttl'        => 60,
				'log_retention_days'        => 30,
				'show_frontend_branding'    => true,
				'delete_data_on_uninstall'  => true,
			)
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="180"', $output );
		$this->assertGreaterThanOrEqual( 2, substr_count( $output, 'checked="checked"' ) );
	}

	/**
	 * Test render includes caution notice.
	 *
	 * @return void
	 */
	public function test_render_includes_caution_notice(): void {
		$section = new AdvancedSettingsSection();

		ob_start();
		$section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-warning', $output );
		$this->assertStringContainsString( 'Caution:', $output );
	}

	/**
	 * Test save preserves existing settings and adds embed-source defaults.
	 *
	 * With no embed input, save() leaves unrelated keys untouched and writes
	 * the empty allowlist + insecure flag (off) defaults.
	 *
	 * @return void
	 */
	public function test_save_preserves_existing_and_adds_embed_defaults(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$section = new AdvancedSettingsSection();
		$current = array( 'foo' => 'bar' );
		$out     = $section->save( array(), $current );

		$this->assertSame( 'bar', $out['foo'] );
		$this->assertSame( array(), $out['allowed_embed_sources'] );
		$this->assertFalse( $out['allow_insecure_embed_sources'] );
	}

	/**
	 * Test get_bool_fields lists known bools.
	 *
	 * @return void
	 */
	public function test_get_bool_fields(): void {
		$section = new AdvancedSettingsSection();
		$fields  = $section->get_bool_fields();
		$this->assertContains( 'delete_data_on_uninstall', $fields );
		$this->assertContains( 'show_frontend_branding', $fields );
	}
}
