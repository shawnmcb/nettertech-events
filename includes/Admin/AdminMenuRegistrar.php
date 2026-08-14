<?php
/**
 * Admin menu registrar.
 *
 * Handles WordPress admin menu and submenu page registration.
 * Extracted from AdminMenu to separate registration from rendering.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers admin menu pages with WordPress.
 *
 * @since 2.2.0
 */
class AdminMenuRegistrar {

	/**
	 * AdminMenu instance for render callbacks.
	 *
	 * @var AdminMenu
	 */
	private AdminMenu $admin_menu;

	/**
	 * Constructor.
	 *
	 * @param AdminMenu $admin_menu AdminMenu instance for render callbacks.
	 */
	public function __construct( AdminMenu $admin_menu ) {
		$this->admin_menu = $admin_menu;
	}

	/**
	 * Add primary menu pages (priority 10).
	 *
	 * Registers the main menu and primary workflow items:
	 * All Events, Add New, Organizers, Spaces, Categories, Attendees.
	 *
	 * Pro features (Reports, Promo Codes) hook at priority 20.
	 * Utility items are registered in add_utility_pages() at priority 30.
	 *
	 * @return void
	 */
	public function add_menu_pages(): void {
		// Main menu page.
		add_menu_page(
			__( 'NetterTech Events', 'nettertech-events' ),
			__( 'Events', 'nettertech-events' ),
			AdminMenu::CAPABILITY,
			AdminMenu::MENU_SLUG,
			'__return_empty_string',
			self::get_menu_icon(),
			26
		);

		// Events submenu (replaces default).
		$events_hook = add_submenu_page(
			AdminMenu::MENU_SLUG,
			__( 'All Events', 'nettertech-events' ),
			__( 'All Events', 'nettertech-events' ),
			AdminMenu::CAPABILITY,
			AdminMenu::MENU_SLUG,
			array( $this->admin_menu, 'render_events_page' )
		);

		if ( $events_hook ) {
			add_action( "load-{$events_hook}", array( $this->admin_menu, 'load_events_page' ) );
		}

		// Add New Event. Using "Add New Event" as the menu label (rather than
		// "Add New") so the intent is unambiguous when viewing sibling admin
		// pages like Organizers, Spaces, or Categories. Matches the WP 6.4+
		// core convention for CPT add-new menu items.
		add_submenu_page(
			AdminMenu::MENU_SLUG,
			__( 'Add New Event', 'nettertech-events' ),
			__( 'Add New Event', 'nettertech-events' ),
			AdminMenu::CAPABILITY,
			AdminMenu::SUBMENU_NEW,
			array( $this->admin_menu, 'render_edit_page' )
		);

		// Organizers submenu.
		add_submenu_page(
			AdminMenu::MENU_SLUG,
			__( 'Organizers', 'nettertech-events' ),
			__( 'Organizers', 'nettertech-events' ),
			AdminMenu::CAPABILITY,
			OrganizerPage::PAGE_SLUG,
			array( $this->admin_menu, 'render_organizers_page' )
		);

		// Spaces submenu.
		add_submenu_page(
			AdminMenu::MENU_SLUG,
			__( 'Spaces', 'nettertech-events' ),
			__( 'Spaces', 'nettertech-events' ),
			AdminMenu::CAPABILITY,
			SpacesPage::PAGE_SLUG,
			array( $this->admin_menu, 'render_spaces_page' )
		);

		// Categories submenu.
		add_submenu_page(
			AdminMenu::MENU_SLUG,
			__( 'Event Categories', 'nettertech-events' ),
			__( 'Categories', 'nettertech-events' ),
			AdminMenu::CAPABILITY,
			CategoryPage::PAGE_SLUG,
			array( $this->admin_menu, 'render_categories_page' )
		);

		// Attendees submenu.
		$attendees_hook = add_submenu_page(
			AdminMenu::MENU_SLUG,
			__( 'Attendees', 'nettertech-events' ),
			__( 'Attendees', 'nettertech-events' ),
			AdminMenu::CAPABILITY,
			AdminMenu::SUBMENU_ATTENDEES,
			array( $this->admin_menu, 'render_attendees_page' )
		);

		// Dispatch bulk/export actions on page load (before output) so the CSV
		// export can stream headers. Mirrors the events page above.
		if ( $attendees_hook ) {
			add_action( "load-{$attendees_hook}", array( $this->admin_menu, 'load_attendees_page' ) );
		}

		// Hidden edit page.
		add_submenu_page(
			'',
			__( 'Edit Event', 'nettertech-events' ),
			'',
			AdminMenu::CAPABILITY,
			AdminMenu::SUBMENU_EDIT,
			array( $this->admin_menu, 'render_edit_event_page' )
		);

		// Hidden per-occurrence edit page.
		add_submenu_page(
			'',
			__( 'Edit Date', 'nettertech-events' ),
			'',
			AdminMenu::CAPABILITY,
			AdminMenu::SUBMENU_EDIT_OCCURRENCE,
			array( $this->admin_menu, 'render_edit_occurrence_page' )
		);
	}

	/**
	 * Fire the register admin pages hook (priority 20).
	 *
	 * Pro uses this to register additional admin submenu pages under
	 * the NetterTech Events parent menu at the correct position.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	public function fire_register_admin_pages_hook(): void {
		do_action( 'nettertech_events_register_admin_pages' );
	}

	/**
	 * Add utility menu pages (priority 30).
	 *
	 * Registers utility and configuration items after Pro features:
	 * CSV Import, Activity Log, Settings.
	 *
	 * @return void
	 */
	public function add_utility_pages(): void {
		// QR Generator submenu.
		add_submenu_page(
			AdminMenu::MENU_SLUG,
			__( 'QR Generator', 'nettertech-events' ),
			__( 'QR Generator', 'nettertech-events' ),
			AdminMenu::CAPABILITY,
			AdminMenu::SUBMENU_QR_GENERATOR,
			array( $this->admin_menu, 'render_qr_generator_page' )
		);

		// CSV Import submenu.
		add_submenu_page(
			AdminMenu::MENU_SLUG,
			__( 'CSV Import', 'nettertech-events' ),
			__( 'CSV Import', 'nettertech-events' ),
			AdminMenu::CAPABILITY,
			AdminMenu::SUBMENU_CSV_IMPORT,
			array( $this->admin_menu, 'render_csv_import_page' )
		);

		// Activity Log submenu.
		add_submenu_page(
			AdminMenu::MENU_SLUG,
			__( 'Activity Log', 'nettertech-events' ),
			__( 'Activity Log', 'nettertech-events' ),
			AdminMenu::CAPABILITY,
			AdminMenu::SUBMENU_ACTIVITY_LOG,
			array( $this->admin_menu, 'render_activity_log_page' )
		);

		// Settings submenu.
		add_submenu_page(
			AdminMenu::MENU_SLUG,
			__( 'Settings', 'nettertech-events' ),
			__( 'Settings', 'nettertech-events' ),
			AdminMenu::CAPABILITY,
			AdminMenu::SUBMENU_SETTINGS,
			array( $this->admin_menu, 'render_settings_page' )
		);
	}

	/**
	 * Ticket-with-star icon path data (NTE-176), distinct from TEC's calendar
	 * icon. Single source for every surface that renders the event brand mark
	 * (dashboard menu, front-end admin-bar Edit node — NTE-194); render it via
	 * icon_svg() so consumers can't drift.
	 *
	 * @var string
	 */
	// phpcs:ignore Generic.Files.LineLength.TooLong -- SVG path data.
	private const ICON_PATH_D = 'M3.5 4.5h13A1.5 1.5 0 0118 6v2a2 2 0 000 4v2a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 012 14v-2a2 2 0 000-4V6A1.5 1.5 0 013.5 4.5zM3.5 5.7h13v2H16v-1.5H4v1.5h-0.5zM3.5 14.3h13v-2H16v1.5H4v-1.5h-0.5zM10 8.22L10.37 8.63L10.89 8.46L11 9L11.54 9.11L11.37 9.63L11.78 10L11.37 10.37L11.54 10.89L11 11L10.89 11.54L10.37 11.37L10 11.78L9.63 11.37L9.11 11.54L9 11L8.46 10.89L8.63 10.37L8.22 10L8.63 9.63L8.46 9.11L9 9L9.11 8.46L9.63 8.63z';

	/**
	 * Render the ticket-with-star icon as an inline SVG.
	 *
	 * @param string $fill  Fill color (hex or 'currentColor').
	 * @param string $style Optional inline style attribute value (e.g. sizing).
	 * @return string SVG markup.
	 */
	public static function icon_svg( string $fill, string $style = '' ): string {
		$style_attr = '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '';

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="' . esc_attr( $fill ) . '"' . $style_attr . '>'
			. '<path fill-rule="evenodd" d="' . self::ICON_PATH_D . '"/></svg>';
	}

	/**
	 * Get the custom menu icon as a base64-encoded SVG data URI.
	 *
	 * @return string SVG data URI for the menu icon.
	 */
	private static function get_menu_icon(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for SVG data URI.
		return 'data:image/svg+xml;base64,' . base64_encode( self::icon_svg( '#a7aaad' ) );
	}
}
