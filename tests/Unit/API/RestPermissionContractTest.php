<?php
/**
 * REST permission_callback contract test.
 *
 * Asserts that every register_rest_route() call in includes/API/ has a
 * permission_callback that is not __return_true (unless the route is on
 * the public allowlist). Prevents silent regression as new routes are added.
 *
 * @package NetterTechEvents\Tests\Unit\API
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\API;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\API\AttendeeFieldsController;
use NetterTechEvents\API\AttendeesAdminController;
use NetterTechEvents\API\EventsAdminController;
use NetterTechEvents\API\EventsController;
use NetterTechEvents\API\ICalController;
use NetterTechEvents\API\TicketTypesAdminController;
use NetterTechEvents\API\WaitlistRestController;
use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Contracts\WaitlistServiceInterface;
use NetterTechEvents\Services\RateLimitService;

/**
 * Contract test: every REST route must have a permission_callback that is not
 * __return_true (unless explicitly allowlisted as intentionally public).
 *
 * Public endpoint: routes in PUBLIC_ROUTE_PATTERNS are intentionally open
 * (read-only, rate-limited, no privileged data) — their controllers use named
 * permission methods that always return true, which is acceptable WP behaviour
 * for genuinely public endpoints. __return_true itself is rejected because
 * it signals a forgotten callback rather than a deliberate choice.
 *
 * @group structural
 */
class RestPermissionContractTest extends \NetterTechEventsTestCase {

	/**
	 * Partial route patterns that are intentionally public.
	 *
	 * These routes serve public-facing data (event listings, iCal feeds) and
	 * are rate-limited via named permission methods on their controllers, not
	 * via __return_true. Listing them here documents the deliberate decision.
	 *
	 * Pattern matching: a captured route key is allowlisted if it contains any
	 * of these substrings.
	 *
	 * @var array<int, string>
	 */
	private const PUBLIC_ROUTE_PATTERNS = array(
		'events/upcoming',
		'events/range',
		'/occurrences',
		'events/(?P<id>',
		'ical/feed',
		'ical/event/',
		'ical/occurrence/',
		'events/(?P<event_id>',  // attendee-fields GET (check-in app)
		'waitlist/join',
		'waitlist/status',
		'waitlist/leave',
	);

	/**
	 * Routes captured from register_rest_route() calls.
	 *
	 * Key: "{namespace}{route}", Value: array of method configs.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $captured_routes = array();

	/**
	 * Set up: mock WP functions, capture register_rest_route calls.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->captured_routes = array();

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
				return preg_replace( '/[^a-zA-Z0-9._-]/', '-', (string) $name );
			}
		);
		Functions\when( 'rest_url' )->returnArg( 1 );

		// Capture every register_rest_route() call rather than executing it.
		$captured = &$this->captured_routes;
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) use ( &$captured ) {
				$key = $namespace . $route;
				// Args may be a flat method config or an indexed array of method configs.
				if ( isset( $args['methods'] ) ) {
					$captured[ $key ][] = $args;
				} else {
					foreach ( $args as $config ) {
						if ( is_array( $config ) && isset( $config['methods'] ) ) {
							$captured[ $key ][] = $config;
						}
					}
				}
			}
		);
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->captured_routes = array();
		parent::tearDown();
	}

	/**
	 * Register routes from all API controllers.
	 *
	 * @return void
	 */
	private function register_all_routes(): void {
		$rate_limit = Mockery::mock( RateLimitService::class );
		$rate_limit->shouldReceive( 'should_bypass' )->andReturn( true )->byDefault();
		$rate_limit->shouldReceive( 'add_headers' )->andReturnUsing(
			function ( $response ) {
				return $response;
			}
		)->byDefault();

		// EventsController.
		( new EventsController(
			Mockery::mock( OccurrenceRepositoryInterface::class ),
			Mockery::mock( TicketTypeRepositoryInterface::class ),
			Mockery::mock( CapacityServiceInterface::class ),
			$rate_limit
		) )->register_routes();

		// ICalController.
		( new ICalController(
			Mockery::mock( EventRepositoryInterface::class ),
			Mockery::mock( OccurrenceRepositoryInterface::class ),
			Mockery::mock( \NetterTechEvents\Services\ICalService::class ),
			$rate_limit
		) )->register_routes();

		// AttendeeFieldsController.
		( new AttendeeFieldsController(
			Mockery::mock( AttendeeFieldRepositoryInterface::class ),
			Mockery::mock( AttendeeFieldValueRepositoryInterface::class ),
			$rate_limit
		) )->register_routes();

		// AttendeesAdminController: the constructor assigns $attendee_repo to both
		// $attendee_repo (AttendeeRepositoryInterface) and $attendee_search
		// (AttendeeSearchInterface) via a phpstan-ignore cast, so we must pass a
		// concrete mock that satisfies both typed properties.
		$attendee_repo_mock = $this->getMockBuilder( AttendeeRepository::class )
			->disableOriginalConstructor()
			->getMock();
		( new AttendeesAdminController(
			$attendee_repo_mock,
			Mockery::mock( ActivityLogServiceInterface::class ),
			$rate_limit
		) )->register_routes();

		// EventsAdminController.
		( new EventsAdminController(
			Mockery::mock( EventRepositoryInterface::class ),
			Mockery::mock( ActivityLogServiceInterface::class ),
			$rate_limit
		) )->register_routes();

		// TicketTypesAdminController.
		( new TicketTypesAdminController(
			Mockery::mock( TicketTypeRepositoryInterface::class ),
			Mockery::mock( ActivityLogServiceInterface::class ),
			$rate_limit
		) )->register_routes();

		// WaitlistRestController.
		( new WaitlistRestController(
			Mockery::mock( WaitlistServiceInterface::class ),
			Mockery::mock( OccurrenceRepositoryInterface::class ),
			$rate_limit
		) )->register_routes();
	}

	/**
	 * Determine whether a route key matches one of the public route patterns.
	 *
	 * @param string $route_key Full route key (namespace + route).
	 * @return bool
	 */
	private function is_public_route( string $route_key ): bool {
		foreach ( self::PUBLIC_ROUTE_PATTERNS as $pattern ) {
			if ( str_contains( $route_key, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every route must have a permission_callback — missing or null is rejected.
	 *
	 * @return void
	 */
	public function test_every_route_has_permission_callback(): void {
		$this->register_all_routes();

		$this->assertNotEmpty( $this->captured_routes, 'No routes were captured — register_all_routes() may have failed.' );

		foreach ( $this->captured_routes as $route_key => $method_configs ) {
			foreach ( $method_configs as $config ) {
				$this->assertArrayHasKey(
					'permission_callback',
					$config,
					sprintf( 'Route "%s" (method: %s) is missing permission_callback.', $route_key, $config['methods'] ?? 'unknown' )
				);

				$this->assertNotNull(
					$config['permission_callback'],
					sprintf( 'Route "%s" (method: %s) has null permission_callback.', $route_key, $config['methods'] ?? 'unknown' )
				);

				$this->assertNotFalse(
					$config['permission_callback'],
					sprintf( 'Route "%s" (method: %s) has false permission_callback.', $route_key, $config['methods'] ?? 'unknown' )
				);
			}
		}
	}

	/**
	 * Non-allowlisted routes must not use __return_true as permission_callback.
	 *
	 * @return void
	 */
	public function test_non_public_routes_do_not_use_return_true(): void {
		$this->register_all_routes();

		$this->assertNotEmpty( $this->captured_routes, 'No routes were captured — register_all_routes() may have failed.' );

		foreach ( $this->captured_routes as $route_key => $method_configs ) {
			if ( $this->is_public_route( $route_key ) ) {
				continue;
			}

			foreach ( $method_configs as $config ) {
				$callback = $config['permission_callback'] ?? null;

				$this->assertNotEquals(
					'__return_true',
					$callback,
					sprintf(
						'Route "%s" (method: %s) uses __return_true as permission_callback. ' .
						'Use a named method with explicit capability check, or add to PUBLIC_ROUTE_PATTERNS with rationale.',
						$route_key,
						$config['methods'] ?? 'unknown'
					)
				);
			}
		}
	}

	/**
	 * Non-allowlisted routes must use a named method callback on the controller,
	 * not a bare string (which could bypass intent review).
	 *
	 * @return void
	 */
	public function test_non_public_routes_use_named_method_callbacks(): void {
		$this->register_all_routes();

		$this->assertNotEmpty( $this->captured_routes, 'No routes were captured — register_all_routes() may have failed.' );

		foreach ( $this->captured_routes as $route_key => $method_configs ) {
			if ( $this->is_public_route( $route_key ) ) {
				continue;
			}

			foreach ( $method_configs as $config ) {
				$callback = $config['permission_callback'] ?? null;

				$this->assertIsArray(
					$callback,
					sprintf(
						'Route "%s" (method: %s) permission_callback must be array( $controller, "method_name" ), got: %s',
						$route_key,
						$config['methods'] ?? 'unknown',
						gettype( $callback )
					)
				);

				$this->assertCount(
					2,
					$callback,
					sprintf( 'Route "%s" permission_callback array must have exactly 2 elements [object, method_name].', $route_key )
				);

				$this->assertIsString(
					$callback[1],
					sprintf( 'Route "%s" permission_callback method name (index 1) must be a string.', $route_key )
				);

				$this->assertNotEmpty(
					$callback[1],
					sprintf( 'Route "%s" permission_callback method name must not be empty.', $route_key )
				);
			}
		}
	}

	/**
	 * Public routes must also have a non-null, non-false permission_callback
	 * (named method is acceptable; __return_true is also accepted here but
	 * should be avoided in favour of named rate-limited methods).
	 *
	 * @return void
	 */
	public function test_public_routes_have_permission_callback(): void {
		$this->register_all_routes();

		$this->assertNotEmpty( $this->captured_routes, 'No routes were captured — register_all_routes() may have failed.' );

		foreach ( $this->captured_routes as $route_key => $method_configs ) {
			if ( ! $this->is_public_route( $route_key ) ) {
				continue;
			}

			foreach ( $method_configs as $config ) {
				$this->assertArrayHasKey(
					'permission_callback',
					$config,
					sprintf( 'Public route "%s" (method: %s) is missing permission_callback.', $route_key, $config['methods'] ?? 'unknown' )
				);

				$this->assertNotNull(
					$config['permission_callback'],
					sprintf( 'Public route "%s" (method: %s) has null permission_callback.', $route_key, $config['methods'] ?? 'unknown' )
				);

				$this->assertNotFalse(
					$config['permission_callback'],
					sprintf( 'Public route "%s" (method: %s) has false permission_callback.', $route_key, $config['methods'] ?? 'unknown' )
				);
			}
		}
	}

	/**
	 * Smoke test: the expected set of controllers registers routes at all.
	 *
	 * Catches a future refactor that silently removes register_routes() calls.
	 *
	 * @return void
	 */
	public function test_expected_route_count_is_met(): void {
		$this->register_all_routes();

		// 20 register_rest_route() calls across all 7 controllers.
		// Update this count whenever a controller gains or loses a route.
		$this->assertCount(
			20,
			$this->captured_routes,
			sprintf(
				'Expected 20 distinct route keys from all API controllers, got %d. ' .
				'If a route was added or removed intentionally, update this count.',
				count( $this->captured_routes )
			)
		);
	}
}
