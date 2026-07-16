<?php
/**
 * REST API response filter hook tests.
 *
 * Verifies that REST controllers apply filter hooks before returning
 * responses, allowing Pro and third-party plugins to modify data.
 *
 * @package NetterTechEvents\Tests\Unit\API
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\API;

use Brain\Monkey\Functions;
use NetterTechEvents\API\EventsController;
use NetterTechEvents\API\EventsAdminController;
use NetterTechEvents\API\AttendeesAdminController;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\ActivityLogService;
use NetterTechEvents\Services\CapacityService;
use NetterTechEvents\Services\RateLimitService;

/**
 * Test REST response filter hooks are applied.
 */
class RestResponseFilterTest extends \NetterTechEventsTestCase {

	/**
	 * Tracks which filter hooks were applied.
	 *
	 * @var array<string, array{data: mixed, request: mixed}>
	 */
	private array $applied_filters = array();

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		ServiceRegistry::reset();
		$this->applied_filters = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_title' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'get_option' )->alias(
			function ( $option ) {
				if ( 'date_format' === $option ) {
					return 'Y-m-d';
				}
				if ( 'time_format' === $option ) {
					return 'H:i';
				}
				return array();
			}
		);
		Functions\when( 'date_i18n' )->alias(
			function ( $format, $timestamp ) {
				return date( $format, $timestamp );
			}
		);
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( null );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . $path );
		Functions\when( 'sanitize_email' )->returnArg( 1 );
		Functions\when( 'is_email' )->justReturn( true );

		// Mock apply_filters to track calls and allow modification.
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, $value, ...$args ) {
				$this->applied_filters[ $tag ] = array(
					'data'    => $value,
					'request' => $args[0] ?? null,
				);
				// Inject a marker to prove the filter was applied.
				if ( is_array( $value ) ) {
					$value['_filter_applied'] = $tag;
				}
				return $value;
			}
		);
	}

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ServiceRegistry::reset();
		parent::tearDown();
	}

	/**
	 * Create a mock WP_REST_Request.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 * @return \WP_REST_Request|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_request( array $params = array() ) {
		$request = $this->createMock( \WP_REST_Request::class );
		$request->method( 'get_param' )->willReturnCallback(
			function ( $key ) use ( $params ) {
				return $params[ $key ] ?? null;
			}
		);
		$request->method( 'get_params' )->willReturn( $params );
		$request->method( 'get_method' )->willReturn( 'GET' );
		$request->method( 'has_param' )->willReturnCallback(
			function ( $key ) use ( $params ) {
				return array_key_exists( $key, $params );
			}
		);
		return $request;
	}

	/**
	 * Create a mock occurrence.
	 *
	 * @param int        $id    Occurrence ID.
	 * @param Event|null $event Associated event.
	 * @return Occurrence|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_occurrence( int $id, $event = null ) {
		$occurrence                 = $this->createMock( Occurrence::class );
		$occurrence->id             = $id;
		$occurrence->event_id       = $event ? $event->id : 1;
		$occurrence->start_datetime = '2026-02-15 19:00:00';
		$occurrence->end_datetime   = '2026-02-15 21:00:00';
		$occurrence->all_day        = false;
		$occurrence->status         = 'scheduled';

		$occurrence->method( 'get_event' )->willReturn( $event );

		return $occurrence;
	}

	/**
	 * Create a mock event.
	 *
	 * @param int $id Event ID.
	 * @return Event|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_event( int $id ) {
		$event                    = $this->createMock( Event::class );
		$event->id                = $id;
		$event->title             = 'Test Event ' . $id;
		$event->slug              = 'test-event-' . $id;
		$event->excerpt           = 'Test excerpt';
		$event->venue_name        = 'Test Venue';
		$event->venue_address     = '123 Test St';
		$event->featured_image_id = null;

		$event->method( 'get_permalink' )->willReturn( 'https://example.com/events/test-event-' . $id );
		$event->method( 'is_published' )->willReturn( true );

		return $event;
	}

	/**
	 * Create a real Event model.
	 *
	 * @param int $id Event ID.
	 * @return Event
	 */
	private function create_event_model( int $id ): Event {
		$event              = new Event();
		$event->id          = $id;
		$event->title       = 'Test Event';
		$event->slug        = 'test-event';
		$event->description = 'Test description';
		$event->excerpt     = 'Test excerpt';
		$event->status      = \NetterTechEvents\Enums\EventStatus::PUBLISHED;
		$event->event_type  = 'single';
		$event->created_at  = '2026-01-01 10:00:00';
		$event->updated_at  = '2026-01-01 10:00:00';
		return $event;
	}

	/**
	 * Create a RateLimitService mock that bypasses rate limiting.
	 *
	 * @return RateLimitService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_rate_limit_mock() {
		$rate_limit = $this->createMock( RateLimitService::class );
		$rate_limit->method( 'should_bypass' )->willReturn( true );
		$rate_limit->method( 'add_headers' )->willReturnArgument( 0 );
		return $rate_limit;
	}

	// =========================================================================
	// EventsController Filter Tests
	// =========================================================================

	/**
	 * Test get_upcoming applies nettertech_events_rest_events_upcoming_response filter.
	 *
	 * @return void
	 */
	public function test_events_get_upcoming_applies_filter(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'upcoming' )->willReturn( array( $occurrence ) );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$capacity_service = $this->createMock( CapacityService::class );

		$controller = new EventsController(
			$occurrence_repo,
			$ticket_type_repo,
			$capacity_service,
			$this->create_rate_limit_mock()
		);

		$request  = $this->create_request( array( 'limit' => 10 ) );
		$response = $controller->get_upcoming( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( Hooks::REST_EVENTS_UPCOMING_RESPONSE, $this->applied_filters );

		$data = $response->get_data();
		$this->assertSame( Hooks::REST_EVENTS_UPCOMING_RESPONSE, $data['_filter_applied'] );
	}

	/**
	 * Test get_item applies nettertech_events_rest_events_get_response filter.
	 *
	 * @return void
	 */
	public function test_events_get_item_applies_filter(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$capacity_service = $this->createMock( CapacityService::class );

		$controller = new EventsController(
			$occurrence_repo,
			$ticket_type_repo,
			$capacity_service,
			$this->create_rate_limit_mock()
		);

		$request  = $this->create_request( array( 'id' => 100 ) );
		$response = $controller->get_item( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( Hooks::REST_EVENTS_GET_RESPONSE, $this->applied_filters );

		$data = $response->get_data();
		$this->assertSame( Hooks::REST_EVENTS_GET_RESPONSE, $data['_filter_applied'] );
	}

	/**
	 * Test get_range applies nettertech_events_rest_events_range_response filter.
	 *
	 * @return void
	 */
	public function test_events_get_range_applies_filter(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'in_range' )->willReturn( array( $occurrence ) );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$capacity_service = $this->createMock( CapacityService::class );

		$controller = new EventsController(
			$occurrence_repo,
			$ticket_type_repo,
			$capacity_service,
			$this->create_rate_limit_mock()
		);

		$request  = $this->create_request( array(
			'start' => '2026-01-01',
			'end'   => '2026-12-31',
		) );
		$response = $controller->get_range( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( Hooks::REST_EVENTS_RANGE_RESPONSE, $this->applied_filters );
	}

	// =========================================================================
	// EventsAdminController Filter Tests
	// =========================================================================

	/**
	 * Test admin get_items applies nettertech_events_rest_admin_events_list_response filter.
	 *
	 * @return void
	 */
	public function test_admin_events_get_items_applies_filter(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$event = $this->create_event_model( 1 );

		$event_repo = $this->getMockBuilder( EventRepository::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'find', 'save', 'delete', 'paginate', 'generate_unique_slug' ) )
			->getMock();

		$event_repo->method( 'paginate' )->willReturn(
			array(
				'items' => array( $event ),
				'total' => 1,
				'pages' => 1,
			)
		);

		$activity_log = $this->getMockBuilder( ActivityLogService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'log' ) )
			->getMock();

		$controller = new EventsAdminController(
			$event_repo,
			$activity_log,
			$this->create_rate_limit_mock()
		);

		$request  = $this->create_request( array( 'page' => 1, 'per_page' => 20 ) );
		$response = $controller->get_items( $request );

		$data = $response->get_data();
		$this->assertArrayHasKey( Hooks::REST_ADMIN_EVENTS_LIST_RESPONSE, $this->applied_filters );
		$this->assertSame( Hooks::REST_ADMIN_EVENTS_LIST_RESPONSE, $data['_filter_applied'] );
	}

	/**
	 * Test admin get_item applies nettertech_events_rest_admin_events_get_response filter.
	 *
	 * @return void
	 */
	public function test_admin_events_get_item_applies_filter(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$event = $this->create_event_model( 42 );

		$event_repo = $this->getMockBuilder( EventRepository::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'find', 'save', 'delete', 'paginate', 'generate_unique_slug' ) )
			->getMock();

		$event_repo->method( 'find' )->willReturn( $event );

		$activity_log = $this->getMockBuilder( ActivityLogService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'log' ) )
			->getMock();

		$controller = new EventsAdminController(
			$event_repo,
			$activity_log,
			$this->create_rate_limit_mock()
		);

		$request  = $this->create_request( array( 'id' => 42 ) );
		$response = $controller->get_item( $request );

		$data = $response->get_data();
		$this->assertArrayHasKey( Hooks::REST_ADMIN_EVENTS_GET_RESPONSE, $this->applied_filters );
		$this->assertSame( Hooks::REST_ADMIN_EVENTS_GET_RESPONSE, $data['_filter_applied'] );
	}

	// =========================================================================
	// AttendeesAdminController Filter Tests
	// =========================================================================

	/**
	 * Test admin attendees get_items applies filter.
	 *
	 * @return void
	 */
	public function test_admin_attendees_get_items_applies_filter(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$attendee                = new Attendee();
		$attendee->id            = 1;
		$attendee->occurrence_id = 100;
		$attendee->name          = 'John Doe';
		$attendee->email         = 'john@example.com';
		$attendee->quantity      = 2;
		$attendee->status        = 'confirmed';
		$attendee->checked_in    = false;

		$attendee_repo = $this->getMockBuilder( AttendeeRepository::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'find', 'save', 'delete', 'for_occurrence', 'search' ) )
			->getMock();

		$attendee_repo->method( 'for_occurrence' )->willReturn( array( $attendee ) );

		$activity_log = $this->getMockBuilder( ActivityLogService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'log' ) )
			->getMock();

		$rate_limit = $this->createMock( RateLimitService::class );
		$rate_limit->method( 'check_and_increment' )->willReturn( null );

		$controller = new AttendeesAdminController(
			$attendee_repo,
			$activity_log,
			$rate_limit
		);

		Functions\when( 'rest_ensure_response' )->alias(
			function ( $data ) {
				return new \WP_REST_Response( $data, 200 );
			}
		);

		$request  = $this->create_request( array( 'occurrence_id' => 100 ) );
		$response = $controller->get_items( $request );

		$this->assertArrayHasKey( Hooks::REST_ADMIN_ATTENDEES_LIST_RESPONSE, $this->applied_filters );
	}
}
