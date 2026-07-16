<?php
/**
 * Tests for RankMathIntegration.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\RankMath
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\RankMath;

use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Integrations\RankMath\RankMathIntegration;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use Brain\Monkey\Actions;

// Ensure RANK_MATH_VERSION is defined for all tests in this file.
if ( ! defined( 'RANK_MATH_VERSION' ) ) {
	define( 'RANK_MATH_VERSION', '1.0.0' );
}

/**
 * @coversDefaultClass \NetterTechEvents\Integrations\RankMath\RankMathIntegration
 */
class RankMathIntegrationTest extends \NetterTechEventsTestCase {

	/**
	 * Integration under test.
	 *
	 * @var RankMathIntegration
	 */
	private RankMathIntegration $integration;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->integration = new RankMathIntegration();

		// Set a default Router singleton with no current event.
		$router     = $this->createMock( \NetterTechEvents\Frontend\Router::class );
		$reflection = new \ReflectionClass( \NetterTechEvents\Frontend\Router::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setValue( null, $router );

		Functions\when( 'home_url' )->alias( function ( $path = '' ) {
			return 'http://example.test' . $path;
		} );

		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
	}

	// =========================================================================
	// init()
	// =========================================================================

	/**
	 * @covers ::init
	 */
	public function test_init_does_not_throw(): void {
		$this->integration->init();
		$this->assertTrue( true );
	}

	// =========================================================================
	// filter_json_ld()
	// =========================================================================

	/**
	 * @covers ::filter_json_ld
	 */
	public function test_filter_json_ld_returns_unchanged_when_not_on_event_page(): void {
		$data   = array( 'WebPage' => array( '@type' => 'WebPage' ) );
		$jsonld = new \stdClass();

		$result = $this->integration->filter_json_ld( $data, $jsonld );

		$this->assertArrayNotHasKey( 'Event', $result );
	}

	/**
	 * @covers ::filter_json_ld
	 */
	public function test_filter_json_ld_adds_event_schema(): void {
		$event         = new Event();
		$event->id     = 1;
		$event->title  = 'Concert Night';
		$event->slug   = 'concert-night';
		$event->status = EventStatus::PUBLISHED;

		$this->set_current_event( $event );

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Chicago' ) );

		$data   = array( 'WebPage' => array( '@type' => 'WebPage' ) );
		$jsonld = new \stdClass();

		$result = $this->integration->filter_json_ld( $data, $jsonld );

		$this->assertArrayHasKey( 'Event', $result );
		$this->assertEquals( 'Event', $result['Event']['@type'] );
		$this->assertEquals( 'Concert Night', $result['Event']['name'] );
	}

	/**
	 * @covers ::filter_json_ld
	 */
	public function test_filter_json_ld_includes_location(): void {
		$event                = new Event();
		$event->id            = 1;
		$event->title         = 'Local Show';
		$event->slug          = 'local-show';
		$event->status        = EventStatus::PUBLISHED;
		$event->venue_name    = 'The Venue';
		$event->venue_address = '123 Main St';

		$this->set_current_event( $event );

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Chicago' ) );

		$result = $this->integration->filter_json_ld( array(), new \stdClass() );

		$this->assertArrayHasKey( 'location', $result['Event'] );
		$this->assertEquals( 'Place', $result['Event']['location']['@type'] );
		$this->assertEquals( 'The Venue', $result['Event']['location']['name'] );
	}

	// =========================================================================
	// disable_builtin_schema()
	// =========================================================================

	/**
	 * @covers ::disable_builtin_schema
	 */
	public function test_disable_builtin_schema_returns_empty_when_rank_math_active(): void {
		$data = array( '@type' => 'Event', 'name' => 'Test' );

		$result = $this->integration->disable_builtin_schema( $data );

		$this->assertEmpty( $result );
	}

	// =========================================================================
	// Custom variable callbacks
	// =========================================================================

	/**
	 * @covers ::get_variable_event_date
	 */
	public function test_get_variable_event_date_returns_empty_when_not_on_event_page(): void {
		$this->assertSame( '', $this->integration->get_variable_event_date() );
	}

	/**
	 * @covers ::get_variable_event_venue
	 */
	public function test_get_variable_event_venue_returns_venue(): void {
		$event             = new Event();
		$event->id         = 1;
		$event->title      = 'Test';
		$event->venue_name = 'Great Hall';
		$event->status     = EventStatus::PUBLISHED;

		$this->set_current_event( $event );

		$this->assertSame( 'Great Hall', $this->integration->get_variable_event_venue() );
	}

	/**
	 * @covers ::get_variable_event_organizer
	 */
	public function test_get_variable_event_organizer_returns_site_name(): void {
		$event         = new Event();
		$event->id     = 1;
		$event->title  = 'Test';
		$event->status = EventStatus::PUBLISHED;

		$this->set_current_event( $event );

		$this->assertSame( 'Test Site', $this->integration->get_variable_event_organizer() );
	}

	// =========================================================================
	// filter_breadcrumb_html()
	// =========================================================================

	/**
	 * @covers ::filter_breadcrumb_html
	 */
	public function test_filter_breadcrumb_html_returns_unchanged_when_not_on_event_page(): void {
		$html = '<nav class="rank-math-breadcrumb"><p><a href="/">Home</a></p></nav>';

		$result = $this->integration->filter_breadcrumb_html( $html, array(), new \stdClass() );

		$this->assertSame( $html, $result );
	}

	/**
	 * @covers ::filter_breadcrumb_html
	 */
	public function test_filter_breadcrumb_html_injects_event_hierarchy(): void {
		$event         = new Event();
		$event->id     = 1;
		$event->title  = 'Test Event';
		$event->slug   = 'test-event';
		$event->status = EventStatus::PUBLISHED;

		$this->set_current_event( $event );

		Functions\when( '__' )->alias( function ( $text ) {
			return $text;
		} );
		Functions\when( 'esc_html' )->alias( function ( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		} );
		Functions\when( 'esc_url' )->alias( function ( $url ) {
			return $url;
		} );
		Functions\when( 'esc_html__' )->alias( function ( $text ) {
			return $text;
		} );

		$html = '<nav class="rank-math-breadcrumb"><p><a href="http://example.test/">Home</a><span class="separator"> - </span><span class="last">test-event</span></p></nav>';

		$result = $this->integration->filter_breadcrumb_html( $html, array(), new \stdClass() );

		$this->assertStringContainsString( 'Events', $result );
		$this->assertStringContainsString( 'Test Event', $result );
		$this->assertStringContainsString( 'rank-math-breadcrumb', $result );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Set the current event on the Router singleton.
	 *
	 * @param Event $event Event model.
	 * @return void
	 */
	private function set_current_event( Event $event ): void {
		$router = $this->createMock( \NetterTechEvents\Frontend\Router::class );

		$reflection = new \ReflectionClass( \NetterTechEvents\Frontend\Router::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setValue( null, $router );

		$current_event = $reflection->getProperty( 'current_event' );
		$current_event->setValue( $router, $event );
	}

	/**
	 * Clean up Router singleton after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$reflection = new \ReflectionClass( \NetterTechEvents\Frontend\Router::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setValue( null, null );

		parent::tearDown();
	}
}
