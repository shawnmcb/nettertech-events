<?php
/**
 * Event duplication service.
 *
 * Handles the business logic of duplicating an event, including
 * creating a new occurrence and copying ticket types.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;

/**
 * Service for duplicating events with their occurrences and ticket types.
 *
 * Extracted from EventRepository to follow the Single Responsibility
 * Principle — repositories handle persistence, services handle
 * business logic orchestration.
 *
 * @since 0.13.0
 */
class EventDuplicationService {

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
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Constructor.
	 *
	 * @since 0.13.0
	 *
	 * @param EventRepositoryInterface      $event_repo       Event repository.
	 * @param OccurrenceRepositoryInterface $occurrence_repo  Occurrence repository.
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		TicketTypeRepositoryInterface $ticket_type_repo
	) {
		$this->event_repo       = $event_repo;
		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
	}

	/**
	 * Duplicate an event.
	 *
	 * Creates a copy of the event with a new slug and draft status.
	 * Also creates ONE occurrence (dated 1 week from now) and copies
	 * all unique ticket types from the source event's occurrences.
	 *
	 * @since 0.13.0
	 *
	 * @param int $id Event ID to duplicate.
	 * @return Event|null The duplicated event, or null if source not found.
	 * @throws ValidationException If validation fails.
	 * @throws DatabaseException   If save fails.
	 */
	public function duplicate( int $id ): ?Event {
		$source = $this->event_repo->find( $id );

		if ( ! $source ) {
			return null;
		}

		$duplicate = new Event();

		// Copy all content fields.
		$duplicate->title = sprintf(
			/* translators: %s: original event title */
			__( '%s (Copy)', 'nettertech-events' ),
			$source->title
		);
		$duplicate->description       = $source->description;
		$duplicate->excerpt           = $source->excerpt;
		$duplicate->featured_image_id = $source->featured_image_id;
		// The copy gets one date and no rule, which a recurring event cannot be.
		$duplicate->event_type          = 'recurring' === $source->event_type ? 'single' : $source->event_type;
		$duplicate->venue_name          = $source->venue_name;
		$duplicate->venue_address       = $source->venue_address;
		$duplicate->recurrence_rule     = null; // Don't copy recurrence - single occurrence.
		$duplicate->recurrence_end_date = null;
		$duplicate->category_ids        = $source->category_ids;
		$duplicate->tag_ids             = $source->tag_ids;

		// Set to draft for review.
		$duplicate->status = EventStatus::DRAFT;

		// Generate unique slug.
		$duplicate->slug = $this->event_repo->generate_unique_slug( $duplicate->title );

		// Don't copy: id, post_id, series_id, created_at, updated_at.

		/**
		 * Fires before duplicating an event.
		 *
		 * @since 1.0.2
		 *
		 * @param Event $duplicate The new event being created.
		 * @param Event $source    The original event being duplicated.
		 */
		do_action( 'nettertech_events_before_duplicate_event', $duplicate, $source );

		$saved = $this->event_repo->save( $duplicate );

		// Now create an occurrence and copy ticket types.
		$this->duplicate_occurrence_and_tickets( $saved, $source );

		/**
		 * Fires after duplicating an event.
		 *
		 * @since 1.0.2
		 *
		 * @param Event $duplicate The duplicated event.
		 * @param Event $source    The original event.
		 */
		do_action( 'nettertech_events_after_duplicate_event', $saved, $source );

		return $saved;
	}

	/**
	 * Create occurrence and copy ticket types for duplicated event.
	 *
	 * @since 0.13.0
	 *
	 * @param Event $duplicate The new event.
	 * @param Event $source    The original event.
	 * @return void
	 */
	private function duplicate_occurrence_and_tickets( Event $duplicate, Event $source ): void {
		$source_id    = $source->id;
		$duplicate_id = $duplicate->id;
		if ( null === $source_id || null === $duplicate_id ) {
			return;
		}

		// Get all occurrences from source to collect ticket types.
		$source_occurrences = $this->occurrence_repo->for_event( $source_id );

		if ( empty( $source_occurrences ) ) {
			return;
		}

		// Use the first occurrence as a template for timing.
		$template_occurrence = $source_occurrences[0];

		// Calculate new occurrence date (1 week from now, same time).
		$template_start = new \DateTime( $template_occurrence->start_datetime );
		$template_end   = new \DateTime( $template_occurrence->end_datetime );
		$duration       = $template_start->diff( $template_end );

		$new_start = new \DateTime( '+1 week', wp_timezone() );
		$new_start->setTime(
			(int) $template_start->format( 'H' ),
			(int) $template_start->format( 'i' ),
			0
		);
		$new_end = clone $new_start;
		$new_end->add( $duration );

		// Create the new occurrence.
		$new_occurrence                  = new Occurrence();
		$new_occurrence->event_id        = $duplicate_id;
		$new_occurrence->start_datetime  = $new_start->format( 'Y-m-d H:i:s' );
		$new_occurrence->end_datetime    = $new_end->format( 'Y-m-d H:i:s' );
		$new_occurrence->all_day         = $template_occurrence->all_day;
		$new_occurrence->timezone        = $template_occurrence->timezone;
		$new_occurrence->status          = 'scheduled';
		$new_occurrence->capacity        = $template_occurrence->capacity;
		$new_occurrence->sequence_number = 1;

		$saved_occurrence = $this->occurrence_repo->save( $new_occurrence );

		// Collect unique ticket types from all source occurrences.
		// Use batch query to prevent N+1 (single query instead of N queries).
		$occurrence_ids = array();
		foreach ( $source_occurrences as $occ ) {
			if ( null !== $occ->id ) {
				$occurrence_ids[] = $occ->id;
			}
		}
		$all_ticket_types = $this->ticket_type_repo->for_multiple_occurrences( $occurrence_ids );

		// Use name+price as uniqueness key to avoid exact duplicates.
		$unique_tickets = array();
		foreach ( $all_ticket_types as $occ_ticket_types ) {
			foreach ( $occ_ticket_types as $tt ) {
				$key = $tt->name . '|' . $tt->price;
				if ( ! isset( $unique_tickets[ $key ] ) ) {
					$unique_tickets[ $key ] = $tt;
				}
			}
		}

		// Copy each unique ticket type to the new occurrence.
		$sort_order = 0;
		foreach ( $unique_tickets as $source_tt ) {
			$new_tt                  = new TicketType();
			$new_tt->occurrence_id   = $saved_occurrence->id;
			$new_tt->name            = $source_tt->name;
			$new_tt->description     = $source_tt->description;
			$new_tt->price           = $source_tt->price;
			$new_tt->capacity        = $source_tt->capacity;
			$new_tt->sold_count      = 0; // Reset sold count.
			$new_tt->stock_status    = 'in_stock'; // Reset stock status.
			$new_tt->sale_start      = null; // Clear sale windows - user should set new ones.
			$new_tt->sale_end        = null;
			$new_tt->min_per_order   = $source_tt->min_per_order;
			$new_tt->max_per_order   = $source_tt->max_per_order;
			$new_tt->sort_order      = $sort_order++;
			$new_tt->status          = 'draft';
			$new_tt->wc_product_id   = null; // Clear WC links - new products will be created.
			$new_tt->wc_variation_id = null;

			$this->ticket_type_repo->save( $new_tt );
		}
	}
}
