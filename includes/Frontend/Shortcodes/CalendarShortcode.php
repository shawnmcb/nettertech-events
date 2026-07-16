<?php
/**
 * Calendar shortcode.
 *
 * @package NetterTechEvents\Frontend\Shortcodes
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Frontend\CalendarRouting;
use NetterTechEvents\Frontend\FrontendBranding;
use NetterTechEvents\Repositories\OccurrenceRepository;

/**
 * Renders an interactive event calendar with month/week/day views.
 *
 * Usage: [nettertech_events_calendar view="month" show_view_switcher="true"]
 *
 * @since 1.0.0
 */
class CalendarShortcode {

	/**
	 * Default attributes.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'view'               => 'month', // month, week, day.
		'show_view_switcher' => true,
		'show_navigation'    => true,
		'class'              => '',
		'rental_mode'        => '',
		'space_id'           => 0,
	);

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Unique instance counter.
	 *
	 * @var int
	 */
	private static int $instance_count = 0;

	/**
	 * Constructor.
	 *
	 * @param OccurrenceRepositoryInterface $occurrence_repo Occurrence repository.
	 */
	public function __construct( OccurrenceRepositoryInterface $occurrence_repo ) {
		$this->occurrence_repo = $occurrence_repo;
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes.
	 * @param string|null                 $content Shortcode content (unused, required by WP shortcode API).
	 * @return string
	 */
	public function render( $atts = array(), ?string $content = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by WP shortcode/hook/filter API signature; cannot remove parameter.
		FrontendBranding::mark_content_rendered();

		$atts = shortcode_atts( self::DEFAULTS, is_array( $atts ) ? $atts : array(), 'nettertech_events_calendar' );

		/**
		 * Filter calendar shortcode attributes after defaults are merged.
		 *
		 * @since 1.0.2
		 *
		 * @param array<string, mixed> $atts Shortcode attributes.
		 */
		$atts = apply_filters( 'nettertech_events_calendar_shortcode_atts', $atts );

		// Normalize boolean attributes.
		$atts['show_view_switcher'] = filter_var( $atts['show_view_switcher'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_navigation']    = filter_var( $atts['show_navigation'], FILTER_VALIDATE_BOOLEAN );

		// Validate view.
		$valid_views = array( 'month', 'week', 'day' );
		if ( ! in_array( $atts['view'], $valid_views, true ) ) {
			$atts['view'] = 'month';
		}

		// Generate unique ID for this instance.
		++self::$instance_count;
		$instance_id = 'nte-calendar-' . self::$instance_count;

		// Determine initial date from URL parameter (?month=YYYY-MM or ?date=YYYY-MM-DD).
		$initial_date = $this->get_initial_date();

		// Get initial events for server-side render (SEO).
		$initial_events = $this->get_initial_events( $atts['view'], $initial_date );

		// Build wrapper classes.
		$classes = array(
			'nte-calendar',
			'nte-calendar--' . $atts['view'],
		);
		if ( ! empty( $atts['class'] ) ) {
			$classes[] = $atts['class'];
		}

		/**
		 * Filter CSS classes applied to the calendar wrapper element.
		 *
		 * @since 1.0.2
		 *
		 * @param string[]             $classes CSS class names.
		 * @param array<string, mixed> $atts    Shortcode attributes.
		 */
		$classes = apply_filters( 'nettertech_events_calendar_wrapper_classes', $classes, $atts );

		ob_start();
		?>
		<div
			class="<?php echo esc_attr( implode( ' ', array_filter( $classes ) ) ); ?>"
			id="<?php echo esc_attr( $instance_id ); ?>"
			data-instance="<?php echo esc_attr( (string) self::$instance_count ); ?>"
			data-view="<?php echo esc_attr( $atts['view'] ); ?>"
			data-initial-date="<?php echo esc_attr( $initial_date->format( 'Y-m-d' ) ); ?>"
			role="region"
			aria-label="<?php esc_attr_e( 'Event Calendar', 'nettertech-events' ); ?>"
			tabindex="0"
		>
			<header class="nte-calendar__header">
				<?php if ( $atts['show_navigation'] ) : ?>
					<nav class="nte-calendar__nav" aria-label="<?php esc_attr_e( 'Calendar navigation', 'nettertech-events' ); ?>">
						<button
							type="button"
							class="nte-calendar__nav-button nte-calendar__nav-button--prev"
							aria-label="<?php esc_attr_e( 'Previous', 'nettertech-events' ); ?>"
						>
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
								<path d="m15 18-6-6 6-6"/>
							</svg>
						</button>
						<button
							type="button"
							class="nte-calendar__nav-button nte-calendar__nav-button--next"
							aria-label="<?php esc_attr_e( 'Next', 'nettertech-events' ); ?>"
						>
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
								<path d="m9 18 6-6-6-6"/>
							</svg>
						</button>
					</nav>
				<?php endif; ?>

				<?php
				/**
				 * Filter additional HTML injected into the calendar header.
				 *
				 * @since 1.0.2
				 *
				 * @param string               $html Additional header HTML.
				 * @param array<string, mixed>  $atts Shortcode attributes.
				 */
				echo wp_kses_post( apply_filters( 'nettertech_events_calendar_header_html', '', $atts ) );
				?>

				<h2 class="nte-calendar__title" aria-live="polite">
					<?php echo esc_html( $this->get_initial_title( $atts['view'], $initial_date ) ); ?>
				</h2>

				<?php if ( $atts['show_view_switcher'] ) : ?>
					<div class="nte-calendar__views" role="tablist" aria-label="<?php esc_attr_e( 'Calendar views', 'nettertech-events' ); ?>">
						<button
							type="button"
							class="nte-calendar__view-button <?php echo 'month' === $atts['view'] ? 'nte-calendar__view-button--active' : ''; ?>"
							data-view="month"
							role="tab"
							aria-selected="<?php echo 'month' === $atts['view'] ? 'true' : 'false'; ?>"
						>
							<?php esc_html_e( 'Month', 'nettertech-events' ); ?>
						</button>
						<button
							type="button"
							class="nte-calendar__view-button <?php echo 'week' === $atts['view'] ? 'nte-calendar__view-button--active' : ''; ?>"
							data-view="week"
							role="tab"
							aria-selected="<?php echo 'week' === $atts['view'] ? 'true' : 'false'; ?>"
						>
							<?php esc_html_e( 'Week', 'nettertech-events' ); ?>
						</button>
						<button
							type="button"
							class="nte-calendar__view-button <?php echo 'day' === $atts['view'] ? 'nte-calendar__view-button--active' : ''; ?>"
							data-view="day"
							role="tab"
							aria-selected="<?php echo 'day' === $atts['view'] ? 'true' : 'false'; ?>"
						>
							<?php esc_html_e( 'Day', 'nettertech-events' ); ?>
						</button>
					</div>
				<?php endif; ?>
			</header>

			<div class="nte-calendar__grid" role="grid" aria-label="<?php esc_attr_e( 'Calendar grid', 'nettertech-events' ); ?>">
				<?php echo wp_kses( $this->render_initial_grid( $atts['view'], $initial_events, $initial_date ), ShortcodeOutput::get_allowlist() ); ?>
			</div>
			<?php echo wp_kses( FrontendBranding::render_badge(), ShortcodeOutput::get_allowlist() ); ?>
		</div>
		<?php

		/**
		 * Fires after the calendar shortcode has rendered.
		 *
		 * @since 1.0.2
		 *
		 * @param array<string, mixed> $atts Shortcode attributes.
		 */
		do_action( 'nettertech_events_calendar_render_complete', $atts );

		return (string) ob_get_clean();
	}

	/**
	 * Parse the initial date from registered query vars.
	 *
	 * Reads `nettertech_events_calendar_date` (YYYY-MM-DD) and
	 * `nettertech_events_calendar_month` (YYYY-MM) — registered via
	 * CalendarRouting::add_query_vars(). Legacy `?month=` / `?date=`
	 * URLs are 301-redirected to the prefixed format by
	 * CalendarRouting::maybe_redirect_legacy_query() before this code runs.
	 *
	 * @return \DateTime Reference date for initial render.
	 */
	private function get_initial_date(): \DateTime {
		$now = new \DateTime( 'now', wp_timezone() );

		$date_param  = (string) get_query_var( CalendarRouting::QUERY_VAR_DATE, '' );
		$month_param = (string) get_query_var( CalendarRouting::QUERY_VAR_MONTH, '' );

		if ( '' !== $date_param && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_param ) ) {
			$parsed = \DateTime::createFromFormat( 'Y-m-d', $date_param, wp_timezone() );
			if ( $parsed ) {
				return $parsed;
			}
		}

		if ( '' !== $month_param && preg_match( '/^\d{4}-\d{2}$/', $month_param ) ) {
			$parsed = \DateTime::createFromFormat( 'Y-m-d', $month_param . '-01', wp_timezone() );
			if ( $parsed ) {
				return $parsed;
			}
		}

		return $now;
	}

	/**
	 * Get initial events for the current view period.
	 *
	 * Uses transient caching to avoid repeated range queries on same day.
	 * Cache key includes view type and date to ensure fresh data per day.
	 *
	 * @param string    $view Current view (month/week/day).
	 * @param \DateTime $now  Reference date.
	 * @return array<\NetterTechEvents\Models\Occurrence>
	 */
	private function get_initial_events( string $view, \DateTime $now ): array {

		switch ( $view ) {
			case 'month':
				$start = ( clone $now )->modify( 'first day of this month' )->modify( 'last sunday' );
				$end   = ( clone $start )->modify( '+42 days' );
				break;

			case 'week':
				$start = ( clone $now )->modify( 'last sunday' );
				$end   = ( clone $start )->modify( '+7 days' );
				break;

			case 'day':
			default:
				$start = ( clone $now )->setTime( 0, 0, 0 );
				$end   = ( clone $now )->setTime( 23, 59, 59 );
				break;
		}

		// Cache key includes view and start date (changes daily/weekly/monthly).
		$cache_key = 'nettertech_events_calendar_' . $view . '_' . $start->format( 'Y-m-d' );

		// Try to get from cache first.
		$events = get_transient( $cache_key );

		if ( false === $events ) {
			$events = $this->occurrence_repo->in_range(
				$start->format( 'Y-m-d H:i:s' ),
				$end->format( 'Y-m-d H:i:s' ),
				array( 'include_events' => true )
			);

			// Cache for 1 hour (invalidated by save hooks).
			set_transient( $cache_key, $events, HOUR_IN_SECONDS );
		}

		return $events;
	}

	/**
	 * Get initial title based on view.
	 *
	 * @param string    $view Current view.
	 * @param \DateTime $now  Reference date.
	 * @return string
	 */
	private function get_initial_title( string $view, \DateTime $now ): string {

		switch ( $view ) {
			case 'month':
				return $now->format( 'F Y' );

			case 'week':
				$start = ( clone $now )->modify( 'last sunday' );
				$end   = ( clone $start )->modify( '+6 days' );
				return $start->format( 'M j' ) . ' - ' . $end->format( 'M j, Y' );

			case 'day':
			default:
				return $now->format( 'l, F j, Y' );
		}
	}

	/**
	 * Render initial grid for server-side render.
	 *
	 * @param string                                     $view     Current view.
	 * @param array<\NetterTechEvents\Models\Occurrence> $events   Events for the period.
	 * @param \DateTime                                  $ref_date Reference date.
	 * @return string
	 */
	private function render_initial_grid( string $view, array $events, \DateTime $ref_date ): string {
		switch ( $view ) {
			case 'month':
				return $this->render_month_grid( $events, $ref_date );

			case 'week':
				return $this->render_week_grid( $events, $ref_date );

			case 'day':
			default:
				return $this->render_day_grid( $events, $ref_date );
		}
	}

	/**
	 * Render month grid.
	 *
	 * @param array<\NetterTechEvents\Models\Occurrence> $events   Events.
	 * @param \DateTime                                  $ref_date Reference date.
	 * @return string
	 */
	private function render_month_grid( array $events, \DateTime $ref_date ): string {
		$days          = array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' );
		$today         = ( new \DateTime( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		$current_month = (int) $ref_date->format( 'n' );

		$start = ( clone $ref_date )->modify( 'first day of this month' )->modify( 'last sunday' );

		// Index events by date.
		$events_by_date = array();
		foreach ( $events as $occ ) {
			$date = substr( $occ->start_datetime, 0, 10 );
			if ( ! isset( $events_by_date[ $date ] ) ) {
				$events_by_date[ $date ] = array();
			}
			$events_by_date[ $date ][] = $occ;
		}

		ob_start();

		// Day headers.
		echo '<div class="nte-calendar__header-row" role="row">';
		foreach ( $days as $day ) {
			echo '<div class="nte-calendar__day-header" role="columnheader">' . esc_html( $day ) . '</div>';
		}
		echo '</div>';

		// Calendar days.
		for ( $i = 0; $i < 42; $i++ ) {
			if ( 0 === $i % 7 ) {
				echo '<div class="nte-calendar__week-row" role="row">';
			}
			$date           = ( clone $start )->modify( "+{$i} days" );
			$date_str       = $date->format( 'Y-m-d' );
			$is_other_month = (int) $date->format( 'n' ) !== $current_month;
			$is_today       = $date_str === $today;
			$day_events     = $events_by_date[ $date_str ] ?? array();

			$classes = 'nte-calendar__day';
			if ( $is_other_month ) {
				$classes .= ' nte-calendar__day--other-month';
			}
			if ( $is_today ) {
				$classes .= ' nte-calendar__day--today';
			}

			echo '<div class="' . esc_attr( $classes ) . '" data-date="' . esc_attr( $date_str ) . '" role="gridcell">';
			echo '<div class="nte-calendar__day-number">' . esc_html( $date->format( 'j' ) ) . '</div>';

			$shown = 0;
			foreach ( $day_events as $occ ) {
				if ( $shown >= 3 ) {
					break;
				}
				$event = $occ->get_event();
				$title = $event ? $event->title : __( 'Event', 'nettertech-events' );
				$url   = $event ? $event->get_permalink() : '#';
				echo '<a href="' . esc_url( $url ) . '" class="nte-calendar__event">' . esc_html( $title ) . '</a>';
				++$shown;
			}

			if ( count( $day_events ) > 3 ) {
				$more       = count( $day_events ) - 3;
				$more_label = sprintf(
					/* translators: 1: number of additional events, 2: formatted date. */
					_n( '%1$d more event on %2$s', '%1$d more events on %2$s', $more, 'nettertech-events' ),
					$more,
					$date->format( 'F j' )
				);
				echo '<button type="button" class="nte-calendar__more" data-date="' . esc_attr( $date_str ) . '" aria-label="' . esc_attr( $more_label ) . '">+' . esc_html( (string) $more ) . ' ' . esc_html__( 'more', 'nettertech-events' ) . '</button>';
			}

			echo '</div>';
			if ( 6 === $i % 7 ) {
				echo '</div>';
			}
		}

		return (string) ob_get_clean();
	}

	/**
	 * Render week grid.
	 *
	 * @param array<\NetterTechEvents\Models\Occurrence> $events   Events.
	 * @param \DateTime                                  $ref_date Reference date.
	 * @return string
	 */
	private function render_week_grid( array $events, \DateTime $ref_date ): string {
		$start = ( clone $ref_date )->modify( 'last sunday' );
		$today = ( new \DateTime( 'now', wp_timezone() ) )->format( 'Y-m-d' );

		// Index events by date.
		$events_by_date = array();
		foreach ( $events as $occ ) {
			$date = substr( $occ->start_datetime, 0, 10 );
			if ( ! isset( $events_by_date[ $date ] ) ) {
				$events_by_date[ $date ] = array();
			}
			$events_by_date[ $date ][] = $occ;
		}

		ob_start();

		// Day headers with dates.
		echo '<div class="nte-calendar__week-header">';
		echo '<div class="nte-calendar__time-gutter"></div>';
		for ( $i = 0; $i < 7; $i++ ) {
			$date     = ( clone $start )->modify( "+{$i} days" );
			$date_str = $date->format( 'Y-m-d' );
			$is_today = $date_str === $today;
			$class    = 'nte-calendar__day-header' . ( $is_today ? ' nte-calendar__day-header--today' : '' );
			echo '<div class="' . esc_attr( $class ) . '">';
			echo '<span class="nte-calendar__day-name">' . esc_html( $date->format( 'D' ) ) . '</span>';
			echo '<span class="nte-calendar__day-date">' . esc_html( $date->format( 'j' ) ) . '</span>';
			echo '</div>';
		}
		echo '</div>';

		// Time slots (6am - 10pm).
		echo '<div class="nte-calendar__week-body">';
		for ( $hour = 6; $hour <= 22; $hour++ ) {
			echo '<div class="nte-calendar__time-row">';
			echo '<div class="nte-calendar__time-label">' . esc_html( gmdate( 'g A', (int) mktime( $hour, 0 ) ) ) . '</div>';

			for ( $day = 0; $day < 7; $day++ ) {
				$date       = ( clone $start )->modify( "+{$day} days" );
				$date_str   = $date->format( 'Y-m-d' );
				$day_events = $events_by_date[ $date_str ] ?? array();

				echo '<div class="nte-calendar__time-cell" data-date="' . esc_attr( $date_str ) . '" data-hour="' . esc_attr( (string) $hour ) . '">';

				// Show events starting in this hour.
				foreach ( $day_events as $occ ) {
					$event_hour = (int) gmdate( 'G', (int) strtotime( $occ->start_datetime ) );
					if ( $event_hour === $hour ) {
						$event = $occ->get_event();
						$title = $event ? $event->title : __( 'Event', 'nettertech-events' );
						$url   = $event ? $event->get_permalink() : '#';
						$time  = gmdate( 'g:i A', (int) strtotime( $occ->start_datetime ) );
						echo '<a href="' . esc_url( $url ) . '" class="nte-calendar__event nte-calendar__event--week">';
						echo '<span class="nte-calendar__event-time">' . esc_html( $time ) . '</span>';
						echo '<span class="nte-calendar__event-title">' . esc_html( $title ) . '</span>';
						echo '</a>';
					}
				}

				echo '</div>';
			}

			echo '</div>';
		}
		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Render day grid.
	 *
	 * @param array<\NetterTechEvents\Models\Occurrence> $events   Events.
	 * @param \DateTime                                  $ref_date Reference date.
	 * @return string
	 */
	private function render_day_grid( array $events, \DateTime $ref_date ): string {
		$now          = new \DateTime( 'now', wp_timezone() );
		$today        = $now->format( 'Y-m-d' );
		$is_today     = $ref_date->format( 'Y-m-d' ) === $today;
		$current_hour = $is_today ? (int) $now->format( 'G' ) : -1;

		// Index events by hour.
		$events_by_hour = array();
		foreach ( $events as $occ ) {
			$hour = (int) gmdate( 'G', (int) strtotime( $occ->start_datetime ) );
			if ( ! isset( $events_by_hour[ $hour ] ) ) {
				$events_by_hour[ $hour ] = array();
			}
			$events_by_hour[ $hour ][] = $occ;
		}

		ob_start();

		echo '<div class="nte-calendar__day-grid">';

		// Time slots (6am - 10pm).
		for ( $hour = 6; $hour <= 22; $hour++ ) {
			$is_current = $hour === $current_hour;
			$class      = 'nte-calendar__time-slot' . ( $is_current ? ' nte-calendar__time-slot--current' : '' );

			echo '<div class="' . esc_attr( $class ) . '">';
			echo '<div class="nte-calendar__time-label">' . esc_html( gmdate( 'g A', (int) mktime( $hour, 0 ) ) ) . '</div>';
			echo '<div class="nte-calendar__time-content">';

			$hour_events = $events_by_hour[ $hour ] ?? array();
			foreach ( $hour_events as $occ ) {
				$event = $occ->get_event();
				$title = $event ? $event->title : __( 'Event', 'nettertech-events' );
				$url   = $event ? $event->get_permalink() : '#';
				$time  = gmdate( 'g:i A', (int) strtotime( $occ->start_datetime ) );
				$venue = $event ? $event->venue_name : '';

				echo '<a href="' . esc_url( $url ) . '" class="nte-calendar__event nte-calendar__event--day">';
				echo '<span class="nte-calendar__event-time">' . esc_html( $time ) . '</span>';
				echo '<span class="nte-calendar__event-title">' . esc_html( $title ) . '</span>';
				if ( $venue ) {
					echo '<span class="nte-calendar__event-venue">' . esc_html( $venue ) . '</span>';
				}
				echo '</a>';
			}

			echo '</div>';
			echo '</div>';
		}

		echo '</div>';

		return (string) ob_get_clean();
	}
}
