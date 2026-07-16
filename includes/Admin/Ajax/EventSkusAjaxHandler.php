<?php
/**
 * AJAX handler for the All Events list "SKUs" row-action dialog.
 *
 * @package NetterTechEvents\Admin\Ajax
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;

/**
 * Returns a single event's ticket-type SKUs for the admin row-action dialog (NTE-114).
 *
 * Admin-only (`edit_posts` + nonce). The SKU lives on the linked WooCommerce
 * product (single source of truth); this handler reads it on demand so the
 * All Events list pays no per-row query cost at render time.
 *
 * @since 1.0.3
 */
class EventSkusAjaxHandler {

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Constructor.
	 *
	 * @param EventRepositoryInterface      $event_repo       Event repository.
	 * @param TicketTypeRepositoryInterface $ticket_type_repo Ticket type repository.
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		TicketTypeRepositoryInterface $ticket_type_repo
	) {
		$this->event_repo       = $event_repo;
		$this->ticket_type_repo = $ticket_type_repo;
	}

	/**
	 * Handle the AJAX request.
	 *
	 * Reads `event_id` and `nonce` from the request query (the action is carried
	 * in the URL so a Cloudflare WAF rule can scope to it without body inspection).
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nettertech-events' ) ), 403 );
		}

		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, Hooks::AJAX_EVENT_SKUS ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'nettertech-events' ) ), 403 );
		}

		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		if ( $event_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid event.', 'nettertech-events' ) ), 400 );
		}

		$event = $this->event_repo->find( $event_id );
		if ( null === $event ) {
			wp_send_json_error( array( 'message' => __( 'Event not found.', 'nettertech-events' ) ), 404 );
		}

		$ticket_types = $this->ticket_type_repo->for_event( $event_id );

		$tickets = array();
		$skus    = array();
		foreach ( $ticket_types as $ticket_type ) {
			$sku = '';
			if ( $ticket_type->wc_product_id && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $ticket_type->wc_product_id );
				$sku     = $product ? (string) $product->get_sku() : '';
			}

			$tickets[] = array(
				'name' => $ticket_type->name,
				'sku'  => $sku,
			);

			if ( '' !== $sku ) {
				$skus[] = $sku;
			}
		}

		wp_send_json_success(
			array(
				'event_id'    => $event_id,
				'event_title' => $event->title,
				'tickets'     => $tickets,
				'skus_csv'    => implode( ', ', $skus ),
			)
		);
	}
}
