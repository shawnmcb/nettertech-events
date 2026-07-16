<?php
/**
 * Event query builder class.
 *
 * @package NetterTechEvents\Database\Queries
 */

declare(strict_types=1);

namespace NetterTechEvents\Database\Queries;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Database\Schema;
use NetterTechEvents\Models\Event;

/**
 * Fluent query builder for events.
 *
 * Provides a chainable interface for building complex event queries
 * while ensuring proper SQL escaping and query optimization.
 *
 * Example usage:
 * ```php
 * $events = EventQuery::create()
 *     ->published()
 *     ->with_category( 5 )
 *     ->order_by( 'title', 'ASC' )
 *     ->limit( 10 )
 *     ->get();
 * ```
 *
 * @since 0.8.0
 * @api
 */
class EventQuery {

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
	 * SELECT columns.
	 *
	 * @var array<string>
	 */
	private array $select = array( '*' );

	/**
	 * WHERE clauses.
	 *
	 * @var array<string>
	 */
	private array $where = array();

	/**
	 * WHERE values for prepared statements.
	 *
	 * @var array<mixed>
	 */
	private array $values = array();

	/**
	 * ORDER BY clause.
	 *
	 * @var string
	 */
	private string $order_by = 'created_at DESC';

	/**
	 * LIMIT value.
	 *
	 * @var int|null
	 */
	private ?int $limit = null;

	/**
	 * OFFSET value.
	 *
	 * @var int
	 */
	private int $offset = 0;

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
	 * Create a new query instance.
	 *
	 * @return self
	 */
	public static function create(): self {
		global $wpdb;
		return new self( $wpdb );
	}

	/**
	 * Set SELECT columns.
	 *
	 * @param array<string>|string $columns Columns to select.
	 * @return self
	 */
	public function select( array|string $columns ): self {
		$this->select = is_array( $columns ) ? $columns : array( $columns );
		return $this;
	}

	/**
	 * Filter by status.
	 *
	 * @param string $status Event status.
	 * @return self
	 */
	public function where_status( string $status ): self {
		$this->where[]  = 'status = %s';
		$this->values[] = $status;
		return $this;
	}

	/**
	 * Filter to published events only.
	 *
	 * @return self
	 */
	public function published(): self {
		return $this->where_status( 'published' );
	}

	/**
	 * Filter to draft events only.
	 *
	 * @return self
	 */
	public function drafts(): self {
		return $this->where_status( 'draft' );
	}

	/**
	 * Filter by event type, as the operator understands it.
	 *
	 * An event with more than one scheduled date cannot logically be a single
	 * event, however its stored type reads (NTE-159 ruling): a stored-single
	 * event carrying extra hand-picked dates files under Recurring here, and is
	 * excluded from Single. The stored column is untouched — the NTE-153/154
	 * conversion guards and the save gates still key on it — only what the
	 * filter (and the matching Type column label) reports is derived. The
	 * scheduled-only predicate matches the list's display aggregate
	 * (date_bounds_for_events), so the filter and the label cannot disagree.
	 *
	 * @param string $type Event type.
	 * @return self
	 */
	public function where_type( string $type ): self {
		$occurrences_table = Schema::table( 'occurrences' );

		$multi_date = "( SELECT COUNT(*) FROM {$occurrences_table} sched_o
			WHERE sched_o.event_id = {$this->table}.id AND sched_o.status = 'scheduled' ) > 1";

		if ( 'recurring' === $type ) {
			$this->where[]  = "( event_type = %s OR ( event_type = 'single' AND {$multi_date} ) )";
			$this->values[] = $type;
			return $this;
		}

		if ( 'single' === $type ) {
			$this->where[]  = "( event_type = %s AND NOT ( {$multi_date} ) )";
			$this->values[] = $type;
			return $this;
		}

		$this->where[]  = 'event_type = %s';
		$this->values[] = $type;
		return $this;
	}

	/**
	 * Filter to single events only.
	 *
	 * @return self
	 */
	public function single(): self {
		return $this->where_type( 'single' );
	}

	/**
	 * Filter to recurring events only.
	 *
	 * @return self
	 */
	public function recurring(): self {
		return $this->where_type( 'recurring' );
	}

	/**
	 * Filter by series ID.
	 *
	 * @param int $series_id Series ID.
	 * @return self
	 */
	public function in_series( int $series_id ): self {
		$this->where[]  = 'series_id = %d';
		$this->values[] = $series_id;
		return $this;
	}

	/**
	 * Filter by category ID.
	 *
	 * Uses junction table nettertech_events_event_categories for many-to-many relationship.
	 *
	 * @param int $category_id Category ID.
	 * @return self
	 */
	public function with_category( int $category_id ): self {
		$junction_table = Schema::table( 'event_categories' );
		$this->where[]  = "EXISTS (SELECT 1 FROM {$junction_table} ec WHERE ec.event_id = {$this->table}.id AND ec.category_id = %d)";
		$this->values[] = $category_id;
		return $this;
	}

	/**
	 * Filter by tag ID.
	 *
	 * Uses junction table nettertech_events_event_tags for many-to-many relationship.
	 *
	 * @param int $tag_id Tag ID.
	 * @return self
	 */
	public function with_tag( int $tag_id ): self {
		$junction_table = Schema::table( 'event_tags' );
		$this->where[]  = "EXISTS (SELECT 1 FROM {$junction_table} et WHERE et.event_id = {$this->table}.id AND et.tag_id = %d)";
		$this->values[] = $tag_id;
		return $this;
	}

	/**
	 * Filter to events that have at least one active ticket type.
	 *
	 * "Ticketed" is orthogonal to the single/recurring event_type axis: an event
	 * is single XOR recurring, and independently may or may not have ticket types.
	 * This applies a single set-level EXISTS subquery (no per-row lookup / N+1).
	 *
	 * The definition mirrors EventQueryRepository::has_ticket_types() so the
	 * list-table filter and that per-event helper agree. A ticket type links to an
	 * event in EITHER of two ways, both of which must count:
	 *   - event-scoped (scope='event'): tt.event_id = event, tt.occurrence_id NULL
	 *   - occurrence-scoped: tt.occurrence_id -> o.id -> o.event_id = event
	 * Only active ticket types count. (Historically this matched the occurrence
	 * linkage only, which wrongly hid every event-scoped-ticketed event.)
	 *
	 * @return self
	 */
	public function where_has_ticket_types(): self {
		$occurrences_table  = Schema::table( 'occurrences' );
		$ticket_types_table = Schema::table( 'ticket_types' );

		$this->where[] = "EXISTS (
			SELECT 1
			FROM {$ticket_types_table} tt
			LEFT JOIN {$occurrences_table} o ON tt.occurrence_id = o.id
			WHERE tt.status = 'active'
			AND ( tt.event_id = {$this->table}.id OR o.event_id = {$this->table}.id )
		)";

		return $this;
	}

	/**
	 * Search by title.
	 *
	 * @param string $search Search term.
	 * @return self
	 */
	public function search( string $search ): self {
		$this->where[]  = 'title LIKE %s';
		$this->values[] = '%' . $this->db->esc_like( $search ) . '%';
		return $this;
	}

	/**
	 * Search by title or description.
	 *
	 * @param string $search Search term.
	 * @return self
	 */
	public function search_full( string $search ): self {
		$like           = '%' . $this->db->esc_like( $search ) . '%';
		$this->where[]  = '(title LIKE %s OR description LIKE %s)';
		$this->values[] = $like;
		$this->values[] = $like;
		return $this;
	}

	/**
	 * Filter by ID.
	 *
	 * @param int $id Event ID.
	 * @return self
	 */
	public function where_id( int $id ): self {
		$this->where[]  = 'id = %d';
		$this->values[] = $id;
		return $this;
	}

	/**
	 * Filter by multiple IDs.
	 *
	 * @param array<int> $ids Event IDs.
	 * @return self
	 */
	public function where_ids( array $ids ): self {
		if ( empty( $ids ) ) {
			// Add impossible condition to return no results.
			$this->where[] = '1 = 0';
			return $this;
		}

		$placeholders  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$this->where[] = "id IN ({$placeholders})";
		$this->values  = array_merge( $this->values, $ids );
		return $this;
	}

	/**
	 * Exclude specific IDs.
	 *
	 * @param array<int> $ids Event IDs to exclude.
	 * @return self
	 */
	public function exclude_ids( array $ids ): self {
		if ( empty( $ids ) ) {
			return $this;
		}

		$placeholders  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$this->where[] = "id NOT IN ({$placeholders})";
		$this->values  = array_merge( $this->values, $ids );
		return $this;
	}

	/**
	 * Filter events created after a date.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return self
	 */
	public function created_after( string $date ): self {
		$this->where[]  = 'created_at >= %s';
		$this->values[] = $date . ' 00:00:00';
		return $this;
	}

	/**
	 * Filter events created before a date.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return self
	 */
	public function created_before( string $date ): self {
		$this->where[]  = 'created_at <= %s';
		$this->values[] = $date . ' 23:59:59';
		return $this;
	}

	/**
	 * Set ORDER BY clause.
	 *
	 * @param string $column Column to order by.
	 * @param string $direction ASC or DESC.
	 * @return self
	 */
	public function order_by( string $column, string $direction = 'ASC' ): self {
		$allowed_columns = array( 'id', 'title', 'slug', 'status', 'event_type', 'created_at', 'updated_at' );

		if ( ! in_array( $column, $allowed_columns, true ) ) {
			$column = 'created_at';
		}

		$direction      = strtoupper( $direction ) === 'DESC' ? 'DESC' : 'ASC';
		$this->order_by = "{$column} {$direction}";

		return $this;
	}

	/**
	 * Order by next upcoming occurrence date.
	 *
	 * Uses a subquery to compute the next occurrence for each event.
	 * When sorting ASC, excludes events with no upcoming occurrences.
	 *
	 * @param string $direction ASC or DESC.
	 * @return self
	 */
	public function order_by_next_occurrence( string $direction = 'ASC' ): self {
		$occurrences_table = Schema::table( 'occurrences' );
		$direction         = strtoupper( $direction ) === 'DESC' ? 'DESC' : 'ASC';

		// When sorting ASC, exclude events with no upcoming occurrences.
		// This provides cleaner UX: "show what's coming up next".
		// Upcoming means not yet ended: an event on stage right now must not drop out of the
		// list an operator is watching it from. Tested against the stored instant and
		// UTC_TIMESTAMP() — NOW() is the *database session's* clock, which is a third clock
		// again, agreeing with neither the site nor the occurrence.
		$not_ended = str_replace( '%s', 'UTC_TIMESTAMP()', Timeline::not_ended() );

		if ( 'ASC' === $direction ) {
			$this->where[] = "EXISTS (
				SELECT 1 FROM {$occurrences_table} o
				WHERE o.event_id = {$this->table}.id
				AND {$not_ended}
			)";
		}

		// Sort by the start of the earliest not-yet-ended occurrence.
		// For ASC: earliest upcoming first. For DESC: furthest out first.
		// NULL values (no upcoming) sort last for ASC, first for DESC.
		$this->order_by = "(
			SELECT MIN(o.start_datetime)
			FROM {$occurrences_table} o
			WHERE o.event_id = {$this->table}.id
			AND {$not_ended}
		) {$direction}";

		return $this;
	}

	/**
	 * Order by the event's first occurrence date.
	 *
	 * Sorts by the earliest occurrence start_datetime across all occurrences,
	 * which matches the leading value shown in the admin list's "Date" column
	 * (the first–last span). Unlike order_by_next_occurrence(), this does not
	 * exclude past events: the Date column is a historical span, so events whose
	 * occurrences are all in the past must still appear.
	 *
	 * Events with no occurrences sort last for ASC and first for DESC (NULL
	 * ordering), keeping populated events grouped together.
	 *
	 * @param string $direction ASC or DESC.
	 * @return self
	 */
	public function order_by_first_occurrence( string $direction = 'ASC' ): self {
		$occurrences_table = Schema::table( 'occurrences' );
		$direction         = strtoupper( $direction ) === 'DESC' ? 'DESC' : 'ASC';

		$this->order_by = "(
			SELECT MIN(o.start_datetime)
			FROM {$occurrences_table} o
			WHERE o.event_id = {$this->table}.id
		) {$direction}";

		return $this;
	}

	/**
	 * Set LIMIT.
	 *
	 * @param int $limit Number of results.
	 * @return self
	 */
	public function limit( int $limit ): self {
		$this->limit = max( 1, $limit );
		return $this;
	}

	/**
	 * Set OFFSET.
	 *
	 * @param int $offset Offset.
	 * @return self
	 */
	public function offset( int $offset ): self {
		$this->offset = max( 0, $offset );
		return $this;
	}

	/**
	 * Set page (calculates offset from limit).
	 *
	 * @param int $page Page number (1-indexed).
	 * @return self
	 */
	public function page( int $page ): self {
		if ( $this->limit ) {
			$this->offset = ( max( 1, $page ) - 1 ) * $this->limit;
		}
		return $this;
	}

	/**
	 * Build and return the SQL query.
	 *
	 * @return string
	 */
	public function to_sql(): string {
		$select = implode( ', ', $this->select );
		$where  = ! empty( $this->where ) ? 'WHERE ' . implode( ' AND ', $this->where ) : '';
		$limit  = $this->limit ? "LIMIT {$this->limit}" : '';
		$offset = $this->offset ? "OFFSET {$this->offset}" : '';

		$sql = "SELECT {$select} FROM {$this->table} {$where} ORDER BY {$this->order_by} {$limit} {$offset}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a trusted constant set at construction.

		if ( ! empty( $this->values ) ) {
			$sql = $this->db->prepare( $sql, $this->values ) ?? ''; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is the result of this class's own builder; values are bound via prepare().
		}

		return $sql;
	}

	/**
	 * Execute query and return Event objects.
	 *
	 * @return array<Event>
	 */
	public function get(): array {
		$sql  = $this->to_sql();
		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL produced by to_sql() which runs prepare() when values are present.

		return array_map( array( Event::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Execute query and return first result.
	 *
	 * @return Event|null
	 */
	public function first(): ?Event {
		$this->limit( 1 );
		$results = $this->get();
		return $results[0] ?? null;
	}

	/**
	 * Execute query and return count.
	 *
	 * @return int
	 */
	public function count(): int {
		$original_select = $this->select;
		$this->select    = array( 'COUNT(*) as count' );

		$where = ! empty( $this->where ) ? 'WHERE ' . implode( ' AND ', $this->where ) : '';
		$sql   = "SELECT COUNT(*) FROM {$this->table} {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a trusted constant set at construction.

		if ( ! empty( $this->values ) ) {
			$sql = $this->db->prepare( $sql, $this->values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is the result of this class's own builder; values are bound via prepare().
		}

		$this->select = $original_select;

		return (int) $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL produced by to_sql() which runs prepare() when values are present.
	}

	/**
	 * Check if any results exist.
	 *
	 * @return bool
	 */
	public function exists(): bool {
		return $this->count() > 0;
	}

	/**
	 * Get IDs only.
	 *
	 * @return array<int>
	 */
	public function get_ids(): array {
		$this->select = array( 'id' );
		$sql          = $this->to_sql();
		$results      = $this->db->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL produced by to_sql() which runs prepare() when values are present.

		return array_map( 'intval', $results ? $results : array() );
	}

	/**
	 * Paginate results.
	 *
	 * @param int $per_page Items per page.
	 * @param int $page     Current page (1-indexed).
	 * @return array{items: array<Event>, total: int, pages: int, page: int}
	 */
	public function paginate( int $per_page = 10, int $page = 1 ): array {
		$total = $this->count();
		$pages = (int) ceil( $total / $per_page );
		$page  = max( 1, min( $page, $pages ) );

		$items = $this->limit( $per_page )->page( $page )->get();

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => $pages,
			'page'  => $page,
		);
	}
}
