<?php
/**
 * Block patterns registration for NetterTech Events.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers block patterns and pattern categories for NetterTech Events.
 *
 * @since 1.8.0
 */
class BlockPatterns {

	/**
	 * Register block pattern category and patterns on init.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( static::class, 'register_category' ) );
		add_action( 'init', array( static::class, 'register_patterns' ) );
	}

	/**
	 * Register the nettertech-events pattern category.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	public static function register_category(): void {
		register_block_pattern_category(
			'nettertech-events',
			array(
				'label'       => __( 'NetterTech Events', 'nettertech-events' ),
				'description' => __( 'Patterns for displaying events on your site.', 'nettertech-events' ),
			)
		);
	}

	/**
	 * Register all block patterns.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	public static function register_patterns(): void {
		self::register_featured_events_pattern();
		self::register_events_page_template_pattern();
		self::register_upcoming_events_list_pattern();
		self::register_calendar_with_sidebar_pattern();
	}

	/**
	 * Register "Featured Events: Carousel + Grid" pattern.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	private static function register_featured_events_pattern(): void {
		register_block_pattern(
			'nettertech-events/featured-events-carousel-grid',
			array(
				'title'       => __( 'Featured Events: Carousel + Grid', 'nettertech-events' ),
				'description' => __( 'A featured carousel above a filterable event grid — ideal for homepages and landing pages.', 'nettertech-events' ),
				'categories'  => array( 'nettertech-events' ),
				'keywords'    => array( 'events', 'carousel', 'grid', 'featured' ),
				'content'     => '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->'
					. '<div class="wp-block-group alignfull">'
					. '<!-- wp:heading {"textAlign":"center","level":2} -->'
					. '<h2 class="wp-block-heading has-text-align-center">' . esc_html__( 'Featured Events', 'nettertech-events' ) . '</h2>'
					. '<!-- /wp:heading -->'
					. '<!-- wp:nettertech-events/carousel {"limit":6,"columns":3,"showImage":true,"showDate":true,"showTime":true,"showVenue":true} /-->'
					. '<!-- wp:separator {"className":"is-style-wide"} -->'
					. '<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/>'
					. '<!-- /wp:separator -->'
					. '<!-- wp:heading {"textAlign":"center","level":3} -->'
					. '<h3 class="wp-block-heading has-text-align-center">' . esc_html__( 'All Events', 'nettertech-events' ) . '</h3>'
					. '<!-- /wp:heading -->'
					. '<!-- wp:nettertech-events/grid {"limit":12,"columns":3,"layout":"grid","showFilters":true,"showSearch":true} /-->'
					. '</div>'
					. '<!-- /wp:group -->',
			)
		);
	}

	/**
	 * Register "Events Page Template" pattern.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	private static function register_events_page_template_pattern(): void {
		register_block_pattern(
			'nettertech-events/events-page-template',
			array(
				'title'       => __( 'Events Page Template', 'nettertech-events' ),
				'description' => __( 'A complete events page with heading, description, and a filterable event grid.', 'nettertech-events' ),
				'categories'  => array( 'nettertech-events' ),
				'keywords'    => array( 'events', 'page', 'template', 'grid' ),
				'content'     => '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->'
					. '<div class="wp-block-group alignfull">'
					. '<!-- wp:heading {"textAlign":"center","level":1} -->'
					. '<h1 class="wp-block-heading has-text-align-center">' . esc_html__( 'Events', 'nettertech-events' ) . '</h1>'
					. '<!-- /wp:heading -->'
					. '<!-- wp:paragraph {"align":"center"} -->'
					. '<p class="has-text-align-center">' . esc_html__( 'Browse all upcoming events. Filter by category or search by keyword.', 'nettertech-events' ) . '</p>'
					. '<!-- /wp:paragraph -->'
					. '<!-- wp:spacer {"height":"24px"} -->'
					. '<div style="height:24px" aria-hidden="true" class="wp-block-spacer"></div>'
					. '<!-- /wp:spacer -->'
					. '<!-- wp:nettertech-events/grid {"limit":12,"columns":3,"layout":"grid","showFilters":true,"showSearch":true,"showCategory":true,"showImage":true,"showDate":true,"showTime":true,"showVenue":true,"pagination":true} /-->'
					. '</div>'
					. '<!-- /wp:group -->',
			)
		);
	}

	/**
	 * Register "Upcoming Events List" pattern.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	private static function register_upcoming_events_list_pattern(): void {
		register_block_pattern(
			'nettertech-events/upcoming-events-list',
			array(
				'title'       => __( 'Upcoming Events List', 'nettertech-events' ),
				'description' => __( 'A compact list of upcoming events, suitable for sidebars or content sections.', 'nettertech-events' ),
				'categories'  => array( 'nettertech-events' ),
				'keywords'    => array( 'events', 'list', 'upcoming', 'sidebar' ),
				'content'     => '<!-- wp:group {"layout":{"type":"constrained"}} -->'
					. '<div class="wp-block-group">'
					. '<!-- wp:heading {"level":3} -->'
					. '<h3 class="wp-block-heading">' . esc_html__( 'Upcoming Events', 'nettertech-events' ) . '</h3>'
					. '<!-- /wp:heading -->'
					. '<!-- wp:nettertech-events/grid {"limit":5,"columns":1,"layout":"list","showFilters":false,"showSearch":false,"showCategory":false,"showImage":false,"showDate":true,"showTime":true,"showVenue":true,"pagination":false,"ajax":false} /-->'
					. '</div>'
					. '<!-- /wp:group -->',
			)
		);
	}

	/**
	 * Register "Event Calendar with Sidebar" pattern.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	private static function register_calendar_with_sidebar_pattern(): void {
		register_block_pattern(
			'nettertech-events/calendar-with-sidebar',
			array(
				'title'       => __( 'Event Calendar with Sidebar', 'nettertech-events' ),
				'description' => __( 'Two-column layout with a full calendar on the left and an upcoming events list on the right.', 'nettertech-events' ),
				'categories'  => array( 'nettertech-events' ),
				'keywords'    => array( 'events', 'calendar', 'sidebar', 'layout' ),
				'content'     => '<!-- wp:columns {"align":"wide"} -->'
					. '<div class="wp-block-columns alignwide">'
					. '<!-- wp:column {"width":"66.66%"} -->'
					. '<div class="wp-block-column" style="flex-basis:66.66%">'
					. '<!-- wp:heading {"level":2} -->'
					. '<h2 class="wp-block-heading">' . esc_html__( 'Event Calendar', 'nettertech-events' ) . '</h2>'
					. '<!-- /wp:heading -->'
					. '<!-- wp:nettertech-events/calendar {"view":"month","showViewSwitcher":true,"showNavigation":true} /-->'
					. '</div>'
					. '<!-- /wp:column -->'
					. '<!-- wp:column {"width":"33.33%"} -->'
					. '<div class="wp-block-column" style="flex-basis:33.33%">'
					. '<!-- wp:heading {"level":3} -->'
					. '<h3 class="wp-block-heading">' . esc_html__( 'Upcoming Events', 'nettertech-events' ) . '</h3>'
					. '<!-- /wp:heading -->'
					. '<!-- wp:nettertech-events/grid {"limit":5,"columns":1,"layout":"list","showFilters":false,"showSearch":false,"showImage":false,"showDate":true,"showTime":true,"showVenue":true,"pagination":false,"ajax":false} /-->'
					. '</div>'
					. '<!-- /wp:column -->'
					. '</div>'
					. '<!-- /wp:columns -->',
			)
		);
	}
}
