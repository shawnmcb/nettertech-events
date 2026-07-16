<?php
/**
 * AJAX handler for event revisions.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);


namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\RevisionRepositoryInterface;
use NetterTechEvents\Services\RevisionService;

/**
 * Handles AJAX requests for viewing, comparing, and restoring event revisions.
 *
 * @since 1.5.0
 */
class RevisionAjaxHandler {

	/**
	 * Nonce action for revision requests.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'nettertech_events_revisions';

	/**
	 * Revision repository.
	 *
	 * @var RevisionRepositoryInterface
	 */
	private RevisionRepositoryInterface $revision_repo;

	/**
	 * Revision service.
	 *
	 * @var RevisionService
	 */
	private RevisionService $revision_service;

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Constructor.
	 *
	 * @param RevisionRepositoryInterface $revision_repo  Revision repository.
	 * @param RevisionService             $revision_service Revision service.
	 * @param EventRepositoryInterface    $event_repo       Event repository.
	 */
	public function __construct( RevisionRepositoryInterface $revision_repo, RevisionService $revision_service, EventRepositoryInterface $event_repo ) {
		$this->revision_repo    = $revision_repo;
		$this->revision_service = $revision_service;
		$this->event_repo       = $event_repo;
	}

	/**
	 * Handle diff AJAX request.
	 *
	 * Returns the computed diff between a revision snapshot and the current event state.
	 *
	 * @return void
	 */
	public function handle_diff(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Unauthorized', 'nettertech-events' ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$revision_id = isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0;

		if ( ! $revision_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid revision ID.', 'nettertech-events' ) ) );
		}

		$revision = $this->revision_repo->find( $revision_id );

		if ( ! $revision ) {
			wp_send_json_error( array( 'message' => __( 'Revision not found.', 'nettertech-events' ) ) );
		}

		$old_snapshot = json_decode( $revision->revision_data, true );

		if ( ! is_array( $old_snapshot ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid revision data.', 'nettertech-events' ) ) );
		}

		$event = $this->event_repo->find( (int) $revision->event_id );

		if ( ! $event ) {
			wp_send_json_error( array( 'message' => __( 'Event not found.', 'nettertech-events' ) ) );
		}

		$current_snapshot = $this->revision_service->build_snapshot( $event );
		$diff             = $this->revision_service->compute_diff( $old_snapshot, $current_snapshot );

		wp_send_json_success(
			array(
				'diff'           => $diff,
				'change_summary' => $revision->change_summary ?? '',
			)
		);
	}

	/**
	 * Handle restore AJAX request.
	 *
	 * Restores an event from a revision and returns a redirect URL.
	 *
	 * @return void
	 */
	public function handle_restore(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Unauthorized', 'nettertech-events' ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$revision_id = isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0;

		if ( ! $revision_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid revision ID.', 'nettertech-events' ) ) );
		}

		$event_id = $this->revision_service->restore( $revision_id );

		if ( false === $event_id ) {
			wp_send_json_error( array( 'message' => __( 'Failed to restore revision.', 'nettertech-events' ) ) );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Revision restored.', 'nettertech-events' ),
				'redirect' => admin_url( 'admin.php?page=nettertech-events&action=edit&event_id=' . $event_id . '&message=revision_restored' ),
			)
		);
	}

	/**
	 * Handle list AJAX request.
	 *
	 * Returns a formatted list of revisions for an event.
	 *
	 * @return void
	 */
	public function handle_list(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Unauthorized', 'nettertech-events' ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;

		if ( ! $event_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid event ID.', 'nettertech-events' ) ) );
		}

		$revisions = $this->revision_repo->for_event( $event_id );
		$list      = array();

		foreach ( $revisions as $revision ) {
			$user = get_user_by( 'id', (int) $revision->user_id );

			$list[] = array(
				'id'             => (int) $revision->id,
				'author'         => $user ? $user->display_name : __( 'Unknown', 'nettertech-events' ),
				'created_at'     => $revision->created_at,
				'change_summary' => $revision->change_summary ?? '',
			);
		}

		wp_send_json_success( array( 'revisions' => $list ) );
	}
}
