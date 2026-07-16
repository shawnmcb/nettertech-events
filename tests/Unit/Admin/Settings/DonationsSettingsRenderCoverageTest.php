<?php
/**
 * DonationsSettingsSection render coverage tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Settings;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Settings\DonationsSettingsSection;

/**
 * Render coverage for DonationsSettingsSection.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\Settings\DonationsSettingsSection
 */
class DonationsSettingsRenderCoverageTest extends \NetterTechEventsTestCase {

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
	}

	/**
	 * Test render with defaults emits all donation fields.
	 *
	 * @return void
	 */
	public function test_render_emits_donation_fields(): void {
		$section = new DonationsSettingsSection();

		ob_start();
		$section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'enable_donations', $output );
		$this->assertStringContainsString( 'donation_cause', $output );
		$this->assertStringContainsString( 'donation_presets', $output );
	}

	/**
	 * Test render with enabled donations reflects checked state.
	 *
	 * @return void
	 */
	public function test_render_with_donations_enabled(): void {
		$section = new DonationsSettingsSection();

		ob_start();
		$section->render(
			array(
				'enable_donations'        => true,
				'donation_cause'          => 'General fund',
				'enable_donation_roundup' => true,
				'donation_presets'        => array( 5, 10, 25 ),
				'allow_custom_donation'   => true,
				'max_donation'            => 500,
			)
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'General fund', $output );
		$this->assertStringContainsString( '5, 10, 25', $output );
		$this->assertStringContainsString( 'value="500"', $output );
	}

	/**
	 * Test get_bool_fields lists donation booleans.
	 *
	 * @return void
	 */
	public function test_get_bool_fields_returns_donation_bools(): void {
		$section = new DonationsSettingsSection();
		$fields  = $section->get_bool_fields();

		$this->assertContains( 'enable_donations', $fields );
		$this->assertContains( 'allow_custom_donation', $fields );
	}
}
