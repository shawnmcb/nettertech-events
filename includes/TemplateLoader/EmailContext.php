<?php
/**
 * Email context value object.
 *
 * @package NetterTechEvents\TemplateLoader
 */

declare(strict_types=1);

namespace NetterTechEvents\TemplateLoader;

defined( 'ABSPATH' ) || exit;

/**
 * Typed wrapper specialized for email templates.
 *
 * Extends TemplateContext semantically (same property-style read
 * surface) but exists as a distinct type so email-template callers
 * can declare `EmailContext $context` in their signatures, and
 * future email-specific helpers (e.g. accent_color() with the
 * '#333333' default) have a natural home.
 *
 * @since 1.0.2
 * @api
 *
 * @extends TemplateContext<mixed>
 */
class EmailContext extends TemplateContext {

	/**
	 * Get the brand accent color, falling back to the conservative default
	 * the prior templates assigned via `$accent_color = $accent_color ?? '#333333'`.
	 *
	 * @return string
	 */
	public function accent_color(): string {
		return $this->color( 'accent_color', '#333333' );
	}

	/**
	 * Body text color (inherits WooCommerce's email text color when set).
	 *
	 * @since 1.4.7
	 *
	 * @return string
	 */
	public function text_color(): string {
		return $this->color( 'text_color', '#333333' );
	}

	/**
	 * Outer page background color.
	 *
	 * @since 1.4.7
	 *
	 * @return string
	 */
	public function background_color(): string {
		return $this->color( 'background_color', '#f7f7f7' );
	}

	/**
	 * Content-card background color.
	 *
	 * @since 1.4.7
	 *
	 * @return string
	 */
	public function body_background_color(): string {
		return $this->color( 'body_background_color', '#ffffff' );
	}

	/**
	 * Background for inset panels (per-event ticket cards) inside the body.
	 *
	 * Uses the page background so panels echo the surrounding email, but
	 * when page and card share a color (WooCommerce's modern defaults are
	 * white on white, Kadence-style stores are #f9f9f9 on #f9f9f9) the panel
	 * would vanish, so a neutral a few steps darker than either steps in.
	 *
	 * @since 1.4.7
	 *
	 * @return string
	 */
	public function panel_color(): string {
		$panel = $this->background_color();
		if ( strtolower( $panel ) === strtolower( $this->body_background_color() ) ) {
			return '#efefef';
		}

		return $panel;
	}

	/**
	 * Read a color key, falling back when unset or empty.
	 *
	 * @param string $key     Context key.
	 * @param string $fallback Fallback color.
	 * @return string
	 */
	private function color( string $key, string $fallback ): string {
		$value = $this->get( $key, $fallback );
		return is_string( $value ) && '' !== $value ? $value : $fallback;
	}
}
