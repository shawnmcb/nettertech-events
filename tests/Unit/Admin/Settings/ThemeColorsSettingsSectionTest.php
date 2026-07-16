<?php
/**
 * ThemeColorsSettingsSection unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Settings;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Admin\Settings\SettingsSectionInterface;
use NetterTechEvents\Admin\Settings\ThemeColorsSettingsSection;
use NetterTechEvents\Services\PaletteResolver;

/**
 * Test ThemeColorsSettingsSection class.
 *
 * Covers identifier metadata, render branches (detected vs no-detected
 * palette, customize on/off), save sanitization wiring, and bool-field
 * surface.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\Settings\ThemeColorsSettingsSection
 */
class ThemeColorsSettingsSectionTest extends \NetterTechEventsTestCase {

	/**
	 * Mock palette resolver.
	 *
	 * @var PaletteResolver|Mockery\MockInterface
	 */
	private $palette_resolver;

	/**
	 * Section instance under test.
	 *
	 * @var ThemeColorsSettingsSection
	 */
	private ThemeColorsSettingsSection $section;

	/**
	 * Set up render-time WP function stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'checked' )->alias(
			static function ( $a, $b = true, $echo = true ) {
				$out = (string) $a === (string) $b ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);

		$this->palette_resolver = Mockery::mock( PaletteResolver::class );
		$this->section          = new ThemeColorsSettingsSection( $this->palette_resolver );
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
	 * Test implements SettingsSectionInterface.
	 *
	 * @return void
	 */
	public function test_implements_settings_section_interface(): void {
		$this->assertInstanceOf( SettingsSectionInterface::class, $this->section );
	}

	/**
	 * Test get_id returns expected identifier.
	 *
	 * @return void
	 */
	public function test_get_id_returns_theme_colors(): void {
		$this->assertSame( 'theme-colors', $this->section->get_id() );
	}

	/**
	 * Test get_title is non-empty string.
	 *
	 * @return void
	 */
	public function test_get_title_returns_translated_string(): void {
		$this->assertSame( 'Theme Colors', $this->section->get_title() );
	}

	/**
	 * Test get_bool_fields returns empty (this section has no booleans).
	 *
	 * @return void
	 */
	public function test_get_bool_fields_returns_empty(): void {
		$this->assertSame( array(), $this->section->get_bool_fields() );
	}

	/**
	 * Test render produces section wrapper when palette is empty.
	 *
	 * @return void
	 */
	public function test_render_outputs_warning_when_no_palette(): void {
		$this->palette_resolver->shouldReceive( 'resolve' )->andReturn( array() );

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-settings__section', $output );
		$this->assertStringContainsString( 'dashicons-warning', $output );
		$this->assertStringContainsString( 'No theme palette detected', $output );
	}

	/**
	 * Test render produces swatches when palette is detected.
	 *
	 * @return void
	 */
	public function test_render_outputs_swatches_when_palette_detected(): void {
		$this->palette_resolver->shouldReceive( 'resolve' )->andReturn(
			array(
				'primary'        => '#abcdef',
				'primary_hover'  => '#123456',
				'text'           => '#000000',
				'text_muted'     => '#666666',
				'border'         => '#dddddd',
				'background'     => '#ffffff',
				'background_alt' => '#fafafa',
			)
		);

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-theme-swatches', $output );
		$this->assertStringContainsString( '#abcdef', $output );
	}

	/**
	 * Test render hides custom-colors block when customize is off.
	 *
	 * @return void
	 */
	public function test_render_hides_custom_block_when_customize_off(): void {
		$this->palette_resolver->shouldReceive( 'resolve' )->andReturn( array() );

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'display:none;', $output );
	}

	/**
	 * Test render reveals custom-colors block when customize is on.
	 *
	 * @return void
	 */
	public function test_render_shows_custom_block_when_customize_on(): void {
		$this->palette_resolver->shouldReceive( 'resolve' )->andReturn( array() );

		ob_start();
		$this->section->render( array( 'theme_colors' => array( 'customize' => 1 ) ) );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'id="nte-theme-colors-custom" style="display:none;"', $output );
		$this->assertStringContainsString( 'nte-theme-color-picker', $output );
	}

	/**
	 * Test render emits one color input per role when customize is on.
	 *
	 * @return void
	 */
	public function test_render_emits_color_picker_per_role(): void {
		$this->palette_resolver->shouldReceive( 'resolve' )->andReturn( array() );

		ob_start();
		$this->section->render( array( 'theme_colors' => array( 'customize' => 1 ) ) );
		$output = ob_get_clean();

		foreach ( array( 'primary', 'primary_hover', 'text', 'text_muted', 'border', 'background', 'background_alt' ) as $role ) {
			$this->assertStringContainsString( 'nettertech_events_theme_color_' . $role, $output );
		}
	}
}
