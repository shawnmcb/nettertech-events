<?php
/**
 * Occurrence repository class.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceQueryRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Traits\IdentityMapTrait;
use NetterTechEvents\Utilities\DatabaseLogger;

/**
 * Handles Occurrence persistence and retrieval.
 *
 * Optimized for calendar-style queries (date range lookups)
 * using the composite indexes on the occurrences table.
 *
 * @since 0.8.0
 * @api
 */
class OccurrenceRepository implements OccurrenceRepositoryInterface {

	use IdentityMapTrait;

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Occurrences table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Events table name.
	 *
	 * @var string
	 */
	private string $events_table;

	/**
	 * Query repository for read-only operations.
	 *
	 * @var OccurrenceQueryRepositoryInterface
	 */
	private OccurrenceQueryRepositoryInterface $query_repo;

	/**
	 * Event repository for injecting into Occurrence models.
	 *
	 * @var EventRepositoryInterface|null
	 */
	private ?EventRepositoryInterface $event_repo = null;

	/**
	 * Constructor.
	 *
	 * @param \wpdb                                   $db         Database instance.
	 * @param OccurrenceQueryRepositoryInterface|null $query_repo Optional query repository for testing.
	 * @param EventRepositoryInterface|null           $event_repo Optional event repository for occurrence lazy-loading.
	 */
	public function __construct( \wpdb $db, ?OccurrenceQueryRepositoryInterface $query_repo = null, ?EventRepositoryInterface $event_repo = null ) {
		$this->db           = $db;
		$this->table        = Schema::table( 'occurrences' );
		$this->events_table = Schema::table( 'events' );
		$this->query_repo   = $query_repo ?? new OccurrenceQueryRepository( $this->db );
		$this->event_repo   = $event_repo;
	}

	/**
	 * Inject the event repository into an Occurrence for lazy-loading.
	 *
	 * @param Occurrence $occurrence The occurrence to configure.
	 * @return Occurrence The same occurrence, for chaining.
	 */
	private function inject_event_repo( Occurrence $occurrence ): Occurrence {
		if ( null !== $this->event_repo ) {
			$occurrence->set_event_repository( $this->event_repo );
		}
		return $occurrence;
	}

	/**
	 * Format database error for exceptions.
	 *
	 * In production (WP_DEBUG false), returns a generic message to avoid
	 * leaking database schema information. In development, includes the
	 * actual error for debugging.
	 *
	 * @param string $operation The operation that failed (e.g., 'insert', 'update').
	 * @param string $entity    The entity type (e.g., 'occurrence').
	 * @return string Safe error message.
	 */
	private function format_db_error( string $operation, string $entity ): string {
		// Log the error with appropriate detail level (sanitized in production).
		if ( $this->db->last_error ) {
			DatabaseLogger::log_error( $operation, $entity, $this->db->last_error );
		}

		// In debug mode, include the actual error for developers.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return sprintf(
				/* translators: 1: operation (insert/update), 2: entity type, 3: error message */
				esc_html__( 'Failed to %1$s %2$s: %3$s', 'nettertech-events' ),
				$operation,
				$entity,
				esc_html( $this->db->last_error )
			);
		}

		// In production, return a generic message.
		return sprintf(
			/* translators: 1: operation (insert/update), 2: entity type */
			esc_html__( 'Failed to %1$s %2$s. Please try again or contact support.', 'nettertech-events' ),
			$operation,
			$entity
		);
	}

	/**
	 * Find an occurrence by ID.
	 *
	 * Uses identity map to return cached instance if available.
	 *
	 * @param int $id Occurrence ID.
	 * @return Occurrence|null
	 */
	public function find( int $id ): ?Occurrence {
		// Check identity map first.
		$cached = $this->recalled( $id );
		if ( $cached instanceof Occurrence ) {
			return $cached;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$id
			)
		);

		if ( ! $row ) {
			return null;
		}

		$occurrence = Occurrence::from_row( $row );
		$this->inject_event_repo( $occurrence );
		if ( null !== $occurrence->id ) {
			$this->remember( $occurrence->id, $occurrence );
		}

		return $occurrence;
	}

	/**
	 * Find an occurrence with its parent event.
	 *
	 * @param int $id Occurrence ID.
	 * @return Occurrence|null
	 */
	public function find_with_event( int $id ): ?Occurrence {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT o.*, e.title as event_title, e.slug as event_slug,
                        e.description as event_description, e.featured_image_id as event_image_id
                 FROM {$this->table} o
                 JOIN {$this->events_table} e ON o.event_id = e.id
                 WHERE o.id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$id
			)
		);

		if ( ! $row ) {
			return null;
		}

		$occurrence = Occurrence::from_row( $row );
		$this->inject_event_repo( $occurrence );

		// Attach event data.
		$event                    = new Event();
		$event->id                = $occurrence->event_id;
		$event->title             = $row->event_title;
		$event->slug              = $row->event_slug;
		$event->description       = $row->event_description;
		$event->featured_image_id = $row->event_image_id ? (int) $row->event_image_id : null;
		$occurrence->set_event( $event );

		return $occurrence;
	}

	/**
	 * Find an occurrence by event ID and datetime.
	 *
	 * Used for URL routing to resolve occurrence from slug + datetime.
	 *
	 * @param int                $event_id  Event ID.
	 * @param \DateTimeInterface $datetime Start datetime to match.
	 * @return Occurrence|null
	 */
	public function find_by_event_and_datetime( int $event_id, \DateTimeInterface $datetime ): ?Occurrence {
		$datetime_str = $datetime->format( 'Y-m-d H:i:s' );

		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT o.*, e.title as event_title, e.slug as event_slug,
                        e.description as event_description, e.featured_image_id as event_image_id,
                        e.event_type, e.venue_name, e.venue_address
                 FROM {$this->table} o
                 JOIN {$this->events_table} e ON o.event_id = e.id
                 WHERE o.event_id = %d AND o.start_datetime = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$event_id,
				$datetime_str
			)
		);

		if ( ! $row ) {
			return null;
		}

		$occurrence = Occurrence::from_row( $row );
		$this->inject_event_repo( $occurrence );

		// Attach event data.
		$event                    = new Event();
		$event->id                = $occurrence->event_id;
		$event->title             = $row->event_title;
		$event->slug              = $row->event_slug;
		$event->description       = $row->event_description;
		$event->event_type        = $row->event_type ?? 'single';
		$event->featured_image_id = $row->event_image_id ? (int) $row->event_image_id : null;
		$event->venue_name        = $row->venue_name ?? null;
		$event->venue_address     = $row->venue_address ?? null;
		$occurrence->set_event( $event );

		return $occurrence;
	}

	/**
	 * Get sibling occurrences for the same event.
	 *
	 * @param int $occurrence_id The current occurrence ID.
	 * @return array{all: array<Occurrence>, past: array<Occurrence>, upcoming: array<Occurrence>, current_index: int}
	 */
	public function get_siblings( int $occurrence_id ): array {
		return $this->query_repo->get_siblings( $occurrence_id );
	}

	/**
	 * Get occurrences for an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array<Occurrence>
	 */
	public function for_event( int $event_id, array $args = array() ): array {
		return $this->query_repo->for_event( $event_id, $args );
	}

	/**
	 * Get occurrences for an event, grouped by past and upcoming.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array{past: array<Occurrence>, upcoming: array<Occurrence>}
	 */
	public function for_event_grouped( int $event_id, array $args = array() ): array {
		return $this->query_repo->for_event_grouped( $event_id, $args );
	}

	/**
	 * Get occurrences in a date range.
	 *
	 * @param string               $start_date Start date (Y-m-d or Y-m-d H:i:s).
	 * @param string               $end_date   End date (Y-m-d or Y-m-d H:i:s).
	 * @param array<string, mixed> $args       Query arguments.
	 * @return array<Occurrence>
	 */
	public function in_range( string $start_date, string $end_date, array $args = array() ): array {
		return $this->query_repo->in_range( $start_date, $end_date, $args );
	}

	/**
	 * Get upcoming occurrences.
	 *
	 * @param int                  $limit Number of occurrences.
	 * @param array<string, mixed> $args  Query arguments.
	 * @return array<Occurrence>
	 */
	public function upcoming( int $limit = 10, array $args = array() ): array {
		return $this->query_repo->upcoming( $limit, $args );
	}

	/**
	 * Get next occurrence for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return Occurrence|null
	 */
	public function next_for_event( int $event_id ): ?Occurrence {
		return $this->query_repo->next_for_event( $event_id );
	}

	/**
	 * Record the authoring timezone on an occurrence before persistence.
	 *
	 * Occurrence start/end are stored as site-local wall-clock; the timezone column
	 * records the zone they were authored in so get_start()/get_end() and the
	 * is_past()/has_ended() comparisons interpret them correctly (DST-aware). Only
	 * stamps when the column is the unset default ('' or 'UTC') — a deliberately-set
	 * zone is preserved. On a UTC site wp_timezone_string() returns 'UTC' (no-op).
	 *
	 * @param Occurrence $occurrence Occurrence about to be persisted.
	 * @return void
	 */
	private function ensure_timezone( Occurrence $occurrence ): void {
		if ( '' === $occurrence->timezone || 'UTC' === $occurrence->timezone ) {
			$occurrence->timezone = wp_timezone_string();
		}
	}

	/**
	 * Save an occurrence (insert or update).
	 *
	 * @param Occurrence $occurrence Occurrence to save.
	 * @return Occurrence The saved occurrence with ID populated.
	 * @throws ValidationException If validation fails.
	 * @throws DatabaseException   If save fails.
	 */
	public function save( Occurrence $occurrence ): Occurrence {
		$errors = $occurrence->validate();
		if ( ! empty( $errors ) ) {
			throw ValidationException::fromErrors( array_map( 'esc_html', $errors ) );
		}

		$this->ensure_timezone( $occurrence );

		$data    = $occurrence->to_array();
		$formats = $occurrence->get_formats();
		$is_new  = ( null === $occurrence->id );

		if ( $is_new ) {
			// Insert.
			$result = $this->db->insert( $this->table, $data, $formats );

			if ( false === $result ) {
				throw DatabaseException::insertFailed( 'occurrence', esc_html( $this->format_db_error( 'insert', 'occurrence' ) ) );
			}

			$occurrence->id = (int) $this->db->insert_id;

			// Hooks::OCCURRENCE_CREATED — granular lifecycle hook for activity logging.
			do_action(
				'nettertech_events_occurrence_created',
				(int) $occurrence->id,
				array_merge( $data, array( 'event_title' => $this->get_event_title( $occurrence->event_id ) ) )
			);
		} else {
			// Update.
			$result = $this->db->update(
				$this->table,
				$data,
				array( 'id' => $occurrence->id ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				throw DatabaseException::updateFailed( 'occurrence', (int) $occurrence->id, esc_html( $this->format_db_error( 'update', 'occurrence' ) ) );
			}

			// Invalidate identity map for updated occurrence.
			$this->forget( $occurrence->id );
		}

		return $occurrence;
	}

	/**
	 * Save multiple occurrences (batch insert).
	 *
	 * @param array<Occurrence> $occurrences Occurrences to save.
	 * @return int Number of occurrences saved.
	 */
	public function save_batch( array $occurrences ): int {
		if ( empty( $occurrences ) ) {
			return 0;
		}

		// Separate validated new (insert) from existing (update) occurrences.
		$to_insert = array();
		$to_update = array();

		foreach ( $occurrences as $occurrence ) {
			$errors = $occurrence->validate();
			if ( ! empty( $errors ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only.
					error_log( 'NetterTechEvents: Validation failed for occurrence - ' . implode( ' ', $errors ) );
				}
				continue;
			}

			if ( null === $occurrence->id ) {
				$to_insert[] = $occurrence;
			} else {
				$to_update[] = $occurrence;
			}
		}

		$saved = 0;

		// Batch INSERT new occurrences using multi-value INSERT.
		if ( ! empty( $to_insert ) ) {
			$saved += $this->batch_insert_occurrences( $to_insert );
		}

		// Update existing occurrences individually (different values per row).
		foreach ( $to_update as $occurrence ) {
			try {
				$this->save( $occurrence );
				++$saved;
			} catch ( \RuntimeException $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only.
					error_log( 'NetterTechEvents: Failed to update occurrence - ' . $e->getMessage() );
				}
			}
		}

		return $saved;
	}

	/**
	 * Batch insert new occurrences using multi-value INSERT.
	 *
	 * Assigns AUTO_INCREMENT IDs back to each Occurrence object.
	 * Chunks at 500 rows to stay within MySQL max_allowed_packet limits.
	 *
	 * @param array<Occurrence> $occurrences New occurrences (id must be null).
	 * @return int Number of occurrences inserted.
	 */
	private function batch_insert_occurrences( array $occurrences ): int {
		foreach ( $occurrences as $occurrence ) {
			$this->ensure_timezone( $occurrence );
		}

		$columns     = array_keys( $occurrences[0]->to_array() );
		$formats     = $occurrences[0]->get_formats();
		$column_list = '`' . implode( '`, `', $columns ) . '`';
		$chunk_size  = 500;
		$inserted    = 0;

		foreach ( array_chunk( $occurrences, $chunk_size ) as $chunk ) {
			$value_clauses = array();
			$values        = array();

			foreach ( $chunk as $occ ) {
				$data             = $occ->to_array();
				$row_placeholders = array();
				foreach ( $columns as $i => $col ) {
					// Emit SQL NULL literal for null values so nullable UNIQUE columns
					// (e.g. checkin_token) allow multiple unset rows. Routing null through
					// $wpdb->prepare with %s coerces it to empty string, breaking UNIQUE.
					if ( null === $data[ $col ] ) {
						$row_placeholders[] = 'NULL';
						continue;
					}
					$row_placeholders[] = $formats[ $i ];
					$values[]           = $data[ $col ];
				}
				$value_clauses[] = '(' . implode( ', ', $row_placeholders ) . ')';
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names from trusted source.
			$sql = "INSERT INTO {$this->table} ({$column_list}) VALUES " . implode( ', ', $value_clauses );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built dynamically with proper placeholders.
			$prepared = $this->db->prepare( $sql, $values );
			if ( null === $prepared ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
			$result = $this->db->query( $prepared );

			if ( false !== $result ) {
				// AUTO_INCREMENT IDs are sequential within a single INSERT statement.
				$first_id = (int) $this->db->insert_id;
				foreach ( $chunk as $i => $occ ) {
					$occ->id = $first_id + $i;
				}
				$inserted += count( $chunk );
			}
		}

		return $inserted;
	}

	/**
	 * Delete an occurrence.
	 *
	 * @param int $id Occurrence ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool {
		// Load occurrence before deletion for hook data.
		$occurrence = $this->find( $id );

		$result = $this->db->delete(
			$this->table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false !== $result ) {
			$this->forget( $id );

			// Fire granular lifecycle hook.
			$event_title = '';
			if ( null !== $occurrence ) {
				$event_title = $this->get_event_title( $occurrence->event_id );
			}

			// Hooks::OCCURRENCE_DELETED — granular lifecycle hook for activity logging.
			do_action( 'nettertech_events_occurrence_deleted', $id, $event_title );
		}

		return false !== $result;
	}

	/**
	 * Get the IDs of ALL occurrences for an event, unbounded.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int> All occurrence IDs for the event.
	 */
	public function all_ids_for_event( int $event_id ): array {
		$ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT id FROM {$this->table} WHERE event_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$event_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Delete all occurrences for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int Number of occurrences deleted.
	 */
	public function delete_for_event( int $event_id ): int {
		$result = $this->db->delete(
			$this->table,
			array( 'event_id' => $event_id ),
			array( '%d' )
		);

		return false !== $result ? $result : 0;
	}

	/**
	 * Delete future occurrences for an event.
	 *
	 * Used when regenerating occurrences for a recurring event.
	 *
	 * @param int $event_id Event ID.
	 * @return int Number of occurrences deleted.
	 */
	public function delete_future_for_event( int $event_id ): int {
		$sql = $this->db->prepare(
			"DELETE FROM {$this->table} WHERE event_id = %d AND start_datetime >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			$event_id,
			current_time( 'mysql' )
		);
		if ( null === $sql ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		return (int) $this->db->query( $sql );
	}

	/**
	 * Get the title of an event by ID.
	 *
	 * Lightweight query used by lifecycle hooks to include event context.
	 * Uses the event repository if available, falls back to direct query.
	 *
	 * @param int $event_id Event ID.
	 * @return string Event title, or empty string if not found.
	 */
	private function get_event_title( int $event_id ): string {
		if ( null !== $this->event_repo ) {
			$event = $this->event_repo->find( $event_id );
			return null !== $event ? ( $event->title ?? '' ) : '';
		}

		// Fallback: direct query to avoid circular dependency.
		$title = $this->db->get_var(
			$this->db->prepare(
				"SELECT title FROM {$this->events_table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$event_id
			)
		);

		return is_string( $title ) ? $title : '';
	}

	/**
	 * Get upcoming occurrences for an event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $limit    Maximum number of occurrences.
	 * @return array<Occurrence>
	 */
	public function get_upcoming_by_event( int $event_id, int $limit = 10 ): array {
		return $this->query_repo->get_upcoming_by_event( $event_id, $limit );
	}

	/**
	 * Count occurrences for an event.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $status   Optional status filter.
	 * @return int
	 */
	public function count_for_event( int $event_id, string $status = '' ): int {
		return $this->query_repo->count_for_event( $event_id, $status );
	}

	/**
	 * Update status for an occurrence.
	 *
	 * @param int    $id     Occurrence ID.
	 * @param string $status New status.
	 * @return bool
	 */
	public function update_status( int $id, string $status ): bool {
		if ( ! in_array( $status, Occurrence::STATUSES, true ) ) {
			return false;
		}

		$old_occurrence = $this->find( $id );
		if ( ! $old_occurrence ) {
			return false;
		}

		$result = $this->db->update(
			$this->table,
			array( 'status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			/**
			 * Fires when an occurrence status changes.
			 *
			 * @param int    $id         Occurrence ID.
			 * @param string $new_status New status.
			 * @param string $old_status Previous status.
			 */
			do_action( 'nettertech_events_occurrence_status_changed', $id, $status, $old_occurrence->status );

			// Fire specific cancellation hook for activity logging.
			if ( 'cancelled' === $status && 'cancelled' !== $old_occurrence->status ) {
				$event_title = $this->get_event_title( $old_occurrence->event_id );

				// Hooks::OCCURRENCE_CANCELLED — granular lifecycle hook for activity logging.
				do_action( 'nettertech_events_occurrence_cancelled', $id, $event_title );
			}
		}

		return false !== $result;
	}

	/**
	 * Get filtered occurrences with pagination.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<Occurrence>, total: int, total_pages: int}
	 */
	public function get_filtered( array $args = array() ): array {
		return $this->query_repo->get_filtered( $args );
	}
}
