<?php
/**
 * Occurrence model class.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Utilities\ImageHelper;
use NetterTechEvents\Utilities\PathHelper;

/**
 * Represents a single event occurrence (showing).
 *
 * Occurrences are denormalized instances of events, pre-generated
 * for fast calendar queries. Each occurrence represents one
 * specific date/time when an event happens.
 *
 * @since 0.1.0
 * @api
 */
class Occurrence {

	/**
	 * Occurrence ID.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Parent event ID.
	 *
	 * @var int
	 */
	public int $event_id;

	/**
	 * Start date and time.
	 *
	 * @var string DateTime in Y-m-d H:i:s format
	 */
	public string $start_datetime;

	/**
	 * End date and time.
	 *
	 * @var string DateTime in Y-m-d H:i:s format
	 */
	public string $end_datetime;

	/**
	 * Whether this is an all-day event.
	 *
	 * @var bool
	 */
	public bool $all_day = false;

	/**
	 * Timezone identifier.
	 *
	 * @var string
	 */
	public string $timezone = 'UTC';

	/**
	 * Title override for this specific occurrence.
	 *
	 * @var string|null
	 */
	public ?string $title_override = null;

	/**
	 * Description override for this specific occurrence.
	 *
	 * @var string|null
	 */
	public ?string $description_override = null;

	/**
	 * Featured image ID override for this specific occurrence.
	 *
	 * @var int|null
	 */
	public ?int $featured_image_id = null;

	/**
	 * Occurrence status.
	 *
	 * @var string One of: scheduled, cancelled, postponed, completed
	 */
	public string $status = 'scheduled';

	/**
	 * Capacity override for this occurrence.
	 *
	 * @var int|null
	 */
	public ?int $capacity = null;

	/**
	 * Sequence number for recurring events.
	 *
	 * @var int
	 */
	public int $sequence_number = 1;

	/**
	 * Whether this occurrence has been rescheduled from its original time.
	 *
	 * @var bool
	 */
	public bool $is_rescheduled = false;

	/**
	 * Whether this occurrence carries manual per-occurrence edits.
	 *
	 * When true, recurrence regeneration must not delete or overwrite this row.
	 *
	 * @var bool
	 */
	public bool $is_override = false;

	/**
	 * Venue name override for this specific occurrence.
	 *
	 * @var string|null
	 */
	public ?string $venue_name_override = null;

	/**
	 * Venue address override for this specific occurrence.
	 *
	 * @var string|null
	 */
	public ?string $venue_address_override = null;

	/**
	 * Virtual event URL override for this specific occurrence.
	 *
	 * @var string|null
	 */
	public ?string $virtual_url_override = null;

	/**
	 * Check-in token for public volunteer access.
	 *
	 * @var string|null
	 */
	public ?string $checkin_token = null;

	/**
	 * Created timestamp.
	 *
	 * @var string|null
	 */
	public ?string $created_at = null;

	/**
	 * Cached parent event.
	 *
	 * @var Event|null
	 */
	private ?Event $event = null;

	/**
	 * Event repository for lazy-loading the parent event.
	 *
	 * @var EventRepositoryInterface|null
	 */
	private ?EventRepositoryInterface $event_repo = null;

	/**
	 * Valid statuses.
	 *
	 * @var array<string>
	 */
	public const STATUSES = array( 'scheduled', 'cancelled', 'postponed', 'completed', 'rescheduled' );

	/**
	 * Create an Occurrence from a database row.
	 *
	 * @since 0.1.0
	 *
	 * @param object|array<string, mixed> $row Database row.
	 * @return self
	 */
	public static function from_row( object|array $row ): self {
		$row        = (object) $row;
		$occurrence = new self();

		$occurrence->id                     = isset( $row->id ) ? (int) $row->id : null;
		$occurrence->event_id               = (int) ( $row->event_id ?? 0 );
		$occurrence->start_datetime         = $row->start_datetime ?? '';
		$occurrence->end_datetime           = $row->end_datetime ?? '';
		$occurrence->all_day                = (bool) ( $row->all_day ?? false );
		$occurrence->timezone               = $row->timezone ?? 'UTC';
		$occurrence->title_override         = $row->title_override ?? null;
		$occurrence->description_override   = $row->description_override ?? null;
		$occurrence->featured_image_id      = isset( $row->featured_image_id ) ? (int) $row->featured_image_id : null;
		$occurrence->status                 = $row->status ?? 'scheduled';
		$occurrence->capacity               = isset( $row->capacity ) ? (int) $row->capacity : null;
		$occurrence->sequence_number        = (int) ( $row->sequence_number ?? 1 );
		$occurrence->is_rescheduled         = (bool) ( $row->is_rescheduled ?? false );
		$occurrence->is_override            = (bool) ( $row->is_override ?? false );
		$occurrence->venue_name_override    = $row->venue_name_override ?? null;
		$occurrence->venue_address_override = $row->venue_address_override ?? null;
		$occurrence->virtual_url_override   = $row->virtual_url_override ?? null;
		$occurrence->checkin_token          = $row->checkin_token ?? null;
		$occurrence->created_at             = $row->created_at ?? null;

		return $occurrence;
	}

	/**
	 * Convert to array for database insertion.
	 *
	 * `start_utc` / `end_utc` are *derived* here, never carried as state and never settable by
	 * a caller: they are exactly get_start()/get_end() — the wall-clock read in this
	 * occurrence's own zone — rendered as UTC. Deriving them at the storage boundary is what
	 * makes it impossible for the instant to disagree with the wall-clock it mirrors. A caller
	 * who moves an event an hour later cannot forget to update them.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'event_id'               => $this->event_id,
			'start_datetime'         => $this->start_datetime,
			'end_datetime'           => $this->end_datetime,
			'start_utc'              => $this->to_utc( $this->get_start() ),
			'end_utc'                => $this->to_utc( $this->get_end() ),
			'all_day'                => $this->all_day ? 1 : 0,
			'timezone'               => $this->timezone,
			'title_override'         => $this->title_override,
			'description_override'   => $this->description_override,
			'featured_image_id'      => $this->featured_image_id,
			'status'                 => $this->status,
			'capacity'               => $this->capacity,
			'sequence_number'        => $this->sequence_number,
			'is_rescheduled'         => $this->is_rescheduled ? 1 : 0,
			'is_override'            => $this->is_override ? 1 : 0,
			'venue_name_override'    => $this->venue_name_override,
			'venue_address_override' => $this->venue_address_override,
			'virtual_url_override'   => $this->virtual_url_override,
			'checkin_token'          => $this->checkin_token,
		);
	}

	/**
	 * Get format specifiers for database operations.
	 *
	 * @return array<string>
	 */
	public function get_formats(): array {
		return array(
			'%d', // Event ID.
			'%s', // Start datetime.
			'%s', // End datetime.
			'%s', // Start UTC.
			'%s', // End UTC.
			'%d', // All day.
			'%s', // Timezone.
			'%s', // Title override.
			'%s', // Description override.
			'%d', // Featured image ID.
			'%s', // Status.
			'%d', // Capacity.
			'%d', // Sequence number.
			'%d', // Is rescheduled.
			'%d', // Is override.
			'%s', // Venue name override.
			'%s', // Venue address override.
			'%s', // Virtual URL override.
			'%s', // Check-in token.
		);
	}

	/**
	 * Validate the occurrence data.
	 *
	 * @return array<string> Array of error messages, empty if valid.
	 */
	public function validate(): array {
		$errors = array();

		if ( empty( $this->event_id ) ) {
			$errors[] = __( 'Event ID is required.', 'nettertech-events' );
		}

		if ( empty( $this->start_datetime ) ) {
			$errors[] = __( 'Start date/time is required.', 'nettertech-events' );
		}

		if ( empty( $this->end_datetime ) ) {
			$errors[] = __( 'End date/time is required.', 'nettertech-events' );
		}

		if ( ! empty( $this->start_datetime ) && ! empty( $this->end_datetime ) ) {
			$start = strtotime( $this->start_datetime );
			$end   = strtotime( $this->end_datetime );
			if ( $end < $start ) {
				$errors[] = __( 'End time cannot be before start time.', 'nettertech-events' );
			}
		}

		if ( ! in_array( $this->status, self::STATUSES, true ) ) {
			$errors[] = __( 'Invalid occurrence status.', 'nettertech-events' );
		}

		return $errors;
	}

	/**
	 * Get the parent event.
	 *
	 * Lazy-loads from the event repository if set and event not yet cached.
	 *
	 * @return Event|null
	 */
	public function get_event(): ?Event {
		if ( null === $this->event && $this->event_id && null !== $this->event_repo ) {
			$this->event = $this->event_repo->find( $this->event_id );
		}
		return $this->event;
	}

	/**
	 * Set the parent event.
	 *
	 * @param Event $event The parent event.
	 * @return void
	 */
	public function set_event( Event $event ): void {
		$this->event    = $event;
		$this->event_id = $event->id ?? 0;
	}

	/**
	 * Set the event repository for lazy-loading the parent event.
	 *
	 * @param EventRepositoryInterface $event_repo Event repository.
	 * @return void
	 */
	public function set_event_repository( EventRepositoryInterface $event_repo ): void {
		$this->event_repo = $event_repo;
	}

	/**
	 * Get the effective title (override or parent).
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_title(): string {
		if ( ! empty( $this->title_override ) ) {
			return $this->title_override;
		}

		$event = $this->get_event();
		return $event ? $event->title : '';
	}

	/**
	 * Get the effective description (override or parent).
	 *
	 * @return string
	 */
	public function get_description(): string {
		if ( ! empty( $this->description_override ) ) {
			return $this->description_override;
		}

		$event = $this->get_event();
		return $event ? $event->description : '';
	}

	/**
	 * Get the effective featured image ID (override or parent).
	 *
	 * @return int|null
	 */
	public function get_featured_image_id(): ?int {
		if ( $this->featured_image_id ) {
			return $this->featured_image_id;
		}

		$event = $this->get_event();
		return $event ? $event->featured_image_id : null;
	}

	/**
	 * Get the effective venue name (override or parent).
	 *
	 * @return string
	 */
	public function get_venue_name(): string {
		if ( ! empty( $this->venue_name_override ) ) {
			return $this->venue_name_override;
		}

		$event = $this->get_event();
		return $event ? (string) $event->venue_name : '';
	}

	/**
	 * Get the effective venue address (override or parent).
	 *
	 * @return string
	 */
	public function get_venue_address(): string {
		if ( ! empty( $this->venue_address_override ) ) {
			return $this->venue_address_override;
		}

		$event = $this->get_event();
		return $event ? (string) $event->venue_address : '';
	}

	/**
	 * Get the effective virtual URL (override or parent).
	 *
	 * @return string
	 */
	public function get_virtual_url(): string {
		if ( ! empty( $this->virtual_url_override ) ) {
			return $this->virtual_url_override;
		}

		$event = $this->get_event();
		return $event ? (string) $event->virtual_url : '';
	}

	/**
	 * Get the featured image URL.
	 *
	 * @param string $size Image size.
	 * @return string|null
	 */
	public function get_featured_image_url( string $size = 'large' ): ?string {
		$image_id = $this->get_featured_image_id();
		if ( ! $image_id ) {
			return null;
		}

		return ImageHelper::get_attachment_image_url( $image_id, $size );
	}

	/**
	 * Get the URL for this occurrence.
	 *
	 * For single events, returns the event URL directly.
	 * For recurring events, returns the occurrence-specific URL with datetime.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_url(): string {
		$event = $this->get_event();
		if ( ! $event ) {
			return '';
		}

		// Single events link directly to the event page.
		if ( 'single' === $event->event_type ) {
			return $event->get_permalink();
		}

		// Recurring events use datetime-suffixed URLs.
		$datetime_slug = $this->get_start()->format( 'Y-m-d-Hi' );
		return PathHelper::get_occurrence_url( $event->slug, $datetime_slug );
	}

	/**
	 * Get start as DateTimeImmutable.
	 *
	 * @return DateTimeImmutable
	 */
	public function get_start(): DateTimeImmutable {
		return new DateTimeImmutable( $this->start_datetime, $this->resolve_timezone() );
	}

	/**
	 * Get end as DateTimeImmutable.
	 *
	 * @return DateTimeImmutable
	 */
	public function get_end(): DateTimeImmutable {
		return new DateTimeImmutable( $this->end_datetime, $this->resolve_timezone() );
	}

	/**
	 * Render an instant as the UTC datetime the database stores.
	 *
	 * @since 1.1.2
	 *
	 * @param DateTimeImmutable $moment The instant.
	 * @return string
	 */
	private function to_utc( DateTimeImmutable $moment ): string {
		return $moment->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * The zone this occurrence's wall-clock times are meant in.
	 *
	 * Callers need it to read anything else stored against this occurrence as bare
	 * wall-clock — a ticket's sale window, for one, which must open in the event's
	 * zone rather than the site's (NTE-148).
	 *
	 * @since 1.1.2
	 *
	 * @return DateTimeZone
	 */
	public function get_timezone(): DateTimeZone {
		return $this->resolve_timezone();
	}

	/**
	 * Resolve the timezone the stored wall-clock should be interpreted in.
	 *
	 * Start/end are stored as site-local wall-clock; the `timezone` column records
	 * the authoring zone (set on write + backfilled by Schema::migrate_to_3_14_0).
	 * Falls back to the site zone (wp_timezone(), a named zone → DST-correct) for
	 * empty or invalid values, so a wall-clock is never misread as UTC.
	 *
	 * @return DateTimeZone
	 */
	private function resolve_timezone(): DateTimeZone {
		if ( '' !== $this->timezone ) {
			try {
				return new DateTimeZone( $this->timezone );
			} catch ( \Exception $e ) {
				unset( $e ); // Invalid stored value — fall through to the site zone.
			}
		}
		return wp_timezone();
	}

	/**
	 * Get formatted start date.
	 *
	 * @param string $format Date format (default: WordPress setting).
	 * @return string
	 */
	public function get_start_date( string $format = '' ): string {
		if ( empty( $format ) ) {
			$format = get_option( 'date_format' );
		}
		return $this->get_start()->format( $format );
	}

	/**
	 * Get formatted start time.
	 *
	 * @param string $format Time format (default: WordPress setting).
	 * @return string
	 */
	public function get_start_time( string $format = '' ): string {
		if ( $this->all_day ) {
			return __( 'All Day', 'nettertech-events' );
		}

		if ( empty( $format ) ) {
			$format = get_option( 'time_format' );
		}
		return $this->get_start()->format( $format );
	}

	/**
	 * Get formatted end time.
	 *
	 * @param string $format Time format (default: WordPress setting).
	 * @return string
	 */
	public function get_end_time( string $format = '' ): string {
		if ( $this->all_day ) {
			return '';
		}

		if ( empty( $format ) ) {
			$format = get_option( 'time_format' );
		}
		return $this->get_end()->format( $format );
	}

	/**
	 * Get formatted date string for display (e.g., in product names).
	 *
	 * @return string Formatted date like "Dec 31, 2025".
	 */
	public function get_formatted_date(): string {
		return $this->get_start()->format( 'M j, Y' );
	}

	/**
	 * Get formatted time string for display.
	 *
	 * @return string Formatted time like "6:00 PM" or "All Day".
	 */
	public function get_formatted_time(): string {
		if ( $this->all_day ) {
			return __( 'All Day', 'nettertech-events' );
		}
		return $this->get_start()->format( get_option( 'time_format', 'g:i A' ) );
	}

	/**
	 * Get duration in minutes.
	 *
	 * @return int
	 */
	public function get_duration_minutes(): int {
		$start = strtotime( $this->start_datetime );
		$end   = strtotime( $this->end_datetime );
		return (int) ( ( $end - $start ) / 60 );
	}

	/**
	 * Check if this occurrence is in the past.
	 *
	 * @return bool
	 */
	public function is_past(): bool {
		// Compare true instants: get_end() interprets the wall-clock in the
		// authoring zone (DST-aware), time() is the current UTC instant.
		return $this->get_end()->getTimestamp() < time();
	}

	/**
	 * Check if this occurrence is happening now.
	 *
	 * @return bool
	 */
	public function is_happening_now(): bool {
		$now = time();
		return $this->get_start()->getTimestamp() <= $now && $now <= $this->get_end()->getTimestamp();
	}

	/**
	 * Check if this occurrence is scheduled.
	 *
	 * @return bool
	 */
	public function is_scheduled(): bool {
		return 'scheduled' === $this->status;
	}

	/**
	 * Check if this occurrence is cancelled.
	 *
	 * @return bool
	 */
	public function is_cancelled(): bool {
		return 'cancelled' === $this->status;
	}

	/**
	 * Check if the occurrence has ended.
	 *
	 * @return bool
	 */
	public function has_ended(): bool {
		return $this->get_end()->getTimestamp() < time();
	}

	/**
	 * Get formatted date and time string.
	 *
	 * @return string
	 */
	public function get_formatted_datetime(): string {
		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );

		if ( $this->all_day ) {
			return $this->get_start()->format( $date_format ) . ' ' . __( '(All Day)', 'nettertech-events' );
		}

		return $this->get_start()->format( $date_format . ' ' . $time_format );
	}
}
