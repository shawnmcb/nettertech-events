<?php
/**
 * Server-side rendering of the Calendar block.
 *
 * @package NetterTechEvents\Blocks
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block default content.
 * @var WP_Block $block      Block instance.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$nettertech_events_shortcode = \NetterTechEvents\nettertech_events_container()->get( \NetterTechEvents\Frontend\Shortcodes\CalendarShortcode::class );

$nettertech_events_atts = array(
	'view'               => $attributes['view'] ?? 'month',
	'show_view_switcher' => $attributes['showViewSwitcher'] ?? true,
	'show_navigation'    => $attributes['showNavigation'] ?? true,
);

echo wp_kses(
	$nettertech_events_shortcode->render( $nettertech_events_atts ),
	\NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput::get_allowlist()
);
