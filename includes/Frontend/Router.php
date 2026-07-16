<?php
/**
 * Frontend routing handler.
 *
 * @package NetterTechEvents\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Core\NetterTechEventsSettings;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Services\ICalFileWriter;
use NetterTechEvents\Services\ICalService;
use NetterTechEvents\TemplateLoader\Templates;
use NetterTechEvents\Utilities\PathHelper;

/**
 * Handles frontend routing for single event pages.
 *
 * Since events are stored in custom tables (not WordPress posts),
 * we need custom rewrite rules to handle /events/{slug}/ URLs.
 *
 * @since 0.1.0
 * @api
 */
class Router {

	/**
	 * Rewrite ruleset version.
	 *
	 * Bump whenever register_rewrite_rules() changes so existing installs
	 * flush automatically on upgrade (not just on activation). NTE-113 added
	 * the year-scoped past-archive rules.
	 *
	 * @var string
	 */
	private const REWRITE_VERSION = '2';

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * The current event being viewed.
	 *
	 * @var \NetterTechEvents\Models\Event|null
	 */
	private ?\NetterTechEvents\Models\Event $current_event = null;

	/**
	 * The current space being viewed.
	 *
	 * @var object|null
	 */
	private ?object $current_space = null;

	/**
	 * The current occurrence being viewed (for occurrence pages).
	 *
	 * @var Occurrence|null
	 */
	private ?Occurrence $current_occurrence = null;

	/**
	 * The validated year for a year-scoped past archive (NTE-113).
	 *
	 * Null when viewing the unscoped past archive or any other page.
	 *
	 * @var int|null
	 */
	private ?int $past_year = null;

	/**
	 * Whether we're viewing a series page (recurring event, no datetime).
	 *
	 * @var bool
	 */
	private bool $is_series_page = false;

	/**
	 * Ticket router for check-in and ticket scan pages (provided by Pro).
	 *
	 * @var object|null
	 */
	private ?object $ticket_router = null;

	/**
	 * Event template resolver.
	 *
	 * @var EventTemplateResolver
	 */
	private EventTemplateResolver $template_resolver;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param EventRepositoryInterface      $event_repo      Event repository.
	 * @param OccurrenceRepositoryInterface $occurrence_repo Occurrence repository.
	 * @param Templates|null                $templates       Templates service.
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		?Templates $templates = null
	) {
		$this->event_repo      = $event_repo;
		$this->occurrence_repo = $occurrence_repo;

		$this->template_resolver = new EventTemplateResolver( $templates ?? \NetterTechEvents\TemplateLoader\Templates::get_instance() );
	}

	/**
	 * Set the ticket router (injected by Pro extension).
	 *
	 * @since 1.8.0
	 *
	 * @param object $ticket_router Ticket router instance.
	 * @return void
	 */
	public function set_ticket_router( object $ticket_router ): void {
		$this->ticket_router = $ticket_router;
	}

	/**
	 * Register hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		$this->maybe_flag_rewrite_flush();
		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_filter( 'redirect_canonical', array( $this, 'prevent_ical_feed_canonical_redirect' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_ical_feed' ) );
		add_filter( 'template_include', array( $this, 'maybe_load_event_template' ) );
	}

	/**
	 * Flag a rewrite flush when the rewrite ruleset version has changed.
	 *
	 * Activation flushes directly; this covers plugin upgrades (where the
	 * activation hook does not re-run) so new rules — like the NTE-113 year
	 * archive — take effect without a manual permalink flush. The existing
	 * maybe_flush_rewrite_rules() (init priority 99) consumes the flag.
	 *
	 * @since 3.13.0
	 *
	 * @return void
	 */
	private function maybe_flag_rewrite_flush(): void {
		if ( get_option( 'nettertech_events_rewrite_version' ) === self::REWRITE_VERSION ) {
			return;
		}

		update_option( 'nettertech_events_flush_rewrite_rules', true );
		update_option( 'nettertech_events_rewrite_version', self::REWRITE_VERSION );
	}

	/**
	 * Register rewrite rules for event pages.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_rewrite_rules(): void {
		$base_path    = PathHelper::get_base_path();
		$archive_path = PathHelper::get_archive_path();

		// Note: Rules are added with 'top' priority but WordPress processes them
		// in the order they were added. More specific patterns must come first.

		// Ticket scan URL: /ticket/{XXXX-XXXX-XXXX-XXXX}/.
		// Used by native camera app scanning - shows check-in result or public ticket view.
		add_rewrite_rule(
			'^ticket/([A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4})/?$',
			'index.php?nettertech_events_ticket_code=$matches[1]',
			'top'
		);

		// Public volunteer check-in: /checkin/{occurrence_id}/{token}/.
		// Token is 96 hex characters for security.
		add_rewrite_rule(
			'^checkin/(\d+)/([a-f0-9]{96})/?$',
			'index.php?nettertech_events_checkin_occurrence=$matches[1]&nettertech_events_checkin_token=$matches[2]',
			'top'
		);

		// Year-scoped past archive (paged): /{archive_path}/{YYYY}/page/{num}/ (NTE-113).
		// More specific than the bare year rule, so it is registered first.
		add_rewrite_rule(
			'^' . $archive_path . '/(\d{4})/page/(\d+)/?$',
			'index.php?nettertech_events_past_archive=1&nettertech_events_past_year=$matches[1]&paged=$matches[2]',
			'top'
		);

		// Year-scoped past archive: /{archive_path}/{YYYY}/ (NTE-113).
		add_rewrite_rule(
			'^' . $archive_path . '/(\d{4})/?$',
			'index.php?nettertech_events_past_archive=1&nettertech_events_past_year=$matches[1]',
			'top'
		);

		// Events archive (past events): /{archive_path}/page/{num}/
		// Must be before generic slug match to avoid "archive" being treated as event slug.
		add_rewrite_rule(
			'^' . $archive_path . '/page/(\d+)/?$',
			'index.php?nettertech_events_past_archive=1&paged=$matches[1]',
			'top'
		);

		// Events archive (past events): /{archive_path}/.
		add_rewrite_rule(
			'^' . $archive_path . '/?$',
			'index.php?nettertech_events_past_archive=1',
			'top'
		);

		// Occurrence page: /{base}/{slug}/{YYYY-MM-DD-HHMM}/.
		add_rewrite_rule(
			'^' . $base_path . '/([^/]+)/(\d{4}-\d{2}-\d{2}-\d{4})/?$',
			'index.php?nettertech_events_event_slug=$matches[1]&nettertech_events_occurrence_datetime=$matches[2]',
			'top'
		);

		// Event/series page: /{base}/{slug}/.
		add_rewrite_rule(
			'^' . $base_path . '/([^/]+)/?$',
			'index.php?nettertech_events_event_slug=$matches[1]',
			'top'
		);

		// Events main page: /{base}/.
		add_rewrite_rule(
			'^' . $base_path . '/?$',
			'index.php?nettertech_events_archive=1',
			'top'
		);

		// Static iCal feed pretty URL: /{base}.ics (NTE-016). Serves the
		// prebuilt static file when static mode is on, else falls back to
		// the dynamic REST feed. Disjoint from the /{base}/ rules (".ics"
		// vs "/").
		add_rewrite_rule(
			'^' . $base_path . '\.ics/?$',
			'index.php?nettertech_events_ical_feed=1',
			'top'
		);

		// Space detail page: /{spaces-base}/{slug}/.
		$spaces_base = PathHelper::get_spaces_base_path();
		add_rewrite_rule(
			'^' . $spaces_base . '/([^/]+)/?$',
			'index.php?nettertech_events_space_slug=$matches[1]',
			'top'
		);
	}

	/**
	 * Flush rewrite rules if flagged.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( 'nettertech_events_flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
			delete_option( 'nettertech_events_flush_rewrite_rules' );
		}
	}

	/**
	 * Skip WordPress canonical (trailing-slash) redirects for the feed URL.
	 *
	 * The site uses trailing-slash permalinks, which would otherwise 301
	 * /{base}.ics to /{base}.ics/ before the feed is served. Subscribers
	 * should reach the feed without an extra hop. See NTE-016.
	 *
	 * @since 3.13.0
	 *
	 * @param string|false $redirect_url The canonical URL, or false to skip.
	 * @return string|false
	 */
	public function prevent_ical_feed_canonical_redirect( $redirect_url ) {
		if ( get_query_var( 'nettertech_events_ical_feed' ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Serve the static iCal feed at the pretty URL, or fall back.
	 *
	 * Fires on template_redirect for /{base}.ics. When static-feed mode is
	 * enabled and the prebuilt file is readable, streams it with iCal
	 * headers and exits. Otherwise 302-redirects to the dynamic REST feed,
	 * so the URL never breaks (mode off, or the file was never built or is
	 * unreadable). See NTE-016.
	 *
	 * @since 3.13.0
	 *
	 * @return void
	 */
	public function maybe_serve_ical_feed(): void {
		if ( ! get_query_var( 'nettertech_events_ical_feed' ) ) {
			return;
		}

		$path = ICalFileWriter::feed_path();

		if ( NetterTechEventsSettings::from_option()->performance->ical_feed_static_mode && is_readable( $path ) ) {
			status_header( 200 );
			header( 'Content-Type: ' . ICalService::get_content_type() );
			header( 'Content-Disposition: ' . ICalService::get_content_disposition( 'events.ics' ) );
			header( 'Cache-Control: public, max-age=3600' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a prebuilt public static feed file.
			readfile( $path );
			exit;
		}

		wp_safe_redirect( rest_url( 'nettertech-events/v1/ical/feed' ), 302 );
		exit;
	}

	/**
	 * Add custom query vars.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string> $vars Existing query vars.
	 * @return array<string>
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = 'nettertech_events_event_slug';
		$vars[] = 'nettertech_events_occurrence_datetime';
		$vars[] = 'nettertech_events_archive';
		$vars[] = 'nettertech_events_past_archive';
		$vars[] = 'nettertech_events_past_year';
		$vars[] = 'nettertech_events_checkin_occurrence';
		$vars[] = 'nettertech_events_checkin_token';
		$vars[] = 'nettertech_events_ticket_code';
		$vars[] = 'nettertech_events_space_slug';
		$vars[] = 'nettertech_events_ical_feed';
		return $vars;
	}

	/**
	 * Load the event template when viewing a single event.
	 *
	 * Dispatches to handler methods for each URL pattern:
	 * 1. /ticket/{code}/           - Ticket scan (native camera)
	 * 2. /checkin/{id}/{token}/     - Public volunteer check-in
	 * 3. /spaces/{slug}/            - Space detail page
	 * 4. /events/ or /past-events/ - Archive pages
	 * 5. /events/{slug}/            - Single event, series, or occurrence
	 *
	 * @since 0.1.0
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function maybe_load_event_template( string $template ): string {
		// Allow extensions (e.g., Pro) to handle routes first.
		$extended = apply_filters( 'nettertech_events_route_template', null, $template );
		if ( null !== $extended ) {
			return $extended;
		}

		return $this->handle_space_page( $template )
			?? $this->handle_events_archive( $template )
			?? $this->handle_event_page( $template )
			?? $template;
	}

	/**
	 * Handle space detail page URL: /spaces/{slug}/.
	 *
	 * @param string $template Default template path.
	 * @return string|null Template path if handled, null otherwise.
	 */
	private function handle_space_page( string $template ): ?string {
		$space_slug = get_query_var( 'nettertech_events_space_slug' );
		if ( empty( $space_slug ) ) {
			return null;
		}

		return $this->load_space_template( $space_slug, $template );
	}

	/**
	 * Handle events archive URLs: /{base}/ and /{archive_path}/.
	 *
	 * @param string $template Default template path.
	 * @return string|null Template path if handled, null otherwise.
	 */
	private function handle_events_archive( string $template ): ?string {
		// Only handle archive routes when no event slug is present.
		$event_slug = get_query_var( 'nettertech_events_event_slug' );
		if ( ! empty( $event_slug ) ) {
			return null;
		}

		if ( get_query_var( 'nettertech_events_archive' ) ) {
			return $this->template_resolver->get_archive_template( $template );
		}

		if ( get_query_var( 'nettertech_events_past_archive' ) ) {
			$this->past_year = $this->resolve_past_year();

			if ( null !== $this->past_year ) {
				$this->setup_document_meta(
					sprintf(
						/* translators: %d: four-digit year */
						__( 'Past Events - %d', 'nettertech-events' ),
						$this->past_year
					),
					'archive nte-past-archive nte-past-archive--year'
				);
			}

			return $this->template_resolver->get_past_archive_template( $template );
		}

		return null;
	}

	/**
	 * Resolve and validate the year for a year-scoped past archive.
	 *
	 * Accepts a four-digit year from 2000 through the current year (the
	 * current year shows the past events that have already happened this
	 * year). Anything out of range yields null, so the template renders the
	 * unscoped past archive rather than erroring. See NTE-113.
	 *
	 * @return int|null Validated year, or null when absent/out of range.
	 */
	private function resolve_past_year(): ?int {
		$raw = get_query_var( 'nettertech_events_past_year' );

		if ( '' === $raw || null === $raw ) {
			return null;
		}

		$year = (int) $raw;
		$max  = (int) gmdate( 'Y' );

		if ( $year < 2000 || $year > $max ) {
			return null;
		}

		return $year;
	}

	/**
	 * Handle single event, series, and occurrence URLs: /{base}/{slug}/ and /{base}/{slug}/{datetime}/.
	 *
	 * @param string $template Default template path.
	 * @return string|null Template path if handled, null otherwise.
	 */
	private function handle_event_page( string $template ): ?string {
		$event_slug = get_query_var( 'nettertech_events_event_slug' );
		if ( empty( $event_slug ) ) {
			return null;
		}

		$event = $this->resolve_event( $event_slug );
		if ( null === $event || null === $event->id ) {
			return $this->send_404_response();
		}

		$this->current_event = $event;

		$occurrence_datetime = get_query_var( 'nettertech_events_occurrence_datetime' );
		$is_ticketed         = $event->is_recurring() && $this->event_repo->has_ticket_types( $event->id );

		// Handle occurrence-specific URLs for both ticketed and unticketed events.
		// Unticketed recurring events previously 301-redirected to the series page,
		// which discarded the clicked date (NTE-076). They now render the
		// occurrence-specific page with a canonical link to the series URL.
		if ( ! empty( $occurrence_datetime ) ) {
			return $this->load_occurrence_template( $event, $occurrence_datetime, $template );
		}

		// For ticketed recurring events, show series page with grid layout.
		if ( $is_ticketed ) {
			return $this->load_series_template( $event, $template );
		}

		// Single event: show standard event page.
		$this->setup_document_meta( $event->title, 'single-nte-event' );

		return $this->template_resolver->get_single_template( $template );
	}

	/**
	 * Resolve an event by slug, checking publication status and preview permissions.
	 *
	 * @param string $slug Event slug from URL.
	 * @return \NetterTechEvents\Models\Event|null The event if found and accessible, null otherwise.
	 */
	private function resolve_event( string $slug ): ?\NetterTechEvents\Models\Event {
		$event = $this->event_repo->find_by_slug( $slug );

		if ( ! $event ) {
			return null;
		}

		if ( ! $event->is_published() && ! $this->template_resolver->can_preview_event( $event ) ) {
			return null;
		}

		return $event;
	}

	/**
	 * Send a 404 response.
	 *
	 * Unified 404 mechanism: sets WP_Query to 404 state, sends the status header,
	 * and returns the 404 template path.
	 *
	 * @return string The 404 template path.
	 */
	private function send_404_response(): string {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		return get_404_template();
	}

	/**
	 * Load the occurrence-specific template.
	 *
	 * @param \NetterTechEvents\Models\Event $event              The parent event.
	 * @param string                         $occurrence_datetime Datetime from URL (YYYY-MM-DD-HHMM).
	 * @param string                         $template            Default template.
	 * @return string
	 */
	private function load_occurrence_template( \NetterTechEvents\Models\Event $event, string $occurrence_datetime, string $template ): string {
		// Parse the datetime from URL format.
		$datetime = $this->template_resolver->parse_occurrence_datetime( $occurrence_datetime );
		$event_id = $event->id;

		if ( ! $datetime || null === $event_id ) {
			return $this->send_404_response();
		}

		// Find the occurrence.
		$occurrence = $this->occurrence_repo->find_by_event_and_datetime( $event_id, $datetime );

		if ( ! $occurrence ) {
			return $this->send_404_response();
		}

		// Store occurrence for template access.
		$this->current_occurrence = $occurrence;

		// Occurrence-specific URLs declare the series URL as canonical so search
		// engines treat the per-date pages as one canonical resource (NTE-076).
		remove_action( 'wp_head', 'rel_canonical' );
		add_action( 'wp_head', array( $this, 'output_occurrence_canonical' ) );

		// Build title with date.
		$title = $occurrence->get_title() . ' - ' . $occurrence->get_formatted_date();
		$this->setup_document_meta( $title, 'single-nte-event nte-event-occurrence' );

		return $this->template_resolver->get_single_template( $template );
	}

	/**
	 * Load the series page template for recurring events.
	 *
	 * @param \NetterTechEvents\Models\Event $event    The event.
	 * @param string                         $template Default template.
	 * @return string
	 */
	private function load_series_template( \NetterTechEvents\Models\Event $event, string $template ): string {
		$this->is_series_page = true;

		$this->setup_document_meta( $event->title, 'single-nte-event nte-event-series' );

		return $this->template_resolver->get_series_template( $template );
	}

	/**
	 * Load the space detail page template.
	 *
	 * @param string $slug     Space slug from URL.
	 * @param string $template Default template.
	 * @return string
	 */
	private function load_space_template( string $slug, string $template ): string {
		$handler = new SpacePageHandler();
		$space   = $handler->resolve( $slug );

		if ( ! $space ) {
			return $this->send_404_response();
		}

		$this->current_space = $space;

		/**
		 * SpacePageHandler::resolve() returns stdClass with name column.
		 *
		 * @var object{name: string} $space
		 */
		$this->setup_document_meta( $space->name, 'single-nte-space' );

		return $this->template_resolver->get_space_template( $template );
	}

	/**
	 * Set up document title and body classes.
	 *
	 * @param string $title       Page title.
	 * @param string $body_class  Space-separated body classes.
	 * @return void
	 */
	private function setup_document_meta( string $title, string $body_class ): void {
		add_filter(
			'document_title_parts',
			function ( $parts ) use ( $title ) {
				$parts['title'] = $title;
				return $parts;
			}
		);

		add_filter(
			'body_class',
			function ( $classes ) use ( $body_class ) {
				return array_merge( $classes, explode( ' ', $body_class ) );
			}
		);
	}

	/**
	 * Output a canonical link to the series URL for occurrence-specific pages.
	 *
	 * Hooked to wp_head only while rendering an occurrence-specific URL so the
	 * per-date pages unify under the series URL for SEO (NTE-076).
	 *
	 * @return void
	 */
	public function output_occurrence_canonical(): void {
		if ( null === $this->current_event ) {
			return;
		}

		printf(
			'<link rel="canonical" href="%s" />' . "\n",
			esc_url( $this->current_event->get_series_url() )
		);
	}

	/**
	 * Get the current event being viewed.
	 *
	 * Static accessor delegates to singleton instance for backward compatibility.
	 *
	 * @since 0.1.0
	 *
	 * @return \NetterTechEvents\Models\Event|null
	 */
	public static function get_current_event(): ?\NetterTechEvents\Models\Event {
		return self::instance()->current_event;
	}

	/**
	 * Get the validated year for a year-scoped past archive (NTE-113).
	 *
	 * Set during template dispatch; read by the past-archive template to
	 * scope the listing to a single calendar year. Null for the unscoped
	 * past archive.
	 *
	 * @since 3.13.0
	 *
	 * @return int|null
	 */
	public static function get_past_year(): ?int {
		return self::instance()->past_year;
	}

	/**
	 * Get the current occurrence being viewed.
	 *
	 * Only set when viewing an occurrence-specific URL.
	 * Static accessor delegates to singleton instance for backward compatibility.
	 *
	 * @since 0.1.0
	 *
	 * @return Occurrence|null
	 */
	public static function get_current_occurrence(): ?Occurrence {
		return self::instance()->current_occurrence;
	}

	/**
	 * Get the current space being viewed.
	 *
	 * Only set when viewing a space detail page (/spaces/{slug}/).
	 * Static accessor delegates to singleton instance.
	 *
	 * @since 1.7.0
	 *
	 * @return object|null
	 */
	public static function get_current_space(): ?object {
		return self::instance()->current_space;
	}

	/**
	 * Get the current check-in token.
	 *
	 * Only set when viewing the public volunteer check-in page.
	 *
	 * @since 0.8.0
	 *
	 * @return string|null
	 */
	public static function get_current_checkin_token(): ?string {
		$router = self::instance();
		return $router->ticket_router ? $router->ticket_router->get_current_checkin_token() : null;
	}

	/**
	 * Check if we're viewing a series page.
	 *
	 * Static accessor delegates to singleton instance for backward compatibility.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_series_page(): bool {
		return self::instance()->is_series_page;
	}

	/**
	 * The singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get the Router instance.
	 *
	 * Templates should use this to access repositories rather than
	 * instantiating them directly.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException If called before Plugin::init_frontend().
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			throw new \RuntimeException(
				'Router::instance() called before Plugin::init_frontend() set the singleton. '
				. 'Ensure the plugin is fully initialized before accessing the Router.'
			);
		}
		return self::$instance;
	}

	/**
	 * Set the singleton instance.
	 *
	 * Used by Plugin to inject dependencies.
	 *
	 * @since 0.1.0
	 *
	 * @param self $instance Router instance.
	 * @return void
	 */
	public static function set_instance( self $instance ): void {
		self::$instance = $instance;
	}

	/**
	 * Get the occurrence repository.
	 *
	 * @since 0.1.0
	 *
	 * @return OccurrenceRepositoryInterface
	 */
	public function get_occurrence_repository(): OccurrenceRepositoryInterface {
		return $this->occurrence_repo;
	}

	/**
	 * Get the event repository.
	 *
	 * @since 0.1.0
	 *
	 * @return EventRepositoryInterface
	 */
	public function get_event_repository(): EventRepositoryInterface {
		return $this->event_repo;
	}

	/**
	 * Get the current ticket data (for ticket scan pages).
	 *
	 * @return object|null
	 */
	public static function get_current_ticket_data(): ?object {
		$router = self::instance();
		return $router->ticket_router ? $router->ticket_router->get_current_ticket_data() : null;
	}

	/**
	 * Check if current request is from a volunteer with valid cookie.
	 *
	 * @return bool
	 */
	public static function is_volunteer_scan(): bool {
		$router = self::instance();
		return $router->ticket_router ? $router->ticket_router->is_volunteer_scan() : false;
	}
}
