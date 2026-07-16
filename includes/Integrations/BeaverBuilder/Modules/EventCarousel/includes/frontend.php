<?php
/**
 * Event Carousel Module Frontend.
 *
 * Uses direct class invocation for better performance.
 *
 * @since 0.8.0
 *
 * @package NetterTechEvents\Integrations\BeaverBuilder\Modules\EventCarousel
 *
 * @var object $module   Module instance.
 * @var object $settings Module settings.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;


use NetterTechEvents\Frontend\Shortcodes\CarouselShortcode;

// Build attributes array matching shortcode defaults (with bounds validation).
$nettertech_events_bb_atts = array(
	'limit'         => min( absint( $settings->limit ?? 6 ), 50 ),
	'columns'       => min( absint( $settings->columns ?? 3 ), 6 ),
	'show_image'    => ! empty( $settings->show_image ) && '1' === $settings->show_image,
	'show_date'     => ! empty( $settings->show_date ) && '1' === $settings->show_date,
	'show_time'     => ! empty( $settings->show_time ) && '1' === $settings->show_time,
	'show_venue'    => ! empty( $settings->show_venue ) && '1' === $settings->show_venue,
	'show_year'     => ! empty( $settings->show_year ) && '1' === $settings->show_year,
	'autoplay'      => ! empty( $settings->autoplay ) && '1' === $settings->autoplay,
	'interval'      => max( 1000, min( absint( $settings->interval ?? 5000 ), 30000 ) ),
	'playback_mode' => sanitize_text_field( $settings->playback_mode ?? 'rewind' ),
	'max_tags'      => max( 0, (int) ( $settings->max_tags ?? 3 ) ),
	'image_ratio'   => sanitize_text_field( $settings->image_ratio ?? '' ),
);

// Render directly via shortcode class.
$nettertech_events_bb_shortcode = \NetterTechEvents\nettertech_events_container()->get( CarouselShortcode::class );
echo wp_kses(
	$nettertech_events_bb_shortcode->render( $nettertech_events_bb_atts ),
	\NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput::get_allowlist()
);
