<?php
/**
 * ICalendar Service.
 *
 * Handles iCal (RFC 5545) import and export for events.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Core\NetterTechEventsSettings;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\RecurrenceRule;
use NetterTechEvents\Services\OccurrenceGenerator;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\VEventParser;
use NetterTechEvents\Utilities\DebugLogger;
use NetterTechEvents\Utilities\PathHelper;

/**
 * Service for iCal import and export operations.
 *
 * Supports RFC 5545 compliant iCalendar format for:
 * - Exporting events as .ics files or calendar feeds
 * - Importing events from .ics files
 *
 * @since 0.9.0
 */
class ICalService {

	/**
	 * Product identifier for iCal files.
	 *
	 * @var string
	 */
	private const PRODID = '-//NetterTech Events//Events Plugin//EN';

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * VEVENT parser for import operations.
	 *
	 * @var VEventParser
	 */
	private VEventParser $vevent_parser;

	/**
	 * Category repository.
	 *
	 * @var CategoryRepositoryInterface
	 */
	private CategoryRepositoryInterface $category_repo;

	/**
	 * Recurrence service for RRULE parsing/serialization.
	 *
	 * @var RecurrenceService
	 */
	private RecurrenceService $recurrence_service;

	/**
	 * Occurrence generator for original-slot recovery (RECURRENCE-ID / EXDATE).
	 *
	 * @var OccurrenceGenerator
	 */
	private OccurrenceGenerator $occurrence_generator;

	/**
	 * Memoized iCal feed horizon (days) for the current request.
	 *
	 * Null until first resolved from settings. See feed_horizon_days().
	 *
	 * @var int|null
	 */
	private ?int $feed_horizon_days = null;

	/**
	 * Constructor.
	 *
	 * @param EventRepositoryInterface      $event_repo           Event repository instance.
	 * @param OccurrenceRepositoryInterface $occurrence_repo      Occurrence repository instance.
	 * @param VEventParser                  $vevent_parser        VEVENT parser instance.
	 * @param CategoryRepositoryInterface   $category_repo        Category repository instance.
	 * @param RecurrenceService             $recurrence_service   Recurrence service for RRULE handling.
	 * @param OccurrenceGenerator           $occurrence_generator Generator for original-slot recovery.
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		VEventParser $vevent_parser,
		CategoryRepositoryInterface $category_repo,
		RecurrenceService $recurrence_service,
		OccurrenceGenerator $occurrence_generator
	) {
		$this->event_repo           = $event_repo;
		$this->occurrence_repo      = $occurrence_repo;
		$this->vevent_parser        = $vevent_parser;
		$this->category_repo        = $category_repo;
		$this->recurrence_service   = $recurrence_service;
		$this->occurrence_generator = $occurrence_generator;
	}

	/**
	 * Export a single occurrence as iCal.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return string|null iCal content or null if not found.
	 */
	public function export_occurrence( int $occurrence_id ): ?string {
		$occurrence = $this->occurrence_repo->find( $occurrence_id );
		if ( ! $occurrence ) {
			return null;
		}

		$event = $this->event_repo->find( $occurrence->event_id );
		if ( ! $event ) {
			return null;
		}

		return $this->generate_ical( array( $this->create_vevent( $event, $occurrence ) ) );
	}

	/**
	 * Export all occurrences for an event as iCal.
	 *
	 * @param int $event_id Event ID.
	 * @return string|null iCal content or null if not found.
	 */
	public function export_event( int $event_id ): ?string {
		$event = $this->event_repo->find( $event_id );
		if ( ! $event ) {
			return null;
		}

		$occurrences = $this->occurrence_repo->for_event( $event_id );
		if ( empty( $occurrences ) ) {
			return null;
		}

		return $this->generate_ical( $this->build_event_vevents( $event, $occurrences ) );
	}

	/**
	 * Build the VEVENT block(s) for one event from its occurrences.
	 *
	 * Recurring events with a parseable rule collapse to ONE master VEVENT
	 * (RRULE + EXDATE for cancellations). Single events — and recurring
	 * events whose rule fails to parse (safety fallback) — emit one plain
	 * VEVENT per occurrence, preserving the legacy expanded behavior.
	 *
	 * @param Event             $event       Event model.
	 * @param array<Occurrence> $occurrences All occurrences for the event (any status).
	 * @return array<int, string> Array of VEVENT content strings.
	 */
	private function build_event_vevents( Event $event, array $occurrences ): array {
		if ( $event->is_recurring() && null !== $event->recurrence_rule ) {
			$rule = $this->recurrence_service->parse_rule( $event->recurrence_rule );
			if ( null !== $rule ) {
				return $this->build_recurring_vevents( $event, $occurrences, $rule );
			}
		}

		$vevents = array();
		foreach ( $occurrences as $occurrence ) {
			$vevents[] = $this->create_vevent( $event, $occurrence );
		}

		return $vevents;
	}

	/**
	 * Build the master VEVENT plus any RECURRENCE-ID override VEVENTs.
	 *
	 * The master collapses the series (RRULE + EXDATE). Each non-cancelled
	 * override occurrence becomes a separate VEVENT sharing the master UID,
	 * keyed to its ORIGINAL recurrence slot via RECURRENCE-ID. See contract
	 * §2 (master), §3 (EXDATE), §4 (RECURRENCE-ID), §6 (original-slot recovery).
	 *
	 * @param Event             $event       Event model.
	 * @param array<Occurrence> $occurrences All occurrences for the event (any status).
	 * @param RecurrenceRule    $rule        Parsed recurrence rule.
	 * @return array<int, string> Master VEVENT followed by override VEVENTs.
	 */
	private function build_recurring_vevents( Event $event, array $occurrences, RecurrenceRule $rule ): array {
		// Series anchor = earliest occurrence start (Event has no start datetime
		// field; the series start lives on its first occurrence). Used to seed the
		// theoretical slot set for original-slot recovery.
		$earliest = $this->earliest_occurrence( $occurrences );
		$anchor   = new \DateTimeImmutable( $earliest->start_datetime );

		$vevents = array( $this->create_master_vevent( $event, $occurrences, $rule, $anchor ) );

		foreach ( $occurrences as $occurrence ) {
			if ( true === $occurrence->is_override && ! $occurrence->is_cancelled() ) {
				$vevents[] = $this->create_recurrence_override_vevent( $event, $occurrence, $rule, $anchor );
			}
		}

		return $vevents;
	}

	/**
	 * Export multiple events as a calendar feed.
	 *
	 * @param array<string, mixed> $args Query arguments for events.
	 * @return string iCal content.
	 */
	public function export_calendar_feed( array $args = array() ): string {
		$defaults = array(
			'status'     => 'published',
			'limit'      => 100,
			'start_from' => gmdate( 'Y-m-d' ),
		);

		$args   = wp_parse_args( $args, $defaults );
		$events = $this->event_repo->all( $args );

		$vevents = array();
		foreach ( $events as $event ) {
			$event_id = $event->id;
			if ( null === $event_id ) {
				continue;
			}

			if ( $event->is_recurring() && null !== $event->recurrence_rule ) {
				// Master VEVENT needs ALL occurrences (incl. cancelled) for EXDATE.
				$occurrences = $this->occurrence_repo->for_event( $event_id );
			} else {
				// Single events: only upcoming scheduled occurrences belong in the feed.
				$occurrences = $this->occurrence_repo->for_event(
					$event_id,
					array(
						'status'     => 'scheduled',
						'start_from' => $args['start_from'],
					)
				);
			}

			if ( empty( $occurrences ) ) {
				continue;
			}

			foreach ( $this->build_event_vevents( $event, $occurrences ) as $vevent ) {
				$vevents[] = $vevent;
			}
		}

		return $this->generate_ical( $vevents );
	}

	/**
	 * Parse iCal content and return parsed events.
	 *
	 * @param string $ical_content iCal file content.
	 * @return array<int, array<string, mixed>> Array of parsed event data.
	 */
	public function parse_ical( string $ical_content ): array {
		$events = array();

		// Normalize line endings.
		$ical_content = str_replace( "\r\n", "\n", $ical_content );
		$ical_content = str_replace( "\r", "\n", $ical_content );

		// Unfold long lines (RFC 5545: lines can be folded with CRLF + space/tab).
		$ical_content = preg_replace( "/\n[ \t]/", '', $ical_content ) ?? '';

		// Extract VEVENT blocks.
		if ( preg_match_all( '/BEGIN:VEVENT(.*?)END:VEVENT/s', $ical_content, $matches ) ) {
			foreach ( $matches[1] as $vevent_content ) {
				$event_data = $this->vevent_parser->parse( $vevent_content );
				if ( $event_data ) {
					$events[] = $event_data;
				}
			}
		}

		return $events;
	}

	/**
	 * Import events from iCal content.
	 *
	 * @param string               $ical_content iCal file content.
	 * @param array<string, mixed> $options      Import options.
	 * @return array{imported: int, skipped: int, errors: array<int, string>, events: array<int, Event>} Import results.
	 */
	public function import_ical( string $ical_content, array $options = array() ): array {
		$defaults = array(
			'status'          => 'draft',
			'skip_duplicates' => true,
		);

		$options = wp_parse_args( $options, $defaults );
		$results = array(
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => array(),
			'events'   => array(),
		);

		$parsed_events = $this->parse_ical( $ical_content );

		foreach ( $parsed_events as $event_data ) {
			try {
				// Check for duplicates by UID.
				if ( $options['skip_duplicates'] && ! empty( $event_data['uid'] ) ) {
					$existing = $this->find_event_by_uid( $event_data['uid'] );
					if ( $existing ) {
						++$results['skipped'];
						continue;
					}
				}

				$event = $this->create_event_from_data( $event_data, $options );
				if ( $event ) {
					++$results['imported'];
					$results['events'][] = $event;
				}
			} catch ( \Exception $e ) {
				$results['errors'][] = sprintf(
					'Failed to import event "%s": %s',
					$event_data['summary'] ?? 'Unknown',
					$e->getMessage()
				);
			}
		}

		return $results;
	}

	/**
	 * Generate iCal content from VEVENT strings.
	 *
	 * @param array<int, string> $vevents Array of VEVENT content strings.
	 * @return string Complete iCal content.
	 */
	private function generate_ical( array $vevents ): string {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "VERSION:2.0\r\n";
		$ical .= 'PRODID:' . self::PRODID . "\r\n";
		$ical .= "CALSCALE:GREGORIAN\r\n";
		$ical .= "METHOD:PUBLISH\r\n";
		$ical .= 'X-WR-CALNAME:' . $this->escape_text( get_bloginfo( 'name' ) . ' Events' ) . "\r\n";

		foreach ( $vevents as $vevent ) {
			$ical .= $vevent;
		}

		$ical .= "END:VCALENDAR\r\n";

		return $ical;
	}

	/**
	 * Create a plain standalone VEVENT for a single occurrence.
	 *
	 * Used for single / non-recurring events, for the explicit single-instance
	 * export (`export_occurrence`), and as the safety fallback when a recurring
	 * event's rule fails to parse. The UID is occurrence-scoped because this is
	 * an explicit single-instance export, not a series master (see contract §5).
	 *
	 * @param Event      $event      Event model.
	 * @param Occurrence $occurrence Occurrence model.
	 * @return string VEVENT content.
	 */
	private function create_vevent( Event $event, Occurrence $occurrence ): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'nettertech-events';
		$uid  = sprintf( 'occurrence-%d@%s', $occurrence->id, $host );

		$start = new \DateTimeImmutable( $occurrence->start_datetime );
		$end   = new \DateTimeImmutable( $occurrence->end_datetime );

		$vevent  = "BEGIN:VEVENT\r\n";
		$vevent .= sprintf( "UID:%s\r\n", $uid );
		$vevent .= sprintf( "DTSTAMP:%s\r\n", gmdate( 'Ymd\THis\Z' ) );

		if ( $occurrence->all_day ) {
			$vevent .= sprintf( "DTSTART;VALUE=DATE:%s\r\n", $start->format( 'Ymd' ) );
			$vevent .= sprintf( "DTEND;VALUE=DATE:%s\r\n", $end->modify( '+1 day' )->format( 'Ymd' ) );
		} else {
			$vevent .= sprintf( "DTSTART:%s\r\n", $start->format( 'Ymd\THis\Z' ) );
			$vevent .= sprintf( "DTEND:%s\r\n", $end->format( 'Ymd\THis\Z' ) );
		}

		// Use occurrence title override if present.
		$title   = $occurrence->title_override ?? $event->title;
		$vevent .= sprintf( "SUMMARY:%s\r\n", $this->escape_text( $title ) );

		// Location.
		if ( $event->venue_name ) {
			$location = $event->venue_name;
			if ( $event->venue_address ) {
				$location .= ', ' . $event->venue_address;
			}
			$vevent .= sprintf( "LOCATION:%s\r\n", $this->escape_text( $location ) );
		}

		// Description.
		$description = $occurrence->description_override ?? $event->description;
		if ( $description ) {
			$vevent .= sprintf( "DESCRIPTION:%s\r\n", $this->escape_text( wp_strip_all_tags( $description ) ) );
		}

		// URL to event page.
		if ( $event->slug ) {
			$url     = home_url( PathHelper::get_base_path() . '/' . $event->slug );
			$vevent .= sprintf( "URL:%s\r\n", $url );
		}

		// Categories from nettertech_events_categories table.
		if ( null !== $event->id ) {
			$vevent .= $this->build_categories_line( $event->id );
		}

		$status  = $occurrence->is_cancelled() ? 'CANCELLED' : 'CONFIRMED';
		$vevent .= sprintf( "STATUS:%s\r\n", $status );
		$vevent .= "TRANSP:OPAQUE\r\n";
		$vevent .= "END:VEVENT\r\n";

		return $vevent;
	}

	/**
	 * Create a master VEVENT for a recurring series (compact-recurring model).
	 *
	 * Emits a single VEVENT with a stable per-series UID, DTSTART/DTEND from the
	 * earliest occurrence, the RRULE serialized via the recurrence rule object
	 * (never hand-emitted), STATUS:CONFIRMED always, and one EXDATE line per
	 * cancelled occurrence. See specs/ical-export-contract.md §2-§3.
	 *
	 * @param Event              $event       Event model.
	 * @param array<Occurrence>  $occurrences All occurrences for the event (any status).
	 * @param RecurrenceRule     $rule        Parsed recurrence rule.
	 * @param \DateTimeImmutable $anchor      Series-start anchor (earliest occurrence start).
	 * @return string VEVENT content.
	 */
	private function create_master_vevent( Event $event, array $occurrences, RecurrenceRule $rule, \DateTimeImmutable $anchor ): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'nettertech-events';
		$uid  = sprintf( 'event-%d@%s', $event->id, $host );

		$earliest = $this->earliest_occurrence( $occurrences );
		$start    = new \DateTimeImmutable( $earliest->start_datetime );
		$end      = new \DateTimeImmutable( $earliest->end_datetime );

		$vevent  = "BEGIN:VEVENT\r\n";
		$vevent .= sprintf( "UID:%s\r\n", $uid );
		$vevent .= sprintf( "DTSTAMP:%s\r\n", gmdate( 'Ymd\THis\Z' ) );

		if ( $earliest->all_day ) {
			$vevent .= sprintf( "DTSTART;VALUE=DATE:%s\r\n", $start->format( 'Ymd' ) );
			$vevent .= sprintf( "DTEND;VALUE=DATE:%s\r\n", $end->modify( '+1 day' )->format( 'Ymd' ) );
		} else {
			$vevent .= sprintf( "DTSTART:%s\r\n", $start->format( 'Ymd\THis\Z' ) );
			$vevent .= sprintf( "DTEND:%s\r\n", $end->format( 'Ymd\THis\Z' ) );
		}

		$vevent .= sprintf( "RRULE:%s\r\n", $this->apply_feed_horizon( $rule )->to_string() );

		// EXDATE lines for cancelled occurrences (one per cancellation). The slot
		// is the ORIGINAL recurrence slot (handles time-moved-then-cancelled), not
		// the occurrence's possibly-moved start_datetime.
		foreach ( $occurrences as $occurrence ) {
			if ( $occurrence->is_cancelled() ) {
				$slot    = $this->resolve_original_slot( $occurrence, $event, $rule, $anchor );
				$vevent .= $this->build_exdate_line( $slot, $occurrence->all_day );
			}
		}

		$vevent .= sprintf( "SUMMARY:%s\r\n", $this->escape_text( $event->title ) );

		if ( $event->venue_name ) {
			$location = $event->venue_name;
			if ( $event->venue_address ) {
				$location .= ', ' . $event->venue_address;
			}
			$vevent .= sprintf( "LOCATION:%s\r\n", $this->escape_text( $location ) );
		}

		if ( $event->description ) {
			$vevent .= sprintf( "DESCRIPTION:%s\r\n", $this->escape_text( wp_strip_all_tags( $event->description ) ) );
		}

		if ( $event->slug ) {
			$url     = home_url( PathHelper::get_base_path() . '/' . $event->slug );
			$vevent .= sprintf( "URL:%s\r\n", $url );
		}

		if ( null !== $event->id ) {
			$vevent .= $this->build_categories_line( $event->id );
		}

		// Master is always CONFIRMED; cancellations are EXDATE, not STATUS.
		$vevent .= "STATUS:CONFIRMED\r\n";
		$vevent .= "TRANSP:OPAQUE\r\n";
		$vevent .= "END:VEVENT\r\n";

		return $vevent;
	}

	/**
	 * Cap an open-ended recurrence rule with the configured feed horizon.
	 *
	 * An open-ended RRULE (no UNTIL and no COUNT) is expanded forever by a
	 * subscribing calendar client. To bound that, emit a forward UNTIL of
	 * "today + horizon" for such rules only. Rules that already carry an end
	 * condition are returned untouched, and a horizon of 0 disables the cap
	 * entirely (opt-out). See NTE-014.
	 *
	 * @param RecurrenceRule $rule Parsed recurrence rule.
	 * @return RecurrenceRule The same rule, or a horizon-capped clone.
	 */
	private function apply_feed_horizon( RecurrenceRule $rule ): RecurrenceRule {
		if ( $rule->has_end() ) {
			return $rule;
		}

		$horizon_days = $this->feed_horizon_days();
		if ( $horizon_days <= 0 ) {
			return $rule;
		}

		$until = ( new \DateTimeImmutable( 'today', new \DateTimeZone( 'UTC' ) ) )
			->modify( sprintf( '+%d days', $horizon_days ) );

		return $rule->with_until( $until );
	}

	/**
	 * Resolve the configured iCal feed horizon (days), memoized per request.
	 *
	 * @return int Horizon in days; 0 means the cap is disabled.
	 */
	private function feed_horizon_days(): int {
		if ( null === $this->feed_horizon_days ) {
			$this->feed_horizon_days = NetterTechEventsSettings::from_option()->performance->ical_feed_horizon_days;
		}

		return $this->feed_horizon_days;
	}

	/**
	 * Find the earliest occurrence by start_datetime.
	 *
	 * Sorts explicitly rather than trusting repository order.
	 *
	 * @param array<Occurrence> $occurrences Occurrences (non-empty).
	 * @return Occurrence Earliest occurrence.
	 */
	private function earliest_occurrence( array $occurrences ): Occurrence {
		$sorted = $occurrences;
		usort(
			$sorted,
			static function ( Occurrence $a, Occurrence $b ): int {
				return strcmp( $a->start_datetime, $b->start_datetime );
			}
		);

		return $sorted[0];
	}

	/**
	 * Build an EXDATE line for a cancelled occurrence's original slot.
	 *
	 * Value type matches DTSTART (timed vs all-day) per contract §3. The slot is
	 * recovered via resolve_original_slot() so time-moved-then-cancelled instances
	 * exclude the correct recurrence date.
	 *
	 * @param \DateTimeImmutable $slot    Original recurrence-slot datetime.
	 * @param bool               $all_day Whether the occurrence is all-day.
	 * @return string EXDATE line.
	 */
	private function build_exdate_line( \DateTimeImmutable $slot, bool $all_day ): string {
		if ( $all_day ) {
			return sprintf( "EXDATE;VALUE=DATE:%s\r\n", $slot->format( 'Ymd' ) );
		}

		return sprintf( "EXDATE:%s\r\n", $slot->format( 'Ymd\THis\Z' ) );
	}

	/**
	 * Create a RECURRENCE-ID override VEVENT for a moved/edited occurrence.
	 *
	 * Shares the master UID and is keyed to its ORIGINAL recurrence slot via
	 * RECURRENCE-ID (value-type-matched to DTSTART). DTSTART/DTEND reflect the
	 * occurrence's ACTUAL (possibly moved) datetime, and all overridable fields
	 * resolve via the Occurrence effective getters (override -> parent). See
	 * contract §4.
	 *
	 * @param Event              $event      Event model (parent, for effective getters).
	 * @param Occurrence         $occurrence Override occurrence (not cancelled).
	 * @param RecurrenceRule     $rule       Parsed recurrence rule.
	 * @param \DateTimeImmutable $anchor     Series-start anchor (earliest occurrence start).
	 * @return string VEVENT content.
	 */
	private function create_recurrence_override_vevent( Event $event, Occurrence $occurrence, RecurrenceRule $rule, \DateTimeImmutable $anchor ): string {
		// Wire the parent so effective getters resolve override -> parent without a repo.
		$occurrence->set_event( $event );

		$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'nettertech-events';
		$uid  = sprintf( 'event-%d@%s', $event->id, $host );

		$slot  = $this->resolve_original_slot( $occurrence, $event, $rule, $anchor );
		$start = new \DateTimeImmutable( $occurrence->start_datetime );
		$end   = new \DateTimeImmutable( $occurrence->end_datetime );

		$vevent  = "BEGIN:VEVENT\r\n";
		$vevent .= sprintf( "UID:%s\r\n", $uid );
		$vevent .= sprintf( "DTSTAMP:%s\r\n", gmdate( 'Ymd\THis\Z' ) );

		if ( $occurrence->all_day ) {
			$vevent .= sprintf( "RECURRENCE-ID;VALUE=DATE:%s\r\n", $slot->format( 'Ymd' ) );
			$vevent .= sprintf( "DTSTART;VALUE=DATE:%s\r\n", $start->format( 'Ymd' ) );
			$vevent .= sprintf( "DTEND;VALUE=DATE:%s\r\n", $end->modify( '+1 day' )->format( 'Ymd' ) );
		} else {
			$vevent .= sprintf( "RECURRENCE-ID:%s\r\n", $slot->format( 'Ymd\THis\Z' ) );
			$vevent .= sprintf( "DTSTART:%s\r\n", $start->format( 'Ymd\THis\Z' ) );
			$vevent .= sprintf( "DTEND:%s\r\n", $end->format( 'Ymd\THis\Z' ) );
		}

		$vevent .= sprintf( "SUMMARY:%s\r\n", $this->escape_text( $occurrence->get_title() ) );

		$venue_name = $occurrence->get_venue_name();
		if ( '' !== $venue_name ) {
			$location      = $venue_name;
			$venue_address = $occurrence->get_venue_address();
			if ( '' !== $venue_address ) {
				$location .= ', ' . $venue_address;
			}
			$vevent .= sprintf( "LOCATION:%s\r\n", $this->escape_text( $location ) );
		}

		$description = $occurrence->get_description();
		if ( '' !== $description ) {
			$vevent .= sprintf( "DESCRIPTION:%s\r\n", $this->escape_text( wp_strip_all_tags( $description ) ) );
		}

		if ( $event->slug ) {
			$url     = home_url( PathHelper::get_base_path() . '/' . $event->slug );
			$vevent .= sprintf( "URL:%s\r\n", $url );
		}

		if ( null !== $event->id ) {
			$vevent .= $this->build_categories_line( $event->id );
		}

		$vevent .= "STATUS:CONFIRMED\r\n";
		$vevent .= "TRANSP:OPAQUE\r\n";
		$vevent .= "END:VEVENT\r\n";

		return $vevent;
	}

	/**
	 * Resolve an override occurrence's ORIGINAL recurrence slot (approach C).
	 *
	 * A Chunk-6 edit may have moved start_datetime, and no original slot is
	 * stored (only sequence_number). We regenerate the theoretical slot set via
	 * OccurrenceGenerator and map the occurrence to its slot by sequence_number
	 * (1-based; slot[seq-1]). Out-of-range sequence numbers fall back to the
	 * nearest slot by start_datetime. If the generator yields nothing (degenerate
	 * rule), fall back to the occurrence's own start_datetime. See contract §6.
	 *
	 * @param Occurrence         $occurrence Override occurrence.
	 * @param Event              $event      Parent event.
	 * @param RecurrenceRule     $rule       Parsed recurrence rule.
	 * @param \DateTimeImmutable $anchor     Series-start anchor (earliest occurrence start).
	 * @return \DateTimeImmutable Original recurrence-slot datetime.
	 */
	private function resolve_original_slot( Occurrence $occurrence, Event $event, RecurrenceRule $rule, \DateTimeImmutable $anchor ): \DateTimeImmutable {
		$actual = new \DateTimeImmutable( $occurrence->start_datetime );

		// Generate the theoretical slot set from the series anchor. The end arg
		// only sets per-slot duration (irrelevant to slot dates); pass the anchor.
		// Extend the horizon past both anchor and the moved occurrence so the
		// target slot is always within the generated set.
		$latest  = $actual > $anchor ? $actual : $anchor;
		$horizon = $latest->modify( '+1 day' );

		$slots = $this->occurrence_generator->generate( $event, $anchor, $anchor, $rule, $horizon );
		if ( empty( $slots ) ) {
			return $actual;
		}

		$index = $occurrence->sequence_number - 1;
		if ( $index >= 0 && isset( $slots[ $index ] ) ) {
			return new \DateTimeImmutable( $slots[ $index ]->start_datetime );
		}

		// Nearest-slot fallback by absolute distance to the actual start.
		$nearest      = $slots[0];
		$best_seconds = PHP_INT_MAX;
		foreach ( $slots as $slot ) {
			$slot_time = new \DateTimeImmutable( $slot->start_datetime );
			$diff      = abs( $slot_time->getTimestamp() - $actual->getTimestamp() );
			if ( $diff < $best_seconds ) {
				$best_seconds = $diff;
				$nearest      = $slot;
			}
		}

		return new \DateTimeImmutable( $nearest->start_datetime );
	}

	/**
	 * Build the CATEGORIES line for an event, or empty string if none.
	 *
	 * @param int $event_id Event ID.
	 * @return string CATEGORIES line or empty string.
	 */
	private function build_categories_line( int $event_id ): string {
		$categories = $this->category_repo->find_by_event( $event_id );
		if ( empty( $categories ) ) {
			return '';
		}

		$cat_names = array_map(
			function ( $category ) {
				return $this->escape_text( $category->name );
			},
			$categories
		);

		return sprintf( "CATEGORIES:%s\r\n", implode( ',', $cat_names ) );
	}

	/**
	 * Escape text for iCal format.
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	private function escape_text( string $text ): string {
		$text = str_replace( '\\', '\\\\', $text );
		$text = str_replace( ',', '\,', $text );
		$text = str_replace( ';', '\;', $text );
		$text = str_replace( "\n", '\\n', $text );
		$text = str_replace( "\r", '', $text );
		return $text;
	}

	/**
	 * Find an event by iCal UID.
	 *
	 * Checks if an event was previously imported with this UID.
	 * Currently a placeholder — meta support not yet implemented.
	 *
	 * @param string $uid iCal UID.
	 * @return Event|null Event if found, or null until meta support is implemented.
	 * @phpstan-ignore return.unusedType (upstream type declaration wider than actual return; narrowing requires WP core change)
	 */
	private function find_event_by_uid( string $uid ): ?Event {
		// UIDs would be stored in event meta (ical_uid).
		// Placeholder: meta support needed in Event model.
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Placeholder for future implementation.
		unset( $uid );
		return null;
	}

	/**
	 * Create an event from parsed iCal data.
	 *
	 * @param array<string, mixed> $data    Parsed event data.
	 * @param array<string, mixed> $options Import options.
	 * @return Event|null Created event or null on failure.
	 */
	private function create_event_from_data( array $data, array $options ): ?Event {
		$event              = new Event();
		$event->title       = $data['summary'];
		$event->description = $data['description'] ?? '';
		$event->status      = EventStatus::tryFrom( $options['status'] ?? 'draft' ) ?? EventStatus::DRAFT;
		$event->event_type  = ! empty( $data['rrule'] ) ? 'recurring' : 'single';
		$event->slug        = $this->event_repo->generate_unique_slug( $data['summary'] );

		// Parse location.
		if ( ! empty( $data['location'] ) ) {
			// Try to split venue name and address.
			$location_parts       = explode( ',', $data['location'], 2 );
			$event->venue_name    = trim( $location_parts[0] );
			$event->venue_address = isset( $location_parts[1] ) ? trim( $location_parts[1] ) : '';
		}

		// Handle recurrence rule.
		if ( ! empty( $data['rrule'] ) ) {
			$event->recurrence_rule = $data['rrule'];
		}

		// Save event.
		$event = $this->event_repo->save( $event );
		if ( ! $event || ! $event->id ) { // @phpstan-ignore booleanNot.alwaysFalse (false positive; value depends on runtime state, not statically determinable)
			return null;
		}

		// Assign imported categories to WordPress taxonomy.
		if ( ! empty( $data['categories'] ) ) {
			$this->sync_categories_to_taxonomy( $event->id, $data['categories'] );
		}

		// Create occurrence.
		$occurrence                 = new Occurrence();
		$occurrence->event_id       = $event->id;
		$occurrence->start_datetime = $data['start_datetime'];
		$occurrence->end_datetime   = $data['end_datetime'];
		$occurrence->all_day        = $data['all_day'] ?? false;
		$occurrence->status         = 'scheduled';

		$this->occurrence_repo->save( $occurrence );

		return $event;
	}

	/**
	 * Sync category names to nettertech_events_categories and associate with an event.
	 *
	 * Creates categories if they don't exist, then associates them with the event
	 * via the nettertech_events_event_categories junction table.
	 *
	 * @param int      $event_id       Event ID.
	 * @param string[] $category_names Array of category name strings.
	 * @return void
	 */
	private function sync_categories_to_taxonomy( int $event_id, array $category_names ): void {
		$repo         = $this->category_repo;
		$category_ids = array();

		foreach ( $category_names as $name ) {
			$name = trim( $name );
			if ( '' === $name ) {
				continue;
			}

			$slug     = sanitize_title( $name );
			$category = $repo->find_by_slug( $slug );

			if ( $category && null !== $category->id ) {
				$category_ids[] = $category->id;
			} else {
				$new_cat       = new \NetterTechEvents\Models\Category();
				$new_cat->name = $name;
				$new_cat->slug = $slug;

				try {
					$saved = $repo->save( $new_cat );
					if ( null !== $saved->id ) {
						$category_ids[] = $saved->id;
					}
				} catch ( \RuntimeException $e ) {
					DebugLogger::exception( $e, 'ICalService' );
					continue;
				}
			}
		}

		if ( ! empty( $category_ids ) ) {
			$repo->sync_event_categories( $event_id, $category_ids );
		}
	}

	/**
	 * Get content type header for iCal files.
	 *
	 * @return string Content-Type header value.
	 */
	public static function get_content_type(): string {
		return 'text/calendar; charset=utf-8';
	}

	/**
	 * Get content disposition header for download.
	 *
	 * @param string $filename Filename for download.
	 * @return string Content-Disposition header value.
	 */
	public static function get_content_disposition( string $filename = 'calendar.ics' ): string {
		return sprintf( 'attachment; filename="%s"', sanitize_file_name( $filename ) );
	}
}
