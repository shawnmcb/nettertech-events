<?php
/**
 * ArchiveSettingsSection unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Admin\Settings
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Admin\Settings;

use Brain\Monkey\Functions;
use NetterTechEvents\Admin\Settings\ArchiveSettingsSection;
use NetterTechEvents\Admin\Settings\SettingsSectionInterface;

/**
 * Test ArchiveSettingsSection class.
 *
 * @coversDefaultClass \NetterTechEvents\Admin\Settings\ArchiveSettingsSection
 */
class ArchiveSettingsSectionTest extends \NetterTechEventsTestCase {

	/**
	 * Section under test.
	 *
	 * @var ArchiveSettingsSection
	 */
	private ArchiveSettingsSection $section;

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

		$this->section = new ArchiveSettingsSection();
	}

	/**
	 * Test interface compliance.
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
		$this->assertSame( 'archive-display', $this->section->get_id() );
	}

	/**
	 * Test get_title.
	 *
	 * @return void
	 */
	public function test_get_title(): void {
		$this->assertSame( 'Archive Display', $this->section->get_title() );
	}

	/**
	 * Test get_bool_fields returns the five archive filter visibility keys.
	 *
	 * These keys must be declared so SettingsSaveHandler sets them to false
	 * when an unchecked checkbox is absent from POST input.
	 *
	 * @return void
	 */
	public function test_get_bool_fields_returns_five_filter_keys(): void {
		$expected = array(
			'archive_show_filters',
			'archive_show_search',
			'archive_show_category',
			'archive_show_tag',
			'archive_show_date_range',
		);
		$this->assertSame( $expected, $this->section->get_bool_fields() );
	}

	/**
	 * Test save returns settings unchanged.
	 *
	 * @return void
	 */
	public function test_save_returns_current_settings_unchanged(): void {
		$current = array( 'archive_layout' => 'list' );
		$this->assertSame( $current, $this->section->save( array(), $current ) );
	}

	/**
	 * Test render outputs section wrapper.
	 *
	 * @return void
	 */
	public function test_render_outputs_section_wrapper(): void {
		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-settings__section', $output );
		$this->assertStringContainsString( 'form-table', $output );
	}

	/**
	 * Test render outputs all three fields.
	 *
	 * @return void
	 */
	public function test_render_outputs_all_fields(): void {
		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="archive_layout"', $output );
		$this->assertStringContainsString( 'id="archive_columns"', $output );
		$this->assertStringContainsString( 'id="archive_limit"', $output );
	}

	/**
	 * Test render lists all layout presets.
	 *
	 * @return void
	 */
	public function test_render_lists_all_layout_presets(): void {
		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="cards"', $output );
		$this->assertStringContainsString( 'value="grid"', $output );
		$this->assertStringContainsString( 'value="list"', $output );
	}

	/**
	 * Test render uses default layout when settings empty.
	 *
	 * @return void
	 */
	public function test_render_defaults_to_cards_layout(): void {
		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// "cards" should be the selected default.
		$this->assertMatchesRegularExpression( '/value="cards"\s+selected="selected"/', $output );
	}

	/**
	 * Test render respects custom layout setting.
	 *
	 * @return void
	 */
	public function test_render_respects_saved_layout(): void {
		ob_start();
		$this->section->render( array( 'archive_layout' => 'list' ) );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="list"\s+selected="selected"/', $output );
	}

	/**
	 * Test render emits all column options.
	 *
	 * @return void
	 */
	public function test_render_emits_six_column_options(): void {
		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		for ( $i = 1; $i <= 6; $i++ ) {
			$this->assertStringContainsString( 'value="' . $i . '"', $output );
		}
	}

	/**
	 * Test render respects saved columns value.
	 *
	 * @return void
	 */
	public function test_render_respects_saved_columns(): void {
		ob_start();
		$this->section->render( array( 'archive_columns' => 4 ) );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="4"\s+selected="selected"/', $output );
	}

	/**
	 * Test render respects saved limit.
	 *
	 * @return void
	 */
	public function test_render_respects_saved_limit(): void {
		ob_start();
		$this->section->render( array( 'archive_limit' => 24 ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="24"', $output );
	}

	/**
	 * Test render falls back to default limit when missing.
	 *
	 * @return void
	 */
	public function test_render_defaults_limit_to_12(): void {
		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="12"', $output );
	}

	// =========================================================================
	// Archive filter visibility checkboxes
	// =========================================================================

	/**
	 * Test render outputs all five filter visibility checkboxes.
	 *
	 * @return void
	 */
	public function test_render_outputs_five_filter_visibility_checkboxes(): void {
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current = true, $echo = true ) {
				$out = (bool) $checked === (bool) $current ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="archive_show_filters"', $output );
		$this->assertStringContainsString( 'id="archive_show_search"', $output );
		$this->assertStringContainsString( 'id="archive_show_category"', $output );
		$this->assertStringContainsString( 'id="archive_show_tag"', $output );
		$this->assertStringContainsString( 'id="archive_show_date_range"', $output );
	}

	/**
	 * Test render defaults all five checkboxes to checked when settings are absent (back-compat).
	 *
	 * @return void
	 */
	public function test_render_filter_checkboxes_default_to_checked(): void {
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current = true, $echo = true ) {
				$out = (bool) $checked === (bool) $current ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		// With no stored settings, all five should render as checked="checked".
		$this->assertSame( 5, substr_count( $output, 'checked="checked"' ) );
	}

	/**
	 * Test render respects stored false values — unchecked boxes render without checked attribute.
	 *
	 * @return void
	 */
	public function test_render_respects_stored_false_for_filter_checkboxes(): void {
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current = true, $echo = true ) {
				$out = (bool) $checked === (bool) $current ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);

		ob_start();
		$this->section->render(
			array(
				'archive_show_filters'    => false,
				'archive_show_search'     => false,
				'archive_show_category'   => false,
				'archive_show_tag'        => false,
				'archive_show_date_range' => false,
			)
		);
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'checked="checked"', $output );
	}

	/**
	 * Test render outputs the master/sub-checkbox JavaScript block.
	 *
	 * @return void
	 */
	public function test_render_outputs_filter_visibility_script(): void {
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current = true, $echo = true ) {
				$out = (bool) $checked === (bool) $current ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<script>', $output );
		$this->assertStringContainsString( 'archive_show_filters', $output );
		$this->assertStringContainsString( 'applyMasterState', $output );
	}

	/**
	 * Test render uses correct input names for form submission.
	 *
	 * @return void
	 */
	public function test_render_filter_checkboxes_have_correct_input_names(): void {
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current = true, $echo = true ) {
				$out = (bool) $checked === (bool) $current ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);

		ob_start();
		$this->section->render( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="nettertech_events_settings[archive_show_filters]"', $output );
		$this->assertStringContainsString( 'name="nettertech_events_settings[archive_show_search]"', $output );
		$this->assertStringContainsString( 'name="nettertech_events_settings[archive_show_category]"', $output );
		$this->assertStringContainsString( 'name="nettertech_events_settings[archive_show_tag]"', $output );
		$this->assertStringContainsString( 'name="nettertech_events_settings[archive_show_date_range]"', $output );
	}
}
