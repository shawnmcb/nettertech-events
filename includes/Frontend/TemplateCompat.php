<?php
/**
 * Block theme compatibility for plugin templates.
 *
 * Provides header/footer rendering that works on both classic and
 * block (FSE) themes. On block themes, renders the theme's header
 * and footer template parts alongside the HTML document structure.
 *
 * @package NetterTechEvents\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Handles block theme compatibility for VE templates.
 *
 * @since 1.0.1
 * @api
 */
class TemplateCompat {

	/**
	 * Pre-rendered footer template part HTML.
	 *
	 * Rendered in block_theme_header() alongside the header part so that
	 * both parts' generated layout styles (wp-container-*) enter the style
	 * engine store before wp_head() prints it. Echoed by block_theme_footer().
	 *
	 * @var string
	 */
	private static string $footer_html = '';

	/**
	 * Render the page header.
	 *
	 * On classic themes, delegates to get_header().
	 * On block themes, outputs the HTML document shell and renders
	 * the theme's header template part — mirroring the structure
	 * WordPress uses in template-canvas.php.
	 *
	 * @return void
	 */
	public static function header(): void {
		if ( wp_is_block_theme() && function_exists( 'block_template_part' ) ) {
			self::block_theme_header();
			return;
		}

		get_header();
	}

	/**
	 * Output HTML document start and header for block themes.
	 *
	 * Pre-renders the header template part BEFORE wp_head() so that
	 * blocks enqueue their styles/scripts in time for the <head>.
	 * This mirrors the approach WordPress core uses in template-canvas.php.
	 *
	 * @return void
	 */
	private static function block_theme_header(): void {
		// Pre-render BOTH template parts before wp_head(). Rendering blocks
		// generates per-render layout styles (wp-container-*) into the style
		// engine store, which core prints once during wp_head() (the
		// core-block-supports-inline-css tag). Parts rendered after wp_head()
		// produce markup whose layout styles are never printed — header/footer
		// appear unstyled (NTE-130, observed on TT4 child themes).
		// Rendering via the template-part block (rather than
		// block_template_part()) also restores the semantic wrapper element
		// core produces, e.g. <header class="wp-block-template-part">.
		$header_html       = self::render_template_part( 'header', 'header' );
		self::$footer_html = self::render_template_part( 'footer', 'footer' );
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
		<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
		<?php wp_body_open(); ?>
<div class="wp-site-blocks">
		<?php echo $header_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() output, escaped at source by block renderers. ?>
		<?php
	}

	/**
	 * Render a theme template part via the core template-part block.
	 *
	 * @param string $slug Template part slug (e.g. 'header', 'footer').
	 * @param string $tag  Wrapper tag name core should emit.
	 * @return string Rendered template part HTML.
	 */
	private static function render_template_part( string $slug, string $tag ): string {
		$attrs = wp_json_encode(
			array(
				'slug'    => $slug,
				'theme'   => get_stylesheet(),
				'tagName' => $tag,
			)
		);

		return do_blocks( '<!-- wp:template-part ' . $attrs . ' /-->' );
	}

	/**
	 * Render the page footer.
	 *
	 * On classic themes, delegates to get_footer().
	 * On block themes, renders the theme's footer template part,
	 * closes the document, and calls wp_footer().
	 *
	 * @return void
	 */
	public static function footer(): void {
		if ( wp_is_block_theme() && function_exists( 'block_template_part' ) ) {
			self::block_theme_footer();
			return;
		}

		get_footer();
	}

	/**
	 * Output footer and close HTML document for block themes.
	 *
	 * Echoes the footer part pre-rendered by block_theme_header(); see
	 * that method for why both parts render before wp_head(). Falls back
	 * to a direct render if header() was never called (style fidelity is
	 * degraded on that path, but content is preserved).
	 *
	 * @return void
	 */
	private static function block_theme_footer(): void {
		if ( '' === self::$footer_html ) {
			self::$footer_html = self::render_template_part( 'footer', 'footer' );
		}
		echo self::$footer_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() output, escaped at source by block renderers.
		?>
</div><!-- .wp-site-blocks -->
		<?php wp_footer(); ?>
</body>
</html>
		<?php
	}
}
