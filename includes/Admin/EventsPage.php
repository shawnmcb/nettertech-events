<?php
/**
 * Events admin page.
 *
 * Handles rendering for the events list, add new, and edit event pages.
 * Extracted from AdminMenu to reduce god-class complexity.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;
use NetterTechEvents\Contracts\RevisionRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Services\LayoutService;
use NetterTechEvents\Services\RecurrenceService;

/**
 * Renders the events list and event editor admin pages.
 *
 * @since 2.2.0
 */
class EventsPage {

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
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Capacity service.
	 *
	 * @var CapacityServiceInterface
	 */
	private CapacityServiceInterface $capacity_service;

	/**
	 * Recurrence service.
	 *
	 * @var RecurrenceService
	 */
	private RecurrenceService $recurrence_service;

	/**
	 * Revision repository.
	 *
	 * @var RevisionRepositoryInterface
	 */
	private RevisionRepositoryInterface $revision_repo;

	/**
	 * Attendee field repository.
	 *
	 * @var AttendeeFieldRepositoryInterface
	 */
	private AttendeeFieldRepositoryInterface $attendee_field_repo;

	/**
	 * Layout service.
	 *
	 * @var LayoutService
	 */
	private LayoutService $layout_service;

	/**
	 * Category repository.
	 *
	 * @var CategoryRepositoryInterface
	 */
	private CategoryRepositoryInterface $category_repo;

	/**
	 * Organizer repository (organizer metabox is not wired when null).
	 *
	 * @var OrganizerRepositoryInterface|null
	 */
	private ?OrganizerRepositoryInterface $organizer_repo;

	/**
	 * Space repository (space metabox is not wired when null).
	 *
	 * @var SpaceRepositoryInterface|null
	 */
	private ?SpaceRepositoryInterface $space_repo;

	/**
	 * Events list table instance (created early for Screen Options).
	 *
	 * @var ListTables\EventsListTable|null
	 */
	private ?ListTables\EventsListTable $events_list_table = null;

	/**
	 * Constructor.
	 *
	 * @param EventRepositoryInterface          $event_repo          Event repository.
	 * @param OccurrenceRepositoryInterface     $occurrence_repo     Occurrence repository.
	 * @param TicketTypeRepositoryInterface     $ticket_type_repo    Ticket type repository.
	 * @param CapacityServiceInterface          $capacity_service    Capacity service.
	 * @param RecurrenceService                 $recurrence_service  Recurrence service.
	 * @param RevisionRepositoryInterface       $revision_repo       Revision repository.
	 * @param AttendeeFieldRepositoryInterface  $attendee_field_repo Attendee field repository.
	 * @param LayoutService                     $layout_service      Layout service.
	 * @param CategoryRepositoryInterface       $category_repo       Category repository.
	 * @param OrganizerRepositoryInterface|null $organizer_repo     Organizer repository (organizer metabox is not wired when null).
	 * @param SpaceRepositoryInterface|null     $space_repo         Space repository (space metabox is not wired when null).
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		TicketTypeRepositoryInterface $ticket_type_repo,
		CapacityServiceInterface $capacity_service,
		RecurrenceService $recurrence_service,
		RevisionRepositoryInterface $revision_repo,
		AttendeeFieldRepositoryInterface $attendee_field_repo,
		LayoutService $layout_service,
		CategoryRepositoryInterface $category_repo,
		?OrganizerRepositoryInterface $organizer_repo = null,
		?SpaceRepositoryInterface $space_repo = null
	) {
		$this->event_repo          = $event_repo;
		$this->occurrence_repo     = $occurrence_repo;
		$this->ticket_type_repo    = $ticket_type_repo;
		$this->capacity_service    = $capacity_service;
		$this->recurrence_service  = $recurrence_service;
		$this->revision_repo       = $revision_repo;
		$this->attendee_field_repo = $attendee_field_repo;
		$this->layout_service      = $layout_service;
		$this->category_repo       = $category_repo;
		$this->organizer_repo      = $organizer_repo;
		$this->space_repo          = $space_repo;
	}

	/**
	 * Set the events list table instance (created early for Screen Options).
	 *
	 * @param ListTables\EventsListTable $list_table List table instance.
	 * @return void
	 */
	public function set_events_list_table( ListTables\EventsListTable $list_table ): void {
		$this->events_list_table = $list_table;
	}

	/**
	 * Render the events list page.
	 *
	 * Routes to the edit view when action=edit, otherwise renders the list.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( 'edit' === AdminRequest::get_key( 'action' ) && AdminRequest::has( 'event_id' ) ) {
			$this->render_edit();
			return;
		}

		$this->render_list();
	}

	/**
	 * Render the edit event page (hidden menu item).
	 *
	 * Handles the nettertech-events-edit page URL pattern.
	 * Accepts event ID via ?event=, ?id=, or ?event_id= parameters.
	 *
	 * @return void
	 */
	public function render_edit_event(): void {
		// Get event ID from various parameter names for flexibility.
		$event_id = AdminRequest::get_absint( 'event' );
		if ( ! $event_id ) {
			$event_id = AdminRequest::get_absint( 'id' );
		}
		if ( ! $event_id ) {
			$event_id = AdminRequest::get_absint( 'event_id' );
		}

		$this->create_editor( $event_id )->render();
	}

	/**
	 * Render the edit/add new page for events.
	 *
	 * @return void
	 */
	public function render_edit(): void {
		$event_id = AdminRequest::get_absint( 'event_id' );
		$this->create_editor( $event_id )->render();
	}

	/**
	 * Render the per-occurrence editor page (hidden menu item).
	 *
	 * Handles the nettertech-events-edit-occurrence page URL pattern.
	 * Accepts the occurrence ID via the ?occurrence_id= parameter.
	 *
	 * @return void
	 */
	public function render_edit_occurrence(): void {
		$occurrence_id = AdminRequest::get_absint( 'occurrence_id' );
		$this->create_occurrence_editor( $occurrence_id )->render();
	}

	/**
	 * Render the events list view.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the container returns an unexpected type for EventsListTable.
	 */
	private function render_list(): void {
		// Use list table created during load hook (for Screen Options), or
		// resolve from container as fallback. Container resolution replaces
		// the prior inline `new` dependency graph (closes SA-19).
		if ( $this->events_list_table ) {
			$list_table = $this->events_list_table;
		} else {
			$list_table = \NetterTechEvents\nettertech_events_container()->get( ListTables\EventsListTable::class );
			if ( ! $list_table instanceof ListTables\EventsListTable ) {
				throw new \RuntimeException( 'Expected EventsListTable instance from container.' );
			}
		}
		$list_table->prepare_items();

		?>
		<a class="nte-skip-link screen-reader-text" href="#nte-main-content">
			<?php esc_html_e( 'Skip to main content', 'nettertech-events' ); ?>
		</a>
		<div id="nte-main-content" class="wrap" tabindex="-1">
			<?php Branding::render_header(); ?>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Events', 'nettertech-events' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::SUBMENU_NEW ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'nettertech-events' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php $this->render_admin_notices(); ?>

			<form method="get" id="nte-events-list-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( AdminMenu::MENU_SLUG ); ?>">
				<?php
				$list_table->search_box( __( 'Search Events', 'nettertech-events' ), 'event-search' );
				wp_add_inline_script(
					'nettertech-events-admin',
					'document.getElementById("event-search-search-input")?.setAttribute("placeholder",' . wp_json_encode( __( 'Search events…', 'nettertech-events' ) ) . ');'
				);
				$list_table->display();
				?>
			</form>
			<?php
			$list_table->inline_edit();
			$list_table->render_category_modal();
			?>
		</div>
		<?php
	}

	/**
	 * Create an EventEditor instance.
	 *
	 * @param int $event_id Event ID (0 for new).
	 * @return EventEditor
	 */
	private function create_editor( int $event_id ): EventEditor {
		return new EventEditor(
			$event_id,
			$this->event_repo,
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->capacity_service,
			$this->recurrence_service,
			$this->revision_repo,
			$this->attendee_field_repo,
			$this->layout_service,
			$this->category_repo,
			$this->organizer_repo,
			$this->space_repo
		);
	}

	/**
	 * Create an OccurrenceEditor instance.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return OccurrenceEditor
	 */
	private function create_occurrence_editor( int $occurrence_id ): OccurrenceEditor {
		return new OccurrenceEditor(
			$occurrence_id,
			$this->occurrence_repo,
			$this->event_repo,
			$this->ticket_type_repo,
			$this->capacity_service
		);
	}

	/**
	 * Render admin notices for event actions.
	 *
	 * @return void
	 */
	private function render_admin_notices(): void {
		settings_errors( 'nettertech_events_settings' );

		if ( ! AdminRequest::has( 'message' ) ) {
			return;
		}

		$message = '';
		$type    = 'success';

		switch ( AdminRequest::get_key( 'message' ) ) {
			case 'created':
				$message = __( 'Event created successfully.', 'nettertech-events' );
				break;
			case 'updated':
				$message = __( 'Event updated successfully.', 'nettertech-events' );
				break;
			case 'duplicated':
				$message = __( 'Event duplicated. You are now editing the copy.', 'nettertech-events' );
				break;
			case 'deleted':
				$message = __( 'Event deleted.', 'nettertech-events' );
				break;
			case 'has_sales':
				$message = __( 'This event has ticket sales and was not deleted. To preserve order history, cancel or refund its orders first, then delete the event.', 'nettertech-events' );
				$type    = 'error';
				break;
			case 'bulk_skipped':
				$skipped = absint( AdminRequest::get_text( 'skipped' ) );
				$message = sprintf(
					/* translators: %d: number of events that were skipped because they have ticket sales. */
					_n(
						'%d event with ticket sales was skipped to preserve order history. Events without sales were deleted.',
						'%d events with ticket sales were skipped to preserve order history. Events without sales were deleted.',
						$skipped,
						'nettertech-events'
					),
					$skipped
				);
				$type = 'warning';
				break;
			case 'error':
				$message = __( 'An error occurred.', 'nettertech-events' );
				$type    = 'error';
				break;
		}

		if ( $message ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				esc_html( $message )
			);
		}
	}
}
