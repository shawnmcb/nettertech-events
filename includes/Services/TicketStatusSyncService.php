<?php
/**
 * Ticket status synchronization service.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Utilities\DebugLogger;

/**
 * Keeps ticket (and product) visibility in step with event status (NTE-177).
 *
 * An event is the master of its own visibility: while it is a draft nothing it owns should be
 * purchasable, and publishing it should make everything purchasable at once. This service listens to
 * the existing event lifecycle actions and moves the event's ticket types between `draft` and
 * `active` (publish: draft ⇒ active; un-publish: active ⇒ draft — other statuses, such as a
 * withdrawn Pro sale tier's `inactive`, are left alone), then re-runs the existing ProductManager
 * sync so each WC product follows (`active` ⇒ publish, else ⇒ draft).
 *
 * It never reads or writes orders and never deletes a product; status changes only gate future
 * purchasability (FR-007). It is idempotent — re-firing a transition it has already applied is a
 * no-op, since setting a status to the value it already holds changes nothing.
 *
 * @since 1.1.4
 */
class TicketStatusSyncService {

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
	 * Product manager for WC product sync (null when WC is inactive).
	 *
	 * @var \NetterTechEvents\Integrations\WooCommerce\ProductManager|null
	 */
	private ?\NetterTechEvents\Integrations\WooCommerce\ProductManager $product_manager;

	/**
	 * Constructor.
	 *
	 * @param TicketTypeRepositoryInterface                                  $ticket_type_repo Ticket type repository.
	 * @param OccurrenceRepositoryInterface                                  $occurrence_repo  Occurrence repository.
	 * @param \NetterTechEvents\Integrations\WooCommerce\ProductManager|null $product_manager  Product manager (null when WC inactive).
	 */
	public function __construct(
		TicketTypeRepositoryInterface $ticket_type_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		?\NetterTechEvents\Integrations\WooCommerce\ProductManager $product_manager = null
	) {
		$this->ticket_type_repo = $ticket_type_repo;
		$this->occurrence_repo  = $occurrence_repo;
		$this->product_manager  = $product_manager;
	}

	/**
	 * Register the lifecycle listeners.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nettertech_events_event_published', array( $this, 'on_event_published' ), 10, 1 );
		add_action( 'nettertech_events_event_unpublished', array( $this, 'on_event_unpublished' ), 10, 1 );
	}

	/**
	 * Activate the event's tickets and publish their products.
	 *
	 * @param int $event_id Event ID.
	 * @return void
	 */
	public function on_event_published( int $event_id ): void {
		$this->sync( $event_id, 'active' );
	}

	/**
	 * Draft the event's tickets and un-publish their products.
	 *
	 * @param int $event_id Event ID.
	 * @return void
	 */
	public function on_event_unpublished( int $event_id ): void {
		$this->sync( $event_id, 'draft' );
	}

	/**
	 * Transition the event's tiers between draft and active, then re-sync products.
	 *
	 * Only the draft⇄active pair is touched: a tier in any other status (e.g. a
	 * withdrawn or not-yet-current Pro sale tier held at 'inactive') has been set
	 * that way deliberately and must not be resurrected by an event publish.
	 *
	 * @param int    $event_id      Event ID.
	 * @param string $target_status 'active' or 'draft'.
	 * @return void
	 */
	private function sync( int $event_id, string $target_status ): void {
		if ( $event_id <= 0 ) {
			return;
		}

		$occurrence_ids = array();
		foreach ( $this->occurrence_repo->for_event( $event_id ) as $occurrence ) {
			if ( null !== $occurrence->id ) {
				$occurrence_ids[ (int) $occurrence->id ] = (int) $occurrence->id;
			}
		}

		// Every tier the event owns: event/template scopes carry event_id, and
		// occurrence tiers are gathered per date so legacy rows without event_id
		// are covered too. Keyed by id to de-duplicate the overlap.
		$ticket_types = array();
		foreach ( $this->ticket_type_repo->for_event( $event_id ) as $ticket_type ) {
			if ( null !== $ticket_type->id ) {
				$ticket_types[ (int) $ticket_type->id ] = $ticket_type;
			}
		}
		foreach ( $occurrence_ids as $occurrence_id ) {
			foreach ( $this->ticket_type_repo->for_occurrence( $occurrence_id, array( 'status' => null ) ) as $ticket_type ) {
				if ( null !== $ticket_type->id ) {
					$ticket_types[ (int) $ticket_type->id ] = $ticket_type;
				}
			}
		}

		$source_status = 'active' === $target_status ? 'draft' : 'active';

		foreach ( $ticket_types as $ticket_type ) {
			if ( $ticket_type->status === $source_status ) {
				$ticket_type->status = $target_status;
				try {
					$this->ticket_type_repo->save( $ticket_type );
				} catch ( \RuntimeException $e ) {
					DebugLogger::exception( $e, 'TicketStatusSyncService' );
				}
			}
		}

		$this->resync_products( $event_id, $occurrence_ids, $target_status );
	}

	/**
	 * Re-run the existing product sync so each WC product follows its tier's status.
	 *
	 * Reuses ProductManager's create/sync paths, which map `active` ⇒ publish and anything else ⇒
	 * draft. No admin SKUs are passed (they apply first-save only), so existing products keep their
	 * SKUs; products with sales are never deleted, and orders are never touched.
	 *
	 * Only the publish transition may create products. An unpublish (draft target) reverts existing
	 * products to draft but never mints one — unpublishing an event must not bring a never-created
	 * product into being (operator ruling 2026-07-20, spec-001 invention audit — R5).
	 *
	 * @param int        $event_id       Event ID.
	 * @param array<int> $occurrence_ids Occurrence IDs of the event.
	 * @param string     $target_status  'active' (publish) or 'draft' (unpublish).
	 * @return void
	 */
	private function resync_products( int $event_id, array $occurrence_ids, string $target_status ): void {
		if ( null === $this->product_manager ) {
			return;
		}

		$existing_only = 'active' !== $target_status;

		try {
			$this->product_manager->create_products_for_event( $event_id, array(), $existing_only );
			foreach ( $occurrence_ids as $occurrence_id ) {
				$this->product_manager->create_products_for_occurrence( $occurrence_id, true, array(), $existing_only );
			}
		} catch ( \RuntimeException $e ) {
			DebugLogger::exception( $e, 'TicketStatusSyncService' );
		}
	}
}
