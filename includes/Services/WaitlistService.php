<?php
/**
 * Waitlist Service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\WaitlistRepositoryInterface;
use NetterTechEvents\Contracts\WaitlistServiceInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\WaitlistEntry;

/**
 * Business logic for waitlist operations.
 *
 * Handles join, leave, promote, and notification workflows.
 * Fires action hooks for Pro plugin extension.
 *
 * @since 1.4.0
 * @api
 */
class WaitlistService implements WaitlistServiceInterface {

	/**
	 * Waitlist repository.
	 *
	 * @var WaitlistRepositoryInterface
	 */
	private WaitlistRepositoryInterface $repo;

	/**
	 * Constructor.
	 *
	 * @param WaitlistRepositoryInterface $repo Waitlist repository.
	 */
	public function __construct( WaitlistRepositoryInterface $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Join the waitlist for an occurrence.
	 *
	 * @since 1.4.0
	 *
	 * @param int         $occurrence_id  Occurrence ID.
	 * @param string      $email          Email address.
	 * @param string      $name           Name.
	 * @param string|null $phone          Phone number (optional).
	 * @param int|null    $ticket_type_id Ticket type ID (optional).
	 * @return WaitlistEntry The created entry.
	 * @throws ValidationException If already on waitlist.
	 */
	public function join(
		int $occurrence_id,
		string $email,
		string $name,
		?string $phone = null,
		?int $ticket_type_id = null
	): WaitlistEntry {
		// Check for existing entry.
		$existing = $this->repo->find_by_email( $email, $occurrence_id );
		if ( $existing ) {
			if ( $existing->is_waiting() || 'notified' === $existing->status ) {
				throw ValidationException::fromErrors(
					array( esc_html__( 'This email is already on the waitlist for this event.', 'nettertech-events' ) )
				);
			}

			// Re-join: reuse existing row for removed/expired/converted entries.
			$existing->name           = sanitize_text_field( $name );
			$existing->phone          = $phone ? sanitize_text_field( $phone ) : null;
			$existing->ticket_type_id = $ticket_type_id;
			$existing->position       = $this->repo->get_next_position( $occurrence_id );
			$existing->status         = 'waiting';
			$existing->notified_at    = null;

			$entry = $this->repo->save( $existing );
		} else {
			$entry                 = new WaitlistEntry();
			$entry->occurrence_id  = $occurrence_id;
			$entry->email          = sanitize_email( $email );
			$entry->name           = sanitize_text_field( $name );
			$entry->phone          = $phone ? sanitize_text_field( $phone ) : null;
			$entry->ticket_type_id = $ticket_type_id;
			$entry->position       = $this->repo->get_next_position( $occurrence_id );
			$entry->status         = 'waiting';

			$entry = $this->repo->save( $entry );
		}

		/**
		 * Fires when someone joins the waitlist.
		 *
		 * @since 1.0.2
		 *
		 * @param WaitlistEntry $entry         The waitlist entry.
		 * @param int           $occurrence_id The occurrence ID.
		 */
		do_action( 'nettertech_events_waitlist_joined', $entry, $occurrence_id );

		return $entry;
	}

	/**
	 * Leave the waitlist.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $email         Email address.
	 * @return bool True if removed.
	 */
	public function leave( int $occurrence_id, string $email ): bool {
		$entry = $this->repo->find_by_email( $email, $occurrence_id );
		if ( ! $entry || null === $entry->id ) {
			return false;
		}

		$this->repo->update_status( $entry->id, 'removed' );

		/**
		 * Fires when someone leaves the waitlist.
		 *
		 * @since 1.0.2
		 *
		 * @param WaitlistEntry $entry         The waitlist entry.
		 * @param int           $occurrence_id The occurrence ID.
		 */
		do_action( 'nettertech_events_waitlist_left', $entry, $occurrence_id );

		return true;
	}

	/**
	 * Get a user's position in the waitlist.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $email         Email address.
	 * @return int|null Position (1-based) or null if not on waitlist.
	 */
	public function get_position( int $occurrence_id, string $email ): ?int {
		$entry = $this->repo->find_by_email( $email, $occurrence_id );
		if ( ! $entry || ! $entry->is_waiting() ) {
			return null;
		}

		return $entry->position;
	}

	/**
	 * Get the waitlist queue for an occurrence.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $status        Status filter (default: 'waiting').
	 * @return array<WaitlistEntry>
	 */
	public function get_queue( int $occurrence_id, string $status = 'waiting' ): array {
		return $this->repo->for_occurrence( $occurrence_id, $status );
	}

	/**
	 * Get the count of waiting entries for an occurrence.
	 *
	 * @since 1.4.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int
	 */
	public function get_count( int $occurrence_id ): int {
		return $this->repo->count_for_occurrence( $occurrence_id );
	}

	/**
	 * Promote the next person in the waitlist queue.
	 *
	 * Marks the entry as 'notified' so Pro can send the notification email.
	 *
	 * @since 1.4.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return WaitlistEntry|null The promoted entry, or null if queue is empty.
	 */
	public function promote_next( int $occurrence_id ): ?WaitlistEntry {
		$entry = $this->repo->get_next_in_queue( $occurrence_id );
		if ( ! $entry || null === $entry->id ) {
			return null;
		}

		$this->repo->update_status( $entry->id, 'notified' );
		$entry->status = 'notified';

		/**
		 * Fires when a waitlist entry is promoted (next in line).
		 *
		 * Pro plugin hooks here to send notification emails.
		 *
		 * @since 1.0.2
		 *
		 * @param WaitlistEntry $entry         The promoted entry.
		 * @param int           $occurrence_id The occurrence ID.
		 */
		do_action( 'nettertech_events_waitlist_promoted', $entry, $occurrence_id );

		return $entry;
	}

	/**
	 * Mark an entry as converted (purchased a ticket).
	 *
	 * @since 1.4.0
	 *
	 * @param int $entry_id Entry ID.
	 * @return bool True on success.
	 */
	public function mark_converted( int $entry_id ): bool {
		return $this->repo->update_status( $entry_id, 'converted' );
	}

	/**
	 * Check if an email is on the active waitlist for an occurrence.
	 *
	 * @since 1.4.0
	 *
	 * @param string $email         Email address.
	 * @param int    $occurrence_id Occurrence ID.
	 * @return bool
	 */
	public function is_on_waitlist( string $email, int $occurrence_id ): bool {
		$entry = $this->repo->find_by_email( $email, $occurrence_id );

		return $entry && $entry->is_waiting();
	}
}
