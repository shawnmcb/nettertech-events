<?php
/**
 * Event action handler for admin operations.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Services\EventDuplicationService;

/**
 * Handles admin event actions like delete and duplicate.
 *
 * @since 0.8.0
 */
class EventActionHandler {

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
	 * Ticket repository (for the order-linked attendance guard).
	 *
	 * @var TicketRepositoryInterface|null
	 */
	private ?TicketRepositoryInterface $ticket_repo;

	/**
	 * Constructor.
	 *
	 * @param EventRepositoryInterface       $event_repo          Event repository.
	 * @param EventDuplicationService        $duplication_service Event duplication service.
	 * @param TicketRepositoryInterface|null $ticket_repo         Ticket repository for the paid-attendance guard.
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		EventDuplicationService $duplication_service,
		?TicketRepositoryInterface $ticket_repo = null
	) {
		$this->event_repo          = $event_repo;
		$this->duplication_service = $duplication_service;
		$this->ticket_repo         = $ticket_repo;
	}

	/**
	 * Register action hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_deletion' ) );
		add_action( 'admin_init', array( $this, 'handle_duplication' ) );
	}

	/**
	 * Handle event deletion action.
	 *
	 * @return void
	 */
	public function handle_deletion(): void {
		if ( ! $this->is_nettertech_events_page() ) {
			return;
		}

		if ( ! isset( $_GET['action'] ) || 'delete' !== $_GET['action'] ) {
			return;
		}

		$event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;

		if ( ! $event_id ) {
			return;
		}

		// Check permissions first (before nonce to avoid timing attacks).
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nettertech-events' ) );
		}

		// Verify nonce.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'delete_event_' . $event_id ) ) {
			wp_die( esc_html__( 'Your session has expired. Please reload the page and try again.', 'nettertech-events' ) );
		}

		// Block deletion when the event has order-linked (paid) attendance —
		// deleting it would destroy ticket/attendee rows tied to WC orders.
		if ( null !== $this->ticket_repo && $this->ticket_repo->has_paid_attendance( $event_id ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=nettertech-events&message=has_sales' ) );
			exit;
		}

		$this->event_repo->delete( $event_id );

		wp_safe_redirect( admin_url( 'admin.php?page=nettertech-events&message=deleted' ) );
		exit;
	}

	/**
	 * Handle event duplication action.
	 *
	 * @return void
	 */
	public function handle_duplication(): void {
		if ( ! $this->is_nettertech_events_page() ) {
			return;
		}

		if ( ! isset( $_GET['action'] ) || 'duplicate' !== $_GET['action'] ) {
			return;
		}

		$event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;

		if ( ! $event_id ) {
			return;
		}

		// Check permissions first (before nonce to avoid timing attacks).
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nettertech-events' ) );
		}

		// Verify nonce.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'duplicate_event_' . $event_id ) ) {
			wp_die( esc_html__( 'Your session has expired. Please reload the page and try again.', 'nettertech-events' ) );
		}

		$duplicate = $this->duplication_service->duplicate( $event_id );

		if ( $duplicate ) {
			wp_safe_redirect( admin_url( 'admin.php?page=nettertech-events&action=edit&event_id=' . $duplicate->id . '&message=duplicated' ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=nettertech-events&message=error' ) );
		}
		exit;
	}

	/**
	 * Check if we're on the nettertech-events admin page.
	 *
	 * @return bool
	 */
	private function is_nettertech_events_page(): bool {
		return 'nettertech-events' === AdminRequest::get_text( 'page' );
	}
}
