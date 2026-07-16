<?php
/**
 * Event Revision Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);


namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Contracts\RevisionRepositoryInterface;

/**
 * Manages event revision lifecycle: capture, restore, and diff.
 *
 * @since 1.5.0
 */
class RevisionService {

	/**
	 * Fields that can be restored from a revision snapshot.
	 *
	 * @var array<string>
	 */
	public const RESTORABLE_FIELDS = array(
		'title',
		'slug',
		'description',
		'excerpt',
		'featured_image_id',
		'status',
		'event_type',
		'series_id',
		'venue_name',
		'venue_address',
		'recurrence_rule',
		'recurrence_end_date',
		'layout_config',
		'custom_fields',
		'is_virtual',
		'virtual_url',
		'reminders_enabled',
	);

	/**
	 * Human-readable labels for event fields.
	 *
	 * @var array<string, string>
	 */
	public const FIELD_LABELS = array(
		'title'               => 'Title',
		'slug'                => 'Slug',
		'description'         => 'Description',
		'excerpt'             => 'Excerpt',
		'featured_image_id'   => 'Featured Image',
		'status'              => 'Status',
		'event_type'          => 'Event Type',
		'series_id'           => 'Series',
		'venue_name'          => 'Venue Name',
		'venue_address'       => 'Venue Address',
		'recurrence_rule'     => 'Recurrence Rule',
		'recurrence_end_date' => 'Recurrence End Date',
		'layout_config'       => 'Layout Config',
		'custom_fields'       => 'Custom Fields',
		'is_virtual'          => 'Virtual Event',
		'virtual_url'         => 'Virtual URL',
		'reminders_enabled'   => 'Reminders Enabled',
		'post_id'             => 'Post ID',
	);

	/**
	 * Revision repository.
	 *
	 * @var RevisionRepositoryInterface
	 */
	private RevisionRepositoryInterface $revision_repo;

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
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param RevisionRepositoryInterface   $revision_repo   Revision repository.
	 * @param EventRepositoryInterface      $event_repo      Event repository.
	 * @param OccurrenceRepositoryInterface $occurrence_repo Occurrence repository.
	 * @param \wpdb                         $db              WordPress database instance.
	 */
	public function __construct(
		RevisionRepositoryInterface $revision_repo,
		EventRepositoryInterface $event_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		\wpdb $db
	) {
		$this->revision_repo   = $revision_repo;
		$this->event_repo      = $event_repo;
		$this->occurrence_repo = $occurrence_repo;
		$this->db              = $db;
	}

	/**
	 * Capture a snapshot of the event before it is saved.
	 *
	 * Hooked to BEFORE_SAVE_EVENT. Fetches the old state directly
	 * from the database to bypass the identity map.
	 *
	 * @param Event                $event The event about to be saved.
	 * @param array<string, mixed> $data  The raw data being saved (unused).
	 * @return void
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature requires $data.
	 */
	public function capture_pre_save_snapshot( Event $event, array $data ): void {
		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( null === $event->id || 0 === $event->id ) {
			return;
		}

		// Bypass the identity map by querying the database directly.
		$table = Schema::table( 'events' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source.
		$old_row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE id = %d", $event->id ) );

		if ( null === $old_row ) {
			return;
		}

		$old_event    = Event::from_row( $old_row );
		$old_snapshot = $this->build_snapshot( $old_event );
		$new_snapshot = $this->build_snapshot( $event );

		$change_summary = $this->generate_change_summary( $old_snapshot, $new_snapshot );

		if ( '' === $change_summary ) {
			return;
		}

		$user_id = get_current_user_id();

		$this->revision_repo->insert( $event->id, $user_id, $old_snapshot, $change_summary );

		$max = (int) apply_filters( 'nettertech_events_max_revisions', 20 );
		$this->revision_repo->prune( $event->id, $max );
	}

	/**
	 * Build a snapshot array from an Event model.
	 *
	 * @param Event $event Event model.
	 * @return array<string, mixed>
	 */
	public function build_snapshot( Event $event ): array {
		$snapshot       = $event->to_array();
		$snapshot['id'] = $event->id;

		$occurrences              = $this->occurrence_repo->for_event( $event->id ?? 0 );
		$snapshot['_occurrences'] = count( $occurrences );

		$next                         = ! empty( $occurrences ) ? $occurrences[0] : null;
		$snapshot['_next_occurrence'] = $next ? ( $next->start_datetime ?? null ) : null;

		return $snapshot;
	}

	/**
	 * Generate a human-readable change summary.
	 *
	 * @param array<string, mixed> $old_snapshot Old snapshot.
	 * @param array<string, mixed> $new_snapshot New snapshot.
	 * @return string Change summary, or empty string if no changes.
	 */
	public function generate_change_summary( array $old_snapshot, array $new_snapshot ): string {
		$changed_fields = array();

		foreach ( self::RESTORABLE_FIELDS as $field ) {
			$old_val = $old_snapshot[ $field ] ?? null;
			$new_val = $new_snapshot[ $field ] ?? null;

			// Normalize for comparison.
			$old_encoded = $this->encode_for_comparison( $old_val );
			$new_encoded = $this->encode_for_comparison( $new_val );

			if ( $old_encoded !== $new_encoded ) {
				$changed_fields[] = self::FIELD_LABELS[ $field ];
			}
		}

		if ( empty( $changed_fields ) ) {
			return '';
		}

		return 'Changed ' . implode( ', ', $changed_fields );
	}

	/**
	 * Restore an event from a revision snapshot.
	 *
	 * @param int $revision_id Revision ID.
	 * @return int|false Restored event ID, or false on failure.
	 */
	public function restore( int $revision_id ) {
		$revision = $this->revision_repo->find( $revision_id );

		if ( ! $revision ) {
			return false;
		}

		$snapshot = json_decode( $revision->revision_data, true );

		if ( ! is_array( $snapshot ) ) {
			return false;
		}

		$event_id = (int) $revision->event_id;
		$event    = $this->event_repo->find( $event_id );

		if ( ! $event ) {
			return false;
		}

		// Capture current state as a pre-restore revision.
		$pre_restore_snapshot = $this->build_snapshot( $event );
		$this->revision_repo->insert(
			$event_id,
			get_current_user_id(),
			$pre_restore_snapshot,
			'Pre-restore snapshot'
		);

		// Apply restorable fields from the revision snapshot.
		foreach ( self::RESTORABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $snapshot ) ) {
				if ( 'status' === $field && is_string( $snapshot[ $field ] ) ) {
					$event->status = EventStatus::tryFrom( $snapshot[ $field ] ) ?? EventStatus::DRAFT;
				} else {
					$event->$field = $snapshot[ $field ];
				}
			}
		}

		$this->event_repo->save( $event );

		do_action( 'nettertech_events_event_restored', $event_id, $revision_id, $event );

		$max = (int) apply_filters( 'nettertech_events_max_revisions', 20 );
		$this->revision_repo->prune( $event_id, $max );

		return $event_id;
	}

	/**
	 * Compute a detailed diff between two snapshots.
	 *
	 * @param array<string, mixed> $old_snapshot Old snapshot.
	 * @param array<string, mixed> $new_snapshot New snapshot.
	 * @return array<array{field: string, label: string, old: string, new: string}>
	 */
	public function compute_diff( array $old_snapshot, array $new_snapshot ): array {
		$diff = array();

		foreach ( $old_snapshot as $field => $old_value ) {
			// Skip metadata fields.
			if ( str_starts_with( $field, '_' ) || 'id' === $field ) {
				continue;
			}

			$new_value = $new_snapshot[ $field ] ?? null;

			$old_str = $this->encode_for_comparison( $old_value );
			$new_str = $this->encode_for_comparison( $new_value );

			if ( $old_str !== $new_str ) {
				$diff[] = array(
					'field' => $field,
					'label' => self::FIELD_LABELS[ $field ] ?? $field,
					'old'   => mb_substr( $old_str, 0, 200 ),
					'new'   => mb_substr( $new_str, 0, 200 ),
				);
			}
		}

		return $diff;
	}

	/**
	 * Delete all revisions when an event is deleted.
	 *
	 * Hooked to AFTER_DELETE_EVENT.
	 *
	 * @param int   $event_id The deleted event ID.
	 * @param Event $event    The deleted event model (unused).
	 * @return void
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature requires $event.
	 */
	public function cleanup_on_event_delete( int $event_id, Event $event ): void {
		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->revision_repo->delete_for_event( $event_id );
	}

	/**
	 * Encode a value for comparison.
	 *
	 * @param mixed $value Value to encode.
	 * @return string
	 */
	private function encode_for_comparison( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value );
			return false !== $encoded ? $encoded : '';
		}

		return (string) $value;
	}
}
