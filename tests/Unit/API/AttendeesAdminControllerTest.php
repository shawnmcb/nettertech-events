<?php
/**
 * AttendeesAdminController unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\API
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\API;

use Brain\Monkey\Functions;
use NetterTechEvents\API\AttendeesAdminController;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Services\ActivityLogService;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Models\Attendee;
use NetterTechEvents\Services\RateLimitService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Test AttendeesAdminController functionality.
 */
class AttendeesAdminControllerTest extends \NetterTechEventsTestCase {

	/**
	 * AttendeesAdminController instance.
	 *
	 * @var AttendeesAdminController
	 */
	private AttendeesAdminController $controller;

	/**
	 * Mock AttendeeRepository.
	 *
	 * @var AttendeeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $attendee_repo;

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
		Functions\when( 'sanitize_email' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'is_email' )->alias( fn( $email ) => filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false );
		Functions\when( 'get_option' )->justReturn( array() );

		// Use concrete classes with mocked methods.
		$this->attendee_repo = $this->getMockBuilder( AttendeeRepository::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'find', 'save', 'delete', 'for_occurrence', 'search' ) )
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

		$this->controller = new AttendeesAdminController(
			$this->attendee_repo,
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
	 * Create a mock Attendee.
	 *
	 * @param int                  $id    Attendee ID.
	 * @param array<string, mixed> $props Attendee properties.
	 * @return Attendee
	 */
	private function create_attendee( int $id, array $props = array() ): Attendee {
		$attendee                      = new Attendee();
		$attendee->id                  = $id;
		$attendee->occurrence_id       = $props['occurrence_id'] ?? 100;
		$attendee->ticket_type_id      = $props['ticket_type_id'] ?? 1;
		$attendee->wc_order_id         = $props['wc_order_id'] ?? null;
		$attendee->name                = $props['name'] ?? 'John Doe';
		$attendee->email               = $props['email'] ?? 'john@example.com';
		$attendee->phone               = $props['phone'] ?? null;
		$attendee->quantity            = $props['quantity'] ?? 1;
		$attendee->status              = $props['status'] ?? 'confirmed';
		$attendee->checked_in          = $props['checked_in'] ?? false;
		$attendee->checked_in_count    = $props['checked_in_count'] ?? 0;
		$attendee->checked_in_at       = $props['checked_in_at'] ?? null;
		$attendee->notes               = $props['notes'] ?? null;
		$attendee->accessibility_notes = $props['accessibility_notes'] ?? null;
		$attendee->created_at          = $props['created_at'] ?? '2026-01-01 10:00:00';
		$attendee->updated_at          = $props['updated_at'] ?? '2026-01-01 10:00:00';
		return $attendee;
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
		$controller = new AttendeesAdminController(
			$this->attendee_repo,
			$this->activity_log,
			$this->rate_limit
		);

		$this->assertInstanceOf( AttendeesAdminController::class, $controller );
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
		$this->assertEquals( '/admin/attendees', $routes_registered[0]['route'] );
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

		$this->assertEquals( '/admin/attendees/(?P<id>\d+)', $routes_registered[1]['route'] );
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

		$controller = new AttendeesAdminController(
			$this->attendee_repo,
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
	 * Test get_items returns attendees for occurrence.
	 *
	 * @return void
	 */
	public function test_get_items_returns_attendees_for_occurrence(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$attendees = array(
			$this->create_attendee( 1, array( 'name' => 'John Doe' ) ),
			$this->create_attendee( 2, array( 'name' => 'Jane Smith' ) ),
		);

		$this->attendee_repo->method( 'for_occurrence' )
			->with( 100, array() )
			->willReturn( $attendees );

		$request  = $this->create_request( array( 'occurrence_id' => 100 ) );
		$response = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertCount( 2, $data );
		$this->assertEquals( 'John Doe', $data[0]['name'] );
		$this->assertEquals( 'Jane Smith', $data[1]['name'] );
	}

	/**
	 * Test get_items returns error without occurrence_id.
	 *
	 * @return void
	 */
	public function test_get_items_returns_error_without_occurrence_id(): void {
		$request  = $this->create_request( array() );
		$response = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'missing_occurrence_id', $response->get_error_code() );
	}

	/**
	 * Test get_items filters by status.
	 *
	 * @return void
	 */
	public function test_get_items_filters_by_status(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$attendees = array(
			$this->create_attendee( 1, array( 'status' => 'cancelled' ) ),
		);

		$this->attendee_repo->method( 'for_occurrence' )
			->with( 100, array( 'status' => 'cancelled' ) )
			->willReturn( $attendees );

		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'status'        => 'cancelled',
			)
		);
		$response = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertEquals( 'cancelled', $data[0]['status'] );
	}

	/**
	 * Test get_items searches attendees.
	 *
	 * @return void
	 */
	public function test_get_items_searches_attendees(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$attendees = array(
			$this->create_attendee( 1, array( 'name' => 'John Doe' ) ),
		);

		$this->attendee_repo->method( 'search' )
			->with( 100, 'john' )
			->willReturn( $attendees );

		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'search'        => 'john',
			)
		);
		$response = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertEquals( 'John Doe', $data[0]['name'] );
	}

	// =========================================================================
	// get_item Tests
	// =========================================================================

	/**
	 * Test get_item returns attendee.
	 *
	 * @return void
	 */
	public function test_get_item_returns_attendee(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$attendee = $this->create_attendee( 1, array( 'name' => 'John Doe' ) );

		$this->attendee_repo->method( 'find' )
			->with( 1 )
			->willReturn( $attendee );

		$request  = $this->create_request( array( 'id' => 1 ) );
		$response = $this->controller->get_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertEquals( 1, $data['id'] );
		$this->assertEquals( 'John Doe', $data['name'] );
	}

	/**
	 * Test get_item returns error for not found.
	 *
	 * @return void
	 */
	public function test_get_item_returns_error_for_not_found(): void {
		$this->attendee_repo->method( 'find' )
			->with( 999 )
			->willReturn( null );

		$request  = $this->create_request( array( 'id' => 999 ) );
		$response = $this->controller->get_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'attendee_not_found', $response->get_error_code() );
	}

	// =========================================================================
	// create_item Tests
	// =========================================================================

	/**
	 * Test create_item creates attendee.
	 *
	 * @return void
	 */
	public function test_create_item_creates_attendee(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$saved_attendee = $this->create_attendee(
			1,
			array(
				'occurrence_id' => 100,
				'name'          => 'New Attendee',
				'email'         => 'new@example.com',
			)
		);

		$this->attendee_repo->method( 'save' )
			->willReturn( $saved_attendee );

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with( 'create', 'attendee', 1, 'New Attendee', $this->anything() );

		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'New Attendee',
				'email'         => 'new@example.com',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'New Attendee', $data['name'] );
	}

	/**
	 * Test create_item returns error without occurrence_id.
	 *
	 * @return void
	 */
	public function test_create_item_returns_error_without_occurrence_id(): void {
		$request  = $this->create_request(
			array(
				'name'  => 'Test',
				'email' => 'test@example.com',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'missing_occurrence_id', $response->get_error_code() );
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
				'email'         => 'test@example.com',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'missing_name', $response->get_error_code() );
	}

	/**
	 * Test create_item returns error without email.
	 *
	 * @return void
	 */
	public function test_create_item_returns_error_without_email(): void {
		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'invalid_email', $response->get_error_code() );
	}

	/**
	 * Test create_item returns error with invalid email.
	 *
	 * @return void
	 */
	public function test_create_item_returns_error_with_invalid_email(): void {
		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'email'         => 'not-an-email',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'invalid_email', $response->get_error_code() );
	}

	/**
	 * Test create_item with optional fields.
	 *
	 * @return void
	 */
	public function test_create_item_with_optional_fields(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$saved_attendee = $this->create_attendee(
			1,
			array(
				'occurrence_id'       => 100,
				'name'                => 'Test',
				'email'               => 'test@example.com',
				'phone'               => '555-1234',
				'quantity'            => 3,
				'status'              => 'pending',
				'ticket_type_id'      => 5,
				'notes'               => 'Some notes',
				'accessibility_notes' => 'Wheelchair access',
			)
		);

		$this->attendee_repo->method( 'save' )
			->willReturn( $saved_attendee );

		$request  = $this->create_request(
			array(
				'occurrence_id'       => 100,
				'name'                => 'Test',
				'email'               => 'test@example.com',
				'phone'               => '555-1234',
				'quantity'            => 3,
				'status'              => 'pending',
				'ticket_type_id'      => 5,
				'notes'               => 'Some notes',
				'accessibility_notes' => 'Wheelchair access',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertEquals( '555-1234', $data['phone'] );
		$this->assertEquals( 3, $data['quantity'] );
	}

	/**
	 * Test create_item returns error on save failure.
	 *
	 * @return void
	 */
	public function test_create_item_returns_error_on_save_failure(): void {
		$this->attendee_repo->method( 'save' )
			->willThrowException( new \RuntimeException( 'Database error' ) );

		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'email'         => 'test@example.com',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'attendee_create_failed', $response->get_error_code() );
	}

	/**
	 * Test create_item returns error with invalid status.
	 *
	 * @return void
	 */
	public function test_create_item_returns_error_with_invalid_status(): void {
		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'email'         => 'test@example.com',
				'status'        => 'invalid_status',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'invalid_status', $response->get_error_code() );
	}

	/**
	 * Test create_item returns error with invalid quantity.
	 *
	 * @return void
	 */
	public function test_create_item_returns_error_with_invalid_quantity(): void {
		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'email'         => 'test@example.com',
				'quantity'      => 0,
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'invalid_quantity', $response->get_error_code() );
	}

	// =========================================================================
	// update_item Tests
	// =========================================================================

	/**
	 * Test update_item updates attendee.
	 *
	 * @return void
	 */
	public function test_update_item_updates_attendee(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$existing = $this->create_attendee( 1, array( 'name' => 'Old Name' ) );
		$updated  = $this->create_attendee( 1, array( 'name' => 'New Name' ) );

		$this->attendee_repo->method( 'find' )
			->with( 1 )
			->willReturn( $existing );

		$this->attendee_repo->method( 'save' )
			->willReturn( $updated );

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with( 'update', 'attendee', 1, 'New Name', $this->anything() );

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
		$this->attendee_repo->method( 'find' )
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
		$this->assertEquals( 'attendee_not_found', $response->get_error_code() );
	}

	/**
	 * Test update_item with no changes returns current state.
	 *
	 * @return void
	 */
	public function test_update_item_with_no_changes_returns_current_state(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$existing = $this->create_attendee( 1, array( 'name' => 'Same Name' ) );

		$this->attendee_repo->method( 'find' )
			->with( 1 )
			->willReturn( $existing );

		// Save should NOT be called when no changes.
		$this->attendee_repo->expects( $this->never() )
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
	 * Test update_item updates multiple fields.
	 *
	 * @return void
	 */
	public function test_update_item_updates_multiple_fields(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$existing = $this->create_attendee(
			1,
			array(
				'name'     => 'Old Name',
				'email'    => 'old@example.com',
				'phone'    => '111-1111',
				'quantity' => 1,
				'status'   => 'confirmed',
			)
		);

		$updated = $this->create_attendee(
			1,
			array(
				'name'     => 'New Name',
				'email'    => 'new@example.com',
				'phone'    => '222-2222',
				'quantity' => 2,
				'status'   => 'pending',
			)
		);

		$this->attendee_repo->method( 'find' )
			->with( 1 )
			->willReturn( $existing );

		$this->attendee_repo->method( 'save' )
			->willReturn( $updated );

		$request  = $this->create_request(
			array(
				'id'       => 1,
				'name'     => 'New Name',
				'email'    => 'new@example.com',
				'phone'    => '222-2222',
				'quantity' => 2,
				'status'   => 'pending',
			),
			'PUT'
		);
		$response = $this->controller->update_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertEquals( 'New Name', $data['name'] );
		$this->assertEquals( 'new@example.com', $data['email'] );
	}

	/**
	 * Test update_item returns error on save failure.
	 *
	 * @return void
	 */
	public function test_update_item_returns_error_on_save_failure(): void {
		$existing = $this->create_attendee( 1, array( 'name' => 'Old Name' ) );

		$this->attendee_repo->method( 'find' )
			->with( 1 )
			->willReturn( $existing );

		$this->attendee_repo->method( 'save' )
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
		$this->assertEquals( 'attendee_update_failed', $response->get_error_code() );
	}

	/**
	 * Test update_item with invalid status.
	 *
	 * @return void
	 */
	public function test_update_item_returns_error_with_invalid_status(): void {
		$existing = $this->create_attendee( 1 );

		$this->attendee_repo->method( 'find' )
			->with( 1 )
			->willReturn( $existing );

		$request  = $this->create_request(
			array(
				'id'     => 1,
				'status' => 'invalid_status',
			),
			'PUT'
		);
		$response = $this->controller->update_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'invalid_status', $response->get_error_code() );
	}

	// =========================================================================
	// delete_item Tests
	// =========================================================================

	/**
	 * Test delete_item deletes attendee.
	 *
	 * @return void
	 */
	public function test_delete_item_deletes_attendee(): void {
		$attendee = $this->create_attendee( 1, array( 'name' => 'John Doe' ) );

		$this->attendee_repo->method( 'find' )
			->with( 1 )
			->willReturn( $attendee );

		$this->attendee_repo->method( 'delete' )
			->with( 1 )
			->willReturn( true );

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with( 'delete', 'attendee', 1, 'John Doe', $this->anything() );

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
		$this->attendee_repo->method( 'find' )
			->with( 999 )
			->willReturn( null );

		$request  = $this->create_request( array( 'id' => 999 ), 'DELETE' );
		$response = $this->controller->delete_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'attendee_not_found', $response->get_error_code() );
	}

	/**
	 * Test delete_item returns error on delete failure.
	 *
	 * @return void
	 */
	public function test_delete_item_returns_error_on_delete_failure(): void {
		$attendee = $this->create_attendee( 1 );

		$this->attendee_repo->method( 'find' )
			->with( 1 )
			->willReturn( $attendee );

		$this->attendee_repo->method( 'delete' )
			->with( 1 )
			->willReturn( false );

		$request  = $this->create_request( array( 'id' => 1 ), 'DELETE' );
		$response = $this->controller->delete_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'attendee_delete_failed', $response->get_error_code() );
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

		$attendee = $this->create_attendee(
			1,
			array(
				'occurrence_id'       => 100,
				'ticket_type_id'      => 5,
				'wc_order_id'         => 123,
				'name'                => 'John Doe',
				'email'               => 'john@example.com',
				'phone'               => '555-1234',
				'quantity'            => 2,
				'status'              => 'confirmed',
				'checked_in'          => true,
				'checked_in_count'    => 2,
				'checked_in_at'       => '2026-01-10 14:30:00',
				'notes'               => 'VIP guest',
				'accessibility_notes' => 'Wheelchair access',
				'created_at'          => '2026-01-01 10:00:00',
				'updated_at'          => '2026-01-10 14:30:00',
			)
		);

		$request  = $this->create_request();
		$response = $this->controller->prepare_item_for_response( $attendee, $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertEquals( 1, $data['id'] );
		$this->assertEquals( 100, $data['occurrence_id'] );
		$this->assertEquals( 5, $data['ticket_type_id'] );
		$this->assertEquals( 123, $data['wc_order_id'] );
		$this->assertEquals( 'John Doe', $data['name'] );
		$this->assertEquals( 'john@example.com', $data['email'] );
		$this->assertEquals( '555-1234', $data['phone'] );
		$this->assertEquals( 2, $data['quantity'] );
		$this->assertEquals( 'confirmed', $data['status'] );
		$this->assertTrue( $data['checked_in'] );
		$this->assertEquals( 2, $data['checked_in_count'] );
		$this->assertEquals( '2026-01-10 14:30:00', $data['checked_in_at'] );
		$this->assertEquals( 'VIP guest', $data['notes'] );
		$this->assertEquals( 'Wheelchair access', $data['accessibility_notes'] );
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
		$this->assertArrayHasKey( 'status', $params );
		$this->assertArrayHasKey( 'search', $params );

		$this->assertTrue( $params['occurrence_id']['required'] );
		$this->assertEquals( 'integer', $params['occurrence_id']['type'] );
		$this->assertEquals( 'string', $params['status']['type'] );
		$this->assertEquals( 'string', $params['search']['type'] );
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
		$this->assertEquals( 'attendee', $schema['title'] );
		$this->assertEquals( 'object', $schema['type'] );

		$props = $schema['properties'];
		$this->assertArrayHasKey( 'id', $props );
		$this->assertArrayHasKey( 'occurrence_id', $props );
		$this->assertArrayHasKey( 'name', $props );
		$this->assertArrayHasKey( 'email', $props );
		$this->assertArrayHasKey( 'quantity', $props );
		$this->assertArrayHasKey( 'status', $props );
		$this->assertArrayHasKey( 'checked_in', $props );
		$this->assertArrayHasKey( 'checked_in_count', $props );
		$this->assertArrayHasKey( 'accessibility_notes', $props );

		$this->assertTrue( $props['id']['readonly'] );
		$this->assertTrue( $props['occurrence_id']['required'] );
		$this->assertEquals( 'email', $props['email']['format'] );
	}

	// =========================================================================
	// Mutation-Killing Tests — create_item field assignments
	// =========================================================================

	/**
	 * Test create_item assigns phone when provided.
	 *
	 * Kills IfNegation on has_param('phone') check.
	 *
	 * @return void
	 */
	public function test_create_item_assigns_phone_field(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$this->attendee_repo->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback(
					function ( Attendee $a ) {
						return '555-9876' === $a->phone;
					}
				)
			)
			->willReturnCallback(
				function ( Attendee $a ) {
					$a->id = 1;
					return $a;
				}
			);

		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'email'         => 'test@example.com',
				'phone'         => '555-9876',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Test create_item does not assign phone when absent.
	 *
	 * @return void
	 */
	public function test_create_item_does_not_set_phone_when_absent(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$this->attendee_repo->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback(
					function ( Attendee $a ) {
						return null === $a->phone;
					}
				)
			)
			->willReturnCallback(
				function ( Attendee $a ) {
					$a->id = 1;
					return $a;
				}
			);

		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'email'         => 'test@example.com',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Test create_item assigns ticket_type_id when provided.
	 *
	 * Kills IfNegation on has_param('ticket_type_id') check.
	 *
	 * @return void
	 */
	public function test_create_item_assigns_ticket_type_id(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$this->attendee_repo->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback(
					function ( Attendee $a ) {
						return 42 === $a->ticket_type_id;
					}
				)
			)
			->willReturnCallback(
				function ( Attendee $a ) {
					$a->id = 1;
					return $a;
				}
			);

		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'email'         => 'test@example.com',
				'ticket_type_id' => 42,
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Test create_item assigns notes when provided.
	 *
	 * Kills IfNegation on has_param('notes') check.
	 *
	 * @return void
	 */
	public function test_create_item_assigns_notes(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$this->attendee_repo->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback(
					function ( Attendee $a ) {
						return 'Test notes' === $a->notes;
					}
				)
			)
			->willReturnCallback(
				function ( Attendee $a ) {
					$a->id = 1;
					return $a;
				}
			);

		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'email'         => 'test@example.com',
				'notes'         => 'Test notes',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Test create_item assigns accessibility_notes when provided.
	 *
	 * Kills IfNegation on has_param('accessibility_notes') check.
	 *
	 * @return void
	 */
	public function test_create_item_assigns_accessibility_notes(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$this->attendee_repo->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback(
					function ( Attendee $a ) {
						return 'Wheelchair' === $a->accessibility_notes;
					}
				)
			)
			->willReturnCallback(
				function ( Attendee $a ) {
					$a->id = 1;
					return $a;
				}
			);

		$request  = $this->create_request(
			array(
				'occurrence_id'       => 100,
				'name'                => 'Test',
				'email'               => 'test@example.com',
				'accessibility_notes' => 'Wheelchair',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Test create_item assigns valid status from STATUSES constant.
	 *
	 * Kills IfNegation on in_array status validation.
	 *
	 * @return void
	 */
	public function test_create_item_assigns_valid_status(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$this->attendee_repo->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback(
					function ( Attendee $a ) {
						return 'confirmed' === $a->status;
					}
				)
			)
			->willReturnCallback(
				function ( Attendee $a ) {
					$a->id = 1;
					return $a;
				}
			);

		$request  = $this->create_request(
			array(
				'occurrence_id' => 100,
				'name'          => 'Test',
				'email'         => 'test@example.com',
				'status'        => 'confirmed',
			),
			'POST'
		);
		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Test create_item correctly casts occurrence_id to int.
	 *
	 * Kills CastInt mutant on occurrence_id assignment.
	 *
	 * @return void
	 */
	public function test_create_item_casts_occurrence_id_to_int(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$this->attendee_repo->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback(
					function ( Attendee $a ) {
						return 100 === $a->occurrence_id;
					}
				)
			)
			->willReturnCallback(
				function ( Attendee $a ) {
					$a->id = 1;
					return $a;
				}
			);

		$request  = $this->create_request(
			array(
				'occurrence_id' => '100',
				'name'          => 'Test',
				'email'         => 'test@example.com',
			),
			'POST'
		);
		$this->controller->create_item( $request );
	}

	// =========================================================================
	// Mutation-Killing Tests — update_item change tracking
	// =========================================================================

	/**
	 * Test update_item tracks email change in activity log.
	 *
	 * Kills IfNegation/NotIdentical on email comparison.
	 *
	 * @return void
	 */
	public function test_update_item_tracks_email_change(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$existing = $this->create_attendee( 1, array( 'email' => 'old@example.com' ) );

		$this->attendee_repo->method( 'find' )->with( 1 )->willReturn( $existing );
		$this->attendee_repo->method( 'save' )->willReturnCallback(
			function ( Attendee $a ) {
				return $a;
			}
		);

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with(
				'update',
				'attendee',
				1,
				$this->anything(),
				$this->callback(
					function ( $context ) {
						return isset( $context['changes']['email'] )
							&& 'old@example.com' === $context['changes']['email']['old']
							&& 'new@example.com' === $context['changes']['email']['new'];
					}
				)
			);

		$request = $this->create_request(
			array(
				'id'    => 1,
				'email' => 'new@example.com',
			),
			'PUT'
		);
		$this->controller->update_item( $request );
	}

	/**
	 * Test update_item tracks phone change.
	 *
	 * Kills IfNegation/NotIdentical on phone comparison.
	 *
	 * @return void
	 */
	public function test_update_item_tracks_phone_change(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$existing = $this->create_attendee( 1, array( 'phone' => '555-0000' ) );

		$this->attendee_repo->method( 'find' )->with( 1 )->willReturn( $existing );
		$this->attendee_repo->method( 'save' )->willReturnCallback(
			function ( Attendee $a ) {
				return $a;
			}
		);

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with(
				'update',
				'attendee',
				1,
				$this->anything(),
				$this->callback(
					function ( $context ) {
						return isset( $context['changes']['phone'] )
							&& '555-0000' === $context['changes']['phone']['old']
							&& '555-1234' === $context['changes']['phone']['new'];
					}
				)
			);

		$request = $this->create_request(
			array(
				'id'    => 1,
				'phone' => '555-1234',
			),
			'PUT'
		);
		$this->controller->update_item( $request );
	}

	/**
	 * Test update_item tracks quantity change.
	 *
	 * Kills IfNegation/NotIdentical on quantity comparison.
	 *
	 * @return void
	 */
	public function test_update_item_tracks_quantity_change(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$existing = $this->create_attendee( 1, array( 'quantity' => 1 ) );

		$this->attendee_repo->method( 'find' )->with( 1 )->willReturn( $existing );
		$this->attendee_repo->method( 'save' )->willReturnCallback(
			function ( Attendee $a ) {
				return $a;
			}
		);

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with(
				'update',
				'attendee',
				1,
				$this->anything(),
				$this->callback(
					function ( $context ) {
						return isset( $context['changes']['quantity'] )
							&& 1 === $context['changes']['quantity']['old']
							&& 5 === $context['changes']['quantity']['new'];
					}
				)
			);

		$request = $this->create_request(
			array(
				'id'       => 1,
				'quantity' => 5,
			),
			'PUT'
		);
		$this->controller->update_item( $request );
	}

	/**
	 * Test update_item tracks notes change.
	 *
	 * @return void
	 */
	public function test_update_item_tracks_notes_change(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$existing = $this->create_attendee( 1, array( 'notes' => 'Old notes' ) );

		$this->attendee_repo->method( 'find' )->with( 1 )->willReturn( $existing );
		$this->attendee_repo->method( 'save' )->willReturnCallback(
			function ( Attendee $a ) {
				return $a;
			}
		);

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with(
				'update',
				'attendee',
				1,
				$this->anything(),
				$this->callback(
					function ( $context ) {
						return isset( $context['changes']['notes'] )
							&& 'Old notes' === $context['changes']['notes']['old']
							&& 'New notes' === $context['changes']['notes']['new'];
					}
				)
			);

		$request = $this->create_request(
			array(
				'id'    => 1,
				'notes' => 'New notes',
			),
			'PUT'
		);
		$this->controller->update_item( $request );
	}

	/**
	 * Test update_item tracks accessibility_notes change.
	 *
	 * @return void
	 */
	public function test_update_item_tracks_accessibility_notes_change(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$existing = $this->create_attendee( 1, array( 'accessibility_notes' => null ) );

		$this->attendee_repo->method( 'find' )->with( 1 )->willReturn( $existing );
		$this->attendee_repo->method( 'save' )->willReturnCallback(
			function ( Attendee $a ) {
				return $a;
			}
		);

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with(
				'update',
				'attendee',
				1,
				$this->anything(),
				$this->callback(
					function ( $context ) {
						return isset( $context['changes']['accessibility_notes'] )
							&& 'Wheelchair ramp' === $context['changes']['accessibility_notes']['new'];
					}
				)
			);

		$request = $this->create_request(
			array(
				'id'                  => 1,
				'accessibility_notes' => 'Wheelchair ramp',
			),
			'PUT'
		);
		$this->controller->update_item( $request );
	}

	/**
	 * Test update_item tracks status change with valid status.
	 *
	 * Kills LogicalAnd/NotIdentical on status validation + comparison.
	 *
	 * @return void
	 */
	public function test_update_item_tracks_status_change(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$existing = $this->create_attendee( 1, array( 'status' => 'confirmed' ) );

		$this->attendee_repo->method( 'find' )->with( 1 )->willReturn( $existing );
		$this->attendee_repo->method( 'save' )->willReturnCallback(
			function ( Attendee $a ) {
				return $a;
			}
		);

		$this->activity_log->expects( $this->once() )
			->method( 'log' )
			->with(
				'update',
				'attendee',
				1,
				$this->anything(),
				$this->callback(
					function ( $context ) {
						return isset( $context['changes']['status'] )
							&& 'confirmed' === $context['changes']['status']['old']
							&& 'cancelled' === $context['changes']['status']['new'];
					}
				)
			);

		$request = $this->create_request(
			array(
				'id'     => 1,
				'status' => 'cancelled',
			),
			'PUT'
		);
		$this->controller->update_item( $request );
	}

	/**
	 * Test update_item does NOT track status change for invalid status.
	 *
	 * @return void
	 */
	public function test_update_item_rejects_invalid_status(): void {
		$existing = $this->create_attendee( 1, array( 'status' => 'confirmed' ) );

		$this->attendee_repo->method( 'find' )->with( 1 )->willReturn( $existing );
		$this->attendee_repo->expects( $this->never() )->method( 'save' );

		$request = $this->create_request(
			array(
				'id'     => 1,
				'status' => 'bogus_status',
			),
			'PUT'
		);
		$response = $this->controller->update_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_status', $response->get_error_code() );
	}

	/**
	 * Test update_item does not change status when same status provided.
	 *
	 * Kills NotIdentical mutant on status !== attendee->status.
	 *
	 * @return void
	 */
	public function test_update_item_no_change_when_same_status(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'rest_ensure_response' )->alias( fn( $data ) => new WP_REST_Response( $data ) );

		$existing = $this->create_attendee( 1, array( 'status' => 'confirmed' ) );

		$this->attendee_repo->method( 'find' )->with( 1 )->willReturn( $existing );
		// Same status = no changes = no save.
		$this->attendee_repo->expects( $this->never() )->method( 'save' );

		$request = $this->create_request(
			array(
				'id'     => 1,
				'status' => 'confirmed',
			),
			'PUT'
		);
		$response = $this->controller->update_item( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}
}
