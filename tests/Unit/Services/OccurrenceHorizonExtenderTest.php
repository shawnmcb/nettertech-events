<?php
/**
 * OccurrenceHorizonExtender unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Services\OccurrenceHorizonExtender;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Tests\Factories\EventFactory;
use NetterTechEvents\Tests\Factories\OccurrenceFactory;

/**
 * Test OccurrenceHorizonExtender functionality.
 */
class OccurrenceHorizonExtenderTest extends \NetterTechEventsTestCase {

	/**
	 * OccurrenceHorizonExtender instance.
	 *
	 * @var OccurrenceHorizonExtender
	 */
	private OccurrenceHorizonExtender $extender;

	/**
	 * Mock EventRepository.
	 *
	 * @var EventRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_repo;

	/**
	 * Mock OccurrenceRepository.
	 *
	 * @var OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Mock RecurrenceService.
	 *
	 * @var RecurrenceService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $recurrence_service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->event_repo         = $this->createMock( EventRepositoryInterface::class );
		$this->occurrence_repo    = $this->createMock( OccurrenceRepositoryInterface::class );
		$this->recurrence_service = $this->createMock( RecurrenceService::class );

		$this->extender = new OccurrenceHorizonExtender(
			$this->event_repo,
			$this->occurrence_repo,
			$this->recurrence_service
		);

		EventFactory::reset();
		OccurrenceFactory::reset();
	}

	// =========================================================================
	// register() Tests
	// =========================================================================

	/**
	 * Test register adds the expected action hooks.
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

		$this->extender->register();

		$this->assertContains( Hooks::GENERATE_OCCURRENCES_CRON, $hooks_added );
		$this->assertContains( OccurrenceHorizonExtender::BATCH_CONTINUATION_HOOK, $hooks_added );
	}

	// =========================================================================
	// process_batch() Tests
	// =========================================================================

	/**
	 * Test events within threshold get extended.
	 *
	 * @return void
	 */
	public function test_events_within_threshold_get_extended(): void {
		$event                  = EventFactory::recurring();
		$event->recurrence_rule = 'FREQ=WEEKLY;BYDAY=MO';

		$this->event_repo
			->method( 'all' )
			->willReturn( array( $event ) );

		// Latest occurrence is 10 days from now (within 30-day threshold).
		$latest_date = ( new \DateTimeImmutable() )->modify( '+10 days' );
		$latest      = OccurrenceFactory::forDate(
			$latest_date->format( 'Y-m-d' ),
			'19:00',
			'21:00',
			array( 'event_id' => $event->id )
		);

		// Earliest occurrence for start/end times.
		$earliest = OccurrenceFactory::forDate(
			'2025-01-06',
			'19:00',
			'21:00',
			array( 'event_id' => $event->id )
		);

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturnCallback(
				function ( int $event_id, array $args ) use ( $latest, $earliest ) {
					if ( isset( $args['order'] ) && 'DESC' === $args['order'] ) {
						return array( $latest );
					}
					return array( $earliest );
				}
			);

		$this->recurrence_service
			->expects( $this->once() )
			->method( 'regenerate_future_occurrences' )
			->willReturn(
				array(
					'generated' => 52,
					'saved'     => 52,
					'deleted'   => 0,
					'protected' => 0,
					'errors'    => array(),
				)
			);

		$result = $this->extender->process_batch();

		$this->assertSame( 1, $result['extended'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( 0, $result['errors'] );
	}

	/**
	 * Test events NOT within threshold are skipped.
	 *
	 * @return void
	 */
	public function test_events_beyond_threshold_are_skipped(): void {
		$event                  = EventFactory::recurring();
		$event->recurrence_rule = 'FREQ=WEEKLY;BYDAY=MO';

		$this->event_repo
			->method( 'all' )
			->willReturn( array( $event ) );

		// Latest occurrence is 60 days from now (beyond 30-day threshold).
		$latest_date = ( new \DateTimeImmutable() )->modify( '+60 days' );
		$latest      = OccurrenceFactory::forDate(
			$latest_date->format( 'Y-m-d' ),
			'19:00',
			'21:00',
			array( 'event_id' => $event->id )
		);

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array( $latest ) );

		$this->recurrence_service
			->expects( $this->never() )
			->method( 'regenerate_future_occurrences' );

		$result = $this->extender->process_batch();

		$this->assertSame( 0, $result['extended'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	/**
	 * Test events with only past occurrences are treated as dormant.
	 *
	 * Regression: previously, recurring events whose latest occurrence was
	 * in the past would get future occurrences generated by the extender —
	 * producing "phantom" future events for migrated stale series (like the
	 * CJAC Harp Resonance Sessions that ended Nov 2025 sprouting May 2026
	 * occurrences). The policy: only actively-scheduled series (latest
	 * occurrence is still in the future) qualify for horizon extension.
	 *
	 * @return void
	 */
	public function test_events_with_only_past_occurrences_are_dormant(): void {
		$event                  = EventFactory::recurring();
		$event->recurrence_rule = 'FREQ=WEEKLY;BYDAY=MO';

		$this->event_repo
			->method( 'all' )
			->willReturn( array( $event ) );

		// Latest occurrence is in the past — series is concluded.
		$past_latest = OccurrenceFactory::forDate(
			'2025-11-02',
			'13:30',
			'15:00',
			array( 'event_id' => $event->id )
		);

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array( $past_latest ) );

		// Critical assertion: extender must NOT call recurrence_service to
		// generate new occurrences for a dormant series.
		$this->recurrence_service
			->expects( $this->never() )
			->method( 'regenerate_future_occurrences' );

		$result = $this->extender->process_batch();

		$this->assertSame( 0, $result['extended'] );
		// Dormant events count toward skipped in the public tally.
		$this->assertSame( 1, $result['skipped'] );
	}

	/**
	 * Test events without RRULE are skipped.
	 *
	 * @return void
	 */
	public function test_events_without_rrule_are_skipped(): void {
		$event                  = EventFactory::recurring();
		$event->recurrence_rule = null;

		$this->event_repo
			->method( 'all' )
			->willReturn( array( $event ) );

		$this->recurrence_service
			->expects( $this->never() )
			->method( 'regenerate_future_occurrences' );

		$result = $this->extender->process_batch();

		$this->assertSame( 0, $result['extended'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	/**
	 * Test events with empty RRULE string are skipped.
	 *
	 * @return void
	 */
	public function test_events_with_empty_rrule_are_skipped(): void {
		$event                  = EventFactory::recurring();
		$event->recurrence_rule = '';

		$this->event_repo
			->method( 'all' )
			->willReturn( array( $event ) );

		$this->recurrence_service
			->expects( $this->never() )
			->method( 'regenerate_future_occurrences' );

		$result = $this->extender->process_batch();

		$this->assertSame( 0, $result['extended'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	/**
	 * Test batch processing schedules continuation when full batch returned.
	 *
	 * @return void
	 */
	public function test_batch_schedules_continuation_when_full(): void {
		// Create a full batch of events (50).
		$events = array();
		for ( $i = 0; $i < OccurrenceHorizonExtender::DEFAULT_BATCH_SIZE; $i++ ) {
			$event                  = EventFactory::recurring( array( 'id' => $i + 1 ) );
			$event->recurrence_rule = null; // Skipped, but triggers batch logic.
			$events[]               = $event;
		}

		$this->event_repo
			->method( 'all' )
			->willReturn( $events );

		$scheduled_hook   = null;
		$scheduled_args   = null;
		$scheduled_called = false;

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $time, $hook, $args = array() ) use ( &$scheduled_hook, &$scheduled_args, &$scheduled_called ) {
				$scheduled_hook   = $hook;
				$scheduled_args   = $args;
				$scheduled_called = true;
				return true;
			}
		);

		$result = $this->extender->process_batch( 0 );

		$this->assertSame( OccurrenceHorizonExtender::DEFAULT_BATCH_SIZE, $result['skipped'] );
		$this->assertTrue( $scheduled_called, 'wp_schedule_single_event should be called for continuation' );
		$this->assertSame( OccurrenceHorizonExtender::BATCH_CONTINUATION_HOOK, $scheduled_hook );
		$this->assertSame( array( OccurrenceHorizonExtender::DEFAULT_BATCH_SIZE ), $scheduled_args );
	}

	/**
	 * Test batch does NOT schedule continuation when less than full batch.
	 *
	 * @return void
	 */
	public function test_batch_no_continuation_when_partial(): void {
		$event                  = EventFactory::recurring();
		$event->recurrence_rule = null;

		$this->event_repo
			->method( 'all' )
			->willReturn( array( $event ) );

		$scheduled_called = false;

		Functions\when( 'wp_schedule_single_event' )->alias(
			function () use ( &$scheduled_called ) {
				$scheduled_called = true;
				return true;
			}
		);

		$this->extender->process_batch( 0 );

		$this->assertFalse( $scheduled_called, 'wp_schedule_single_event should NOT be called for partial batch' );
	}

	/**
	 * Test empty event list returns zero counts.
	 *
	 * @return void
	 */
	public function test_empty_event_list_returns_zeros(): void {
		$this->event_repo
			->method( 'all' )
			->willReturn( array() );

		$result = $this->extender->process_batch();

		$this->assertSame( 0, $result['extended'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( 0, $result['errors'] );
	}

	/**
	 * Test events with no occurrences generate from now.
	 *
	 * @return void
	 */
	public function test_events_with_no_occurrences_generate_from_now(): void {
		$event                  = EventFactory::recurring();
		$event->recurrence_rule = 'FREQ=WEEKLY;BYDAY=MO';

		$this->event_repo
			->method( 'all' )
			->willReturn( array( $event ) );

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturn( array() );

		$this->recurrence_service
			->expects( $this->once() )
			->method( 'generate_occurrences' )
			->willReturn(
				array(
					'generated' => 52,
					'saved'     => 52,
					'protected' => 0,
					'errors'    => array(),
				)
			);

		$result = $this->extender->process_batch();

		$this->assertSame( 1, $result['extended'] );
	}

	/**
	 * Test regeneration errors are counted.
	 *
	 * @return void
	 */
	public function test_regeneration_errors_are_counted(): void {
		$event                  = EventFactory::recurring();
		$event->recurrence_rule = 'FREQ=WEEKLY;BYDAY=MO';

		$this->event_repo
			->method( 'all' )
			->willReturn( array( $event ) );

		// Latest occurrence is within threshold.
		$latest_date = ( new \DateTimeImmutable() )->modify( '+5 days' );
		$latest      = OccurrenceFactory::forDate(
			$latest_date->format( 'Y-m-d' ),
			'19:00',
			'21:00',
			array( 'event_id' => $event->id )
		);

		$earliest = OccurrenceFactory::forDate(
			'2025-01-06',
			'19:00',
			'21:00',
			array( 'event_id' => $event->id )
		);

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturnCallback(
				function ( int $event_id, array $args ) use ( $latest, $earliest ) {
					if ( isset( $args['order'] ) && 'DESC' === $args['order'] ) {
						return array( $latest );
					}
					return array( $earliest );
				}
			);

		$this->recurrence_service
			->method( 'regenerate_future_occurrences' )
			->willReturn(
				array(
					'generated' => 0,
					'saved'     => 0,
					'deleted'   => 0,
					'protected' => 0,
					'errors'    => array( 'Invalid RRULE' ),
				)
			);

		$result = $this->extender->process_batch();

		$this->assertSame( 0, $result['extended'] );
		$this->assertSame( 1, $result['errors'] );
	}

	/**
	 * Test threshold filter is applied.
	 *
	 * @return void
	 */
	public function test_threshold_filter_is_applied(): void {
		// Set threshold to 90 days via filter.
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				if ( 'nettertech_events_horizon_extension_threshold' === $hook ) {
					return 90;
				}
				if ( 'nettertech_events_horizon_extension_batch_size' === $hook ) {
					return $value;
				}
				return $value;
			}
		);

		$event                  = EventFactory::recurring();
		$event->recurrence_rule = 'FREQ=WEEKLY;BYDAY=MO';

		$this->event_repo
			->method( 'all' )
			->willReturn( array( $event ) );

		// Latest occurrence is 60 days from now.
		// With default 30-day threshold this would be skipped.
		// With 90-day threshold this should be extended.
		$latest_date = ( new \DateTimeImmutable() )->modify( '+60 days' );
		$latest      = OccurrenceFactory::forDate(
			$latest_date->format( 'Y-m-d' ),
			'19:00',
			'21:00',
			array( 'event_id' => $event->id )
		);

		$earliest = OccurrenceFactory::forDate(
			'2025-01-06',
			'19:00',
			'21:00',
			array( 'event_id' => $event->id )
		);

		$this->occurrence_repo
			->method( 'for_event' )
			->willReturnCallback(
				function ( int $event_id, array $args ) use ( $latest, $earliest ) {
					if ( isset( $args['order'] ) && 'DESC' === $args['order'] ) {
						return array( $latest );
					}
					return array( $earliest );
				}
			);

		$this->recurrence_service
			->expects( $this->once() )
			->method( 'regenerate_future_occurrences' )
			->willReturn(
				array(
					'generated' => 52,
					'saved'     => 52,
					'deleted'   => 0,
					'protected' => 0,
					'errors'    => array(),
				)
			);

		$result = $this->extender->process_batch();

		$this->assertSame( 1, $result['extended'] );
	}

	/**
	 * Test batch size filter is applied.
	 *
	 * @return void
	 */
	public function test_batch_size_filter_is_applied(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				if ( 'nettertech_events_horizon_extension_batch_size' === $hook ) {
					return 10;
				}
				return $value;
			}
		);

		$this->event_repo
			->expects( $this->once() )
			->method( 'all' )
			->with(
				$this->callback(
					function ( array $args ): bool {
						return 10 === $args['limit'];
					}
				)
			)
			->willReturn( array() );

		$result = $this->extender->process_batch();

		$this->assertSame( 0, $result['extended'] );
	}

	/**
	 * Test process_batch passes correct args to event repository.
	 *
	 * @return void
	 */
	public function test_process_batch_queries_recurring_published_events(): void {
		$this->event_repo
			->expects( $this->once() )
			->method( 'all' )
			->with(
				$this->callback(
					function ( array $args ): bool {
						return 'recurring' === $args['event_type']
							&& 'published' === $args['status']
							&& OccurrenceHorizonExtender::DEFAULT_BATCH_SIZE === $args['limit']
							&& 0 === $args['offset'];
					}
				)
			)
			->willReturn( array() );

		$this->extender->process_batch( 0 );
	}

	/**
	 * Test process_batch uses offset for pagination.
	 *
	 * @return void
	 */
	public function test_process_batch_uses_offset(): void {
		$this->event_repo
			->expects( $this->once() )
			->method( 'all' )
			->with(
				$this->callback(
					function ( array $args ): bool {
						return 100 === $args['offset'];
					}
				)
			)
			->willReturn( array() );

		$this->extender->process_batch( 100 );
	}

	/**
	 * Test events with null ID are skipped.
	 *
	 * @return void
	 */
	public function test_events_with_null_id_are_skipped(): void {
		$event                  = EventFactory::recurring();
		$event->id              = null;
		$event->recurrence_rule = 'FREQ=WEEKLY;BYDAY=MO';

		$this->event_repo
			->method( 'all' )
			->willReturn( array( $event ) );

		$this->recurrence_service
			->expects( $this->never() )
			->method( 'regenerate_future_occurrences' );

		$result = $this->extender->process_batch();

		$this->assertSame( 1, $result['skipped'] );
	}
}
