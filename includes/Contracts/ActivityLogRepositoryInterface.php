<?php
/**
 * Activity Log Repository interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\ActivityLog;

/**
 * Interface for the activity log repository.
 *
 * Persists and queries OWASP A09 (Security Logging) activity entries.
 * The default implementation writes to the `wp_nettertech_events_activity_log`
 * custom table; alternate implementations may target external storage
 * for compliance or fan-out scenarios.
 *
 * @since 2.2.0
 * @api
 */
interface ActivityLogRepositoryInterface {

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
	): int|false;

	/**
	 * Find a log entry by ID.
	 *
	 * @param int $id Log entry ID.
	 * @return ActivityLog|null
	 */
	public function find( int $id ): ?ActivityLog;

	/**
	 * Get paginated log entries.
	 *
	 * @param int                  $page     Page number (1-indexed).
	 * @param int                  $per_page Items per page.
	 * @param array<string, mixed> $filters  Optional filters.
	 * @return array{items: array<ActivityLog>, total: int, pages: int}
	 */
	public function paginate( int $page = 1, int $per_page = 20, array $filters = array() ): array;

	/**
	 * Get log entries for a specific object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param int    $limit       Maximum entries to return.
	 * @return array<ActivityLog>
	 */
	public function get_for_object( string $object_type, int $object_id, int $limit = 50 ): array;

	/**
	 * Get recent activity for a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Maximum entries.
	 * @return array<ActivityLog>
	 */
	public function get_for_user( int $user_id, int $limit = 50 ): array;

	/**
	 * Delete old log entries.
	 *
	 * @param int $days_to_keep Number of days to retain logs.
	 * @return int Number of deleted entries.
	 */
	public function cleanup( int $days_to_keep = 90 ): int;

	/**
	 * Get distinct action types.
	 *
	 * @return array<string>
	 */
	public function get_action_types(): array;

	/**
	 * Get distinct object types.
	 *
	 * @return array<string>
	 */
	public function get_object_types(): array;
}
