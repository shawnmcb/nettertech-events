<?php
/**
 * Admin menu class.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Services\CsvColumnMapper;
use NetterTechEvents\Services\CsvImporter;
use NetterTechEvents\Services\CsvParser;
use NetterTechEvents\Services\LayoutService;

/**
 * Registers and manages the admin menu structure.
 *
 * @since 0.8.0
 */
class AdminMenu {

	/**
	 * Top-level menu slug.
	 *
	 * @var string
	 */
	public const MENU_SLUG = 'nettertech-events';

	/**
	 * Submenu slug: New Event.
	 *
	 * @var string
	 */
	public const SUBMENU_NEW = 'nettertech-events-new';

	/**
	 * Submenu slug: Edit Event (single-event editor).
	 *
	 * @var string
	 */
	public const SUBMENU_EDIT = 'nettertech-events-edit';

	/**
	 * Submenu slug: Edit Occurrence (per-occurrence editor).
	 *
	 * @var string
	 */
	public const SUBMENU_EDIT_OCCURRENCE = 'nettertech-events-edit-occurrence';

	/**
	 * Submenu slug: Attendees list.
	 *
	 * @var string
	 */
	public const SUBMENU_ATTENDEES = 'nettertech-events-attendees';

	/**
	 * Submenu slug: QR Code generator.
	 *
	 * @var string
	 */
	public const SUBMENU_QR_GENERATOR = 'nettertech-events-qr-generator';

	/**
	 * Submenu slug: CSV import.
	 *
	 * @var string
	 */
	public const SUBMENU_CSV_IMPORT = 'nettertech-events-csv-import';

	/**
	 * Submenu slug: Activity log.
	 *
	 * @var string
	 */
	public const SUBMENU_ACTIVITY_LOG = 'nettertech-events-activity-log';

	/**
	 * Submenu slug: Settings.
	 *
	 * @var string
	 */
	public const SUBMENU_SETTINGS = 'nettertech-events-settings';

	/**
	 * Capability required to access the menu.
	 *
	 * @var string
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Events page handler.
	 *
	 * @var EventsPage
	 */
	private EventsPage $events_page;

	/**
	 * Menu registrar.
	 *
	 * @var AdminMenuRegistrar
	 */
	private AdminMenuRegistrar $registrar;

	/**
	 * Events list table instance (created early for Screen Options).
	 *
	 * @var ListTables\EventsListTable|null
	 */
	private ?ListTables\EventsListTable $events_list_table = null;

	/**
	 * Constructor.
	 *
	 * @param EventsPage $events_page Events page handler.
	 */
	public function __construct( EventsPage $events_page ) {
		$this->events_page = $events_page;

		$this->registrar = new AdminMenuRegistrar( $this );
	}

	/**
	 * Register admin menu hooks.
	 *
	 * Menu items are registered at different priorities for logical grouping:
	 * - Priority 10: Primary items (All Events, Add New, Categories, Attendees, Check-In)
	 * - Priority 20: Pro features hook here (Reports, Promo Codes)
	 * - Priority 30: Utility items (QR Generator, Activity Log, Settings)
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this->registrar, 'add_menu_pages' ) );
		add_action( 'admin_menu', array( $this->registrar, 'fire_register_admin_pages_hook' ), 20 );
		add_action( 'admin_menu', array( $this->registrar, 'add_utility_pages' ), 30 );
		add_filter( 'parent_file', array( $this, 'fix_taxonomy_parent_menu' ) );
		add_filter( 'admin_title', array( $this, 'filter_admin_title' ), 10, 2 );
		add_filter( 'set-screen-option', array( $this, 'save_events_per_page' ), 10, 3 );

		// Return 404 for unauthenticated users instead of revealing page existence.
		add_action( 'admin_init', array( $this, 'maybe_return_404' ), 1 );
	}

	/**
	 * Return 404 for unauthenticated users on our admin pages.
	 *
	 * WordPress shows "Sorry, you are not allowed to access this page" for
	 * unauthenticated users, which reveals page existence. We return 404
	 * instead to reduce attack surface.
	 *
	 * @return void
	 */
	public function maybe_return_404(): void {
		$page = AdminRequest::get_text( 'page' );

		if ( empty( $page ) || 0 !== strpos( $page, self::MENU_SLUG ) ) {
			return; // Not our page.
		}

		// If user is logged in but lacks capability, let WordPress handle it.
		if ( is_user_logged_in() ) {
			return;
		}

		// User is not logged in - return 404 to hide page existence.
		// Use WordPress's global $wp_query to set 404 state for theme compatibility.
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
		include get_404_template();
		exit( 0 );
	}

	/**
	 * Fix parent menu highlighting for taxonomy pages.
	 *
	 * @param string $parent_file Current parent file.
	 * @return string
	 */
	public function fix_taxonomy_parent_menu( string $parent_file ): string {
		global $current_screen;

		if ( $current_screen && \NetterTechEvents\Core\Taxonomies::EVENT_CATEGORY === $current_screen->taxonomy ) {
			return self::MENU_SLUG;
		}

		return $parent_file;
	}

	/**
	 * Filter the browser tab title for event admin pages.
	 *
	 * WordPress sets the tab title from the submenu page_title registered in
	 * add_submenu_page(). When the events list page routes to the edit view
	 * via ?action=edit, the title stays "All Events". This filter replaces it
	 * with "Edit Event" or "Add New Event" as appropriate.
	 *
	 * @param string $admin_title The full admin title (Page Title &lsaquo; Site &mdash; WordPress).
	 * @param string $title       The page title portion.
	 * @return string Filtered admin title.
	 */
	public function filter_admin_title( string $admin_title, string $title ): string {
		$page = AdminRequest::get_text( 'page' );

		if ( self::MENU_SLUG !== $page ) {
			return $admin_title;
		}

		$action = AdminRequest::get_key( 'action' );

		if ( 'edit' === $action ) {
			$new_title = __( 'Edit Event', 'nettertech-events' );
			return str_replace( $title, $new_title, $admin_title );
		}

		return $admin_title;
	}


	/**
	 * Handle load hook for the events list page.
	 *
	 * Registers Screen Options (per-page count, column visibility) and
	 * instantiates the list table early so WP_List_Table can register columns.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the container returns an unexpected type for EventsListTable.
	 */
	public function load_events_page(): void {
		// Register per-page screen option.
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Events', 'nettertech-events' ),
				'default' => 20,
				'option'  => 'events_per_page',
			)
		);

		// Resolve list table from container so the dependency graph (repos,
		// services) flows through the DI binding rather than per-render `new`
		// (closes SA-19). Container instance is acceptable here per ADR-013:
		// AdminMenu page-load callbacks are view-edge code where constructor
		// injection isn't viable (WordPress admin_menu wiring binds method
		// names, not service factories).
		$list_table = \NetterTechEvents\nettertech_events_container()->get( ListTables\EventsListTable::class );
		if ( ! $list_table instanceof ListTables\EventsListTable ) {
			throw new \RuntimeException( 'Expected EventsListTable instance from container.' );
		}
		$this->events_list_table = $list_table;

		// Add contextual help tabs.
		$screen = get_current_screen();
		if ( $screen ) {
			$screen->add_help_tab(
				array(
					'id'      => 'nte-help-managing-events',
					'title'   => __( 'Managing Events', 'nettertech-events' ),
					'content' => '<h3>' . esc_html__( 'Managing Events', 'nettertech-events' ) . '</h3>'
						. '<p>' . esc_html__( 'This screen lists all your events. You can filter, sort, search, and manage events from here.', 'nettertech-events' ) . '</p>'
						. '<ul>'
						. '<li>' . esc_html__( 'Use the status and type dropdowns to filter the list. Click "Clear" to reset all filters.', 'nettertech-events' ) . '</li>'
						. '<li>' . esc_html__( 'Click a column header to sort by that column. Click again to reverse the order.', 'nettertech-events' ) . '</li>'
						. '<li>' . esc_html__( 'Use the search box to find events by title.', 'nettertech-events' ) . '</li>'
						. '<li>' . esc_html__( 'Select multiple events with the checkboxes, then choose a bulk action (Delete, Publish, Set to Draft, Duplicate, or manage Categories).', 'nettertech-events' ) . '</li>'
						. '<li>' . esc_html__( 'Hover over an event row to see quick actions: Edit, Quick Edit, Duplicate, and Delete.', 'nettertech-events' ) . '</li>'
						. '</ul>'
						. '<p>' . esc_html__( 'Use Screen Options (top right) to choose which columns to display and how many events to show per page.', 'nettertech-events' ) . '</p>',
				)
			);

			$screen->add_help_tab(
				array(
					'id'      => 'nte-help-types-statuses',
					'title'   => __( 'Types & Statuses', 'nettertech-events' ),
					'content' => '<h3>' . esc_html__( 'Event Types', 'nettertech-events' ) . '</h3>'
						. '<ul>'
						. '<li><strong>' . esc_html__( 'Single', 'nettertech-events' ) . '</strong> &mdash; ' . esc_html__( 'A one-time event on a specific date.', 'nettertech-events' ) . '</li>'
						. '<li><strong>' . esc_html__( 'Recurring', 'nettertech-events' ) . '</strong> &mdash; ' . esc_html__( 'An event that repeats on a schedule (daily, weekly, monthly). Each occurrence can be managed individually.', 'nettertech-events' ) . '</li>'
						. '<li><strong>' . esc_html__( 'Series', 'nettertech-events' ) . '</strong> &mdash; ' . esc_html__( 'A parent event that groups related occurrences together.', 'nettertech-events' ) . '</li>'
						. '</ul>'
						. '<h3>' . esc_html__( 'Event Statuses', 'nettertech-events' ) . '</h3>'
						. '<ul>'
						. '<li><strong>' . esc_html__( 'Published', 'nettertech-events' ) . '</strong> &mdash; ' . esc_html__( 'Visible to the public on your site.', 'nettertech-events' ) . '</li>'
						. '<li><strong>' . esc_html__( 'Draft', 'nettertech-events' ) . '</strong> &mdash; ' . esc_html__( 'Not visible to the public. Use drafts to prepare events before publishing.', 'nettertech-events' ) . '</li>'
						. '<li><strong>' . esc_html__( 'Cancelled', 'nettertech-events' ) . '</strong> &mdash; ' . esc_html__( 'The event has been cancelled. It may still appear on the site with a cancelled notice.', 'nettertech-events' ) . '</li>'
						. '<li><strong>' . esc_html__( 'Postponed', 'nettertech-events' ) . '</strong> &mdash; ' . esc_html__( 'The event has been postponed to a later date. The new date can be set when rescheduling.', 'nettertech-events' ) . '</li>'
						. '</ul>',
				)
			);

			$screen->set_help_sidebar(
				'<p><strong>' . esc_html__( 'For more information:', 'nettertech-events' ) . '</strong></p>'
				. '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::SUBMENU_SETTINGS ) ) . '">' . esc_html__( 'NetterTech Events Settings', 'nettertech-events' ) . '</a></p>'
			);
		}
	}

	/**
	 * Save the events per-page screen option.
	 *
	 * @param mixed  $status Current option status (false to skip saving).
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed The sanitized value to save, or $status to skip.
	 */
	public function save_events_per_page( mixed $status, string $option, mixed $value ): mixed {
		if ( 'events_per_page' === $option ) {
			return absint( $value );
		}

		return $status;
	}

	/**
	 * Render the organizers page.
	 *
	 * @return void
	 */
	public function render_organizers_page(): void {
		\NetterTechEvents\nettertech_events_container()->get( OrganizerPage::class )->render();
	}

	/**
	 * Render the spaces page.
	 *
	 * @return void
	 */
	public function render_spaces_page(): void {
		\NetterTechEvents\nettertech_events_container()->get( SpacesPage::class )->render();
	}

	/**
	 * Render the categories page.
	 *
	 * @return void
	 */
	public function render_categories_page(): void {
		\NetterTechEvents\nettertech_events_container()->get( CategoryPage::class )->render();
	}

	/**
	 * Render the events list page.
	 *
	 * @return void
	 */
	public function render_events_page(): void {
		if ( $this->events_list_table ) {
			$this->events_page->set_events_list_table( $this->events_list_table );
		}
		$this->events_page->render();
	}

	/**
	 * Render the edit event page (hidden menu item).
	 *
	 * Handles the nettertech-events-edit page URL pattern.
	 * Accepts event ID via ?event=, ?id=, or ?event_id= parameters.
	 *
	 * @return void
	 */
	public function render_edit_event_page(): void {
		$this->events_page->render_edit_event();
	}

	/**
	 * Render the per-occurrence editor page (hidden menu item).
	 *
	 * Handles the nettertech-events-edit-occurrence page URL pattern.
	 * Accepts the occurrence ID via the ?occurrence_id= parameter.
	 *
	 * @return void
	 */
	public function render_edit_occurrence_page(): void {
		$this->events_page->render_edit_occurrence();
	}

	/**
	 * Render the edit page for existing events.
	 *
	 * @return void
	 */
	public function render_edit_page(): void {
		$this->events_page->render_edit();
	}

	/**
	 * Render the attendees page.
	 *
	 * @return void
	 */
	public function render_attendees_page(): void {
		\NetterTechEvents\nettertech_events_container()->get( AttendeesPage::class )->render();
	}

	/**
	 * Fires on the attendees page load-{hook}, before any output, so the CSV
	 * export can stream its headers (running it during render() was too late).
	 *
	 * @return void
	 */
	public function load_attendees_page(): void {
		\NetterTechEvents\nettertech_events_container()->get( AttendeesPage::class )->handle_actions();
	}

	/**
	 * Render the CSV import page.
	 *
	 * @return void
	 */
	public function render_csv_import_page(): void {
		\NetterTechEvents\nettertech_events_container()->get( CsvImportPage::class )->render();
	}

	/**
	 * Render the activity log page.
	 *
	 * @return void
	 */
	public function render_activity_log_page(): void {
		\NetterTechEvents\nettertech_events_container()->get( ActivityLogPage::class )->render();
	}

	/**
	 * Render the settings page.
	 *
	 * Delegates to SettingsPage class for rendering and form handling.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		\NetterTechEvents\nettertech_events_container()->get( SettingsPage::class )->render();
	}

	/**
	 * Render the QR Generator page.
	 *
	 * @return void
	 */
	public function render_qr_generator_page(): void {
		$page = new QRGeneratorPage();
		$page->render();
	}
}
