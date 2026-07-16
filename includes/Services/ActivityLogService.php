<?php
/**
 * Activity Log Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\ActivityLog;
use NetterTechEvents\Repositories\ActivityLogRepository;

/**
 * Service for activity logging.
 *
 * Provides OWASP A09 (Security Logging and Monitoring) compliance
 * by tracking all administrative actions in the plugin.
 *
 * @since 0.9.0
 * @api
 */
class ActivityLogService implements ActivityLogServiceInterface {

	/**
	 * Repository instance.
	 *
	 * @var ActivityLogRepository
	 */
	private ActivityLogRepository $repository;

	/**
	 * Whether logging is enabled.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Constructor.
	 *
	 * @param ActivityLogRepository $repository Repository instance.
	 */
	public function __construct( ActivityLogRepository $repository ) {
		$this->repository = $repository;
		$this->enabled    = $this->is_logging_enabled();
	}

	/**
	 * Check if logging is enabled.
	 *
	 * @return bool
	 */
	private function is_logging_enabled(): bool {
		/**
		 * Filter whether activity logging is enabled.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'nettertech_events_activity_logging_enabled', true );
	}

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
	public function log( string $action, string $object_type, int $object_id, string $name, ?array $details = null ): void {
		match ( $object_type ) {
			'event' => $this->log_event( $action, $object_id, $name, $details ),
			'occurrence' => $this->log_occurrence( $action, $object_id, $name, $details ),
			'attendee' => $this->log_attendee( $action, $object_id, $name, $details ),
			'ticket_type' => $this->log_ticket_type( $action, $object_id, $name, $details ),
			'series' => $this->log_series( $action, $object_id, $name, $details ),
			default => $this->log_event( $action, $object_id, $name, $details ),
		};
	}

	/**
	 * Log an event action.
	 *
	 * @param string                    $action   Action performed.
	 * @param int                       $event_id Event ID.
	 * @param string                    $title    Event title.
	 * @param array<string, mixed>|null $details  Additional details.
	 * @return void
	 */
	public function log_event( string $action, int $event_id, string $title, ?array $details = null ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$this->repository->log( $action, 'event', $event_id, $title, $details );

		/**
		 * Fires after an event activity is logged.
		 *
		 * @param string $action      Action performed.
		 * @param string $object_type Object type ('event').
		 * @param int    $event_id    Event ID.
		 * @param string $title       Event title.
		 * @param array<string, mixed>|null $details     Additional details.
		 */
		do_action( 'nettertech_events_activity_logged', $action, 'event', $event_id, $title, $details );
	}

	/**
	 * Log an occurrence action.
	 *
	 * @param string                    $action        Action performed.
	 * @param int                       $occurrence_id Occurrence ID.
	 * @param string                    $title         Title.
	 * @param array<string, mixed>|null $details       Additional details.
	 * @return void
	 */
	public function log_occurrence( string $action, int $occurrence_id, string $title, ?array $details = null ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$this->repository->log( $action, 'occurrence', $occurrence_id, $title, $details );

		do_action( 'nettertech_events_activity_logged', $action, 'occurrence', $occurrence_id, $title, $details );
	}

	/**
	 * Log an attendee action.
	 *
	 * @param string                    $action      Action performed.
	 * @param int                       $attendee_id Attendee ID.
	 * @param string                    $name        Attendee name.
	 * @param array<string, mixed>|null $details     Additional details.
	 * @return void
	 */
	public function log_attendee( string $action, int $attendee_id, string $name, ?array $details = null ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$this->repository->log( $action, 'attendee', $attendee_id, $name, $details );

		do_action( 'nettertech_events_activity_logged', $action, 'attendee', $attendee_id, $name, $details );
	}

	/**
	 * Log a ticket type action.
	 *
	 * @param string                    $action         Action performed.
	 * @param int                       $ticket_type_id Ticket type ID.
	 * @param string                    $name           Ticket type name.
	 * @param array<string, mixed>|null $details        Additional details.
	 * @return void
	 */
	public function log_ticket_type( string $action, int $ticket_type_id, string $name, ?array $details = null ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$this->repository->log( $action, 'ticket_type', $ticket_type_id, $name, $details );

		do_action( 'nettertech_events_activity_logged', $action, 'ticket_type', $ticket_type_id, $name, $details );
	}

	/**
	 * Log a settings update.
	 *
	 * @param array<string, mixed> $changes Changed settings.
	 * @return void
	 */
	public function log_settings( array $changes ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$this->repository->log(
			'settings_update',
			'settings',
			null,
			__( 'Plugin Settings', 'nettertech-events' ),
			$changes
		);

		do_action( 'nettertech_events_activity_logged', 'settings_update', 'settings', null, 'Plugin Settings', $changes );
	}

	/**
	 * Log a data export.
	 *
	 * @param string               $export_type Type of export.
	 * @param array<string, mixed> $parameters  Export parameters.
	 * @return void
	 */
	public function log_export( string $export_type, array $parameters ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$this->repository->log(
			'export',
			$export_type,
			null,
			ucfirst( $export_type ) . ' Export',
			$parameters
		);

		do_action( 'nettertech_events_activity_logged', 'export', $export_type, null, $export_type, $parameters );
	}

	/**
	 * Log a series action.
	 *
	 * @param string                    $action    Action performed.
	 * @param int                       $series_id Series ID.
	 * @param string                    $title     Series title.
	 * @param array<string, mixed>|null $details   Additional details.
	 * @return void
	 */
	public function log_series( string $action, int $series_id, string $title, ?array $details = null ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$this->repository->log( $action, 'series', $series_id, $title, $details );

		do_action( 'nettertech_events_activity_logged', $action, 'series', $series_id, $title, $details );
	}

	/**
	 * Get paginated activity logs.
	 *
	 * @param int                  $page     Page number.
	 * @param int                  $per_page Items per page.
	 * @param array<string, mixed> $filters  Filters.
	 * @return array{items: array<ActivityLog>, total: int, pages: int}
	 */
	public function get_logs( int $page = 1, int $per_page = 20, array $filters = array() ): array {
		return $this->repository->paginate( $page, $per_page, $filters );
	}

	/**
	 * Get activity history for a specific object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return array<ActivityLog>
	 */
	public function get_object_history( string $object_type, int $object_id ): array {
		return $this->repository->get_for_object( $object_type, $object_id );
	}

	/**
	 * Get available action types for filtering.
	 *
	 * @return array<string>
	 */
	public function get_action_types(): array {
		return $this->repository->get_action_types();
	}

	/**
	 * Get available object types for filtering.
	 *
	 * @return array<string>
	 */
	public function get_object_types(): array {
		return $this->repository->get_object_types();
	}

	/**
	 * Run log cleanup.
	 *
	 * @param int $retention_days Days to retain logs. Default 90.
	 * @return int Number of entries deleted.
	 */
	public function cleanup( int $retention_days = 90 ): int {
		/**
		 * Filter the log retention period in days.
		 *
		 * @param int $retention_days Default 90 days.
		 */
		$retention_days = (int) apply_filters( 'nettertech_events_activity_log_retention_days', $retention_days );

		return $this->repository->cleanup( $retention_days );
	}
}
