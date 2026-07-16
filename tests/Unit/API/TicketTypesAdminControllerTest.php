<?php
/**
 * TicketTypesAdminController unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\API
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\API;

use Brain\Monkey\Functions;
use NetterTechEvents\API\TicketTypesAdminController;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Services\ActivityLogService;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Services\RateLimitService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Test TicketTypesAdminController functionality.
 */
class TicketTypesAdminControllerTest extends \NetterTechEventsTestCase {

	/**
	 * TicketTypesAdminController instance.
	 *
	 * @var TicketTypesAdminController
	 */
	private TicketTypesAdminController $controller;

	/**
	 * Mock TicketTypeRepository.
	 *
	 * @var TicketTypeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ticket_type_repo;

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
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( array() );

		// Use concrete classes with mocked methods.
		$this->ticket_type_repo = $this->getMockBuilder( TicketTypeRepository::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'find', 'save', 'delete', 'for_occurrence', 'for_event' ) )
			->getMock();

		$this->activity_log = $this->getMockBuilder( ActivityLogService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'log' ) )
			->getMock();

		$this->rate_limit = $this->getMockBuilder( RateLimitService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'check_and_increment' ) )
			->getMock();

		// Rate limit service should allow requests by default in tests.
		$this->rate_limit->method( 'check_and_increment' )->willReturn( null );

		$this->controller = new TicketTypesAdminController(
			$this->ticket_type_repo,
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
	 * Create a WP_REST_Request instance.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 * @param string               $method HTTP method.
	 * @return WP_REST_Request
	 */
	private function create_request( array $params = array(), string $method = 'GET' ): WP_REST_Request {
		$request = new WP_REST_Request( $method );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Create a mock TicketType.
	 *
	 * @param int                  $id    Ticket type ID.
	 * @param array<string, mixed> $props Ticket type properties.
	 * @return TicketType
	 */
	private function create_ticket_type( int $id, array $props = array() ): TicketType {
		$ticket_type                  = new TicketType();
		$ticket_type->id              = $id;
		$ticket_type->occurrence_id   = $props['occurrence_id'] ?? 100;
		$ticket_type->event_id        = $props['event_id'] ?? null;
		$ticket_type->scope           = $props['scope'] ?? 'occurrence';
		$ticket_type->template_id     = $props['template_id'] ?? null;
		$ticket_type->name            = $props['name'] ?? 'General Admission';
		$ticket_type->description     = $props['description'] ?? null;
		$ticket_type->price           = $props['price'] ?? 25.00;
		$ticket_type->capacity_type   = $props['capacity_type'] ?? 'fixed';
		$ticket_type->capacity        = $props['capacity'] ?? 100;
		$ticket_type->sold_count      = $props['sold_count'] ?? 0;
		$ticket_type->stock_status    = $props['stock_status'] ?? 'in_stock';
		$ticket_type->sale_start      = $props['sale_start'] ?? null;
		$ticket_type->sale_end        = $props['sale_end'] ?? null;
		$ticket_type->min_per_order   = $props['min_per_order'] ?? 1;
		$ticket_type->max_per_order   = $props['max_per_order'] ?? 10;
		$ticket_type->sort_order      = $props['sort_order'] ?? 0;
		$ticket_type->status          = $props['status'] ?? 'active';
		$ticket_type->wc_product_id   = $props['wc_product_id'] ?? null;
		$ticket_type->wc_variation_id = $props['wc_variation_id'] ?? null;
		$ticket_type->created_at      = $props['created_at'] ?? '2026-01-01 10:00:00';
		$ticket_type->updated_at      = $props['updated_at'] ?? '2026-01-01 10:00:00';
		return $ticket_type;
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
		$controller = new TicketTypesAdminController(
			$this->ticket_type_repo,
			$this->activity_log,
			$this->rate_limit
		);

		$this->assertInstanceOf( TicketTypesAdminController::class, $controller );
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

		$this->assertCount( 2, $routes_registered );
		$this->assertEquals( 'nettertech-events/v1', $routes_registered[0]['namespace'] );
		$this->assertEquals( '/admin/ticket-types', $routes_registered[0]['route'] );
	}

	/**
	 * Test register_routes registers single item routes.
	 *
	 * @return void
	 */
	public function test_register_routes_registers_single_item(): void {
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

		$this->assertEquals( '/admin/ticket-types/(?P<id>\d+)', $routes_registered[1]['route'] );
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
	 * Test admin_permissions_check returns error for non-admin.
	 *
	 * @return void
	 */
	public function test_admin_permissions_check_returns_error_for_non_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$request = $this->create_request();
		$result  = $this->controller->admin_permissions_check( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test admin_permissions_check returns error when rate limited.
	 *
	 * @return void
	 */
	public function test_admin_permissions_check_returns_error_when_rate_limited(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$rate_limit = $this->getMockBuilder( RateLimitService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'check_and_increment' ) )
			->getMock();

		$rate_limit_response = $this->createMock( \WP_REST_Response::class );
		$rate_limit_response->method( 'get_data' )->willReturn(
			array(
				'data' => array(
					'retry_after' => 60,
				),
			)
		);
		$rate_limit->method( 'check_and_increment' )->willReturn( $rate_limit_response );

		$controller = new TicketTypesAdminController(
			$this->ticket_type_repo,
			$this->activity_log,
			$rate_limit
		);

		$request = $this->create_request();
		$result  = $controller->admin_permissions_check( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'rate_limit_exceeded', $result->get_error_code() );
	}

	// =========================================================================
	// get_items Tests
	// =========================================================================

	/**
	 * Test get_items returns ticket types for occurrence.
	 *
	 * @return void
	 */
	public function test_get_items_returns_ticket_types_for_occurrence(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$ticket_types = array(
			$this->create_ticket_type( 1, array( 'name' => 'General Admission' ) ),
			$this->create_ticket_type( 2, array( 'name' => 'VIP' ) ),
		);

		$this->ticket_type_repo->method( 'for_occurrence' )
			->with( 100, array() )
			->willReturn( $ticket_types );

		$request  = $this->create_request( array( 'occurrence_id' => 100 ) );
		$response = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertCount( 2, $data );
		$this->assertEquals( 'General Admission', $data[0]['name'] );
		$this->assertEquals( 'VIP', $data[1]['name'] );
	}

	/**
	 * Test get_items returns ticket types for event.
	 *
	 * @return void
	 */
	public function test_get_items_returns_ticket_types_for_event(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$ticket_types = array(
			$this->create_ticket_type( 1, array( 'name' => 'Event Pass', 'scope' => 'event', 'event_id' => 50 ) ),
		);

		$this->ticket_type_repo->method( 'for_event' )
			->with( 50, array() )
			->willReturn( $ticket_types );

		$request  = $this->create_request( array( 'event_id' => 50 ) );
		$response = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertEquals( 'Event Pass', $data[0]['name'] );
	}

	/**
	 * Test get_items returns error without parent.
	 *
	 * @return void
	 */
	public function test_get_items_returns_error_without_parent(): void {
		$request  = $this->create_request( array() );
		$response = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'missing_parent', $response->get_error_code() );
	}

	/**
	 * Test get_items filters by status.
	 *
	 * @return void
	 */
	public function test_get_items_filters_by_status(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$ticket_types = array(
			$this->create_ticket_type( 1, array( 'status' => 'inactive' ) ),
		);

		$this->ticket_type_repo->method( 'for_occurrence' )
			->with( 100, array( 'status' => 'inactive' ) )
			->willReturn( $ticket_types );

		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'status'        => 'inactive',
			)
		);
		$response = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertEquals( 'inactive', $data[0]['status'] );
	}

	/**
	 * Test get_items filters by scope.
	 *
	 * @return void
	 */
	public function test_get_items_filters_by_scope(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$ticket_types = array(
			$this->create_ticket_type( 1, array( 'scope' => 'template' ) ),
		);

		$this->ticket_type_repo->method( 'for_event' )
			->with( 50, array( 'scope' => 'template' ) )
			->willReturn( $ticket_types );

		$request  = $this->create_request(
			array(
				'event_id' => 50,
				'scope'    => 'template',
			)
		);
		$response = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertEquals( 'template', $data[0]['scope'] );
	}

	// =========================================================================
	// get_item Tests
	// =========================================================================

	/**
	 * Test get_item returns ticket type.
	 *
	 * @return void
	 */
	public function test_get_item_returns_ticket_type(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$ticket_type = $this->create_ticket_type( 1, array( 'name' => 'General Admission' ) );

		$this->ticket_type_repo->method( 'find' )
			->with( 1 )
			->willReturn( $ticket_type );

		$request  = $this->create_request( array( 'id' => 1 ) );
		$response = $this->controller->get_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertEquals( 1, $data['id'] );
		$this->assertEquals( 'General Admission', $data['name'] );
	}

	/**
	 * Test get_item returns error for not found.
	 *
	 * @return void
	 */
	public function test_get_item_returns_error_for_not_found(): void {
		$this->ticket_type_repo->method( 'find' )
			->with( 999 )
			->willReturn( null );

		$request  = $this->create_request( array( 'id' => 999 ) );
		$response = $this->controller->get_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'ticket_type_not_found', $response->get_error_code() );
	}

	// =========================================================================
	// create_item Tests
	// =========================================================================

	/**
	 * Test create_item creates occurrence-scoped ticket type.
	 *
	 * @return void
	 */
	public function test_create_item_creates_occurrence_scoped_ticket_type(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$saved_ticket_type = $this->create_ticket_type(
			1,
			array(
				'occurrence_id' => 100,
				'name'          => 'General Admission',
				'price'         => 25.00,
			)
		);

		$this->ticket_type_repo->method( 'save' )
			->willReturn( $saved_ticket_type );

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with( 'create', 'ticket_type', 1, 'General Admission', $this->anything() );

		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'General Admission',
				'price'         => 25.00,
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'General Admission', $data['name'] );
	}

	/**
	 * Test create_item creates event-scoped ticket type.
	 *
	 * @return void
	 */
	public function test_create_item_creates_event_scoped_ticket_type(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$saved_ticket_type = $this->create_ticket_type(
			1,
			array(
				'event_id'      => 50,
				'occurrence_id' => null,
				'scope'         => 'event',
				'name'          => 'Season Pass',
			)
		);

		$this->ticket_type_repo->method( 'save' )
			->willReturn( $saved_ticket_type );

		$request  = $this->create_request(
			array(
				'event_id' => 50,
				'scope'    => 'event',
				'name'     => 'Season Pass',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 201, $response->get_status() );
	}

	/**
	 * Test create_item returns error without name.
	 *
	 * @return void
	 */
	public function test_create_item_returns_error_without_name(): void {
		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'missing_name', $response->get_error_code() );
	}

	/**
	 * Test create_item returns error without occurrence_id for occurrence scope.
	 *
	 * @return void
	 */
	public function test_create_item_returns_error_without_occurrence_id(): void {
		$request  = $this->create_request(
			array(
				'name'  => 'Test',
				'scope' => 'occurrence',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'missing_occurrence_id', $response->get_error_code() );
	}

	/**
	 * Test create_item returns error without event_id for event scope.
	 *
	 * @return void
	 */
	public function test_create_item_returns_error_without_event_id(): void {
		$request  = $this->create_request(
			array(
				'name'  => 'Test',
				'scope' => 'event',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'missing_event_id', $response->get_error_code() );
	}

	/**
	 * Test create_item returns error with invalid scope.
	 *
	 * @return void
	 */
	public function test_create_item_returns_error_with_invalid_scope(): void {
		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'scope'         => 'invalid_scope',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'invalid_scope', $response->get_error_code() );
	}

	/**
	 * Test create_item returns error with invalid capacity_type.
	 *
	 * @return void
	 */
	public function test_create_item_returns_error_with_invalid_capacity_type(): void {
		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'capacity_type' => 'invalid_type',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'invalid_capacity_type', $response->get_error_code() );
	}

	/**
	 * Test create_item returns error with negative price.
	 *
	 * @return void
	 */
	public function test_create_item_returns_error_with_negative_price(): void {
		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'price'         => -10,
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'invalid_price', $response->get_error_code() );
	}

	/**
	 * Test create_item with optional fields.
	 *
	 * @return void
	 */
	public function test_create_item_with_optional_fields(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$saved_ticket_type = $this->create_ticket_type(
			1,
			array(
				'occurrence_id' => 100,
				'name'          => 'VIP Pass',
				'description'   => 'Premium seating',
				'price'         => 100.00,
				'capacity_type' => 'fixed',
				'capacity'      => 50,
				'sale_start'    => '2026-01-01 00:00:00',
				'sale_end'      => '2026-12-31 23:59:59',
				'min_per_order' => 1,
				'max_per_order' => 4,
				'sort_order'    => 1,
				'status'        => 'active',
			)
		);

		$this->ticket_type_repo->method( 'save' )
			->willReturn( $saved_ticket_type );

		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'VIP Pass',
				'description'   => 'Premium seating',
				'price'         => 100.00,
				'capacity_type' => 'fixed',
				'capacity'      => 50,
				'sale_start'    => '2026-01-01 00:00:00',
				'sale_end'      => '2026-12-31 23:59:59',
				'min_per_order' => 1,
				'max_per_order' => 4,
				'sort_order'    => 1,
				'status'        => 'active',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertEquals( 'VIP Pass', $data['name'] );
		$this->assertEquals( 'Premium seating', $data['description'] );
		$this->assertEquals( 100.00, $data['price'] );
	}

	/**
	 * Test create_item returns error on save failure.
	 *
	 * @return void
	 */
	public function test_create_item_returns_error_on_save_failure(): void {
		$this->ticket_type_repo->method( 'save' )
			->willThrowException( new \RuntimeException( 'Database error' ) );

		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'ticket_type_create_failed', $response->get_error_code() );
	}

	// =========================================================================
	// update_item Tests
	// =========================================================================

	/**
	 * Test update_item updates ticket type.
	 *
	 * @return void
	 */
	public function test_update_item_updates_ticket_type(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$existing = $this->create_ticket_type( 1, array( 'name' => 'Old Name' ) );
		$updated  = $this->create_ticket_type( 1, array( 'name' => 'New Name' ) );

		$this->ticket_type_repo->method( 'find' )
			->with( 1 )
			->willReturn( $existing );

		$this->ticket_type_repo->method( 'save' )
			->willReturn( $updated );

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with( 'update', 'ticket_type', 1, 'New Name', $this->anything() );

		$request  = $this->create_request(
			array(
				'id'   => 1,
				'name' => 'New Name',
			),
			'PUT'
		);
		$response = $this->controller->update_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertEquals( 'New Name', $data['name'] );
	}

	/**
	 * Test update_item returns error for not found.
	 *
	 * @return void
	 */
	public function test_update_item_returns_error_for_not_found(): void {
		$this->ticket_type_repo->method( 'find' )
			->with( 999 )
			->willReturn( null );

		$request  = $this->create_request(
			array(
				'id'   => 999,
				'name' => 'Test',
			),
			'PUT'
		);
		$response = $this->controller->update_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'ticket_type_not_found', $response->get_error_code() );
	}

	/**
	 * Test update_item with no changes returns current state.
	 *
	 * @return void
	 */
	public function test_update_item_with_no_changes_returns_current_state(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$existing = $this->create_ticket_type( 1, array( 'name' => 'Same Name' ) );

		$this->ticket_type_repo->method( 'find' )
			->with( 1 )
			->willReturn( $existing );

		// Save should NOT be called when no changes.
		$this->ticket_type_repo->expects( $this->never() )
			->method( 'save' );

		$request  = $this->create_request(
			array(
				'id'   => 1,
				'name' => 'Same Name',
			),
			'PUT'
		);
		$response = $this->controller->update_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertEquals( 'Same Name', $data['name'] );
	}

	/**
	 * Test update_item updates price.
	 *
	 * @return void
	 */
	public function test_update_item_updates_price(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$existing = $this->create_ticket_type( 1, array( 'price' => 25.00 ) );
		$updated  = $this->create_ticket_type( 1, array( 'price' => 35.00 ) );

		$this->ticket_type_repo->method( 'find' )
			->with( 1 )
			->willReturn( $existing );

		$this->ticket_type_repo->method( 'save' )
			->willReturn( $updated );

		$request  = $this->create_request(
			array(
				'id'    => 1,
				'price' => 35.00,
			),
			'PUT'
		);
		$response = $this->controller->update_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertEquals( 35.00, $data['price'] );
	}

	/**
	 * Test update_item returns error on save failure.
	 *
	 * @return void
	 */
	public function test_update_item_returns_error_on_save_failure(): void {
		$existing = $this->create_ticket_type( 1, array( 'name' => 'Old Name' ) );

		$this->ticket_type_repo->method( 'find' )
			->with( 1 )
			->willReturn( $existing );

		$this->ticket_type_repo->method( 'save' )
			->willThrowException( new \RuntimeException( 'Database error' ) );

		$request  = $this->create_request(
			array(
				'id'   => 1,
				'name' => 'New Name',
			),
			'PUT'
		);
		$response = $this->controller->update_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'ticket_type_update_failed', $response->get_error_code() );
	}

	// =========================================================================
	// delete_item Tests
	// =========================================================================

	/**
	 * Test delete_item deletes ticket type.
	 *
	 * @return void
	 */
	public function test_delete_item_deletes_ticket_type(): void {
		$ticket_type = $this->create_ticket_type( 1, array( 'name' => 'General Admission', 'sold_count' => 0 ) );

		$this->ticket_type_repo->method( 'find' )
			->with( 1 )
			->willReturn( $ticket_type );

		$this->ticket_type_repo->method( 'delete' )
			->with( 1 )
			->willReturn( true );

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with( 'delete', 'ticket_type', 1, 'General Admission', $this->anything() );

		$request  = $this->create_request( array( 'id' => 1 ), 'DELETE' );
		$response = $this->controller->delete_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 204, $response->get_status() );
	}

	/**
	 * Test delete_item returns error for not found.
	 *
	 * @return void
	 */
	public function test_delete_item_returns_error_for_not_found(): void {
		$this->ticket_type_repo->method( 'find' )
			->with( 999 )
			->willReturn( null );

		$request  = $this->create_request( array( 'id' => 999 ), 'DELETE' );
		$response = $this->controller->delete_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'ticket_type_not_found', $response->get_error_code() );
	}

	/**
	 * Test delete_item returns error when tickets sold.
	 *
	 * @return void
	 */
	public function test_delete_item_returns_error_when_tickets_sold(): void {
		$ticket_type = $this->create_ticket_type( 1, array( 'sold_count' => 5 ) );

		$this->ticket_type_repo->method( 'find' )
			->with( 1 )
			->willReturn( $ticket_type );

		$request  = $this->create_request( array( 'id' => 1 ), 'DELETE' );
		$response = $this->controller->delete_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'ticket_type_has_sales', $response->get_error_code() );
	}

	/**
	 * Test delete_item returns error on delete failure.
	 *
	 * @return void
	 */
	public function test_delete_item_returns_error_on_delete_failure(): void {
		$ticket_type = $this->create_ticket_type( 1, array( 'sold_count' => 0 ) );

		$this->ticket_type_repo->method( 'find' )
			->with( 1 )
			->willReturn( $ticket_type );

		$this->ticket_type_repo->method( 'delete' )
			->with( 1 )
			->willReturn( false );

		$request  = $this->create_request( array( 'id' => 1 ), 'DELETE' );
		$response = $this->controller->delete_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'ticket_type_delete_failed', $response->get_error_code() );
	}

	// =========================================================================
	// prepare_item_for_response Tests
	// =========================================================================

	/**
	 * Test prepare_item_for_response returns complete data.
	 *
	 * @return void
	 */
	public function test_prepare_item_for_response_returns_complete_data(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$ticket_type = $this->create_ticket_type(
			1,
			array(
				'occurrence_id'   => 100,
				'event_id'        => null,
				'scope'           => 'occurrence',
				'name'            => 'General Admission',
				'description'     => 'Standard entry',
				'price'           => 25.00,
				'capacity_type'   => 'fixed',
				'capacity'        => 100,
				'sold_count'      => 10,
				'stock_status'    => 'in_stock',
				'sale_start'      => '2026-01-01 00:00:00',
				'sale_end'        => '2026-12-31 23:59:59',
				'min_per_order'   => 1,
				'max_per_order'   => 10,
				'sort_order'      => 0,
				'status'          => 'active',
				'wc_product_id'   => 123,
				'wc_variation_id' => 456,
			)
		);

		$request  = $this->create_request();
		$response = $this->controller->prepare_item_for_response( $ticket_type, $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertEquals( 1, $data['id'] );
		$this->assertEquals( 100, $data['occurrence_id'] );
		$this->assertEquals( 'occurrence', $data['scope'] );
		$this->assertEquals( 'General Admission', $data['name'] );
		$this->assertEquals( 'Standard entry', $data['description'] );
		$this->assertEquals( 25.00, $data['price'] );
		$this->assertEquals( 'fixed', $data['capacity_type'] );
		$this->assertEquals( 100, $data['capacity'] );
		$this->assertEquals( 10, $data['sold_count'] );
		$this->assertEquals( 'in_stock', $data['stock_status'] );
		$this->assertEquals( 1, $data['min_per_order'] );
		$this->assertEquals( 10, $data['max_per_order'] );
		$this->assertEquals( 'active', $data['status'] );
		$this->assertArrayHasKey( '_links', $data );
	}

	// =========================================================================
	// get_collection_params Tests
	// =========================================================================

	/**
	 * Test get_collection_params returns expected params.
	 *
	 * @return void
	 */
	public function test_get_collection_params_returns_expected_params(): void {
		$params = $this->controller->get_collection_params();

		$this->assertArrayHasKey( 'occurrence_id', $params );
		$this->assertArrayHasKey( 'event_id', $params );
		$this->assertArrayHasKey( 'scope', $params );
		$this->assertArrayHasKey( 'status', $params );

		$this->assertEquals( 'integer', $params['occurrence_id']['type'] );
		$this->assertEquals( 'integer', $params['event_id']['type'] );
		$this->assertEquals( 'string', $params['scope']['type'] );
		$this->assertEquals( 'string', $params['status']['type'] );
	}

	// =========================================================================
	// get_item_schema Tests
	// =========================================================================

	/**
	 * Test get_item_schema returns valid schema.
	 *
	 * @return void
	 */
	public function test_get_item_schema_returns_valid_schema(): void {
		$schema = $this->controller->get_item_schema();

		$this->assertArrayHasKey( '$schema', $schema );
		$this->assertEquals( 'ticket_type', $schema['title'] );
		$this->assertEquals( 'object', $schema['type'] );

		$props = $schema['properties'];
		$this->assertArrayHasKey( 'id', $props );
		$this->assertArrayHasKey( 'occurrence_id', $props );
		$this->assertArrayHasKey( 'event_id', $props );
		$this->assertArrayHasKey( 'scope', $props );
		$this->assertArrayHasKey( 'name', $props );
		$this->assertArrayHasKey( 'price', $props );
		$this->assertArrayHasKey( 'capacity_type', $props );
		$this->assertArrayHasKey( 'capacity', $props );
		$this->assertArrayHasKey( 'sold_count', $props );
		$this->assertArrayHasKey( 'status', $props );

		$this->assertTrue( $props['id']['readonly'] );
		$this->assertTrue( $props['name']['required'] );
	}

	// =========================================================================
	// Validation Tests
	// =========================================================================

	/**
	 * Test validation rejects invalid min_per_order.
	 *
	 * @return void
	 */
	public function test_validation_rejects_invalid_min_per_order(): void {
		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'min_per_order' => 0,
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'invalid_min_per_order', $response->get_error_code() );
	}

	/**
	 * Test validation rejects invalid max_per_order.
	 *
	 * @return void
	 */
	public function test_validation_rejects_invalid_max_per_order(): void {
		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'max_per_order' => 0,
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'invalid_max_per_order', $response->get_error_code() );
	}

	/**
	 * Test validation rejects invalid status.
	 *
	 * @return void
	 */
	public function test_validation_rejects_invalid_status(): void {
		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'status'        => 'invalid_status',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'invalid_status', $response->get_error_code() );
	}
}
