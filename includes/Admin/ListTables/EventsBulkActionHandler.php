<?php
/**
 * Events bulk action handler.
 *
 * @package NetterTechEvents\Admin\ListTables
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\ListTables;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Services\EventDuplicationService;

/**
 * Executes mutating bulk actions for the events list table.
 */
class EventsBulkActionHandler {

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Event duplication service.
	 *
	 * @var EventDuplicationService
	 */
	private EventDuplicationService $duplication_service;

	/**
	 * Category repository.
	 *
	 * @var CategoryRepositoryInterface|null
	 */
	private ?CategoryRepositoryInterface $category_repo;

	/**
	 * Ticket repository (for the order-linked attendance guard).
	 *
	 * @var TicketRepositoryInterface|null
	 */
	private ?TicketRepositoryInterface $ticket_repo;

	/**
	 * Constructor.
	 *
	 * @param EventRepositoryInterface         $event_repo          Event repository.
	 * @param EventDuplicationService          $duplication_service Event duplication service.
	 * @param CategoryRepositoryInterface|null $category_repo       Category repository.
	 * @param TicketRepositoryInterface|null   $ticket_repo         Ticket repository for the paid-attendance guard.
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		EventDuplicationService $duplication_service,
		?CategoryRepositoryInterface $category_repo = null,
		?TicketRepositoryInterface $ticket_repo = null
	) {
		$this->event_repo          = $event_repo;
		$this->duplication_service = $duplication_service;
		$this->category_repo       = $category_repo;
		$this->ticket_repo         = $ticket_repo;
	}

	/**
	 * Process the current bulk action request.
	 *
	 * Capability and nonce verification happen up-front in this method so
	 * subsequent `$_REQUEST` reads occur in the same lexical scope and PHPCS
	 * can statically prove nonce verification preceded the data extraction.
	 *
	 * Reads from `$_REQUEST` so the bulk-action form can submit via POST
	 * (required when the selection holds more event IDs than the URL length
	 * limit permits — ~500+ IDs overflow Apache's default 8190-char limit).
	 *
	 * @param string|null $action Bulk action key.
	 * @param string      $plural List table plural key for nonce verification.
	 * @return void
	 */
	public function process( ?string $action, string $plural ): void {
		if ( ! $action ) {
			return;
		}

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		if ( ! isset( $_REQUEST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-' . $plural )
		) {
			return;
		}

		$event_ids = isset( $_REQUEST['event'] )
			? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_REQUEST['event'] ) ) ) )
			: array();
		if ( empty( $event_ids ) ) {
			return;
		}

		$category_ids = isset( $_REQUEST['bulk_category_ids'] )
			? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_REQUEST['bulk_category_ids'] ) ) ) )
			: array();

		match ( $action ) {
			'delete'               => $this->delete_events( $event_ids ),
			'publish'              => $this->update_event_status( $event_ids, EventStatus::PUBLISHED ),
			'draft'                => $this->update_event_status( $event_ids, EventStatus::DRAFT ),
			'cancel'               => $this->update_event_status( $event_ids, EventStatus::CANCELLED ),
			'bulk_duplicate'       => $this->duplicate_events( $event_ids ),
			'bulk_category_add'    => $this->process_bulk_category( $event_ids, $category_ids, 'add' ),
			'bulk_category_remove' => $this->process_bulk_category( $event_ids, $category_ids, 'remove' ),
			default                => null,
		};
	}

	/**
	 * Delete events, skipping any with order-linked (paid) attendance.
	 *
	 * Events whose tickets/attendees reference a WooCommerce order are left
	 * intact to preserve order history; the rest are deleted. When any event is
	 * skipped, redirect with a notice listing how many (safe here because bulk
	 * actions run during prepare_items(), before page output begins).
	 *
	 * @param array<int, int> $event_ids Event IDs.
	 * @return void
	 */
	private function delete_events( array $event_ids ): void {
		$skipped = 0;

		foreach ( $event_ids as $event_id ) {
			if ( null !== $this->ticket_repo && $this->ticket_repo->has_paid_attendance( $event_id ) ) {
				++$skipped;
				continue;
			}
			$this->event_repo->delete( $event_id );
		}

		if ( $skipped > 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'nettertech-events',
						'message' => 'bulk_skipped',
						'skipped' => $skipped,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Update event status.
	 *
	 * @param array<int, int> $event_ids Event IDs.
	 * @param EventStatus     $status    Target status.
	 * @return void
	 */
	private function update_event_status( array $event_ids, EventStatus $status ): void {
		foreach ( $event_ids as $event_id ) {
			$event = $this->event_repo->find( $event_id );
			if ( ! $event ) {
				continue;
			}

			$event->status = $status;
			$this->event_repo->save( $event );
		}
	}

	/**
	 * Duplicate events.
	 *
	 * @param array<int, int> $event_ids Event IDs.
	 * @return void
	 */
	private function duplicate_events( array $event_ids ): void {
		foreach ( $event_ids as $event_id ) {
			$this->duplication_service->duplicate( $event_id );
		}
	}

	/**
	 * Process category add/remove.
	 *
	 * @param array<int, int> $event_ids    Event IDs (already nonce-verified by caller).
	 * @param array<int, int> $category_ids Category IDs (already nonce-verified by caller).
	 * @param string          $operation    Either add or remove.
	 * @return void
	 */
	private function process_bulk_category( array $event_ids, array $category_ids, string $operation ): void {
		if ( ! $this->category_repo ) {
			return;
		}

		if ( empty( $category_ids ) ) {
			return;
		}

		foreach ( $event_ids as $event_id ) {
			foreach ( $category_ids as $category_id ) {
				if ( 'add' === $operation ) {
					$this->category_repo->attach_to_event( $event_id, $category_id );
				} else {
					$this->category_repo->detach_from_event( $event_id, $category_id );
				}
			}
		}
	}
}
