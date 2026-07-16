<?php
/**
 * FieldConnections unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\BeaverBuilder\Themer
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\BeaverBuilder\Themer;

use Brain\Monkey\Functions;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Frontend\Router;
use NetterTechEvents\Integrations\BeaverBuilder\Themer\Context;
use NetterTechEvents\Integrations\BeaverBuilder\Themer\FieldConnections;
use NetterTechEvents\Models\Event;

/**
 * Tests the FLPageData getter callbacks that field connections invoke at render time.
 *
 * Stubs the Router singleton via reflection so the getters resolve a known
 * Event / Space / null without standing up the full repository graph.
 *
 * @coversDefaultClass \NetterTechEvents\Integrations\BeaverBuilder\Themer\FieldConnections
 */
class FieldConnectionsTest extends \NetterTechEventsTestCase {

	/**
	 * Reset the Router singleton between tests to keep state isolated.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->set_router_instance( null );
		parent::tearDown();
	}

	/**
	 * Off-context (no Router singleton, no event) => all getters return empty.
	 *
	 * @return void
	 */
	public function test_event_getters_return_empty_off_context(): void {
		$this->set_router_instance( null );

		$this->assertSame( '', FieldConnections::get_event_title() );
		$this->assertSame( '', FieldConnections::get_event_description() );
		$this->assertSame( '', FieldConnections::get_event_excerpt() );
		$this->assertSame( '', FieldConnections::get_event_url() );
		$this->assertSame( 0, FieldConnections::get_event_featured_image() );
		$this->assertSame( '', FieldConnections::get_event_venue_name() );
		$this->assertSame( '', FieldConnections::get_event_venue_address() );
		$this->assertSame( '', FieldConnections::get_event_event_type() );
		$this->assertSame( '', FieldConnections::get_event_is_recurring() );
		$this->assertSame( '', FieldConnections::get_event_is_virtual() );
		$this->assertSame( '', FieldConnections::get_event_virtual_url() );
		$this->assertSame( '', FieldConnections::get_event_recurrence_summary() );
	}

	/**
	 * Event populated => string/url/image getters reflect its public fields.
	 *
	 * @return void
	 */
	public function test_event_getters_return_event_fields(): void {
		Functions\when( 'home_url' )->returnArg( 1 );
		Functions\when( 'trailingslashit' )->alias(
			static fn( $url ) => rtrim( (string) $url, '/' ) . '/'
		);
		Functions\when( 'get_option' )->justReturn( '' );

		$event                    = new Event();
		$event->id                = 42;
		$event->title             = 'Summer Concert';
		$event->slug              = 'summer-concert';
		$event->description       = 'Open-air evening of jazz.';
		$event->excerpt           = 'Jazz under the stars.';
		$event->featured_image_id = 555;
		$event->status            = EventStatus::PUBLISHED;
		$event->event_type        = 'recurring';
		$event->venue_name        = 'Riverfront Pavilion';
		$event->venue_address     = '100 River Rd';
		$event->recurrence_rule   = 'FREQ=WEEKLY;BYDAY=FR';
		$event->virtual_url       = 'https://stream.example.test/';

		$this->set_router_current_event( $event );

		$this->assertSame( 'Summer Concert', FieldConnections::get_event_title() );
		$this->assertSame( 'Open-air evening of jazz.', FieldConnections::get_event_description() );
		$this->assertSame( 'Jazz under the stars.', FieldConnections::get_event_excerpt() );
		$this->assertSame( 555, FieldConnections::get_event_featured_image() );
		$this->assertSame( 'Riverfront Pavilion', FieldConnections::get_event_venue_name() );
		$this->assertSame( '100 River Rd', FieldConnections::get_event_venue_address() );
		$this->assertSame( 'recurring', FieldConnections::get_event_event_type() );
		$this->assertSame( 'yes', FieldConnections::get_event_is_recurring() );
		$this->assertSame( 'FREQ=WEEKLY;BYDAY=FR', FieldConnections::get_event_recurrence_summary() );
		$this->assertSame( 'https://stream.example.test/', FieldConnections::get_event_virtual_url() );
	}

	/**
	 * is_virtual reports "yes" only when Event::is_virtual_event() returns true.
	 *
	 * @return void
	 */
	public function test_is_virtual_reflects_event_state(): void {
		$event             = new Event();
		$event->is_virtual = true;
		$this->set_router_current_event( $event );

		$this->assertSame( 'yes', FieldConnections::get_event_is_virtual() );
	}

	/**
	 * is_recurring is empty for a non-recurring event.
	 *
	 * @return void
	 */
	public function test_is_recurring_empty_for_single_event(): void {
		$event             = new Event();
		$event->event_type = 'single';
		$this->set_router_current_event( $event );

		$this->assertSame( '', FieldConnections::get_event_is_recurring() );
	}

	/**
	 * Space getters return empty off-context.
	 *
	 * @return void
	 */
	public function test_space_getters_return_empty_off_context(): void {
		$this->set_router_current_space( null );

		$this->assertSame( '', FieldConnections::get_space_name() );
		$this->assertSame( '', FieldConnections::get_space_description() );
		$this->assertSame( '', FieldConnections::get_space_tagline() );
		$this->assertSame( '', FieldConnections::get_space_capacity() );
		$this->assertSame( 0, FieldConnections::get_space_featured_image() );
	}

	/**
	 * Space getters resolve stdClass row fields when populated.
	 *
	 * @return void
	 */
	public function test_space_getters_return_row_fields(): void {
		$space                    = new \stdClass();
		$space->name              = 'Main Hall';
		$space->description       = 'A 400-seat venue.';
		$space->tagline           = '400 seats — Premium';
		$space->capacity          = 400;
		$space->featured_image_id = 777;

		$this->set_router_current_space( $space );

		$this->assertSame( 'Main Hall', FieldConnections::get_space_name() );
		$this->assertSame( 'A 400-seat venue.', FieldConnections::get_space_description() );
		$this->assertSame( '400 seats — Premium', FieldConnections::get_space_tagline() );
		$this->assertSame( '400', FieldConnections::get_space_capacity() );
		$this->assertSame( 777, FieldConnections::get_space_featured_image() );
	}

	/**
	 * Space capacity returns empty string when the row lacks the field.
	 *
	 * Defends against schema drift where older space rows may pre-date
	 * the capacity column.
	 *
	 * @return void
	 */
	public function test_space_capacity_empty_when_missing_field(): void {
		$space       = new \stdClass();
		$space->name = 'Main Hall';

		$this->set_router_current_space( $space );

		$this->assertSame( '', FieldConnections::get_space_capacity() );
	}

	/**
	 * Archive title resolves per Context::detect() result.
	 *
	 * @return void
	 */
	public function test_archive_title_per_context(): void {
		Functions\when( '__' )->returnArg( 1 );

		$this->stub_query_vars( array( 'nettertech_events_archive' => '1' ) );
		$this->assertSame( 'Upcoming Events', FieldConnections::get_archive_title() );

		$this->stub_query_vars( array( 'nettertech_events_past_archive' => '1' ) );
		$this->assertSame( 'Past Events', FieldConnections::get_archive_title() );

		$this->stub_query_vars( array() );
		$this->assertSame( '', FieldConnections::get_archive_title() );
	}

	/**
	 * Archive context returns the detected Context key on archive routes only.
	 *
	 * @return void
	 */
	public function test_archive_context_returns_key(): void {
		$this->stub_query_vars( array( 'nettertech_events_archive' => '1' ) );
		$this->assertSame( Context::EVENTS_ARCHIVE, FieldConnections::get_archive_context() );

		$this->stub_query_vars( array( 'nettertech_events_event_slug' => 'foo' ) );
		$this->assertSame( '', FieldConnections::get_archive_context() );
	}

	/**
	 * init() is a no-op when FLPageData is not loaded.
	 *
	 * @return void
	 */
	public function test_init_noop_when_flpagedata_absent(): void {
		$this->assertFalse( class_exists( '\\FLPageData' ) );

		( new FieldConnections() )->init();

		$this->assertTrue( true );
	}

	/**
	 * Group constants are stable identifiers, not accidentally rewritten.
	 *
	 * @return void
	 */
	public function test_group_constants_use_nettertech_events_namespace(): void {
		$this->assertSame( 'nettertech_events_event', FieldConnections::GROUP_EVENT );
		$this->assertSame( 'nettertech_events_space', FieldConnections::GROUP_SPACE );
		$this->assertSame( 'nettertech_events_archive', FieldConnections::GROUP_ARCHIVE );
	}

	/**
	 * Stub get_query_var with a fixed override map.
	 *
	 * @param array<string, string> $overrides Query var => value map.
	 * @return void
	 */
	private function stub_query_vars( array $overrides ): void {
		Functions\when( 'get_query_var' )->alias(
			function ( $name, $default = '' ) use ( $overrides ) {
				return $overrides[ $name ] ?? $default;
			}
		);
	}

	/**
	 * Build a Router stub via reflection (bypassing the constructor) and bind it.
	 *
	 * @param Event|null $event Current event to expose.
	 * @return void
	 */
	private function set_router_current_event( ?Event $event ): void {
		$router = ( new \ReflectionClass( Router::class ) )->newInstanceWithoutConstructor();
		$prop   = new \ReflectionProperty( Router::class, 'current_event' );
		$prop->setValue( $router, $event );

		$this->set_router_instance( $router );
	}

	/**
	 * Build a Router stub via reflection and expose a space row on it.
	 *
	 * @param object|null $space Current space row.
	 * @return void
	 */
	private function set_router_current_space( ?object $space ): void {
		$router = ( new \ReflectionClass( Router::class ) )->newInstanceWithoutConstructor();
		$prop   = new \ReflectionProperty( Router::class, 'current_space' );
		$prop->setValue( $router, $space );

		$this->set_router_instance( $router );
	}

	/**
	 * Set or clear the Router singleton via reflection.
	 *
	 * @param Router|null $instance Router instance or null to clear.
	 * @return void
	 */
	private function set_router_instance( ?Router $instance ): void {
		$prop = new \ReflectionProperty( Router::class, 'instance' );
		$prop->setValue( null, $instance );
	}
}
