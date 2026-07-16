<?php
/**
 * PrivacyHooks unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\PrivacyHooks;
use NetterTechEvents\Services\PrivacyService;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test PrivacyHooks registration and cron scheduling.
 *
 * @group wiring
 */
class PrivacyHooksTest extends \NetterTechEventsTestCase {

	/**
	 * Mock privacy service.
	 *
	 * @var PrivacyService|Mockery\MockInterface
	 */
	private $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = Mockery::mock( PrivacyService::class );
	}

	/**
	 * Count Mockery expectations as assertions.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( Mockery::getContainer() ) {
			$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
		}
		parent::tearDown();
	}

	/**
	 * Test register adds filter and action hooks.
	 *
	 * @return void
	 */
	public function test_register_adds_hooks(): void {
		$filters_added = array();
		$actions_added = array();

		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$filters_added ) {
				$filters_added[] = $hook;
				return true;
			}
		);

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$actions_added ) {
				$actions_added[] = $hook;
				return true;
			}
		);

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );

		$hooks = new PrivacyHooks( $this->service );
		$hooks->register();

		$this->assertContains( 'wp_privacy_personal_data_exporters', $filters_added );
		$this->assertContains( 'wp_privacy_personal_data_erasers', $filters_added );
		$this->assertContains( PrivacyHooks::RETENTION_CRON_HOOK, $actions_added );
	}

	/**
	 * Test register schedules cron if not already scheduled.
	 *
	 * @return void
	 */
	public function test_register_schedules_cron_if_not_scheduled(): void {
		$scheduled_event = null;

		Functions\when( 'add_filter' )->justReturn( true );
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

		$hooks = new PrivacyHooks( $this->service );
		$hooks->register();

		$this->assertNotNull( $scheduled_event );
		$this->assertSame( 'daily', $scheduled_event['recurrence'] );
		$this->assertSame( PrivacyHooks::RETENTION_CRON_HOOK, $scheduled_event['hook'] );
	}

	/**
	 * Test register skips scheduling if cron already scheduled.
	 *
	 * @return void
	 */
	public function test_register_skips_schedule_if_already_scheduled(): void {
		$schedule_called = false;

		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( 1738000000 );
		Functions\when( 'wp_schedule_event' )->alias(
			function () use ( &$schedule_called ) {
				$schedule_called = true;
			}
		);

		$hooks = new PrivacyHooks( $this->service );
		$hooks->register();

		$this->assertFalse( $schedule_called );
	}

	/**
	 * Test register_exporters adds all four exporters.
	 *
	 * @return void
	 */
	public function test_register_exporters_adds_all_exporters(): void {
		$hooks = new PrivacyHooks( $this->service );

		$result = $hooks->register_exporters( array() );

		$this->assertArrayHasKey( 'nettertech-events-attendees', $result );
		$this->assertArrayHasKey( 'nettertech-events-activity-log', $result );
		$this->assertArrayHasKey( 'nettertech-events-organizers', $result );
		$this->assertArrayHasKey( 'nettertech-events-waitlist', $result );

		$this->assertSame( 'export_attendee_data', $result['nettertech-events-attendees']['callback'][1] );
		$this->assertSame( 'export_activity_log_data', $result['nettertech-events-activity-log']['callback'][1] );
		$this->assertSame( 'export_organizer_data', $result['nettertech-events-organizers']['callback'][1] );
		$this->assertSame( 'export_waitlist_data', $result['nettertech-events-waitlist']['callback'][1] );
	}

	/**
	 * Test register_erasers adds all four erasers.
	 *
	 * @return void
	 */
	public function test_register_erasers_adds_all_erasers(): void {
		$hooks = new PrivacyHooks( $this->service );

		$result = $hooks->register_erasers( array() );

		$this->assertArrayHasKey( 'nettertech-events-attendees', $result );
		$this->assertArrayHasKey( 'nettertech-events-activity-log', $result );
		$this->assertArrayHasKey( 'nettertech-events-organizers', $result );
		$this->assertArrayHasKey( 'nettertech-events-waitlist', $result );

		$this->assertSame( 'erase_attendee_data', $result['nettertech-events-attendees']['callback'][1] );
		$this->assertSame( 'erase_activity_log_data', $result['nettertech-events-activity-log']['callback'][1] );
		$this->assertSame( 'erase_organizer_data', $result['nettertech-events-organizers']['callback'][1] );
		$this->assertSame( 'erase_waitlist_data', $result['nettertech-events-waitlist']['callback'][1] );
	}

	/**
	 * Test register_exporters preserves existing exporters.
	 *
	 * @return void
	 */
	public function test_register_exporters_preserves_existing(): void {
		$hooks    = new PrivacyHooks( $this->service );
		$existing = array(
			'other-plugin' => array(
				'exporter_friendly_name' => 'Other Plugin',
				'callback'               => 'other_callback',
			),
		);

		$result = $hooks->register_exporters( $existing );

		$this->assertArrayHasKey( 'other-plugin', $result );
		$this->assertArrayHasKey( 'nettertech-events-attendees', $result );
		$this->assertCount( 5, $result );
	}

	/**
	 * Test run_retention_purge calls service with default days.
	 *
	 * @return void
	 */
	public function test_run_retention_purge_calls_service(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value ) {
				if ( 'nettertech_events_activity_log_retention_days' === $filter ) {
					return 90;
				}
				return $value;
			}
		);

		$this->service->shouldReceive( 'purge_old_activity_log_pii' )
			->with( 90 )
			->once();

		$hooks = new PrivacyHooks( $this->service );
		$hooks->run_retention_purge();
	}

	/**
	 * Test run_retention_purge respects custom filter value.
	 *
	 * @return void
	 */
	public function test_run_retention_purge_respects_custom_days(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value ) {
				if ( 'nettertech_events_activity_log_retention_days' === $filter ) {
					return 30;
				}
				return $value;
			}
		);

		$this->service->shouldReceive( 'purge_old_activity_log_pii' )
			->with( 30 )
			->once();

		$hooks = new PrivacyHooks( $this->service );
		$hooks->run_retention_purge();
	}
}
