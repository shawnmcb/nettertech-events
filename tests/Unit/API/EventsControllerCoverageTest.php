<?php
/**
 * EventsController coverage push tests.
 *
 * Targets remaining uncovered branches: schema introspection,
 * validate_date_param branches, get_occurrences tag/date_from/date_to filters,
 * get_image_data with real image, range invalid-order branch, and rate-limit
 * non-bypass path.
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
use WP_REST_Response;

/**
 * Coverage-targeted tests for EventsController.
 *
 * @coversDefaultClass \NetterTechEvents\API\EventsController
 */
class EventsControllerCoverageTest extends \NetterTechEventsTestCase {

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
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ServiceRegistry::reset();

		$this->occurrence_repo  = $this->createMock( OccurrenceRepository::class );
		$this->ticket_type_repo = $this->createMock( TicketTypeRepository::class );
		$this->capacity_service = $this->createMock( CapacityService::class );
		$this->rate_limit       = $this->createMock( RateLimitService::class );
		$this->rate_limit->method( 'should_bypass' )->willReturn( true );
		$this->rate_limit->method( 'add_headers' )->willReturnArgument( 0 );

		Functions\when( 'date_i18n' )->alias(
			static fn( $format, $timestamp ) => date( $format, $timestamp )
		);
		Functions\when( 'get_option' )->alias(
			static function ( $option ) {
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
			static fn( $value ) => abs( intval( $value ) )
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
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
	 * Build controller with mocks.
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
	 * Build a mock occurrence.
	 *
	 * @param int        $id    ID.
	 * @param Event|null $event Event.
	 * @return Occurrence
	 */
	private function build_occurrence( int $id, ?Event $event = null ): Occurrence {
		$occurrence                 = $this->createMock( Occurrence::class );
		$occurrence->id             = $id;
		$occurrence->event_id       = $event ? $event->id : 1;
		$occurrence->start_datetime = '2026-02-15 19:00:00';
		$occurrence->end_datetime   = '2026-02-15 21:00:00';
		$occurrence->all_day        = false;
		$occurrence->status         = 'scheduled';
		$occurrence->method( 'get_event' )->willReturn( $event );
		$occurrence->method( 'get_url' )->willReturn(
			'https://example.com/event/' . ( $event ? $event->id : 1 ) . '/2026-02-15-1900/'
		);
		$occurrence->method( 'get_featured_image_id' )->willReturn( $event ? $event->featured_image_id : null );
		return $occurrence;
	}

	/**
	 * Build a mock event.
	 *
	 * @param int      $id              ID.
	 * @param int|null $featured_image  Featured image ID.
	 * @return Event
	 */
	private function build_event( int $id, ?int $featured_image = null ): Event {
		$event                    = $this->createMock( Event::class );
		$event->id                = $id;
		$event->title             = 'Event ' . $id;
		$event->slug              = 'event-' . $id;
		$event->excerpt           = 'Excerpt';
		$event->venue_name        = 'Venue';
		$event->venue_address     = 'Address';
		$event->featured_image_id = $featured_image;
		$event->method( 'get_permalink' )->willReturn( 'https://example.com/event/' . $id );
		$event->method( 'is_published' )->willReturn( true );
		return $event;
	}

	/**
	 * Build a mock request.
	 *
	 * @param array<string, mixed> $params Params.
	 * @return \WP_REST_Request
	 */
	private function build_request( array $params = array() ): \WP_REST_Request {
		$request = $this->createMock( \WP_REST_Request::class );
		$request->method( 'get_param' )->willReturnCallback(
			static fn( $key ) => $params[ $key ] ?? null
		);
		return $request;
	}

	// =========================================================================
	// get_item_schema
	// =========================================================================

	/**
	 * @covers ::get_item_schema
	 */
	public function test_get_item_schema_returns_object_shape(): void {
		$schema = $this->create_controller()->get_item_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'occurrence', $schema['title'] );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
	}

	/**
	 * @covers ::get_item_schema
	 */
	public function test_get_item_schema_describes_id_and_event_id(): void {
		$props = $this->create_controller()->get_item_schema()['properties'];

		$this->assertSame( 'integer', $props['id']['type'] );
		$this->assertSame( 'integer', $props['event_id']['type'] );
		$this->assertTrue( $props['id']['readonly'] );
	}

	/**
	 * @covers ::get_item_schema
	 */
	public function test_get_item_schema_describes_datetimes_and_all_day(): void {
		$props = $this->create_controller()->get_item_schema()['properties'];

		$this->assertSame( 'string', $props['start_datetime']['type'] );
		$this->assertSame( 'date-time', $props['start_datetime']['format'] );
		$this->assertSame( 'string', $props['end_datetime']['type'] );
		$this->assertSame( 'boolean', $props['all_day']['type'] );
		$this->assertSame( 'string', $props['status']['type'] );
	}

	/**
	 * @covers ::get_item_schema
	 */
	public function test_get_item_schema_describes_formatted_sub_object(): void {
		$props = $this->create_controller()->get_item_schema()['properties'];

		$this->assertSame( 'object', $props['formatted']['type'] );
		$this->assertArrayHasKey( 'date', $props['formatted']['properties'] );
		$this->assertArrayHasKey( 'time', $props['formatted']['properties'] );
		$this->assertArrayHasKey( 'date_range', $props['formatted']['properties'] );
	}

	/**
	 * @covers ::get_item_schema
	 */
	public function test_get_item_schema_describes_event_sub_object(): void {
		$props = $this->create_controller()->get_item_schema()['properties'];

		$this->assertContains( 'object', $props['event']['type'] );
		$this->assertContains( 'null', $props['event']['type'] );
		$this->assertArrayHasKey( 'title', $props['event']['properties'] );
		$this->assertArrayHasKey( 'venue_name', $props['event']['properties'] );
		$this->assertArrayHasKey( 'permalink', $props['event']['properties'] );
	}

	/**
	 * @covers ::get_item_schema
	 */
	public function test_get_item_schema_describes_tickets_sub_object(): void {
		$props = $this->create_controller()->get_item_schema()['properties'];

		$this->assertSame( 'object', $props['tickets']['type'] );
		$this->assertSame( 'boolean', $props['tickets']['properties']['available']['type'] );
		$this->assertSame( 'boolean', $props['tickets']['properties']['sold_out']['type'] );
		$this->assertSame( 'array', $props['tickets']['properties']['types']['type'] );
	}

	// =========================================================================
	// validate_date_param
	// =========================================================================

	/**
	 * @covers ::validate_date_param
	 */
	public function test_validate_date_param_accepts_valid_iso_date(): void {
		$controller = $this->create_controller();
		$request    = $this->build_request();

		$result = $controller->validate_date_param( '2026-02-15', $request, 'start' );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::validate_date_param
	 */
	public function test_validate_date_param_accepts_datetime_string(): void {
		$controller = $this->create_controller();
		$request    = $this->build_request();

		$result = $controller->validate_date_param( '2026-02-15 19:00:00', $request, 'start' );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::validate_date_param
	 */
	public function test_validate_date_param_rejects_unparseable_string(): void {
		$controller = $this->create_controller();
		$request    = $this->build_request();

		$result = $controller->validate_date_param( 'not-a-date-anywhere', $request, 'end' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_date', $result->get_error_code() );
	}

	// =========================================================================
	// public_events_permission_check
	// =========================================================================

	/**
	 * @covers ::public_events_permission_check
	 */
	public function test_public_events_permission_check_always_true(): void {
		$this->assertTrue( $this->create_controller()->public_events_permission_check() );
	}

	// =========================================================================
	// get_range invalid-order branch
	// =========================================================================

	/**
	 * @covers ::get_range
	 */
	public function test_get_range_returns_400_when_start_after_end(): void {
		$controller = $this->create_controller();
		$request    = $this->build_request( array( 'start' => '2026-03-01', 'end' => '2026-02-01' ) );

		$response = $controller->get_range( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data );
	}

	// =========================================================================
	// get_occurrences filter branches
	// =========================================================================

	/**
	 * @covers ::get_occurrences
	 */
	public function test_get_occurrences_with_tag_filter(): void {
		$this->occurrence_repo
			->method( 'get_filtered' )
			->willReturn( array( 'items' => array(), 'total' => 0, 'total_pages' => 0 ) );

		$response = $this->create_controller()->get_occurrences(
			$this->build_request( array( 'tag' => 'jazz' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 0, $data['total'] );
	}

	/**
	 * @covers ::get_occurrences
	 */
	public function test_get_occurrences_with_date_from_and_date_to(): void {
		$this->occurrence_repo
			->method( 'get_filtered' )
			->willReturn( array( 'items' => array(), 'total' => 0, 'total_pages' => 0 ) );

		$response = $this->create_controller()->get_occurrences(
			$this->build_request(
				array(
					'date_from' => '2026-01-01',
					'date_to'   => '2026-12-31',
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @covers ::get_occurrences
	 */
	public function test_get_occurrences_with_array_category(): void {
		$this->occurrence_repo
			->method( 'get_filtered' )
			->willReturn( array( 'items' => array(), 'total' => 0, 'total_pages' => 0 ) );

		$response = $this->create_controller()->get_occurrences(
			$this->build_request( array( 'category' => array( '1', '2', '3' ) ) )
		);

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @covers ::get_occurrences
	 */
	public function test_get_occurrences_range_mode_with_invalid_order(): void {
		$response = $this->create_controller()->get_occurrences(
			$this->build_request( array( 'start' => '2026-03-01', 'end' => '2026-02-01' ) )
		);

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data );
	}

	// =========================================================================
	// get_image_data with real attachment
	// =========================================================================

	/**
	 * @covers ::get_item
	 */
	public function test_get_item_with_event_image(): void {
		$event      = $this->build_event( 7, 42 );
		$occurrence = $this->build_occurrence( 11, $event );

		// Override default to simulate a real attachment.
		Functions\when( 'wp_get_attachment_image_src' )->alias(
			static function ( $id, $size ) {
				if ( 'medium' === $size ) {
					return array( 'https://cdn.example.com/medium.jpg', 300, 200 );
				}
				if ( 'full' === $size ) {
					return array( 'https://cdn.example.com/full.jpg', 1200, 800 );
				}
				return null;
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( 'alt text' );

		$this->occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$response = $this->create_controller()->get_item(
			$this->build_request( array( 'id' => 11 ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'event', $data );
		$this->assertIsArray( $data['event']['image'] );
		$this->assertSame( 42, $data['event']['image']['id'] );
		$this->assertSame( 'https://cdn.example.com/medium.jpg', $data['event']['image']['url'] );
		$this->assertSame( 'https://cdn.example.com/full.jpg', $data['event']['image']['full'] );
		$this->assertSame( 'alt text', $data['event']['image']['alt'] );
	}

	/**
	 * @covers ::get_item
	 */
	public function test_get_item_with_image_id_but_no_attachment(): void {
		$event      = $this->build_event( 9, 999 );
		$occurrence = $this->build_occurrence( 99, $event );

		// wp_get_attachment_image_src returns null (default in setUp).
		$this->occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$response = $this->create_controller()->get_item(
			$this->build_request( array( 'id' => 99 ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertNull( $data['event']['image'] );
	}

	// =========================================================================
	// Rate limit non-bypass path
	// =========================================================================

	/**
	 * @covers ::get_upcoming
	 */
	public function test_get_upcoming_returns_rate_limit_response(): void {
		$rate_limited = new WP_REST_Response( array( 'error' => 'rate limited' ), 429 );
		$rate_limit   = $this->createMock( RateLimitService::class );
		$rate_limit->method( 'should_bypass' )->willReturn( false );
		$rate_limit->method( 'check_and_increment' )->willReturn( $rate_limited );

		$controller = new EventsController(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->capacity_service,
			$rate_limit
		);

		$response = $controller->get_upcoming( $this->build_request() );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * @covers ::get_range
	 */
	public function test_get_range_returns_rate_limit_response(): void {
		$rate_limited = new WP_REST_Response( array( 'error' => 'rate limited' ), 429 );
		$rate_limit   = $this->createMock( RateLimitService::class );
		$rate_limit->method( 'should_bypass' )->willReturn( false );
		$rate_limit->method( 'check_and_increment' )->willReturn( $rate_limited );

		$controller = new EventsController(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->capacity_service,
			$rate_limit
		);

		$response = $controller->get_range(
			$this->build_request( array( 'start' => '2026-01-01', 'end' => '2026-02-01' ) )
		);

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * @covers ::get_item
	 */
	public function test_get_item_returns_rate_limit_response(): void {
		$rate_limited = new WP_REST_Response( array( 'error' => 'rate limited' ), 429 );
		$rate_limit   = $this->createMock( RateLimitService::class );
		$rate_limit->method( 'should_bypass' )->willReturn( false );
		$rate_limit->method( 'check_and_increment' )->willReturn( $rate_limited );

		$controller = new EventsController(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->capacity_service,
			$rate_limit
		);

		$response = $controller->get_item( $this->build_request( array( 'id' => 1 ) ) );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * @covers ::get_occurrences
	 */
	public function test_get_occurrences_returns_rate_limit_response(): void {
		$rate_limited = new WP_REST_Response( array( 'error' => 'rate limited' ), 429 );
		$rate_limit   = $this->createMock( RateLimitService::class );
		$rate_limit->method( 'should_bypass' )->willReturn( false );
		$rate_limit->method( 'check_and_increment' )->willReturn( $rate_limited );

		$controller = new EventsController(
			$this->occurrence_repo,
			$this->ticket_type_repo,
			$this->capacity_service,
			$rate_limit
		);

		$response = $controller->get_occurrences( $this->build_request() );

		$this->assertSame( 429, $response->get_status() );
	}

	// =========================================================================
	// format_date_range single-day branch
	// =========================================================================

	/**
	 * @covers ::get_item
	 */
	public function test_get_item_multi_day_occurrence_uses_date_range(): void {
		$event      = $this->build_event( 5 );
		$occurrence = $this->build_occurrence( 13, $event );
		// Override to span two days.
		$occurrence->end_datetime = '2026-02-17 21:00:00';

		$this->occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$response = $this->create_controller()->get_item(
			$this->build_request( array( 'id' => 13 ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		// date_range should now contain " - " separator for multi-day.
		$this->assertStringContainsString( ' - ', $data['formatted']['date_range'] );
	}

	/**
	 * @covers ::get_item
	 */
	public function test_get_item_all_day_event_returns_all_day_label(): void {
		$event              = $this->build_event( 6 );
		$occurrence         = $this->build_occurrence( 14, $event );
		$occurrence->all_day = true;

		Functions\when( '__' )->alias(
			static fn( $text, $domain = '' ) => 'All Day' === $text ? 'All Day' : $text
		);

		$this->occurrence_repo->method( 'find_with_event' )->willReturn( $occurrence );
		$this->ticket_type_repo->method( 'for_occurrence' )->willReturn( array() );

		$response = $this->create_controller()->get_item(
			$this->build_request( array( 'id' => 14 ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'All Day', $data['formatted']['time'] );
	}
}
