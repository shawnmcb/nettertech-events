<?php
/**
 * PaletteResolver class unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Services\PaletteResolver;
use Brain\Monkey\Functions;

/**
 * Test PaletteResolver palette detection, var() resolution,
 * slug/template/luminance matching, and contrast validation.
 */
class PaletteResolverTest extends \NetterTechEventsTestCase {

	/**
	 * PaletteResolver instance.
	 *
	 * @var PaletteResolver
	 */
	private PaletteResolver $resolver;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new PaletteResolver();
	}

	// =========================================================================
	// resolve() Integration Tests
	// =========================================================================

	/**
	 * Test resolve returns empty when no palette available.
	 *
	 * @return void
	 */
	public function test_resolve_returns_empty_without_palette(): void {
		Functions\when( 'get_theme_support' )->justReturn( false );

		$result = $this->resolver->resolve();

		$this->assertSame( array(), $result );
	}

	/**
	 * Test resolve uses filter override when provided.
	 *
	 * @return void
	 */
	public function test_resolve_uses_filter_override(): void {
		$custom_map = array(
			'primary'    => '#FF0000',
			'text'       => '#000000',
			'background' => '#FFFFFF',
		);

		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) use ( $custom_map ) {
				if ( 'nettertech_events_palette_map' === $tag ) {
					return $custom_map;
				}
				return $value;
			}
		);

		$result = $this->resolver->resolve();

		$this->assertSame( '#FF0000', $result['primary'] );
		$this->assertSame( '#000000', $result['text'] );
		$this->assertSame( '#FFFFFF', $result['background'] );
	}

	/**
	 * Test resolve with Kadence palette and var() references.
	 *
	 * @return void
	 */
	public function test_resolve_kadence_palette(): void {
		$kadence_palette = json_encode(
			array(
				'active'  => 'palette',
				'palette' => array(
					array( 'color' => '#3296A8' ),
					array( 'color' => '#2B6CB0' ),
					array( 'color' => '#1A202C' ),
					array( 'color' => '#2D3748' ),
					array( 'color' => '#F5F0E8' ),
					array( 'color' => '#718096' ),
					array( 'color' => '#EDF2F7' ),
					array( 'color' => '#F7FAFC' ),
					array( 'color' => '#3D6B5E' ),
				),
			)
		);

		Functions\when( 'get_theme_support' )->justReturn(
			array(
				array(
					array( 'name' => 'Palette 1', 'slug' => 'theme-palette1', 'color' => 'var(--global-palette1)' ),
					array( 'name' => 'Palette 3', 'slug' => 'theme-palette3', 'color' => 'var(--global-palette3)' ),
					array( 'name' => 'Palette 5', 'slug' => 'theme-palette5', 'color' => 'var(--global-palette5)' ),
					array( 'name' => 'Palette 7', 'slug' => 'theme-palette7', 'color' => 'var(--global-palette7)' ),
					array( 'name' => 'Palette 9', 'slug' => 'theme-palette9', 'color' => 'var(--global-palette9)' ),
				),
			)
		);
		Functions\when( 'wp_get_theme' )->justReturn(
			new class {
				public function get_template(): string {
					return 'kadence';
				}
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $key ) use ( $kadence_palette ) {
				if ( 'kadence_global_palette' === $key ) {
					return $kadence_palette;
				}
				return false;
			}
		);

		$result = $this->resolver->resolve();

		// Kadence template mapping: palette1->primary, palette3->text, palette9->background.
		// Contrast validation corrects dark-on-dark: text stays darkest, background becomes lightest.
		$this->assertSame( '#3296A8', $result['primary'] );
		$this->assertArrayHasKey( 'text', $result );
		$this->assertArrayHasKey( 'background', $result );
		// After contrast correction, background should be a light color (high luminance).
		$bg_rgb = \NetterTechEvents\Utilities\ColorUtility::hex_to_rgb( $result['background'] );
		$this->assertNotNull( $bg_rgb );
		$bg_luminance = \NetterTechEvents\Utilities\ColorUtility::relative_luminance( $bg_rgb[0], $bg_rgb[1], $bg_rgb[2] );
		$this->assertGreaterThan( 0.7, $bg_luminance, 'Background should be a light color after contrast correction' );
	}

	/**
	 * Test resolve with Astra palette and var() references.
	 *
	 * @return void
	 */
	public function test_resolve_astra_palette(): void {
		$astra_settings = array(
			'global-color-palette' => array(
				'palette' => array(
					'#0170B9', // 0 -> primary
					'#3a3a3a', // 1
					'#3a3a3a', // 2 -> text
					'#4B5563', // 3 -> text_muted
					'#F0F0F0', // 4 -> background_alt
					'#FFFFFF', // 5 -> background
				),
			),
		);

		Functions\when( 'get_theme_support' )->justReturn(
			array(
				array(
					array( 'name' => 'Color 0', 'slug' => 'ast-global-color-0', 'color' => 'var(--ast-global-color-0)' ),
					array( 'name' => 'Color 2', 'slug' => 'ast-global-color-2', 'color' => 'var(--ast-global-color-2)' ),
					array( 'name' => 'Color 3', 'slug' => 'ast-global-color-3', 'color' => 'var(--ast-global-color-3)' ),
					array( 'name' => 'Color 4', 'slug' => 'ast-global-color-4', 'color' => 'var(--ast-global-color-4)' ),
					array( 'name' => 'Color 5', 'slug' => 'ast-global-color-5', 'color' => 'var(--ast-global-color-5)' ),
				),
			)
		);
		Functions\when( 'wp_get_theme' )->justReturn(
			new class {
				public function get_template(): string {
					return 'astra';
				}
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $key ) use ( $astra_settings ) {
				if ( 'astra-settings' === $key ) {
					return $astra_settings;
				}
				return false;
			}
		);

		$result = $this->resolver->resolve();

		$this->assertSame( '#0170B9', $result['primary'] );
		$this->assertSame( '#3a3a3a', $result['text'] );
		$this->assertSame( '#FFFFFF', $result['background'] );
	}

	/**
	 * Test resolve with unknown theme falls back to slug matching.
	 *
	 * @return void
	 */
	public function test_resolve_unknown_theme_fallback(): void {
		Functions\when( 'get_theme_support' )->justReturn(
			array(
				array(
					array( 'name' => 'Primary', 'slug' => 'primary', 'color' => '#0066CC' ),
					array( 'name' => 'Contrast', 'slug' => 'contrast', 'color' => '#1A1A1A' ),
					array( 'name' => 'Base', 'slug' => 'base', 'color' => '#FFFFFF' ),
				),
			)
		);

		$result = $this->resolver->resolve();

		$this->assertSame( '#0066CC', $result['primary'] );
		$this->assertSame( '#1A1A1A', $result['text'] );
		$this->assertSame( '#FFFFFF', $result['background'] );
	}

	// =========================================================================
	// Contrast Validation Tests (migrated from AssetsTest)
	// =========================================================================

	/**
	 * Test validate_palette_contrast passes through when contrast is sufficient.
	 *
	 * @return void
	 */
	public function test_validate_palette_contrast_passes_good_contrast(): void {
		$map = array(
			'text'       => '#000000',
			'background' => '#FFFFFF',
			'primary'    => '#0066CC',
		);
		$palette = array(
			array( 'name' => 'Black', 'slug' => 'black', 'color' => '#000000' ),
			array( 'name' => 'White', 'slug' => 'white', 'color' => '#FFFFFF' ),
			array( 'name' => 'Blue', 'slug' => 'blue', 'color' => '#0066CC' ),
		);

		$result = $this->resolver->validate_palette_contrast( $map, $palette );

		$this->assertSame( '#000000', $result['text'] );
		$this->assertSame( '#FFFFFF', $result['background'] );
		$this->assertSame( '#0066CC', $result['primary'] );
	}

	/**
	 * Test validate_palette_contrast corrects Bellwright-like palette.
	 *
	 * @return void
	 */
	public function test_validate_palette_contrast_corrects_bellwright_palette(): void {
		$map = array(
			'text'       => '#8B4513',
			'background' => '#3D6B5E',
			'primary'    => '#8B4513',
		);
		$palette = array(
			array( 'name' => 'Dark', 'slug' => 'palette1', 'color' => '#2B2D2F' ),
			array( 'name' => 'Mid-dark', 'slug' => 'palette2', 'color' => '#4A4A4A' ),
			array( 'name' => 'Brown', 'slug' => 'palette3', 'color' => '#8B4513' ),
			array( 'name' => 'Teal', 'slug' => 'palette4', 'color' => '#3D6B5E' ),
			array( 'name' => 'Light', 'slug' => 'palette5', 'color' => '#F5F0E8' ),
		);

		$result = $this->resolver->validate_palette_contrast( $map, $palette );

		$this->assertSame( '#2B2D2F', $result['text'] );
		$this->assertSame( '#F5F0E8', $result['background'] );
		$this->assertArrayNotHasKey( 'text_muted', $result );
		$this->assertArrayNotHasKey( 'background_alt', $result );
	}

	/**
	 * Test validate_palette_contrast skips when no background in map.
	 *
	 * @return void
	 */
	public function test_validate_palette_contrast_skips_without_background(): void {
		$map = array(
			'text'    => '#8B4513',
			'primary' => '#3D6B5E',
		);
		$palette = array(
			array( 'name' => 'Brown', 'slug' => 'brown', 'color' => '#8B4513' ),
			array( 'name' => 'Teal', 'slug' => 'teal', 'color' => '#3D6B5E' ),
		);

		$result = $this->resolver->validate_palette_contrast( $map, $palette );

		$this->assertSame( '#8B4513', $result['text'] );
		$this->assertSame( '#3D6B5E', $result['primary'] );
	}

	/**
	 * Test validate_palette_contrast handles palette with fewer than 2 colors.
	 *
	 * @return void
	 */
	public function test_validate_palette_contrast_with_tiny_palette(): void {
		$map = array(
			'text'       => '#808080',
			'background' => '#909090',
		);
		$palette = array(
			array( 'name' => 'Gray', 'slug' => 'gray', 'color' => '#808080' ),
		);

		$result = $this->resolver->validate_palette_contrast( $map, $palette );

		$this->assertSame( '#808080', $result['text'] );
		$this->assertSame( '#909090', $result['background'] );
	}

	/**
	 * Test validate_palette_contrast fixes primary when it lacks contrast against background.
	 *
	 * @return void
	 */
	public function test_validate_palette_contrast_fixes_primary_contrast(): void {
		$map = array(
			'text'       => '#000000',
			'background' => '#FFFFFF',
			'primary'    => '#F0F0F0',
		);
		$palette = array(
			array( 'name' => 'Black', 'slug' => 'black', 'color' => '#000000' ),
			array( 'name' => 'White', 'slug' => 'white', 'color' => '#FFFFFF' ),
			array( 'name' => 'Light Gray', 'slug' => 'light-gray', 'color' => '#F0F0F0' ),
			array( 'name' => 'Blue', 'slug' => 'blue', 'color' => '#0066CC' ),
		);

		$result = $this->resolver->validate_palette_contrast( $map, $palette );

		$this->assertSame( '#000000', $result['text'] );
		$this->assertSame( '#FFFFFF', $result['background'] );
		$this->assertNotSame( '#F0F0F0', $result['primary'] );
	}

	/**
	 * Test validate_palette_contrast with unresolvable colors.
	 *
	 * @return void
	 */
	public function test_validate_palette_contrast_with_unresolvable_colors(): void {
		$map = array(
			'text'       => 'var(--unknown-text)',
			'background' => 'var(--unknown-bg)',
			'primary'    => 'var(--unknown-primary)',
		);
		$palette = array(
			array( 'name' => 'Dark', 'slug' => 'dark', 'color' => '#1A1A1A' ),
			array( 'name' => 'Mid', 'slug' => 'mid', 'color' => '#0066CC' ),
			array( 'name' => 'Light', 'slug' => 'light', 'color' => '#F5F5F5' ),
		);

		$result = $this->resolver->validate_palette_contrast( $map, $palette );

		$this->assertSame( '#1A1A1A', $result['text'] );
		$this->assertSame( '#F5F5F5', $result['background'] );
	}

	// =========================================================================
	// resolve_palette_var_references Tests (migrated from AssetsTest)
	// =========================================================================

	/**
	 * Test resolve_palette_var_references resolves Kadence var() to hex.
	 *
	 * @return void
	 */
	public function test_resolve_palette_var_references_kadence_hex(): void {
		$kadence_palette = json_encode(
			array(
				'active'  => 'palette',
				'palette' => array(
					array( 'color' => '#3296A8' ),
					array( 'color' => '#2B6CB0' ),
					array( 'color' => '#1A202C' ),
					array( 'color' => '#2D3748' ),
					array( 'color' => '#F5F0E8' ),
					array( 'color' => '#718096' ),
					array( 'color' => '#EDF2F7' ),
					array( 'color' => '#F7FAFC' ),
					array( 'color' => '#3D6B5E' ),
				),
			)
		);

		Functions\when( 'wp_get_theme' )->justReturn(
			new class {
				public function get_template(): string {
					return 'kadence';
				}
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $key ) use ( $kadence_palette ) {
				if ( 'kadence_global_palette' === $key ) {
					return $kadence_palette;
				}
				return false;
			}
		);

		$palette = array(
			array( 'name' => 'Palette 1', 'slug' => 'theme-palette1', 'color' => 'var(--global-palette1)' ),
			array( 'name' => 'Palette 5', 'slug' => 'theme-palette5', 'color' => 'var(--global-palette5)' ),
			array( 'name' => 'Palette 9', 'slug' => 'theme-palette9', 'color' => 'var(--global-palette9)' ),
		);

		$result = $this->resolver->resolve_palette_var_references( $palette );

		$this->assertSame( '#3296A8', $result[0]['color'] );
		$this->assertSame( '#F5F0E8', $result[1]['color'] );
		$this->assertSame( '#3D6B5E', $result[2]['color'] );
	}

	/**
	 * Test resolve_palette_var_references passes through hex entries unchanged.
	 *
	 * @return void
	 */
	public function test_resolve_palette_var_references_passthrough_hex(): void {
		$palette = array(
			array( 'name' => 'Black', 'slug' => 'black', 'color' => '#000000' ),
			array( 'name' => 'White', 'slug' => 'white', 'color' => '#FFFFFF' ),
		);

		$result = $this->resolver->resolve_palette_var_references( $palette );

		$this->assertSame( '#000000', $result[0]['color'] );
		$this->assertSame( '#FFFFFF', $result[1]['color'] );
	}

	/**
	 * Test resolve_palette_var_references leaves unresolvable var() as-is.
	 *
	 * @return void
	 */
	public function test_resolve_palette_var_references_unknown_var(): void {
		Functions\when( 'wp_get_theme' )->justReturn(
			new class {
				public function get_template(): string {
					return 'unknown-theme';
				}
			}
		);

		$palette = array(
			array( 'name' => 'Custom', 'slug' => 'custom', 'color' => 'var(--custom-color-1)' ),
			array( 'name' => 'Black', 'slug' => 'black', 'color' => '#000000' ),
		);

		$result = $this->resolver->resolve_palette_var_references( $palette );

		$this->assertSame( 'var(--custom-color-1)', $result[0]['color'] );
		$this->assertSame( '#000000', $result[1]['color'] );
	}

	// =========================================================================
	// match_palette_by_slug Tests (migrated from AssetsTest)
	// =========================================================================

	/**
	 * Test match_palette_by_slug recognizes BB theme suffix-based slugs.
	 *
	 * @return void
	 */
	public function test_match_palette_by_slug_bb_theme_suffixes(): void {
		$palette = array(
			array( 'name' => 'Heading Text', 'slug' => 'fl-heading-text', 'color' => '#333333' ),
			array( 'name' => 'Body BG', 'slug' => 'fl-body-bg', 'color' => '#ededd3' ),
			array( 'name' => 'Body Text', 'slug' => 'fl-body-text', 'color' => '#333333' ),
			array( 'name' => 'Accent', 'slug' => 'fl-accent', 'color' => '#428bca' ),
			array( 'name' => 'Accent Hover', 'slug' => 'fl-accent-hover', 'color' => '#226baa' ),
			array( 'name' => 'Content BG', 'slug' => 'fl-content-bg', 'color' => '#fffff0' ),
		);

		$result = $this->resolver->match_palette_by_slug( $palette );

		$this->assertSame( '#428bca', $result['primary'] );
		$this->assertSame( '#226baa', $result['primary_hover'] );
		$this->assertSame( '#333333', $result['text'] );
		$this->assertSame( '#ededd3', $result['background'] );
	}

	/**
	 * Test match_palette_by_slug with standard slug keywords.
	 *
	 * @return void
	 */
	public function test_match_palette_by_slug_standard_keywords(): void {
		$palette = array(
			array( 'name' => 'Primary', 'slug' => 'primary', 'color' => '#0066CC' ),
			array( 'name' => 'Contrast', 'slug' => 'contrast', 'color' => '#1A1A1A' ),
			array( 'name' => 'Base', 'slug' => 'base', 'color' => '#FFFFFF' ),
			array( 'name' => 'Secondary', 'slug' => 'secondary', 'color' => '#6B7280' ),
			array( 'name' => 'Tertiary', 'slug' => 'tertiary', 'color' => '#E5E7EB' ),
		);

		$result = $this->resolver->match_palette_by_slug( $palette );

		$this->assertSame( '#0066CC', $result['primary'] );
		$this->assertSame( '#1A1A1A', $result['text'] );
		$this->assertSame( '#FFFFFF', $result['background'] );
		$this->assertSame( '#6B7280', $result['text_muted'] );
		$this->assertSame( '#E5E7EB', $result['border'] );
	}

	// =========================================================================
	// get_template_slug_map Tests
	// =========================================================================

	/**
	 * Test get_template_slug_map returns correct map for Kadence.
	 *
	 * @return void
	 */
	public function test_get_template_slug_map_kadence(): void {
		$map = $this->resolver->get_template_slug_map( 'kadence' );

		$this->assertSame( 'primary', $map['theme-palette1'] );
		$this->assertSame( 'text', $map['theme-palette3'] );
		$this->assertSame( 'background', $map['theme-palette9'] );
	}

	/**
	 * Test get_template_slug_map returns correct map for Astra.
	 *
	 * @return void
	 */
	public function test_get_template_slug_map_astra(): void {
		$map = $this->resolver->get_template_slug_map( 'astra' );

		$this->assertSame( 'primary', $map['ast-global-color-0'] );
		$this->assertSame( 'text', $map['ast-global-color-2'] );
		$this->assertSame( 'background', $map['ast-global-color-5'] );
	}

	/**
	 * Test get_template_slug_map returns empty for unknown theme.
	 *
	 * @return void
	 */
	public function test_get_template_slug_map_unknown(): void {
		$map = $this->resolver->get_template_slug_map( 'unknown-theme' );
		$this->assertSame( array(), $map );
	}
}
