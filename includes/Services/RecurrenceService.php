<?php
/**
 * Recurrence service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Exceptions\RRuleException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\RecurrenceRule;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Orchestrates recurrence parsing, generation, and persistence.
 *
 * This is the main entry point for working with recurring events.
 * It coordinates between the parser, generator, and repository.
 *
 * @since 0.8.0
 * @api
 */
class RecurrenceService {

	/**
	 * RRULE parser.
	 *
	 * @var RRuleParser
	 */
	private RRuleParser $parser;

	/**
	 * Occurrence generator.
	 *
	 * @var OccurrenceGenerator
	 */
	private OccurrenceGenerator $generator;

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
	 * Attendee repository for attendee-protection checks.
	 *
	 * @var AttendeeRepositoryInterface
	 */
	private AttendeeRepositoryInterface $attendee_repo;

	/**
	 * Removes dates with the ticket types filed under them (resolved on first use when not injected).
	 *
	 * @var OccurrenceRemover|null
	 */
	private ?OccurrenceRemover $remover;

	/**
	 * Constructor.
	 *
	 * @since 1.5.4 Added `$remover`.
	 *
	 * @param RRuleParser                   $parser           RRULE parser.
	 * @param OccurrenceGenerator           $generator        Occurrence generator.
	 * @param OccurrenceRepositoryInterface $occurrence_repo  Occurrence repository.
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 * @param AttendeeRepositoryInterface   $attendee_repo    Attendee repository.
	 * @param OccurrenceRemover|null        $remover          Removes dates with their ticket types.
	 */
	public function __construct(
		RRuleParser $parser,
		OccurrenceGenerator $generator,
		OccurrenceRepositoryInterface $occurrence_repo,
		TicketTypeRepositoryInterface $ticket_type_repo,
		AttendeeRepositoryInterface $attendee_repo,
		?OccurrenceRemover $remover = null
	) {
		$this->parser           = $parser;
		$this->generator        = $generator;
		$this->occurrence_repo  = $occurrence_repo;
		$this->ticket_type_repo = $ticket_type_repo;
		$this->attendee_repo    = $attendee_repo;
		$this->remover          = $remover;
	}

	/**
	 * The remover every date deletion here goes through.
	 *
	 * @return OccurrenceRemover
	 * @throws \LogicException When the container has no remover to resolve.
	 */
	private function remover(): OccurrenceRemover {
		if ( null === $this->remover ) {
			$remover = ServiceRegistry::get( OccurrenceRemover::class );
			if ( ! $remover instanceof OccurrenceRemover ) {
				throw new \LogicException( 'OccurrenceRemover is not registered.' );
			}
			$this->remover = $remover;
		}

		return $this->remover;
	}

	/**
	 * Generate and save occurrences for a recurring event.
	 *
	 * This is the main method called when saving a recurring event.
	 * When $replace is true, occurrences with confirmed attendees are
	 * protected from deletion to prevent orphaning ticket purchases.
	 *
	 * @param Event              $event      The event.
	 * @param \DateTimeInterface $start_date First occurrence start date/time.
	 * @param \DateTimeInterface $end_date   First occurrence end date/time.
	 * @param string             $rrule      RRULE string.
	 * @param bool               $replace    Whether to replace existing occurrences.
	 * @return array{generated: int, saved: int, protected: int, errors: array<string>}
	 */
	public function generate_occurrences(
		Event $event,
		\DateTimeInterface $start_date,
		\DateTimeInterface $end_date,
		string $rrule,
		bool $replace = true
	): array {
		$result = array(
			'generated' => 0,
			'saved'     => 0,
			'protected' => 0,
			'errors'    => array(),
		);

		$event_id = $event->id;
		if ( null === $event_id ) {
			$result['errors'][] = __( 'Event must be saved before occurrences can be generated.', 'nettertech-events' );
			return $result;
		}

		// Validate RRULE.
		$errors = $this->parser->validate( $rrule );
		if ( ! empty( $errors ) ) {
			$result['errors'] = $errors;
			return $result;
		}

		// Parse RRULE.
		try {
			$rule = $this->parser->parse( $rrule );
		} catch ( RRuleException $e ) {
			$result['errors'][] = $e->getMessage();
			return $result;
		}

		// Generate occurrences.
		$occurrences = $this->generator->generate(
			$event,
			$start_date,
			$end_date,
			$rule
		);

		$result['generated'] = count( $occurrences );

		if ( empty( $occurrences ) ) {
			$result['errors'][] = __( 'No occurrences generated. Check your recurrence rule and date range.', 'nettertech-events' );
			return $result;
		}

		// Delete existing occurrences if replacing, protecting those with attendees.
		if ( $replace ) {
			$result['protected'] = $this->delete_unprotected_occurrences_for_event( $event_id );

			// Skip generated slots already held by a surviving (protected) row:
			// cancelled occurrences (EXDATE exclusions) and in-place overrides
			// (NTE-077). Without this, the generator recreates a fresh scheduled
			// row at the same slot, duplicating the occurrence and defeating the
			// exclusion/override.
			$occurrences = $this->remove_occurrences_colliding_with_survivors( $event_id, $occurrences );
		}

		// Save new occurrences.
		$result['saved'] = $this->occurrence_repo->save_batch( $occurrences );

		// Apply ticket templates to new occurrences.
		$this->apply_templates_to_occurrences( $event, $occurrences );

		/**
		 * Fires after occurrences are generated for a recurring event.
		 *
		 * @param Event              $event       The event.
		 * @param array<Occurrence>  $occurrences Generated occurrences.
		 * @param RecurrenceRule     $rule        The recurrence rule.
		 */
		do_action( 'nettertech_events_occurrences_generated', $event, $occurrences, $rule );

		return $result;
	}

	/**
	 * Extend a recurring event's dates past its last one.
	 *
	 * @deprecated 1.5.4 Use extend_horizon(). This no longer deletes or recreates any date.
	 *
	 * @param Event              $event      The event.
	 * @param \DateTimeInterface $start_date First occurrence start date/time.
	 * @param \DateTimeInterface $end_date   First occurrence end date/time.
	 * @param string             $rrule      RRULE string.
	 * @return array{generated: int, saved: int, deleted: int, protected: int, errors: array<string>}
	 */
	public function regenerate_future_occurrences(
		Event $event,
		\DateTimeInterface $start_date,
		\DateTimeInterface $end_date,
		string $rrule
	): array {
		$result = $this->extend_horizon( $event, $start_date, $end_date, $rrule );

		return array(
			'generated' => $result['generated'],
			'saved'     => $result['saved'],
			'deleted'   => 0,
			'protected' => 0,
			'errors'    => $result['errors'],
		);
	}

	/**
	 * Add the pattern's dates that fall after the event's last generated date, and give them the
	 * event's ticket templates.
	 *
	 * Add-only: no existing date is deleted, moved or recreated, so every date keeps its ID, its
	 * tickets and its per-date values. The pattern is expanded from its original first date, so a
	 * rule without BYDAY keeps its weekday and COUNT keeps meaning "this many in all". The boundary
	 * is the later of the last slot the pattern already produced and the site's wall-clock now.
	 *
	 * @since 1.5.4
	 *
	 * @param Event              $event      The event.
	 * @param \DateTimeInterface $start_date First date's start (the pattern's anchor).
	 * @param \DateTimeInterface $end_date   First date's end.
	 * @param string             $rrule      RRULE string.
	 * @return array{generated: int, saved: int, tickets: int, errors: array<string>} Slots past the boundary, dates added, ticket types created on them, errors.
	 */
	public function extend_horizon(
		Event $event,
		\DateTimeInterface $start_date,
		\DateTimeInterface $end_date,
		string $rrule
	): array {
		$result = array(
			'generated' => 0,
			'saved'     => 0,
			'tickets'   => 0,
			'errors'    => array(),
		);

		$event_id = $event->id;
		if ( null === $event_id ) {
			$result['errors'][] = __( 'Event must be saved before occurrences can be generated.', 'nettertech-events' );
			return $result;
		}

		try {
			$rule = $this->parser->parse( $rrule );
		} catch ( RRuleException $e ) {
			$result['errors'][] = $e->getMessage();
			return $result;
		}

		$existing = $this->occurrence_repo->for_event( $event_id, array( 'limit' => PHP_INT_MAX ) );

		// Site wall-clock, the frame occurrence slots are stored in.
		$boundary  = new \DateTimeImmutable( current_time( 'mysql' ) );
		$last_slot = $this->last_generated_slot( $existing );
		if ( null !== $last_slot && $last_slot >= $boundary ) {
			$boundary = $last_slot->modify( '+1 second' );
		}

		$occurrences = $this->generator->generate( $event, $start_date, $end_date, $rule, null, $boundary );

		$result['generated'] = count( $occurrences );

		$occurrences = $this->remove_occurrences_colliding_with_survivors( $event_id, $occurrences, $existing );

		$result['saved']   = $this->occurrence_repo->save_batch( $occurrences );
		$result['tickets'] = $this->apply_templates_to_occurrences( $event, $occurrences );

		return $result;
	}

	/**
	 * The latest slot the pattern has already produced for an event.
	 *
	 * A moved date counts at the slot it was generated at, so moving it later does not hide the
	 * slots in between. A date added by hand was never a slot of the pattern and does not count.
	 *
	 * @param array<Occurrence> $occurrences The event's dates.
	 * @return \DateTimeImmutable|null Null when the pattern has produced no date.
	 */
	private function last_generated_slot( array $occurrences ): ?\DateTimeImmutable {
		$latest = null;

		foreach ( $occurrences as $occurrence ) {
			$slot = ( null !== $occurrence->origin_start_datetime && '' !== $occurrence->origin_start_datetime )
				? $occurrence->origin_start_datetime
				: ( $occurrence->is_override ? null : $occurrence->start_datetime );

			if ( null !== $slot && ( null === $latest || $slot > $latest ) ) {
				$latest = $slot;
			}
		}

		return null === $latest ? null : new \DateTimeImmutable( $latest );
	}

	/**
	 * Remove generated occurrences whose slot is already held by a surviving row.
	 *
	 * After unprotected occurrences are deleted, the rows that remain are the
	 * protected ones: occurrences with attendees, cancelled occurrences (EXDATE
	 * exclusions), and in-place overrides (NTE-077). The generator produces a
	 * fresh scheduled occurrence for every slot in the rule, so without this
	 * filter a survivor's slot would be re-created as a duplicate row, defeating
	 * the exclusion/override. Slot identity is datetime only: a survivor holds
	 * its current start_datetime and, for a moved override, the origin slot it
	 * was generated at (origin_start_datetime), so the generator neither
	 * duplicates the moved row nor resurrects its origin slot (NTE-177 /
	 * FR-011). Sequence numbers are deliberately not consulted: fresh rows are
	 * renumbered from the current anchor, so matching them against survivors'
	 * historical numbers silently discarded legitimate new occurrences — the
	 * NTE-182 first-occurrence loss.
	 *
	 * @param int                    $event_id    Event ID.
	 * @param array<Occurrence>      $occurrences Freshly generated occurrences.
	 * @param array<Occurrence>|null $survivors   The event's dates, when the caller already has them.
	 * @return array<Occurrence> Occurrences whose slots are not already taken.
	 */
	private function remove_occurrences_colliding_with_survivors( int $event_id, array $occurrences, ?array $survivors = null ): array {
		$survivors ??= $this->occurrence_repo->for_event( $event_id, array( 'limit' => PHP_INT_MAX ) );

		if ( empty( $survivors ) ) {
			return $occurrences;
		}

		$taken_slots = array();
		foreach ( $survivors as $survivor ) {
			$taken_slots[ $survivor->start_datetime ] = true;
			if ( null !== $survivor->origin_start_datetime && '' !== $survivor->origin_start_datetime ) {
				$taken_slots[ $survivor->origin_start_datetime ] = true;
			}
		}

		return array_values(
			array_filter(
				$occurrences,
				static fn( Occurrence $occurrence ) => ! isset( $taken_slots[ $occurrence->start_datetime ] )
			)
		);
	}

	/**
	 * Whether an occurrence has operator-configured (non-template-derived) tiers bound to it.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool
	 */
	private function has_direct_ticket_types( int $occurrence_id ): bool {
		foreach ( $this->ticket_type_repo->for_occurrence( $occurrence_id, array( 'status' => null ) ) as $ticket_type ) {
			if ( null === $ticket_type->template_id && null !== $ticket_type->occurrence_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Collapse a recurring event down to one of its dates.
	 *
	 * Before this existed, flipping the event-type dropdown to "single" did something much worse
	 * than failing: no transition was detected at all, so the save fell through to
	 * create_single_occurrence(), which took the *earliest* occurrence — past dates included —
	 * overwrote its start and end in place with whatever was in the date metabox, and deleted
	 * nothing. Every other date stayed in the table, still `scheduled`, still on sale on the front
	 * end, and simultaneously invisible in the admin (the Upcoming Dates box only renders for a
	 * recurring event). Sold seats were silently moved to a different day.
	 *
	 * So: the operator says which date survives, and it survives **as it is** — its own start and
	 * end, not the metabox's. The rest are deleted.
	 *
	 * A date that has **attendees** is never deleted to make this happen. If one is in the way the
	 * whole conversion is refused and the offending dates are named, because the operator has to
	 * decide what happens to those buyers — cancel and refund, or keep the event recurring. Silently
	 * destroying a sold date is not a choice this code gets to make.
	 *
	 * @since 1.1.2
	 *
	 * @param Event    $event               The event, already saved as 'single'.
	 * @param int|null $keep_occurrence_id  The date to keep. Defaults to the earliest.
	 * @return array{kept: int|null, deleted: int}
	 * @throws ValidationException When a date that would be removed still has attendees.
	 */
	public function convert_to_single( Event $event, ?int $keep_occurrence_id = null ): array {
		$event_id = $event->id;
		if ( null === $event_id ) {
			return array(
				'kept'    => null,
				'deleted' => 0,
			);
		}

		$plan = $this->plan_conversion_to_single( $event_id, $keep_occurrence_id );

		if ( null === $plan['survivor'] ) {
			return array(
				'kept'    => null,
				'deleted' => 0,
			);
		}

		$this->refuse_if_sold( $plan['doomed'] );

		$deleted = 0;
		foreach ( $plan['doomed'] as $occurrence ) {
			if ( null !== $occurrence->id && $this->remover()->remove( $occurrence->id ) ) {
				++$deleted;
			}
		}

		return array(
			'kept'    => $plan['survivor']->id,
			'deleted' => $deleted,
		);
	}

	/**
	 * Refuse a conversion that would destroy sold dates — *before* anything has been written.
	 *
	 * The caller must ask this before it saves the event row, not after. The event is saved as
	 * `single` with its recurrence rule nulled; if the refusal came afterwards, a rejected
	 * conversion would leave behind an event that is no longer recurring, has no rule, and still
	 * has all its dates — a state nothing else in the plugin knows how to read.
	 *
	 * @since 1.1.2
	 *
	 * @param int      $event_id           The event about to be converted.
	 * @param int|null $keep_occurrence_id The date the operator chose to keep.
	 * @return void
	 * @throws ValidationException When a date that would be removed still has attendees.
	 */
	public function assert_convertible_to_single( int $event_id, ?int $keep_occurrence_id = null ): void {
		$plan = $this->plan_conversion_to_single( $event_id, $keep_occurrence_id );

		if ( null === $plan['survivor'] ) {
			return;
		}

		$this->refuse_if_sold( $plan['doomed'] );
	}

	/**
	 * Work out which date would survive a conversion and which would go.
	 *
	 * @param int      $event_id           The event.
	 * @param int|null $keep_occurrence_id The operator's choice, if any.
	 * @return array{survivor: Occurrence|null, doomed: array<Occurrence>}
	 */
	private function plan_conversion_to_single( int $event_id, ?int $keep_occurrence_id ): array {
		// Earliest first, so the default survivor is the first date — which is what an operator
		// means by "keep the first one". The limit is explicit and above the generator's ceiling
		// (MAX_OCCURRENCES = 365): for_event() interpolates it straight into LIMIT, so a 0 here
		// would return no rows at all and we would "convert" by keeping the wrong date and
		// deleting nothing.
		$occurrences = $this->occurrence_repo->for_event( $event_id, array( 'limit' => 1000 ) );

		if ( empty( $occurrences ) ) {
			return array(
				'survivor' => null,
				'doomed'   => array(),
			);
		}

		$survivor = $this->choose_survivor( $occurrences, $keep_occurrence_id );
		$doomed   = array();

		foreach ( $occurrences as $occurrence ) {
			if ( null !== $occurrence->id && $occurrence->id !== $survivor->id ) {
				$doomed[] = $occurrence;
			}
		}

		return array(
			'survivor' => $survivor,
			'doomed'   => $doomed,
		);
	}

	/**
	 * Pick the date that survives a conversion.
	 *
	 * The operator's choice, when they made one and it belongs to this event. Otherwise the
	 * earliest — the list arrives ordered by start.
	 *
	 * @param array<Occurrence> $occurrences        The event's dates, earliest first.
	 * @param int|null          $keep_occurrence_id The operator's choice, if any.
	 * @return Occurrence
	 */
	private function choose_survivor( array $occurrences, ?int $keep_occurrence_id ): Occurrence {
		if ( null !== $keep_occurrence_id ) {
			foreach ( $occurrences as $occurrence ) {
				if ( $occurrence->id === $keep_occurrence_id ) {
					return $occurrence;
				}
			}
		}

		return $occurrences[0];
	}

	/**
	 * Refuse the conversion if it would delete a date somebody has bought a ticket to.
	 *
	 * Named dates, not a count: "3 dates have attendees" leaves the operator hunting. They need to
	 * know *which* so they can go and deal with them.
	 *
	 * @param array<Occurrence> $doomed The dates that would be removed.
	 * @return void
	 * @throws ValidationException When anyone holds a place on any of them (OccurrenceRemover::has_holders()).
	 */
	private function refuse_if_sold( array $doomed ): void {
		$sold = array();

		foreach ( $doomed as $occurrence ) {
			if ( null === $occurrence->id ) {
				continue;
			}

			if ( $this->remover()->has_holders( $occurrence->id ) ) {
				$attendees = $this->attendee_repo->count_for_occurrence( $occurrence->id, null );
				do_action( 'nettertech_events_occurrence_deletion_blocked', $occurrence->id, $attendees );

				$sold[] = sprintf(
					/* translators: 1: occurrence date, 2: number of attendees. */
					__( '%1$s (%2$d attending)', 'nettertech-events' ),
					$occurrence->get_start()->format( 'M j, Y g:i a' ),
					$attendees
				);
			}
		}

		if ( empty( $sold ) ) {
			return;
		}

		throw ValidationException::fromErrors(
			array(
				sprintf(
					/* translators: %s: comma-separated list of dates with attendee counts. */
					esc_html__( 'This event cannot become a single date, because these dates have already sold tickets: %s. Cancel and refund them first, or leave the event recurring.', 'nettertech-events' ),
					esc_html( implode( '; ', $sold ) )
				),
			)
		);
	}

	/**
	 * Create or update a single occurrence for a non-recurring event.
	 *
	 * Updates the date the editor displayed: the posted `$target_occurrence_id` when it is a live
	 * date of this event, else PrimaryOccurrence::find(). A new row is created only when the event
	 * has no live date; inserting one beside an existing date duplicates it. The updated row
	 * becomes the event's own date, so its hand-added flag is cleared.
	 *
	 * @param Event              $event                The event.
	 * @param \DateTimeInterface $start_date           Start date/time.
	 * @param \DateTimeInterface $end_date             End date/time.
	 * @param bool               $all_day              Whether it's an all-day event.
	 * @param int                $target_occurrence_id The date the editor displayed; 0 when the form posted none.
	 * @return Occurrence|null
	 */
	public function create_single_occurrence(
		Event $event,
		\DateTimeInterface $start_date,
		\DateTimeInterface $end_date,
		bool $all_day = false,
		int $target_occurrence_id = 0
	): ?Occurrence {
		$event_id = $event->id;
		if ( null === $event_id ) {
			return null;
		}

		$occurrence = $this->find_update_target( $event_id, $target_occurrence_id );

		if ( null === $occurrence ) {
			$occurrence           = new Occurrence();
			$occurrence->event_id = $event_id;
			$occurrence->status   = 'scheduled';
		}

		$occurrence->is_override    = false;
		$occurrence->start_datetime = $start_date->format( 'Y-m-d H:i:s' );
		$occurrence->end_datetime   = $end_date->format( 'Y-m-d H:i:s' );
		$occurrence->all_day        = $all_day;

		try {
			return $this->occurrence_repo->save( $occurrence );
		} catch ( \RuntimeException $e ) {
			DebugLogger::exception( $e, 'RecurrenceService' );
			return null;
		}
	}

	/**
	 * The existing date a non-recurring event's save should update, if any.
	 *
	 * @param int $event_id             Event id.
	 * @param int $target_occurrence_id The date the editor displayed; 0 when none was posted.
	 * @return Occurrence|null
	 */
	private function find_update_target( int $event_id, int $target_occurrence_id ): ?Occurrence {
		if ( $target_occurrence_id > 0 ) {
			$posted = $this->occurrence_repo->find( $target_occurrence_id );
			if ( null !== $posted && $posted->event_id === $event_id && ! $posted->is_cancelled() && 'completed' !== $posted->status ) {
				return $posted;
			}
		}

		// The list query behind PrimaryOccurrence leaves out the long per-date columns; the row is
		// saved back, so read it whole.
		$primary = PrimaryOccurrence::find( $this->occurrence_repo, $event_id );

		return null !== $primary && null !== $primary->id ? $this->occurrence_repo->find( $primary->id ) : null;
	}

	/**
	 * Parse an RRULE string.
	 *
	 * @param string $rrule RRULE string.
	 * @return RecurrenceRule|null Null on failure.
	 */
	public function parse_rule( string $rrule ): ?RecurrenceRule {
		return $this->parser->try_parse( $rrule );
	}

	/**
	 * Everything the generator reads from a schedule, in a form two schedules can be compared by.
	 *
	 * Equal fingerprints generate the same dates, so a save whose schedule matches the stored one
	 * has nothing to regenerate. Each entry is an input of OccurrenceGenerator::generate(): the
	 * seed's start (date, time of day, weekday, day of month), its end (the duration every date
	 * gets), and every rule part the expansion reads. An input missing here would stop a real
	 * change to it from regenerating the dates.
	 *
	 * @since 1.5.4
	 *
	 * @param string             $rrule RRULE string.
	 * @param \DateTimeInterface $start First date's start.
	 * @param \DateTimeInterface $end   First date's end.
	 * @return array<string, mixed>|null Null when the rule does not parse, which no stored schedule can equal.
	 */
	public function schedule_fingerprint( string $rrule, \DateTimeInterface $start, \DateTimeInterface $end ): ?array {
		$rule = $this->parser->try_parse( $rrule );
		if ( null === $rule ) {
			return null;
		}

		return array(
			'start'        => $start->format( 'Y-m-d H:i' ),
			'end'          => $end->format( 'Y-m-d H:i' ),
			'freq'         => $rule->freq,
			'interval'     => $rule->interval,
			'count'        => $rule->count,
			'until'        => $rule->until?->format( 'Y-m-d H:i:s' ),
			'by_day'       => $rule->by_day,
			'by_set_pos'   => $rule->by_set_pos,
			'by_month_day' => $rule->by_month_day,
			'by_month'     => $rule->by_month,
			'wkst'         => $rule->wkst,
		);
	}

	/**
	 * The fingerprint of the schedule an event has stored: its rule, seeded from the row the
	 * editor shows as the first date.
	 *
	 * Read it before the save overwrites the event's rule.
	 *
	 * @since 1.5.4
	 *
	 * @param Event $event The event as stored.
	 * @return array<string, mixed>|null Null when the event has no stored schedule to compare with.
	 */
	public function stored_schedule_fingerprint( Event $event ): ?array {
		$rrule = (string) $event->recurrence_rule;
		if ( null === $event->id || ! $event->is_recurring() || '' === $rrule ) {
			return null;
		}

		$seed = PrimaryOccurrence::pattern_seed( $this->occurrence_repo, $event->id );
		if ( null === $seed ) {
			return null;
		}

		return $this->schedule_fingerprint(
			$rrule,
			new \DateTimeImmutable( $seed->start_datetime ),
			new \DateTimeImmutable( $seed->end_datetime )
		);
	}

	/**
	 * Validate an RRULE string.
	 *
	 * @param string $rrule RRULE string.
	 * @return array<string> Validation errors.
	 */
	public function validate_rule( string $rrule ): array {
		return $this->parser->validate( $rrule );
	}

	/**
	 * Build an RRULE string from components.
	 *
	 * Helper for UI to construct rules.
	 *
	 * @param array<string, mixed> $components RRULE components.
	 * @return string RRULE string.
	 */
	public function build_rule( array $components ): string {
		return $this->parser->build( $components );
	}

	/**
	 * Get human-readable description of an RRULE.
	 *
	 * @param string $rrule RRULE string.
	 * @return string Description or empty on failure.
	 */
	public function describe_rule( string $rrule ): string {
		$rule = $this->parser->try_parse( $rrule );
		return $rule ? $rule->get_description() : '';
	}

	/**
	 * Get occurrence count for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int
	 */
	public function count_occurrences( int $event_id ): int {
		return $this->occurrence_repo->count_for_event( $event_id );
	}

	/**
	 * Get upcoming occurrences for an event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $limit    Number to return.
	 * @return array<Occurrence>
	 */
	public function upcoming_for_event( int $event_id, int $limit = 10 ): array {
		return $this->occurrence_repo->for_event(
			$event_id,
			array(
				'upcoming' => true,
				'limit'    => $limit,
			)
		);
	}

	/**
	 * Cancel a specific occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return bool
	 */
	public function cancel_occurrence( int $occurrence_id ): bool {
		return $this->occurrence_repo->update_status( $occurrence_id, 'cancelled' );
	}

	/**
	 * Reschedule a specific occurrence.
	 *
	 * @param int                $occurrence_id Occurrence ID.
	 * @param \DateTimeInterface $new_start     New start date/time.
	 * @param \DateTimeInterface $new_end       New end date/time.
	 * @return Occurrence|null
	 */
	public function reschedule_occurrence(
		int $occurrence_id,
		\DateTimeInterface $new_start,
		\DateTimeInterface $new_end
	): ?Occurrence {
		$occurrence = $this->occurrence_repo->find( $occurrence_id );

		if ( ! $occurrence ) {
			return null;
		}

		$occurrence->start_datetime = $new_start->format( 'Y-m-d H:i:s' );
		$occurrence->end_datetime   = $new_end->format( 'Y-m-d H:i:s' );
		$occurrence->is_rescheduled = true;
		$occurrence->status         = 'rescheduled';

		try {
			return $this->occurrence_repo->save( $occurrence );
		} catch ( \RuntimeException $e ) {
			DebugLogger::exception( $e, 'RecurrenceService' );
			return null;
		}
	}

	/**
	 * Get common recurrence presets for UI.
	 *
	 * @return array<string, array{label: string, rrule: string}>
	 */
	public static function get_presets(): array {
		return array(
			'daily'        => array(
				'label' => __( 'Daily', 'nettertech-events' ),
				'rrule' => 'FREQ=DAILY',
			),
			'weekly'       => array(
				'label' => __( 'Weekly', 'nettertech-events' ),
				'rrule' => 'FREQ=WEEKLY',
			),
			'biweekly'     => array(
				'label' => __( 'Every 2 weeks', 'nettertech-events' ),
				'rrule' => 'FREQ=WEEKLY;INTERVAL=2',
			),
			'monthly'      => array(
				'label' => __( 'Monthly', 'nettertech-events' ),
				'rrule' => 'FREQ=MONTHLY',
			),
			'yearly'       => array(
				'label' => __( 'Yearly', 'nettertech-events' ),
				'rrule' => 'FREQ=YEARLY',
			),
			'weekdays'     => array(
				'label' => __( 'Every weekday (Mon-Fri)', 'nettertech-events' ),
				'rrule' => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR',
			),
			'weekends'     => array(
				'label' => __( 'Every weekend (Sat-Sun)', 'nettertech-events' ),
				'rrule' => 'FREQ=WEEKLY;BYDAY=SA,SU',
			),
			'first_monday' => array(
				'label' => __( 'First Monday of month', 'nettertech-events' ),
				'rrule' => 'FREQ=MONTHLY;BYDAY=1MO',
			),
			'last_friday'  => array(
				'label' => __( 'Last Friday of month', 'nettertech-events' ),
				'rrule' => 'FREQ=MONTHLY;BYDAY=-1FR',
			),
		);
	}

	/**
	 * Delete occurrences for an event, protecting those with attendees.
	 *
	 * Iterates all occurrences for the event and deletes only those
	 * with zero confirmed attendees. Fires a hook for each protected
	 * occurrence so admins have visibility.
	 *
	 * @param int $event_id Event ID.
	 * @return int Number of occurrences that were protected (not deleted).
	 */
	private function delete_unprotected_occurrences_for_event( int $event_id ): int {
		$existing = $this->occurrence_repo->for_event( $event_id, array( 'limit' => PHP_INT_MAX ) );

		if ( empty( $existing ) ) {
			return 0;
		}

		$protected = 0;

		foreach ( $existing as $occurrence ) {
			if ( null === $occurrence->id ) {
				continue;
			}

			if ( $this->remover()->has_holders( $occurrence->id ) ) {
				++$protected;

				/**
				 * Fires when an occurrence deletion is blocked because someone holds a place on it.
				 *
				 * @param int $occurrence_id  The occurrence that was protected.
				 * @param int $attendee_count Number of confirmed attendees.
				 */
				do_action( 'nettertech_events_occurrence_deletion_blocked', $occurrence->id, $this->attendee_repo->count_for_occurrence( $occurrence->id ) );
				continue;
			}

			// Manual per-occurrence overrides (NTE-077) must survive regeneration,
			// even with no attendees, or the edit would be lost on the next RRULE save.
			if ( $occurrence->is_override ) {
				++$protected;
				continue;
			}

			// Cancelled occurrences are recurrence exceptions (EXDATE): the date was
			// deliberately excluded. Deleting it would let regeneration recreate the
			// slot as scheduled, so the exclusion must survive even with no attendees.
			if ( $occurrence->is_cancelled() ) {
				++$protected;
				continue;
			}

			// Operator-configured tiers bind to the occurrence row by id (NTE-177):
			// deleting the row would orphan the tier and its commerce product —
			// they point at an id that no longer exists, so status sync and stock
			// updates silently stop reaching them. Template-derived tiers are
			// excluded: regeneration re-applies templates to the new rows.
			if ( $this->has_direct_ticket_types( $occurrence->id ) ) {
				++$protected;
				continue;
			}

			$this->remover()->remove( $occurrence->id );
		}

		return $protected;
	}

	/**
	 * Return the event's active ticket templates.
	 *
	 * Exposes the same set apply_templates_to_occurrences() copies from, so a caller can name the
	 * tiers a template application just created (operator ruling 2026-07-20, spec-001 invention
	 * audit — R1: report what was minted, no silent product creation).
	 *
	 * @param int $event_id Event ID.
	 * @return array<\NetterTechEvents\Models\TicketType> Active templates (may be empty).
	 */
	public function get_active_templates( int $event_id ): array {
		return $this->ticket_type_repo->get_templates( $event_id );
	}

	/**
	 * Give the event's future dates any active template they lack a copy of.
	 *
	 * A date the event's templates no longer reach is left alone: one with a ticket of its own
	 * (not from a template), which was set up by hand, and one edited on its own with no template
	 * copy at all, whose tickets were turned off there. No date is deleted or recreated.
	 *
	 * The horizon cron calls this for every live series on every run, so a series with nothing
	 * missing costs three reads and writes nothing: the gaps are found here, and only dates that
	 * have one go on to be filled.
	 *
	 * @since 1.5.4
	 *
	 * @param Event $event The event.
	 * @return int Copies created.
	 */
	public function fill_template_gaps( Event $event ): int {
		$event_id = $event->id;
		if ( null === $event_id ) {
			return 0;
		}

		$template_ids = array();
		foreach ( $this->ticket_type_repo->get_templates( $event_id ) as $template ) {
			if ( null !== $template->id ) {
				$template_ids[] = $template->id;
			}
		}
		if ( empty( $template_ids ) ) {
			return 0;
		}

		$own    = array();
		$copied = array();
		foreach ( $this->ticket_type_repo->for_event( $event_id, array( 'scope' => TicketTypeScope::OCCURRENCE->value ) ) as $row ) {
			if ( null === $row->occurrence_id ) {
				continue;
			}
			if ( null === $row->template_id ) {
				$own[ $row->occurrence_id ] = $row->occurrence_id;
			} else {
				$copied[ $row->occurrence_id ][] = $row->template_id;
			}
		}

		// Instants on both sides: get_start() reads the wall-clock in the date's own zone.
		$now     = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$created = 0;
		$dates   = $this->occurrence_repo->for_event(
			$event_id,
			array(
				'upcoming' => true,
				'limit'    => PHP_INT_MAX,
			)
		);
		foreach ( $dates as $date ) {
			$date_id = $date->id;
			if ( null === $date_id || isset( $own[ $date_id ] ) || $date->is_cancelled() || $date->get_start() <= $now ) {
				continue;
			}
			if ( ! isset( $copied[ $date_id ] ) && $date->is_override ) {
				continue;
			}

			$missing = array_values( array_diff( $template_ids, $copied[ $date_id ] ?? array() ) );
			if ( ! empty( $missing ) ) {
				$created += $this->apply_templates_to_occurrences( $event, array( $date ), $missing );
			}
		}

		return $created;
	}

	/**
	 * Give dates a copy of each of the event's active ticket templates.
	 *
	 * A date that already has a copy of a template is not given another, so this is safe to run
	 * on dates that have some of the templates already.
	 *
	 * @since 1.5.4 Skips a template the date already has a copy of; added `$template_ids`.
	 *
	 * @param Event             $event        The event.
	 * @param array<Occurrence> $occurrences  Saved dates of the event (must have IDs).
	 * @param array<int>|null   $template_ids Only these templates; null for all of them.
	 * @return int Number of tickets created.
	 */
	public function apply_templates_to_occurrences( Event $event, array $occurrences, ?array $template_ids = null ): int {
		if ( empty( $occurrences ) || ! $event->id ) {
			return 0;
		}

		// Get active templates for this event.
		$templates = $this->ticket_type_repo->get_templates( $event->id );
		if ( null !== $template_ids ) {
			$templates = array_filter(
				$templates,
				static fn( TicketType $template ): bool => in_array( (int) $template->id, $template_ids, true )
			);
		}

		if ( empty( $templates ) ) {
			return 0;
		}

		$created = 0;

		foreach ( $occurrences as $occurrence ) {
			// Skip if occurrence has no ID (wasn't saved).
			$occurrence_id = $occurrence->id;
			if ( ! $occurrence_id ) {
				continue;
			}

			$copied = array();
			foreach ( $this->ticket_type_repo->for_occurrence( $occurrence_id ) as $existing ) {
				if ( $occurrence_id === $existing->occurrence_id && null !== $existing->template_id ) {
					$copied[ $existing->template_id ] = true;
				}
			}

			foreach ( $templates as $template ) {
				if ( isset( $copied[ (int) $template->id ] ) ) {
					continue;
				}

				try {
					// Create occurrence ticket from template.
					$new_ticket = $this->ticket_type_repo->create_from_template( $template, $occurrence_id );

					// Save the new ticket.
					$this->ticket_type_repo->save( $new_ticket );

					// Fire hook for WooCommerce product sync (listener in WooCommerceIntegration).
					if ( $new_ticket->price > 0 ) {
						do_action( 'nettertech_events_ticket_type_sync_product', $new_ticket, $occurrence );
					}

					++$created;
				} catch ( \RuntimeException $e ) {
					DebugLogger::exception( $e, 'RecurrenceService' );
				}
			}
		}

		/**
		 * Fires after templates are applied to occurrences.
		 *
		 * @param Event             $event       The event.
		 * @param array<Occurrence> $occurrences The occurrences.
		 * @param int               $created     Number of tickets created.
		 */
		do_action( 'nettertech_events_templates_applied', $event, $occurrences, $created );

		return $created;
	}
}
