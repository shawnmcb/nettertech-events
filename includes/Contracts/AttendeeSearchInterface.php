<?php
/**
 * Attendee Search Interface.
 *
 * Defines search and lookup operations for attendees.
 * Part of the segregated AttendeeRepository interface design (ISP compliance).
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Attendee;

/**
 * Interface for attendee search operations.
 *
 * This interface segregates search/lookup functionality from the core repository,
 * following the Interface Segregation Principle. Consumers that only need
 * search operations (CheckInController ticket lookup, RSVPFormShortcode validation)
 * should depend on this interface rather than the full AttendeeRepositoryInterface.
 *
 * @since 0.9.3
 * @api
 */
interface AttendeeSearchInterface {

	/**
	 * Search attendees for an occurrence.
	 *
	 * Searches by name, email, or ticket code.
	 * Used in check-in UI for finding attendees.
	 *
	 * @since 0.9.0
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $query         Search query.
	 * @return array<Attendee>
	 */
	public function search( int $occurrence_id, string $query ): array;

	/**
	 * Find attendees by email for an occurrence.
	 *
	 * Returns all attendees with matching email for the occurrence.
	 * Email comparison is case-insensitive.
	 *
	 * @since 0.9.0
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $email         Email address.
	 * @return array<Attendee>
	 */
	public function find_by_email( int $occurrence_id, string $email ): array;

	/**
	 * Find attendee by ticket code for an occurrence.
	 *
	 * Used for QR code scanning and ticket validation.
	 * Ticket code format: XXXX-XXXX-XXXX-XXXX.
	 *
	 * @since 0.9.0
	 *
	 * @param string $ticket_code   Ticket code (format: XXXX-XXXX-XXXX-XXXX).
	 * @param int    $occurrence_id Occurrence ID.
	 * @return Attendee|null
	 */
	public function find_by_ticket_code( string $ticket_code, int $occurrence_id ): ?Attendee;

	/**
	 * Check if email is already registered for occurrence.
	 *
	 * Used by RSVP form to prevent duplicate registrations.
	 *
	 * @since 0.9.0
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $email         Email address.
	 * @param int    $exclude_id    Attendee ID to exclude (for updates).
	 * @return bool True if already registered.
	 */
	public function email_exists_for_occurrence( int $occurrence_id, string $email, int $exclude_id = 0 ): bool;
}
