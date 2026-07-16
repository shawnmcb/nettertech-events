<?php
/**
 * Reminder Email Hooks.
 *
 * Registers the hourly WP-Cron job for sending event reminder emails.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Services\ReminderEmailService;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Registers and handles the reminder email cron job.
 *
 * @since 0.9.5
 */
class ReminderEmailHooks {

	/**
	 * Cron hook name for sending reminder emails.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'nettertech_events_send_reminder_emails';

	/**
	 * Reminder email service.
	 *
	 * @var ReminderEmailService
	 */
	private ReminderEmailService $service;

	/**
	 * Constructor.
	 *
	 * @param ReminderEmailService $service Reminder email service.
	 */
	public function __construct( ReminderEmailService $service ) {
		$this->service = $service;
	}

	/**
	 * Register the cron hook and schedule the event.
	 *
	 * @since 0.9.5
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'process_reminders' ) );

		// Schedule hourly cron if not already scheduled.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Process reminder emails via cron.
	 *
	 * Delegates to ReminderEmailService and logs failures.
	 *
	 * @since 0.9.5
	 *
	 * @return void
	 */
	public function process_reminders(): void {
		$stats = $this->service->process_reminders();

		if ( $stats['failed'] > 0 ) {
			DebugLogger::log(
				sprintf(
					'Reminder emails: %d sent, %d failed, %d skipped',
					$stats['sent'],
					$stats['failed'],
					$stats['skipped']
				),
				'ReminderEmailHooks'
			);
		}
	}
}
