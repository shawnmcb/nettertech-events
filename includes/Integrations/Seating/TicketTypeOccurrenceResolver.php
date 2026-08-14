<?php
/**
 * Ticket-type-to-occurrence resolver for the Seating add-on.
 *
 * @package NetterTechEvents\Integrations\Seating
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\Seating;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;

/**
 * Resolves a ticket type to its occurrence for the Seating add-on.
 *
 * The Seating add-on asks "which occurrence does this ticket-type product sell
 * seats for?" through the nettertech_events_seating_resolve_occurrence filter
 * (SeatingProductIntegration). The add-on has no way to answer this itself —
 * the ticket-type → occurrence linkage lives in core's ticket-type model — so
 * without a core listener the filter returns its default of 0 and seat
 * selection never renders. This resolver answers from core's data model.
 *
 * Only occurrence-scoped ticket types carry an occurrence assignment; event
 * (series pass) and template scopes have a null occurrence_id, for which this
 * resolver yields to the incoming value. Series passes are already skipped by
 * the add-on before this filter runs.
 *
 * Registered unconditionally: a filter on a hook the Seating add-on never
 * applies costs nothing when the add-on is absent.
 *
 * @since 1.1.1
 */
class TicketTypeOccurrenceResolver {

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Constructor.
	 *
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 */
	public function __construct( TicketTypeRepositoryInterface $ticket_type_repo ) {
		$this->ticket_type_repo = $ticket_type_repo;
	}

	/**
	 * Register the resolution filter.
	 *
	 * Priority 5 to mirror the occurrence→space resolver; there is no other
	 * subscriber to yield to, but an explicit early priority keeps the two
	 * seating seams consistent.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'nettertech_events_seating_resolve_occurrence', array( $this, 'resolve_occurrence' ), 5, 2 );
	}

	/**
	 * Resolve the occurrence for a ticket type from core's ticket-type model.
	 *
	 * @param int $occurrence_id Occurrence ID resolved so far (0 = unresolved).
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int Occurrence ID, or the incoming value when no assignment exists.
	 */
	public function resolve_occurrence( int $occurrence_id, int $ticket_type_id ): int {
		if ( $occurrence_id > 0 || $ticket_type_id <= 0 ) {
			return $occurrence_id;
		}

		$ticket_type = $this->ticket_type_repo->find( $ticket_type_id );
		if ( null === $ticket_type || null === $ticket_type->occurrence_id ) {
			return $occurrence_id;
		}

		return $ticket_type->occurrence_id;
	}
}
