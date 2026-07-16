<?php
/**
 * Category repository class.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Database\Queries\Timeline;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Category;
use NetterTechEvents\Utilities\DatabaseLogger;

/**
 * Handles Category persistence and retrieval.
 *
 * Supports hierarchical categories with parent/child relationships.
 *
 * @since 0.9.0
 * @api
 */
class CategoryRepository implements CategoryRepositoryInterface {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Categories table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Event categories junction table name.
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
		$this->table          = Schema::table( 'categories' );
		$this->junction_table = Schema::table( 'event_categories' );
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
	 * Find a category by ID.
	 *
	 * @param int $id Category ID.
	 * @return Category|null
	 */
	public function find( int $id ): ?Category {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$id
			)
		);

		return $row ? Category::from_row( $row ) : null;
	}

	/**
	 * Find a category by slug.
	 *
	 * @param string $slug Category slug.
	 * @return Category|null
	 */
	public function find_by_slug( string $slug ): ?Category {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE slug = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$slug
			)
		);

		return $row ? Category::from_row( $row ) : null;
	}

	/**
	 * Save a category (insert or update).
	 *
	 * @param Category $category Category to save.
	 * @return Category The saved category with ID populated.
	 * @throws ValidationException If validation fails.
	 * @throws DatabaseException   If save fails.
	 */
	public function save( Category $category ): Category {
		$errors = $category->validate();
		if ( ! empty( $errors ) ) {
			throw ValidationException::fromErrors( array_map( 'esc_html', $errors ) );
		}

		$data    = $category->to_array();
		$formats = $category->get_formats();

		if ( null === $category->id ) {
			$result = $this->db->insert( $this->table, $data, $formats );

			if ( false === $result ) {
				throw DatabaseException::insertFailed( 'category', esc_html( $this->format_db_error( 'insert', 'category' ) ) );
			}

			$category->id = (int) $this->db->insert_id;
		} else {
			$result = $this->db->update(
				$this->table,
				$data,
				array( 'id' => $category->id ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				throw DatabaseException::updateFailed( 'category', (int) $category->id, esc_html( $this->format_db_error( 'update', 'category' ) ) );
			}
		}

		wp_cache_flush_group( CacheManager::CACHE_GROUP );

		return $category;
	}

	/**
	 * Delete a category.
	 *
	 * @param int $id Category ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool {
		// Update children to have no parent.
		$this->db->update(
			$this->table,
			array( 'parent_id' => null ),
			array( 'parent_id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		// Remove from all events.
		$this->db->delete(
			$this->junction_table,
			array( 'category_id' => $id ),
			array( '%d' )
		);

		$result = $this->db->delete(
			$this->table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false !== $result ) {
			wp_cache_flush_group( CacheManager::CACHE_GROUP );
		}

		return false !== $result;
	}

	/**
	 * Get all categories, optionally limited to those with events in a timeframe.
	 *
	 * When $timeframe is 'upcoming' or 'past', the result set is restricted to
	 * categories attached (via the event_categories junction) to at least one
	 * event that has a scheduled occurrence in that timeframe. Categories with
	 * no qualifying events are excluded. Hourly cache-key granularity ensures
	 * that the natural passage of time eventually re-evaluates which terms
	 * still qualify, even if no save hook fires.
	 *
	 * @since 0.9.0
	 * @since 1.1.0 Added $timeframe parameter (NTE-068).
	 *
	 * @param array<string, mixed> $args      Query arguments.
	 * @param string|null          $timeframe Timeframe predicate: null, 'upcoming', or 'past'.
	 * @return array<Category>
	 */
	public function get_all( array $args = array(), ?string $timeframe = null ): array {
		$defaults = array(
			'orderby'   => 'sort_order',
			'order'     => 'ASC',
			'parent_id' => null, // null = all, 0 = top-level only.
			'limit'     => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$timeframe_key = $this->normalize_timeframe( $timeframe );

		// Cache lookup — categories rarely change. Hourly granularity bucket
		// for timeframed queries so the cache eventually expires as time passes.
		$cache_args              = $args;
		$cache_args['timeframe'] = $timeframe_key;
		if ( null !== $timeframe_key ) {
			$cache_args['_hour'] = gmdate( 'Y-m-d-H' );
		}
		$cache_key = 'categories_all_' . md5( (string) wp_json_encode( $cache_args ) );
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$where = array();

		if ( 0 === $args['parent_id'] ) {
			$where[] = 'c.parent_id IS NULL';
		} elseif ( null !== $args['parent_id'] ) {
			$where[] = $this->db->prepare( 'c.parent_id = %d', $args['parent_id'] );
		}

		$orderby_col = in_array( $args['orderby'], array( 'name', 'slug', 'sort_order', 'created_at' ), true ) ? $args['orderby'] : 'sort_order';
		$order       = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$sanitized   = sanitize_sql_orderby( "c.{$orderby_col} {$order}" );
		$orderby     = $sanitized ? $sanitized : 'c.sort_order ASC';
		$limit_sql   = $args['limit'] > 0 ? $this->db->prepare( ' LIMIT %d', $args['limit'] ) : '';

		if ( null === $timeframe_key ) {
			// Unfiltered path: legacy SELECT, no aliasing required.
			$bare_where = array();
			if ( 0 === $args['parent_id'] ) {
				$bare_where[] = 'parent_id IS NULL';
			} elseif ( null !== $args['parent_id'] ) {
				$bare_where[] = $this->db->prepare( 'parent_id = %d', $args['parent_id'] );
			}
			$where_sql         = ! empty( $bare_where ) ? 'WHERE ' . implode( ' AND ', $bare_where ) : '';
			$orderby_sanitized = sanitize_sql_orderby( "{$orderby_col} {$order}" );
			$orderby_bare      = $orderby_sanitized ? $orderby_sanitized : 'sort_order ASC';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql prepared, $orderby_bare via sanitize_sql_orderby(), $limit_sql prepared.
			$rows = $this->db->get_results( "SELECT * FROM {$this->table} {$where_sql} ORDER BY {$orderby_bare}{$limit_sql}" ) ?? array();
		} else {
			$events_table      = Schema::table( 'events' );
			$occurrences_table = Schema::table( 'occurrences' );
			$timeframe         = ( 'past' === $timeframe_key ) ? Timeline::ended() : Timeline::not_ended();
			$where[]           = $this->db->prepare( $timeframe, Timeline::now() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fragment is a constant from Timeline; the value is bound here.
			$where[]           = "o.status = 'scheduled'";
			$where_sql         = 'WHERE ' . implode( ' AND ', $where );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from Schema; $where_sql is prepared above; $orderby via sanitize_sql_orderby(); $limit_sql prepared.
			$rows = $this->db->get_results(
				"SELECT DISTINCT c.*
				FROM {$this->table} c
				INNER JOIN {$this->junction_table} ec ON c.id = ec.category_id
				INNER JOIN {$events_table} e ON ec.event_id = e.id
				INNER JOIN {$occurrences_table} o ON o.event_id = e.id
				{$where_sql}
				ORDER BY {$orderby}{$limit_sql}"
			) ?? array();
		}

		$categories = array_map( array( Category::class, 'from_row' ), $rows );

		wp_cache_set( $cache_key, $categories, CacheManager::CACHE_GROUP, CacheManager::TTL_EVENT );

		return $categories;
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
	 * Get categories for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<Category>
	 */
	public function find_by_event( int $event_id ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names cannot be parameterized.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT c.*, ec.is_primary
				FROM {$this->table} c
				INNER JOIN {$this->junction_table} ec ON c.id = ec.category_id
				WHERE ec.event_id = %d
				ORDER BY ec.is_primary DESC, c.sort_order ASC, c.name ASC",
				$event_id
			)
		) ?? array();

		return array_map( array( Category::class, 'from_row' ), $rows );
	}

	/**
	 * Get child categories.
	 *
	 * @param int|null $parent_id Parent category ID, or null for top-level.
	 * @return array<Category>
	 */
	public function find_children( ?int $parent_id = null ): array {
		if ( null === $parent_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized.
			$rows = $this->db->get_results( "SELECT * FROM {$this->table} WHERE parent_id IS NULL ORDER BY sort_order ASC, name ASC" ) ?? array();
		} else {
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$this->table} WHERE parent_id = %d ORDER BY sort_order ASC, name ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
					$parent_id
				)
			) ?? array();
		}

		return array_map( array( Category::class, 'from_row' ), $rows );
	}

	/**
	 * Get category tree (hierarchical structure).
	 *
	 * @return array<array{category: Category, children: array<mixed>}>
	 */
	public function get_tree(): array {
		$all_categories = $this->get_all();
		$tree           = array();
		$by_parent      = array();

		// Group by parent.
		foreach ( $all_categories as $category ) {
			$parent_key                 = $category->parent_id ?? 0;
			$by_parent[ $parent_key ][] = $category;
		}

		// Build tree recursively starting from top-level.
		$tree = $this->build_tree_branch( $by_parent, 0 );

		return $tree;
	}

	/**
	 * Build a branch of the category tree.
	 *
	 * @param array<int, array<Category>> $by_parent Categories grouped by parent ID.
	 * @param int                         $parent_id Current parent ID.
	 * @return array<array{category: Category, children: array<mixed>}>
	 */
	private function build_tree_branch( array $by_parent, int $parent_id ): array {
		$branch = array();

		$children = $by_parent[ $parent_id ] ?? array();
		foreach ( $children as $category ) {
			$branch[] = array(
				'category' => $category,
				'children' => $this->build_tree_branch( $by_parent, $category->id ?? 0 ),
			);
		}

		return $branch;
	}


	/**
	 * Get the number of events assigned to a category.
	 *
	 * @param int $category_id Category ID.
	 * @return int Event count.
	 */
	public function get_event_count( int $category_id ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized.
		$count = $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->junction_table} WHERE category_id = %d",
				$category_id
			)
		);

		return (int) $count;
	}


	/**
	 * Get event counts for all categories in a single query.
	 *
	 * @return array<int, int> Map of category_id => event_count.
	 */
	public function get_event_counts(): array {
		$cache_key = 'category_event_counts';
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized.
		$rows = $this->db->get_results(
			"SELECT category_id, COUNT(*) AS event_count FROM {$this->junction_table} GROUP BY category_id"
		) ?? array();

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row->category_id ] = (int) $row->event_count;
		}

		wp_cache_set( $cache_key, $counts, CacheManager::CACHE_GROUP, CacheManager::TTL_EVENT );

		return $counts;
	}

	/**
	 * Attach a category to an event.
	 *
	 * @param int  $event_id    Event ID.
	 * @param int  $category_id Category ID.
	 * @param bool $is_primary  Whether this is the primary category.
	 * @return bool True on success.
	 */
	public function attach_to_event( int $event_id, int $category_id, bool $is_primary = false ): bool {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized.
		$sql = $this->db->prepare(
			"INSERT IGNORE INTO {$this->junction_table} (event_id, category_id, is_primary)
			VALUES (%d, %d, %d)",
			$event_id,
			$category_id,
			$is_primary ? 1 : 0
		);
		if ( null === $sql ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$result = $this->db->query( $sql );

		return false !== $result;
	}

	/**
	 * Detach a category from an event.
	 *
	 * @param int $event_id    Event ID.
	 * @param int $category_id Category ID.
	 * @return bool True on success.
	 */
	public function detach_from_event( int $event_id, int $category_id ): bool {
		$result = $this->db->delete(
			$this->junction_table,
			array(
				'event_id'    => $event_id,
				'category_id' => $category_id,
			),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Sync categories for an event.
	 *
	 * @param int                                          $event_id   Event ID.
	 * @param array<int|array{id: int, is_primary?: bool}> $categories Category IDs or config arrays.
	 * @return bool True on success.
	 */
	public function sync_event_categories( int $event_id, array $categories ): bool {
		// Remove all existing categories for this event.
		$this->db->delete(
			$this->junction_table,
			array( 'event_id' => $event_id ),
			array( '%d' )
		);

		// Add new categories.
		foreach ( $categories as $index => $category ) {
			if ( is_array( $category ) ) {
				$category_id = $category['id'];
				$is_primary  = $category['is_primary'] ?? false;
			} else {
				$category_id = $category;
				$is_primary  = 0 === $index; // First one is primary by default.
			}

			$this->attach_to_event( $event_id, $category_id, $is_primary );
		}

		return true;
	}
}
