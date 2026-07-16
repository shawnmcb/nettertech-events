<?php
/**
 * Event model unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;

/**
 * Test Event model functionality.
 */
class EventTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// from_row Tests
	// =========================================================================

	/**
	 * Test from_row creates Event from object.
	 *
	 * @return void
	 */
	public function test_from_row_creates_event_from_object(): void {
		$row = (object) array(
			'id'          => 1,
			'post_id'     => 100,
			'title'       => 'Test Event',
			'slug'        => 'test-event',
			'description' => 'Event description',
			'excerpt'     => 'Short excerpt',
			'status'      => 'published',
			'event_type'  => 'single',
			'venue_name'  => 'Test Venue',
		);

		$event = Event::from_row( $row );

		$this->assertSame( 1, $event->id );
		$this->assertSame( 100, $event->post_id );
		$this->assertSame( 'Test Event', $event->title );
		$this->assertSame( 'test-event', $event->slug );
		$this->assertSame( EventStatus::PUBLISHED, $event->status );
		$this->assertSame( 'single', $event->event_type );
		$this->assertSame( 'Test Venue', $event->venue_name );
	}

	/**
	 * Test from_row creates Event from array.
	 *
	 * @return void
	 */
	public function test_from_row_creates_event_from_array(): void {
		$row = array(
			'id'    => 2,
			'title' => 'Array Event',
			'slug'  => 'array-event',
		);

		$event = Event::from_row( $row );

		$this->assertSame( 2, $event->id );
		$this->assertSame( 'Array Event', $event->title );
	}

	/**
	 * Test from_row handles missing optional fields gracefully.
	 *
	 * @return void
	 */
	public function test_from_row_handles_missing_optional_fields(): void {
		$row = (object) array(
			'id'    => 1,
			'title' => 'Minimal Event',
			'slug'  => 'minimal-event',
		);

		$event = Event::from_row( $row );

		$this->assertSame( 1, $event->id );
		$this->assertNull( $event->venue_name );
		$this->assertNull( $event->series_id );
	}

	// =========================================================================
	// to_array Tests
	// =========================================================================

	/**
	 * Test to_array returns correct structure.
	 *
	 * @return void
	 */
	public function test_to_array_returns_correct_structure(): void {
		$event             = new Event();
		$event->title      = 'My Event';
		$event->slug       = 'my-event';
		$event->status     = EventStatus::PUBLISHED;
		$event->event_type = 'recurring';
		$event->venue_name = 'Test Venue';

		$array = $event->to_array();

		$this->assertSame( 'My Event', $array['title'] );
		$this->assertSame( 'my-event', $array['slug'] );
		$this->assertSame( 'published', $array['status'] );
		$this->assertSame( 'recurring', $array['event_type'] );
		$this->assertSame( 'Test Venue', $array['venue_name'] );
	}

	/**
	 * Test image_vertical_anchor round-trips through from_row and to_array (NTE-119).
	 *
	 * @return void
	 */
	public function test_image_vertical_anchor_round_trip(): void {
		// Stored value hydrates onto the model and serializes back unchanged.
		$event = Event::from_row(
			(object) array(
				'id'                    => 5,
				'title'                 => 'Anchored',
				'slug'                  => 'anchored',
				'image_vertical_anchor' => 'top',
			)
		);
		$this->assertSame( 'top', $event->image_vertical_anchor );
		$this->assertSame( 'top', $event->to_array()['image_vertical_anchor'] );

		// Missing column hydrates to null (existing rows pre-migration → center).
		$missing = Event::from_row(
			(object) array(
				'id'    => 6,
				'title' => 'No anchor',
				'slug'  => 'no-anchor',
			)
		);
		$this->assertNull( $missing->image_vertical_anchor );
		$this->assertNull( $missing->to_array()['image_vertical_anchor'] );
	}

	// =========================================================================
	// validate Tests
	// =========================================================================

	/**
	 * Test validate returns empty for valid event.
	 *
	 * @return void
	 */
	public function test_validate_returns_empty_for_valid_event(): void {
		$event         = new Event();
		$event->title  = 'Valid Event';
		$event->slug   = 'valid-event';
		$event->status = EventStatus::PUBLISHED;

		$errors = $event->validate();

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate requires title.
	 *
	 * @return void
	 */
	public function test_validate_requires_title(): void {
		$event       = new Event();
		$event->slug = 'test-slug';

		$errors = $event->validate();

		$this->assertNotEmpty( $errors );
		$this->assertContains( 'Event title is required.', $errors );
	}

	/**
	 * Test validate requires slug.
	 *
	 * @return void
	 */
	public function test_validate_requires_slug(): void {
		$event        = new Event();
		$event->title = 'Test Title';

		$errors = $event->validate();

		$this->assertContains( 'Event slug is required.', $errors );
	}

	/**
	 * Test that assigning an invalid status string throws a TypeError.
	 *
	 * The EventStatus enum type on the property enforces valid values
	 * at the language level, replacing the previous in_array() validation.
	 *
	 * @return void
	 */
	public function test_invalid_status_string_throws_type_error(): void {
		$this->expectException( \TypeError::class );

		$event         = new Event();
		$event->status = 'invalid'; // @phpstan-ignore assign.propertyType
	}

	/**
	 * Test validate rejects invalid event type.
	 *
	 * @return void
	 */
	public function test_validate_rejects_invalid_event_type(): void {
		$event             = new Event();
		$event->title      = 'Test';
		$event->slug       = 'test';
		$event->event_type = 'invalid';

		$errors = $event->validate();

		$this->assertContains( 'Invalid event type.', $errors );
	}

	/**
	 * Test validate requires recurrence rule for recurring events.
	 *
	 * @return void
	 */
	public function test_validate_requires_rrule_for_recurring(): void {
		$event             = new Event();
		$event->title      = 'Test';
		$event->slug       = 'test';
		$event->event_type = 'recurring';

		$errors = $event->validate();

		$this->assertContains( 'Recurring events require a recurrence rule.', $errors );
	}

	// =========================================================================
	// Status Helper Tests
	// =========================================================================

	/**
	 * Test is_published returns true for published events.
	 *
	 * @return void
	 */
	public function test_is_published_returns_true_when_published(): void {
		$event         = new Event();
		$event->status = EventStatus::PUBLISHED;

		$this->assertTrue( $event->is_published() );
	}

	/**
	 * Test is_published returns false for draft events.
	 *
	 * @return void
	 */
	public function test_is_published_returns_false_when_draft(): void {
		$event         = new Event();
		$event->status = EventStatus::DRAFT;

		$this->assertFalse( $event->is_published() );
	}

	/**
	 * Test is_recurring returns true for recurring events.
	 *
	 * @return void
	 */
	public function test_is_recurring_returns_true_for_recurring(): void {
		$event             = new Event();
		$event->event_type = 'recurring';

		$this->assertTrue( $event->is_recurring() );
	}

	/**
	 * Test is_recurring returns false for single events.
	 *
	 * @return void
	 */
	public function test_is_recurring_returns_false_for_single(): void {
		$event             = new Event();
		$event->event_type = 'single';

		$this->assertFalse( $event->is_recurring() );
	}

	/**
	 * Test has_series returns true when series_id set.
	 *
	 * @return void
	 */
	public function test_has_series_returns_true_when_series_id_set(): void {
		$event            = new Event();
		$event->series_id = 5;

		$this->assertTrue( $event->has_series() );
	}

	/**
	 * Test has_series returns false when no series.
	 *
	 * @return void
	 */
	public function test_has_series_returns_false_when_no_series(): void {
		$event = new Event();

		$this->assertFalse( $event->has_series() );
	}

	// =========================================================================
	// Constants Tests
	// =========================================================================

	/**
	 * Test STATUSES constant contains expected values.
	 *
	 * @return void
	 */
	public function test_statuses_constant_contains_expected_values(): void {
		$this->assertContains( 'draft', Event::STATUSES );
		$this->assertContains( 'published', Event::STATUSES );
		$this->assertContains( 'cancelled', Event::STATUSES );
		$this->assertContains( 'postponed', Event::STATUSES );
	}

	/**
	 * Test TYPES constant contains expected values.
	 *
	 * @return void
	 */
	public function test_types_constant_contains_expected_values(): void {
		$this->assertContains( 'single', Event::TYPES );
		$this->assertContains( 'recurring', Event::TYPES );
		$this->assertContains( 'series_parent', Event::TYPES );
	}

	// =========================================================================
	// get_formats Tests
	// =========================================================================

	/**
	 * Test get_formats returns correct number of format specifiers.
	 *
	 * @return void
	 */
	public function test_get_formats_returns_correct_count(): void {
		$event   = new Event();
		$formats = $event->get_formats();
		$array   = $event->to_array();

		$this->assertCount( count( $array ), $formats );
	}

	// =========================================================================
	// Additional from_row Tests
	// =========================================================================

	/**
	 * Test from_row handles all fields.
	 *
	 * @return void
	 */
	public function test_from_row_handles_all_fields(): void {
		$row = (object) array(
			'id'                  => 1,
			'post_id'             => 100,
			'title'               => 'Full Event',
			'slug'                => 'full-event',
			'description'         => 'Full description',
			'excerpt'             => 'Short excerpt',
			'featured_image_id'   => 42,
			'status'              => 'published',
			'event_type'          => 'recurring',
			'series_id'           => 5,
			'venue_name'          => 'Venue Name',
			'venue_address'       => '123 Main St',
			'recurrence_rule'     => 'FREQ=WEEKLY',
			'recurrence_end_date' => '2026-12-31',
			'created_at'          => '2026-01-01 00:00:00',
			'updated_at'          => '2026-01-02 00:00:00',
		);

		$event = Event::from_row( $row );

		$this->assertSame( 1, $event->id );
		$this->assertSame( 100, $event->post_id );
		$this->assertSame( 'Full Event', $event->title );
		$this->assertSame( 'full-event', $event->slug );
		$this->assertSame( 'Full description', $event->description );
		$this->assertSame( 'Short excerpt', $event->excerpt );
		$this->assertSame( 42, $event->featured_image_id );
		$this->assertSame( EventStatus::PUBLISHED, $event->status );
		$this->assertSame( 'recurring', $event->event_type );
		$this->assertSame( 5, $event->series_id );
		$this->assertSame( 'Venue Name', $event->venue_name );
		$this->assertSame( '123 Main St', $event->venue_address );
		$this->assertSame( 'FREQ=WEEKLY', $event->recurrence_rule );
		$this->assertSame( '2026-12-31', $event->recurrence_end_date );
	}

	/**
	 * Test from_row handles invalid layout_config JSON gracefully.
	 *
	 * @return void
	 */
	public function test_from_row_handles_invalid_layout_config_json(): void {
		$row = (object) array(
			'id'            => 1,
			'title'         => 'Test',
			'slug'          => 'test',
			'layout_config' => 'invalid json',
		);

		$event = Event::from_row( $row );

		$this->assertNull( $event->layout_config );
	}

	// =========================================================================
	// Additional to_array Tests
	// =========================================================================

	/**
	 * Test to_array includes all fields.
	 *
	 * @return void
	 */
	public function test_to_array_includes_all_fields(): void {
		$event                      = new Event();
		$event->post_id             = 100;
		$event->title               = 'Test';
		$event->slug                = 'test';
		$event->description         = 'Description';
		$event->excerpt             = 'Excerpt';
		$event->featured_image_id   = 42;
		$event->status              = EventStatus::PUBLISHED;
		$event->event_type          = 'single';
		$event->series_id           = 5;
		$event->venue_name          = 'Venue';
		$event->venue_address       = 'Address';
		$event->recurrence_rule     = 'FREQ=DAILY';
		$event->recurrence_end_date = '2026-12-31';

		$array = $event->to_array();

		$this->assertSame( 100, $array['post_id'] );
		$this->assertSame( 'Test', $array['title'] );
		$this->assertSame( 'test', $array['slug'] );
		$this->assertSame( 'Description', $array['description'] );
		$this->assertSame( 'Excerpt', $array['excerpt'] );
		$this->assertSame( 42, $array['featured_image_id'] );
		$this->assertSame( 'published', $array['status'] );
		$this->assertSame( 'single', $array['event_type'] );
		$this->assertSame( 5, $array['series_id'] );
		$this->assertSame( 'Venue', $array['venue_name'] );
		$this->assertSame( 'Address', $array['venue_address'] );
		$this->assertSame( 'FREQ=DAILY', $array['recurrence_rule'] );
		$this->assertSame( '2026-12-31', $array['recurrence_end_date'] );
	}

	// =========================================================================
	// Additional Status Tests
	// =========================================================================

	/**
	 * Test is_published returns false for cancelled events.
	 *
	 * @return void
	 */
	public function test_is_published_returns_false_when_cancelled(): void {
		$event         = new Event();
		$event->status = EventStatus::CANCELLED;

		$this->assertFalse( $event->is_published() );
	}

	/**
	 * Test is_published returns false for postponed events.
	 *
	 * @return void
	 */
	public function test_is_published_returns_false_when_postponed(): void {
		$event         = new Event();
		$event->status = EventStatus::POSTPONED;

		$this->assertFalse( $event->is_published() );
	}

	/**
	 * Test is_recurring returns false for series_parent.
	 *
	 * @return void
	 */
	public function test_is_recurring_returns_false_for_series_parent(): void {
		$event             = new Event();
		$event->event_type = 'series_parent';

		$this->assertFalse( $event->is_recurring() );
	}

	// =========================================================================
	// Additional Validation Tests
	// =========================================================================

	/**
	 * Test validate accepts all valid statuses.
	 *
	 * @return void
	 */
	public function test_validate_accepts_draft_status(): void {
		$event         = new Event();
		$event->title  = 'Test';
		$event->slug   = 'test';
		$event->status = EventStatus::DRAFT;

		$errors = $event->validate();

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate accepts cancelled status.
	 *
	 * @return void
	 */
	public function test_validate_accepts_cancelled_status(): void {
		$event         = new Event();
		$event->title  = 'Test';
		$event->slug   = 'test';
		$event->status = EventStatus::CANCELLED;

		$errors = $event->validate();

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate accepts postponed status.
	 *
	 * @return void
	 */
	public function test_validate_accepts_postponed_status(): void {
		$event         = new Event();
		$event->title  = 'Test';
		$event->slug   = 'test';
		$event->status = EventStatus::POSTPONED;

		$errors = $event->validate();

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate accepts series_parent type.
	 *
	 * @return void
	 */
	public function test_validate_accepts_series_parent_type(): void {
		$event             = new Event();
		$event->title      = 'Test';
		$event->slug       = 'test';
		$event->event_type = 'series_parent';

		$errors = $event->validate();

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate passes for recurring with rrule.
	 *
	 * @return void
	 */
	public function test_validate_passes_for_recurring_with_rrule(): void {
		$event                  = new Event();
		$event->title           = 'Test';
		$event->slug            = 'test';
		$event->event_type      = 'recurring';
		$event->recurrence_rule = 'FREQ=WEEKLY';

		$errors = $event->validate();

		$this->assertEmpty( $errors );
	}

	// =========================================================================
	// Featured Image Tests
	// =========================================================================

	/**
	 * Test get_featured_image_url returns null when no image.
	 *
	 * @return void
	 */
	public function test_get_featured_image_url_returns_null_when_no_image(): void {
		$event = new Event();

		$this->assertNull( $event->get_featured_image_url() );
	}

	/**
	 * Test get_featured_image_url returns null when image_id is zero.
	 *
	 * @return void
	 */
	public function test_get_featured_image_url_returns_null_when_zero(): void {
		$event                    = new Event();
		$event->featured_image_id = 0;

		$this->assertNull( $event->get_featured_image_url() );
	}

	// =========================================================================
	// Custom Fields Tests
	// =========================================================================

	/**
	 * Test from_row decodes custom_fields JSON.
	 *
	 * @return void
	 */
	public function test_from_row_decodes_custom_fields_json(): void {
		$custom = array(
			'source'     => 'eventbrite',
			'external_id' => 'EVT-12345',
		);

		$row = (object) array(
			'id'            => 1,
			'title'         => 'Test',
			'slug'          => 'test',
			'custom_fields' => wp_json_encode( $custom ),
		);

		$event = Event::from_row( $row );

		$this->assertIsArray( $event->custom_fields );
		$this->assertSame( 'eventbrite', $event->custom_fields['source'] );
		$this->assertSame( 'EVT-12345', $event->custom_fields['external_id'] );
	}

	/**
	 * Test from_row returns null for empty custom_fields.
	 *
	 * @return void
	 */
	public function test_from_row_returns_null_for_empty_custom_fields(): void {
		$row = (object) array(
			'id'    => 1,
			'title' => 'Test',
			'slug'  => 'test',
		);

		$event = Event::from_row( $row );

		$this->assertNull( $event->custom_fields );
	}

	/**
	 * Test from_row returns null for invalid custom_fields JSON.
	 *
	 * @return void
	 */
	public function test_from_row_returns_null_for_invalid_custom_fields_json(): void {
		$row = (object) array(
			'id'            => 1,
			'title'         => 'Test',
			'slug'          => 'test',
			'custom_fields' => 'not valid json',
		);

		$event = Event::from_row( $row );

		$this->assertNull( $event->custom_fields );
	}

	/**
	 * Test to_array encodes custom_fields to JSON string.
	 *
	 * @return void
	 */
	public function test_to_array_encodes_custom_fields_to_json(): void {
		$event                = new Event();
		$event->title         = 'Test';
		$event->slug          = 'test';
		$event->custom_fields = array( 'key' => 'value' );

		$array = $event->to_array();

		$this->assertSame( '{"key":"value"}', $array['custom_fields'] );
	}

	/**
	 * Test to_array returns null for null custom_fields.
	 *
	 * @return void
	 */
	public function test_to_array_returns_null_for_null_custom_fields(): void {
		$event        = new Event();
		$event->title = 'Test';
		$event->slug  = 'test';

		$array = $event->to_array();

		$this->assertNull( $array['custom_fields'] );
	}

	/**
	 * Test custom_fields round-trip: from_row then to_array preserves data.
	 *
	 * @return void
	 */
	public function test_custom_fields_round_trip(): void {
		$custom = array(
			'source'    => 'import',
			'nested'    => array( 'a' => 1, 'b' => 2 ),
			'is_legacy' => true,
		);

		$row = (object) array(
			'id'            => 1,
			'title'         => 'Test',
			'slug'          => 'test',
			'custom_fields' => wp_json_encode( $custom ),
		);

		$event = Event::from_row( $row );
		$array = $event->to_array();

		// Re-decode the JSON from to_array to verify round-trip.
		$decoded = json_decode( $array['custom_fields'], true );

		$this->assertSame( $custom, $decoded );
	}

	// =========================================================================
	// Virtual/Hybrid Event Tests
	// =========================================================================

	/**
	 * Test from_row decodes is_virtual boolean.
	 *
	 * @return void
	 */
	public function test_from_row_decodes_is_virtual(): void {
		$row = (object) array(
			'id'          => 1,
			'title'       => 'Webinar',
			'slug'        => 'webinar',
			'is_virtual'  => '1',
			'virtual_url' => 'https://zoom.us/j/123',
		);

		$event = Event::from_row( $row );

		$this->assertTrue( $event->is_virtual );
		$this->assertSame( 'https://zoom.us/j/123', $event->virtual_url );
	}

	/**
	 * Test from_row defaults is_virtual to null.
	 *
	 * @return void
	 */
	public function test_from_row_defaults_is_virtual_null(): void {
		$row = (object) array(
			'id'    => 1,
			'title' => 'Test',
			'slug'  => 'test',
		);

		$event = Event::from_row( $row );

		$this->assertNull( $event->is_virtual );
		$this->assertNull( $event->virtual_url );
	}

	/**
	 * Test to_array includes virtual fields.
	 *
	 * @return void
	 */
	public function test_to_array_includes_virtual_fields(): void {
		$event              = new Event();
		$event->title       = 'Webinar';
		$event->slug        = 'webinar';
		$event->is_virtual  = true;
		$event->virtual_url = 'https://zoom.us/j/123';

		$array = $event->to_array();

		$this->assertTrue( $array['is_virtual'] );
		$this->assertSame( 'https://zoom.us/j/123', $array['virtual_url'] );
	}

	/**
	 * Test is_virtual_event returns true for virtual events.
	 *
	 * @return void
	 */
	public function test_is_virtual_event_returns_true(): void {
		$event             = new Event();
		$event->is_virtual = true;

		$this->assertTrue( $event->is_virtual_event() );
	}

	/**
	 * Test is_virtual_event returns false for non-virtual events.
	 *
	 * @return void
	 */
	public function test_is_virtual_event_returns_false(): void {
		$event             = new Event();
		$event->is_virtual = null;

		$this->assertFalse( $event->is_virtual_event() );
	}

	/**
	 * Test is_hybrid returns true for virtual events with a venue.
	 *
	 * @return void
	 */
	public function test_is_hybrid_returns_true(): void {
		$event              = new Event();
		$event->is_virtual  = true;
		$event->venue_name  = 'Concert Hall';
		$event->virtual_url = 'https://youtube.com/live';

		$this->assertTrue( $event->is_hybrid() );
	}

	/**
	 * Test is_hybrid returns false for virtual-only events.
	 *
	 * @return void
	 */
	public function test_is_hybrid_returns_false_for_virtual_only(): void {
		$event              = new Event();
		$event->is_virtual  = true;
		$event->virtual_url = 'https://youtube.com/live';

		$this->assertFalse( $event->is_hybrid() );
	}

	/**
	 * Test get_attendance_mode for offline events.
	 *
	 * @return void
	 */
	public function test_attendance_mode_offline(): void {
		$event = new Event();

		$this->assertSame( 'https://schema.org/OfflineEventAttendanceMode', $event->get_attendance_mode() );
	}

	/**
	 * Test get_attendance_mode for online events.
	 *
	 * @return void
	 */
	public function test_attendance_mode_online(): void {
		$event             = new Event();
		$event->is_virtual = true;

		$this->assertSame( 'https://schema.org/OnlineEventAttendanceMode', $event->get_attendance_mode() );
	}

	/**
	 * Test get_attendance_mode for hybrid events.
	 *
	 * @return void
	 */
	public function test_attendance_mode_mixed(): void {
		$event              = new Event();
		$event->is_virtual  = true;
		$event->venue_name  = 'Venue';

		$this->assertSame( 'https://schema.org/MixedEventAttendanceMode', $event->get_attendance_mode() );
	}
}
