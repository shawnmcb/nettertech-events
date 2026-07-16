<?php
/**
 * WaitlistRestController unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\API
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\API;

use Brain\Monkey\Functions;
use NetterTechEvents\API\WaitlistRestController;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\WaitlistEntry;
use NetterTechEvents\Services\RateLimitService;
use NetterTechEvents\Services\WaitlistService;

/**
 * Test WaitlistRestController functionality.
 *
 * Note: Full REST API tests require integration tests with WP_REST_Server.
 * These unit tests focus on handler logic and response structure.
 */
class WaitlistRestControllerTest extends \NetterTechEventsTestCase {

	/**
	 * Mock WaitlistService.
	 *
	 * @var WaitlistService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $waitlist_service;

	/**
	 * Mock OccurrenceRepository.
	 *
	 * @var OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

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

		$this->waitlist_service = $this->createMock( WaitlistService::class );
		$this->occurrence_repo  = $this->createMock( \NetterTechEvents\Repositories\OccurrenceRepository::class );
		$this->rate_limit       = $this->createMock( RateLimitService::class );
		$this->rate_limit->method( 'should_bypass' )->willReturn( true );

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( intval( $value ) );
			}
		);
		Functions\when( 'sanitize_email' )->returnArg( 1 );
			Functions\when( 'sanitize_text_field' )->returnArg( 1 );
			Functions\when( 'get_option' )->justReturn( 'test-waitlist-token-secret' );
			Functions\when( 'wp_generate_password' )->justReturn( 'generated-waitlist-token-secret' );
			Functions\when( 'update_option' )->justReturn( true );
			Functions\when( 'is_email' )->alias(
			function ( $value ) {
				return false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
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
	 * Create a WaitlistRestController with mock dependencies.
	 *
	 * @return WaitlistRestController
	 */
	private function create_controller(): WaitlistRestController {
		return new WaitlistRestController(
			$this->waitlist_service,
			$this->occurrence_repo,
			$this->rate_limit
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
	 * Test handle_join returns 404 when occurrence not found.
	 *
	 * @return void
	 */
	public function test_handle_join_occurrence_not_found(): void {
		$this->occurrence_repo->method( 'find' )->willReturn( null );

		$controller = $this->create_controller();
		$request    = $this->create_request(
			array(
				'occurrence_id' => 999,
				'email'         => 'test@example.com',
				'name'          => 'Test User',
			)
		);

		$response = $controller->handle_join( $request );

		$this->assertSame( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'occurrence_not_found', $data['code'] );
	}

	/**
	 * Test handle_join returns 201 on success.
	 *
	 * @return void
	 */
	public function test_handle_join_success(): void {
		$occurrence     = new Occurrence();
		$occurrence->id = 1;

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );

		$entry           = new WaitlistEntry();
		$entry->position = 3;

		$this->waitlist_service->method( 'join' )->willReturn( $entry );

		$controller = $this->create_controller();
		$request    = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'test@example.com',
				'name'          => 'Test User',
				'phone'         => null,
			)
		);

		$response = $controller->handle_join( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 3, $data['position'] );
		$this->assertArrayHasKey( 'leave_token', $data );
		$this->assertMatchesRegularExpression( '/^\d+:[a-f0-9]{64}$/', $data['leave_token'] );
	}

	/**
	 * Test item schema documents leave token contract.
	 *
	 * @return void
	 */
	public function test_item_schema_documents_leave_token(): void {
		$controller = $this->create_controller();
		$schema     = $controller->get_item_schema();

		$this->assertArrayHasKey( 'leave_token', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['leave_token']['type'] );
		$this->assertTrue( $schema['properties']['leave_token']['readonly'] );
	}

	/**
	 * Test handle_join returns 409 when already on waitlist.
	 *
	 * @return void
	 */
	public function test_handle_join_already_on_waitlist(): void {
		$occurrence     = new Occurrence();
		$occurrence->id = 1;

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );

		$this->waitlist_service->method( 'join' )->willThrowException(
			ValidationException::fromErrors( array( 'Already on waitlist' ) )
		);

		$controller = $this->create_controller();
		$request    = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'test@example.com',
				'name'          => 'Test User',
			)
		);

		$response = $controller->handle_join( $request );

		$this->assertSame( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'already_on_waitlist', $data['code'] );
	}

	/**
	 * Test handle_status default behavior returns uniform empty response (SEC-007).
	 *
	 * Even when the email IS on the waitlist, the default base-plugin response
	 * must NOT reveal presence — `on_waitlist` is false and `position` is null.
	 * The waitlist service must NOT be queried in the unauthorized path
	 * (no information leak via timing or side effects).
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function test_handle_status_default_does_not_reveal_presence(): void {
		// Service must NOT be called when caller is unauthorized (default).
		$this->waitlist_service->expects( $this->never() )->method( 'get_position' );

		$controller = $this->create_controller();
		$request    = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'test@example.com',
			)
		);

		$response = $controller->handle_status( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'on_waitlist', $data, 'Response shape must preserve on_waitlist key.' );
		$this->assertArrayHasKey( 'position', $data, 'Response shape must preserve position key.' );
		$this->assertFalse( $data['on_waitlist'], 'Default response must not reveal presence.' );
		$this->assertNull( $data['position'], 'Default response must not reveal position.' );
	}

	/**
	 * Test handle_status returns real position when authorized via filter.
	 *
	 * When an extension plugin (e.g., Pro) hooks `nettertech_events_waitlist_status_authorized`
	 * to return true (e.g., after validating a cancellation token), the response
	 * exposes the real `on_waitlist` boolean and numeric `position`.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function test_handle_status_authorized_via_filter_returns_real_position(): void {
		$this->waitlist_service->method( 'get_position' )->willReturn( 5 );

		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value, ...$args ) {
				if ( 'nettertech_events_waitlist_status_authorized' === $filter ) {
					return true;
				}
				return $value;
			}
		);

		$controller = $this->create_controller();
		$request    = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'test@example.com',
			)
		);

		$response = $controller->handle_status( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['on_waitlist'] );
		$this->assertSame( 5, $data['position'] );
	}

	/**
	 * Test handle_status authorized filter returns null position when not on waitlist.
	 *
	 * Even with authorization granted, an email not on the waitlist still receives
	 * the same null/false shape — authorization just unmasks the real value.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function test_handle_status_authorized_via_filter_not_on_waitlist(): void {
		$this->waitlist_service->method( 'get_position' )->willReturn( null );

		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value, ...$args ) {
				if ( 'nettertech_events_waitlist_status_authorized' === $filter ) {
					return true;
				}
				return $value;
			}
		);

		$controller = $this->create_controller();
		$request    = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'test@example.com',
			)
		);

		$response = $controller->handle_status( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['on_waitlist'] );
		$this->assertNull( $data['position'] );
	}

	/**
	 * Test nettertech_events_waitlist_status_authorized filter receives default=false and full context.
	 *
	 * Verifies the contract for extension plugins: filter is invoked with
	 * (false, occurrence_id, email, request). Default is FALSE so base plugin
	 * is secure-by-default; Pro returns true when a valid cancellation token
	 * accompanies the request.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function test_handle_status_filter_receives_default_false_and_context(): void {
		$captured_args = array();

		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value, ...$args ) use ( &$captured_args ) {
				if ( 'nettertech_events_waitlist_status_authorized' === $filter ) {
					$captured_args = array_merge( array( $value ), $args );
				}
				return $value;
			}
		);

		$controller = $this->create_controller();
		$request    = $this->create_request(
			array(
				'occurrence_id' => 42,
				'email'         => 'test@example.com',
			)
		);

		$controller->handle_status( $request );

		$this->assertCount( 4, $captured_args, 'Filter must receive 4 args: default, occurrence_id, email, request.' );
		$this->assertFalse( $captured_args[0], 'Default authorized value must be false (secure-by-default).' );
		$this->assertSame( 42, $captured_args[1], 'Second arg must be occurrence_id.' );
		$this->assertSame( 'test@example.com', $captured_args[2], 'Third arg must be email.' );
		$this->assertSame( $request, $captured_args[3], 'Fourth arg must be the WP_REST_Request.' );
	}

	/**
	 * Test handle_leave returns 200 on success.
	 *
	 * @return void
	 */
	public function test_handle_leave_success(): void {
		$this->waitlist_service->method( 'leave' )->willReturn( true );

		$controller = $this->create_controller();
		$request    = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'test@example.com',
			)
		);

		$response = $controller->handle_leave( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test handle_leave returns 404 when not on waitlist.
	 *
	 * @return void
	 */
	public function test_handle_leave_not_found(): void {
		$this->waitlist_service->method( 'leave' )->willReturn( false );

		$controller = $this->create_controller();
		$request    = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'test@example.com',
			)
		);

		$response = $controller->handle_leave( $request );

		$this->assertSame( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'not_on_waitlist', $data['code'] );
	}

	/**
	 * Test controller uses Base plugin REST namespace.
	 *
	 * @return void
	 */
	public function test_uses_base_namespace(): void {
		$controller = $this->create_controller();
		$reflection = new \ReflectionClass( $controller );

		$namespace_prop = $reflection->getProperty( 'namespace' );

		$this->assertSame( 'nettertech-events/v1', $namespace_prop->getValue( $controller ) );
	}

	/**
	 * Test rate limiting is enforced on leave endpoint.
	 *
	 * @return void
	 */
	public function test_handle_leave_rate_limited(): void {
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

		$controller = new WaitlistRestController(
			$this->waitlist_service,
			$this->occurrence_repo,
			$rate_limit
		);

		$request  = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'test@example.com',
			)
		);
		$response = $controller->handle_leave( $request );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * Test rate limiting is enforced on join endpoint.
	 *
	 * @return void
	 */
	public function test_handle_join_rate_limited(): void {
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

		$controller = new WaitlistRestController(
			$this->waitlist_service,
			$this->occurrence_repo,
			$rate_limit
		);

		$request  = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'test@example.com',
				'name'          => 'Test User',
			)
		);
		$response = $controller->handle_join( $request );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * Test rate limiting is enforced on status endpoint.
	 *
	 * @return void
	 */
	public function test_handle_status_rate_limited(): void {
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

		$controller = new WaitlistRestController(
			$this->waitlist_service,
			$this->occurrence_repo,
			$rate_limit
		);

		$request  = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'test@example.com',
			)
		);
		$response = $controller->handle_status( $request );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * Test handle_status applies per-email rate limit after per-IP rate limit.
	 *
	 * Per SEC-007 mitigation: per-email rate limiting raises the cost of
	 * email enumeration even before the authorization check. Verifies that
	 * when the per-IP check passes but the per-email check trips, the
	 * rate-limited response is returned and the service is NOT queried.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function test_handle_status_applies_per_email_rate_limit_after_per_ip(): void {
		$email         = 'test@example.com';
		$occurrence_id = 7;
		$expected_id   = sprintf( 'waitlist_status_email:%s:%d', sha1( $email ), $occurrence_id );
		$limited_resp  = new \WP_REST_Response(
			array(
				'code'    => 'rate_limited',
				'message' => 'Too many requests.',
			),
			429
		);

		$call_log   = array();
		$rate_limit = $this->createMock( RateLimitService::class );
		$rate_limit->method( 'should_bypass' )->willReturn( false );
		$rate_limit->method( 'check_and_increment' )->willReturnCallback(
			function ( ?string $identifier = null ) use ( &$call_log, $expected_id, $limited_resp ) {
				$call_log[] = $identifier;
				if ( $identifier === $expected_id ) {
					return $limited_resp;
				}
				return null;
			}
		);

		// Service must NOT be called when per-email rate limit trips.
		$this->waitlist_service->expects( $this->never() )->method( 'get_position' );

		$controller = new WaitlistRestController(
			$this->waitlist_service,
			$this->occurrence_repo,
			$rate_limit
		);

		$request  = $this->create_request(
			array(
				'occurrence_id' => $occurrence_id,
				'email'         => $email,
			)
		);
		$response = $controller->handle_status( $request );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( array( null, $expected_id ), $call_log, 'Per-IP check must run before per-email check.' );
	}

	/**
	 * Test handle_leave applies per-email rate limit after per-IP rate limit.
	 *
	 * Verifies that when the per-IP check passes but the per-email check trips,
	 * the rate-limited response is returned and the join service is NOT called.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function test_handle_leave_applies_per_email_rate_limit_after_per_ip(): void {
		$email          = 'test@example.com';
		$occurrence_id  = 7;
		$expected_id    = sprintf( 'waitlist_leave_email:%s:%d', sha1( $email ), $occurrence_id );
		$limited_resp   = new \WP_REST_Response(
			array(
				'code'    => 'rate_limited',
				'message' => 'Too many requests.',
			),
			429
		);

		$call_log   = array();
		$rate_limit = $this->createMock( RateLimitService::class );
		$rate_limit->method( 'should_bypass' )->willReturn( false );
		$rate_limit->method( 'check_and_increment' )->willReturnCallback(
			function ( ?string $identifier = null ) use ( &$call_log, $expected_id, $limited_resp ) {
				$call_log[] = $identifier;
				// Per-IP call (no identifier) passes; per-email call returns the limited response.
				if ( $identifier === $expected_id ) {
					return $limited_resp;
				}
				return null;
			}
		);

		// Service must NOT be called when per-email rate limit trips.
		$this->waitlist_service->expects( $this->never() )->method( 'leave' );

		$controller = new WaitlistRestController(
			$this->waitlist_service,
			$this->occurrence_repo,
			$rate_limit
		);

		$request  = $this->create_request(
			array(
				'occurrence_id' => $occurrence_id,
				'email'         => $email,
			)
		);
		$response = $controller->handle_leave( $request );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( array( null, $expected_id ), $call_log, 'Per-IP check must run before per-email check.' );
	}

	/**
	 * Test leave_permission_check denies missing token by default.
	 *
	 * Default base-plugin behavior requires the signed leave token returned by
	 * a successful waitlist join. Extension plugins can still authorize another
	 * proof through the nettertech_events_waitlist_leave_authorized filter.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function test_leave_permission_check_default_denies_missing_token(): void {
		$controller = $this->create_controller();
		$request    = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'test@example.com',
			)
		);

		$result = $controller->leave_permission_check( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test leave_permission_check accepts the token returned by join.
	 *
	 * @return void
	 */
	public function test_leave_permission_check_accepts_join_leave_token(): void {
		$occurrence     = new Occurrence();
		$occurrence->id = 1;

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );

		$entry           = new WaitlistEntry();
		$entry->position = 3;

		$this->waitlist_service->method( 'join' )->willReturn( $entry );

		$controller   = $this->create_controller();
		$join_request = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'test@example.com',
				'name'          => 'Test User',
				'phone'         => null,
			)
		);
		$join_data    = $controller->handle_join( $join_request )->get_data();

		$leave_request = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'test@example.com',
				'token'         => $join_data['leave_token'],
			)
		);

		$this->assertTrue( $controller->leave_permission_check( $leave_request ) );
	}

	/**
	 * Test leave_permission_check rejects a token for a different email.
	 *
	 * @return void
	 */
	public function test_leave_permission_check_rejects_token_for_different_email(): void {
		$occurrence     = new Occurrence();
		$occurrence->id = 1;

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );

		$entry           = new WaitlistEntry();
		$entry->position = 3;

		$this->waitlist_service->method( 'join' )->willReturn( $entry );

		$controller   = $this->create_controller();
		$join_request = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'owner@example.com',
				'name'          => 'Test User',
				'phone'         => null,
			)
		);
		$join_data    = $controller->handle_join( $join_request )->get_data();

		$leave_request = $this->create_request(
			array(
				'occurrence_id' => 1,
				'email'         => 'attacker@example.com',
				'token'         => $join_data['leave_token'],
			)
		);
		$result        = $controller->leave_permission_check( $leave_request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test leave_permission_check returns the WP_Error when filter denies.
	 *
	 * Verifies the filter receives the expected args (false, occurrence_id, email, request)
	 * and that a WP_Error returned by the filter is propagated verbatim.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function test_leave_permission_check_respects_filter_returning_false_or_wp_error(): void {
		$captured_args = array();
		$denied        = new \WP_Error( 'invalid_token', 'Invalid cancellation token.' );

		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value, ...$args ) use ( &$captured_args, $denied ) {
				if ( 'nettertech_events_waitlist_leave_authorized' === $filter ) {
					$captured_args = array_merge( array( $value ), $args );
					return $denied;
				}
				return $value;
			}
		);

		$controller = $this->create_controller();
		$request    = $this->create_request(
			array(
				'occurrence_id' => 42,
				'email'         => 'test@example.com',
			)
		);

		$result = $controller->leave_permission_check( $request );

			$this->assertSame( $denied, $result, 'WP_Error from filter must be returned verbatim.' );
			$this->assertCount( 4, $captured_args, 'Filter must receive 4 args: default, occurrence_id, email, request.' );
			$this->assertFalse( $captured_args[0], 'First arg (default authorized) must be false without a valid token.' );
			$this->assertSame( 42, $captured_args[1], 'Second arg must be occurrence_id.' );
			$this->assertSame( 'test@example.com', $captured_args[2], 'Third arg must be email.' );
			$this->assertSame( $request, $captured_args[3], 'Fourth arg must be the WP_REST_Request.' );
		}

	/**
	 * Test leave_permission_check allows extension filters to authorize another proof.
	 *
	 * @return void
	 */
	public function test_leave_permission_check_allows_filter_override_true(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $value ) {
				if ( 'nettertech_events_waitlist_leave_authorized' === $filter ) {
					return true;
				}
				return $value;
			}
		);

		$controller = $this->create_controller();
		$request    = $this->create_request(
			array(
				'occurrence_id' => 42,
				'email'         => 'test@example.com',
			)
		);

		$this->assertTrue( $controller->leave_permission_check( $request ) );
	}

	/**
	 * Test handle_join fires nettertech_events_waitlist_entry_joined action after successful join.
	 *
	 * Verifies the action fires with payload ($entry, $occurrence_id) once the
	 * service returns successfully — extension plugins use this to mint cancellation
	 * tokens or send confirmation emails without modifying the base plugin.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function test_handle_join_fires_nettertech_events_waitlist_entry_joined_action(): void {
		$occurrence     = new Occurrence();
		$occurrence->id = 9;

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );

		$entry           = new WaitlistEntry();
		$entry->position = 2;

		$this->waitlist_service->method( 'join' )->willReturn( $entry );

		// Capture do_action calls (Brain Monkey's Actions\expectDone conflicts
		// with the base class do_action stub — see OrderRefundProcessorTest pattern).
		$captured_actions = array();
		Functions\when( 'do_action' )->alias(
			function () use ( &$captured_actions ) {
				$captured_actions[] = func_get_args();
			}
		);

		$controller = $this->create_controller();
		$request    = $this->create_request(
			array(
				'occurrence_id' => 9,
				'email'         => 'test@example.com',
				'name'          => 'Test User',
				'phone'         => null,
			)
		);

		$response = $controller->handle_join( $request );

		$this->assertSame( 201, $response->get_status() );

		$joined_actions = array_values(
			array_filter(
				$captured_actions,
				fn( $args ) => 'nettertech_events_waitlist_entry_joined' === $args[0]
			)
		);

		$this->assertCount( 1, $joined_actions, 'nettertech_events_waitlist_entry_joined must fire exactly once.' );
		$this->assertSame( $entry, $joined_actions[0][1], 'Action payload arg 1 must be the WaitlistEntry.' );
		$this->assertSame( 9, $joined_actions[0][2], 'Action payload arg 2 must be the occurrence_id.' );
	}
}
