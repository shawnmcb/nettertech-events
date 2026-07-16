<?php
/**
 * Rank Math SEO Integration.
 *
 * Injects NTE Event schema into Rank Math's JSON-LD output,
 * registers custom SEO variables, and modifies breadcrumbs.
 *
 * @package NetterTechEvents\Integrations\RankMath
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\RankMath;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Frontend\Router;
use NetterTechEvents\Integrations\SchemaBuilder;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Utilities\PathHelper;

/**
 * Rank Math SEO integration for NetterTech Events.
 *
 * @since 2.1.0
 */
class RankMathIntegration {

	use SchemaBuilder;

	/**
	 * Initialize the integration.
	 *
	 * Registers hooks for schema, breadcrumbs, and custom variables.
	 * All callbacks check for Rank Math internally since it may load after NTE.
	 *
	 * @return void
	 */
	public function init(): void {
		// Schema: inject Event schema into Rank Math's JSON-LD.
		add_filter( 'rank_math/json_ld', array( $this, 'filter_json_ld' ), 10, 2 );

		// Disable NTE's built-in SchemaMarkup when Rank Math handles schema.
		add_filter( 'nettertech_events_schema_org_data', array( $this, 'disable_builtin_schema' ), 1, 1 );

		// Custom SEO variables: %nettertech_events_event_date%, %nettertech_events_event_venue%, %nettertech_events_event_organizer%.
		add_action( 'rank_math/vars/register_extra_replacements', array( $this, 'register_custom_variables' ) );

		// Breadcrumbs: inject NTE event hierarchy.
		add_filter( 'rank_math/frontend/breadcrumb/html', array( $this, 'filter_breadcrumb_html' ), 10, 3 );
	}

	/**
	 * Filter Rank Math's JSON-LD output to add Event structured data.
	 *
	 * @param array<string, mixed> $data   JSON-LD data keyed by schema type.
	 * @param object               $jsonld Rank Math JsonLD instance.
	 * @return array<string, mixed> Modified JSON-LD data.
	 */
	public function filter_json_ld( array $data, $jsonld ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		if ( ! $this->is_rank_math_active() ) {
			return $data;
		}

		$event = Router::get_current_event();
		if ( ! $event || ! $event->is_published() ) {
			return $data;
		}

		$occurrence   = Router::get_current_occurrence();
		$event_schema = $this->build_event_schema( $event, $occurrence );

		if ( ! empty( $event_schema ) ) {
			$data['Event'] = $event_schema;
		}

		return $data;
	}

	/**
	 * Disable NTE's built-in SchemaMarkup when Rank Math is active.
	 *
	 * @param array<string, mixed> $data Schema data from NTE.
	 * @return array<string, mixed> Empty array if Rank Math is active; original data otherwise.
	 */
	public function disable_builtin_schema( array $data ): array {
		if ( $this->is_rank_math_active() ) {
			return array();
		}

		return $data;
	}

	/**
	 * Register custom SEO replacement variables for NTE events.
	 *
	 * @return void
	 */
	public function register_custom_variables(): void {
		if ( ! $this->is_rank_math_active() || ! function_exists( 'rank_math_register_var_replacement' ) ) {
			return;
		}

		rank_math_register_var_replacement(
			'nettertech_events_event_date',
			array(
				'name'        => esc_html__( 'NTE Event Date', 'nettertech-events' ),
				'description' => esc_html__( 'The next occurrence date of the current NTE event.', 'nettertech-events' ),
				'variable'    => 'nettertech_events_event_date',
				'example'     => '',
			),
			array( $this, 'get_variable_event_date' )
		);

		rank_math_register_var_replacement(
			'nettertech_events_event_venue',
			array(
				'name'        => esc_html__( 'NTE Event Venue', 'nettertech-events' ),
				'description' => esc_html__( 'The venue name of the current NTE event.', 'nettertech-events' ),
				'variable'    => 'nettertech_events_event_venue',
				'example'     => '',
			),
			array( $this, 'get_variable_event_venue' )
		);

		rank_math_register_var_replacement(
			'nettertech_events_event_organizer',
			array(
				'name'        => esc_html__( 'NTE Event Organizer', 'nettertech-events' ),
				'description' => esc_html__( 'The organizer of the current NTE event.', 'nettertech-events' ),
				'variable'    => 'nettertech_events_event_organizer',
				'example'     => '',
			),
			array( $this, 'get_variable_event_organizer' )
		);
	}

	/**
	 * Get the event date for the %nettertech_events_event_date% variable.
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
	 * Get the venue name for the %nettertech_events_event_venue% variable.
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
	 * Get the organizer name for the %nettertech_events_event_organizer% variable.
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
	 * Filter Rank Math breadcrumb HTML to inject NTE event hierarchy.
	 *
	 * Builds: Home > Events > [Category >] Event Title [> Occurrence Date]
	 *
	 * @param string            $html              Rendered breadcrumb HTML.
	 * @param array<int, mixed> $crumbs            Breadcrumb items from Rank Math.
	 * @param object            $breadcrumb_class  Rank Math Breadcrumbs class instance.
	 * @return string Modified breadcrumb HTML.
	 */
	public function filter_breadcrumb_html( string $html, $crumbs, $breadcrumb_class ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		if ( ! $this->is_rank_math_active() ) {
			return $html;
		}

		$event = Router::get_current_event();
		if ( ! $event || ! $event->is_published() ) {
			return $html;
		}

		$occurrence = Router::get_current_occurrence();

		// Build custom breadcrumb items as [text, url] pairs.
		$custom_crumbs = array();

		// Events listing page.
		$custom_crumbs[] = array(
			esc_html__( 'Events', 'nettertech-events' ),
			home_url( '/' . PathHelper::get_base_path() . '/' ),
		);

		// Category (first one, if any).
		if ( ! empty( $event->category_ids ) ) {
			$term = get_term( $event->category_ids[0], 'nettertech_event_category' );
			if ( $term && ! is_wp_error( $term ) ) {
				$term_link = get_term_link( $term );
				if ( ! is_wp_error( $term_link ) ) {
					$custom_crumbs[] = array( $term->name, $term_link );
				}
			}
		}

		// Event title.
		if ( $occurrence ) {
			$custom_crumbs[] = array( $event->title, $event->get_permalink() );
			$custom_crumbs[] = array( $occurrence->get_formatted_date(), $occurrence->get_url() );
		} else {
			$custom_crumbs[] = array( $event->title, '' );
		}

		// Rebuild HTML: keep the wrapper, insert custom crumbs after Home.
		return $this->rebuild_breadcrumb_html( $html, $custom_crumbs );
	}

	/**
	 * Rebuild Rank Math breadcrumb HTML with custom crumbs.
	 *
	 * Inserts custom items after the Home crumb, replacing default content.
	 * Preserves the outer wrapper markup from Rank Math's rendering.
	 *
	 * @param string                         $html          Original breadcrumb HTML.
	 * @param array<int, array<int, string>> $custom_crumbs Crumbs as [text, url] pairs.
	 * @return string Modified HTML.
	 */
	private function rebuild_breadcrumb_html( string $html, array $custom_crumbs ): string {
		// Extract separator from existing HTML.
		if ( preg_match( '/<span class="separator">(.*?)<\/span>/s', $html, $sep_match ) ) {
			$separator = $sep_match[0];
		} else {
			$separator = '<span class="separator"> - </span>';
		}

		// Extract Home crumb (first link in the breadcrumb).
		if ( preg_match( '/<a href="[^"]*"[^>]*>.*?<\/a>/', $html, $home_match ) ) {
			$home_link = $home_match[0];
		} else {
			$home_link = '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'nettertech-events' ) . '</a>';
		}

		// Extract wrapper.
		$wrap_before = '<nav class="rank-math-breadcrumb"><p>';
		$wrap_after  = '</p></nav>';

		if ( preg_match( '/^(<nav[^>]*><p[^>]*>)/s', $html, $before_match ) ) {
			$wrap_before = $before_match[1];
		}
		if ( preg_match( '/(<\/p><\/nav>)\s*$/s', $html, $after_match ) ) {
			$wrap_after = $after_match[1];
		}

		// Build the new HTML.
		$parts   = array();
		$parts[] = $home_link;

		$last_index = count( $custom_crumbs ) - 1;
		foreach ( $custom_crumbs as $index => $crumb ) {
			$parts[] = $separator;

			if ( $index === $last_index || empty( $crumb[1] ) ) {
				// Last crumb or no URL: render as span (current page).
				$parts[] = '<span class="last">' . esc_html( $crumb[0] ) . '</span>';
			} else {
				$parts[] = '<a href="' . esc_url( $crumb[1] ) . '">' . esc_html( $crumb[0] ) . '</a>';
			}
		}

		return $wrap_before . implode( '', $parts ) . $wrap_after;
	}

	/**
	 * Build Schema.org Event data for Rank Math's JSON-LD output.
	 *
	 * @param Event           $event      Event model.
	 * @param Occurrence|null $occurrence Current occurrence.
	 * @return array<string, mixed> Schema.org Event data.
	 */
	private function build_event_schema( Event $event, ?Occurrence $occurrence ): array {
		$data = array(
			'@type'               => 'Event',
			'name'                => $event->title,
			'eventAttendanceMode' => $event->get_attendance_mode(),
		);

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
	 * Check if Rank Math is active.
	 *
	 * @return bool
	 */
	private function is_rank_math_active(): bool {
		return defined( 'RANK_MATH_VERSION' );
	}
}
