<?php
/**
 * Server-side rendering of the Carousel block.
 *
 * @package NetterTechEvents\Blocks
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block default content.
 * @var WP_Block $block      Block instance.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$nettertech_events_shortcode = \NetterTechEvents\nettertech_events_container()->get( \NetterTechEvents\Frontend\Shortcodes\CarouselShortcode::class );

$nettertech_events_atts = array(
	'limit'         => $attributes['limit'] ?? 6,
	'columns'       => $attributes['columns'] ?? 3,
	'show_image'    => $attributes['showImage'] ?? true,
	'show_date'     => $attributes['showDate'] ?? true,
	'show_time'     => $attributes['showTime'] ?? true,
	'show_venue'    => $attributes['showVenue'] ?? true,
	'show_year'     => $attributes['showYear'] ?? false,
	'autoplay'      => $attributes['autoplay'] ?? false,
	'interval'      => $attributes['interval'] ?? 5000,
	'playback_mode' => $attributes['playbackMode'] ?? 'rewind',
	'max_tags'      => $attributes['maxTags'] ?? 3,
);

echo wp_kses(
	$nettertech_events_shortcode->render( $nettertech_events_atts ),
	\NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput::get_allowlist()
);
