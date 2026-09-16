<?php
/**
 * AJAX handler for the All Events list per-date row expansion.
 *
 * @package NetterTechEvents\Admin\Ajax
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\ListTables\EventsListTable;
use NetterTechEvents\Admin\ListTables\OccurrenceRowRenderer;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceQueryRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\Occurrence;

/**
 * Returns one event's per-date rows as rendered HTML.
 *
 * Admin-only (`edit_posts` + nonce) and read-only. The rows come from the same
 * {@see OccurrenceRowRenderer} the server-side `expanded` argument uses, against
 * the same filtered column list, so the toggle inserts markup identical to what a
 * reload would produce.
 *
 * @since 1.4.8
 */
class EventOccurrenceRowsAjaxHandler {

	/**
	 * Event repository.
	 *
	 * @var EventRepositoryInterface
	 */
	private EventRepositoryInterface $event_repo;

	/**
	 * Occurrence query repository.
	 *
	 * @var OccurrenceQueryRepositoryInterface
	 */
	private OccurrenceQueryRepositoryInterface $occurrence_query_repo;

	/**
	 * Attendee repository.
	 *
	 * @var AttendeeRepositoryInterface
	 */
	private AttendeeRepositoryInterface $attendee_repo;

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Constructor.
	 *
	 * @param EventRepositoryInterface           $event_repo            Event repository.
	 * @param OccurrenceQueryRepositoryInterface $occurrence_query_repo Occurrence query repository.
	 * @param AttendeeRepositoryInterface        $attendee_repo         Attendee repository.
	 * @param TicketTypeRepositoryInterface      $ticket_type_repo      Ticket type repository.
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		OccurrenceQueryRepositoryInterface $occurrence_query_repo,
		AttendeeRepositoryInterface $attendee_repo,
		TicketTypeRepositoryInterface $ticket_type_repo
	) {
		$this->event_repo            = $event_repo;
		$this->occurrence_query_repo = $occurrence_query_repo;
		$this->attendee_repo         = $attendee_repo;
		$this->ticket_type_repo      = $ticket_type_repo;
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
		if ( ! wp_verify_nonce( $nonce, Hooks::AJAX_EVENT_OCCURRENCE_ROWS ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'nettertech-events' ) ), 403 );
		}

		$event_id = absint( wp_unslash( $_GET['event_id'] ?? '' ) );
		if ( $event_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid event.', 'nettertech-events' ) ), 400 );
		}

		$event = $this->event_repo->find( $event_id );
		if ( null === $event ) {
			wp_send_json_error( array( 'message' => __( 'Event not found.', 'nettertech-events' ) ), 404 );
		}

		$grouped     = $this->occurrence_query_repo->for_events( array( $event_id ) );
		$occurrences = $grouped[ $event_id ] ?? array();

		$occurrence_ids = array();
		foreach ( $occurrences as $occurrence ) {
			if ( $occurrence instanceof Occurrence && null !== $occurrence->id ) {
				$occurrence_ids[] = $occurrence->id;
			}
		}

		$sold_counts  = array();
		$capacity_map = array();
		if ( ! empty( $occurrence_ids ) ) {
			$sold_counts  = $this->attendee_repo->confirmed_guest_counts_for_occurrences( $occurrence_ids );
			$capacity_map = $this->ticket_type_repo->occurrence_capacity_for_occurrences( $occurrence_ids );
		}

		$renderer = new OccurrenceRowRenderer( EventsListTable::filtered_columns(), $this->hidden_columns() );

		wp_send_json_success(
			array(
				'event_id' => $event_id,
				'count'    => count( $occurrences ),
				'html'     => $renderer->render( $event, $occurrences, $sold_counts, $capacity_map ),
			)
		);
	}

	/**
	 * The columns this user has hidden through Screen Options.
	 *
	 * Read from the user option `get_hidden_columns()` itself reads: admin-ajax has
	 * no `WP_Screen` for the list page, and a child cell that stayed visible while
	 * its parent cell was hidden would put the row a column out of step.
	 *
	 * @return array<int, string>
	 */
	private function hidden_columns(): array {
		$hidden = get_user_option( 'manage' . EventsListTable::SCREEN_ID . 'columnshidden' );

		return is_array( $hidden ) ? array_values( array_filter( $hidden, 'is_string' ) ) : array();
	}
}
