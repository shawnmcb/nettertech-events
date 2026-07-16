<?php
/**
 * Event save handler class.
 *
 * Handles save, validation, and processing logic for the event editor.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Admin\Metaboxes\AttendeeFieldsSaveHandler;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Services\CheckInEmailSaver;
use NetterTechEvents\Services\LayoutService;
use NetterTechEvents\Services\RecurrenceRuleBuilder;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\TicketTypeSaver;

/**
 * Handles all save, validation, and processing logic for the event editor.
 *
 * Dependencies are injected via constructor for testability and
 * separation of concerns.
 *
 * @since 0.9.0
 */
class EventSaveHandler {

	/**
	 * Event repository for slug generation.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Occurrence repository for occurrence persistence.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Category repository for event-category associations.
	 *
	 * @var CategoryRepositoryInterface
	 */
	private CategoryRepositoryInterface $category_repo;

	/**
	 * Organizer repository for event-organizer associations.
	 *
	 * @var OrganizerRepositoryInterface
	 */
	private ?OrganizerRepositoryInterface $organizer_repo;

	/**
	 * Recurrence service for occurrence generation.
	 *
	 * @var RecurrenceService
	 */
	private RecurrenceService $recurrence_service;

	/**
	 * Ticket type saver for saving ticket types per occurrence.
	 *
	 * @var TicketTypeSaver
	 */
	private TicketTypeSaver $ticket_saver;

	/**
	 * Layout service.
	 *
	 * @var LayoutService
	 */
	private LayoutService $layout_service;

	/**
	 * Recurrence rule builder.
	 *
	 * @var RecurrenceRuleBuilder
	 */
	private RecurrenceRuleBuilder $rrule_builder;

	/**
	 * Check-in email saver (null when Pro absent).
	 *
	 * @var CheckInEmailSaver|null
	 */
	private ?CheckInEmailSaver $checkin_email_saver;

	/**
	 * Attendee fields save handler.
	 *
	 * @var AttendeeFieldsSaveHandler
	 */
	private AttendeeFieldsSaveHandler $attendee_fields_saver;

	/**
	 * Constructor.
	 *
	 * @param EventRepositoryInterface          $event_repo                Event repository for slug generation.
	 * @param OccurrenceRepositoryInterface     $occurrence_repo           Occurrence repository.
	 * @param CategoryRepositoryInterface       $category_repo             Category repository.
	 * @param RecurrenceService                 $recurrence_service        Recurrence service for occurrence generation.
	 * @param TicketTypeSaver                   $ticket_saver              Ticket type saver.
	 * @param LayoutService                     $layout_service            Layout service.
	 * @param RecurrenceRuleBuilder             $rrule_builder             Recurrence rule builder.
	 * @param AttendeeFieldsSaveHandler         $attendee_fields_saver     Attendee fields save handler.
	 * @param CheckInEmailSaver|null            $checkin_email_saver       Check-in email saver (null-safe for Pro absence).
	 * @param OrganizerRepositoryInterface|null $organizer_repo            Organizer repository.
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		CategoryRepositoryInterface $category_repo,
		RecurrenceService $recurrence_service,
		TicketTypeSaver $ticket_saver,
		LayoutService $layout_service,
		RecurrenceRuleBuilder $rrule_builder,
		AttendeeFieldsSaveHandler $attendee_fields_saver,
		?CheckInEmailSaver $checkin_email_saver = null,
		?OrganizerRepositoryInterface $organizer_repo = null
	) {
		$this->event_repo            = $event_repo;
		$this->occurrence_repo       = $occurrence_repo;
		$this->category_repo         = $category_repo;
		$this->recurrence_service    = $recurrence_service;
		$this->ticket_saver          = $ticket_saver;
		$this->layout_service        = $layout_service;
		$this->rrule_builder         = $rrule_builder;
		$this->attendee_fields_saver = $attendee_fields_saver;
		$this->checkin_email_saver   = $checkin_email_saver;
		$this->organizer_repo        = $organizer_repo;
	}

	/**
	 * Handle form submission (action hook entry point).
	 *
	 * Delegates to process_save() and performs the redirect. This is
	 * the only place that calls wp_safe_redirect()/exit, keeping the
	 * rest of the class testable.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$redirect_url = $this->process_save();

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Process the save form submission and return a redirect URL.
	 *
	 * All validation, persistence, and error handling lives here.
	 * Returns the URL the caller should redirect to.
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
		if ( ! isset( $_POST['nettertech_events_event_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nettertech_events_event_nonce'] ) ), 'nettertech_events_save_event' ) ) {
			wp_die( esc_html__( 'Your session has expired. Please reload the page and try again.', 'nettertech-events' ) );
		}

		// Unslash $_POST once at the nonce-verified boundary so helpers can
		// accept already-unslashed data via parameter and PHPCS sees no
		// cross-method superglobal access. Per-field type-specific
		// sanitization continues at each read site in the helpers.
		$post = wp_unslash( $_POST );

		$event_id = isset( $post['event_id'] ) ? absint( $post['event_id'] ) : 0;

		// Load or create event.
		if ( $event_id > 0 ) {
			$event = $this->event_repo->find( $event_id );
			if ( ! $event ) {
				return admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG . '&message=error' );
			}
		} else {
			$event = new Event();
		}

		// Read the *stored* type before the POST clobbers it. Nothing used to, which is why
		// recurring → single was never a conversion at all — just a fall-through that overwrote
		// the earliest date and stranded the rest (NTE-153).
		$was_recurring = ( $event_id > 0 && $event->is_recurring() );

		$event = $this->extract_event_fields( $event, $post );

		$converting_to_single = $was_recurring && 'recurring' !== $event->event_type;

		$keep_occurrence = isset( $post['nettertech_events_keep_occurrence'] )
			? absint( $post['nettertech_events_keep_occurrence'] )
			: 0;

		try {
			// Ask before writing anything. The save below nulls the recurrence rule and flips the
			// type; a refusal *after* that would leave an event that is no longer recurring, has no
			// rule, and still has all its dates — a state nothing else knows how to read.
			if ( $converting_to_single ) {
				// $converting_to_single already implies a saved, previously-recurring event.
				$this->recurrence_service->assert_convertible_to_single(
					$event_id,
					$keep_occurrence > 0 ? $keep_occurrence : null
				);
			}

			// Handle recurrence rule for recurring events.
			if ( 'recurring' === $event->event_type ) {
				$this->assert_recurrence_specified( $post );

				$event->recurrence_rule = $this->rrule_builder->build_from_post(
					map_deep( $post, 'sanitize_textarea_field' )
				);
			} else {
				$event->recurrence_rule = null;
			}

			$event = $this->event_repo->save( $event );

			$saved_id = $event->id;
			if ( null === $saved_id ) {
				throw new \RuntimeException( 'Event save did not assign an ID.' );
			}

			// Event-scoped tickets (series passes, templates) belong to any event whose
			// dates a pass could span — recurring, or a single event carrying extra
			// hand-picked dates (NTE-156). Gating on the type alone left a two-date
			// festival with no way to sell one ticket covering both days.
			if ( 'recurring' === $event->event_type
				|| $this->occurrence_repo->count_for_event( $saved_id, 'scheduled' ) > 1 ) {
				$post_sanitized = map_deep( $post, 'sanitize_textarea_field' );
				$this->ticket_saver->save_for_event( $saved_id, $post_sanitized );
			}

			if ( $converting_to_single ) {
				// The surviving date keeps its OWN start and end. Do not run process_occurrences():
				// it would overwrite them with whatever the date metabox happens to hold, which for
				// a recurring event is the first date — so choosing to keep the third date would
				// have moved it onto the first one's slot.
				$this->recurrence_service->convert_to_single(
					$event,
					$keep_occurrence > 0 ? $keep_occurrence : null
				);
			} else {
				// Process occurrences (handles validation, creation, and ticket types).
				$this->process_occurrences( $event, $this->occurrence_repo, $post );
			}

			// After the pattern has had its say, so regeneration cannot collide with the new date.
			$this->add_manual_date( $event, $post );

			$this->save_pro_extensions( $saved_id, $post );
			$this->save_event_associations( $saved_id, $post );

			$message = $event_id > 0 ? 'updated' : 'created';
			return admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG . '&action=edit&event_id=' . $event->id . '&message=' . $message );

		} catch ( ValidationException $e ) {
			// Validation errors redirect back to the event editor.
			set_transient( 'nettertech_events_save_error_' . get_current_user_id(), $e->getMessage(), 60 );
			return admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG . '&action=edit&event_id=' . $event->id . '&message=error' );

		} catch ( \RuntimeException $e ) {
			// Store error in transient and redirect back.
			set_transient( 'nettertech_events_save_error_' . get_current_user_id(), $e->getMessage(), 60 );
			return admin_url( 'admin.php?page=' . AdminMenu::SUBMENU_NEW . '&message=error' );
		}
	}

	/**
	 * Populate event model fields from POST data.
	 *
	 * Handles all scalar field sanitization and layout config processing.
	 * Receives already-unslashed POST data from the nonce-verified boundary
	 * in process_save(); applies per-field type-specific sanitization here.
	 *
	 * @param Event                $event Event to populate.
	 * @param array<string, mixed> $post  Unslashed POST data.
	 * @return Event Populated event.
	 */
	public function extract_event_fields( Event $event, array $post ): Event {
		$event->title         = sanitize_text_field( $post['event_title'] ?? '' );
		$event->slug          = sanitize_title( $post['event_slug'] ?? '' );
		$event->description   = wp_kses_post( $post['event_description'] ?? '' );
		$event->excerpt       = sanitize_textarea_field( $post['event_excerpt'] ?? '' );
		$event->status        = EventStatus::tryFrom( sanitize_text_field( $post['event_status'] ?? 'draft' ) ) ?? EventStatus::DRAFT;
		$event->event_type    = sanitize_text_field( $post['event_type'] ?? 'single' );
		$event->venue_name    = sanitize_text_field( $post['venue_name'] ?? '' );
		$event->venue_address = sanitize_textarea_field( $post['venue_address'] ?? '' );

		// Featured image.
		$event->featured_image_id = ! empty( $post['featured_image_id'] ) ? absint( $post['featured_image_id'] ) : null;

		// Space assignment ("0" / absent = unassigned).
		$event->space_id = ! empty( $post['event_space_id'] ) ? absint( $post['event_space_id'] ) : null;

		// Featured-image vertical crop anchor (top|center|bottom; invalid/missing → center).
		// Store NULL for center to keep the column clean — model + render treat NULL as center.
		$anchor                       = self::sanitize_vertical_anchor( $post['image_vertical_anchor'] ?? null );
		$event->image_vertical_anchor = ( 'center' === $anchor ) ? null : $anchor;

		// Series.
		$event->series_id = ! empty( $post['series_id'] ) ? absint( $post['series_id'] ) : null;

		// Layout configuration.
		$this->process_layout_config( $event, $post );

		// Reminder emails: only set if the hidden marker field is present (form was rendered).
		if ( isset( $post['reminders_enabled_set'] ) ) {
			$event->reminders_enabled = ! empty( $post['reminders_enabled'] );
		}

		// Notification recipients (per-event).
		$event->notification_emails = self::sanitize_notification_emails(
			sanitize_textarea_field( $post['notification_emails'] ?? '' )
		);

		$event->is_virtual  = ! empty( $post['is_virtual'] );
		$event->virtual_url = ! empty( $post['virtual_url'] )
			? esc_url_raw( $post['virtual_url'] )
			: null;

		// Clear virtual URL when not virtual.
		if ( ! $event->is_virtual ) {
			$event->virtual_url = null;
		}

		// Per-attendee collection toggle.
		$event->collect_individual_attendees = ! empty( $post['collect_individual_attendees'] );

		// Generate slug if empty.
		if ( empty( $event->slug ) && ! empty( $event->title ) ) {
			$event->slug = $this->event_repo->generate_unique_slug( $event->title );
		}

		return $event;
	}

	/**
	 * Refuse to invent a recurrence pattern the operator never chose.
	 *
	 * The editor posts a *hidden default* for every recurrence field — frequency DAILY, end
	 * condition "never" — whether or not the operator ever opened the pattern controls. So an event
	 * switched to "recurring" and saved without choosing a pattern used to build `FREQ=DAILY` with no
	 * end, and the generator dutifully filled the whole horizon: one click, 286 dates (NTE-154).
	 *
	 * Defaults are a reasonable thing for a form to carry and a terrible thing to treat as an
	 * answer. An unchosen pattern is not "daily forever"; it is *no pattern*, and the only safe
	 * reading of no pattern is to stop and ask.
	 *
	 * @since 1.1.2
	 *
	 * @param array<string, mixed> $post Unslashed POST data from the nonce-verified boundary.
	 * @return void
	 *
	 * @throws ValidationException When the event is recurring but no pattern was chosen.
	 */
	private function assert_recurrence_specified( array $post ): void {
		$preset = sanitize_text_field( (string) ( $post['recurrence_preset'] ?? '' ) );
		$rule   = sanitize_text_field( (string) ( $post['recurrence_rule'] ?? '' ) );

		// An explicit rule, or a named preset, is a choice. "custom" with nothing in it is not.
		if ( '' !== $rule ) {
			return;
		}

		if ( '' !== $preset && 'custom' !== $preset ) {
			return;
		}

		throw ValidationException::fromErrors(
			array(
				esc_html__(
					'Choose how this event repeats before saving it as a recurring event. If you only want one more date, leave it as a single event and use "Add a date" on the Dates box instead.',
					'nettertech-events'
				),
			)
		);
	}

	/**
	 * Add one date to the event, outside whatever pattern the event may have.
	 *
	 * A recurrence pattern is one source of dates. It should not be the *only* one: an event that
	 * runs every Tuesday may also run on one Saturday, and until now there was no way to say so —
	 * the only dates an event could have were the ones its RRULE produced. (RFC 5545 has always
	 * allowed both: the set is RRULE ∪ RDATE − EXDATE.)
	 *
	 * The date is stamped `is_override`, which is the whole trick. That flag already means "the
	 * operator put this here; regeneration must not touch it", and the guards that honour it already
	 * exist and already work — deletion guards in delete_unprotected_occurrences_for_event() and
	 * delete_future_unattended_occurrences(), and a re-creation guard in
	 * remove_occurrences_colliding_with_survivors(). Nothing in the plugin ever *created* such a row;
	 * that missing verb was the entire gap. So an added date survives a later save of the pattern,
	 * for free.
	 *
	 * @since 1.1.2
	 *
	 * @param Event                $event The event that gets the date.
	 * @param array<string, mixed> $post  Unslashed POST data from the nonce-verified boundary.
	 * @return void
	 */
	private function add_manual_date( Event $event, array $post ): void {
		$event_id = $event->id;

		if ( null === $event_id ) {
			return;
		}

		$date  = sanitize_text_field( (string) ( $post['nettertech_events_new_date'] ?? '' ) );
		$start = sanitize_text_field( (string) ( $post['nettertech_events_new_start_time'] ?? '' ) );
		$end   = sanitize_text_field( (string) ( $post['nettertech_events_new_end_time'] ?? '' ) );

		if ( '' === $date || '' === $start || '' === $end ) {
			return;
		}

		$occurrence                 = new Occurrence();
		$occurrence->event_id       = $event_id;
		$occurrence->status         = 'scheduled';
		$occurrence->start_datetime = $date . ' ' . $start . ':00';
		$occurrence->end_datetime   = $date . ' ' . $end . ':00';

		// The zone the event's other dates are kept in, so the stored instants (start_utc/end_utc,
		// derived on save) name the moment the operator actually meant.
		$siblings             = $this->occurrence_repo->for_event( $event_id, array( 'limit' => 1 ) );
		$occurrence->timezone = isset( $siblings[0] ) && '' !== $siblings[0]->timezone
			? $siblings[0]->timezone
			: wp_timezone_string();

		// This is what regeneration already knows to leave alone.
		$occurrence->is_override = true;

		$saved = $this->occurrence_repo->save( $occurrence );

		// A date the pattern generated is given the event's ticket templates. A date added by hand is
		// still a date the event runs on, so it gets them too — otherwise an added date would be the
		// one date in the series that could sell nothing, and the operator would have no way to tell
		// why. Events with no templates are left alone; this creates nothing out of nothing.
		$this->recurrence_service->apply_templates_to_occurrences( $event, array( $saved ) );
	}

	/**
	 * Save Pro-gated extensions for an event.
	 *
	 * Saves check-in email recipients and fires the Pro extension hook.
	 * Safe to call when Pro is absent — all operations are null-guarded.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $post     Unslashed POST data from the nonce-verified boundary.
	 * @return void
	 */
	public function save_pro_extensions( int $event_id, array $post ): void {
		if ( null !== $this->checkin_email_saver ) {
			// Per-leaf boundary sanitization for the entire POST payload;
			// fine-grained ticket/recipient validation continues inside
			// CheckinEmailSaver::save().
			$post_sanitized = map_deep( $post, 'sanitize_textarea_field' );
			$this->checkin_email_saver->save( $event_id, $post_sanitized );
		}

		/**
		 * Fires after Pro extension data is saved for an event.
		 *
		 * @since 1.0.2
		 *
		 * @param int $event_id Event ID.
		 */
		do_action( 'nettertech_events_save_pro_extensions', $event_id );
	}

	/**
	 * Save event associations: categories, organizers, attendee fields, and QR logo.
	 *
	 * Receives already-unslashed POST data from the nonce-verified boundary
	 * in process_save(); applies per-field type-specific sanitization here.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $post     Unslashed POST data.
	 * @return void
	 */
	public function save_event_associations( int $event_id, array $post ): void {
		// Save QR logo settings.
		$this->save_qr_logo_settings( $event_id, $post );

		// Save custom attendee registration fields. Per-leaf boundary
		// sanitization via map_deep( sanitize_textarea_field ); per-field
		// type-specific tightening continues inside
		// AttendeeFieldsSaveHandler::save() (field type, slug, validation
		// rules, option lists).
		$attendee_fields_raw = isset( $post['attendee_fields'] ) && is_array( $post['attendee_fields'] )
			? map_deep( $post['attendee_fields'], 'sanitize_textarea_field' )
			: array();
		$this->attendee_fields_saver->save( $event_id, $attendee_fields_raw );

		// Save categories: existing IDs from checkbox list + new names from
		// inline "Add new categories" input. Comma-separated names are created
		// via $category_repo->save() and appended to the assignment set before
		// sync_event_categories() runs atomically.
		$category_ids     = isset( $post['event_categories'] )
			? array_map( 'absint', (array) $post['event_categories'] )
			: array();
		$new_category_csv = isset( $post['new_category_names'] ) && is_string( $post['new_category_names'] )
			? sanitize_text_field( $post['new_category_names'] )
			: '';
		if ( '' !== $new_category_csv ) {
			foreach ( explode( ',', $new_category_csv ) as $raw_name ) {
				$name = trim( $raw_name );
				if ( '' === $name ) {
					continue;
				}
				$category       = new \NetterTechEvents\Models\Category();
				$category->name = $name;
				$category->slug = sanitize_title( $name );
				try {
					$saved          = $this->category_repo->save( $category );
					$category_ids[] = (int) $saved->id;
				} catch ( \RuntimeException $e ) {
					// Duplicate slug or other constraint - skip silently.
					unset( $e );
				}
			}
		}
		$this->category_repo->sync_event_categories( $event_id, $category_ids );

		// Save tags: existing IDs from checkbox list + new names from inline
		// "Add new tags" input. New names go through find_or_create() (matches
		// WP core's post-tag UX).
		$tag_ids     = isset( $post['event_tags'] )
			? array_map( 'absint', (array) $post['event_tags'] )
			: array();
		$new_tag_csv = isset( $post['new_tag_names'] ) && is_string( $post['new_tag_names'] )
			? sanitize_text_field( $post['new_tag_names'] )
			: '';
		$tag_repo    = \NetterTechEvents\Core\ServiceRegistry::tag_repository();
		if ( '' !== $new_tag_csv ) {
			foreach ( explode( ',', $new_tag_csv ) as $raw_name ) {
				$name = trim( $raw_name );
				if ( '' === $name ) {
					continue;
				}
				$tag       = $tag_repo->find_or_create( $name );
				$tag_ids[] = (int) $tag->id;
			}
		}
		$tag_repo->sync_event_tags( $event_id, $tag_ids );

		// Save organizers (nettertech_events_event_organizers junction table).
		if ( null !== $this->organizer_repo ) {
			$organizer_ids = isset( $post['event_organizers'] )
				? array_map( 'absint', (array) $post['event_organizers'] )
				: array();
			$this->organizer_repo->sync_event_organizers( $event_id, $organizer_ids );
		}
	}

	/**
	 * Process layout configuration from POST data.
	 *
	 * @param Event                $event Event to update.
	 * @param array<string, mixed> $post  Unslashed POST data from the nonce-verified boundary.
	 * @return void
	 */
	public function process_layout_config( Event $event, array $post ): void {
		$layout_mode = sanitize_text_field( $post['nettertech_events_layout_mode'] ?? 'global' );

		if ( 'custom' !== $layout_mode ) {
			$event->layout_config = null;
			return;
		}

		$layout_order_raw      = sanitize_text_field( $post['event_layout_order'] ?? '' );
		$layout_visibility_raw = sanitize_text_field( $post['event_layout_visibility'] ?? '' );

		if ( empty( $layout_order_raw ) ) {
			return;
		}

		$layout_order      = array_filter( array_map( 'trim', explode( ',', $layout_order_raw ) ) );
		$layout_visibility = json_decode( $layout_visibility_raw, true );

		if ( ! is_array( $layout_visibility ) ) {
			$layout_visibility = array();
		}

		$layout_config = array(
			'order'      => $layout_order,
			'visibility' => $layout_visibility,
		);

		if ( $this->layout_service->validate_config( $layout_config ) ) {
			$event->layout_config = $this->layout_service->sanitize_config( $layout_config );
		}
	}

	/**
	 * Process occurrences from POST data.
	 *
	 * Handles datetime validation, occurrence creation (single or recurring),
	 * capacity setting, and ticket type saving.
	 *
	 * @param Event                         $event           Event to process occurrences for.
	 * @param OccurrenceRepositoryInterface $occurrence_repo Occurrence repository.
	 * @param array<string, mixed>          $post            Unslashed POST data from the nonce-verified boundary.
	 * @return void
	 * @throws \RuntimeException When recurrence generation fails.
	 */
	public function process_occurrences( Event $event, OccurrenceRepositoryInterface $occurrence_repo, array $post ): void {
		$start_date = sanitize_text_field( $post['start_date'] ?? '' );

		if ( empty( $start_date ) ) {
			return;
		}

		$start_time_raw = sanitize_text_field( $post['start_time'] ?? '' );
		$end_date       = sanitize_text_field( $post['end_date'] ?? '' );
		$end_time_raw   = sanitize_text_field( $post['end_time'] ?? '' );
		$all_day        = ! empty( $post['all_day'] );
		$capacity       = isset( $post['occurrence_capacity'] ) && '' !== $post['occurrence_capacity']
			? absint( $post['occurrence_capacity'] )
			: null;

		// Default end date to start date when not provided.
		if ( empty( $end_date ) ) {
			$end_date = $start_date;
		}

		// Time fallbacks: all-day events span midnight to end-of-day; timed
		// events default to the configured start time + duration when the
		// author supplied a date but left the time blank. Defaults are
		// site-tunable in Settings → Display (default_event_start_time /
		// default_event_duration_minutes); ship defaults are 19:00 / 120 min
		// (venue evening-performance pattern). Industry-standard rationale
		// surveyed Google/Apple/Outlook vs Eventbrite/Tito/TEC/MEC.
		if ( $all_day ) {
			$start_time = ! empty( $start_time_raw ) ? $start_time_raw : '00:00';
			$end_time   = ! empty( $end_time_raw ) ? $end_time_raw : '23:59';
		} else {
			$display          = \NetterTechEvents\Core\NetterTechEventsSettings::from_option()->display;
			$default_start    = $display->default_event_start_time;
			$default_duration = $display->default_event_duration_minutes;
			$start_time       = ! empty( $start_time_raw ) ? $start_time_raw : $default_start;
			if ( ! empty( $end_time_raw ) ) {
				$end_time = $end_time_raw;
			} else {
				$end_time = ( new \DateTimeImmutable( $start_date . ' ' . $start_time . ':00' ) )
					->modify( '+' . $default_duration . ' minutes' )
					->format( 'H:i' );
			}
		}

		$start_datetime = new \DateTimeImmutable( $start_date . ' ' . $start_time . ':00' );
		$end_datetime   = new \DateTimeImmutable( $end_date . ' ' . $end_time . ':00' );

		// Validate datetime and capacity.
		$this->validate_occurrence_data( $event, $start_datetime, $end_datetime, $capacity );

		// Create occurrences based on event type.
		if ( 'recurring' === $event->event_type && ! empty( $event->recurrence_rule ) ) {
			$this->create_recurring_occurrences( $event, $start_datetime, $end_datetime );
		} else {
			$this->create_single_occurrence( $event, $start_datetime, $end_datetime, $all_day, $capacity, $occurrence_repo, $post );
		}
	}

	/**
	 * Validate occurrence datetime and capacity.
	 *
	 * @param Event              $event          Event being validated.
	 * @param \DateTimeImmutable $start_datetime Start datetime.
	 * @param \DateTimeImmutable $end_datetime   End datetime.
	 * @param mixed              $capacity       Capacity value.
	 * @return void
	 * @throws ValidationException When validation fails.
	 */
	public function validate_occurrence_data(
		Event $event,
		\DateTimeImmutable $start_datetime,
		\DateTimeImmutable $end_datetime,
		mixed $capacity
	): void {
		// Validate: event must be at least 10 minutes long.
		$duration_seconds = $end_datetime->getTimestamp() - $start_datetime->getTimestamp();
		if ( $duration_seconds < 600 ) {
			throw ValidationException::fromErrors(
				array( esc_html__( 'Event must be at least 10 minutes long. Please adjust the end time.', 'nettertech-events' ) )
			);
		}

		// Validate: capacity must be a positive integer if provided.
		if ( null !== $capacity && '' !== $capacity && ( ! is_numeric( $capacity ) || (int) $capacity < 0 ) ) {
			throw ValidationException::fromErrors(
				array( esc_html__( 'Capacity must be a positive number or left empty for unlimited.', 'nettertech-events' ) )
			);
		}
	}

	/**
	 * Create recurring occurrences for an event.
	 *
	 * @param Event              $event          Event to create occurrences for.
	 * @param \DateTimeImmutable $start_datetime Start datetime.
	 * @param \DateTimeImmutable $end_datetime   End datetime.
	 * @return void
	 * @throws ValidationException When generation fails.
	 */
	public function create_recurring_occurrences(
		Event $event,
		\DateTimeImmutable $start_datetime,
		\DateTimeImmutable $end_datetime
	): void {
		$rrule = $event->recurrence_rule;
		if ( null === $rrule || '' === $rrule ) {
			throw ValidationException::fromErrors(
				array( esc_html__( 'A recurrence rule is required for recurring events.', 'nettertech-events' ) )
			);
		}

		$result = $this->recurrence_service->generate_occurrences(
			$event,
			$start_datetime,
			$end_datetime,
			$rrule,
			true // Replace existing.
		);

		if ( ! empty( $result['errors'] ) ) {
			throw ValidationException::fromErrors( array_map( 'esc_html', (array) $result['errors'] ) );
		}
	}

	/**
	 * Create a single occurrence for an event.
	 *
	 * @param Event                         $event           Event to create occurrence for.
	 * @param \DateTimeImmutable            $start_datetime  Start datetime.
	 * @param \DateTimeImmutable            $end_datetime    End datetime.
	 * @param bool                          $all_day         Whether this is an all-day event.
	 * @param mixed                         $capacity        Capacity value.
	 * @param OccurrenceRepositoryInterface $occurrence_repo Occurrence repository.
	 * @param array<string, mixed>          $post            Unslashed POST data from the nonce-verified boundary.
	 * @return void
	 */
	public function create_single_occurrence(
		Event $event,
		\DateTimeImmutable $start_datetime,
		\DateTimeImmutable $end_datetime,
		bool $all_day,
		mixed $capacity,
		OccurrenceRepositoryInterface $occurrence_repo,
		array $post
	): void {
		$occurrence = $this->recurrence_service->create_single_occurrence(
			$event,
			$start_datetime,
			$end_datetime,
			$all_day
		);

		if ( ! $occurrence ) {
			return;
		}

		// Save capacity (handle 0 and empty string as "unlimited"/null).
		$occurrence->capacity = ( null !== $capacity && '' !== $capacity )
			? absint( $capacity )
			: null;

		$occurrence_repo->save( $occurrence );

		$occurrence_id = $occurrence->id;
		$event_id      = $event->id;
		if ( null === $occurrence_id || null === $event_id ) {
			return;
		}

		// Save ticket types. Per-leaf boundary sanitization via
		// map_deep( sanitize_textarea_field ); ticket-specific tightening
		// (numeric caps, prices, status enum) runs in TicketTypeSaver.
		$post_sanitized = map_deep( $post, 'sanitize_textarea_field' );
		$this->ticket_saver->save_for_occurrence( $occurrence_id, $post_sanitized, $event_id );
	}

	/**
	 * Save QR logo settings for an event.
	 *
	 * Writes per-event QR logo mode and custom logo attachment ID
	 * directly to the events custom table.
	 *
	 * @param int                  $event_id Event ID (custom table ID).
	 * @param array<string, mixed> $post     Unslashed POST data from the nonce-verified boundary.
	 * @return void
	 */
	private function save_qr_logo_settings( int $event_id, array $post ): void {
		global $wpdb;

		$valid_modes = array( 'default', 'none', 'site', 'custom' );
		$logo_mode   = isset( $post['_nettertech_events_qr_logo_mode'] ) ? sanitize_key( $post['_nettertech_events_qr_logo_mode'] ) : 'default';
		$logo_mode   = in_array( $logo_mode, $valid_modes, true ) ? $logo_mode : 'default';
		$logo_id     = isset( $post['_nettertech_events_qr_logo_id'] ) ? absint( $post['_nettertech_events_qr_logo_id'] ) : 0;

		$events_table = Schema::table( 'events' );

		// Store NULL when reverting to defaults (keeps column clean).
		$mode_value = ( 'default' !== $logo_mode ) ? $logo_mode : null;
		$id_value   = ( $logo_id > 0 ) ? $logo_id : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update to custom table; no cache for targeted column update.
		$wpdb->update(
			$events_table,
			array(
				'qr_logo_mode'          => $mode_value,
				'qr_logo_attachment_id' => $id_value,
			),
			array( 'id' => $event_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Sanitize the per-event image vertical crop anchor.
	 *
	 * Allowlists top|center|bottom; anything else (missing, invalid type, or an
	 * unrecognized value) falls back to 'center' per FR-005. Callers store NULL
	 * for 'center' to keep the column clean.
	 *
	 * @param mixed $raw Raw input.
	 * @return string One of 'top', 'center', or 'bottom'.
	 */
	private static function sanitize_vertical_anchor( mixed $raw ): string {
		$value = is_string( $raw ) ? sanitize_key( $raw ) : '';
		return in_array( $value, array( 'top', 'center', 'bottom' ), true ) ? $value : 'center';
	}

	/**
	 * Sanitize notification emails from textarea input.
	 *
	 * Accepts newline-separated or comma-separated emails.
	 * Returns a comma-separated string of valid emails, or null if empty.
	 *
	 * @param mixed $raw Raw input (string expected).
	 * @return string|null Comma-separated valid emails, or null.
	 */
	private static function sanitize_notification_emails( mixed $raw ): ?string {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}

		// Split on newlines and commas, trim, validate.
		$addresses = preg_split( '/[\r\n,]+/', $raw );
		if ( ! is_array( $addresses ) ) {
			return null;
		}
		$valid = array();

		foreach ( $addresses as $address ) {
			$address = sanitize_email( trim( $address ) );
			if ( is_email( $address ) ) {
				$valid[] = $address;
			}
		}

		return empty( $valid ) ? null : implode( ',', array_unique( $valid ) );
	}
}
