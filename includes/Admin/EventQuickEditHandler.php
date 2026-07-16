<?php
/**
 * AJAX handler for event quick edit.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Enums\EventStatus;
use NetterTechEvents\Models\Event;

/**
 * Handles AJAX requests for inline quick editing of events.
 *
 * @since 1.5.0
 */
class EventQuickEditHandler {

	/**
	 * Nonce action for quick edit requests.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'nettertech_events_quick_edit';

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Constructor.
	 *
	 * @param EventRepositoryInterface $event_repo Event repository.
	 */
	public function __construct( EventRepositoryInterface $event_repo ) {
		$this->event_repo = $event_repo;
	}

	/**
	 * Handle quick edit AJAX request.
	 *
	 * Updates event title, status, and/or venue_name via inline edit.
	 *
	 * @return void
	 */
	public function handle_quick_edit(): void {
		// Capability check first (before nonce to avoid timing attacks).
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Unauthorized', 'nettertech-events' ), 403 );
		}

		// Verify nonce.
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		// Sanitize and validate input.
		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;

		if ( ! $event_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid event ID.', 'nettertech-events' ) ) );
		}

		$event = $this->event_repo->find( $event_id );

		if ( ! $event ) {
			wp_send_json_error( array( 'message' => __( 'Event not found.', 'nettertech-events' ) ) );
		}

		// Apply changes from submitted fields.
		$changed = false;

		if ( isset( $_POST['title'] ) ) {
			$title = sanitize_text_field( wp_unslash( $_POST['title'] ) );
			if ( '' !== $title && $title !== $event->title ) {
				$event->title = $title;
				$event->slug  = $this->event_repo->generate_unique_slug( $title );
				$changed      = true;
			}
		}

		if ( isset( $_POST['status'] ) ) {
			$status_string = sanitize_text_field( wp_unslash( $_POST['status'] ) );
			$new_status    = EventStatus::tryFrom( $status_string );
			if ( null !== $new_status && $new_status !== $event->status ) {
				$event->status = $new_status;
				$changed       = true;
			}
		}

		if ( isset( $_POST['venue_name'] ) ) {
			$venue_name = sanitize_text_field( wp_unslash( $_POST['venue_name'] ) );
			if ( ( $event->venue_name ?? '' ) !== $venue_name ) {
				$event->venue_name = '' === $venue_name ? null : $venue_name;
				$changed           = true;
			}
		}

		if ( ! $changed ) {
			wp_send_json_success(
				array(
					'message' => __( 'No changes detected.', 'nettertech-events' ),
					'event'   => $this->event_to_response( $event ),
				)
			);
		}

		$saved = $this->event_repo->save( $event );

		wp_send_json_success(
			array(
				'message' => __( 'Event updated.', 'nettertech-events' ),
				'event'   => $this->event_to_response( $saved ),
			)
		);
	}

	/**
	 * Convert event to response array for JSON.
	 *
	 * @param Event $event Event model.
	 * @return array<string, mixed>
	 */
	private function event_to_response( Event $event ): array {
		return array(
			'id'         => $event->id,
			'title'      => $event->title,
			'status'     => $event->status->value,
			'venue_name' => $event->venue_name ?? '',
		);
	}
}
