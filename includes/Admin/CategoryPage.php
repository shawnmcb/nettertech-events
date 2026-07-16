<?php
/**
 * Category admin page.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\Categories\Presenters\CategoryNoticesPresenter;
use NetterTechEvents\Models\Category;
use NetterTechEvents\Repositories\CategoryRepository;

/**
 * CRUD admin page for event categories.
 *
 * Manages nettertech_events_categories table entries with list, add, edit, and delete actions.
 *
 * @since 1.0.0
 */
class CategoryPage {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'nettertech-events-categories';

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'nettertech_events_category_save';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	private const NONCE_FIELD = '_nettertech_events_category_nonce';

	/**
	 * Category repository.
	 *
	 * @var CategoryRepository
	 */
	private CategoryRepository $repo;

	/**
	 * Constructor.
	 *
	 * @param CategoryRepository $repo Category repository.
	 */
	public function __construct( CategoryRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Render the category page.
	 *
	 * Routes to list, add, edit, or handles delete based on action parameter.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nettertech-events' ) );
		}

		// Handle form submissions (verifies nonce + sanitizes inline).
		$this->handle_actions();

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
	 * Handle POST actions (save, delete).
	 *
	 * Verifies the appropriate nonce inline, then unslashes the $_POST
	 * array once at this boundary and passes it down to the per-action
	 * helpers. Helpers do their own per-field sanitization on the
	 * already-unslashed array. PHPCS recognizes the in-scope
	 * wp_verify_nonce() call and does not require per-field suppressions.
	 *
	 * @return void
	 */
	private function handle_actions(): void {
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'nettertech-events' ) );
		}

		// Handle save.
		if ( isset( $_POST[ self::NONCE_FIELD ] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
				wp_die( esc_html__( 'Your session has expired. Please reload the page and try again.', 'nettertech-events' ) );
			}

			$post = wp_unslash( $_POST );
			$this->handle_save( is_array( $post ) ? $post : array() );
			return;
		}

		// Handle delete.
		if ( isset( $_POST['_nettertech_events_category_delete_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nettertech_events_category_delete_nonce'] ) ), 'nettertech_events_category_delete' ) ) {
				wp_die( esc_html__( 'Your session has expired. Please reload the page and try again.', 'nettertech-events' ) );
			}

			$post = wp_unslash( $_POST );
			$this->handle_delete( is_array( $post ) ? $post : array() );
		}
	}

	/**
	 * Handle category save (create or update).
	 *
	 * @param array<string, mixed> $post Unslashed $_POST array (nonce verified by caller).
	 * @return void
	 */
	private function handle_save( array $post ): void {
		$category_id = isset( $post['category_id'] ) ? absint( $post['category_id'] ) : 0;

		$category = $category_id > 0 ? $this->repo->find( $category_id ) : null;
		if ( null === $category ) {
			$category = new Category();
		}

		$category->name        = isset( $post['category_name'] ) ? sanitize_text_field( (string) $post['category_name'] ) : '';
		$category->slug        = isset( $post['category_slug'] ) ? sanitize_title( (string) $post['category_slug'] ) : '';
		$category->description = isset( $post['category_description'] ) ? sanitize_textarea_field( (string) $post['category_description'] ) : '';
		$category->parent_id   = isset( $post['category_parent'] ) && '' !== $post['category_parent'] ? absint( $post['category_parent'] ) : null;
		$category->sort_order  = isset( $post['category_sort_order'] ) ? absint( $post['category_sort_order'] ) : 0;

		// Auto-generate slug from name if empty.
		if ( empty( $category->slug ) && ! empty( $category->name ) ) {
			$category->slug = sanitize_title( $category->name );
		}

		try {
			$this->repo->save( $category );
			$message = $category_id > 0 ? 'updated' : 'created';
		} catch ( \RuntimeException $e ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => self::PAGE_SLUG,
						'action'  => $category_id > 0 ? 'edit' : 'add',
						'id'      => $category_id,
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
					'page'    => self::PAGE_SLUG,
					'message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle category deletion.
	 *
	 * @param array<string, mixed> $post Unslashed $_POST array (nonce verified by caller).
	 * @return void
	 */
	private function handle_delete( array $post ): void {
		$category_id = isset( $post['category_id'] ) ? absint( $post['category_id'] ) : 0;

		if ( $category_id > 0 ) {
			$this->repo->delete( $category_id );
		}

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
	 * Render the categories list view.
	 *
	 * @return void
	 */
	private function render_list(): void {
		$categories   = $this->repo->get_all(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);
		$event_counts = $this->repo->get_event_counts();
		$page_url     = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$this->enqueue_delete_confirmation_script();

		?>
		<div class="wrap">
			<?php Branding::render_header(); ?>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Event Categories', 'nettertech-events' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( 'action', 'add', $page_url ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'nettertech-events' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<?php if ( empty( $categories ) ) : ?>
				<p><?php esc_html_e( 'No categories found. Create your first category.', 'nettertech-events' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col" class="column-name"><?php esc_html_e( 'Name', 'nettertech-events' ); ?></th>
							<th scope="col" class="column-slug"><?php esc_html_e( 'Slug', 'nettertech-events' ); ?></th>
							<th scope="col" class="column-description"><?php esc_html_e( 'Description', 'nettertech-events' ); ?></th>
							<th scope="col" class="column-parent"><?php esc_html_e( 'Parent', 'nettertech-events' ); ?></th>
							<th scope="col" class="column-sort-order"><?php esc_html_e( 'Sort Order', 'nettertech-events' ); ?></th>
							<th scope="col" class="column-count"><?php esc_html_e( 'Events', 'nettertech-events' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$categories_by_id = array();
						foreach ( $categories as $cat ) {
							$categories_by_id[ $cat->id ] = $cat;
						}

						foreach ( $categories as $category ) :
							$edit_url    = add_query_arg(
								array(
									'action' => 'edit',
									'id'     => $category->id,
								),
								$page_url
							);
							$parent_name = '';
							if ( $category->parent_id && isset( $categories_by_id[ $category->parent_id ] ) ) {
								$parent_name = $categories_by_id[ $category->parent_id ]->name;
							}
							$count = $event_counts[ $category->id ] ?? 0;
							?>
							<tr>
								<td class="column-name">
									<strong>
										<a href="<?php echo esc_url( $edit_url ); ?>">
											<?php echo esc_html( $category->name ); ?>
										</a>
									</strong>
									<div class="row-actions">
										<span class="edit">
											<a href="<?php echo esc_url( $edit_url ); ?>">
												<?php esc_html_e( 'Edit', 'nettertech-events' ); ?>
											</a> |
										</span>
										<span class="delete">
											<button type="button" class="button-link nte-delete-category" data-id="<?php echo esc_attr( (string) $category->id ); ?>" data-name="<?php echo esc_attr( $category->name ); ?>">
												<?php esc_html_e( 'Delete', 'nettertech-events' ); ?>
											</button>
										</span>
									</div>
								</td>
								<td class="column-slug"><code><?php echo esc_html( $category->slug ); ?></code></td>
								<td class="column-description"><?php echo esc_html( wp_trim_words( $category->description, 10 ) ); ?></td>
								<td class="column-parent"><?php echo esc_html( $parent_name ); ?></td>
								<td class="column-sort-order"><?php echo esc_html( (string) $category->sort_order ); ?></td>
								<td class="column-count"><?php echo esc_html( (string) $count ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<!-- Hidden delete form -->
			<form id="nte-category-delete-form" method="post" style="display:none;">
				<?php wp_nonce_field( 'nettertech_events_category_delete', '_nettertech_events_category_delete_nonce' ); ?>
				<input type="hidden" name="category_id" id="nte-delete-category-id" value="">
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
		$handle = 'nettertech-events-categories-list';
		$config = array(
			'selector'     => '.nte-delete-category',
			'message'      => __( 'Are you sure you want to delete this category?', 'nettertech-events' ),
			'idField'      => 'nte-delete-category-id',
			'deleteFormId' => 'nte-category-delete-form',
			'dataIdAttr'   => 'data-id',
			'dataNameAttr' => 'data-name',
		);

		wp_register_script( $handle, false, array(), NETTERTECH_EVENTS_VERSION, true );
		wp_enqueue_script( $handle );
		wp_add_inline_script(
			$handle,
			'window.nettertechEventsCategoriesList = ' . wp_json_encode( $config ) . ';
document.addEventListener("DOMContentLoaded", function() {
	document.querySelectorAll(window.nettertechEventsCategoriesList.selector).forEach(function(btn) {
		btn.addEventListener("click", function() {
			var name = this.getAttribute(window.nettertechEventsCategoriesList.dataNameAttr) || "";
			var idField = document.getElementById(window.nettertechEventsCategoriesList.idField);
			var deleteForm = document.getElementById(window.nettertechEventsCategoriesList.deleteFormId);

			if (confirm(window.nettertechEventsCategoriesList.message + " \"" + name + "\"") && idField && deleteForm) {
				idField.value = this.getAttribute(window.nettertechEventsCategoriesList.dataIdAttr) || "";
				deleteForm.submit();
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
		$category = null;
		$page_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		if ( 'edit' === $action ) {
			$id       = AdminRequest::get_absint( 'id' );
			$category = $id > 0 ? $this->repo->find( $id ) : null;

			if ( ! $category ) {
				wp_die( esc_html__( 'Category not found.', 'nettertech-events' ) );
			}
		}

		$all_categories = $this->repo->get_all(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);
		$is_edit        = null !== $category;
		$title          = $is_edit ? __( 'Edit Category', 'nettertech-events' ) : __( 'Add New Category', 'nettertech-events' );

		?>
		<div class="wrap">
			<?php Branding::render_header(); ?>
			<h1><?php echo esc_html( $title ); ?></h1>

			<?php $this->render_notices(); ?>

			<form method="post" action="<?php echo esc_url( $page_url ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="category_id" value="<?php echo esc_attr( (string) ( $category->id ?? 0 ) ); ?>">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="category_name"><?php esc_html_e( 'Name', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="text" name="category_name" id="category_name" class="regular-text"
								value="<?php echo esc_attr( $category->name ?? '' ); ?>" required>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="category_slug"><?php esc_html_e( 'Slug', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="text" name="category_slug" id="category_slug" class="regular-text"
								value="<?php echo esc_attr( $category->slug ?? '' ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty to auto-generate from name.', 'nettertech-events' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="category_description"><?php esc_html_e( 'Description', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<textarea name="category_description" id="category_description" rows="4" class="large-text"><?php echo esc_textarea( $category->description ?? '' ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="category_parent"><?php esc_html_e( 'Parent Category', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<select name="category_parent" id="category_parent">
								<option value=""><?php esc_html_e( '&mdash; None &mdash;', 'nettertech-events' ); ?></option>
								<?php
								foreach ( $all_categories as $cat ) :
									// Don't allow category to be its own parent.
									if ( $is_edit && $cat->id === $category->id ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( (string) $cat->id ); ?>"
										<?php selected( $category->parent_id ?? 0, $cat->id ); ?>>
										<?php echo esc_html( $cat->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="category_sort_order"><?php esc_html_e( 'Sort Order', 'nettertech-events' ); ?></label>
						</th>
						<td>
							<input type="number" name="category_sort_order" id="category_sort_order" class="small-text"
								value="<?php echo esc_attr( (string) ( $category->sort_order ?? 0 ) ); ?>" min="0">
						</td>
					</tr>
				</table>

				<?php submit_button( $is_edit ? __( 'Update Category', 'nettertech-events' ) : __( 'Add Category', 'nettertech-events' ) ); ?>
			</form>

			<p>
				<a href="<?php echo esc_url( $page_url ); ?>">&larr; <?php esc_html_e( 'Back to Categories', 'nettertech-events' ); ?></a>
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

		$presenter = new CategoryNoticesPresenter( $message_key, $error_text );
		include dirname( __DIR__, 2 ) . '/templates/admin/categories/notices.php';
	}
}
