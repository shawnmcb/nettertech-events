<?php
/**
 * EventsAdminController unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\API
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\API;

use Brain\Monkey\Functions;
use NetterTechEvents\API\EventsAdminController;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Services\ActivityLogService;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Services\RateLimitService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Test EventsAdminController functionality.
 */
class EventsAdminControllerTest extends \NetterTechEventsTestCase {

	/**
	 * EventsAdminController instance.
	 *
	 * @var EventsAdminController
	 */
	private EventsAdminController $controller;

	/**
	 * Mock EventRepository.
	 *
	 * @var EventRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_repo;

	/**
	 * Mock ActivityLogService.
	 *
	 * @var ActivityLogServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $activity_log;

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

		ServiceRegistry::reset();

		// Set up WP function mocks.
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_title' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( array() );

		// Use concrete classes with mocked methods.
		$this->event_repo = $this->getMockBuilder( EventRepository::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'find', 'save', 'delete', 'paginate', 'generate_unique_slug' ) )
			->getMock();
		$this->event_repo->method( 'generate_unique_slug' )->willReturnCallback( 'sanitize_title' );

		$this->activity_log = $this->getMockBuilder( ActivityLogService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'log' ) )
			->getMock();

		$this->rate_limit = $this->createMock( RateLimitService::class );

		// Rate limit service should not block by default in tests.
		$this->rate_limit->method( 'should_bypass' )->willReturn( true );
		$this->rate_limit->method( 'add_headers' )->willReturnArgument( 0 );

		$this->controller = new EventsAdminController(
			$this->event_repo,
			$this->activity_log,
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
	 * Create a mock WP_REST_Request.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 * @param string               $method HTTP method.
	 * @return WP_REST_Request|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_request( array $params = array(), string $method = 'GET' ) {
		$request = $this->createMock( WP_REST_Request::class );
		$request->method( 'get_method' )->willReturn( $method );
		$request->method( 'get_param' )->willReturnCallback(
			function ( $key ) use ( $params ) {
				return $params[ $key ] ?? null;
			}
		);
		$request->method( 'get_params' )->willReturn( $params );
		return $request;
	}

	/**
	 * Create a mock Event.
	 *
	 * @param int                  $id    Event ID.
	 * @param array<string, mixed> $props Event properties.
	 * @return Event
	 */
	private function create_event( int $id, array $props = array() ): Event {
		$event = new Event();
		$event->id          = $id;
		$event->title       = $props['title'] ?? 'Test Event';
		$event->slug        = $props['slug'] ?? 'test-event';
		$event->description = $props['description'] ?? 'Test description';
		$event->status      = isset( $props['status'] )
			? ( $props['status'] instanceof EventStatus ? $props['status'] : EventStatus::tryFrom( $props['status'] ) ?? EventStatus::DRAFT )
			: EventStatus::PUBLISHED;
		$event->event_type  = $props['event_type'] ?? 'single';
		$event->created_at  = $props['created_at'] ?? '2026-01-01 10:00:00';
		$event->updated_at  = $props['updated_at'] ?? '2026-01-01 10:00:00';
		return $event;
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test constructor creates instance with injected dependencies.
	 *
	 * @return void
	 */
	public function test_constructor_with_injected_dependencies(): void {
		$controller = new EventsAdminController(
			$this->event_repo,
			$this->activity_log,
			$this->rate_limit
		);

		$this->assertInstanceOf( EventsAdminController::class, $controller );
	}

	// =========================================================================
	// register_routes Tests
	// =========================================================================

	/**
	 * Test register_routes registers collection routes.
	 *
	 * @return void
	 */
	public function test_register_routes_registers_collection(): void {
		$routes_registered = array();

		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) use ( &$routes_registered ) {
				$routes_registered[] = array(
					'namespace' => $namespace,
					'route'     => $route,
				);
			}
		);

		$this->controller->register_routes();

		$this->assertNotEmpty( $routes_registered );

		// Check for collection route.
		$collection_routes = array_filter(
			$routes_registered,
			fn( $r ) => $r['route'] === '/admin/events'
		);
		$this->assertNotEmpty( $collection_routes );
	}

	/**
	 * Test register_routes registers item routes.
	 *
	 * @return void
	 */
	public function test_register_routes_registers_item(): void {
		$routes_registered = array();

		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) use ( &$routes_registered ) {
				$routes_registered[] = array(
					'namespace' => $namespace,
					'route'     => $route,
				);
			}
		);

		$this->controller->register_routes();

		// Check for item route.
		$item_routes = array_filter(
			$routes_registered,
			fn( $r ) => strpos( $r['route'], '/admin/events/' ) === 0
		);
		$this->assertNotEmpty( $item_routes );
	}

	// =========================================================================
	// admin_permissions_check Tests
	// =========================================================================

	/**
	 * Test admin_permissions_check returns true for admin.
	 *
	 * @return void
	 */
	public function test_admin_permissions_check_returns_true_for_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$request = $this->create_request();
		$result  = $this->controller->admin_permissions_check( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test admin_permissions_check returns WP_Error for non-admin.
	 *
	 * @return void
	 */
	public function test_admin_permissions_check_returns_error_for_non_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$request = $this->create_request();
		$result  = $this->controller->admin_permissions_check( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	// =========================================================================
	// get_items Tests
	// =========================================================================

	/**
	 * Test get_items returns paginated events.
	 *
	 * @return void
	 */
	public function test_get_items_returns_paginated_events(): void {
		$events = array(
			$this->create_event( 1, array( 'title' => 'Event 1' ) ),
			$this->create_event( 2, array( 'title' => 'Event 2' ) ),
		);

		$this->event_repo->expects( $this->once() )
			->method( 'paginate' )
			->willReturn(
				array(
					'items' => $events,
					'total' => 2,
					'pages' => 1,
				)
			);

		$request  = $this->create_request( array( 'page' => 1, 'per_page' => 20 ) );
		$response = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertCount( 2, $data['items'] );
		$this->assertSame( 2, $data['total'] );
		$this->assertSame( 1, $data['total_pages'] );
	}

	/**
	 * Test get_items with status filter.
	 *
	 * @return void
	 */
	public function test_get_items_with_status_filter(): void {
		$this->event_repo->expects( $this->once() )
			->method( 'paginate' )
			->with(
				$this->callback(
					function ( $args ) {
						return isset( $args['status'] ) && 'published' === $args['status'];
					}
				)
			)
			->willReturn(
				array(
					'items' => array(),
					'total' => 0,
					'pages' => 0,
				)
			);

		$request  = $this->create_request( array( 'status' => 'published' ) );
		$response = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test get_items with search filter.
	 *
	 * @return void
	 */
	public function test_get_items_with_search_filter(): void {
		$this->event_repo->expects( $this->once() )
			->method( 'paginate' )
			->with(
				$this->callback(
					function ( $args ) {
						return isset( $args['search'] ) && 'concert' === $args['search'];
					}
				)
			)
			->willReturn(
				array(
					'items' => array(),
					'total' => 0,
					'pages' => 0,
				)
			);

		$request  = $this->create_request( array( 'search' => 'concert' ) );
		$response = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	// =========================================================================
	// get_item Tests
	// =========================================================================

	/**
	 * Test get_item returns event when found.
	 *
	 * @return void
	 */
	public function test_get_item_returns_event(): void {
		$event = $this->create_event( 1 );

		$this->event_repo->expects( $this->once() )
			->method( 'find' )
			->with( 1 )
			->willReturn( $event );

		$request  = $this->create_request( array( 'id' => 1 ) );
		$response = $this->controller->get_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 1, $data['id'] );
		$this->assertSame( 'Test Event', $data['title'] );
	}

	/**
	 * Test get_item returns 404 when not found.
	 *
	 * @return void
	 */
	public function test_get_item_returns_404_when_not_found(): void {
		$this->event_repo->expects( $this->once() )
			->method( 'find' )
			->with( 999 )
			->willReturn( null );

		$request  = $this->create_request( array( 'id' => 999 ) );
		$response = $this->controller->get_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 404, $response->get_status() );
	}

	// =========================================================================
	// create_item Tests
	// =========================================================================

	/**
	 * Test create_item creates event with valid data.
	 *
	 * @return void
	 */
	public function test_create_item_creates_event(): void {
		$saved_event = $this->create_event( 1, array( 'title' => 'New Event' ) );

		$this->event_repo->expects( $this->once() )
			->method( 'save' )
			->willReturn( $saved_event );

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with( 'create', 'event', 1, 'New Event' );

		$request  = $this->create_request(
			array(
				'title'      => 'New Event',
				'status'     => 'published',
				'event_type' => 'single',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
	}

	/**
	 * Test create_item returns validation error for missing title.
	 *
	 * @return void
	 */
	public function test_create_item_validation_error(): void {
		// No title provided, should fail validation.
		$request  = $this->create_request( array(), 'POST' );
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'validation_error', $data['code'] );
	}

	/**
	 * Test create_item generates slug from title.
	 *
	 * @return void
	 */
	public function test_create_item_generates_slug(): void {
		$saved_event = $this->create_event( 1, array( 'title' => 'My Great Event' ) );

		$this->event_repo->expects( $this->once() )
			->method( 'save' )
			->willReturn( $saved_event );

		$this->activity_log->method( 'log' );

		$request  = $this->create_request(
			array(
				'title' => 'My Great Event',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertSame( 201, $response->get_status() );
	}

	/**
	 * Test create_item handles repository exception.
	 *
	 * @return void
	 */
	public function test_create_item_handles_exception(): void {
		$this->event_repo->expects( $this->once() )
			->method( 'save' )
			->willThrowException( new \RuntimeException( 'Database error' ) );

		$request  = $this->create_request(
			array(
				'title'  => 'New Event',
				'status' => 'published',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 500, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'creation_failed', $data['code'] );
	}

	// =========================================================================
	// update_item Tests
	// =========================================================================

	/**
	 * Test update_item updates event.
	 *
	 * @return void
	 */
	public function test_update_item_updates_event(): void {
		$existing_event = $this->create_event( 1 );
		$updated_event  = $this->create_event( 1, array( 'title' => 'Updated Title' ) );

		$this->event_repo->expects( $this->once() )
			->method( 'find' )
			->with( 1 )
			->willReturn( $existing_event );

		$this->event_repo->expects( $this->once() )
			->method( 'save' )
			->willReturn( $updated_event );

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with( 'update', 'event', 1, 'Updated Title' );

		$request  = $this->create_request(
			array(
				'id'    => 1,
				'title' => 'Updated Title',
			),
			'PUT'
		);
		$response = $this->controller->update_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test update_item returns 404 when event not found.
	 *
	 * @return void
	 */
	public function test_update_item_returns_404_when_not_found(): void {
		$this->event_repo->expects( $this->once() )
			->method( 'find' )
			->with( 999 )
			->willReturn( null );

		$request  = $this->create_request( array( 'id' => 999 ), 'PUT' );
		$response = $this->controller->update_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Test update_item handles repository exception.
	 *
	 * @return void
	 */
	public function test_update_item_handles_exception(): void {
		$existing_event = $this->create_event( 1 );

		$this->event_repo->expects( $this->once() )
			->method( 'find' )
			->willReturn( $existing_event );

		$this->event_repo->expects( $this->once() )
			->method( 'save' )
			->willThrowException( new \RuntimeException( 'Database error' ) );

		$request  = $this->create_request(
			array(
				'id'    => 1,
				'title' => 'Updated',
			),
			'PUT'
		);
		$response = $this->controller->update_item( $request );

		$this->assertSame( 500, $response->get_status() );
	}

	// =========================================================================
	// delete_item Tests
	// =========================================================================

	/**
	 * Test delete_item deletes event.
	 *
	 * @return void
	 */
	public function test_delete_item_deletes_event(): void {
		$event = $this->create_event( 1 );

		$this->event_repo->expects( $this->once() )
			->method( 'find' )
			->with( 1 )
			->willReturn( $event );

		$this->event_repo->expects( $this->once() )
			->method( 'delete' )
			->with( 1 )
			->willReturn( true );

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with( 'delete', 'event', 1, 'Test Event' );

		$request  = $this->create_request( array( 'id' => 1 ), 'DELETE' );
		$response = $this->controller->delete_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['deleted'] );
		$this->assertSame( 1, $data['id'] );
	}

	/**
	 * Test delete_item returns 404 when event not found.
	 *
	 * @return void
	 */
	public function test_delete_item_returns_404_when_not_found(): void {
		$this->event_repo->expects( $this->once() )
			->method( 'find' )
			->with( 999 )
			->willReturn( null );

		$request  = $this->create_request( array( 'id' => 999 ), 'DELETE' );
		$response = $this->controller->delete_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Test delete_item returns 500 when delete fails.
	 *
	 * @return void
	 */
	public function test_delete_item_returns_500_when_delete_fails(): void {
		$event = $this->create_event( 1 );

		$this->event_repo->expects( $this->once() )
			->method( 'find' )
			->willReturn( $event );

		$this->event_repo->expects( $this->once() )
			->method( 'delete' )
			->willReturn( false );

		$request  = $this->create_request( array( 'id' => 1 ), 'DELETE' );
		$response = $this->controller->delete_item( $request );

		$this->assertSame( 500, $response->get_status() );
	}

	/**
	 * Test delete_item handles exception.
	 *
	 * @return void
	 */
	public function test_delete_item_handles_exception(): void {
		$event = $this->create_event( 1 );

		$this->event_repo->expects( $this->once() )
			->method( 'find' )
			->willReturn( $event );

		$this->event_repo->expects( $this->once() )
			->method( 'delete' )
			->willThrowException( new \RuntimeException( 'Database error' ) );

		$request  = $this->create_request( array( 'id' => 1 ), 'DELETE' );
		$response = $this->controller->delete_item( $request );

		$this->assertSame( 500, $response->get_status() );
	}

	// =========================================================================
	// Rate Limit Tests
	// =========================================================================

	/**
	 * Test get_items respects rate limit.
	 *
	 * @return void
	 */
	public function test_get_items_respects_rate_limit(): void {
		// Reconfigure rate limit to return limit response.
		$limited_rate_limit = $this->createMock( RateLimitService::class );
		$limited_rate_limit->method( 'should_bypass' )->willReturn( false );
		$limited_rate_limit->method( 'check_and_increment' )->willReturn(
			new WP_REST_Response(
				array(
					'code'    => 'rate_limit_exceeded',
					'message' => 'Too many requests',
				),
				429
			)
		);

		$controller = new EventsAdminController(
			$this->event_repo,
			$this->activity_log,
			$limited_rate_limit
		);

		$request  = $this->create_request();
		$response = $controller->get_items( $request );

		$this->assertSame( 429, $response->get_status() );
	}

	// =========================================================================
	// get_collection_params Tests
	// =========================================================================

	/**
	 * Test get_collection_params returns expected keys.
	 *
	 * @return void
	 */
	public function test_get_collection_params_returns_expected_keys(): void {
		$params = $this->controller->get_collection_params();

		$expected_keys = array( 'page', 'per_page', 'status', 'search', 'orderby', 'order' );

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $params, "Missing expected key: $key" );
		}
	}

	/**
	 * Test get_collection_params has correct defaults.
	 *
	 * @return void
	 */
	public function test_get_collection_params_defaults(): void {
		$params = $this->controller->get_collection_params();

		$this->assertSame( 1, $params['page']['default'] );
		$this->assertSame( 20, $params['per_page']['default'] );
		$this->assertSame( 'created_at', $params['orderby']['default'] );
		$this->assertSame( 'desc', $params['order']['default'] );
	}
}
