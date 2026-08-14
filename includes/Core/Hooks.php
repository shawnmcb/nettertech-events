<?php
/**
 * Centralized action and filter hooks.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Manages all action and filter hook names used by the plugin.
 *
 * @since 1.0.2
 * @api
 * PHPMD ExcessiveClassLength suppressed: central hook-name registry; splitting would
 * make hook discovery harder.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
final class Hooks {

	// =========================================================================
	// Plugin Lifecycle
	// =========================================================================

	/**
	 * Fires after the plugin is fully initialized.
	 *
	 * @param \NetterTechEvents\Core\Plugin $plugin The main plugin instance.
	 */
	public const INIT = 'nettertech_events_init';

	/**
	 * Fires on plugin activation, after tables are created.
	 */
	public const ACTIVATED = 'nettertech_events_activated';

	/**
	 * Fires on plugin deactivation.
	 */
	public const DEACTIVATED = 'nettertech_events_deactivated';

	/**
	 * Fires after the table/option prefix migration completes successfully.
	 *
	 * Add-on plugins (Pro, Rentals, Seating) listen on this hook to run
	 * their own prefix migrations.
	 *
	 * @since 1.0.2
	 *
	 * @param array $log Migration log with per-phase counts.
	 */
	public const PREFIX_MIGRATION_COMPLETE = 'nettertech_events_prefix_migration_complete';

	// =========================================================================
	// Data Lifecycle - Events & Occurrences
	// =========================================================================

	/**
	 * Fires before an event is created or updated.
	 *
	 * @param \NetterTechEvents\Models\Event $event  The event model to be saved.
	 * @param array                     $data   The raw data array being saved.
	 */
	public const BEFORE_SAVE_EVENT = 'nettertech_events_before_save_event';

	/**
	 * Fires after an event is created or updated.
	 *
	 * @param \NetterTechEvents\Models\Event $event The saved event model.
	 */
	public const AFTER_SAVE_EVENT = 'nettertech_events_after_save_event';

	/**
	 * Fires before an event is deleted.
	 *
	 * @param \NetterTechEvents\Models\Event $event The event model to be deleted.
	 */
	public const BEFORE_DELETE_EVENT = 'nettertech_events_before_delete_event';

	/**
	 * Fires after an event has been deleted.
	 *
	 * @param int                       $id    The ID of the deleted event.
	 * @param \NetterTechEvents\Models\Event $event The event model that was deleted.
	 */
	public const AFTER_DELETE_EVENT = 'nettertech_events_after_delete_event';

	/**
	 * Fires before an event is duplicated.
	 *
	 * @param \NetterTechEvents\Models\Event $duplicate The duplicate event (not yet saved).
	 * @param \NetterTechEvents\Models\Event $source    The source event.
	 */
	public const BEFORE_DUPLICATE_EVENT = 'nettertech_events_before_duplicate_event';

	/**
	 * Fires after an event is duplicated.
	 *
	 * @param \NetterTechEvents\Models\Event $saved  The saved duplicate event.
	 * @param \NetterTechEvents\Models\Event $source The source event.
	 */
	public const AFTER_DUPLICATE_EVENT = 'nettertech_events_after_duplicate_event';

	/**
	 * Fires after occurrences are generated for a recurring event.
	 *
	 * @param \NetterTechEvents\Models\Event $event         The parent event.
	 * @param \NetterTechEvents\Models\Occurrence[] $occurrences The array of generated occurrences.
	 * @param \NetterTechEvents\Models\RecurrenceRule $rule The recurrence rule used.
	 */
	public const OCCURRENCES_GENERATED = 'nettertech_events_occurrences_generated';

	/**
	 * Fires when an occurrence's status changes.
	 *
	 * @param int    $id         The occurrence ID.
	 * @param string $new_status The new status.
	 * @param string $old_status The old status.
	 */
	public const OCCURRENCE_STATUS_CHANGED = 'nettertech_events_occurrence_status_changed';

	/**
	 * Fires when an occurrence deletion is blocked because it has attendees.
	 *
	 * Triggered during recurrence regeneration when an occurrence would
	 * have been deleted but has confirmed attendees. Allows admin
	 * notification or logging of the protection event.
	 *
	 * @param int $occurrence_id  The occurrence ID that was protected.
	 * @param int $attendee_count Number of active (non-cancelled) attendees.
	 */
	public const OCCURRENCE_DELETION_BLOCKED = 'nettertech_events_occurrence_deletion_blocked';

	/**
	 * Fires after templates are applied to occurrences.
	 *
	 * @param \NetterTechEvents\Models\Event        $event       The parent event.
	 * @param \NetterTechEvents\Models\Occurrence[] $occurrences The occurrences.
	 * @param int                              $created     Number of occurrences created.
	 */
	public const TEMPLATES_APPLIED = 'nettertech_events_templates_applied';

	/**
	 * Fires when a ticket type needs WooCommerce product synchronization.
	 *
	 * @param \NetterTechEvents\Models\TicketType  $ticket_type The ticket type to sync.
	 * @param \NetterTechEvents\Models\Occurrence $occurrence  The parent occurrence.
	 */
	public const TICKET_TYPE_SYNC_PRODUCT = 'nettertech_events_ticket_type_sync_product';

	/**
	 * Filters the final product_cat term-ID set for a ticket product.
	 *
	 * @since 1.1.2
	 *
	 * @param array<int>  $new_set  Resolved and preserved term IDs.
	 * @param int         $event_id Linked event ID.
	 * @param \WC_Product $product  Ticket product.
	 */
	public const PRODUCT_CAT_IDS = 'nettertech_events_product_cat_ids';

	/**
	 * Fires when a capacity reservation changes (created, released, or expired).
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param int    $delta          Change in reserved quantity (positive = reserved, negative = released).
	 * @param string $session_key    Session key associated with the change.
	 */
	public const RESERVATION_CHANGED = 'nettertech_events_reservation_changed';

	/**
	 * Fires when an event is created.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $data     Event data.
	 */
	public const EVENT_CREATED = 'nettertech_events_event_created';

	/**
	 * Fires when an event is updated.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $data     Updated data.
	 */
	public const EVENT_UPDATED = 'nettertech_events_event_updated';

	/**
	 * Fires when an event is deleted.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $title    Event title.
	 */
	public const EVENT_DELETED = 'nettertech_events_event_deleted';

	/**
	 * Fires when an event is published.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $title    Event title.
	 */
	public const EVENT_PUBLISHED = 'nettertech_events_event_published';

	/**
	 * Fires when an event is unpublished.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $title    Event title.
	 */
	public const EVENT_UNPUBLISHED = 'nettertech_events_event_unpublished';

	/**
	 * Fires when an event is restored from a revision.
	 *
	 * @param int   $event_id    Event ID.
	 * @param int   $revision_id Revision ID that was restored.
	 * @param array $old_data    Pre-restore snapshot data.
	 */
	public const EVENT_RESTORED = 'nettertech_events_event_restored';

	/**
	 * Filter the maximum number of revisions to keep per event.
	 *
	 * @param int $max_revisions Default 20.
	 */
	public const MAX_REVISIONS = 'nettertech_events_max_revisions';

	/**
	 * Fires when an occurrence is created.
	 *
	 * @param int                  $occurrence_id Occurrence ID.
	 * @param array<string, mixed> $data          Occurrence data.
	 */
	public const OCCURRENCE_CREATED = 'nettertech_events_occurrence_created';

	/**
	 * Fires when an occurrence is deleted.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $title         Event title.
	 */
	public const OCCURRENCE_DELETED = 'nettertech_events_occurrence_deleted';

	/**
	 * Fires when an occurrence is cancelled.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $title         Event title.
	 */
	public const OCCURRENCE_CANCELLED = 'nettertech_events_occurrence_cancelled';


	// =========================================================================
	// Data Lifecycle - Attendees & Tickets
	// =========================================================================

	/**
	 * Fires after an attendee is created from a WooCommerce order.
	 *
	 * @param \NetterTechEvents\Models\Attendee $attendee The created attendee.
	 * @param \WC_Order                    $order    The WooCommerce order.
	 * @param \WC_Order_Item_Product       $item     The order item.
	 */
	public const ATTENDEE_CREATED = 'nettertech_events_attendee_created';

	/**
	 * Fires when an attendee is cancelled.
	 *
	 * Reserved: core does not currently fire this hook. Attendee removal in
	 * the WooCommerce order flow fires REGISTRATION_VOIDED instead.
	 *
	 * @param int    $attendee_id Attendee ID.
	 * @param string $name        Attendee name.
	 */
	public const ATTENDEE_CANCELLED = 'nettertech_events_attendee_cancelled';

	/**
	 * Fires when an attendee is checked in.
	 *
	 * @param int                  $attendee_id Attendee ID.
	 * @param array<string, mixed> $data        Check-in data.
	 */
	public const ATTENDEE_CHECKED_IN = 'nettertech_events_attendee_checked_in';

	/**
	 * Fires when attendee creation fails.
	 *
	 * @param \Exception             $exception The exception that was thrown.
	 * @param int                    $order_id  WooCommerce order ID.
	 * @param \WC_Order_Item_Product $item      The order item.
	 */
	public const ATTENDEE_CREATION_FAILED = 'nettertech_events_attendee_creation_failed';

	/**
	 * Fires after custom field values are saved for an attendee.
	 *
	 * @param int                           $attendee_id Attendee ID.
	 * @param int                           $event_id    Event ID.
	 * @param array<string, string>         $values      Field key => value pairs.
	 *
	 * @since 1.0.2
	 */
	public const CUSTOM_FIELD_VALUES_SAVED = 'nettertech_events_custom_field_values_saved';

	/**
	 * Fires after the failure handler has completed all corrective actions.
	 *
	 * Allows downstream consumers to add custom failure handling (e.g.,
	 * Slack notifications, external ticket system integration).
	 *
	 * @param int                    $order_id      WooCommerce order ID.
	 * @param \WC_Order_Item_Product $item          The order item that failed.
	 * @param \RuntimeException      $exception     The exception that caused the failure.
	 * @param array<string>          $actions_taken List of actions completed (e.g., 'order_note', 'customer_note', 'status_changed', 'admin_email', 'activity_log').
	 */
	public const ATTENDEE_FAILURE_HANDLED = 'nettertech_events_attendee_failure_handled';

	/**
	 * Fires when a registration is voided due to order cancellation/full refund.
	 *
	 * @param \NetterTechEvents\Models\Attendee $attendee The voided attendee.
	 * @param \WC_Order                    $order    The WooCommerce order.
	 */
	public const REGISTRATION_VOIDED = 'nettertech_events_registration_voided';

	/**
	 * Fires when tickets are refunded (covers partial refunds).
	 *
	 * @param \NetterTechEvents\Models\Attendee $attendee     The affected attendee.
	 * @param int                          $refunded_qty The number of tickets refunded.
	 * @param int                          $new_quantity The new quantity for the attendee (0 if all refunded).
	 * @param \WC_Order                    $order        The parent order.
	 * @param \WC_Order_Refund             $refund       The refund object.
	 */
	public const TICKETS_REFUNDED = 'nettertech_events_tickets_refunded';

	/**
	 * Fires after ticket types are saved for an event/occurrence.
	 *
	 * @param int                                   $event_id      Event ID.
	 * @param int                                   $occurrence_id Occurrence ID.
	 * @param int[]                                 $submitted_ids Ids of every tier saved, new ones included.
	 * @param array<string, array<int|string, int>> $saved_ids     Scope, then posted row index, to tier id.
	 */
	public const TICKET_TYPES_SAVED = 'nettertech_events_ticket_types_saved';

	/**
	 * Fires inside a ticket-type row in the admin form, after the tier's own fields.
	 *
	 * Where an extension hangs per-tier configuration. Also fires while the new-row template is
	 * built, so added fields follow rows the operator creates in the browser.
	 *
	 * @param \NetterTechEvents\Models\TicketType $ticket The tier; empty in the new-row template.
	 * @param int|string                          $index  Row index, or '{{INDEX}}' in the template.
	 * @param \NetterTechEvents\Enums\TicketTypeScope $scope The tier's scope.
	 * @param string                              $prefix Field-name prefix.
	 */
	public const TICKET_ROW_FIELDS = 'nettertech_events_ticket_row_fields';

	/**
	 * Filters the tiers offered for editing in the tickets metabox.
	 *
	 * Lets an extension withhold a tier it derives and keeps in step with another, so the operator
	 * is not offered a form whose edits would be overwritten. Affects this one form only.
	 *
	 * @param array<\NetterTechEvents\Models\TicketType> $tickets    The tiers about to be drawn.
	 * @param \NetterTechEvents\Enums\TicketTypeScope    $scope      Which list is being drawn.
	 * @param \NetterTechEvents\Models\Event|null        $event      The event being edited.
	 * @param \NetterTechEvents\Models\Occurrence|null   $occurrence The occurrence, when one is in scope.
	 */
	public const ADMIN_TICKET_ROWS = 'nettertech_events_admin_ticket_rows';

	/**
	 * Filters the tiers about to be deleted for not coming back with the ticket form.
	 *
	 * Absence from the payload means the operator removed the row — but only for a row the form
	 * offered. A tier an extension withholds must be spared here too, or saving destroys it.
	 *
	 * @param array<int> $removed       Tier IDs about to be deleted.
	 * @param array<int> $existing_ids  Every tier that existed before this save.
	 * @param array<int> $submitted_ids The tier IDs the form posted back.
	 */
	public const TICKET_TYPES_TO_DELETE = 'nettertech_events_ticket_types_to_delete';

	/**
	 * Fires when a ticket type is created.
	 *
	 * @param int                  $ticket_type_id Ticket type ID.
	 * @param array<string, mixed> $data           Ticket type data.
	 */
	public const TICKET_TYPE_CREATED = 'nettertech_events_ticket_type_created';

	/**
	 * Fires when a ticket type is updated.
	 *
	 * @param int                  $ticket_type_id Ticket type ID.
	 * @param array<string, mixed> $data           Updated data.
	 */
	public const TICKET_TYPE_UPDATED = 'nettertech_events_ticket_type_updated';

	/**
	 * Fires when a ticket type is saved (created or updated).
	 *
	 * Reserved: core does not currently fire this hook. Subscribe to
	 * TICKET_TYPE_CREATED or TICKET_TYPE_UPDATED for per-entity saves, or
	 * TICKET_TYPES_SAVED for the batch-level save event.
	 *
	 * @param int                  $ticket_type_id Ticket type ID.
	 * @param array<string, mixed> $data           Ticket type data.
	 */
	public const TICKET_TYPE_SAVED = 'nettertech_events_ticket_type_saved';

	/**
	 * Fires when a ticket type is deleted.
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $name           Ticket type name.
	 */
	public const TICKET_TYPE_DELETED = 'nettertech_events_ticket_type_deleted';

	/**
	 * Fires after an RSVP form is successfully submitted and an attendee is created.
	 *
	 * @param int   $attendee_id The ID of the new attendee.
	 * @param array $form_data   The submitted form data.
	 */
	public const RSVP_SUBMITTED = 'nettertech_events_rsvp_submitted';

	// =========================================================================
	// Email & Notification Hooks
	// =========================================================================

	/**
	 * Fires after confirmation emails (customer and venue) are sent for an order.
	 *
	 * @param int      $order_id      WooCommerce order ID.
	 * @param \NetterTechEvents\Models\Ticket[] $tickets       The tickets from the order.
	 * @param bool     $customer_sent True if the customer email was sent.
	 * @param bool     $venue_sent    True if any venue notification emails were sent.
	 */
	public const CONFIRMATION_EMAILS_SENT = 'nettertech_events_confirmation_emails_sent';

	/**
	 * Filters the reminder email subject line.
	 *
	 * @param string $subject    The default subject line.
	 * @param object $occurrence The occurrence.
	 * @param object $event      The event.
	 * @return string
	 */
	public const REMINDER_EMAIL_SUBJECT = 'nettertech_events_reminder_email_subject';

	/**
	 * Filters the reminder email data before rendering.
	 *
	 * @param array  $data       The email data array.
	 * @param object $occurrence The occurrence.
	 * @param object $event      The event.
	 * @param object $attendee   The attendee.
	 * @return array
	 */
	public const REMINDER_EMAIL_DATA = 'nettertech_events_reminder_email_data';

	/**
	 * Filters the rendered reminder email HTML content.
	 *
	 * @param string $html       The rendered HTML.
	 * @param object $occurrence The occurrence.
	 * @param object $event      The event.
	 * @param object $attendee   The attendee.
	 * @return string
	 */
	public const REMINDER_EMAIL_CONTENT = 'nettertech_events_reminder_email_content';


	// =========================================================================
	// Waitlist Hooks
	// =========================================================================

	/**
	 * Fires when someone joins the waitlist.
	 *
	 * @param \NetterTechEvents\Models\WaitlistEntry $entry         The waitlist entry.
	 * @param int                               $occurrence_id The occurrence ID.
	 */
	public const WAITLIST_JOINED = 'nettertech_events_waitlist_joined';

	/**
	 * Fires when someone leaves the waitlist.
	 *
	 * @param \NetterTechEvents\Models\WaitlistEntry $entry         The waitlist entry.
	 * @param int                               $occurrence_id The occurrence ID.
	 */
	public const WAITLIST_LEFT = 'nettertech_events_waitlist_left';

	/**
	 * Fires when a waitlist entry is promoted (next in line).
	 *
	 * WaitlistEmailHandler sends the notification email.
	 *
	 * @param \NetterTechEvents\Models\WaitlistEntry $entry         The promoted entry.
	 * @param int                               $occurrence_id The occurrence ID.
	 */
	public const WAITLIST_PROMOTED = 'nettertech_events_waitlist_promoted';

	/**
	 * Filters whether waitlist functionality is available.
	 *
	 * Defaults to true; the frontend uses this to decide whether to render
	 * waitlist panels. Return false to disable waitlist UI entirely.
	 *
	 * @since 1.0.2
	 *
	 * @param bool $has_waitlist Whether waitlist functionality is available.
	 */
	public const HAS_WAITLIST = 'nettertech_events_has_waitlist';

	/**
	 * Fires after the sold-out message renders, before the closing wrapper.
	 *
	 * Allows a waitlist join panel (or other content) to render below the
	 * sold-out message. RSVP contexts pass a placeholder TicketType because
	 * RSVP events may not have a specific one.
	 *
	 * @since 1.0.2
	 *
	 * @param \NetterTechEvents\Models\TicketType $ticket_type The sold-out ticket type (placeholder in RSVP contexts).
	 * @param \NetterTechEvents\Models\Occurrence $occurrence  The occurrence.
	 */
	public const AFTER_SOLD_OUT = 'nettertech_events_after_sold_out';

	/**
	 * Filters whether listing controllers should compute per-card availability.
	 *
	 * Return true to have the list/carousel shortcodes and series page run the
	 * batched on-sale prefetch plus OccurrenceAvailabilityPresenter verdicts and
	 * pass a prefetched_availability array ('sold_out' => bool) into every
	 * event-card context — the no-N+1 path for extensions that render
	 * availability labels (NTE-203). Base renders no label itself, so the
	 * default false keeps free installs free of the extra queries.
	 *
	 * @since 1.4.0
	 *
	 * @param bool $needed Whether card availability is needed. Default false.
	 */
	public const CARDS_NEED_AVAILABILITY = 'nettertech_events_cards_need_availability';

	/**
	 * Fires in the event card's status slot for active occurrences.
	 *
	 * Runs only when the card is neither cancelled nor past (those states
	 * print base labels in the same slot). Extensions may print status markup
	 * here — e.g. a sold-out badge from the context's prefetched_availability
	 * — and the output must satisfy ShortcodeOutput::get_allowlist(), which
	 * the listing shortcodes apply to the whole card.
	 *
	 * @since 1.4.0
	 *
	 * @param \NetterTechEvents\TemplateLoader\TemplateContext $context Card template context.
	 */
	public const EVENT_CARD_STATUS = 'nettertech_events_event_card_status';

	/**
	 * Fires after a visitor joins a waitlist via the REST API.
	 *
	 * Extensions can mint a cancellation token, send a confirmation email,
	 * or perform other post-join workflows.
	 *
	 * @since 1.0.2
	 *
	 * @param \NetterTechEvents\Models\WaitlistEntry $entry         Newly created waitlist entry.
	 * @param int                                    $occurrence_id Occurrence ID joined.
	 */
	public const WAITLIST_ENTRY_JOINED = 'nettertech_events_waitlist_entry_joined';

	/**
	 * Filters authorization for a waitlist leave request.
	 *
	 * Return true to authorize, or a WP_Error to deny with a specific reason.
	 *
	 * @since 1.0.2
	 *
	 * @param bool|\WP_Error   $authorized    True if authorized; WP_Error to deny.
	 * @param int              $occurrence_id Occurrence ID being left.
	 * @param string           $email         Email address requesting leave.
	 * @param \WP_REST_Request $request       Full REST request.
	 */
	public const WAITLIST_LEAVE_AUTHORIZED = 'nettertech_events_waitlist_leave_authorized';

	/**
	 * Filters the waitlist leave-token lifetime in seconds.
	 *
	 * Values below 300 are clamped to 300.
	 *
	 * @since 1.0.2
	 *
	 * @param int $ttl Token lifetime in seconds.
	 */
	public const WAITLIST_LEAVE_TOKEN_TTL = 'nettertech_events_waitlist_leave_token_ttl';

	/**
	 * Filters whether a waitlist status query may reveal the real status.
	 *
	 * False by default; return true to reveal real status to the requester.
	 *
	 * @since 1.0.2
	 *
	 * @param bool             $authorized    False by default.
	 * @param int              $occurrence_id Occurrence ID being queried.
	 * @param string           $email         Email address being queried.
	 * @param \WP_REST_Request $request       Full REST request.
	 */
	public const WAITLIST_STATUS_AUTHORIZED = 'nettertech_events_waitlist_status_authorized';

	/**
	 * Filters the waitlist promotion email subject.
	 *
	 * @since 1.0.2
	 *
	 * @param string                                 $subject       Email subject.
	 * @param \NetterTechEvents\Models\WaitlistEntry $entry         The waitlist entry.
	 * @param int                                    $occurrence_id The occurrence ID.
	 */
	public const WAITLIST_EMAIL_SUBJECT = 'nettertech_events_waitlist_email_subject';

	/**
	 * Filters the waitlist promotion email body.
	 *
	 * @since 1.0.2
	 *
	 * @param string                                 $body          Email body (HTML).
	 * @param \NetterTechEvents\Models\WaitlistEntry $entry         The waitlist entry.
	 * @param int                                    $occurrence_id The occurrence ID.
	 */
	public const WAITLIST_EMAIL_BODY = 'nettertech_events_waitlist_email_body';

	/**
	 * Fires after a waitlist notification email is sent (or attempted).
	 *
	 * @since 1.0.2
	 *
	 * @param \NetterTechEvents\Models\WaitlistEntry $entry         The waitlist entry.
	 * @param int                                    $occurrence_id The occurrence ID.
	 * @param bool                                   $sent          Whether the email was sent.
	 */
	public const WAITLIST_NOTIFICATION_SENT = 'nettertech_events_waitlist_notification_sent';

	// =========================================================================
	// Cache Hooks
	// =========================================================================

	/**
	 * Fires after plugin caches are intentionally invalidated.
	 * Useful for other plugins/themes to clear their own related caches.
	 */
	public const CACHE_INVALIDATED = 'nettertech_events_cache_invalidated';

	/**
	 * Fires after capacity cache is invalidated for a ticket type.
	 *
	 * @param int $ticket_type_id The ticket type ID.
	 */
	public const CAPACITY_CACHE_INVALIDATED = 'nettertech_events_capacity_cache_invalidated';

	// =========================================================================
	// Capacity Hooks
	// =========================================================================

	/**
	 * Fires after capacity is reserved for a ticket type.
	 *
	 * @param int $ticket_type_id The ticket type ID.
	 * @param int $quantity       The quantity reserved.
	 */
	public const CAPACITY_RESERVED = 'nettertech_events_capacity_reserved';

	/**
	 * Fires after capacity is released for a ticket type.
	 *
	 * @param int $ticket_type_id The ticket type ID.
	 * @param int $quantity       The quantity released.
	 */
	public const CAPACITY_RELEASED = 'nettertech_events_capacity_released';

	/**
	 * Fires when a capacity oversell is detected during order validation.
	 *
	 * @param \WC_Order $order  The WooCommerce order.
	 * @param array     $issues The capacity issues detected.
	 */
	public const CAPACITY_OVERSELL_DETECTED = 'nettertech_events_capacity_oversell_detected';

	/**
	 * Fires after buffer stock is updated for a ticket type.
	 *
	 * @param int $ticket_type_id The ticket type ID.
	 * @param int $amount         The new buffer amount.
	 */
	public const BUFFER_STOCK_UPDATED = 'nettertech_events_buffer_stock_updated';

	/**
	 * Filters the available count for a ticket type.
	 *
	 * @param int|null $override       Override value (null to use default).
	 * @param int      $ticket_type_id The ticket type ID.
	 * @param string   $capacity_type  The capacity type.
	 * @return int|null
	 */
	public const AVAILABLE_COUNT = 'nettertech_events_available_count';

	/**
	 * Filters the capacity check result.
	 *
	 * @param bool|null $override       Override value (null to use default).
	 * @param int       $ticket_type_id The ticket type ID.
	 * @param int       $quantity       The quantity to check.
	 * @param string    $capacity_type  The capacity type.
	 * @return bool|null
	 */
	public const CAPACITY_CHECK = 'nettertech_events_capacity_check';

	/**
	 * Filters the available capacity types.
	 *
	 * @param array  $types Array of capacity types.
	 * @param string $scope The scope of the request.
	 * @return array
	 */
	public const CAPACITY_TYPES = 'nettertech_events_capacity_types';

	// =========================================================================
	// Activity Log Hooks
	// =========================================================================

	/**
	 * Fires after an activity is logged.
	 *
	 * @param string      $action    The action performed.
	 * @param string      $type      The entity type.
	 * @param int|null    $entity_id The entity ID.
	 * @param string      $title     The entity title.
	 * @param array|null  $details   Optional details.
	 */
	public const ACTIVITY_LOGGED = 'nettertech_events_activity_logged';

	/**
	 * Filters whether activity logging is enabled.
	 *
	 * @param bool $enabled Whether logging is enabled.
	 * @return bool
	 */
	public const ACTIVITY_LOGGING_ENABLED = 'nettertech_events_activity_logging_enabled';

	/**
	 * Filters the activity log retention days.
	 *
	 * @param int $days Number of days to retain logs.
	 * @return int
	 */
	public const ACTIVITY_LOG_RETENTION_DAYS = 'nettertech_events_activity_log_retention_days';

	/**
	 * Filters the activity retention cutoff date.
	 *
	 * @param string $cutoff        The cutoff datetime string.
	 * @param int    $days_to_keep  Number of days to keep.
	 * @return string
	 */
	public const ACTIVITY_RETENTION = 'nettertech_events_activity_retention';

	/**
	 * Fires when settings are updated.
	 *
	 * @param array<string, mixed> $changes Changed settings.
	 */
	public const SETTINGS_UPDATED = 'nettertech_events_settings_updated';

	/**
	 * Fires when attendees are exported.
	 *
	 * @param array<string, mixed> $parameters Export parameters.
	 */
	public const ATTENDEES_EXPORTED = 'nettertech_events_attendees_exported';

	/**
	 * Fires when events are exported.
	 *
	 * Extension-fired: core does not fire this hook itself. Export and
	 * migration tooling fires it after exporting events; core listens and
	 * records an activity-log entry.
	 *
	 * @param array<string, mixed> $parameters Export parameters.
	 */
	public const EVENTS_EXPORTED = 'nettertech_events_events_exported';

	/**
	 * Cron hook for daily activity log cleanup.
	 */
	public const DAILY_CLEANUP = 'nettertech_events_daily_cleanup';

	// =========================================================================
	// Template & Display Filters
	// =========================================================================

	/**
	 * Filters the arguments passed to a template file before it is rendered.
	 *
	 * @param array  $args The array of arguments.
	 * @param string $file The full path to the template file being loaded.
	 * @return array
	 */
	public const TEMPLATE_ARGS = 'nettertech_events_template_args';

	/**
	 * Filters the array of candidate template files for a given template part.
	 *
	 * @param string[] $templates An array of candidate template file paths.
	 * @param string   $slug      The slug for the template part.
	 * @param string   $name      The name of the template part.
	 * @return string[]
	 */
	public const GET_TEMPLATE_PART = 'nettertech_events_get_template_part';

	/**
	 * Filters the array of paths to search for template files.
	 *
	 * @param string[] $paths Array of directory paths.
	 * @return string[]
	 */
	public const TEMPLATE_PATHS = 'nettertech_events_template_paths';

	/**
	 * Fires immediately before a template file is included.
	 *
	 * Replaces the dynamic `{filter_prefix}_before_template_load` pattern.
	 *
	 * @since 1.0.2
	 *
	 * @param string               $file Template file being loaded.
	 * @param array<string, mixed> $args Template args.
	 */
	public const BEFORE_TEMPLATE_LOAD = 'nettertech_events_before_template_load';

	/**
	 * Fires immediately after a template file has been included.
	 *
	 * Replaces the dynamic `{filter_prefix}_after_template_load` pattern.
	 *
	 * @since 1.0.2
	 *
	 * @param string               $file Template file that was loaded.
	 * @param array<string, mixed> $args Template args.
	 */
	public const AFTER_TEMPLATE_LOAD = 'nettertech_events_after_template_load';

	/**
	 * Filters the data array localized for frontend scripts.
	 *
	 * @param array $data The default data array.
	 * @return array
	 */
	public const LOCALIZE_DATA = 'nettertech_events_localize_data';

	/**
	 * Filters the container CSS classes.
	 *
	 * @param array  $classes Array of CSS class names.
	 * @param string $context The rendering context.
	 * @param string $variant The variant name.
	 * @return array
	 */
	public const CONTAINER_CLASS = 'nettertech_events_container_class';

	/**
	 * Filters the CSS overrides applied to templates.
	 *
	 * @param string $css_overrides The CSS override string.
	 * @param string $template      The template name.
	 * @return string
	 */
	public const CSS_OVERRIDES = 'nettertech_events_css_overrides';

	/**
	 * Filters the image ratio CSS variables.
	 *
	 * @param string $css_vars The CSS variables string.
	 * @return string
	 */
	public const IMAGE_RATIO_CSS_VARS = 'nettertech_events_image_ratio_css_vars';

	/**
	 * Filters the detected views for asset enqueueing.
	 *
	 * @param array $views Array of detected view names.
	 * @return array
	 */
	public const DETECTED_VIEWS = 'nettertech_events_detected_views';

	/**
	 * Filters the layout components list.
	 *
	 * @param array $components Array of layout component definitions.
	 * @return array
	 */
	public const LAYOUT_COMPONENTS = 'nettertech_events_layout_components';

	/**
	 * Filters the palette map for theme color integration.
	 *
	 * @param array $palette_map Array of palette color mappings.
	 * @return array
	 */
	public const PALETTE_MAP = 'nettertech_events_palette_map';

	/**
	 * Filters the Open Graph meta tags before output.
	 *
	 * @param array<string, string> $tags       Associative array of property => content.
	 * @param \NetterTechEvents\Models\Event      $event      Event model.
	 * @param \NetterTechEvents\Models\Occurrence|null $occurrence Current occurrence (if viewing specific one).
	 * @return array<string, string>
	 */
	public const OPEN_GRAPH_TAGS = 'nettertech_events_open_graph_tags';

	/**
	 * Filters the Schema.org structured data for an event.
	 *
	 * @param array  $data       The structured data array.
	 * @param object $event      The event.
	 * @param object $occurrence The occurrence.
	 * @return array
	 */
	public const SCHEMA_ORG_DATA = 'nettertech_events_schema_org_data';

	/**
	 * Fires after the main content on single event pages.
	 *
	 * @since 1.0.2
	 *
	 * @param \NetterTechEvents\Models\Event $event The event.
	 */
	public const AFTER_SINGLE_CONTENT = 'nettertech_events_after_single_content';

	/**
	 * Fires after the main content on series pages.
	 *
	 * @since 1.0.2
	 *
	 * @param \NetterTechEvents\Models\Event $event The event.
	 */
	public const AFTER_SERIES_CONTENT = 'nettertech_events_after_series_content';

	/**
	 * Fires within the space detail page article.
	 *
	 * Rentals hooks here to add its rental CTA button.
	 *
	 * @since 1.0.2
	 *
	 * @param object $space The space data object.
	 */
	public const SINGLE_SPACE_CONTENT = 'nettertech_events_single_space_content';

	/**
	 * Fires after the space article on single space pages.
	 *
	 * @since 1.0.2
	 *
	 * @param object $space The space data object.
	 */
	public const AFTER_SINGLE_SPACE_CONTENT = 'nettertech_events_after_single_space_content';

	/**
	 * Filters the empty-state message shown when no events match.
	 *
	 * @since 1.0.2
	 *
	 * @param string $message The message to display.
	 * @param string $context The context: 'list', 'search', 'filter'.
	 */
	public const EMPTY_STATE_MESSAGE = 'nettertech_events_empty_state_message';

	/**
	 * Fires inside the empty-state container, after the message.
	 *
	 * @since 1.0.2
	 *
	 * @param string $context The context: 'list', 'search', 'filter'.
	 */
	public const EMPTY_STATE_CONTENT = 'nettertech_events_empty_state_content';

	/**
	 * Filters ticket scan result template data.
	 *
	 * Extension plugins add data (for example, seat assignments) to the
	 * scan result display.
	 *
	 * @since 1.0.2
	 *
	 * @param array<string, mixed> $scan_data Scan result template data.
	 */
	public const TICKET_SCAN_DATA = 'nettertech_events_ticket_scan_data';

	// =========================================================================
	// Calendar Hooks
	// =========================================================================

	/**
	 * Fires when calendar scripts are enqueued.
	 *
	 * @param string $handle The script handle.
	 */
	public const CALENDAR_ENQUEUE_SCRIPTS = 'nettertech_events_calendar_enqueue_scripts';

	/**
	 * Fires after calendar rendering is complete.
	 *
	 * @param array $atts The shortcode attributes.
	 */
	public const CALENDAR_RENDER_COMPLETE = 'nettertech_events_calendar_render_complete';

	/**
	 * Filters the calendar shortcode attributes.
	 *
	 * @param array $atts The shortcode attributes.
	 * @return array
	 */
	public const CALENDAR_SHORTCODE_ATTS = 'nettertech_events_calendar_shortcode_atts';

	/**
	 * Filters the calendar wrapper CSS classes.
	 *
	 * @param array $classes Array of CSS class names.
	 * @param array $atts    The shortcode attributes.
	 * @return array
	 */
	public const CALENDAR_WRAPPER_CLASSES = 'nettertech_events_calendar_wrapper_classes';

	/**
	 * Filters the calendar header HTML content.
	 *
	 * @param string $html The header HTML.
	 * @param array  $atts The shortcode attributes.
	 * @return string
	 */
	public const CALENDAR_HEADER_HTML = 'nettertech_events_calendar_header_html';

	/**
	 * Filters the calendar JavaScript configuration data.
	 *
	 * @param array $data The JS configuration data.
	 * @return array
	 */
	public const CALENDAR_JS_CONFIG = 'nettertech_events_calendar_js_config';

	// =========================================================================
	// Shortcode Display Filters
	// =========================================================================

	/**
	 * Filters the empty state message for the carousel shortcode.
	 *
	 * @param string $message The default message.
	 * @return string
	 */
	public const CAROUSEL_EMPTY_MESSAGE = 'nettertech_events_carousel_empty_message';

	/**
	 * Filters the empty state message for the event list shortcode.
	 *
	 * @param string $message The default message.
	 * @return string
	 */
	public const LIST_EMPTY_MESSAGE = 'nettertech_events_list_empty_message';

	/**
	 * Filters the search input placeholder text in the event list.
	 *
	 * Default value is timeframe-aware: "Search upcoming events…" when viewing
	 * upcoming events, "Search past events…" when viewing the past-events archive.
	 *
	 * @since 1.1.1
	 *
	 * @param string               $placeholder The default placeholder text.
	 * @param bool                 $is_past     True for past-events view; false for upcoming.
	 * @param array<string, mixed> $atts        Full shortcode attributes.
	 * @return string
	 */
	public const SEARCH_PLACEHOLDER = 'nettertech_events_search_placeholder';

	/**
	 * Filters the screen-reader label for the search input in the event list.
	 *
	 * Default value is timeframe-aware: "Search upcoming events" when viewing
	 * upcoming events, "Search past events" when viewing the past-events archive.
	 *
	 * @since 1.1.1
	 *
	 * @param string               $label   The default screen-reader label.
	 * @param bool                 $is_past True for past-events view; false for upcoming.
	 * @param array<string, mixed> $atts    Full shortcode attributes.
	 * @return string
	 */
	public const SEARCH_LABEL = 'nettertech_events_search_label';

	// =========================================================================
	// Single Occurrence Actions
	// =========================================================================

	/**
	 * Fires to render actions for a single occurrence display.
	 *
	 * @param object $occurrence The occurrence.
	 * @param object $event      The event.
	 */
	public const SINGLE_OCCURRENCE_ACTIONS = 'nettertech_events_single_occurrence_actions';

	// =========================================================================
	// Settings Hooks
	// =========================================================================

	/**
	 * Filters the settings page tabs.
	 *
	 * @param array $tabs Array of tab definitions.
	 * @return array
	 */
	public const SETTINGS_TABS = 'nettertech_events_settings_tabs';

	/**
	 * Fired when rendering a settings page extension tab.
	 *
	 * Replaces an earlier dynamic-name pattern that fired
	 * `nettertech_events_settings_tab_{$tab}`. Listeners receive the tab slug
	 * as an action argument and can switch internally rather than registering
	 * against a runtime-generated hook name.
	 *
	 * @since 1.0.2
	 *
	 * @param string               $tab      Active tab slug.
	 * @param array<string, mixed> $settings Current settings array.
	 */
	public const SETTINGS_TAB_RENDER = 'nettertech_events_settings_tab_render';

	/**
	 * Fires when saving a settings page extension tab.
	 *
	 * Replaces an earlier dynamic-name pattern that fired
	 * `nettertech_events_settings_save_{$tab}`.
	 *
	 * @since 1.0.2
	 *
	 * @param string $tab Active tab slug.
	 */
	public const SETTINGS_TAB_SAVE = 'nettertech_events_settings_tab_save';

	/**
	 * Fires after a template part is rendered, scoped to a particular slug.
	 *
	 * Replaces the dynamic `{filter_prefix}_get_template_part_{slug}` pattern.
	 * Listeners can inspect the slug argument and act accordingly.
	 *
	 * @since 1.0.2
	 *
	 * @param string $slug Template slug.
	 * @param string $name Template name.
	 */
	public const GET_TEMPLATE_PART_RENDERED = 'nettertech_events_get_template_part_rendered';

	// =========================================================================
	// Security & Rate Limiting Filters
	// =========================================================================

	/**
	 * Filters the CSP directives for admin pages.
	 *
	 * @param array<string> $directives Array of CSP directive strings.
	 * @return array<string>
	 */
	public const CSP_DIRECTIVES = 'nettertech_events_csp_directives';

	/**
	 * Filters the CSP directives for public pages.
	 *
	 * @param array<string> $directives Array of CSP directive strings.
	 * @return array<string>
	 */
	public const PUBLIC_CSP_DIRECTIVES = 'nettertech_events_public_csp_directives';

	/**
	 * Filters the rate limit settings.
	 *
	 * @param array $settings Rate limit settings array.
	 * @return array
	 */
	public const RATE_LIMIT_SETTINGS = 'nettertech_events_rate_limit_settings';

	/**
	 * Filters whether rate limiting should be bypassed.
	 *
	 * @param bool $bypass Whether to bypass rate limiting.
	 * @return bool
	 */
	public const RATE_LIMIT_BYPASS = 'nettertech_events_rate_limit_bypass';

	/**
	 * Filters the frame-src origins for the Content Security Policy.
	 *
	 * Merged with 'self'. Provide origins as scheme + host. Used to permit
	 * oEmbed/iframe video providers that the default-src 'self' fallback
	 * would otherwise block.
	 *
	 * @since 1.1.1
	 *
	 * @param array<string> $default_sources Default providers plus admin-configured origins.
	 */
	public const CSP_FRAME_SRC = 'nettertech_events_csp_frame_src';

	/**
	 * Filters the script-src origins for the Content Security Policy.
	 *
	 * Merged with the built-in 'self' 'unsafe-inline' keywords. Only https
	 * origins are supported for scripts.
	 *
	 * @since 1.1.2
	 *
	 * @param array<string> $configured Admin-configured https script origins.
	 */
	public const CSP_SCRIPT_SRC = 'nettertech_events_csp_script_src';

	/**
	 * Filters the CSP violation report URI.
	 *
	 * Return an empty string to disable CSP violation reporting, or a URL
	 * to override the built-in REST endpoint.
	 *
	 * @since 1.0.2
	 *
	 * @param string $report_uri Default report URI (plugin REST endpoint).
	 */
	public const CSP_REPORT_URI = 'nettertech_events_csp_report_uri';

	/**
	 * Filters the CIDR ranges of trusted reverse proxies for client IP resolution.
	 *
	 * Ships with Cloudflare's published ranges; add load balancer or CDN
	 * egress ranges for operation behind other proxies.
	 *
	 * @since 1.1.2
	 *
	 * @param array<int, string> $ranges CIDR ranges (IPv4 and IPv6).
	 */
	public const TRUSTED_PROXY_RANGES = 'nettertech_events_trusted_proxy_ranges';

	// =========================================================================
	// Frontend Branding Filters
	// =========================================================================

	/**
	 * Filters whether to show optional frontend branding.
	 *
	 * Public-facing credit links must be explicitly enabled by the site owner.
	 *
	 * @param bool $show Whether to show branding. Default false.
	 * @return bool
	 */
	public const SHOW_FRONTEND_BRANDING = 'nettertech_events_show_frontend_branding';

	// =========================================================================
	// Ticket Admin Extensibility Hooks
	// =========================================================================

	/**
	 * Fires after the ticket type list in admin metaboxes.
	 *
	 * Extension point for add-ons to inject UI after the ticket type rows.
	 *
	 * @param object       $event        The event object.
	 * @param int          $ticket_count Number of existing ticket types.
	 * @param string       $context      Metabox context: 'legacy', 'occurrence', or 'series'.
	 */
	public const TICKET_ADD_BUTTON_AREA = 'nettertech_events_ticket_add_button_area';

	/**
	 * Fires after the ticket form on the frontend single-event page.
	 *
	 * Extension point for add-ons to inject content after the ticket purchase form.
	 *
	 * @param array<\NetterTechEvents\Models\TicketType> $ticket_types Ticket types displayed.
	 * @param \NetterTechEvents\Models\Occurrence         $occurrence   The occurrence.
	 */
	public const AFTER_TICKET_FORM = 'nettertech_events_after_ticket_form';

	/**
	 * Filters extra columns for the admin attendees table.
	 *
	 * Return a map of column key to header label. Cell content for added
	 * columns is supplied via ATTENDEES_COLUMN_CONTENT.
	 *
	 * @since 1.1.2
	 *
	 * @param array<string, string> $columns       Map of column key to header label.
	 * @param int                   $event_id      Event scope (0 = all).
	 * @param int                   $occurrence_id Occurrence scope (0 = all).
	 */
	public const ATTENDEES_COLUMNS = 'nettertech_events_attendees_columns';

	/**
	 * Filters the cell HTML for an extension-added attendees table column.
	 *
	 * Return value is passed through wp_kses_post().
	 *
	 * @since 1.1.2
	 *
	 * @param string               $content    Cell HTML (default '').
	 * @param string               $column_key Column key being rendered.
	 * @param array<string, mixed> $item       Attendee record (includes wc_order_id).
	 */
	public const ATTENDEES_COLUMN_CONTENT = 'nettertech_events_attendees_column_content';

	/**
	 * Fires once before the attendee rows render, with the full page set.
	 *
	 * Extensions batch-prime per-row data here so the render loop stays
	 * free of per-row lookups.
	 *
	 * @since 1.1.2
	 *
	 * @param array<array<string, mixed>> $items         Attendee rows on this page.
	 * @param int                         $event_id      Event scope (0 = all).
	 * @param int                         $occurrence_id Occurrence scope (0 = all).
	 */
	public const ATTENDEES_PRIME = 'nettertech_events_attendees_prime';

	/**
	 * Fires inside the attendees summary card as an extension slot.
	 *
	 * The card is only emitted when something hooks here; base renders
	 * nothing extra of its own. Pro attaches the financial synopsis.
	 *
	 * @since 1.1.2
	 *
	 * @param int $event_id      The event ID being viewed.
	 * @param int $occurrence_id The occurrence drill-down filter (0 = all occurrences).
	 */
	public const PURCHASES_SYNOPSIS = 'nettertech_events_purchases_synopsis';

	/**
	 * Filters per-ticket-type revenue for the consolidated attendees overview.
	 *
	 * Return a tier-id-keyed map to add a net-revenue column (refunds and
	 * voids already subtracted); base renders no revenue of its own. Pro
	 * answers this filter.
	 *
	 * @since 1.1.2
	 *
	 * @param array<int, array{gross: float, refunds: float, net: float}>|null $revenue       Null when nothing answers.
	 * @param int                                                              $event_id      Event being viewed.
	 * @param int                                                              $occurrence_id Occurrence filter (0 = all).
	 */
	public const TICKET_TYPE_REVENUE = 'nettertech_events_ticket_type_revenue';

	/**
	 * Filters whether a sale-schedule control is present in the ticket form.
	 *
	 * An extension that renders per-tier sale scheduling returns true to
	 * suppress the plain-window guidance in the ticket form.
	 *
	 * @since 1.1.2
	 *
	 * @param bool $available Whether a sale-schedule control is present. Default false.
	 */
	public const SALE_SCHEDULE_UI_AVAILABLE = 'nettertech_events_sale_schedule_ui_available';

	/**
	 * Filters the ticket types offered to a buyer for an occurrence.
	 *
	 * @since 1.1.2
	 *
	 * @param array<\NetterTechEvents\Models\TicketType> $on_sale       Tiers the sale window admitted.
	 * @param array<\NetterTechEvents\Models\TicketType> $all_active    Every active tier on the occurrence.
	 * @param int                                        $occurrence_id Occurrence ID.
	 */
	public const ON_SALE_TICKET_TYPES = 'nettertech_events_on_sale_ticket_types';

	// =========================================================================
	// Cron Hooks
	// =========================================================================

	/**
	 * Cron hook for generating occurrences.
	 */
	public const GENERATE_OCCURRENCES_CRON = 'nettertech_events_generate_occurrences';

	/**
	 * Cron hook for sending reminder emails.
	 */
	public const SEND_REMINDER_EMAILS_CRON = 'nettertech_events_send_reminder_emails';

	/**
	 * Cron hook for purging activity log PII.
	 */
	public const PURGE_ACTIVITY_LOG_PII_CRON = 'nettertech_events_purge_activity_log_pii';

	/**
	 * Cron hook for sweeping expired capacity reservations.
	 */
	public const SWEEP_RESERVATIONS_CRON = 'nettertech_events_sweep_expired_reservations';

	/**
	 * One-off cron hook for (re)building the static iCal feed file (NTE-016).
	 *
	 * Scheduled debounced via wp_schedule_single_event() when event data
	 * changes and static-feed mode is enabled.
	 */
	public const ICAL_STATIC_REGENERATE = 'nettertech_events_ical_static_regenerate';

	/**
	 * Filters the number of days before horizon expiry that triggers
	 * occurrence horizon extension.
	 *
	 * @since 1.0.2
	 *
	 * @param int $days Default 30 days.
	 */
	public const HORIZON_EXTENSION_THRESHOLD = 'nettertech_events_horizon_extension_threshold';

	/**
	 * Filters the batch size for occurrence horizon extension processing.
	 *
	 * @since 1.0.2
	 *
	 * @param int $batch_size Default 50 events per batch.
	 */
	public const HORIZON_EXTENSION_BATCH_SIZE = 'nettertech_events_horizon_extension_batch_size';

	// =========================================================================
	// REST API Response Filters
	// =========================================================================

	/**
	 * Filters the REST response for the events/upcoming endpoint.
	 *
	 * @param array<int, array<string, mixed>> $data    Response data array.
	 * @param \WP_REST_Request                 $request The request object.
	 * @return array<int, array<string, mixed>>
	 */
	public const REST_EVENTS_UPCOMING_RESPONSE = 'nettertech_events_rest_events_upcoming_response';

	/**
	 * Filters the REST response for the events/range endpoint.
	 *
	 * @param array<int, array<string, mixed>> $data    Response data array.
	 * @param \WP_REST_Request                 $request The request object.
	 * @return array<int, array<string, mixed>>
	 */
	public const REST_EVENTS_RANGE_RESPONSE = 'nettertech_events_rest_events_range_response';

	/**
	 * Filters the REST response for a single event (public).
	 *
	 * @param array<string, mixed> $data    Response data array.
	 * @param \WP_REST_Request     $request The request object.
	 * @return array<string, mixed>
	 */
	public const REST_EVENTS_GET_RESPONSE = 'nettertech_events_rest_events_get_response';

	/**
	 * Filters the REST response for the occurrences endpoint.
	 *
	 * @param array<string, mixed> $data    Response data array.
	 * @param \WP_REST_Request     $request The request object.
	 * @return array<string, mixed>
	 */
	public const REST_OCCURRENCES_LIST_RESPONSE = 'nettertech_events_rest_occurrences_list_response';

	/**
	 * Filters the REST response for admin events list.
	 *
	 * @param array<string, mixed> $data    Response data array.
	 * @param \WP_REST_Request     $request The request object.
	 * @return array<string, mixed>
	 */
	public const REST_ADMIN_EVENTS_LIST_RESPONSE = 'nettertech_events_rest_admin_events_list_response';

	/**
	 * Filters the REST response for a single admin event.
	 *
	 * @param array<string, mixed> $data    Response data array.
	 * @param \WP_REST_Request     $request The request object.
	 * @return array<string, mixed>
	 */
	public const REST_ADMIN_EVENTS_GET_RESPONSE = 'nettertech_events_rest_admin_events_get_response';

	/**
	 * Filters the REST response for admin attendees list.
	 *
	 * @param array<int, array<string, mixed>> $data    Response data array.
	 * @param \WP_REST_Request                 $request The request object.
	 * @return array<int, array<string, mixed>>
	 */
	public const REST_ADMIN_ATTENDEES_LIST_RESPONSE = 'nettertech_events_rest_admin_attendees_list_response';

	/**
	 * Filters the REST response for a single admin attendee.
	 *
	 * @param array<string, mixed> $data    Response data array.
	 * @param \WP_REST_Request     $request The request object.
	 * @return array<string, mixed>
	 */
	public const REST_ADMIN_ATTENDEES_GET_RESPONSE = 'nettertech_events_rest_admin_attendees_get_response';

	/**
	 * Filters the REST response for admin ticket types list.
	 *
	 * @param array<int, array<string, mixed>> $data    Response data array.
	 * @param \WP_REST_Request                 $request The request object.
	 * @return array<int, array<string, mixed>>
	 */
	public const REST_ADMIN_TICKET_TYPES_LIST_RESPONSE = 'nettertech_events_rest_admin_ticket_types_list_response';

	/**
	 * Filters the REST response for a single admin ticket type.
	 *
	 * @param array<string, mixed> $data    Response data array.
	 * @param \WP_REST_Request     $request The request object.
	 * @return array<string, mixed>
	 */
	public const REST_ADMIN_TICKET_TYPES_GET_RESPONSE = 'nettertech_events_rest_admin_ticket_types_get_response';

	// =========================================================================
	// Bulk Import Hooks
	// =========================================================================

	/**
	 * Fires after a bulk import batch completes.
	 *
	 * Extension-fired: core does not fire this hook itself. Import tooling
	 * (for example, the Migrator plugin) fires it after each batch; core
	 * listens and performs cache, log, and stock housekeeping.
	 *
	 * @param string    $type   Entity type ('events', 'occurrences', 'attendees', 'tickets', etc.).
	 * @param int       $count  Number of entities in the batch.
	 * @param array<int> $ids    Array of entity IDs created/updated.
	 * @param string    $source Import source identifier (e.g., 'tec').
	 */
	public const BULK_IMPORT_COMPLETED = 'nettertech_events_bulk_import_completed';

	// =========================================================================
	// Extension Hooks (Pro Plugin Integration)
	// =========================================================================

	/**
	 * Filters the list of service providers registered with the DI container.
	 *
	 * Pro uses this to add its own service providers (e.g., QR, CheckIn, PDF).
	 *
	 * Timing contract: this filter is read once, eagerly, when the base plugin
	 * constructs its DI container. That happens inside `Core\Plugin::__construct()`
	 * (via `ServiceRegistry::init()` then `register_providers()`) at
	 * `plugins_loaded` priority 10. An extension plugin contributing a provider
	 * MUST add its filter callback before that point, i.e. on `plugins_loaded`
	 * at a priority below 10. A callback added later (for example from a
	 * module's own `init()` on the `nettertech_events_init` action) runs after
	 * the filter was already read and is silently ignored.
	 *
	 * @since 1.0.2
	 *
	 * @param array<ServiceProviderInterface> $providers The service providers.
	 * @return array<ServiceProviderInterface>
	 */
	public const FILTER_SERVICE_PROVIDERS = 'nettertech_events_service_providers';

	/**
	 * Fires after admin initialization is complete.
	 *
	 * Pro uses this to register admin pages, AJAX handlers, and metaboxes
	 * that depend on the base admin being fully initialized.
	 *
	 * @since 1.0.2
	 *
	 * @param Plugin $plugin The plugin instance.
	 */
	public const ACTION_ADMIN_READY = 'nettertech_events_admin_ready';

	/**
	 * Fires after frontend initialization is complete.
	 *
	 * Pro uses this to register frontend routes and template handlers
	 * that depend on the base frontend being fully initialized.
	 *
	 * @since 1.0.2
	 *
	 * @param Plugin $plugin The plugin instance.
	 */
	public const ACTION_FRONTEND_READY = 'nettertech_events_frontend_ready';

	/**
	 * Fires after admin submenu pages are registered (priority 20).
	 *
	 * Pro uses this to register additional admin submenu pages under
	 * the NetterTech Events parent menu at the correct position.
	 *
	 * @since 1.0.2
	 */
	public const ACTION_REGISTER_ADMIN_PAGES = 'nettertech_events_register_admin_pages';

	/**
	 * Fires after base scripts and styles are registered.
	 *
	 * Pro uses this to register its own scripts and styles (e.g., QR scanner,
	 * check-in page assets, PDF vendor scripts).
	 *
	 * @since 1.0.2
	 */
	public const ACTION_REGISTER_ASSETS = 'nettertech_events_register_assets';

	/**
	 * Filters the columns shown on the admin All Events list table.
	 *
	 * Add-ons (e.g. Pro Reports) use this to append columns such as Revenue.
	 * Mirrors WP core's `manage_{screen}_columns` convention. Insert keys before
	 * `created_at` to keep them left of the Created column.
	 *
	 * @since 1.1.1
	 *
	 * @param array<string, string> $columns Map of column key => header label.
	 * @return array<string, string>
	 */
	public const FILTER_LIST_COLUMNS = 'nettertech_events_list_columns';

	/**
	 * Fires when rendering a registered custom column cell on the All Events list.
	 *
	 * Paired with {@see self::FILTER_LIST_COLUMNS}. Mirrors WP core's
	 * `manage_{screen}_custom_column`. Add-ons echo cell content for columns they
	 * registered via the filter.
	 *
	 * @since 1.1.1
	 *
	 * @param string                       $column_name The column key being rendered.
	 * @param \NetterTechEvents\Models\Event $item       The event for this row.
	 */
	public const ACTION_LIST_COLUMN = 'nettertech_events_list_column';

	/**
	 * AJAX action for the All Events list "SKUs" row-action dialog (NTE-114).
	 *
	 * Admin-only (`edit_posts` + nonce). Returns the event's ticket-type SKUs as
	 * a per-ticket list plus a comma-delimited copy string. The action is carried
	 * in the request URL query (not just the body) so a Cloudflare WAF rule can
	 * scope to it without body inspection.
	 *
	 * @since 1.0.3
	 */
	public const AJAX_EVENT_SKUS = 'nettertech_events_event_skus';

	/**
	 * AJAX action for logged-in attendee check-in from the Attendees screen (NTE-144).
	 *
	 * Admin-only (`edit_posts` + nonce). Toggles an attendee's checked-in state and
	 * returns the refreshed occurrence check-in stats. Pro's volunteer check-in is a
	 * separate, token-authenticated public surface and does not route through here.
	 *
	 * @since 1.1.2
	 */
	public const AJAX_ATTENDEE_CHECK_IN = 'nettertech_events_attendee_check_in';

	/**
	 * Filters the template to load for custom route handling.
	 *
	 * Pro uses this to handle routes that are not part of the base plugin
	 * (e.g., check-in pages, ticket scan pages). Return a template path
	 * to handle the route, or null to let the base router continue.
	 *
	 * @since 1.0.2
	 *
	 * @param string|null $result   The template path, or null if not handled.
	 * @param string      $template The original WordPress template.
	 * @return string|null
	 */
	public const FILTER_ROUTE_TEMPLATE = 'nettertech_events_route_template';

	/**
	 * Fires after Pro extension data is saved for an event.
	 *
	 * @since 1.0.2
	 *
	 * @param int $event_id Event ID.
	 */
	public const SAVE_PRO_EXTENSIONS = 'nettertech_events_save_pro_extensions';

	/**
	 * Filters the check-in lookup response data.
	 *
	 * Extension-fired: core reserves this name as part of the cross-plugin
	 * contract but does not fire it. The Pro plugin's check-in scan
	 * controller fires it; Seating subscribes to add seat assignment info.
	 *
	 * @since 1.1.2
	 *
	 * @param array<string, mixed>                 $response_data Lookup response data.
	 * @param \NetterTechEvents\Models\Attendee    $attendee      The attendee looked up.
	 * @param int                                  $occurrence_id Occurrence ID.
	 */
	public const CHECKIN_LOOKUP_DATA = 'nettertech_events_checkin_lookup_data';

	/**
	 * Filters a check-in search result item.
	 *
	 * Extension-fired: core reserves this name as part of the cross-plugin
	 * contract but does not fire it. The Pro plugin's check-in scan
	 * controller fires it; Seating subscribes to add seat labels.
	 *
	 * @since 1.1.2
	 *
	 * @param array<string, mixed>              $item_data The search result item.
	 * @param \NetterTechEvents\Models\Attendee $attendee  The matched attendee.
	 */
	public const CHECKIN_SEARCH_ITEM = 'nettertech_events_checkin_search_item';

	// =========================================================================
	// Email Extension Hooks
	// =========================================================================

	/**
	 * Renders QR code section in customer confirmation emails.
	 *
	 * Pro hooks here to output QR code images and check-in instructions
	 * for WooCommerce order tickets.
	 *
	 * @since 1.0.2
	 *
	 * @param array<\NetterTechEvents\Models\Ticket> $tickets Tickets for the current event group.
	 */
	public const ACTION_EMAIL_QR_CODES = 'nettertech_events_email_qr_codes';

	/**
	 * Renders QR code section in RSVP confirmation emails.
	 *
	 * Pro hooks here to output the QR code image and check-in instructions
	 * for a single RSVP ticket.
	 *
	 * @since 1.0.2
	 *
	 * @param \NetterTechEvents\Models\Ticket $ticket The RSVP ticket.
	 */
	public const ACTION_EMAIL_RSVP_QR_CODE = 'nettertech_events_email_rsvp_qr_code';
}
