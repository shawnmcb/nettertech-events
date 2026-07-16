<?php
/**
 * Event metabox handler class.
 *
 * Handles rendering of all metaboxes in the event editor.
 * Delegates to specialized handlers for complex metaboxes.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\Metaboxes\AttendeeFieldsMetaboxHandler;
use NetterTechEvents\Admin\Metaboxes\DateTimeMetaboxHandler;
use NetterTechEvents\Admin\Metaboxes\EventMetaboxContentRenderer;
use NetterTechEvents\Admin\Metaboxes\LayoutMetaboxHandler;
use NetterTechEvents\Admin\Metaboxes\OrganizerMetaboxHandler;
use NetterTechEvents\Admin\Metaboxes\QRCodeMetaboxHandler;
use NetterTechEvents\Admin\Metaboxes\RevisionMetaboxHandler;
use NetterTechEvents\Admin\Metaboxes\SpaceMetaboxHandler;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;
use NetterTechEvents\Contracts\RevisionRepositoryInterface;
use NetterTechEvents\Admin\Metaboxes\TicketsMetabox;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;

/**
 * Handles rendering of event editor metaboxes.
 *
 * This class coordinates rendering of all metaboxes in the event editor,
 * delegating to specialized handlers for complex functionality.
 *
 * @since 0.8.0
 */
class EventMetaboxHandler {

	/**
	 * Event being edited.
	 *
	 * @var Event
	 */
	private Event $event;

	/**
	 * Event ID (0 for new).
	 *
	 * @var int
	 */
	private int $event_id;

	/**
	 * Date/time metabox handler.
	 *
	 * @var DateTimeMetaboxHandler
	 */
	private DateTimeMetaboxHandler $datetime_handler;

	/**
	 * Ticketing metabox handler.
	 *
	 * @var TicketsMetabox
	 */
	private TicketsMetabox $ticketing_handler;

	/**
	 * QR code metabox handler.
	 *
	 * @var QRCodeMetaboxHandler
	 */
	private QRCodeMetaboxHandler $qr_handler;

	/**
	 * Layout metabox handler.
	 *
	 * @var LayoutMetaboxHandler
	 */
	private LayoutMetaboxHandler $layout_handler;

	/**
	 * Category repository.
	 *
	 * @var CategoryRepositoryInterface
	 */
	private CategoryRepositoryInterface $category_repo;

	/**
	 * Revision repository.
	 *
	 * @var RevisionRepositoryInterface
	 */
	private RevisionRepositoryInterface $revision_repo;

	/**
	 * Attendee fields metabox handler (lazy-loaded).
	 *
	 * @var AttendeeFieldsMetaboxHandler|null
	 */
	private ?AttendeeFieldsMetaboxHandler $attendee_fields_handler = null;

	/**
	 * Organizer metabox handler (lazy-loaded).
	 *
	 * @var OrganizerMetaboxHandler|null
	 */
	private ?OrganizerMetaboxHandler $organizer_handler = null;

	/**
	 * Space metabox handler (lazy-loaded).
	 *
	 * @var SpaceMetaboxHandler|null
	 */
	private ?SpaceMetaboxHandler $space_handler = null;

	/**
	 * Constructor.
	 *
	 * @param Event                                    $event              Event being edited.
	 * @param int                                      $event_id           Event ID (0 for new).
	 * @param TicketTypeRepositoryInterface            $ticket_type_repo   Ticket type repository.
	 * @param CapacityServiceInterface                 $capacity_service   Capacity service.
	 * @param RecurrenceService                        $recurrence_service Recurrence service.
	 * @param RevisionRepositoryInterface              $revision_repo      Revision repository.
	 * @param \NetterTechEvents\Services\LayoutService $layout_service     Layout service.
	 * @param CategoryRepositoryInterface              $category_repo      Category repository.
	 */
	public function __construct(
		Event $event,
		int $event_id,
		TicketTypeRepositoryInterface $ticket_type_repo,
		CapacityServiceInterface $capacity_service,
		RecurrenceService $recurrence_service,
		RevisionRepositoryInterface $revision_repo,
		\NetterTechEvents\Services\LayoutService $layout_service,
		CategoryRepositoryInterface $category_repo
	) {
		$this->event         = $event;
		$this->event_id      = $event_id;
		$this->revision_repo = $revision_repo;
		$this->category_repo = $category_repo;

		// Initialize specialized handlers.
		$this->datetime_handler  = new DateTimeMetaboxHandler( $event, $recurrence_service );
		$this->ticketing_handler = new TicketsMetabox( $ticket_type_repo, $capacity_service );
		$this->layout_handler    = new LayoutMetaboxHandler( $event, $layout_service );
		$this->qr_handler        = new QRCodeMetaboxHandler( $event );
	}

	/**
	 * Render publish metabox.
	 *
	 * Thin facade over {@see EventMetaboxContentRenderer::render_publish_box()}.
	 *
	 * @return void
	 */
	public function render_publish_box(): void {
		$this->content_renderer()->render_publish_box();
	}


	/**
	 * Render the consolidated Schedule box: date/time fields, recurrence pattern,
	 * and the dates list in one postbox (NTE-159).
	 *
	 * @param Occurrence|null   $occurrence  First/current occurrence (seed for the date fields).
	 * @param array<Occurrence> $occurrences Upcoming occurrences for the dates list.
	 * @param int               $event_id    The event (0 = unsaved; dates section hidden).
	 * @return void
	 */
	public function render_schedule_box( ?Occurrence $occurrence, array $occurrences, int $event_id ): void {
		$this->datetime_handler->render_schedule( $occurrence, $occurrences, $event_id );
	}


	/**
	 * Render venue metabox.
	 *
	 * Thin facade over {@see EventMetaboxContentRenderer::render_venue_box()}.
	 *
	 * @return void
	 */
	public function render_venue_box(): void {
		$this->content_renderer()->render_venue_box();
	}

	/**
	 * Render categories metabox.
	 *
	 * Displays a checkbox list of categories from nettertech_events_categories table
	 * for assigning categories to the event. Thin facade over
	 * {@see EventMetaboxContentRenderer::render_categories_box()}.
	 *
	 * @since 0.9.5
	 *
	 * @return void
	 */
	public function render_categories_box(): void {
		$this->content_renderer()->render_categories_box();
	}

	/**
	 * Render tags metabox.
	 *
	 * Displays a comma-separated text input for assigning tags via the
	 * nettertech_event_tag taxonomy + nettertech_events_event_tags junction
	 * table. Thin facade over
	 * {@see EventMetaboxContentRenderer::render_tags_box()}.
	 *
	 * @return void
	 */
	public function render_tags_box(): void {
		$this->content_renderer()->render_tags_box();
	}

	/**
	 * Render featured image metabox.
	 *
	 * Thin facade over {@see EventMetaboxContentRenderer::render_featured_image_box()}.
	 *
	 * @return void
	 */
	public function render_featured_image_box(): void {
		$this->content_renderer()->render_featured_image_box();
	}

	/**
	 * Render page layout metabox.
	 *
	 * @return void
	 */
	public function render_layout_box(): void {
		$this->layout_handler->render();
	}

	/**
	 * Render layout live preview in the main content area.
	 *
	 * @return void
	 */
	public function render_layout_preview_box(): void {
		$this->layout_handler->render_preview();
	}

	/**
	 * Render event QR code metabox.
	 *
	 * @return void
	 */
	public function render_qr_code_box(): void {
		$this->qr_handler->render();
	}

	/**
	 * Render check-in settings metabox.
	 *
	 * Thin facade over {@see EventMetaboxContentRenderer::render_checkin_settings_box()}.
	 *
	 * @return void
	 */
	public function render_checkin_settings_box(): void {
		$this->content_renderer()->render_checkin_settings_box();
	}

	/**
	 * Render notification recipients metabox.
	 *
	 * Allows per-event email addresses to receive ticket purchase notifications
	 * in addition to the global venue contacts. Thin facade over
	 * {@see EventMetaboxContentRenderer::render_notification_recipients_box()}.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function render_notification_recipients_box(): void {
		$this->content_renderer()->render_notification_recipients_box();
	}

	/**
	 * Render event reminder settings box.
	 *
	 * Thin facade over {@see EventMetaboxContentRenderer::render_reminder_settings_box()}.
	 *
	 * @since 0.9.5
	 *
	 * @return void
	 */
	public function render_reminder_settings_box(): void {
		$this->content_renderer()->render_reminder_settings_box();
	}

	/**
	 * Render the revisions metabox.
	 *
	 * Shows revision history for existing events. Skips new events.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function render_revisions_box(): void {
		$event_id = $this->event->id ?? 0;
		if ( 0 === $event_id ) {
			return;
		}

		$handler = new RevisionMetaboxHandler( $this->revision_repo );
		$handler->render( $event_id );
	}


	/**
	 * Render ticket types metabox.
	 *
	 * @param Occurrence|null $occurrence Current occurrence (for single events).
	 * @return void
	 */
	public function render_ticket_types_box( ?Occurrence $occurrence ): void {
		$this->ticketing_handler->set_context( $this->event, $occurrence )->render();
	}

	/**
	 * Set the attendee field repository for the fields metabox.
	 *
	 * @param AttendeeFieldRepositoryInterface $field_repo Field repository.
	 * @return void
	 */
	public function set_attendee_field_repo( AttendeeFieldRepositoryInterface $field_repo ): void {
		$this->attendee_fields_handler = new AttendeeFieldsMetaboxHandler( $this->event, $field_repo );
	}

	/**
	 * Set the organizer repository for the organizer metabox.
	 *
	 * @param OrganizerRepositoryInterface $organizer_repo Organizer repository.
	 * @return void
	 */
	public function set_organizer_repo( OrganizerRepositoryInterface $organizer_repo ): void {
		$this->organizer_handler = new OrganizerMetaboxHandler( $this->event_id, $organizer_repo );
	}

	/**
	 * Render the organizer assignment metabox.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	public function render_organizers_box(): void {
		if ( null !== $this->organizer_handler ) {
			$this->organizer_handler->render();
		}
	}

	/**
	 * Set the space repository for the space metabox.
	 *
	 * @param SpaceRepositoryInterface $space_repo Space repository.
	 * @return void
	 */
	public function set_space_repo( SpaceRepositoryInterface $space_repo ): void {
		$this->space_handler = new SpaceMetaboxHandler( $space_repo, $this->event->space_id );
	}

	/**
	 * Render the space assignment metabox.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function render_space_box(): void {
		if ( null !== $this->space_handler ) {
			$this->space_handler->render();
		}
	}

	/**
	 * Render virtual/hybrid event settings metabox.
	 *
	 * Provides a checkbox to mark the event as virtual and a URL input
	 * for the virtual access link.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function render_virtual_settings_box(): void {
		$this->content_renderer()->render_virtual_settings_box();
	}

	/**
	 * Render custom fields metabox (read-only).
	 *
	 * Displays custom key-value data attached to the event by integrations
	 * or import tools. Only shown when custom_fields is non-empty.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function render_custom_fields_box(): void {
		$this->content_renderer()->render_custom_fields_box();
	}

	/**
	 * Render the attendee registration fields metabox.
	 *
	 * @since 3.6.0
	 *
	 * @return void
	 */
	public function render_attendee_fields_box(): void {
		if ( null !== $this->attendee_fields_handler ) {
			$this->attendee_fields_handler->render();
		}
	}

	/**
	 * Lazily build the inline-content renderer collaborator.
	 *
	 * @return EventMetaboxContentRenderer
	 */
	private function content_renderer(): EventMetaboxContentRenderer {
		return new EventMetaboxContentRenderer(
			$this->event,
			$this->event_id,
			$this->category_repo
		);
	}
}
