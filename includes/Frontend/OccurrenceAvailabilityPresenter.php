<?php
/**
 * Occurrence availability presenter.
 *
 * @package NetterTechEvents\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Models\TicketType;

/**
 * Answers "should this occurrence display as sold out?" for listing surfaces
 * (cards, grids, carousels, series pages).
 *
 * Sold out is a per-type verdict from CapacityService::get_capacity_summary(),
 * whose effective availability comes from get_available_count() — the same
 * arithmetic the checkout uses, so the badge and the till cannot disagree
 * about pending holds, buffer stock, seating overrides, or the shared house.
 * The denormalized stock_status column must never drive this: it does not
 * know about the house, so a tier with an untouched allotment in a full room
 * would read as available.
 *
 * An occurrence with no on-sale ticket types is never sold out — that covers
 * both unticketed events (NTE-009 bug class: absence of tickets must not read
 * as exhaustion) and events whose sale windows are all closed ("sales closed"
 * is a different state than "sold out").
 *
 * Base computes verdicts only when an extension opts in via
 * Hooks::CARDS_NEED_AVAILABILITY and renders no availability UI of its own;
 * visible labels are an extension concern (e.g. Pro's availability badges).
 *
 * @since 1.4.0
 */
final class OccurrenceAvailabilityPresenter {

	/**
	 * Constructor.
	 *
	 * @param CapacityServiceInterface      $capacity_service Capacity service.
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 */
	public function __construct(
		private readonly CapacityServiceInterface $capacity_service,
		private readonly TicketTypeRepositoryInterface $ticket_type_repo
	) {
	}

	/**
	 * Whether a set of on-sale ticket types is collectively sold out.
	 *
	 * @param array<TicketType> $on_sale_ticket_types The occurrence's on-sale ticket types.
	 * @return bool True when every saleable type has zero effective availability.
	 */
	public function is_sold_out( array $on_sale_ticket_types ): bool {
		$has_saleable_type = false;

		foreach ( $on_sale_ticket_types as $ticket_type ) {
			if ( ! $ticket_type instanceof TicketType || null === $ticket_type->id ) {
				continue;
			}

			$has_saleable_type = true;
			$summary           = $this->capacity_service->get_capacity_summary( (int) $ticket_type->id );

			// One buyable type (including any unlimited type) means not sold out.
			if ( empty( $summary['is_sold_out'] ) ) {
				return false;
			}
		}

		return $has_saleable_type;
	}

	/**
	 * Whether an occurrence is sold out, resolving its on-sale ticket types.
	 *
	 * Convenience path for direct template callers without a prefetched map;
	 * listing controllers should prefetch via get_on_sale_for_occurrences()
	 * and call is_sold_out() instead to avoid per-card queries.
	 *
	 * @api Extension entry point (e.g. Pro availability labels); no base
	 *      caller, so static analysis cannot see its consumers.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool True when every saleable type has zero effective availability.
	 */
	public function is_occurrence_sold_out( int $occurrence_id ): bool {
		if ( $occurrence_id <= 0 ) {
			return false;
		}

		return $this->is_sold_out( $this->ticket_type_repo->get_on_sale_for_occurrence( $occurrence_id ) );
	}
}
