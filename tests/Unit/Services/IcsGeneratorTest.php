<?php
/**
 * Tests for IcsGenerator (NTE-107 — extracted from EmailTemplateRenderer).
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Services\IcsGenerator;
use NetterTechEvents\Models\Ticket;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\EventRepository;
use Brain\Monkey\Functions;

/**
 * Test cases for IcsGenerator.
 *
 * @covers \NetterTechEvents\Services\IcsGenerator
 */
class IcsGeneratorTest extends \NetterTechEventsTestCase {

	/**
	 * Mock OccurrenceRepository.
	 *
	 * @var OccurrenceRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Mock EventRepository.
	 *
	 * @var EventRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_repo;

	/**
	 * System under test.
	 *
	 * @var IcsGenerator
	 */
	private IcsGenerator $ics_generator;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->occurrence_repo = $this->createMock( OccurrenceRepository::class );
		$this->event_repo      = $this->createMock( EventRepository::class );

		$this->ics_generator = new IcsGenerator(
			$this->occurrence_repo,
			$this->event_repo
		);

		Functions\when( 'home_url' )->justReturn( 'https://test.local' );
	}

	/**
	 * Create a test ticket.
	 *
	 * @param array $args Override arguments.
	 * @return Ticket
	 */
	private function create_test_ticket( array $args = array() ): Ticket {
		$ticket                 = new Ticket();
		$ticket->id             = $args['id'] ?? 1;
		$ticket->occurrence_id  = $args['occurrence_id'] ?? 100;
		$ticket->ticket_type_id = $args['ticket_type_id'] ?? 10;
		$ticket->ticket_code    = $args['ticket_code'] ?? 'TEST-CODE-123';
		$ticket->price_paid     = $args['price_paid'] ?? 25.00;
		$ticket->status         = $args['status'] ?? 'confirmed';

		return $ticket;
	}

	/**
	 * Create a test event.
	 *
	 * @param array $args Override arguments.
	 * @return Event
	 */
	private function create_test_event( array $args = array() ): Event {
		$event                = new Event();
		$event->id            = $args['id'] ?? 1;
		$event->title         = $args['title'] ?? 'Test Event';
		$event->description   = $args['description'] ?? 'Event description';
		$event->venue_name    = $args['venue_name'] ?? 'Test Venue';
		$event->venue_address = $args['venue_address'] ?? '123 Main St';

		return $event;
	}

	/**
	 * Create a test occurrence.
	 *
	 * @param array $args Override arguments.
	 * @return Occurrence
	 */
	private function create_test_occurrence( array $args = array() ): Occurrence {
		$occurrence                 = new Occurrence();
		$occurrence->id             = $args['id'] ?? 100;
		$occurrence->event_id       = $args['event_id'] ?? 1;
		$occurrence->start_datetime = $args['start_datetime'] ?? '2026-02-01 19:00:00';
		$occurrence->end_datetime   = $args['end_datetime'] ?? '2026-02-01 22:00:00';
		$occurrence->status         = $args['status'] ?? 'scheduled';

		return $occurrence;
	}

	/**
	 * Set up the temp directory for ICS file tests.
	 *
	 * @return string The temp directory path.
	 */
	private function set_up_ics_temp_dir(): string {
		$temp_dir = '/tmp/uploads/nettertech-events/temp';
		if ( ! is_dir( $temp_dir ) ) {
			mkdir( $temp_dir, 0755, true );
		}
		return $temp_dir;
	}

	/**
	 * Clean up generated ICS files from the temp directory.
	 *
	 * @param string $temp_dir The temp directory path.
	 */
	private function clean_up_ics_temp_dir( string $temp_dir ): void {
		$files = glob( $temp_dir . '/*.ics' );
		if ( $files ) {
			foreach ( $files as $file ) {
				unlink( $file );
			}
		}
	}

	// =========================================================================
	// escape_ics_text Tests
	// =========================================================================

	/**
	 * Test escape_ics_text escapes backslashes.
	 */
	public function test_escape_ics_text_escapes_backslashes(): void {
		$result = $this->ics_generator->escape_ics_text( 'path\\to\\file' );

		$this->assertEquals( 'path\\\\to\\\\file', $result );
	}

	/**
	 * Test escape_ics_text escapes commas.
	 */
	public function test_escape_ics_text_escapes_commas(): void {
		$result = $this->ics_generator->escape_ics_text( 'one, two, three' );

		$this->assertEquals( 'one\, two\, three', $result );
	}

	/**
	 * Test escape_ics_text escapes semicolons.
	 */
	public function test_escape_ics_text_escapes_semicolons(): void {
		$result = $this->ics_generator->escape_ics_text( 'a;b;c' );

		$this->assertEquals( 'a\;b\;c', $result );
	}

	/**
	 * Test escape_ics_text escapes newlines.
	 */
	public function test_escape_ics_text_escapes_newlines(): void {
		$result = $this->ics_generator->escape_ics_text( "line1\nline2\nline3" );

		$this->assertEquals( 'line1\\nline2\\nline3', $result );
	}

	/**
	 * Test escape_ics_text handles complex string.
	 */
	public function test_escape_ics_text_handles_complex_string(): void {
		$input  = "Event; with, special\\chars\nand newlines";
		$result = $this->ics_generator->escape_ics_text( $input );

		$this->assertStringNotContainsString( "\n", $result );
		$this->assertStringContainsString( '\,', $result );
		$this->assertStringContainsString( '\;', $result );
		$this->assertStringContainsString( '\\n', $result );
	}

	// =========================================================================
	// generate_ics_file Tests
	// =========================================================================

	/**
	 * Test generate_ics_file returns false for empty tickets.
	 */
	public function test_generate_ics_file_empty_tickets_returns_false(): void {
		$result = $this->ics_generator->generate_ics_file( array() );

		$this->assertFalse( $result );
	}

	/**
	 * Test generate_ics_file creates ICS with valid tickets.
	 */
	public function test_generate_ics_file_with_valid_tickets(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$temp_dir = $this->set_up_ics_temp_dir();

		$ticket     = $this->create_test_ticket( array(
			'ticket_code'   => 'TICKET-ABC',
			'occurrence_id' => 100,
		) );
		$occurrence = $this->create_test_occurrence( array(
			'id'             => 100,
			'event_id'       => 1,
			'start_datetime' => '2026-08-20 19:00:00',
			'end_datetime'   => '2026-08-20 22:00:00',
		) );
		$event      = $this->create_test_event( array(
			'id'            => 1,
			'title'         => 'Autumn Concert',
			'venue_name'    => 'Celtic Hall',
			'venue_address' => '100 Main St',
			'description'   => 'An autumn evening of music.',
		) );

		$this->occurrence_repo->method( 'find' )->willReturn( $occurrence );
		$this->event_repo->method( 'find' )->willReturn( $event );

		$result = $this->ics_generator->generate_ics_file( array( $ticket ) );

		$this->assertIsString( $result );
		$this->assertFileExists( $result );

		$contents = file_get_contents( $result );
		$this->assertStringContainsString( 'BEGIN:VCALENDAR', $contents );
		$this->assertStringContainsString( 'BEGIN:VEVENT', $contents );
		$this->assertStringContainsString( 'SUMMARY:Autumn Concert', $contents );
		$this->assertStringContainsString( 'LOCATION:Celtic Hall', $contents );
		$this->assertStringContainsString( 'END:VCALENDAR', $contents );

		$this->clean_up_ics_temp_dir( $temp_dir );
	}

	/**
	 * Test generate_ics_file deduplicates by occurrence_id.
	 */
	public function test_generate_ics_file_deduplicates_by_occurrence_id(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$temp_dir = $this->set_up_ics_temp_dir();

		$ticket1 = $this->create_test_ticket( array( 'id' => 1, 'occurrence_id' => 100, 'ticket_code' => 'T-001' ) );
		$ticket2 = $this->create_test_ticket( array( 'id' => 2, 'occurrence_id' => 100, 'ticket_code' => 'T-002' ) );
		$ticket3 = $this->create_test_ticket( array( 'id' => 3, 'occurrence_id' => 200, 'ticket_code' => 'T-003' ) );

		$occ100 = $this->create_test_occurrence( array(
			'id'             => 100,
			'event_id'       => 1,
			'start_datetime' => '2026-09-01 19:00:00',
			'end_datetime'   => '2026-09-01 22:00:00',
		) );
		$occ200 = $this->create_test_occurrence( array(
			'id'             => 200,
			'event_id'       => 2,
			'start_datetime' => '2026-09-02 19:00:00',
			'end_datetime'   => '2026-09-02 22:00:00',
		) );

		$event1 = $this->create_test_event( array( 'id' => 1, 'title' => 'Event Alpha' ) );
		$event2 = $this->create_test_event( array( 'id' => 2, 'title' => 'Event Beta' ) );

		$this->occurrence_repo->method( 'find' )
			->willReturnCallback( function ( $id ) use ( $occ100, $occ200 ) {
				return $id === 100 ? $occ100 : ( $id === 200 ? $occ200 : null );
			} );
		$this->event_repo->method( 'find' )
			->willReturnCallback( function ( $id ) use ( $event1, $event2 ) {
				return $id === 1 ? $event1 : ( $id === 2 ? $event2 : null );
			} );

		$result   = $this->ics_generator->generate_ics_file( array( $ticket1, $ticket2, $ticket3 ) );
		$contents = file_get_contents( $result );

		// Two VEVENT blocks (one per unique occurrence), not three.
		$vevent_count = substr_count( $contents, 'BEGIN:VEVENT' );
		$this->assertEquals( 2, $vevent_count );

		$this->assertStringContainsString( 'SUMMARY:Event Alpha', $contents );
		$this->assertStringContainsString( 'SUMMARY:Event Beta', $contents );

		$this->clean_up_ics_temp_dir( $temp_dir );
	}

	/**
	 * Test generate_ics_file skips tickets with missing occurrence or event.
	 */
	public function test_generate_ics_file_skips_missing_occurrence_or_event(): void {
		$temp_dir = $this->set_up_ics_temp_dir();

		$ticket_no_occ   = $this->create_test_ticket( array( 'id' => 1, 'occurrence_id' => 999, 'ticket_code' => 'T-NO-OCC' ) );
		$ticket_no_event = $this->create_test_ticket( array( 'id' => 2, 'occurrence_id' => 300, 'ticket_code' => 'T-NO-EVT' ) );

		$occ300 = $this->create_test_occurrence( array(
			'id'             => 300,
			'event_id'       => 999,
			'start_datetime' => '2026-10-01 19:00:00',
			'end_datetime'   => '2026-10-01 22:00:00',
		) );

		$this->occurrence_repo->method( 'find' )
			->willReturnCallback( function ( $id ) use ( $occ300 ) {
				return $id === 300 ? $occ300 : null;
			} );
		$this->event_repo->method( 'find' )->willReturn( null );

		$result   = $this->ics_generator->generate_ics_file( array( $ticket_no_occ, $ticket_no_event ) );
		$contents = file_get_contents( $result );

		// No VEVENT blocks since both tickets had missing occurrence or event.
		$this->assertStringContainsString( 'BEGIN:VCALENDAR', $contents );
		$this->assertStringNotContainsString( 'BEGIN:VEVENT', $contents );

		$this->clean_up_ics_temp_dir( $temp_dir );
	}

	// =========================================================================
	// generate_occurrence_ics Tests
	// =========================================================================

	/**
	 * Test generate_occurrence_ics creates a valid ICS file.
	 */
	public function test_generate_occurrence_ics_creates_valid_file(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$temp_dir = $this->set_up_ics_temp_dir();

		$occurrence = $this->create_test_occurrence( array(
			'id'             => 42,
			'start_datetime' => '2026-05-01 19:00:00',
			'end_datetime'   => '2026-05-01 22:00:00',
		) );
		$event      = $this->create_test_event( array(
			'title'         => 'Ceili Dance',
			'venue_name'    => 'Tara Hall',
			'venue_address' => '800 Cedar Ave, Minneapolis',
			'description'   => 'Traditional Irish ceili dancing.',
		) );

		$result = $this->ics_generator->generate_occurrence_ics( $occurrence, $event );

		$this->assertIsString( $result );
		$this->assertFileExists( $result );

		$contents = file_get_contents( $result );
		$this->assertStringContainsString( 'BEGIN:VCALENDAR', $contents );
		$this->assertStringContainsString( 'BEGIN:VEVENT', $contents );
		$this->assertStringContainsString( 'END:VEVENT', $contents );
		$this->assertStringContainsString( 'END:VCALENDAR', $contents );
		$this->assertStringContainsString( 'SUMMARY:Ceili Dance', $contents );
		$this->assertStringContainsString( 'LOCATION:Tara Hall', $contents );
		$this->assertStringContainsString( 'DESCRIPTION:Traditional Irish ceili dancing.', $contents );

		$this->clean_up_ics_temp_dir( $temp_dir );
	}

	/**
	 * Test generate_occurrence_ics contains event summary and location.
	 */
	public function test_generate_occurrence_ics_contains_summary_and_location(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$temp_dir = $this->set_up_ics_temp_dir();

		$occurrence = $this->create_test_occurrence( array(
			'id'             => 55,
			'start_datetime' => '2026-06-15 20:00:00',
			'end_datetime'   => '2026-06-15 23:00:00',
		) );
		$event      = $this->create_test_event( array(
			'title'         => 'Summer Concert',
			'venue_name'    => 'Celtic Junction',
			'venue_address' => '836 Prior Ave N',
		) );

		$result   = $this->ics_generator->generate_occurrence_ics( $occurrence, $event );
		$contents = file_get_contents( $result );

		$this->assertStringContainsString( 'SUMMARY:Summer Concert', $contents );
		$this->assertStringContainsString( 'LOCATION:Celtic Junction\, 836 Prior Ave N', $contents );

		$this->clean_up_ics_temp_dir( $temp_dir );
	}

	/**
	 * Test generate_occurrence_ics handles event without venue or description.
	 */
	public function test_generate_occurrence_ics_without_venue_or_description(): void {
		$temp_dir = $this->set_up_ics_temp_dir();

		$occurrence = $this->create_test_occurrence( array(
			'id'             => 60,
			'start_datetime' => '2026-07-01 18:00:00',
			'end_datetime'   => '2026-07-01 20:00:00',
		) );

		// Set properties directly because the ?? operator in create_test_event
		// treats null as "not set" and falls through to the default.
		$event                = $this->create_test_event( array( 'title' => 'Informal Gathering' ) );
		$event->venue_name    = null;
		$event->venue_address = null;
		$event->description   = '';

		$result   = $this->ics_generator->generate_occurrence_ics( $occurrence, $event );
		$contents = file_get_contents( $result );

		$this->assertStringContainsString( 'SUMMARY:Informal Gathering', $contents );
		$this->assertStringNotContainsString( 'LOCATION:', $contents );
		$this->assertStringNotContainsString( 'DESCRIPTION:', $contents );

		$this->clean_up_ics_temp_dir( $temp_dir );
	}

	/**
	 * Test generate_occurrence_ics returns false on write failure.
	 */
	public function test_generate_occurrence_ics_returns_false_on_write_failure(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		// Point uploads to a non-existent, non-writable path.
		Functions\when( 'wp_upload_dir' )->justReturn( array(
			'basedir' => '/nonexistent/readonly/path',
			'baseurl' => 'http://example.com/wp-content/uploads',
		) );
		Functions\when( 'wp_mkdir_p' )->justReturn( false );

		$occurrence = $this->create_test_occurrence();
		$event      = $this->create_test_event();

		// Suppress PHP warnings from file_put_contents on non-writable path.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test utility.
		set_error_handler( static function () {
			return true;
		}, E_WARNING );

		$result = $this->ics_generator->generate_occurrence_ics( $occurrence, $event );

		restore_error_handler();

		$this->assertFalse( $result );
	}
}
