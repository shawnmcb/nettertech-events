<?php
/**
 * Schema.org Event structured data.
 *
 * @package NetterTechEvents\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;

/**
 * Outputs JSON-LD Schema.org Event markup on single event pages.
 *
 * @since 1.0.0
 */
class SchemaMarkup {

	/**
	 * Initialize Schema.org markup output.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_head', array( static::class, 'output' ), 5 );
	}

	/**
	 * Output JSON-LD markup if on a single event page.
	 *
	 * @return void
	 */
	public static function output(): void {
		$event = Router::get_current_event();
		if ( ! $event || ! $event->is_published() ) {
			return;
		}

		$occurrence = Router::get_current_occurrence();
		$data       = self::build_event_data( $event, $occurrence );

		if ( empty( $data ) ) {
			return;
		}

		/**
		 * Filter the Schema.org Event data before output.
		 *
		 * @since 1.0.2
		 *
		 * @param array<string, mixed>  $data       Schema.org structured data.
		 * @param Event                 $event      Event model.
		 * @param Occurrence|null       $occurrence Current occurrence (if viewing specific one).
		 */
		$data = apply_filters( 'nettertech_events_schema_org_data', $data, $event, $occurrence );

		if ( empty( $data ) ) {
			return;
		}

		if ( false === wp_json_encode( $data ) ) {
			return;
		}

		// JSON-LD: wp_json_encode escapes the payload; surrounding <script> tag is a literal.
		// The encode is called again at the echo site so PHPCS recognizes the escape provenance.
		echo '<script type="application/ld+json">' . wp_json_encode( $data ) . '</script>' . "\n";
	}

	/**
	 * Build Schema.org Event structured data.
	 *
	 * @param Event           $event      Event model.
	 * @param Occurrence|null $occurrence Specific occurrence (optional).
	 * @return array<string, mixed> Schema.org Event data.
	 */
	public static function build_event_data( Event $event, ?Occurrence $occurrence = null ): array {
		$data = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'name'                => $event->title,
			'eventAttendanceMode' => $event->get_attendance_mode(),
		);

		// Description.
		$description = $event->excerpt ? $event->excerpt : $event->description;
		if ( $description ) {
			$data['description'] = wp_strip_all_tags( $description );
		}

		// Dates from occurrence or event context.
		if ( $occurrence ) {
			self::add_occurrence_dates( $data, $occurrence );
			self::add_event_status( $data, $occurrence );
		}

		// URL.
		if ( $occurrence ) {
			$url = $occurrence->get_url();
		} else {
			$url = $event->get_permalink();
		}
		if ( $url ) {
			$data['url'] = $url;
		}

		// Image.
		$image_url = null;
		if ( $occurrence ) {
			$image_url = $occurrence->get_featured_image_url( 'large' );
		}
		if ( ! $image_url ) {
			$image_url = $event->get_featured_image_url( 'large' );
		}
		if ( $image_url ) {
			$data['image'] = $image_url;
		}

		// Location.
		self::add_location( $data, $event );

		// Organizer (site name as fallback).
		$data['organizer'] = array(
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url(),
		);

		return $data;
	}

	/**
	 * Add start/end dates from an occurrence.
	 *
	 * @param array<string, mixed> $data       Schema data (passed by reference).
	 * @param Occurrence           $occurrence Occurrence model.
	 * @return void
	 */
	private static function add_occurrence_dates( array &$data, Occurrence $occurrence ): void {
		if ( $occurrence->start_datetime ) {
			$data['startDate'] = self::format_datetime( $occurrence->start_datetime, $occurrence->timezone );
		}

		if ( $occurrence->end_datetime ) {
			$data['endDate'] = self::format_datetime( $occurrence->end_datetime, $occurrence->timezone );
		}
	}

	/**
	 * Add event status based on occurrence state.
	 *
	 * @param array<string, mixed> $data       Schema data (passed by reference).
	 * @param Occurrence           $occurrence Occurrence model.
	 * @return void
	 */
	private static function add_event_status( array &$data, Occurrence $occurrence ): void {
		if ( $occurrence->is_cancelled() ) {
			$data['eventStatus'] = 'https://schema.org/EventCancelled';
		} elseif ( $occurrence->is_rescheduled ) {
			$data['eventStatus'] = 'https://schema.org/EventRescheduled';
		} else {
			$data['eventStatus'] = 'https://schema.org/EventScheduled';
		}
	}

	/**
	 * Add location data from the event.
	 *
	 * Handles three modes: in-person (Place), virtual-only (VirtualLocation),
	 * and hybrid (array of both Place and VirtualLocation).
	 *
	 * @param array<string, mixed> $data  Schema data (passed by reference).
	 * @param Event                $event Event model.
	 * @return void
	 */
	private static function add_location( array &$data, Event $event ): void {
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

		// Hybrid: array of both locations.
		if ( $physical_location && $virtual_location ) {
			$data['location'] = array( $physical_location, $virtual_location );
		} elseif ( $virtual_location ) {
			$data['location'] = $virtual_location;
		} elseif ( $physical_location ) {
			$data['location'] = $physical_location;
		}
	}

	/**
	 * Format a datetime string as ISO 8601 with timezone.
	 *
	 * @param string      $datetime MySQL datetime string (Y-m-d H:i:s).
	 * @param string|null $timezone Timezone identifier.
	 * @return string ISO 8601 formatted datetime.
	 */
	private static function format_datetime( string $datetime, ?string $timezone = null ): string {
		try {
			$tz = $timezone ? new \DateTimeZone( $timezone ) : wp_timezone();
			$dt = new \DateTime( $datetime, $tz );
			return $dt->format( 'c' ); // ISO 8601.
		} catch ( \Exception $e ) {
			// Fall back to raw datetime if timezone is invalid.
			return str_replace( ' ', 'T', $datetime );
		}
	}
}
