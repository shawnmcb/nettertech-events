<?php
/**
 * Tests for SchemaMarkup.
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Frontend\SchemaMarkup;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use Brain\Monkey\Functions;

/**
 * @coversDefaultClass \NetterTechEvents\Frontend\SchemaMarkup
 */
class SchemaMarkupTest extends \NetterTechEventsTestCase {

	/**
	 * Create a mock Event with default properties.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Event
	 */
	private function make_event( array $overrides = array() ): Event {
		$event                = new Event();
		$event->id            = $overrides['id'] ?? 1;
		$event->title         = $overrides['title'] ?? 'Test Concert';
		$event->slug          = $overrides['slug'] ?? 'test-concert';
		$event->description   = $overrides['description'] ?? 'A great show.';
		$event->excerpt       = $overrides['excerpt'] ?? '';
		$event->status        = isset( $overrides['status'] )
			? ( $overrides['status'] instanceof EventStatus ? $overrides['status'] : EventStatus::tryFrom( $overrides['status'] ) ?? EventStatus::DRAFT )
			: EventStatus::PUBLISHED;
		$event->event_type    = $overrides['event_type'] ?? 'single';
		$event->venue_name    = array_key_exists( 'venue_name', $overrides ) ? $overrides['venue_name'] : 'The Venue';
		$event->venue_address = array_key_exists( 'venue_address', $overrides ) ? $overrides['venue_address'] : '123 Main St';
		$event->featured_image_id = $overrides['featured_image_id'] ?? 0;

		return $event;
	}

	/**
	 * Create a mock Occurrence with default properties.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Occurrence
	 */
	private function make_occurrence( array $overrides = array() ): Occurrence {
		$occ                    = new Occurrence();
		$occ->id                = $overrides['id'] ?? 10;
		$occ->event_id          = $overrides['event_id'] ?? 1;
		$occ->start_datetime    = $overrides['start_datetime'] ?? '2026-03-15 19:30:00';
		$occ->end_datetime      = $overrides['end_datetime'] ?? '2026-03-15 22:00:00';
		$occ->timezone          = $overrides['timezone'] ?? 'America/Chicago';
		$occ->status            = $overrides['status'] ?? 'scheduled';
		$occ->is_rescheduled    = (bool) ( $overrides['is_rescheduled'] ?? false );
		$occ->title_override    = $overrides['title_override'] ?? null;
		$occ->description_override = $overrides['description_override'] ?? null;
		$occ->featured_image_id = $overrides['featured_image_id'] ?? 0;

		return $occ;
	}

	// =========================================================================
	// build_event_data() Tests
	// =========================================================================

	public function test_basic_event_data_structure(): void {
		$event = $this->make_event();

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event );

		$this->assertEquals( 'https://schema.org', $data['@context'] );
		$this->assertEquals( 'Event', $data['@type'] );
		$this->assertEquals( 'Test Concert', $data['name'] );
		$this->assertEquals( 'https://schema.org/OfflineEventAttendanceMode', $data['eventAttendanceMode'] );
	}

	public function test_description_uses_excerpt_when_available(): void {
		$event = $this->make_event( array( 'excerpt' => 'Short summary', 'description' => 'Long description' ) );

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event );

		$this->assertEquals( 'Short summary', $data['description'] );
	}

	public function test_description_falls_back_to_description(): void {
		$event = $this->make_event( array( 'excerpt' => '', 'description' => 'Full description' ) );

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event );

		$this->assertEquals( 'Full description', $data['description'] );
	}

	public function test_no_description_when_empty(): void {
		$event = $this->make_event( array( 'excerpt' => '', 'description' => '' ) );

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event );

		$this->assertArrayNotHasKey( 'description', $data );
	}

	public function test_occurrence_dates_included(): void {
		$event = $this->make_event();
		$occ   = $this->make_occurrence();

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event, $occ );

		$this->assertArrayHasKey( 'startDate', $data );
		$this->assertArrayHasKey( 'endDate', $data );
		// ISO 8601 format should contain a T separator.
		$this->assertStringContainsString( 'T', $data['startDate'] );
		$this->assertStringContainsString( 'T', $data['endDate'] );
	}

	public function test_no_dates_without_occurrence(): void {
		$event = $this->make_event();

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event );

		$this->assertArrayNotHasKey( 'startDate', $data );
		$this->assertArrayNotHasKey( 'endDate', $data );
	}

	public function test_scheduled_event_status(): void {
		$event = $this->make_event();
		$occ   = $this->make_occurrence();

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event, $occ );

		$this->assertEquals( 'https://schema.org/EventScheduled', $data['eventStatus'] );
	}

	public function test_cancelled_event_status(): void {
		$event = $this->make_event();
		$occ   = $this->make_occurrence( array( 'status' => 'cancelled' ) );

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event, $occ );

		$this->assertEquals( 'https://schema.org/EventCancelled', $data['eventStatus'] );
	}

	public function test_rescheduled_event_status(): void {
		$event = $this->make_event();
		$occ   = $this->make_occurrence( array( 'is_rescheduled' => 1 ) );

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event, $occ );

		$this->assertEquals( 'https://schema.org/EventRescheduled', $data['eventStatus'] );
	}

	public function test_location_with_name_and_address(): void {
		$event = $this->make_event( array( 'venue_name' => 'CJAC', 'venue_address' => '836 Prior Ave N' ) );

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event );

		$this->assertEquals( 'Place', $data['location']['@type'] );
		$this->assertEquals( 'CJAC', $data['location']['name'] );
		$this->assertEquals( '836 Prior Ave N', $data['location']['address']['streetAddress'] );
	}

	public function test_no_location_when_empty(): void {
		$event = $this->make_event( array( 'venue_name' => null, 'venue_address' => null ) );

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event );

		$this->assertArrayNotHasKey( 'location', $data );
	}

	public function test_organizer_uses_site_name(): void {
		$event = $this->make_event();

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Celtic Junction' );
		Functions\when( 'home_url' )->justReturn( 'https://cjac.local' );

		$data = SchemaMarkup::build_event_data( $event );

		$this->assertEquals( 'Organization', $data['organizer']['@type'] );
		$this->assertEquals( 'Celtic Junction', $data['organizer']['name'] );
		$this->assertEquals( 'https://cjac.local', $data['organizer']['url'] );
	}

	public function test_image_from_event(): void {
		$event = $this->make_event( array( 'featured_image_id' => 42 ) );

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/image.jpg' );

		$data = SchemaMarkup::build_event_data( $event );

		$this->assertEquals( 'https://example.com/image.jpg', $data['image'] );
	}

	public function test_no_image_when_missing(): void {
		$event = $this->make_event( array( 'featured_image_id' => 0 ) );

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( false );

		$data = SchemaMarkup::build_event_data( $event );

		$this->assertArrayNotHasKey( 'image', $data );
	}

	public function test_datetime_format_iso8601(): void {
		$event = $this->make_event();
		$occ   = $this->make_occurrence( array(
			'start_datetime' => '2026-03-15 19:30:00',
			'timezone'       => 'America/Chicago',
		) );

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event, $occ );

		// Should be ISO 8601 with timezone offset.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['startDate'] );
	}

	public function test_invalid_timezone_falls_back(): void {
		$event = $this->make_event();
		$occ   = $this->make_occurrence( array( 'timezone' => 'Invalid/Zone' ) );

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event, $occ );

		// Should still produce a date, just with T separator fallback.
		$this->assertArrayHasKey( 'startDate', $data );
		$this->assertStringContainsString( 'T', $data['startDate'] );
	}

	public function test_filter_allows_modification(): void {
		$event = $this->make_event();

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event );

		// Verify the data structure is array that a filter could modify.
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( '@context', $data );
		$this->assertArrayHasKey( '@type', $data );
		$this->assertArrayHasKey( 'name', $data );
	}

	// =========================================================================
	// Virtual/Hybrid Event Tests
	// =========================================================================

	/**
	 * Test virtual event attendance mode.
	 *
	 * @return void
	 */
	public function test_virtual_event_attendance_mode(): void {
		$event             = $this->make_event( array( 'venue_name' => null, 'venue_address' => null ) );
		$event->is_virtual = true;
		$event->virtual_url = 'https://zoom.us/j/123';

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event );

		$this->assertEquals( 'https://schema.org/OnlineEventAttendanceMode', $data['eventAttendanceMode'] );
	}

	/**
	 * Test virtual event location is VirtualLocation.
	 *
	 * @return void
	 */
	public function test_virtual_event_virtual_location(): void {
		$event             = $this->make_event( array( 'venue_name' => null, 'venue_address' => null ) );
		$event->is_virtual = true;
		$event->virtual_url = 'https://zoom.us/j/123';

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event );

		$this->assertEquals( 'VirtualLocation', $data['location']['@type'] );
		$this->assertEquals( 'https://zoom.us/j/123', $data['location']['url'] );
	}

	/**
	 * Test hybrid event attendance mode.
	 *
	 * @return void
	 */
	public function test_hybrid_event_attendance_mode(): void {
		$event              = $this->make_event( array( 'venue_name' => 'CJAC', 'venue_address' => '836 Prior Ave N' ) );
		$event->is_virtual  = true;
		$event->virtual_url = 'https://zoom.us/j/456';

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event );

		$this->assertEquals( 'https://schema.org/MixedEventAttendanceMode', $data['eventAttendanceMode'] );
	}

	/**
	 * Test hybrid event has both Place and VirtualLocation.
	 *
	 * @return void
	 */
	public function test_hybrid_event_multiple_locations(): void {
		$event              = $this->make_event( array( 'venue_name' => 'CJAC', 'venue_address' => '836 Prior Ave N' ) );
		$event->is_virtual  = true;
		$event->virtual_url = 'https://zoom.us/j/456';

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event );

		// Hybrid should have array of two locations.
		$this->assertIsArray( $data['location'] );
		$this->assertCount( 2, $data['location'] );
		$this->assertEquals( 'Place', $data['location'][0]['@type'] );
		$this->assertEquals( 'VirtualLocation', $data['location'][1]['@type'] );
	}

	/**
	 * Test virtual event without URL has no location.
	 *
	 * @return void
	 */
	public function test_virtual_event_no_url_no_location(): void {
		$event             = $this->make_event( array( 'venue_name' => null, 'venue_address' => null ) );
		$event->is_virtual = true;

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$data = SchemaMarkup::build_event_data( $event );

		$this->assertArrayNotHasKey( 'location', $data );
	}
}
