<?php
/**
 * ICS calendar-file generator.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\IcsGeneratorInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;

/**
 * Builds `.ics` calendar files for email attachments.
 *
 * Extracted from EmailTemplateRenderer (NTE-107). Distinct from ICalService,
 * which produces the public subscribable feed (master VEVENT + RRULE/EXDATE);
 * this generator writes simple single-PUBLISH temp files for email attachment.
 *
 * @since 1.0.3
 */
class IcsGenerator implements IcsGeneratorInterface {

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Constructor.
	 *
	 * @param OccurrenceRepositoryInterface $occurrence_repo Occurrence repository.
	 * @param EventRepositoryInterface      $event_repo      Event repository.
	 */
	public function __construct(
		OccurrenceRepositoryInterface $occurrence_repo,
		EventRepositoryInterface $event_repo
	) {
		$this->occurrence_repo = $occurrence_repo;
		$this->event_repo      = $event_repo;
	}

	/**
	 * Generate an ICS calendar file for a set of tickets (one VEVENT per occurrence).
	 *
	 * @param \NetterTechEvents\Models\Ticket[] $tickets Tickets.
	 * @return string|false Path to a temp ICS file, or false on failure.
	 */
	public function generate_ics_file( array $tickets ): string|false {
		if ( empty( $tickets ) ) {
			return false;
		}

		$ics_content  = "BEGIN:VCALENDAR\r\n";
		$ics_content .= "VERSION:2.0\r\n";
		$ics_content .= "PRODID:-//NetterTech Events//Tickets//EN\r\n";
		$ics_content .= "CALSCALE:GREGORIAN\r\n";
		$ics_content .= "METHOD:PUBLISH\r\n";

		// Track unique occurrences (one event per occurrence).
		$processed = array();

		foreach ( $tickets as $ticket ) {
			$occ_id = $ticket->occurrence_id;

			if ( isset( $processed[ $occ_id ] ) ) {
				continue;
			}
			$processed[ $occ_id ] = true;

			$occurrence = $this->occurrence_repo->find( $occ_id );
			if ( ! $occurrence ) {
				continue;
			}

			$event = $this->event_repo->find( $occurrence->event_id );
			if ( ! $event ) {
				continue;
			}

			// True instants from the occurrence's authoring zone (DST-aware), so the
			// UTC DTSTART/DTEND below are correct; strtotime() read the wall-clock as
			// server-UTC and exported times off by the site's offset.
			$start = $occurrence->get_start()->getTimestamp();
			$end   = $occurrence->get_end()->getTimestamp();

			$ics_content .= "BEGIN:VEVENT\r\n";
			$ics_content .= sprintf( "UID:%s-%d@%s\r\n", $ticket->ticket_code, $occ_id, wp_parse_url( home_url(), PHP_URL_HOST ) );
			$ics_content .= sprintf( "DTSTAMP:%s\r\n", gmdate( 'Ymd\THis\Z' ) );
			$ics_content .= sprintf( "DTSTART:%s\r\n", gmdate( 'Ymd\THis\Z', $start ) );
			$ics_content .= sprintf( "DTEND:%s\r\n", gmdate( 'Ymd\THis\Z', $end ) );
			$ics_content .= sprintf( "SUMMARY:%s\r\n", $this->escape_ics_text( $event->title ) );

			if ( $event->venue_name ) {
				$location = $event->venue_name;
				if ( $event->venue_address ) {
					$location .= ', ' . $event->venue_address;
				}
				$ics_content .= sprintf( "LOCATION:%s\r\n", $this->escape_ics_text( $location ) );
			}

			if ( $event->description ) {
				$ics_content .= sprintf( "DESCRIPTION:%s\r\n", $this->escape_ics_text( wp_strip_all_tags( $event->description ) ) );
			}

			$ics_content .= "END:VEVENT\r\n";
		}

		$ics_content .= "END:VCALENDAR\r\n";

		// Write to temp file.
		$upload_dir = wp_upload_dir();
		$temp_file  = $upload_dir['basedir'] . '/nettertech-events/temp/calendar-' . wp_generate_uuid4() . '.ics';

		// Ensure temp directory exists.
		$temp_dir = dirname( $temp_file );
		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem requires prior instantiation not available in this parsing context.
		if ( file_put_contents( $temp_file, $ics_content ) === false ) {
			return false;
		}

		return $temp_file;
	}

	/**
	 * Generate an ICS calendar file for a single occurrence (reminder emails).
	 *
	 * Used by reminder emails where no tickets may exist (e.g. RSVP attendees).
	 *
	 * @param Occurrence $occurrence Occurrence.
	 * @param Event      $event      Event.
	 * @return string|false Path to a temp ICS file, or false on failure.
	 */
	public function generate_occurrence_ics( Occurrence $occurrence, Event $event ): string|false {
		$ics_content  = "BEGIN:VCALENDAR\r\n";
		$ics_content .= "VERSION:2.0\r\n";
		$ics_content .= "PRODID:-//NetterTech Events//Reminder//EN\r\n";
		$ics_content .= "CALSCALE:GREGORIAN\r\n";
		$ics_content .= "METHOD:PUBLISH\r\n";

		// True instants from the occurrence's authoring zone (DST-aware) so the UTC
		// DTSTART/DTEND below are correct (was strtotime → wall-clock read as UTC).
		$start = $occurrence->get_start()->getTimestamp();
		$end   = $occurrence->get_end()->getTimestamp();

		$ics_content .= "BEGIN:VEVENT\r\n";
		$ics_content .= sprintf( "UID:reminder-%d@%s\r\n", $occurrence->id, wp_parse_url( home_url(), PHP_URL_HOST ) );
		$ics_content .= sprintf( "DTSTAMP:%s\r\n", gmdate( 'Ymd\THis\Z' ) );
		$ics_content .= sprintf( "DTSTART:%s\r\n", gmdate( 'Ymd\THis\Z', $start ) );
		$ics_content .= sprintf( "DTEND:%s\r\n", gmdate( 'Ymd\THis\Z', $end ) );
		$ics_content .= sprintf( "SUMMARY:%s\r\n", $this->escape_ics_text( $event->title ) );

		if ( $event->venue_name ) {
			$location = $event->venue_name;
			if ( $event->venue_address ) {
				$location .= ', ' . $event->venue_address;
			}
			$ics_content .= sprintf( "LOCATION:%s\r\n", $this->escape_ics_text( $location ) );
		}

		if ( $event->description ) {
			$ics_content .= sprintf( "DESCRIPTION:%s\r\n", $this->escape_ics_text( wp_strip_all_tags( $event->description ) ) );
		}

		$ics_content .= "END:VEVENT\r\n";
		$ics_content .= "END:VCALENDAR\r\n";

		$upload_dir = wp_upload_dir();
		$temp_file  = $upload_dir['basedir'] . '/nettertech-events/temp/reminder-' . wp_generate_uuid4() . '.ics';

		$temp_dir = dirname( $temp_file );
		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem requires prior instantiation not available in this parsing context.
		if ( file_put_contents( $temp_file, $ics_content ) === false ) {
			return false;
		}

		return $temp_file;
	}

	/**
	 * Escape text for the ICS text format.
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	public function escape_ics_text( string $text ): string {
		$text = str_replace( array( '\\', ',', ';', "\n" ), array( '\\\\', '\,', '\;', '\\n' ), $text );
		return $text;
	}
}
