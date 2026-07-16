<?php
/**
 * Organizer Bulk Actions Handler.
 *
 * @package NetterTechEvents\Admin\Organizers
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Organizers;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\OrganizerPage;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;

/**
 * Handles bulk actions for the Organizers admin page.
 *
 * Extracted from OrganizerListTable so the list-table view class is
 * read-only display logic and all mutating $_GET access lives in an
 * explicit handler with inline nonce verification.
 *
 * The WP_List_Table bulk-action form submits via GET. The nonce key is
 * `bulk-{plural}` — set by WP core in `WP_List_Table::display_tablenav()`.
 *
 * @since 1.0.2
 */
final class OrganizerBulkActions {

	/**
	 * Organizer repository.
	 *
	 * @var OrganizerRepositoryInterface
	 */
	private OrganizerRepositoryInterface $repo;

	/**
	 * Constructor.
	 *
	 * @param OrganizerRepositoryInterface $repo Organizer repository.
	 */
	public function __construct( OrganizerRepositoryInterface $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Handle bulk actions for the Organizers list table.
	 *
	 * Verifies capability + nonce inline before any state change. PHPCS
	 * recognizes the in-scope `wp_verify_nonce()` and does not require
	 * per-read suppressions for the subsequent $_GET reads.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'delete' !== $action ) {
			return;
		}

		// `organizer[]` is only present on bulk submissions; `id` is the
		// single delete path handled by OrganizerPage::handle_delete_action().
		if ( ! isset( $_GET['organizer'] ) ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
				'bulk-organizers'
			)
		) {
			return;
		}

		$organizer_ids = array_values(
			array_filter(
				array_map( 'absint', (array) wp_unslash( $_GET['organizer'] ) )
			)
		);

		if ( empty( $organizer_ids ) ) {
			return;
		}

		foreach ( $organizer_ids as $id ) {
			$this->repo->delete( $id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => OrganizerPage::PAGE_SLUG,
					'message' => 'deleted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
