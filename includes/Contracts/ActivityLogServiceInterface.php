<?php
/**
 * Activity Log Service interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\ActivityLog;

/**
 * Interface for activity logging service.
 *
 * Provides OWASP A09 (Security Logging) compliance.
 *
 * @since 0.9.0
 * @api
 */
interface ActivityLogServiceInterface {

	/**
	 * Generic logging method that dispatches to type-specific methods.
	 *
	 * @param string                    $action      Action (create, update, delete, etc.).
	 * @param string                    $object_type Object type (event, occurrence, attendee, ticket_type).
	 * @param int                       $object_id   Object ID.
	 * @param string                    $name        Object name/title.
	 * @param array<string, mixed>|null $details     Additional details.
	 * @return void
	 */
	public function log( string $action, string $object_type, int $object_id, string $name, ?array $details = null ): void;

	/**
	 * Log an event action.
	 *
	 * @param string                    $action    Action (create, update, delete, publish, unpublish).
	 * @param int                       $event_id  Event ID.
	 * @param string                    $title     Event title.
	 * @param array<string, mixed>|null $details   Additional details.
	 * @return void
	 */
	public function log_event( string $action, int $event_id, string $title, ?array $details = null ): void;

	/**
	 * Log an occurrence action.
	 *
	 * @param string                    $action        Action performed.
	 * @param int                       $occurrence_id Occurrence ID.
	 * @param string                    $title         Occurrence/event title.
	 * @param array<string, mixed>|null $details       Additional details.
	 * @return void
	 */
	public function log_occurrence( string $action, int $occurrence_id, string $title, ?array $details = null ): void;

	/**
	 * Log an attendee action.
	 *
	 * @param string                    $action      Action (create, check_in, cancel, refund).
	 * @param int                       $attendee_id Attendee ID.
	 * @param string                    $name        Attendee name.
	 * @param array<string, mixed>|null $details     Additional details.
	 * @return void
	 */
	public function log_attendee( string $action, int $attendee_id, string $name, ?array $details = null ): void;

	/**
	 * Log a ticket type action.
	 *
	 * @param string                    $action         Action performed.
	 * @param int                       $ticket_type_id Ticket type ID.
	 * @param string                    $name           Ticket type name.
	 * @param array<string, mixed>|null $details        Additional details.
	 * @return void
	 */
	public function log_ticket_type( string $action, int $ticket_type_id, string $name, ?array $details = null ): void;

	/**
	 * Log a settings update.
	 *
	 * @param array<string, mixed> $changes Changed settings.
	 * @return void
	 */
	public function log_settings( array $changes ): void;

	/**
	 * Log a data export.
	 *
	 * @param string               $export_type Type of export (attendees, events, etc).
	 * @param array<string, mixed> $parameters  Export parameters.
	 * @return void
	 */
	public function log_export( string $export_type, array $parameters ): void;

	/**
	 * Get paginated activity logs.
	 *
	 * @param int                  $page     Page number.
	 * @param int                  $per_page Items per page.
	 * @param array<string, mixed> $filters  Filters.
	 * @return array{items: array<ActivityLog>, total: int, pages: int}
	 */
	public function get_logs( int $page = 1, int $per_page = 20, array $filters = array() ): array;

	/**
	 * Get activity history for a specific object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return array<ActivityLog>
	 */
	public function get_object_history( string $object_type, int $object_id ): array;

	/**
	 * Clean up old log entries.
	 *
	 * @param int $retention_days Days to retain logs. Default 90.
	 * @return int Number of entries deleted.
	 */
	public function cleanup( int $retention_days = 90 ): int;
}
