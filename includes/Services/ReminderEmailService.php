<?php
/**
 * Reminder Email Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\EmailTemplateRendererInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\IcsGeneratorInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Contracts\ReminderLogRepositoryInterface;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Orchestrates sending reminder emails for upcoming event occurrences.
 *
 * Finds occurrences in the reminder window, resolves unsent attendees,
 * and sends reminders with ICS attachments in configurable batches.
 *
 * @since 0.9.5
 */
class ReminderEmailService {

	/**
	 * Maximum emails to send per cron run.
	 *
	 * @var int
	 */
	public const BATCH_LIMIT = 50;

	/**
	 * Hours before event to check for reminders.
	 *
	 * Set to 25 (not 24) to provide a 1h buffer so hourly cron never misses.
	 *
	 * @var int
	 */
	public const REMINDER_WINDOW_HOURS = 25;

	/**
	 * Reminder type identifier.
	 *
	 * @var string
	 */
	public const REMINDER_TYPE = '24h_before';

	/**
	 * Email settings option key.
	 *
	 * @var string
	 */
	private const SETTINGS_KEY = 'nettertech_events_email_settings';

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Attendee repository.
	 *
	 * @var AttendeeRepositoryInterface
	 */
	private AttendeeRepositoryInterface $attendee_repo;

	/**
	 * Reminder log repository.
	 *
	 * @var ReminderLogRepositoryInterface
	 */
	private ReminderLogRepositoryInterface $log_repo;

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Email template renderer.
	 *
	 * @var EmailTemplateRendererInterface
	 */
	private EmailTemplateRendererInterface $renderer;

	/**
	 * ICS calendar-file generator.
	 *
	 * @var IcsGeneratorInterface
	 */
	private IcsGeneratorInterface $ics_generator;

	/**
	 * Cached email settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $settings = null;

	/**
	 * Constructor.
	 *
	 * @since 0.9.5
	 *
	 * @param OccurrenceRepositoryInterface  $occurrence_repo Occurrence repository.
	 * @param AttendeeRepositoryInterface    $attendee_repo   Attendee repository.
	 * @param ReminderLogRepositoryInterface $log_repo        Reminder log repository.
	 * @param EventRepositoryInterface       $event_repo      Event repository.
	 * @param EmailTemplateRendererInterface $renderer        Email template renderer.
	 * @param IcsGeneratorInterface          $ics_generator   ICS calendar-file generator.
	 */
	public function __construct(
		OccurrenceRepositoryInterface $occurrence_repo,
		AttendeeRepositoryInterface $attendee_repo,
		ReminderLogRepositoryInterface $log_repo,
		EventRepositoryInterface $event_repo,
		EmailTemplateRendererInterface $renderer,
		IcsGeneratorInterface $ics_generator
	) {
		$this->occurrence_repo = $occurrence_repo;
		$this->attendee_repo   = $attendee_repo;
		$this->log_repo        = $log_repo;
		$this->event_repo      = $event_repo;
		$this->renderer        = $renderer;
		$this->ics_generator   = $ics_generator;
	}

	/**
	 * Process reminder emails for upcoming occurrences.
	 *
	 * Main orchestrator called by the cron hook. Finds occurrences
	 * in the reminder window, resolves unsent attendees, and sends
	 * emails up to the batch limit.
	 *
	 * @since 0.9.5
	 *
	 * @return array{sent: int, failed: int, skipped: int}
	 */
	public function process_reminders(): array {
		$stats = array(
			'sent'    => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		$occurrences = $this->get_occurrences_needing_reminders();
		$total_sent  = 0;

		foreach ( $occurrences as $occurrence ) {
			if ( $total_sent >= self::BATCH_LIMIT ) {
				break;
			}

			$occurrence_id = $occurrence->id;
			if ( null === $occurrence_id ) {
				continue;
			}

			$event = $this->event_repo->find( $occurrence->event_id );
			if ( ! $event ) {
				continue;
			}

			if ( ! $this->is_reminder_enabled_for_event( $event ) ) {
				continue;
			}

			$attendees = $this->get_unsent_attendees( $occurrence_id );

			foreach ( $attendees as $attendee ) {
				if ( $total_sent >= self::BATCH_LIMIT ) {
					break 2;
				}

				if ( ! $attendee->email || ! is_email( $attendee->email ) ) {
					++$stats['skipped'];
					++$total_sent;
					continue;
				}

				if ( $this->send_reminder( $occurrence, $event, $attendee ) ) {
					++$stats['sent'];
				} else {
					++$stats['failed'];
				}
				++$total_sent;
			}
		}

		return $stats;
	}

	/**
	 * Get occurrences that need reminder emails.
	 *
	 * Queries for scheduled occurrences of published events starting
	 * within the reminder window (now to now + 25 hours).
	 *
	 * @since 0.9.5
	 *
	 * @return array<Occurrence>
	 */
	public function get_occurrences_needing_reminders(): array {
		$now = current_time( 'mysql' );
		$end = (string) wp_date( 'Y-m-d H:i:s', time() + self::REMINDER_WINDOW_HOURS * HOUR_IN_SECONDS );

		return $this->occurrence_repo->in_range(
			$now,
			$end,
			array(
				'status'         => 'scheduled',
				'event_status'   => 'published',
				'include_events' => false,
			)
		);
	}

	/**
	 * Get attendees who haven't received a reminder for an occurrence.
	 *
	 * @since 0.9.5
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<Attendee>
	 */
	public function get_unsent_attendees( int $occurrence_id ): array {
		$all_attendees = $this->attendee_repo->for_occurrence(
			$occurrence_id,
			array( 'status' => 'confirmed' )
		);

		$sent_ids = $this->log_repo->get_sent_attendee_ids( $occurrence_id, self::REMINDER_TYPE );

		if ( empty( $sent_ids ) ) {
			return $all_attendees;
		}

		return array_values(
			array_filter(
				$all_attendees,
				static function ( Attendee $attendee ) use ( $sent_ids ): bool {
					return ! in_array( $attendee->id, $sent_ids, true );
				}
			)
		);
	}

	/**
	 * Send a reminder email to a single attendee.
	 *
	 * @since 0.9.5
	 *
	 * @param Occurrence $occurrence Occurrence.
	 * @param Event      $event      Event.
	 * @param Attendee   $attendee   Attendee.
	 * @return bool True if email was sent successfully.
	 */
	public function send_reminder( Occurrence $occurrence, Event $event, Attendee $attendee ): bool {
		$settings = $this->get_template_settings();
		$subject  = $this->renderer->get_reminder_subject( $event, $occurrence );
		$body     = $this->renderer->render_reminder_email( $occurrence, $event, $attendee, $settings );
		$headers  = $this->renderer->get_email_headers();

		// Generate ICS attachment.
		$attachments = array();
		$ics_file    = $this->ics_generator->generate_occurrence_ics( $occurrence, $event );
		if ( $ics_file ) {
			$attachments[] = $ics_file;
		}

		try {
			$sent = wp_mail( $attendee->email, $subject, $body, $headers, $attachments );
		} catch ( \Throwable $e ) {
			DebugLogger::exception( $e, 'ReminderEmailService' );
			$sent = false;
		} finally {
			// Clean up temp ICS file.
			if ( $ics_file && file_exists( $ics_file ) ) {
				wp_delete_file( $ics_file );
			}
		}

		// Log the result regardless of success/failure.
		if ( null !== $occurrence->id && null !== $attendee->id ) {
			$this->log_repo->log_sent(
				$occurrence->id,
				$attendee->id,
				self::REMINDER_TYPE,
				$sent ? 'sent' : 'failed'
			);
		}

		return $sent;
	}

	/**
	 * Check if reminders are enabled for a specific event.
	 *
	 * Resolution: per-event setting overrides site default.
	 * NULL = use site default, true = force on, false = force off.
	 *
	 * @since 0.9.5
	 *
	 * @param Event $event Event.
	 * @return bool
	 */
	public function is_reminder_enabled_for_event( Event $event ): bool {
		if ( null !== $event->reminders_enabled ) {
			return $event->reminders_enabled;
		}

		$settings = $this->get_settings();
		return (bool) ( $settings['enable_reminders'] ?? true );
	}

	/**
	 * Get email settings.
	 *
	 * @since 0.9.5
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings(): array {
		$settings = $this->settings;
		if ( null === $settings ) {
			$stored         = get_option( self::SETTINGS_KEY, array() );
			$settings       = is_array( $stored ) ? $stored : array();
			$this->settings = $settings;
		}

		return wp_parse_args(
			$settings,
			array(
				'enable_reminders' => true,
				'venue_logo'       => '',
			)
		);
	}

	/**
	 * Get template settings for the renderer.
	 *
	 * @since 0.9.5
	 *
	 * @return array<string, mixed>
	 */
	private function get_template_settings(): array {
		$settings = $this->get_settings();
		return array(
			'venue_logo' => $settings['venue_logo'] ?? '',
		);
	}
}
