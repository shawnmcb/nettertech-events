<?php
/**
 * Export Service.
 *
 * Handles CSV and data export for check-in lists.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeCheckInInterface;
use NetterTechEvents\Contracts\ExportServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Core\Hooks;

/**
 * Service for exporting check-in data.
 *
 * Provides CSV generation with injection protection and
 * standardized formatting for check-in exports.
 *
 * Uses AttendeeCheckInInterface (ISP) since it only needs
 * check-in specific methods (get_check_in_list, get_check_in_stats).
 *
 * @since 0.9.0
 * @since 0.9.3 Updated to use segregated AttendeeCheckInInterface.
 * @api
 */
class ExportService implements ExportServiceInterface {

	/**
	 * Attendee check-in operations.
	 *
	 * @var AttendeeCheckInInterface
	 */
	private AttendeeCheckInInterface $attendee_checkin;

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Constructor.
	 *
	 * @param AttendeeCheckInInterface      $attendee_checkin Attendee check-in service.
	 * @param OccurrenceRepositoryInterface $occurrence_repo  Occurrence repository.
	 */
	public function __construct(
		AttendeeCheckInInterface $attendee_checkin,
		OccurrenceRepositoryInterface $occurrence_repo
	) {
		$this->attendee_checkin = $attendee_checkin;
		$this->occurrence_repo  = $occurrence_repo;
	}

	/**
	 * Generate CSV data for check-in list.
	 *
	 * @param int  $occurrence_id     Occurrence ID.
	 * @param bool $include_sensitive Include Art.9 columns (accessibility notes).
	 *                                Only pass true from a capability-checked caller.
	 * @return string CSV content.
	 */
	public function generate_csv( int $occurrence_id, bool $include_sensitive = false ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Using php://temp stream, not filesystem.
		$output = fopen( 'php://temp', 'r+' );
		if ( false === $output ) {
			return '';
		}

		$this->write_csv_rows_to_stream( $output, $occurrence_id, $include_sensitive );

		rewind( $output );
		$csv = (string) stream_get_contents( $output );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://temp stream, not filesystem.
		fclose( $output );

		return $csv;
	}

	/**
	 * Write CSV rows to an open output stream.
	 *
	 * Shared serialization path for in-memory generation (generate_csv) and
	 * direct streaming downloads (send_csv_download). All cell escaping is
	 * handled by fputcsv() per RFC 4180; no HTML-escape is appropriate here
	 * because the body is served with Content-Type: text/csv.
	 *
	 * The accessibility-notes column carries GDPR Art.9 special-category data,
	 * so it is opt-in: callers that are not behind a capability check get a CSV
	 * without it (2026-09-03 audit Q-004).
	 *
	 * @param resource $output            Open writable stream resource.
	 * @param int      $occurrence_id     Occurrence ID.
	 * @param bool     $include_sensitive Include the accessibility-notes column.
	 * @return void
	 */
	private function write_csv_rows_to_stream( $output, int $occurrence_id, bool $include_sensitive ): void {
		$attendees = $this->attendee_checkin->get_check_in_list( $occurrence_id );

		$header = array(
			__( 'Name', 'nettertech-events' ),
			__( 'Email', 'nettertech-events' ),
			__( 'Quantity', 'nettertech-events' ),
			__( 'Checked In', 'nettertech-events' ),
			__( 'Check-In Time', 'nettertech-events' ),
			__( 'Ticket Type', 'nettertech-events' ),
		);

		if ( $include_sensitive ) {
			$header[] = __( 'Accessibility Notes', 'nettertech-events' );
		}

		fputcsv(
			$output,
			$header,
			',',
			'"',
			'\\' // PHP 8.4+ compatibility.
		);

		foreach ( $attendees as $attendee ) {
			$row = array(
				$this->sanitize_csv_value( $attendee['name'] ?? '' ),
				$this->sanitize_csv_value( $attendee['email'] ?? '' ),
				$attendee['quantity'] ?? 0,
				$attendee['checked_in_count'] ?? 0,
				$attendee['checked_in_at'] ?? '',
				$this->sanitize_csv_value( $attendee['ticket_type'] ?? '' ),
			);

			if ( $include_sensitive ) {
				$row[] = $this->sanitize_csv_value( $attendee['accessibility_notes'] ?? '' );
			}

			fputcsv(
				$output,
				$row,
				',',
				'"',
				'\\' // PHP 8.4+ compatibility.
			);
		}
	}

	/**
	 * Sanitize a value for CSV to prevent formula injection.
	 *
	 * Prefixes potentially dangerous values with a single quote
	 * to prevent spreadsheet formula execution.
	 *
	 * @param string $value The value to sanitize.
	 * @return string Sanitized value.
	 */
	public static function sanitize_csv_value( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		// Characters that can trigger formula execution in spreadsheets.
		$dangerous_prefixes = array( '=', '+', '-', '@', "\t", "\r", "\n" );

		foreach ( $dangerous_prefixes as $prefix ) {
			if ( str_starts_with( $value, $prefix ) ) {
				// Prefix with single quote to prevent formula execution.
				return "'" . $value;
			}
		}

		return $value;
	}

	/**
	 * Generate a standardized CSV filename.
	 *
	 * @param string $event_title Event title.
	 * @return string Sanitized filename.
	 */
	public function get_csv_filename( string $event_title ): string {
		return sanitize_file_name( $event_title . '-checkin-' . gmdate( 'Y-m-d' ) . '.csv' );
	}

	/**
	 * Send CSV as a download response.
	 *
	 * Outputs appropriate headers and the CSV content, then exits.
	 *
	 * @param int    $occurrence_id     Occurrence ID.
	 * @param string $event_title       Event title for filename.
	 * @param bool   $include_sensitive Include Art.9 columns (accessibility notes).
	 *                                  Only pass true from a capability-checked caller.
	 * @return void
	 */
	public function send_csv_download( int $occurrence_id, string $event_title, bool $include_sensitive = false ): void {
		$filename = $this->get_csv_filename( $event_title );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing CSV directly to the PHP output stream; not a filesystem operation.
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		$this->write_csv_rows_to_stream( $output, $occurrence_id, $include_sensitive );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing PHP output stream.
		fclose( $output );

		/**
		 * Fires after an attendee CSV export is streamed to the requester.
		 *
		 * @since 1.1.2
		 *
		 * @param array<string, mixed> $parameters Export parameters: occurrence_id, filename.
		 */
		do_action(
			Hooks::ATTENDEES_EXPORTED,
			array(
				'occurrence_id' => $occurrence_id,
				'filename'      => $filename,
			)
		);

		exit;
	}

	/**
	 * Get occurrence with event title for export.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array{occurrence: \NetterTechEvents\Models\Occurrence|null, event_title: string}
	 */
	public function get_export_context( int $occurrence_id ): array {
		$occurrence = $this->occurrence_repo->find( $occurrence_id );

		if ( null === $occurrence ) {
			return array(
				'occurrence'  => null,
				'event_title' => '',
			);
		}

		$event       = $occurrence->get_event();
		$event_title = $event ? $event->title : __( 'Unknown Event', 'nettertech-events' );

		return array(
			'occurrence'  => $occurrence,
			'event_title' => $event_title,
		);
	}

	/**
	 * Get check-in statistics for export header.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<string, int>
	 */
	public function get_export_stats( int $occurrence_id ): array {
		return $this->attendee_checkin->get_check_in_stats( $occurrence_id );
	}
}
