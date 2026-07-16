<?php
/**
 * Inline CSS generator for NTE theme integration and settings.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Services\PaletteResolver;
use NetterTechEvents\Utilities\ColorUtility;

/**
 * Generates and injects inline CSS for theme integration and plugin settings.
 *
 * Handles theme container-width overrides, palette color token mapping,
 * image aspect-ratio variables, and date-badge color. All output is attached
 * to the 'nettertech-events-base' style handle via wp_add_inline_style().
 *
 * Extracted from Assets to keep CSS generation logic self-contained and testable.
 */
class InlineCssGenerator {

	/**
	 * Palette resolver for theme color detection.
	 *
	 * @var PaletteResolver
	 */
	private PaletteResolver $palette_resolver;

	/**
	 * Plugin settings DTO.
	 *
	 * @var NetterTechEventsSettings
	 */
	private NetterTechEventsSettings $settings;

	/**
	 * Constructor.
	 *
	 * @param PaletteResolver          $palette_resolver Palette resolver.
	 * @param NetterTechEventsSettings $settings         Plugin settings.
	 */
	public function __construct( PaletteResolver $palette_resolver, NetterTechEventsSettings $settings ) {
		$this->palette_resolver = $palette_resolver;
		$this->settings         = $settings;
	}

	/**
	 * Add theme-specific CSS variable overrides.
	 *
	 * Detects popular themes and injects CSS to match their container widths.
	 *
	 * @return void
	 */
	public function add_theme_overrides(): void {
		$css_overrides = array();

		$theme    = wp_get_theme();
		$template = $theme->get_template();

		// Beaver Builder Theme - get max content width from theme settings.
		if ( class_exists( 'FLTheme' ) || 'bb-theme' === $template ) {
			$bb_max_width = get_theme_mod( 'fl-content-width', '1020' );
			if ( $bb_max_width ) {
				$css_overrides['--nte-container-max-width'] = $bb_max_width . 'px';
			}
		} elseif ( 'astra' === $template || class_exists( 'Astra_Theme_Options' ) ) {
			// Astra - get container width from options.
			$astra_width = astra_get_option( 'site-content-width', 1200 );
			if ( $astra_width ) {
				$css_overrides['--nte-container-max-width'] = $astra_width . 'px';
			}
		} elseif ( 'generatepress' === $template || function_exists( 'generate_get_option' ) ) {
			// GeneratePress - get container width.
			$gp_width = function_exists( 'generate_get_option' )
				? generate_get_option( 'container_width' )
				: 1100;
			if ( $gp_width ) {
				$css_overrides['--nte-container-max-width'] = $gp_width . 'px';
			}
		}

		/**
		 * Filter CSS variable overrides for theme integration.
		 *
		 * Allows themes to provide their own container width and other overrides.
		 *
		 * @since 1.0.2
		 *
		 * @param array  $css_overrides Array of CSS variable => value pairs.
		 * @param string $template      The active theme template.
		 */
		$css_overrides = apply_filters( 'nettertech_events_css_overrides', $css_overrides, $template );

		if ( empty( $css_overrides ) ) {
			return;
		}

		// Build inline CSS.
		$css_vars = array();
		foreach ( $css_overrides as $property => $value ) {
			$css_vars[] = esc_attr( $property ) . ': ' . esc_attr( $value );
		}

		$inline_css = ':root { ' . implode( '; ', $css_vars ) . '; }';

		wp_add_inline_style( 'nettertech-events-base', $inline_css );
	}

	/**
	 * Add palette overrides from the active theme's editor color palette.
	 *
	 * Detects the theme's registered colors and maps them to VE semantic
	 * color tokens. Admin-configured overrides take priority over auto-detection.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function add_palette_overrides(): void {
		$palette_map = $this->palette_resolver->resolve();

		// Admin overrides take priority (even when auto-detection finds nothing).
		$theme_colors = $this->settings->display->theme_colors;
		if ( ! empty( $theme_colors['customize'] ) ) {
			$role_keys = array( 'primary', 'primary_hover', 'text', 'text_muted', 'border', 'background', 'background_alt' );
			foreach ( $role_keys as $role ) {
				if ( ! empty( $theme_colors[ $role ] ) ) {
					$palette_map[ $role ] = $theme_colors[ $role ];
				}
			}
		}

		if ( empty( $palette_map ) ) {
			return;
		}

		// Compute derived values when not explicitly set.
		if ( ! empty( $palette_map['primary'] ) && empty( $palette_map['primary_hover'] ) ) {
			$palette_map['primary_hover'] = ColorUtility::adjust_brightness( $palette_map['primary'], -15 );
		}
		if ( ! empty( $palette_map['background'] ) && empty( $palette_map['background_alt'] ) ) {
			$palette_map['background_alt'] = ColorUtility::adjust_brightness( $palette_map['background'], -5 );
		}

		// Map roles to CSS custom properties.
		$role_to_property = array(
			'primary'        => '--nte-color-primary',
			'primary_hover'  => '--nte-color-primary-hover',
			'text'           => '--nte-color-text',
			'text_muted'     => '--nte-color-text-muted',
			'border'         => '--nte-color-border',
			'background'     => '--nte-color-background',
			'background_alt' => '--nte-color-background-alt',
		);

		$css_vars = array();
		foreach ( $role_to_property as $role => $property ) {
			if ( ! empty( $palette_map[ $role ] ) ) {
				$css_vars[] = esc_attr( $property ) . ': ' . esc_attr( $palette_map[ $role ] );
			}
		}

		if ( empty( $css_vars ) ) {
			return;
		}

		$inline_css = ':root { ' . implode( '; ', $css_vars ) . '; }';
		wp_add_inline_style( 'nettertech-events-base', $inline_css );
	}

	/**
	 * Add image aspect ratio CSS custom properties.
	 *
	 * Injects CSS variables for configurable image ratios based on plugin settings.
	 * Supports site-wide default and per-view overrides.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function add_image_ratio_styles(): void {
		$display   = $this->settings->display;
		$sanitizer = new \NetterTechEvents\Admin\SettingsSanitizer();

		// Get site default.
		$default_preset = $display->image_aspect_ratio;
		$default_custom = $display->image_aspect_ratio_custom;
		$default_css    = $sanitizer->get_aspect_ratio_css( $default_preset, $default_custom );

		// If no valid default, use fallback.
		if ( empty( $default_css ) ) {
			$default_css = '16 / 9';
		}

		$css_vars = array(
			'--nte-image-ratio-default' => $default_css,
		);

		// Get per-view overrides.
		$views = array( 'cards', 'list', 'carousel', 'calendar', 'single' );
		foreach ( $views as $view ) {
			$view_ratio = $display->get_view_aspect_ratio( $view );
			$preset     = $view_ratio['preset'];
			$custom     = $view_ratio['custom'];

			if ( '' !== $preset ) {
				$view_css = $sanitizer->get_aspect_ratio_css( $preset, $custom );
				if ( ! empty( $view_css ) ) {
					$css_vars[ '--nte-image-ratio-' . $view ] = $view_css;
				}
			}
		}

		/**
		 * Filter image ratio CSS variables.
		 *
		 * @since 1.0.2
		 *
		 * @param array<string, string> $css_vars CSS variable => value pairs.
		 */
		$css_vars = apply_filters( 'nettertech_events_image_ratio_css_vars', $css_vars );

		// Build inline CSS.
		$declarations = array();
		foreach ( $css_vars as $property => $value ) {
			$declarations[] = esc_attr( $property ) . ': ' . esc_attr( $value );
		}

		$inline_css = ':root { ' . implode( '; ', $declarations ) . '; }';

		wp_add_inline_style( 'nettertech-events-base', $inline_css );
	}

	/**
	 * Add date badge color CSS custom property from settings.
	 *
	 * Outputs --nte-date-bg only when a custom color is configured.
	 * Themes can override via CSS: :root { --nte-date-bg: #yourcolor; }
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function add_date_badge_styles(): void {
		$display = $this->settings->display;

		// Only apply if the custom badge color checkbox is enabled.
		if ( ! $display->date_badge_color_custom ) {
			return;
		}

		$color = $display->date_badge_color;
		if ( empty( $color ) ) {
			return;
		}

		$inline_css = ':root { --nte-date-bg: ' . esc_attr( $color ) . '; }';

		wp_add_inline_style( 'nettertech-events-base', $inline_css );
	}
}
