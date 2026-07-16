<?php
/**
 * Ticket Repository Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Ticket;

/**
 * Interface for Ticket repository implementations.
 *
 * @since 0.9.0
 * @api
 */
interface TicketRepositoryInterface {

	/**
	 * Find a ticket by ID.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Ticket ID.
	 * @return Ticket|null
	 */
	public function find( int $id ): ?Ticket;

	/**
	 * Find a ticket by code.
	 *
	 * @since 0.9.0
	 *
	 * @param string $code Ticket code (XXXX-XXXX-XXXX-XXXX format).
	 * @return Ticket|null
	 */
	public function find_by_code( string $code ): ?Ticket;

	/**
	 * Find tickets by WooCommerce order ID.
	 *
	 * @since 0.9.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array<Ticket>
	 */
	public function find_by_order( int $order_id ): array;

	/**
	 * Find tickets by attendee ID.
	 *
	 * @since 0.9.0
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return array<Ticket>
	 */
	public function find_by_attendee( int $attendee_id ): array;

	/**
	 * Find tickets for an occurrence.
	 *
	 * @since 0.9.0
	 *
	 * @param int         $occurrence_id Occurrence ID.
	 * @param string|null $status        Optional status filter.
	 * @return array<Ticket>
	 */
	public function find_by_occurrence( int $occurrence_id, ?string $status = null ): array;

	/**
	 * Save a ticket (insert or update).
	 *
	 * @since 0.9.0
	 *
	 * @param Ticket $ticket Ticket to save.
	 * @return Ticket The saved ticket with ID populated.
	 */
	public function save( Ticket $ticket ): Ticket;

	/**
	 * Cancel all tickets for an attendee.
	 *
	 * @since 0.9.0
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return int Number of tickets cancelled.
	 */
	public function cancel_all_tickets_for_attendee( int $attendee_id ): int;

	/**
	 * Cancel a specific number of tickets for an attendee.
	 *
	 * @since 0.9.0
	 *
	 * @param int $attendee_id Attendee ID.
	 * @param int $quantity    Number of tickets to cancel.
	 * @return int Number of tickets actually cancelled.
	 */
	public function cancel_tickets_for_attendee( int $attendee_id, int $quantity ): int;

	/**
	 * Check in a ticket by code.
	 *
	 * @since 0.9.0
	 *
	 * @param string $code Ticket code.
	 * @return bool True if check-in succeeded.
	 */
	public function check_in( string $code ): bool;

	/**
	 * Delete a ticket.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Ticket ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool;

	/**
	 * Count tickets by occurrence and status.
	 *
	 * @since 0.9.0
	 *
	 * @param int         $occurrence_id Occurrence ID.
	 * @param string|null $status        Optional status filter.
	 * @return int
	 */
	public function count_for_occurrence( int $occurrence_id, ?string $status = null ): int;

	/**
	 * Whether an event has any order-linked (paid) attendance.
	 *
	 * True if any ticket OR attendee belonging to one of the event's occurrences
	 * references a WooCommerce order (`wc_order_id > 0`). Pre-delete guard:
	 * deleting such an event would destroy order-linked attendance history.
	 *
	 * @since 1.0.4
	 *
	 * @param int $event_id Event ID.
	 * @return bool True if the event has order-linked tickets or attendees.
	 */
	public function has_paid_attendance( int $event_id ): bool;
}
