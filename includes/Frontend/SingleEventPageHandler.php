<?php
/**
 * Single event page data handler.
 *
 * Extracts data resolution and business logic from single-event.php
 * template to keep templates focused on presentation.
 *
 * @package NetterTechEvents\Frontend
 * @since   1.1.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Services\LayoutService;

/**
 * Resolves data for the single event page template.
 *
 * @since 1.1.0
 * @api
 */
class SingleEventPageHandler {

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
	 * Layout service.
	 *
	 * @var LayoutService
	 */
	private LayoutService $layout_service;

	/**
	 * Constructor.
	 *
	 * @param OccurrenceRepositoryInterface $occurrence_repo  Occurrence repository.
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 * @param LayoutService                 $layout_service   Layout service.
	 */
	public function __construct(
		OccurrenceRepositoryInterface $occurrence_repo,
		TicketTypeRepositoryInterface $ticket_type_repo,
		LayoutService $layout_service
	) {
		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->layout_service   = $layout_service;
	}

	/**
	 * Resolve all data needed for the single event page.
	 *
	 * @param Event           $event      Event model.
	 * @param Occurrence|null $occurrence Current occurrence (null for event-level view).
	 * @return array<string, mixed> Template context.
	 */
	public function resolve( Event $event, ?Occurrence $occurrence = null ): array {
		// Siblings for occurrence navigation.
		$siblings      = null;
		$sibling_count = 0;
		if ( $occurrence && null !== $occurrence->id ) {
			$siblings      = $this->occurrence_repo->get_siblings( $occurrence->id );
			$sibling_count = count( $siblings['all'] );
		}

		// Upcoming occurrences (for event-level view).
		$occurrences = array();
		if ( ! $occurrence && null !== $event->id ) {
			$occurrences = $this->occurrence_repo->get_upcoming_by_event( $event->id, 10 );
		}

		// Featured image (occurrence override or event).
		$featured_image_id = $occurrence
			? $occurrence->get_featured_image_id()
			: $event->featured_image_id;

		// Display title (occurrence override or event).
		$display_title = $occurrence
			? $occurrence->get_title()
			: $event->title;

		// Date/time subtitle and target occurrence for price display.
		$date_subtitle = '';
		$target_occ    = $occurrence;

		if ( $occurrence ) {
			$date_subtitle = $this->format_occurrence_date( $occurrence );
		} elseif ( ! empty( $occurrences ) ) {
			$target_occ    = $occurrences[0];
			$date_subtitle = $this->format_occurrence_date( $target_occ );
		}

		// Price display from ticket types.
		$price_display = $target_occ ? $this->resolve_price_display( $target_occ ) : '';

		// Layout configuration with fallback chain.
		$layout_config = $this->layout_service->get_preview_config();
		if ( ! $layout_config ) {
			$layout_config = $this->layout_service->get_layout( $event->layout_config, $event );
		}

		$visible_components = $this->layout_service->get_visible_components( $layout_config );

		// Cancelled flag applies only to a specific (occurrence-level) view —
		// event-level views never show a series-wide cancellation banner.
		$is_cancelled = null !== $occurrence && $occurrence->is_cancelled();

		return array(
			'event'                 => $event,
			'occurrence'            => $occurrence,
			'occurrences'           => $occurrences,
			'featured_image_id'     => $featured_image_id,
			'image_vertical_anchor' => $event->image_vertical_anchor,
			'display_title'         => $display_title,
			'date_subtitle'         => $date_subtitle,
			'price_display'         => $price_display,
			'target_occ'            => $target_occ,
			'siblings'              => $siblings,
			'sibling_count'         => $sibling_count,
			'is_cancelled'          => $is_cancelled,
			'visible_components'    => $visible_components,
		);
	}

	/**
	 * Format an occurrence date for display.
	 *
	 * @param Occurrence $occurrence Occurrence.
	 * @return string Formatted date string.
	 */
	private function format_occurrence_date( Occurrence $occurrence ): string {
		$date = $occurrence->get_start_date( 'l, F j, Y' );
		if ( ! $occurrence->all_day ) {
			$date .= ' · ' . $occurrence->get_formatted_time();
		}
		return $date;
	}

	/**
	 * Resolve price display string for an occurrence.
	 *
	 * @param Occurrence $occurrence Target occurrence.
	 * @return string Price display string.
	 */
	private function resolve_price_display( Occurrence $occurrence ): string {
		if ( null === $occurrence->id ) {
			return '';
		}

		$ticket_types = $this->ticket_type_repo->get_on_sale_for_occurrence( $occurrence->id );

		if ( empty( $ticket_types ) ) {
			return '';
		}

		// Shared with event cards and the REST payload (NTE-215).
		return OccurrenceAvailabilityPresenter::price_range( $ticket_types )['label'];
	}
}
