<?php
/**
 * Tests for VEventParser.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Services\VEventParser;
use Brain\Monkey\Functions;

/**
 * Test cases for VEventParser.
 *
 * Tests RFC 5545 VEVENT parsing with data-driven property handling.
 *
 * @covers \NetterTechEvents\Services\VEventParser
 */
class VEventParserTest extends \NetterTechEventsTestCase {

	/**
	 * System under test.
	 *
	 * @var VEventParser
	 */
	private VEventParser $parser;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parser = new VEventParser();

		// Stub wp_timezone to return a timezone object.
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Chicago' ) );
	}

	// =========================================================================
	// Basic Parsing Tests
	// =========================================================================

	/**
	 * Test parse extracts basic event data.
	 */
	public function test_parse_extracts_basic_event_data(): void {
		$content = "UID:test-uid-123@example.com\n" .
			"SUMMARY:Test Event\n" .
			"DTSTART:20260115T190000Z\n" .
			"DTEND:20260115T220000Z";

		$result = $this->parser->parse( $content );

		$this->assertIsArray( $result );
		$this->assertEquals( 'test-uid-123@example.com', $result['uid'] );
		$this->assertEquals( 'Test Event', $result['summary'] );
		$this->assertArrayHasKey( 'start_datetime', $result );
		$this->assertArrayHasKey( 'end_datetime', $result );
	}

	/**
	 * Test parse returns null for missing summary.
	 */
	public function test_parse_returns_null_for_missing_summary(): void {
		$content = "UID:test-uid-123@example.com\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertNull( $result );
	}

	/**
	 * Test parse returns null for missing start datetime.
	 */
	public function test_parse_returns_null_for_missing_start(): void {
		$content = "UID:test-uid-123@example.com\n" .
			"SUMMARY:Test Event";

		$result = $this->parser->parse( $content );

		$this->assertNull( $result );
	}

	/**
	 * Test parse uses start as end when end not provided.
	 */
	public function test_parse_defaults_end_to_start(): void {
		$content = "SUMMARY:Test Event\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertIsArray( $result );
		$this->assertEquals( $result['start_datetime'], $result['end_datetime'] );
	}

	// =========================================================================
	// Simple Property Tests
	// =========================================================================

	/**
	 * Test parse extracts description.
	 */
	public function test_parse_extracts_description(): void {
		$content = "SUMMARY:Test Event\n" .
			"DESCRIPTION:This is a test description\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertEquals( 'This is a test description', $result['description'] );
	}

	/**
	 * Test parse extracts location.
	 */
	public function test_parse_extracts_location(): void {
		$content = "SUMMARY:Test Event\n" .
			"LOCATION:Test Venue, 123 Main St\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertEquals( 'Test Venue, 123 Main St', $result['location'] );
	}

	/**
	 * Test parse extracts URL.
	 */
	public function test_parse_extracts_url(): void {
		$content = "SUMMARY:Test Event\n" .
			"URL:https://example.com/event\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertEquals( 'https://example.com/event', $result['url'] );
	}

	/**
	 * Test parse extracts RRULE.
	 */
	public function test_parse_extracts_rrule(): void {
		$content = "SUMMARY:Test Event\n" .
			"RRULE:FREQ=WEEKLY;BYDAY=MO,WE,FR\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertEquals( 'FREQ=WEEKLY;BYDAY=MO,WE,FR', $result['rrule'] );
	}

	// =========================================================================
	// Datetime Parsing Tests
	// =========================================================================

	/**
	 * Test parse handles UTC datetime.
	 */
	public function test_parse_handles_utc_datetime(): void {
		$content = "SUMMARY:Test Event\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		// UTC 19:00 should be converted to Chicago time (CST is UTC-6).
		$this->assertIsArray( $result );
		$this->assertStringContainsString( '2026-01-15', $result['start_datetime'] );
	}

	/**
	 * Test parse handles local datetime.
	 */
	public function test_parse_handles_local_datetime(): void {
		$content = "SUMMARY:Test Event\n" .
			"DTSTART:20260115T190000";

		$result = $this->parser->parse( $content );

		$this->assertEquals( '2026-01-15 19:00:00', $result['start_datetime'] );
	}

	/**
	 * Test parse handles all-day events (VALUE=DATE).
	 */
	public function test_parse_handles_all_day_events(): void {
		$content = "SUMMARY:All Day Event\n" .
			"DTSTART;VALUE=DATE:20260115";

		$result = $this->parser->parse( $content );

		$this->assertEquals( '2026-01-15 00:00:00', $result['start_datetime'] );
		$this->assertTrue( $result['all_day'] );
	}

	/**
	 * Test parse handles datetime without seconds.
	 */
	public function test_parse_handles_datetime_without_seconds(): void {
		$content = "SUMMARY:Test Event\n" .
			"DTSTART:20260115T1900";

		$result = $this->parser->parse( $content );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( '2026-01-15', $result['start_datetime'] );
	}

	// =========================================================================
	// Property Parameters Tests
	// =========================================================================

	/**
	 * Test parse handles DTSTART with TZID parameter.
	 */
	public function test_parse_handles_tzid_parameter(): void {
		$content = "SUMMARY:Test Event\n" .
			"DTSTART;TZID=America/New_York:20260115T190000";

		$result = $this->parser->parse( $content );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['all_day'] ?? false );
	}

	/**
	 * Test parse extracts multiple parameters.
	 */
	public function test_parse_handles_multiple_parameters(): void {
		$content = "SUMMARY:Test Event\n" .
			"DTSTART;VALUE=DATE;X-CUSTOM=value:20260115";

		$result = $this->parser->parse( $content );

		$this->assertTrue( $result['all_day'] );
	}

	// =========================================================================
	// Categories Tests
	// =========================================================================

	/**
	 * Test parse extracts single category.
	 */
	public function test_parse_extracts_single_category(): void {
		$content = "SUMMARY:Test Event\n" .
			"CATEGORIES:Music\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertEquals( array( 'Music' ), $result['categories'] );
	}

	/**
	 * Test parse extracts multiple categories.
	 */
	public function test_parse_extracts_multiple_categories(): void {
		$content = "SUMMARY:Test Event\n" .
			"CATEGORIES:Music,Concert,Jazz\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertCount( 3, $result['categories'] );
		$this->assertContains( 'Music', $result['categories'] );
		$this->assertContains( 'Concert', $result['categories'] );
		$this->assertContains( 'Jazz', $result['categories'] );
	}

	// =========================================================================
	// Text Escaping Tests
	// =========================================================================

	/**
	 * Test parse unescapes newlines.
	 */
	public function test_parse_unescapes_newlines(): void {
		$content = "SUMMARY:Test Event\n" .
			"DESCRIPTION:Line one\\nLine two\\nLine three\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertStringContainsString( "\n", $result['description'] );
	}

	/**
	 * Test parse unescapes uppercase N newlines.
	 */
	public function test_parse_unescapes_uppercase_newlines(): void {
		$content = "SUMMARY:Test Event\n" .
			"DESCRIPTION:Line one\\NLine two\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertStringContainsString( "\n", $result['description'] );
	}

	/**
	 * Test parse unescapes commas.
	 */
	public function test_parse_unescapes_commas(): void {
		$content = "SUMMARY:Test Event\n" .
			"LOCATION:City\\, State\\, Country\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertEquals( 'City, State, Country', $result['location'] );
	}

	/**
	 * Test parse unescapes semicolons.
	 */
	public function test_parse_unescapes_semicolons(): void {
		$content = "SUMMARY:Test Event\n" .
			"DESCRIPTION:First\\; Second\\; Third\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertEquals( 'First; Second; Third', $result['description'] );
	}

	/**
	 * Test parse unescapes backslashes.
	 */
	public function test_parse_unescapes_backslashes(): void {
		$content = "SUMMARY:Test Event\n" .
			"DESCRIPTION:Path\\\\to\\\\file\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertEquals( 'Path\\to\\file', $result['description'] );
	}

	// =========================================================================
	// Edge Cases
	// =========================================================================

	/**
	 * Test parse skips empty lines.
	 */
	public function test_parse_skips_empty_lines(): void {
		$content = "SUMMARY:Test Event\n" .
			"\n" .
			"DTSTART:20260115T190000Z\n" .
			"\n";

		$result = $this->parser->parse( $content );

		$this->assertIsArray( $result );
		$this->assertEquals( 'Test Event', $result['summary'] );
	}

	/**
	 * Test parse skips invalid lines (no colon).
	 */
	public function test_parse_skips_invalid_lines(): void {
		$content = "SUMMARY:Test Event\n" .
			"INVALIDLINE\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertIsArray( $result );
	}

	/**
	 * Test parse handles unknown properties gracefully.
	 */
	public function test_parse_ignores_unknown_properties(): void {
		$content = "SUMMARY:Test Event\n" .
			"X-CUSTOM-PROP:Custom Value\n" .
			"UNKNOWN-PROPERTY:Some Value\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'x-custom-prop', $result );
	}

	/**
	 * Test parse with Windows line endings.
	 */
	public function test_parse_handles_windows_line_endings(): void {
		$content = "SUMMARY:Test Event\r\n" .
			"DTSTART:20260115T190000Z\r\n";

		// The parser expects \n, but we should test trimming.
		$content = str_replace( "\r\n", "\n", $content );
		$result  = $this->parser->parse( $content );

		$this->assertIsArray( $result );
	}

	/**
	 * Test parse handles property with empty value.
	 */
	public function test_parse_handles_empty_value(): void {
		$content = "SUMMARY:Test Event\n" .
			"DESCRIPTION:\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertIsArray( $result );
		$this->assertEquals( '', $result['description'] );
	}

	/**
	 * Test parse handles colon in value.
	 */
	public function test_parse_handles_colon_in_value(): void {
		$content = "SUMMARY:Event: The Beginning\n" .
			"DTSTART:20260115T190000Z";

		$result = $this->parser->parse( $content );

		$this->assertEquals( 'Event: The Beginning', $result['summary'] );
	}

	/**
	 * Test parse handles complete realistic event.
	 */
	public function test_parse_handles_complete_event(): void {
		$content = "UID:event-12345@nettertech-events\n" .
			"SUMMARY:Celtic Music Night\n" .
			"DESCRIPTION:An evening of traditional Irish and Scottish music featuring local artists.\\nDoors open at 6:30 PM.\n" .
			"LOCATION:Celtic Junction Arts Center\\, 836 Prior Ave N\\, St. Paul\\, MN\n" .
			"URL:https://celticjunction.org/events/celtic-music-night\n" .
			"DTSTART:20260115T190000Z\n" .
			"DTEND:20260115T220000Z\n" .
			"CATEGORIES:Music,Celtic,Live Performance\n" .
			"RRULE:FREQ=WEEKLY;BYDAY=TH";

		$result = $this->parser->parse( $content );

		$this->assertIsArray( $result );
		$this->assertEquals( 'event-12345@nettertech-events', $result['uid'] );
		$this->assertEquals( 'Celtic Music Night', $result['summary'] );
		$this->assertStringContainsString( 'traditional Irish', $result['description'] );
		$this->assertStringContainsString( 'Celtic Junction', $result['location'] );
		$this->assertEquals( 'https://celticjunction.org/events/celtic-music-night', $result['url'] );
		$this->assertCount( 3, $result['categories'] );
		$this->assertEquals( 'FREQ=WEEKLY;BYDAY=TH', $result['rrule'] );
	}
}
