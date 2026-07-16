<?php
/**
 * Spaces Bulk Actions Handler.
 *
 * @package NetterTechEvents\Admin\Spaces
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Spaces;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\SpacesPage;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;

/**
 * Handles bulk actions for the Spaces admin page.
 *
 * Extracted from SpacesListTable so the list-table view class is read-only
 * display logic and all mutating $_GET/$_POST access lives in an explicit
 * handler with inline nonce verification.
 *
 * The WP_List_Table bulk-action form submits via GET (the default for
 * list-table forms), so this handler reads from $_GET. The nonce key is
 * `bulk-{plural}` — set by WP core in `WP_List_Table::display_tablenav()`.
 *
 * @since 1.0.2
 */
final class SpacesBulkActions {

	/**
	 * Space repository.
	 *
	 * @var SpaceRepositoryInterface
	 */
	private SpaceRepositoryInterface $repo;

	/**
	 * Constructor.
	 *
	 * @param SpaceRepositoryInterface $repo Space repository.
	 */
	public function __construct( SpaceRepositoryInterface $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Handle bulk actions for the Spaces list table.
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

		// `space[]` is only present on bulk submissions; `id` is the single
		// delete path handled by SpacesPage::handle_delete_action().
		if ( ! isset( $_GET['space'] ) ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
				'bulk-spaces'
			)
		) {
			return;
		}

		$space_ids = array_values(
			array_filter(
				array_map( 'absint', (array) wp_unslash( $_GET['space'] ) )
			)
		);

		if ( empty( $space_ids ) ) {
			return;
		}

		foreach ( $space_ids as $id ) {
			$this->repo->delete( $id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => SpacesPage::PAGE_SLUG,
					'message' => 'deleted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
