<?php
/**
 * Page context detector for NTE asset loading decisions.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Detects whether the current page contains NetterTechEvents content
 * and identifies which view types are present.
 *
 * Extracted from Assets to keep detection logic self-contained and testable.
 */
class PageContextDetector {

	/**
	 * Detected view types for current page.
	 *
	 * Supports multiple views on the same page (e.g., grid + carousel + calendar).
	 *
	 * @var array<string>
	 */
	private array $detected_views = array();

	/**
	 * Cached result of content detection.
	 *
	 * Null means not yet checked, bool is the cached result.
	 *
	 * @var bool|null
	 */
	private ?bool $has_content_cache = null;

	/**
	 * Check if current page contains NetterTechEvents content.
	 *
	 * Detects ALL view types on the page (shortcodes, blocks, archives).
	 * Result is cached to avoid repeated detection.
	 *
	 * @return bool
	 */
	public function page_has_nettertech_events_content(): bool {
		// Return cached result if already computed.
		if ( null !== $this->has_content_cache ) {
			return $this->has_content_cache;
		}

		global $post;

		// Check for single event or occurrence page via Router rewrite rules.
		// Router sets nettertech_events_event_slug / nettertech_events_occurrence_datetime query vars
		// rather than registering a custom post type.
		if ( get_query_var( 'nettertech_events_event_slug' ) || get_query_var( 'nettertech_events_occurrence_datetime' ) ) {
			$this->detected_views[] = 'single';
		}

		// Check for space detail page.
		if ( get_query_var( 'nettertech_events_space_slug' ) ) {
			$this->detected_views[] = 'single';
		}

		// Check for single event via CPT (forward compatibility).
		if ( is_singular( 'nettertech_event' ) ) {
			$this->detected_views[] = 'single';
		}

		// Check for event archive (post type archive or custom rewrite rules).
		if ( is_post_type_archive( 'nettertech_event' ) ) {
			$this->detected_views[] = 'grid';
		}

		// Check for custom archive pages via Router rewrite rules.
		// These use query vars instead of post type archive detection.
		if ( get_query_var( 'nettertech_events_archive' ) || get_query_var( 'nettertech_events_past_archive' ) ) {
			$this->detected_views[] = 'grid';
		}

		// Check post content for shortcodes and blocks.
		if ( $post instanceof \WP_Post ) {
			$shortcodes = array(
				'nettertech_events_calendar' => 'calendar',
				'nettertech_events_carousel' => 'carousel',
				'nettertech_events_grid'     => 'grid',
				'nettertech_events_list'     => 'grid',
				'nettertech_events_regulars' => 'regulars',
				'nettertech_events_rsvp'     => 'single', // -- base.css is required for the inline RSVP styles to attach.
				'nettertech_events'          => 'grid',   // Default shortcode.
			);

			foreach ( $shortcodes as $shortcode => $view ) {
				if ( has_shortcode( $post->post_content, $shortcode ) ) {
					$this->detected_views[] = $view;
				}
			}

			// Check for Gutenberg blocks.
			$blocks = array(
				'nettertech-events/calendar' => 'calendar',
				'nettertech-events/carousel' => 'carousel',
				'nettertech-events/grid'     => 'grid',
			);

			foreach ( $blocks as $block => $view ) {
				if ( has_block( $block, $post ) ) {
					$this->detected_views[] = $view;
				}
			}
		}

		/**
		 * Filter to add additional detected views.
		 *
		 * Allows integrations (like Beaver Builder modules) to indicate
		 * they are rendering NetterTechEvents content.
		 *
		 * @param array<string> $views The detected view types.
		 */
		$this->detected_views = apply_filters( 'nettertech_events_detected_views', $this->detected_views );

		// Remove duplicates and re-index.
		$this->detected_views = array_values( array_unique( $this->detected_views ) );

		$this->has_content_cache = ! empty( $this->detected_views );
		return $this->has_content_cache;
	}

	/**
	 * Get all detected view types.
	 *
	 * @return array<string>
	 */
	public function get_detected_views(): array {
		return $this->detected_views;
	}

	/**
	 * Get the primary detected view type (first detected).
	 *
	 * Used for script localization when only one target is needed.
	 *
	 * @return string|null
	 */
	public function get_primary_view(): ?string {
		return $this->detected_views[0] ?? null;
	}

	/**
	 * Check if current admin page is a NetterTechEvents page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return bool
	 */
	public function is_nettertech_events_admin_page( string $hook_suffix ): bool {
		// Submenu hooks are prefixed with the sanitized *parent menu title* ("Events"),
		// not the parent slug. The `nettertech-events_page_*` entries below never match
		// and are retained only until their pages are confirmed unused (NTE-144).
		$venue_pages = array(
			'toplevel_page_nettertech-events',
			'events_page_nettertech-events-new',
			'events_page_nettertech-events-settings',
			'events_page_nettertech-events-check-in',
			'events_page_nettertech-events-qr-generator',
			'events_page_nettertech-events-attendees',
			'nettertech-events_page_nettertech-events-settings',
			'nettertech-events_page_nettertech-events-tickets',
			'nettertech-events_page_nettertech-events-attendees',
		);

		if ( in_array( $hook_suffix, $venue_pages, true ) ) {
			return true;
		}

		// Check for event post type screens.
		$screen = get_current_screen();
		if ( $screen && 'nettertech_event' === $screen->post_type ) {
			return true;
		}

		return false;
	}
}
