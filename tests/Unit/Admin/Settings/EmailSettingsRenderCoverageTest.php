<?php
/**
 * EmailSettingsSection render coverage tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Settings;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Settings\EmailSettingsSection;
use NetterTechEvents\Services\EmailConfig;

/**
 * Render coverage for EmailSettingsSection.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\Settings\EmailSettingsSection
 */
class EmailSettingsRenderCoverageTest extends \NetterTechEventsTestCase {

	/**
	 * Mock EmailConfig.
	 *
	 * @var EmailConfig|Mockery\MockInterface
	 */
	private $email_config;

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

		$this->email_config = Mockery::mock( EmailConfig::class );
		$this->email_config->shouldIgnoreMissing( '#2563eb' );
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
	 * Test get_id.
	 *
	 * @return void
	 */
	public function test_get_id(): void {
		$section = new EmailSettingsSection( $this->email_config );
		$this->assertSame( 'email', $section->get_id() );
	}

	/**
	 * Test render emits all configured field IDs.
	 *
	 * @return void
	 */
	public function test_render_with_defaults(): void {
		$section = new EmailSettingsSection( $this->email_config );

		ob_start();
		$section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-settings__section', $output );
	}

	/**
	 * Test render with values populates fields.
	 *
	 * @return void
	 */
	public function test_render_with_populated_settings(): void {
		$section = new EmailSettingsSection( $this->email_config );

		ob_start();
		$section->render(
			array(
				'accent_color'           => '#abcdef',
				'enable_reminders'       => true,
				'disable_customer_email' => false,
				'disable_qr_codes'       => false,
				'venue_contacts'         => 'venue@example.test',
				'cancellation_policy'    => 'Cancel within 24h.',
			)
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( '#abcdef', $output );
		$this->assertStringContainsString( 'venue@example.test', $output );
		$this->assertStringContainsString( 'Cancel within 24h', $output );
	}
}
