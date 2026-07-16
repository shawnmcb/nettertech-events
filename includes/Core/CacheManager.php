<?php
/**
 * Cache management class.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\HouseCapacityRepositoryInterface;
use NetterTechEvents\TemplateLoader\Templates;

/**
 * Handles cache invalidation for NetterTechEvents.
 *
 * Centralizes all cache-related operations to ensure consistent
 * cache clearing across the plugin.
 *
 * @since 0.8.0
 * @api
 */
class CacheManager {

	/**
	 * Cache group name.
	 */
	public const CACHE_GROUP = 'nettertech_events';

	/**
	 * TTL for capacity data (seconds).
	 * Short TTL due to checkout sensitivity.
	 */
	public const TTL_CAPACITY = 60;

	/**
	 * TTL for occurrence queries (seconds).
	 */
	public const TTL_OCCURRENCE = 300;

	/**
	 * TTL for event metadata (seconds).
	 */
	public const TTL_EVENT = 3600;

	/**
	 * TTL for ticket type queries (seconds).
	 * Moderate TTL — ticket types change infrequently.
	 */
	public const TTL_TICKET_TYPE = 600;

	/**
	 * TTL for attendee statistics (seconds).
	 * Short TTL — check-in counts change during events.
	 */
	public const TTL_ATTENDEE_STATS = 120;

	/**
	 * Cache version for bulk invalidation.
	 */
	private const CACHE_VERSION_OPTION = 'nettertech_events_cache_version';

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Templates service.
	 *
	 * @since 1.6.0
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * House capacity repository.
	 *
	 * @var HouseCapacityRepositoryInterface
	 */
	private HouseCapacityRepositoryInterface $house_repo;

	/**
	 * Constructor.
	 *
	 * @param \wpdb                            $db         WordPress database instance.
	 * @param Templates                        $templates  Templates service.
	 * @param HouseCapacityRepositoryInterface $house_repo House capacity repository.
	 */
	public function __construct( \wpdb $db, Templates $templates, HouseCapacityRepositoryInterface $house_repo ) {
		$this->db         = $db;
		$this->templates  = $templates;
		$this->house_repo = $house_repo;
	}

	/**
	 * Register cache invalidation hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Targeted invalidation when a specific event is saved or deleted.
		add_action( 'nettertech_events_after_save_event', array( $this, 'on_after_save_event' ), 10, 1 );
		add_action( 'nettertech_events_after_delete_event', array( $this, 'on_after_delete_event' ), 10, 2 );

		// Targeted invalidation when occurrences are regenerated for an event.
		add_action( 'nettertech_events_occurrences_generated', array( $this, 'on_occurrences_generated' ), 10, 1 );

		// Full flush when occurrence status changes — affects calendar/listing cross-event caches.
		add_action( 'nettertech_events_occurrence_status_changed', array( $this, 'invalidate_all' ) );

		// Full flush when a single occurrence is created/deleted (NTE-068): timeframe-limited
		// taxonomy dropdowns may flip qualification as the only occurrence in a category/tag
		// appears or disappears. The recurrence-engine path is already covered by
		// nettertech_events_occurrences_generated above; these hooks cover one-off admin edits.
		add_action( 'nettertech_events_occurrence_created', array( $this, 'invalidate_all' ) );
		add_action( 'nettertech_events_occurrence_deleted', array( $this, 'invalidate_all' ) );

		// Full flush when attendees change — occurrence sold-count caches span events.
		add_action( 'nettertech_events_attendee_created', array( $this, 'invalidate_all' ) );
		add_action( 'nettertech_events_attendee_cancelled', array( $this, 'invalidate_all' ) );

		// Targeted capacity invalidation on capacity changes.
		add_action( 'nettertech_events_capacity_reserved', array( $this, 'invalidate_capacity_for_ticket_type' ), 10, 1 );
		add_action( 'nettertech_events_capacity_released', array( $this, 'invalidate_capacity_for_ticket_type' ), 10, 1 );
		add_action( 'nettertech_events_buffer_stock_updated', array( $this, 'invalidate_capacity_for_ticket_type' ), 10, 1 );
	}

	/**
	 * Hook handler: invalidate caches after an event is saved.
	 *
	 * @param \NetterTechEvents\Models\Event $event The saved event model.
	 * @return void
	 */
	public function on_after_save_event( \NetterTechEvents\Models\Event $event ): void {
		if ( null === $event->id ) {
			return;
		}
		$this->invalidate_event( $event->id );
	}

	/**
	 * Hook handler: invalidate caches after an event is deleted.
	 *
	 * Deletion removes the event from listings, so cross-event caches
	 * (calendar, listing) also need flushing.
	 *
	 * @param int                            $id    The deleted event ID.
	 * @param \NetterTechEvents\Models\Event $event The deleted event model (required by hook signature).
	 * @return void
	 */
	public function on_after_delete_event( int $id, \NetterTechEvents\Models\Event $event ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $event required by WordPress hook contract; only $id needed for cache key.
		$this->invalidate_event( $id, 'all' );
	}

	/**
	 * Hook handler: invalidate caches after occurrences are regenerated.
	 *
	 * @param \NetterTechEvents\Models\Event $event The parent event.
	 * @return void
	 */
	public function on_occurrences_generated( \NetterTechEvents\Models\Event $event ): void {
		if ( null === $event->id ) {
			return;
		}
		$this->invalidate_event( $event->id );
	}

	/**
	 * Invalidate all NetterTechEvents caches.
	 *
	 * Clears calendar/series transients and object cache group.
	 *
	 * @return void
	 */
	public function invalidate_all(): void {
		$db = $this->db;

		// Delete all NetterTechEvents transients (calendar, series, etc.).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $db->options is a safe table name.
		$sql = $db->prepare(
			"DELETE FROM {$db->options}
			WHERE option_name LIKE %s
			OR option_name LIKE %s",
			$db->esc_like( '_transient_nettertech_events_' ) . '%',
			$db->esc_like( '_transient_timeout_nettertech_events_' ) . '%'
		);
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
			$db->query( $sql );
		}

		// Clear object cache group (for sites with persistent object cache).
		wp_cache_flush_group( 'nettertech_events' );

		/**
		 * Fires after NetterTechEvents caches are invalidated.
		 *
		 * Allows third-party integrations to clear their own caches.
		 */
		do_action( 'nettertech_events_cache_invalidated' );
	}

	/**
	 * Build an event-specific transient key.
	 *
	 * Convention: nettertech_events_event_{id}_{suffix}
	 *
	 * @param int    $event_id Event ID.
	 * @param string $suffix   Key suffix identifying the cached data type.
	 * @return string Transient key.
	 */
	public function event_transient_key( int $event_id, string $suffix ): string {
		return 'nettertech_events_event_' . $event_id . '_' . $suffix;
	}

	/**
	 * Invalidate caches for a specific event.
	 *
	 * Scope 'event' (default): deletes only transients for this event
	 * (keys matching nettertech_events_event_{id}_*) and clears the object cache group.
	 *
	 * Scope 'all': performs a full flush via invalidate_all(). Use when the
	 * change also affects cross-event caches such as calendar or listing views
	 * (e.g. event deletion, status change).
	 *
	 * @param int    $event_id Event ID.
	 * @param string $scope    'event' for targeted invalidation (default), 'all' for full flush.
	 * @return void
	 */
	public function invalidate_event( int $event_id, string $scope = 'event' ): void {
		if ( 'all' === $scope ) {
			$this->invalidate_all();
			return;
		}

		$db = $this->db;

		// Delete legacy series transient preserved from earlier cache conventions.
		delete_transient( 'nettertech_events_series_' . $event_id );

		// Delete all event-specific transients matching the nettertech_events_event_{id}_* convention.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $db->options is a safe table name.
		$sql = $db->prepare(
			"DELETE FROM {$db->options}
			WHERE option_name LIKE %s
			OR option_name LIKE %s",
			$db->esc_like( '_transient_nettertech_events_event_' . $event_id . '_' ) . '%',
			$db->esc_like( '_transient_timeout_nettertech_events_event_' . $event_id . '_' ) . '%'
		);
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
			$db->query( $sql );
		}

		// Clear the shared object cache group so in-memory entries are evicted.
		wp_cache_flush_group( 'nettertech_events' );
	}

	/**
	 * Clear the template location cache.
	 *
	 * @return void
	 */
	public function clear_template_cache(): void {
		$this->templates->clear_template_cache();
	}

	// =========================================================================
	// Targeted Invalidation
	// =========================================================================

	/**
	 * Invalidate capacity caches for a specific ticket type.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return void
	 */
	public function invalidate_capacity_for_ticket_type( int $ticket_type_id ): void {
		// Selling a seat on one tier changes what every tier sharing the room has
		// left, so the whole house is stale, not just the tier that sold.
		$peer_ids = $this->house_repo->house_peer_ids( $ticket_type_id );

		if ( ! in_array( $ticket_type_id, $peer_ids, true ) ) {
			$peer_ids[] = $ticket_type_id;
		}

		foreach ( $peer_ids as $peer_id ) {
			wp_cache_delete( 'capacity_summary_' . $peer_id, self::CACHE_GROUP );
			wp_cache_delete( 'capacity_available_' . $peer_id, self::CACHE_GROUP );
			wp_cache_delete( 'capacity_available_' . $peer_id . '_pending', self::CACHE_GROUP );
		}

		/**
		 * Fires after capacity cache is invalidated for a ticket type.
		 *
		 * @param int $ticket_type_id Ticket type ID.
		 */
		do_action( 'nettertech_events_capacity_cache_invalidated', $ticket_type_id );
	}

	/**
	 * Invalidate capacity caches for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return void
	 */
	public function invalidate_capacity_for_occurrence( int $occurrence_id ): void {
		wp_cache_delete( 'capacity_occurrence_' . $occurrence_id, self::CACHE_GROUP );
		wp_cache_delete( 'ticket_types_occurrence_' . $occurrence_id, self::CACHE_GROUP );
	}

	/**
	 * Invalidate attendee caches for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return void
	 */
	public function invalidate_attendees_for_occurrence( int $occurrence_id ): void {
		wp_cache_delete( 'attendee_count_' . $occurrence_id, self::CACHE_GROUP );
		wp_cache_delete( 'attendee_stats_' . $occurrence_id, self::CACHE_GROUP );
	}

	/**
	 * Invalidate event object cache.
	 *
	 * @param int $event_id Event ID.
	 * @return void
	 */
	public function invalidate_event_cache( int $event_id ): void {
		wp_cache_delete( 'event_' . $event_id, self::CACHE_GROUP );
	}

	// =========================================================================
	// Cache Helpers
	// =========================================================================

	/**
	 * Get the current cache version.
	 *
	 * Used for bulk invalidation via version bump.
	 *
	 * @return int Cache version.
	 */
	public static function get_cache_version(): int {
		return (int) get_option( self::CACHE_VERSION_OPTION, 1 );
	}

	/**
	 * Bump the cache version for bulk invalidation.
	 *
	 * All caches using the version in their key become stale.
	 *
	 * @return void
	 */
	public static function bump_cache_version(): void {
		$version = self::get_cache_version();
		update_option( self::CACHE_VERSION_OPTION, $version + 1 );
	}

	/**
	 * Build a versioned cache key.
	 *
	 * @param string $base_key Base cache key.
	 * @return string Versioned cache key.
	 */
	public static function versioned_key( string $base_key ): string {
		return $base_key . '_v' . self::get_cache_version();
	}
}
