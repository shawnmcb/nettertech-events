<?php
/**
 * Organizer list table class.
 *
 * @package NetterTechEvents\Admin\ListTables
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\ListTables;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\AdminRequest;
use NetterTechEvents\Admin\OrganizerPage;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;

// Load WP_List_Table if not available.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Organizer list table for admin display.
 *
 * @since 1.8.0
 */
class OrganizerListTable extends \WP_List_Table {

	/**
	 * Organizer repository.
	 *
	 * @var OrganizerRepositoryInterface
	 */
	private OrganizerRepositoryInterface $organizer_repo;

	/**
	 * Event counts per organizer.
	 *
	 * @var array<int, int>
	 */
	private array $event_counts = array();

	/**
	 * Constructor.
	 *
	 * @param OrganizerRepositoryInterface $organizer_repo Organizer repository.
	 */
	public function __construct( OrganizerRepositoryInterface $organizer_repo ) {
		parent::__construct(
			array(
				'singular' => 'organizer',
				'plural'   => 'organizers',
				'ajax'     => false,
			)
		);

		$this->organizer_repo = $organizer_repo;
	}

	/**
	 * Get columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'     => '<input type="checkbox">',
			'name'   => __( 'Name', 'nettertech-events' ),
			'email'  => __( 'Email', 'nettertech-events' ),
			'phone'  => __( 'Phone', 'nettertech-events' ),
			'events' => __( 'Events', 'nettertech-events' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function get_sortable_columns(): array {
		return array(
			'name' => array( 'name', false ),
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

		$orderby = AdminRequest::get_orderby( 'name', array( 'name' ) );
		$order   = strtoupper( AdminRequest::get_order( 'asc' ) );

		$organizers = $this->organizer_repo->get_all(
			array(
				'orderby' => $orderby,
				'order'   => $order,
			)
		);

		$this->items = $organizers;

		// Load event counts.
		$this->event_counts = $this->organizer_repo->get_event_counts();

		$this->set_pagination_args(
			array(
				'total_items' => count( $organizers ),
				'per_page'    => count( $organizers ),
				'total_pages' => 1,
			)
		);
	}

	/**
	 * Render checkbox column.
	 *
	 * @param \NetterTechEvents\Models\Organizer $item Organizer object.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="organizer[]" value="%d">',
			$item->id
		);
	}

	/**
	 * Render name column.
	 *
	 * @param \NetterTechEvents\Models\Organizer $item Organizer object.
	 * @return string
	 */
	public function column_name( $item ): string {
		$edit_url   = add_query_arg(
			array(
				'page'   => OrganizerPage::PAGE_SLUG,
				'action' => 'edit',
				'id'     => $item->id,
			),
			admin_url( 'admin.php' )
		);
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => OrganizerPage::PAGE_SLUG,
					'action' => 'delete',
					'id'     => $item->id,
				),
				admin_url( 'admin.php' )
			),
			'nettertech_events_organizer_delete_' . $item->id
		);

		$actions = array(
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				__( 'Edit', 'nettertech-events' )
			),
			'delete' => sprintf(
				'<a href="%s" class="nte-delete-organizer" data-name="%s">%s</a>',
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
	 * Render email column.
	 *
	 * @param \NetterTechEvents\Models\Organizer $item Organizer object.
	 * @return string
	 */
	public function column_email( $item ): string {
		return esc_html( $item->email ?? '' );
	}

	/**
	 * Render phone column.
	 *
	 * @param \NetterTechEvents\Models\Organizer $item Organizer object.
	 * @return string
	 */
	public function column_phone( $item ): string {
		return esc_html( $item->phone ?? '' );
	}

	/**
	 * Render events count column.
	 *
	 * @param \NetterTechEvents\Models\Organizer $item Organizer object.
	 * @return string
	 */
	public function column_events( $item ): string {
		$count = $this->event_counts[ $item->id ] ?? 0;
		return esc_html( (string) $count );
	}

	/**
	 * Default column rendering.
	 *
	 * @param \NetterTechEvents\Models\Organizer $item        Organizer object.
	 * @param string                             $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		return esc_html( $item->$column_name ?? '' );
	}

	/**
	 * Display when no items.
	 *
	 * @return void
	 */
	public function no_items(): void {
		$new_url = add_query_arg(
			array(
				'page'   => OrganizerPage::PAGE_SLUG,
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="nte-empty-state">';
		echo '<p>' . esc_html__( 'No organizers found.', 'nettertech-events' ) . '</p>';
		printf(
			'<p><a href="%s" class="button button-primary">%s</a></p>',
			esc_url( $new_url ),
			esc_html__( 'Add New Organizer', 'nettertech-events' )
		);
		echo '</div>';
	}
}
