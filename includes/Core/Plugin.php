<?php
/**
 * Main plugin class.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CalendarLinkServiceInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\EmailServiceInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketCodeGeneratorInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Contracts\WaitlistServiceInterface;
use NetterTechEvents\Database\MigrationManager;
use NetterTechEvents\Database\PrefixMigrationManager;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Contracts\EventDeletionCascadeInterface;
use NetterTechEvents\Services\RateLimitService;
use NetterTechEvents\Services\RevisionService;
use NetterTechEvents\Services\RsvpCapacityHandler;

/**
 * Plugin orchestrator class.
 *
 * Coordinates initialization of all plugin components.
 *
 * @since 0.1.0
 * @api
 */
class Plugin {

	/**
	 * Hook loader instance.
	 *
	 * @var Loader
	 */
	private Loader $loader;

	/**
	 * Cache manager instance.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache_manager;

	/**
	 * DI container instance.
	 *
	 * @since 1.5.0
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * WooCommerce integration (kept for CLI wiring; null until init_integrations()).
	 *
	 * @var \NetterTechEvents\Integrations\WooCommerce\WooCommerceIntegration|null
	 */
	private ?\NetterTechEvents\Integrations\WooCommerce\WooCommerceIntegration $wc_integration = null;

	/**
	 * Whether the plugin has been initialized.
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		global $wpdb;

		$this->container     = new Container();
		$this->loader        = new Loader();
		$this->cache_manager = new CacheManager(
			$wpdb,
			\NetterTechEvents\TemplateLoader\Templates::get_instance(),
			new \NetterTechEvents\Repositories\HouseCapacityRepository( $wpdb )
		);

		// Initialize the DI container and ServiceRegistry facade.
		ServiceRegistry::init( $this->container );
	}

	/**
	 * Get the DI container.
	 *
	 * @since 1.5.0
	 *
	 * @return Container
	 */
	public function get_container(): Container {
		return $this->container;
	}

	/**
	 * Initialize the plugin.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		// Run rebrand migration if upgrading from legacy naming.
		MigrationManager::maybe_migrate();

		// Run prefix migration (Step 2: nte_* → nettertech_events_*).
		PrefixMigrationManager::maybe_migrate();

		// Run database migrations if needed.
		if ( Schema::needs_migration() ) {
			Schema::migrate();
		}

		// Register custom image sizes.
		$this->register_image_sizes();

		// Register taxonomies.
		Taxonomies::register();

		// Static facades initialized with injected dependencies (ADR-013).

		// Initialize components.
		$this->init_assets();
		$this->init_security_headers();
		$this->init_admin();
		$this->init_frontend();
		$this->init_api();
		$this->init_integrations();

		// Register shadow post type for admin search + Gutenberg link dialog.
		ShadowPostType::register();

		// Register shadow post sync service (keeps shadow posts in sync with events).
		// NTE-002j: category_repo and tag_repo are passed so the service can
		// populate the nettertech_event_category and nettertech_event_tag shadow taxonomies.
		$shadow_sync = new \NetterTechEvents\Services\ShadowPostSyncService(
			$this->container->get( EventRepositoryInterface::class ),
			$GLOBALS['wpdb'],
			$this->container->get( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class ),
			$this->container->get( \NetterTechEvents\Contracts\TagRepositoryInterface::class )
		);
		$shadow_sync->register();

		// Register Gutenberg blocks on init hook.
		$this->loader->add_action( 'init', $this, 'register_blocks' );

		// Register block patterns and pattern category.
		BlockPatterns::register();

		// Register cache invalidation hooks.
		$this->cache_manager->register();

		// Register activity logging hooks (OWASP A09).
		$this->init_activity_logging();

		// Keep ticket/product visibility in step with event status (NTE-177).
		$this->init_ticket_status_sync();

		// Register bulk import handler (cache invalidation, logging, WC sync).
		$this->init_bulk_import_handler();

		// Register revision tracking hooks.
		$this->init_revisions();

		// Register event-delete cascade (removes child data before the row is deleted).
		$this->init_event_deletion_cascade();

		// Register privacy tools hooks (GDPR compliance).
		$this->init_privacy_tools();

		// Register reminder email cron hooks.
		$this->init_reminder_emails();

		// Register occurrence horizon extension cron hooks.
		$this->init_occurrence_horizon_extender();

		// Register reservation sweep cron hooks.
		$this->init_reservation_sweep();

		// Register static iCal feed regeneration listener (NTE-016).
		$this->init_ical_static_feed();

		// Register RSVP capacity handler (NTE-036): increments sold_count on
		// successful RSVP submits so free-event capacity is enforced
		// end-to-end without depending on the WC order-completion path.
		$this->init_rsvp_capacity_handler();

		// Register waitlist promotion email handler.
		$this->init_waitlist_emails();

		// Register waitlist frontend UI (join panel on sold-out events).
		$this->init_waitlist_frontend();

		// Register WP-CLI commands (only when running under WP-CLI).
		$this->init_cli();

		// Register all hooks.
		$this->loader->run();

		/**
		 * Fires after the plugin has been fully initialized.
		 *
		 * @since 1.0.2
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'nettertech_events_init', $this );
	}

	/**
	 * Register custom image sizes for event tiles.
	 *
	 * Registers an 800x800 image size optimized for retina displays.
	 * Tiles display at ~400px, so 2x size ensures sharp images on high-DPI screens.
	 *
	 * Note: Existing images will need regeneration to use this size.
	 * Use WP-CLI: wp media regenerate --only-missing
	 * Or install "Regenerate Thumbnails" plugin.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	private function register_image_sizes(): void {
		add_image_size( 'nettertech-events-tile', 800, 800, true );
	}

	/**
	 * Initialize asset management.
	 *
	 * @return void
	 */
	private function init_assets(): void {
		$assets = new Assets(
			$this->container->get( \NetterTechEvents\Services\PaletteResolver::class ),
			$this->container->get( NetterTechEventsSettings::class )
		);
		// Register assets early so templates can enqueue them.
		$this->loader->add_action( 'init', $assets, 'register' );
		$this->loader->add_action( 'wp_enqueue_scripts', $assets, 'maybe_enqueue_frontend' );
		$this->loader->add_action( 'admin_enqueue_scripts', $assets, 'enqueue_admin' );
	}

	/**
	 * Initialize security headers.
	 *
	 * Adds defense-in-depth security headers on NetterTech Events admin pages.
	 * Includes X-Content-Type-Options, X-Frame-Options, Referrer-Policy,
	 * and CSP in report-only mode for monitoring.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function init_security_headers(): void {
		$security_headers = new SecurityHeaders();
		$security_headers->init();
	}

	/**
	 * Register Gutenberg blocks.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		$blocks_dir = NETTERTECH_EVENTS_PLUGIN_DIR . 'blocks/';

		// Register each block from its block.json.
		$blocks = array( 'calendar', 'carousel', 'event-grid' );

		foreach ( $blocks as $block ) {
			$block_path = $blocks_dir . $block;
			if ( file_exists( $block_path . '/block.json' ) ) {
				register_block_type( $block_path );
			}
		}
	}

	/**
	 * Initialize admin components.
	 *
	 * @return void
	 */
	private function init_admin(): void {
		if ( ! is_admin() ) {
			return;
		}

		// Register event editor form handler (resolved from AdminServiceProvider).
		$save_handler = $this->container->get( \NetterTechEvents\Admin\EventSaveHandler::class );
		\NetterTechEvents\Admin\EventEditor::register( $save_handler );

		// Register per-occurrence editor form handler.
		$occurrence_save_handler = $this->container->get( \NetterTechEvents\Admin\OccurrenceSaveHandler::class );
		\NetterTechEvents\Admin\OccurrenceEditor::register( $occurrence_save_handler );

		// Register organizer save handler.
		$organizer_save_handler = $this->container->get( \NetterTechEvents\Admin\OrganizerSaveHandler::class );
		$organizer_save_handler->register();

		// Register space save handler.
		$space_save_handler = $this->container->get( \NetterTechEvents\Admin\SpaceSaveHandler::class );
		$space_save_handler->register();

		// Register admin menu.
		$admin_menu = $this->container->get( \NetterTechEvents\Admin\AdminMenu::class );
		$admin_menu->register();

		// Register path conflict detector (warns about URL conflicts with other plugins).
		$conflict_detector = new \NetterTechEvents\Services\PathConflictDetector();
		$conflict_detector->register();
		add_action( 'admin_init', array( $conflict_detector, 'handle_dismiss' ) );

		// Register Site Health checks (rate-limit proxy topology, NTE-142).
		$settings    = $this->container->get( \NetterTechEvents\Core\NetterTechEventsSettings::class );
		$site_health = new \NetterTechEvents\Admin\SiteHealth(
			new \NetterTechEvents\Services\ClientIpResolver( $settings->performance->rate_limit_proxy_mode )
		);
		$site_health->register();

		// Register event action handlers (delete, duplicate).
		$action_handler = $this->container->get( \NetterTechEvents\Admin\EventActionHandler::class );
		$action_handler->register();

		// Register quick edit AJAX handler.
		$quick_edit_handler = $this->container->get( \NetterTechEvents\Admin\EventQuickEditHandler::class );
		add_action( 'wp_ajax_nettertech_events_quick_edit_event', array( $quick_edit_handler, 'handle_quick_edit' ) );

		// Register revision AJAX handlers.
		$revision_handler = $this->container->get( \NetterTechEvents\Admin\RevisionAjaxHandler::class );
		add_action( 'wp_ajax_nettertech_events_revision_diff', array( $revision_handler, 'handle_diff' ) );
		add_action( 'wp_ajax_nettertech_events_revision_restore', array( $revision_handler, 'handle_restore' ) );
		add_action( 'wp_ajax_nettertech_events_revision_list', array( $revision_handler, 'handle_list' ) );

		// Register QR Generator AJAX handlers.
		$qr_generator_page = $this->container->get( \NetterTechEvents\Admin\QRGeneratorPage::class );
		add_action( 'wp_ajax_nettertech_events_get_page_title', array( $qr_generator_page, 'handle_get_page_title' ) );
		add_action( 'wp_ajax_nettertech_events_generate_qr', array( $qr_generator_page, 'handle_generate_qr' ) );

		// Register the All Events "SKUs" row-action AJAX handler (NTE-114).
		$event_skus_handler = new \NetterTechEvents\Admin\Ajax\EventSkusAjaxHandler(
			$this->container->get( \NetterTechEvents\Contracts\EventRepositoryInterface::class ),
			$this->container->get( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class )
		);
		add_action( 'wp_ajax_' . \NetterTechEvents\Core\Hooks::AJAX_EVENT_SKUS, array( $event_skus_handler, 'handle' ) );

		// Register the All Events per-date row expansion handler (NTE-245). Read-only.
		$occurrence_rows_handler = new \NetterTechEvents\Admin\Ajax\EventOccurrenceRowsAjaxHandler(
			$this->container->get( \NetterTechEvents\Contracts\EventRepositoryInterface::class ),
			$this->container->get( \NetterTechEvents\Contracts\OccurrenceQueryRepositoryInterface::class ),
			$this->container->get( \NetterTechEvents\Contracts\AttendeeRepositoryInterface::class ),
			$this->container->get( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class )
		);
		add_action( 'wp_ajax_' . \NetterTechEvents\Core\Hooks::AJAX_EVENT_OCCURRENCE_ROWS, array( $occurrence_rows_handler, 'handle' ) );

		// Register the Attendees screen check-in toggle (NTE-144). Logged-in only;
		// Pro's volunteer check-in is token-authenticated and routed separately.
		$attendee_check_in_handler = new \NetterTechEvents\Admin\Ajax\AttendeeCheckInAjaxHandler(
			$this->container->get( \NetterTechEvents\Contracts\AttendeeRepositoryInterface::class ),
			$this->container->get( \NetterTechEvents\Contracts\AttendeeCheckInInterface::class ),
			$this->container->get( \NetterTechEvents\Contracts\ActivityLogServiceInterface::class )
		);
		add_action( 'wp_ajax_' . \NetterTechEvents\Core\Hooks::AJAX_ATTENDEE_CHECK_IN, array( $attendee_check_in_handler, 'handle' ) );

		// Register WooCommerce-missing notice for the event editor.
		$wc_missing_notice = $this->container->get( \NetterTechEvents\Admin\Notices\WooCommerceMissingNotice::class );
		$wc_missing_notice->register();

		/**
		 * Fires after base admin initialization is complete.
		 *
		 * @since 1.0.2
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'nettertech_events_admin_ready', $this );
	}

	/**
	 * Initialize frontend components.
	 *
	 * @return void
	 */
	private function init_frontend(): void {
		// Initialize template system (registers theme-switch hooks).
		\NetterTechEvents\TemplateLoader\Templates::init();
		$templates = \NetterTechEvents\TemplateLoader\Templates::get_instance();

		// Resolve repositories from the DI container.
		$event_repo      = $this->container->get( EventRepositoryInterface::class );
		$occurrence_repo = $this->container->get( OccurrenceRepositoryInterface::class );

		// Create router with injected dependencies and register as singleton.
		$router = new \NetterTechEvents\Frontend\Router(
			$event_repo,
			$occurrence_repo,
			$templates
		);
		\NetterTechEvents\Frontend\Router::set_instance( $router );
		$router->register();

		// Register calendar routing (custom query vars + legacy URL redirect).
		( new \NetterTechEvents\Frontend\CalendarRouting() )->register();

		// Register shortcodes (available on frontend and in editor previews).
		$this->register_shortcodes();

		// Initialize ticket/RSVP display on single event pages.
		\NetterTechEvents\Frontend\TicketDisplay::init(
			$this->container->get( CapacityServiceInterface::class ),
			$this->container->get( TicketTypeRepositoryInterface::class ),
			$templates,
			$this->container->get( \NetterTechEvents\Frontend\Shortcodes\RSVPFormShortcode::class ),
			$this->container->get( OccurrenceRepositoryInterface::class ),
			$this->container->get( \NetterTechEvents\Contracts\SpaceRepositoryInterface::class )
		);
		$ical_button = new \NetterTechEvents\Frontend\ICalButton(
			$this->container->get( CalendarLinkServiceInterface::class )
		);
		$ical_button->init();

		// Initialize optional frontend branding.
		\NetterTechEvents\Frontend\FrontendBranding::init();

		// Open Graph + Twitter Card meta tags on event pages.
		\NetterTechEvents\Frontend\OpenGraphTags::init();

		// Register custom sitemap provider for events.
		add_action(
			'wp_sitemaps_init',
			function () {
				global $wpdb;
				wp_register_sitemap_provider( 'nettertechevents', new \NetterTechEvents\SEO\EventSitemapProvider( $wpdb ) );
			}
		);

		// Schema.org JSON-LD structured data on event pages.
		\NetterTechEvents\Frontend\SchemaMarkup::init();

		// Add "Edit Event" link to admin bar on single event pages.
		add_action( 'admin_bar_menu', array( $this, 'add_edit_event_admin_bar_link' ), 80 );

		/**
		 * Fires after base frontend initialization is complete.
		 *
		 * @since 1.0.2
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'nettertech_events_frontend_ready', $this );
	}

	/**
	 * Add "Edit Event" link to the admin bar when viewing a single event.
	 *
	 * Mirrors WordPress core's "Edit Post" admin bar node for custom event pages.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public function add_edit_event_admin_bar_link( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( is_admin() || ! is_user_logged_in() || ! current_user_can( \NetterTechEvents\Admin\AdminMenu::CAPABILITY ) ) {
			return;
		}

		$event = \NetterTechEvents\Frontend\Router::get_current_event();
		if ( ! $event || ! $event->id ) {
			return;
		}

		$edit_url = admin_url( 'admin.php?page=nettertech-events&action=edit&event_id=' . $event->id );

		// Same ticket-with-star icon as the dashboard menu (NTE-176/NTE-194,
		// single source in AdminMenuRegistrar); currentColor inherits the WP
		// admin bar color scheme and hover states.
		$icon = '<span class="ab-icon" style="display:inline-block;width:20px;height:20px;margin-top:2px;">'
			. \NetterTechEvents\Admin\AdminMenuRegistrar::icon_svg( 'currentColor', 'width:20px;height:20px;' )
			. '</span>';

		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar #wp-admin-bar-nte-edit-event .ab-icon{color:rgba(240,246,252,.6)}
#wpadminbar #wp-admin-bar-nte-edit-event:hover .ab-icon,
#wpadminbar #wp-admin-bar-nte-edit-event.hover .ab-icon{color:#72aee6}'
		);

		$wp_admin_bar->add_node(
			array(
				'id'    => 'nte-edit-event',
				'title' => $icon . '<span class="ab-label">' . esc_html__( 'Edit', 'nettertech-events' ) . '</span>',
				'href'  => esc_url( $edit_url ),
				'meta'  => array(
					'title' => esc_attr__( 'Edit this event', 'nettertech-events' ),
				),
			)
		);
	}

	/**
	 * Register shortcodes.
	 *
	 * @return void
	 */
	private function register_shortcodes(): void {
		$carousel = $this->container->get( \NetterTechEvents\Frontend\Shortcodes\CarouselShortcode::class );
		add_shortcode( 'nettertech_events_carousel', array( $carousel, 'render' ) );

		$event_list = $this->container->get( \NetterTechEvents\Frontend\Shortcodes\EventListShortcode::class );
		add_shortcode( 'nettertech_events_list', array( $event_list, 'render' ) );
		// Aliases for nettertech_events_list — documented in PageContextDetector's shortcode map
		// as 'grid' view type. Registering them prevents [nettertech_events_grid] and
		// [nettertech_events] from rendering as literal text (NTE-019, NTE-020).
		add_shortcode( 'nettertech_events_grid', array( $event_list, 'render' ) );
		add_shortcode( 'nettertech_events', array( $event_list, 'render' ) );

		$calendar = $this->container->get( \NetterTechEvents\Frontend\Shortcodes\CalendarShortcode::class );
		add_shortcode( 'nettertech_events_calendar', array( $calendar, 'render' ) );

		$rsvp = $this->container->get( \NetterTechEvents\Frontend\Shortcodes\RSVPFormShortcode::class );
		add_shortcode( 'nettertech_events_rsvp', array( $rsvp, 'render' ) );

		$regulars = $this->container->get( \NetterTechEvents\Frontend\Shortcodes\RegularsShortcode::class );
		add_shortcode( 'nettertech_events_regulars', array( $regulars, 'render' ) );
	}

	/**
	 * Initialize REST API.
	 *
	 * @return void
	 */
	private function init_api(): void {
		$this->loader->add_action( 'rest_api_init', $this, 'register_rest_routes' );
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$events_controller = new \NetterTechEvents\API\EventsController(
			$this->container->get( OccurrenceRepositoryInterface::class ),
			$this->container->get( TicketTypeRepositoryInterface::class ),
			$this->container->get( CapacityServiceInterface::class ),
			$this->container->get( RateLimitService::class )
		);
		$events_controller->register_routes();

		$events_admin_controller = new \NetterTechEvents\API\EventsAdminController(
			$this->container->get( EventRepositoryInterface::class ),
			$this->container->get( ActivityLogServiceInterface::class ),
			$this->container->get( RateLimitService::class )
		);
		$events_admin_controller->register_routes();

		$attendees_admin_controller = new \NetterTechEvents\API\AttendeesAdminController(
			$this->container->get( AttendeeRepositoryInterface::class ),
			$this->container->get( ActivityLogServiceInterface::class ),
			$this->container->get( RateLimitService::class ),
			$this->container->get( \NetterTechEvents\Contracts\CapacityServiceInterface::class )
		);
		$attendees_admin_controller->register_routes();

		$ticket_types_admin_controller = new \NetterTechEvents\API\TicketTypesAdminController(
			$this->container->get( TicketTypeRepositoryInterface::class ),
			$this->container->get( ActivityLogServiceInterface::class ),
			$this->container->get( RateLimitService::class )
		);
		$ticket_types_admin_controller->register_routes();

		$ical_controller = new \NetterTechEvents\API\ICalController(
			$this->container->get( EventRepositoryInterface::class ),
			$this->container->get( OccurrenceRepositoryInterface::class ),
			$this->container->get( \NetterTechEvents\Services\ICalService::class ),
			$this->container->get( RateLimitService::class )
		);
		$ical_controller->register_routes();

		$attendee_fields_controller = new \NetterTechEvents\API\AttendeeFieldsController(
			$this->container->get( \NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface::class ),
			$this->container->get( \NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface::class ),
			$this->container->get( RateLimitService::class )
		);
		$attendee_fields_controller->register_routes();

		$waitlist_controller = new \NetterTechEvents\API\WaitlistRestController(
			$this->container->get( WaitlistServiceInterface::class ),
			$this->container->get( OccurrenceRepositoryInterface::class ),
			new RateLimitService(
				$this->container->get( \NetterTechEvents\Core\NetterTechEventsSettings::class ),
				5,
				60
			),
			$this->container->get( \NetterTechEvents\Services\WaitlistAvailabilityResolver::class )
		);
		$waitlist_controller->register_routes();
	}

	/**
	 * Initialize integrations.
	 *
	 * @return void
	 */
	private function init_integrations(): void {
		// Beaver Builder integration.
		// Always initialize - register_modules() checks class_exists('FLBuilder') on init hook
		// when BB is guaranteed to be loaded.
		$bb_integration = new \NetterTechEvents\Integrations\BeaverBuilder\BeaverBuilderIntegration();
		$bb_integration->init();

		// EmailService handlers are wired via constructor injection in ServiceRegistry.
		$email_service = $this->container->get( EmailServiceInterface::class );

		// WooCommerce integration.
		$wc_integration = new \NetterTechEvents\Integrations\WooCommerce\WooCommerceIntegration(
			$this->container->get( TicketTypeRepositoryInterface::class ),
			$this->container->get( CapacityServiceInterface::class ),
			$this->container->get( OccurrenceRepositoryInterface::class ),
			$this->container->get( AttendeeRepositoryInterface::class ),
			$this->container->get( TicketRepositoryInterface::class ),
			$this->container->get( TicketCodeGeneratorInterface::class ),
			$email_service,
			$this->container->get( \NetterTechEvents\Integrations\WooCommerce\ProductManager::class ),
			$this->container->get( \NetterTechEvents\Services\PaletteResolver::class ),
			$this->container->get( ActivityLogServiceInterface::class ),
			$this->container->get( \NetterTechEvents\Services\AttendeeFieldService::class ),
			$this->container->get( EventRepositoryInterface::class ),
			$this->container->get( \NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface::class ),
			$this->container->get( RateLimitService::class )
		);
		$wc_integration->init();
		$this->wc_integration = $wc_integration;

		// Yoast SEO integration (schema, sitemap, breadcrumbs, custom variables).
		$yoast_integration = new \NetterTechEvents\Integrations\Yoast\YoastIntegration();
		$yoast_integration->init();

		// Rank Math SEO integration (schema, breadcrumbs, custom variables).
		$rank_math_integration = new \NetterTechEvents\Integrations\RankMath\RankMathIntegration();
		$rank_math_integration->init();

		// Seating add-on bridge: answer occurrence→space from the event's
		// explicit assignment (no-op when the Seating add-on is absent).
		$space_resolver = new \NetterTechEvents\Integrations\Seating\OccurrenceSpaceResolver(
			$this->container->get( OccurrenceRepositoryInterface::class ),
			$this->container->get( EventRepositoryInterface::class )
		);
		$space_resolver->register();

		// Seating add-on bridge: answer ticket-type→occurrence from core's
		// ticket-type model (no-op when the Seating add-on is absent).
		$occurrence_resolver = new \NetterTechEvents\Integrations\Seating\TicketTypeOccurrenceResolver(
			$this->container->get( TicketTypeRepositoryInterface::class )
		);
		$occurrence_resolver->register();
	}

	/**
	 * Initialize activity logging (OWASP A09 compliance).
	 *
	 * @since 0.9.2
	 *
	 * @return void
	 */
	private function init_activity_logging(): void {
		$hooks = $this->container->get( ActivityLogHooks::class );
		$hooks->register();
	}

	/**
	 * Register the ticket/product status sync service (NTE-177).
	 *
	 * Listens to the event published/unpublished lifecycle actions and flips the
	 * event's ticket types (and their WC products) between active and draft. The
	 * ProductManager is only wired when WooCommerce is active; when it is absent
	 * the service still keeps ticket statuses in step.
	 *
	 * @return void
	 */
	private function init_ticket_status_sync(): void {
		$product_manager = class_exists( 'WooCommerce' )
			? $this->container->get( \NetterTechEvents\Integrations\WooCommerce\ProductManager::class )
			: null;

		$service = new \NetterTechEvents\Services\TicketStatusSyncService(
			$this->container->get( TicketTypeRepositoryInterface::class ),
			$this->container->get( OccurrenceRepositoryInterface::class ),
			$product_manager instanceof \NetterTechEvents\Integrations\WooCommerce\ProductManager ? $product_manager : null
		);
		$service->register();
	}

	/**
	 * Initialize the bulk import handler.
	 *
	 * Listens for BULK_IMPORT_COMPLETED (fired by Migrator) and handles
	 * cache invalidation, activity logging, and WC stock sync.
	 *
	 * @since 3.6.0
	 *
	 * @return void
	 */
	private function init_bulk_import_handler(): void {
		$handler = new \NetterTechEvents\Services\BulkImportHandler(
			$this->container->get( ActivityLogServiceInterface::class ),
			$this->container->get( TicketTypeRepositoryInterface::class ),
			$this->container->get( OccurrenceRepositoryInterface::class )
		);
		$handler->register();
	}

	/**
	 * Initialize WordPress Privacy Tools (GDPR compliance).
	 *
	 * Registers data exporters, erasers, and retention cron.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function init_privacy_tools(): void {
		$service = $this->container->get( \NetterTechEvents\Services\PrivacyService::class );
		$hooks   = new PrivacyHooks( $service );
		$hooks->register();
	}

	/**
	 * Initialize reminder email cron job.
	 *
	 * Registers the hourly cron hook for sending event reminders.
	 *
	 * @since 0.9.5
	 *
	 * @return void
	 */
	private function init_reminder_emails(): void {
		$service = $this->container->get( \NetterTechEvents\Services\ReminderEmailService::class );
		$hooks   = new ReminderEmailHooks( $service );
		$hooks->register();
	}

	/**
	 * Initialize occurrence horizon extension cron job.
	 *
	 * Registers the daily cron listener that extends occurrence horizons
	 * for recurring events, ensuring occurrences are always generated
	 * into the future.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	private function init_occurrence_horizon_extender(): void {
		$extender = $this->container->get( \NetterTechEvents\Services\OccurrenceHorizonExtender::class );
		$extender->register();
	}

	/**
	 * Initialize reservation expiry sweep cron job.
	 *
	 * Registers the hourly cron hook that cleans up expired capacity
	 * reservations from abandoned carts.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	private function init_reservation_sweep(): void {
		$reservation_manager = $this->container->get( \NetterTechEvents\Contracts\ReservationManagerInterface::class );

		add_action( 'nettertech_events_sweep_expired_reservations', array( $reservation_manager, 'sweep_expired' ) );

		if ( ! wp_next_scheduled( 'nettertech_events_sweep_expired_reservations' ) ) {
			wp_schedule_event( time(), 'hourly', 'nettertech_events_sweep_expired_reservations' );
		}
	}

	/**
	 * Initialize the static iCal feed regeneration listener.
	 *
	 * Binds the debounced regeneration cron callback and, when static-feed
	 * mode is enabled, the event-lifecycle invalidation listeners. See
	 * NTE-016.
	 *
	 * @since 3.13.0
	 *
	 * @return void
	 */
	private function init_ical_static_feed(): void {
		$listener = $this->container->get( \NetterTechEvents\Services\ICalFeedRegenerationListener::class );
		$listener->register();
	}

	/**
	 * Initialize the RSVP capacity handler.
	 *
	 * Registers the listener that increments sold_count on successful RSVP
	 * submissions. Without this, free-event RSVPs never decrement the
	 * available capacity and the "event is full" panel never renders from
	 * RSVP activity alone — the WC order-completion path is the only thing
	 * that bumps sold_count and it never fires for free events.
	 *
	 * NTE-036.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	private function init_rsvp_capacity_handler(): void {
		$handler = $this->container->get( RsvpCapacityHandler::class );
		$handler->register();
	}

	/**
	 * Initialize waitlist promotion email handler.
	 *
	 * Registers the hook listener that sends notification emails when
	 * customers are promoted from the waitlist.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	private function init_waitlist_emails(): void {
		$handler = $this->container->get( \NetterTechEvents\Services\WaitlistEmailHandler::class );
		$handler->register();
	}

	/**
	 * Initialize waitlist frontend UI.
	 *
	 * Registers the frontend hooks for rendering the waitlist join panel
	 * on sold-out ticket types and RSVP events.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function init_waitlist_frontend(): void {
		$frontend = new \NetterTechEvents\Frontend\WaitlistFrontend(
			$this->container->get( \NetterTechEvents\Services\WaitlistAvailabilityResolver::class )
		);
		$frontend->init();
	}

	/**
	 * Initialize revision tracking for events.
	 *
	 * Captures pre-save snapshots and cleans up on delete.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	private function init_revisions(): void {
		$revision_service = $this->container->get( RevisionService::class );

		// Capture pre-save snapshot (old state from DB before new values are saved).
		add_action(
			'nettertech_events_before_save_event',
			array( $revision_service, 'capture_pre_save_snapshot' ),
			10,
			2
		);

		// Clean up revisions when an event is deleted.
		add_action(
			'nettertech_events_after_delete_event',
			array( $revision_service, 'cleanup_on_event_delete' ),
			10,
			2
		);
	}

	/**
	 * Register the event-delete cascade.
	 *
	 * Removes all child data (occurrences, ticket types, tickets, attendees,
	 * waitlist, reminder log, attendee fields, taxonomy/organizer junctions)
	 * before the event row is deleted. Runs on BEFORE_DELETE_EVENT so the
	 * event and its children still exist; covers both the single and bulk
	 * admin delete paths, which both route through EventRepository::delete().
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function init_event_deletion_cascade(): void {
		$cascade = $this->container->get( EventDeletionCascadeInterface::class );

		add_action(
			'nettertech_events_before_delete_event',
			array( $cascade, 'on_before_delete_event' )
		);
	}

	/**
	 * Register WP-CLI commands (NTE-129).
	 *
	 * No-op outside the WP-CLI runtime.
	 *
	 * @return void
	 */
	private function init_cli(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$product_manager = $this->container->get(
			\NetterTechEvents\Integrations\WooCommerce\ProductManager::class
		);
		if ( ! $product_manager instanceof \NetterTechEvents\Integrations\WooCommerce\ProductManager ) {
			return;
		}

		\WP_CLI::add_command(
			'nettertech-events backfill-product-cats',
			new \NetterTechEvents\Cli\ProductCategoryBackfillCommand( $product_manager )
		);

		// NTE-131 created_at UTC normalizer.
		\WP_CLI::add_command(
			'nettertech-events normalize-created-at',
			\NetterTechEvents\Cli\NormalizeCreatedAtCommand::class
		);

		// NTE-228: orphaned ticket product audit and prune.
		global $wpdb;
		$product_auditor = new \NetterTechEvents\Integrations\WooCommerce\TicketProductAuditor( $wpdb );
		\WP_CLI::add_command(
			'nettertech-events products audit',
			new \NetterTechEvents\Cli\ProductAuditCommand( $product_auditor )
		);
		\WP_CLI::add_command(
			'nettertech-events products prune',
			new \NetterTechEvents\Cli\ProductPruneCommand( $product_auditor )
		);

		// NTE-235: collapse stacked "{event} - {date} - " prefixes on ticket products.
		\WP_CLI::add_command(
			'nettertech-events products normalize-titles',
			new \NetterTechEvents\Cli\ProductTitleNormalizeCommand(
				new \NetterTechEvents\Integrations\WooCommerce\ProductTitleNormalizer(
					$wpdb,
					$this->container->get( OccurrenceRepositoryInterface::class ),
					$this->container->get( EventRepositoryInterface::class )
				)
			)
		);

		// NTE-226: copy each order's customer note onto attendees created before notes were recorded.
		\WP_CLI::add_command(
			'nettertech-events backfill-attendee-notes',
			new \NetterTechEvents\Cli\BackfillAttendeeNotesCommand( $wpdb )
		);

		// NTE-212: void attendees left confirmed by orders that never paid.
		if ( null !== $this->wc_integration ) {
			\WP_CLI::add_command(
				'nettertech-events reconcile-order-status',
				new \NetterTechEvents\Cli\ReconcileOrderStatusCommand( $this->wc_integration->get_order_handler() )
			);
		}
	}

	/**
	 * Get the cache manager instance.
	 *
	 * @since 0.1.0
	 *
	 * @return CacheManager
	 */
	public function get_cache_manager(): CacheManager {
		return $this->cache_manager;
	}

	/**
	 * Get the loader instance.
	 *
	 * @since 0.1.0
	 *
	 * @return Loader
	 */
	public function get_loader(): Loader {
		return $this->loader;
	}

	/**
	 * Get plugin version.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_version(): string {
		return NETTERTECH_EVENTS_VERSION;
	}
}
