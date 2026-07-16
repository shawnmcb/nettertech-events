<?php
/**
 * Tests for ICalService.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\RecurrenceRule;
use NetterTechEvents\Repositories\CategoryRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Services\ICalService;
use NetterTechEvents\Services\OccurrenceGenerator;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\VEventParser;

/**
 * Test cases for ICalService.
 *
 * @coversDefaultClass \NetterTechEvents\Services\ICalService
 */
class ICalServiceTest extends \NetterTechEventsTestCase {

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Additional mocks specific to ICalService tests.
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'https://example.com/' . ltrim( $path, '/' );
			}
		);

		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component ) {
				$parts = parse_url( $url );
				if ( PHP_URL_HOST === $component ) {
					return $parts['host'] ?? null;
				}
				return $parts;
			}
		);

		Functions\when( 'get_bloginfo' )->alias(
			function ( $show ) {
				if ( 'name' === $show ) {
					return 'Test Site';
				}
				return '';
			}
		);

		Functions\when( 'wp_strip_all_tags' )->alias(
			function ( $text ) {
				return strip_tags( $text );
			}
		);

		Functions\when( 'sanitize_file_name' )->alias(
			function ( $name ) {
				return preg_replace( '/[^a-zA-Z0-9._-]/', '', $name );
			}
		);

		Functions\when( 'wp_timezone' )->alias(
			function () {
				return new \DateTimeZone( 'America/Chicago' );
			}
		);

	}

	/**
	 * Create a mock event.
	 *
	 * @param array $overrides Field overrides.
	 * @return Event
	 */
	private function create_mock_event( array $overrides = array() ): Event {
		$defaults = array(
			'id'              => 1,
			'title'           => 'Test Event',
			'slug'            => 'test-event',
			'description'     => 'Test description',
			'status'          => EventStatus::PUBLISHED,
			'event_type'      => 'single',
			'venue_name'      => 'Test Venue',
			'venue_address'   => '123 Test St',
			'recurrence_rule' => null,
		);

		$data  = array_merge( $defaults, $overrides );
		$event = new Event();
		foreach ( $data as $key => $value ) {
			if ( 'status' === $key && is_string( $value ) ) {
				$value = EventStatus::tryFrom( $value ) ?? EventStatus::DRAFT;
			}
			$event->$key = $value;
		}

		return $event;
	}

	/**
	 * Create a mock occurrence.
	 *
	 * @param array $overrides Field overrides.
	 * @return Occurrence
	 */
	private function create_mock_occurrence( array $overrides = array() ): Occurrence {
		$defaults = array(
			'id'                   => 1,
			'event_id'             => 1,
			'start_datetime'       => '2026-01-20 19:00:00',
			'end_datetime'         => '2026-01-20 21:00:00',
			'all_day'              => false,
			'status'               => 'scheduled',
			'title_override'       => null,
			'description_override' => null,
		);

		$data       = array_merge( $defaults, $overrides );
		$occurrence = new Occurrence();
		foreach ( $data as $key => $value ) {
			$occurrence->$key = $value;
		}

		return $occurrence;
	}

	/**
	 * Create an ICalService with required dependencies.
	 *
	 * The recurrence service defaults to a mock whose parse_rule() returns null,
	 * so single events (the default fixture) take the plain-VEVENT path. Tests
	 * exercising the master/RRULE path pass a recurrence service that returns a
	 * real RecurrenceRule, so the contract-mandated to_string() serializer runs.
	 *
	 * @param EventRepository|null      $event_repo         Event repository mock.
	 * @param OccurrenceRepository|null $occurrence_repo    Occurrence repository mock.
	 * @param CategoryRepository|null   $category_repo      Category repository mock.
	 * @param RecurrenceService|null    $recurrence_service Recurrence service mock.
	 * @return ICalService
	 */
	private function make_service(
		?EventRepository $event_repo = null,
		?OccurrenceRepository $occurrence_repo = null,
		?CategoryRepository $category_repo = null,
		?RecurrenceService $recurrence_service = null
	): ICalService {
		if ( null === $event_repo ) {
			$event_repo = Mockery::mock( EventRepository::class );
			$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		}

		$default_category_repo = Mockery::mock( CategoryRepository::class );
		$default_category_repo->shouldReceive( 'find_by_event' )->andReturn( [] );

		if ( null === $recurrence_service ) {
			$recurrence_service = Mockery::mock( RecurrenceService::class );
			$recurrence_service->shouldReceive( 'parse_rule' )->andReturn( null );
		}

		return new ICalService(
			$event_repo,
			$occurrence_repo ?? Mockery::mock( OccurrenceRepository::class ),
			new VEventParser(),
			$category_repo ?? $default_category_repo,
			$recurrence_service,
			new OccurrenceGenerator()
		);
	}

	/**
	 * Create a recurrence service mock that returns a real parsed rule.
	 *
	 * Exercises the real RecurrenceRule::to_string() serializer per the
	 * iCal export contract (RRULE must not be hand-emitted).
	 *
	 * @param RecurrenceRule $rule Rule to return from parse_rule().
	 * @return RecurrenceService
	 */
	private function make_recurrence_service( RecurrenceRule $rule ): RecurrenceService {
		$recurrence_service = Mockery::mock( RecurrenceService::class );
		$recurrence_service->shouldReceive( 'parse_rule' )->andReturn( $rule );

		return $recurrence_service;
	}

	/**
	 * Test export_occurrence returns null for non-existent occurrence.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_returns_null_when_not_found(): void {
		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )
			->with( 999 )
			->andReturn( null );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );

		$service = $this->make_service( $event_repo, $occurrence_repo );

		$this->assertNull( $service->export_occurrence( 999 ) );
	}

	/**
	 * Test export_occurrence returns null when event not found.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_returns_null_when_event_not_found(): void {
		$occurrence = $this->create_mock_occurrence();

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )
			->with( 1 )
			->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )
			->with( 1 )
			->andReturn( null );

		$service = $this->make_service( $event_repo, $occurrence_repo );

		$this->assertNull( $service->export_occurrence( 1 ) );
	}

	/**
	 * Test export_occurrence returns valid iCal content.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_returns_ical_content(): void {
		$event      = $this->create_mock_event();
		$occurrence = $this->create_mock_occurrence();

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )
			->with( 1 )
			->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )
			->with( 1 )
			->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'BEGIN:VCALENDAR', $result );
		$this->assertStringContainsString( 'BEGIN:VEVENT', $result );
		$this->assertStringContainsString( 'SUMMARY:Test Event', $result );
		$this->assertStringContainsString( 'END:VEVENT', $result );
		$this->assertStringContainsString( 'END:VCALENDAR', $result );
	}

	/**
	 * Test export_occurrence includes location.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_includes_location(): void {
		$event      = $this->create_mock_event();
		$occurrence = $this->create_mock_occurrence();

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		$this->assertStringContainsString( 'LOCATION:Test Venue\\, 123 Test St', $result );
	}

	/**
	 * Test export_occurrence includes URL.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_includes_url(): void {
		$event      = $this->create_mock_event();
		$occurrence = $this->create_mock_occurrence();

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		$this->assertStringContainsString( 'URL:https://example.com/events/test-event', $result );
	}

	/**
	 * Test export_occurrence handles all-day events.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_handles_all_day_events(): void {
		$event      = $this->create_mock_event();
		$occurrence = $this->create_mock_occurrence(
			array(
				'all_day'        => true,
				'start_datetime' => '2026-01-20 00:00:00',
				'end_datetime'   => '2026-01-20 23:59:59',
			)
		);

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		$this->assertStringContainsString( 'DTSTART;VALUE=DATE:20260120', $result );
		$this->assertStringContainsString( 'DTEND;VALUE=DATE:20260121', $result );
	}

	/**
	 * Test export_occurrence uses title override when present.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_uses_title_override(): void {
		$event      = $this->create_mock_event();
		$occurrence = $this->create_mock_occurrence(
			array(
				'title_override' => 'Special Session',
			)
		);

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		$this->assertStringContainsString( 'SUMMARY:Special Session', $result );
	}

	/**
	 * Test export_event returns null when event not found.
	 *
	 * @covers ::export_event
	 */
	public function test_export_event_returns_null_when_not_found(): void {
		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )
			->with( 999 )
			->andReturn( null );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );

		$service = $this->make_service( $event_repo, $occurrence_repo );

		$this->assertNull( $service->export_event( 999 ) );
	}

	/**
	 * Test export_event returns null when no occurrences.
	 *
	 * @covers ::export_event
	 */
	public function test_export_event_returns_null_when_no_occurrences(): void {
		$event = $this->create_mock_event();

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( array() );

		$service = $this->make_service( $event_repo, $occurrence_repo );

		$this->assertNull( $service->export_event( 1 ) );
	}

	/**
	 * Test export_event of a recurring series collapses to ONE master VEVENT with RRULE.
	 *
	 * Under the compact-recurring model a recurring event exports a single master
	 * VEVENT carrying the RRULE and a stable per-series UID, regardless of how many
	 * occurrences exist. DTSTART comes from the earliest occurrence.
	 *
	 * @covers ::export_event
	 */
	public function test_export_event_includes_multiple_occurrences(): void {
		$event       = $this->create_mock_event(
			array(
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=10',
			)
		);
		$occurrence1 = $this->create_mock_occurrence( array( 'id' => 1 ) );
		$occurrence2 = $this->create_mock_occurrence(
			array(
				'id'             => 2,
				'start_datetime' => '2026-01-27 19:00:00',
				'end_datetime'   => '2026-01-27 21:00:00',
			)
		);

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( array( $occurrence1, $occurrence2 ) );

		$rule    = ( new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY ) )
			->with_by_day( array( RecurrenceRule::DAY_TU ) )
			->with_count( 10 );
		$service = $this->make_service(
			$event_repo,
			$occurrence_repo,
			null,
			$this->make_recurrence_service( $rule )
		);
		$result  = $service->export_event( 1 );

		$this->assertNotNull( $result );
		// Recurring series collapses to a single master VEVENT.
		$this->assertSame( 1, substr_count( $result, 'BEGIN:VEVENT' ) );
		$this->assertSame( 1, substr_count( $result, 'END:VEVENT' ) );
		// RRULE serialized via RecurrenceRule::to_string() (not hand-emitted).
		$this->assertStringContainsString( 'RRULE:FREQ=WEEKLY;COUNT=10;BYDAY=TU', $result );
		// Stable per-series UID.
		$this->assertStringContainsString( 'UID:event-1@example.com', $result );
		// DTSTART from the earliest occurrence.
		$this->assertStringContainsString( 'DTSTART:20260120T190000Z', $result );
	}

	/**
	 * Test an open-ended recurring series gets a forward UNTIL cap (NTE-014).
	 *
	 * A rule with neither UNTIL nor COUNT would expand forever in a subscribing
	 * client. The configured feed horizon (default 730 days) is injected as a
	 * forward UNTIL = today + horizon. BYDAY and other parts are preserved.
	 *
	 * @covers ::export_event
	 */
	public function test_open_ended_recurrence_gets_feed_horizon_until(): void {
		$event      = $this->create_mock_event(
			array(
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=TU',
			)
		);
		$occurrence = $this->create_mock_occurrence( array( 'id' => 1 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )->with( 1 )->andReturn( array( $occurrence ) );

		$rule    = ( new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY ) )
			->with_by_day( array( RecurrenceRule::DAY_TU ) );
		$service = $this->make_service( $event_repo, $occurrence_repo, null, $this->make_recurrence_service( $rule ) );
		$result  = $service->export_event( 1 );

		$this->assertNotNull( $result );
		$expected_until = ( new \DateTimeImmutable( 'today', new \DateTimeZone( 'UTC' ) ) )
			->modify( '+730 days' )
			->format( 'Ymd\THis\Z' );
		$this->assertStringContainsString( 'UNTIL=' . $expected_until, $result );
		// BYDAY preserved alongside the injected UNTIL.
		$this->assertStringContainsString( 'BYDAY=TU', $result );
	}

	/**
	 * Test the feed horizon cap is skipped when set to 0 (opt-out) — NTE-014.
	 *
	 * @covers ::export_event
	 */
	public function test_feed_horizon_disabled_emits_unbounded_rrule(): void {
		Functions\when( 'get_option' )->justReturn( array( 'ical_feed_horizon_days' => 0 ) );

		$event      = $this->create_mock_event(
			array(
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=TU',
			)
		);
		$occurrence = $this->create_mock_occurrence( array( 'id' => 1 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )->with( 1 )->andReturn( array( $occurrence ) );

		$rule    = ( new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY ) )
			->with_by_day( array( RecurrenceRule::DAY_TU ) );
		$service = $this->make_service( $event_repo, $occurrence_repo, null, $this->make_recurrence_service( $rule ) );
		$result  = $service->export_event( 1 );

		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'RRULE:FREQ=WEEKLY;BYDAY=TU', $result );
		$this->assertStringNotContainsString( 'UNTIL=', $result );
	}

	/**
	 * Test a recurring series with its own COUNT is left untouched (NTE-014).
	 *
	 * Events that already define an end condition must not receive the feed
	 * horizon cap.
	 *
	 * @covers ::export_event
	 */
	public function test_bounded_recurrence_keeps_own_end_condition(): void {
		$event      = $this->create_mock_event(
			array(
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=10',
			)
		);
		$occurrence = $this->create_mock_occurrence( array( 'id' => 1 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )->with( 1 )->andReturn( array( $occurrence ) );

		$rule    = ( new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY ) )
			->with_by_day( array( RecurrenceRule::DAY_TU ) )
			->with_count( 10 );
		$service = $this->make_service( $event_repo, $occurrence_repo, null, $this->make_recurrence_service( $rule ) );
		$result  = $service->export_event( 1 );

		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'RRULE:FREQ=WEEKLY;COUNT=10;BYDAY=TU', $result );
		$this->assertStringNotContainsString( 'UNTIL=', $result );
	}

	/**
	 * Test export_event emits EXDATE for cancelled occurrences on the master.
	 *
	 * Cancellations are excluded from the recurrence set via EXDATE; the master
	 * remains STATUS:CONFIRMED (never STATUS:CANCELLED).
	 *
	 * @covers ::export_event
	 */
	public function test_export_event_emits_exdate_for_cancelled_occurrences(): void {
		$event = $this->create_mock_event(
			array(
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=TU',
			)
		);

		$scheduled = $this->create_mock_occurrence( array( 'id' => 1 ) );
		$cancelled = $this->create_mock_occurrence(
			array(
				'id'              => 2,
				'start_datetime'  => '2026-01-27 19:00:00',
				'end_datetime'    => '2026-01-27 21:00:00',
				'status'          => 'cancelled',
				'is_override'     => true,
				'sequence_number' => 2,
			)
		);

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( array( $scheduled, $cancelled ) );

		$rule    = ( new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY ) )
			->with_by_day( array( RecurrenceRule::DAY_TU ) );
		$service = $this->make_service(
			$event_repo,
			$occurrence_repo,
			null,
			$this->make_recurrence_service( $rule )
		);
		$result  = $service->export_event( 1 );

		$this->assertNotNull( $result );
		// One EXDATE for the cancelled occurrence's original slot (seq 2 = 2026-01-27).
		$this->assertStringContainsString( 'EXDATE:20260127T190000Z', $result );
		$this->assertSame( 1, substr_count( $result, 'EXDATE' ) );
		// Master stays CONFIRMED; cancellation is expressed via EXDATE only.
		$this->assertStringContainsString( 'STATUS:CONFIRMED', $result );
		$this->assertStringNotContainsString( 'STATUS:CANCELLED', $result );
	}

	/**
	 * Test export_event emits EXDATE;VALUE=DATE for cancelled all-day occurrences.
	 *
	 * EXDATE value type must match DTSTART (all-day → VALUE=DATE).
	 *
	 * @covers ::export_event
	 */
	public function test_export_event_all_day_exdate_uses_date_value(): void {
		$event = $this->create_mock_event(
			array(
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=TU',
			)
		);

		$scheduled = $this->create_mock_occurrence(
			array(
				'id'             => 1,
				'all_day'        => true,
				'start_datetime' => '2026-01-20 00:00:00',
				'end_datetime'   => '2026-01-20 23:59:59',
			)
		);
		$cancelled = $this->create_mock_occurrence(
			array(
				'id'              => 2,
				'all_day'         => true,
				'start_datetime'  => '2026-01-27 00:00:00',
				'end_datetime'    => '2026-01-27 23:59:59',
				'status'          => 'cancelled',
				'is_override'     => true,
				'sequence_number' => 2,
			)
		);

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( array( $scheduled, $cancelled ) );

		$rule    = ( new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY ) )
			->with_by_day( array( RecurrenceRule::DAY_TU ) );
		$service = $this->make_service(
			$event_repo,
			$occurrence_repo,
			null,
			$this->make_recurrence_service( $rule )
		);
		$result  = $service->export_event( 1 );

		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'DTSTART;VALUE=DATE:20260120', $result );
		$this->assertStringContainsString( 'EXDATE;VALUE=DATE:20260127', $result );
	}

	// =========================================================================
	// Chunk 7b: RECURRENCE-ID override instances + original-slot recovery
	// =========================================================================

	/**
	 * Invoke the private resolve_original_slot via reflection.
	 *
	 * @param ICalService    $service    Service under test.
	 * @param Occurrence     $occurrence Override occurrence.
	 * @param Event          $event      Parent event.
	 * @param RecurrenceRule $rule       Parsed rule.
	 * @param string         $anchor     Series-start anchor (Y-m-d H:i:s).
	 * @return \DateTimeImmutable
	 */
	private function invoke_resolve_original_slot(
		ICalService $service,
		Occurrence $occurrence,
		Event $event,
		RecurrenceRule $rule,
		string $anchor
	): \DateTimeImmutable {
		$method = new \ReflectionMethod( ICalService::class, 'resolve_original_slot' );

		return $method->invoke( $service, $occurrence, $event, $rule, new \DateTimeImmutable( $anchor ) );
	}

	/**
	 * Test resolve_original_slot maps a weekly occurrence to its slot by sequence.
	 *
	 * For FREQ=WEEKLY anchored 2026-01-20 (Tue), sequence_number 3 -> the third
	 * slot (2026-02-03), even when the occurrence's own start has been moved.
	 *
	 * @covers ::resolve_original_slot
	 */
	public function test_resolve_original_slot_weekly_by_sequence(): void {
		$event      = $this->create_mock_event(
			array(
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY',
			)
		);
		$occurrence = $this->create_mock_occurrence(
			array(
				// Moved off-slot to a Thursday; original slot must still resolve to the Tuesday.
				'start_datetime'  => '2026-02-05 19:00:00',
				'end_datetime'    => '2026-02-05 21:00:00',
				'is_override'     => true,
				'sequence_number' => 3,
			)
		);

		$rule    = new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY );
		$service = $this->make_service( null, null, null, $this->make_recurrence_service( $rule ) );

		$slot = $this->invoke_resolve_original_slot( $service, $occurrence, $event, $rule, '2026-01-20 19:00:00' );

		$this->assertSame( '2026-02-03 19:00:00', $slot->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Test resolve_original_slot honors a BYDAY rule (arithmetic reconstruction would fail).
	 *
	 * FREQ=WEEKLY;BYDAY=MO,WE anchored Mon 2026-01-19 yields Mon, Wed, Mon, Wed...
	 * sequence_number 3 -> the second Monday (2026-01-26), which a simple
	 * anchor + (seq-1)*INTERVAL arithmetic would get wrong.
	 *
	 * @covers ::resolve_original_slot
	 */
	public function test_resolve_original_slot_byday_rule(): void {
		$event      = $this->create_mock_event(
			array(
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO,WE',
			)
		);
		$occurrence = $this->create_mock_occurrence(
			array(
				'start_datetime'  => '2026-01-30 19:00:00',
				'end_datetime'    => '2026-01-30 21:00:00',
				'is_override'     => true,
				'sequence_number' => 3,
			)
		);

		$rule    = ( new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY ) )
			->with_by_day( array( RecurrenceRule::DAY_MO, RecurrenceRule::DAY_WE ) );
		$service = $this->make_service( null, null, null, $this->make_recurrence_service( $rule ) );

		// Anchor on the first Monday of the series.
		$slot = $this->invoke_resolve_original_slot( $service, $occurrence, $event, $rule, '2026-01-19 19:00:00' );

		// Slots: Mon 01-19, Wed 01-21, Mon 01-26 -> seq 3 is the second Monday.
		$this->assertSame( '2026-01-26 19:00:00', $slot->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Test resolve_original_slot falls back to the nearest slot when out of range.
	 *
	 * A sequence_number past the generated set (e.g. degenerate / capped rule)
	 * resolves to the slot nearest the occurrence's actual start.
	 *
	 * @covers ::resolve_original_slot
	 */
	public function test_resolve_original_slot_nearest_fallback(): void {
		$event      = $this->create_mock_event(
			array(
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY;COUNT=2',
			)
		);
		$occurrence = $this->create_mock_occurrence(
			array(
				'start_datetime'  => '2026-01-28 19:00:00',
				'end_datetime'    => '2026-01-28 21:00:00',
				'is_override'     => true,
				'sequence_number' => 99,
			)
		);

		$rule    = ( new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY ) )->with_count( 2 );
		$service = $this->make_service( null, null, null, $this->make_recurrence_service( $rule ) );

		// Slots (COUNT=2): 2026-01-20, 2026-01-27. Nearest to 01-28 is 01-27.
		$slot = $this->invoke_resolve_original_slot( $service, $occurrence, $event, $rule, '2026-01-20 19:00:00' );

		$this->assertSame( '2026-01-27 19:00:00', $slot->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Test export_event emits a RECURRENCE-ID VEVENT for a moved override occurrence.
	 *
	 * The override VEVENT shares the master UID, carries RECURRENCE-ID = the
	 * ORIGINAL slot, DTSTART = the moved actual time, and uses effective getters
	 * (override -> parent). See contract §4.
	 *
	 * @covers ::export_event
	 */
	public function test_export_event_emits_recurrence_id_for_override(): void {
		$event       = $this->create_mock_event(
			array(
				'title'           => 'Weekly Class',
				'description'     => 'Default description',
				'venue_name'      => 'Main Hall',
				'venue_address'   => '1 Center St',
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY',
			)
		);
		$occurrence1 = $this->create_mock_occurrence(
			array(
				'id'              => 1,
				'sequence_number' => 1,
			)
		);
		$override    = $this->create_mock_occurrence(
			array(
				'id'                  => 2,
				// Original slot would be 2026-01-27; moved to 2026-01-28 20:00.
				'start_datetime'      => '2026-01-28 20:00:00',
				'end_datetime'        => '2026-01-28 22:00:00',
				'is_override'         => true,
				'sequence_number'     => 2,
				'title_override'      => 'Special Guest',
				'venue_name_override' => 'Annex Room',
			)
		);

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( array( $occurrence1, $override ) );

		$rule    = new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY );
		$service = $this->make_service(
			$event_repo,
			$occurrence_repo,
			null,
			$this->make_recurrence_service( $rule )
		);
		$result  = $service->export_event( 1 );

		$this->assertNotNull( $result );
		// Master + one override VEVENT.
		$this->assertSame( 2, substr_count( $result, 'BEGIN:VEVENT' ) );
		// Both share the master UID.
		$this->assertSame( 2, substr_count( $result, 'UID:event-1@example.com' ) );
		// RECURRENCE-ID points at the ORIGINAL slot (2026-01-27), not the moved start.
		$this->assertStringContainsString( 'RECURRENCE-ID:20260127T190000Z', $result );
		// DTSTART reflects the moved ACTUAL time.
		$this->assertStringContainsString( 'DTSTART:20260128T200000Z', $result );
		// Effective getters: override title + venue, parent description.
		$this->assertStringContainsString( 'SUMMARY:Special Guest', $result );
		$this->assertStringContainsString( 'LOCATION:Annex Room\, 1 Center St', $result );
		$this->assertStringContainsString( 'DESCRIPTION:Default description', $result );
	}

	/**
	 * Test EXDATE uses the ORIGINAL slot for a time-moved-then-cancelled occurrence.
	 *
	 * The occurrence's start_datetime was moved before cancellation; EXDATE must
	 * still carry the original recurrence slot so subscribers exclude the right
	 * date. See contract §3/§6.
	 *
	 * @covers ::export_event
	 */
	public function test_export_event_exdate_uses_original_slot_for_moved_cancellation(): void {
		$event       = $this->create_mock_event(
			array(
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY',
			)
		);
		$occurrence1 = $this->create_mock_occurrence( array( 'id' => 1 ) );
		$cancelled   = $this->create_mock_occurrence(
			array(
				'id'              => 2,
				// Moved to 2026-01-29 then cancelled; original slot is 2026-01-27.
				'start_datetime'  => '2026-01-29 19:00:00',
				'end_datetime'    => '2026-01-29 21:00:00',
				'status'          => 'cancelled',
				'is_override'     => true,
				'sequence_number' => 2,
			)
		);

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( array( $occurrence1, $cancelled ) );

		$rule    = new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY );
		$service = $this->make_service(
			$event_repo,
			$occurrence_repo,
			null,
			$this->make_recurrence_service( $rule )
		);
		$result  = $service->export_event( 1 );

		$this->assertNotNull( $result );
		// EXDATE = ORIGINAL slot (2026-01-27), NOT the moved start (2026-01-29).
		$this->assertStringContainsString( 'EXDATE:20260127T190000Z', $result );
		$this->assertStringNotContainsString( 'EXDATE:20260129T190000Z', $result );
		// Cancelled override does NOT produce a RECURRENCE-ID VEVENT.
		$this->assertSame( 1, substr_count( $result, 'BEGIN:VEVENT' ) );
		$this->assertStringNotContainsString( 'RECURRENCE-ID', $result );
	}

	/**
	 * Test a split series (scope=following) yields two non-overlapping masters.
	 *
	 * Series-split produces two events: the parent capped by RRULE UNTIL and the
	 * new event starting after. Their instance ranges must not overlap
	 * (parent UNTIL < new DTSTART). See contract §7.
	 *
	 * @covers ::export_calendar_feed
	 */
	public function test_export_calendar_feed_split_series_two_masters_no_overlap(): void {
		$parent = $this->create_mock_event(
			array(
				'id'              => 1,
				'title'           => 'Parent Series',
				'slug'            => 'parent-series',
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY;UNTIL=20260127T190000Z',
			)
		);
		$child  = $this->create_mock_event(
			array(
				'id'              => 2,
				'title'           => 'Split Series',
				'slug'            => 'split-series',
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY',
			)
		);

		$parent_occ = $this->create_mock_occurrence(
			array(
				'id'             => 1,
				'event_id'       => 1,
				'start_datetime' => '2026-01-20 19:00:00',
				'end_datetime'   => '2026-01-20 21:00:00',
			)
		);
		$child_occ  = $this->create_mock_occurrence(
			array(
				'id'             => 2,
				'event_id'       => 2,
				'start_datetime' => '2026-02-03 19:00:00',
				'end_datetime'   => '2026-02-03 21:00:00',
			)
		);

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'all' )->andReturn( array( $parent, $child ) );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )->with( 1 )->andReturn( array( $parent_occ ) );
		$occurrence_repo->shouldReceive( 'for_event' )->with( 2 )->andReturn( array( $child_occ ) );

		$parent_rule = ( new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY ) )
			->with_until( new \DateTimeImmutable( '2026-01-27 19:00:00', new \DateTimeZone( 'UTC' ) ) );
		$child_rule  = new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY );

		$recurrence_service = Mockery::mock( RecurrenceService::class );
		$recurrence_service->shouldReceive( 'parse_rule' )
			->with( 'FREQ=WEEKLY;UNTIL=20260127T190000Z' )
			->andReturn( $parent_rule );
		$recurrence_service->shouldReceive( 'parse_rule' )
			->with( 'FREQ=WEEKLY' )
			->andReturn( $child_rule );

		$service = $this->make_service( $event_repo, $occurrence_repo, null, $recurrence_service );
		$result  = $service->export_calendar_feed();

		// Two distinct masters.
		$this->assertSame( 2, substr_count( $result, 'BEGIN:VEVENT' ) );
		$this->assertStringContainsString( 'UID:event-1@example.com', $result );
		$this->assertStringContainsString( 'UID:event-2@example.com', $result );
		// Parent capped by UNTIL; child starts after. No instance overlap.
		$this->assertStringContainsString( 'RRULE:FREQ=WEEKLY;UNTIL=20260127T190000Z', $result );
		$this->assertStringContainsString( 'DTSTART:20260203T190000Z', $result );

		// Assert parent UNTIL < child DTSTART (non-overlap invariant).
		$parent_until = new \DateTimeImmutable( '2026-01-27 19:00:00', new \DateTimeZone( 'UTC' ) );
		$child_start  = new \DateTimeImmutable( '2026-02-03 19:00:00', new \DateTimeZone( 'UTC' ) );
		$this->assertLessThan( $child_start, $parent_until );
	}

	/**
	 * Test export_calendar_feed returns valid iCal.
	 *
	 * @covers ::export_calendar_feed
	 */
	public function test_export_calendar_feed_returns_ical(): void {
		$event      = $this->create_mock_event();
		$occurrence = $this->create_mock_occurrence();

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'all' )->andReturn( array( $event ) );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )->andReturn( array( $occurrence ) );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_calendar_feed();

		$this->assertStringContainsString( 'BEGIN:VCALENDAR', $result );
		$this->assertStringContainsString( 'X-WR-CALNAME:Test Site Events', $result );
		$this->assertStringContainsString( 'BEGIN:VEVENT', $result );
	}

	/**
	 * Test parse_ical extracts event data.
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_extracts_event_data(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "VERSION:2.0\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "UID:test-123@example.com\r\n";
		$ical .= "SUMMARY:Test Import Event\r\n";
		$ical .= "DESCRIPTION:Test Description\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "DTEND:20260215T210000Z\r\n";
		$ical .= "LOCATION:Import Venue\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertCount( 1, $result );
		$this->assertSame( 'test-123@example.com', $result[0]['uid'] );
		$this->assertSame( 'Test Import Event', $result[0]['summary'] );
		$this->assertSame( 'Test Description', $result[0]['description'] );
		$this->assertSame( 'Import Venue', $result[0]['location'] );
		$this->assertFalse( $result[0]['all_day'] );
	}

	/**
	 * Test parse_ical handles all-day events.
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_handles_all_day_events(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:All Day Event\r\n";
		$ical .= "DTSTART;VALUE=DATE:20260215\r\n";
		$ical .= "DTEND;VALUE=DATE:20260216\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertCount( 1, $result );
		$this->assertTrue( $result[0]['all_day'] );
		$this->assertStringContainsString( '2026-02-15', $result[0]['start_datetime'] );
	}

	/**
	 * Test parse_ical handles line folding.
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_handles_line_folding(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Long Event Ti\r\n tle With Folding\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Long Event Title With Folding', $result[0]['summary'] );
	}

	/**
	 * Test parse_ical handles escaped characters.
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_handles_escaped_characters(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Event\\, with\\; special\\nchars\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertCount( 1, $result );
		$this->assertSame( "Event, with; special\nchars", $result[0]['summary'] );
	}

	/**
	 * Test parse_ical handles multiple events.
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_handles_multiple_events(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Event One\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Event Two\r\n";
		$ical .= "DTSTART:20260216T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertCount( 2, $result );
		$this->assertSame( 'Event One', $result[0]['summary'] );
		$this->assertSame( 'Event Two', $result[1]['summary'] );
	}

	/**
	 * Test parse_ical skips events without required fields.
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_skips_invalid_events(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "DESCRIPTION:No summary or start\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Valid Event\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Valid Event', $result[0]['summary'] );
	}

	/**
	 * Test parse_ical handles RRULE.
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_handles_rrule(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Recurring Event\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=10\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertCount( 1, $result );
		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO;COUNT=10', $result[0]['rrule'] );
	}

	/**
	 * Test parse_ical handles categories.
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_handles_categories(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Categorized Event\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "CATEGORIES:Music,Concert,Live\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertCount( 1, $result );
		$this->assertSame( array( 'Music', 'Concert', 'Live' ), $result[0]['categories'] );
	}

	/**
	 * Test import_ical creates events.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_creates_events(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Import Test\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "DTEND:20260215T210000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$saved_event     = $this->create_mock_event(
			array(
				'id'    => 1,
				'title' => 'Import Test',
			)
		);
		$saved_event->id = 1;

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )->andReturn( $saved_event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $this->create_mock_occurrence() );

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->import_ical( $ical );

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Test import_ical handles import errors gracefully.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_handles_errors(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Error Test\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )->andThrow( new \Exception( 'Database error' ) );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->import_ical( $ical );

		$this->assertSame( 0, $result['imported'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'Database error', $result['errors'][0] );
	}

	/**
	 * Test get_content_type returns correct value.
	 *
	 * @covers ::get_content_type
	 */
	public function test_get_content_type_returns_correct_value(): void {
		$this->assertSame( 'text/calendar; charset=utf-8', ICalService::get_content_type() );
	}

	/**
	 * Test get_content_disposition returns correct value.
	 *
	 * @covers ::get_content_disposition
	 */
	public function test_get_content_disposition_returns_correct_value(): void {
		$this->assertSame(
			'attachment; filename="calendar.ics"',
			ICalService::get_content_disposition()
		);

		$this->assertSame(
			'attachment; filename="my-events.ics"',
			ICalService::get_content_disposition( 'my-events.ics' )
		);
	}

	/**
	 * Test export includes proper iCal headers.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_includes_proper_headers(): void {
		$event      = $this->create_mock_event();
		$occurrence = $this->create_mock_occurrence();

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		$this->assertStringContainsString( 'VERSION:2.0', $result );
		$this->assertStringContainsString( 'PRODID:-//NetterTech Events//Events Plugin//EN', $result );
		$this->assertStringContainsString( 'CALSCALE:GREGORIAN', $result );
		$this->assertStringContainsString( 'METHOD:PUBLISH', $result );
	}

	/**
	 * Test export generates unique UIDs.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_generates_unique_uids(): void {
		$event      = $this->create_mock_event();
		$occurrence = $this->create_mock_occurrence( array( 'id' => 42 ) );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		$this->assertStringContainsString( 'UID:occurrence-42@example.com', $result );
	}

	// =========================================================================
	// Additional Coverage Tests
	// =========================================================================

	/**
	 * Test export_occurrence uses description override when present.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_uses_description_override(): void {
		$event      = $this->create_mock_event( array( 'description' => 'Original description' ) );
		$occurrence = $this->create_mock_occurrence(
			array(
				'description_override' => 'Override description',
			)
		);

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		$this->assertStringContainsString( 'DESCRIPTION:Override description', $result );
		$this->assertStringNotContainsString( 'Original description', $result );
	}

	/**
	 * Test export_occurrence without venue omits location.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_without_venue_omits_location(): void {
		$event      = $this->create_mock_event(
			array(
				'venue_name'    => null,
				'venue_address' => null,
			)
		);
		$occurrence = $this->create_mock_occurrence();

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		$this->assertStringNotContainsString( 'LOCATION:', $result );
	}

	/**
	 * Test export_occurrence without description omits description line.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_without_description_omits_line(): void {
		$event      = $this->create_mock_event( array( 'description' => '' ) );
		$occurrence = $this->create_mock_occurrence();

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		$this->assertStringNotContainsString( 'DESCRIPTION:', $result );
	}

	/**
	 * Test export_occurrence without slug omits URL line.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_without_slug_omits_url(): void {
		$event      = $this->create_mock_event( array( 'slug' => '' ) );
		$occurrence = $this->create_mock_occurrence();

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		$this->assertStringNotContainsString( 'URL:', $result );
	}

	/**
	 * Test export_occurrence venue with address only (no address part).
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_venue_without_address(): void {
		$event      = $this->create_mock_event(
			array(
				'venue_name'    => 'My Venue',
				'venue_address' => '',
			)
		);
		$occurrence = $this->create_mock_occurrence();

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		$this->assertStringContainsString( 'LOCATION:My Venue', $result );
		// Should not have trailing comma.
		$this->assertStringNotContainsString( 'LOCATION:My Venue\\,', $result );
	}

	/**
	 * Test export_calendar_feed with no events returns empty calendar.
	 *
	 * @covers ::export_calendar_feed
	 */
	public function test_export_calendar_feed_with_no_events(): void {
		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'all' )->andReturn( array() );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_calendar_feed();

		$this->assertStringContainsString( 'BEGIN:VCALENDAR', $result );
		$this->assertStringContainsString( 'END:VCALENDAR', $result );
		$this->assertStringNotContainsString( 'BEGIN:VEVENT', $result );
	}

	/**
	 * Test export_calendar_feed with event that has no occurrences in range.
	 *
	 * @covers ::export_calendar_feed
	 */
	public function test_export_calendar_feed_skips_events_without_occurrences(): void {
		$event = $this->create_mock_event();

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'all' )->andReturn( array( $event ) );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )->andReturn( array() );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_calendar_feed();

		$this->assertStringContainsString( 'BEGIN:VCALENDAR', $result );
		$this->assertStringNotContainsString( 'BEGIN:VEVENT', $result );
	}

	/**
	 * Test import_ical when event save returns event without id (id=0).
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_handles_event_without_id(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:No ID Test\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		// Return event with id=0 (falsy but valid return).
		$saved_event = $this->create_mock_event( array( 'id' => 0 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )->andReturn( $saved_event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->import_ical( $ical );

		// id=0 is falsy, so import fails.
		$this->assertSame( 0, $result['imported'] );
	}

	/**
	 * Test import_ical parses location into venue name and address.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_parses_location(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Location Test\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "LOCATION:My Venue\\, 123 Main St\\, City\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$captured_event = null;
		$saved_event    = $this->create_mock_event( array( 'id' => 1 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )
			->andReturnUsing(
				function ( $event ) use ( &$captured_event, $saved_event ) {
					$captured_event = $event;
					return $saved_event;
				}
			);

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $this->create_mock_occurrence() );

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$service->import_ical( $ical );

		$this->assertNotNull( $captured_event );
		$this->assertSame( 'My Venue', $captured_event->venue_name );
		$this->assertSame( '123 Main St, City', $captured_event->venue_address );
	}

	/**
	 * Test import_ical handles recurrence rule.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_handles_recurrence_rule(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Recurring Import\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=10\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$captured_event = null;
		$saved_event    = $this->create_mock_event( array( 'id' => 1 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )
			->andReturnUsing(
				function ( $event ) use ( &$captured_event, $saved_event ) {
					$captured_event = $event;
					return $saved_event;
				}
			);

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $this->create_mock_occurrence() );

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$service->import_ical( $ical );

		$this->assertNotNull( $captured_event );
		$this->assertSame( 'recurring', $captured_event->event_type );
		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO;COUNT=10', $captured_event->recurrence_rule );
	}

	/**
	 * Test import_ical with skip_duplicates disabled imports all.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_skip_duplicates_disabled(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "UID:duplicate-123\r\n";
		$ical .= "SUMMARY:Duplicate Test\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$saved_event = $this->create_mock_event( array( 'id' => 1 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )->andReturn( $saved_event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $this->create_mock_occurrence() );

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->import_ical( $ical, array( 'skip_duplicates' => false ) );

		// With skip_duplicates=false, should import regardless of UID.
		$this->assertSame( 1, $result['imported'] );
	}

	/**
	 * Test import_ical all-day flag is set on occurrence.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_sets_all_day_flag(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:All Day Import\r\n";
		$ical .= "DTSTART;VALUE=DATE:20260215\r\n";
		$ical .= "DTEND;VALUE=DATE:20260216\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$saved_event         = $this->create_mock_event( array( 'id' => 1 ) );
		$captured_occurrence = null;

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )->andReturn( $saved_event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )
			->andReturnUsing(
				function ( $occurrence ) use ( &$captured_occurrence ) {
					$captured_occurrence = $occurrence;
					return $occurrence;
				}
			);

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$service->import_ical( $ical );

		$this->assertNotNull( $captured_occurrence );
		$this->assertTrue( $captured_occurrence->all_day );
	}

	/**
	 * Test parse_ical with invalid content returns empty array.
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_with_no_vevents(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "VERSION:2.0\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test parse_ical with completely invalid content.
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_with_invalid_content(): void {
		$service = $this->make_service();
		$result  = $service->parse_ical( 'not ical content at all' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test parse_ical handles URL property.
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_handles_url(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:URL Test\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "URL:https://example.com/event/123\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://example.com/event/123', $result[0]['url'] );
	}

	/**
	 * Test parse_ical handles event without end time.
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_event_without_end_uses_start(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:No End Time\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertCount( 1, $result );
		// End time should default to start time.
		$this->assertSame( $result[0]['start_datetime'], $result[0]['end_datetime'] );
	}

	/**
	 * Test parse_ical with local datetime (no Z suffix).
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_local_datetime(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Local Time Event\r\n";
		$ical .= "DTSTART:20260215T190000\r\n";
		$ical .= "DTEND:20260215T210000\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertCount( 1, $result );
		// Local time should not be converted.
		$this->assertSame( '2026-02-15 19:00:00', $result[0]['start_datetime'] );
		$this->assertSame( '2026-02-15 21:00:00', $result[0]['end_datetime'] );
	}

	/**
	 * Test parse_ical with lines that have no colon are skipped.
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_skips_invalid_lines(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Valid Event\r\n";
		$ical .= "INVALID LINE WITHOUT COLON\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Valid Event', $result[0]['summary'] );
	}

	/**
	 * Test parse_ical with different line endings (CR only).
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_handles_cr_line_endings(): void {
		$ical = "BEGIN:VCALENDAR\rBEGIN:VEVENT\rSUMMARY:CR Only\rDTSTART:20260215T190000Z\rEND:VEVENT\rEND:VCALENDAR\r";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertCount( 1, $result );
		$this->assertSame( 'CR Only', $result[0]['summary'] );
	}

	/**
	 * Test import_ical uses custom status from options.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_uses_custom_status(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Status Test\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$captured_event = null;
		$saved_event    = $this->create_mock_event( array( 'id' => 1 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )
			->andReturnUsing(
				function ( $event ) use ( &$captured_event, $saved_event ) {
					$captured_event = $event;
					return $saved_event;
				}
			);

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $this->create_mock_occurrence() );

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$service->import_ical( $ical, array( 'status' => 'published' ) );

		$this->assertNotNull( $captured_event );
		$this->assertSame( EventStatus::PUBLISHED, $captured_event->status );
	}

	/**
	 * Test export_calendar_feed with multiple events and occurrences.
	 *
	 * @covers ::export_calendar_feed
	 */
	public function test_export_calendar_feed_multiple_events(): void {
		$event1      = $this->create_mock_event(
			array(
				'id'    => 1,
				'title' => 'Event 1',
			)
		);
		$event2      = $this->create_mock_event(
			array(
				'id'    => 2,
				'title' => 'Event 2',
			)
		);
		$occurrence1 = $this->create_mock_occurrence(
			array(
				'id'       => 1,
				'event_id' => 1,
			)
		);
		$occurrence2 = $this->create_mock_occurrence(
			array(
				'id'       => 2,
				'event_id' => 2,
			)
		);

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'all' )->andReturn( array( $event1, $event2 ) );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )
			->with( 1, Mockery::any() )
			->andReturn( array( $occurrence1 ) );
		$occurrence_repo->shouldReceive( 'for_event' )
			->with( 2, Mockery::any() )
			->andReturn( array( $occurrence2 ) );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_calendar_feed();

		$this->assertSame( 2, substr_count( $result, 'BEGIN:VEVENT' ) );
		$this->assertStringContainsString( 'SUMMARY:Event 1', $result );
		$this->assertStringContainsString( 'SUMMARY:Event 2', $result );
	}

	/**
	 * Test export_calendar_feed emits a master VEVENT + RRULE for recurring events.
	 *
	 * Recurring events in the feed fetch ALL occurrences (no scheduled filter, for
	 * EXDATE) and collapse to one master VEVENT with the RRULE.
	 *
	 * @covers ::export_calendar_feed
	 */
	public function test_export_calendar_feed_recurring_event_emits_master_rrule(): void {
		$event = $this->create_mock_event(
			array(
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=TU',
			)
		);

		$scheduled = $this->create_mock_occurrence( array( 'id' => 1 ) );
		$cancelled = $this->create_mock_occurrence(
			array(
				'id'              => 2,
				'start_datetime'  => '2026-01-27 19:00:00',
				'end_datetime'    => '2026-01-27 21:00:00',
				'status'          => 'cancelled',
				'is_override'     => true,
				'sequence_number' => 2,
			)
		);

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'all' )->andReturn( array( $event ) );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		// Recurring path fetches ALL occurrences (no args), not the scheduled filter.
		$occurrence_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( array( $scheduled, $cancelled ) );

		$rule    = ( new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY ) )
			->with_by_day( array( RecurrenceRule::DAY_TU ) );
		$service = $this->make_service(
			$event_repo,
			$occurrence_repo,
			null,
			$this->make_recurrence_service( $rule )
		);
		$result  = $service->export_calendar_feed();

		$this->assertSame( 1, substr_count( $result, 'BEGIN:VEVENT' ) );
		// Master RRULE is emitted. The open-ended rule also receives the feed-horizon
		// UNTIL cap (NTE-014), asserted explicitly elsewhere; assert the stable parts here.
		$this->assertStringContainsString( 'RRULE:FREQ=WEEKLY;', $result );
		$this->assertStringContainsString( 'BYDAY=TU', $result );
		$this->assertStringContainsString( 'EXDATE:20260127T190000Z', $result );
		$this->assertStringContainsString( 'UID:event-1@example.com', $result );
	}

	/**
	 * Test import creates nettertech_events_categories entries from iCal CATEGORIES.
	 *
	 * Regression test for BUG-003: iCal import was discarding parsed categories,
	 * leaving the frontend category filter dropdown empty.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_creates_categories_from_ical(): void {
		$event = $this->create_mock_event( array( 'id' => 42 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )->andReturn( $event );

		$mock_occurrence     = new Occurrence();
		$mock_occurrence->id = 1;

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $mock_occurrence );

		// Track CategoryRepository operations via injected mock.
		$categories_saved = array();
		$synced_event_id  = null;
		$synced_cat_ids   = array();

		$cat_repo_mock = Mockery::mock( CategoryRepository::class );

		// find_by_slug returns null (categories don't exist yet).
		$cat_repo_mock->shouldReceive( 'find_by_slug' )->andReturn( null );

		// save() tracks names and returns Category objects with sequential IDs.
		$cat_repo_mock->shouldReceive( 'save' )->andReturnUsing(
			function ( $category ) use ( &$categories_saved ) {
				$categories_saved[] = $category->name;
				$saved       = new \NetterTechEvents\Models\Category();
				$saved->id   = count( $categories_saved ) + 100;
				$saved->name = $category->name;
				$saved->slug = $category->slug;
				return $saved;
			}
		);

		// sync_event_categories tracks the call.
		$cat_repo_mock->shouldReceive( 'sync_event_categories' )->andReturnUsing(
			function ( $event_id, $cat_ids ) use ( &$synced_event_id, &$synced_cat_ids ) {
				$synced_event_id = $event_id;
				$synced_cat_ids  = $cat_ids;
				return true;
			}
		);

		Functions\when( 'sanitize_title' )->alias(
			function ( $title ) {
				return strtolower( str_replace( ' ', '-', $title ) );
			}
		);

		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Music Night\r\n";
		$ical .= "DTSTART:20260301T190000Z\r\n";
		$ical .= "DTEND:20260301T210000Z\r\n";
		$ical .= "CATEGORIES:Live Music,Irish Traditional\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service( $event_repo, $occurrence_repo, $cat_repo_mock );
		$result  = $service->import_ical( $ical );

		$this->assertSame( 1, $result['imported'] );
		$this->assertContains( 'Live Music', $categories_saved );
		$this->assertContains( 'Irish Traditional', $categories_saved );
		$this->assertSame( 42, $synced_event_id );
		$this->assertCount( 2, $synced_cat_ids );
	}

	/**
	 * Test constructor creates service with default dependencies.
	 *
	 * @covers ::__construct
	 */
	public function test_constructor_with_default_dependencies(): void {
		$service = $this->make_service();
		$this->assertInstanceOf( ICalService::class, $service );
	}

	/**
	 * Test parse_ical with \N newline escape variant.
	 *
	 * @covers ::parse_ical
	 */
	public function test_parse_ical_handles_uppercase_n_escape(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Line\\NBreak Test\r\n";
		$ical .= "DTSTART:20260215T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service();
		$result  = $service->parse_ical( $ical );

		$this->assertCount( 1, $result );
		$this->assertSame( "Line\nBreak Test", $result[0]['summary'] );
	}

	// =========================================================================
	// Branch Coverage Tests
	// =========================================================================

	/**
	 * Test import_ical with skip_duplicates=true and UID still imports
	 * because find_event_by_uid is a stub returning null.
	 *
	 * Exercises the skip_duplicates + UID code path where the lookup
	 * falls through to import because the stub always returns null.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_skip_duplicates_true_with_uid_imports(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "UID:unique-event-abc@example.com\r\n";
		$ical .= "SUMMARY:UID Dedup Test\r\n";
		$ical .= "DTSTART:20260301T190000Z\r\n";
		$ical .= "DTEND:20260301T210000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$saved_event = $this->create_mock_event( array( 'id' => 5 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )->once()->andReturn( $saved_event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->once()->andReturn( $this->create_mock_occurrence() );

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );

		// Default options have skip_duplicates=true.
		$result = $service->import_ical( $ical );

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Test import_ical with event that has no end time flows through import correctly.
	 *
	 * parse_ical sets end_datetime = start_datetime when DTEND is missing;
	 * verifies create_event_from_data handles equal start/end.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_event_without_end_time(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:No End Time Import\r\n";
		$ical .= "DTSTART:20260315T180000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$saved_event         = $this->create_mock_event( array( 'id' => 7 ) );
		$captured_occurrence = null;

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )->andReturn( $saved_event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )
			->andReturnUsing(
				function ( $occurrence ) use ( &$captured_occurrence ) {
					$captured_occurrence = $occurrence;
					return $occurrence;
				}
			);

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->import_ical( $ical );

		$this->assertSame( 1, $result['imported'] );
		$this->assertNotNull( $captured_occurrence );
		$this->assertSame( $captured_occurrence->start_datetime, $captured_occurrence->end_datetime );
	}

	/**
	 * Test import_ical with multiple events where some throw exceptions.
	 *
	 * Verifies partial success: successful imports are counted,
	 * failures are captured in the errors array.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_partial_success_with_errors(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Good Event\r\n";
		$ical .= "DTSTART:20260301T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Bad Event\r\n";
		$ical .= "DTSTART:20260302T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Another Good Event\r\n";
		$ical .= "DTSTART:20260303T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$saved_event = $this->create_mock_event( array( 'id' => 1 ) );
		$call_count  = 0;

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )
			->andReturnUsing(
				function () use ( &$call_count, $saved_event ) {
					++$call_count;
					if ( 2 === $call_count ) {
						throw new \Exception( 'Connection lost' );
					}
					return $saved_event;
				}
			);

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $this->create_mock_occurrence() );

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->import_ical( $ical );

		$this->assertSame( 2, $result['imported'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'Bad Event', $result['errors'][0] );
		$this->assertStringContainsString( 'Connection lost', $result['errors'][0] );
	}

	/**
	 * Test export_calendar_feed passes correct status filter to occurrence_repo.
	 *
	 * The method calls for_event with array('status' => 'scheduled', ...);
	 * verifies the 'scheduled' filter is passed correctly.
	 *
	 * @covers ::export_calendar_feed
	 */
	public function test_export_calendar_feed_passes_scheduled_status_filter(): void {
		$event      = $this->create_mock_event();
		$occurrence = $this->create_mock_occurrence();

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'all' )->andReturn( array( $event ) );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )
			->withArgs(
				function ( $event_id, $args ) {
					return 1 === $event_id
						&& is_array( $args )
						&& 'scheduled' === $args['status'];
				}
			)
			->andReturn( array( $occurrence ) );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_calendar_feed();

		$this->assertStringContainsString( 'BEGIN:VEVENT', $result );
	}

	/**
	 * Test export_occurrence with multi-day timed event (different dates).
	 *
	 * Verifies DTSTART and DTEND span multiple days correctly when all_day=false.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_multi_day_timed_event(): void {
		$event      = $this->create_mock_event();
		$occurrence = $this->create_mock_occurrence(
			array(
				'start_datetime' => '2026-03-20 19:00:00',
				'end_datetime'   => '2026-03-22 02:00:00',
				'all_day'        => false,
			)
		);

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		$this->assertStringContainsString( 'DTSTART:20260320T190000Z', $result );
		$this->assertStringContainsString( 'DTEND:20260322T020000Z', $result );
		// Should NOT use VALUE=DATE format since all_day is false.
		$this->assertStringNotContainsString( 'VALUE=DATE', $result );
	}

	/**
	 * Test export escapes special characters in event title via escape_text.
	 *
	 * The private escape_text method is tested indirectly through export output.
	 * Commas, semicolons, backslashes, and newlines must be escaped per RFC 5545.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_escapes_special_characters_in_title(): void {
		$event      = $this->create_mock_event(
			array(
				'title' => 'Music, Dance; Art\\Gallery',
			)
		);
		$occurrence = $this->create_mock_occurrence();

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		// Backslash escaped first, then comma, then semicolon.
		$this->assertStringContainsString( 'SUMMARY:Music\\, Dance\\; Art\\\\Gallery', $result );
	}

	/**
	 * Test export escapes newlines in description via escape_text.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_escapes_newlines_in_description(): void {
		$event      = $this->create_mock_event(
			array(
				'description' => "Line one\nLine two\r\nLine three",
			)
		);
		$occurrence = $this->create_mock_occurrence();

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		// \n becomes \\n in iCal, \r is stripped.
		$this->assertStringContainsString( 'DESCRIPTION:Line one\\nLine two\\nLine three', $result );
	}

	/**
	 * Test import_ical with location containing no comma sets venue_name only.
	 *
	 * When LOCATION is a single value (no comma), create_event_from_data
	 * should set venue_name and leave venue_address empty.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_location_without_comma(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Venue Only Event\r\n";
		$ical .= "DTSTART:20260301T190000Z\r\n";
		$ical .= "LOCATION:Celtic Junction\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$captured_event = null;
		$saved_event    = $this->create_mock_event( array( 'id' => 10 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )
			->andReturnUsing(
				function ( $event ) use ( &$captured_event, $saved_event ) {
					$captured_event = $event;
					return $saved_event;
				}
			);

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $this->create_mock_occurrence() );

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$service->import_ical( $ical );

		$this->assertNotNull( $captured_event );
		$this->assertSame( 'Celtic Junction', $captured_event->venue_name );
		$this->assertSame( '', $captured_event->venue_address );
	}

	/**
	 * Test import_ical with categories that already exist uses existing IDs.
	 *
	 * When find_by_slug returns an existing category, sync_categories_to_taxonomy
	 * should use that category's ID instead of creating a new one.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_existing_categories_reused(): void {
		$event = $this->create_mock_event( array( 'id' => 20 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )->andReturn( $event );

		$mock_occurrence     = new Occurrence();
		$mock_occurrence->id = 1;

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $mock_occurrence );

		$existing_cat       = new \NetterTechEvents\Models\Category();
		$existing_cat->id   = 50;
		$existing_cat->name = 'Music';
		$existing_cat->slug = 'music';

		$synced_cat_ids = array();
		$save_called    = false;

		$cat_repo_mock = Mockery::mock( CategoryRepository::class );

		// find_by_slug returns existing category for 'music', null for others.
		$cat_repo_mock->shouldReceive( 'find_by_slug' )
			->andReturnUsing(
				function ( $slug ) use ( $existing_cat ) {
					return 'music' === $slug ? $existing_cat : null;
				}
			);

		// save() should only be called for the new category 'Dance'.
		$cat_repo_mock->shouldReceive( 'save' )
			->andReturnUsing(
				function ( $category ) use ( &$save_called ) {
					$save_called     = true;
					$saved           = new \NetterTechEvents\Models\Category();
					$saved->id       = 200;
					$saved->name     = $category->name;
					$saved->slug     = $category->slug;
					return $saved;
				}
			);

		$cat_repo_mock->shouldReceive( 'sync_event_categories' )
			->andReturnUsing(
				function ( $event_id, $cat_ids ) use ( &$synced_cat_ids ) {
					$synced_cat_ids = $cat_ids;
					return true;
				}
			);

		$cat_repo_mock->shouldReceive( 'find_by_event' )->andReturn( array() );

		Functions\when( 'sanitize_title' )->alias(
			function ( $title ) {
				return strtolower( str_replace( ' ', '-', $title ) );
			}
		);

		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Mixed Categories Event\r\n";
		$ical .= "DTSTART:20260401T190000Z\r\n";
		$ical .= "DTEND:20260401T210000Z\r\n";
		$ical .= "CATEGORIES:Music,Dance\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service( $event_repo, $occurrence_repo, $cat_repo_mock );
		$result  = $service->import_ical( $ical );

		$this->assertSame( 1, $result['imported'] );
		$this->assertTrue( $save_called, 'save() should be called for the new "Dance" category' );
		// Should contain existing category ID 50 and new category ID 200.
		$this->assertContains( 50, $synced_cat_ids );
		$this->assertContains( 200, $synced_cat_ids );
	}

	/**
	 * Test export_calendar_feed includes calendar-level headers.
	 *
	 * Verifies PRODID, VERSION, CALSCALE, METHOD, and X-WR-CALNAME
	 * are present in the feed output.
	 *
	 * @covers ::export_calendar_feed
	 */
	public function test_export_calendar_feed_includes_calendar_headers(): void {
		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'all' )->andReturn( array() );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_calendar_feed();

		$this->assertStringContainsString( 'PRODID:-//NetterTech Events//Events Plugin//EN', $result );
		$this->assertStringContainsString( 'VERSION:2.0', $result );
		$this->assertStringContainsString( 'CALSCALE:GREGORIAN', $result );
		$this->assertStringContainsString( 'METHOD:PUBLISH', $result );
		$this->assertStringContainsString( 'X-WR-CALNAME:Test Site Events', $result );
	}

	/**
	 * Test export_event honors occurrence overrides on the plain-VEVENT path.
	 *
	 * For single events (and the unparseable-rule fallback) each occurrence is a
	 * separate plain VEVENT, so title_override / description_override are honored.
	 * (For recurring masters, overrides become RECURRENCE-ID VEVENTs — Chunk 7b —
	 * and are intentionally NOT reflected on the master; see the recurring test.)
	 *
	 * @covers ::export_event
	 */
	public function test_export_event_uses_occurrence_overrides(): void {
		$event       = $this->create_mock_event(
			array(
				'title'       => 'Weekly Class',
				'description' => 'Default class description',
			)
		);
		$occurrence1 = $this->create_mock_occurrence(
			array(
				'id'                   => 10,
				'title_override'       => null,
				'description_override' => null,
			)
		);
		$occurrence2 = $this->create_mock_occurrence(
			array(
				'id'                   => 11,
				'start_datetime'       => '2026-02-01 19:00:00',
				'end_datetime'         => '2026-02-01 21:00:00',
				'title_override'       => 'Special Guest Session',
				'description_override' => 'Guest instructor this week',
			)
		);

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( array( $occurrence1, $occurrence2 ) );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_event( 1 );

		$this->assertNotNull( $result );

		// First occurrence should use event defaults.
		$this->assertStringContainsString( 'SUMMARY:Weekly Class', $result );
		$this->assertStringContainsString( 'DESCRIPTION:Default class description', $result );

		// Second occurrence should use overrides.
		$this->assertStringContainsString( 'SUMMARY:Special Guest Session', $result );
		$this->assertStringContainsString( 'DESCRIPTION:Guest instructor this week', $result );
	}

	/**
	 * Test recurring master uses event-level fields, not occurrence overrides (7a).
	 *
	 * In the compact-recurring model the master VEVENT carries event-level
	 * SUMMARY/DESCRIPTION; per-occurrence overrides are deferred to Chunk 7b
	 * (RECURRENCE-ID VEVENTs) and must not leak onto the master.
	 *
	 * @covers ::export_event
	 */
	public function test_export_event_recurring_master_ignores_occurrence_overrides(): void {
		$event       = $this->create_mock_event(
			array(
				'title'           => 'Weekly Class',
				'description'     => 'Default class description',
				'event_type'      => 'recurring',
				'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=TU',
			)
		);
		$occurrence1 = $this->create_mock_occurrence( array( 'id' => 10 ) );
		$occurrence2 = $this->create_mock_occurrence(
			array(
				'id'                   => 11,
				'start_datetime'       => '2026-02-01 19:00:00',
				'end_datetime'         => '2026-02-01 21:00:00',
				'title_override'       => 'Special Guest Session',
				'description_override' => 'Guest instructor this week',
			)
		);

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )
			->with( 1 )
			->andReturn( array( $occurrence1, $occurrence2 ) );

		$rule    = ( new RecurrenceRule( RecurrenceRule::FREQ_WEEKLY ) )
			->with_by_day( array( RecurrenceRule::DAY_TU ) );
		$service = $this->make_service(
			$event_repo,
			$occurrence_repo,
			null,
			$this->make_recurrence_service( $rule )
		);
		$result  = $service->export_event( 1 );

		$this->assertNotNull( $result );
		$this->assertSame( 1, substr_count( $result, 'BEGIN:VEVENT' ) );
		$this->assertStringContainsString( 'SUMMARY:Weekly Class', $result );
		$this->assertStringNotContainsString( 'Special Guest Session', $result );
	}

	/**
	 * Test export_occurrence includes STATUS and TRANSP fields.
	 *
	 * Every exported VEVENT should contain STATUS:CONFIRMED and TRANSP:OPAQUE.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_includes_status_and_transp(): void {
		$event      = $this->create_mock_event();
		$occurrence = $this->create_mock_occurrence();

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		$this->assertStringContainsString( 'STATUS:CONFIRMED', $result );
		$this->assertStringContainsString( 'TRANSP:OPAQUE', $result );
	}

	/**
	 * Test import_ical with event that has no location omits venue fields.
	 *
	 * When LOCATION is absent, create_event_from_data should not set
	 * venue_name or venue_address.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_without_location(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:No Location Event\r\n";
		$ical .= "DTSTART:20260501T190000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$captured_event = null;
		$saved_event    = $this->create_mock_event( array( 'id' => 15 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )
			->andReturnUsing(
				function ( $event ) use ( &$captured_event, $saved_event ) {
					$captured_event = $event;
					return $saved_event;
				}
			);

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $this->create_mock_occurrence() );

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$service->import_ical( $ical );

		$this->assertNotNull( $captured_event );
		// venue_name should remain null since no location was provided.
		$this->assertNull( $captured_event->venue_name );
		$this->assertNull( $captured_event->venue_address ?? null );
	}

	/**
	 * Test import_ical with empty categories array does not call sync.
	 *
	 * When the iCal event has no CATEGORIES, sync_categories_to_taxonomy
	 * should not be invoked.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_without_categories_skips_sync(): void {
		$event = $this->create_mock_event( array( 'id' => 30 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )->andReturn( $event );

		$mock_occurrence     = new Occurrence();
		$mock_occurrence->id = 1;

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $mock_occurrence );

		$sync_called = false;

		$cat_repo_mock = Mockery::mock( CategoryRepository::class );
		$cat_repo_mock->shouldReceive( 'find_by_event' )->andReturn( array() );
		$cat_repo_mock->shouldReceive( 'sync_event_categories' )
			->andReturnUsing(
				function () use ( &$sync_called ) {
					$sync_called = true;
				}
			);

		Functions\when( 'sanitize_title' )->returnArg();

		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:No Categories Event\r\n";
		$ical .= "DTSTART:20260501T190000Z\r\n";
		$ical .= "DTEND:20260501T210000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service( $event_repo, $occurrence_repo, $cat_repo_mock );
		$result  = $service->import_ical( $ical );

		$this->assertSame( 1, $result['imported'] );
		$this->assertFalse( $sync_called, 'sync_event_categories should not be called without categories' );
	}

	/**
	 * Test import_ical with category save failure skips the failed category.
	 *
	 * When CategoryRepository::save() throws RuntimeException for one category,
	 * sync_categories_to_taxonomy should skip it and continue with others.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_category_save_failure_skips_category(): void {
		$event = $this->create_mock_event( array( 'id' => 25 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )->andReturn( $event );

		$mock_occurrence     = new Occurrence();
		$mock_occurrence->id = 1;

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $mock_occurrence );

		$synced_cat_ids = array();

		$cat_repo_mock = Mockery::mock( CategoryRepository::class );
		$cat_repo_mock->shouldReceive( 'find_by_slug' )->andReturn( null );
		$cat_repo_mock->shouldReceive( 'find_by_event' )->andReturn( array() );

		$save_call_count = 0;
		$cat_repo_mock->shouldReceive( 'save' )
			->andReturnUsing(
				function ( $category ) use ( &$save_call_count ) {
					++$save_call_count;
					if ( 1 === $save_call_count ) {
						throw new \RuntimeException( 'Duplicate slug' );
					}
					$saved       = new \NetterTechEvents\Models\Category();
					$saved->id   = 300;
					$saved->name = $category->name;
					$saved->slug = $category->slug;
					return $saved;
				}
			);

		$cat_repo_mock->shouldReceive( 'sync_event_categories' )
			->andReturnUsing(
				function ( $event_id, $cat_ids ) use ( &$synced_cat_ids ) {
					$synced_cat_ids = $cat_ids;
					return true;
				}
			);

		Functions\when( 'sanitize_title' )->alias(
			function ( $title ) {
				return strtolower( str_replace( ' ', '-', $title ) );
			}
		);
		Functions\when( 'error_log' )->justReturn( true );

		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Category Fail Event\r\n";
		$ical .= "DTSTART:20260601T190000Z\r\n";
		$ical .= "DTEND:20260601T210000Z\r\n";
		$ical .= "CATEGORIES:Bad Category,Good Category\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service( $event_repo, $occurrence_repo, $cat_repo_mock );
		$result  = $service->import_ical( $ical );

		$this->assertSame( 1, $result['imported'] );
		// Only the second category should be synced (first one threw exception).
		$this->assertCount( 1, $synced_cat_ids );
		$this->assertContains( 300, $synced_cat_ids );
	}

	/**
	 * Test import_ical with empty category name in CATEGORIES is skipped.
	 *
	 * When CATEGORIES contains an empty segment (e.g., trailing comma),
	 * sync_categories_to_taxonomy should skip blank names.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_empty_category_name_skipped(): void {
		$event = $this->create_mock_event( array( 'id' => 35 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )->andReturn( $event );

		$mock_occurrence     = new Occurrence();
		$mock_occurrence->id = 1;

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $mock_occurrence );

		$categories_saved = array();

		$cat_repo_mock = Mockery::mock( CategoryRepository::class );
		$cat_repo_mock->shouldReceive( 'find_by_slug' )->andReturn( null );
		$cat_repo_mock->shouldReceive( 'find_by_event' )->andReturn( array() );
		$cat_repo_mock->shouldReceive( 'save' )
			->andReturnUsing(
				function ( $category ) use ( &$categories_saved ) {
					$categories_saved[] = $category->name;
					$saved       = new \NetterTechEvents\Models\Category();
					$saved->id   = count( $categories_saved ) + 400;
					$saved->name = $category->name;
					$saved->slug = $category->slug;
					return $saved;
				}
			);
		$cat_repo_mock->shouldReceive( 'sync_event_categories' )->andReturn( true );

		Functions\when( 'sanitize_title' )->alias(
			function ( $title ) {
				return strtolower( str_replace( ' ', '-', $title ) );
			}
		);

		// Note: The VEventParser splits by comma and trims. An empty string after
		// trimming should be skipped by sync_categories_to_taxonomy.
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Trailing Comma Event\r\n";
		$ical .= "DTSTART:20260701T190000Z\r\n";
		$ical .= "DTEND:20260701T210000Z\r\n";
		$ical .= "CATEGORIES:Music, ,Dance\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$service = $this->make_service( $event_repo, $occurrence_repo, $cat_repo_mock );
		$result  = $service->import_ical( $ical );

		$this->assertSame( 1, $result['imported'] );
		// Only 'Music' and 'Dance' should be saved; empty string skipped.
		$this->assertCount( 2, $categories_saved );
		$this->assertContains( 'Music', $categories_saved );
		$this->assertContains( 'Dance', $categories_saved );
	}

	/**
	 * Test export_occurrence includes DTSTAMP field.
	 *
	 * Every exported VEVENT must contain a DTSTAMP per RFC 5545.
	 *
	 * @covers ::export_occurrence
	 */
	public function test_export_occurrence_includes_dtstamp(): void {
		$event      = $this->create_mock_event();
		$occurrence = $this->create_mock_occurrence();

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'find' )->andReturn( $occurrence );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'find' )->andReturn( $event );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_occurrence( 1 );

		// DTSTAMP should be present in UTC format.
		$this->assertMatchesRegularExpression( '/DTSTAMP:\d{8}T\d{6}Z/', $result );
	}

	/**
	 * Test import_ical result includes events array.
	 *
	 * The return value should include an 'events' key with saved Event objects.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_result_includes_events_array(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Events Array Test\r\n";
		$ical .= "DTSTART:20260801T190000Z\r\n";
		$ical .= "DTEND:20260801T210000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$saved_event = $this->create_mock_event(
			array(
				'id'    => 99,
				'title' => 'Events Array Test',
			)
		);

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )->andReturn( $saved_event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $this->create_mock_occurrence() );

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->import_ical( $ical );

		$this->assertArrayHasKey( 'events', $result );
		$this->assertCount( 1, $result['events'] );
		$this->assertSame( 99, $result['events'][0]->id );
	}

	/**
	 * Test export_calendar_feed passes start_from argument to occurrence_repo.
	 *
	 * Verifies custom start_from is forwarded to the occurrence query.
	 *
	 * @covers ::export_calendar_feed
	 */
	public function test_export_calendar_feed_passes_start_from(): void {
		$event      = $this->create_mock_event();
		$occurrence = $this->create_mock_occurrence();

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'all' )->andReturn( array( $event ) );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'for_event' )
			->withArgs(
				function ( $event_id, $args ) {
					return 1 === $event_id
						&& is_array( $args )
						&& '2026-06-01' === $args['start_from'];
				}
			)
			->andReturn( array( $occurrence ) );

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$result  = $service->export_calendar_feed( array( 'start_from' => '2026-06-01' ) );

		$this->assertStringContainsString( 'BEGIN:VEVENT', $result );
	}

	/**
	 * Test import_ical sets occurrence status to 'scheduled'.
	 *
	 * Regardless of the event status option, occurrence status should
	 * always be 'scheduled'.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_occurrence_status_is_scheduled(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Occurrence Status Test\r\n";
		$ical .= "DTSTART:20260901T190000Z\r\n";
		$ical .= "DTEND:20260901T210000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$saved_event         = $this->create_mock_event( array( 'id' => 40 ) );
		$captured_occurrence = null;

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )->andReturn( $saved_event );

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )
			->andReturnUsing(
				function ( $occurrence ) use ( &$captured_occurrence ) {
					$captured_occurrence = $occurrence;
					return $occurrence;
				}
			);

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$service->import_ical( $ical, array( 'status' => 'published' ) );

		$this->assertNotNull( $captured_occurrence );
		$this->assertSame( 'scheduled', $captured_occurrence->status );
	}

	/**
	 * Test import_ical sets event_type to 'single' when no RRULE present.
	 *
	 * @covers ::import_ical
	 */
	public function test_import_ical_sets_single_event_type_without_rrule(): void {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "SUMMARY:Single Type Test\r\n";
		$ical .= "DTSTART:20261001T190000Z\r\n";
		$ical .= "DTEND:20261001T210000Z\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		$captured_event = null;
		$saved_event    = $this->create_mock_event( array( 'id' => 45 ) );

		$event_repo = Mockery::mock( EventRepository::class );
		$event_repo->shouldReceive( 'generate_unique_slug' )->andReturnUsing( fn( $title ) => sanitize_title( $title ) );
		$event_repo->shouldReceive( 'save' )
			->andReturnUsing(
				function ( $event ) use ( &$captured_event, $saved_event ) {
					$captured_event = $event;
					return $saved_event;
				}
			);

		$occurrence_repo = Mockery::mock( OccurrenceRepository::class );
		$occurrence_repo->shouldReceive( 'save' )->andReturn( $this->create_mock_occurrence() );

		Functions\when( 'sanitize_title' )->returnArg();

		$service = $this->make_service( $event_repo, $occurrence_repo );
		$service->import_ical( $ical );

		$this->assertNotNull( $captured_event );
		$this->assertSame( 'single', $captured_event->event_type );
		$this->assertNull( $captured_event->recurrence_rule ?? null );
	}
}
