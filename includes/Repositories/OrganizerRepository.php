<?php
/**
 * Organizer repository class.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Organizer;
use NetterTechEvents\Utilities\DatabaseLogger;

/**
 * Handles Organizer persistence and retrieval.
 *
 * @since 0.9.0
 * @api
 */
class OrganizerRepository implements OrganizerRepositoryInterface {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Organizers table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Event organizers junction table name.
	 *
	 * @var string
	 */
	private string $junction_table;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database instance.
	 */
	public function __construct( \wpdb $db ) {
		$this->db             = $db;
		$this->table          = Schema::table( 'organizers' );
		$this->junction_table = Schema::table( 'event_organizers' );
	}

	/**
	 * Format database error for exceptions.
	 *
	 * @param string $operation The operation that failed.
	 * @param string $entity    The entity type.
	 * @return string Safe error message.
	 */
	private function format_db_error( string $operation, string $entity ): string {
		// Log the error with appropriate detail level (sanitized in production).
		if ( $this->db->last_error ) {
			DatabaseLogger::log_error( $operation, $entity, $this->db->last_error );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return sprintf(
				/* translators: 1: operation (insert/update), 2: entity type, 3: error message */
				esc_html__( 'Failed to %1$s %2$s: %3$s', 'nettertech-events' ),
				$operation,
				$entity,
				esc_html( $this->db->last_error )
			);
		}

		return sprintf(
			/* translators: 1: operation (insert/update), 2: entity type */
			esc_html__( 'Failed to %1$s %2$s. Please try again or contact support.', 'nettertech-events' ),
			$operation,
			$entity
		);
	}

	/**
	 * Find an organizer by ID.
	 *
	 * Uses persistent object cache for cross-request performance.
	 *
	 * @param int $id Organizer ID.
	 * @return Organizer|null
	 */
	public function find( int $id ): ?Organizer {
		$cache_key = 'organizer_' . $id;
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );
		if ( false !== $cached ) {
			return Organizer::from_row( $cached );
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

		wp_cache_set( $cache_key, $row, CacheManager::CACHE_GROUP, CacheManager::TTL_EVENT );

		return Organizer::from_row( $row );
	}

	/**
	 * Find an organizer by slug.
	 *
	 * Uses persistent object cache keyed by slug.
	 *
	 * @param string $slug Organizer slug.
	 * @return Organizer|null
	 */
	public function find_by_slug( string $slug ): ?Organizer {
		$cache_key = 'organizer_slug_' . $slug;
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );
		if ( false !== $cached ) {
			return Organizer::from_row( $cached );
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

		wp_cache_set( $cache_key, $row, CacheManager::CACHE_GROUP, CacheManager::TTL_EVENT );

		return Organizer::from_row( $row );
	}

	/**
	 * Save an organizer (insert or update).
	 *
	 * @param Organizer $organizer Organizer to save.
	 * @return Organizer The saved organizer with ID populated.
	 * @throws ValidationException If validation fails.
	 * @throws DatabaseException   If save fails.
	 */
	public function save( Organizer $organizer ): Organizer {
		$errors = $organizer->validate();
		if ( ! empty( $errors ) ) {
			throw ValidationException::fromErrors( array_map( 'esc_html', $errors ) );
		}

		$data    = $organizer->to_array();
		$formats = $organizer->get_formats();

		if ( null === $organizer->id ) {
			$result = $this->db->insert( $this->table, $data, $formats );

			if ( false === $result ) {
				throw DatabaseException::insertFailed( 'organizer', esc_html( $this->format_db_error( 'insert', 'organizer' ) ) );
			}

			$organizer->id = (int) $this->db->insert_id;
		} else {
			$result = $this->db->update(
				$this->table,
				$data,
				array( 'id' => $organizer->id ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				throw DatabaseException::updateFailed( 'organizer', (int) $organizer->id, esc_html( $this->format_db_error( 'update', 'organizer' ) ) );
			}
		}

		// Invalidate caches for this organizer.
		if ( $organizer->id ) {
			wp_cache_delete( 'organizer_' . $organizer->id, CacheManager::CACHE_GROUP );
			if ( ! empty( $organizer->slug ) ) {
				wp_cache_delete( 'organizer_slug_' . $organizer->slug, CacheManager::CACHE_GROUP );
			}
		}

		return $organizer;
	}

	/**
	 * Delete an organizer.
	 *
	 * @param int $id Organizer ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool {
		// Invalidate cache before deletion.
		wp_cache_delete( 'organizer_' . $id, CacheManager::CACHE_GROUP );

		// First remove from all events.
		$this->db->delete(
			$this->junction_table,
			array( 'organizer_id' => $id ),
			array( '%d' )
		);

		$result = $this->db->delete(
			$this->table,
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get all organizers.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<Organizer>
	 */
	public function get_all( array $args = array() ): array {
		$defaults = array(
			'orderby' => 'name',
			'order'   => 'ASC',
			'limit'   => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$orderby_col = in_array( $args['orderby'], array( 'name', 'slug', 'created_at' ), true ) ? $args['orderby'] : 'name';
		$order       = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$sanitized   = sanitize_sql_orderby( "{$orderby_col} {$order}" );
		$orderby     = $sanitized ? $sanitized : 'name ASC';
		$limit_sql   = $args['limit'] > 0 ? $this->db->prepare( ' LIMIT %d', $args['limit'] ) : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $orderby via sanitize_sql_orderby(), $limit_sql prepared.
		$rows = $this->db->get_results( "SELECT * FROM {$this->table} ORDER BY {$orderby}{$limit_sql}" ) ?? array();

		return array_map( array( Organizer::class, 'from_row' ), $rows );
	}

	/**
	 * Get organizers for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<Organizer>
	 */
	public function find_by_event( int $event_id ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names cannot be parameterized.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT o.*, eo.is_primary, eo.sort_order
				FROM {$this->table} o
				INNER JOIN {$this->junction_table} eo ON o.id = eo.organizer_id
				WHERE eo.event_id = %d
				ORDER BY eo.is_primary DESC, eo.sort_order ASC, o.name ASC",
				$event_id
			)
		) ?? array();

		return array_map( array( Organizer::class, 'from_row' ), $rows );
	}

	/**
	 * Attach an organizer to an event.
	 *
	 * @param int  $event_id     Event ID.
	 * @param int  $organizer_id Organizer ID.
	 * @param bool $is_primary   Whether this is the primary organizer.
	 * @param int  $sort_order   Sort order.
	 * @return bool True on success.
	 */
	public function attach_to_event( int $event_id, int $organizer_id, bool $is_primary = false, int $sort_order = 0 ): bool {
		// Use INSERT IGNORE to handle duplicates gracefully.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized.
		$sql = $this->db->prepare(
			"INSERT IGNORE INTO {$this->junction_table} (event_id, organizer_id, is_primary, sort_order)
			VALUES (%d, %d, %d, %d)",
			$event_id,
			$organizer_id,
			$is_primary ? 1 : 0,
			$sort_order
		);
		if ( null === $sql ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$result = $this->db->query( $sql );

		return false !== $result;
	}

	/**
	 * Detach an organizer from an event.
	 *
	 * @param int $event_id     Event ID.
	 * @param int $organizer_id Organizer ID.
	 * @return bool True on success.
	 */
	public function detach_from_event( int $event_id, int $organizer_id ): bool {
		$result = $this->db->delete(
			$this->junction_table,
			array(
				'event_id'     => $event_id,
				'organizer_id' => $organizer_id,
			),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get event counts for all organizers in a single query.
	 *
	 * @return array<int, int> Map of organizer_id => event_count.
	 */
	public function get_event_counts(): array {
		$cache_key = 'organizer_event_counts';
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized.
		$rows = $this->db->get_results(
			"SELECT organizer_id, COUNT(*) AS event_count FROM {$this->junction_table} GROUP BY organizer_id"
		) ?? array();

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row->organizer_id ] = (int) $row->event_count;
		}

		wp_cache_set( $cache_key, $counts, CacheManager::CACHE_GROUP, CacheManager::TTL_EVENT );

		return $counts;
	}

	/**
	 * Sync organizers for an event.
	 *
	 * @param int                                                            $event_id   Event ID.
	 * @param array<int|array{id: int, is_primary?: bool, sort_order?: int}> $organizers Organizer IDs or config arrays.
	 * @return bool True on success.
	 */
	public function sync_event_organizers( int $event_id, array $organizers ): bool {
		// Remove all existing organizers for this event.
		$this->db->delete(
			$this->junction_table,
			array( 'event_id' => $event_id ),
			array( '%d' )
		);

		// Add new organizers.
		foreach ( $organizers as $index => $organizer ) {
			if ( is_array( $organizer ) ) {
				$organizer_id = $organizer['id'];
				$is_primary   = $organizer['is_primary'] ?? false;
				$sort_order   = $organizer['sort_order'] ?? $index;
			} else {
				$organizer_id = $organizer;
				$is_primary   = 0 === $index; // First one is primary by default.
				$sort_order   = $index;
			}

			$this->attach_to_event( $event_id, $organizer_id, $is_primary, $sort_order );
		}

		return true;
	}
}
