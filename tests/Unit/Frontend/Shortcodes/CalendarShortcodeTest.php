<?php
/**
 * CalendarShortcode unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend\Shortcodes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend\Shortcodes;

use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Frontend\Shortcodes\CalendarShortcode;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test CalendarShortcode class.
 *
 * @since 0.9.0
 * @coversDefaultClass \NetterTechEvents\Frontend\Shortcodes\CalendarShortcode
 */
class CalendarShortcodeTest extends \NetterTechEventsTestCase {

	/**
	 * Mock OccurrenceRepository.
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

		$this->occurrence_repo = Mockery::mock( OccurrenceRepositoryInterface::class );

		// Reset the static instance counter via reflection.
		$reflection = new \ReflectionClass( CalendarShortcode::class );
		$prop       = $reflection->getProperty( 'instance_count' );
		$prop->setValue( null, 0 );
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * @covers ::__construct
	 */
	public function test_constructor_accepts_repository(): void {
		$shortcode = new CalendarShortcode( $this->occurrence_repo );

		$this->assertInstanceOf( CalendarShortcode::class, $shortcode );
	}


	// =========================================================================
	// Default Attributes Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_has_defaults_constant(): void {
		$reflection = new \ReflectionClass( CalendarShortcode::class );

		$this->assertTrue( $reflection->hasConstant( 'DEFAULTS' ) );
	}

	/**
	 * @covers ::render
	 */
	public function test_defaults_contain_expected_keys(): void {
		$reflection = new \ReflectionClass( CalendarShortcode::class );
		$defaults   = $reflection->getConstant( 'DEFAULTS' );

		$expected_keys = array( 'view', 'show_view_switcher', 'show_navigation', 'class' );

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $defaults );
		}
	}

	/**
	 * @covers ::render
	 */
	public function test_default_view_is_month(): void {
		$reflection = new \ReflectionClass( CalendarShortcode::class );
		$defaults   = $reflection->getConstant( 'DEFAULTS' );

		$this->assertEquals( 'month', $defaults['view'] );
	}

	// =========================================================================
	// Render - Basic Output Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_returns_string(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render();

		$this->assertIsString( $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_uses_correct_shortcode_name(): void {
		$shortcode_name = null;

		$this->setup_render_mocks();
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $name ) use ( &$shortcode_name ) {
				$shortcode_name = $name;
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$shortcode->render();

		$this->assertEquals( 'nettertech_events_calendar', $shortcode_name );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_includes_calendar_wrapper(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'nte-calendar', $result );
		$this->assertStringContainsString( 'nte-calendar--month', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_generates_unique_instance_id(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result1   = $shortcode->render();
		$result2   = $shortcode->render();

		$this->assertStringContainsString( 'nte-calendar-1', $result1 );
		$this->assertStringContainsString( 'nte-calendar-2', $result2 );
	}

	// =========================================================================
	// Render - View Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_month_view(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'month' ) );

		$this->assertStringContainsString( 'nte-calendar--month', $result );
		$this->assertStringContainsString( 'data-view="month"', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_week_view(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'week' ) );

		$this->assertStringContainsString( 'nte-calendar--week', $result );
		$this->assertStringContainsString( 'data-view="week"', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_day_view(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'day' ) );

		$this->assertStringContainsString( 'nte-calendar--day', $result );
		$this->assertStringContainsString( 'data-view="day"', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_invalid_view_defaults_to_month(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'invalid' ) );

		$this->assertStringContainsString( 'nte-calendar--month', $result );
		$this->assertStringContainsString( 'data-view="month"', $result );
	}

	// =========================================================================
	// Render - Navigation Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_shows_navigation_by_default(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'nte-calendar__nav', $result );
		$this->assertStringContainsString( 'nte-calendar__nav-button--prev', $result );
		$this->assertStringContainsString( 'nte-calendar__nav-button--next', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_hides_navigation_when_disabled(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'show_navigation' => 'false' ) );

		$this->assertStringNotContainsString( 'nte-calendar__nav-button--prev', $result );
		$this->assertStringNotContainsString( 'nte-calendar__nav-button--next', $result );
	}

	// =========================================================================
	// Render - View Switcher Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_shows_view_switcher_by_default(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'nte-calendar__views', $result );
		$this->assertStringContainsString( 'data-view="month"', $result );
		$this->assertStringContainsString( 'data-view="week"', $result );
		$this->assertStringContainsString( 'data-view="day"', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_hides_view_switcher_when_disabled(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'show_view_switcher' => 'false' ) );

		$this->assertStringNotContainsString( 'nte-calendar__views', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_marks_current_view_button_active(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'week' ) );

		// Week button should have active class (class comes before data-view in HTML).
		$this->assertMatchesRegularExpression(
			'/nte-calendar__view-button--active[^>]*data-view="week"/',
			$result
		);
	}

	// =========================================================================
	// Render - CSS Class Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_custom_class(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'class' => 'my-custom-class' ) );

		$this->assertStringContainsString( 'my-custom-class', $result );
	}

	// =========================================================================
	// Render - Accessibility Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_accessibility_attributes(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'role="region"', $result );
		$this->assertStringContainsString( 'aria-label', $result );
		$this->assertStringContainsString( 'tabindex="0"', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_navigation_has_aria_labels(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'aria-label="Previous"', $result );
		$this->assertStringContainsString( 'aria-label="Next"', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_title_has_aria_live(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'aria-live="polite"', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_view_buttons_have_tab_roles(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'role="tablist"', $result );
		$this->assertStringContainsString( 'role="tab"', $result );
		$this->assertStringContainsString( 'aria-selected=', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_grid_has_grid_role(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'role="grid"', $result );
	}

	// =========================================================================
	// Render - Title Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_includes_title(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render();

		$this->assertStringContainsString( 'nte-calendar__title', $result );
	}

	// =========================================================================
	// Render - Month Grid Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_month_grid_includes_day_headers(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'month' ) );

		$this->assertStringContainsString( 'nte-calendar__day-header', $result );
		$this->assertStringContainsString( 'Sun', $result );
		$this->assertStringContainsString( 'Mon', $result );
		$this->assertStringContainsString( 'Tue', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_month_grid_includes_day_cells(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'month' ) );

		$this->assertStringContainsString( 'nte-calendar__day', $result );
		$this->assertStringContainsString( 'nte-calendar__day-number', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_month_grid_marks_today(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'month' ) );

		$this->assertStringContainsString( 'nte-calendar__day--today', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_month_grid_with_events(): void {
		$this->setup_render_mocks();

		$event            = Mockery::mock( Event::class );
		$event->title     = 'Test Event';
		$event->shouldReceive( 'get_permalink' )->andReturn( 'https://example.com/event/1' );

		$occurrence                 = Mockery::mock( Occurrence::class );
		$occurrence->start_datetime = ( new \DateTime() )->format( 'Y-m-d 10:00:00' );
		$occurrence->shouldReceive( 'get_event' )->andReturn( $event );
		$occurrence->shouldReceive( 'get_url' )->andReturn( 'https://example.com/event/1' );

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array( $occurrence ) );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'month' ) );

		$this->assertStringContainsString( 'nte-calendar__event', $result );
		$this->assertStringContainsString( 'Test Event', $result );
		$this->assertStringContainsString( 'https://example.com/event/1', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_month_grid_limits_displayed_events(): void {
		$this->setup_render_mocks();

		$events = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$event            = Mockery::mock( Event::class );
			$event->title     = "Event {$i}";
			$event->shouldReceive( 'get_permalink' )->andReturn( '#' );

			$occurrence                 = Mockery::mock( Occurrence::class );
			$occurrence->start_datetime = ( new \DateTime() )->format( 'Y-m-d 10:00:00' );
			$occurrence->shouldReceive( 'get_event' )->andReturn( $event );
			$occurrence->shouldReceive( 'get_url' )->andReturn( '#' );

			$events[] = $occurrence;
		}

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( $events );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'month' ) );

		// Should show "+2 more" indicator (5 events, only 3 shown).
		$this->assertStringContainsString( 'nte-calendar__more', $result );
		$this->assertStringContainsString( '+2', $result );
	}

	// =========================================================================
	// Render - Week Grid Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_week_grid_includes_time_slots(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'week' ) );

		$this->assertStringContainsString( 'nte-calendar__week-header', $result );
		$this->assertStringContainsString( 'nte-calendar__week-body', $result );
		$this->assertStringContainsString( 'nte-calendar__time-row', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_week_grid_includes_time_labels(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'week' ) );

		$this->assertStringContainsString( 'nte-calendar__time-label', $result );
		// Should include time labels from 6 AM to 10 PM.
		$this->assertStringContainsString( '6 AM', $result );
		$this->assertStringContainsString( '10 PM', $result );
	}

	// =========================================================================
	// Render - Day Grid Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_day_grid_includes_time_slots(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'day' ) );

		$this->assertStringContainsString( 'nte-calendar__day-grid', $result );
		$this->assertStringContainsString( 'nte-calendar__time-slot', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_day_grid_marks_current_hour(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'day' ) );

		// Current hour slot should be marked (if between 6 AM and 10 PM).
		$current_hour = (int) ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'G' );
		if ( $current_hour >= 6 && $current_hour <= 22 ) {
			$this->assertStringContainsString( 'nte-calendar__time-slot--current', $result );
		} else {
			// Outside visible range, no current marker.
			$this->assertStringNotContainsString( 'nte-calendar__time-slot--current', $result );
		}
	}

	// =========================================================================
	// Caching Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_uses_transient_cache(): void {
		$this->setup_render_mocks();

		$cached_events = array();
		Functions\when( 'get_transient' )->justReturn( $cached_events );

		// Repository should NOT be called if cache hit.
		$this->occurrence_repo->shouldNotReceive( 'in_range' );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render();

		// Verify render still produces output despite using cache.
		$this->assertStringContainsString( 'nte-calendar', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_sets_transient_on_cache_miss(): void {
		$this->setup_render_mocks();

		$set_transient_called = false;
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $expiration ) use ( &$set_transient_called ) {
				$set_transient_called = true;
				return true;
			}
		);

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$shortcode->render();

		$this->assertTrue( $set_transient_called );
	}

	// =========================================================================
	// Content Parameter Test
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_accepts_content_parameter(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		// Content parameter is ignored but should not cause errors.
		$result = $shortcode->render( array(), 'Some content' );

		$this->assertIsString( $result );
	}

	// =========================================================================
	// Event Without Event Model Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_handles_occurrence_without_event(): void {
		$this->setup_render_mocks();

		$occurrence                 = Mockery::mock( Occurrence::class );
		$occurrence->start_datetime = ( new \DateTime() )->format( 'Y-m-d 10:00:00' );
		$occurrence->shouldReceive( 'get_event' )->andReturn( null );

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array( $occurrence ) );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'month' ) );

		// Should use fallback title "Event".
		$this->assertStringContainsString( 'Event', $result );
		$this->assertStringContainsString( 'href="#"', $result );
	}

	// =========================================================================
	// Week Grid with Events Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_week_grid_with_events(): void {
		$this->setup_render_mocks();

		// Create event at 10 AM on the first day of the week (Sunday).
		$now   = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$start = ( clone $now )->modify( 'last sunday' );

		$event            = Mockery::mock( Event::class );
		$event->title     = 'Week Test Event';
		$event->shouldReceive( 'get_permalink' )->andReturn( 'https://example.com/event/week' );

		$occurrence                 = Mockery::mock( Occurrence::class );
		$occurrence->start_datetime = $start->format( 'Y-m-d' ) . ' 10:00:00';
		$occurrence->shouldReceive( 'get_event' )->andReturn( $event );
		$occurrence->shouldReceive( 'get_url' )->andReturn( 'https://example.com/event/week' );

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array( $occurrence ) );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'week' ) );

		$this->assertStringContainsString( 'nte-calendar__event--week', $result );
		$this->assertStringContainsString( 'Week Test Event', $result );
		$this->assertStringContainsString( 'nte-calendar__event-time', $result );
		$this->assertStringContainsString( 'nte-calendar__event-title', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_week_grid_displays_event_time(): void {
		$this->setup_render_mocks();

		$now   = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$start = ( clone $now )->modify( 'last sunday' );

		$event        = Mockery::mock( Event::class );
		$event->title = 'Timed Event';
		$event->shouldReceive( 'get_permalink' )->andReturn( '#' );

		$occurrence                 = Mockery::mock( Occurrence::class );
		$occurrence->start_datetime = $start->format( 'Y-m-d' ) . ' 14:30:00';
		$occurrence->shouldReceive( 'get_event' )->andReturn( $event );
		$occurrence->shouldReceive( 'get_url' )->andReturn( '#' );

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array( $occurrence ) );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'week' ) );

		$this->assertStringContainsString( '2:30 PM', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_week_grid_handles_null_event(): void {
		$this->setup_render_mocks();

		$now   = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$start = ( clone $now )->modify( 'last sunday' );

		$occurrence                 = Mockery::mock( Occurrence::class );
		$occurrence->start_datetime = $start->format( 'Y-m-d' ) . ' 10:00:00';
		$occurrence->shouldReceive( 'get_event' )->andReturn( null );

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array( $occurrence ) );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'week' ) );

		// Should use fallback.
		$this->assertStringContainsString( 'Event', $result );
		$this->assertStringContainsString( 'href="#"', $result );
	}

	// =========================================================================
	// Day Grid with Events Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_day_grid_with_events(): void {
		$this->setup_render_mocks();

		$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

		$event            = Mockery::mock( Event::class );
		$event->title     = 'Day Test Event';
		$event->venue_name = null;
		$event->shouldReceive( 'get_permalink' )->andReturn( 'https://example.com/event/day' );

		$occurrence                 = Mockery::mock( Occurrence::class );
		$occurrence->start_datetime = $now->format( 'Y-m-d' ) . ' 15:00:00';
		$occurrence->shouldReceive( 'get_event' )->andReturn( $event );
		$occurrence->shouldReceive( 'get_url' )->andReturn( 'https://example.com/event/day' );

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array( $occurrence ) );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'day' ) );

		$this->assertStringContainsString( 'nte-calendar__event--day', $result );
		$this->assertStringContainsString( 'Day Test Event', $result );
		$this->assertStringContainsString( '3:00 PM', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_day_grid_displays_venue_name(): void {
		$this->setup_render_mocks();

		$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

		$event             = Mockery::mock( Event::class );
		$event->title      = 'Venue Event';
		$event->venue_name = 'Celtic Junction Arts Center';
		$event->shouldReceive( 'get_permalink' )->andReturn( '#' );

		$occurrence                 = Mockery::mock( Occurrence::class );
		$occurrence->start_datetime = $now->format( 'Y-m-d' ) . ' 19:00:00';
		$occurrence->shouldReceive( 'get_event' )->andReturn( $event );
		$occurrence->shouldReceive( 'get_url' )->andReturn( '#' );

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array( $occurrence ) );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'day' ) );

		$this->assertStringContainsString( 'nte-calendar__event-venue', $result );
		$this->assertStringContainsString( 'Celtic Junction Arts Center', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_day_grid_handles_null_event(): void {
		$this->setup_render_mocks();

		$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

		$occurrence                 = Mockery::mock( Occurrence::class );
		$occurrence->start_datetime = $now->format( 'Y-m-d' ) . ' 12:00:00';
		$occurrence->shouldReceive( 'get_event' )->andReturn( null );

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array( $occurrence ) );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'day' ) );

		// Should use fallback.
		$this->assertStringContainsString( 'Event', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_day_grid_omits_empty_venue(): void {
		$this->setup_render_mocks();

		$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

		$event             = Mockery::mock( Event::class );
		$event->title      = 'No Venue Event';
		$event->venue_name = '';
		$event->shouldReceive( 'get_permalink' )->andReturn( '#' );

		$occurrence                 = Mockery::mock( Occurrence::class );
		$occurrence->start_datetime = $now->format( 'Y-m-d' ) . ' 10:00:00';
		$occurrence->shouldReceive( 'get_event' )->andReturn( $event );
		$occurrence->shouldReceive( 'get_url' )->andReturn( '#' );

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array( $occurrence ) );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'day' ) );

		// Should NOT have venue class when venue is empty.
		$this->assertStringNotContainsString( 'nte-calendar__event-venue', $result );
	}

	// =========================================================================
	// Title Format Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_month_title_format(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'month' ) );

		// Month title should contain month and year (e.g., "January 2026").
		$now           = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$expected_year = $now->format( 'Y' );
		$this->assertStringContainsString( $expected_year, $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_week_title_format(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'week' ) );

		// Week title should contain date range with " - ".
		$this->assertStringContainsString( ' - ', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_day_title_format(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'view' => 'day' ) );

		// Day title should contain day of week (e.g., "Tuesday").
		$now         = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$day_of_week = $now->format( 'l' );
		$this->assertStringContainsString( $day_of_week, $result );
	}

	// =========================================================================
	// Boolean Attribute Normalization Tests
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_normalizes_string_true_to_boolean(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'show_view_switcher' => 'true' ) );

		$this->assertStringContainsString( 'nte-calendar__views', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_normalizes_string_false_to_boolean(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'show_view_switcher' => 'false' ) );

		$this->assertStringNotContainsString( 'nte-calendar__views', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_normalizes_integer_1_to_boolean_true(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'show_navigation' => 1 ) );

		$this->assertStringContainsString( 'nte-calendar__nav', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_normalizes_integer_0_to_boolean_false(): void {
		$this->setup_render_mocks();

		$this->occurrence_repo
			->shouldReceive( 'in_range' )
			->andReturn( array() );

		$shortcode = new CalendarShortcode( $this->occurrence_repo );
		$result    = $shortcode->render( array( 'show_navigation' => 0 ) );

		$this->assertStringNotContainsString( 'nte-calendar__nav-button', $result );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Set up common WordPress function mocks for render tests.
	 *
	 * @return void
	 */
	private function setup_render_mocks(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias(
			function ( $text ) {
				echo $text;
			}
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text;
			}
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		// Calendar query vars (registered by CalendarRouting); default to empty.
		Functions\when( 'get_query_var' )->alias(
			function ( $var, $default = '' ) {
				return $default;
			}
		);
	}
}
