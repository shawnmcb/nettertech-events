<?php
/**
 * Occurrence model unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Models;

use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;

/**
 * Test Occurrence model functionality.
 */
class OccurrenceTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// from_row Tests
	// =========================================================================

	/**
	 * Test from_row creates Occurrence from object.
	 *
	 * @return void
	 */
	public function test_from_row_creates_occurrence_from_object(): void {
		$row = (object) array(
			'id'             => 1,
			'event_id'       => 10,
			'start_datetime' => '2026-06-15 19:00:00',
			'end_datetime'   => '2026-06-15 21:00:00',
			'all_day'        => 0,
			'timezone'       => 'America/New_York',
			'status'         => 'scheduled',
			'capacity'       => 100,
		);

		$occurrence = Occurrence::from_row( $row );

		$this->assertSame( 1, $occurrence->id );
		$this->assertSame( 10, $occurrence->event_id );
		$this->assertSame( '2026-06-15 19:00:00', $occurrence->start_datetime );
		$this->assertSame( '2026-06-15 21:00:00', $occurrence->end_datetime );
		$this->assertFalse( $occurrence->all_day );
		$this->assertSame( 'America/New_York', $occurrence->timezone );
		$this->assertSame( 100, $occurrence->capacity );
	}

	/**
	 * Test from_row handles all_day flag.
	 *
	 * @return void
	 */
	public function test_from_row_handles_all_day_flag(): void {
		$row = (object) array(
			'id'      => 1,
			'all_day' => 1,
		);

		$occurrence = Occurrence::from_row( $row );

		$this->assertTrue( $occurrence->all_day );
	}

	/**
	 * Test from_row hydrates the per-occurrence override columns (NTE-077).
	 *
	 * @return void
	 */
	public function test_from_row_hydrates_override_columns(): void {
		$row = (object) array(
			'id'                     => 7,
			'event_id'               => 3,
			'is_override'            => 1,
			'venue_name_override'    => 'Annex Hall',
			'venue_address_override' => '123 Side St',
			'virtual_url_override'   => 'https://example.test/stream',
		);

		$occurrence = Occurrence::from_row( $row );

		$this->assertTrue( $occurrence->is_override );
		$this->assertSame( 'Annex Hall', $occurrence->venue_name_override );
		$this->assertSame( '123 Side St', $occurrence->venue_address_override );
		$this->assertSame( 'https://example.test/stream', $occurrence->virtual_url_override );
	}

	/**
	 * Test from_row defaults override columns when absent.
	 *
	 * @return void
	 */
	public function test_from_row_defaults_override_columns_when_absent(): void {
		$occurrence = Occurrence::from_row( (object) array( 'id' => 1 ) );

		$this->assertFalse( $occurrence->is_override );
		$this->assertNull( $occurrence->venue_name_override );
		$this->assertNull( $occurrence->venue_address_override );
		$this->assertNull( $occurrence->virtual_url_override );
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
		$occurrence                 = new Occurrence();
		$occurrence->event_id       = 5;
		$occurrence->start_datetime = '2026-07-01 10:00:00';
		$occurrence->end_datetime   = '2026-07-01 12:00:00';
		$occurrence->all_day        = true;
		$occurrence->status         = 'scheduled';

		$array = $occurrence->to_array();

		$this->assertSame( 5, $array['event_id'] );
		$this->assertSame( '2026-07-01 10:00:00', $array['start_datetime'] );
		$this->assertSame( 1, $array['all_day'] );
		$this->assertSame( 'scheduled', $array['status'] );
	}

	// =========================================================================
	// validate Tests
	// =========================================================================

	/**
	 * Test validate returns empty for valid occurrence.
	 *
	 * @return void
	 */
	public function test_validate_returns_empty_for_valid_occurrence(): void {
		$occurrence                 = new Occurrence();
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-06-15 19:00:00';
		$occurrence->end_datetime   = '2026-06-15 21:00:00';
		$occurrence->status         = 'scheduled';

		$errors = $occurrence->validate();

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate requires event_id.
	 *
	 * @return void
	 */
	public function test_validate_requires_event_id(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-15 19:00:00';
		$occurrence->end_datetime   = '2026-06-15 21:00:00';

		$errors = $occurrence->validate();

		$this->assertContains( 'Event ID is required.', $errors );
	}

	/**
	 * Test validate requires start_datetime.
	 *
	 * @return void
	 */
	public function test_validate_requires_start_datetime(): void {
		$occurrence               = new Occurrence();
		$occurrence->event_id     = 1;
		$occurrence->end_datetime = '2026-06-15 21:00:00';

		$errors = $occurrence->validate();

		$this->assertContains( 'Start date/time is required.', $errors );
	}

	/**
	 * Test validate rejects end before start.
	 *
	 * @return void
	 */
	public function test_validate_rejects_end_before_start(): void {
		$occurrence                 = new Occurrence();
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-06-15 21:00:00';
		$occurrence->end_datetime   = '2026-06-15 19:00:00';

		$errors = $occurrence->validate();

		$this->assertContains( 'End time cannot be before start time.', $errors );
	}

	/**
	 * Test validate rejects invalid status.
	 *
	 * @return void
	 */
	public function test_validate_rejects_invalid_status(): void {
		$occurrence                 = new Occurrence();
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-06-15 19:00:00';
		$occurrence->end_datetime   = '2026-06-15 21:00:00';
		$occurrence->status         = 'invalid';

		$errors = $occurrence->validate();

		$this->assertContains( 'Invalid occurrence status.', $errors );
	}

	// =========================================================================
	// DateTime Helper Tests
	// =========================================================================

	/**
	 * Test get_start returns DateTimeImmutable.
	 *
	 * @return void
	 */
	public function test_get_start_returns_datetime_immutable(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-15 19:00:00';
		$occurrence->timezone       = 'UTC';

		$start = $occurrence->get_start();

		$this->assertInstanceOf( \DateTimeImmutable::class, $start );
		$this->assertSame( '2026-06-15', $start->format( 'Y-m-d' ) );
		$this->assertSame( '19:00:00', $start->format( 'H:i:s' ) );
	}

	/**
	 * Test get_end returns DateTimeImmutable.
	 *
	 * @return void
	 */
	public function test_get_end_returns_datetime_immutable(): void {
		$occurrence               = new Occurrence();
		$occurrence->end_datetime = '2026-06-15 21:30:00';
		$occurrence->timezone     = 'UTC';

		$end = $occurrence->get_end();

		$this->assertInstanceOf( \DateTimeImmutable::class, $end );
		$this->assertSame( '21:30:00', $end->format( 'H:i:s' ) );
	}

	/**
	 * Test get_duration_minutes calculates correctly.
	 *
	 * @return void
	 */
	public function test_get_duration_minutes_calculates_correctly(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-15 19:00:00';
		$occurrence->end_datetime   = '2026-06-15 21:30:00';

		$duration = $occurrence->get_duration_minutes();

		$this->assertSame( 150, $duration );
	}

	/**
	 * Test get_formatted_date returns correct format.
	 *
	 * @return void
	 */
	public function test_get_formatted_date_returns_correct_format(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-12-31 19:00:00';
		$occurrence->timezone       = 'UTC';

		$formatted = $occurrence->get_formatted_date();

		$this->assertSame( 'Dec 31, 2026', $formatted );
	}

	// =========================================================================
	// Status Helper Tests
	// =========================================================================

	/**
	 * Test is_scheduled returns true for scheduled status.
	 *
	 * @return void
	 */
	public function test_is_scheduled_returns_true_for_scheduled(): void {
		$occurrence         = new Occurrence();
		$occurrence->status = 'scheduled';

		$this->assertTrue( $occurrence->is_scheduled() );
	}

	/**
	 * Test is_cancelled returns true for cancelled status.
	 *
	 * @return void
	 */
	public function test_is_cancelled_returns_true_for_cancelled(): void {
		$occurrence         = new Occurrence();
		$occurrence->status = 'cancelled';

		$this->assertTrue( $occurrence->is_cancelled() );
	}

	/**
	 * Test is_past returns true for past occurrence.
	 *
	 * @return void
	 */
	public function test_is_past_returns_true_for_past_occurrence(): void {
		$occurrence               = new Occurrence();
		$occurrence->end_datetime = '2020-01-01 12:00:00';

		$this->assertTrue( $occurrence->is_past() );
	}

	/**
	 * Test is_past returns false for future occurrence.
	 *
	 * @return void
	 */
	public function test_is_past_returns_false_for_future_occurrence(): void {
		$occurrence               = new Occurrence();
		$occurrence->end_datetime = '2099-12-31 23:59:59';

		$this->assertFalse( $occurrence->is_past() );
	}

	/**
	 * Test has_ended returns true for ended occurrence.
	 *
	 * @return void
	 */
	public function test_has_ended_returns_true_for_ended(): void {
		$occurrence               = new Occurrence();
		$occurrence->end_datetime = '2020-01-01 12:00:00';

		$this->assertTrue( $occurrence->has_ended() );
	}

	/**
	 * The stored wall-clock is interpreted in the occurrence's authoring zone, NOT
	 * the (UTC) server default. This is the core of the timezone fix: get_end()'s
	 * instant must reflect the authoring zone regardless of the PHP server timezone.
	 *
	 * @return void
	 */
	public function test_get_end_uses_authoring_zone_not_server_default(): void {
		$occurrence               = new Occurrence();
		$occurrence->end_datetime = '2026-07-15 12:00:00';
		$occurrence->timezone     = 'America/Chicago';

		$expected = ( new \DateTimeImmutable( '2026-07-15 12:00:00', new \DateTimeZone( 'America/Chicago' ) ) )->getTimestamp();
		$this->assertSame( $expected, $occurrence->get_end()->getTimestamp() );

		// Must NOT be read as UTC (the bug): 12:00 CDT = 17:00 UTC, a different instant.
		$utc_misread = ( new \DateTimeImmutable( '2026-07-15 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$this->assertNotSame( $utc_misread, $occurrence->get_end()->getTimestamp() );
	}

	/**
	 * Interpretation is DST-aware: the same wall-clock resolves to a different UTC
	 * instant in summer (CDT, UTC-5) vs winter (CST, UTC-6) — a fixed offset would
	 * be wrong half the year.
	 *
	 * @return void
	 */
	public function test_authoring_zone_interpretation_is_dst_aware(): void {
		$summer                 = new Occurrence();
		$summer->start_datetime = '2026-07-15 12:00:00';
		$summer->timezone       = 'America/Chicago';

		$winter                 = new Occurrence();
		$winter->start_datetime = '2026-01-15 12:00:00';
		$winter->timezone       = 'America/Chicago';

		// 12:00 CDT = 17:00 UTC; 12:00 CST = 18:00 UTC.
		$this->assertSame( '17', gmdate( 'H', $summer->get_start()->getTimestamp() ) );
		$this->assertSame( '18', gmdate( 'H', $winter->get_start()->getTimestamp() ) );
	}

	/**
	 * resolve_timezone() falls back to the site zone (wp_timezone()) when the stored
	 * column is empty/invalid, so a wall-clock is never misread as UTC.
	 *
	 * @return void
	 */
	public function test_get_start_falls_back_to_site_zone_when_timezone_empty(): void {
		\Brain\Monkey\Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Chicago' ) );

		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-07-15 12:00:00';
		$occurrence->timezone       = '';

		$expected = ( new \DateTimeImmutable( '2026-07-15 12:00:00', new \DateTimeZone( 'America/Chicago' ) ) )->getTimestamp();
		$this->assertSame( $expected, $occurrence->get_start()->getTimestamp() );
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
		$this->assertContains( 'scheduled', Occurrence::STATUSES );
		$this->assertContains( 'cancelled', Occurrence::STATUSES );
		$this->assertContains( 'postponed', Occurrence::STATUSES );
		$this->assertContains( 'completed', Occurrence::STATUSES );
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
		$occurrence                 = new Occurrence();
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-06-15 19:00:00';
		$occurrence->end_datetime   = '2026-06-15 21:00:00';
		$formats                    = $occurrence->get_formats();
		$array                      = $occurrence->to_array();

		$this->assertCount( count( $array ), $formats );
	}

	// =========================================================================
	// Additional DateTime Helper Tests
	// =========================================================================

	/**
	 * Test get_start_time returns All Day for all-day events.
	 *
	 * @return void
	 */
	public function test_get_start_time_returns_all_day(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-15 00:00:00';
		$occurrence->all_day        = true;

		$this->assertSame( 'All Day', $occurrence->get_start_time() );
	}

	/**
	 * Test get_end_time returns empty for all-day events.
	 *
	 * @return void
	 */
	public function test_get_end_time_returns_empty_for_all_day(): void {
		$occurrence               = new Occurrence();
		$occurrence->end_datetime = '2026-06-15 23:59:59';
		$occurrence->all_day      = true;

		$this->assertSame( '', $occurrence->get_end_time() );
	}

	/**
	 * Test get_formatted_time returns All Day for all-day events.
	 *
	 * @return void
	 */
	public function test_get_formatted_time_returns_all_day(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-15 00:00:00';
		$occurrence->all_day        = true;

		$this->assertSame( 'All Day', $occurrence->get_formatted_time() );
	}

	/**
	 * Test is_happening_now returns true for current event.
	 *
	 * @return void
	 */
	public function test_is_happening_now_returns_true_for_current(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );
		$occurrence->end_datetime   = gmdate( 'Y-m-d H:i:s', strtotime( '+1 hour' ) );

		$this->assertTrue( $occurrence->is_happening_now() );
	}

	/**
	 * Test is_happening_now returns false for future event.
	 *
	 * @return void
	 */
	public function test_is_happening_now_returns_false_for_future(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2099-01-01 10:00:00';
		$occurrence->end_datetime   = '2099-01-01 12:00:00';

		$this->assertFalse( $occurrence->is_happening_now() );
	}

	/**
	 * Test is_happening_now returns false for past event.
	 *
	 * @return void
	 */
	public function test_is_happening_now_returns_false_for_past(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2020-01-01 10:00:00';
		$occurrence->end_datetime   = '2020-01-01 12:00:00';

		$this->assertFalse( $occurrence->is_happening_now() );
	}

	// =========================================================================
	// Additional Status Tests
	// =========================================================================

	/**
	 * Test is_scheduled returns false for non-scheduled status.
	 *
	 * @return void
	 */
	public function test_is_scheduled_returns_false_for_cancelled(): void {
		$occurrence         = new Occurrence();
		$occurrence->status = 'cancelled';

		$this->assertFalse( $occurrence->is_scheduled() );
	}

	/**
	 * Test is_cancelled returns false for scheduled status.
	 *
	 * @return void
	 */
	public function test_is_cancelled_returns_false_for_scheduled(): void {
		$occurrence         = new Occurrence();
		$occurrence->status = 'scheduled';

		$this->assertFalse( $occurrence->is_cancelled() );
	}

	// =========================================================================
	// Override Tests
	// =========================================================================

	/**
	 * Test get_title returns title_override when set.
	 *
	 * @return void
	 */
	public function test_get_title_returns_override(): void {
		$occurrence                 = new Occurrence();
		$occurrence->title_override = 'Special Night';

		$this->assertSame( 'Special Night', $occurrence->get_title() );
	}

	/**
	 * Test get_description returns description_override when set.
	 *
	 * @return void
	 */
	public function test_get_description_returns_override(): void {
		$occurrence                       = new Occurrence();
		$occurrence->description_override = 'A special performance.';

		$this->assertSame( 'A special performance.', $occurrence->get_description() );
	}

	/**
	 * Test get_featured_image_id returns override when set.
	 *
	 * @return void
	 */
	public function test_get_featured_image_id_returns_override(): void {
		$occurrence                    = new Occurrence();
		$occurrence->featured_image_id = 42;

		$this->assertSame( 42, $occurrence->get_featured_image_id() );
	}

	/**
	 * Test get_venue_name returns venue_name_override when set.
	 *
	 * @return void
	 */
	public function test_get_venue_name_returns_override(): void {
		$occurrence                      = new Occurrence();
		$occurrence->venue_name_override = 'Annex Hall';

		$this->assertSame( 'Annex Hall', $occurrence->get_venue_name() );
	}

	/**
	 * Test get_venue_address returns venue_address_override when set.
	 *
	 * @return void
	 */
	public function test_get_venue_address_returns_override(): void {
		$occurrence                         = new Occurrence();
		$occurrence->venue_address_override = '123 Side St';

		$this->assertSame( '123 Side St', $occurrence->get_venue_address() );
	}

	/**
	 * Test get_virtual_url returns virtual_url_override when set.
	 *
	 * @return void
	 */
	public function test_get_virtual_url_returns_override(): void {
		$occurrence                       = new Occurrence();
		$occurrence->virtual_url_override = 'https://example.test/stream';

		$this->assertSame( 'https://example.test/stream', $occurrence->get_virtual_url() );
	}

	/**
	 * Test get_venue_name falls back to the parent event when no override is set.
	 *
	 * @return void
	 */
	public function test_get_venue_name_falls_back_to_event(): void {
		$event             = new Event();
		$event->id         = 5;
		$event->venue_name = 'Main Hall';

		$occurrence = new Occurrence();
		$occurrence->set_event( $event );

		$this->assertSame( 'Main Hall', $occurrence->get_venue_name() );
	}

	// =========================================================================
	// from_row Additional Tests
	// =========================================================================

	/**
	 * Test from_row handles array input.
	 *
	 * @return void
	 */
	public function test_from_row_handles_array_input(): void {
		$row = array(
			'id'             => 5,
			'event_id'       => 10,
			'start_datetime' => '2026-06-15 19:00:00',
			'end_datetime'   => '2026-06-15 21:00:00',
			'status'         => 'scheduled',
		);

		$occurrence = Occurrence::from_row( $row );

		$this->assertSame( 5, $occurrence->id );
		$this->assertSame( 10, $occurrence->event_id );
		$this->assertSame( 'scheduled', $occurrence->status );
	}

	/**
	 * Test from_row handles missing fields with defaults.
	 *
	 * @return void
	 */
	public function test_from_row_handles_missing_fields(): void {
		$row = (object) array(
			'id' => 1,
		);

		$occurrence = Occurrence::from_row( $row );

		$this->assertSame( 1, $occurrence->id );
		$this->assertSame( 0, $occurrence->event_id );
		$this->assertSame( '', $occurrence->start_datetime );
		$this->assertSame( 'UTC', $occurrence->timezone );
		$this->assertSame( 'scheduled', $occurrence->status );
		$this->assertSame( 1, $occurrence->sequence_number );
	}

	/**
	 * Test from_row handles override fields.
	 *
	 * @return void
	 */
	public function test_from_row_handles_override_fields(): void {
		$row = (object) array(
			'id'                   => 1,
			'title_override'       => 'Custom Title',
			'description_override' => 'Custom Description',
			'featured_image_id'    => 99,
		);

		$occurrence = Occurrence::from_row( $row );

		$this->assertSame( 'Custom Title', $occurrence->title_override );
		$this->assertSame( 'Custom Description', $occurrence->description_override );
		$this->assertSame( 99, $occurrence->featured_image_id );
	}

	// =========================================================================
	// Validation Additional Tests
	// =========================================================================

	/**
	 * Test validate requires end_datetime.
	 *
	 * @return void
	 */
	public function test_validate_requires_end_datetime(): void {
		$occurrence                 = new Occurrence();
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-06-15 19:00:00';

		$errors = $occurrence->validate();

		$this->assertContains( 'End date/time is required.', $errors );
	}

	/**
	 * Test validate accepts valid statuses.
	 *
	 * @return void
	 */
	public function test_validate_accepts_postponed_status(): void {
		$occurrence                 = new Occurrence();
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-06-15 19:00:00';
		$occurrence->end_datetime   = '2026-06-15 21:00:00';
		$occurrence->status         = 'postponed';

		$errors = $occurrence->validate();

		$this->assertEmpty( $errors );
	}

	/**
	 * Test validate accepts completed status.
	 *
	 * @return void
	 */
	public function test_validate_accepts_completed_status(): void {
		$occurrence                 = new Occurrence();
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-06-15 19:00:00';
		$occurrence->end_datetime   = '2026-06-15 21:00:00';
		$occurrence->status         = 'completed';

		$errors = $occurrence->validate();

		$this->assertEmpty( $errors );
	}

	// =========================================================================
	// to_array Tests
	// =========================================================================

	/**
	 * Test to_array includes all fields.
	 *
	 * @return void
	 */
	public function test_to_array_includes_all_fields(): void {
		$occurrence                       = new Occurrence();
		$occurrence->event_id             = 5;
		$occurrence->start_datetime       = '2026-07-01 10:00:00';
		$occurrence->end_datetime         = '2026-07-01 12:00:00';
		$occurrence->all_day              = false;
		$occurrence->timezone             = 'America/Chicago';
		$occurrence->title_override       = 'Special';
		$occurrence->description_override = 'Description';
		$occurrence->featured_image_id    = 42;
		$occurrence->status               = 'scheduled';
		$occurrence->capacity             = 200;
		$occurrence->sequence_number      = 3;

		$array = $occurrence->to_array();

		$this->assertSame( 5, $array['event_id'] );
		$this->assertSame( 'America/Chicago', $array['timezone'] );
		$this->assertSame( 'Special', $array['title_override'] );
		$this->assertSame( 'Description', $array['description_override'] );
		$this->assertSame( 42, $array['featured_image_id'] );
		$this->assertSame( 200, $array['capacity'] );
		$this->assertSame( 3, $array['sequence_number'] );
	}

	// =========================================================================
	// URL and Event Relationship Tests
	// =========================================================================

	/**
	 * Test get_url returns expected URL format.
	 *
	 * @return void
	 */
	public function test_get_url_returns_correct_format(): void {
		$occurrence                 = new Occurrence();
		$occurrence->id             = 123;
		$occurrence->event_id       = 5;
		$occurrence->start_datetime = '2026-06-15 14:00:00';
		$occurrence->end_datetime   = '2026-06-15 16:00:00';

		// Create a real Event object (set_event has strict type hint).
		$event             = new Event();
		$event->id         = 5;
		$event->slug       = 'test-event';
		$event->event_type = 'recurring'; // Use recurring to get datetime-suffixed URL.

		$occurrence->set_event( $event );

		\Brain\Monkey\Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'https://example.com' . $path;
			}
		);

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function ( $option, $default = array() ) {
				if ( 'nettertech_events_settings' === $option ) {
					return array( 'events_base_path' => 'events' );
				}
				return $default;
			}
		);

		$url = $occurrence->get_url();

		$this->assertStringContainsString( 'events/test-event', $url );
		$this->assertStringContainsString( '2026-06-15-1400', $url );
	}

	/**
	 * Test get_event returns null when no event set.
	 *
	 * @return void
	 */
	public function test_get_event_returns_null_initially(): void {
		$occurrence           = new Occurrence();
		$occurrence->event_id = 0; // Initialize typed property.

		$this->assertNull( $occurrence->get_event() );
	}

	/**
	 * Test set_event and get_event work together.
	 *
	 * @return void
	 */
	public function test_set_event_and_get_event(): void {
		$occurrence = new Occurrence();

		// Use real Event object (set_event has strict type hint).
		$event     = new Event();
		$event->id = 42;

		$occurrence->set_event( $event );

		$this->assertSame( $event, $occurrence->get_event() );
	}

	/**
	 * Test get_formatted_datetime returns expected format.
	 *
	 * @return void
	 */
	public function test_get_formatted_datetime_returns_expected_format(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-15 14:00:00';
		$occurrence->end_datetime   = '2026-06-15 16:00:00';

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function ( $option, $default = '' ) {
				if ( 'date_format' === $option ) {
					return 'F j, Y';
				}
				if ( 'time_format' === $option ) {
					return 'g:i a';
				}
				return $default;
			}
		);

		$formatted = $occurrence->get_formatted_datetime();

		// Should contain date and time.
		$this->assertStringContainsString( 'June 15, 2026', $formatted );
	}

	/**
	 * Test get_featured_image_url returns null when no image.
	 *
	 * @return void
	 */
	public function test_get_featured_image_url_returns_null_without_image(): void {
		$occurrence                    = new Occurrence();
		$occurrence->event_id          = 0; // Initialize typed property.
		$occurrence->featured_image_id = null;

		$this->assertNull( $occurrence->get_featured_image_url() );
	}

	/**
	 * Test get_featured_image_url returns URL when image exists.
	 *
	 * @return void
	 */
	public function test_get_featured_image_url_returns_url_with_image(): void {
		$occurrence                    = new Occurrence();
		$occurrence->event_id          = 0; // Initialize typed property.
		$occurrence->featured_image_id = 123;

		\Brain\Monkey\Functions\when( 'wp_get_attachment_image_url' )->alias(
			function ( $id, $size ) {
				if ( 123 === $id && 'large' === $size ) {
					return 'https://example.com/image.jpg';
				}
				return false;
			}
		);

		$url = $occurrence->get_featured_image_url();

		$this->assertSame( 'https://example.com/image.jpg', $url );
	}

	/**
	 * Test get_start_date with custom format.
	 *
	 * @return void
	 */
	public function test_get_start_date_with_custom_format(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-15 14:00:00';

		$date = $occurrence->get_start_date( 'Y-m-d' );

		$this->assertSame( '2026-06-15', $date );
	}

	/**
	 * Test get_start_date uses WordPress option when no format provided.
	 *
	 * @return void
	 */
	public function test_get_start_date_uses_wp_option(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-15 14:00:00';

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function ( $option, $default = '' ) {
				if ( 'date_format' === $option ) {
					return 'F j, Y';
				}
				return $default;
			}
		);

		$date = $occurrence->get_start_date();

		$this->assertSame( 'June 15, 2026', $date );
	}

	// =========================================================================
	// Additional Time Tests
	// =========================================================================

	/**
	 * Test get_start_time returns formatted time with explicit format.
	 *
	 * @return void
	 */
	public function test_get_start_time_returns_formatted_time_with_format(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-15 14:30:00';
		$occurrence->all_day        = false;
		$occurrence->timezone       = 'America/Chicago';

		$time = $occurrence->get_start_time( 'g:i A' );

		$this->assertSame( '2:30 PM', $time );
	}

	/**
	 * Test get_end_time returns formatted time with explicit format.
	 *
	 * @return void
	 */
	public function test_get_end_time_returns_formatted_time_with_format(): void {
		$occurrence               = new Occurrence();
		$occurrence->end_datetime = '2026-06-15 18:30:00';
		$occurrence->all_day      = false;
		$occurrence->timezone     = 'America/Chicago';

		$time = $occurrence->get_end_time( 'g:i A' );

		$this->assertSame( '6:30 PM', $time );
	}

	/**
	 * Test get_formatted_time returns time with WP option format.
	 *
	 * @return void
	 */
	public function test_get_formatted_time_returns_time_with_wp_option(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-15 18:00:00';
		$occurrence->all_day        = false;
		$occurrence->timezone       = 'America/Chicago';

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function ( $option, $default = '' ) {
				if ( 'time_format' === $option ) {
					return 'g:i A';
				}
				return $default;
			}
		);

		$time = $occurrence->get_formatted_time();

		$this->assertSame( '6:00 PM', $time );
	}

	/**
	 * Test get_formatted_datetime for all-day events.
	 *
	 * @return void
	 */
	public function test_get_formatted_datetime_all_day(): void {
		$occurrence                 = new Occurrence();
		$occurrence->start_datetime = '2026-06-15 00:00:00';
		$occurrence->all_day        = true;
		$occurrence->timezone       = 'America/Chicago';

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function ( $option, $default = '' ) {
				if ( 'date_format' === $option ) {
					return 'F j, Y';
				}
				if ( 'time_format' === $option ) {
					return 'g:i a';
				}
				return $default;
			}
		);

		\Brain\Monkey\Functions\when( '__' )->returnArg( 1 );

		$formatted = $occurrence->get_formatted_datetime();

		$this->assertStringContainsString( 'June 15, 2026', $formatted );
		$this->assertStringContainsString( '(All Day)', $formatted );
	}

	// =========================================================================
	// get_event() Lazy-Loading Tests
	// =========================================================================

	/**
	 * Test get_event returns cached event without calling repo.
	 *
	 * Kills mutant: `null === $this->event` flipped to `null !== $this->event`.
	 *
	 * @return void
	 */
	public function test_get_event_returns_cached_event_without_repo_call(): void {
		$occurrence           = new Occurrence();
		$occurrence->event_id = 5;

		$event     = new Event();
		$event->id = 5;

		$mock_repo = $this->createMock( \NetterTechEvents\Contracts\EventRepositoryInterface::class );
		$mock_repo->expects( $this->never() )
			->method( 'find' );

		$occurrence->set_event_repository( $mock_repo );
		$occurrence->set_event( $event );

		$result = $occurrence->get_event();

		$this->assertSame( $event, $result );
	}

	/**
	 * Test get_event returns null when event_id is zero (falsy).
	 *
	 * Kills mutant: `$this->event_id` truthiness check.
	 *
	 * @return void
	 */
	public function test_get_event_returns_null_when_event_id_is_zero(): void {
		$occurrence           = new Occurrence();
		$occurrence->event_id = 0;

		$mock_repo = $this->createMock( \NetterTechEvents\Contracts\EventRepositoryInterface::class );
		$mock_repo->expects( $this->never() )
			->method( 'find' );

		$occurrence->set_event_repository( $mock_repo );

		$result = $occurrence->get_event();

		$this->assertNull( $result );
	}

	/**
	 * Test get_event returns null when event_repo is not set.
	 *
	 * Kills mutant: `null !== $this->event_repo` flipped to `null === $this->event_repo`.
	 *
	 * @return void
	 */
	public function test_get_event_returns_null_without_repo(): void {
		$occurrence           = new Occurrence();
		$occurrence->event_id = 5;

		$result = $occurrence->get_event();

		$this->assertNull( $result );
	}

	/**
	 * Test get_event calls repo find when all conditions met.
	 *
	 * Kills mutants: AND/OR precedence changes and all-conditions-met path.
	 *
	 * @return void
	 */
	public function test_get_event_calls_repo_when_conditions_met(): void {
		$occurrence           = new Occurrence();
		$occurrence->event_id = 5;

		$event     = new Event();
		$event->id = 5;

		$mock_repo = $this->createMock( \NetterTechEvents\Contracts\EventRepositoryInterface::class );
		$mock_repo->expects( $this->once() )
			->method( 'find' )
			->with( 5 )
			->willReturn( $event );

		$occurrence->set_event_repository( $mock_repo );

		$result = $occurrence->get_event();

		$this->assertSame( $event, $result );
	}

	// =========================================================================
	// The stored instant (start_utc / end_utc)
	// =========================================================================

	/**
	 * Test the persisted instant is the wall-clock read in the occurrence's OWN zone.
	 *
	 * The whole point of the column. 8pm in Sydney is 09:00 UTC; 8pm in Chicago is 01:00 UTC
	 * the next day. If the instant were derived against the site's clock instead, the two would
	 * come out identical, and every "has it ended" question would be answered by the wrong
	 * clock — off by the distance between the zones. That is NTE-148 wearing a different hat.
	 *
	 * @return void
	 */
	public function test_the_stored_instant_is_read_in_the_occurrences_own_zone(): void {
		$sydney                     = new Occurrence();
		$sydney->event_id           = 5;
		$sydney->start_datetime     = '2026-07-01 20:00:00';
		$sydney->end_datetime       = '2026-07-01 22:00:00';
		$sydney->timezone           = 'Australia/Sydney';

		$chicago                    = new Occurrence();
		$chicago->event_id          = 5;
		$chicago->start_datetime    = '2026-07-01 20:00:00';
		$chicago->end_datetime      = '2026-07-01 22:00:00';
		$chicago->timezone          = 'America/Chicago';

		$sydney_row  = $sydney->to_array();
		$chicago_row = $chicago->to_array();

		// Same wall-clock, different zones — therefore different moments.
		$this->assertSame( '2026-07-01 10:00:00', $sydney_row['start_utc'] );
		$this->assertSame( '2026-07-02 01:00:00', $chicago_row['start_utc'] );
		$this->assertSame( '2026-07-01 12:00:00', $sydney_row['end_utc'] );
		$this->assertSame( '2026-07-02 03:00:00', $chicago_row['end_utc'] );

		$this->assertNotSame(
			$sydney_row['end_utc'],
			$chicago_row['end_utc'],
			'Two occurrences with the same wall-clock in different zones are not the same moment.'
		);
	}

	/**
	 * Test the instant follows DST, because a fixed offset would not.
	 *
	 * Chicago is UTC-5 in July and UTC-6 in January. A conversion that hard-coded an offset
	 * would be an hour wrong for half the year — and wrong in the direction that closes a sale
	 * window early.
	 *
	 * @return void
	 */
	public function test_the_stored_instant_follows_daylight_saving(): void {
		$summer                 = new Occurrence();
		$summer->event_id       = 5;
		$summer->start_datetime = '2026-07-01 12:00:00';
		$summer->end_datetime   = '2026-07-01 13:00:00';
		$summer->timezone       = 'America/Chicago';

		$winter                 = new Occurrence();
		$winter->event_id       = 5;
		$winter->start_datetime = '2026-01-01 12:00:00';
		$winter->end_datetime   = '2026-01-01 13:00:00';
		$winter->timezone       = 'America/Chicago';

		// Noon CDT is 17:00 UTC. Noon CST is 18:00 UTC.
		$this->assertSame( '2026-07-01 17:00:00', $summer->to_array()['start_utc'] );
		$this->assertSame( '2026-01-01 18:00:00', $winter->to_array()['start_utc'] );
	}

	/**
	 * Test the instant cannot drift from the wall-clock it mirrors.
	 *
	 * It is derived at the storage boundary, not carried as state, so there is no way to move
	 * an occurrence and forget to restamp it.
	 *
	 * @return void
	 */
	public function test_moving_an_occurrence_restamps_its_instant(): void {
		$occurrence                 = new Occurrence();
		$occurrence->event_id       = 5;
		$occurrence->start_datetime = '2026-07-01 20:00:00';
		$occurrence->end_datetime   = '2026-07-01 22:00:00';
		$occurrence->timezone       = 'America/Chicago';

		$before = $occurrence->to_array()['end_utc'];

		$occurrence->end_datetime = '2026-07-01 23:00:00';

		$this->assertNotSame( $before, $occurrence->to_array()['end_utc'] );
		$this->assertSame( '2026-07-02 04:00:00', $occurrence->to_array()['end_utc'] );
	}

	/**
	 * Test the format list still lines up with the column list.
	 *
	 * wpdb binds these positionally: a format array one element short of the data array does
	 * not fail loudly, it binds the wrong type to the wrong column.
	 *
	 * @return void
	 */
	public function test_formats_line_up_with_columns(): void {
		$occurrence                 = new Occurrence();
		$occurrence->event_id       = 5;
		$occurrence->start_datetime = '2026-07-01 20:00:00';
		$occurrence->end_datetime   = '2026-07-01 22:00:00';

		$this->assertCount(
			count( $occurrence->to_array() ),
			$occurrence->get_formats(),
			'Every column must have exactly one format specifier.'
		);
	}
}
