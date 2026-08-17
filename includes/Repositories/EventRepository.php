<?php
/**
 * Event repository class.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventQueryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Traits\IdentityMapTrait;
use NetterTechEvents\Utilities\DatabaseLogger;

/**
 * Handles Event persistence and retrieval.
 *
 * All database operations for events go through this repository,
 * ensuring consistent query patterns and proper escaping.
 *
 * @since 0.1.0
 * @api
 */
class EventRepository implements EventRepositoryInterface {

	use IdentityMapTrait;

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Query repository for read-only operations.
	 *
	 * @var EventQueryRepositoryInterface
	 */
	private EventQueryRepositoryInterface $query_repo;

	/**
	 * Slug-to-ID index for identity map lookups.
	 *
	 * @var array<string, int>
	 */
	private array $slug_index = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param \wpdb                              $db         Database instance.
	 * @param EventQueryRepositoryInterface|null $query_repo Optional query repository.
	 */
	public function __construct(
		\wpdb $db,
		?EventQueryRepositoryInterface $query_repo = null
	) {
		$this->db         = $db;
		$this->table      = Schema::table( 'events' );
		$this->query_repo = $query_repo ?? new EventQueryRepository( $this->db );
	}

	/**
	 * Format database error for exceptions.
	 *
	 * In production (WP_DEBUG false), returns a generic message to avoid
	 * leaking database schema information. In development, includes the
	 * actual error for debugging.
	 *
	 * @param string $operation The operation that failed (e.g., 'insert', 'update').
	 * @param string $entity    The entity type (e.g., 'event').
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
	 * Find an event by ID.
	 *
	 * Uses identity map to return cached instance if available.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Event ID.
	 * @return Event|null
	 */
	public function find( int $id ): ?Event {
		// Check identity map first (per-request).
		$cached_event = $this->recalled( $id );
		if ( $cached_event instanceof Event ) {
			return $cached_event;
		}

		// Check persistent object cache (cross-request).
		$cache_key = 'event_' . $id;
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );
		if ( false !== $cached ) {
			$event = Event::from_row( $cached );
			$this->cache_event( $event );
			return $event;
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

		$event = Event::from_row( $row );
		$this->cache_event( $event );

		// Store in persistent object cache.
		wp_cache_set( $cache_key, $row, CacheManager::CACHE_GROUP, CacheManager::TTL_EVENT );

		return $event;
	}

	/**
	 * Find an event by slug.
	 *
	 * Uses identity map to return cached instance if available.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug Event slug.
	 * @return Event|null
	 */
	public function find_by_slug( string $slug ): ?Event {
		// Check slug index first.
		if ( isset( $this->slug_index[ $slug ] ) ) {
			$cached = $this->recalled( $this->slug_index[ $slug ] );
			if ( $cached instanceof Event ) {
				return $cached;
			}
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE slug = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$slug
			)
		);

		if ( ! $row ) {
			return null;
		}

		$event = Event::from_row( $row );
		$this->cache_event( $event );

		return $event;
	}

	/**
	 * Cache an event in the identity map.
	 *
	 * @param Event $event Event to cache.
	 * @return void
	 */
	private function cache_event( Event $event ): void {
		$event_id = $event->id;
		if ( null === $event_id ) {
			return;
		}

		$this->remember( $event_id, $event );
		if ( ! empty( $event->slug ) ) {
			$this->slug_index[ $event->slug ] = $event_id;
		}
	}

	/**
	 * Remove an event from the identity map.
	 *
	 * Called after updates/deletes to ensure stale data isn't returned.
	 *
	 * @param int $id Event ID.
	 * @return void
	 */
	private function uncache_event( int $id ): void {
		/**
		 * The identity map in this repository only ever stores Event instances.
		 *
		 * @var Event|null $event
		 */
		$event = $this->recalled( $id );
		if ( null !== $event ) {
			unset( $this->slug_index[ $event->slug ] );
			$this->forget( $id );
		}
	}

	/**
	 * Find an event by post ID.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return Event|null
	 */
	public function find_by_post_id( int $post_id ): ?Event {
		return $this->query_repo->find_by_post_id( $post_id );
	}

	/**
	 * Get all events.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<Event>
	 */
	public function all( array $args = array() ): array {
		return $this->query_repo->all( $args );
	}

	/**
	 * Get published events.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<Event>
	 */
	public function published( array $args = array() ): array {
		return $this->query_repo->published( $args );
	}

	/**
	 * Get events by series.
	 *
	 * @param int                  $series_id Series ID.
	 * @param array<string, mixed> $args      Query arguments.
	 * @return array<Event>
	 */
	public function by_series( int $series_id, array $args = array() ): array {
		return $this->query_repo->by_series( $series_id, $args );
	}

	/**
	 * Count events.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return int
	 */
	public function count( array $args = array() ): int {
		return $this->query_repo->count( $args );
	}

	/**
	 * Paginate events with filtering and search.
	 *
	 * @param array<string, mixed> $args Query arguments (page, per_page, status, search, orderby, order).
	 * @return array{items: array<Event>, total: int, pages: int}
	 */
	public function paginate( array $args = array() ): array {
		return $this->query_repo->paginate( $args );
	}

	/**
	 * Save an event (insert or update).
	 *
	 * @since 0.1.0
	 *
	 * @param Event $event Event to save.
	 * @return Event The saved event with ID populated.
	 * @throws ValidationException If validation fails.
	 * @throws DatabaseException   If save fails.
	 */
	public function save( Event $event ): Event {
		// Generate slug before validation (validation requires slug).
		if ( empty( $event->slug ) && ! empty( $event->title ) ) {
			$event->slug = $this->generate_unique_slug( $event->title );
		}

		$errors = $event->validate();
		if ( ! empty( $errors ) ) {
			throw ValidationException::fromErrors( array_map( 'esc_html', $errors ) );
		}

		$data    = $event->to_array();
		$formats = $event->get_formats();

		// Capture pre-save state for lifecycle hooks.
		$is_new     = ( null === $event->id );
		$old_status = null;
		if ( ! $is_new ) {
			$old_row    = $this->db->get_row(
				$this->db->prepare(
					"SELECT status FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
					$event->id
				)
			);
			$old_status = $old_row->status ?? null;
		}

		/**
		 * Fires before saving an event.
		 *
		 * @since 1.0.2
		 *
		 * @param Event $event The event being saved.
		 * @param array $data  The data being saved.
		 */
		do_action( 'nettertech_events_before_save_event', $event, $data );

		if ( $is_new ) {
			// Set created_at explicitly in UTC (NTE-131) rather than relying on the
			// MySQL `created_at DEFAULT CURRENT_TIMESTAMP`, which MySQL writes in the
			// DB session timezone and is therefore environment-dependent (a local dev DB may store
			// site-local while a managed host stores UTC). Storing UTC here makes the admin
			// "Created" display portable: callers render it with get_date_from_gmt().
			$data['created_at'] = gmdate( 'Y-m-d H:i:s' );
			$formats[]          = '%s';

			// Insert.
			$result = $this->db->insert( $this->table, $data, $formats );

			if ( false === $result ) {
				throw DatabaseException::insertFailed( 'event', esc_html( $this->format_db_error( 'insert', 'event' ) ) );
			}

			$event->id         = (int) $this->db->insert_id;
			$event->created_at = $data['created_at'];
		} else {
			// Update.
			$result = $this->db->update(
				$this->table,
				$data,
				array( 'id' => $event->id ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				throw DatabaseException::updateFailed( 'event', (int) $event->id, esc_html( $this->format_db_error( 'update', 'event' ) ) );
			}
		}

		// Update identity map with new/updated event.
		$this->cache_event( $event );

		// Invalidate persistent object cache for this event.
		wp_cache_delete( 'event_' . $event->id, CacheManager::CACHE_GROUP );

		/**
		 * Fires after saving an event.
		 *
		 * @since 1.0.2
		 *
		 * @param Event $event The saved event.
		 */
		do_action( 'nettertech_events_after_save_event', $event );

		// Fire granular lifecycle hooks for activity logging and downstream consumers.
		$this->fire_lifecycle_hooks( $event, $data, $is_new, $old_status );

		return $event;
	}

	/**
	 * Fire granular lifecycle hooks after an event is saved.
	 *
	 * Discriminates between create/update and detects status transitions
	 * to fire the appropriate hooks for ActivityLogHooks and other consumers.
	 *
	 * @param Event                $event      The saved event.
	 * @param array<string, mixed> $data       The saved data array.
	 * @param bool                 $is_new     Whether the event was just created.
	 * @param string|null          $old_status The previous status (null for new events).
	 * @return void
	 */
	private function fire_lifecycle_hooks( Event $event, array $data, bool $is_new, ?string $old_status ): void {
		$event_id       = (int) $event->id;
		$new_status     = $event->status;
		$new_status_str = $new_status->value;

		if ( $is_new ) {
			// Hooks::EVENT_CREATED — granular lifecycle hook for activity logging.
			do_action( 'nettertech_events_event_created', $event_id, $data );

			// New events published on creation.
			if ( EventStatus::PUBLISHED === $new_status ) {
				do_action( 'nettertech_events_event_published', $event_id, $event->title ?? '' );
			}
		} else {
			// Hooks::EVENT_UPDATED — granular lifecycle hook for activity logging.
			do_action( 'nettertech_events_event_updated', $event_id, $data );

			// Detect status transitions ($old_status is a string from the database).
			if ( null !== $old_status && $old_status !== $new_status_str ) {
				if ( EventStatus::PUBLISHED === $new_status && 'published' !== $old_status ) {
					do_action( 'nettertech_events_event_published', $event_id, $event->title ?? '' );
				} elseif ( 'published' === $old_status && EventStatus::PUBLISHED !== $new_status ) {
					do_action( 'nettertech_events_event_unpublished', $event_id, $event->title ?? '' );
				}
			}
		}
	}

	/**
	 * Delete an event.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Event ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool {
		$event = $this->find( $id );

		if ( ! $event ) {
			return false;
		}

		/**
		 * Fires before deleting an event.
		 *
		 * @since 1.0.2
		 *
		 * @param Event $event The event being deleted.
		 */
		do_action( 'nettertech_events_before_delete_event', $event );

		$result = $this->db->delete(
			$this->table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false !== $result ) {
			// Remove from identity map and persistent cache.
			$this->uncache_event( $id );
			wp_cache_delete( 'event_' . $id, CacheManager::CACHE_GROUP );

			/**
			 * Fires after deleting an event.
			 *
			 * @since 1.0.2
			 *
			 * @param int   $id    The deleted event ID.
			 * @param Event $event The deleted event data.
			 */
			do_action( 'nettertech_events_after_delete_event', $id, $event );

			// Hooks::EVENT_DELETED — granular lifecycle hook for activity logging.
			do_action( 'nettertech_events_event_deleted', $id, $event->title ?? '' );
		}

		return false !== $result;
	}

	/**
	 * Check if a slug exists.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $slug       Slug to check.
	 * @param int|null $exclude_id Event ID to exclude from check.
	 * @return bool
	 */
	public function slug_exists( string $slug, ?int $exclude_id = null ): bool {
		$sql    = "SELECT COUNT(*) FROM {$this->table} WHERE slug = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
		$values = array( $slug );

		if ( null !== $exclude_id ) {
			$sql     .= ' AND id != %d';
			$values[] = $exclude_id;
		}

		return (int) $this->db->get_var( $this->db->prepare( $sql, ...$values ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().
	}

	/**
	 * Search events by title.
	 *
	 * @param string               $search Search term.
	 * @param array<string, mixed> $args   Query arguments.
	 * @return array<Event>
	 */
	public function search( string $search, array $args = array() ): array {
		return $this->query_repo->search( $search, $args );
	}

	/**
	 * Count search results without loading rows into memory.
	 *
	 * @param string               $search Search term.
	 * @param array<string, mixed> $args   Query arguments (status).
	 * @return int Total matching rows.
	 */
	public function search_count( string $search, array $args = array() ): int {
		return $this->query_repo->search_count( $search, $args );
	}

	/**
	 * Generate a unique slug from a title.
	 *
	 * @since 0.11.0
	 *
	 * @param string $title The title to generate a slug from.
	 * @return string Unique slug.
	 * @throws \RuntimeException If a unique slug cannot be generated after 100 attempts.
	 */
	public function generate_unique_slug( string $title ): string {
		$slug = sanitize_title( $title );

		$existing = $this->db->get_var(
			$this->db->prepare(
				"SELECT slug FROM {$this->table} WHERE slug = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$slug
			)
		);

		if ( $existing ) {
			$counter      = 2;
			$base_slug    = $slug;
			$max_attempts = 100;
			while ( $existing && $counter <= $max_attempts ) {
				$slug     = $base_slug . '-' . $counter;
				$existing = $this->db->get_var(
					$this->db->prepare(
						"SELECT slug FROM {$this->table} WHERE slug = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
						$slug
					)
				);
				++$counter;
			}

			if ( $counter > $max_attempts ) {
				throw new \RuntimeException(
					esc_html(
						sprintf(
							/* translators: 1: base slug, 2: max attempts */
							'Unable to generate unique slug for "%1$s" after %2$d attempts.',
							$base_slug,
							$max_attempts
						)
					)
				);
			}
		}

		return $slug;
	}
}
