<?php
/**
 * ExportService unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Services;

use NetterTechEvents\Contracts\AttendeeCheckInInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Services\ExportService;
use Brain\Monkey\Functions;

/**
 * Test ExportService functionality.
 */
class ExportServiceTest extends \NetterTechEventsTestCase {

	/**
	 * ExportService instance.
	 *
	 * @var ExportService
	 */
	private ExportService $service;

	/**
	 * Mock AttendeeCheckIn service.
	 *
	 * @var AttendeeCheckInInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $attendee_checkin;

	/**
	 * Mock OccurrenceRepository.
	 *
	 * @var OccurrenceRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $occurrence_repo;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->attendee_checkin = $this->createMock( AttendeeCheckInInterface::class );
		$this->occurrence_repo  = $this->createMock( OccurrenceRepositoryInterface::class );

		$this->service = new ExportService(
			$this->attendee_checkin,
			$this->occurrence_repo
		);
	}

	// =========================================================================
	// Constructor tests
	// =========================================================================

	/**
	 * Test constructor creates service with injected dependencies.
	 *
	 * @return void
	 */
	public function test_constructor_with_injected_dependencies(): void {
		$service = new ExportService(
			$this->attendee_checkin,
			$this->occurrence_repo
		);

		$this->assertInstanceOf( ExportService::class, $service );
	}

	// =========================================================================
	// sanitize_csv_value tests
	// =========================================================================

	/**
	 * Test sanitize_csv_value returns empty string for empty input.
	 *
	 * @return void
	 */
	public function test_sanitize_csv_value_returns_empty_for_empty_input(): void {
		$this->assertSame( '', $this->service->sanitize_csv_value( '' ) );
	}

	/**
	 * Test sanitize_csv_value passes through safe values unchanged.
	 *
	 * @return void
	 */
	public function test_sanitize_csv_value_passes_safe_values_unchanged(): void {
		$this->assertSame( 'John Doe', $this->service->sanitize_csv_value( 'John Doe' ) );
		$this->assertSame( 'test@example.com', $this->service->sanitize_csv_value( 'test@example.com' ) );
		$this->assertSame( 'General Admission', $this->service->sanitize_csv_value( 'General Admission' ) );
	}

	/**
	 * Test sanitize_csv_value prefixes equals sign to prevent formula injection.
	 *
	 * @return void
	 */
	public function test_sanitize_csv_value_prefixes_equals_sign(): void {
		$this->assertSame( "'=SUM(A1:A10)", $this->service->sanitize_csv_value( '=SUM(A1:A10)' ) );
		$this->assertSame( "'=cmd|'/C calc'!A0", $this->service->sanitize_csv_value( "=cmd|'/C calc'!A0" ) );
	}

	/**
	 * Test sanitize_csv_value prefixes plus sign to prevent formula injection.
	 *
	 * @return void
	 */
	public function test_sanitize_csv_value_prefixes_plus_sign(): void {
		$this->assertSame( "'+1234567890", $this->service->sanitize_csv_value( '+1234567890' ) );
	}

	/**
	 * Test sanitize_csv_value prefixes minus sign to prevent formula injection.
	 *
	 * @return void
	 */
	public function test_sanitize_csv_value_prefixes_minus_sign(): void {
		$this->assertSame( "'-1234567890", $this->service->sanitize_csv_value( '-1234567890' ) );
	}

	/**
	 * Test sanitize_csv_value prefixes at sign to prevent formula injection.
	 *
	 * @return void
	 */
	public function test_sanitize_csv_value_prefixes_at_sign(): void {
		$this->assertSame( "'@SUM(A1:A10)", $this->service->sanitize_csv_value( '@SUM(A1:A10)' ) );
	}

	/**
	 * Test sanitize_csv_value prefixes tab character to prevent formula injection.
	 *
	 * @return void
	 */
	public function test_sanitize_csv_value_prefixes_tab(): void {
		$this->assertSame( "'\tmalicious", $this->service->sanitize_csv_value( "\tmalicious" ) );
	}

	/**
	 * Test sanitize_csv_value prefixes carriage return to prevent formula injection.
	 *
	 * @return void
	 */
	public function test_sanitize_csv_value_prefixes_carriage_return(): void {
		$this->assertSame( "'\rmalicious", $this->service->sanitize_csv_value( "\rmalicious" ) );
	}

	/**
	 * Test sanitize_csv_value prefixes newline to prevent formula injection.
	 *
	 * @return void
	 */
	public function test_sanitize_csv_value_prefixes_newline(): void {
		$this->assertSame( "'\nmalicious", $this->service->sanitize_csv_value( "\nmalicious" ) );
	}

	/**
	 * Test sanitize_csv_value allows dangerous characters in middle of string.
	 *
	 * @return void
	 */
	public function test_sanitize_csv_value_allows_dangerous_chars_in_middle(): void {
		$this->assertSame( 'John=Doe', $this->service->sanitize_csv_value( 'John=Doe' ) );
		$this->assertSame( 'email@domain.com', $this->service->sanitize_csv_value( 'email@domain.com' ) );
		$this->assertSame( 'A-Z', $this->service->sanitize_csv_value( 'A-Z' ) );
	}

	// =========================================================================
	// get_csv_filename tests
	// =========================================================================

	/**
	 * Test get_csv_filename generates expected format.
	 *
	 * @return void
	 */
	public function test_get_csv_filename_generates_expected_format(): void {
		Functions\when( 'sanitize_file_name' )->alias(
			function ( $name ) {
				// Simplified sanitize for testing.
				return preg_replace( '/[^a-zA-Z0-9\-_.]/', '-', $name );
			}
		);

		$filename = $this->service->get_csv_filename( 'Test Event' );

		$this->assertStringContainsString( 'Test-Event', $filename );
		$this->assertStringContainsString( '-checkin-', $filename );
		$this->assertStringEndsWith( '.csv', $filename );
		$this->assertStringContainsString( gmdate( 'Y-m-d' ), $filename );
	}

	/**
	 * Test get_csv_filename sanitizes special characters.
	 *
	 * @return void
	 */
	public function test_get_csv_filename_sanitizes_special_characters(): void {
		Functions\when( 'sanitize_file_name' )->alias(
			function ( $name ) {
				return preg_replace( '/[^a-zA-Z0-9\-_.]/', '-', $name );
			}
		);

		$filename = $this->service->get_csv_filename( 'Event: With "Special" <Characters>' );

		$this->assertStringEndsWith( '.csv', $filename );
		// Should not contain the special characters.
		$this->assertStringNotContainsString( ':', $filename );
		$this->assertStringNotContainsString( '"', $filename );
		$this->assertStringNotContainsString( '<', $filename );
		$this->assertStringNotContainsString( '>', $filename );
	}

	// =========================================================================
	// generate_csv tests
	// =========================================================================

	/**
	 * Test generate_csv returns CSV with header row.
	 *
	 * @return void
	 */
	public function test_generate_csv_returns_csv_with_header(): void {
		$this->attendee_checkin
			->expects( $this->once() )
			->method( 'get_check_in_list' )
			->with( 123 )
			->willReturn( array() );

		$csv = $this->service->generate_csv( 123 );

		$this->assertStringContainsString( 'Name', $csv );
		$this->assertStringContainsString( 'Email', $csv );
		$this->assertStringContainsString( 'Quantity', $csv );
		$this->assertStringContainsString( 'Checked In', $csv );
		$this->assertStringContainsString( 'Check-In Time', $csv );
		$this->assertStringContainsString( 'Ticket Type', $csv );
		$this->assertStringContainsString( 'Accessibility Notes', $csv );
	}

	/**
	 * Test generate_csv includes attendee data.
	 *
	 * @return void
	 */
	public function test_generate_csv_includes_attendee_data(): void {
		$attendees = array(
			array(
				'name'               => 'John Doe',
				'email'              => 'john@example.com',
				'quantity'           => 2,
				'checked_in_count'   => 1,
				'checked_in_at'      => '2026-01-12 10:00:00',
				'ticket_type'        => 'General Admission',
				'accessibility_notes' => 'Wheelchair access',
			),
		);

		$this->attendee_checkin
			->expects( $this->once() )
			->method( 'get_check_in_list' )
			->with( 456 )
			->willReturn( $attendees );

		$csv = $this->service->generate_csv( 456 );

		$this->assertStringContainsString( 'John Doe', $csv );
		$this->assertStringContainsString( 'john@example.com', $csv );
		$this->assertStringContainsString( 'General Admission', $csv );
		$this->assertStringContainsString( 'Wheelchair access', $csv );
	}

	/**
	 * Test generate_csv sanitizes values with formula injection prevention.
	 *
	 * @return void
	 */
	public function test_generate_csv_sanitizes_dangerous_values(): void {
		$attendees = array(
			array(
				'name'               => '=HYPERLINK("http://evil.com","Click me")',
				'email'              => 'safe@example.com',
				'quantity'           => 1,
				'checked_in_count'   => 0,
				'checked_in_at'      => '',
				'ticket_type'        => 'VIP',
				'accessibility_notes' => '',
			),
		);

		$this->attendee_checkin
			->expects( $this->once() )
			->method( 'get_check_in_list' )
			->willReturn( $attendees );

		$csv = $this->service->generate_csv( 789 );

		// The dangerous formula should be prefixed with single quote.
		$this->assertStringContainsString( "'=HYPERLINK", $csv );
	}

	/**
	 * Test generate_csv handles empty attendee list.
	 *
	 * @return void
	 */
	public function test_generate_csv_handles_empty_attendee_list(): void {
		$this->attendee_checkin
			->expects( $this->once() )
			->method( 'get_check_in_list' )
			->willReturn( array() );

		$csv = $this->service->generate_csv( 999 );

		// Should only have header row.
		$lines = explode( "\n", trim( $csv ) );
		$this->assertCount( 1, $lines );
	}

	/**
	 * Test generate_csv handles missing fields gracefully.
	 *
	 * @return void
	 */
	public function test_generate_csv_handles_missing_fields(): void {
		$attendees = array(
			array(
				'name'  => 'Partial Data',
				'email' => 'partial@example.com',
				// Missing other fields.
			),
		);

		$this->attendee_checkin
			->expects( $this->once() )
			->method( 'get_check_in_list' )
			->willReturn( $attendees );

		$csv = $this->service->generate_csv( 111 );

		// Should not throw - should have default values.
		$this->assertStringContainsString( 'Partial Data', $csv );
		$this->assertStringContainsString( 'partial@example.com', $csv );
	}

	// =========================================================================
	// get_export_context tests
	// =========================================================================

	/**
	 * Test get_export_context returns null occurrence when not found.
	 *
	 * @return void
	 */
	public function test_get_export_context_returns_null_when_not_found(): void {
		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'find' )
			->with( 999 )
			->willReturn( null );

		$context = $this->service->get_export_context( 999 );

		$this->assertNull( $context['occurrence'] );
		$this->assertSame( '', $context['event_title'] );
	}

	/**
	 * Test get_export_context returns occurrence and event title.
	 *
	 * @return void
	 */
	public function test_get_export_context_returns_occurrence_and_event_title(): void {
		$event        = new Event();
		$event->title = 'Test Event Title';

		$occurrence = $this->createMock( Occurrence::class );
		$occurrence->method( 'get_event' )->willReturn( $event );

		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'find' )
			->with( 123 )
			->willReturn( $occurrence );

		$context = $this->service->get_export_context( 123 );

		$this->assertSame( $occurrence, $context['occurrence'] );
		$this->assertSame( 'Test Event Title', $context['event_title'] );
	}

	/**
	 * Test get_export_context handles occurrence without event.
	 *
	 * @return void
	 */
	public function test_get_export_context_handles_occurrence_without_event(): void {
		$occurrence = $this->createMock( Occurrence::class );
		$occurrence->method( 'get_event' )->willReturn( null );

		$this->occurrence_repo
			->expects( $this->once() )
			->method( 'find' )
			->with( 456 )
			->willReturn( $occurrence );

		$context = $this->service->get_export_context( 456 );

		$this->assertSame( $occurrence, $context['occurrence'] );
		$this->assertSame( 'Unknown Event', $context['event_title'] );
	}

	// =========================================================================
	// get_export_stats tests
	// =========================================================================

	/**
	 * Test get_export_stats delegates to repository.
	 *
	 * @return void
	 */
	public function test_get_export_stats_delegates_to_repository(): void {
		$expected_stats = array(
			'total_registrations' => 50,
			'total_guests'        => 75,
			'fully_checked_in'    => 30,
			'guests_checked_in'   => 45,
		);

		$this->attendee_checkin
			->expects( $this->once() )
			->method( 'get_check_in_stats' )
			->with( 123 )
			->willReturn( $expected_stats );

		$stats = $this->service->get_export_stats( 123 );

		$this->assertSame( $expected_stats, $stats );
	}
}
