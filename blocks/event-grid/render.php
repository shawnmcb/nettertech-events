<?php
/**
 * Server-side rendering of the Event Grid block.
 *
 * @package NetterTechEvents\Blocks
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block default content.
 * @var WP_Block $block      Block instance.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$nettertech_events_shortcode = \NetterTechEvents\nettertech_events_container()->get(
	\NetterTechEvents\Frontend\Shortcodes\EventListShortcode::class
);

$nettertech_events_atts = array(
	'limit'         => $attributes['limit'] ?? 12,
	'columns'       => $attributes['columns'] ?? 3,
	'layout'        => $attributes['layout'] ?? 'grid',
	'show_filters'  => $attributes['showFilters'] ?? true,
	'show_search'   => $attributes['showSearch'] ?? true,
	'show_category' => $attributes['showCategory'] ?? true,
	'show_image'    => $attributes['showImage'] ?? true,
	'show_date'     => $attributes['showDate'] ?? true,
	'show_time'     => $attributes['showTime'] ?? true,
	'show_venue'    => $attributes['showVenue'] ?? true,
	'show_excerpt'  => $attributes['showExcerpt'] ?? false,
	'pagination'    => $attributes['pagination'] ?? true,
	'ajax'          => $attributes['ajax'] ?? true,
	'category'      => $attributes['category'] ?? '',
	'past'          => $attributes['past'] ?? false,
);

echo wp_kses(
	$nettertech_events_shortcode->render( $nettertech_events_atts ),
	\NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput::get_allowlist()
);
