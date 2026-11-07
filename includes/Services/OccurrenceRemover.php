<?php
/**
 * Occurrence remover.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Models\TicketType;

/**
 * Removes a date together with the ticket types filed under it, and never removes a date that
 * someone holds a place on.
 *
 * A ticket type points at its date by ID. Deleting the date alone leaves the ticket type, and its
 * WooCommerce product, attached to a date that no longer exists: still published, never shown.
 *
 * @since 1.5.4
 * @api
 */
class OccurrenceRemover {

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
	 * Attendee repository.
	 *
	 * @var AttendeeRepositoryInterface
	 */
	private AttendeeRepositoryInterface $attendee_repo;

	/**
	 * Ticket repository.
	 *
	 * @var TicketRepositoryInterface
	 */
	private TicketRepositoryInterface $ticket_repo;

	/**
	 * Constructor.
	 *
	 * @param OccurrenceRepositoryInterface $occurrence_repo  Occurrence repository.
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 * @param AttendeeRepositoryInterface   $attendee_repo    Attendee repository.
	 * @param TicketRepositoryInterface     $ticket_repo      Ticket repository.
	 */
	public function __construct(
		OccurrenceRepositoryInterface $occurrence_repo,
		TicketTypeRepositoryInterface $ticket_type_repo,
		AttendeeRepositoryInterface $attendee_repo,
		TicketRepositoryInterface $ticket_repo
	) {
		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->attendee_repo    = $attendee_repo;
		$this->ticket_repo      = $ticket_repo;
	}

	/**
	 * Whether anyone holds a place on a date: an attendee of any status, a ticket, or a ticket type
	 * of the date's own that has sold.
	 *
	 * A series-pass sale puts an attendee on every date, so a date covered only by a pass holder
	 * counts as held.
	 *
	 * @since 1.5.4
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool
	 */
	public function has_holders( int $occurrence_id ): bool {
		if ( $this->attendee_repo->count_for_occurrence( $occurrence_id, null ) > 0 ) {
			return true;
		}

		if ( $this->ticket_repo->count_for_occurrence( $occurrence_id ) > 0 ) {
			return true;
		}

		foreach ( $this->ticket_type_repo->for_occurrence( $occurrence_id ) as $ticket_type ) {
			if ( $occurrence_id === $ticket_type->occurrence_id && $ticket_type->sold_count > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete a date, then the ticket types filed under it.
	 *
	 * @since 1.5.4
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool False when the date has holders or the delete failed; nothing is removed then.
	 */
	public function remove( int $occurrence_id ): bool {
		if ( $this->has_holders( $occurrence_id ) ) {
			return false;
		}

		if ( ! $this->occurrence_repo->delete( $occurrence_id ) ) {
			return false;
		}

		$this->remove_ticket_types( $occurrence_id );

		return true;
	}

	/**
	 * Delete one ticket type, and with it its WooCommerce product, unless it has sold.
	 *
	 * @since 1.5.4
	 *
	 * @param TicketType $ticket_type The ticket type.
	 * @return bool False when it has sold or the delete failed.
	 */
	public function remove_ticket_type( TicketType $ticket_type ): bool {
		if ( null === $ticket_type->id || $ticket_type->sold_count > 0 ) {
			return false;
		}

		return $this->ticket_type_repo->delete( $ticket_type->id );
	}

	/**
	 * Delete the ticket types filed under a date, and with them their WooCommerce products.
	 *
	 * Works for a date that no longer exists, so ticket types stranded by an earlier deletion can
	 * be cleared the same way. Products follow the ticket-type deletion contract: deleted, or
	 * trashed when any order refers to them.
	 *
	 * @since 1.5.4
	 *
	 * @param int $occurrence_id Occurrence ID, of a date that may already be gone.
	 * @return int Ticket types deleted.
	 */
	public function remove_ticket_types( int $occurrence_id ): int {
		return $this->ticket_type_repo->delete_for_occurrence( $occurrence_id );
	}
}
