<?php
/**
 * ActivityLogService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Services\ActivityLogService;
use NetterTechEvents\Repositories\ActivityLogRepository;
use NetterTechEvents\Models\ActivityLog;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use Brain\Monkey\Actions;
use Mockery;

/**
 * Test ActivityLogService functionality.
 */
class ActivityLogServiceTest extends \NetterTechEventsTestCase {

	/**
	 * Mock repository.
	 *
	 * @var ActivityLogRepository|Mockery\MockInterface
	 */
	private $repository;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->repository = Mockery::mock( ActivityLogRepository::class );

		// Default: logging enabled.
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value ) {
				if ( 'nettertech_events_activity_logging_enabled' === $filter ) {
					return true;
				}
				if ( 'nettertech_events_activity_log_retention_days' === $filter ) {
					return $value;
				}
				return $value;
			}
		);
	}

	/**
	 * Tear down and verify Mockery expectations.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// Count Mockery expectations as PHPUnit assertions.
		$container = \Mockery::getContainer();
		if ( $container !== null ) {
			$this->addToAssertionCount( $container->mockery_getExpectationCount() );
		}
		parent::tearDown();
	}

	// =========================================================================
	// Constructor tests
	// =========================================================================

	/**
	 * Test constructor initializes service.
	 *
	 * @return void
	 */
	public function test_constructor_initializes_service(): void {
		$service = new ActivityLogService( $this->repository );

		$this->assertInstanceOf( ActivityLogService::class, $service );
	}

	/**
	 * Test constructor checks logging enabled filter.
	 *
	 * @return void
	 */
	public function test_constructor_checks_logging_filter(): void {
		$filter_called = false;

		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value ) use ( &$filter_called ) {
				if ( 'nettertech_events_activity_logging_enabled' === $filter ) {
					$filter_called = true;
					return true;
				}
				return $value;
			}
		);

		new ActivityLogService( $this->repository );

		$this->assertTrue( $filter_called );
	}

	// =========================================================================
	// log_event tests
	// =========================================================================

	/**
	 * Test log_event calls repository log method.
	 *
	 * @return void
	 */
	public function test_log_event_calls_repository(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$captured_args = array();

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $details ) use ( &$captured_args ) {
					$captured_args = compact( 'action', 'object_type', 'object_id', 'title', 'details' );
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_event( 'create', 123, 'Test Event' );

		$this->assertSame( 'create', $captured_args['action'] );
		$this->assertSame( 'event', $captured_args['object_type'] );
		$this->assertSame( 123, $captured_args['object_id'] );
		$this->assertSame( 'Test Event', $captured_args['title'] );
		$this->assertNull( $captured_args['details'] );
	}

	/**
	 * Test log_event passes details to repository.
	 *
	 * @return void
	 */
	public function test_log_event_passes_details(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$details       = array( 'old_status' => 'draft', 'new_status' => 'published' );
		$captured_args = array();

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $det ) use ( &$captured_args ) {
					$captured_args = array(
						'action'      => $action,
						'object_type' => $object_type,
						'object_id'   => $object_id,
						'title'       => $title,
						'details'     => $det,
					);
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_event( 'update', 456, 'Updated Event', $details );

		$this->assertSame( 'update', $captured_args['action'] );
		$this->assertSame( 'event', $captured_args['object_type'] );
		$this->assertSame( 456, $captured_args['object_id'] );
		$this->assertIsArray( $captured_args['details'] );
		$this->assertArrayHasKey( 'old_status', $captured_args['details'] );
		$this->assertArrayHasKey( 'new_status', $captured_args['details'] );
		$this->assertSame( 'draft', $captured_args['details']['old_status'] );
		$this->assertSame( 'published', $captured_args['details']['new_status'] );
	}

	/**
	 * Test log_event fires action after logging.
	 *
	 * @return void
	 */
	public function test_log_event_fires_action(): void {
		$action_fired = false;
		$action_args  = array();

		Functions\when( 'do_action' )->alias(
			function ( $action, ...$args ) use ( &$action_fired, &$action_args ) {
				if ( 'nettertech_events_activity_logged' === $action ) {
					$action_fired = true;
					$action_args  = $args;
				}
			}
		);

		$this->repository->shouldReceive( 'log' )->once();

		$service = new ActivityLogService( $this->repository );
		$service->log_event( 'delete', 789, 'Deleted Event' );

		$this->assertTrue( $action_fired );
		$this->assertSame( 'delete', $action_args[0] );
		$this->assertSame( 'event', $action_args[1] );
		$this->assertSame( 789, $action_args[2] );
	}

	/**
	 * Test log_event does nothing when logging disabled.
	 *
	 * @return void
	 */
	public function test_log_event_skips_when_disabled(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value ) {
				if ( 'nettertech_events_activity_logging_enabled' === $filter ) {
					return false;
				}
				return $value;
			}
		);

		$this->repository->shouldNotReceive( 'log' );

		$service = new ActivityLogService( $this->repository );
		$service->log_event( 'create', 123, 'Test Event' );
	}

	// =========================================================================
	// log_occurrence tests
	// =========================================================================

	/**
	 * Test log_occurrence calls repository.
	 *
	 * @return void
	 */
	public function test_log_occurrence_calls_repository(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$captured_args = array();

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $details ) use ( &$captured_args ) {
					$captured_args = compact( 'action', 'object_type', 'object_id', 'title', 'details' );
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_occurrence( 'cancel', 100, 'March 15 Occurrence' );

		$this->assertSame( 'cancel', $captured_args['action'] );
		$this->assertSame( 'occurrence', $captured_args['object_type'] );
		$this->assertSame( 100, $captured_args['object_id'] );
		$this->assertSame( 'March 15 Occurrence', $captured_args['title'] );
		$this->assertNull( $captured_args['details'] );
	}

	/**
	 * Test log_occurrence with details.
	 *
	 * @return void
	 */
	public function test_log_occurrence_with_details(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$details       = array( 'reason' => 'weather' );
		$captured_args = array();

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $det ) use ( &$captured_args ) {
					$captured_args = array(
						'action'      => $action,
						'object_type' => $object_type,
						'object_id'   => $object_id,
						'title'       => $title,
						'details'     => $det,
					);
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_occurrence( 'reschedule', 200, 'Rescheduled', $details );

		$this->assertSame( 'reschedule', $captured_args['action'] );
		$this->assertSame( 'occurrence', $captured_args['object_type'] );
		$this->assertIsArray( $captured_args['details'] );
		$this->assertSame( 'weather', $captured_args['details']['reason'] );
	}

	/**
	 * Test log_occurrence skips when disabled.
	 *
	 * @return void
	 */
	public function test_log_occurrence_skips_when_disabled(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value ) {
				if ( 'nettertech_events_activity_logging_enabled' === $filter ) {
					return false;
				}
				return $value;
			}
		);

		$this->repository->shouldNotReceive( 'log' );

		$service = new ActivityLogService( $this->repository );
		$service->log_occurrence( 'cancel', 100, 'Test' );
	}

	// =========================================================================
	// log_attendee tests
	// =========================================================================

	/**
	 * Test log_attendee calls repository.
	 *
	 * @return void
	 */
	public function test_log_attendee_calls_repository(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$captured_args = array();

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $details ) use ( &$captured_args ) {
					$captured_args = compact( 'action', 'object_type', 'object_id', 'title', 'details' );
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_attendee( 'check_in', 50, 'John Doe' );

		$this->assertSame( 'check_in', $captured_args['action'] );
		$this->assertSame( 'attendee', $captured_args['object_type'] );
		$this->assertSame( 50, $captured_args['object_id'] );
		$this->assertSame( 'John Doe', $captured_args['title'] );
		$this->assertNull( $captured_args['details'] );
	}

	/**
	 * Test log_attendee with details.
	 *
	 * @return void
	 */
	public function test_log_attendee_with_details(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$details       = array( 'check_in_method' => 'qr_code', 'station' => 'front_entrance' );
		$captured_args = array();

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $det ) use ( &$captured_args ) {
					$captured_args = array(
						'action'      => $action,
						'object_type' => $object_type,
						'object_id'   => $object_id,
						'title'       => $title,
						'details'     => $det,
					);
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_attendee( 'check_in', 75, 'Jane Smith', $details );

		$this->assertSame( 'check_in', $captured_args['action'] );
		$this->assertSame( 'attendee', $captured_args['object_type'] );
		$this->assertSame( 75, $captured_args['object_id'] );
		$this->assertIsArray( $captured_args['details'] );
		$this->assertCount( 2, $captured_args['details'] );
		$this->assertSame( 'qr_code', $captured_args['details']['check_in_method'] );
		$this->assertSame( 'front_entrance', $captured_args['details']['station'] );
	}

	/**
	 * Test log_attendee skips when disabled.
	 *
	 * @return void
	 */
	public function test_log_attendee_skips_when_disabled(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value ) {
				if ( 'nettertech_events_activity_logging_enabled' === $filter ) {
					return false;
				}
				return $value;
			}
		);

		$this->repository->shouldNotReceive( 'log' );

		$service = new ActivityLogService( $this->repository );
		$service->log_attendee( 'check_in', 50, 'John Doe' );
	}

	// =========================================================================
	// log_ticket_type tests
	// =========================================================================

	/**
	 * Test log_ticket_type calls repository.
	 *
	 * @return void
	 */
	public function test_log_ticket_type_calls_repository(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$captured_args = array();

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $details ) use ( &$captured_args ) {
					$captured_args = compact( 'action', 'object_type', 'object_id', 'title', 'details' );
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_ticket_type( 'create', 10, 'General Admission' );

		$this->assertSame( 'create', $captured_args['action'] );
		$this->assertSame( 'ticket_type', $captured_args['object_type'] );
		$this->assertSame( 10, $captured_args['object_id'] );
		$this->assertSame( 'General Admission', $captured_args['title'] );
		$this->assertNull( $captured_args['details'] );
	}

	/**
	 * Test log_ticket_type with details.
	 *
	 * @return void
	 */
	public function test_log_ticket_type_with_details(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$details       = array( 'old_price' => 25.00, 'new_price' => 30.00 );
		$captured_args = array();

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $det ) use ( &$captured_args ) {
					$captured_args = array(
						'action'      => $action,
						'object_type' => $object_type,
						'object_id'   => $object_id,
						'title'       => $title,
						'details'     => $det,
					);
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_ticket_type( 'update', 20, 'VIP Pass', $details );

		$this->assertSame( 'update', $captured_args['action'] );
		$this->assertSame( 'ticket_type', $captured_args['object_type'] );
		$this->assertSame( 20, $captured_args['object_id'] );
		$this->assertIsArray( $captured_args['details'] );
		$this->assertSame( 25.00, $captured_args['details']['old_price'] );
		$this->assertSame( 30.00, $captured_args['details']['new_price'] );
	}

	/**
	 * Test log_ticket_type skips when disabled.
	 *
	 * @return void
	 */
	public function test_log_ticket_type_skips_when_disabled(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value ) {
				if ( 'nettertech_events_activity_logging_enabled' === $filter ) {
					return false;
				}
				return $value;
			}
		);

		$this->repository->shouldNotReceive( 'log' );

		$service = new ActivityLogService( $this->repository );
		$service->log_ticket_type( 'create', 10, 'General Admission' );
	}

	// =========================================================================
	// log_settings tests
	// =========================================================================

	/**
	 * Test log_settings calls repository.
	 *
	 * @return void
	 */
	public function test_log_settings_calls_repository(): void {
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( '__' )->returnArg();

		$changes       = array( 'events_per_page' => array( 'old' => 10, 'new' => 20 ) );
		$captured_args = array();

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $details ) use ( &$captured_args ) {
					$captured_args = compact( 'action', 'object_type', 'object_id', 'title', 'details' );
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_settings( $changes );

		$this->assertSame( 'settings_update', $captured_args['action'] );
		$this->assertSame( 'settings', $captured_args['object_type'] );
		$this->assertNull( $captured_args['object_id'] );
		$this->assertSame( 'Plugin Settings', $captured_args['title'] );
		$this->assertIsArray( $captured_args['details'] );
		$this->assertArrayHasKey( 'events_per_page', $captured_args['details'] );
		$this->assertSame( 10, $captured_args['details']['events_per_page']['old'] );
		$this->assertSame( 20, $captured_args['details']['events_per_page']['new'] );
	}

	/**
	 * Test log_settings fires action.
	 *
	 * @return void
	 */
	public function test_log_settings_fires_action(): void {
		Functions\when( '__' )->returnArg();

		$action_fired = false;

		Functions\when( 'do_action' )->alias(
			function ( $action, ...$args ) use ( &$action_fired ) {
				if ( 'nettertech_events_activity_logged' === $action ) {
					$action_fired = true;
				}
			}
		);

		$this->repository->shouldReceive( 'log' )->once();

		$service = new ActivityLogService( $this->repository );
		$service->log_settings( array( 'timezone' => 'America/Chicago' ) );

		$this->assertTrue( $action_fired );
	}

	/**
	 * Test log_settings skips when disabled.
	 *
	 * @return void
	 */
	public function test_log_settings_skips_when_disabled(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value ) {
				if ( 'nettertech_events_activity_logging_enabled' === $filter ) {
					return false;
				}
				return $value;
			}
		);

		$this->repository->shouldNotReceive( 'log' );

		$service = new ActivityLogService( $this->repository );
		$service->log_settings( array( 'test' => 'value' ) );
	}

	// =========================================================================
	// log_export tests
	// =========================================================================

	/**
	 * Test log_export calls repository.
	 *
	 * @return void
	 */
	public function test_log_export_calls_repository(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$parameters    = array( 'event_id' => 123, 'format' => 'csv' );
		$captured_args = array();

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $details ) use ( &$captured_args ) {
					$captured_args = compact( 'action', 'object_type', 'object_id', 'title', 'details' );
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_export( 'attendees', $parameters );

		$this->assertSame( 'export', $captured_args['action'] );
		$this->assertSame( 'attendees', $captured_args['object_type'] );
		$this->assertNull( $captured_args['object_id'] );
		$this->assertStringContainsString( 'Export', $captured_args['title'] );
		$this->assertIsArray( $captured_args['details'] );
		$this->assertSame( 123, $captured_args['details']['event_id'] );
		$this->assertSame( 'csv', $captured_args['details']['format'] );
	}

	/**
	 * Test log_export with events export type.
	 *
	 * @return void
	 */
	public function test_log_export_events_type(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$captured_args = array();

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $details ) use ( &$captured_args ) {
					$captured_args = compact( 'action', 'object_type', 'object_id', 'title', 'details' );
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_export( 'events', array() );

		$this->assertSame( 'export', $captured_args['action'] );
		$this->assertSame( 'events', $captured_args['object_type'] );
		$this->assertNull( $captured_args['object_id'] );
		$this->assertSame( 'Events Export', $captured_args['title'] );
		$this->assertSame( array(), $captured_args['details'] );
	}

	/**
	 * Test log_export skips when disabled.
	 *
	 * @return void
	 */
	public function test_log_export_skips_when_disabled(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value ) {
				if ( 'nettertech_events_activity_logging_enabled' === $filter ) {
					return false;
				}
				return $value;
			}
		);

		$this->repository->shouldNotReceive( 'log' );

		$service = new ActivityLogService( $this->repository );
		$service->log_export( 'attendees', array() );
	}

	// =========================================================================
	// log_series tests
	// =========================================================================

	/**
	 * Test log_series calls repository.
	 *
	 * @return void
	 */
	public function test_log_series_calls_repository(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$captured_args = array();

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $details ) use ( &$captured_args ) {
					$captured_args = compact( 'action', 'object_type', 'object_id', 'title', 'details' );
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_series( 'create', 5, 'Weekly Workshop Series' );

		$this->assertSame( 'create', $captured_args['action'] );
		$this->assertSame( 'series', $captured_args['object_type'] );
		$this->assertSame( 5, $captured_args['object_id'] );
		$this->assertSame( 'Weekly Workshop Series', $captured_args['title'] );
		$this->assertNull( $captured_args['details'] );
	}

	/**
	 * Test log_series with details.
	 *
	 * @return void
	 */
	public function test_log_series_with_details(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$details       = array( 'events_count' => 12 );
		$captured_args = array();

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $det ) use ( &$captured_args ) {
					$captured_args = array(
						'action'      => $action,
						'object_type' => $object_type,
						'object_id'   => $object_id,
						'title'       => $title,
						'details'     => $det,
					);
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_series( 'update', 10, 'Monthly Concert', $details );

		$this->assertSame( 'update', $captured_args['action'] );
		$this->assertSame( 'series', $captured_args['object_type'] );
		$this->assertSame( 10, $captured_args['object_id'] );
		$this->assertIsArray( $captured_args['details'] );
		$this->assertSame( 12, $captured_args['details']['events_count'] );
	}

	/**
	 * Test log_series skips when disabled.
	 *
	 * @return void
	 */
	public function test_log_series_skips_when_disabled(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value ) {
				if ( 'nettertech_events_activity_logging_enabled' === $filter ) {
					return false;
				}
				return $value;
			}
		);

		$this->repository->shouldNotReceive( 'log' );

		$service = new ActivityLogService( $this->repository );
		$service->log_series( 'create', 5, 'Test Series' );
	}

	// =========================================================================
	// get_logs tests
	// =========================================================================

	/**
	 * Test get_logs returns paginated results.
	 *
	 * @return void
	 */
	public function test_get_logs_returns_paginated_results(): void {
		$expected = array(
			'items' => array(),
			'total' => 100,
			'pages' => 5,
		);

		$this->repository
			->shouldReceive( 'paginate' )
			->once()
			->with( 1, 20, array() )
			->andReturn( $expected );

		$service = new ActivityLogService( $this->repository );
		$result  = $service->get_logs();

		$this->assertSame( $expected, $result );
	}

	/**
	 * Test get_logs with custom page and per_page.
	 *
	 * @return void
	 */
	public function test_get_logs_with_pagination_params(): void {
		$expected = array( 'items' => array(), 'total' => 0, 'pages' => 0 );

		$this->repository
			->shouldReceive( 'paginate' )
			->once()
			->with( 3, 50, array() )
			->andReturn( $expected );

		$service = new ActivityLogService( $this->repository );
		$result  = $service->get_logs( 3, 50 );

		$this->assertSame( $expected, $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'pages', $result );
		$this->assertSame( 0, $result['total'] );
		$this->assertSame( 0, $result['pages'] );
		$this->assertCount( 0, $result['items'] );
	}

	/**
	 * Test get_logs with filters.
	 *
	 * @return void
	 */
	public function test_get_logs_with_filters(): void {
		$filters  = array( 'object_type' => 'event', 'action' => 'create' );
		$expected = array( 'items' => array(), 'total' => 0, 'pages' => 0 );

		$captured_filters = null;

		$this->repository
			->shouldReceive( 'paginate' )
			->once()
			->withArgs(
				function ( $page, $per_page, $f ) use ( &$captured_filters ) {
					$captured_filters = $f;
					return true;
				}
			)
			->andReturn( $expected );

		$service = new ActivityLogService( $this->repository );
		$result  = $service->get_logs( 1, 20, $filters );

		$this->assertSame( $expected, $result );
		$this->assertSame( $filters, $captured_filters );
		$this->assertSame( 'event', $captured_filters['object_type'] );
		$this->assertSame( 'create', $captured_filters['action'] );
	}

	// =========================================================================
	// get_object_history tests
	// =========================================================================

	/**
	 * Test get_object_history returns activity logs.
	 *
	 * @return void
	 */
	public function test_get_object_history_returns_logs(): void {
		$logs = array(
			$this->createMock( ActivityLog::class ),
			$this->createMock( ActivityLog::class ),
		);

		$this->repository
			->shouldReceive( 'get_for_object' )
			->once()
			->with( 'event', 123 )
			->andReturn( $logs );

		$service = new ActivityLogService( $this->repository );
		$result  = $service->get_object_history( 'event', 123 );

		$this->assertCount( 2, $result );
	}

	/**
	 * Test get_object_history for attendee.
	 *
	 * @return void
	 */
	public function test_get_object_history_for_attendee(): void {
		$this->repository
			->shouldReceive( 'get_for_object' )
			->once()
			->with( 'attendee', 456 )
			->andReturn( array() );

		$service = new ActivityLogService( $this->repository );
		$result  = $service->get_object_history( 'attendee', 456 );

		$this->assertIsArray( $result );
		$this->assertCount( 0, $result );
	}

	// =========================================================================
	// get_action_types tests
	// =========================================================================

	/**
	 * Test get_action_types returns available actions.
	 *
	 * @return void
	 */
	public function test_get_action_types_returns_actions(): void {
		$actions = array( 'create', 'update', 'delete', 'check_in' );

		$this->repository
			->shouldReceive( 'get_action_types' )
			->once()
			->andReturn( $actions );

		$service = new ActivityLogService( $this->repository );
		$result  = $service->get_action_types();

		$this->assertSame( $actions, $result );
	}

	// =========================================================================
	// get_object_types tests
	// =========================================================================

	/**
	 * Test get_object_types returns available object types.
	 *
	 * @return void
	 */
	public function test_get_object_types_returns_types(): void {
		$types = array( 'event', 'occurrence', 'attendee', 'ticket_type' );

		$this->repository
			->shouldReceive( 'get_object_types' )
			->once()
			->andReturn( $types );

		$service = new ActivityLogService( $this->repository );
		$result  = $service->get_object_types();

		$this->assertSame( $types, $result );
	}

	// =========================================================================
	// cleanup tests
	// =========================================================================

	/**
	 * Test cleanup calls repository with default retention.
	 *
	 * @return void
	 */
	public function test_cleanup_with_default_retention(): void {
		$this->repository
			->shouldReceive( 'cleanup' )
			->once()
			->with( 90 )
			->andReturn( 50 );

		$service = new ActivityLogService( $this->repository );
		$result  = $service->cleanup();

		$this->assertSame( 50, $result );
	}

	/**
	 * Test cleanup with custom retention days.
	 *
	 * @return void
	 */
	public function test_cleanup_with_custom_retention(): void {
		$this->repository
			->shouldReceive( 'cleanup' )
			->once()
			->with( 30 )
			->andReturn( 100 );

		$service = new ActivityLogService( $this->repository );
		$result  = $service->cleanup( 30 );

		$this->assertSame( 100, $result );
	}

	/**
	 * Test cleanup uses filtered retention days.
	 *
	 * @return void
	 */
	public function test_cleanup_uses_filtered_retention(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value ) {
				if ( 'nettertech_events_activity_log_retention_days' === $filter ) {
					return 180; // Filter changes retention to 180 days.
				}
				if ( 'nettertech_events_activity_logging_enabled' === $filter ) {
					return true;
				}
				return $value;
			}
		);

		$this->repository
			->shouldReceive( 'cleanup' )
			->once()
			->with( 180 )
			->andReturn( 25 );

		$service = new ActivityLogService( $this->repository );
		$result  = $service->cleanup( 90 ); // Passed 90 but filter changes to 180.

		$this->assertSame( 25, $result );
	}

	/**
	 * Test cleanup returns deleted count.
	 *
	 * @return void
	 */
	public function test_cleanup_returns_deleted_count(): void {
		$this->repository
			->shouldReceive( 'cleanup' )
			->once()
			->andReturn( 0 );

		$service = new ActivityLogService( $this->repository );
		$result  = $service->cleanup();

		$this->assertSame( 0, $result );
		$this->assertIsInt( $result );
	}

	// =========================================================================
	// Edge case tests
	// =========================================================================

	/**
	 * Test log_event with empty details passes empty array.
	 *
	 * @return void
	 */
	public function test_log_event_with_empty_details_passes_empty_array(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$captured_details = 'not_set';

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $details ) use ( &$captured_details ) {
					$captured_details = $details;
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_event( 'create', 999, 'Empty Details Event', array() );

		$this->assertIsArray( $captured_details );
		$this->assertCount( 0, $captured_details );
		$this->assertSame( array(), $captured_details );
	}

	/**
	 * Test log_event with special characters in title.
	 *
	 * @return void
	 */
	public function test_log_event_with_special_characters_in_title(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$special_title  = 'Event "With" <Special> & Characters\'s';
		$captured_title = '';

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title ) use ( &$captured_title ) {
					$captured_title = $title;
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log_event( 'create', 500, $special_title );

		$this->assertSame( $special_title, $captured_title );
		$this->assertStringContainsString( '"With"', $captured_title );
		$this->assertStringContainsString( '<Special>', $captured_title );
		$this->assertStringContainsString( '&', $captured_title );
		$this->assertStringContainsString( "Characters's", $captured_title );
	}

	/**
	 * Test get_logs with all filters verifies filter propagation.
	 *
	 * @return void
	 */
	public function test_get_logs_with_all_filters(): void {
		$filters = array(
			'object_type' => 'occurrence',
			'action'      => 'cancel',
			'user_id'     => 42,
			'date_from'   => '2025-01-01',
			'date_to'     => '2025-12-31',
		);

		$mock_log  = $this->createMock( ActivityLog::class );
		$expected  = array(
			'items' => array( $mock_log ),
			'total' => 1,
			'pages' => 1,
		);

		$captured_page     = null;
		$captured_per_page = null;
		$captured_filters  = null;

		$this->repository
			->shouldReceive( 'paginate' )
			->once()
			->withArgs(
				function ( $page, $per_page, $f ) use ( &$captured_page, &$captured_per_page, &$captured_filters ) {
					$captured_page     = $page;
					$captured_per_page = $per_page;
					$captured_filters  = $f;
					return true;
				}
			)
			->andReturn( $expected );

		$service = new ActivityLogService( $this->repository );
		$result  = $service->get_logs( 2, 10, $filters );

		$this->assertSame( 2, $captured_page );
		$this->assertSame( 10, $captured_per_page );
		$this->assertSame( $filters, $captured_filters );
		$this->assertCount( 5, $captured_filters );
		$this->assertSame( 'occurrence', $captured_filters['object_type'] );
		$this->assertSame( 'cancel', $captured_filters['action'] );
		$this->assertSame( 42, $captured_filters['user_id'] );
		$this->assertSame( '2025-01-01', $captured_filters['date_from'] );
		$this->assertSame( '2025-12-31', $captured_filters['date_to'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 1, $result['pages'] );
	}

	/**
	 * Test log generic method dispatches to correct type-specific method.
	 *
	 * @return void
	 */
	public function test_log_generic_dispatches_to_event(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$captured_args = array();

		$this->repository
			->shouldReceive( 'log' )
			->once()
			->withArgs(
				function ( $action, $object_type, $object_id, $title, $details ) use ( &$captured_args ) {
					$captured_args = compact( 'action', 'object_type', 'object_id', 'title', 'details' );
					return true;
				}
			);

		$service = new ActivityLogService( $this->repository );
		$service->log( 'update', 'event', 77, 'Generic Dispatch Test' );

		$this->assertSame( 'update', $captured_args['action'] );
		$this->assertSame( 'event', $captured_args['object_type'] );
		$this->assertSame( 77, $captured_args['object_id'] );
	}

	/**
	 * Test cleanup return type is integer.
	 *
	 * @return void
	 */
	public function test_cleanup_return_type_is_int(): void {
		$this->repository
			->shouldReceive( 'cleanup' )
			->once()
			->andReturn( 42 );

		$service = new ActivityLogService( $this->repository );
		$result  = $service->cleanup( 60 );

		$this->assertIsInt( $result );
		$this->assertSame( 42, $result );
	}

	// =========================================================================
	// do_action fires_action Tests (killing removal-of-do_action mutants)
	// =========================================================================

	/**
	 * Test log_occurrence fires activity_logged action.
	 *
	 * @return void
	 */
	public function test_log_occurrence_fires_action(): void {
		$action_fired = false;
		$action_args  = array();

		Functions\when( 'do_action' )->alias(
			function ( $action, ...$args ) use ( &$action_fired, &$action_args ) {
				if ( 'nettertech_events_activity_logged' === $action ) {
					$action_fired = true;
					$action_args  = $args;
				}
			}
		);

		$this->repository->shouldReceive( 'log' )->once();

		$service = new ActivityLogService( $this->repository );
		$service->log_occurrence( 'cancel', 100, 'Test Occurrence' );

		$this->assertTrue( $action_fired );
		$this->assertSame( 'cancel', $action_args[0] );
		$this->assertSame( 'occurrence', $action_args[1] );
		$this->assertSame( 100, $action_args[2] );
	}

	/**
	 * Test log_attendee fires activity_logged action.
	 *
	 * @return void
	 */
	public function test_log_attendee_fires_action(): void {
		$action_fired = false;
		$action_args  = array();

		Functions\when( 'do_action' )->alias(
			function ( $action, ...$args ) use ( &$action_fired, &$action_args ) {
				if ( 'nettertech_events_activity_logged' === $action ) {
					$action_fired = true;
					$action_args  = $args;
				}
			}
		);

		$this->repository->shouldReceive( 'log' )->once();

		$service = new ActivityLogService( $this->repository );
		$service->log_attendee( 'check_in', 50, 'Jane Doe' );

		$this->assertTrue( $action_fired );
		$this->assertSame( 'check_in', $action_args[0] );
		$this->assertSame( 'attendee', $action_args[1] );
		$this->assertSame( 50, $action_args[2] );
	}

	/**
	 * Test log_ticket_type fires activity_logged action.
	 *
	 * @return void
	 */
	public function test_log_ticket_type_fires_action(): void {
		$action_fired = false;
		$action_args  = array();

		Functions\when( 'do_action' )->alias(
			function ( $action, ...$args ) use ( &$action_fired, &$action_args ) {
				if ( 'nettertech_events_activity_logged' === $action ) {
					$action_fired = true;
					$action_args  = $args;
				}
			}
		);

		$this->repository->shouldReceive( 'log' )->once();

		$service = new ActivityLogService( $this->repository );
		$service->log_ticket_type( 'create', 10, 'VIP' );

		$this->assertTrue( $action_fired );
		$this->assertSame( 'create', $action_args[0] );
		$this->assertSame( 'ticket_type', $action_args[1] );
		$this->assertSame( 10, $action_args[2] );
	}

	/**
	 * Test log_export fires activity_logged action.
	 *
	 * @return void
	 */
	public function test_log_export_fires_action(): void {
		$action_fired = false;
		$action_args  = array();

		Functions\when( 'do_action' )->alias(
			function ( $action, ...$args ) use ( &$action_fired, &$action_args ) {
				if ( 'nettertech_events_activity_logged' === $action ) {
					$action_fired = true;
					$action_args  = $args;
				}
			}
		);

		$this->repository->shouldReceive( 'log' )->once();

		$service = new ActivityLogService( $this->repository );
		$service->log_export( 'attendees', array( 'format' => 'csv' ) );

		$this->assertTrue( $action_fired );
		$this->assertSame( 'export', $action_args[0] );
		$this->assertSame( 'attendees', $action_args[1] );
	}

	/**
	 * Test log_series fires activity_logged action.
	 *
	 * @return void
	 */
	public function test_log_series_fires_action(): void {
		$action_fired = false;
		$action_args  = array();

		Functions\when( 'do_action' )->alias(
			function ( $action, ...$args ) use ( &$action_fired, &$action_args ) {
				if ( 'nettertech_events_activity_logged' === $action ) {
					$action_fired = true;
					$action_args  = $args;
				}
			}
		);

		$this->repository->shouldReceive( 'log' )->once();

		$service = new ActivityLogService( $this->repository );
		$service->log_series( 'create', 5, 'Test Series' );

		$this->assertTrue( $action_fired );
		$this->assertSame( 'create', $action_args[0] );
		$this->assertSame( 'series', $action_args[1] );
		$this->assertSame( 5, $action_args[2] );
	}
}
