<?php
/**
 * Attendees Bulk Actions Handler.
 *
 * @package NetterTechEvents\Admin\Attendees
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Attendees;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Database\Schema;

/**
 * Handles bulk actions for attendees admin page.
 *
 * Extracted from AttendeesPage to reduce class complexity.
 * Manages delete, export, and single-item actions.
 *
 * @since 1.1.0
 */
class AttendeesBulkActions {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Exporter instance.
	 *
	 * @var AttendeesExporter
	 */
	private AttendeesExporter $exporter;

	/**
	 * Confirmation-email sender (null when WooCommerce is inactive).
	 *
	 * @var \NetterTechEvents\Services\OrderEmailHandler|null
	 */
	private ?\NetterTechEvents\Services\OrderEmailHandler $email_handler = null;

	/**
	 * Constructor.
	 *
	 * @param \wpdb                                             $db            Database instance.
	 * @param AttendeesExporter                                 $exporter      Exporter instance.
	 * @param \NetterTechEvents\Services\OrderEmailHandler|null $email_handler Confirmation-email sender (null when WooCommerce is inactive).
	 */
	public function __construct( \wpdb $db, AttendeesExporter $exporter, ?\NetterTechEvents\Services\OrderEmailHandler $email_handler = null ) {
		$this->email_handler = $email_handler;
		$this->db            = $db;
		$this->exporter      = $exporter;
	}

	/**
	 * Handle bulk actions (delete, export).
	 *
	 * @return void
	 */
	public function handle(): void {
		// Only process POST requests.
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}

		// Verify user has permission (defense-in-depth per OWASP A01).
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_POST['nettertech_events_attendees_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nettertech_events_attendees_nonce'] ) ), 'nettertech_events_attendees_bulk_action' ) ) {
			return;
		}

		$action = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '';

		// The Export All button posts its own key instead of riding
		// bulk_action: WordPress 7.0's common.js blocks any .bulkactions form
		// submit whose submitter is named bulk_action when core's own select
		// isn't present ("Please select a bulk action to perform."). The
		// bulk_action=export_all branch below stays for back-compat.
		if ( isset( $_POST['nettertech_events_export_all'] ) ) {
			$action = 'export_all';
		}

		if ( empty( $action ) ) {
			return;
		}

		// Sort mode selected on the list, threaded through so CSV exports come
		// out in the order the operator sees (NTE-195). The exporter validates
		// both values against AttendeesPage::SORTABLE_COLUMNS before use.
		$orderby = isset( $_POST['filter_orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_orderby'] ) ) : '';
		$order   = isset( $_POST['filter_order'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_order'] ) ) : '';

		// Handle export_all action (doesn't require selected IDs).
		// Filter values are extracted here (post nonce-verify) and passed in so
		// the helper does not need to read $_POST itself.
		if ( 'export_all' === $action ) {
			$occurrence_id      = isset( $_POST['filter_occurrence_id'] ) ? absint( $_POST['filter_occurrence_id'] ) : 0;
			$event_id           = isset( $_POST['filter_event_id'] ) ? absint( $_POST['filter_event_id'] ) : 0;
			$search             = isset( $_POST['filter_search'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_search'] ) ) : '';
			$status_filter      = isset( $_POST['filter_status'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_status'] ) ) : '';
			$placeholder_filter = isset( $_POST['filter_placeholder'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_placeholder'] ) ) : '';

			$this->handle_export_all( $occurrence_id, $event_id, $search, $status_filter, $placeholder_filter, $orderby, $order );
			return;
		}

		// Extract attendee IDs here (post nonce-verify) so the helper doesn't
		// need to read $_POST. Per-element absint() applies at the boundary,
		// satisfying WordPress.Security.ValidatedSanitizedInput without a
		// suppression (Plugin Check's stricter sniff rejects the in-line
		// suppression form even with a rationale comment).
		$attendee_ids = isset( $_POST['attendee_ids'] ) && is_array( $_POST['attendee_ids'] )
			? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['attendee_ids'] ) ) ) )
			: array();

		if ( empty( $attendee_ids ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-warning is-dismissible"><p>';
					esc_html_e( 'No attendees selected.', 'nettertech-events' );
					echo '</p></div>';
				}
			);
			return;
		}

		if ( 'delete' === $action ) {
			$this->handle_bulk_delete( $attendee_ids );
		} elseif ( 'export' === $action ) {
			$this->exporter->export_selected( $attendee_ids, $orderby, $order );
		} elseif ( 'email' === $action ) {
			$this->handle_bulk_email( $attendee_ids );
		}
	}

	/**
	 * Re-send confirmation emails for the selected attendees' orders.
	 *
	 * Attendees resolve to their WooCommerce orders and each order is mailed
	 * once — selecting five attendees from one order sends one email, not five.
	 * Attendees without an order (free RSVPs, CSV imports) are counted out
	 * loud rather than silently skipped.
	 *
	 * @param array<int> $attendee_ids Selected attendee IDs.
	 * @return void
	 */
	private function handle_bulk_email( array $attendee_ids ): void {
		if ( null === $this->email_handler ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error is-dismissible"><p>';
					esc_html_e( 'Emailing attendees requires WooCommerce.', 'nettertech-events' );
					echo '</p></div>';
				}
			);
			return;
		}

		$attendees_table = Schema::table( 'attendees' );
		$placeholders    = implode( ',', array_fill( 0, count( $attendee_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin bulk action.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table name from Schema; IDs bound via prepare().
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, wc_order_id FROM {$attendees_table} WHERE id IN ( {$placeholders} )",
				$attendee_ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$order_ids = array();
		$no_order  = 0;
		foreach ( (array) $rows as $row ) {
			$order_id = (int) ( $row['wc_order_id'] ?? 0 );
			if ( $order_id > 0 ) {
				$order_ids[ $order_id ] = true;
			} else {
				++$no_order;
			}
		}

		$sent   = 0;
		$failed = 0;
		foreach ( array_keys( $order_ids ) as $order_id ) {
			$result = $this->email_handler->resend_confirmation( $order_id );
			if ( ! empty( $result['success'] ) ) {
				++$sent;
			} else {
				++$failed;
			}
		}

		add_action(
			'admin_notices',
			static function () use ( $sent, $failed, $no_order ) {
				$class = $failed > 0 ? 'notice-warning' : 'notice-success';
				echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>';
				printf(
					/* translators: %d: number of confirmation emails sent. */
					esc_html( _n( '%d confirmation email re-sent.', '%d confirmation emails re-sent.', $sent, 'nettertech-events' ) ),
					(int) $sent
				);
				if ( $failed > 0 ) {
					echo ' ';
					printf(
						/* translators: %d: number of orders that could not be emailed. */
						esc_html( _n( '%d order could not be emailed.', '%d orders could not be emailed.', $failed, 'nettertech-events' ) ),
						(int) $failed
					);
				}
				if ( $no_order > 0 ) {
					echo ' ';
					printf(
						/* translators: %d: number of attendees without an order. */
						esc_html( _n( '%d attendee has no order and was skipped.', '%d attendees have no order and were skipped.', $no_order, 'nettertech-events' ) ),
						(int) $no_order
					);
				}
				echo '</p></div>';
			}
		);
	}

	/**
	 * Handle single attendee delete action.
	 *
	 * @return void
	 */
	public function handle_single_delete(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'nettertech-events' ) );
		}

		// Action discriminator + per-row nonce action key construction. The
		// nonce action key contains the attendee ID
		// (`nettertech_events_delete_attendee_{ID}`), so action + ID must be
		// read before wp_verify_nonce() can build the key it is verifying
		// against. The nonce check below gates every state-changing
		// operation. PHPCS sees the verification in the same scope as the
		// reads below and does not require a per-read suppression.
		if ( ! isset( $_GET['action'] ) || 'nettertech_events_delete_attendee' !== $_GET['action'] ) {
			return;
		}

		$attendee_id = isset( $_GET['attendee_id'] ) ? absint( $_GET['attendee_id'] ) : 0;

		if ( $attendee_id <= 0 ) {
			return;
		}

		// Verify nonce — gates every subsequent state change.
		$nonce_action = 'nettertech_events_delete_attendee_' . $attendee_id;
		if ( ! isset( $_GET['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( esc_html__( 'Your session has expired. Please reload the page and try again.', 'nettertech-events' ) );
		}

		$table = Schema::table( 'attendees' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single delete.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema class is safe.
		$deleted = $this->db->delete(
			$table,
			array( 'id' => $attendee_id ),
			array( '%d' )
		);

		// Redirect to clean URL.
		$redirect_url = admin_url( 'admin.php?page=' . AdminMenu::SUBMENU_ATTENDEES );
		$redirect_url = add_query_arg( 'nettertech_events_deleted', $deleted ? 1 : 0, $redirect_url );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle bulk delete action.
	 *
	 * @param array<int> $attendee_ids Attendee IDs to delete.
	 * @return void
	 */
	private function handle_bulk_delete( array $attendee_ids ): void {
		$table = Schema::table( 'attendees' );
		$count = count( $attendee_ids );

		// Build placeholders for IN clause.
		$placeholders = implode( ', ', array_fill( 0, $count, '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk delete requires direct query.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema class is safe.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholders for IN clause.
		$sql     = $this->db->prepare(
			"DELETE FROM {$table} WHERE id IN ({$placeholders})",
			$attendee_ids
		);
		$deleted = null === $sql ? 0 : $this->db->query( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$deleted_count = (int) $deleted;

		// Redirect to avoid form resubmission.
		$redirect_url = remove_query_arg( array( '_wpnonce' ) );
		$redirect_url = add_query_arg( 'nettertech_events_deleted', $deleted_count, $redirect_url );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle export all filtered attendees.
	 *
	 * Filter values are extracted and sanitized by handle() (post nonce-verify)
	 * and passed in as parameters, so this method performs no superglobal reads.
	 *
	 * @param int    $occurrence_id      Filter: occurrence ID.
	 * @param int    $event_id           Filter: event ID (event-scoped Purchases view).
	 * @param string $search             Filter: search string.
	 * @param string $status_filter      Filter: status.
	 * @param string $placeholder_filter Filter: placeholder flag.
	 * @param string $orderby            Sort key selected on the list.
	 * @param string $order              Sort direction selected on the list.
	 * @return void
	 */
	private function handle_export_all( int $occurrence_id, int $event_id, string $search, string $status_filter, string $placeholder_filter, string $orderby = '', string $order = '' ): void {
		$this->exporter->export_all_filtered( $occurrence_id, $event_id, $search, $status_filter, $placeholder_filter, $orderby, $order );
	}
}
