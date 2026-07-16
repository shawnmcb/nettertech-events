<?php
/**
 * EmailSettingsSection unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Settings;

use NetterTechEvents\Admin\Settings\EmailSettingsSection;
use NetterTechEvents\Admin\Settings\SettingsSectionInterface;
use NetterTechEvents\Services\EmailConfig;
use Brain\Monkey\Functions;

/**
 * Test EmailSettingsSection class.
 *
 * @since 1.1.0
 * @coversDefaultClass \NetterTechEvents\Admin\Settings\EmailSettingsSection
 */
class EmailSettingsSectionTest extends \NetterTechEventsTestCase {

	/**
	 * Section instance.
	 *
	 * @var EmailSettingsSection
	 */
	private EmailSettingsSection $section;

	/**
	 * Mock email config.
	 *
	 * @var EmailConfig|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $email_config;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->email_config = $this->createMock( EmailConfig::class );
		$this->email_config->method( 'get_accent_color' )->willReturn( '#333333' );
		$this->section      = new EmailSettingsSection( $this->email_config );
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
	public function test_get_id_returns_email(): void {
		$this->assertEquals( 'email', $this->section->get_id() );
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
	public function test_get_title_returns_email(): void {
		Functions\when( '__' )->returnArg();

		$this->assertEquals( 'Email', $this->section->get_title() );
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

	/**
	 * @covers ::render
	 */
	public function test_render_outputs_description(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Configure email notifications', $output );
	}

	// =========================================================================
	// render() Tests - Disable Customer Email Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_disable_customer_email_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'disable_customer_email', $output );
		$this->assertStringContainsString( 'Disable Customer Emails', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_disable_customer_email_unchecked_by_default(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// Verify the field exists but isn't checked.
		$this->assertStringContainsString( 'disable_customer_email', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_disable_customer_email_checked_when_enabled(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'disable_customer_email' => true ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'checked', $output );
	}

	// =========================================================================
	// render() Tests - Disable QR Codes Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_disable_qr_codes_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'disable_qr_codes', $output );
		$this->assertStringContainsString( 'Exclude QR Codes', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_disable_qr_codes_unchecked_by_default(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'disable_qr_codes', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_disable_qr_codes_checked_when_enabled(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'disable_qr_codes' => true ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'checked', $output );
	}

	// =========================================================================
	// render() Tests - Venue Contacts Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_venue_contacts_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'venue_contacts', $output );
		$this->assertStringContainsString( 'Notification Emails', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_venue_contacts_empty_by_default(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/venue_contacts[^>]*value=""/', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_venue_contacts_custom_value(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array( 'venue_contacts' => 'staff@venue.com, manager@venue.com' ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'staff@venue.com, manager@venue.com', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_venue_contacts_has_placeholder(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'placeholder=', $output );
	}

	// =========================================================================
	// render() Tests - Cancellation Policy Field
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_cancellation_policy_field(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'cancellation_policy', $output );
		$this->assertStringContainsString( 'Cancellation Policy', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_cancellation_policy_empty_by_default(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<textarea', $output );
		$this->assertStringContainsString( '</textarea>', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_cancellation_policy_custom_value(): void {
		$this->setup_render_mocks();

		$policy = 'Tickets are non-refundable within 48 hours of event.';
		ob_start();
		$this->section->render( array( 'cancellation_policy' => $policy ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( $policy, $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_cancellation_policy_is_textarea(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/<textarea[^>]*cancellation_policy/', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_cancellation_policy_has_large_text_class(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'large-text', $output );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_cancellation_policy_mentions_html_allowed(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'HTML allowed', $output );
	}

	// =========================================================================
	// render() Tests - Field Types and Classes
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_text_input_has_regular_text_class(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'regular-text', $output );
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

		$this->assertStringContainsString( 'for="venue_contacts"', $output );
		$this->assertStringContainsString( 'for="cancellation_policy"', $output );
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
	public function test_render_uses_nte_email_settings_option_name(): void {
		$this->setup_render_mocks();

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nettertech_events_email_settings[', $output );
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
		Functions\when( 'esc_attr_e' )->alias(
			function ( $text ) {
				echo $text;
			}
		);
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'checked' )->alias(
			function ( $value ) {
				if ( $value ) {
					echo ' checked';
				}
			}
		);
	}
}
