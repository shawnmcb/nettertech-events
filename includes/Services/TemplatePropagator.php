<?php
/**
 * Template propagator.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Enums\TicketTypeScope;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Models\TicketType;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Carries a saved ticket template to the copies of it on the event's future dates.
 *
 * A copy stays linked to its template through `template_id` until it is changed on its own date.
 * Creating or changing a template updates its unsold linked copies in place and syncs their
 * products; deleting a template retires its unsold copies. A copy with its own sales is left
 * exactly as it is and reported. Every save then gives future dates any template they lack
 * (RecurrenceService::fill_template_gaps()). Past dates, cancelled dates and detached copies are
 * never touched.
 *
 * @since 1.5.4
 */
class TemplatePropagator {

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

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
	 * Gives dates their missing template copies.
	 *
	 * @var RecurrenceService
	 */
	private RecurrenceService $recurrence_service;

	/**
	 * Retires copies of a deleted template.
	 *
	 * @var OccurrenceRemover
	 */
	private OccurrenceRemover $remover;

	/**
	 * Constructor.
	 *
	 * @param TicketTypeRepositoryInterface $ticket_type_repo   Ticket type repository.
	 * @param OccurrenceRepositoryInterface $occurrence_repo    Occurrence repository.
	 * @param EventRepositoryInterface      $event_repo         Event repository.
	 * @param RecurrenceService             $recurrence_service Gives dates their missing template copies.
	 * @param OccurrenceRemover             $remover            Retires copies of a deleted template.
	 */
	public function __construct(
		TicketTypeRepositoryInterface $ticket_type_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		EventRepositoryInterface $event_repo,
		RecurrenceService $recurrence_service,
		OccurrenceRemover $remover
	) {
		$this->ticket_type_repo   = $ticket_type_repo;
		$this->occurrence_repo    = $occurrence_repo;
		$this->event_repo         = $event_repo;
		$this->recurrence_service = $recurrence_service;
		$this->remover            = $remover;
	}

	/**
	 * Carry created, changed and deleted templates to the event's future dates, then fill the
	 * dates that lack a template.
	 *
	 * @since 1.5.4
	 *
	 * @param int        $event_id             Event ID.
	 * @param array<int> $saved_template_ids   Templates created or changed in this request.
	 * @param array<int> $deleted_template_ids Templates deleted in this request.
	 * @return array{updated: int, created: int, retired: int, kept: array<int, Occurrence>} Copies
	 *         updated, created and retired, and the dates whose copies were left alone because they
	 *         have sales of their own, keyed by occurrence ID.
	 */
	public function propagate( int $event_id, array $saved_template_ids, array $deleted_template_ids ): array {
		$result = array(
			'updated' => 0,
			'created' => 0,
			'retired' => 0,
			'kept'    => array(),
		);

		if ( ! empty( $saved_template_ids ) || ! empty( $deleted_template_ids ) ) {
			$this->carry( $event_id, $saved_template_ids, $deleted_template_ids, $result );
		}

		// On every save, changed templates or not: re-saving the event is how an operator gets
		// tickets back onto dates that lost them.
		$event = $this->event_repo->find( $event_id );
		if ( null !== $event ) {
			$result['created'] = $this->recurrence_service->fill_template_gaps( $event );
		}

		return $result;
	}

	/**
	 * Update or retire the copies of the templates this save changed or deleted.
	 *
	 * @param int                                                                           $event_id             Event ID.
	 * @param array<int>                                                                    $saved_template_ids   Templates created or changed.
	 * @param array<int>                                                                    $deleted_template_ids Templates deleted.
	 * @param array{updated: int, created: int, retired: int, kept: array<int, Occurrence>} $result               Counts, filled in.
	 * @return void
	 */
	private function carry( int $event_id, array $saved_template_ids, array $deleted_template_ids, array &$result ): void {
		$dates = $this->future_dates( $event_id );
		if ( empty( $dates ) ) {
			return;
		}

		$rows   = $this->ticket_type_repo->for_event( $event_id, array( 'scope' => TicketTypeScope::OCCURRENCE->value ) );
		$copies = $this->linked_copies( $rows, $dates );

		foreach ( $saved_template_ids as $template_id ) {
			$template = $this->ticket_type_repo->find( $template_id );
			if ( null === $template || TicketTypeScope::TEMPLATE->value !== $template->scope ) {
				continue;
			}

			foreach ( $copies[ $template_id ] ?? array() as $date_id => $copy ) {
				if ( ! self::differs( $copy, $template ) ) {
					continue;
				}
				if ( $copy->sold_count > 0 ) {
					if ( ! $this->carry_to_sold_copy( $copy, $template ) ) {
						$result['kept'][ $date_id ] = $dates[ $date_id ];
					}
					continue;
				}
				$this->follow( $copy, $template, $dates[ $date_id ] );
				++$result['updated'];
			}
		}

		foreach ( $deleted_template_ids as $template_id ) {
			foreach ( $copies[ $template_id ] ?? array() as $date_id => $copy ) {
				if ( $this->remover->remove_ticket_type( $copy ) ) {
					++$result['retired'];
					continue;
				}
				$result['kept'][ $date_id ] = $dates[ $date_id ];
			}
		}
	}

	/**
	 * Carry a template change to a copy that has sales of its own. Nothing is carried: the copy
	 * keeps every value it was sold at, and the caller reports its date.
	 *
	 * Changing a sold ticket's price in place would put two prices on one ticket's sales. This is
	 * the one point where a template change meets a sold copy, so a price handover, which closes
	 * the sold ticket and opens a successor at the new price, belongs here.
	 *
	 * @param TicketType $copy     The sold copy.
	 * @param TicketType $template The template as saved.
	 * @return bool Whether the change reached the copy's date.
	 */
	protected function carry_to_sold_copy( TicketType $copy, TicketType $template ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the handover that replaces this body needs both.
		return false;
	}

	/**
	 * The event's dates that have not started and are not cancelled, keyed by ID.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, Occurrence>
	 */
	private function future_dates( int $event_id ): array {
		$now   = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$dates = array();

		$upcoming = $this->occurrence_repo->for_event(
			$event_id,
			array(
				'upcoming' => true,
				'limit'    => PHP_INT_MAX,
			)
		);

		foreach ( $upcoming as $date ) {
			if ( null !== $date->id && ! $date->is_cancelled() && $date->get_start() > $now ) {
				$dates[ $date->id ] = $date;
			}
		}

		return $dates;
	}

	/**
	 * The template copies on the given dates, grouped by template, then keyed by date.
	 *
	 * @param array<TicketType>      $rows  The event's date tickets.
	 * @param array<int, Occurrence> $dates Dates to include.
	 * @return array<int, array<int, TicketType>>
	 */
	private function linked_copies( array $rows, array $dates ): array {
		$copies = array();

		foreach ( $rows as $row ) {
			if ( null !== $row->template_id && null !== $row->occurrence_id && isset( $dates[ $row->occurrence_id ] ) ) {
				$copies[ $row->template_id ][ $row->occurrence_id ] = $row;
			}
		}

		return $copies;
	}

	/**
	 * Whether two ticket types differ in any value a copy takes from its template.
	 *
	 * @since 1.5.4
	 *
	 * @param TicketType $a Copy or template.
	 * @param TicketType $b Copy or template.
	 * @return bool
	 */
	public static function differs( TicketType $a, TicketType $b ): bool {
		return self::followed( $a ) !== self::followed( $b );
	}

	/**
	 * The values a copy takes from its template.
	 *
	 * @since 1.5.4
	 *
	 * @param TicketType $ticket_type Copy or template.
	 * @return array<string, mixed>
	 */
	public static function followed( TicketType $ticket_type ): array {
		return array(
			'name'          => $ticket_type->name,
			'description'   => (string) $ticket_type->description,
			'price'         => round( $ticket_type->price, 2 ),
			'capacity_type' => $ticket_type->capacity_type,
			'capacity'      => $ticket_type->capacity,
			'sale_start'    => $ticket_type->sale_start,
			'sale_end'      => $ticket_type->sale_end,
			'min_per_order' => $ticket_type->min_per_order,
			'max_per_order' => $ticket_type->max_per_order,
			'sort_order'    => $ticket_type->sort_order,
			'status'        => $ticket_type->status,
		);
	}

	/**
	 * Give an unsold copy its template's values and sync its product.
	 *
	 * @param TicketType $copy     Unsold copy.
	 * @param TicketType $template Template.
	 * @param Occurrence $date     The copy's date.
	 * @return void
	 */
	private function follow( TicketType $copy, TicketType $template, Occurrence $date ): void {
		foreach ( array_keys( self::followed( $template ) ) as $field ) {
			$copy->$field = $template->$field;
		}

		try {
			$this->ticket_type_repo->save( $copy );
		} catch ( \RuntimeException $e ) {
			DebugLogger::exception( $e, 'TemplatePropagator' );
			return;
		}

		if ( null !== $copy->wc_product_id || $copy->price > 0 ) {
			do_action( Hooks::TICKET_TYPE_SYNC_PRODUCT, $copy, $date );
		}
	}
}
