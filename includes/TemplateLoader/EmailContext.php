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
		$value = $this->get( 'accent_color', '#333333' );
		return is_string( $value ) && '' !== $value ? $value : '#333333';
	}
}
