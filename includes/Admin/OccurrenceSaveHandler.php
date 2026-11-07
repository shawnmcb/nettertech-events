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
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Services\OccurrenceRemover;
use NetterTechEvents\Services\OccurrenceTimeResolver;
use NetterTechEvents\Services\TicketTypeSaver;

/**
 * Handles submission of the per-occurrence edit form.
 *
 * Two scopes:
 *  - this: edit one occurrence (override columns + is_override flag).
 *  - all:  propagate inheritable fields to the parent event and future
 *          occurrences.
 *
 * A series is never split into two events, so any other posted scope saves
 * this date only.
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
	 * Removes a date with its ticket types (resolved on first use when not injected).
	 *
	 * @var OccurrenceRemover|null
	 */
	private ?OccurrenceRemover $remover;

	/**
	 * Constructor.
	 *
	 * @since 1.5.4 Added `$remover`.
	 *
	 * @param OccurrenceRepositoryInterface    $occurrence_repo Occurrence repository.
	 * @param EventRepositoryInterface         $event_repo      Event repository.
	 * @param TicketTypeSaver|null             $ticket_saver    Saves this date's own ticket types.
	 * @param AttendeeRepositoryInterface|null $attendee_repo   Tells us whether a date has sold seats.
	 * @param OccurrenceRemover|null           $remover         Removes a date with its ticket types.
	 */
	public function __construct(
		OccurrenceRepositoryInterface $occurrence_repo,
		EventRepositoryInterface $event_repo,
		?TicketTypeSaver $ticket_saver = null,
		?AttendeeRepositoryInterface $attendee_repo = null,
		?OccurrenceRemover $remover = null
	) {
		$this->occurrence_repo = $occurrence_repo;
		$this->event_repo      = $event_repo;
		$this->ticket_saver    = $ticket_saver;
		$this->attendee_repo   = $attendee_repo;
		$this->remover         = $remover;
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
	 * A date that anyone holds a place on is never deleted. Those seats are somebody's evening; the
	 * refusal says so and names the date, exactly as converting a series to a single date does. The
	 * date's own ticket types go with it, so none is left on sale for a date that is gone.
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

		$event_id = $occurrence->event_id;
		$remover  = $this->remover ?? ServiceRegistry::get( OccurrenceRemover::class );
		$when     = $occurrence->get_start()->format( 'M j, Y g:i a' );

		if ( $remover instanceof OccurrenceRemover && $remover->has_holders( $occurrence->id ) ) {
			$attending = null !== $this->attendee_repo ? $this->attendee_repo->count_for_occurrence( $occurrence->id ) : 0;

			$message = $attending > 0
				? sprintf(
					/* translators: 1: the date, 2: how many people are going. */
					esc_html__( 'That date cannot be removed, because %1$s has already sold tickets (%2$d attending). Cancel and refund them first, or cancel the date instead of removing it.', 'nettertech-events' ),
					esc_html( $when ),
					$attending
				)
				: sprintf(
					/* translators: %s: the date. */
					esc_html__( 'That date cannot be removed, because %s has ticket or order records. Cancel the date instead of removing it.', 'nettertech-events' ),
					esc_html( $when )
				);

			set_transient( 'nettertech_events_save_error_' . get_current_user_id(), $message, 60 );

			return $this->redirect_to_event( $event_id, 'error' );
		}

		if ( ! $remover instanceof OccurrenceRemover || ! $remover->remove( $occurrence->id ) ) {
			set_transient(
				'nettertech_events_save_error_' . get_current_user_id(),
				sprintf(
					/* translators: %s: the date. */
					esc_html__( '%s could not be removed. Reload the event and try again.', 'nettertech-events' ),
					esc_html( $when )
				),
				60
			);

			return $this->redirect_to_event( $event_id, 'error' );
		}

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

		$posted_scope = isset( $post['scope'] ) ? sanitize_key( (string) $post['scope'] ) : 'this';

		try {
			// Inside the try: extract_fields now derives and validates times and can
			// throw ValidationException (blank end with require_end_time, zero-length
			// or inverted span — NTE-189).
			$fields = $this->extract_fields( $post );

			if ( 'all' === $posted_scope ) {
				$this->apply_all( $occurrence, $event, $fields );
			} else {
				$this->apply_this( $occurrence, $event, $fields );
			}

			$this->save_ticket_types( $occurrence, $post );
		} catch ( \RuntimeException $e ) {
			set_transient( 'nettertech_events_save_error_' . get_current_user_id(), $e->getMessage(), 60 );
			return $this->redirect_to_editor( $occurrence_id, 'error' );
		}

		// Any other scope, from a stale form or a crafted request, was saved as this date only;
		// the operator asked for more and has to be told they did not get it.
		if ( ! in_array( $posted_scope, array( 'this', 'all' ), true ) ) {
			set_transient(
				'nettertech_events_save_notice_' . get_current_user_id(),
				__( 'Your changes were applied to this date only.', 'nettertech-events' ),
				60
			);
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
	 * Tickets are filed under the event the date belongs to now.
	 *
	 * @param Occurrence           $occurrence The date being saved.
	 * @param array<string, mixed> $post       Unslashed POST data from the nonce-verified boundary.
	 * @return void
	 */
	private function save_ticket_types( Occurrence $occurrence, array $post ): void {
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
			$occurrence->event_id
		);
	}

	/**
	 * Scope: this date only.
	 *
	 * Only a recurring event's date is flagged: there the flag keeps regeneration off the edited
	 * row. On a single event it marks a hand-added extra date, and flagging the event's own date
	 * would leave it without a primary date.
	 *
	 * @param Occurrence           $occurrence Occurrence being edited.
	 * @param Event                $event      Its event.
	 * @param array<string, mixed> $fields     Sanitized field values.
	 * @return void
	 */
	private function apply_this( Occurrence $occurrence, Event $event, array $fields ): void {
		$this->apply_fields_to_occurrence( $occurrence, $fields );
		if ( $event->is_recurring() ) {
			$occurrence->is_override = true;
		}
		$this->occurrence_repo->save( $occurrence );
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

		$future  = $this->occurrence_repo->for_event(
			(int) $event->id,
			array(
				'upcoming' => true,
				'limit'    => PHP_INT_MAX,
			)
		);
		$skipped = array();
		foreach ( $future as $listed ) {
			// The list query leaves out the long per-date columns; saving a listed row would blank
			// the date's own description and address.
			$sibling = null !== $listed->id ? $this->occurrence_repo->find( $listed->id ) : null;
			if ( null === $sibling ) {
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
