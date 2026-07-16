<?php
/**
 * Organizer admin page.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\ListTables\OrganizerListTable;
use NetterTechEvents\Admin\Organizers\OrganizerBulkActions;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;

/**
 * CRUD admin page for event organizers.
 *
 * Manages nettertech_events_organizers table entries with list, add, edit, and delete actions.
 *
 * @since 1.8.0
 */
class OrganizerPage {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'nettertech-events-organizers';

	/**
	 * Organizer repository.
	 *
	 * @var OrganizerRepositoryInterface
	 */
	private OrganizerRepositoryInterface $repo;

	/**
	 * Bulk actions handler.
	 *
	 * @var OrganizerBulkActions
	 */
	private OrganizerBulkActions $bulk_actions;

	/**
	 * Constructor.
	 *
	 * @param OrganizerRepositoryInterface $repo         Organizer repository.
	 * @param OrganizerBulkActions         $bulk_actions Bulk actions handler.
	 */
	public function __construct( OrganizerRepositoryInterface $repo, OrganizerBulkActions $bulk_actions ) {
		$this->repo         = $repo;
		$this->bulk_actions = $bulk_actions;
	}

	/**
	 * Render the organizer page.
	 *
	 * Routes to list, add, edit, or handles delete based on action parameter.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nettertech-events' ) );
		}

		// Handle mutating actions (single delete + bulk delete) first; each
		// verifies its own nonce inline and redirects on success.
		$this->handle_delete_action();
		$this->bulk_actions->handle();

		$action = AdminRequest::get_key( 'action', 'list' );

		switch ( $action ) {
			case 'add':
			case 'edit':
				$this->render_form( $action );
				break;
			default:
				$this->render_list();
				break;
		}
	}

	/**
	 * Handle single-row delete action from URL.
	 *
	 * Single-row deletes use the `?action=delete&id=N&_wpnonce=...` pattern
	 * with a per-row nonce action (`nettertech_events_organizer_delete_{ID}`).
	 * Bulk deletes use `?action=delete&organizer[]=...` and are handled by
	 * {@see OrganizerBulkActions::handle()}.
	 *
	 * @return void
	 */
	private function handle_delete_action(): void {
		$action = AdminRequest::get_key( 'action' );
		if ( 'delete' !== $action ) {
			return;
		}

		// Discriminate single vs. bulk: single uses `id`, bulk uses `organizer[]`.
		if ( ! AdminRequest::has( 'id' ) || AdminRequest::has( 'organizer' ) ) {
			return;
		}

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'nettertech-events' ) );
		}

		$organizer_id = AdminRequest::get_absint( 'id' );
		if ( $organizer_id <= 0 ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
				'nettertech_events_organizer_delete_' . $organizer_id
			)
		) {
			wp_die( esc_html__( 'Security check failed.', 'nettertech-events' ) );
		}

		$this->repo->delete( $organizer_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'message' => 'deleted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the organizers list view.
	 *
	 * @return void
	 */
	private function render_list(): void {
		$list_table = new OrganizerListTable( $this->repo );
		$list_table->prepare_items();

		$page_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$this->enqueue_delete_confirmation_script();

		?>
		<div class="wrap">
			<?php Branding::render_header(); ?>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Organizers', 'nettertech-events' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( 'action', 'add', $page_url ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'nettertech-events' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<?php $list_table->display(); ?>
			</form>
		</div>

		<?php
	}

	/**
	 * Enqueue list-page delete confirmation behavior.
	 *
	 * @return void
	 */
	private function enqueue_delete_confirmation_script(): void {
		$handle = 'nettertech-events-organizers-list';
		$config = array(
			'selector' => '.nte-delete-organizer',
			'message'  => __( 'Are you sure you want to delete this organizer?', 'nettertech-events' ),
		);

		wp_register_script( $handle, false, array(), NETTERTECH_EVENTS_VERSION, true );
		wp_enqueue_script( $handle );
		wp_add_inline_script(
			$handle,
			'window.nettertechEventsOrganizerList = ' . wp_json_encode( $config ) . ';
document.addEventListener("DOMContentLoaded", function() {
	document.querySelectorAll(window.nettertechEventsOrganizerList.selector).forEach(function(link) {
		link.addEventListener("click", function(e) {
			var name = this.getAttribute("data-name") || "";
			if (!confirm(window.nettertechEventsOrganizerList.message + " \"" + name + "\"")) {
				e.preventDefault();
			}
		});
	});
});'
		);
	}

	/**
	 * Render the add/edit form.
	 *
	 * @param string $action 'add' or 'edit'.
	 * @return void
	 */
	private function render_form( string $action ): void {
		$organizer = null;
		$page_url  = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		if ( 'edit' === $action ) {
			$id        = AdminRequest::get_absint( 'id' );
			$organizer = $id > 0 ? $this->repo->find( $id ) : null;

			if ( ! $organizer ) {
				wp_die( esc_html__( 'Organizer not found.', 'nettertech-events' ) );
			}
		}

		$is_edit = null !== $organizer;
		$title   = $is_edit ? __( 'Edit Organizer', 'nettertech-events' ) : __( 'Add New Organizer', 'nettertech-events' );

		?>
		<div class="wrap">
			<?php Branding::render_header(); ?>
			<h1><?php echo esc_html( $title ); ?></h1>

			<?php $this->render_notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nettertech_events_save_organizer">
				<input type="hidden" name="organizer_id" value="<?php echo esc_attr( (string) ( $organizer->id ?? 0 ) ); ?>">
				<?php wp_nonce_field( 'nettertech_events_organizer_save', '_nettertech_events_organizer_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="organizer_name"><?php esc_html_e( 'Name', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="text" name="organizer_name" id="organizer_name" class="regular-text"
								value="<?php echo esc_attr( $organizer->name ?? '' ); ?>" required>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="organizer_slug"><?php esc_html_e( 'Slug', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="text" name="organizer_slug" id="organizer_slug" class="regular-text"
								value="<?php echo esc_attr( $organizer->slug ?? '' ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty to auto-generate from name.', 'nettertech-events' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="organizer_email"><?php esc_html_e( 'Email', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="email" name="organizer_email" id="organizer_email" class="regular-text"
								value="<?php echo esc_attr( $organizer->email ?? '' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="organizer_phone"><?php esc_html_e( 'Phone', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="tel" name="organizer_phone" id="organizer_phone" class="regular-text"
								value="<?php echo esc_attr( $organizer->phone ?? '' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="organizer_website"><?php esc_html_e( 'Website', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="url" name="organizer_website" id="organizer_website" class="regular-text"
								value="<?php echo esc_url( $organizer->website ?? '' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="organizer_description"><?php esc_html_e( 'Bio', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<textarea name="organizer_description" id="organizer_description" rows="5" class="large-text"><?php echo esc_textarea( $organizer->description ?? '' ); ?></textarea>
						</td>
					</tr>
				</table>

				<?php submit_button( $is_edit ? __( 'Update Organizer', 'nettertech-events' ) : __( 'Add Organizer', 'nettertech-events' ) ); ?>
			</form>

			<p>
				<a href="<?php echo esc_url( $page_url ); ?>">&larr; <?php esc_html_e( 'Back to Organizers', 'nettertech-events' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render admin notices based on URL parameters.
	 *
	 * @return void
	 */
	private function render_notices(): void {
		if ( ! AdminRequest::has( 'message' ) ) {
			return;
		}

		$message_key = AdminRequest::get_key( 'message' );
		$message     = '';
		$type        = 'success';

		switch ( $message_key ) {
			case 'created':
				$message = __( 'Organizer created.', 'nettertech-events' );
				break;
			case 'updated':
				$message = __( 'Organizer updated.', 'nettertech-events' );
				break;
			case 'deleted':
				$message = __( 'Organizer deleted.', 'nettertech-events' );
				break;
			case 'error':
				$message = AdminRequest::has( 'error' )
					? AdminRequest::get_text( 'error' )
					: __( 'An error occurred.', 'nettertech-events' );
				$type    = 'error';
				break;
		}

		if ( $message ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				esc_html( $message )
			);
		}
	}
}
