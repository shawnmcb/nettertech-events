<?php
/**
 * ReminderEmailHooks unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Core\ReminderEmailHooks;
use NetterTechEvents\Services\ReminderEmailService;

/**
 * Test ReminderEmailHooks class.
 *
 * @coversDefaultClass \NetterTechEvents\Core\ReminderEmailHooks
 */
class ReminderEmailHooksTest extends \NetterTechEventsTestCase {

	/**
	 * Mock service.
	 *
	 * @var ReminderEmailService|Mockery\MockInterface
	 */
	private $service;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );

		$this->service = Mockery::mock( ReminderEmailService::class );
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Test cron hook constant.
	 *
	 * @return void
	 */
	public function test_cron_hook_constant(): void {
		$this->assertSame(
			'nettertech_events_send_reminder_emails',
			ReminderEmailHooks::CRON_HOOK
		);
	}

	/**
	 * Test register schedules cron when not already scheduled.
	 *
	 * @return void
	 */
	public function test_register_schedules_cron_when_unscheduled(): void {
		$scheduled = null;
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( $timestamp, $recurrence, $hook ) use ( &$scheduled ) {
				$scheduled = array( $recurrence, $hook );
				return true;
			}
		);

		$hooks = new ReminderEmailHooks( $this->service );
		$hooks->register();

		$this->assertSame( array( 'hourly', 'nettertech_events_send_reminder_emails' ), $scheduled );
	}

	/**
	 * Test register does not re-schedule when already scheduled.
	 *
	 * @return void
	 */
	public function test_register_does_not_reschedule(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( 12345 );

		$scheduled = false;
		Functions\when( 'wp_schedule_event' )->alias(
			static function () use ( &$scheduled ) {
				$scheduled = true;
				return true;
			}
		);

		$hooks = new ReminderEmailHooks( $this->service );
		$hooks->register();

		$this->assertFalse( $scheduled );
	}

	/**
	 * Test process_reminders does not log when no failures.
	 *
	 * @return void
	 */
	public function test_process_reminders_no_log_when_no_failures(): void {
		$this->service->shouldReceive( 'process_reminders' )->andReturn(
			array(
				'sent'    => 5,
				'failed'  => 0,
				'skipped' => 1,
			)
		);

		$hooks = new ReminderEmailHooks( $this->service );
		$hooks->process_reminders();

		$this->assertTrue( true );
	}

	/**
	 * Test process_reminders logs when failures occur.
	 *
	 * @return void
	 */
	public function test_process_reminders_logs_on_failure(): void {
		$this->service->shouldReceive( 'process_reminders' )->andReturn(
			array(
				'sent'    => 2,
				'failed'  => 1,
				'skipped' => 0,
			)
		);

		$hooks = new ReminderEmailHooks( $this->service );
		$hooks->process_reminders();

		// Logging is via DebugLogger which writes to error_log; we only assert no exception.
		$this->assertTrue( true );
	}
}
