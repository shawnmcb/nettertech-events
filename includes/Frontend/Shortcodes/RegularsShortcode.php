<?php
/**
 * Weekly Regulars Shortcode
 *
 * Renders a table of weekly recurring events grouped by day of week.
 * Replaces manually-maintained static HTML tables with live data.
 *
 * Usage: [nettertech_events_regulars]
 *        [nettertech_events_regulars show_venue="false" show_time="true" limit="20"]
 *
 * @package NetterTechEvents\Frontend\Shortcodes
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Models\RecurrenceRule;
use NetterTechEvents\Services\RRuleParser;
use NetterTechEvents\TemplateLoader\Templates;

/**
 * Renders a table of weekly recurring events grouped by day of week.
 *
 * @since 1.0.0
 */
class RegularsShortcode {

	/**
	 * Default shortcode attributes.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'limit'      => 50,
		'show_venue' => 'true',
		'show_time'  => 'true',
		'show_day'   => 'true',
		'class'      => '',
		'heading'    => '',
	);

	/**
	 * Day-of-week display order (Monday first).
	 *
	 * @var array<string, int>
	 */
	private const DAY_ORDER = array(
		'Monday'    => 1,
		'Tuesday'   => 2,
		'Wednesday' => 3,
		'Thursday'  => 4,
		'Friday'    => 5,
		'Saturday'  => 6,
		'Sunday'    => 7,
	);

	/**
	 * RRULE day abbreviation to full day name.
	 *
	 * @var array<string, string>
	 */
	private const RRULE_DAY_NAMES = array(
		'MO' => 'Monday',
		'TU' => 'Tuesday',
		'WE' => 'Wednesday',
		'TH' => 'Thursday',
		'FR' => 'Friday',
		'SA' => 'Saturday',
		'SU' => 'Sunday',
	);

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
	 * RRULE parser.
	 *
	 * @var RRuleParser
	 */
	private RRuleParser $rrule_parser;

	/**
	 * Templates service.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Constructor.
	 *
	 * @param EventRepositoryInterface      $event_repo      Event repository.
	 * @param OccurrenceRepositoryInterface $occurrence_repo Occurrence repository.
	 * @param RRuleParser                   $rrule_parser    RRULE parser.
	 * @param Templates                     $templates       Templates service.
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		RRuleParser $rrule_parser,
		Templates $templates
	) {
		$this->event_repo      = $event_repo;
		$this->occurrence_repo = $occurrence_repo;
		$this->rrule_parser    = $rrule_parser;
		$this->templates       = $templates;
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string, string>|string $atts    Shortcode attributes.
	 * @param string|null                  $content Shortcode content (unused).
	 * @return string Rendered HTML.
	 */
	public function render( $atts = array(), ?string $content = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		$atts = shortcode_atts( self::DEFAULTS, is_array( $atts ) ? $atts : array(), 'nettertech_events_regulars' );

		$atts['show_venue'] = filter_var( $atts['show_venue'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_time']  = filter_var( $atts['show_time'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_day']   = filter_var( $atts['show_day'], FILTER_VALIDATE_BOOLEAN );
		$atts['limit']      = absint( $atts['limit'] );

		$rows = $this->build_rows( $atts['limit'] );

		if ( empty( $rows ) ) {
			return $this->templates->get_template_part(
				'empty-state',
				array(
					'message' => __( 'No weekly regulars at this time.', 'nettertech-events' ),
				)
			);
		}

		return $this->templates->get_template_part(
			'regulars-table',
			array(
				'rows'       => $rows,
				'show_venue' => $atts['show_venue'],
				'show_time'  => $atts['show_time'],
				'show_day'   => $atts['show_day'],
				'class'      => sanitize_html_class( $atts['class'] ),
				'heading'    => sanitize_text_field( $atts['heading'] ),
			)
		);
	}

	/**
	 * Build table rows from recurring event data.
	 *
	 * Each row contains: day_name, event_title, event_url, venue_name, time.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int, array{day_name: string, day_order: int, event_title: string, event_url: string, venue_name: string, time: string}>
	 */
	private function build_rows( int $limit ): array {
		$events = $this->event_repo->all(
			array(
				'type'   => 'recurring',
				'status' => 'published',
				'limit'  => $limit,
			)
		);

		$rows = array();

		foreach ( $events as $event ) {
			if ( empty( $event->recurrence_rule ) ) {
				continue;
			}

			// Parse recurrence rule to find weekly days.
			try {
				$rule = $this->rrule_parser->parse( $event->recurrence_rule );
			} catch ( \Exception $e ) {
				continue;
			}

			// Only include weekly events.
			if ( RecurrenceRule::FREQ_WEEKLY !== $rule->freq || null === $event->id ) {
				continue;
			}

			// Get the next occurrence for time display.
			$next = $this->occurrence_repo->get_upcoming_by_event( $event->id, 1 );
			$time = '';
			if ( ! empty( $next ) ) {
				$occurrence = $next[0];
				if ( $occurrence->all_day ) {
					$time = __( 'All Day', 'nettertech-events' );
				} else {
					$time = $occurrence->get_start_time();
				}
			}

			// Build a row for each day this event occurs.
			$days = $rule->by_day;
			if ( empty( $days ) ) {
				// Weekly with no BYDAY defaults to the start day.
				if ( ! empty( $next ) ) {
					$day_num = (int) $next[0]->get_start()->format( 'w' );
					$days    = array( RecurrenceRule::PHP_DAY_MAP[ $day_num ] );
				}
			}

			foreach ( $days as $day_code ) {
				$day_name = self::RRULE_DAY_NAMES[ $day_code ] ?? $day_code;
				$rows[]   = array(
					'day_name'    => $day_name,
					'day_order'   => self::DAY_ORDER[ $day_name ] ?? 8,
					'event_title' => $event->title,
					'event_url'   => $event->get_permalink(),
					'venue_name'  => $event->venue_name ?? '',
					'time'        => $time,
				);
			}
		}

		// Sort by day of week, then by time within each day.
		usort(
			$rows,
			function ( array $a, array $b ): int {
				$day_cmp = $a['day_order'] <=> $b['day_order'];
				if ( 0 !== $day_cmp ) {
					return $day_cmp;
				}
				return strcmp( $a['time'], $b['time'] );
			}
		);

		return array_slice( $rows, 0, $limit );
	}
}
