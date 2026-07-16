<?php
/**
 * CalendarShortcode coverage push tests.
 *
 * Targets uncovered branches:
 * - get_initial_date URL parameter branches (date, month, invalid date, invalid month)
 * - render_month_grid "more events" overflow branch (>3 events per day)
 * - render_week_grid event-in-hour branch
 * - render_day_grid event venue branch
 * - apply_filters customization paths (header HTML, wrapper classes, atts)
 * - cached events branch (get_transient hit path)
 *
 * @package NetterTechEvents\Tests\Unit\Frontend\Shortcodes
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend\Shortcodes;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Frontend\Shortcodes\CalendarShortcode;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;

/**
 * Coverage-targeted tests for CalendarShortcode.
 *
 * @coversDefaultClass \NetterTechEvents\Frontend\Shortcodes\CalendarShortcode
 */
class CalendarShortcodeCoverageTest extends \NetterTechEventsTestCase {

	/**
	 * @var OccurrenceRepositoryInterface|Mockery\MockInterface
	 */
	private $occurrence_repo;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->occurrence_repo = Mockery::mock( OccurrenceRepositoryInterface::class );

		// Reset the instance counter.
		$reflection = new \ReflectionClass( CalendarShortcode::class );
		$prop       = $reflection->getProperty( 'instance_count' );
		$prop->setValue( null, 0 );

		$this->setup_mocks();
	}

	/**
	 * Set up common WP function mocks.
	 *
	 * @return void
	 */
	private function setup_mocks(): void {
		Functions\when( 'shortcode_atts' )->alias(
			static fn( $defaults, $atts ) => array_merge( $defaults, is_array( $atts ) ? $atts : array() )
		);
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias( static fn( $t ) => print $t );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static fn( $t ) => print $t );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '_n' )->alias(
			static fn( $s, $p, $n ) => 1 === (int) $n ? $s : $p
		);
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_query_var' )->alias(
			static fn( $var, $default = '' ) => $default
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
	}

	/**
	 * Build a mock occurrence with optional event.
	 *
	 * @param string     $start Start datetime.
	 * @param string     $title Event title.
	 * @param string     $url   Event URL.
	 * @param string     $venue Venue name.
	 * @return Occurrence
	 */
	private function build_occurrence( string $start, string $title = 'Test Event', string $url = '/event/test', string $venue = '' ): Occurrence {
		$event = Mockery::mock( Event::class );
		$event->shouldReceive( 'get_permalink' )->andReturn( $url )->byDefault();
		$event->title      = $title;
		$event->venue_name = $venue;

		$occ                 = Mockery::mock( Occurrence::class );
		$occ->start_datetime = $start;
		$occ->shouldReceive( 'get_event' )->andReturn( $event )->byDefault();
		return $occ;
	}

	/**
	 * Build occurrence with no event (the orphan branch).
	 *
	 * @param string $start Start datetime.
	 * @return Occurrence
	 */
	private function build_orphan_occurrence( string $start ): Occurrence {
		$occ                 = Mockery::mock( Occurrence::class );
		$occ->start_datetime = $start;
		$occ->shouldReceive( 'get_event' )->andReturn( null )->byDefault();
		return $occ;
	}

	// =========================================================================
	// get_initial_date — URL parameter branches
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_uses_date_query_var_when_valid(): void {
		Functions\when( 'get_query_var' )->alias(
			static function ( $var, $default = '' ) {
				if ( 'nettertech_events_calendar_date' === $var ) {
					return '2026-04-15';
				}
				return $default;
			}
		);

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( array() );

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render();

		$this->assertStringContainsString( 'data-initial-date="2026-04-15"', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_uses_month_query_var_when_date_absent(): void {
		Functions\when( 'get_query_var' )->alias(
			static function ( $var, $default = '' ) {
				if ( 'nettertech_events_calendar_month' === $var ) {
					return '2026-06';
				}
				return $default;
			}
		);

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( array() );

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render();

		// Month-only resolves to YYYY-MM-01.
		$this->assertStringContainsString( 'data-initial-date="2026-06-01"', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_ignores_invalid_date_format(): void {
		Functions\when( 'get_query_var' )->alias(
			static function ( $var, $default = '' ) {
				if ( 'nettertech_events_calendar_date' === $var ) {
					return 'invalid-format';
				}
				return $default;
			}
		);

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( array() );

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render();

		// Falls back to "now".
		$this->assertStringContainsString( 'data-initial-date="', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_ignores_invalid_month_format(): void {
		Functions\when( 'get_query_var' )->alias(
			static function ( $var, $default = '' ) {
				if ( 'nettertech_events_calendar_month' === $var ) {
					return '2026-99'; // Bogus month format but matches regex.
				}
				return $default;
			}
		);

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( array() );

		// DateTime will still parse 2026-99-01 (rolls to next year). Should not throw.
		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render();
		$this->assertIsString( $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_ignores_malformed_month_string(): void {
		Functions\when( 'get_query_var' )->alias(
			static function ( $var, $default = '' ) {
				if ( 'nettertech_events_calendar_month' === $var ) {
					return 'xx-yy'; // Won't match /^\d{4}-\d{2}$/.
				}
				return $default;
			}
		);

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( array() );

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render();

		$this->assertIsString( $result );
	}

	// =========================================================================
	// Cached events path (get_transient hit)
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_uses_cached_events_when_transient_present(): void {
		$cached = array(
			$this->build_occurrence( '2026-02-15 10:00:00' ),
		);

		Functions\when( 'get_transient' )->justReturn( $cached );

		// Should not hit the repo when cache is warm.
		$this->occurrence_repo->shouldNotReceive( 'in_range' );

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render( array( 'view' => 'month' ) );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'nte-calendar', $result );
	}

	// =========================================================================
	// render_month_grid — overflow "more events" branch
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_month_grid_shows_more_button_when_over_3_events_per_day(): void {
		$date    = '2026-02-15';
		$events  = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$events[] = $this->build_occurrence(
				$date . ' 1' . $i . ':00:00',
				'Event ' . $i,
				'/event/' . $i
			);
		}

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( $events );

		Functions\when( 'get_query_var' )->alias(
			static function ( $var, $default = '' ) use ( $date ) {
				if ( 'nettertech_events_calendar_date' === $var ) {
					return $date;
				}
				return $default;
			}
		);

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render( array( 'view' => 'month' ) );

		// Only first 3 events are rendered; rest collapse to a "+N more" button.
		$this->assertStringContainsString( 'nte-calendar__more', $result );
		$this->assertStringContainsString( '+2', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_month_grid_handles_orphan_occurrence(): void {
		$events = array( $this->build_orphan_occurrence( '2026-02-15 10:00:00' ) );

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( $events );

		Functions\when( 'get_query_var' )->alias(
			static function ( $var, $default = '' ) {
				if ( 'nettertech_events_calendar_date' === $var ) {
					return '2026-02-15';
				}
				return $default;
			}
		);

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render( array( 'view' => 'month' ) );

		// Orphan event falls back to "Event" label and "#" URL.
		$this->assertStringContainsString( 'href="#"', $result );
	}

	// =========================================================================
	// render_week_grid — event-in-hour branch
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_week_grid_renders_events_in_correct_hour_slot(): void {
		// Reference date is a Monday — week renders Sun (02-15) through Sat (02-21).
		$events = array(
			$this->build_occurrence( '2026-02-16 14:30:00', 'Afternoon Event', '/afternoon' ),
		);

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( $events );

		Functions\when( 'get_query_var' )->alias(
			static function ( $var, $default = '' ) {
				if ( 'nettertech_events_calendar_date' === $var ) {
					return '2026-02-16';
				}
				return $default;
			}
		);

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render( array( 'view' => 'week' ) );

		$this->assertStringContainsString( 'nte-calendar__event--week', $result );
		$this->assertStringContainsString( 'Afternoon Event', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_week_grid_with_orphan_event_uses_default_label(): void {
		// Use Monday 2026-02-16 — "last sunday" from a Monday goes back to the prior Sunday,
		// but the grid then renders Sun through Sat (02-15 through 02-21), so 02-16 is in range.
		$events = array(
			$this->build_orphan_occurrence( '2026-02-16 10:00:00' ),
		);

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( $events );

		Functions\when( 'get_query_var' )->alias(
			static function ( $var, $default = '' ) {
				if ( 'nettertech_events_calendar_date' === $var ) {
					return '2026-02-16';
				}
				return $default;
			}
		);

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render( array( 'view' => 'week' ) );

		$this->assertStringContainsString( 'href="#"', $result );
	}

	// =========================================================================
	// render_day_grid — venue branch
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_day_grid_includes_venue_when_set(): void {
		$events = array(
			$this->build_occurrence( '2026-02-15 10:00:00', 'Test', '/url', 'Awesome Venue' ),
		);

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( $events );

		Functions\when( 'get_query_var' )->alias(
			static function ( $var, $default = '' ) {
				if ( 'nettertech_events_calendar_date' === $var ) {
					return '2026-02-15';
				}
				return $default;
			}
		);

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render( array( 'view' => 'day' ) );

		$this->assertStringContainsString( 'nte-calendar__event-venue', $result );
		$this->assertStringContainsString( 'Awesome Venue', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_day_grid_omits_venue_when_blank(): void {
		$events = array(
			$this->build_occurrence( '2026-02-15 10:00:00', 'Test', '/url', '' ),
		);

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( $events );

		Functions\when( 'get_query_var' )->alias(
			static function ( $var, $default = '' ) {
				if ( 'nettertech_events_calendar_date' === $var ) {
					return '2026-02-15';
				}
				return $default;
			}
		);

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render( array( 'view' => 'day' ) );

		$this->assertStringNotContainsString( 'nte-calendar__event-venue', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_day_grid_with_orphan_event(): void {
		$events = array(
			$this->build_orphan_occurrence( '2026-02-15 10:00:00' ),
		);

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( $events );

		Functions\when( 'get_query_var' )->alias(
			static function ( $var, $default = '' ) {
				if ( 'nettertech_events_calendar_date' === $var ) {
					return '2026-02-15';
				}
				return $default;
			}
		);

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render( array( 'view' => 'day' ) );

		$this->assertStringContainsString( 'href="#"', $result );
	}

	// =========================================================================
	// Filter customization paths
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_applies_atts_filter(): void {
		$captured_atts = null;
		Functions\when( 'apply_filters' )->alias(
			static function ( $filter, $value, ...$rest ) use ( &$captured_atts ) {
				if ( 'nettertech_events_calendar_shortcode_atts' === $filter ) {
					$captured_atts = $value;
				}
				return $value;
			}
		);

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( array() );

		( new CalendarShortcode( $this->occurrence_repo ) )->render( array( 'view' => 'month' ) );

		$this->assertIsArray( $captured_atts );
		$this->assertSame( 'month', $captured_atts['view'] );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_applies_wrapper_classes_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $filter, $value, ...$rest ) {
				if ( 'nettertech_events_calendar_wrapper_classes' === $filter ) {
					$value[] = 'custom-injected-class';
				}
				return $value;
			}
		);

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( array() );

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render();

		$this->assertStringContainsString( 'custom-injected-class', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_injects_filterable_header_html(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $filter, $value, ...$rest ) {
				if ( 'nettertech_events_calendar_header_html' === $filter ) {
					return '<div class="my-injected">Pinned</div>';
				}
				return $value;
			}
		);

		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( array() );

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render();

		$this->assertStringContainsString( 'my-injected', $result );
		$this->assertStringContainsString( 'Pinned', $result );
	}

	// =========================================================================
	// View routing
	// =========================================================================

	/**
	 * @covers ::render
	 */
	public function test_render_week_view_emits_time_gutter(): void {
		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( array() );

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render( array( 'view' => 'week' ) );

		$this->assertStringContainsString( 'nte-calendar__time-gutter', $result );
		$this->assertStringContainsString( 'nte-calendar__week-body', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_day_view_emits_day_grid(): void {
		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( array() );

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render( array( 'view' => 'day' ) );

		$this->assertStringContainsString( 'nte-calendar__day-grid', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_invalid_view_defaults_to_month_grid(): void {
		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( array() );

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render( array( 'view' => 'year' ) );

		$this->assertStringContainsString( 'nte-calendar--month', $result );
	}

	/**
	 * @covers ::render
	 */
	public function test_render_includes_custom_class_attribute(): void {
		$this->occurrence_repo->shouldReceive( 'in_range' )->andReturn( array() );

		$result = ( new CalendarShortcode( $this->occurrence_repo ) )->render(
			array( 'class' => 'my-custom-class another-class' )
		);

		$this->assertStringContainsString( 'my-custom-class', $result );
		$this->assertStringContainsString( 'another-class', $result );
	}
}
