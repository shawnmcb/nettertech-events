<?php
/**
 * Tests for YoastIntegration.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\Yoast
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\Yoast;

use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Integrations\Yoast\YoastIntegration;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use Brain\Monkey\Actions;

// Ensure WPSEO_VERSION is defined for all tests in this file.
if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', '24.0' );
}

/**
 * @coversDefaultClass \NetterTechEvents\Integrations\Yoast\YoastIntegration
 */
class YoastIntegrationTest extends \NetterTechEventsTestCase {

	/**
	 * Integration under test.
	 *
	 * @var YoastIntegration
	 */
	private YoastIntegration $integration;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->integration = new YoastIntegration();

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
		Functions\when( 'sanitize_url' )->alias( function ( $url ) {
			return $url;
		} );
		Functions\when( 'wp_unslash' )->alias( function ( $value ) {
			return $value;
		} );
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
	// filter_schema_graph()
	// =========================================================================

	/**
	 * @covers ::filter_schema_graph
	 */
	public function test_filter_schema_graph_returns_unchanged_when_not_on_event_page(): void {
		$graph   = array( array( '@type' => 'WebPage' ) );
		$context = new \stdClass();

		$result = $this->integration->filter_schema_graph( $graph, $context );

		$this->assertCount( 1, $result );
	}

	/**
	 * @covers ::filter_schema_graph
	 */
	public function test_filter_schema_graph_adds_event_when_on_event_page(): void {
		$event         = new Event();
		$event->id     = 1;
		$event->title  = 'Test Event';
		$event->slug   = 'test-event';
		$event->status = EventStatus::PUBLISHED;

		$this->set_current_event( $event );

		$graph              = array( array( '@type' => 'WebPage' ) );
		$context            = new \stdClass();
		$context->canonical = 'http://example.test/events/test-event/';

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Chicago' ) );

		$result = $this->integration->filter_schema_graph( $graph, $context );

		$this->assertCount( 2, $result );
		$this->assertEquals( 'Event', $result[1]['@type'] );
		$this->assertEquals( 'Test Event', $result[1]['name'] );
	}

	/**
	 * @covers ::filter_schema_graph
	 */
	public function test_filter_schema_graph_includes_occurrence_dates(): void {
		$event         = new Event();
		$event->id     = 1;
		$event->title  = 'Test Event';
		$event->slug   = 'test-event';
		$event->status = EventStatus::PUBLISHED;

		$occurrence                 = new Occurrence();
		$occurrence->id             = 10;
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-06-15 19:00:00';
		$occurrence->end_datetime   = '2026-06-15 21:00:00';
		$occurrence->timezone       = 'America/Chicago';
		$occurrence->status         = 'active';

		$this->set_current_event( $event );
		$this->set_current_occurrence( $occurrence );

		$graph              = array();
		$context            = new \stdClass();
		$context->canonical = 'http://example.test/events/test-event/';

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Chicago' ) );

		$result = $this->integration->filter_schema_graph( $graph, $context );

		$this->assertArrayHasKey( 'startDate', $result[0] );
		$this->assertArrayHasKey( 'endDate', $result[0] );
		$this->assertEquals( 'https://schema.org/EventScheduled', $result[0]['eventStatus'] );
	}

	// =========================================================================
	// disable_builtin_schema()
	// =========================================================================

	/**
	 * @covers ::disable_builtin_schema
	 */
	public function test_disable_builtin_schema_returns_empty_when_yoast_active(): void {
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
		$result = $this->integration->get_variable_event_date();

		$this->assertSame( '', $result );
	}

	/**
	 * @covers ::get_variable_event_date
	 */
	public function test_get_variable_event_date_returns_date_when_on_occurrence_page(): void {
		$event         = new Event();
		$event->id     = 1;
		$event->title  = 'Test Event';
		$event->status = EventStatus::PUBLISHED;

		$occurrence                 = new Occurrence();
		$occurrence->id             = 10;
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-06-15 19:00:00';
		$occurrence->timezone       = 'America/Chicago';

		$this->set_current_event( $event );
		$this->set_current_occurrence( $occurrence );

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Chicago' ) );
		Functions\when( 'wp_date' )->alias( function ( $format, $timestamp ) {
			return gmdate( $format, $timestamp );
		} );

		$result = $this->integration->get_variable_event_date();

		$this->assertNotEmpty( $result );
	}

	/**
	 * @covers ::get_variable_event_venue
	 */
	public function test_get_variable_event_venue_returns_venue_name(): void {
		$event             = new Event();
		$event->id         = 1;
		$event->title      = 'Test Event';
		$event->venue_name = 'The Grand Hall';
		$event->status     = EventStatus::PUBLISHED;

		$this->set_current_event( $event );

		$this->assertSame( 'The Grand Hall', $this->integration->get_variable_event_venue() );
	}

	/**
	 * @covers ::get_variable_event_venue
	 */
	public function test_get_variable_event_venue_returns_empty_when_no_venue(): void {
		$event         = new Event();
		$event->id     = 1;
		$event->title  = 'Test Event';
		$event->status = EventStatus::PUBLISHED;

		$this->set_current_event( $event );

		$this->assertSame( '', $this->integration->get_variable_event_venue() );
	}

	/**
	 * @covers ::get_variable_event_organizer
	 */
	public function test_get_variable_event_organizer_returns_site_name(): void {
		$event         = new Event();
		$event->id     = 1;
		$event->title  = 'Test Event';
		$event->status = EventStatus::PUBLISHED;

		$this->set_current_event( $event );

		$this->assertSame( 'Test Site', $this->integration->get_variable_event_organizer() );
	}

	/**
	 * @covers ::get_variable_event_organizer
	 */
	public function test_get_variable_event_organizer_returns_empty_when_not_on_event_page(): void {
		$this->assertSame( '', $this->integration->get_variable_event_organizer() );
	}

	// =========================================================================
	// filter_breadcrumb_links()
	// =========================================================================

	/**
	 * @covers ::filter_breadcrumb_links
	 */
	public function test_filter_breadcrumb_links_returns_unchanged_when_not_on_event_page(): void {
		$crumbs = array(
			array( 'url' => 'http://example.test/', 'text' => 'Home' ),
			array( 'url' => 'http://example.test/blog/', 'text' => 'Blog' ),
		);

		$result = $this->integration->filter_breadcrumb_links( $crumbs );

		$this->assertSame( $crumbs, $result );
	}

	/**
	 * @covers ::filter_breadcrumb_links
	 */
	public function test_filter_breadcrumb_links_injects_event_hierarchy(): void {
		$event         = new Event();
		$event->id     = 1;
		$event->title  = 'Test Event';
		$event->slug   = 'test-event';
		$event->status = EventStatus::PUBLISHED;

		$this->set_current_event( $event );

		Functions\when( '__' )->alias( function ( $text ) {
			return $text;
		} );

		$crumbs = array(
			array( 'url' => 'http://example.test/', 'text' => 'Home' ),
			array( 'text' => 'test-event' ),
		);

		$result = $this->integration->filter_breadcrumb_links( $crumbs );

		$this->assertCount( 3, $result );
		$this->assertEquals( 'Home', $result[0]['text'] );
		$this->assertEquals( 'Events', $result[1]['text'] );
		$this->assertStringContainsString( '/events/', $result[1]['url'] );
		$this->assertEquals( 'Test Event', $result[2]['text'] );
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
	 * Set the current occurrence on the Router singleton.
	 *
	 * @param Occurrence $occurrence Occurrence model.
	 * @return void
	 */
	private function set_current_occurrence( Occurrence $occurrence ): void {
		$reflection = new \ReflectionClass( \NetterTechEvents\Frontend\Router::class );
		$instance   = $reflection->getProperty( 'instance' );
		$router = $instance->getValue();

		$current_occurrence = $reflection->getProperty( 'current_occurrence' );
		$current_occurrence->setValue( $router, $occurrence );
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
