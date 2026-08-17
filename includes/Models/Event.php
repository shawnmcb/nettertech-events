<?php
/**
 * Event model class.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Utilities\ImageHelper;
use NetterTechEvents\Utilities\PathHelper;

/**
 * Represents an event definition.
 *
 * Events are the parent entity that define the event content,
 * while Occurrences represent individual showings/instances.
 *
 * @since 0.1.0
 * @api
 * PHPMD TooManyFields suppressed: data model mirrors the event table and public API contract.
 *
 * @SuppressWarnings("PHPMD.TooManyFields")
 */
class Event {

	/**
	 * Event ID.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Associated WordPress post ID (optional).
	 *
	 * @var int|null
	 */
	public ?int $post_id = null;

	/**
	 * Event title.
	 *
	 * @var string
	 */
	public string $title = '';

	/**
	 * URL-friendly slug.
	 *
	 * @var string
	 */
	public string $slug = '';

	/**
	 * Full description (HTML allowed).
	 *
	 * @var string
	 */
	public string $description = '';

	/**
	 * Short excerpt.
	 *
	 * @var string
	 */
	public string $excerpt = '';

	/**
	 * Featured image attachment ID.
	 *
	 * @var int|null
	 */
	public ?int $featured_image_id = null;

	/**
	 * Event status.
	 *
	 * @var EventStatus
	 */
	public EventStatus $status = EventStatus::DRAFT;

	/**
	 * Event type.
	 *
	 * @var string One of: single, recurring, series_parent
	 */
	public string $event_type = 'single';

	/**
	 * Series ID if part of a series.
	 *
	 * @var int|null
	 */
	public ?int $series_id = null;

	/**
	 * Venue name.
	 *
	 * @var string|null
	 */
	public ?string $venue_name = null;

	/**
	 * Venue address.
	 *
	 * @var string|null
	 */
	public ?string $venue_address = null;

	/**
	 * Recurrence rule (RFC 5545 RRULE format).
	 *
	 * @var string|null
	 */
	public ?string $recurrence_rule = null;

	/**
	 * End date for recurrence.
	 *
	 * @var string|null Date in Y-m-d format
	 */
	public ?string $recurrence_end_date = null;

	/**
	 * Layout configuration (JSON object).
	 *
	 * Controls component order and visibility on single event page.
	 * Null = use global default.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $layout_config = null;

	/**
	 * Whether reminder emails are enabled for this event.
	 *
	 * Null = use site default, true = force on, false = force off.
	 *
	 * @var bool|null
	 */
	public ?bool $reminders_enabled = null;

	/**
	 * Per-event waitlist override (NTE-214).
	 * Null = use site default, true = force on, false = force off.
	 *
	 * @var bool|null
	 */
	public ?bool $waitlist_enabled = null;

	/**
	 * Per-event notification recipient emails (comma-separated).
	 *
	 * These addresses receive ticket purchase notifications in addition
	 * to the global venue contacts. Null = no per-event recipients.
	 *
	 * @var string|null
	 */
	public ?string $notification_emails = null;

	/**
	 * Custom fields data (JSON object).
	 *
	 * Arbitrary key-value data attached to the event by integrations
	 * or import tools. Read-only in the admin UI.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $custom_fields = null;

	/**
	 * Whether this is a virtual (online-only) event.
	 *
	 * Null = in-person (default), true = virtual, false = explicitly in-person.
	 * When combined with a venue, the event is treated as hybrid.
	 *
	 * @var bool|null
	 */
	public ?bool $is_virtual = null;

	/**
	 * URL for virtual event access (e.g., Zoom, Teams, YouTube).
	 *
	 * @var string|null
	 */
	public ?string $virtual_url = null;

	/**
	 * Whether to collect individual attendee details at checkout for qty > 1.
	 *
	 * @var bool|null
	 */
	public ?bool $collect_individual_attendees = null;

	/**
	 * QR code logo mode for this event.
	 *
	 * Null = use site default, 'none'/'site'/'custom' = per-event override.
	 *
	 * @var string|null
	 */
	public ?string $qr_logo_mode = null;

	/**
	 * Attachment ID for the custom QR code logo image.
	 *
	 * Only relevant when qr_logo_mode is 'custom'.
	 *
	 * @var int|null
	 */
	public ?int $qr_logo_attachment_id = null;

	/**
	 * Space ID (foreign key to nettertech_events_spaces table).
	 *
	 * Links the event to a physical space for seating and capacity management.
	 *
	 * @var int|null
	 */
	public ?int $space_id = null;

	/**
	 * Per-event vertical crop anchor for the single-page featured image.
	 *
	 * Null = center (default), 'top'/'center'/'bottom' = per-event override.
	 * Horizontal positioning is always centered (no control).
	 *
	 * @var string|null
	 */
	public ?string $image_vertical_anchor = null;

	/**
	 * Category term IDs (used during duplication, not persisted to events table).
	 *
	 * @var array<int>
	 */
	public array $category_ids = array();

	/**
	 * Tag term IDs (used during duplication, not persisted to events table).
	 *
	 * @var array<int>
	 */
	public array $tag_ids = array();

	/**
	 * Created timestamp.
	 *
	 * @var string|null
	 */
	public ?string $created_at = null;

	/**
	 * Updated timestamp.
	 *
	 * @var string|null
	 */
	public ?string $updated_at = null;

	/**
	 * Valid statuses as string values.
	 *
	 * Delegates to EventStatus::values() for backward compatibility
	 * with REST API schemas and validators that expect string arrays.
	 *
	 * @var array<string>
	 */
	public const STATUSES = array( 'draft', 'published', 'cancelled', 'postponed' );

	/**
	 * Valid event types.
	 *
	 * @var array<string>
	 */
	public const TYPES = array( 'single', 'recurring', 'series_parent' );

	/**
	 * Create an Event from a database row.
	 *
	 * @since 0.1.0
	 *
	 * @param object|array<string, mixed> $row Database row.
	 * @return self
	 */
	public static function from_row( object|array $row ): self {
		$row   = (object) $row;
		$event = new self();

		$event->id                           = isset( $row->id ) ? (int) $row->id : null;
		$event->post_id                      = isset( $row->post_id ) ? (int) $row->post_id : null;
		$event->title                        = $row->title ?? '';
		$event->slug                         = $row->slug ?? '';
		$event->description                  = $row->description ?? '';
		$event->excerpt                      = $row->excerpt ?? '';
		$event->featured_image_id            = isset( $row->featured_image_id ) ? (int) $row->featured_image_id : null;
		$event->status                       = EventStatus::tryFrom( $row->status ?? 'draft' ) ?? EventStatus::DRAFT;
		$event->event_type                   = $row->event_type ?? 'single';
		$event->series_id                    = isset( $row->series_id ) ? (int) $row->series_id : null;
		$event->venue_name                   = $row->venue_name ?? null;
		$event->venue_address                = $row->venue_address ?? null;
		$event->recurrence_rule              = $row->recurrence_rule ?? null;
		$event->recurrence_end_date          = $row->recurrence_end_date ?? null;
		$event->reminders_enabled            = isset( $row->reminders_enabled ) ? (bool) $row->reminders_enabled : null;
		$event->waitlist_enabled             = isset( $row->waitlist_enabled ) ? (bool) $row->waitlist_enabled : null;
		$event->notification_emails          = $row->notification_emails ?? null;
		$event->is_virtual                   = isset( $row->is_virtual ) ? (bool) $row->is_virtual : null;
		$event->virtual_url                  = $row->virtual_url ?? null;
		$event->collect_individual_attendees = isset( $row->collect_individual_attendees ) ? (bool) $row->collect_individual_attendees : null;
		$event->qr_logo_mode                 = $row->qr_logo_mode ?? null;
		$event->qr_logo_attachment_id        = isset( $row->qr_logo_attachment_id ) ? (int) $row->qr_logo_attachment_id : null;
		$event->space_id                     = isset( $row->space_id ) ? (int) $row->space_id : null;
		$event->image_vertical_anchor        = $row->image_vertical_anchor ?? null;
		$event->created_at                   = $row->created_at ?? null;
		$event->updated_at                   = $row->updated_at ?? null;

		// Decode JSON objects.
		$event->layout_config = self::decode_json_object( $row->layout_config ?? '' );
		$event->custom_fields = self::decode_json_object( $row->custom_fields ?? '' );

		return $event;
	}

	/**
	 * Convert to array for database insertion.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'post_id'                      => $this->post_id,
			'title'                        => $this->title,
			'slug'                         => $this->slug,
			'description'                  => $this->description,
			'excerpt'                      => $this->excerpt,
			'featured_image_id'            => $this->featured_image_id,
			'status'                       => $this->status->value,
			'event_type'                   => $this->event_type,
			'series_id'                    => $this->series_id,
			'venue_name'                   => $this->venue_name,
			'venue_address'                => $this->venue_address,
			'recurrence_rule'              => $this->recurrence_rule,
			'recurrence_end_date'          => $this->recurrence_end_date,
			'layout_config'                => $this->layout_config ? wp_json_encode( $this->layout_config ) : null,
			'custom_fields'                => $this->custom_fields ? wp_json_encode( $this->custom_fields ) : null,
			'is_virtual'                   => $this->is_virtual,
			'virtual_url'                  => $this->virtual_url,
			'reminders_enabled'            => $this->reminders_enabled,
			'waitlist_enabled'             => $this->waitlist_enabled,
			'notification_emails'          => $this->notification_emails,
			'collect_individual_attendees' => $this->collect_individual_attendees,
			'qr_logo_mode'                 => $this->qr_logo_mode,
			'qr_logo_attachment_id'        => $this->qr_logo_attachment_id,
			'space_id'                     => $this->space_id,
			'image_vertical_anchor'        => $this->image_vertical_anchor,
		);
	}

	/**
	 * Get format specifiers for database operations.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string>
	 */
	public function get_formats(): array {
		return array(
			'%d', // Post ID.
			'%s', // Title.
			'%s', // Slug.
			'%s', // Description.
			'%s', // Excerpt.
			'%d', // Featured image ID.
			'%s', // Status.
			'%s', // Event type.
			'%d', // Series ID.
			'%s', // Venue name.
			'%s', // Venue address.
			'%s', // Recurrence rule.
			'%s', // Recurrence end date.
			'%s', // Layout config.
			'%s', // Custom fields.
			'%d', // Is virtual.
			'%s', // Virtual URL.
			'%d', // Reminders enabled.
			'%d', // Waitlist enabled.
			'%s', // Notification emails.
			'%d', // Collect individual attendees.
			'%s', // QR logo mode.
			'%d', // QR logo attachment ID.
			'%d', // Space ID.
			'%s', // Image vertical anchor.
		);
	}

	/**
	 * Validate the event data.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string> Array of error messages, empty if valid.
	 */
	public function validate(): array {
		$errors = array();

		if ( empty( $this->title ) ) {
			$errors[] = __( 'Event title is required.', 'nettertech-events' );
		}

		if ( empty( $this->slug ) ) {
			$errors[] = __( 'Event slug is required.', 'nettertech-events' );
		}

		// Status validation is enforced by the EventStatus enum type.

		if ( ! in_array( $this->event_type, self::TYPES, true ) ) {
			$errors[] = __( 'Invalid event type.', 'nettertech-events' );
		}

		if ( 'recurring' === $this->event_type && empty( $this->recurrence_rule ) ) {
			$errors[] = __( 'Recurring events require a recurrence rule.', 'nettertech-events' );
		}

		return $errors;
	}

	/**
	 * Check if event is published.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_published(): bool {
		return $this->status->is_public();
	}

	/**
	 * Check if event is recurring.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_recurring(): bool {
		return 'recurring' === $this->event_type;
	}

	/**
	 * Check if event is virtual (online-only).
	 *
	 * @since 3.7.0
	 *
	 * @return bool
	 */
	public function is_virtual_event(): bool {
		return true === $this->is_virtual;
	}

	/**
	 * Check if event is hybrid (both virtual and in-person).
	 *
	 * An event is hybrid when it has a virtual URL and a venue.
	 *
	 * @since 3.7.0
	 *
	 * @return bool
	 */
	public function is_hybrid(): bool {
		return true === $this->is_virtual && ! empty( $this->venue_name );
	}

	/**
	 * Get the Schema.org eventAttendanceMode value.
	 *
	 * @since 3.7.0
	 *
	 * @return string Schema.org attendance mode URL.
	 */
	public function get_attendance_mode(): string {
		if ( $this->is_hybrid() ) {
			return 'https://schema.org/MixedEventAttendanceMode';
		}

		if ( $this->is_virtual_event() ) {
			return 'https://schema.org/OnlineEventAttendanceMode';
		}

		return 'https://schema.org/OfflineEventAttendanceMode';
	}

	/**
	 * Check if event is part of a series.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function has_series(): bool {
		return null !== $this->series_id;
	}

	/**
	 * Get the featured image URL.
	 *
	 * @since 0.1.0
	 *
	 * @param string $size Image size.
	 * @return string|null
	 */
	public function get_featured_image_url( string $size = 'large' ): ?string {
		if ( ! $this->featured_image_id ) {
			return null;
		}

		return ImageHelper::get_attachment_image_url( $this->featured_image_id, $size );
	}

	/**
	 * Get the permalink for this event.
	 *
	 * For single events, links directly to the event page.
	 * For recurring events, links to the series page.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_permalink(): string {
		if ( $this->post_id ) {
			$permalink = get_permalink( $this->post_id );
			if ( false !== $permalink ) {
				return $permalink;
			}
		}

		return PathHelper::get_event_url( $this->slug );
	}

	/**
	 * Get the series page URL for this event.
	 *
	 * Always returns the base event URL (without occurrence suffix).
	 * For recurring events, this shows the list of all occurrences.
	 * For single events, same as get_permalink().
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_series_url(): string {
		return PathHelper::get_series_url( $this->slug );
	}

	/**
	 * Decode a JSON-encoded object.
	 *
	 * @param string|null $json JSON string.
	 * @return array<string, mixed>|null Associative array or null if empty/invalid.
	 */
	private static function decode_json_object( ?string $json ): ?array {
		if ( empty( $json ) ) {
			return null;
		}

		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
