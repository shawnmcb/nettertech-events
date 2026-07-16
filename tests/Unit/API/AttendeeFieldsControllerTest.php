<?php
/**
 * AttendeeFieldsController unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\API
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\API;

use Brain\Monkey\Functions;
use NetterTechEvents\API\AttendeeFieldsController;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Services\RateLimitService;

/**
 * Test AttendeeFieldsController rate limiting on public endpoints.
 */
class AttendeeFieldsControllerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock field repository.
	 *
	 * @var AttendeeFieldRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $field_repo;

	/**
	 * Mock value repository.
	 *
	 * @var AttendeeFieldValueRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $value_repo;

	/**
	 * Mock rate limit service.
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

		$this->field_repo = $this->createMock( AttendeeFieldRepositoryInterface::class );
		$this->value_repo = $this->createMock( AttendeeFieldValueRepositoryInterface::class );
		$this->rate_limit = $this->createMock( RateLimitService::class );
		$this->rate_limit->method( 'should_bypass' )->willReturn( true );

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( intval( $value ) );
			}
		);
		Functions\when( 'rest_ensure_response' )->alias(
			function ( $data ) {
				if ( $data instanceof \WP_REST_Response ) {
					return $data;
				}
				return new \WP_REST_Response( $data, 200 );
			}
		);
	}

	/**
	 * Create a controller with mock dependencies.
	 *
	 * @param RateLimitService|null $rate_limit Optional rate limit service override.
	 * @return AttendeeFieldsController
	 */
	private function create_controller( ?RateLimitService $rate_limit = null ): AttendeeFieldsController {
		return new AttendeeFieldsController(
			$this->field_repo,
			$this->value_repo,
			$rate_limit ?? $this->rate_limit
		);
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
			function ( string $key ) use ( $params ) {
				return $params[ $key ] ?? null;
			}
		);
		return $request;
	}

	/**
	 * Test rate limiting is enforced on get_fields (public endpoint).
	 *
	 * @return void
	 */
	public function test_get_fields_returns_429_when_rate_limited(): void {
		$rate_limit = $this->createMock( RateLimitService::class );
		$rate_limit->method( 'should_bypass' )->willReturn( false );
		$rate_limit->method( 'check_and_increment' )->willReturn(
			new \WP_REST_Response(
				array(
					'code'    => 'rate_limited',
					'message' => 'Too many requests.',
				),
				429
			)
		);

		$controller = $this->create_controller( $rate_limit );
		$request    = $this->create_request( array( 'event_id' => 1 ) );

		$response = $controller->get_fields( $request );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * Test rate limiting is bypassed for admins on get_fields.
	 *
	 * @return void
	 */
	public function test_get_fields_bypasses_rate_limit_for_admins(): void {
		$rate_limit = $this->createMock( RateLimitService::class );
		$rate_limit->method( 'should_bypass' )->willReturn( true );
		$rate_limit->expects( $this->never() )->method( 'check_and_increment' );

		$this->field_repo->method( 'for_event' )->willReturn( array() );

		$controller = $this->create_controller( $rate_limit );
		$request    = $this->create_request( array( 'event_id' => 1 ) );

		$response = $controller->get_fields( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test get_fields returns field data when not rate limited.
	 *
	 * @return void
	 */
	public function test_get_fields_returns_data_when_not_limited(): void {
		$this->field_repo->method( 'for_event' )->willReturn( array() );

		$controller = $this->create_controller();
		$request    = $this->create_request( array( 'event_id' => 1 ) );

		$response = $controller->get_fields( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}
}
