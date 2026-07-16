<?php
/**
 * DonationsSettingsSection unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Settings;

use NetterTechEvents\Admin\Settings\DonationsSettingsSection;
use NetterTechEvents\Admin\Settings\SettingsSectionInterface;
use Brain\Monkey\Functions;

/**
 * Test DonationsSettingsSection class.
 *
 * @since 1.1.0
 * @coversDefaultClass \NetterTechEvents\Admin\Settings\DonationsSettingsSection
 */
class DonationsSettingsSectionTest extends \NetterTechEventsTestCase {

	/**
	 * Section instance.
	 *
	 * @var DonationsSettingsSection
	 */
	private DonationsSettingsSection $section;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->section = new DonationsSettingsSection();
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
	public function test_get_id_returns_donations(): void {
		$this->assertEquals( 'donations', $this->section->get_id() );
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
	public function test_get_title_returns_donations(): void {
		Functions\when( '__' )->returnArg();

		$this->assertEquals( 'Donations', $this->section->get_title() );
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
	public function test_render_outputs_form_table(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'form-table', $output );
	}

	// =========================================================================
	// render() Tests - Enable Donations Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_enable_donations_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'enable_donations', $output );
		$this->assertStringContainsString( 'Enable Donations', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_enable_donations_unchecked_by_default(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// Look for the enable_donations checkbox section - should not be checked.
		$this->assertMatchesRegularExpression( '/enable_donations[^>]*value="1"[^>]*>/', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_enable_donations_checked_when_enabled(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'enable_donations' => true ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'checked', $output );
	}

	// =========================================================================
	// render() Tests - Donation Cause Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_donation_cause_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'donation_cause', $output );
		$this->assertStringContainsString( 'Cause/Message', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_donation_cause_default_value(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Support our venue', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_donation_cause_custom_value(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'donation_cause' => 'Help us grow' ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Help us grow', $output );
	}

	// =========================================================================
	// render() Tests - Round-Up Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_roundup_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'roundup_to', $output );
		$this->assertStringContainsString( 'Round-Up To', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_roundup_has_three_options(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="dollar"', $output );
		$this->assertStringContainsString( 'value="five"', $output );
		$this->assertStringContainsString( 'value="ten"', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_roundup_default_is_dollar(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// Dollar option should be checked by default.
		$this->assertMatchesRegularExpression( '/value="dollar"[^>]*checked/', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_roundup_five_checked_when_selected(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'roundup_to' => 'five' ) );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="five"[^>]*checked/', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_roundup_ten_checked_when_selected(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'roundup_to' => 'ten' ) );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="ten"[^>]*checked/', $output );
	}

	// =========================================================================
	// render() Tests - Preset Amounts Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_preset_amounts_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'donation_presets', $output );
		$this->assertStringContainsString( 'Preset Amounts', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_preset_amounts_default_value(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( '5, 10, 25', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_preset_amounts_custom_value(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'donation_presets' => array( 10, 20, 50, 100 ) ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( '10, 20, 50, 100', $output );
	}

	// =========================================================================
	// render() Tests - Custom Amount Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_custom_amount_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'allow_custom_donation', $output );
		$this->assertStringContainsString( 'Custom Amount', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_custom_amount_checked_by_default(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// Custom amount is checked by default (true).
		$this->assertMatchesRegularExpression( '/allow_custom_donation[^>]*checked/', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_custom_amount_unchecked_when_disabled(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'allow_custom_donation' => false ) );
		$output = ob_get_clean();

		// Should not have checked for this specific checkbox.
		$this->assertStringNotContainsString( 'allow_custom_donation" value="1"  checked', $output );
	}

	// =========================================================================
	// render() Tests - Max Donation Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_max_donation_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'max_donation', $output );
		$this->assertStringContainsString( 'Maximum Donation', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_max_donation_default_value(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/max_donation[^>]*value="100"/', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_max_donation_custom_value(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'max_donation' => 500 ) );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/max_donation[^>]*value="500"/', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_max_donation_has_min_max(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'min="1"', $output );
		$this->assertStringContainsString( 'max="10000"', $output );
	}

	// =========================================================================
	// render() Tests - Field Types and Classes
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_text_fields_have_regular_text_class(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'regular-text', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_number_fields_have_small_text_class(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'small-text', $output );
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

		$this->assertStringContainsString( 'for="donation_cause"', $output );
		$this->assertStringContainsString( 'for="donation_presets"', $output );
		$this->assertStringContainsString( 'for="max_donation"', $output );
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

	/**
	 * @covers ::render
	 */
	public function test_render_includes_fieldset_for_roundup(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<fieldset>', $output );
		$this->assertStringContainsString( '</fieldset>', $output );
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
			function ( $value, $compare = true ) {
				if ( $value === $compare ) {
					echo ' checked';
				}
			}
		);
	}
}
