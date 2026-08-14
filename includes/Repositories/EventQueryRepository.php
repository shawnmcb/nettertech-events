<?php
/**
 * Event query repository.
 *
 * Handles read-only event queries (listing, searching, pagination, lookups).
 * Extracted from EventRepository to enforce SRP.
 *
 * @package NetterTechEvents\Repositories
 * @since   1.5.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventQueryRepositoryInterface;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Models\Event;

/**
 * Read-only event queries for listing, searching, and pagination.
 *
 * @since 1.5.0
 */
class EventQueryRepository implements EventQueryRepositoryInterface {

	/**
	 * Columns for list/pagination queries, excluding TEXT columns.
	 *
	 * @var string
	 */
	private const LIST_COLUMNS = 'id, post_id, title, slug, featured_image_id, status, event_type, series_id, venue_name, recurrence_rule, recurrence_end_date, reminders_enabled, created_at, updated_at';

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
	 * Constructor.
	 *
	 * @param \wpdb $db Database instance.
	 */
	public function __construct( \wpdb $db ) {
		$this->db    = $db;
		$this->table = Schema::table( 'events' );
	}

	/**
	 * Find an event by post ID.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return Event|null
	 */
	public function find_by_post_id( int $post_id ): ?Event {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$post_id
			)
		);

		return $row ? Event::from_row( $row ) : null;
	}

	/**
	 * Get all events.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<Event>
	 */
	public function all( array $args = array() ): array {
		$defaults = array(
			'status'    => null,
			'type'      => null,
			'series_id' => null,
			'orderby'   => 'created_at',
			'order'     => 'DESC',
			'limit'     => 100,
			'offset'    => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array();
		$values = array();

		if ( null !== $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( null !== $args['type'] ) {
			$where[]  = 'event_type = %s';
			$values[] = $args['type'];
		}

		if ( null !== $args['series_id'] ) {
			$where[]  = 'series_id = %d';
			$values[] = $args['series_id'];
		}

		$where_clause      = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$sanitized_orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		$orderby           = $sanitized_orderby ? $sanitized_orderby : 'created_at DESC';

		$sql = 'SELECT ' . self::LIST_COLUMNS . " FROM {$this->table} {$where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().

		$values[] = $args['limit'];
		$values[] = $args['offset'];

		if ( ! empty( $values ) ) {
			$sql = $this->db->prepare( $sql, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().
		}

		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().

		return array_map( array( Event::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Get published events.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<Event>
	 */
	public function published( array $args = array() ): array {
		return $this->all( array_merge( $args, array( 'status' => 'published' ) ) );
	}

	/**
	 * Get events by series.
	 *
	 * @param int                  $series_id Series ID.
	 * @param array<string, mixed> $args      Query arguments.
	 * @return array<Event>
	 */
	public function by_series( int $series_id, array $args = array() ): array {
		return $this->all( array_merge( $args, array( 'series_id' => $series_id ) ) );
	}

	/**
	 * Count events.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return int
	 */
	public function count( array $args = array() ): int {
		$defaults = array(
			'status'    => null,
			'type'      => null,
			'series_id' => null,
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array();
		$values = array();

		if ( null !== $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( null !== $args['type'] ) {
			$where[]  = 'event_type = %s';
			$values[] = $args['type'];
		}

		if ( null !== $args['series_id'] ) {
			$where[]  = 'series_id = %d';
			$values[] = $args['series_id'];
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$sql          = "SELECT COUNT(*) FROM {$this->table} {$where_clause}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().

		if ( ! empty( $values ) ) {
			$sql = $this->db->prepare( $sql, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().
		}

		return (int) $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().
	}

	/**
	 * Paginate events with filtering and search.
	 *
	 * @param array<string, mixed> $args Query arguments (page, per_page, status, search, orderby, order).
	 * @return array{items: array<Event>, total: int, pages: int}
	 */
	public function paginate( array $args = array() ): array {
		$defaults = array(
			'page'     => 1,
			'per_page' => 20,
			'status'   => null,
			'search'   => null,
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		);

		$args     = wp_parse_args( $args, $defaults );
		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		$query_args = array(
			'status'  => $args['status'],
			'orderby' => $args['orderby'],
			'order'   => $args['order'],
			'limit'   => $per_page,
			'offset'  => $offset,
		);

		// Handle search.
		if ( ! empty( $args['search'] ) ) {
			$items = $this->search( $args['search'], $query_args );
			$total = $this->search_count( $args['search'], array( 'status' => $args['status'] ) );
		} else {
			$items = $this->all( $query_args );
			$total = $this->count( array( 'status' => $args['status'] ) );
		}

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Search events by title.
	 *
	 * @param string               $search Search term.
	 * @param array<string, mixed> $args   Query arguments.
	 * @return array<Event>
	 */
	public function search( string $search, array $args = array() ): array {
		$defaults = array(
			'status'  => 'published',
			'orderby' => 'title',
			'order'   => 'ASC',
			'limit'   => 20,
		);

		$args  = wp_parse_args( $args, $defaults );
		$where = $this->build_search_where( $search, $args['status'] );

		$sanitized_orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		$orderby           = $sanitized_orderby ? $sanitized_orderby : 'title ASC';

		$sql = $this->db->prepare(
			'SELECT ' . self::LIST_COLUMNS . " FROM {$this->table} {$where['clause']} ORDER BY {$orderby} LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			array_merge( $where['values'], array( $args['limit'] ) )
		);

		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().

		return array_map( array( Event::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Count search results without loading rows into memory.
	 *
	 * @param string               $search Search term.
	 * @param array<string, mixed> $args   Query arguments (status).
	 * @return int Total matching rows.
	 */
	public function search_count( string $search, array $args = array() ): int {
		$defaults = array( 'status' => 'published' );
		$args     = wp_parse_args( $args, $defaults );
		$where    = $this->build_search_where( $search, $args['status'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and WHERE from trusted source.
		$sql = $this->db->prepare(
			"SELECT COUNT(*) FROM {$this->table} {$where['clause']}",
			$where['values']
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return (int) $this->db->get_var( $sql );
	}

	/**
	 * Build search WHERE clause and parameter values.
	 *
	 * @param string      $search Search term.
	 * @param string|null $status Status filter.
	 * @return array{clause: string, values: array<string>} WHERE clause and prepared values.
	 */
	private function build_search_where( string $search, ?string $status ): array {
		$where  = array( 'title LIKE %s' );
		$values = array( '%' . $this->db->esc_like( $search ) . '%' );

		if ( null !== $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}

		return array(
			'clause' => 'WHERE ' . implode( ' AND ', $where ),
			'values' => $values,
		);
	}
}
