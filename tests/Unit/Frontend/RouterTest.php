<?php
/**
 * Router unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use NetterTechEvents\Frontend\Router;
use NetterTechEvents\Frontend\EventTemplateResolver;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Models\Event;
use NetterTechEvents\TemplateLoader\Templates;

/**
 * Test Router class structure.
 *
 * Note: Full integration testing requires WordPress environment.
 * These tests verify class structure and method existence.
 */
class RouterTest extends \NetterTechEventsTestCase {

	/**
	 * Mock event repository.
	 *
	 * @var EventRepositoryInterface|\Mockery\MockInterface
	 */
	private $event_repo_mock;

	/**
	 * Mock occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface|\Mockery\MockInterface
	 */
	private $occurrence_repo_mock;

	/**
	 * Router instance.
	 *
	 * @var Router
	 */
	private Router $router;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->event_repo_mock      = \Mockery::mock( EventRepositoryInterface::class );
		$this->occurrence_repo_mock = \Mockery::mock( OccurrenceRepositoryInterface::class );

		$this->router = new Router(
			$this->event_repo_mock,
			$this->occurrence_repo_mock
		);

		// Reset singleton to fresh instance for each test.
		Router::set_instance( $this->router );
	}

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test Router class exists.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( Router::class ) );
	}

	/**
	 * Test can be instantiated with repositories.
	 *
	 * @return void
	 */
	public function test_can_instantiate_with_repositories(): void {
		$this->assertInstanceOf( Router::class, $this->router );
	}

	/**
	 * Test can be instantiated with different repository instances.
	 *
	 * @return void
	 */
	public function test_can_instantiate_with_different_repositories(): void {
		$event_repo      = \Mockery::mock( EventRepositoryInterface::class );
		$occurrence_repo = \Mockery::mock( OccurrenceRepositoryInterface::class );
		$router          = new Router( $event_repo, $occurrence_repo );
		$this->assertInstanceOf( Router::class, $router );
	}

	/**
	 * Test required methods exist.
	 *
	 * @return void
	 */
	public function test_required_methods_exist(): void {
		$methods = array(
			'register',
			'register_rewrite_rules',
			'maybe_flush_rewrite_rules',
			'add_query_vars',
			'maybe_load_event_template',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( Router::class, $method ),
				"Method {$method} should exist"
			);
		}
	}

	/**
	 * Test register method is public.
	 *
	 * @return void
	 */
	public function test_register_is_public(): void {
		$reflection = new \ReflectionMethod( Router::class, 'register' );
		$this->assertTrue( $reflection->isPublic() );
	}

	/**
	 * Test register method returns void.
	 *
	 * @return void
	 */
	public function test_register_returns_void(): void {
		$reflection  = new \ReflectionMethod( Router::class, 'register' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type );
		$this->assertEquals( 'void', $return_type->getName() );
	}

	// =========================================================================
	// add_query_vars Tests
	// =========================================================================

	/**
	 * Test add_query_vars adds custom query vars.
	 *
	 * @return void
	 */
	public function test_add_query_vars_adds_custom_vars(): void {
		$vars = array( 'existing_var' );

		$result = $this->router->add_query_vars( $vars );

		$this->assertContains( 'nettertech_events_event_slug', $result );
		$this->assertContains( 'nettertech_events_occurrence_datetime', $result );
		$this->assertContains( 'nettertech_events_archive', $result );
		$this->assertContains( 'nettertech_events_past_archive', $result );
		$this->assertContains( 'existing_var', $result );
	}

	/**
	 * Test add_query_vars preserves existing vars.
	 *
	 * @return void
	 */
	public function test_add_query_vars_preserves_existing(): void {
		$vars = array( 'foo', 'bar', 'baz' );

		$result = $this->router->add_query_vars( $vars );

		$this->assertContains( 'foo', $result );
		$this->assertContains( 'bar', $result );
		$this->assertContains( 'baz', $result );
	}

	// =========================================================================
	// Repository Accessor Tests
	// =========================================================================

	/**
	 * Test get_event_repository returns repository.
	 *
	 * @return void
	 */
	public function test_get_event_repository_returns_repository(): void {
		$repo = $this->router->get_event_repository();

		$this->assertInstanceOf( EventRepositoryInterface::class, $repo );
	}

	/**
	 * Test get_occurrence_repository returns repository.
	 *
	 * @return void
	 */
	public function test_get_occurrence_repository_returns_repository(): void {
		$repo = $this->router->get_occurrence_repository();

		$this->assertInstanceOf( OccurrenceRepositoryInterface::class, $repo );
	}

	// =========================================================================
	// Static Accessor Tests
	// =========================================================================

	/**
	 * Test set_instance and instance work correctly.
	 *
	 * @return void
	 */
	public function test_set_instance_and_instance_work(): void {
		Router::set_instance( $this->router );

		$instance = Router::instance();

		$this->assertSame( $this->router, $instance );
	}

	/**
	 * Test get_current_event returns null initially.
	 *
	 * @return void
	 */
	public function test_get_current_event_returns_null_initially(): void {
		Router::set_instance( $this->router );

		$event = Router::get_current_event();

		$this->assertNull( $event );
	}

	/**
	 * Test get_current_occurrence returns null initially.
	 *
	 * @return void
	 */
	public function test_get_current_occurrence_returns_null_initially(): void {
		Router::set_instance( $this->router );

		$occurrence = Router::get_current_occurrence();

		$this->assertNull( $occurrence );
	}

	/**
	 * Test output_occurrence_canonical emits the series URL as canonical (NTE-076).
	 *
	 * @return void
	 */
	public function test_output_occurrence_canonical_emits_series_url(): void {
		\Brain\Monkey\Functions\when( 'esc_url' )->returnArg();

		$event = \Mockery::mock( Event::class );
		$event->shouldReceive( 'get_series_url' )->andReturn( 'https://example.test/events/harp/' );

		$property = new \ReflectionProperty( Router::class, 'current_event' );
		$property->setValue( $this->router, $event );

		ob_start();
		$this->router->output_occurrence_canonical();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<link rel="canonical"', $output );
		$this->assertStringContainsString( 'https://example.test/events/harp/', $output );
	}

	/**
	 * Test output_occurrence_canonical is a no-op when no event is being viewed.
	 *
	 * @return void
	 */
	public function test_output_occurrence_canonical_noop_without_event(): void {
		ob_start();
		$this->router->output_occurrence_canonical();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Test is_series_page returns false initially.
	 *
	 * @return void
	 */
	public function test_is_series_page_returns_false_initially(): void {
		Router::set_instance( $this->router );

		$is_series = Router::is_series_page();

		$this->assertFalse( $is_series );
	}

	/**
	 * Test static methods exist.
	 *
	 * @return void
	 */
	public function test_static_methods_exist(): void {
		$static_methods = array(
			'instance',
			'set_instance',
			'get_current_event',
			'get_current_occurrence',
			'is_series_page',
		);

		foreach ( $static_methods as $method ) {
			$reflection = new \ReflectionMethod( Router::class, $method );
			$this->assertTrue(
				$reflection->isStatic(),
				"Method {$method} should be static"
			);
		}
	}

	// =========================================================================
	// get_current_checkin_token Tests
	// =========================================================================

	/**
	 * Test get_current_checkin_token returns null initially.
	 *
	 * @return void
	 */
	public function test_get_current_checkin_token_returns_null_initially(): void {
		Router::set_instance( $this->router );

		$token = Router::get_current_checkin_token();

		$this->assertNull( $token );
	}

	/**
	 * Test get_current_checkin_token is static.
	 *
	 * @return void
	 */
	public function test_get_current_checkin_token_is_static(): void {
		$reflection = new \ReflectionMethod( Router::class, 'get_current_checkin_token' );
		$this->assertTrue( $reflection->isStatic() );
	}

	// =========================================================================
	// maybe_flush_rewrite_rules Tests
	// =========================================================================

	/**
	 * Test maybe_flush_rewrite_rules does nothing when option not set.
	 *
	 * @return void
	 */
	public function test_maybe_flush_rewrite_rules_does_nothing_when_option_not_set(): void {
		\Brain\Monkey\Functions\when( 'get_option' )
			->justReturn( false );

		// These should not be called.
		\Brain\Monkey\Functions\expect( 'flush_rewrite_rules' )->never();
		\Brain\Monkey\Functions\expect( 'delete_option' )->never();

		$this->router->maybe_flush_rewrite_rules();

		// Assertion to make test not risky.
		$this->assertTrue( true );
	}

	/**
	 * Test maybe_flush_rewrite_rules flushes and deletes when option set.
	 *
	 * @return void
	 */
	public function test_maybe_flush_rewrite_rules_flushes_when_option_set(): void {
		$flush_called  = false;
		$delete_called = false;

		\Brain\Monkey\Functions\when( 'get_option' )
			->alias(
				function ( $key ) {
					return 'nettertech_events_flush_rewrite_rules' === $key;
				}
			);

		\Brain\Monkey\Functions\when( 'flush_rewrite_rules' )
			->alias(
				function () use ( &$flush_called ) {
					$flush_called = true;
				}
			);

		\Brain\Monkey\Functions\when( 'delete_option' )
			->alias(
				function () use ( &$delete_called ) {
					$delete_called = true;
				}
			);

		$this->router->maybe_flush_rewrite_rules();

		$this->assertTrue( $flush_called, 'flush_rewrite_rules should be called' );
		$this->assertTrue( $delete_called, 'delete_option should be called' );
	}

	// =========================================================================
	// register_rewrite_rules Tests
	// =========================================================================

	/**
	 * Test register_rewrite_rules adds rewrite rules.
	 *
	 * @return void
	 */
	public function test_register_rewrite_rules_adds_rules(): void {
		\Brain\Monkey\Functions\expect( 'add_rewrite_rule' )
			->times( 11 ); // 8 page/route rules + iCal feed (NTE-016) + 2 year-scoped past-archive rules (NTE-113).

		$this->router->register_rewrite_rules();

		// Assertion to make test not risky.
		$this->assertTrue( true );
	}

	// =========================================================================
	// register Tests
	// =========================================================================

	/**
	 * Test register adds appropriate hooks.
	 *
	 * @return void
	 */
	public function test_register_adds_hooks(): void {
		$actions_added = array();
		$filters_added = array();

		\Brain\Monkey\Functions\when( 'add_action' )
			->alias(
				function ( $hook, $callback, $priority = 10 ) use ( &$actions_added ) {
					$actions_added[] = $hook;
				}
			);

		\Brain\Monkey\Functions\when( 'add_filter' )
			->alias(
				function ( $hook, $callback ) use ( &$filters_added ) {
					$filters_added[] = $hook;
				}
			);

		$this->router->register();

		$this->assertContains( 'init', $actions_added );
		$this->assertContains( 'query_vars', $filters_added );
		$this->assertContains( 'template_include', $filters_added );
	}

	// =========================================================================
	// maybe_load_event_template Tests
	// =========================================================================

	/**
	 * Test maybe_load_event_template returns default when no query vars set.
	 *
	 * @return void
	 */
	public function test_maybe_load_event_template_returns_default_when_no_query_vars(): void {
		\Brain\Monkey\Functions\when( 'get_query_var' )->justReturn( '' );

		$default  = '/path/to/default/template.php';
		$template = $this->router->maybe_load_event_template( $default );

		$this->assertSame( $default, $template );
	}

	/**
	 * Test maybe_load_event_template handles event not found.
	 *
	 * @return void
	 */
	public function test_maybe_load_event_template_404_when_event_not_found(): void {
		\Brain\Monkey\Functions\when( 'get_query_var' )
			->alias(
				function ( $var ) {
					if ( 'nettertech_events_event_slug' === $var ) {
						return 'non-existent-event';
					}
					return '';
				}
			);

		$this->event_repo_mock->shouldReceive( 'find_by_slug' )
			->with( 'non-existent-event' )
			->andReturn( null );

		// Mock global $wp_query.
		global $wp_query;
		$wp_query = \Mockery::mock( 'WP_Query' );
		$wp_query->shouldReceive( 'set_404' )->once();

		\Brain\Monkey\Functions\expect( 'status_header' )
			->with( 404 )
			->once();

		\Brain\Monkey\Functions\expect( 'get_404_template' )
			->once()
			->andReturn( '/path/to/404.php' );

		$template = $this->router->maybe_load_event_template( '/default.php' );

		$this->assertSame( '/path/to/404.php', $template );
	}

	// =========================================================================
	// Query Vars Constants Tests
	// =========================================================================

	/**
	 * Test add_query_vars includes checkin vars.
	 *
	 * @return void
	 */
	public function test_add_query_vars_includes_checkin_vars(): void {
		$vars = array();

		$result = $this->router->add_query_vars( $vars );

		$this->assertContains( 'nettertech_events_checkin_occurrence', $result );
		$this->assertContains( 'nettertech_events_checkin_token', $result );
	}

	/**
	 * Test add_query_vars returns the expected number of custom vars.
	 *
	 * @return void
	 */
	public function test_add_query_vars_returns_correct_count(): void {
		$vars = array();

		$result = $this->router->add_query_vars( $vars );

		// 8 page/route vars + nettertech_events_ical_feed (NTE-016) + nettertech_events_past_year (NTE-113).
		$this->assertCount( 10, $result );
	}

	/**
	 * Year-scoped past archive validates the year (NTE-113).
	 *
	 * In-range four-digit years resolve to an int; out-of-range and absent
	 * values resolve to null so the template renders the unscoped archive.
	 *
	 * @return void
	 */
	public function test_resolve_past_year_validates_range(): void {
		$method  = new \ReflectionMethod( Router::class, 'resolve_past_year' );
		$current = (int) gmdate( 'Y' );

		\Brain\Monkey\Functions\when( 'get_query_var' )->justReturn( '2024' );
		$this->assertSame( 2024, $method->invoke( $this->router ) );

		\Brain\Monkey\Functions\when( 'get_query_var' )->justReturn( (string) $current );
		$this->assertSame( $current, $method->invoke( $this->router ), 'current year is a valid past archive' );

		\Brain\Monkey\Functions\when( 'get_query_var' )->justReturn( '1850' );
		$this->assertNull( $method->invoke( $this->router ), 'below the 2000 floor' );

		\Brain\Monkey\Functions\when( 'get_query_var' )->justReturn( (string) ( $current + 5 ) );
		$this->assertNull( $method->invoke( $this->router ), 'future years are not past' );

		\Brain\Monkey\Functions\when( 'get_query_var' )->justReturn( '' );
		$this->assertNull( $method->invoke( $this->router ), 'absent year' );
	}

	// =========================================================================
	// parse_occurrence_datetime Tests (via reflection)
	// =========================================================================

	/**
	 * Test parse_occurrence_datetime with valid datetime.
	 *
	 * @return void
	 */
	public function test_parse_occurrence_datetime_with_valid_datetime(): void {
		$resolver = new EventTemplateResolver( Templates::get_instance() );

		$result = $resolver->parse_occurrence_datetime( '2024-01-15-1400' );

		$this->assertInstanceOf( \DateTimeImmutable::class, $result );
		$this->assertSame( '2024-01-15', $result->format( 'Y-m-d' ) );
		$this->assertSame( '14:00', $result->format( 'H:i' ) );
	}

	/**
	 * Test parse_occurrence_datetime with invalid format.
	 *
	 * @return void
	 */
	public function test_parse_occurrence_datetime_with_invalid_format(): void {
		$resolver = new EventTemplateResolver( Templates::get_instance() );

		$result = $resolver->parse_occurrence_datetime( 'invalid-datetime' );

		$this->assertNull( $result );
	}

	/**
	 * Test parse_occurrence_datetime with midnight.
	 *
	 * @return void
	 */
	public function test_parse_occurrence_datetime_with_midnight(): void {
		$resolver = new EventTemplateResolver( Templates::get_instance() );

		$result = $resolver->parse_occurrence_datetime( '2024-12-31-0000' );

		$this->assertInstanceOf( \DateTimeImmutable::class, $result );
		$this->assertSame( '00:00', $result->format( 'H:i' ) );
	}

	/**
	 * Test parse_occurrence_datetime with late evening.
	 *
	 * @return void
	 */
	public function test_parse_occurrence_datetime_with_late_evening(): void {
		$resolver = new EventTemplateResolver( Templates::get_instance() );

		$result = $resolver->parse_occurrence_datetime( '2024-06-15-2330' );

		$this->assertInstanceOf( \DateTimeImmutable::class, $result );
		$this->assertSame( '23:30', $result->format( 'H:i' ) );
	}

	// =========================================================================
	// maybe_load_event_template Archive Tests
	// =========================================================================

	/**
	 * Test maybe_load_event_template returns archive template.
	 *
	 * @return void
	 */
	public function test_maybe_load_event_template_returns_archive_template(): void {
		\Brain\Monkey\Functions\when( 'get_query_var' )
			->alias(
				function ( $var ) {
					if ( 'nettertech_events_archive' === $var ) {
						return '1';
					}
					return '';
				}
			);

		\Brain\Monkey\Functions\when( 'locate_template' )->justReturn( '' );

		$template = $this->router->maybe_load_event_template( '/path/to/default.php' );

		// Plugin template exists, so it should return the archive template.
		$this->assertStringContainsString( 'templates/archive-events.php', $template );
	}

	/**
	 * Test maybe_load_event_template returns past archive template.
	 *
	 * @return void
	 */
	public function test_maybe_load_event_template_returns_past_archive_template(): void {
		\Brain\Monkey\Functions\when( 'get_query_var' )
			->alias(
				function ( $var ) {
					if ( 'nettertech_events_past_archive' === $var ) {
						return '1';
					}
					return '';
				}
			);

		\Brain\Monkey\Functions\when( 'locate_template' )->justReturn( '' );

		$template = $this->router->maybe_load_event_template( '/path/to/default.php' );

		// Plugin template exists, so it should return the past archive template.
		$this->assertStringContainsString( 'templates/archive-past-events.php', $template );
	}

	// =========================================================================
	// maybe_load_event_template Published Event Tests
	// =========================================================================

	/**
	 * Test maybe_load_event_template handles unpublished event without permission.
	 *
	 * @return void
	 */
	public function test_maybe_load_event_template_404_for_unpublished_without_permission(): void {
		\Brain\Monkey\Functions\when( 'get_query_var' )
			->alias(
				function ( $var ) {
					if ( 'nettertech_events_event_slug' === $var ) {
						return 'draft-event';
					}
					return '';
				}
			);

		// Create a mock event that's not published.
		$event = \Mockery::mock( \NetterTechEvents\Models\Event::class );
		$event->id = 1;
		$event->shouldReceive( 'is_published' )->andReturn( false );

		$this->event_repo_mock->shouldReceive( 'find_by_slug' )
			->with( 'draft-event' )
			->andReturn( $event );

		// User can't preview.
		\Brain\Monkey\Functions\when( 'current_user_can' )->justReturn( false );

		// Mock global $wp_query for 404.
		global $wp_query;
		$wp_query = \Mockery::mock( 'WP_Query' );
		$wp_query->shouldReceive( 'set_404' )->once();

		\Brain\Monkey\Functions\expect( 'status_header' )
			->with( 404 )
			->once();

		\Brain\Monkey\Functions\expect( 'get_404_template' )
			->once()
			->andReturn( '/path/to/404.php' );

		$template = $this->router->maybe_load_event_template( '/default.php' );

		$this->assertSame( '/path/to/404.php', $template );
	}

	/**
	 * Test maybe_load_event_template loads single event for non-recurring.
	 *
	 * @return void
	 */
	public function test_maybe_load_event_template_loads_single_for_non_recurring(): void {
		\Brain\Monkey\Functions\when( 'get_query_var' )
			->alias(
				function ( $var ) {
					if ( 'nettertech_events_event_slug' === $var ) {
						return 'single-event';
					}
					return '';
				}
			);

		// Create a mock published single event.
		$event = \Mockery::mock( \NetterTechEvents\Models\Event::class );
		$event->id    = 1;
		$event->title = 'Test Event';
		$event->shouldReceive( 'is_published' )->andReturn( true );
		$event->shouldReceive( 'is_recurring' )->andReturn( false );

		$this->event_repo_mock->shouldReceive( 'find_by_slug' )
			->with( 'single-event' )
			->andReturn( $event );

		\Brain\Monkey\Functions\when( 'locate_template' )->justReturn( '' );
		\Brain\Monkey\Functions\when( 'add_filter' )->justReturn( true );

		$template = $this->router->maybe_load_event_template( '/path/to/default.php' );

		// Plugin template exists, so it should return the single event template.
		$this->assertStringContainsString( 'templates/single-event.php', $template );
	}

	/**
	 * Test maybe_load_event_template loads series for ticketed recurring.
	 *
	 * @return void
	 */
	public function test_maybe_load_event_template_loads_series_for_ticketed_recurring(): void {
		\Brain\Monkey\Functions\when( 'get_query_var' )
			->alias(
				function ( $var ) {
					if ( 'nettertech_events_event_slug' === $var ) {
						return 'recurring-event';
					}
					return '';
				}
			);

		// Create a mock published recurring ticketed event.
		$event = \Mockery::mock( \NetterTechEvents\Models\Event::class );
		$event->id    = 1;
		$event->title = 'Recurring Event';
		$event->shouldReceive( 'is_published' )->andReturn( true );
		$event->shouldReceive( 'is_recurring' )->andReturn( true );

		$this->event_repo_mock->shouldReceive( 'find_by_slug' )
			->with( 'recurring-event' )
			->andReturn( $event );

		$this->event_repo_mock->shouldReceive( 'has_ticket_types' )
			->with( 1 )
			->andReturn( true );

		\Brain\Monkey\Functions\when( 'locate_template' )->justReturn( '' );
		\Brain\Monkey\Functions\when( 'add_filter' )->justReturn( true );

		$template = $this->router->maybe_load_event_template( '/path/to/default.php' );

		// Plugin template exists, so it should return the series template.
		$this->assertStringContainsString( 'templates/series-page.php', $template );
	}

	// =========================================================================
	// Occurrence Loading Tests
	// =========================================================================

	/**
	 * Test maybe_load_event_template 404s for invalid datetime format.
	 *
	 * @return void
	 */
	public function test_maybe_load_event_template_404_for_invalid_datetime(): void {
		\Brain\Monkey\Functions\when( 'get_query_var' )
			->alias(
				function ( $var ) {
					if ( 'nettertech_events_event_slug' === $var ) {
						return 'ticketed-event';
					}
					if ( 'nettertech_events_occurrence_datetime' === $var ) {
						return 'invalid-format';
					}
					return '';
				}
			);

		// Create a mock published recurring ticketed event.
		$event = \Mockery::mock( \NetterTechEvents\Models\Event::class );
		$event->id = 1;
		$event->shouldReceive( 'is_published' )->andReturn( true );
		$event->shouldReceive( 'is_recurring' )->andReturn( true );

		$this->event_repo_mock->shouldReceive( 'find_by_slug' )
			->with( 'ticketed-event' )
			->andReturn( $event );

		$this->event_repo_mock->shouldReceive( 'has_ticket_types' )
			->with( 1 )
			->andReturn( true );

		// Mock global $wp_query for 404.
		global $wp_query;
		$wp_query = \Mockery::mock( 'WP_Query' );
		$wp_query->shouldReceive( 'set_404' )->once();

		\Brain\Monkey\Functions\expect( 'status_header' )
			->with( 404 )
			->once();

		\Brain\Monkey\Functions\expect( 'get_404_template' )
			->once()
			->andReturn( '/path/to/404.php' );

		$template = $this->router->maybe_load_event_template( '/default.php' );

		$this->assertSame( '/path/to/404.php', $template );
	}

	/**
	 * Test maybe_load_event_template 404s for occurrence not found.
	 *
	 * @return void
	 */
	public function test_maybe_load_event_template_404_for_occurrence_not_found(): void {
		\Brain\Monkey\Functions\when( 'get_query_var' )
			->alias(
				function ( $var ) {
					if ( 'nettertech_events_event_slug' === $var ) {
						return 'ticketed-event';
					}
					if ( 'nettertech_events_occurrence_datetime' === $var ) {
						return '2024-01-15-1400';
					}
					return '';
				}
			);

		// Create a mock published recurring ticketed event.
		$event = \Mockery::mock( \NetterTechEvents\Models\Event::class );
		$event->id = 1;
		$event->shouldReceive( 'is_published' )->andReturn( true );
		$event->shouldReceive( 'is_recurring' )->andReturn( true );

		$this->event_repo_mock->shouldReceive( 'find_by_slug' )
			->with( 'ticketed-event' )
			->andReturn( $event );

		$this->event_repo_mock->shouldReceive( 'has_ticket_types' )
			->with( 1 )
			->andReturn( true );

		// Occurrence not found.
		$this->occurrence_repo_mock->shouldReceive( 'find_by_event_and_datetime' )
			->andReturn( null );

		// Mock global $wp_query for 404.
		global $wp_query;
		$wp_query = \Mockery::mock( 'WP_Query' );
		$wp_query->shouldReceive( 'set_404' )->once();

		\Brain\Monkey\Functions\expect( 'status_header' )
			->with( 404 )
			->once();

		\Brain\Monkey\Functions\expect( 'get_404_template' )
			->once()
			->andReturn( '/path/to/404.php' );

		$template = $this->router->maybe_load_event_template( '/default.php' );

		$this->assertSame( '/path/to/404.php', $template );
	}

	/**
	 * Test maybe_load_event_template loads occurrence successfully.
	 *
	 * @return void
	 */
	public function test_maybe_load_event_template_loads_occurrence(): void {
		\Brain\Monkey\Functions\when( 'get_query_var' )
			->alias(
				function ( $var ) {
					if ( 'nettertech_events_event_slug' === $var ) {
						return 'ticketed-event';
					}
					if ( 'nettertech_events_occurrence_datetime' === $var ) {
						return '2024-01-15-1400';
					}
					return '';
				}
			);

		// Create a mock published recurring ticketed event.
		$event = \Mockery::mock( \NetterTechEvents\Models\Event::class );
		$event->id = 1;
		$event->shouldReceive( 'is_published' )->andReturn( true );
		$event->shouldReceive( 'is_recurring' )->andReturn( true );

		$this->event_repo_mock->shouldReceive( 'find_by_slug' )
			->with( 'ticketed-event' )
			->andReturn( $event );

		$this->event_repo_mock->shouldReceive( 'has_ticket_types' )
			->with( 1 )
			->andReturn( true );

		// Create mock occurrence.
		$occurrence = \Mockery::mock( \NetterTechEvents\Models\Occurrence::class );
		$occurrence->shouldReceive( 'get_title' )->andReturn( 'Test Event' );
		$occurrence->shouldReceive( 'get_formatted_date' )->andReturn( 'January 15, 2024' );

		$this->occurrence_repo_mock->shouldReceive( 'find_by_event_and_datetime' )
			->andReturn( $occurrence );

		\Brain\Monkey\Functions\when( 'add_filter' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'locate_template' )->justReturn( '' );

		$template = $this->router->maybe_load_event_template( '/path/to/default.php' );

		// Plugin template exists, so it should return the single event template.
		$this->assertStringContainsString( 'templates/single-event.php', $template );
	}

	// =========================================================================
	// can_preview_event Tests (via reflection)
	// =========================================================================

	/**
	 * Test can_preview_event returns false when user has no capability.
	 *
	 * @return void
	 */
	public function test_can_preview_event_returns_false_without_capability(): void {
		$resolver = new EventTemplateResolver( Templates::get_instance() );

		$event     = \Mockery::mock( \NetterTechEvents\Models\Event::class );
		$event->id = 1;

		\Brain\Monkey\Functions\when( 'current_user_can' )->justReturn( false );

		$result = $resolver->can_preview_event( $event );

		$this->assertFalse( $result );
	}

	/**
	 * Test can_preview_event returns true for logged-in editor.
	 *
	 * @return void
	 */
	public function test_can_preview_event_returns_true_for_logged_in_editor(): void {
		$resolver = new EventTemplateResolver( Templates::get_instance() );

		$event     = \Mockery::mock( \NetterTechEvents\Models\Event::class );
		$event->id = 1;

		\Brain\Monkey\Functions\when( 'current_user_can' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'is_user_logged_in' )->justReturn( true );

		// No preview param set.
		$_GET = array();

		$result = $resolver->can_preview_event( $event );

		$this->assertTrue( $result );
	}

	/**
	 * Test can_preview_event verifies nonce when preview param set.
	 *
	 * @return void
	 */
	public function test_can_preview_event_verifies_nonce(): void {
		$resolver = new EventTemplateResolver( Templates::get_instance() );

		$event     = \Mockery::mock( \NetterTechEvents\Models\Event::class );
		$event->id = 123;

		\Brain\Monkey\Functions\when( 'current_user_can' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'sanitize_text_field' )->returnArg();
		\Brain\Monkey\Functions\when( 'wp_unslash' )->returnArg();

		// With valid preview and nonce.
		$_GET = array(
			'preview'  => 'true',
			'_wpnonce' => 'valid_nonce',
		);

		// Nonce verification returns true (as int 1).
		\Brain\Monkey\Functions\when( 'wp_verify_nonce' )
			->alias(
				function ( $nonce, $action ) {
					return 'nettertech_events_preview_123' === $action ? 1 : false;
				}
			);

		$result = $resolver->can_preview_event( $event );

		$this->assertTrue( $result );

		// Clean up.
		$_GET = array();
	}

	/**
	 * Test can_preview_event returns false for invalid nonce.
	 *
	 * @return void
	 */
	public function test_can_preview_event_returns_false_for_invalid_nonce(): void {
		$resolver = new EventTemplateResolver( Templates::get_instance() );

		$event     = \Mockery::mock( \NetterTechEvents\Models\Event::class );
		$event->id = 123;

		\Brain\Monkey\Functions\when( 'current_user_can' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'sanitize_text_field' )->returnArg();
		\Brain\Monkey\Functions\when( 'wp_unslash' )->returnArg();
		\Brain\Monkey\Functions\when( 'wp_verify_nonce' )->justReturn( false );

		// With preview but invalid nonce.
		$_GET = array(
			'preview'  => 'true',
			'_wpnonce' => 'invalid_nonce',
		);

		$result = $resolver->can_preview_event( $event );

		$this->assertFalse( $result );

		// Clean up.
		$_GET = array();
	}

	// =========================================================================
	// setup_document_meta Tests (via reflection)
	// =========================================================================

	/**
	 * Test setup_document_meta adds filters.
	 *
	 * @return void
	 */
	public function test_setup_document_meta_adds_filters(): void {
		$reflection = new \ReflectionClass( $this->router );
		$method     = $reflection->getMethod( 'setup_document_meta' );

		$filters_added = array();
		\Brain\Monkey\Functions\when( 'add_filter' )
			->alias(
				function ( $hook ) use ( &$filters_added ) {
					$filters_added[] = $hook;
				}
			);

		$method->invoke( $this->router, 'Test Title', 'test-class' );

		$this->assertContains( 'document_title_parts', $filters_added );
		$this->assertContains( 'body_class', $filters_added );
	}

	// =========================================================================
	// Template Method Tests (via reflection)
	// =========================================================================

	/**
	 * Test get_single_template returns theme template when available.
	 *
	 * @return void
	 */
	public function test_get_single_template_returns_theme_template(): void {
		$resolver = new EventTemplateResolver( Templates::get_instance() );

		\Brain\Monkey\Functions\when( 'locate_template' )
			->justReturn( '/theme/nettertech-events/single-event.php' );

		$result = $resolver->get_single_template( '/default.php' );

		$this->assertSame( '/theme/nettertech-events/single-event.php', $result );
	}

	/**
	 * Test get_archive_template returns theme template when available.
	 *
	 * @return void
	 */
	public function test_get_archive_template_returns_theme_template(): void {
		$resolver = new EventTemplateResolver( Templates::get_instance() );

		\Brain\Monkey\Functions\when( 'locate_template' )
			->justReturn( '/theme/nettertech-events/archive-events.php' );

		$result = $resolver->get_archive_template( '/default.php' );

		$this->assertSame( '/theme/nettertech-events/archive-events.php', $result );
	}

	/**
	 * Test get_series_template returns theme template when available.
	 *
	 * @return void
	 */
	public function test_get_series_template_returns_theme_template(): void {
		$resolver = new EventTemplateResolver( Templates::get_instance() );

		\Brain\Monkey\Functions\when( 'locate_template' )
			->justReturn( '/theme/nettertech-events/series-page.php' );

		$result = $resolver->get_series_template( '/default.php' );

		$this->assertSame( '/theme/nettertech-events/series-page.php', $result );
	}

	/**
	 * Test get_past_archive_template returns theme template when available.
	 *
	 * @return void
	 */
	public function test_get_past_archive_template_returns_theme_template(): void {
		$resolver = new EventTemplateResolver( Templates::get_instance() );

		\Brain\Monkey\Functions\when( 'locate_template' )
			->justReturn( '/theme/nettertech-events/archive-past-events.php' );

		$result = $resolver->get_past_archive_template( '/default.php' );

		$this->assertSame( '/theme/nettertech-events/archive-past-events.php', $result );
	}

	/**
	 * Test get_single_template returns plugin template when no theme template.
	 *
	 * @return void
	 */
	public function test_get_single_template_returns_plugin_template(): void {
		$resolver = new EventTemplateResolver( Templates::get_instance() );

		\Brain\Monkey\Functions\when( 'locate_template' )->justReturn( '' );

		$result = $resolver->get_single_template( '/default.php' );

		// Plugin template exists, so it should return the plugin template.
		$this->assertStringContainsString( 'templates/single-event.php', $result );
	}
}

