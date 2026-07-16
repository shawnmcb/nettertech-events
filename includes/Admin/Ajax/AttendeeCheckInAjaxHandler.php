<?php
/**
 * Attendee check-in AJAX handler.
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Ajax;

use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\AttendeeCheckInInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Core\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Handles logged-in attendee check-in toggles from the Attendees screen.
 *
 * Base ships manual, logged-in check-in for users who can `edit_posts`. The
 * token-authenticated volunteer/QR-scan surface remains a Pro feature and is
 * routed separately — nothing here accepts an unauthenticated request.
 *
 * @since 1.1.2
 */
final class AttendeeCheckInAjaxHandler {

	/**
	 * Capability required to check attendees in.
	 *
	 * Matches the capability guarding the Attendees screen itself, so any staff
	 * member who can see the list can work the door.
	 */
	private const CAPABILITY = 'edit_posts';

	/**
	 * Attendee repository.
	 *
	 * @var AttendeeRepositoryInterface
	 */
	private AttendeeRepositoryInterface $attendee_repo;

	/**
	 * Check-in repository.
	 *
	 * @var AttendeeCheckInInterface
	 */
	private AttendeeCheckInInterface $check_in_repo;

	/**
	 * Activity log service.
	 *
	 * @var ActivityLogServiceInterface
	 */
	private ActivityLogServiceInterface $activity_log;

	/**
	 * Constructor.
	 *
	 * @param AttendeeRepositoryInterface $attendee_repo Attendee repository.
	 * @param AttendeeCheckInInterface    $check_in_repo Check-in repository.
	 * @param ActivityLogServiceInterface $activity_log  Activity log service.
	 */
	public function __construct(
		AttendeeRepositoryInterface $attendee_repo,
		AttendeeCheckInInterface $check_in_repo,
		ActivityLogServiceInterface $activity_log
	) {
		$this->attendee_repo = $attendee_repo;
		$this->check_in_repo = $check_in_repo;
		$this->activity_log  = $activity_log;
	}

	/**
	 * Handle the check-in toggle request.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nettertech-events' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, Hooks::AJAX_ATTENDEE_CHECK_IN ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'nettertech-events' ) ), 403 );
		}

		$attendee_id = isset( $_POST['attendee_id'] ) ? absint( wp_unslash( $_POST['attendee_id'] ) ) : 0;
		if ( $attendee_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attendee.', 'nettertech-events' ) ), 400 );
		}

		$state = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
		if ( 'in' !== $state && 'out' !== $state ) {
			wp_send_json_error( array( 'message' => __( 'Invalid check-in state.', 'nettertech-events' ) ), 400 );
		}

		$attendee = $this->attendee_repo->find( $attendee_id );
		if ( null === $attendee ) {
			wp_send_json_error( array( 'message' => __( 'Attendee not found.', 'nettertech-events' ) ), 404 );
		}

		// Only a confirmed registration holds a valid ticket. Refuse to check in a
		// voided, refunded, cancelled or pending attendee even if a stale row or a
		// forged request reaches here — the UI hides the control, but the endpoint
		// is the authority.
		if ( 'confirmed' !== $attendee->status ) {
			wp_send_json_error(
				array( 'message' => __( 'Only confirmed attendees can be checked in.', 'nettertech-events' ) ),
				409
			);
		}

		$checking_in = ( 'in' === $state );
		$updated     = $checking_in
			? $this->check_in_repo->mark_checked_in( $attendee_id )
			: $this->check_in_repo->mark_not_checked_in( $attendee_id );

		if ( ! $updated ) {
			wp_send_json_error( array( 'message' => __( 'Could not update check-in status.', 'nettertech-events' ) ), 500 );
		}

		if ( $checking_in ) {
			/**
			 * Fires after an attendee is checked in.
			 *
			 * @since 1.1.2
			 *
			 * @param int                  $attendee_id Attendee ID.
			 * @param array<string, mixed> $data        Check-in context: occurrence_id, name, quantity.
			 */
			do_action(
				Hooks::ATTENDEE_CHECKED_IN,
				$attendee_id,
				array(
					'occurrence_id' => $attendee->occurrence_id,
					'name'          => $attendee->name,
					'quantity'      => max( 1, $attendee->quantity ),
				)
			);
		}

		// Action keys must match ActivityLog::get_description()'s label vocabulary,
		// otherwise the Description column renders the raw underscored key.
		$this->activity_log->log_attendee(
			$checking_in ? 'check_in' : 'undo_check_in',
			$attendee_id,
			$attendee->name,
			array( 'occurrence_id' => $attendee->occurrence_id )
		);

		$quantity      = max( 1, $attendee->quantity );
		$checked_count = $checking_in ? $quantity : 0;

		wp_send_json_success(
			array(
				'attendeeId'     => $attendee_id,
				'checkedIn'      => $checking_in,
				'checkedInCount' => $checked_count,
				'quantity'       => $quantity,
				'label'          => $this->format_check_in_label( $checked_count, $quantity ),
				'stats'          => $this->check_in_repo->get_check_in_stats( $attendee->occurrence_id ),
			)
		);
	}

	/**
	 * Format the check-in cell label for a given count.
	 *
	 * Mirrors AttendeesPage::render_row() so the AJAX-refreshed cell and a
	 * freshly rendered page agree on wording for partial party check-ins.
	 *
	 * @param int $checked_count Guests checked in.
	 * @param int $quantity      Total guests on the attendee record.
	 * @return string Display label.
	 */
	private function format_check_in_label( int $checked_count, int $quantity ): string {
		if ( $checked_count >= $quantity ) {
			return __( 'Yes', 'nettertech-events' );
		}

		if ( $checked_count > 0 ) {
			return sprintf( '%d/%d', $checked_count, $quantity );
		}

		return __( 'No', 'nettertech-events' );
	}
}
