<?php
/**
 * Event Deletion Cascade Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventDeletionCascadeInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Contracts\ReminderLogRepositoryInterface;
use NetterTechEvents\Contracts\ReservationManagerInterface;
use NetterTechEvents\Contracts\TagRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Contracts\WaitlistRepositoryInterface;
use NetterTechEvents\Models\Event;

/**
 * Removes all child data of an event before the event row is deleted.
 *
 * Hooked to BEFORE_DELETE_EVENT so it runs while the event and its children
 * still exist. Deleting ticket types fires TICKET_TYPE_DELETED, which drives
 * WooCommerce product cleanup and activity logging.
 *
 * @since 1.0.0
 */
class EventDeletionCascade implements EventDeletionCascadeInterface {

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
	 * Ticket repository.
	 *
	 * @var TicketRepositoryInterface
	 */
	private TicketRepositoryInterface $ticket_repo;

	/**
	 * Attendee repository.
	 *
	 * @var AttendeeRepositoryInterface
	 */
	private AttendeeRepositoryInterface $attendee_repo;

	/**
	 * Attendee field value repository.
	 *
	 * @var AttendeeFieldValueRepositoryInterface
	 */
	private AttendeeFieldValueRepositoryInterface $attendee_field_value_repo;

	/**
	 * Attendee field repository.
	 *
	 * @var AttendeeFieldRepositoryInterface
	 */
	private AttendeeFieldRepositoryInterface $attendee_field_repo;

	/**
	 * Waitlist repository.
	 *
	 * @var WaitlistRepositoryInterface
	 */
	private WaitlistRepositoryInterface $waitlist_repo;

	/**
	 * Reminder log repository.
	 *
	 * @var ReminderLogRepositoryInterface
	 */
	private ReminderLogRepositoryInterface $reminder_log_repo;

	/**
	 * Category repository.
	 *
	 * @var CategoryRepositoryInterface
	 */
	private CategoryRepositoryInterface $category_repo;

	/**
	 * Tag repository.
	 *
	 * @var TagRepositoryInterface
	 */
	private TagRepositoryInterface $tag_repo;

	/**
	 * Organizer repository.
	 *
	 * @var OrganizerRepositoryInterface
	 */
	private OrganizerRepositoryInterface $organizer_repo;

	/**
	 * Reservation manager.
	 *
	 * @var ReservationManagerInterface
	 */
	private ReservationManagerInterface $reservation_manager;

	/**
	 * Constructor.
	 *
	 * @param OccurrenceRepositoryInterface         $occurrence_repo           Occurrence repository.
	 * @param TicketTypeRepositoryInterface         $ticket_type_repo          Ticket type repository.
	 * @param TicketRepositoryInterface             $ticket_repo               Ticket repository.
	 * @param AttendeeRepositoryInterface           $attendee_repo             Attendee repository.
	 * @param AttendeeFieldValueRepositoryInterface $attendee_field_value_repo Attendee field value repository.
	 * @param AttendeeFieldRepositoryInterface      $attendee_field_repo       Attendee field repository.
	 * @param WaitlistRepositoryInterface           $waitlist_repo             Waitlist repository.
	 * @param ReminderLogRepositoryInterface        $reminder_log_repo         Reminder log repository.
	 * @param CategoryRepositoryInterface           $category_repo             Category repository.
	 * @param TagRepositoryInterface                $tag_repo                  Tag repository.
	 * @param OrganizerRepositoryInterface          $organizer_repo            Organizer repository.
	 * @param ReservationManagerInterface           $reservation_manager       Reservation manager.
	 */
	public function __construct(
		OccurrenceRepositoryInterface $occurrence_repo,
		TicketTypeRepositoryInterface $ticket_type_repo,
		TicketRepositoryInterface $ticket_repo,
		AttendeeRepositoryInterface $attendee_repo,
		AttendeeFieldValueRepositoryInterface $attendee_field_value_repo,
		AttendeeFieldRepositoryInterface $attendee_field_repo,
		WaitlistRepositoryInterface $waitlist_repo,
		ReminderLogRepositoryInterface $reminder_log_repo,
		CategoryRepositoryInterface $category_repo,
		TagRepositoryInterface $tag_repo,
		OrganizerRepositoryInterface $organizer_repo,
		ReservationManagerInterface $reservation_manager
	) {
		$this->occurrence_repo           = $occurrence_repo;
		$this->ticket_type_repo          = $ticket_type_repo;
		$this->ticket_repo               = $ticket_repo;
		$this->attendee_repo             = $attendee_repo;
		$this->attendee_field_value_repo = $attendee_field_value_repo;
		$this->attendee_field_repo       = $attendee_field_repo;
		$this->waitlist_repo             = $waitlist_repo;
		$this->reminder_log_repo         = $reminder_log_repo;
		$this->category_repo             = $category_repo;
		$this->tag_repo                  = $tag_repo;
		$this->organizer_repo            = $organizer_repo;
		$this->reservation_manager       = $reservation_manager;
	}

	/**
	 * Hook callback for BEFORE_DELETE_EVENT.
	 *
	 * Named method so the listener can be wired without a closure.
	 *
	 * @since 1.0.0
	 *
	 * @param Event $event The event being deleted.
	 * @return void
	 */
	public function on_before_delete_event( Event $event ): void {
		$this->cascade( $event );
	}

	/**
	 * Remove all data that belongs to the given event.
	 *
	 * @since 1.0.0
	 *
	 * @param Event $event The event whose child data should be removed.
	 * @return void
	 */
	public function cascade( Event $event ): void {
		if ( null === $event->id ) {
			return;
		}

		$ticket_types = $this->ticket_type_repo->for_event( $event->id, array( 'status' => null ) );

		// Release any pending reservations held against this event's ticket types.
		foreach ( $ticket_types as $tt ) {
			if ( null !== $tt->id ) {
				$this->reservation_manager->clear_pending( (int) $tt->id, '' );
			}
		}

		// Remove per-occurrence children. Use an unbounded ID fetch so events
		// with more than 100 occurrences (recurring series) cascade fully.
		$occurrence_ids = $this->occurrence_repo->all_ids_for_event( $event->id );

		foreach ( $occurrence_ids as $occurrence_id ) {
			foreach ( $this->ticket_repo->find_by_occurrence( $occurrence_id ) as $ticket ) {
				if ( null !== $ticket->id ) {
					$this->ticket_repo->delete( (int) $ticket->id );
				}
			}

			foreach ( $this->attendee_repo->for_occurrence( $occurrence_id ) as $attendee ) {
				if ( null !== $attendee->id ) {
					$this->attendee_field_value_repo->delete_for_attendee( (int) $attendee->id );
				}
			}
			$this->attendee_repo->delete_for_occurrence( $occurrence_id );

			$this->waitlist_repo->delete_for_occurrence( $occurrence_id );
			$this->reminder_log_repo->delete_for_occurrence( $occurrence_id );
		}

		// Remove ticket types (fires TICKET_TYPE_DELETED → product cleanup + activity log).
		foreach ( $ticket_types as $tt ) {
			if ( null !== $tt->id ) {
				$this->ticket_type_repo->delete( (int) $tt->id );
			}
		}

		// Remove occurrences and event-scoped attendee fields.
		$this->occurrence_repo->delete_for_event( $event->id );
		$this->attendee_field_repo->delete_for_event( $event->id );

		// Clear taxonomy and organizer junctions.
		$this->category_repo->sync_event_categories( $event->id, array() );
		$this->tag_repo->sync_event_tags( $event->id, array() );
		$this->organizer_repo->sync_event_organizers( $event->id, array() );
	}
}
