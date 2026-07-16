<?php
/**
 * ImageDisplaySettingsSection unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Settings;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Settings\ImageDisplaySettingsSection;
use NetterTechEvents\Admin\Settings\SettingsSectionInterface;

/**
 * Test ImageDisplaySettingsSection class.
 *
 * Covers identity, render output across all five view ratio fields, and
 * the date-badge color row. `save()` reaches into the container and is
 * exercised via integration tests; this suite focuses on render coverage.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\Settings\ImageDisplaySettingsSection
 */
class ImageDisplaySettingsSectionTest extends \NetterTechEventsTestCase {

	/**
	 * Section under test.
	 *
	 * @var ImageDisplaySettingsSection
	 */
	private ImageDisplaySettingsSection $section;

	/**
	 * Set up.
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
		Functions\when( 'selected' )->alias(
			static function ( $a, $b = true, $echo = true ) {
				$out = (string) $a === (string) $b ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);
		Functions\when( 'checked' )->alias(
			static function ( $a, $b = true, $echo = true ) {
				$out = (string) $a === (string) $b ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);

		$this->section = new ImageDisplaySettingsSection();
	}

	/**
	 * Test interface implementation.
	 *
	 * @return void
	 */
	public function test_implements_interface(): void {
		$this->assertInstanceOf( SettingsSectionInterface::class, $this->section );
	}

	/**
	 * Test get_id.
	 *
	 * @return void
	 */
	public function test_get_id(): void {
		$this->assertSame( 'image-display', $this->section->get_id() );
	}

	/**
	 * Test get_title.
	 *
	 * @return void
	 */
	public function test_get_title(): void {
		$this->assertSame( 'Image Display', $this->section->get_title() );
	}

	/**
	 * Test get_bool_fields.
	 *
	 * @return void
	 */
	public function test_get_bool_fields_returns_empty(): void {
		$this->assertSame( array(), $this->section->get_bool_fields() );
	}

	/**
	 * Test render emits all view ratio fields.
	 *
	 * @return void
	 */
	public function test_render_emits_all_view_fields(): void {
		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="image_aspect_ratio"', $output );
		foreach ( array( 'cards', 'list', 'carousel', 'calendar' ) as $view ) {
			$this->assertStringContainsString( 'id="image_aspect_ratio_' . $view . '"', $output );
		}
	}

	/**
	 * Test render emits all preset values.
	 *
	 * @return void
	 */
	public function test_render_emits_all_ratio_presets(): void {
		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		foreach ( array( '16:9', '3:2', '4:3', '1:1', 'original', 'custom' ) as $preset ) {
			$this->assertStringContainsString( 'value="' . $preset . '"', $output );
		}
	}

	/**
	 * Test render defaults to 16:9 for site default.
	 *
	 * @return void
	 */
	public function test_render_defaults_site_to_16_9(): void {
		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/<select[^>]*id="image_aspect_ratio"[^>]*>.*?value="16:9"\s+selected="selected"/s', $output );
	}

	/**
	 * Test render respects saved site default.
	 *
	 * @return void
	 */
	public function test_render_respects_saved_default(): void {
		ob_start();
		$this->section->render( array( 'image_aspect_ratio' => '1:1' ) );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/<select[^>]*id="image_aspect_ratio"[^>]*>.*?value="1:1"\s+selected="selected"/s', $output );
	}

	/**
	 * Test render reveals custom-ratio field when preset is "custom".
	 *
	 * @return void
	 */
	public function test_render_shows_custom_field_when_preset_is_custom(): void {
		ob_start();
		$this->section->render(
			array(
				'image_aspect_ratio'        => 'custom',
				'image_aspect_ratio_custom' => '5:4',
			)
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="5:4"', $output );
	}

	/**
	 * Test render emits date badge color picker.
	 *
	 * @return void
	 */
	public function test_render_emits_date_badge_color_picker(): void {
		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// Date badge section.
		$this->assertStringContainsString( 'date_badge_color', $output );
	}
}
