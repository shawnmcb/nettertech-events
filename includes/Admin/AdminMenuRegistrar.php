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
	 * Get the custom menu icon as a base64-encoded SVG data URI.
	 *
	 * @return string SVG data URI for the menu icon.
	 */
	private static function get_menu_icon(): string {
		// Ticket-shaped icon distinct from TEC's calendar icon.
		// phpcs:ignore Generic.Files.LineLength.TooLong -- Base64 SVG data URI.
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#a0a5aa"><g transform="translate(0 1) scale(1 0.9)"><path d="M2 4.5A1.5 1.5 0 013.5 3h13A1.5 1.5 0 0118 4.5v2.879a.5.5 0 01-.354.476A2 2 0 0016 9.787v.426a2 2 0 001.646 1.932.5.5 0 01.354.476V15.5a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 012 15.5v-2.879a.5.5 0 01.354-.476A2 2 0 004 10.213v-.426a2 2 0 00-1.646-1.932A.5.5 0 012 7.379V4.5z"/><path d="M7 7h6v1H7zM6 9h8v5H6z" fill-opacity="0.3"/></g></svg>';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for SVG data URI.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
