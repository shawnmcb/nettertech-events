<?php
/**
 * ICalController unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\API
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\API;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\API\ICalController;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Services\ICalService;
use NetterTechEvents\Services\RateLimitService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Test ICalController functionality.
 *
 * Note: Full REST API tests require integration tests with WP_REST_Server.
 * These unit tests focus on validation methods, permission checks, and structure.
 */
class ICalControllerTest extends \NetterTechEventsTestCase {

	/**
	 * Event repository mock.
	 *
	 * @var EventRepositoryInterface|Mockery\MockInterface
	 */
	private $event_repo;

	/**
	 * Occurrence repository mock.
	 *
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * Registered routes storage.
	 *
	 * @var array<string, array>
	 */
	private array $registered_routes = array();

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registered_routes = array();

		// Create mocks for repositories.
		$this->event_repo      = Mockery::mock( EventRepositoryInterface::class );
		$this->occurrence_repo = Mockery::mock( OccurrenceRepositoryInterface::class );

		// Mock WordPress functions.
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( intval( $value ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_file_name' )->alias(
			function ( $name ) {
				return preg_replace( '/[^a-zA-Z0-9._-]/', '-', $name );
			}
		);

		// Mock register_rest_route to capture registrations.
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) {
				$this->registered_routes[ $namespace . $route ] = array(
					'namespace' => $namespace,
					'route'     => $route,
					'args'      => $args,
				);
			}
		);
	}

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->registered_routes = array();
		parent::tearDown();
	}

	/**
	 * Create an ICalController with mock dependencies.
	 *
	 * @return ICalController
	 */
	private function create_controller(): ICalController {
		$rate_limit = Mockery::mock( RateLimitService::class );
		$rate_limit->shouldReceive( 'should_bypass' )->andReturn( true )->byDefault();

		return new ICalController(
			$this->event_repo,
			$this->occurrence_repo,
			Mockery::mock( ICalService::class ),
			$rate_limit
		);
	}

	/**
	 * Create a mock event for testing.
	 *
	 * @param int    $id     Event ID.
	 * @param string $status Event status.
	 * @return Event
	 */
	private function create_mock_event( int $id, EventStatus $status = EventStatus::PUBLISHED ): Event {
		$event         = new Event();
		$event->id     = $id;
		$event->title  = 'Test Event ' . $id;
		$event->slug   = 'test-event-' . $id;
		$event->status = $status;

		return $event;
	}

	/**
	 * Create a mock occurrence for testing.
	 *
	 * @param int $id       Occurrence ID.
	 * @param int $event_id Event ID.
	 * @return Occurrence
	 */
	private function create_mock_occurrence( int $id, int $event_id ): Occurrence {
		$occurrence                 = new Occurrence();
		$occurrence->id             = $id;
		$occurrence->event_id       = $event_id;
		$occurrence->start_datetime = '2026-02-15 19:00:00';
		$occurrence->end_datetime   = '2026-02-15 21:00:00';
		$occurrence->status         = 'scheduled';

		return $occurrence;
	}

	/**
	 * Create a mock WP_REST_Request.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 * @return WP_REST_Request
	 */
	private function create_mock_request( array $params = array() ): WP_REST_Request {
		$request = Mockery::mock( WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
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
		$this->assertInstanceOf( ICalController::class, $controller );
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

		$this->assertSame( 'ical', $prop->getValue( $controller ) );
	}

	// =========================================================================
	// Route Registration Tests
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
	 * Test register_routes registers all expected routes.
	 *
	 * @return void
	 */
	public function test_register_routes_registers_all_routes(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		// Check feed route.
		$this->assertArrayHasKey( 'nettertech-events/v1/ical/feed', $this->registered_routes );

		// Check single event route.
		$this->assertArrayHasKey( 'nettertech-events/v1/ical/event/(?P<id>\d+)', $this->registered_routes );

		// Check single occurrence route.
		$this->assertArrayHasKey( 'nettertech-events/v1/ical/occurrence/(?P<id>\d+)', $this->registered_routes );

		// Check import route.
		$this->assertArrayHasKey( 'nettertech-events/v1/ical/import', $this->registered_routes );
	}

	/**
	 * Test register_routes registers exactly 4 routes.
	 *
	 * @return void
	 */
	public function test_register_routes_registers_4_routes(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$this->assertCount( 4, $this->registered_routes );
	}

	/**
	 * Test feed route is GET method.
	 *
	 * @return void
	 */
	public function test_feed_route_is_get_method(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route = $this->registered_routes['nettertech-events/v1/ical/feed'];
		$this->assertSame( 'GET', $route['args'][0]['methods'] );
	}

	/**
	 * Test event route is GET method.
	 *
	 * @return void
	 */
	public function test_event_route_is_get_method(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route = $this->registered_routes['nettertech-events/v1/ical/event/(?P<id>\d+)'];
		$this->assertSame( 'GET', $route['args'][0]['methods'] );
	}

	/**
	 * Test occurrence route is GET method.
	 *
	 * @return void
	 */
	public function test_occurrence_route_is_get_method(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route = $this->registered_routes['nettertech-events/v1/ical/occurrence/(?P<id>\d+)'];
		$this->assertSame( 'GET', $route['args'][0]['methods'] );
	}

	/**
	 * Test import route is POST method.
	 *
	 * @return void
	 */
	public function test_import_route_is_post_method(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route = $this->registered_routes['nettertech-events/v1/ical/import'];
		$this->assertSame( 'POST', $route['args'][0]['methods'] );
	}

	/**
	 * Test feed route has limit argument.
	 *
	 * @return void
	 */
	public function test_feed_route_has_limit_argument(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route = $this->registered_routes['nettertech-events/v1/ical/feed'];
		$args  = $route['args'][0]['args'];

		$this->assertArrayHasKey( 'limit', $args );
		$this->assertSame( 'integer', $args['limit']['type'] );
		$this->assertSame( 100, $args['limit']['default'] );
	}

	/**
	 * Test feed route limit has min and max.
	 *
	 * @return void
	 */
	public function test_feed_route_limit_has_bounds(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route = $this->registered_routes['nettertech-events/v1/ical/feed'];
		$args  = $route['args'][0]['args'];

		$this->assertSame( 1, $args['limit']['minimum'] );
		$this->assertSame( 500, $args['limit']['maximum'] );
	}

	/**
	 * Test feed route has start_from argument.
	 *
	 * @return void
	 */
	public function test_feed_route_has_start_from_argument(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route = $this->registered_routes['nettertech-events/v1/ical/feed'];
		$args  = $route['args'][0]['args'];

		$this->assertArrayHasKey( 'start_from', $args );
		$this->assertSame( 'string', $args['start_from']['type'] );
	}

	/**
	 * Test feed route has category argument.
	 *
	 * @return void
	 */
	public function test_feed_route_has_category_argument(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route = $this->registered_routes['nettertech-events/v1/ical/feed'];
		$args  = $route['args'][0]['args'];

		$this->assertArrayHasKey( 'category', $args );
	}

	/**
	 * Test import route has content argument as required.
	 *
	 * @return void
	 */
	public function test_import_route_has_required_content_argument(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route = $this->registered_routes['nettertech-events/v1/ical/import'];
		$args  = $route['args'][0]['args'];

		$this->assertArrayHasKey( 'content', $args );
		$this->assertTrue( $args['content']['required'] );
		$this->assertSame( 'string', $args['content']['type'] );
	}

	/**
	 * Test import route has status argument with enum.
	 *
	 * @return void
	 */
	public function test_import_route_has_status_argument_with_enum(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route = $this->registered_routes['nettertech-events/v1/ical/import'];
		$args  = $route['args'][0]['args'];

		$this->assertArrayHasKey( 'status', $args );
		$this->assertSame( 'draft', $args['status']['default'] );
		$this->assertSame( array( 'draft', 'published' ), $args['status']['enum'] );
	}

	/**
	 * Test import route has skip_duplicates argument.
	 *
	 * @return void
	 */
	public function test_import_route_has_skip_duplicates_argument(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route = $this->registered_routes['nettertech-events/v1/ical/import'];
		$args  = $route['args'][0]['args'];

		$this->assertArrayHasKey( 'skip_duplicates', $args );
		$this->assertSame( 'boolean', $args['skip_duplicates']['type'] );
		$this->assertTrue( $args['skip_duplicates']['default'] );
	}

	/**
	 * Test event route has id argument as required.
	 *
	 * @return void
	 */
	public function test_event_route_has_required_id_argument(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route = $this->registered_routes['nettertech-events/v1/ical/event/(?P<id>\d+)'];
		$args  = $route['args'][0]['args'];

		$this->assertArrayHasKey( 'id', $args );
		$this->assertTrue( $args['id']['required'] );
		$this->assertSame( 'integer', $args['id']['type'] );
	}

	/**
	 * Test occurrence route has id argument as required.
	 *
	 * @return void
	 */
	public function test_occurrence_route_has_required_id_argument(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route = $this->registered_routes['nettertech-events/v1/ical/occurrence/(?P<id>\d+)'];
		$args  = $route['args'][0]['args'];

		$this->assertArrayHasKey( 'id', $args );
		$this->assertTrue( $args['id']['required'] );
		$this->assertSame( 'integer', $args['id']['type'] );
	}

	// =========================================================================
	// Permission Check Tests
	// =========================================================================

	/**
	 * Test public_feed_permission_check returns true.
	 *
	 * @return void
	 */
	public function test_public_feed_permission_check_returns_true(): void {
		$controller = $this->create_controller();

		$this->assertTrue( $controller->public_feed_permission_check() );
	}

	/**
	 * Test public_feed_permission_check always returns true.
	 *
	 * Public feeds are intentionally public for calendar subscriptions.
	 *
	 * @return void
	 */
	public function test_public_feed_permission_check_is_always_true(): void {
		$controller = $this->create_controller();

		// Call multiple times to ensure it's consistently true.
		$this->assertTrue( $controller->public_feed_permission_check() );
		$this->assertTrue( $controller->public_feed_permission_check() );
	}

	/**
	 * Test import_permission_check returns false without capability.
	 *
	 * @return void
	 */
	public function test_import_permission_check_returns_false_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$controller = $this->create_controller();

		$this->assertFalse( $controller->import_permission_check() );
	}

	/**
	 * Test import_permission_check returns true with manage_options.
	 *
	 * @return void
	 */
	public function test_import_permission_check_returns_true_with_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$controller = $this->create_controller();

		$this->assertTrue( $controller->import_permission_check() );
	}

	/**
	 * Test feed route has public permission callback.
	 *
	 * @return void
	 */
	public function test_feed_route_uses_public_permission_callback(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route    = $this->registered_routes['nettertech-events/v1/ical/feed'];
		$callback = $route['args'][0]['permission_callback'];

		$this->assertIsArray( $callback );
		$this->assertSame( 'public_feed_permission_check', $callback[1] );
	}

	/**
	 * Test event route has public permission callback.
	 *
	 * @return void
	 */
	public function test_event_route_uses_public_permission_callback(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route    = $this->registered_routes['nettertech-events/v1/ical/event/(?P<id>\d+)'];
		$callback = $route['args'][0]['permission_callback'];

		$this->assertIsArray( $callback );
		$this->assertSame( 'public_feed_permission_check', $callback[1] );
	}

	/**
	 * Test occurrence route has public permission callback.
	 *
	 * @return void
	 */
	public function test_occurrence_route_uses_public_permission_callback(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route    = $this->registered_routes['nettertech-events/v1/ical/occurrence/(?P<id>\d+)'];
		$callback = $route['args'][0]['permission_callback'];

		$this->assertIsArray( $callback );
		$this->assertSame( 'public_feed_permission_check', $callback[1] );
	}

	/**
	 * Test import route has import permission callback.
	 *
	 * @return void
	 */
	public function test_import_route_uses_import_permission_callback(): void {
		$controller = $this->create_controller();
		$controller->register_routes();

		$route    = $this->registered_routes['nettertech-events/v1/ical/import'];
		$callback = $route['args'][0]['permission_callback'];

		$this->assertIsArray( $callback );
		$this->assertSame( 'import_permission_check', $callback[1] );
	}

	// =========================================================================
	// Get Event Tests
	// =========================================================================

	/**
	 * Test get_event returns 404 for non-existent event.
	 *
	 * @return void
	 */
	public function test_get_event_returns_404_for_missing_event(): void {
		$this->event_repo->shouldReceive( 'find' )
			->once()
			->with( 999 )
			->andReturn( null );

		$request    = $this->create_mock_request( array( 'id' => 999 ) );
		$controller = $this->create_controller();

		$response = $controller->get_event( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 404, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'not_found', $data['code'] );
	}

	/**
	 * Test get_event returns 403 for unpublished event.
	 *
	 * @return void
	 */
	public function test_get_event_returns_403_for_unpublished_event(): void {
		$event = $this->create_mock_event( 1, EventStatus::DRAFT );

		$this->event_repo->shouldReceive( 'find' )
			->once()
			->with( 1 )
			->andReturn( $event );

		$request    = $this->create_mock_request( array( 'id' => 1 ) );
		$controller = $this->create_controller();

		$response = $controller->get_event( $request );

		$this->assertSame( 403, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'not_published', $data['code'] );
	}

	/**
	 * Test get_event returns error message for non-existent event.
	 *
	 * @return void
	 */
	public function test_get_event_returns_not_found_message(): void {
		$this->event_repo->shouldReceive( 'find' )
			->once()
			->with( 1 )
			->andReturn( null );

		$request    = $this->create_mock_request( array( 'id' => 1 ) );
		$controller = $this->create_controller();

		$response = $controller->get_event( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'message', $data );
		$this->assertSame( 'Event not found.', $data['message'] );
	}

	/**
	 * Test get_event returns not_published message for draft event.
	 *
	 * @return void
	 */
	public function test_get_event_returns_not_published_message(): void {
		$event = $this->create_mock_event( 1, EventStatus::DRAFT );

		$this->event_repo->shouldReceive( 'find' )
			->once()
			->with( 1 )
			->andReturn( $event );

		$request    = $this->create_mock_request( array( 'id' => 1 ) );
		$controller = $this->create_controller();

		$response = $controller->get_event( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'message', $data );
		$this->assertSame( 'Event is not published.', $data['message'] );
	}

	// =========================================================================
	// Get Occurrence Tests
	// =========================================================================

	/**
	 * Test get_occurrence returns 404 for non-existent occurrence.
	 *
	 * @return void
	 */
	public function test_get_occurrence_returns_404_for_missing_occurrence(): void {
		$this->occurrence_repo->shouldReceive( 'find' )
			->once()
			->with( 999 )
			->andReturn( null );

		$request    = $this->create_mock_request( array( 'id' => 999 ) );
		$controller = $this->create_controller();

		$response = $controller->get_occurrence( $request );

		$this->assertSame( 404, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'not_found', $data['code'] );
	}

	/**
	 * Test get_occurrence returns 403 when event not found.
	 *
	 * @return void
	 */
	public function test_get_occurrence_returns_403_when_event_not_found(): void {
		$occurrence = $this->create_mock_occurrence( 1, 5 );

		$this->occurrence_repo->shouldReceive( 'find' )
			->once()
			->with( 1 )
			->andReturn( $occurrence );

		$this->event_repo->shouldReceive( 'find' )
			->once()
			->with( 5 )
			->andReturn( null );

		$request    = $this->create_mock_request( array( 'id' => 1 ) );
		$controller = $this->create_controller();

		$response = $controller->get_occurrence( $request );

		$this->assertSame( 403, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'not_published', $data['code'] );
	}

	/**
	 * Test get_occurrence returns 403 for unpublished event.
	 *
	 * @return void
	 */
	public function test_get_occurrence_returns_403_for_unpublished_event(): void {
		$occurrence = $this->create_mock_occurrence( 1, 5 );
		$event      = $this->create_mock_event( 5, EventStatus::DRAFT );

		$this->occurrence_repo->shouldReceive( 'find' )
			->once()
			->with( 1 )
			->andReturn( $occurrence );

		$this->event_repo->shouldReceive( 'find' )
			->once()
			->with( 5 )
			->andReturn( $event );

		$request    = $this->create_mock_request( array( 'id' => 1 ) );
		$controller = $this->create_controller();

		$response = $controller->get_occurrence( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test get_occurrence returns not_found message.
	 *
	 * @return void
	 */
	public function test_get_occurrence_returns_not_found_message(): void {
		$this->occurrence_repo->shouldReceive( 'find' )
			->once()
			->with( 1 )
			->andReturn( null );

		$request    = $this->create_mock_request( array( 'id' => 1 ) );
		$controller = $this->create_controller();

		$response = $controller->get_occurrence( $request );
		$data     = $response->get_data();

		$this->assertSame( 'Occurrence not found.', $data['message'] );
	}

	// =========================================================================
	// Import Tests
	// =========================================================================

	/**
	 * Test import returns 400 for missing content.
	 *
	 * @return void
	 */
	public function test_import_returns_400_for_missing_content(): void {
		$request    = $this->create_mock_request( array( 'content' => '' ) );
		$controller = $this->create_controller();

		$response = $controller->import( $request );

		$this->assertSame( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'missing_content', $data['code'] );
	}

	/**
	 * Test import returns 400 for null content.
	 *
	 * @return void
	 */
	public function test_import_returns_400_for_null_content(): void {
		$request    = $this->create_mock_request( array() );
		$controller = $this->create_controller();

		$response = $controller->import( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test import returns missing_content message.
	 *
	 * @return void
	 */
	public function test_import_returns_missing_content_message(): void {
		$request    = $this->create_mock_request( array( 'content' => '' ) );
		$controller = $this->create_controller();

		$response = $controller->import( $request );
		$data     = $response->get_data();

		$this->assertSame( 'iCal content is required.', $data['message'] );
	}

	// =========================================================================
	// Method Existence Tests
	// =========================================================================

	/**
	 * Test all required methods exist.
	 *
	 * @return void
	 */
	public function test_all_required_methods_exist(): void {
		$controller = $this->create_controller();

		$this->assertTrue( method_exists( $controller, 'register_routes' ) );
		$this->assertTrue( method_exists( $controller, 'get_feed' ) );
		$this->assertTrue( method_exists( $controller, 'get_event' ) );
		$this->assertTrue( method_exists( $controller, 'get_occurrence' ) );
		$this->assertTrue( method_exists( $controller, 'import' ) );
		$this->assertTrue( method_exists( $controller, 'import_permission_check' ) );
		$this->assertTrue( method_exists( $controller, 'public_feed_permission_check' ) );
	}

	/**
	 * Test controller extends WP_REST_Controller.
	 *
	 * @return void
	 */
	public function test_controller_extends_wp_rest_controller(): void {
		$controller = $this->create_controller();

		$this->assertInstanceOf( \WP_REST_Controller::class, $controller );
	}
}
