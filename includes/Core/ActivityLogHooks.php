<?php
/**
 * Activity Log Hooks.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Contracts\ReminderLogRepositoryInterface;

/**
 * Registers hooks to automatically log plugin activities.
 *
 * Provides OWASP A09 (Security Logging) compliance by capturing
 * all significant administrative actions.
 *
 * @since 0.9.0
 */
class ActivityLogHooks {

	/**
	 * Activity log service.
	 *
	 * @var ActivityLogServiceInterface
	 */
	private ActivityLogServiceInterface $service;

	/**
	 * Reminder log repository.
	 *
	 * @var ReminderLogRepositoryInterface
	 */
	private ReminderLogRepositoryInterface $reminder_repo;

	/**
	 * Constructor.
	 *
	 * @param ActivityLogServiceInterface    $service       Activity log service.
	 * @param ReminderLogRepositoryInterface $reminder_repo Reminder log repository.
	 */
	public function __construct( ActivityLogServiceInterface $service, ReminderLogRepositoryInterface $reminder_repo ) {
		$this->service       = $service;
		$this->reminder_repo = $reminder_repo;
	}

	/**
	 * Register all activity logging hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Event actions.
		add_action( 'nettertech_events_event_created', array( $this, 'log_event_created' ), 10, 2 );
		add_action( 'nettertech_events_event_updated', array( $this, 'log_event_updated' ), 10, 2 );
		add_action( 'nettertech_events_event_deleted', array( $this, 'log_event_deleted' ), 10, 2 );
		add_action( 'nettertech_events_event_published', array( $this, 'log_event_published' ), 10, 2 );
		add_action( 'nettertech_events_event_unpublished', array( $this, 'log_event_unpublished' ), 10, 2 );

		// Occurrence actions.
		add_action( 'nettertech_events_occurrence_created', array( $this, 'log_occurrence_created' ), 10, 2 );
		add_action( 'nettertech_events_occurrence_deleted', array( $this, 'log_occurrence_deleted' ), 10, 2 );
		add_action( 'nettertech_events_occurrence_cancelled', array( $this, 'log_occurrence_cancelled' ), 10, 2 );

		// Attendee actions. Check-in is logged directly by
		// AttendeeCheckInAjaxHandler (richer action vocabulary), and no core
		// path currently fires attendee_cancelled, so neither is listened
		// to here.
		add_action( 'nettertech_events_attendee_created', array( $this, 'log_attendee_created' ), 10, 2 );

		// Ticket type actions.
		add_action( 'nettertech_events_ticket_type_created', array( $this, 'log_ticket_type_created' ), 10, 2 );
		add_action( 'nettertech_events_ticket_type_updated', array( $this, 'log_ticket_type_updated' ), 10, 2 );
		add_action( 'nettertech_events_ticket_type_deleted', array( $this, 'log_ticket_type_deleted' ), 10, 2 );

		// Settings actions.
		add_action( 'nettertech_events_settings_updated', array( $this, 'log_settings_updated' ), 10, 1 );

		// Export actions.
		add_action( 'nettertech_events_attendees_exported', array( $this, 'log_attendees_exported' ), 10, 1 );
		add_action( 'nettertech_events_events_exported', array( $this, 'log_events_exported' ), 10, 1 );

		// Daily cleanup cron.
		add_action( 'nettertech_events_daily_cleanup', array( $this, 'run_cleanup' ) );
		if ( ! wp_next_scheduled( 'nettertech_events_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'nettertech_events_daily_cleanup' );
		}
	}

	/**
	 * Log event creation.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $data     Event data.
	 * @return void
	 */
	public function log_event_created( int $event_id, array $data ): void {
		$title = $data['title'] ?? "Event #{$event_id}";
		$this->service->log_event( 'create', $event_id, $title, $data );
	}

	/**
	 * Log event update.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $data     Updated data.
	 * @return void
	 */
	public function log_event_updated( int $event_id, array $data ): void {
		$title = $data['title'] ?? "Event #{$event_id}";
		$this->service->log_event( 'update', $event_id, $title, $data );
	}

	/**
	 * Log event deletion.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $title    Event title.
	 * @return void
	 */
	public function log_event_deleted( int $event_id, string $title ): void {
		$this->service->log_event( 'delete', $event_id, $title );
	}

	/**
	 * Log event publication.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $title    Event title.
	 * @return void
	 */
	public function log_event_published( int $event_id, string $title ): void {
		$this->service->log_event( 'publish', $event_id, $title );
	}

	/**
	 * Log event unpublication.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $title    Event title.
	 * @return void
	 */
	public function log_event_unpublished( int $event_id, string $title ): void {
		$this->service->log_event( 'unpublish', $event_id, $title );
	}

	/**
	 * Log occurrence creation.
	 *
	 * @param int                  $occurrence_id Occurrence ID.
	 * @param array<string, mixed> $data          Occurrence data.
	 * @return void
	 */
	public function log_occurrence_created( int $occurrence_id, array $data ): void {
		$title = $data['event_title'] ?? "Occurrence #{$occurrence_id}";
		$this->service->log_occurrence( 'create', $occurrence_id, $title, $data );
	}

	/**
	 * Log occurrence deletion.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $title         Event title.
	 * @return void
	 */
	public function log_occurrence_deleted( int $occurrence_id, string $title ): void {
		$this->service->log_occurrence( 'delete', $occurrence_id, $title );
	}

	/**
	 * Log occurrence cancellation.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $title         Event title.
	 * @return void
	 */
	public function log_occurrence_cancelled( int $occurrence_id, string $title ): void {
		$this->service->log_occurrence( 'cancel', $occurrence_id, $title );
	}

	/**
	 * Log attendee creation.
	 *
	 * Called via nettertech_events_attendee_created hook from OrderAttendeeCreator,
	 * which fires: do_action( 'nettertech_events_attendee_created', $attendee, $order, $item ).
	 *
	 * @param Attendee $attendee The created attendee model.
	 * @param mixed    $order    WooCommerce order (unused, but part of hook signature).
	 * @return void
	 */
	public function log_attendee_created( Attendee $attendee, $order = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Part of hook signature.
		$attendee_id = (int) $attendee->id;
		$name        = ! empty( $attendee->name ) ? $attendee->name : "Attendee #{$attendee_id}";
		$this->service->log_attendee( 'create', $attendee_id, $name, array( 'name' => $name ) );
	}

	/**
	 * Log ticket type creation.
	 *
	 * @param int                  $ticket_type_id Ticket type ID.
	 * @param array<string, mixed> $data           Ticket type data.
	 * @return void
	 */
	public function log_ticket_type_created( int $ticket_type_id, array $data ): void {
		$name = $data['name'] ?? "Ticket Type #{$ticket_type_id}";
		$this->service->log_ticket_type( 'create', $ticket_type_id, $name, $data );
	}

	/**
	 * Log ticket type update.
	 *
	 * @param int                  $ticket_type_id Ticket type ID.
	 * @param array<string, mixed> $data           Updated data.
	 * @return void
	 */
	public function log_ticket_type_updated( int $ticket_type_id, array $data ): void {
		$name = $data['name'] ?? "Ticket Type #{$ticket_type_id}";
		$this->service->log_ticket_type( 'update', $ticket_type_id, $name, $data );
	}

	/**
	 * Log ticket type deletion.
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $name           Ticket type name.
	 * @return void
	 */
	public function log_ticket_type_deleted( int $ticket_type_id, string $name ): void {
		$this->service->log_ticket_type( 'delete', $ticket_type_id, $name );
	}

	/**
	 * Log settings update.
	 *
	 * @param array<string, mixed> $changes Changed settings.
	 * @return void
	 */
	public function log_settings_updated( array $changes ): void {
		$this->service->log_settings( $changes );
	}

	/**
	 * Log attendees export.
	 *
	 * @param array<string, mixed> $parameters Export parameters.
	 * @return void
	 */
	public function log_attendees_exported( array $parameters ): void {
		$this->service->log_export( 'attendees', $parameters );
	}

	/**
	 * Log events export.
	 *
	 * @param array<string, mixed> $parameters Export parameters.
	 * @return void
	 */
	public function log_events_exported( array $parameters ): void {
		$this->service->log_export( 'events', $parameters );
	}

	/**
	 * Run daily log cleanup.
	 *
	 * @return void
	 */
	public function run_cleanup(): void {
		$retention_days = NetterTechEventsSettings::from_option()->advanced->activity_log_retention_days;

		// Clean activity log.
		$deleted = $this->service->cleanup( $retention_days );

		// Clean reminder log (same retention period).
		$reminder_deleted = $this->reminder_repo->cleanup( $retention_days );

		$total_deleted = $deleted + $reminder_deleted;
		if ( $total_deleted > 0 ) {
			$this->service->log_settings(
				array(
					'action'           => 'log_cleanup',
					'activity_deleted' => $deleted,
					'reminder_deleted' => $reminder_deleted,
				)
			);
		}
	}
}
