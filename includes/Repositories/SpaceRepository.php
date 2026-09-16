<?php
/**
 * Space repository class.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\SpaceRepositoryInterface;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Space;
use NetterTechEvents\Traits\IdentityMapTrait;
use NetterTechEvents\Utilities\DatabaseLogger;

/**
 * Handles Space persistence and retrieval.
 *
 * Uses soft-delete pattern: delete() sets status to 'archived' rather than
 * removing the row. Paginate/count exclude archived spaces by default.
 *
 * @since 2.1.0
 * @api
 */
class SpaceRepository implements SpaceRepositoryInterface {

	use IdentityMapTrait;

	/**
	 * Columns used in list/paginate queries.
	 *
	 * Excludes TEXT/LONGTEXT fields (description, amenities, gallery_image_ids)
	 * for performance. Use find() to load full record.
	 *
	 * @var string
	 */
	private const LIST_COLUMNS = 'id, name, slug, tagline, capacity, square_footage, featured_image_id, sort_order, status, seating_model, door_sales, created_at, updated_at';

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Spaces table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database instance.
	 */
	public function __construct( \wpdb $db ) {
		$this->db    = $db;
		$this->table = Schema::table( 'spaces' );
	}

	/**
	 * Format database error for exceptions.
	 *
	 * In production (WP_DEBUG false), returns a generic message to avoid
	 * leaking database schema information. In development, includes the
	 * actual error for debugging.
	 *
	 * @param string $operation The operation that failed (e.g., 'insert', 'update').
	 * @param string $entity    The entity type (e.g., 'space').
	 * @return string Safe error message.
	 */
	private function format_db_error( string $operation, string $entity ): string {
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
	 * Find a space by ID.
	 *
	 * Uses identity map for per-request deduplication and persistent
	 * object cache for cross-request performance.
	 *
	 * @param int $id Space ID.
	 * @return Space|null
	 */
	public function find( int $id ): ?Space {
		$cached_space = $this->recalled( $id );
		if ( $cached_space instanceof Space ) {
			return $cached_space;
		}

		$cache_key = 'space_' . $id;
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );
		if ( false !== $cached ) {
			$space = Space::from_row( $cached );
			$this->remember( $id, $space );
			return $space;
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

		$space = Space::from_row( $row );
		$this->remember( $id, $space );

		wp_cache_set( $cache_key, $row, CacheManager::CACHE_GROUP, CacheManager::TTL_EVENT );

		return $space;
	}

	/**
	 * Find a space by slug.
	 *
	 * Uses persistent object cache keyed by slug.
	 *
	 * @param string $slug Space slug.
	 * @return Space|null
	 */
	public function find_by_slug( string $slug ): ?Space {
		$cache_key = 'space_slug_' . $slug;
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );
		if ( false !== $cached ) {
			$space = Space::from_row( $cached );
			if ( $space->id ) {
				$this->remember( $space->id, $space );
			}
			return $space;
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

		$space = Space::from_row( $row );
		if ( $space->id ) {
			$this->remember( $space->id, $space );
		}

		return $space;
	}

	/**
	 * Save a space (insert or update).
	 *
	 * If 'id' key is present and non-null in $data, performs an update.
	 * Otherwise performs an insert. Returns the space ID.
	 *
	 * @param array<string, mixed> $data Space data.
	 * @return int Space ID.
	 * @throws ValidationException If validation fails.
	 * @throws DatabaseException   If save fails.
	 */
	public function save( array $data ): int {
		$space = Space::from_row( $data );

		// Preserve the explicit ID for updates.
		if ( isset( $data['id'] ) ) {
			$space->id = (int) $data['id'];
		}

		$errors = $space->validate();
		if ( ! empty( $errors ) ) {
			throw ValidationException::fromErrors( array_map( 'esc_html', $errors ) );
		}

		$row_data = $space->to_array();
		$formats  = $space->get_formats();

		if ( null === $space->id ) {
			$result = $this->db->insert( $this->table, $row_data, $formats );

			if ( false === $result ) {
				throw DatabaseException::insertFailed( 'space', esc_html( $this->format_db_error( 'insert', 'space' ) ) );
			}

			$space->id = (int) $this->db->insert_id;
		} else {
			$result = $this->db->update(
				$this->table,
				$row_data,
				array( 'id' => $space->id ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				throw DatabaseException::updateFailed( 'space', (int) $space->id, esc_html( $this->format_db_error( 'update', 'space' ) ) );
			}
		}

		$this->invalidate_space_cache( $space->id, $space->slug );

		return $space->id;
	}

	/**
	 * Soft-delete a space (set status to archived).
	 *
	 * @param int $id Space ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool {
		$space = $this->find( $id );

		$result = $this->db->update(
			$this->table,
			array( 'status' => 'archived' ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			$slug = $space ? $space->slug : '';
			$this->invalidate_space_cache( $id, $slug );
		}

		return false !== $result;
	}

	/**
	 * Get paginated, filtered list of spaces.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<Space>
	 */
	public function paginate( array $args = array() ): array {
		$defaults = array(
			'search'  => '',
			'status'  => 'active',
			'orderby' => 'sort_order',
			'order'   => 'ASC',
			'limit'   => 20,
			'offset'  => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array();
		$values = array();

		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( '' !== $args['search'] ) {
			$search_term = '%' . $this->db->esc_like( $args['search'] ) . '%';
			$where[]     = '(name LIKE %s OR slug LIKE %s)';
			$values[]    = $search_term;
			$values[]    = $search_term;
		}

		$where_clause = '';
		if ( ! empty( $where ) ) {
			$where_clause = 'WHERE ' . implode( ' AND ', $where );
		}

		$allowed_orderby = array( 'name', 'slug', 'capacity', 'sort_order', 'status', 'created_at' );
		$orderby_col     = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'sort_order';
		$order           = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$sanitized       = sanitize_sql_orderby( "{$orderby_col} {$order}" );
		$orderby         = $sanitized ? $sanitized : 'sort_order ASC';

		$values[] = (int) $args['limit'];
		$values[] = (int) $args['offset'];

		$columns = self::LIST_COLUMNS;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name/columns/orderby from trusted source.
		$sql = $this->db->prepare(
			"SELECT {$columns} FROM {$this->table} {$where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d",
			$values
		);

		$rows = $this->db->get_results( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( Space::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Check if a slug is unique.
	 *
	 * @param string   $slug       Slug to check.
	 * @param int|null $exclude_id Space ID to exclude (for updates).
	 * @return bool True if slug is unique.
	 */
	public function is_slug_unique( string $slug, ?int $exclude_id = null ): bool {
		$sql    = "SELECT COUNT(*) FROM {$this->table} WHERE slug = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
		$values = array( $slug );

		if ( null !== $exclude_id ) {
			$sql     .= ' AND id != %d';
			$values[] = $exclude_id;
		}

		return 0 === (int) $this->db->get_var( $this->db->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().
	}

	/**
	 * Count spaces matching filter criteria.
	 *
	 * @param array<string, mixed> $args Filter arguments (search, status).
	 * @return int
	 */
	public function count( array $args = array() ): int {
		$defaults = array(
			'search' => '',
			'status' => 'active',
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array();
		$values = array();

		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( '' !== $args['search'] ) {
			$search_term = '%' . $this->db->esc_like( $args['search'] ) . '%';
			$where[]     = '(name LIKE %s OR slug LIKE %s)';
			$values[]    = $search_term;
			$values[]    = $search_term;
		}

		$where_clause = '';
		if ( ! empty( $where ) ) {
			$where_clause = 'WHERE ' . implode( ' AND ', $where );
		}

		if ( empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No user input; trusted where clause.
			return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->table} {$where_clause}" );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name/where clause from trusted source.
		$sql = $this->db->prepare(
			"SELECT COUNT(*) FROM {$this->table} {$where_clause}",
			$values
		);

		return (int) $this->db->get_var( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Invalidate cached data for a space.
	 *
	 * @param int    $id   Space ID.
	 * @param string $slug Space slug.
	 * @return void
	 */
	private function invalidate_space_cache( int $id, string $slug ): void {
		$this->forget( $id );
		wp_cache_delete( 'space_' . $id, CacheManager::CACHE_GROUP );
		if ( '' !== $slug ) {
			wp_cache_delete( 'space_slug_' . $slug, CacheManager::CACHE_GROUP );
		}
	}
}
