<?php
/**
 * Spaces admin page.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\ListTables\SpacesListTable;
use NetterTechEvents\Admin\Spaces\Presenters\SpacesNoticesPresenter;
use NetterTechEvents\Admin\Spaces\SpacesBulkActions;
use NetterTechEvents\Catalog\AccessibilityFeature;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;
use NetterTechEvents\Enums\SeatingModel;
use NetterTechEvents\Models\Space;

/**
 * CRUD admin page for event spaces/venues.
 *
 * Manages nettertech_events_spaces table entries with list, add, edit, and delete actions.
 *
 * @since 2.1.0
 */
class SpacesPage {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'nettertech-events-spaces';

	/**
	 * Space repository.
	 *
	 * @var SpaceRepositoryInterface
	 */
	private SpaceRepositoryInterface $repo;

	/**
	 * Bulk actions handler.
	 *
	 * @var SpacesBulkActions
	 */
	private SpacesBulkActions $bulk_actions;

	/**
	 * Constructor.
	 *
	 * @param SpaceRepositoryInterface $repo         Space repository.
	 * @param SpacesBulkActions        $bulk_actions Bulk actions handler.
	 */
	public function __construct( SpaceRepositoryInterface $repo, SpacesBulkActions $bulk_actions ) {
		$this->repo         = $repo;
		$this->bulk_actions = $bulk_actions;
	}

	/**
	 * Render the spaces page.
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
	 * with a per-row nonce action (`nettertech_events_space_delete_{ID}`).
	 * Bulk deletes use `?action=delete&space[]=...` and are handled by
	 * {@see SpacesBulkActions::handle()}.
	 *
	 * @return void
	 */
	private function handle_delete_action(): void {
		$action = AdminRequest::get_key( 'action' );
		if ( 'delete' !== $action ) {
			return;
		}

		// Discriminate single vs. bulk: single uses `id`, bulk uses `space[]`.
		if ( ! AdminRequest::has( 'id' ) || AdminRequest::has( 'space' ) ) {
			return;
		}

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'nettertech-events' ) );
		}

		$space_id = AdminRequest::get_absint( 'id' );
		if ( $space_id <= 0 ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
				'nettertech_events_space_delete_' . $space_id
			)
		) {
			wp_die( esc_html__( 'Security check failed.', 'nettertech-events' ) );
		}

		$this->repo->delete( $space_id );

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
	 * Render the spaces list view.
	 *
	 * @return void
	 */
	private function render_list(): void {
		$list_table = new SpacesListTable( $this->repo );
		$list_table->prepare_items();

		$page_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$this->enqueue_delete_confirmation_script();

		?>
		<div class="wrap">
			<?php Branding::render_header(); ?>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Spaces', 'nettertech-events' ); ?></h1>
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
		$handle = 'nettertech-events-spaces-list';
		$config = array(
			'selector' => '.nte-delete-space',
			'message'  => __( 'Are you sure you want to delete this space?', 'nettertech-events' ),
		);

		wp_register_script( $handle, false, array(), NETTERTECH_EVENTS_VERSION, true );
		wp_enqueue_script( $handle );
		wp_add_inline_script(
			$handle,
			'window.nettertechEventsSpacesList = ' . wp_json_encode( $config ) . ';
document.addEventListener("DOMContentLoaded", function() {
	document.querySelectorAll(window.nettertechEventsSpacesList.selector).forEach(function(link) {
		link.addEventListener("click", function(e) {
			var name = this.getAttribute("data-name") || "";
			if (!confirm(window.nettertechEventsSpacesList.message + " \"" + name + "\"")) {
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
		$space    = null;
		$page_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		if ( 'edit' === $action ) {
			$id    = AdminRequest::get_absint( 'id' );
			$space = $id > 0 ? $this->repo->find( $id ) : null;

			if ( ! $space ) {
				wp_die( esc_html__( 'Space not found.', 'nettertech-events' ) );
			}
		}

		$is_edit = null !== $space;
		$title   = $is_edit ? __( 'Edit Space', 'nettertech-events' ) : __( 'Add New Space', 'nettertech-events' );

		// Enqueue Media Library for image pickers.
		wp_enqueue_media();
		wp_enqueue_style( 'nettertech-events-space-form' );
		wp_enqueue_script( 'nettertech-events-space-form' );

		// Decode existing accessibility features and gallery for the JS bootstrap.
		$existing_features = $space ? $space->get_accessibility_features() : array();
		$gallery_ids       = array();
		if ( $space && ! empty( $space->gallery_image_ids ) ) {
			$decoded = json_decode( $space->gallery_image_ids, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $g_id ) {
					$gallery_ids[] = (int) $g_id;
				}
			}
		}

		?>
		<div class="wrap nte-space-form">
			<?php Branding::render_header(); ?>
			<h1><?php echo esc_html( $title ); ?></h1>

			<?php $this->render_notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nettertech_events_save_space">
				<input type="hidden" name="space_id" value="<?php echo esc_attr( (string) ( $space->id ?? 0 ) ); ?>">
				<?php wp_nonce_field( 'nettertech_events_space_save', '_nettertech_events_space_nonce' ); ?>

				<h2><?php esc_html_e( 'Basics', 'nettertech-events' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="space_name"><?php esc_html_e( 'Name', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="text" name="space_name" id="space_name" class="regular-text"
								value="<?php echo esc_attr( $space->name ?? '' ); ?>" required>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="space_slug"><?php esc_html_e( 'Slug', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="text" name="space_slug" id="space_slug" class="regular-text"
								value="<?php echo esc_attr( $space->slug ?? '' ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty to auto-generate from name.', 'nettertech-events' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="space_tagline"><?php esc_html_e( 'Tagline', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="text" name="space_tagline" id="space_tagline" class="large-text"
								value="<?php echo esc_attr( $space->tagline ?? '' ); ?>"
								maxlength="255">
							<p class="description"><?php esc_html_e( 'Optional short subtitle, e.g. "Main Hall — 400 Seats — Premium".', 'nettertech-events' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="space_description"><?php esc_html_e( 'Description', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<textarea name="space_description" id="space_description" rows="5" class="large-text"><?php echo esc_textarea( $space->description ?? '' ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="space_status"><?php esc_html_e( 'Status', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<select name="space_status" id="space_status">
								<?php foreach ( Space::STATUSES as $status_value ) : ?>
									<option value="<?php echo esc_attr( $status_value ); ?>"
										<?php selected( $space->status ?? 'active', $status_value ); ?>>
										<?php echo esc_html( ucfirst( $status_value ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="space_sort_order"><?php esc_html_e( 'Sort Order', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="number" name="space_sort_order" id="space_sort_order" class="small-text"
								value="<?php echo esc_attr( (string) ( $space->sort_order ?? 0 ) ); ?>">
							<p class="description"><?php esc_html_e( 'Lower numbers appear first in lists.', 'nettertech-events' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Imagery', 'nettertech-events' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="space_featured_image_id"><?php esc_html_e( 'Featured Image', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="space_featured_image_id" id="space_featured_image_id"
								value="<?php echo esc_attr( (string) ( $space->featured_image_id ?? '' ) ); ?>">
							<div class="nte-media-picker" data-target="space_featured_image_id" data-mode="single">
								<div class="nte-media-picker__preview">
									<?php if ( ! empty( $space->featured_image_id ) ) : ?>
										<?php echo wp_get_attachment_image( (int) $space->featured_image_id, 'medium' ); // wp_get_attachment_image() returns pre-escaped HTML. ?>
									<?php endif; ?>
								</div>
								<button type="button" class="button nte-media-picker__select">
									<?php esc_html_e( 'Choose image', 'nettertech-events' ); ?>
								</button>
								<button type="button" class="button-link nte-media-picker__clear" <?php echo empty( $space->featured_image_id ) ? 'hidden' : ''; ?>>
									<?php esc_html_e( 'Remove', 'nettertech-events' ); ?>
								</button>
							</div>
							<p class="description"><?php esc_html_e( 'Hero image for the public space detail page.', 'nettertech-events' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="space_gallery_image_ids"><?php esc_html_e( 'Gallery', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="space_gallery_image_ids" id="space_gallery_image_ids"
								value="<?php echo esc_attr( implode( ',', $gallery_ids ) ); ?>">
							<div class="nte-media-picker" data-target="space_gallery_image_ids" data-mode="multiple">
								<div class="nte-media-picker__gallery">
									<?php foreach ( $gallery_ids as $gid ) : ?>
										<span class="nte-media-picker__thumb" data-id="<?php echo esc_attr( (string) $gid ); ?>">
											<?php echo wp_get_attachment_image( $gid, 'thumbnail' ); // wp_get_attachment_image() returns pre-escaped HTML. ?>
										</span>
									<?php endforeach; ?>
								</div>
								<button type="button" class="button nte-media-picker__select">
									<?php esc_html_e( 'Choose images', 'nettertech-events' ); ?>
								</button>
								<button type="button" class="button-link nte-media-picker__clear" <?php echo empty( $gallery_ids ) ? 'hidden' : ''; ?>>
									<?php esc_html_e( 'Clear all', 'nettertech-events' ); ?>
								</button>
							</div>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Capacity', 'nettertech-events' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="space_capacity"><?php esc_html_e( 'Capacity', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="number" name="space_capacity" id="space_capacity" class="small-text"
								value="<?php echo esc_attr( (string) ( $space->capacity ?? 0 ) ); ?>" min="0">
							<p class="description"><?php esc_html_e( 'Maximum occupancy.', 'nettertech-events' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="space_square_footage"><?php esc_html_e( 'Square Footage', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="number" name="space_square_footage" id="space_square_footage" class="small-text"
								value="<?php echo esc_attr( (string) ( $space->square_footage ?? '' ) ); ?>" min="0">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="space_seating_model"><?php esc_html_e( 'Seating Model', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<select name="space_seating_model" id="space_seating_model">
								<?php foreach ( SeatingModel::cases() as $case ) : ?>
									<option value="<?php echo esc_attr( $case->value ); ?>"
										<?php selected( $space->seating_model ?? 'free', $case->value ); ?>>
										<?php echo esc_html( $case->label() ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Accessibility', 'nettertech-events' ); ?></h2>
				<p class="description" style="margin-bottom: 1em;">
					<?php esc_html_e( 'Choose the features the space provides. Add a count when relevant (e.g., "8 wheelchair positions"). Use "Add custom feature" only when nothing in the preset list fits.', 'nettertech-events' ); ?>
				</p>
				<input type="hidden" name="space_accessibility_features" id="space_accessibility_features"
					value="<?php echo esc_attr( (string) wp_json_encode( $existing_features ) ); ?>">
				<div class="nte-a11y-editor"
					data-target="space_accessibility_features"
					data-presets="<?php echo esc_attr( (string) wp_json_encode( AccessibilityFeature::presets() ) ); ?>">
					<div class="nte-a11y-editor__rows"></div>
					<p>
						<button type="button" class="button nte-a11y-editor__add-preset">
							<?php esc_html_e( 'Add feature', 'nettertech-events' ); ?>
						</button>
						<button type="button" class="button-secondary nte-a11y-editor__add-custom">
							<?php esc_html_e( 'Add custom feature', 'nettertech-events' ); ?>
						</button>
					</p>
				</div>

				<h2><?php esc_html_e( 'Other amenities', 'nettertech-events' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="space_amenities"><?php esc_html_e( 'Amenities', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<textarea name="space_amenities" id="space_amenities" rows="3" class="large-text"><?php echo esc_textarea( $space->amenities ?? '' ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Free-form list of non-accessibility amenities (lighting, sound, projection, etc.). Comma-separated or JSON.', 'nettertech-events' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( $is_edit ? __( 'Update Space', 'nettertech-events' ) : __( 'Add Space', 'nettertech-events' ) ); ?>
			</form>

			<p>
				<a href="<?php echo esc_url( $page_url ); ?>">&larr; <?php esc_html_e( 'Back to Spaces', 'nettertech-events' ); ?></a>
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

		$message_key = AdminRequest::get_text( 'message' );
		$error_text  = AdminRequest::has( 'error' ) ? AdminRequest::get_text( 'error' ) : null;

		$presenter = new SpacesNoticesPresenter( $message_key, $error_text );
		include dirname( __DIR__, 2 ) . '/templates/admin/spaces/notices.php';
	}
}
