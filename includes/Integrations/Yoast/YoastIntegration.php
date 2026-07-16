<?php
/**
 * Yoast SEO Integration.
 *
 * Injects NTE Event schema into Yoast's structured data graph,
 * disabling NTE's built-in SchemaMarkup to avoid duplication.
 *
 * @package NetterTechEvents\Integrations\Yoast
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\Yoast;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Frontend\Router;
use NetterTechEvents\Integrations\SchemaBuilder;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Utilities\PathHelper;

/**
 * Yoast SEO integration for NetterTech Events.
 *
 * @since 2.1.0
 */
class YoastIntegration {

	use SchemaBuilder;

	/**
	 * Initialize the integration.
	 *
	 * Registers hooks for schema, sitemaps, breadcrumbs, and custom variables.
	 * All callbacks check WPSEO_VERSION internally since Yoast may load after NTE.
	 *
	 * @return void
	 */
	public function init(): void {
		// Schema: inject Event schema into Yoast's graph.
		add_filter( 'wpseo_schema_graph', array( $this, 'filter_schema_graph' ), 10, 2 );

		// Disable NTE's built-in SchemaMarkup when Yoast handles schema.
		add_filter( 'nettertech_events_schema_org_data', array( $this, 'disable_builtin_schema' ), 1, 1 );

		// Sitemap: register NTE events as a Yoast sitemap provider.
		add_filter( 'wpseo_sitemaps_providers', array( $this, 'register_sitemap_provider' ) );

		// Custom SEO variables: %%nettertech_events_event_date%%, %%nettertech_events_event_venue%%, %%nettertech_events_event_organizer%%.
		add_action( 'wpseo_register_extra_replacements', array( $this, 'register_custom_variables' ) );

		// Breadcrumbs: inject NTE event hierarchy.
		add_filter( 'wpseo_breadcrumb_links', array( $this, 'filter_breadcrumb_links' ) );
	}

	/**
	 * Filter the Yoast schema graph to add Event structured data.
	 *
	 * @param array<int, array<string, mixed>> $graph   Schema graph pieces.
	 * @param object                           $context Yoast schema context.
	 * @return array<int, array<string, mixed>> Modified graph.
	 */
	public function filter_schema_graph( array $graph, $context ): array {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return $graph;
		}

		$event = Router::get_current_event();
		if ( ! $event || ! $event->is_published() ) {
			return $graph;
		}

		$occurrence   = Router::get_current_occurrence();
		$event_schema = $this->build_event_schema( $event, $occurrence, $context );

		if ( ! empty( $event_schema ) ) {
			$graph[] = $event_schema;
		}

		return $graph;
	}

	/**
	 * Disable NTE's built-in SchemaMarkup when Yoast is active.
	 *
	 * Returns an empty array to prevent NTE from outputting its own JSON-LD,
	 * since Yoast's schema graph now includes the Event data.
	 *
	 * @param array<string, mixed> $data Schema data from NTE.
	 * @return array<string, mixed> Empty array if Yoast is active; original data otherwise.
	 */
	public function disable_builtin_schema( array $data ): array {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return array();
		}

		return $data;
	}

	/**
	 * Register the YoastSitemapProvider with Yoast's sitemap system.
	 *
	 * @param array<int, \WPSEO_Sitemap_Provider> $providers Existing providers.
	 * @return array<int, \WPSEO_Sitemap_Provider> Modified providers.
	 */
	public function register_sitemap_provider( array $providers ): array {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return $providers;
		}

		global $wpdb;
		$providers[] = new YoastSitemapProvider( $wpdb );

		return $providers;
	}

	/**
	 * Register custom SEO replacement variables for NTE events.
	 *
	 * @return void
	 */
	public function register_custom_variables(): void {
		if ( ! defined( 'WPSEO_VERSION' ) || ! function_exists( 'wpseo_register_var_replacement' ) ) {
			return;
		}

		wpseo_register_var_replacement(
			'%%nettertech_events_event_date%%',
			array( $this, 'get_variable_event_date' ),
			'advanced',
			'The next occurrence date of the current NetterTech Events event'
		);

		wpseo_register_var_replacement(
			'%%nettertech_events_event_venue%%',
			array( $this, 'get_variable_event_venue' ),
			'advanced',
			'The venue name of the current NetterTech Events event'
		);

		wpseo_register_var_replacement(
			'%%nettertech_events_event_organizer%%',
			array( $this, 'get_variable_event_organizer' ),
			'advanced',
			'The organizer of the current NetterTech Events event'
		);
	}

	/**
	 * Get the event date for the %%nettertech_events_event_date%% variable.
	 *
	 * Returns the current occurrence date if viewing an occurrence,
	 * or the next upcoming occurrence date for the event.
	 *
	 * @return string Formatted date or empty string.
	 */
	public function get_variable_event_date(): string {
		$event = Router::get_current_event();
		if ( ! $event ) {
			return '';
		}

		$occurrence = Router::get_current_occurrence();
		if ( $occurrence && $occurrence->start_datetime ) {
			return $occurrence->get_formatted_date();
		}

		return '';
	}

	/**
	 * Get the venue name for the %%nettertech_events_event_venue%% variable.
	 *
	 * @return string Venue name or empty string.
	 */
	public function get_variable_event_venue(): string {
		$event = Router::get_current_event();
		if ( ! $event || ! $event->venue_name ) {
			return '';
		}

		return $event->venue_name;
	}

	/**
	 * Get the organizer name for the %%nettertech_events_event_organizer%% variable.
	 *
	 * Falls back to the site name as the default organizer.
	 *
	 * @return string Organizer name or empty string.
	 */
	public function get_variable_event_organizer(): string {
		$event = Router::get_current_event();
		if ( ! $event ) {
			return '';
		}

		$site_name = get_bloginfo( 'name' );
		return $site_name ? $site_name : '';
	}

	/**
	 * Filter Yoast breadcrumb links to inject NTE event hierarchy.
	 *
	 * Builds: Home > Events > [Category >] Event Title [> Occurrence Date]
	 *
	 * @param array<int, array<string, mixed>> $crumbs Existing breadcrumb links.
	 * @return array<int, array<string, mixed>> Modified breadcrumb links.
	 */
	public function filter_breadcrumb_links( array $crumbs ): array {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return $crumbs;
		}

		$event = Router::get_current_event();
		if ( ! $event || ! $event->is_published() ) {
			return $crumbs;
		}

		$occurrence = Router::get_current_occurrence();

		// Build custom crumbs: keep Home (first crumb), replace the rest.
		$home_crumb = ! empty( $crumbs ) ? $crumbs[0] : array(
			'url'  => home_url( '/' ),
			'text' => __( 'Home', 'nettertech-events' ),
		);

		$custom_crumbs   = array();
		$custom_crumbs[] = $home_crumb;

		// Events listing page.
		$custom_crumbs[] = array(
			'url'  => home_url( '/' . PathHelper::get_base_path() . '/' ),
			'text' => __( 'Events', 'nettertech-events' ),
		);

		// Category (first one, if any).
		if ( ! empty( $event->category_ids ) ) {
			$term = get_term( $event->category_ids[0], 'nettertech_event_category' );
			if ( $term && ! is_wp_error( $term ) ) {
				$term_link = get_term_link( $term );
				if ( ! is_wp_error( $term_link ) ) {
					$custom_crumbs[] = array(
						'url'  => $term_link,
						'text' => $term->name,
					);
				}
			}
		}

		// Event title.
		if ( $occurrence ) {
			// Link to event/series page, then add occurrence as final crumb.
			$custom_crumbs[] = array(
				'url'  => $event->get_permalink(),
				'text' => $event->title,
			);

			$custom_crumbs[] = array(
				'url'  => $occurrence->get_url(),
				'text' => $occurrence->get_formatted_date(),
			);
		} else {
			// Final crumb (current page, no URL).
			$custom_crumbs[] = array(
				'text' => $event->title,
			);
		}

		return $custom_crumbs;
	}

	/**
	 * Build Schema.org Event data for injection into Yoast's graph.
	 *
	 * Mirrors the structure from SchemaMarkup::build_event_data() but formatted
	 * for Yoast's graph system (uses @id references for WebPage integration).
	 *
	 * @param Event           $event      Event model.
	 * @param Occurrence|null $occurrence Current occurrence.
	 * @param object          $context    Yoast schema context (provides @id for WebPage).
	 * @return array<string, mixed> Schema.org Event data.
	 */
	private function build_event_schema( Event $event, ?Occurrence $occurrence, $context ): array {
		$data = array(
			'@type'               => 'Event',
			'@id'                 => $this->get_current_url() . '#event',
			'name'                => $event->title,
			'eventAttendanceMode' => $event->get_attendance_mode(),
		);

		// Link to Yoast's WebPage piece.
		if ( isset( $context->canonical ) ) {
			$data['mainEntityOfPage'] = array( '@id' => $context->canonical . '#webpage' );
		}

		// Description.
		$description = $event->excerpt ? $event->excerpt : $event->description;
		if ( $description ) {
			$data['description'] = wp_strip_all_tags( $description );
		}

		// Dates and status from occurrence.
		if ( $occurrence ) {
			$this->add_occurrence_dates( $data, $occurrence );
			$this->add_event_status( $data, $occurrence );
		}

		// URL.
		$url = $occurrence ? $occurrence->get_url() : $event->get_permalink();
		if ( $url ) {
			$data['url'] = $url;
		}

		// Image.
		$image_url = null;
		if ( $occurrence ) {
			$image_url = $occurrence->get_featured_image_url( 'large' );
		}
		if ( ! $image_url ) {
			$image_url = $event->get_featured_image_url( 'large' );
		}
		if ( $image_url ) {
			$data['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $image_url,
			);
		}

		// Location.
		$this->add_location( $data, $event );

		// Organizer.
		$data['organizer'] = array(
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url(),
		);

		return $data;
	}

	/**
	 * Get the current request URL.
	 *
	 * @return string Current URL.
	 */
	private function get_current_url(): string {
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			return home_url( sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		}
		return home_url();
	}
}
