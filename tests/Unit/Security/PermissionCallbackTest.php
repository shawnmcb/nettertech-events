<?php
/**
 * Permission callback security tests.
 *
 * Tests that REST API endpoints have proper permission callbacks
 * that enforce access control correctly.
 *
 * @package NetterTechEvents\Tests\Unit\Security
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Security;

use Brain\Monkey\Functions;
use NetterTechEvents\API\EventsController;
use NetterTechEvents\API\EventsAdminController;
use NetterTechEvents\API\ICalController;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Services\RateLimitService;

/**
 * Test permission callbacks for REST API endpoints.
 *
 * These tests ensure that:
 * - Public endpoints are accessible without authentication
 * - Admin endpoints require proper capabilities
 * - No endpoints use __return_true directly
 *
 * @covers \NetterTechEvents\API\ICalController::public_feed_permission_check
 * @covers \NetterTechEvents\API\ICalController::import_permission_check
 * @covers \NetterTechEvents\API\EventsController::public_events_permission_check
 * @covers \NetterTechEvents\API\EventsAdminController::admin_permissions_check
 */
class PermissionCallbackTest extends \NetterTechEventsTestCase {

	/**
	 * ICalController instance.
	 *
	 * @var ICalController
	 */
	private ICalController $ical_controller;

	/**
	 * EventsController instance.
	 *
	 * @var EventsController
	 */
	private EventsController $events_controller;

	/**
	 * EventsAdminController instance.
	 *
	 * @var EventsAdminController
	 */
	private EventsAdminController $events_admin_controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset ServiceRegistry between tests.
		ServiceRegistry::reset();

		// Mock WordPress functions.
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( intval( $value ) );
			}
		);
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );

		// Create mock dependencies for controllers.
		$event_repo      = $this->createMock( \NetterTechEvents\Contracts\EventRepositoryInterface::class );
		$occurrence_repo = $this->createMock( \NetterTechEvents\Contracts\OccurrenceRepositoryInterface::class );
		$ticket_type_repo = $this->createMock( \NetterTechEvents\Contracts\TicketTypeRepositoryInterface::class );
		$capacity_service = $this->createMock( \NetterTechEvents\Contracts\CapacityServiceInterface::class );
		$activity_log     = $this->createMock( \NetterTechEvents\Contracts\ActivityLogServiceInterface::class );

		// Create controller instances.
		$ical_service  = $this->createMock( \NetterTechEvents\Services\ICalService::class );
		$rate_limit    = $this->createMock( RateLimitService::class );
		$rate_limit->method( 'should_bypass' )->willReturn( true );

		$this->ical_controller         = new ICalController( $event_repo, $occurrence_repo, $ical_service, $rate_limit );
		$this->events_controller       = new EventsController( $occurrence_repo, $ticket_type_repo, $capacity_service, $rate_limit );
		$this->events_admin_controller = new EventsAdminController( $event_repo, $activity_log, $rate_limit );
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

	// =========================================================================
	// ICalController Public Endpoint Tests (T4.1.2)
	// =========================================================================

	/**
	 * Test ICalController public feed permission callback returns true.
	 *
	 * @return void
	 */
	public function test_ical_public_feed_permission_allows_access(): void {
		$result = $this->ical_controller->public_feed_permission_check();

		$this->assertTrue( $result );
	}

	/**
	 * Test ICalController public feed permission is explicit method not __return_true.
	 *
	 * This ensures we have explicit permission methods for documentation and auditability.
	 *
	 * @return void
	 */
	public function test_ical_public_feed_permission_is_explicit_method(): void {
		$this->assertTrue(
			method_exists( $this->ical_controller, 'public_feed_permission_check' ),
			'ICalController must have explicit public_feed_permission_check method'
		);
	}

	/**
	 * Test ICalController public feed permission method has PHPDoc.
	 *
	 * Explicit permission callbacks should document why access is allowed.
	 *
	 * @return void
	 */
	public function test_ical_public_feed_permission_has_documentation(): void {
		$reflection = new \ReflectionMethod( $this->ical_controller, 'public_feed_permission_check' );
		$docComment = $reflection->getDocComment();

		$this->assertNotFalse( $docComment, 'public_feed_permission_check should have PHPDoc' );
		$this->assertStringContainsString( '@since', $docComment, 'PHPDoc should include @since tag' );
	}

	// =========================================================================
	// EventsController Public Endpoint Tests (T4.1.3)
	// =========================================================================

	/**
	 * Test EventsController public events permission callback returns true.
	 *
	 * @return void
	 */
	public function test_events_public_permission_allows_access(): void {
		$result = $this->events_controller->public_events_permission_check();

		$this->assertTrue( $result );
	}

	/**
	 * Test EventsController public events permission is explicit method.
	 *
	 * @return void
	 */
	public function test_events_public_permission_is_explicit_method(): void {
		$this->assertTrue(
			method_exists( $this->events_controller, 'public_events_permission_check' ),
			'EventsController must have explicit public_events_permission_check method'
		);
	}

	/**
	 * Test EventsController public events permission method has PHPDoc.
	 *
	 * @return void
	 */
	public function test_events_public_permission_has_documentation(): void {
		$reflection = new \ReflectionMethod( $this->events_controller, 'public_events_permission_check' );
		$docComment = $reflection->getDocComment();

		$this->assertNotFalse( $docComment, 'public_events_permission_check should have PHPDoc' );
		$this->assertStringContainsString( '@since', $docComment, 'PHPDoc should include @since tag' );
	}

	/**
	 * Test EventsController has 4 public endpoints using permission callback.
	 *
	 * @return void
	 */
	public function test_events_controller_uses_explicit_permission_callbacks(): void {
		$reflection = new \ReflectionClass( EventsController::class );
		$source     = file_get_contents( $reflection->getFileName() );

		// Count occurrences of the permission callback assignment.
		$count = substr_count( $source, "'permission_callback' => array( \$this, 'public_events_permission_check' )" );

		$this->assertGreaterThanOrEqual(
			4,
			$count,
			'EventsController should use public_events_permission_check for at least 4 endpoints'
		);
	}

	// =========================================================================
	// Admin Endpoint Tests (T4.1.4)
	// =========================================================================

	/**
	 * Test ICalController import requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_ical_import_requires_admin_capability(): void {
		// Mock current_user_can to return false (not logged in / no capability).
		Functions\when( 'current_user_can' )->alias(
			function ( string $capability ): bool {
				return 'manage_options' !== $capability;
			}
		);

		$event_repo      = $this->createMock( \NetterTechEvents\Contracts\EventRepositoryInterface::class );
		$occurrence_repo = $this->createMock( \NetterTechEvents\Contracts\OccurrenceRepositoryInterface::class );
		$ical_service    = $this->createMock( \NetterTechEvents\Services\ICalService::class );
		$rate_limit      = $this->createMock( RateLimitService::class );
		$controller      = new ICalController( $event_repo, $occurrence_repo, $ical_service, $rate_limit );
		$result          = $controller->import_permission_check();

		$this->assertFalse( $result, 'Import should be denied without manage_options' );
	}

	/**
	 * Test ICalController import allows access with manage_options.
	 *
	 * @return void
	 */
	public function test_ical_import_allows_admin_access(): void {
		// Mock current_user_can to return true (admin user).
		Functions\when( 'current_user_can' )->alias(
			function ( string $capability ): bool {
				return 'manage_options' === $capability;
			}
		);

		$event_repo      = $this->createMock( \NetterTechEvents\Contracts\EventRepositoryInterface::class );
		$occurrence_repo = $this->createMock( \NetterTechEvents\Contracts\OccurrenceRepositoryInterface::class );
		$ical_service    = $this->createMock( \NetterTechEvents\Services\ICalService::class );
		$rate_limit      = $this->createMock( RateLimitService::class );
		$controller      = new ICalController( $event_repo, $occurrence_repo, $ical_service, $rate_limit );
		$result          = $controller->import_permission_check();

		$this->assertTrue( $result, 'Import should be allowed with manage_options' );
	}

	/**
	 * Test EventsAdminController requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_events_admin_requires_admin_capability(): void {
		// Mock current_user_can to return false.
		Functions\when( 'current_user_can' )->alias(
			function ( string $capability ): bool {
				return 'manage_options' !== $capability;
			}
		);

		$event_repo   = $this->createMock( \NetterTechEvents\Contracts\EventRepositoryInterface::class );
		$activity_log = $this->createMock( \NetterTechEvents\Contracts\ActivityLogServiceInterface::class );
		$rate_limit   = $this->createMock( RateLimitService::class );
		$controller   = new EventsAdminController( $event_repo, $activity_log, $rate_limit );
		$request      = $this->createMock( \WP_REST_Request::class );
		$result       = $controller->admin_permissions_check( $request );

		// Returns WP_Error when denied.
		$this->assertInstanceOf( \WP_Error::class, $result, 'Admin endpoints should return WP_Error without manage_options' );
	}

	/**
	 * Test EventsAdminController allows access with manage_options.
	 *
	 * @return void
	 */
	public function test_events_admin_allows_admin_access(): void {
		// Mock current_user_can to return true.
		Functions\when( 'current_user_can' )->alias(
			function ( string $capability ): bool {
				return 'manage_options' === $capability;
			}
		);

		$event_repo   = $this->createMock( \NetterTechEvents\Contracts\EventRepositoryInterface::class );
		$activity_log = $this->createMock( \NetterTechEvents\Contracts\ActivityLogServiceInterface::class );
		$rate_limit   = $this->createMock( RateLimitService::class );
		$controller   = new EventsAdminController( $event_repo, $activity_log, $rate_limit );
		$request      = $this->createMock( \WP_REST_Request::class );
		$result       = $controller->admin_permissions_check( $request );

		$this->assertTrue( $result, 'Admin endpoints should be allowed with manage_options' );
	}

	// =========================================================================
	// Security Pattern Tests
	// =========================================================================

	/**
	 * Test no API controllers use __return_true directly.
	 *
	 * All permission callbacks should be explicit methods for auditability.
	 *
	 * @return void
	 */
	public function test_no_return_true_permission_callbacks(): void {
		$api_files = glob( NETTERTECH_EVENTS_PLUGIN_DIR . 'includes/API/*.php' );

		foreach ( $api_files as $file ) {
			$content  = file_get_contents( $file );
			$filename = basename( $file );

			// Check for __return_true as permission callback.
			$this->assertStringNotContainsString(
				"'permission_callback' => '__return_true'",
				$content,
				"{$filename} should not use __return_true as permission callback"
			);

			// Also check without quotes.
			$this->assertStringNotContainsString(
				'permission_callback' . " => '__return_true'",
				$content,
				"{$filename} should not use __return_true as permission callback"
			);
		}
	}

	/**
	 * Test all registered routes have permission_callback defined.
	 *
	 * This test examines the source code to ensure no routes are registered
	 * without explicit permission callbacks.
	 *
	 * @return void
	 */
	public function test_all_routes_have_permission_callbacks(): void {
		$api_files = glob( NETTERTECH_EVENTS_PLUGIN_DIR . 'includes/API/*.php' );

		foreach ( $api_files as $file ) {
			$content  = file_get_contents( $file );
			$filename = basename( $file );

			// Count register_rest_route calls.
			$route_count = substr_count( $content, 'register_rest_route(' );
			if ( 0 === $route_count ) {
				continue;
			}

			// Count permission_callback assignments.
			$permission_count = substr_count( $content, "'permission_callback'" );

			// Each route registration should have at least one permission callback.
			$this->assertGreaterThanOrEqual(
				$route_count,
				$permission_count,
				"{$filename} has {$route_count} routes but only {$permission_count} permission callbacks"
			);
		}
	}
}
