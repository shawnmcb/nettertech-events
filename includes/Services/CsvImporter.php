<?php
/**
 * CSV Importer.
 *
 * Orchestrates the CSV import pipeline: parse, map, validate, create.
 * Follows the same result pattern as ICalService::import_ical().
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Category;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\Organizer;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Orchestrates CSV event import: parse -> map -> validate -> create.
 *
 * @since 2.1.0
 */
class CsvImporter {

	/**
	 * CSV parser.
	 *
	 * @var CsvParser
	 */
	private CsvParser $parser;

	/**
	 * Column mapper.
	 *
	 * @var CsvColumnMapper
	 */
	private CsvColumnMapper $mapper;

	/**
	 * Row validator.
	 *
	 * @var CsvValidator
	 */
	private CsvValidator $validator;

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
	 * Organizer repository.
	 *
	 * @var OrganizerRepositoryInterface
	 */
	private OrganizerRepositoryInterface $organizer_repo;

	/**
	 * Category repository.
	 *
	 * @var CategoryRepositoryInterface
	 */
	private CategoryRepositoryInterface $category_repo;

	/**
	 * Constructor.
	 *
	 * @param CsvParser                     $parser          CSV parser.
	 * @param CsvColumnMapper               $mapper          Column mapper.
	 * @param CsvValidator                  $validator       Row validator.
	 * @param EventRepositoryInterface      $event_repo      Event repository.
	 * @param OccurrenceRepositoryInterface $occurrence_repo Occurrence repository.
	 * @param OrganizerRepositoryInterface  $organizer_repo  Organizer repository.
	 * @param CategoryRepositoryInterface   $category_repo   Category repository.
	 */
	public function __construct(
		CsvParser $parser,
		CsvColumnMapper $mapper,
		CsvValidator $validator,
		EventRepositoryInterface $event_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		OrganizerRepositoryInterface $organizer_repo,
		CategoryRepositoryInterface $category_repo
	) {
		$this->parser          = $parser;
		$this->mapper          = $mapper;
		$this->validator       = $validator;
		$this->event_repo      = $event_repo;
		$this->occurrence_repo = $occurrence_repo;
		$this->organizer_repo  = $organizer_repo;
		$this->category_repo   = $category_repo;
	}

	/**
	 * Run a dry-run import: parse, map, and validate without creating any records.
	 *
	 * @param string                $file_path File path.
	 * @param array<string, string> $mapping   Column mapping (CSV header => NTE field).
	 * @return array{valid_count: int, error_count: int, errors: array<int, array<string>>, preview: array<int, array<string, string>>}
	 */
	public function dry_run( string $file_path, array $mapping ): array {
		$parsed      = $this->parser->parse( $file_path );
		$mapped_rows = $this->map_rows( $parsed['rows'], $mapping );
		$validation  = $this->validator->validate( $mapped_rows );

		return array(
			'valid_count' => count( $validation['valid'] ),
			'error_count' => count( $validation['errors'] ),
			'errors'      => $validation['errors'],
			'preview'     => array_slice( $validation['valid'], 0, 5, true ),
		);
	}

	/**
	 * Execute the full import pipeline.
	 *
	 * @param string                $file_path File path.
	 * @param array<string, string> $mapping   Column mapping (CSV header => NTE field).
	 * @param array<string, mixed>  $options   Import options.
	 * @return array{imported: int, skipped: int, errors: array<string>, events: array<Event>}
	 */
	public function import( string $file_path, array $mapping, array $options = array() ): array {
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

		$parsed      = $this->parser->parse( $file_path );
		$mapped_rows = $this->map_rows( $parsed['rows'], $mapping );
		$validation  = $this->validator->validate( $mapped_rows );

		// Report validation errors.
		foreach ( $validation['errors'] as $row_num => $row_errors ) {
			foreach ( $row_errors as $error ) {
				$results['errors'][] = sprintf( 'Row %d: %s', $row_num, $error );
			}
		}

		// Import valid rows.
		foreach ( $validation['valid'] as $row_num => $row ) {
			try {
				$event = $this->create_event_from_row( $row, $options );

				if ( null === $event ) {
					++$results['skipped'];
					continue;
				}

				++$results['imported'];
				$results['events'][] = $event;
			} catch ( \Exception $e ) {
				$results['errors'][] = sprintf(
					'Row %d: Failed to import "%s": %s',
					$row_num,
					$row['title'] ?? 'Unknown',
					$e->getMessage()
				);
			}
		}

		return $results;
	}

	/**
	 * Map all rows using the provided column mapping.
	 *
	 * @param array<array<string, string>> $rows    Raw rows.
	 * @param array<string, string>        $mapping Column mapping.
	 * @return array<array<string, string>> Mapped rows.
	 */
	private function map_rows( array $rows, array $mapping ): array {
		return array_map(
			fn( array $row ) => $this->mapper->apply( $row, $mapping ),
			$rows
		);
	}

	/**
	 * Create an event from a mapped and validated row.
	 *
	 * Follows the same pattern as ICalService::create_event_from_data().
	 *
	 * @param array<string, string> $row     Mapped row data.
	 * @param array<string, mixed>  $options Import options.
	 * @return Event|null Created event or null if skipped.
	 */
	private function create_event_from_row( array $row, array $options ): ?Event {
		// Check for duplicate by title + date.
		if ( $options['skip_duplicates'] ) {
			$start_dt = $this->build_start_datetime( $row );
			$existing = $this->find_duplicate( $row['title'], $start_dt );
			if ( $existing ) {
				return null;
			}
		}

		$event              = new Event();
		$event->title       = sanitize_text_field( $row['title'] );
		$event->description = wp_kses_post( $row['description'] ?? '' );
		$event->status      = EventStatus::tryFrom( sanitize_text_field( $row['status'] ?? $options['status'] ) ) ?? EventStatus::DRAFT;
		$event->event_type  = ! empty( $row['recurrence_rule'] ) ? 'recurring' : 'single';
		$event->slug        = $this->event_repo->generate_unique_slug( $row['title'] );

		// Venue.
		if ( ! empty( $row['venue_name'] ) ) {
			$event->venue_name = sanitize_text_field( $row['venue_name'] );
		}
		if ( ! empty( $row['venue_address'] ) ) {
			$event->venue_address = sanitize_text_field( $row['venue_address'] );
		}

		// Recurrence rule.
		if ( ! empty( $row['recurrence_rule'] ) ) {
			$rrule = $row['recurrence_rule'];
			if ( str_starts_with( strtoupper( $rrule ), 'RRULE:' ) ) {
				$rrule = substr( $rrule, 6 );
			}
			$event->recurrence_rule = sanitize_text_field( $rrule );
		}

		// Save event.
		$event = $this->event_repo->save( $event );
		if ( ! $event || ! $event->id ) { // @phpstan-ignore booleanNot.alwaysFalse (false positive; value depends on runtime state, not statically determinable)
			return null;
		}

		// Create initial occurrence.
		$start_datetime = $this->build_start_datetime( $row );
		$end_datetime   = $this->build_end_datetime( $row, $start_datetime );

		$occurrence                 = new Occurrence();
		$occurrence->event_id       = $event->id;
		$occurrence->start_datetime = $start_datetime->format( 'Y-m-d H:i:s' );
		$occurrence->end_datetime   = $end_datetime->format( 'Y-m-d H:i:s' );
		$occurrence->all_day        = $this->is_all_day( $row );
		$occurrence->status         = 'scheduled';

		$this->occurrence_repo->save( $occurrence );

		// Associate categories.
		if ( ! empty( $row['category'] ) ) {
			$this->sync_categories( $event->id, $row['category'] );
		}

		// Associate organizer.
		if ( ! empty( $row['organizer_name'] ) ) {
			$this->sync_organizer( $event->id, $row );
		}

		return $event;
	}

	/**
	 * Build the start datetime from row data.
	 *
	 * @param array<string, string> $row Mapped row.
	 * @return \DateTimeImmutable Start datetime.
	 * @throws \InvalidArgumentException When the start date is unparseable (reported per-row by import()).
	 */
	private function build_start_datetime( array $row ): \DateTimeImmutable {
		$date = $this->validator->parse_date( $row['start_date'] );
		if ( null === $date ) {
			// Rows are pre-validated; an unparseable date here is reported per-row by import().
			throw new \InvalidArgumentException( 'Unrecognized start date format.' );
		}
		$time = ! empty( $row['start_time'] ) ? $this->validator->parse_time( $row['start_time'] ) : null;

		return $this->validator->build_datetime( $date, $time );
	}

	/**
	 * Build the end datetime from row data, defaulting to start + 1 hour.
	 *
	 * @param array<string, string> $row            Mapped row.
	 * @param \DateTimeImmutable    $start_datetime Start datetime (fallback base).
	 * @return \DateTimeImmutable End datetime.
	 * @throws \InvalidArgumentException When a date is unparseable (reported per-row by import()).
	 */
	private function build_end_datetime( array $row, \DateTimeImmutable $start_datetime ): \DateTimeImmutable {
		if ( ! empty( $row['end_date'] ) ) {
			$date = $this->validator->parse_date( $row['end_date'] );
			if ( null === $date ) {
				throw new \InvalidArgumentException( 'Unrecognized end date format.' );
			}
			$time = ! empty( $row['end_time'] ) ? $this->validator->parse_time( $row['end_time'] ) : null;

			return $this->validator->build_datetime( $date, $time );
		}

		if ( ! empty( $row['end_time'] ) ) {
			$time = $this->validator->parse_time( $row['end_time'] );
			$date = $this->validator->parse_date( $row['start_date'] );
			if ( null === $date ) {
				throw new \InvalidArgumentException( 'Unrecognized start date format.' );
			}

			return $this->validator->build_datetime( $date, $time );
		}

		// Default: start + 1 hour (or all-day to end of day).
		if ( $this->is_all_day( $row ) ) {
			return $start_datetime->setTime( 23, 59, 59 );
		}

		return $start_datetime->modify( '+1 hour' );
	}

	/**
	 * Determine if the row represents an all-day event.
	 *
	 * @param array<string, string> $row Mapped row.
	 * @return bool True if all-day.
	 */
	private function is_all_day( array $row ): bool {
		if ( ! empty( $row['all_day'] ) ) {
			return $this->validator->to_bool( $row['all_day'] );
		}

		// Infer: no start_time = all day.
		return empty( $row['start_time'] );
	}

	/**
	 * Find a duplicate event by title and start date.
	 *
	 * @param string             $title    Event title.
	 * @param \DateTimeImmutable $start_dt Start datetime.
	 * @return bool True if duplicate exists.
	 */
	private function find_duplicate( string $title, \DateTimeImmutable $start_dt ): bool {
		$existing = $this->event_repo->find_by_slug( sanitize_title( $title ) );
		if ( ! $existing || null === $existing->id ) {
			return false;
		}

		// Check if any occurrence starts at the same time.
		$occurrences = $this->occurrence_repo->for_event( $existing->id );
		foreach ( $occurrences as $occ ) {
			$occ_start = new \DateTimeImmutable( $occ->start_datetime );
			if ( $occ_start->format( 'Y-m-d H:i' ) === $start_dt->format( 'Y-m-d H:i' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sync category names to the event.
	 *
	 * Handles comma-separated category lists. Creates categories if needed.
	 * Follows ICalService::sync_categories_to_taxonomy() pattern.
	 *
	 * @param int    $event_id       Event ID.
	 * @param string $category_value Comma-separated category names.
	 */
	private function sync_categories( int $event_id, string $category_value ): void {
		$names        = array_map( 'trim', explode( ',', $category_value ) );
		$category_ids = array();

		foreach ( $names as $name ) {
			if ( '' === $name ) {
				continue;
			}

			$slug     = sanitize_title( $name );
			$category = $this->category_repo->find_by_slug( $slug );

			if ( $category && null !== $category->id ) {
				$category_ids[] = $category->id;
			} else {
				$new_cat       = new Category();
				$new_cat->name = $name;
				$new_cat->slug = $slug;

				try {
					$saved = $this->category_repo->save( $new_cat );
					if ( null !== $saved->id ) {
						$category_ids[] = $saved->id;
					}
				} catch ( \RuntimeException $e ) {
					DebugLogger::exception( $e, 'CsvImporter' );
					continue;
				}
			}
		}

		if ( ! empty( $category_ids ) ) {
			$this->category_repo->sync_event_categories( $event_id, $category_ids );
		}
	}

	/**
	 * Find or create an organizer and attach to the event.
	 *
	 * @param int                   $event_id Event ID.
	 * @param array<string, string> $row      Mapped row containing organizer_name and optionally organizer_email.
	 */
	private function sync_organizer( int $event_id, array $row ): void {
		$name = sanitize_text_field( $row['organizer_name'] );
		$slug = sanitize_title( $name );

		$organizer = $this->organizer_repo->find_by_slug( $slug );

		if ( ! $organizer ) {
			$organizer       = new Organizer();
			$organizer->name = $name;
			$organizer->slug = $slug;

			if ( ! empty( $row['organizer_email'] ) ) {
				$organizer->email = sanitize_email( $row['organizer_email'] );
			}

			try {
				$organizer = $this->organizer_repo->save( $organizer );
			} catch ( \RuntimeException $e ) {
				DebugLogger::exception( $e, 'CsvImporter' );
				return;
			}
		}

		if ( null !== $organizer->id ) {
			$this->organizer_repo->attach_to_event( $event_id, $organizer->id, true );
		}
	}
}
