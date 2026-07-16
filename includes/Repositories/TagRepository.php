<?php
/**
 * Tag repository class.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\TagRepositoryInterface;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Database\Queries\Timeline;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Tag;
use NetterTechEvents\Utilities\DatabaseLogger;

/**
 * Handles Tag persistence and retrieval.
 *
 * @since 0.9.0
 * @api
 */
class TagRepository implements TagRepositoryInterface {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Tags table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Event tags junction table name.
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
		$this->table          = Schema::table( 'tags' );
		$this->junction_table = Schema::table( 'event_tags' );
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
	 * Find a tag by ID.
	 *
	 * @param int $id Tag ID.
	 * @return Tag|null
	 */
	public function find( int $id ): ?Tag {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$id
			)
		);

		return $row ? Tag::from_row( $row ) : null;
	}

	/**
	 * Find a tag by slug.
	 *
	 * @param string $slug Tag slug.
	 * @return Tag|null
	 */
	public function find_by_slug( string $slug ): ?Tag {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE slug = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$slug
			)
		);

		return $row ? Tag::from_row( $row ) : null;
	}

	/**
	 * Save a tag (insert or update).
	 *
	 * @param Tag $tag Tag to save.
	 * @return Tag The saved tag with ID populated.
	 * @throws ValidationException If validation fails.
	 * @throws DatabaseException   If save fails.
	 */
	public function save( Tag $tag ): Tag {
		$errors = $tag->validate();
		if ( ! empty( $errors ) ) {
			throw ValidationException::fromErrors( array_map( 'esc_html', $errors ) );
		}

		$data    = $tag->to_array();
		$formats = $tag->get_formats();

		if ( null === $tag->id ) {
			$result = $this->db->insert( $this->table, $data, $formats );

			if ( false === $result ) {
				throw DatabaseException::insertFailed( 'tag', esc_html( $this->format_db_error( 'insert', 'tag' ) ) );
			}

			$tag->id = (int) $this->db->insert_id;
		} else {
			$result = $this->db->update(
				$this->table,
				$data,
				array( 'id' => $tag->id ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				throw DatabaseException::updateFailed( 'tag', (int) $tag->id, esc_html( $this->format_db_error( 'update', 'tag' ) ) );
			}
		}

		return $tag;
	}

	/**
	 * Delete a tag.
	 *
	 * @param int $id Tag ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool {
		// Remove from all events.
		$this->db->delete(
			$this->junction_table,
			array( 'tag_id' => $id ),
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
	 * Get all tags, optionally limited to those with events in a timeframe.
	 *
	 * When $timeframe is 'upcoming' or 'past', the result set is restricted to
	 * tags attached (via the event_tags junction) to at least one event that
	 * has a scheduled occurrence in that timeframe. Tags with no qualifying
	 * events are excluded. Hourly cache-key granularity ensures that the
	 * natural passage of time eventually re-evaluates which terms still
	 * qualify, even if no save hook fires.
	 *
	 * @since 0.9.0
	 * @since 1.1.0 Added $timeframe parameter (NTE-068).
	 *
	 * @param array<string, mixed> $args      Query arguments.
	 * @param string|null          $timeframe Timeframe predicate: null, 'upcoming', or 'past'.
	 * @return array<Tag>
	 */
	public function get_all( array $args = array(), ?string $timeframe = null ): array {
		$defaults = array(
			'orderby' => 'name',
			'order'   => 'ASC',
			'limit'   => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$timeframe_key = $this->normalize_timeframe( $timeframe );

		// Cache lookup — tags rarely change. Hourly granularity bucket for
		// timeframed queries so the cache eventually expires as time passes.
		$cache_args              = $args;
		$cache_args['timeframe'] = $timeframe_key;
		if ( null !== $timeframe_key ) {
			$cache_args['_hour'] = gmdate( 'Y-m-d-H' );
		}
		$cache_key = 'tags_all_' . md5( (string) wp_json_encode( $cache_args ) );
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$orderby_col = in_array( $args['orderby'], array( 'name', 'slug', 'created_at' ), true ) ? $args['orderby'] : 'name';
		$order       = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$limit_sql   = $args['limit'] > 0 ? $this->db->prepare( ' LIMIT %d', $args['limit'] ) : '';

		if ( null === $timeframe_key ) {
			$sanitized = sanitize_sql_orderby( "{$orderby_col} {$order}" );
			$orderby   = $sanitized ? $sanitized : 'name ASC';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $orderby via sanitize_sql_orderby(), $limit_sql prepared.
			$rows = $this->db->get_results( "SELECT * FROM {$this->table} ORDER BY {$orderby}{$limit_sql}" ) ?? array();
		} else {
			$events_table      = Schema::table( 'events' );
			$occurrences_table = Schema::table( 'occurrences' );
			$timeframe         = ( 'past' === $timeframe_key ) ? Timeline::ended() : Timeline::not_ended();
			$sanitized         = sanitize_sql_orderby( "t.{$orderby_col} {$order}" );
			$orderby           = $sanitized ? $sanitized : 't.name ASC';
			$prepared_where    = $this->db->prepare( $timeframe, Timeline::now() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fragment is a constant from Timeline; the value is bound here.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from Schema; $prepared_where prepared above; $orderby via sanitize_sql_orderby(); $limit_sql prepared.
			$rows = $this->db->get_results(
				"SELECT DISTINCT t.*
				FROM {$this->table} t
				INNER JOIN {$this->junction_table} et ON t.id = et.tag_id
				INNER JOIN {$events_table} e ON et.event_id = e.id
				INNER JOIN {$occurrences_table} o ON o.event_id = e.id
				WHERE {$prepared_where} AND o.status = 'scheduled'
				ORDER BY {$orderby}{$limit_sql}"
			) ?? array();
		}

		$tags = array_map( array( Tag::class, 'from_row' ), $rows );

		wp_cache_set( $cache_key, $tags, CacheManager::CACHE_GROUP, CacheManager::TTL_EVENT );

		return $tags;
	}

	/**
	 * Normalize a timeframe argument to one of: null, 'upcoming', 'past'.
	 *
	 * Unknown / invalid strings collapse to null (unfiltered) so a caller
	 * mistake doesn't silently produce a zero-row result.
	 *
	 * @param string|null $timeframe Raw timeframe.
	 * @return string|null
	 */
	private function normalize_timeframe( ?string $timeframe ): ?string {
		if ( null === $timeframe ) {
			return null;
		}
		$value = strtolower( trim( $timeframe ) );
		if ( in_array( $value, array( 'upcoming', 'past' ), true ) ) {
			return $value;
		}
		return null;
	}

	/**
	 * Get tags for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<Tag>
	 */
	public function find_by_event( int $event_id ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names cannot be parameterized.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT t.*
				FROM {$this->table} t
				INNER JOIN {$this->junction_table} et ON t.id = et.tag_id
				WHERE et.event_id = %d
				ORDER BY t.name ASC",
				$event_id
			)
		) ?? array();

		return array_map( array( Tag::class, 'from_row' ), $rows );
	}

	/**
	 * Get tags for multiple events in a single query.
	 *
	 * N+1 prevention for grid/list renderers. Returns a map keyed by
	 * event_id; events with no tags are absent from the returned array.
	 *
	 * @since 1.0.3
	 *
	 * @param array<int> $event_ids Event IDs.
	 * @return array<int, array<Tag>> Map of event_id => list of tags.
	 */
	public function find_by_event_ids( array $event_ids ): array {
		if ( empty( $event_ids ) ) {
			return array();
		}

		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );

		if ( empty( $event_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names cannot be parameterized; placeholders generated from sanitized int IDs and bound via prepare().
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT t.*, et.event_id AS _nte_event_id
				FROM {$this->table} t
				INNER JOIN {$this->junction_table} et ON t.id = et.tag_id
				WHERE et.event_id IN ({$placeholders})
				ORDER BY et.event_id ASC, t.name ASC",
				$event_ids
			)
		) ?? array();

		$grouped = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$event_id               = (int) $row->_nte_event_id;
			$grouped[ $event_id ]   = $grouped[ $event_id ] ?? array();
			$grouped[ $event_id ][] = Tag::from_row( $row );
		}

		return $grouped;
	}

	/**
	 * Search tags by name.
	 *
	 * @param string $query Search query.
	 * @param int    $limit Maximum results.
	 * @return array<Tag>
	 */
	public function search( string $query, int $limit = 10 ): array {
		$like = '%' . $this->db->esc_like( $query ) . '%';

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE name LIKE %s ORDER BY name ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$like,
				$limit
			)
		) ?? array();

		return array_map( array( Tag::class, 'from_row' ), $rows );
	}

	/**
	 * Attach a tag to an event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $tag_id   Tag ID.
	 * @return bool True on success.
	 */
	public function attach_to_event( int $event_id, int $tag_id ): bool {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized.
		$sql = $this->db->prepare(
			"INSERT IGNORE INTO {$this->junction_table} (event_id, tag_id)
			VALUES (%d, %d)",
			$event_id,
			$tag_id
		);
		if ( null === $sql ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$result = $this->db->query( $sql );

		return false !== $result;
	}

	/**
	 * Detach a tag from an event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $tag_id   Tag ID.
	 * @return bool True on success.
	 */
	public function detach_from_event( int $event_id, int $tag_id ): bool {
		$result = $this->db->delete(
			$this->junction_table,
			array(
				'event_id' => $event_id,
				'tag_id'   => $tag_id,
			),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Sync tags for an event.
	 *
	 * @param int        $event_id Event ID.
	 * @param array<int> $tag_ids  List of tag IDs.
	 * @return bool True on success.
	 */
	public function sync_event_tags( int $event_id, array $tag_ids ): bool {
		// Remove all existing tags for this event.
		$this->db->delete(
			$this->junction_table,
			array( 'event_id' => $event_id ),
			array( '%d' )
		);

		// Add new tags.
		foreach ( $tag_ids as $tag_id ) {
			$this->attach_to_event( $event_id, $tag_id );
		}

		return true;
	}

	/**
	 * Find or create a tag by name.
	 *
	 * @param string $name Tag name.
	 * @return Tag The found or created tag.
	 */
	public function find_or_create( string $name ): Tag {
		$slug = sanitize_title( $name );
		$tag  = $this->find_by_slug( $slug );

		if ( $tag ) {
			return $tag;
		}

		$tag       = new Tag();
		$tag->name = $name;
		$tag->slug = $slug;

		return $this->save( $tag );
	}
}
