<?php
/**
 * CalendarLinkService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use Brain\Monkey\Functions;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Services\CalendarLinkService;

/**
 * Test CalendarLinkService URL generation.
 */
class CalendarLinkServiceTest extends \NetterTechEventsTestCase {

	/**
	 * Service under test.
	 *
	 * @var CalendarLinkService
	 */
	private CalendarLinkService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new CalendarLinkService();

		Functions\when( 'rest_url' )->alias(
			function ( string $path ): string {
				return 'https://example.com/wp-json/' . $path;
			}
		);

		Functions\when( 'wp_strip_all_tags' )->alias(
			function ( string $text ): string {
				return strip_tags( $text );
			}
		);
	}

	/**
	 * Create a test occurrence with an event.
	 *
	 * @param string      $start       Start datetime.
	 * @param string      $end         End datetime.
	 * @param string      $timezone    Timezone.
	 * @param bool        $all_day     All-day flag.
	 * @param string|null $venue_name  Venue name.
	 * @param string|null $venue_addr  Venue address.
	 * @return Occurrence
	 */
	private function make_occurrence(
		string $start = '2026-06-15 19:00:00',
		string $end = '2026-06-15 21:00:00',
		string $timezone = 'America/Chicago',
		bool $all_day = false,
		?string $venue_name = 'The Bellwright',
		?string $venue_addr = '123 Main St, Minneapolis, MN'
	): Occurrence {
		$event                = new Event();
		$event->id            = 42;
		$event->title         = 'Summer Concert';
		$event->description   = 'An evening of live music.';
		$event->venue_name    = $venue_name;
		$event->venue_address = $venue_addr;
		$event->slug          = 'summer-concert';

		$occurrence                 = new Occurrence();
		$occurrence->id             = 101;
		$occurrence->event_id       = 42;
		$occurrence->start_datetime = $start;
		$occurrence->end_datetime   = $end;
		$occurrence->timezone       = $timezone;
		$occurrence->all_day        = $all_day;
		$occurrence->set_event( $event );

		return $occurrence;
	}

	/**
	 * @testdox Google URL contains correct date range in UTC
	 */
	public function test_google_url_timed_event(): void {
		$occurrence = $this->make_occurrence();
		$url        = $this->service->google_url( $occurrence );

		$this->assertStringStartsWith( 'https://calendar.google.com/calendar/render?', $url );
		$this->assertStringContainsString( 'action=TEMPLATE', $url );
		$this->assertStringContainsString( 'text=Summer', $url );

		// 2026-06-15 19:00 CDT = 2026-06-16 00:00 UTC.
		$this->assertStringContainsString( 'dates=20260616T000000Z', $url );
		$this->assertStringContainsString( 'location=The%20Bellwright', $url );
	}

	/**
	 * @testdox Google URL uses date-only format for all-day events
	 */
	public function test_google_url_all_day_event(): void {
		$occurrence = $this->make_occurrence(
			'2026-06-15 00:00:00',
			'2026-06-15 23:59:59',
			'America/Chicago',
			true
		);

		$url = $this->service->google_url( $occurrence );

		// All-day uses YYYYMMDD format, end = next day.
		$dates = $this->extract_param( $url, 'dates' );
		$this->assertMatchesRegularExpression( '/^\d{8}\/\d{8}$/', $dates );
		$this->assertStringNotContainsString( 'T', $dates );
	}

	/**
	 * @testdox Outlook Live URL has correct domain and ISO datetime
	 */
	public function test_outlook_live_url(): void {
		$occurrence = $this->make_occurrence();
		$url        = $this->service->outlook_live_url( $occurrence );

		$this->assertStringStartsWith( 'https://outlook.live.com/calendar/0/deeplink/compose?', $url );
		$this->assertStringContainsString( 'subject=Summer', $url );
		$this->assertStringContainsString( 'startdt=', $url );
		$this->assertStringContainsString( 'enddt=', $url );
		$this->assertStringContainsString( 'location=The%20Bellwright', $url );
	}

	/**
	 * @testdox Outlook 365 URL has correct domain
	 */
	public function test_outlook_365_url(): void {
		$occurrence = $this->make_occurrence();
		$url        = $this->service->outlook_365_url( $occurrence );

		$this->assertStringStartsWith( 'https://outlook.office.com/calendar/0/deeplink/compose?', $url );
		$this->assertStringContainsString( 'subject=Summer', $url );
	}

	/**
	 * @testdox iCal URL points to REST endpoint
	 */
	public function test_ical_url(): void {
		$occurrence = $this->make_occurrence();
		$url        = $this->service->ical_url( $occurrence );

		$this->assertSame( 'https://example.com/wp-json/nettertech-events/v1/ical/occurrence/101', $url );
	}

	/**
	 * @testdox all_links returns all four providers
	 */
	public function test_all_links_returns_four_providers(): void {
		$occurrence = $this->make_occurrence();
		$links      = $this->service->all_links( $occurrence );

		$this->assertArrayHasKey( 'google', $links );
		$this->assertArrayHasKey( 'outlook_365', $links );
		$this->assertArrayHasKey( 'outlook_live', $links );
		$this->assertArrayHasKey( 'ical', $links );

		foreach ( $links as $link ) {
			$this->assertArrayHasKey( 'url', $link );
			$this->assertArrayHasKey( 'label', $link );
			$this->assertNotEmpty( $link['url'] );
			$this->assertNotEmpty( $link['label'] );
		}
	}

	/**
	 * @testdox Location is empty when event has no venue
	 */
	public function test_url_with_no_venue(): void {
		$occurrence = $this->make_occurrence(
			'2026-06-15 19:00:00',
			'2026-06-15 21:00:00',
			'America/Chicago',
			false,
			null,
			null
		);

		$url = $this->service->google_url( $occurrence );

		$this->assertStringNotContainsString( 'location=', $url );
	}

	/**
	 * @testdox Description is truncated to 1000 characters
	 */
	public function test_long_description_truncated(): void {
		$event              = new Event();
		$event->id          = 1;
		$event->title       = 'Test';
		$event->description = str_repeat( 'A', 2000 );
		$event->slug        = 'test';

		$occurrence                 = new Occurrence();
		$occurrence->id             = 1;
		$occurrence->event_id       = 1;
		$occurrence->start_datetime = '2026-06-15 19:00:00';
		$occurrence->end_datetime   = '2026-06-15 21:00:00';
		$occurrence->timezone       = 'UTC';
		$occurrence->set_event( $event );

		$url = $this->service->google_url( $occurrence );

		// The description should be truncated, meaning the URL contains "..." and not 2000 As.
		$this->assertStringContainsString( '...', urldecode( $url ) );
	}

	/**
	 * @testdox Outlook all-day events include allday=true param
	 */
	public function test_outlook_all_day_event(): void {
		$occurrence = $this->make_occurrence(
			'2026-06-15 00:00:00',
			'2026-06-15 23:59:59',
			'America/Chicago',
			true
		);

		$url = $this->service->outlook_live_url( $occurrence );

		$this->assertStringContainsString( 'allday=true', $url );
		$this->assertStringContainsString( 'startdt=2026-06-15', $url );
	}

	/**
	 * @testdox Title override on occurrence is used instead of event title
	 */
	public function test_title_override(): void {
		$occurrence                 = $this->make_occurrence();
		$occurrence->title_override = 'Special Night';

		$url = $this->service->google_url( $occurrence );

		$this->assertStringContainsString( 'text=Special', $url );
		$this->assertStringNotContainsString( 'Summer', $url );
	}

	/**
	 * Extract a URL query parameter value.
	 *
	 * @param string $url   Full URL.
	 * @param string $param Parameter name.
	 * @return string
	 */
	private function extract_param( string $url, string $param ): string {
		$query = parse_url( $url, PHP_URL_QUERY ) ?? '';
		parse_str( $query, $params );
		return (string) ( $params[ $param ] ?? '' );
	}
}
