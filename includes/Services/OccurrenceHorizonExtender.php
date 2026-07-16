<?php
/**
 * Occurrence Horizon Extender service.
 *
 * Periodically extends occurrence horizons for recurring events via WP-Cron,
 * ensuring events always have occurrences generated into the future.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Extends occurrence horizons for recurring events via daily cron.
 *
 * When a recurring event's latest occurrence falls within the extension
 * threshold (default 30 days from now), new occurrences are generated
 * to push the horizon out to the configured limit.
 *
 * @since 2.2.0
 */
class OccurrenceHorizonExtender {

	/**
	 * Default number of events to process per cron batch.
	 *
	 * @var int
	 */
	public const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Default threshold in days — events whose latest occurrence is
	 * within this many days of today will have their horizons extended.
	 *
	 * @var int
	 */
	public const DEFAULT_THRESHOLD_DAYS = 30;

	/**
	 * Hook name for continuation batches.
	 *
	 * @var string
	 */
	public const BATCH_CONTINUATION_HOOK = 'nettertech_events_extend_horizons_batch';

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
	 * Recurrence service.
	 *
	 * @var RecurrenceService
	 */
	private RecurrenceService $recurrence_service;

	/**
	 * Constructor.
	 *
	 * @param EventRepositoryInterface      $event_repo         Event repository.
	 * @param OccurrenceRepositoryInterface $occurrence_repo    Occurrence repository.
	 * @param RecurrenceService             $recurrence_service Recurrence service.
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		RecurrenceService $recurrence_service
	) {
		$this->event_repo         = $event_repo;
		$this->occurrence_repo    = $occurrence_repo;
		$this->recurrence_service = $recurrence_service;
	}

	/**
	 * Register the cron hook listener.
	 *
	 * Called during plugin init to wire the daily cron job.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nettertech_events_generate_occurrences', array( $this, 'extend_horizons' ) );
		add_action( self::BATCH_CONTINUATION_HOOK, array( $this, 'process_batch' ) );
	}

	/**
	 * Main entry point — called by the daily cron job.
	 *
	 * Fetches all recurring events and processes them in batches.
	 *
	 * @return void
	 */
	public function extend_horizons(): void {
		$this->process_batch( 0 );
	}

	/**
	 * Process a batch of recurring events starting at the given offset.
	 *
	 * @param int $offset The offset into the recurring events list.
	 * @return array{extended: int, skipped: int, errors: int} Results for this batch.
	 */
	public function process_batch( int $offset = 0 ): array {
		$batch_size = $this->get_batch_size();
		$result     = array(
			'extended' => 0,
			'skipped'  => 0,
			'errors'   => 0,
		);

		$events = $this->event_repo->all(
			array(
				'event_type' => 'recurring',
				'status'     => 'published',
				'limit'      => $batch_size,
				'offset'     => $offset,
			)
		);

		if ( empty( $events ) ) {
			return $result;
		}

		$threshold = $this->get_threshold();

		foreach ( $events as $event ) {
			$event_result = $this->maybe_extend_event( $event, $threshold );

			if ( 'extended' === $event_result ) {
				++$result['extended'];
			} elseif ( 'error' === $event_result ) {
				++$result['errors'];
			} else {
				++$result['skipped'];
			}
		}

		// Schedule next batch if we got a full batch (more events may exist).
		if ( count( $events ) >= $batch_size ) {
			$next_offset = $offset + $batch_size;
			if ( ! wp_next_scheduled( self::BATCH_CONTINUATION_HOOK, array( $next_offset ) ) ) {
				wp_schedule_single_event( time() + 60, self::BATCH_CONTINUATION_HOOK, array( $next_offset ) );
			}
		}

		return $result;
	}

	/**
	 * Check a single event and extend its occurrences if needed.
	 *
	 * @param Event              $event     The recurring event.
	 * @param \DateTimeImmutable $threshold The threshold date.
	 * @return string 'extended', 'skipped', or 'error'.
	 */
	private function maybe_extend_event( Event $event, \DateTimeImmutable $threshold ): string {
		if ( null === $event->id || empty( $event->recurrence_rule ) ) {
			return 'skipped';
		}

		// Get the latest occurrence for this event.
		$occurrences = $this->occurrence_repo->for_event(
			$event->id,
			array(
				'orderby' => 'start_datetime',
				'order'   => 'DESC',
				'limit'   => 1,
			)
		);

		if ( empty( $occurrences ) ) {
			// No occurrences at all — generate from now.
			return $this->generate_from_now( $event );
		}

		$latest = $occurrences[0];

		// Parse the latest occurrence's start datetime.
		try {
			$latest_date = new \DateTimeImmutable( $latest->start_datetime );
		} catch ( \Exception $e ) {
			DebugLogger::log(
				sprintf( 'Failed to parse latest occurrence date for event #%d: %s', $event->id, $e->getMessage() ),
				'OccurrenceHorizonExtender'
			);
			return 'error';
		}

		// Policy: only extend events that are still actively scheduled into the
		// future. If every existing occurrence is in the past, treat the series
		// as concluded — even if the recurrence rule lacks an explicit UNTIL.
		// Prevents migrated stale series from sprouting phantom future
		// occurrences just because their RRULE is unbounded.
		$now = new \DateTimeImmutable();
		if ( $latest_date < $now ) {
			return 'dormant';
		}

		// Skip if latest occurrence is beyond the threshold.
		if ( $latest_date > $threshold ) {
			return 'skipped';
		}

		// Get the earliest occurrence to determine the original start/end times.
		$earliest_occurrences = $this->occurrence_repo->for_event(
			$event->id,
			array(
				'orderby' => 'start_datetime',
				'order'   => 'ASC',
				'limit'   => 1,
			)
		);

		if ( empty( $earliest_occurrences ) ) {
			return 'error';
		}

		$earliest = $earliest_occurrences[0];

		try {
			$start_date = new \DateTimeImmutable( $earliest->start_datetime );
			$end_date   = new \DateTimeImmutable( $earliest->end_datetime );
		} catch ( \Exception $e ) {
			DebugLogger::log(
				sprintf( 'Failed to parse occurrence dates for event #%d: %s', $event->id, $e->getMessage() ),
				'OccurrenceHorizonExtender'
			);
			return 'error';
		}

		$gen_result = $this->recurrence_service->regenerate_future_occurrences(
			$event,
			$start_date,
			$end_date,
			$event->recurrence_rule
		);

		if ( ! empty( $gen_result['errors'] ) ) {
			DebugLogger::log(
				sprintf(
					'Errors extending occurrences for event #%d "%s": %s',
					$event->id,
					$event->title,
					implode( ', ', $gen_result['errors'] )
				),
				'OccurrenceHorizonExtender'
			);
			return 'error';
		}

		if ( $gen_result['generated'] > 0 ) {
			DebugLogger::log(
				sprintf(
					'Extended occurrences for event #%d "%s": generated %d new occurrences',
					$event->id,
					$event->title,
					$gen_result['generated']
				),
				'OccurrenceHorizonExtender'
			);
		}

		return 'extended';
	}

	/**
	 * Generate occurrences for an event that has none.
	 *
	 * @param Event $event The recurring event.
	 * @return string 'extended' or 'error'.
	 */
	private function generate_from_now( Event $event ): string {
		if ( null === $event->id || empty( $event->recurrence_rule ) ) {
			return 'error';
		}

		$now    = new \DateTimeImmutable();
		$end    = $now->modify( '+1 hour' );
		$result = $this->recurrence_service->generate_occurrences(
			$event,
			$now,
			$end,
			$event->recurrence_rule,
			false
		);

		if ( ! empty( $result['errors'] ) ) {
			DebugLogger::log(
				sprintf(
					'Errors generating initial occurrences for event #%d "%s": %s',
					$event->id,
					$event->title,
					implode( ', ', $result['errors'] )
				),
				'OccurrenceHorizonExtender'
			);
			return 'error';
		}

		if ( $result['generated'] > 0 ) {
			DebugLogger::log(
				sprintf(
					'Generated %d initial occurrences for event #%d "%s"',
					$result['generated'],
					$event->id,
					$event->title
				),
				'OccurrenceHorizonExtender'
			);
		}

		return 'extended';
	}

	/**
	 * Get the threshold date — events whose latest occurrence is before
	 * this date will have their horizons extended.
	 *
	 * @return \DateTimeImmutable
	 */
	private function get_threshold(): \DateTimeImmutable {
		$days = self::DEFAULT_THRESHOLD_DAYS;

		/**
		 * Filters the number of days before horizon expiry that triggers extension.
		 *
		 * @since 1.0.2
		 *
		 * @param int $days Default 30 days.
		 */
		$days = (int) apply_filters( 'nettertech_events_horizon_extension_threshold', $days );

		return ( new \DateTimeImmutable() )->modify( "+{$days} days" );
	}

	/**
	 * Get the batch size for processing events.
	 *
	 * @return int
	 */
	private function get_batch_size(): int {
		/**
		 * Filters the batch size for horizon extension processing.
		 *
		 * @since 1.0.2
		 *
		 * @param int $batch_size Default 50 events per batch.
		 */
		return (int) apply_filters( 'nettertech_events_horizon_extension_batch_size', self::DEFAULT_BATCH_SIZE );
	}
}
