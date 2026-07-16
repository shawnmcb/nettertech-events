<?php
/**
 * Spaces list table class.
 *
 * @package NetterTechEvents\Admin\ListTables
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\ListTables;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\AdminRequest;
use NetterTechEvents\Admin\SpacesPage;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;

// Load WP_List_Table if not available.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Spaces list table for admin display.
 *
 * @since 2.1.0
 */
class SpacesListTable extends \WP_List_Table {

	/**
	 * Space repository.
	 *
	 * @var SpaceRepositoryInterface
	 */
	private SpaceRepositoryInterface $space_repo;

	/**
	 * Constructor.
	 *
	 * @param SpaceRepositoryInterface $space_repo Space repository.
	 */
	public function __construct( SpaceRepositoryInterface $space_repo ) {
		parent::__construct(
			array(
				'singular' => 'space',
				'plural'   => 'spaces',
				'ajax'     => false,
			)
		);

		$this->space_repo = $space_repo;
	}

	/**
	 * Get columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'       => '<input type="checkbox">',
			'name'     => __( 'Name', 'nettertech-events' ),
			'capacity' => __( 'Capacity', 'nettertech-events' ),
			'status'   => __( 'Status', 'nettertech-events' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function get_sortable_columns(): array {
		return array(
			'name'     => array( 'name', false ),
			'capacity' => array( 'capacity', false ),
		);
	}

	/**
	 * Get bulk actions.
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Delete', 'nettertech-events' ),
		);
	}

	/**
	 * Prepare items for display.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$orderby = AdminRequest::get_orderby( 'name', array( 'name', 'capacity' ) );
		$order   = strtoupper( AdminRequest::get_order( 'asc' ) );

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$spaces = $this->space_repo->paginate(
			array(
				'orderby' => $orderby,
				'order'   => $order,
				'status'  => '',
				'limit'   => $per_page,
				'offset'  => $offset,
			)
		);

		$total_items = $this->space_repo->count( array( 'status' => '' ) );

		$this->items = $spaces;

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Render checkbox column.
	 *
	 * @param \NetterTechEvents\Models\Space $item Space object.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="space[]" value="%d">',
			$item->id
		);
	}

	/**
	 * Render name column.
	 *
	 * @param \NetterTechEvents\Models\Space $item Space object.
	 * @return string
	 */
	public function column_name( $item ): string {
		$edit_url   = add_query_arg(
			array(
				'page'   => SpacesPage::PAGE_SLUG,
				'action' => 'edit',
				'id'     => $item->id,
			),
			admin_url( 'admin.php' )
		);
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => SpacesPage::PAGE_SLUG,
					'action' => 'delete',
					'id'     => $item->id,
				),
				admin_url( 'admin.php' )
			),
			'nettertech_events_space_delete_' . $item->id
		);

		$actions = array(
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				__( 'Edit', 'nettertech-events' )
			),
			'delete' => sprintf(
				'<a href="%s" class="nte-delete-space" data-name="%s">%s</a>',
				esc_url( $delete_url ),
				esc_attr( $item->name ),
				__( 'Delete', 'nettertech-events' )
			),
		);

		$title = sprintf(
			'<strong><a href="%s" class="row-title">%s</a></strong>',
			esc_url( $edit_url ),
			esc_html( $item->name )
		);

		return $title . $this->row_actions( $actions );
	}

	/**
	 * Render capacity column.
	 *
	 * @param \NetterTechEvents\Models\Space $item Space object.
	 * @return string
	 */
	public function column_capacity( $item ): string {
		return esc_html( (string) $item->capacity );
	}

	/**
	 * Render status column.
	 *
	 * @param \NetterTechEvents\Models\Space $item Space object.
	 * @return string
	 */
	public function column_status( $item ): string {
		return esc_html( ucfirst( $item->status ) );
	}

	/**
	 * Default column rendering.
	 *
	 * @param \NetterTechEvents\Models\Space $item        Space object.
	 * @param string                         $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item->$column_name ?? '' ) );
	}

	/**
	 * Display when no items.
	 *
	 * @return void
	 */
	public function no_items(): void {
		$new_url = add_query_arg(
			array(
				'page'   => SpacesPage::PAGE_SLUG,
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="nte-empty-state">';
		echo '<p>' . esc_html__( 'No spaces found.', 'nettertech-events' ) . '</p>';
		printf(
			'<p><a href="%s" class="button button-primary">%s</a></p>',
			esc_url( $new_url ),
			esc_html__( 'Add New Space', 'nettertech-events' )
		);
		echo '</div>';
	}
}
