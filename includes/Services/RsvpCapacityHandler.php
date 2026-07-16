<?php
/**
 * RSVP capacity handler.
 *
 * Bridges the RSVP submit path to the capacity ledger. RSVPs against free
 * events flow entirely outside WooCommerce, so the WC-driven sold_count
 * increment never fires for them. Without this handler, sold_count never
 * rises on RSVP submits and per-occurrence capacity is silently unenforced.
 *
 * Listens to Hooks::RSVP_SUBMITTED (fired by RSVPFormShortcode after a
 * successful attendee insert) and increments sold_count on the matching
 * free ticket type via CapacityService::reserve_capacity (which also
 * invalidates the capacity cache for the affected occurrence).
 *
 * @package NetterTechEvents\Services
 * @since   3.7.0
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Increments sold_count on the matching free ticket type after a successful RSVP.
 *
 * Fixes NTE-036 (latent contract violation, same class as NTE-008/009/010/017).
 *
 * @since 3.7.0
 */
class RsvpCapacityHandler {

	/**
	 * Capacity service (atomic increment + cache invalidation).
	 *
	 * @var CapacityServiceInterface
	 */
	private CapacityServiceInterface $capacity_service;

	/**
	 * Ticket type repository (lookup free ticket types for the occurrence).
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Constructor.
	 *
	 * @param CapacityServiceInterface      $capacity_service Capacity service.
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 */
	public function __construct(
		CapacityServiceInterface $capacity_service,
		TicketTypeRepositoryInterface $ticket_type_repo
	) {
		$this->capacity_service = $capacity_service;
		$this->ticket_type_repo = $ticket_type_repo;
	}

	/**
	 * Register the RSVP submit listener.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nettertech_events_rsvp_submitted', array( $this, 'handle_rsvp_submitted' ), 5, 2 );
	}

	/**
	 * Handle a successful RSVP submission by incrementing sold_count.
	 *
	 * Resolution order:
	 *   1. If form_data carries an explicit ticket_type_id and that type is
	 *      free with a configured capacity, increment it. Paid types are
	 *      WooCommerce's domain and are skipped to avoid double-counting.
	 *   2. Otherwise, look up free ticket types for the occurrence and
	 *      increment the first one that has a configured (non-null) capacity.
	 *      Unlimited / unconfigured types need no increment.
	 *
	 * Fires earlier (priority 5) than RsvpEmailHandler (priority 10) so the
	 * counter is updated before any side-effects observe it.
	 *
	 * @param int                  $attendee_id Attendee row ID. Unused; required
	 *                                          by the Hooks::RSVP_SUBMITTED contract.
	 * @param array<string, mixed> $form_data   Submitted form data: occurrence_id,
	 *                                          ticket_type_id, email, name, quantity.
	 * @return void
	 */
	public function handle_rsvp_submitted( int $attendee_id, array $form_data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by Hooks::RSVP_SUBMITTED signature.
		$occurrence_id = isset( $form_data['occurrence_id'] ) ? (int) $form_data['occurrence_id'] : 0;
		$quantity      = isset( $form_data['quantity'] ) ? (int) $form_data['quantity'] : 1;

		if ( $occurrence_id <= 0 || $quantity <= 0 ) {
			return;
		}

		try {
			$target = $this->resolve_target_ticket_type( $form_data, $occurrence_id );

			if ( null === $target || null === $target->id ) {
				// No constrained free ticket type — capacity is unconstrained, nothing to bump.
				return;
			}

			$this->capacity_service->reserve_capacity( (int) $target->id, $quantity );
		} catch ( \Throwable $e ) {
			// Capacity bookkeeping must never break the RSVP confirmation flow.
			DebugLogger::exception( $e, 'RsvpCapacityHandler' );
		}
	}

	/**
	 * Resolve which ticket type should receive the increment.
	 *
	 * Returns null when no constrained free ticket type is found, signalling
	 * "no enforcement needed for this submit".
	 *
	 * @param array<string, mixed> $form_data     Submitted form data.
	 * @param int                  $occurrence_id Occurrence ID.
	 * @return TicketType|null
	 */
	private function resolve_target_ticket_type( array $form_data, int $occurrence_id ): ?TicketType {
		$explicit_id = isset( $form_data['ticket_type_id'] ) ? (int) $form_data['ticket_type_id'] : 0;

		if ( $explicit_id > 0 ) {
			$explicit = $this->ticket_type_repo->find( $explicit_id );

			if ( $explicit && $explicit->is_free() && null !== $explicit->capacity ) {
				return $explicit;
			}

			// Explicit type is paid or unconstrained — defer to the WC path or skip.
			return null;
		}

		$ticket_types = $this->ticket_type_repo->for_occurrence( $occurrence_id );

		foreach ( $ticket_types as $ticket_type ) {
			if ( ! $ticket_type instanceof TicketType ) {
				continue;
			}

			if ( ! $ticket_type->is_free() ) {
				continue;
			}

			if ( null === $ticket_type->capacity ) {
				continue;
			}

			return $ticket_type;
		}

		return null;
	}
}
