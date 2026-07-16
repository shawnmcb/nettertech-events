<?php
/**
 * Calendar Link Service
 *
 * Generates "Add to Calendar" URLs for external calendar services.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CalendarLinkServiceInterface;
use NetterTechEvents\Models\Occurrence;

/**
 * Builds calendar URLs for Google Calendar, Outlook Live, Outlook 365, and iCal.
 *
 * All methods are pure URL builders with no side effects or external dependencies.
 *
 * @since 1.0.0
 */
class CalendarLinkService implements CalendarLinkServiceInterface {

	/**
	 * Generate a Google Calendar event creation URL.
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @return string Google Calendar URL.
	 */
	public function google_url( Occurrence $occurrence ): string {
		$event  = $occurrence->get_event();
		$params = array(
			'action'   => 'TEMPLATE',
			'text'     => $occurrence->get_title(),
			'dates'    => $this->google_dates( $occurrence ),
			'details'  => $this->plain_description( $occurrence ),
			'location' => $event ? $this->build_location( $event->venue_name, $event->venue_address ) : '',
		);

		return 'https://calendar.google.com/calendar/render?' . $this->build_query( $params );
	}

	/**
	 * Generate an Outlook Live calendar event creation URL.
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @return string Outlook Live URL.
	 */
	public function outlook_live_url( Occurrence $occurrence ): string {
		return $this->outlook_url( 'https://outlook.live.com', $occurrence );
	}

	/**
	 * Generate an Outlook 365 calendar event creation URL.
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @return string Outlook 365 URL.
	 */
	public function outlook_365_url( Occurrence $occurrence ): string {
		return $this->outlook_url( 'https://outlook.office.com', $occurrence );
	}

	/**
	 * Generate the iCal download REST URL.
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @return string iCal REST endpoint URL.
	 */
	public function ical_url( Occurrence $occurrence ): string {
		return rest_url( 'nettertech-events/v1/ical/occurrence/' . $occurrence->id );
	}

	/**
	 * Get all calendar links for an occurrence.
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @return array<string, array{url: string, label: string}> Keyed by provider slug.
	 */
	public function all_links( Occurrence $occurrence ): array {
		return array(
			'google'       => array(
				'url'   => $this->google_url( $occurrence ),
				'label' => __( 'Google Calendar', 'nettertech-events' ),
			),
			'outlook_365'  => array(
				'url'   => $this->outlook_365_url( $occurrence ),
				'label' => __( 'Outlook 365', 'nettertech-events' ),
			),
			'outlook_live' => array(
				'url'   => $this->outlook_live_url( $occurrence ),
				'label' => __( 'Outlook.com', 'nettertech-events' ),
			),
			'ical'         => array(
				'url'   => $this->ical_url( $occurrence ),
				'label' => __( 'iCal File', 'nettertech-events' ),
			),
		);
	}

	/**
	 * Build an Outlook calendar URL (shared logic for Live and 365).
	 *
	 * @param string     $base_url   The Outlook service base URL.
	 * @param Occurrence $occurrence The occurrence.
	 * @return string Outlook calendar URL.
	 */
	private function outlook_url( string $base_url, Occurrence $occurrence ): string {
		$event = $occurrence->get_event();

		if ( $occurrence->all_day ) {
			$params = array(
				'subject'  => $occurrence->get_title(),
				'startdt'  => $occurrence->get_start()->format( 'Y-m-d' ),
				'enddt'    => $occurrence->get_end()->modify( '+1 day' )->format( 'Y-m-d' ),
				'allday'   => 'true',
				'body'     => $this->plain_description( $occurrence ),
				'location' => $event ? $this->build_location( $event->venue_name, $event->venue_address ) : '',
			);
		} else {
			$start_utc = $occurrence->get_start()->setTimezone( new \DateTimeZone( 'UTC' ) );
			$end_utc   = $occurrence->get_end()->setTimezone( new \DateTimeZone( 'UTC' ) );

			$params = array(
				'subject'  => $occurrence->get_title(),
				'startdt'  => $start_utc->format( 'Y-m-d\TH:i:s\Z' ),
				'enddt'    => $end_utc->format( 'Y-m-d\TH:i:s\Z' ),
				'body'     => $this->plain_description( $occurrence ),
				'location' => $event ? $this->build_location( $event->venue_name, $event->venue_address ) : '',
			);
		}

		return $base_url . '/calendar/0/deeplink/compose?' . $this->build_query( $params );
	}

	/**
	 * Format dates for Google Calendar's expected format.
	 *
	 * Google uses: YYYYMMDD/YYYYMMDD for all-day, YYYYMMDDTHHMMSSZ/YYYYMMDDTHHMMSSZ for timed.
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @return string Google-formatted date range.
	 */
	private function google_dates( Occurrence $occurrence ): string {
		if ( $occurrence->all_day ) {
			$start = $occurrence->get_start()->format( 'Ymd' );
			$end   = $occurrence->get_end()->modify( '+1 day' )->format( 'Ymd' );
			return $start . '/' . $end;
		}

		$start_utc = $occurrence->get_start()->setTimezone( new \DateTimeZone( 'UTC' ) );
		$end_utc   = $occurrence->get_end()->setTimezone( new \DateTimeZone( 'UTC' ) );

		return $start_utc->format( 'Ymd\THis\Z' ) . '/' . $end_utc->format( 'Ymd\THis\Z' );
	}

	/**
	 * Get plain-text description (stripped of HTML).
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @return string Plain text description, truncated to 1000 chars for URL safety.
	 */
	private function plain_description( Occurrence $occurrence ): string {
		$description = wp_strip_all_tags( $occurrence->get_description() );
		if ( strlen( $description ) > 1000 ) {
			$description = substr( $description, 0, 997 ) . '...';
		}
		return $description;
	}

	/**
	 * Build a location string from venue name and address.
	 *
	 * @param string|null $name    Venue name.
	 * @param string|null $address Venue address.
	 * @return string Combined location string.
	 */
	private function build_location( ?string $name, ?string $address ): string {
		$parts = array_filter( array( $name, $address ) );
		return implode( ', ', $parts );
	}

	/**
	 * Build a query string, omitting empty values.
	 *
	 * @param array<string, string> $params Query parameters.
	 * @return string Encoded query string.
	 */
	private function build_query( array $params ): string {
		return http_build_query( array_filter( $params, fn( string $value ): bool => '' !== $value ), '', '&', PHP_QUERY_RFC3986 );
	}
}
