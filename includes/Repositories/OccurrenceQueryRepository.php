<?php
/**
 * Occurrence query repository.
 *
 * Handles read-only occurrence queries (calendar views, listings, navigation).
 * Extracted from OccurrenceRepository to enforce SRP.
 *
 * @package NetterTechEvents\Repositories
 * @since   1.5.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\OccurrenceFilterRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceQueryRepositoryInterface;
use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Database\Queries\Timeline;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;

/**
 * Read-only occurrence queries for calendar views, listings, and navigation.
 *
 * @since 1.5.0
 */
class OccurrenceQueryRepository implements OccurrenceQueryRepositoryInterface {

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
	 * Filtered query repository.
	 *
	 * @var OccurrenceFilterRepositoryInterface
	 */
	private OccurrenceFilterRepositoryInterface $filter_repo;

	/**
	 * Columns for list queries (excludes longtext columns).
	 *
	 * @var string
	 */
	private const LIST_COLUMNS = 'id, event_id, start_datetime, end_datetime, all_day, timezone, title_override, featured_image_id, status, capacity, sequence_number, origin_start_datetime, is_rescheduled, is_override, venue_name_override, virtual_url_override, checkin_token, created_at, updated_at';

	/**
	 * Columns for JOIN list queries, prefixed with table alias 'o.'.
	 *
	 * @var string
	 */
	private const JOIN_LIST_COLUMNS = 'o.id, o.event_id, o.start_datetime, o.end_datetime, o.all_day, o.timezone, o.title_override, o.featured_image_id, o.status, o.capacity, o.sequence_number, o.origin_start_datetime, o.is_rescheduled, o.is_override, o.venue_name_override, o.virtual_url_override, o.checkin_token, o.created_at, o.updated_at';

	/**
	 * Constructor.
	 *
	 * @param \wpdb                                    $db          Database instance.
	 * @param OccurrenceFilterRepositoryInterface|null $filter_repo Optional filter repository for testing.
	 */
	public function __construct( \wpdb $db, ?OccurrenceFilterRepositoryInterface $filter_repo = null ) {
		$this->db           = $db;
		$this->table        = Schema::table( 'occurrences' );
		$this->events_table = Schema::table( 'events' );
		$this->filter_repo  = $filter_repo ?? new OccurrenceFilterRepository( $db );
	}

	/**
	 * Get occurrences for an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array<Occurrence>
	 */
	public function for_event( int $event_id, array $args = array() ): array {
		$defaults = array(
			'status'   => null,
			'upcoming' => false,
			'orderby'  => 'start_datetime',
			'order'    => 'ASC',
			'limit'    => 100,
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array( 'event_id = %d' );
		$values = array( $event_id );

		if ( null !== $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( $args['upcoming'] ) {
			// NTE-128: bucket by the *end* so an in-progress occurrence (started, not yet
			// ended) remains in "upcoming", matching Occurrence::is_past()/is_happening_now()
			// and for_event_grouped. Against the stored instant, so an occurrence in another
			// zone is judged by its own clock rather than the site's.
			$where[]  = Timeline::not_ended( false );
			$values[] = Timeline::now();
		}

		$where_clause      = 'WHERE ' . implode( ' AND ', $where );
		$sanitized_orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		$orderby           = $sanitized_orderby ? $sanitized_orderby : 'start_datetime ASC';

		$sql = $this->db->prepare(
			'SELECT ' . self::LIST_COLUMNS . " FROM {$this->table} {$where_clause} ORDER BY {$orderby} LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			array_merge( $values, array( $args['limit'] ) )
		);

		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().

		return array_map( array( Occurrence::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Get occurrences for an event, grouped by past and upcoming.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array{past: array<Occurrence>, upcoming: array<Occurrence>}
	 */
	public function for_event_grouped( int $event_id, array $args = array() ): array {
		$defaults = array(
			'status' => 'scheduled',
			'limit'  => 100,
		);

		$args = wp_parse_args( $args, $defaults );
		$now  = Timeline::now();

		$where  = array( 'event_id = %d' );
		$values = array( $event_id );

		if ( null !== $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		$where_clause = 'WHERE ' . implode( ' AND ', $where );
		$is_past      = Timeline::ended( false );

		// Single query, let PHP split by the flag the database computed.
		$sql = $this->db->prepare(
			'SELECT ' . self::LIST_COLUMNS . ", {$is_past} AS is_past
             FROM {$this->table}
             {$where_clause}
             ORDER BY start_datetime ASC
             LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			array_merge( array( $now ), $values, array( $args['limit'] ) )
		);

		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().

		$past     = array();
		$upcoming = array();

		foreach ( $rows ? $rows : array() as $row ) {
			$occurrence = Occurrence::from_row( $row );
			if ( $row->is_past ) {
				$past[] = $occurrence;
			} else {
				$upcoming[] = $occurrence;
			}
		}

		return array(
			'past'     => $past,
			'upcoming' => $upcoming,
		);
	}

	/**
	 * Get sibling occurrences for the same event.
	 *
	 * Returns all occurrences for the parent event, organized for navigation.
	 * Results are cached per-hour for performance.
	 *
	 * @param int $occurrence_id The current occurrence ID.
	 * @return array{all: array<Occurrence>, past: array<Occurrence>, upcoming: array<Occurrence>, current_index: int}
	 */
	public function get_siblings( int $occurrence_id ): array {
		// Cache key includes occurrence_id and hourly granularity.
		$cache_key = 'siblings_' . $occurrence_id . '_' . gmdate( 'Y-m-d-H' );
		$cached    = wp_cache_get( $cache_key, 'nettertech_events' );

		if ( false !== $cached ) {
			return $cached;
		}

		// Get the event_id for this occurrence directly.
		$event_id = $this->db->get_var(
			$this->db->prepare(
				"SELECT event_id FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$occurrence_id
			)
		);

		if ( ! $event_id ) {
			return array(
				'all'           => array(),
				'past'          => array(),
				'upcoming'      => array(),
				'current_index' => -1,
			);
		}

		// Get all occurrences for this event with event data attached.
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::JOIN_LIST_COLUMNS . ", e.title as event_title, e.slug as event_slug,
                        e.featured_image_id as event_image_id, e.event_type,
                        e.venue_name, e.venue_address
                 FROM {$this->table} o
                 JOIN {$this->events_table} e ON o.event_id = e.id
                 WHERE o.event_id = %d
                 ORDER BY o.start_datetime ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				(int) $event_id
			)
		);

		$all           = array();
		$past          = array();
		$upcoming      = array();
		$current_index = -1;

		foreach ( $rows ? $rows : array() as $index => $row ) {
			$sibling = Occurrence::from_row( $row );

			// Attach event data.
			$event                    = new Event();
			$event->id                = $sibling->event_id;
			$event->title             = $row->event_title;
			$event->slug              = $row->event_slug;
			$event->event_type        = $row->event_type ?? 'single';
			$event->featured_image_id = $row->event_image_id ? (int) $row->event_image_id : null;
			$event->venue_name        = $row->venue_name ?? null;
			$event->venue_address     = $row->venue_address ?? null;
			$sibling->set_event( $event );

			$all[] = $sibling;

			// Track current occurrence index.
			if ( $sibling->id === $occurrence_id ) {
				$current_index = $index;
			}

			// Past once it has *ended*, not once it has started: an occurrence under way is
			// still one you can attend. is_past() owns that rule (and reads the wall-clock in
			// the occurrence's own zone, DST-aware).
			if ( $sibling->is_past() ) {
				$past[] = $sibling;
			} else {
				$upcoming[] = $sibling;
			}
		}

		$result = array(
			'all'           => $all,
			'past'          => $past,
			'upcoming'      => $upcoming,
			'current_index' => $current_index,
		);

		// Cache for 1 hour.
		wp_cache_set( $cache_key, $result, 'nettertech_events', HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Get occurrences in a date range.
	 *
	 * Primary query for calendar views. Uses the composite index
	 * (start_datetime, end_datetime, status).
	 *
	 * By default the window selects occurrences that *start* inside it — what a calendar grid
	 * means by "this month". Pass `include_in_progress` to move the lower bound onto
	 * `end_datetime` instead, so an occurrence already under way still counts as ahead of you.
	 * That is what `upcoming()` wants and a grid does not.
	 *
	 * @since 1.1.2 Accepts `include_in_progress`.
	 *
	 * @param string               $start_date Start date (Y-m-d or Y-m-d H:i:s).
	 * @param string               $end_date   End date (Y-m-d or Y-m-d H:i:s).
	 * @param array<string, mixed> $args       Query arguments.
	 * @return array<Occurrence>
	 */
	public function in_range( string $start_date, string $end_date, array $args = array() ): array {
		$defaults = array(
			'status'              => 'scheduled',
			'event_status'        => 'published',
			'include_events'      => true,
			'limit'               => 500,
			'include_in_progress' => false,
		);

		$args = wp_parse_args( $args, $defaults );

		// Normalize dates to MySQL datetime in site timezone.
		if ( str_contains( $start_date, 'T' ) ) {
			$ts = strtotime( $start_date );
			if ( false !== $ts ) {
				$start_date = (string) wp_date( 'Y-m-d H:i:s', $ts );
			}
		} elseif ( strlen( $start_date ) === 10 ) {
			$start_date .= ' 00:00:00';
		}

		if ( str_contains( $end_date, 'T' ) ) {
			$ts = strtotime( $end_date );
			if ( false !== $ts ) {
				$end_date = (string) wp_date( 'Y-m-d H:i:s', $ts );
			}
		} elseif ( strlen( $end_date ) === 10 ) {
			$end_date .= ' 23:59:59';
		}

		// Cache lookup — normalize timestamps to 5-minute intervals for key stability.
		$key_start = substr( $start_date, 0, 15 ) . '0:00'; // Round to 10-min.
		$key_end   = substr( $end_date, 0, 15 ) . '0:00';
		$key_args  = md5( (string) wp_json_encode( $args ) );
		$cache_key = 'in_range_' . $key_start . '_' . $key_end . '_' . $key_args;
		$cached    = wp_cache_get( $cache_key, CacheManager::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		// The window arrives as wall-clock the site means locally ("June", "from today"), so it
		// is resolved to instants to be compared against the stored ones. An occurrence in
		// another zone then lands in the window its own clock puts it in.
		$start_utc = Timeline::to_utc( $start_date );
		$end_utc   = Timeline::to_utc( $end_date );

		if ( null === $start_utc || null === $end_utc ) {
			return array();
		}

		// Which bound the *lower* edge tests. A calendar grid asks "what starts in this
		// window", and must keep asking that. A caller asking "what is still to come" wants an
		// occurrence that began an hour ago and runs another two — under way is not over.
		$where   = array( $args['include_in_progress'] ? Timeline::not_ended() : Timeline::starts_from() );
		$where[] = Timeline::starts_until();
		$values  = array( $start_utc, $end_utc );

		if ( null !== $args['status'] ) {
			$where[]  = 'o.status = %s';
			$values[] = $args['status'];
		}

		if ( null !== $args['event_status'] ) {
			$where[]  = 'e.status = %s';
			$values[] = $args['event_status'];
		}

		$where_clause = 'WHERE ' . implode( ' AND ', $where );
		$values[]     = $args['limit'];

		if ( $args['include_events'] ) {
			$sql = $this->db->prepare(
				'SELECT ' . self::JOIN_LIST_COLUMNS . ", e.title as event_title, e.slug as event_slug,
                        e.featured_image_id as event_image_id, e.event_type,
                        e.venue_name, e.venue_address
                 FROM {$this->table} o
                 JOIN {$this->events_table} e ON o.event_id = e.id
                 {$where_clause}
                 ORDER BY o.start_datetime ASC
                 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$values
			);
		} else {
			$sql = $this->db->prepare(
				'SELECT ' . self::JOIN_LIST_COLUMNS . "
                 FROM {$this->table} o
                 JOIN {$this->events_table} e ON o.event_id = e.id
                 {$where_clause}
                 ORDER BY o.start_datetime ASC
                 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$values
			);
		}

		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().

		$occurrences = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$occurrence = Occurrence::from_row( $row );

			// Attach minimal event data if included.
			if ( $args['include_events'] && isset( $row->event_title ) ) {
				$event                    = new Event();
				$event->id                = $occurrence->event_id;
				$event->title             = $row->event_title;
				$event->slug              = $row->event_slug;
				$event->featured_image_id = $row->event_image_id ? (int) $row->event_image_id : null;
				$event->venue_name        = $row->venue_name ?? null;
				$event->venue_address     = $row->venue_address ?? null;
				// Without event_type the model defaults to 'single' and
				// Occurrence::get_url() emits the series permalink for
				// recurring events (NTE-179).
				$event->event_type = $row->event_type ?? 'single';
				$occurrence->set_event( $event );
			}

			$occurrences[] = $occurrence;
		}

		wp_cache_set( $cache_key, $occurrences, CacheManager::CACHE_GROUP, CacheManager::TTL_OCCURRENCE );

		return $occurrences;
	}

	/**
	 * Get upcoming occurrences.
	 *
	 * Upcoming means *not yet over*, not *not yet begun*. A concert an hour into its
	 * three-hour run has not become a past event, and dropping it from this list at the
	 * downbeat is how a live event disappears from the site while the audience is in the room.
	 *
	 * @since 1.1.2 Bounded by the end of the occurrence; previously by its start.
	 *
	 * @param int                  $limit Number of occurrences.
	 * @param array<string, mixed> $args  Query arguments.
	 * @return array<Occurrence>
	 */
	public function upcoming( int $limit = 10, array $args = array() ): array {
		// Both bounds in the same site-local basis as the stored start_datetime;
		// wp_date renders the +2y instant in the site zone (was gmdate → UTC mismatch).
		$now        = current_time( 'mysql' );
		$far_future = wp_date( 'Y-m-d H:i:s', strtotime( '+2 years' ) );
		if ( false === $far_future ) {
			return array();
		}

		return $this->in_range(
			$now,
			$far_future,
			array_merge(
				$args,
				array(
					'limit'               => $limit,
					'include_in_progress' => true,
				)
			)
		);
	}

	/**
	 * Get next occurrence for an event.
	 *
	 * "Next" includes one already under way — that is the occurrence a visitor arriving at
	 * the page right now cares about, and skipping ahead to next week's date while tonight's
	 * show is on stage is worse than useless.
	 *
	 * @since 1.1.2 An in-progress occurrence is the next one; previously it was skipped.
	 *
	 * @param int $event_id Event ID.
	 * @return Occurrence|null
	 */
	public function next_for_event( int $event_id ): ?Occurrence {
		$not_ended = Timeline::not_ended( false );

		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table}
                 WHERE event_id = %d
                   AND {$not_ended}
                   AND status = 'scheduled'
                 ORDER BY start_datetime ASC
                 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$event_id,
				Timeline::now()
			)
		);

		return $row ? Occurrence::from_row( $row ) : null;
	}

	/**
	 * Get occurrence date bounds per event for a batch of events.
	 *
	 * Single batched aggregate over the occurrences table: MIN/MAX of start_datetime
	 * for the full span, plus a conditional MIN for the next upcoming occurrence and
	 * the all_day flag of that next occurrence. Only `scheduled` occurrences are
	 * considered, matching next_for_event()'s filter.
	 *
	 * @since 1.0.3
	 *
	 * @param array<int> $event_ids Event IDs to aggregate.
	 * @return array<int, array{first: ?string, last: ?string, next: ?string, next_all_day: bool, count: int}>
	 */
	public function date_bounds_for_events( array $event_ids ): array {
		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );

		if ( empty( $event_ids ) ) {
			return array();
		}

		$now          = Timeline::now();
		$not_ended    = Timeline::not_ended( false );
		$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );

		// One aggregate row per event: full span (first/last) plus the next upcoming
		// start. The next-bound is computed via a conditional MIN so the existing
		// per-row next_for_event() N+1 in column_next_date() can be retired. The condition
		// tests the end, matching next_for_event(): an occurrence under way is the next one,
		// and the value reported is still its start (a wall-clock, because it is displayed).
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT
					event_id,
					MIN(start_datetime) AS first_start,
					MAX(start_datetime) AS last_start,
					MIN(CASE WHEN {$not_ended} THEN start_datetime ELSE NULL END) AS next_start,
					COUNT(*) AS occ_count
				FROM {$this->table}
				WHERE event_id IN ({$placeholders})
					AND status = 'scheduled'
				GROUP BY event_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant; event IDs bound via prepare().
				array_merge( array( $now ), $event_ids )
			)
		);

		$bounds    = array();
		$next_keys = array();

		foreach ( $rows ? $rows : array() as $row ) {
			$event_id            = (int) $row->event_id;
			$next_start          = null !== $row->next_start ? (string) $row->next_start : null;
			$bounds[ $event_id ] = array(
				'first'        => null !== $row->first_start ? (string) $row->first_start : null,
				'last'         => null !== $row->last_start ? (string) $row->last_start : null,
				'next'         => $next_start,
				'next_all_day' => false,
				'count'        => (int) ( $row->occ_count ?? 0 ),
			);

			if ( null !== $next_start ) {
				$next_keys[ $event_id ] = $next_start;
			}
		}

		// Resolve the all_day flag for each event's next occurrence in one batched
		// lookup keyed by (event_id, start_datetime). Avoids a per-event query.
		if ( ! empty( $next_keys ) ) {
			$pairs  = array();
			$values = array();
			foreach ( $next_keys as $event_id => $start ) {
				$pairs[]  = '(event_id = %d AND start_datetime = %s)';
				$values[] = $event_id;
				$values[] = $start;
			}
			$pair_clause = implode( ' OR ', $pairs );

			$flag_rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT event_id, start_datetime, all_day
					FROM {$this->table}
					WHERE status = 'scheduled' AND ({$pair_clause})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant; values bound via prepare().
					$values
				)
			);

			foreach ( $flag_rows ? $flag_rows : array() as $flag_row ) {
				$event_id = (int) $flag_row->event_id;
				if ( isset( $bounds[ $event_id ] ) && (string) $flag_row->start_datetime === (string) $bounds[ $event_id ]['next'] ) {
					$bounds[ $event_id ]['next_all_day'] = (bool) $flag_row->all_day;
				}
			}
		}

		return $bounds;
	}

	/**
	 * Get upcoming occurrences for an event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $limit    Maximum number of occurrences.
	 * @return array<Occurrence>
	 */
	public function get_upcoming_by_event( int $event_id, int $limit = 10 ): array {
		return $this->for_event(
			$event_id,
			array(
				'upcoming' => true,
				'status'   => 'scheduled',
				'limit'    => $limit,
			)
		);
	}

	/**
	 * Count occurrences for an event.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $status   Optional status filter.
	 * @return int
	 */
	public function count_for_event( int $event_id, string $status = '' ): int {
		$sql    = "SELECT COUNT(*) FROM {$this->table} WHERE event_id = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
		$values = array( $event_id );

		if ( ! empty( $status ) ) {
			$sql     .= ' AND status = %s';
			$values[] = $status;
		}

		return (int) $this->db->get_var( $this->db->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built by this class's own query builder and conditionally run through prepare().
	}

	/**
	 * Get filtered occurrences with pagination.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<Occurrence>, total: int, total_pages: int}
	 */
	public function get_filtered( array $args = array() ): array {
		return $this->filter_repo->get_filtered( $args );
	}
}
