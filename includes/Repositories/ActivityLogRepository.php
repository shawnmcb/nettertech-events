<?php
/**
 * Activity Log repository.
 *
 * @package NetterTechEvents\Repositories
 */

declare(strict_types=1);

namespace NetterTechEvents\Repositories;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\ActivityLogRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Models\ActivityLog;

/**
 * Repository for activity log entries.
 *
 * Provides database access for OWASP A09 security logging.
 *
 * @since 0.9.0
 * @api
 */
class ActivityLogRepository implements ActivityLogRepositoryInterface {

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
		$this->table = Schema::table( 'activity_log' );
	}

	/**
	 * Get the table name.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->table;
	}

	/**
	 * Log an activity.
	 *
	 * @param string                    $action      Action performed.
	 * @param string                    $object_type Type of object.
	 * @param int|null                  $object_id   Object ID.
	 * @param string|null               $object_name Object name for display.
	 * @param array<string, mixed>|null $details     Additional details.
	 * @return int|false Inserted ID or false on failure.
	 */
	public function log(
		string $action,
		string $object_type,
		?int $object_id = null,
		?string $object_name = null,
		?array $details = null
	): int|false {
		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			$user_id = null;
		}

		$ip_address = $this->get_client_ip();
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 )
			: null;

		$data = array(
			'user_id'     => $user_id,
			'action'      => $action,
			'object_type' => $object_type,
			'object_id'   => $object_id,
			'object_name' => $object_name,
			'details'     => $details ? wp_json_encode( $details ) : null,
			'ip_address'  => $ip_address,
			'user_agent'  => $user_agent,
			'created_at'  => current_time( 'mysql' ),
		);

		// Null values (user_id, object_id) need no format handling: wpdb::insert()
		// emits a literal NULL for null values and ignores the format element.
		$formats = array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Activity logging requires direct access.
		$result = $this->db->insert( $this->table(), $data, $formats );

		if ( false === $result ) {
			return false;
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Find a log entry by ID.
	 *
	 * @param int $id Log entry ID.
	 * @return ActivityLog|null
	 */
	public function find( int $id ): ?ActivityLog {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single lookup.
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$id
			)
		);

		if ( ! $row ) {
			return null;
		}

		return ActivityLog::from_row( $row );
	}

	/**
	 * Get paginated log entries.
	 *
	 * @param int                  $page     Page number (1-indexed).
	 * @param int                  $per_page Items per page.
	 * @param array<string, mixed> $filters  Optional filters.
	 * @return array{items: array<ActivityLog>, total: int, pages: int}
	 */
	public function paginate( int $page = 1, int $per_page = 20, array $filters = array() ): array {
		$table  = $this->table();
		$where  = array( '1=1' );
		$params = array();

		// Filter by user.
		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}

		// Filter by action.
		if ( ! empty( $filters['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = $filters['action'];
		}

		// Filter by object type.
		if ( ! empty( $filters['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$params[] = $filters['object_type'];
		}

		// Filter by object ID.
		if ( ! empty( $filters['object_id'] ) ) {
			$where[]  = 'object_id = %d';
			$params[] = (int) $filters['object_id'];
		}

		// Filter by date range.
		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $filters['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $filters['date_to'] . ' 23:59:59';
		}

		// Search in object_name.
		if ( ! empty( $filters['search'] ) ) {
			$where[]  = 'object_name LIKE %s';
			$params[] = '%' . $this->db->esc_like( $filters['search'] ) . '%';
		}

		$where_clause = implode( ' AND ', $where );

		// Get total count.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table has no WP_Query equivalent; direct access required.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders -- Dynamic placeholder count from filters.
		if ( ! empty( $params ) ) {
			$total = (int) $this->db->get_var(
				$this->db->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE {$where_clause}",
					...$params
				)
			);
		} else {
			$total = (int) $this->db->get_var(
				"SELECT COUNT(*) FROM {$table} WHERE {$where_clause}"
			);
		}

		// Build items query with pagination.
		$offset   = ( $page - 1 ) * $per_page;
		$params[] = $per_page;
		$params[] = $offset;

		// Get items.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...$params
			)
		) ?? array();
		// phpcs:enable

		$items = array_map(
			fn( $row ) => ActivityLog::from_row( $row ),
			$rows
		);

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Get log entries for a specific object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param int    $limit       Maximum entries to return.
	 * @return array<ActivityLog>
	 */
	public function get_for_object( string $object_type, int $object_id, int $limit = 50 ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table has no WP_Query equivalent; direct access required.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE object_type = %s AND object_id = %d ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$object_type,
				$object_id,
				$limit
			)
		) ?? array();

		return array_map(
			fn( $row ) => ActivityLog::from_row( $row ),
			$rows
		);
	}

	/**
	 * Get recent activity for a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Maximum entries.
	 * @return array<ActivityLog>
	 */
	public function get_for_user( int $user_id, int $limit = 50 ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table has no WP_Query equivalent; direct access required.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
				$user_id,
				$limit
			)
		) ?? array();

		return array_map(
			fn( $row ) => ActivityLog::from_row( $row ),
			$rows
		);
	}

	/**
	 * Delete old log entries.
	 *
	 * @param int $days_to_keep Number of days to retain logs.
	 * @return int Number of deleted entries.
	 */
	public function cleanup( int $days_to_keep = 90 ): int {
		$table  = $this->table();
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$days_to_keep} days" ) );

		/**
		 * Filter the retention period before cleanup.
		 *
		 * @param string $cutoff   Cutoff datetime.
		 * @param int    $days     Configured retention days.
		 */
		$cutoff = apply_filters( 'nettertech_events_activity_retention', $cutoff, $days_to_keep );

		$sql = $this->db->prepare(
			"DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
			$cutoff
		);
		if ( null === $sql ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table has no WP_Query equivalent; direct access required; query prepared above.
		$deleted = $this->db->query( $sql );

		return (int) $deleted;
	}

	/**
	 * Get distinct action types.
	 *
	 * @return array<string>
	 */
	public function get_action_types(): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table has no WP_Query equivalent; direct access required.
		$actions = $this->db->get_col(
			"SELECT DISTINCT action FROM {$table} ORDER BY action" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
		);

		return $actions;
	}

	/**
	 * Get distinct object types.
	 *
	 * @return array<string>
	 */
	public function get_object_types(): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table has no WP_Query equivalent; direct access required.
		$types = $this->db->get_col(
			"SELECT DISTINCT object_type FROM {$table} ORDER BY object_type" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted constant or plugin property; user values bound via prepare().
		);

		return $types;
	}

	/**
	 * Get client IP address.
	 *
	 * Handles proxied requests properly.
	 *
	 * @return string|null
	 */
	private function get_client_ip(): ?string {
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// Handle comma-separated IPs (X-Forwarded-For).
				if ( str_contains( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return null;
	}
}
