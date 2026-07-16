<?php
/**
 * Occurrence-to-space resolver for the Seating add-on.
 *
 * @package NetterTechEvents\Integrations\Seating
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\Seating;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;

/**
 * Resolves an occurrence to its event's assigned space.
 *
 * The Seating add-on asks "which space does this occurrence happen in?"
 * through the nettertech_events_seating_resolve_space /
 * nettertech_events_seating_occurrence_space_id filters. Its own fallback
 * (SpaceResolver, priority 10) can only answer when the site has exactly one
 * seating-enabled space with a published map, and bails on multi-space sites.
 *
 * This resolver answers from the event's explicit assignment instead:
 * occurrence → parent event → events.space_id (set via the Space/Venue
 * metabox on the event editor). It hooks at priority 5 so an explicit
 * assignment wins; when no space is assigned it returns 0 and the add-on's
 * single-space fallback still applies.
 *
 * Registered unconditionally: filters on hooks the Seating add-on never
 * applies cost nothing when the add-on is absent.
 *
 * @since 1.1.1
 */
class OccurrenceSpaceResolver {

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Constructor.
	 *
	 * @param OccurrenceRepositoryInterface $occurrence_repo Occurrence repository.
	 * @param EventRepositoryInterface      $event_repo      Event repository.
	 */
	public function __construct(
		OccurrenceRepositoryInterface $occurrence_repo,
		EventRepositoryInterface $event_repo
	) {
		$this->occurrence_repo = $occurrence_repo;
		$this->event_repo      = $event_repo;
	}

	/**
	 * Register the resolution filters.
	 *
	 * Priority 5: explicit event-level assignment beats the Seating add-on's
	 * single-space heuristic fallback (priority 10, yields to non-zero input).
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'nettertech_events_seating_resolve_space', array( $this, 'resolve_space' ), 5, 2 );
		add_filter( 'nettertech_events_seating_occurrence_space_id', array( $this, 'resolve_space' ), 5, 2 );
	}

	/**
	 * Resolve the space for an occurrence from its event's assignment.
	 *
	 * @param int $space_id      Space ID resolved so far (0 = unresolved).
	 * @param int $occurrence_id Occurrence ID.
	 * @return int Space ID, or the incoming value when no assignment exists.
	 */
	public function resolve_space( int $space_id, int $occurrence_id ): int {
		if ( $space_id > 0 || $occurrence_id <= 0 ) {
			return $space_id;
		}

		$occurrence = $this->occurrence_repo->find( $occurrence_id );
		if ( null === $occurrence ) {
			return $space_id;
		}

		$event = $this->event_repo->find( $occurrence->event_id );
		if ( null === $event || null === $event->space_id ) {
			return $space_id;
		}

		return $event->space_id;
	}
}
