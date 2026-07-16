<?php
/**
 * Shortcode output escape helper.
 *
 * @package NetterTechEvents\Frontend\Shortcodes
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

/**
 * Escape boundary for shortcode HTML output.
 *
 * Shortcode render() methods return HTML strings that contain SVG icons,
 * data-* attributes consumed by frontend JS, and ARIA attributes for
 * accessibility. wp_kses_post() strips all of these by default.
 *
 * This helper provides a custom kses allowlist tailored to the markup
 * shortcodes actually emit, applied at the echo boundary. Render methods
 * may continue to build HTML using esc_* primitives internally; this
 * helper is the final defense-in-depth wrap.
 */
final class ShortcodeOutput {

	/**
	 * Build the allowed-HTML allowlist for shortcode output.
	 *
	 * Starts from wp_kses_allowed_html('post') and extends with:
	 * - SVG elements (svg, path, circle, rect, g, line)
	 * - data-* attributes used by NTE frontend JS
	 * - ARIA attributes used for accessibility
	 * - <time>, <section>, <article>, <header>, <nav>, <button> structural tags
	 *
	 * @return array<string, array<string, bool>> kses allowlist.
	 */
	public static function get_allowlist(): array {
		$allowed = wp_kses_allowed_html( 'post' );

		// SVG icon support.
		$svg_attrs         = array(
			'class'           => true,
			'width'           => true,
			'height'          => true,
			'viewBox'         => true,
			'viewbox'         => true,
			'xmlns'           => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'aria-hidden'     => true,
			'aria-label'      => true,
			'role'            => true,
			'focusable'       => true,
		);
		$allowed['svg']    = $svg_attrs;
		$allowed['path']   = array(
			'd'      => true,
			'fill'   => true,
			'stroke' => true,
			'class'  => true,
		);
		$allowed['circle'] = array(
			'cx'     => true,
			'cy'     => true,
			'r'      => true,
			'fill'   => true,
			'stroke' => true,
			'class'  => true,
		);
		$allowed['rect']   = array(
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
			'rx'     => true,
			'ry'     => true,
			'fill'   => true,
			'stroke' => true,
			'class'  => true,
		);
		$allowed['g']      = array(
			'fill'   => true,
			'stroke' => true,
			'class'  => true,
		);
		$allowed['line']   = array(
			'x1'     => true,
			'y1'     => true,
			'x2'     => true,
			'y2'     => true,
			'stroke' => true,
			'class'  => true,
		);
		$allowed['use']    = array(
			'href'       => true,
			'xlink:href' => true,
		);

		// Structural and interactive tags used by NTE shortcodes and the
		// ticket purchase form (templates/parts/ticket-form.php).
		$nte_data_attrs = array(
			'data-ajax'          => true,
			'data-autoplay'      => true,
			'data-available'     => true,
			'data-columns'       => true,
			'data-currency'      => true,
			'data-date'          => true,
			'data-event-id'      => true,
			'data-hour'          => true,
			'data-initial-date'  => true,
			'data-instance'      => true,
			'data-interval'      => true,
			'data-layout'        => true,
			'data-max-per-order' => true,
			'data-min-per-order' => true,
			'data-name'          => true,
			'data-occurrence-id' => true,
			'data-past'          => true,
			'data-per-page'      => true,
			'data-price'         => true,
			'data-target'        => true,
			'data-text-none'     => true,
			'data-text-plural'   => true,
			'data-text-single'   => true,
			'data-ticket-id'     => true,
			'data-view'          => true,
		);

		$aria_attrs = array(
			'aria-label'       => true,
			'aria-labelledby'  => true,
			'aria-describedby' => true,
			'aria-hidden'      => true,
			'aria-live'        => true,
			'aria-atomic'      => true,
			'aria-disabled'    => true,
			'aria-required'    => true,
			'aria-invalid'     => true,
			'aria-controls'    => true,
			'aria-expanded'    => true,
			'aria-current'     => true,
			'aria-selected'    => true,
			'aria-pressed'     => true,
			'role'             => true,
			'tabindex'         => true,
		);

		// Extend structural tags with data-* and ARIA attributes.
		$structural_tags = array( 'div', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside' );
		foreach ( $structural_tags as $tag ) {
			$allowed[ $tag ] = array_merge(
				$allowed[ $tag ] ?? array(),
				array(
					'class' => true,
					'id'    => true,
				),
				$nte_data_attrs,
				$aria_attrs
			);
		}

		// Interactive tags.
		$allowed['button'] = array_merge(
			$allowed['button'] ?? array(),
			array(
				'class'    => true,
				'id'       => true,
				'type'     => true,
				'name'     => true,
				'value'    => true,
				'disabled' => true,
			),
			$nte_data_attrs,
			$aria_attrs
		);

		$allowed['a'] = array_merge(
			$allowed['a'] ?? array(),
			array(
				'href'   => true,
				'class'  => true,
				'id'     => true,
				'target' => true,
				'rel'    => true,
				'title'  => true,
			),
			$nte_data_attrs,
			$aria_attrs
		);

		// Semantic time element.
		$allowed['time'] = array_merge(
			$allowed['time'] ?? array(),
			array(
				'datetime' => true,
				'class'    => true,
				'id'       => true,
			),
			$nte_data_attrs,
			$aria_attrs
		);

		// Form controls used in filter UIs and the ticket purchase form
		// (templates/parts/ticket-form.php — renders inside TicketDisplay,
		// whose output is filtered through this allowlist).
		$allowed['form']   = array_merge(
			array(
				'action'          => true,
				'method'          => true,
				'class'           => true,
				'id'              => true,
				'name'            => true,
				'enctype'         => true,
				'accept-charset'  => true,
				'novalidate'      => true,
				'data-occurrence' => true,
				'data-event'      => true,
			),
			$aria_attrs
		);
		$allowed['select'] = array(
			'class'                      => true,
			'id'                         => true,
			'name'                       => true,
			'multiple'                   => true,
			'size'                       => true,
			'aria-label'                 => true,
			'aria-labelledby'            => true,
			// NTE-075: multi-select disclosure widget hook for the enhancement JS.
			'data-nte-multiselect-label' => true,
		);
		$allowed['option'] = array(
			'value'    => true,
			'selected' => true,
			'disabled' => true,
		);
		$allowed['input']  = array_merge(
			array(
				'type'         => true,
				'class'        => true,
				'id'           => true,
				'name'         => true,
				'value'        => true,
				'placeholder'  => true,
				'disabled'     => true,
				'readonly'     => true,
				'required'     => true,
				'checked'      => true,
				'min'          => true,
				'max'          => true,
				'step'         => true,
				'pattern'      => true,
				'inputmode'    => true,
				'autocomplete' => true,
			),
			$nte_data_attrs,
			$aria_attrs
		);
		$allowed['label']  = array(
			'for'   => true,
			'class' => true,
			'id'    => true,
		);
		// Completeness for extension listeners on the occurrence-actions
		// surface (occurrence-row.php merges this allowlist over the post
		// defaults); no first-party emitter uses these today.
		$allowed['textarea'] = array_merge(
			array(
				'name'        => true,
				'rows'        => true,
				'cols'        => true,
				'required'    => true,
				'disabled'    => true,
				'readonly'    => true,
				'placeholder' => true,
				'maxlength'   => true,
				'class'       => true,
				'id'          => true,
			),
			$aria_attrs
		);
		$allowed['fieldset'] = array(
			'disabled' => true,
			'name'     => true,
			'class'    => true,
			'id'       => true,
		);
		$allowed['legend']   = array(
			'class' => true,
			'id'    => true,
		);
		$allowed['optgroup'] = array(
			'label'    => true,
			'disabled' => true,
		);
		$allowed['polyline'] = array(
			'points'       => true,
			'fill'         => true,
			'stroke'       => true,
			'stroke-width' => true,
		);
		$allowed['polygon']  = array(
			'points'       => true,
			'fill'         => true,
			'stroke'       => true,
			'stroke-width' => true,
		);

		// Span and inline elements extended with data-* (used for date pieces, status pills).
		$inline_tags = array( 'span', 'strong', 'em', 'small' );
		foreach ( $inline_tags as $tag ) {
			$allowed[ $tag ] = array_merge(
				$allowed[ $tag ] ?? array(),
				array(
					'class' => true,
					'id'    => true,
				),
				$nte_data_attrs,
				$aria_attrs
			);
		}

		// oEmbed video iframes (YouTube, Vimeo, etc.). Permitting <iframe> here
		// is what lets event-description videos survive output sanitization.
		// It is safe in this allowlist because the only iframes that can reach
		// this point come from WordPress's oEmbed auto-embed, whose providers
		// are a trusted, core-maintained allowlist: author-supplied raw <iframe>
		// markup is already stripped at the input boundary (wp_kses_post in
		// EventSaveHandler), and the public CSP frame-src directive further
		// restricts which origins the browser will actually load.
		$allowed['iframe'] = array(
			'src'             => true,
			'width'           => true,
			'height'          => true,
			'frameborder'     => true,
			'allow'           => true,
			'allowfullscreen' => true,
			'referrerpolicy'  => true,
			'loading'         => true,
			'title'           => true,
			'name'            => true,
			'class'           => true,
			'style'           => true,
			'sandbox'         => true,
		);

		return $allowed;
	}
}
