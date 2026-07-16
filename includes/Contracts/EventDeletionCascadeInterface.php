<?php
/**
 * Event Deletion Cascade Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Event;

/**
 * Interface for the service that removes all child data of an event
 * before the event row itself is deleted.
 *
 * @since 1.0.0
 * @api
 */
interface EventDeletionCascadeInterface {

	/**
	 * Remove all data that belongs to the given event.
	 *
	 * Clears pending reservations, then per-occurrence children (tickets,
	 * attendee field values, attendees, waitlist entries, reminder log rows),
	 * then ticket types, occurrences, attendee fields, and taxonomy/organizer
	 * junctions. Must run while the event row and its children still exist.
	 *
	 * @since 1.0.0
	 *
	 * @param Event $event The event whose child data should be removed.
	 * @return void
	 */
	public function cascade( Event $event ): void;

	/**
	 * Hook adapter for nettertech_events_before_delete_event.
	 *
	 * Named method so the listener can be wired without a closure.
	 *
	 * @since 1.1.1
	 *
	 * @param Event $event The event being deleted.
	 * @return void
	 */
	public function on_before_delete_event( Event $event ): void;
}
