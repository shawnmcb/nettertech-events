<?php
/**
 * EventsController unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\API
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\API;

use Brain\Monkey\Functions;
use NetterTechEvents\API\EventsController;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\CapacityService;
use NetterTechEvents\Services\RateLimitService;

/**
 * Test EventsController functionality.
 *
 * Note: Full REST API tests require integration tests with WP_REST_Server.
 * These unit tests focus on argument definitions and controller structure.
 */
class EventsControllerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock OccurrenceRepository.
	 *
	 * @var OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Mock TicketTypeRepository.
	 *
	 * @var TicketTypeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

	/**
	 * Mock CapacityService.
	 *
	 * @var CapacityServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $capacity_service;

	/**
	 * Mock RateLimitService.
	 *
	 * @var RateLimitService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $rate_limit;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset ServiceRegistry between tests.
		ServiceRegistry::reset();

		// Create mocks for constructor injection.
		$this->occurrence_repo  = $this->createMock( OccurrenceRepository::class );
		$this->ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$this->capacity_service = $this->createMock( CapacityService::class );
		$this->rate_limit       = $this->createMock( RateLimitService::class );
		$this->rate_limit->method( 'should_bypass' )->willReturn( true );
		$this->rate_limit->method( 'add_headers' )->willReturnArgument( 0 );

		// Mock WordPress functions.
		Functions\when( 'date_i18n' )->alias(
			function ( $format, $timestamp ) {
				return date( $format, $timestamp );
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $option ) {
				if ( 'date_format' === $option ) {
					return 'Y-m-d';
				}
				if ( 'time_format' === $option ) {
					return 'H:i';
				}
				return '';
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( null );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( intval( $value ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
	}

	/**
	 * Create an EventsController with mock dependencies.
	 *
	 * @return EventsController
	 */
	private function create_controller(): EventsController {
		return new EventsController(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->capacity_service,
			$this->rate_limit
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
	 * Create a mock occurrence for testing.
	 *
	 * @param int   $id Occurrence ID.
	 * @param Event $event Associated event.
	 * @return Occurrence
	 */
	private function create_mock_occurrence( int $id, ?Event $event = null ): Occurrence {
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
	 * Create a mock event for testing.
	 *
	 * @param int $id Event ID.
	 * @return Event
	 */
	private function create_mock_event( int $id ): Event {
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
	 * Create a mock WP_REST_Request.
	 *
	 * @param array $params Request parameters.
	 * @return \WP_REST_Request
	 */
	private function create_mock_request( array $params = array() ): \WP_REST_Request {
		$request = $this->createMock( \WP_REST_Request::class );
		$request->method( 'get_param' )->willReturnCallback(
			function ( $key ) use ( $params ) {
				return $params[ $key ] ?? null;
			}
		);
		return $request;
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test controller can be instantiated.
	 *
	 * @return void
	 */
	public function test_can_instantiate_controller(): void {
		$controller = $this->create_controller();

		$this->assertInstanceOf( EventsController::class, $controller );
	}

	// =========================================================================
	// Namespace/Base Tests
	// =========================================================================

	/**
	 * Test controller has correct namespace.
	 *
	 * @return void
	 */
	public function test_has_correct_namespace(): void {
		$controller = $this->create_controller();

		// Use reflection to access protected property.
		$reflection = new \ReflectionClass( $controller );
		$prop       = $reflection->getProperty( 'namespace' );

		$this->assertSame( 'nettertech-events/v1', $prop->getValue( $controller ) );
	}

	/**
	 * Test controller has correct rest base.
	 *
	 * @return void
	 */
	public function test_has_correct_rest_base(): void {
		$controller = $this->create_controller();

		$reflection = new \ReflectionClass( $controller );
		$prop       = $reflection->getProperty( 'rest_base' );

		$this->assertSame( 'events', $prop->getValue( $controller ) );
	}

	// =========================================================================
	// Method Existence Tests
	// =========================================================================

	/**
	 * Test register_routes method exists.
	 *
	 * @return void
	 */
	public function test_register_routes_method_exists(): void {
		$controller = $this->create_controller();
		$this->assertTrue( method_exists( $controller, 'register_routes' ) );
	}

	/**
	 * Test get_upcoming method exists.
	 *
	 * @return void
	 */
	public function test_get_upcoming_method_exists(): void {
		$controller = $this->create_controller();
		$this->assertTrue( method_exists( $controller, 'get_upcoming' ) );
	}

	/**
	 * Test get_range method exists.
	 *
	 * @return void
	 */
	public function test_get_range_method_exists(): void {
		$controller = $this->create_controller();
		$this->assertTrue( method_exists( $controller, 'get_range' ) );
	}

	/**
	 * Test get_item method exists.
	 *
	 * @return void
	 */
	public function test_get_item_method_exists(): void {
		$controller = $this->create_controller();
		$this->assertTrue( method_exists( $controller, 'get_item' ) );
	}

	/**
	 * Test get_occurrences method exists.
	 *
	 * @return void
	 */
	public function test_get_occurrences_method_exists(): void {
		$controller = $this->create_controller();
		$this->assertTrue( method_exists( $controller, 'get_occurrences' ) );
	}

	// =========================================================================
	// Arguments Specification Tests (via reflection)
	// =========================================================================

	/**
	 * Test get_upcoming_args returns correct structure.
	 *
	 * @return void
	 */
	public function test_get_upcoming_args_structure(): void {
		$controller = $this->create_controller();

		// Use reflection to call private method.
		$reflection = new \ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_upcoming_args' );

		$args = $method->invoke( $controller );

		$this->assertArrayHasKey( 'limit', $args );
		$this->assertSame( 'integer', $args['limit']['type'] );
		$this->assertSame( 10, $args['limit']['default'] );
		$this->assertSame( 1, $args['limit']['minimum'] );
		$this->assertSame( 100, $args['limit']['maximum'] );
	}

	/**
	 * Test get_range_args returns correct structure.
	 *
	 * @return void
	 */
	public function test_get_range_args_structure(): void {
		$controller = $this->create_controller();

		$reflection = new \ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_range_args' );

		$args = $method->invoke( $controller );

		$this->assertArrayHasKey( 'start', $args );
		$this->assertArrayHasKey( 'end', $args );
		$this->assertTrue( $args['start']['required'] );
		$this->assertTrue( $args['end']['required'] );
		$this->assertSame( 'date', $args['start']['format'] );
		$this->assertSame( 'date', $args['end']['format'] );
	}

	/**
	 * Test get_occurrences_args returns correct structure.
	 *
	 * @return void
	 */
	public function test_get_occurrences_args_structure(): void {
		$controller = $this->create_controller();

		$reflection = new \ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_occurrences_args' );

		$args = $method->invoke( $controller );

		// Check all expected parameters exist.
		$expected = array( 'start', 'end', 'page', 'per_page', 'category', 'search', 'upcoming', 'past' );
		foreach ( $expected as $param ) {
			$this->assertArrayHasKey( $param, $args, "Missing parameter: {$param}" );
		}

		// Check pagination defaults.
		$this->assertSame( 1, $args['page']['default'] );
		$this->assertSame( 12, $args['per_page']['default'] );
		$this->assertSame( 100, $args['per_page']['maximum'] );
	}

	// =========================================================================
	// Inheritance Tests
	// =========================================================================

	/**
	 * Test controller extends WP_REST_Controller.
	 *
	 * @return void
	 */
	public function test_extends_wp_rest_controller(): void {
		// Note: WP_REST_Controller is mocked, so we check the declaration.
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );
		$parent     = $reflection->getParentClass();

		$this->assertNotFalse( $parent );
		$this->assertSame( 'WP_REST_Controller', $parent->getName() );
	}

	// =========================================================================
	// format_date Tests (via reflection)
	// =========================================================================

	/**
	 * Test format_date method exists.
	 *
	 * @return void
	 */
	public function test_format_date_method_exists(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );

		$this->assertTrue( $reflection->hasMethod( 'format_date' ) );
	}

	/**
	 * Test format_date is private.
	 *
	 * @return void
	 */
	public function test_format_date_is_private(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'format_date' );

		$this->assertTrue( $method->isPrivate() );
	}

	// =========================================================================
	// format_time Tests (via reflection)
	// =========================================================================

	/**
	 * Test format_time method exists.
	 *
	 * @return void
	 */
	public function test_format_time_method_exists(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );

		$this->assertTrue( $reflection->hasMethod( 'format_time' ) );
	}

	/**
	 * Test format_time is private.
	 *
	 * @return void
	 */
	public function test_format_time_is_private(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'format_time' );

		$this->assertTrue( $method->isPrivate() );
	}

	// =========================================================================
	// format_date_range Tests (via reflection)
	// =========================================================================

	/**
	 * Test format_date_range method exists.
	 *
	 * @return void
	 */
	public function test_format_date_range_method_exists(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );

		$this->assertTrue( $reflection->hasMethod( 'format_date_range' ) );
	}

	/**
	 * Test format_date_range is private.
	 *
	 * @return void
	 */
	public function test_format_date_range_is_private(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'format_date_range' );

		$this->assertTrue( $method->isPrivate() );
	}

	// =========================================================================
	// get_image_data Tests (via reflection)
	// =========================================================================

	/**
	 * Test get_image_data method exists.
	 *
	 * @return void
	 */
	public function test_get_image_data_method_exists(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );

		$this->assertTrue( $reflection->hasMethod( 'get_image_data' ) );
	}

	/**
	 * Test get_image_data returns null for null input.
	 *
	 * @return void
	 */
	public function test_get_image_data_returns_null_for_null_input(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_image_data' );

		$result = $method->invoke( $controller, null );

		$this->assertNull( $result );
	}

	// =========================================================================
	// prepare_occurrence Tests (via reflection)
	// =========================================================================

	/**
	 * Test prepare_occurrence method exists.
	 *
	 * @return void
	 */
	public function test_prepare_occurrence_method_exists(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );

		$this->assertTrue( $reflection->hasMethod( 'prepare_occurrence' ) );
	}

	/**
	 * Test prepare_occurrence is private.
	 *
	 * @return void
	 */
	public function test_prepare_occurrence_is_private(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'prepare_occurrence' );

		$this->assertTrue( $method->isPrivate() );
	}

	// =========================================================================
	// get_ticket_availability Tests (via reflection)
	// =========================================================================

	/**
	 * Test get_ticket_availability method exists.
	 *
	 * @return void
	 */
	public function test_get_ticket_availability_method_exists(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );

		$this->assertTrue( $reflection->hasMethod( 'get_ticket_availability' ) );
	}

	/**
	 * Test get_ticket_availability is private.
	 *
	 * @return void
	 */
	public function test_get_ticket_availability_is_private(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_ticket_availability' );

		$this->assertTrue( $method->isPrivate() );
	}

	// =========================================================================
	// check_rate_limit Tests (via reflection)
	// =========================================================================

	/**
	 * Test check_rate_limit method exists.
	 *
	 * @return void
	 */
	public function test_check_rate_limit_method_exists(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );

		$this->assertTrue( $reflection->hasMethod( 'check_rate_limit' ) );
	}

	/**
	 * Test check_rate_limit is private.
	 *
	 * @return void
	 */
	public function test_check_rate_limit_is_private(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'check_rate_limit' );

		$this->assertTrue( $method->isPrivate() );
	}

	// =========================================================================
	// register_routes Tests
	// =========================================================================

	/**
	 * Test register_routes calls register_rest_route.
	 *
	 * @return void
	 */
	public function test_register_routes_registers_routes(): void {
		$routes_registered = array();

		\Brain\Monkey\Functions\when( 'register_rest_route' )
			->alias(
				function ( $namespace, $route ) use ( &$routes_registered ) {
					$routes_registered[] = $route;
				}
			);

		$controller = $this->create_controller();
		$controller->register_routes();

		$this->assertNotEmpty( $routes_registered );
		// Routes are prefixed with /events/
		$this->assertContains( '/events/upcoming', $routes_registered );
	}

	// =========================================================================
	// get_upcoming Tests
	// =========================================================================

	/**
	 * Test get_upcoming returns occurrences.
	 *
	 * @return void
	 */
	public function test_get_upcoming_returns_occurrences(): void {
		// Create mock dependencies.
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'upcoming' )->willReturn( array( $occurrence ) );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'limit' => 10 ) );

		$controller = $this->create_controller();
		$response   = $controller->get_upcoming( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertCount( 1, $data );
		$this->assertSame( 100, $data[0]['id'] );
	}

	/**
	 * Test get_upcoming with custom limit.
	 *
	 * @return void
	 */
	public function test_get_upcoming_respects_limit_parameter(): void {
		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->expects( $this->once() )
			->method( 'upcoming' )
			->with( 5 )
			->willReturn( array() );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'limit' => 5 ) );

		$controller = $this->create_controller();
		$controller->get_upcoming( $request );
	}

	/**
	 * Test get_upcoming returns empty array when no events.
	 *
	 * @return void
	 */
	public function test_get_upcoming_returns_empty_when_no_events(): void {
		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'upcoming' )->willReturn( array() );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request();

		$controller = $this->create_controller();
		$response   = $controller->get_upcoming( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}

	// =========================================================================
	// get_range Tests
	// =========================================================================

	/**
	 * Test get_range with valid date range.
	 *
	 * @return void
	 */
	public function test_get_range_returns_occurrences_in_range(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->expects( $this->once() )
			->method( 'in_range' )
			->with( '2026-02-01', '2026-02-28' )
			->willReturn( array( $occurrence ) );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request(
			array(
				'start' => '2026-02-01',
				'end'   => '2026-02-28',
			)
		);

		$controller = $this->create_controller();
		$response   = $controller->get_range( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
	}

	/**
	 * Test get_range requires start and end parameters.
	 *
	 * @return void
	 */
	public function test_get_range_returns_error_when_missing_dates(): void {
		$occurrence_repo  = $this->createMock( OccurrenceRepository::class );
		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array() );

		$controller = $this->create_controller();
		$response   = $controller->get_range( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'error', $response->get_data() );
	}

	/**
	 * Test get_range returns error when only start provided.
	 *
	 * @return void
	 */
	public function test_get_range_returns_error_when_missing_end(): void {
		$occurrence_repo  = $this->createMock( OccurrenceRepository::class );
		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'start' => '2026-02-01' ) );

		$controller = $this->create_controller();
		$response   = $controller->get_range( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	// =========================================================================
	// get_item Tests
	// =========================================================================

	/**
	 * Test get_item returns occurrence details.
	 *
	 * @return void
	 */
	public function test_get_item_returns_occurrence_details(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'find_with_event' )->with( 100 )->willReturn( $occurrence );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'id' => 100 ) );

		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 100, $data['id'] );
		$this->assertArrayHasKey( 'event', $data );
		$this->assertSame( 'Test Event 1', $data['event']['title'] );
	}

	/**
	 * Test get_item returns 404 for invalid ID.
	 *
	 * @return void
	 */
	public function test_get_item_returns_404_for_invalid_id(): void {
		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'find_with_event' )->willReturn( null );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'id' => 99999 ) );

		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertArrayHasKey( 'error', $response->get_data() );
	}

	/**
	 * Test get_item returns 404 when parent event is not published (draft, cancelled, etc.).
	 *
	 * Public endpoint must not leak unpublished events.
	 *
	 * @return void
	 */
	public function test_get_item_returns_404_when_event_not_published(): void {
		$event = $this->createMock( Event::class );
		$event->id = 1;
		$event->method( 'is_published' )->willReturn( false );

		$occurrence                 = $this->createMock( Occurrence::class );
		$occurrence->id             = 100;
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-02-15 19:00:00';
		$occurrence->method( 'get_event' )->willReturn( $event );

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'id' => 100 ) );

		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Test get_item includes ticket availability.
	 *
	 * @return void
	 */
	public function test_get_item_includes_ticket_availability(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$ticket_type     = $this->createMock( TicketType::class );
		$ticket_type->id = 1;
		$ticket_type->name = 'General Admission';
		$ticket_type->price = 25.00;

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array( $ticket_type ) );

		$capacity_service = $this->createMock( CapacityService::class );
		$capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'is_unlimited'       => false,
				'is_sold_out'        => false,
				'is_low_stock'       => false,
				'effective_available' => 50,
			)
		);
		// get_item reads total_available off this summary (EventsController::386); mock the full
		// contract so the double matches CapacityCalculator and the read does not warn.
		$capacity_service->method( 'get_occurrence_capacity' )->willReturn(
			array(
				'total_capacity'  => 50,
				'total_sold'      => 0,
				'total_available' => 50,
				'has_unlimited'   => false,
				'ticket_types'    => array(),
			)
		);

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'id' => 100 ) );

		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'tickets', $data );
		$this->assertTrue( $data['tickets']['available'] );
		$this->assertFalse( $data['tickets']['sold_out'] );
		$this->assertSame( 25.00, $data['tickets']['min_price'] );
		$this->assertSame( 50, $data['tickets']['total_left'] );
	}

	// =========================================================================
	// get_occurrences Tests
	// =========================================================================

	/**
	 * Test get_occurrences pagination mode.
	 *
	 * @return void
	 */
	public function test_get_occurrences_pagination(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'get_filtered' )->willReturn(
			array(
				'items'       => array( $occurrence ),
				'total'       => 1,
				'total_pages' => 1,
			)
		);

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request(
			array(
				'page'     => 1,
				'per_page' => 12,
			)
		);

		$controller = $this->create_controller();
		$response   = $controller->get_occurrences( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'events', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'total_pages', $data );
		$this->assertSame( 1, $data['page'] );
	}

	/**
	 * Test get_occurrences date range mode.
	 *
	 * @return void
	 */
	public function test_get_occurrences_date_range_mode(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->expects( $this->once() )
			->method( 'in_range' )
			->with( '2026-02-01', '2026-02-28' )
			->willReturn( array( $occurrence ) );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request(
			array(
				'start' => '2026-02-01',
				'end'   => '2026-02-28',
			)
		);

		$controller = $this->create_controller();
		$response   = $controller->get_occurrences( $request );

		$this->assertSame( 200, $response->get_status() );

		// Date range mode returns flat array, not paginated.
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertCount( 1, $data );
	}

	/**
	 * Test get_occurrences with category filter.
	 *
	 * @return void
	 */
	public function test_get_occurrences_with_category_filter(): void {
		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->expects( $this->once() )
			->method( 'get_filtered' )
			->with(
				$this->callback(
					function ( $args ) {
						return isset( $args['category'] ) && $args['category'] === array( 5 );
					}
				)
			)
			->willReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'category' => 5 ) );

		$controller = $this->create_controller();
		$controller->get_occurrences( $request );
	}

	/**
	 * Test get_occurrences with search filter.
	 *
	 * @return void
	 */
	public function test_get_occurrences_with_search_filter(): void {
		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->expects( $this->once() )
			->method( 'get_filtered' )
			->with(
				$this->callback(
					function ( $args ) {
						return isset( $args['search'] ) && $args['search'] === 'concert';
					}
				)
			)
			->willReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'search' => 'concert' ) );

		$controller = $this->create_controller();
		$controller->get_occurrences( $request );
	}

	/**
	 * Test get_occurrences with past filter.
	 *
	 * @return void
	 */
	public function test_get_occurrences_with_past_filter(): void {
		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->expects( $this->once() )
			->method( 'get_filtered' )
			->with(
				$this->callback(
					function ( $args ) {
						return isset( $args['past'] ) && true === $args['past'];
					}
				)
			)
			->willReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'past' => true ) );

		$controller = $this->create_controller();
		$controller->get_occurrences( $request );
	}

	/**
	 * Test get_occurrences preserves past parameter across paginated requests.
	 *
	 * Regression test for BUG-002: AJAX pagination was dropping the past parameter,
	 * causing page 2+ to show upcoming events instead of past events.
	 *
	 * @return void
	 */
	public function test_get_occurrences_past_filter_preserved_on_page_two(): void {
		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->expects( $this->once() )
			->method( 'get_filtered' )
			->with(
				$this->callback(
					function ( $args ) {
						return isset( $args['past'] ) && true === $args['past']
							&& isset( $args['page'] ) && 2 === $args['page']
							&& isset( $args['upcoming'] ) && false === $args['upcoming'];
					}
				)
			)
			->willReturn(
				array(
					'items'       => array(),
					'total'       => 25,
					'total_pages' => 3,
				)
			);

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request(
			array(
				'past'     => 'true',
				'page'     => 2,
				'per_page' => 12,
			)
		);

		$controller = $this->create_controller();
		$response   = $controller->get_occurrences( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 2, $data['page'] );
		$this->assertSame( 3, $data['total_pages'] );
	}

	// =========================================================================
	// Ticket Availability Tests
	// =========================================================================

	/**
	 * Test ticket availability with sold out tickets.
	 *
	 * @return void
	 */
	public function test_ticket_availability_sold_out(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$ticket_type     = $this->createMock( TicketType::class );
		$ticket_type->id = 1;
		$ticket_type->name = 'General Admission';
		$ticket_type->price = 25.00;

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array( $ticket_type ) );

		$capacity_service = $this->createMock( CapacityService::class );
		$capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'is_unlimited'       => false,
				'is_sold_out'        => true,
				'is_low_stock'       => false,
				'effective_available' => 0,
			)
		);
		$capacity_service->method( 'get_occurrence_capacity' )->willReturn(
			array(
				'total_capacity'  => 30,
				'total_sold'      => 30,
				'total_available' => 0,
				'has_unlimited'   => false,
				'ticket_types'    => array(),
			)
		);

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'id' => 100 ) );

		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );

		$data = $response->get_data();
		$this->assertFalse( $data['tickets']['available'] );
		$this->assertTrue( $data['tickets']['sold_out'] );
		$this->assertSame( 0, $data['tickets']['total_left'] );
	}

	/**
	 * Test ticket availability with low stock.
	 *
	 * @return void
	 */
	public function test_ticket_availability_low_stock(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$ticket_type     = $this->createMock( TicketType::class );
		$ticket_type->id = 1;
		$ticket_type->name = 'VIP';
		$ticket_type->price = 100.00;

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array( $ticket_type ) );

		$capacity_service = $this->createMock( CapacityService::class );
		$capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'is_unlimited'       => false,
				'is_sold_out'        => false,
				'is_low_stock'       => true,
				'effective_available' => 3,
			)
		);
		$capacity_service->method( 'get_occurrence_capacity' )->willReturn(
			array(
				'total_capacity'  => 30,
				'total_sold'      => 27,
				'total_available' => 3,
				'has_unlimited'   => false,
				'ticket_types'    => array(),
			)
		);

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'id' => 100 ) );

		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );

		$data = $response->get_data();
		$this->assertTrue( $data['tickets']['available'] );
		$this->assertTrue( $data['tickets']['low_stock'] );
		$this->assertSame( 3, $data['tickets']['total_left'] );
	}

	/**
	 * Test ticket availability with unlimited capacity.
	 *
	 * @return void
	 */
	public function test_ticket_availability_unlimited(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$ticket_type     = $this->createMock( TicketType::class );
		$ticket_type->id = 1;
		$ticket_type->name = 'Free Entry';
		$ticket_type->price = 0.00;

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array( $ticket_type ) );

		$capacity_service = $this->createMock( CapacityService::class );
		$capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'is_unlimited'       => true,
				'is_sold_out'        => false,
				'is_low_stock'       => false,
				'effective_available' => 0,
			)
		);
		// Unlimited house: total_available is null, so total_left comes through as null.
		$capacity_service->method( 'get_occurrence_capacity' )->willReturn(
			array(
				'total_capacity'  => null,
				'total_sold'      => 0,
				'total_available' => null,
				'has_unlimited'   => true,
				'ticket_types'    => array(),
			)
		);

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'id' => 100 ) );

		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );

		$data = $response->get_data();
		$this->assertTrue( $data['tickets']['available'] );
		$this->assertNull( $data['tickets']['total_left'] );
	}

	/**
	 * Test ticket availability with no tickets.
	 *
	 * @return void
	 */
	public function test_ticket_availability_no_tickets(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'id' => 100 ) );

		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );

		$data = $response->get_data();
		$this->assertFalse( $data['tickets']['available'] );
		$this->assertFalse( $data['tickets']['sold_out'] );
		$this->assertNull( $data['tickets']['min_price'] );
	}

	// =========================================================================
	// Format Methods Tests (via prepare_occurrence)
	// =========================================================================

	/**
	 * Test formatted date in response.
	 *
	 * @return void
	 */
	public function test_occurrence_includes_formatted_date(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'id' => 100 ) );

		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'formatted', $data );
		$this->assertArrayHasKey( 'date', $data['formatted'] );
		$this->assertArrayHasKey( 'time', $data['formatted'] );
		$this->assertArrayHasKey( 'date_range', $data['formatted'] );
	}

	/**
	 * Test all-day event formatting.
	 *
	 * @return void
	 */
	public function test_all_day_event_time_formatting(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );
		$occurrence->all_day = true;

		$occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );

		$ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$capacity_service = $this->createMock( CapacityService::class );

		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->capacity_service = $capacity_service;

		$request = $this->create_mock_request( array( 'id' => 100 ) );

		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );

		$data = $response->get_data();
		$this->assertSame( 'All Day', $data['formatted']['time'] );
	}

	// =========================================================================
	// Mutation-Killing Tests — Rate Limiting
	// =========================================================================

	/**
	 * Test that rate limiting is checked on get_upcoming.
	 *
	 * Kills IfNegation mutant on check_rate_limit() return check.
	 *
	 * @return void
	 */
	public function test_get_upcoming_returns_429_when_rate_limited(): void {
		$rate_limit_service = $this->createMock( \NetterTechEvents\Services\RateLimitService::class );
		$rate_limit_service->method( 'should_bypass' )->willReturn( false );
		$rate_limit_service->method( 'check_and_increment' )->willReturn(
			new \WP_REST_Response( array( 'error' => 'Rate limited' ), 429 )
		);

		$controller = new EventsController(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->capacity_service,
			$rate_limit_service
		);

		$request  = $this->create_mock_request( array( 'limit' => 10 ) );
		$response = $controller->get_upcoming( $request );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * Test that rate limiting is checked on get_range.
	 *
	 * @return void
	 */
	public function test_get_range_returns_429_when_rate_limited(): void {
		$rate_limit_service = $this->createMock( \NetterTechEvents\Services\RateLimitService::class );
		$rate_limit_service->method( 'should_bypass' )->willReturn( false );
		$rate_limit_service->method( 'check_and_increment' )->willReturn(
			new \WP_REST_Response( array( 'error' => 'Rate limited' ), 429 )
		);

		$controller = new EventsController(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->capacity_service,
			$rate_limit_service
		);

		$request  = $this->create_mock_request( array( 'start' => '2026-01-01', 'end' => '2026-01-31' ) );
		$response = $controller->get_range( $request );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * Test that rate limiting is checked on get_item.
	 *
	 * @return void
	 */
	public function test_get_item_returns_429_when_rate_limited(): void {
		$rate_limit_service = $this->createMock( \NetterTechEvents\Services\RateLimitService::class );
		$rate_limit_service->method( 'should_bypass' )->willReturn( false );
		$rate_limit_service->method( 'check_and_increment' )->willReturn(
			new \WP_REST_Response( array( 'error' => 'Rate limited' ), 429 )
		);

		$controller = new EventsController(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->capacity_service,
			$rate_limit_service
		);

		$request  = $this->create_mock_request( array( 'id' => 100 ) );
		$response = $controller->get_item( $request );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * Test that rate limiting is checked on get_occurrences.
	 *
	 * @return void
	 */
	public function test_get_occurrences_returns_429_when_rate_limited(): void {
		$rate_limit_service = $this->createMock( \NetterTechEvents\Services\RateLimitService::class );
		$rate_limit_service->method( 'should_bypass' )->willReturn( false );
		$rate_limit_service->method( 'check_and_increment' )->willReturn(
			new \WP_REST_Response( array( 'error' => 'Rate limited' ), 429 )
		);

		$controller = new EventsController(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->capacity_service,
			$rate_limit_service
		);

		$request  = $this->create_mock_request();
		$response = $controller->get_occurrences( $request );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * Test rate limit bypassed for admins.
	 *
	 * Kills IfNegation on should_bypass() check.
	 *
	 * @return void
	 */
	public function test_rate_limit_bypassed_when_should_bypass_returns_true(): void {
		$rate_limit_service = $this->createMock( \NetterTechEvents\Services\RateLimitService::class );
		$rate_limit_service->method( 'should_bypass' )->willReturn( true );
		$rate_limit_service->expects( $this->never() )->method( 'check_and_increment' );
		$rate_limit_service->method( 'add_headers' )->willReturnArgument( 0 );

		$this->occurrence_repo->method( 'upcoming' )->willReturn( array() );

		$controller = new EventsController(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->capacity_service,
			$rate_limit_service
		);

		$request  = $this->create_mock_request( array( 'limit' => 10 ) );
		$response = $controller->get_upcoming( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	// =========================================================================
	// Mutation-Killing Tests — Date Validation
	// =========================================================================

	/**
	 * Test validate_date_param returns true for valid date.
	 *
	 * Kills FalseValue mutant on strtotime check.
	 *
	 * @return void
	 */
	public function test_validate_date_param_returns_true_for_valid_date(): void {
		$controller = $this->create_controller();
		$request    = $this->create_mock_request();
		$result     = $controller->validate_date_param( '2026-03-15', $request, 'start' );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_date_param returns WP_Error for invalid date.
	 *
	 * @return void
	 */
	public function test_validate_date_param_returns_error_for_invalid_date(): void {
		$controller = $this->create_controller();
		$request    = $this->create_mock_request();
		$result     = $controller->validate_date_param( 'not-a-date', $request, 'start' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test get_range returns 400 when start is after end.
	 *
	 * Kills comparison mutant on strtotime ordering.
	 *
	 * @return void
	 */
	public function test_get_range_returns_error_when_start_after_end(): void {
		$this->occurrence_repo->method( 'in_range' )->willReturn( array() );

		$request = $this->create_mock_request(
			array(
				'start' => '2026-03-31',
				'end'   => '2026-03-01',
			)
		);

		$controller = $this->create_controller();
		$response   = $controller->get_range( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data );
		$this->assertStringContainsString( 'before or equal', $data['error'] );
	}

	/**
	 * Test get_occurrences date range returns 400 when start after end.
	 *
	 * @return void
	 */
	public function test_get_occurrences_date_range_rejects_start_after_end(): void {
		$request = $this->create_mock_request(
			array(
				'start' => '2026-03-31',
				'end'   => '2026-03-01',
			)
		);

		$controller = $this->create_controller();
		$response   = $controller->get_occurrences( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	// =========================================================================
	// Mutation-Killing Tests — Prepare Occurrence Field Values
	// =========================================================================

	/**
	 * Test prepare_occurrence maps all occurrence fields correctly.
	 *
	 * Kills ArrayItemRemoval and ConcatOperandRemoval mutants.
	 *
	 * @return void
	 */
	public function test_prepare_occurrence_maps_all_fields(): void {
		$event                    = $this->create_mock_event( 5 );
		$event->excerpt           = 'A great show';
		$event->venue_name        = 'Main Hall';
		$event->venue_address     = '456 Oak Ave';
		$event->featured_image_id = null;

		$occurrence                 = $this->create_mock_occurrence( 200, $event );
		$occurrence->event_id       = 5;
		$occurrence->start_datetime = '2026-03-15 20:00:00';
		$occurrence->end_datetime   = '2026-03-15 22:00:00';
		$occurrence->all_day        = false;
		$occurrence->status         = 'scheduled';

		$this->occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$request    = $this->create_mock_request( array( 'id' => 200 ) );
		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );
		$data       = $response->get_data();

		$this->assertSame( 200, $data['id'] );
		$this->assertSame( 5, $data['event_id'] );
		$this->assertSame( '2026-03-15 20:00:00', $data['start_datetime'] );
		$this->assertSame( '2026-03-15 22:00:00', $data['end_datetime'] );
		$this->assertFalse( $data['all_day'] );
		$this->assertSame( 'scheduled', $data['status'] );

		// Event fields.
		$this->assertSame( 5, $data['event']['id'] );
		$this->assertSame( 'Test Event 5', $data['event']['title'] );
		$this->assertSame( 'test-event-5', $data['event']['slug'] );
		$this->assertSame( 'A great show', $data['event']['excerpt'] );
		$this->assertSame( 'Main Hall', $data['event']['venue_name'] );
		$this->assertSame( '456 Oak Ave', $data['event']['venue_address'] );
		$this->assertStringContainsString( 'test-event-5', $data['event']['permalink'] );
	}

	/**
	 * Test occurrence without event omits event key.
	 *
	 * Kills IfNegation on $event null check.
	 *
	 * @return void
	 */
	public function test_prepare_occurrence_without_event_omits_event_key(): void {
		$occurrence                 = $this->createMock( \NetterTechEvents\Models\Occurrence::class );
		$occurrence->id             = 300;
		$occurrence->event_id       = 99;
		$occurrence->start_datetime = '2026-03-15 20:00:00';
		$occurrence->end_datetime   = '2026-03-15 22:00:00';
		$occurrence->all_day        = false;
		$occurrence->status         = 'scheduled';
		$occurrence->method( 'get_event' )->willReturn( null );

		$this->occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$request    = $this->create_mock_request( array( 'id' => 300 ) );
		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );
		$data       = $response->get_data();

		$this->assertArrayNotHasKey( 'event', $data );
	}

	// =========================================================================
	// Mutation-Killing Tests — Multi-Ticket Price Range
	// =========================================================================

	/**
	 * Test ticket availability with multiple ticket types tracks min/max price.
	 *
	 * Kills comparison mutants in price tracking loop.
	 *
	 * @return void
	 */
	public function test_ticket_availability_tracks_price_range_with_multiple_types(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$cheap_ticket        = $this->createMock( TicketType::class );
		$cheap_ticket->id    = 1;
		$cheap_ticket->name  = 'General';
		$cheap_ticket->price = 15.00;

		$expensive_ticket        = $this->createMock( TicketType::class );
		$expensive_ticket->id    = 2;
		$expensive_ticket->name  = 'VIP';
		$expensive_ticket->price = 75.00;

		$this->occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn(
			array( $cheap_ticket, $expensive_ticket )
		);
		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'is_unlimited'       => false,
				'is_sold_out'        => false,
				'is_low_stock'       => false,
				'effective_available' => 20,
			)
		);
		$this->capacity_service->method( 'get_occurrence_capacity' )->willReturn(
			array(
				'total_capacity'  => 50,
				'total_sold'      => 30,
				'total_available' => 20,
				'has_unlimited'   => false,
				'ticket_types'    => array(),
			)
		);

		$request    = $this->create_mock_request( array( 'id' => 100 ) );
		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );
		$data       = $response->get_data();

		$this->assertSame( 15.00, $data['tickets']['min_price'] );
		$this->assertSame( 75.00, $data['tickets']['max_price'] );
		// Both tiers report 20 available because they are looking at the same 20 seats.
		// The room has 20 left, not 40 (ADR-019).
		$this->assertSame( 20, $data['tickets']['total_left'] );
		$this->assertFalse( $data['tickets']['sold_out'] );
		$this->assertCount( 2, $data['tickets']['types'] );

		// Verify individual type data.
		$this->assertSame( 1, $data['tickets']['types'][0]['id'] );
		$this->assertSame( 'General', $data['tickets']['types'][0]['name'] );
		$this->assertSame( 15.00, $data['tickets']['types'][0]['price'] );
		$this->assertSame( 20, $data['tickets']['types'][0]['available'] );
		$this->assertFalse( $data['tickets']['types'][0]['sold_out'] );
		$this->assertFalse( $data['tickets']['types'][0]['low_stock'] );
		$this->assertFalse( $data['tickets']['types'][0]['unlimited'] );
	}

	/**
	 * Test low_stock is false when all tickets are sold out.
	 *
	 * Kills LogicalAnd mutant on low_stock && !all_sold_out.
	 *
	 * @return void
	 */
	public function test_ticket_availability_low_stock_false_when_sold_out(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );

		$ticket_type        = $this->createMock( TicketType::class );
		$ticket_type->id    = 1;
		$ticket_type->name  = 'General';
		$ticket_type->price = 25.00;

		$this->occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array( $ticket_type ) );
		$this->capacity_service->method( 'get_capacity_summary' )->willReturn(
			array(
				'is_unlimited'       => false,
				'is_sold_out'        => true,
				'is_low_stock'       => false,
				'effective_available' => 0,
			)
		);
		$this->capacity_service->method( 'get_occurrence_capacity' )->willReturn(
			array(
				'total_capacity'  => 40,
				'total_sold'      => 40,
				'total_available' => 0,
				'has_unlimited'   => false,
				'ticket_types'    => array(),
			)
		);

		$request    = $this->create_mock_request( array( 'id' => 100 ) );
		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );
		$data       = $response->get_data();

		$this->assertTrue( $data['tickets']['sold_out'] );
		$this->assertFalse( $data['tickets']['low_stock'] );
		$this->assertSame( 0, $data['tickets']['total_left'] );
	}

	// =========================================================================
	// Mutation-Killing Tests — Time Formatting
	// =========================================================================

	/**
	 * Test non-all-day time formatting includes start and end with separator.
	 *
	 * Kills ConcatOperandRemoval on time string concatenation.
	 *
	 * @return void
	 */
	public function test_time_formatting_includes_start_and_end_times(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );
		$occurrence->all_day        = false;
		$occurrence->start_datetime = '2026-03-15 19:00:00';
		$occurrence->end_datetime   = '2026-03-15 21:00:00';

		$this->occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$request    = $this->create_mock_request( array( 'id' => 100 ) );
		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );
		$data       = $response->get_data();

		$time = $data['formatted']['time'];
		$this->assertStringContainsString( ' - ', $time );
		// Should contain both start and end time components.
		$parts = explode( ' - ', $time );
		$this->assertCount( 2, $parts );
		$this->assertNotEmpty( $parts[0] );
		$this->assertNotEmpty( $parts[1] );
	}

	/**
	 * Test multi-day date range formatting includes separator.
	 *
	 * Kills ConcatOperandRemoval on date range concatenation.
	 *
	 * @return void
	 */
	public function test_date_range_multi_day_includes_both_dates(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );
		$occurrence->start_datetime = '2026-03-15 19:00:00';
		$occurrence->end_datetime   = '2026-03-17 21:00:00';

		$this->occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$request    = $this->create_mock_request( array( 'id' => 100 ) );
		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );
		$data       = $response->get_data();

		$date_range = $data['formatted']['date_range'];
		$this->assertStringContainsString( ' - ', $date_range );
		$parts = explode( ' - ', $date_range );
		$this->assertCount( 2, $parts );
	}

	/**
	 * Test same-day date range returns single date (not range).
	 *
	 * @return void
	 */
	public function test_date_range_same_day_returns_single_date(): void {
		$event      = $this->create_mock_event( 1 );
		$occurrence = $this->create_mock_occurrence( 100, $event );
		$occurrence->start_datetime = '2026-03-15 19:00:00';
		$occurrence->end_datetime   = '2026-03-15 21:00:00';

		$this->occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$request    = $this->create_mock_request( array( 'id' => 100 ) );
		$controller = $this->create_controller();
		$response   = $controller->get_item( $request );
		$data       = $response->get_data();

		$date_range = $data['formatted']['date_range'];
		// Same-day: no separator.
		$this->assertStringNotContainsString( ' - ', $date_range );
	}

	// =========================================================================
	// Mutation-Killing Tests — Occurrences Pagination Response
	// =========================================================================

	/**
	 * Test pagination response includes per_page value.
	 *
	 * @return void
	 */
	public function test_get_occurrences_pagination_includes_per_page(): void {
		$this->occurrence_repo->method( 'get_filtered' )->willReturn(
			array(
				'items'       => array(),
				'total'       => 0,
				'total_pages' => 0,
			)
		);

		$request    = $this->create_mock_request( array( 'per_page' => 24 ) );
		$controller = $this->create_controller();
		$response   = $controller->get_occurrences( $request );
		$data       = $response->get_data();

		$this->assertSame( 24, $data['per_page'] );
	}

	/**
	 * Test get_occurrences passes correct per_page to repository.
	 *
	 * Kills CastInt mutant on per_page cast.
	 *
	 * @return void
	 */
	public function test_get_occurrences_passes_per_page_to_repo(): void {
		$this->occurrence_repo->expects( $this->once() )
			->method( 'get_filtered' )
			->with(
				$this->callback(
					function ( $args ) {
						return 24 === $args['per_page'];
					}
				)
			)
			->willReturn(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);

		$request    = $this->create_mock_request( array( 'per_page' => 24 ) );
		$controller = $this->create_controller();
		$controller->get_occurrences( $request );
	}

	/**
	 * Test public_events_permission_check always returns true.
	 *
	 * Kills FalseValue mutant on permission check return.
	 *
	 * @return void
	 */
	public function test_public_events_permission_check_returns_true(): void {
		$controller = $this->create_controller();
		$this->assertTrue( $controller->public_events_permission_check() );
	}
}
