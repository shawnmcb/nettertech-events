<?php
/**
 * Occurrence filtered query repository.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\OccurrenceFilterRepositoryInterface;
use NetterTechEvents\Database\Queries\Timeline;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;

/**
 * Handles filtered occurrence queries with pagination, caching, and category joins.
 *
 * Extracted from OccurrenceRepository to separate complex query building
 * from core CRUD operations.
 *
 * @since 0.9.5
 */
class OccurrenceFilterRepository implements OccurrenceFilterRepositoryInterface {

	/**
	 * Columns for JOIN list queries, prefixed with table alias 'o.'.
	 *
	 * Excludes longtext/text columns (description_override, venue_address_override) for list efficiency.
	 *
	 * @var string
	 */
	private const JOIN_LIST_COLUMNS = 'o.id, o.event_id, o.start_datetime, o.end_datetime, o.all_day, o.timezone, o.title_override, o.featured_image_id, o.status, o.capacity, o.sequence_number, o.origin_start_datetime, o.is_rescheduled, o.is_override, o.venue_name_override, o.virtual_url_override, o.checkin_token, o.created_at, o.updated_at';

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
	 * Constructor.
	 *
	 * @param \wpdb $db Database instance.
	 */
	public function __construct( \wpdb $db ) {
		$this->db           = $db;
		$this->table        = Schema::table( 'occurrences' );
		$this->events_table = Schema::table( 'events' );
	}

	/**
	 * Get filtered occurrences with pagination.
	 *
	 * Supports filtering by category, search, and upcoming-only.
	 * Uses object cache for per-request caching (persistent on sites with Redis/Memcached).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<Occurrence>, total: int, total_pages: int}
	 */
	public function get_filtered( array $args = array() ): array {
		$defaults = array(
			'page'         => 1,
			'per_page'     => 12,
			'upcoming'     => true,
			'past'         => false,
			'category'     => array(),
			'tag'          => '',
			'search'       => '',
			'date_from'    => '',
			'date_to'      => '',
			'status'       => 'scheduled',
			'event_status' => 'published',
		);

		$args = wp_parse_args( $args, $defaults );

		// Check object cache first.
		$cache_key = $this->build_filtered_cache_key( $args );
		$cached    = wp_cache_get( $cache_key, 'nettertech_events' );
		if ( false !== $cached ) {
			return $cached;
		}

		// Build filter conditions.
		$filters      = $this->build_filter_conditions( $args );
		$where_clause = ! empty( $filters['where'] ) ? 'WHERE ' . implode( ' AND ', $filters['where'] ) : '';

		$extra_joins = $filters['category_join'] . $filters['tag_join'];

		// Execute count and items queries.
		$total       = $this->execute_filtered_count( $where_clause, $extra_joins, $filters['values'] );
		$pagination  = $this->calculate_pagination( $args, $total );
		$occurrences = $this->execute_filtered_items(
			$where_clause,
			$extra_joins,
			$filters['values'],
			$pagination,
			$args['past']
		);

		$result = array(
			'items'       => $occurrences,
			'total'       => $total,
			'total_pages' => $pagination['total_pages'],
		);

		// Cache for 1 hour (matches the hourly cache key granularity).
		wp_cache_set( $cache_key, $result, 'nettertech_events', HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Build cache key for filtered queries.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return string Cache key.
	 */
	private function build_filtered_cache_key( array $args ): string {
		$cache_args          = $args;
		$cache_args['_hour'] = gmdate( 'Y-m-d-H' ); // Hourly granularity for upcoming filter.
		return 'filtered_' . crc32( (string) wp_json_encode( $cache_args ) );
	}

	/**
	 * Build WHERE conditions and JOINs for filtered queries.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{where: array<string>, values: array<mixed>, category_join: string, tag_join: string}
	 */
	private function build_filter_conditions( array $args ): array {
		$where  = array();
		$values = array();

		// Status filters.
		if ( null !== $args['status'] ) {
			$where[]  = 'o.status = %s';
			$values[] = $args['status'];
		}

		if ( null !== $args['event_status'] ) {
			$where[]  = 'e.status = %s';
			$values[] = $args['event_status'];
		}

		// Time-based filters.
		$time_filter = $this->build_time_filter( $args );
		if ( $time_filter ) {
			$where[]  = $time_filter['condition'];
			$values[] = $time_filter['value'];
		}

		// Category filter.
		$category_join = $this->build_category_join( $args['category'], $where );

		// Tag filter.
		$tag_join = $this->build_tag_join( $args['tag'], $where );

		// Search filter.
		if ( ! empty( $args['search'] ) ) {
			$search_term = '%' . $this->db->esc_like( $args['search'] ) . '%';
			$where[]     = '(e.title LIKE %s OR e.description LIKE %s)';
			$values[]    = $search_term;
			$values[]    = $search_term;
		}

		// Date range filters.
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'o.start_datetime >= %s';
			$values[] = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'o.start_datetime <= %s';
			$values[] = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
		}

		return array(
			'where'         => $where,
			'values'        => $values,
			'category_join' => $category_join,
			'tag_join'      => $tag_join,
		);
	}

	/**
	 * Build time-based filter condition.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{condition: string, value: string}|null Filter or null if no time filter.
	 */
	private function build_time_filter( array $args ): ?array {
		if ( $args['past'] ) {
			return array(
				'condition' => Timeline::ended(),
				'value'     => Timeline::now(),
			);
		}

		if ( $args['upcoming'] ) {
			return array(
				'condition' => Timeline::not_ended(),
				'value'     => Timeline::now(),
			);
		}

		return null;
	}

	/**
	 * Build category JOIN clause using nettertech_events_event_categories junction table.
	 *
	 * @param array<int|string>|int|string $category Category ID(s) from nettertech_events_categories.
	 * @param array<string>                $where    Reference to WHERE conditions array (may add impossible condition).
	 * @return string JOIN clause or empty string.
	 */
	private function build_category_join( $category, array &$where ): string {
		if ( empty( $category ) ) {
			return '';
		}

		$category_ids = array_map( 'absint', (array) $category );
		$category_ids = array_filter( $category_ids );

		if ( empty( $category_ids ) ) {
			$where[] = '1 = 0';
			return '';
		}

		$ec_table     = Schema::table( 'event_categories' );
		$placeholders = implode( ',', array_fill( 0, count( $category_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is safe (%d), $ec_table is from Schema.
		return $this->db->prepare(
			" INNER JOIN {$ec_table} ec ON e.id = ec.event_id AND ec.category_id IN ($placeholders)",
			$category_ids
		) ?? '';
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Build tag JOIN clause using nettertech_events_event_tags and nettertech_events_tags tables.
	 *
	 * Accepts a tag slug (string), tag ID (numeric string/int), or an array of
	 * mixed slugs/IDs. Multi-value input produces OR semantics via SQL IN().
	 * Numeric-looking strings are treated as IDs; non-numeric values as slugs;
	 * mixed arrays are resolved together by joining through the tags table.
	 *
	 * @since 1.1.0 Added array support (NTE-068).
	 *
	 * @param string|int|array<string|int> $tag   Tag slug, ID, or array of slugs/IDs.
	 * @param array<string>                $where Reference to WHERE conditions array (may add impossible condition).
	 * @return string JOIN clause or empty string.
	 */
	private function build_tag_join( $tag, array &$where ): string {
		if ( empty( $tag ) && '0' !== $tag ) {
			return '';
		}

		$et_table   = Schema::table( 'event_tags' );
		$tags_table = Schema::table( 'tags' );

		// Multi-value path: array of slugs/IDs (or single non-empty array).
		if ( is_array( $tag ) ) {
			$ids   = array();
			$slugs = array();
			foreach ( $tag as $value ) {
				if ( is_numeric( $value ) ) {
					$id = absint( $value );
					if ( $id > 0 ) {
						$ids[] = $id;
					}
				} elseif ( is_string( $value ) && '' !== $value ) {
					$slug = sanitize_title( $value );
					if ( '' !== $slug ) {
						$slugs[] = $slug;
					}
				}
			}

			$ids   = array_values( array_unique( $ids ) );
			$slugs = array_values( array_unique( $slugs ) );

			if ( empty( $ids ) && empty( $slugs ) ) {
				$where[] = '1 = 0';
				return '';
			}

			// All-IDs fast path: single-table join, no need to resolve slugs.
			if ( ! empty( $ids ) && empty( $slugs ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is safe (%d), $et_table from Schema.
				return $this->db->prepare(
					" INNER JOIN {$et_table} et_tag ON e.id = et_tag.event_id AND et_tag.tag_id IN ($placeholders)",
					$ids
				) ?? '';
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			}

			// Mixed or slug-only path: resolve slugs to IDs via the tags table.
			$id_placeholders   = ! empty( $ids ) ? implode( ',', array_fill( 0, count( $ids ), '%d' ) ) : '';
			$slug_placeholders = ! empty( $slugs ) ? implode( ',', array_fill( 0, count( $slugs ), '%s' ) ) : '';

			$conditions = array();
			if ( '' !== $id_placeholders ) {
				$conditions[] = "t_filter.id IN ($id_placeholders)";
			}
			if ( '' !== $slug_placeholders ) {
				$conditions[] = "t_filter.slug IN ($slug_placeholders)";
			}
			$condition_sql = implode( ' OR ', $conditions );
			$values        = array_merge( $ids, $slugs );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table names from Schema; placeholders generated as %d/%s safely; values bound via prepare().
			return $this->db->prepare(
				" INNER JOIN {$et_table} et_tag ON e.id = et_tag.event_id
				  INNER JOIN {$tags_table} t_filter ON et_tag.tag_id = t_filter.id AND ($condition_sql)",
				$values
			) ?? '';
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		// Single scalar path (legacy): determine if filtering by ID or slug.
		if ( is_numeric( $tag ) ) {
			$tag_id = absint( $tag );
			if ( 0 === $tag_id ) {
				$where[] = '1 = 0';
				return '';
			}
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $et_table is from Schema.
			return $this->db->prepare(
				" INNER JOIN {$et_table} et_tag ON e.id = et_tag.event_id AND et_tag.tag_id = %d",
				$tag_id
			) ?? '';
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Filter by slug: join through tags table to resolve slug to ID.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from Schema.
		return $this->db->prepare(
			" INNER JOIN {$et_table} et_tag ON e.id = et_tag.event_id
			  INNER JOIN {$tags_table} t_filter ON et_tag.tag_id = t_filter.id AND t_filter.slug = %s",
			sanitize_title( (string) $tag )
		) ?? '';
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Execute COUNT query for filtered results.
	 *
	 * @param string       $where_clause WHERE clause.
	 * @param string       $extra_joins  Extra JOIN clauses (category, tag, etc.).
	 * @param array<mixed> $values       Prepared values.
	 * @return int Total count.
	 */
	private function execute_filtered_count( string $where_clause, string $extra_joins, array $values ): int {
		$count_sql = "SELECT COUNT(*)
                      FROM {$this->table} o
                      JOIN {$this->events_table} e ON o.event_id = e.id
                      {$extra_joins}
                      {$where_clause}";

		if ( ! empty( $values ) ) {
			$count_sql = $this->db->prepare( $count_sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().
		}

		return (int) $this->db->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().
	}

	/**
	 * Calculate pagination values.
	 *
	 * @param array<string, mixed> $args  Query arguments.
	 * @param int                  $total Total count.
	 * @return array{per_page: int, total_pages: int, page: int, offset: int}
	 */
	private function calculate_pagination( array $args, int $total ): array {
		$per_page    = max( 1, (int) $args['per_page'] );
		$total_pages = (int) ceil( $total / $per_page );
		$page        = max( 1, min( (int) $args['page'], $total_pages > 0 ? $total_pages : 1 ) );
		$offset      = ( $page - 1 ) * $per_page;

		return array(
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
			'page'        => $page,
			'offset'      => $offset,
		);
	}

	/**
	 * Execute items query for filtered results.
	 *
	 * @param string                                                         $where_clause WHERE clause.
	 * @param string                                                         $extra_joins  Extra JOIN clauses (category, tag, etc.).
	 * @param array<mixed>                                                   $values       Prepared values.
	 * @param array{per_page: int, total_pages: int, page: int, offset: int} $pagination   Pagination values.
	 * @param bool                                                           $past         Whether querying past events.
	 * @return array<Occurrence>
	 */
	private function execute_filtered_items(
		string $where_clause,
		string $extra_joins,
		array $values,
		array $pagination,
		bool $past
	): array {
		$items_values   = $values;
		$items_values[] = $pagination['per_page'];
		$items_values[] = $pagination['offset'];

		$order_direction = $past ? 'DESC' : 'ASC';

		$items_sql = 'SELECT ' . self::JOIN_LIST_COLUMNS . ", e.title as event_title, e.slug as event_slug,
                             e.featured_image_id as event_image_id, e.venue_name, e.venue_address,
                             e.excerpt as event_excerpt, e.event_type
                      FROM {$this->table} o
                      JOIN {$this->events_table} e ON o.event_id = e.id
                      {$extra_joins}
                      {$where_clause}
                      ORDER BY o.start_datetime {$order_direction}
                      LIMIT %d OFFSET %d";

		$items_sql = $this->db->prepare( $items_sql, $items_values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().
		$rows      = $this->db->get_results( $items_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().

		return $this->hydrate_occurrences_with_events( $rows ? $rows : array() );
	}

	/**
	 * Hydrate occurrence models with attached event data from query rows.
	 *
	 * @param array<\stdClass> $rows Database result rows (joined occurrence + event projection).
	 * @return array<Occurrence>
	 */
	private function hydrate_occurrences_with_events( array $rows ): array {
		$occurrences = array();

		foreach ( $rows as $row ) {
			$occurrence = Occurrence::from_row( $row );

			$event                    = new Event();
			$event->id                = $occurrence->event_id;
			$event->title             = $row->event_title;
			$event->slug              = $row->event_slug;
			$event->excerpt           = $row->event_excerpt ?? '';
			$event->featured_image_id = isset( $row->event_image_id ) ? (int) $row->event_image_id : null;
			$event->venue_name        = $row->venue_name ?? null;
			$event->venue_address     = $row->venue_address ?? null;
			$event->event_type        = $row->event_type ?? 'single';
			$occurrence->set_event( $event );

			$occurrences[] = $occurrence;
		}

		return $occurrences;
	}
}
