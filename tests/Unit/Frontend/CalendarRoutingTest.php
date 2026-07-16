<?php
/**
 * CalendarRouting unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use Brain\Monkey\Functions;
use NetterTechEvents\Frontend\CalendarRouting;

/**
 * Test CalendarRouting service — custom query vars + legacy URL redirect.
 *
 * @since 1.0.2
 * @coversDefaultClass \NetterTechEvents\Frontend\CalendarRouting
 */
class CalendarRoutingTest extends \NetterTechEventsTestCase {

	/**
	 * Service under test.
	 *
	 * @var CalendarRouting
	 */
	private CalendarRouting $routing;

	/**
	 * Set up per-test state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->routing = new CalendarRouting();

		// Clear any prior superglobal state.
		$_GET = array();
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	/**
	 * @covers ::add_query_vars
	 */
	public function test_add_query_vars_registers_calendar_vars(): void {
		$vars = $this->routing->add_query_vars( array( 'existing' ) );

		$this->assertContains( 'existing', $vars );
		$this->assertContains( CalendarRouting::QUERY_VAR_MONTH, $vars );
		$this->assertContains( CalendarRouting::QUERY_VAR_DATE, $vars );
	}

	/**
	 * @covers ::add_query_vars
	 */
	public function test_query_var_constants_are_prefixed(): void {
		$this->assertSame( 'nettertech_events_calendar_month', CalendarRouting::QUERY_VAR_MONTH );
		$this->assertSame( 'nettertech_events_calendar_date', CalendarRouting::QUERY_VAR_DATE );
	}

	/**
	 * @covers ::maybe_redirect_legacy_query
	 */
	public function test_redirect_skipped_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$_GET = array( 'month' => '2024-06' );

		$redirected = false;
		Functions\when( 'wp_safe_redirect' )->alias(
			function () use ( &$redirected ) {
				$redirected = true;
				return true;
			}
		);

		$this->routing->maybe_redirect_legacy_query();

		$this->assertFalse( $redirected );
	}

	/**
	 * @covers ::maybe_redirect_legacy_query
	 */
	public function test_redirect_skipped_when_no_calendar_shortcode_on_page(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_post' )->justReturn( null );
		$_GET = array( 'month' => '2024-06' );

		$redirected = false;
		Functions\when( 'wp_safe_redirect' )->alias(
			function () use ( &$redirected ) {
				$redirected = true;
				return true;
			}
		);

		$this->routing->maybe_redirect_legacy_query();

		$this->assertFalse( $redirected );
	}

	/**
	 * @covers ::maybe_redirect_legacy_query
	 */
	public function test_redirect_skipped_for_malformed_month_value(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$post              = new \stdClass();
		$post->post_content = '[nettertech_events_calendar]';
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'has_shortcode' )->justReturn( true );

		$_GET = array( 'month' => 'not-a-date' );

		$redirected = false;
		Functions\when( 'wp_safe_redirect' )->alias(
			function () use ( &$redirected ) {
				$redirected = true;
				return true;
			}
		);
		Functions\when( 'add_query_arg' )->returnArg();
		Functions\when( 'remove_query_arg' )->justReturn( '/calendar/' );

		$this->routing->maybe_redirect_legacy_query();

		$this->assertFalse( $redirected );
	}
}
