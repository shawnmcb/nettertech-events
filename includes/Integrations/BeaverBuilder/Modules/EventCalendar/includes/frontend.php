<?php
/**
 * Event Calendar Module Frontend.
 *
 * Uses direct class invocation for better performance.
 *
 * @since 0.8.0
 *
 * @package NetterTechEvents\Integrations\BeaverBuilder\Modules\EventCalendar
 *
 * @var object $module   Module instance.
 * @var object $settings Module settings.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;


use NetterTechEvents\Frontend\Shortcodes\CalendarShortcode;

// Allowed values for strict validation.
$nettertech_events_bb_allowed_views = array( 'month', 'week', 'day', 'list' );

// Build attributes array matching shortcode defaults (with allowlist validation).
$nettertech_events_bb_view = sanitize_key( $settings->view ?? 'month' );
$nettertech_events_bb_atts = array(
	'view'               => in_array( $nettertech_events_bb_view, $nettertech_events_bb_allowed_views, true ) ? $nettertech_events_bb_view : 'month',
	'show_view_switcher' => ! empty( $settings->show_view_switcher ) && '1' === $settings->show_view_switcher,
	'show_navigation'    => ! empty( $settings->show_navigation ) && '1' === $settings->show_navigation,
);

// Render directly via shortcode class.
$nettertech_events_bb_shortcode = \NetterTechEvents\nettertech_events_container()->get( CalendarShortcode::class );
echo wp_kses(
	$nettertech_events_bb_shortcode->render( $nettertech_events_bb_atts ),
	\NetterTechEvents\Frontend\Shortcodes\ShortcodeOutput::get_allowlist()
);
