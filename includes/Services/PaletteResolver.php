<?php
/**
 * Palette resolver — maps a theme's editor color palette to VE semantic tokens.
 *
 * Extracts palette detection, var() resolution, slug/template/luminance matching,
 * and WCAG contrast validation from Assets so the logic can be shared with the
 * Theme Colors settings section preview.
 *
 * @package NetterTechEvents\Services
 * @since   1.6.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Utilities\ColorUtility;

/**
 * Resolves the active theme's editor color palette into VE semantic color roles.
 */
class PaletteResolver {

	/**
	 * Resolve the theme palette to semantic color roles.
	 *
	 * Top-level orchestrator: detects the theme palette, resolves var()
	 * references, maps to roles, and validates contrast.
	 *
	 * @since 1.6.0
	 *
	 * @return array<string, string> Role => hex color pairs. Roles: primary,
	 *                               primary_hover, text, text_muted, border,
	 *                               background, background_alt.
	 */
	public function resolve(): array {
		/**
		 * Filter to provide a custom palette map, bypassing auto-detection.
		 *
		 * @since 1.0.2
		 *
		 * @param array<string, string> $palette_map Role => hex color pairs.
		 */
		$palette_map = apply_filters( 'nettertech_events_palette_map', array() );

		$palette = array();
		if ( empty( $palette_map ) ) {
			$palette     = $this->get_theme_palette();
			$palette     = $this->resolve_palette_var_references( $palette );
			$palette_map = ! empty( $palette ) ? $this->map_palette_to_roles( $palette ) : array();
		}

		// Validate contrast between auto-detected roles.
		if ( ! empty( $palette_map ) && ! empty( $palette ) ) {
			$palette_map = $this->validate_palette_contrast( $palette_map, $palette );
		}

		return $palette_map;
	}

	/**
	 * Get the active theme's editor color palette.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array{name: string, slug: string, color: string}> Palette entries.
	 */
	public function get_theme_palette(): array {
		$palette = get_theme_support( 'editor-color-palette' );

		if ( ! empty( $palette ) && is_array( $palette ) ) {
			// get_theme_support() wraps the result in an extra array.
			if ( isset( $palette[0] ) && is_array( $palette[0] ) ) {
				$palette = $palette[0];
			}
			return $palette;
		}

		// Fallback: try wp_get_global_settings for block themes.
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$global = wp_get_global_settings( array( 'color', 'palette', 'theme' ) );
			if ( ! empty( $global ) && is_array( $global ) ) {
				return $global;
			}
		}

		return array();
	}

	/**
	 * Resolve var() CSS references in palette color values to hex.
	 *
	 * Reads theme-specific customizer options to resolve CSS custom property
	 * references (e.g. var(--global-palette1)) to their actual hex values.
	 *
	 * @since 1.5.0
	 *
	 * @param array<int, array{name: string, slug: string, color: string}> $palette Theme palette.
	 * @return array<int, array{name: string, slug: string, color: string}> Palette with resolved colors.
	 */
	public function resolve_palette_var_references( array $palette ): array {
		$has_var = false;
		foreach ( $palette as $entry ) {
			if ( 0 === strpos( (string) ( $entry['color'] ?? '' ), 'var(' ) ) {
				$has_var = true;
				break;
			}
		}

		if ( ! $has_var ) {
			return $palette;
		}

		$template = wp_get_theme()->get_template();

		foreach ( $palette as $index => $entry ) {
			$color = $entry['color'] ?? '';
			if ( 0 !== strpos( $color, 'var(' ) ) {
				continue;
			}

			$resolved = $this->resolve_single_var( $color, $template );
			if ( null !== $resolved ) {
				$palette[ $index ]['color'] = $resolved;
			}
		}

		return $palette;
	}

	/**
	 * Resolve a single var() CSS reference to its hex value.
	 *
	 * @since 1.5.0
	 *
	 * @param string $var_ref  CSS var() reference (e.g. "var(--global-palette1)").
	 * @param string $template Theme template slug.
	 * @return string|null Resolved hex color, or null if unresolvable.
	 */
	public function resolve_single_var( string $var_ref, string $template ): ?string {
		// Extract the custom property name from var(--name).
		if ( ! preg_match( '/var\(\s*--([^)]+)\s*\)/', $var_ref, $matches ) ) {
			return null;
		}
		$prop = trim( $matches[1] );

		// Kadence: var(--global-palette1) through var(--global-palette9).
		if ( 0 === strpos( $template, 'kadence' ) && preg_match( '/^global-palette(\d)$/', $prop, $slot ) ) {
			return $this->resolve_kadence_palette_var( (int) $slot[1] );
		}

		// Astra: var(--ast-global-color-0) through var(--ast-global-color-N).
		if ( 'astra' === $template && preg_match( '/^ast-global-color-(\d+)$/', $prop, $slot ) ) {
			return $this->resolve_astra_palette_var( (int) $slot[1] );
		}

		return null;
	}

	/**
	 * Resolve a Kadence palette slot number to its hex color.
	 *
	 * @since 1.5.0
	 *
	 * @param int $slot Palette slot (1-9).
	 * @return string|null Hex color or null.
	 */
	public function resolve_kadence_palette_var( int $slot ): ?string {
		$option = get_option( 'kadence_global_palette' );
		if ( empty( $option ) ) {
			return null;
		}

		$data = is_string( $option ) ? json_decode( $option, true ) : $option;
		if ( ! is_array( $data ) ) {
			return null;
		}

		// Kadence palette structure: active key selects which palette array to use.
		$active = $data['active'] ?? 'palette';
		$colors = $data[ $active ] ?? array();
		$index  = $slot - 1; // Slots are 1-based, array is 0-based.

		if ( ! isset( $colors[ $index ]['color'] ) ) {
			return null;
		}

		$color = $colors[ $index ]['color'];
		return is_string( $color ) && '' !== $color ? $color : null;
	}

	/**
	 * Resolve an Astra global color slot to its hex color.
	 *
	 * @since 1.5.0
	 *
	 * @param int $slot Color slot (0-based).
	 * @return string|null Hex color or null.
	 */
	public function resolve_astra_palette_var( int $slot ): ?string {
		$settings = get_option( 'astra-settings' );
		if ( ! is_array( $settings ) ) {
			return null;
		}

		$colors = $settings['global-color-palette']['palette'] ?? array();
		if ( ! isset( $colors[ $slot ] ) ) {
			return null;
		}

		$color = $colors[ $slot ];
		return is_string( $color ) && '' !== $color ? $color : null;
	}

	/**
	 * Map a theme palette to VE semantic color roles.
	 *
	 * Applies three strategies in order of priority:
	 * 1. Slug-based semantic matching (primary, accent, contrast, etc.)
	 * 2. Template-aware positional mapping (Kadence, Astra, GeneratePress)
	 * 3. Luminance heuristic fallback
	 *
	 * @since 1.4.0
	 *
	 * @param array<int, array{name: string, slug: string, color: string}> $palette Theme palette.
	 * @return array<string, string> Role => hex color pairs.
	 */
	public function map_palette_to_roles( array $palette ): array {
		// Strategy 1: Slug-based semantic matching.
		$map = $this->match_palette_by_slug( $palette );
		if ( ! empty( $map['primary'] ) ) {
			return $map;
		}

		// Strategy 2: Template-aware positional mapping.
		$template_map = $this->match_palette_by_template( $palette );
		if ( ! empty( $template_map['primary'] ) ) {
			return array_merge( $map, $template_map );
		}

		// Strategy 3: Luminance heuristic fallback.
		return array_merge( $map, $this->match_palette_by_luminance( $palette ) );
	}

	/**
	 * Match palette colors by semantic slug keywords.
	 *
	 * @since 1.4.0
	 *
	 * @param array<int, array{name: string, slug: string, color: string}> $palette Theme palette.
	 * @return array<string, string> Role => hex color pairs.
	 */
	public function match_palette_by_slug( array $palette ): array {
		$slug_to_role = array(
			'primary'      => 'primary',
			'accent-hover' => 'primary_hover',
			'accent'       => 'primary',
			'contrast'     => 'text',
			'foreground'   => 'text',
			'body-text'    => 'text',
			'heading-text' => 'text',
			'base'         => 'background',
			'background'   => 'background',
			'content-bg'   => 'background',
			'body-bg'      => 'background',
			'secondary'    => 'text_muted',
			'tertiary'     => 'border',
		);

		$map = array();
		foreach ( $palette as $entry ) {
			$slug  = strtolower( $entry['slug'] ?? '' );
			$color = $entry['color'] ?? '';
			if ( empty( $slug ) || empty( $color ) ) {
				continue;
			}

			foreach ( $slug_to_role as $keyword => $role ) {
				if ( ! isset( $map[ $role ] ) && false !== strpos( $slug, $keyword ) ) {
					$map[ $role ] = $color;
					break;
				}
			}
		}

		return $map;
	}

	/**
	 * Match palette colors by positional index for known theme templates.
	 *
	 * @since 1.4.0
	 *
	 * @param array<int, array{name: string, slug: string, color: string}> $palette Theme palette.
	 * @return array<string, string> Role => hex color pairs.
	 */
	public function match_palette_by_template( array $palette ): array {
		$template     = wp_get_theme()->get_template();
		$template_map = $this->get_template_slug_map( $template );
		if ( empty( $template_map ) ) {
			return array();
		}

		$indexed = array();
		foreach ( $palette as $entry ) {
			$slug  = $entry['slug'] ?? '';
			$color = $entry['color'] ?? '';
			if ( ! empty( $slug ) && ! empty( $color ) ) {
				$indexed[ $slug ] = $color;
			}
		}

		$map = array();
		foreach ( $template_map as $slug => $role ) {
			if ( isset( $indexed[ $slug ] ) ) {
				$map[ $role ] = $indexed[ $slug ];
			}
		}

		return $map;
	}

	/**
	 * Get the palette slug-to-role mapping for a known theme template.
	 *
	 * @since 1.4.0
	 *
	 * @param string $template Theme template slug.
	 * @return array<string, string> Palette slug => role pairs.
	 */
	public function get_template_slug_map( string $template ): array {
		if ( 0 === strpos( $template, 'kadence' ) ) {
			return array(
				'theme-palette1' => 'primary',
				'theme-palette3' => 'text',
				'theme-palette5' => 'text_muted',
				'theme-palette7' => 'background_alt',
				'theme-palette9' => 'background',
			);
		}

		if ( 'astra' === $template ) {
			return array(
				'ast-global-color-0' => 'primary',
				'ast-global-color-2' => 'text',
				'ast-global-color-3' => 'text_muted',
				'ast-global-color-4' => 'background_alt',
				'ast-global-color-5' => 'background',
			);
		}

		if ( 'generatepress' === $template ) {
			return array(
				'global-color-1' => 'primary',
				'global-color-3' => 'text',
				'global-color-4' => 'text_muted',
				'global-color-5' => 'background_alt',
				'global-color-2' => 'background',
			);
		}

		return array();
	}

	/**
	 * Match palette colors by luminance and saturation heuristics.
	 *
	 * Sorts colors by relative luminance: lightest to background,
	 * darkest to text, highest-saturation mid-luminance to primary.
	 *
	 * @since 1.4.0
	 *
	 * @param array<int, array{name: string, slug: string, color: string}> $palette Theme palette.
	 * @return array<string, string> Role => hex color pairs.
	 */
	public function match_palette_by_luminance( array $palette ): array {
		$analyzed = array();
		foreach ( $palette as $entry ) {
			$color = (string) ( $entry['color'] ?? '' );
			if ( empty( $color ) ) {
				continue;
			}
			$rgb = ColorUtility::hex_to_rgb( $color );
			if ( null === $rgb ) {
				continue;
			}
			$analyzed[] = array(
				'color'      => $color,
				'luminance'  => ColorUtility::relative_luminance( $rgb[0], $rgb[1], $rgb[2] ),
				'saturation' => ColorUtility::saturation( $rgb[0], $rgb[1], $rgb[2] ),
			);
		}

		if ( count( $analyzed ) < 2 ) {
			return array();
		}

		usort(
			$analyzed,
			function ( array $a, array $b ): int {
				return $a['luminance'] <=> $b['luminance'];
			}
		);

		$map               = array();
		$map['background'] = $analyzed[ count( $analyzed ) - 1 ]['color'];
		$map['text']       = $analyzed[0]['color'];

		// Highest-saturation color in mid-luminance range for primary.
		$mid_colors = array();
		foreach ( $analyzed as $color_data ) {
			if ( $color_data['luminance'] > 0.05 && $color_data['luminance'] < 0.7 ) {
				$mid_colors[] = $color_data;
			}
		}

		if ( ! empty( $mid_colors ) ) {
			usort(
				$mid_colors,
				function ( array $a, array $b ): int {
					return $b['saturation'] <=> $a['saturation'];
				}
			);
			$map['primary'] = $mid_colors[0]['color'];
		}

		return $map;
	}

	/**
	 * Validate contrast between auto-detected palette roles.
	 *
	 * When text/background or primary/background contrast fails WCAG
	 * minimums, replaces those roles with luminance-heuristic values
	 * (darkest->text, lightest->background) which guarantee maximum contrast.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, string>                                        $map     Role => hex color pairs.
	 * @param array<int, array{name: string, slug: string, color: string}> $palette Theme palette.
	 * @return array<string, string> Corrected role => hex color pairs.
	 */
	public function validate_palette_contrast( array $map, array $palette ): array {
		$needs_text_fix    = false;
		$needs_primary_fix = false;

		// Check text <-> background contrast (WCAG AA normal text: 4.5:1).
		if ( ! empty( $map['text'] ) && ! empty( $map['background'] ) ) {
			$ratio = ColorUtility::contrast_ratio( $map['text'], $map['background'] );
			if ( $ratio < 4.5 ) {
				$needs_text_fix = true;
			}
		}

		// Check primary <-> background contrast (WCAG AA large text: 3.0:1).
		if ( ! empty( $map['primary'] ) && ! empty( $map['background'] ) ) {
			$ratio = ColorUtility::contrast_ratio( $map['primary'], $map['background'] );
			if ( $ratio < 3.0 ) {
				$needs_primary_fix = true;
			}
		}

		if ( ! $needs_text_fix && ! $needs_primary_fix ) {
			return $map;
		}

		$luminance_map = $this->match_palette_by_luminance( $palette );

		if ( empty( $luminance_map ) ) {
			return $map;
		}

		if ( $needs_text_fix ) {
			if ( ! empty( $luminance_map['text'] ) ) {
				$map['text'] = $luminance_map['text'];
			}
			if ( ! empty( $luminance_map['background'] ) ) {
				$map['background'] = $luminance_map['background'];
			}
			// Clear derived values so they get recomputed or fall through to CSS defaults.
			unset( $map['text_muted'], $map['background_alt'] );
		}

		if ( $needs_primary_fix && ! empty( $luminance_map['primary'] ) ) {
			$map['primary'] = $luminance_map['primary'];
		}

		return $map;
	}
}
