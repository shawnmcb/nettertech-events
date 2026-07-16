<?php
/**
 * Event List Module Frontend.
 *
 * Uses direct class invocation for better performance.
 *
 * @since 0.8.0
 *
 * @package NetterTechEvents\Integrations\BeaverBuilder\Modules\EventList
 *
 * @var object $module   Module instance.
 * @var object $settings Module settings.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;


use NetterTechEvents\Frontend\Shortcodes\EventListShortcode;

// Allowed values for strict validation.
$nettertech_events_bb_allowed_layouts = array( 'grid', 'list', 'cards' );

// Build attributes array matching shortcode defaults.
$nettertech_events_bb_layout = sanitize_key( $settings->layout ?? 'grid' );
$nettertech_events_bb_atts   = array(
	// Layout settings (with allowlist validation).
	'layout'        => in_array( $nettertech_events_bb_layout, $nettertech_events_bb_allowed_layouts, true ) ? $nettertech_events_bb_layout : 'grid',
	'columns'       => min( absint( $settings->columns ?? 3 ), 6 ),
	'limit'         => min( absint( $settings->limit ?? 12 ), 100 ),

	// Filter settings.
	'show_filters'  => ! empty( $settings->show_filters ) && '1' === $settings->show_filters,
	'show_search'   => ! empty( $settings->show_search ) && '1' === $settings->show_search,
	'show_category' => ! empty( $settings->show_category ) && '1' === $settings->show_category,
	'category'      => sanitize_text_field( $settings->category ?? '' ),
	'past'          => ! empty( $settings->past ) && '1' === $settings->past,

	// Display settings.
	'show_image'    => ! empty( $settings->show_image ) && '1' === $settings->show_image,
	'show_date'     => ! empty( $settings->show_date ) && '1' === $settings->show_date,
	'show_time'     => ! empty( $settings->show_time ) && '1' === $settings->show_time,
	'show_venue'    => ! empty( $settings->show_venue ) && '1' === $settings->show_venue,
	'show_excerpt'  => ! empty( $settings->show_excerpt ) && '1' === $settings->show_excerpt,

	// Pagination settings.
	'pagination'    => ! empty( $settings->pagination ) && '1' === $settings->pagination,
	'ajax'          => ! empty( $settings->ajax ) && '1' === $settings->ajax,

	// Image display settings.
	'image_ratio'   => sanitize_text_field( $settings->image_ratio ?? '' ),
);

// Render directly via shortcode class from the DI container.
$nettertech_events_bb_shortcode = \NetterTechEvents\nettertech_events_container()->get( EventListShortcode::class );
echo wp_kses(
	$nettertech_events_bb_shortcode->render( $nettertech_events_bb_atts ),
	\NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput::get_allowlist()
);
