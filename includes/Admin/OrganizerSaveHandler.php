<?php
/**
 * Organizer save handler class.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Models\Organizer;

/**
 * Handles form submission for organizer add/edit.
 *
 * Validates input, sanitizes, saves via OrganizerRepository.
 *
 * @since 1.8.0
 */
class OrganizerSaveHandler {

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
	 * Register the admin_post hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_nettertech_events_save_organizer', array( $this, 'handle_save' ) );
	}

	/**
	 * Handle form submission.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		// Check permissions first (before nonce to avoid timing attacks).
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nettertech-events' ) );
		}

		// Verify nonce.
		if ( ! isset( $_POST['_nettertech_events_organizer_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nettertech_events_organizer_nonce'] ) ), 'nettertech_events_organizer_save' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'nettertech-events' ) );
		}

		$organizer_id = isset( $_POST['organizer_id'] ) ? absint( $_POST['organizer_id'] ) : 0;

		// Load or create organizer.
		$organizer = $organizer_id > 0 ? $this->repo->find( $organizer_id ) : null;
		if ( null === $organizer ) {
			$organizer = new Organizer();
		}

		// Populate from POST data.
		$organizer->name        = isset( $_POST['organizer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['organizer_name'] ) ) : '';
		$organizer->slug        = isset( $_POST['organizer_slug'] ) ? sanitize_title( wp_unslash( $_POST['organizer_slug'] ) ) : '';
		$organizer->email       = isset( $_POST['organizer_email'] ) && '' !== $_POST['organizer_email']
			? sanitize_email( wp_unslash( $_POST['organizer_email'] ) )
			: null;
		$organizer->phone       = isset( $_POST['organizer_phone'] ) && '' !== $_POST['organizer_phone']
			? sanitize_text_field( wp_unslash( $_POST['organizer_phone'] ) )
			: null;
		$organizer->website     = isset( $_POST['organizer_website'] ) && '' !== $_POST['organizer_website']
			? esc_url_raw( wp_unslash( $_POST['organizer_website'] ) )
			: null;
		$organizer->description = isset( $_POST['organizer_description'] )
			? sanitize_textarea_field( wp_unslash( $_POST['organizer_description'] ) )
			: '';

		// Auto-generate slug from name if empty.
		if ( empty( $organizer->slug ) && ! empty( $organizer->name ) ) {
			$organizer->slug = sanitize_title( $organizer->name );
		}

		try {
			$this->repo->save( $organizer );
			$message = $organizer_id > 0 ? 'updated' : 'created';
		} catch ( \RuntimeException $e ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => OrganizerPage::PAGE_SLUG,
						'action'  => $organizer_id > 0 ? 'edit' : 'add',
						'id'      => $organizer_id,
						'message' => 'error',
						'error'   => rawurlencode( $e->getMessage() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => OrganizerPage::PAGE_SLUG,
					'message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
