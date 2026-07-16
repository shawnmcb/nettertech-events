<?php
/**
 * SchemaBuilder trait — shared Schema.org Event construction logic.
 *
 * Provides the schema helper methods common to both RankMathIntegration and
 * YoastIntegration. The two integrations share identical implementations of
 * add_occurrence_dates(), add_event_status(), add_location(), and
 * format_datetime(). Extracting them here prevents drift between the two.
 *
 * @package NetterTechEvents\Integrations
 * @since   2.1.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;

/**
 * Shared Schema.org Event helper methods for SEO integrations.
 */
trait SchemaBuilder {

	/**
	 * Add start/end dates from an occurrence to the schema data array.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $data       Schema data (passed by reference).
	 * @param Occurrence           $occurrence Occurrence model.
	 * @return void
	 */
	private function add_occurrence_dates( array &$data, Occurrence $occurrence ): void {
		if ( $occurrence->start_datetime ) {
			$data['startDate'] = $this->format_datetime( $occurrence->start_datetime, $occurrence->timezone );
		}

		if ( $occurrence->end_datetime ) {
			$data['endDate'] = $this->format_datetime( $occurrence->end_datetime, $occurrence->timezone );
		}
	}

	/**
	 * Add event status based on occurrence state.
	 *
	 * Maps cancelled/rescheduled/active occurrence states to Schema.org
	 * EventStatusType values.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $data       Schema data (passed by reference).
	 * @param Occurrence           $occurrence Occurrence model.
	 * @return void
	 */
	private function add_event_status( array &$data, Occurrence $occurrence ): void {
		if ( $occurrence->is_cancelled() ) {
			$data['eventStatus'] = 'https://schema.org/EventCancelled';
		} elseif ( $occurrence->is_rescheduled ) {
			$data['eventStatus'] = 'https://schema.org/EventRescheduled';
		} else {
			$data['eventStatus'] = 'https://schema.org/EventScheduled';
		}
	}

	/**
	 * Add location data from the event to the schema data array.
	 *
	 * Handles Place, VirtualLocation, and hybrid (array of both) cases.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $data  Schema data (passed by reference).
	 * @param Event                $event Event model.
	 * @return void
	 */
	private function add_location( array &$data, Event $event ): void {
		$has_physical = $event->venue_name || $event->venue_address;
		$has_virtual  = $event->is_virtual_event() && ! empty( $event->virtual_url );

		if ( ! $has_physical && ! $has_virtual ) {
			return;
		}

		$physical_location = null;
		if ( $has_physical ) {
			$physical_location = array(
				'@type' => 'Place',
			);

			if ( $event->venue_name ) {
				$physical_location['name'] = $event->venue_name;
			}

			if ( $event->venue_address ) {
				$physical_location['address'] = array(
					'@type'         => 'PostalAddress',
					'streetAddress' => $event->venue_address,
				);
			}
		}

		$virtual_location = null;
		if ( $has_virtual ) {
			$virtual_location = array(
				'@type' => 'VirtualLocation',
				'url'   => $event->virtual_url,
			);
		}

		if ( $physical_location && $virtual_location ) {
			$data['location'] = array( $physical_location, $virtual_location );
		} elseif ( $virtual_location ) {
			$data['location'] = $virtual_location;
		} elseif ( $physical_location ) {
			$data['location'] = $physical_location;
		}
	}

	/**
	 * Format a MySQL datetime string as ISO 8601 with timezone offset.
	 *
	 * Falls back to a basic T-separator substitution on invalid input to
	 * avoid returning an empty string for schema consumers.
	 *
	 * @since 2.1.0
	 *
	 * @param string      $datetime MySQL datetime string (Y-m-d H:i:s).
	 * @param string|null $timezone Timezone identifier (e.g. 'America/Chicago').
	 * @return string ISO 8601 formatted datetime string.
	 */
	private function format_datetime( string $datetime, ?string $timezone = null ): string {
		try {
			$tz = $timezone ? new \DateTimeZone( $timezone ) : wp_timezone();
			$dt = new \DateTime( $datetime, $tz );
			return $dt->format( 'c' );
		} catch ( \Exception $e ) {
			return str_replace( ' ', 'T', $datetime );
		}
	}
}
