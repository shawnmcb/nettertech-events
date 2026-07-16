<?php
/**
 * EventsAdminController coverage push tests.
 *
 * Targets: get_item_schema, prepare_event_response, rate-limit branches,
 * orderby/order params, update with status, get_create_args/get_update_args.
 *
 * @package NetterTechEvents\Tests\Unit\API
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\API;

use Brain\Monkey\Functions;
use NetterTechEvents\API\EventsAdminController;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Services\ActivityLogService;
use NetterTechEvents\Services\RateLimitService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Coverage-targeted tests for EventsAdminController.
 *
 * @coversDefaultClass \NetterTechEvents\API\EventsAdminController
 */
class EventsAdminControllerCoverageTest extends \NetterTechEventsTestCase {

	/**
	 * @var EventRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_repo;

	/**
	 * @var ActivityLogService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $activity_log;

	/**
	 * @var RateLimitService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $rate_limit;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ServiceRegistry::reset();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_title' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'rest_sanitize_boolean' )->alias(
			static fn( $v ) => (bool) filter_var( $v, FILTER_VALIDATE_BOOLEAN )
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->event_repo = $this->getMockBuilder( EventRepository::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'find', 'save', 'delete', 'paginate', 'generate_unique_slug' ) )
			->getMock();

		$this->activity_log = $this->getMockBuilder( ActivityLogService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'log' ) )
			->getMock();

		$this->rate_limit = $this->createMock( RateLimitService::class );
		$this->rate_limit->method( 'should_bypass' )->willReturn( true );
		$this->rate_limit->method( 'add_headers' )->willReturnArgument( 0 );
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ServiceRegistry::reset();
		parent::tearDown();
	}

	/**
	 * Build controller.
	 *
	 * @param RateLimitService|null $rate Optional rate limit override.
	 * @return EventsAdminController
	 */
	private function build_controller( ?RateLimitService $rate = null ): EventsAdminController {
		return new EventsAdminController(
			$this->event_repo,
			$this->activity_log,
			$rate ?? $this->rate_limit
		);
	}

	/**
	 * Build mock request.
	 *
	 * @param array<string, mixed> $params Params.
	 * @return WP_REST_Request
	 */
	private function build_request( array $params = array() ): WP_REST_Request {
		$request = $this->createMock( WP_REST_Request::class );
		$request->method( 'get_param' )->willReturnCallback(
			static fn( $key ) => $params[ $key ] ?? null
		);
		$request->method( 'get_params' )->willReturn( $params );
		return $request;
	}

	/**
	 * Build a real Event object.
	 *
	 * @param int                  $id    ID.
	 * @param array<string, mixed> $props Properties.
	 * @return Event
	 */
	private function build_event( int $id, array $props = array() ): Event {
		$event = $this->getMockBuilder( Event::class )
			->onlyMethods( array( 'get_featured_image_url', 'get_attendance_mode', 'get_permalink' ) )
			->getMock();
		$event->id                  = $id;
		$event->title               = $props['title'] ?? 'Test ' . $id;
		$event->slug                = $props['slug'] ?? 'test-' . $id;
		$event->description         = $props['description'] ?? 'Description';
		$event->excerpt             = $props['excerpt'] ?? 'Excerpt';
		$event->status              = $props['status'] ?? EventStatus::DRAFT;
		$event->event_type          = $props['event_type'] ?? 'single';
		$event->venue_name          = $props['venue_name'] ?? 'Venue';
		$event->venue_address       = $props['venue_address'] ?? '123 St';
		$event->featured_image_id   = $props['featured_image_id'] ?? 0;
		$event->series_id           = $props['series_id'] ?? null;
		$event->recurrence_rule     = $props['recurrence_rule'] ?? null;
		$event->recurrence_end_date = $props['recurrence_end_date'] ?? null;
		$event->layout_config       = $props['layout_config'] ?? null;
		$event->custom_fields       = $props['custom_fields'] ?? null;
		$event->is_virtual          = $props['is_virtual'] ?? false;
		$event->virtual_url         = $props['virtual_url'] ?? null;
		$event->created_at          = $props['created_at'] ?? '2026-01-01 00:00:00';
		$event->updated_at          = $props['updated_at'] ?? '2026-01-01 00:00:00';

		$event->method( 'get_featured_image_url' )->willReturn( 'https://example.com/img.jpg' );
		$event->method( 'get_attendance_mode' )->willReturn( 'offline' );
		$event->method( 'get_permalink' )->willReturn( 'https://example.com/events/' . $event->slug );
		return $event;
	}

	// =========================================================================
	// get_item_schema
	// =========================================================================

	/**
	 * @covers ::get_item_schema
	 */
	public function test_get_item_schema_returns_event_shape(): void {
		$schema = $this->build_controller()->get_item_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'event', $schema['title'] );
		$this->assertSame( 'object', $schema['type'] );
	}

	/**
	 * @covers ::get_item_schema
	 */
	public function test_get_item_schema_declares_all_event_properties(): void {
		$props = $this->build_controller()->get_item_schema()['properties'];

		$expected = array(
			'id',
			'title',
			'slug',
			'description',
			'excerpt',
			'status',
			'event_type',
			'venue_name',
			'venue_address',
			'featured_image_id',
			'featured_image_url',
			'series_id',
			'recurrence_rule',
			'recurrence_end_date',
			'layout_config',
			'custom_fields',
			'is_virtual',
			'virtual_url',
			'attendance_mode',
			'permalink',
			'created_at',
			'updated_at',
		);
		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, $props, sprintf( "Missing property: %s", $key ) );
		}
	}

	/**
	 * @covers ::get_item_schema
	 */
	public function test_get_item_schema_status_uses_event_statuses(): void {
		$props = $this->build_controller()->get_item_schema()['properties'];

		$this->assertSame( Event::STATUSES, $props['status']['enum'] );
		$this->assertSame( Event::TYPES, $props['event_type']['enum'] );
	}

	/**
	 * @covers ::get_item_schema
	 */
	public function test_get_item_schema_readonly_fields(): void {
		$props = $this->build_controller()->get_item_schema()['properties'];

		$this->assertTrue( $props['id']['readonly'] );
		$this->assertTrue( $props['featured_image_url']['readonly'] );
		$this->assertTrue( $props['attendance_mode']['readonly'] );
		$this->assertTrue( $props['permalink']['readonly'] );
		$this->assertTrue( $props['created_at']['readonly'] );
		$this->assertTrue( $props['updated_at']['readonly'] );
	}

	// =========================================================================
	// get_collection_params — orderby/order branches
	// =========================================================================

	/**
	 * @covers ::get_collection_params
	 */
	public function test_get_collection_params_orderby_enum(): void {
		$params = $this->build_controller()->get_collection_params();

		$this->assertContains( 'id', $params['orderby']['enum'] );
		$this->assertContains( 'title', $params['orderby']['enum'] );
		$this->assertContains( 'created_at', $params['orderby']['enum'] );
		$this->assertContains( 'updated_at', $params['orderby']['enum'] );
		$this->assertContains( 'status', $params['orderby']['enum'] );
	}

	/**
	 * @covers ::get_collection_params
	 */
	public function test_get_collection_params_order_enum(): void {
		$params = $this->build_controller()->get_collection_params();

		$this->assertSame( array( 'asc', 'desc' ), $params['order']['enum'] );
		$this->assertSame( 'desc', $params['order']['default'] );
	}

	/**
	 * @covers ::get_collection_params
	 */
	public function test_get_collection_params_per_page_bounds(): void {
		$params = $this->build_controller()->get_collection_params();

		$this->assertSame( 1, $params['per_page']['minimum'] );
		$this->assertSame( 100, $params['per_page']['maximum'] );
		$this->assertSame( 20, $params['per_page']['default'] );
	}

	// =========================================================================
	// get_items — orderby/order params drive args
	// =========================================================================

	/**
	 * @covers ::get_items
	 */
	public function test_get_items_passes_custom_orderby_and_order(): void {
		$captured_args = null;
		$this->event_repo->method( 'paginate' )->willReturnCallback(
			static function ( $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
					'pages'       => 0,
					'page'        => $args['page'] ?? 1,
				);
			}
		);

		$response = $this->build_controller()->get_items(
			$this->build_request(
				array(
					'orderby' => 'title',
					'order'   => 'asc',
					'page'    => 2,
				)
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'title', $captured_args['orderby'] );
		$this->assertSame( 'asc', $captured_args['order'] );
		$this->assertSame( 2, $captured_args['page'] );
	}

	// =========================================================================
	// Rate limit branches
	// =========================================================================

	/**
	 * @covers ::get_items
	 */
	public function test_get_items_returns_rate_limit_when_blocked(): void {
		$rate_limited = new WP_REST_Response( array( 'error' => 'limited' ), 429 );
		$rate         = $this->createMock( RateLimitService::class );
		$rate->method( 'should_bypass' )->willReturn( false );
		$rate->method( 'check_and_increment' )->willReturn( $rate_limited );

		$response = $this->build_controller( $rate )->get_items( $this->build_request() );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * @covers ::get_item
	 */
	public function test_get_item_returns_rate_limit_when_blocked(): void {
		$rate_limited = new WP_REST_Response( array( 'error' => 'limited' ), 429 );
		$rate         = $this->createMock( RateLimitService::class );
		$rate->method( 'should_bypass' )->willReturn( false );
		$rate->method( 'check_and_increment' )->willReturn( $rate_limited );

		$response = $this->build_controller( $rate )->get_item( $this->build_request( array( 'id' => 1 ) ) );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * @covers ::create_item
	 */
	public function test_create_item_returns_rate_limit_when_blocked(): void {
		$rate_limited = new WP_REST_Response( array( 'error' => 'limited' ), 429 );
		$rate         = $this->createMock( RateLimitService::class );
		$rate->method( 'should_bypass' )->willReturn( false );
		$rate->method( 'check_and_increment' )->willReturn( $rate_limited );

		$response = $this->build_controller( $rate )->create_item( $this->build_request() );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * @covers ::update_item
	 */
	public function test_update_item_returns_rate_limit_when_blocked(): void {
		$rate_limited = new WP_REST_Response( array( 'error' => 'limited' ), 429 );
		$rate         = $this->createMock( RateLimitService::class );
		$rate->method( 'should_bypass' )->willReturn( false );
		$rate->method( 'check_and_increment' )->willReturn( $rate_limited );

		$response = $this->build_controller( $rate )->update_item( $this->build_request( array( 'id' => 1 ) ) );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * @covers ::delete_item
	 */
	public function test_delete_item_returns_rate_limit_when_blocked(): void {
		$rate_limited = new WP_REST_Response( array( 'error' => 'limited' ), 429 );
		$rate         = $this->createMock( RateLimitService::class );
		$rate->method( 'should_bypass' )->willReturn( false );
		$rate->method( 'check_and_increment' )->willReturn( $rate_limited );

		$response = $this->build_controller( $rate )->delete_item( $this->build_request( array( 'id' => 1 ) ) );

		$this->assertSame( 429, $response->get_status() );
	}

	// =========================================================================
	// admin_permissions_check denial
	// =========================================================================

	/**
	 * @covers ::admin_permissions_check
	 */
	public function test_admin_permissions_check_denies_with_403_error_code(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = $this->build_controller()->admin_permissions_check( $this->build_request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	// =========================================================================
	// prepare_event_response via get_item
	// =========================================================================

	/**
	 * @covers ::get_item
	 */
	public function test_get_item_response_includes_all_event_fields(): void {
		$event = $this->build_event(
			77,
			array(
				'title'      => 'Concert',
				'venue_name' => 'The Hall',
				'is_virtual' => false,
			)
		);
		$this->event_repo->method( 'find' )->willReturn( $event );

		$response = $this->build_controller()->get_item( $this->build_request( array( 'id' => 77 ) ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 77, $data['id'] );
		$this->assertSame( 'Concert', $data['title'] );
		$this->assertSame( 'The Hall', $data['venue_name'] );
		$this->assertSame( 'offline', $data['attendance_mode'] );
		$this->assertSame( 'https://example.com/img.jpg', $data['featured_image_url'] );
		$this->assertSame( 'draft', $data['status'] );
	}

	/**
	 * @covers ::update_item
	 */
	public function test_update_item_updates_status_via_enum_conversion(): void {
		$event = $this->build_event( 88 );
		$this->event_repo->method( 'find' )->willReturn( $event );
		$this->event_repo->method( 'save' )->willReturn( $event );

		$response = $this->build_controller()->update_item(
			$this->build_request(
				array(
					'id'     => 88,
					'status' => 'published',
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( EventStatus::PUBLISHED, $event->status );
	}

	/**
	 * @covers ::update_item
	 */
	public function test_update_item_invalid_status_falls_back_to_draft(): void {
		$event = $this->build_event( 89 );
		$event->status = EventStatus::PUBLISHED;
		$this->event_repo->method( 'find' )->willReturn( $event );
		$this->event_repo->method( 'save' )->willReturn( $event );

		$response = $this->build_controller()->update_item(
			$this->build_request(
				array(
					'id'     => 89,
					'status' => 'not-a-real-status-string',
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( EventStatus::DRAFT, $event->status );
	}

	/**
	 * @covers ::create_item
	 */
	public function test_create_item_uses_provided_slug_when_present(): void {
		$captured_event = null;
		$this->event_repo->method( 'save' )->willReturnCallback(
			static function ( $event ) use ( &$captured_event ) {
				$captured_event     = $event;
				$captured_event->id = 100;
				return $event;
			}
		);

		$response = $this->build_controller()->create_item(
			$this->build_request(
				array(
					'title' => 'Custom-Slug Event',
					'slug'  => 'my-custom-slug',
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );
		// Slug was provided as a populated field — not auto-generated.
		$this->assertNotNull( $captured_event );
		$this->assertSame( 'my-custom-slug', $captured_event->slug );
	}
}
