<?php
/**
 * ActivityLogHooks unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\ActivityLogHooks;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Repositories\ReminderLogRepository;
use NetterTechEvents\Services\ActivityLogService;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test ActivityLogHooks class functionality.
 */
class ActivityLogHooksTest extends \NetterTechEventsTestCase {

	/**
	 * Mock ActivityLogService.
	 *
	 * @var ActivityLogService&Mockery\MockInterface
	 */
	private $service_mock;

	/**
	 * Mock ReminderLogRepository.
	 *
	 * @var ReminderLogRepository&Mockery\MockInterface
	 */
	private $reminder_repo_mock;

	/**
	 * ActivityLogHooks instance.
	 *
	 * @var ActivityLogHooks
	 */
	private ActivityLogHooks $hooks;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->service_mock       = Mockery::mock( ActivityLogService::class );
		$this->reminder_repo_mock = Mockery::mock( ReminderLogRepository::class );
		$this->hooks              = new ActivityLogHooks( $this->service_mock, $this->reminder_repo_mock );
	}

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// Count Mockery expectations as PHPUnit assertions to avoid risky test warnings.
		if ( Mockery::getContainer() ) {
			$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
		}

		parent::tearDown();
	}

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test class exists.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( ActivityLogHooks::class ) );
	}

	/**
	 * Test constructor accepts ActivityLogService.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_dependencies(): void {
		$service       = Mockery::mock( ActivityLogService::class );
		$reminder_repo = Mockery::mock( ReminderLogRepository::class );
		$hooks         = new ActivityLogHooks( $service, $reminder_repo );

		$this->assertInstanceOf( ActivityLogHooks::class, $hooks );
	}

	// =========================================================================
	// register Tests
	// =========================================================================

	/**
	 * Test register adds action hooks.
	 *
	 * @return void
	 */
	public function test_register_adds_action_hooks(): void {
		$hooks_added = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$hooks_added ) {
				$hooks_added[] = $hook;
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( true );

		$this->hooks->register();

		$expected_hooks = array(
			'nettertech_events_event_created',
			'nettertech_events_event_updated',
			'nettertech_events_event_deleted',
			'nettertech_events_event_published',
			'nettertech_events_event_unpublished',
			'nettertech_events_occurrence_created',
			'nettertech_events_occurrence_deleted',
			'nettertech_events_occurrence_cancelled',
			'nettertech_events_attendee_created',
			'nettertech_events_ticket_type_created',
			'nettertech_events_ticket_type_updated',
			'nettertech_events_ticket_type_deleted',
			'nettertech_events_settings_updated',
			'nettertech_events_attendees_exported',
			'nettertech_events_events_exported',
			'nettertech_events_daily_cleanup',
		);

		foreach ( $expected_hooks as $expected ) {
			$this->assertContains( $expected, $hooks_added, "Hook '{$expected}' should be registered" );
		}
	}

	/**
	 * Test register schedules cleanup event if not scheduled.
	 *
	 * @return void
	 */
	public function test_register_schedules_cleanup_if_not_scheduled(): void {
		$scheduled_event = null;

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $time, $recurrence, $hook ) use ( &$scheduled_event ) {
				$scheduled_event = array(
					'recurrence' => $recurrence,
					'hook'       => $hook,
				);
				return true;
			}
		);

		$this->hooks->register();

		$this->assertNotNull( $scheduled_event );
		$this->assertSame( 'daily', $scheduled_event['recurrence'] );
		$this->assertSame( 'nettertech_events_daily_cleanup', $scheduled_event['hook'] );
	}

	/**
	 * Test register does not reschedule cleanup if already scheduled.
	 *
	 * @return void
	 */
	public function test_register_does_not_reschedule_if_scheduled(): void {
		$schedule_called = false;

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( 1234567890 );
		Functions\when( 'wp_schedule_event' )->alias(
			function () use ( &$schedule_called ) {
				$schedule_called = true;
				return true;
			}
		);

		$this->hooks->register();

		$this->assertFalse( $schedule_called );
	}

	// =========================================================================
	// Event Logging Tests
	// =========================================================================

	/**
	 * Test log_event_created calls service.
	 *
	 * @return void
	 */
	public function test_log_event_created_calls_service(): void {
		$this->service_mock
			->shouldReceive( 'log_event' )
			->once()
			->with( 'create', 123, 'Summer Festival', array( 'title' => 'Summer Festival' ) );

		$this->hooks->log_event_created( 123, array( 'title' => 'Summer Festival' ) );

		// Mockery verifies expectations on tearDown - add assertion for PHPUnit.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test log_event_created uses fallback title.
	 *
	 * @return void
	 */
	public function test_log_event_created_uses_fallback_title(): void {
		$this->service_mock
			->shouldReceive( 'log_event' )
			->once()
			->with( 'create', 456, 'Event #456', array() );

		$this->hooks->log_event_created( 456, array() );
	}

	/**
	 * Test log_event_updated calls service.
	 *
	 * @return void
	 */
	public function test_log_event_updated_calls_service(): void {
		$this->service_mock
			->shouldReceive( 'log_event' )
			->once()
			->with( 'update', 789, 'Updated Event', array( 'title' => 'Updated Event' ) );

		$this->hooks->log_event_updated( 789, array( 'title' => 'Updated Event' ) );
	}

	/**
	 * Test log_event_deleted calls service.
	 *
	 * @return void
	 */
	public function test_log_event_deleted_calls_service(): void {
		$this->service_mock
			->shouldReceive( 'log_event' )
			->once()
			->with( 'delete', 100, 'Deleted Event' );

		$this->hooks->log_event_deleted( 100, 'Deleted Event' );
	}

	/**
	 * Test log_event_published calls service.
	 *
	 * @return void
	 */
	public function test_log_event_published_calls_service(): void {
		$this->service_mock
			->shouldReceive( 'log_event' )
			->once()
			->with( 'publish', 200, 'Published Event' );

		$this->hooks->log_event_published( 200, 'Published Event' );
	}

	/**
	 * Test log_event_unpublished calls service.
	 *
	 * @return void
	 */
	public function test_log_event_unpublished_calls_service(): void {
		$this->service_mock
			->shouldReceive( 'log_event' )
			->once()
			->with( 'unpublish', 300, 'Unpublished Event' );

		$this->hooks->log_event_unpublished( 300, 'Unpublished Event' );
	}

	// =========================================================================
	// Occurrence Logging Tests
	// =========================================================================

	/**
	 * Test log_occurrence_created calls service.
	 *
	 * @return void
	 */
	public function test_log_occurrence_created_calls_service(): void {
		$this->service_mock
			->shouldReceive( 'log_occurrence' )
			->once()
			->with( 'create', 50, 'Concert Night', array( 'event_title' => 'Concert Night' ) );

		$this->hooks->log_occurrence_created( 50, array( 'event_title' => 'Concert Night' ) );
	}

	/**
	 * Test log_occurrence_created uses fallback title.
	 *
	 * @return void
	 */
	public function test_log_occurrence_created_uses_fallback_title(): void {
		$this->service_mock
			->shouldReceive( 'log_occurrence' )
			->once()
			->with( 'create', 75, 'Occurrence #75', array() );

		$this->hooks->log_occurrence_created( 75, array() );
	}

	/**
	 * Test log_occurrence_deleted calls service.
	 *
	 * @return void
	 */
	public function test_log_occurrence_deleted_calls_service(): void {
		$this->service_mock
			->shouldReceive( 'log_occurrence' )
			->once()
			->with( 'delete', 60, 'Deleted Occurrence' );

		$this->hooks->log_occurrence_deleted( 60, 'Deleted Occurrence' );
	}

	/**
	 * Test log_occurrence_cancelled calls service.
	 *
	 * @return void
	 */
	public function test_log_occurrence_cancelled_calls_service(): void {
		$this->service_mock
			->shouldReceive( 'log_occurrence' )
			->once()
			->with( 'cancel', 70, 'Cancelled Event' );

		$this->hooks->log_occurrence_cancelled( 70, 'Cancelled Event' );
	}

	// =========================================================================
	// Attendee Logging Tests
	// =========================================================================

	/**
	 * Test log_attendee_created calls service.
	 *
	 * @return void
	 */
	public function test_log_attendee_created_calls_service(): void {
		$attendee                = new Attendee();
		$attendee->id            = 1000;
		$attendee->occurrence_id = 1;
		$attendee->name          = 'John Doe';

		$this->service_mock
			->shouldReceive( 'log_attendee' )
			->once()
			->with( 'create', 1000, 'John Doe', array( 'name' => 'John Doe' ) );

		$this->hooks->log_attendee_created( $attendee );
	}

	/**
	 * Test log_attendee_created uses fallback name.
	 *
	 * @return void
	 */
	public function test_log_attendee_created_uses_fallback_name(): void {
		$attendee                = new Attendee();
		$attendee->id            = 1500;
		$attendee->occurrence_id = 1;
		$attendee->name          = '';

		$this->service_mock
			->shouldReceive( 'log_attendee' )
			->once()
			->with( 'create', 1500, 'Attendee #1500', array( 'name' => 'Attendee #1500' ) );

		$this->hooks->log_attendee_created( $attendee );
	}

	// =========================================================================
	// Ticket Type Logging Tests
	// =========================================================================

	/**
	 * Test log_ticket_type_created calls service.
	 *
	 * @return void
	 */
	public function test_log_ticket_type_created_calls_service(): void {
		$this->service_mock
			->shouldReceive( 'log_ticket_type' )
			->once()
			->with( 'create', 500, 'VIP Pass', array( 'name' => 'VIP Pass' ) );

		$this->hooks->log_ticket_type_created( 500, array( 'name' => 'VIP Pass' ) );
	}

	/**
	 * Test log_ticket_type_created uses fallback name.
	 *
	 * @return void
	 */
	public function test_log_ticket_type_created_uses_fallback_name(): void {
		$this->service_mock
			->shouldReceive( 'log_ticket_type' )
			->once()
			->with( 'create', 600, 'Ticket Type #600', array() );

		$this->hooks->log_ticket_type_created( 600, array() );
	}

	/**
	 * Test log_ticket_type_updated calls service.
	 *
	 * @return void
	 */
	public function test_log_ticket_type_updated_calls_service(): void {
		$this->service_mock
			->shouldReceive( 'log_ticket_type' )
			->once()
			->with( 'update', 700, 'General Admission', array( 'name' => 'General Admission' ) );

		$this->hooks->log_ticket_type_updated( 700, array( 'name' => 'General Admission' ) );
	}

	/**
	 * Test log_ticket_type_deleted calls service.
	 *
	 * @return void
	 */
	public function test_log_ticket_type_deleted_calls_service(): void {
		$this->service_mock
			->shouldReceive( 'log_ticket_type' )
			->once()
			->with( 'delete', 800, 'Deleted Ticket' );

		$this->hooks->log_ticket_type_deleted( 800, 'Deleted Ticket' );
	}

	// =========================================================================
	// Settings Logging Tests
	// =========================================================================

	/**
	 * Test log_settings_updated calls service.
	 *
	 * @return void
	 */
	public function test_log_settings_updated_calls_service(): void {
		$changes = array( 'base_path' => 'events', 'timezone' => 'America/Chicago' );

		$this->service_mock
			->shouldReceive( 'log_settings' )
			->once()
			->with( $changes );

		$this->hooks->log_settings_updated( $changes );
	}

	// =========================================================================
	// Export Logging Tests
	// =========================================================================

	/**
	 * Test log_attendees_exported calls service.
	 *
	 * @return void
	 */
	public function test_log_attendees_exported_calls_service(): void {
		$params = array( 'event_id' => 123, 'format' => 'csv' );

		$this->service_mock
			->shouldReceive( 'log_export' )
			->once()
			->with( 'attendees', $params );

		$this->hooks->log_attendees_exported( $params );
	}

	/**
	 * Test log_events_exported calls service.
	 *
	 * @return void
	 */
	public function test_log_events_exported_calls_service(): void {
		$params = array( 'date_range' => '2026-01', 'format' => 'json' );

		$this->service_mock
			->shouldReceive( 'log_export' )
			->once()
			->with( 'events', $params );

		$this->hooks->log_events_exported( $params );
	}

	// =========================================================================
	// Cleanup Tests
	// =========================================================================

	/**
	 * Test run_cleanup calls service cleanup.
	 *
	 * @return void
	 */
	public function test_run_cleanup_calls_service(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->service_mock
			->shouldReceive( 'cleanup' )
			->once()
			->with( 90 )
			->andReturn( 0 );

		$this->reminder_repo_mock
			->shouldReceive( 'cleanup' )
			->once()
			->with( 90 )
			->andReturn( 0 );

		$this->service_mock
			->shouldNotReceive( 'log_settings' );

		$this->hooks->run_cleanup();
	}

	/**
	 * Test run_cleanup logs cleanup when records deleted.
	 *
	 * @return void
	 */
	public function test_run_cleanup_logs_when_records_deleted(): void {
		Functions\when( 'get_option' )->justReturn( array( 'activity_log_retention_days' => 30 ) );

		$this->service_mock
			->shouldReceive( 'cleanup' )
			->once()
			->with( 30 )
			->andReturn( 100 );

		$this->reminder_repo_mock
			->shouldReceive( 'cleanup' )
			->once()
			->with( 30 )
			->andReturn( 50 );

		$this->service_mock
			->shouldReceive( 'log_settings' )
			->once()
			->with(
				Mockery::on(
					function ( $args ) {
						return 'log_cleanup' === $args['action']
							&& 100 === $args['activity_deleted']
							&& 50 === $args['reminder_deleted'];
					}
				)
			);

		$this->hooks->run_cleanup();
	}

	/**
	 * Test run_cleanup does not log when no records deleted.
	 *
	 * @return void
	 */
	public function test_run_cleanup_does_not_log_when_no_records(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->service_mock
			->shouldReceive( 'cleanup' )
			->once()
			->with( 90 )
			->andReturn( 0 );

		$this->reminder_repo_mock
			->shouldReceive( 'cleanup' )
			->once()
			->with( 90 )
			->andReturn( 0 );

		$this->service_mock
			->shouldNotReceive( 'log_settings' );

		$this->hooks->run_cleanup();
	}
}
