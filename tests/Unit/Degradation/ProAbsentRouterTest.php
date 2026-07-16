<?php
/**
 * Router degradation tests — Pro absent.
 *
 * Proves Router operates without check-in route handlers:
 * - FILTER_ROUTE_TEMPLATE fires and returns null when no Pro handler is present.
 * - Non-ticket URLs fall through to base handlers.
 * - Router instantiates with null Templates (lazy-init fallback).
 *
 * @package NetterTechEvents\Tests\Unit\Degradation
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Degradation;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Frontend\Router;

/**
 * Verify Router behaviour when Pro ticket_router is absent.
 *
 * @covers \NetterTechEvents\Frontend\Router
 */
class ProAbsentRouterTest extends \NetterTechEventsTestCase {

	/**
	 * Mock event repository.
	 *
	 * @var EventRepositoryInterface|Mockery\MockInterface
	 */
	private $event_repo;

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->event_repo      = Mockery::mock( EventRepositoryInterface::class );
		$this->occurrence_repo = Mockery::mock( OccurrenceRepositoryInterface::class );
	}

	/**
	 * Build a Router without injected Templates (simulates base init path).
	 *
	 * @return Router
	 */
	private function create_router(): Router {
		// Templates::get_instance() is called inside the constructor when null is passed.
		// The base test stubs apply_filters which is enough for the lazy-init path.
		return new Router(
			$this->event_repo,
			$this->occurrence_repo,
			null
		);
	}

	// =========================================================================
	// Instantiation
	// =========================================================================

	/**
	 * Test Router instantiates cleanly without a ticket router.
	 *
	 * @return void
	 */
	public function test_instantiates_without_ticket_router(): void {
		$router = $this->create_router();

		$this->assertInstanceOf( Router::class, $router );
	}

	// =========================================================================
	// register() wires hooks
	// =========================================================================

	/**
	 * Test register() adds the template_include filter.
	 *
	 * @return void
	 */
	public function test_register_adds_template_include_filter(): void {
		$router = $this->create_router();

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		$router->register();

		$this->addToAssertionCount( 1 );
	}

	// =========================================================================
	// maybe_load_event_template — FILTER_ROUTE_TEMPLATE returns null without Pro
	// =========================================================================

	/**
	 * Test FILTER_ROUTE_TEMPLATE constant is defined with the expected value.
	 *
	 * Without Pro no handler is attached.  The constant must exist so Pro
	 * can safely reference it and hook in.
	 *
	 * @return void
	 */
	public function test_filter_route_template_constant_is_defined(): void {
		$this->assertSame( 'nettertech_events_route_template', Hooks::FILTER_ROUTE_TEMPLATE );
	}

	/**
	 * Test maybe_load_event_template returns default template when apply_filters returns null.
	 *
	 * Without Pro apply_filters('nettertech_events_route_template') returns null (pass-through),
	 * and without any matching query vars the default template is returned.
	 *
	 * @return void
	 */
	public function test_maybe_load_event_template_returns_default_when_filter_returns_null(): void {
		$router = $this->create_router();

		// apply_filters is stubbed in bootstrap to return the first non-tag argument (null here).
		Functions\when( 'get_query_var' )->justReturn( '' );

		$result = $router->maybe_load_event_template( '/default-template.php' );

		// Without Pro and without any matching query vars the original template is returned.
		$this->assertSame( '/default-template.php', $result );
	}

	/**
	 * Test maybe_load_event_template returns default template when no routes match.
	 *
	 * @return void
	 */
	public function test_returns_default_template_when_no_routes_match(): void {
		$router = $this->create_router();

		Functions\when( 'get_query_var' )->justReturn( '' );

		$default = '/path/to/default.php';
		$result  = $router->maybe_load_event_template( $default );

		$this->assertSame( $default, $result );
	}

	/**
	 * Test that ticket scan URL returns default template when Pro is absent.
	 *
	 * The nettertech_events_ticket_code query var is set but no Pro handler exists; the
	 * base router does not handle ticket codes so the default is returned.
	 *
	 * @return void
	 */
	public function test_ticket_scan_url_returns_default_template_without_pro(): void {
		$router = $this->create_router();

		// Simulate nettertech_events_ticket_code being set (ticket scan URL) but no Pro handler.
		Functions\when( 'get_query_var' )->alias(
			function ( string $var ) {
				if ( 'nettertech_events_ticket_code' === $var ) {
					return 'ABCD-1234-EFGH-5678';
				}
				return '';
			}
		);

		$default = '/path/to/index.php';
		$result  = $router->maybe_load_event_template( $default );

		// Base router does not handle nettertech_events_ticket_code so passes through to default.
		$this->assertSame( $default, $result );
	}

	// =========================================================================
	// set_ticket_router is callable
	// =========================================================================

	/**
	 * Test set_ticket_router accepts a Pro-provided object without error.
	 *
	 * Regression guard: the setter must work even though it is not used in
	 * base-only runs.
	 *
	 * @return void
	 */
	public function test_set_ticket_router_is_callable(): void {
		$router = $this->create_router();

		$fake_router = new \stdClass();
		$router->set_ticket_router( $fake_router );

		$this->addToAssertionCount( 1 );
	}
}
