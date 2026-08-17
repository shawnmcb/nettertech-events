<?php
/**
 * Per-occurrence save handler.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\Metaboxes\TicketsMetabox;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Contracts\TagRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\RecurrenceRule;
use NetterTechEvents\Services\OccurrenceTimeResolver;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\TicketTypeSaver;

/**
 * Handles submission of the per-occurrence edit form.
 *
 * Three scope branches (NTE-077):
 *  - this:      edit one occurrence (override columns + is_override flag).
 *  - following: split the series at this date into a new event, re-pointing
 *               future occurrences (and their tickets) to it so attendee
 *               links survive. Capability check first, then nonce.
 *  - all:       propagate inheritable fields to the parent event and future
 *               occurrences.
 *
 * @since 1.0.4
 */
class OccurrenceSaveHandler {

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Recurrence service (RRULE parse for series-split).
	 *
	 * @var RecurrenceService
	 */
	private RecurrenceService $recurrence_service;

	/**
	 * Ticket type repository (re-point + template copy on series-split).
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Category repository (junction copy on series-split).
	 *
	 * @var CategoryRepositoryInterface
	 */
	private CategoryRepositoryInterface $category_repo;

	/**
	 * Tag repository (junction copy on series-split).
	 *
	 * @var TagRepositoryInterface
	 */
	private TagRepositoryInterface $tag_repo;

	/**
	 * Organizer repository (junction copy on series-split).
	 *
	 * @var OrganizerRepositoryInterface
	 */
	private OrganizerRepositoryInterface $organizer_repo;

	/**
	 * Saves this date's own ticket types (null when unavailable).
	 *
	 * @var TicketTypeSaver|null
	 */
	private ?TicketTypeSaver $ticket_saver;

	/**
	 * Tells us whether a date has sold seats (null when unavailable).
	 *
	 * @var AttendeeRepositoryInterface|null
	 */
	private ?AttendeeRepositoryInterface $attendee_repo;

	/**
	 * Constructor.
	 *
	 * @param OccurrenceRepositoryInterface    $occurrence_repo    Occurrence repository.
	 * @param EventRepositoryInterface         $event_repo         Event repository.
	 * @param RecurrenceService                $recurrence_service Recurrence service.
	 * @param TicketTypeRepositoryInterface    $ticket_type_repo   Ticket type repository.
	 * @param CategoryRepositoryInterface      $category_repo      Category repository.
	 * @param TagRepositoryInterface           $tag_repo           Tag repository.
	 * @param OrganizerRepositoryInterface     $organizer_repo     Organizer repository.
	 * @param TicketTypeSaver|null             $ticket_saver       Saves this date's own ticket types.
	 * @param AttendeeRepositoryInterface|null $attendee_repo   Tells us whether a date has sold seats.
	 */
	public function __construct(
		OccurrenceRepositoryInterface $occurrence_repo,
		EventRepositoryInterface $event_repo,
		RecurrenceService $recurrence_service,
		TicketTypeRepositoryInterface $ticket_type_repo,
		CategoryRepositoryInterface $category_repo,
		TagRepositoryInterface $tag_repo,
		OrganizerRepositoryInterface $organizer_repo,
		?TicketTypeSaver $ticket_saver = null,
		?AttendeeRepositoryInterface $attendee_repo = null
	) {
		$this->occurrence_repo    = $occurrence_repo;
		$this->event_repo         = $event_repo;
		$this->recurrence_service = $recurrence_service;
		$this->ticket_type_repo   = $ticket_type_repo;
		$this->category_repo      = $category_repo;
		$this->tag_repo           = $tag_repo;
		$this->organizer_repo     = $organizer_repo;
		$this->ticket_saver       = $ticket_saver;
		$this->attendee_repo      = $attendee_repo;
	}

	/**
	 * Handle form submission (action hook entry point).
	 *
	 * @return void
	 */
	public function handle_save(): void {
		wp_safe_redirect( $this->process_save() );
		exit;
	}

	/**
	 * Handle "delete this date" (action hook entry point).
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		wp_safe_redirect( $this->process_delete() );
		exit;
	}

	/**
	 * Remove one date from an event.
	 *
	 * A pattern that ran away — a recurrence saved before the editor refused to invent one could
	 * leave hundreds of dates behind — has to be undoable date by date, and an event that simply
	 * gained a date the operator no longer wants has to be able to lose it again. Creating dates
	 * without being able to remove them is only half a feature.
	 *
	 * A date that has sold tickets is never deleted. Those seats are somebody's evening; the refusal
	 * says so and names the date, exactly as converting a series to a single date does.
	 *
	 * @since 1.1.2
	 *
	 * @return string Redirect URL.
	 */
	public function process_delete(): string {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nettertech-events' ) );
		}

		$occurrence_id = isset( $_GET['occurrence_id'] ) ? absint( wp_unslash( $_GET['occurrence_id'] ) ) : 0;

		if ( ! isset( $_GET['_wpnonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
				OccurrenceEditor::DELETE_ACTION . '_' . $occurrence_id
			) ) {
			wp_die( esc_html__( 'Your session has expired. Please reload the page and try again.', 'nettertech-events' ) );
		}

		$occurrence = $occurrence_id > 0 ? $this->occurrence_repo->find( $occurrence_id ) : null;

		if ( null === $occurrence || null === $occurrence->id ) {
			return $this->redirect_to_event( 0, 'error' );
		}

		$event_id  = $occurrence->event_id;
		$attendees = null !== $this->attendee_repo
			? $this->attendee_repo->count_for_occurrence( $occurrence->id, null )
			: 0;

		if ( $attendees > 0 ) {
			set_transient(
				'nettertech_events_save_error_' . get_current_user_id(),
				sprintf(
					/* translators: 1: the date, 2: how many people are going. */
					esc_html__( 'That date cannot be removed, because %1$s has already sold tickets (%2$d attending). Cancel and refund them first, or cancel the date instead of removing it.', 'nettertech-events' ),
					esc_html( $occurrence->get_start()->format( 'M j, Y g:i a' ) ),
					(int) $attendees
				),
				60
			);

			return $this->redirect_to_event( $event_id, 'error' );
		}

		$this->occurrence_repo->delete( $occurrence->id );

		return $this->redirect_to_event( $event_id, 'updated' );
	}

	/**
	 * Back to the event that owned the date.
	 *
	 * @param int    $event_id The event.
	 * @param string $message  Message slug.
	 * @return string
	 */
	private function redirect_to_event( int $event_id, string $message ): string {
		return admin_url(
			'admin.php?page=' . AdminMenu::MENU_SLUG . '&action=edit&event_id=' . $event_id . '&message=' . $message
		);
	}

	/**
	 * Process the submission and return a redirect URL.
	 *
	 * @return string Redirect URL.
	 *
	 * @phpcs:ignore Squiz.Commenting.FunctionCommentThrowTag.Missing -- Exceptions are caught internally, not propagated.
	 */
	public function process_save(): string {
		// Check permissions first (before nonce to avoid timing attacks).
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nettertech-events' ) );
		}

		// Verify nonce.
		if ( ! isset( $_POST[ OccurrenceEditor::NONCE_FIELD ] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ OccurrenceEditor::NONCE_FIELD ] ) ), OccurrenceEditor::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Your session has expired. Please reload the page and try again.', 'nettertech-events' ) );
		}

		// Unslash $_POST once at the nonce-verified boundary; per-field
		// type-specific sanitization happens in extract_fields().
		$post          = wp_unslash( $_POST );
		$occurrence_id = isset( $post['occurrence_id'] ) ? absint( $post['occurrence_id'] ) : 0;

		$occurrence = $occurrence_id > 0 ? $this->occurrence_repo->find( $occurrence_id ) : null;
		if ( null === $occurrence || null === $occurrence->id ) {
			return $this->redirect_to_editor( $occurrence_id, 'error' );
		}

		$event = $this->event_repo->find( $occurrence->event_id );
		if ( null === $event ) {
			return $this->redirect_to_editor( $occurrence_id, 'error' );
		}
		$occurrence->set_event( $event );

		$scope = $this->sanitize_scope( isset( $post['scope'] ) ? (string) $post['scope'] : 'this' );

		try {
			// Inside the try: extract_fields now derives and validates times and can
			// throw ValidationException (blank end with require_end_time, zero-length
			// or inverted span — NTE-189).
			$fields = $this->extract_fields( $post );

			switch ( $scope ) {
				case 'following':
					$this->apply_following( $occurrence, $event, $fields );
					break;
				case 'all':
					$this->apply_all( $occurrence, $event, $fields );
					break;
				case 'this':
				default:
					$this->apply_this( $occurrence, $fields );
					break;
			}

			$this->save_ticket_types( $occurrence, $event, $post );
		} catch ( ValidationException $e ) {
			set_transient( 'nettertech_events_save_error_' . get_current_user_id(), $e->getMessage(), 60 );
			return $this->redirect_to_editor( $occurrence_id, 'error' );
		} catch ( \RuntimeException $e ) {
			set_transient( 'nettertech_events_save_error_' . get_current_user_id(), $e->getMessage(), 60 );
			return $this->redirect_to_editor( $occurrence_id, 'error' );
		}

		return $this->redirect_to_editor( $occurrence_id, 'updated' );
	}

	/**
	 * Save the ticket types the operator set on this date.
	 *
	 * Guarded on the metabox having actually rendered. The saver reads a missing `ticketing_enabled`
	 * as "ticketing off" and deletes the date's ticket types — which is right when the operator
	 * unticked the box, and catastrophic if the box was never on the page at all.
	 *
	 * @since 1.1.2
	 *
	 * @param Occurrence           $occurrence The date being saved.
	 * @param Event                $event      Its event.
	 * @param array<string, mixed> $post       Unslashed POST data from the nonce-verified boundary.
	 * @return void
	 */
	private function save_ticket_types( Occurrence $occurrence, Event $event, array $post ): void {
		if ( null === $this->ticket_saver || empty( $post['nte_tickets_metabox_rendered'] ) ) {
			return;
		}

		$nonce = isset( $post[ TicketsMetabox::NONCE_ACTION ] )
			? sanitize_text_field( (string) $post[ TicketsMetabox::NONCE_ACTION ] )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, TicketsMetabox::NONCE_ACTION ) ) {
			return;
		}

		$this->ticket_saver->save_for_occurrence(
			(int) $occurrence->id,
			$post,
			(int) $event->id
		);
	}

	/**
	 * Scope: this date only.
	 *
	 * @param Occurrence           $occurrence Occurrence being edited.
	 * @param array<string, mixed> $fields     Sanitized field values.
	 * @return void
	 */
	private function apply_this( Occurrence $occurrence, array $fields ): void {
		$this->apply_fields_to_occurrence( $occurrence, $fields );
		$occurrence->is_override = true;
		$this->occurrence_repo->save( $occurrence );
	}

	/**
	 * Scope: this and all following dates (series split via re-point).
	 *
	 * Future occurrences (and their occurrence-scoped tickets) are re-pointed
	 * to a new event so occurrence IDs — and therefore attendee links, WC
	 * orders, QR codes, and check-in tokens — survive. The parent series RRULE
	 * is capped with UNTIL = day before the cutoff.
	 *
	 * @param Occurrence           $occurrence Occurrence at the split point.
	 * @param Event                $event      Parent event.
	 * @param array<string, mixed> $fields     Sanitized field values.
	 * @return void
	 */
	private function apply_following( Occurrence $occurrence, Event $event, array $fields ): void {
		// The split cutoff is the occurrence's ORIGINAL position, not any edited date.
		$cutoff_str = $occurrence->start_datetime;
		$cutoff_dt  = new \DateTimeImmutable( $cutoff_str );

		$all_occurrences = $this->occurrence_repo->for_event( (int) $event->id );

		$before_count = 0;
		foreach ( $all_occurrences as $sibling ) {
			if ( $sibling->start_datetime < $cutoff_str ) {
				++$before_count;
			}
		}

		$parent_rule = ! empty( $event->recurrence_rule )
			? $this->recurrence_service->parse_rule( $event->recurrence_rule )
			: null;

		// Create and persist the new (future) series.
		$new_event = $this->event_repo->save(
			$this->build_split_event( $event, $parent_rule, $before_count )
		);

		// Cap the parent series so the horizon-extender stops at the split.
		$this->cap_parent_series( $event, $parent_rule, $cutoff_dt );

		// Re-point future occurrences (excluding the clicked one) + their tickets.
		foreach ( $all_occurrences as $sibling ) {
			if ( null === $sibling->id
				|| $sibling->id === $occurrence->id
				|| $sibling->start_datetime < $cutoff_str ) {
				continue;
			}
			$sibling->event_id = (int) $new_event->id;
			$this->occurrence_repo->save( $sibling );
			$this->repoint_occurrence_tickets( (int) $sibling->id, (int) $new_event->id );
		}

		// Copy category/tag/organizer junctions onto the new series.
		$this->copy_junctions( (int) $event->id, (int) $new_event->id );

		// Copy event-level ticket templates onto the new series.
		$this->copy_templates( (int) $event->id, (int) $new_event->id );

		// Move + edit the clicked occurrence onto the new series.
		$occurrence->event_id = (int) $new_event->id;
		$occurrence->set_event( $new_event );
		$this->apply_fields_to_occurrence( $occurrence, $fields );
		$occurrence->is_override = true;
		$this->occurrence_repo->save( $occurrence );
		$this->repoint_occurrence_tickets( (int) $occurrence->id, (int) $new_event->id );

		// Re-sequence the new series 1-based by start_datetime. Re-pointed siblings
		// retain their parent-series sequence_number; without this the new event's
		// sequence would start mid-series, breaking iCal original-slot recovery.
		$this->resequence_event_occurrences( (int) $new_event->id );
	}

	/**
	 * Re-number an event's occurrences 1-based by start_datetime.
	 *
	 * @param int $event_id Event ID.
	 * @return void
	 */
	private function resequence_event_occurrences( int $event_id ): void {
		$occurrences = $this->occurrence_repo->for_event(
			$event_id,
			array(
				'orderby' => 'start_datetime',
				'order'   => 'ASC',
			)
		);

		$seq = 1;
		foreach ( $occurrences as $occurrence ) {
			if ( null === $occurrence->id ) {
				continue;
			}
			if ( $occurrence->sequence_number !== $seq ) {
				$occurrence->sequence_number = $seq;
				$this->occurrence_repo->save( $occurrence );
			}
			++$seq;
		}
	}

	/**
	 * Scope: all dates in the series.
	 *
	 * Inheritable fields (description, venue, virtual URL, image) become the
	 * parent-event defaults; time-of-day, all-day flag, and capacity propagate
	 * to all FUTURE occurrences (each keeps its own date). Per-date status is
	 * not propagated — cancelling is a single-date concept.
	 *
	 * @param Occurrence           $occurrence Occurrence being edited.
	 * @param Event                $event      Parent event.
	 * @param array<string, mixed> $fields     Sanitized field values.
	 * @return void
	 */
	private function apply_all( Occurrence $occurrence, Event $event, array $fields ): void {
		unset( $occurrence );

		if ( null !== $fields['description_override'] ) {
			$event->description = $fields['description_override'];
		}
		if ( null !== $fields['venue_name_override'] ) {
			$event->venue_name = $fields['venue_name_override'];
		}
		if ( null !== $fields['venue_address_override'] ) {
			$event->venue_address = $fields['venue_address_override'];
		}
		if ( null !== $fields['virtual_url_override'] ) {
			$event->virtual_url = $fields['virtual_url_override'];
		}
		if ( null !== $fields['featured_image_id'] ) {
			$event->featured_image_id = $fields['featured_image_id'];
		}
		$this->event_repo->save( $event );

		$future  = $this->occurrence_repo->for_event( (int) $event->id, array( 'upcoming' => true ) );
		$skipped = array();
		foreach ( $future as $sibling ) {
			if ( null === $sibling->id ) {
				continue;
			}

			// Hand-picked (is_override) dates carry times/capacity the operator set deliberately;
			// an "apply to all" must not steamroll them. Skip and name them in the save notice
			// (operator ruling 2026-07-20, spec-001 invention audit — R2).
			if ( $sibling->is_override ) {
				$skipped[] = (string) wp_date(
					get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
					$sibling->get_start()->getTimestamp()
				);
				continue;
			}

			$start_date = substr( $sibling->start_datetime, 0, 10 );
			$end_date   = substr( $sibling->end_datetime, 0, 10 );

			$sibling->start_datetime = $start_date . ' ' . $fields['start_time'] . ':00';
			$sibling->end_datetime   = $end_date . ' ' . $fields['end_time'] . ':00';
			$sibling->all_day        = $fields['all_day'];
			$sibling->capacity       = $fields['capacity'];
			$this->occurrence_repo->save( $sibling );
		}

		if ( ! empty( $skipped ) ) {
			set_transient(
				'nettertech_events_save_notice_' . get_current_user_id(),
				sprintf(
					/* translators: %s: comma-separated list of dates. */
					esc_html__( 'These hand-picked dates kept their own time and capacity: %s.', 'nettertech-events' ),
					esc_html( implode( '; ', $skipped ) )
				),
				60
			);
		}
	}

	/**
	 * Apply edited field values to an occurrence (override columns + direct columns).
	 *
	 * @param Occurrence           $occurrence Occurrence to mutate.
	 * @param array<string, mixed> $fields     Sanitized field values.
	 * @return void
	 */
	private function apply_fields_to_occurrence( Occurrence $occurrence, array $fields ): void {
		if ( '' !== $fields['start_date'] ) {
			$occurrence->start_datetime = $fields['start_date'] . ' ' . $fields['start_time'] . ':00';
			$occurrence->end_datetime   = $fields['end_date'] . ' ' . $fields['end_time'] . ':00';
		}

		$occurrence->all_day                = $fields['all_day'];
		$occurrence->capacity               = $fields['capacity'];
		$occurrence->status                 = $this->resolve_occurrence_status( (string) $occurrence->status, $fields['status'] );
		$occurrence->description_override   = $fields['description_override'];
		$occurrence->venue_name_override    = $fields['venue_name_override'];
		$occurrence->venue_address_override = $fields['venue_address_override'];
		$occurrence->virtual_url_override   = $fields['virtual_url_override'];
		$occurrence->featured_image_id      = $fields['featured_image_id'];
	}

	/**
	 * Decide an occurrence's status from its current status and the form's posted intent.
	 *
	 * The editor's status control offers only Active (scheduled) and Cancelled, and pre-selects
	 * Active for ANY non-cancelled status. A plain save of a `rescheduled` date therefore posts
	 * 'scheduled', which the old squash rewrote to 'scheduled' — quietly discarding the reschedule.
	 * Preserve the current status unless the operator made an explicit change: cancelling, or
	 * un-cancelling a cancelled date (operator ruling 2026-07-20, spec-001 invention audit — R3).
	 *
	 * @param string $current_status The occurrence's stored status.
	 * @param string $posted_status  The form's posted intent ('scheduled' or 'cancelled').
	 * @return string The status to persist.
	 */
	private function resolve_occurrence_status( string $current_status, string $posted_status ): string {
		if ( 'cancelled' === $posted_status ) {
			return 'cancelled';
		}

		// Posted 'scheduled': un-cancel a cancelled date; otherwise keep what was there so a
		// 'rescheduled' (or any other non-cancelled) status survives an unrelated edit.
		return 'cancelled' === $current_status ? 'scheduled' : $current_status;
	}

	/**
	 * Build a new event copying the parent's content for a series split.
	 *
	 * Inherits content/venue/virtual/image/recurrence/layout/reminder/QR
	 * fields. Category/tag/organizer junctions are copied separately by
	 * copy_junctions() after the new event is persisted.
	 *
	 * @param Event               $source       Parent event.
	 * @param RecurrenceRule|null $rule         Parsed parent rule (null when not recurring).
	 * @param int                 $before_count Occurrences strictly before the cutoff.
	 * @return Event Unsaved new event.
	 */
	private function build_split_event( Event $source, ?RecurrenceRule $rule, int $before_count ): Event {
		$new                               = new Event();
		$new->title                        = $source->title;
		$new->description                  = $source->description;
		$new->excerpt                      = $source->excerpt;
		$new->featured_image_id            = $source->featured_image_id;
		$new->status                       = $source->status;
		$new->event_type                   = $source->event_type;
		$new->series_id                    = $source->series_id;
		$new->venue_name                   = $source->venue_name;
		$new->venue_address                = $source->venue_address;
		$new->is_virtual                   = $source->is_virtual;
		$new->virtual_url                  = $source->virtual_url;
		$new->collect_individual_attendees = $source->collect_individual_attendees;
		$new->layout_config                = $source->layout_config;
		$new->reminders_enabled            = $source->reminders_enabled;
		$new->waitlist_enabled             = $source->waitlist_enabled;
		$new->notification_emails          = $source->notification_emails;
		$new->qr_logo_mode                 = $source->qr_logo_mode;
		$new->qr_logo_attachment_id        = $source->qr_logo_attachment_id;
		$new->space_id                     = $source->space_id;
		$new->slug                         = $this->event_repo->generate_unique_slug( $source->title );

		// The future series carries the recurrence; COUNT rules lose the
		// occurrences that stay on the parent.
		if ( null !== $rule ) {
			if ( null !== $rule->count ) {
				$new->recurrence_rule = (string) $rule->with_count( max( 1, $rule->count - $before_count ) );
			} else {
				$new->recurrence_rule = (string) $rule;
			}
		}

		return $new;
	}

	/**
	 * Cap the parent series at the split point.
	 *
	 * @param Event               $parent_event Parent event.
	 * @param RecurrenceRule|null $rule         Parsed parent rule.
	 * @param \DateTimeImmutable  $cutoff_dt    Split cutoff datetime.
	 * @return void
	 */
	private function cap_parent_series( Event $parent_event, ?RecurrenceRule $rule, \DateTimeImmutable $cutoff_dt ): void {
		if ( null !== $rule ) {
			$until                         = $cutoff_dt->modify( '-1 day' )->setTime( 23, 59, 59 );
			$parent_event->recurrence_rule = (string) $rule->with_until( $until );
		}

		$parent_event->title = sprintf(
			/* translators: 1: original event title, 2: cutoff date */
			__( '%1$s (through %2$s)', 'nettertech-events' ),
			$parent_event->title,
			$cutoff_dt->modify( '-1 day' )->format( (string) get_option( 'date_format', 'Y-m-d' ) )
		);

		$this->event_repo->save( $parent_event );
	}

	/**
	 * Re-point an occurrence's occurrence-scoped ticket types to a new event.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @param int $new_event_id  Destination event ID.
	 * @return void
	 */
	private function repoint_occurrence_tickets( int $occurrence_id, int $new_event_id ): void {
		$tickets = $this->ticket_type_repo->for_occurrence( $occurrence_id );
		foreach ( $tickets as $ticket ) {
			if ( $ticket->event_id === $new_event_id ) {
				continue;
			}
			$ticket->event_id = $new_event_id;
			$this->ticket_type_repo->save( $ticket );
		}
	}

	/**
	 * Copy event-level ticket templates from one event to another.
	 *
	 * @param int $source_event_id Source (parent) event ID.
	 * @param int $new_event_id    Destination (new) event ID.
	 * @return void
	 */
	private function copy_templates( int $source_event_id, int $new_event_id ): void {
		$templates = $this->ticket_type_repo->get_templates( $source_event_id );
		foreach ( $templates as $template ) {
			$copy                  = clone $template;
			$copy->id              = null;
			$copy->event_id        = $new_event_id;
			$copy->occurrence_id   = null;
			$copy->template_id     = null;
			$copy->wc_product_id   = null;
			$copy->wc_variation_id = null;
			$copy->sold_count      = 0;
			$this->ticket_type_repo->save( $copy );
		}
	}

	/**
	 * Copy category, tag, and organizer junctions from one event to another.
	 *
	 * Called after a series split so the new event inherits the same taxonomy
	 * links as the source. Primary ordering is preserved because each
	 * find_by_event() returns rows sorted primary-first; sync_event_* treats
	 * the first array entry as primary when plain IDs are passed.
	 *
	 * @param int $source_event_id Source event ID.
	 * @param int $new_event_id    New event ID.
	 * @return void
	 */
	private function copy_junctions( int $source_event_id, int $new_event_id ): void {
		$categories = $this->category_repo->find_by_event( $source_event_id );
		if ( ! empty( $categories ) ) {
			$category_ids = array();
			foreach ( $categories as $cat ) {
				$category_ids[] = (int) $cat->id;
			}
			$this->category_repo->sync_event_categories( $new_event_id, $category_ids );
		}

		$tags = $this->tag_repo->find_by_event( $source_event_id );
		if ( ! empty( $tags ) ) {
			$tag_ids = array();
			foreach ( $tags as $tag ) {
				$tag_ids[] = (int) $tag->id;
			}
			$this->tag_repo->sync_event_tags( $new_event_id, $tag_ids );
		}

		$organizers = $this->organizer_repo->find_by_event( $source_event_id );
		if ( ! empty( $organizers ) ) {
			$organizer_ids = array();
			foreach ( $organizers as $org ) {
				$organizer_ids[] = (int) $org->id;
			}
			$this->organizer_repo->sync_event_organizers( $new_event_id, $organizer_ids );
		}
	}

	/**
	 * Extract and sanitize the editable fields from POST.
	 *
	 * @param array<string, mixed> $post Unslashed POST data.
	 * @return array<string, mixed> Sanitized field values.
	 * @throws ValidationException When end derivation is blocked by require_end_time,
	 *                             or the resulting span is under the 10-minute minimum.
	 */
	private function extract_fields( array $post ): array {
		$start_date = sanitize_text_field( (string) ( $post['start_date'] ?? '' ) );
		$start_time = sanitize_text_field( (string) ( $post['start_time'] ?? '' ) );
		$end_date   = sanitize_text_field( (string) ( $post['end_date'] ?? '' ) );
		$end_time   = sanitize_text_field( (string) ( $post['end_time'] ?? '' ) );
		$all_day    = ! empty( $post['all_day'] );

		if ( '' === $end_date ) {
			$end_date = $start_date;
		}

		// One derivation path for editor, occurrence editor, and manual rows (NTE-189):
		// blank start -> default start time; blank end -> start + default duration.
		$times      = OccurrenceTimeResolver::derive_times( $start_date, $start_time, $end_time, $all_day );
		$start_time = $times['start_time'];
		$end_time   = $times['end_time'];

		// Reject a zero-length or inverted span (previously blank end = start, saving a
		// zero-length occurrence with no validation). Skip when start_date is blank —
		// apply_fields_to_occurrence leaves the stored datetimes untouched in that case.
		if ( '' !== $start_date ) {
			OccurrenceTimeResolver::validate_span(
				new \DateTimeImmutable( $start_date . ' ' . $start_time . ':00' ),
				new \DateTimeImmutable( $end_date . ' ' . $end_time . ':00' )
			);
		}

		$status   = 'cancelled' === sanitize_text_field( (string) ( $post['status'] ?? 'scheduled' ) )
			? 'cancelled'
			: 'scheduled';
		$capacity = isset( $post['capacity'] ) && '' !== $post['capacity']
			? absint( $post['capacity'] )
			: null;

		return array(
			'start_date'             => $start_date,
			'start_time'             => $start_time,
			'end_date'               => $end_date,
			'end_time'               => $end_time,
			'all_day'                => $all_day,
			'status'                 => $status,
			'capacity'               => $capacity,
			'description_override'   => $this->null_if_empty( wp_kses_post( (string) ( $post['description_override'] ?? '' ) ) ),
			'venue_name_override'    => $this->null_if_empty( sanitize_text_field( (string) ( $post['venue_name_override'] ?? '' ) ) ),
			'venue_address_override' => $this->null_if_empty( sanitize_textarea_field( (string) ( $post['venue_address_override'] ?? '' ) ) ),
			'virtual_url_override'   => $this->null_if_empty( esc_url_raw( (string) ( $post['virtual_url_override'] ?? '' ) ) ),
			'featured_image_id'      => ! empty( $post['featured_image_id'] ) ? absint( $post['featured_image_id'] ) : null,
		);
	}

	/**
	 * Return null for an empty string (inherit), else the trimmed value.
	 *
	 * @param string $value Sanitized value.
	 * @return string|null
	 */
	private function null_if_empty( string $value ): ?string {
		return '' === $value ? null : $value;
	}

	/**
	 * Normalize the scope parameter.
	 *
	 * @param string $scope Raw scope value.
	 * @return string One of: this, following, all.
	 */
	private function sanitize_scope( string $scope ): string {
		$scope = sanitize_key( $scope );
		return in_array( $scope, array( 'this', 'following', 'all' ), true ) ? $scope : 'this';
	}

	/**
	 * Build the redirect URL back to the occurrence editor.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $message       Optional message slug.
	 * @return string Redirect URL.
	 */
	private function redirect_to_editor( int $occurrence_id, string $message = '' ): string {
		$args = array(
			'page'          => AdminMenu::SUBMENU_EDIT_OCCURRENCE,
			'occurrence_id' => $occurrence_id,
		);
		if ( '' !== $message ) {
			$args['message'] = $message;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
