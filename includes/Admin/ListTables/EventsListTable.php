<?php
/**
 * Events list table class.
 *
 * @package NetterTechEvents\Admin\ListTables
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\ListTables;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\AdminRequest;
use NetterTechEvents\Admin\EventQuickEditHandler;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceQueryRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Database\Queries\EventQuery;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Services\EventDuplicationService;

// Load WP_List_Table if not available.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Events list table for admin display.
 *
 * @since 0.8.0
 * @api
 */
class EventsListTable extends \WP_List_Table {

	/**
	 * Special event_type filter value for "has ticketing".
	 *
	 * Orthogonal to Event::TYPES (single/recurring); selecting it filters to
	 * events with active ticket types regardless of their single/recurring type.
	 *
	 * @var string
	 */
	private const TICKETED_FILTER = 'ticketed';

	/**
	 * Screen ID of the All Events list page.
	 *
	 * Needed outside a `WP_Screen` context (admin-ajax) to read the same hidden-column
	 * user option Screen Options writes, so AJAX-inserted rows hide the same columns.
	 *
	 * @var string
	 */
	public const SCREEN_ID = 'toplevel_page_nettertech-events';

	/**
	 * Upper bound on the number of events one request may expand.
	 *
	 * @var int
	 */
	public const MAX_EXPANDED = 50;

	/**
	 * GET argument carrying the expanded event IDs.
	 *
	 * @var string
	 */
	public const ARG_EXPANDED = 'expanded';

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Occurrence query repository (batched date bounds).
	 *
	 * @var OccurrenceQueryRepositoryInterface|null
	 */
	private ?OccurrenceQueryRepositoryInterface $occurrence_query_repo;

	/**
	 * Ticket repository (batched confirmed counts).
	 *
	 * @var TicketRepositoryInterface|null
	 */
	private ?TicketRepositoryInterface $ticket_repo;

	/**
	 * Attendee repository for batched confirmed guest counts.
	 *
	 * @var AttendeeRepositoryInterface|null
	 */
	private ?AttendeeRepositoryInterface $attendee_repo;

	/**
	 * Ticket type repository (batched event capacity denominator).
	 *
	 * @var TicketTypeRepositoryInterface|null
	 */
	private ?TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Category repository (optional, for bulk category actions).
	 *
	 * @var CategoryRepositoryInterface|null
	 */
	private ?CategoryRepositoryInterface $category_repo;

	/**
	 * Bulk action handler.
	 *
	 * @var EventsBulkActionHandler
	 */
	private EventsBulkActionHandler $bulk_action_handler;

	/**
	 * Per-page map of event_id => occurrence date bounds.
	 *
	 * @var array<int, array{first: ?string, last: ?string, next: ?string, next_all_day: bool, count: int}>
	 */
	private array $date_bounds = array();

	/**
	 * Per-page map of event_id => confirmed ticket count.
	 *
	 * @var array<int, int>
	 */
	private array $sold_counts = array();

	/**
	 * Per-page map of event_id => capacity denominator data.
	 *
	 * @var array<int, array{capacity: ?int, has_unlimited: bool, configured: bool}>
	 */
	private array $capacity_map = array();

	/**
	 * Event IDs expanded on this render, already intersected with the page.
	 *
	 * @var array<int, int>
	 */
	private array $expanded = array();

	/**
	 * Map of event_id => occurrences, for the expanded events only.
	 *
	 * @var array<int, array<Occurrence>>
	 */
	private array $expanded_occurrences = array();

	/**
	 * Map of occurrence_id => confirmed guest count.
	 *
	 * @var array<int, int>
	 */
	private array $occurrence_sold_counts = array();

	/**
	 * Map of occurrence_id => capacity denominator data.
	 *
	 * @var array<int, array{capacity: ?int, has_unlimited: bool, configured: bool}>
	 */
	private array $occurrence_capacity_map = array();

	/**
	 * Constructor.
	 *
	 * @param EventRepositoryInterface                $event_repo            Event repository.
	 * @param OccurrenceRepositoryInterface           $occurrence_repo       Occurrence repository.
	 * @param EventDuplicationService                 $duplication_service   Event duplication service.
	 * @param CategoryRepositoryInterface|null        $category_repo         Category repository (optional).
	 * @param OccurrenceQueryRepositoryInterface|null $occurrence_query_repo Occurrence query repo for batched date bounds.
	 * @param TicketRepositoryInterface|null          $ticket_repo           Ticket repo (bulk-action delete guard).
	 * @param TicketTypeRepositoryInterface|null      $ticket_type_repo      Ticket type repo for batched capacity denominator.
	 * @param AttendeeRepositoryInterface|null        $attendee_repo         Attendee repo for batched confirmed guest counts.
	 */
	public function __construct(
		EventRepositoryInterface $event_repo,
		OccurrenceRepositoryInterface $occurrence_repo,
		EventDuplicationService $duplication_service,
		?CategoryRepositoryInterface $category_repo = null,
		?OccurrenceQueryRepositoryInterface $occurrence_query_repo = null,
		?TicketRepositoryInterface $ticket_repo = null,
		?TicketTypeRepositoryInterface $ticket_type_repo = null,
		?AttendeeRepositoryInterface $attendee_repo = null
	) {
		parent::__construct(
			array(
				'singular' => 'event',
				'plural'   => 'events',
				'ajax'     => false,
			)
		);

		$this->occurrence_repo       = $occurrence_repo;
		$this->occurrence_query_repo = $occurrence_query_repo;
		$this->ticket_repo           = $ticket_repo;
		$this->ticket_type_repo      = $ticket_type_repo;
		$this->attendee_repo         = $attendee_repo;
		$this->category_repo         = $category_repo;
		$this->bulk_action_handler   = new EventsBulkActionHandler( $event_repo, $duplication_service, $category_repo, $ticket_repo );
	}

	/**
	 * Get columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return self::filtered_columns();
	}

	/**
	 * Build the filtered column map without a list-table instance.
	 *
	 * The occurrence-row AJAX endpoint renders child rows outside any screen, and
	 * the child cells must match the parent's columns exactly; sharing one builder
	 * is what keeps the two from drifting.
	 *
	 * @return array<string, string>
	 */
	public static function filtered_columns(): array {
		$columns = array(
			'cb'           => '<input type="checkbox">',
			'title'        => __( 'Event', 'nettertech-events' ),
			'tickets_sold' => __( 'Tickets Sold', 'nettertech-events' ),
			'date'         => __( 'Date', 'nettertech-events' ),
			'next_date'    => __( 'Next Date', 'nettertech-events' ),
			'type'         => __( 'Type', 'nettertech-events' ),
			'status'       => __( 'Status', 'nettertech-events' ),
			'created_at'   => __( 'Created', 'nettertech-events' ),
		);

		/**
		 * Filters the columns shown on the admin All Events list table.
		 *
		 * @since 1.0.3
		 *
		 * @param array<string, string> $columns Map of column key => header label.
		 */
		$columns = apply_filters( Hooks::FILTER_LIST_COLUMNS, $columns );

		return is_array( $columns ) ? $columns : array();
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function get_sortable_columns(): array {
		return array(
			'title'      => array( 'title', false ),
			// Date is the default sort column: 'desc' (5th element) marks it as the
			// initially-sorted column so WP renders the active sort indicator on
			// first load, and 'true' (2nd element) makes its first click sort DESC.
			'date'       => array( 'date', true, '', '', 'desc' ),
			'next_date'  => array( 'next_date', false ),
			'type'       => array( 'event_type', false ),
			'status'     => array( 'status', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Get bulk actions.
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		$actions = array(
			'delete'         => __( 'Delete', 'nettertech-events' ),
			'publish'        => __( 'Publish', 'nettertech-events' ),
			'draft'          => __( 'Set to Draft', 'nettertech-events' ),
			'cancel'         => __( 'Cancel', 'nettertech-events' ),
			'bulk_duplicate' => __( 'Duplicate', 'nettertech-events' ),
		);

		if ( $this->category_repo ) {
			$actions['bulk_category_add']    = __( 'Add Category', 'nettertech-events' );
			$actions['bulk_category_remove'] = __( 'Remove Category', 'nettertech-events' );
		}

		return $actions;
	}

	/**
	 * Prepare items for display.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		// Set column headers with hidden column support from Screen Options.
		$this->_column_headers = $this->get_column_info();

		$this->process_bulk_actions();

		$per_page     = $this->get_items_per_page( 'events_per_page', 20 );
		$current_page = $this->get_pagenum();

		// Build query.
		$query = EventQuery::create();

		// Apply search.
		$search = AdminRequest::get_text( 's' );
		if ( '' !== $search ) {
			$query->search( $search );
		}

		// Apply status filter.
		$status = AdminRequest::get_text( 'status' );
		if ( '' !== $status && in_array( $status, Event::STATUSES, true ) ) {
			$query->where_status( $status );
		}

		// Apply type filter.
		// "Ticketed" is a special, orthogonal value (not a member of Event::TYPES):
		// it filters to events with active ticket types regardless of single/recurring,
		// so it is branched before the where_type() apply.
		$type = AdminRequest::get_text( 'event_type' );
		if ( self::TICKETED_FILTER === $type ) {
			$query->where_has_ticket_types();
		} elseif ( '' !== $type && in_array( $type, Event::TYPES, true ) ) {
			$query->where_type( $type );
		}

		// Apply sorting. Defaults to the event Date (first occurrence) descending
		// so the most future-dated events surface at the top on first load.
		$orderby = AdminRequest::get_text( 'orderby', 'date' );
		$order   = AdminRequest::get_text( 'order', 'DESC' );

		if ( 'next_date' === $orderby ) {
			$query->order_by_next_occurrence( $order );
		} elseif ( 'date' === $orderby ) {
			$query->order_by_first_occurrence( $order );
		} else {
			$query->order_by( $orderby, $order );
		}

		// Get paginated results.
		$result = $query->paginate( $per_page, $current_page );

		$this->items = $result['items'];

		$this->prime_column_aggregates();
		$this->prime_expanded_occurrences();

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => $result['pages'],
			)
		);
	}

	/**
	 * Prime the per-page batched aggregates used by the Date and Tickets Sold columns.
	 *
	 * Runs a bounded, fixed number of aggregate queries for the whole page (date
	 * bounds, confirmed sold counts, capacity denominator) rather than per-row,
	 * avoiding N+1. Maps are stored on private properties for the column renderers.
	 *
	 * @return void
	 */
	private function prime_column_aggregates(): void {
		$this->date_bounds  = array();
		$this->sold_counts  = array();
		$this->capacity_map = array();

		$event_ids = array();
		foreach ( $this->items as $item ) {
			if ( $item instanceof Event && null !== $item->id ) {
				$event_ids[] = (int) $item->id;
			}
		}

		if ( empty( $event_ids ) ) {
			return;
		}

		if ( null !== $this->occurrence_query_repo ) {
			$this->date_bounds = $this->occurrence_query_repo->date_bounds_for_events( $event_ids );
		}

		if ( null !== $this->attendee_repo ) {
			$this->sold_counts = $this->attendee_repo->confirmed_guest_counts_for_events( $event_ids );
		}

		if ( null !== $this->ticket_type_repo ) {
			$this->capacity_map = $this->ticket_type_repo->event_capacity_for_events( $event_ids );
		}
	}

	/**
	 * Resolve the `expanded` request argument into occurrences and their aggregates.
	 *
	 * IDs are intersected with the events actually on this page, so a stale or
	 * hand-edited URL expands nothing it cannot show and the fetch stays bounded by
	 * the page. Three batched queries cover the whole page whatever K is.
	 *
	 * @return void
	 */
	private function prime_expanded_occurrences(): void {
		$this->expanded                = array();
		$this->expanded_occurrences    = array();
		$this->occurrence_sold_counts  = array();
		$this->occurrence_capacity_map = array();

		$requested = AdminRequest::get_id_list( self::ARG_EXPANDED, self::MAX_EXPANDED );
		if ( empty( $requested ) || null === $this->occurrence_query_repo ) {
			return;
		}

		$page_ids = array();
		foreach ( $this->items as $item ) {
			if ( $item instanceof Event && null !== $item->id ) {
				$page_ids[] = $item->id;
			}
		}

		$this->expanded = array_values( array_intersect( $requested, $page_ids ) );
		if ( empty( $this->expanded ) ) {
			return;
		}

		$this->expanded_occurrences = $this->occurrence_query_repo->for_events( $this->expanded );

		$occurrence_ids = array();
		foreach ( $this->expanded_occurrences as $occurrences ) {
			foreach ( $occurrences as $occurrence ) {
				if ( $occurrence instanceof Occurrence && null !== $occurrence->id ) {
					$occurrence_ids[] = $occurrence->id;
				}
			}
		}

		if ( empty( $occurrence_ids ) ) {
			return;
		}

		if ( null !== $this->attendee_repo ) {
			$this->occurrence_sold_counts = $this->attendee_repo->confirmed_guest_counts_for_occurrences( $occurrence_ids );
		}

		if ( null !== $this->ticket_type_repo ) {
			$this->occurrence_capacity_map = $this->ticket_type_repo->occurrence_capacity_for_occurrences( $occurrence_ids );
		}
	}

	/**
	 * Display rows, following each expanded event with its date rows.
	 *
	 * @return void
	 */
	public function display_rows(): void {
		foreach ( $this->items as $item ) {
			$this->single_row( $item );

			if ( $item instanceof Event ) {
				$this->display_occurrence_rows( $item );
			}
		}
	}

	/**
	 * Emit the child date rows for one event when it is expanded.
	 *
	 * @param Event $item Event object.
	 * @return void
	 */
	private function display_occurrence_rows( Event $item ): void {
		if ( null === $item->id || ! in_array( $item->id, $this->expanded, true ) ) {
			return;
		}

		$occurrences = $this->expanded_occurrences[ $item->id ] ?? array();
		if ( empty( $occurrences ) ) {
			return;
		}

		// WP core tolerates a three-element _column_headers (it appends the primary
		// column itself), so the fourth slot is read defensively rather than unpacked.
		$column_info = $this->get_column_info();
		$columns     = is_array( $column_info[0] ?? null ) ? $column_info[0] : $this->get_columns();
		$hidden      = is_array( $column_info[1] ?? null ) ? $column_info[1] : array();
		$primary     = is_string( $column_info[3] ?? null ) ? $column_info[3] : 'title';

		$renderer = new OccurrenceRowRenderer( $columns, $hidden, $primary );

		// Escaped cell by cell inside the renderer; this is assembled markup, not data.
		echo $renderer->render( $item, $occurrences, $this->occurrence_sold_counts, $this->occurrence_capacity_map ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled table markup; OccurrenceRowRenderer escapes every cell value as it builds it.
	}

	/**
	 * The current list URL, as the toggle and WP core's own sort links use it.
	 *
	 * Built from the request URI rather than from known arguments so filters this
	 * class does not know about (an add-on's, for one) survive a toggle click.
	 *
	 * @return string
	 */
	private function current_list_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG );

		return remove_query_arg( array( '_wpnonce', '_wp_http_referer', 'action', 'action2', 'event' ), $uri );
	}

	/**
	 * Build the URL that opens or closes one event's dates.
	 *
	 * @param int  $event_id Event ID.
	 * @param bool $is_open  Whether the event is currently expanded.
	 * @return string
	 */
	private function toggle_url( int $event_id, bool $is_open ): string {
		$url = $this->current_list_url();

		$target = $is_open
			? array_diff( $this->expanded, array( $event_id ) )
			: array_merge( $this->expanded, array( $event_id ) );

		if ( empty( $target ) ) {
			return remove_query_arg( self::ARG_EXPANDED, $url );
		}

		return add_query_arg( self::ARG_EXPANDED, implode( ',', $target ), $url );
	}

	/**
	 * Process bulk actions.
	 *
	 * @return void
	 */
	private function process_bulk_actions(): void {
		$action = $this->current_action();
		$this->bulk_action_handler->process( is_string( $action ) ? $action : null, $this->_args['plural'] );
	}

	/**
	 * Generate a single row with data attributes for quick edit.
	 *
	 * @param Event $item Event object.
	 * @return void
	 */
	public function single_row( $item ): void {
		printf(
			'<tr data-event-id="%d" data-title="%s" data-status="%s" data-venue-name="%s">',
			(int) $item->id,
			esc_attr( $item->title ),
			esc_attr( $item->status->value ),
			esc_attr( $item->venue_name ?? '' )
		);
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Render the inline edit form template.
	 *
	 * Output once after the table; JS clones it per-row.
	 *
	 * @return void
	 */
	public function inline_edit(): void {
		$num_columns = count( $this->get_columns() );
		?>
		<table style="display:none;">
			<tbody>
				<tr id="nte-inline-edit-template" class="nte-inline-edit-row" style="display:none;">
					<td colspan="<?php echo (int) $num_columns; ?>">
						<fieldset class="nte-inline-edit-fieldset">
							<legend class="screen-reader-text"><?php esc_html_e( 'Quick Edit', 'nettertech-events' ); ?></legend>
							<div class="nte-inline-edit-fields">
								<label class="nte-inline-edit-field">
									<span class="nte-inline-edit-field__label"><?php esc_html_e( 'Title', 'nettertech-events' ); ?></span>
									<input type="text" name="title" class="nte-inline-edit-field__input" />
								</label>
								<label class="nte-inline-edit-field">
									<span class="nte-inline-edit-field__label"><?php esc_html_e( 'Status', 'nettertech-events' ); ?></span>
									<select name="status" class="nte-inline-edit-field__input">
										<option value="draft"><?php esc_html_e( 'Draft', 'nettertech-events' ); ?></option>
										<option value="published"><?php esc_html_e( 'Published', 'nettertech-events' ); ?></option>
										<option value="cancelled"><?php esc_html_e( 'Cancelled', 'nettertech-events' ); ?></option>
										<option value="postponed"><?php esc_html_e( 'Postponed', 'nettertech-events' ); ?></option>
									</select>
								</label>
								<label class="nte-inline-edit-field">
									<span class="nte-inline-edit-field__label"><?php esc_html_e( 'Venue', 'nettertech-events' ); ?></span>
									<input type="text" name="venue_name" class="nte-inline-edit-field__input" />
								</label>
							</div>
							<div class="nte-inline-edit-actions">
								<button type="button" class="button button-primary nte-inline-edit-save">
									<?php esc_html_e( 'Update', 'nettertech-events' ); ?>
								</button>
								<button type="button" class="button nte-inline-edit-cancel">
									<?php esc_html_e( 'Cancel', 'nettertech-events' ); ?>
								</button>
								<span class="spinner"></span>
							</div>
						</fieldset>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render category selector for bulk category actions.
	 *
	 * @return void
	 */
	public function render_category_modal(): void {
		if ( ! $this->category_repo ) {
			return;
		}

		$categories = $this->category_repo->get_all();
		if ( empty( $categories ) ) {
			return;
		}

		?>
		<div id="nte-category-modal" class="nte-category-modal" style="display:none;" role="dialog" aria-labelledby="nte-category-modal-title" aria-modal="true">
			<div class="nte-category-modal__content">
				<h3 id="nte-category-modal-title" class="nte-category-modal__title">
					<?php esc_html_e( 'Select Categories', 'nettertech-events' ); ?>
				</h3>
				<ul class="nte-category-modal__list">
					<?php foreach ( $categories as $category ) : ?>
						<li>
							<label>
								<input type="checkbox" name="bulk_category_ids[]" value="<?php echo (int) $category->id; ?>" />
								<?php echo esc_html( $category->name ); ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
				<div class="nte-category-modal__actions">
					<button type="button" class="button button-primary nte-category-modal__apply">
						<?php esc_html_e( 'Apply', 'nettertech-events' ); ?>
					</button>
					<button type="button" class="button nte-category-modal__cancel">
						<?php esc_html_e( 'Cancel', 'nettertech-events' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render checkbox column.
	 *
	 * @param Event $item Event object.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="event[]" value="%d">',
			$item->id
		);
	}

	/**
	 * Render title column.
	 *
	 * @param Event $item Event object.
	 * @return string
	 */
	public function column_title( $item ): string {
		$edit_url      = admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG . '&action=edit&event_id=' . $item->id );
		$duplicate_url = wp_nonce_url(
			admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG . '&action=duplicate&event_id=' . $item->id ),
			'duplicate_event_' . $item->id
		);
		$delete_url    = wp_nonce_url(
			admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG . '&action=delete&event_id=' . $item->id ),
			'delete_event_' . $item->id
		);

		$actions = array(
			'edit'       => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				__( 'Edit', 'nettertech-events' )
			),
			'quick_edit' => sprintf(
				'<button type="button" class="button-link nte-quick-edit-trigger" data-event-id="%d">%s</button>',
				(int) $item->id,
				__( 'Quick Edit', 'nettertech-events' )
			),
			'duplicate'  => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $duplicate_url ),
				__( 'Duplicate', 'nettertech-events' )
			),
			'delete'     => sprintf(
				'<a href="%s" class="nte-delete-event" data-event-name="%s">%s</a>',
				esc_url( $delete_url ),
				esc_attr( $item->title ),
				__( 'Delete', 'nettertech-events' )
			),
		);

		if ( $item->is_published() ) {
			$actions['view'] = sprintf(
				'<a href="%s" target="_blank">%s</a>',
				esc_url( $item->get_permalink() ),
				__( 'View', 'nettertech-events' )
			);
		}

		// Purchases row action (NTE-118) — links to the Attendees page filtered
		// to this event (rolled up across its occurrences). Read-only navigation;
		// the Attendees page enforces the edit_posts capability itself.
		$actions['purchases'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . AdminMenu::SUBMENU_ATTENDEES . '&event_id=' . $item->id ) ),
			__( 'Purchases', 'nettertech-events' )
		);

		// SKUs row action (NTE-114) — opens a dialog listing the event's
		// ticket-type SKUs. Data is fetched via AJAX only on click, so the
		// list pays no per-row query cost. WooCommerce-gated.
		if ( class_exists( 'WooCommerce' ) ) {
			$actions['skus'] = sprintf(
				'<button type="button" class="button-link nte-skus-action" data-event-id="%d">%s</button>',
				(int) $item->id,
				__( 'SKUs', 'nettertech-events' )
			);
		}

		$title = sprintf(
			'<strong><a href="%s" class="row-title">%s</a></strong>',
			esc_url( $edit_url ),
			esc_html( $item->title )
		);

		return $title . $this->row_actions( $actions );
	}

	/**
	 * Render the Date column (full occurrence date or first–last span).
	 *
	 * Unlike Next Date, this shows the event's real date(s) regardless of whether
	 * they are past or future: a single date for single events, a `first – last`
	 * span for recurring events, and "—" when there are no occurrences. Reads from
	 * the per-page batched date-bounds map (no per-row query).
	 *
	 * @param Event $item Event object.
	 * @return string
	 */
	public function column_date( $item ): string {
		$bounds = ( null !== $item->id && isset( $this->date_bounds[ (int) $item->id ] ) )
			? $this->date_bounds[ (int) $item->id ]
			: null;

		$first = $bounds['first'] ?? null;
		$last  = $bounds['last'] ?? null;

		if ( null === $first ) {
			return '<span style="color: #999;">' . esc_html__( '—', 'nettertech-events' ) . '</span>';
		}

		$date_format = get_option( 'date_format' );
		$first_date  = date_i18n( $date_format, strtotime( $first ) );

		// Recurring (or series) events with a distinct last date show the full span.
		if ( null !== $last && substr( (string) $first, 0, 10 ) !== substr( (string) $last, 0, 10 ) ) {
			$last_date = date_i18n( $date_format, strtotime( $last ) );
			return esc_html( $first_date ) . ' &ndash; ' . esc_html( $last_date );
		}

		return esc_html( $first_date );
	}

	/**
	 * Render next date column.
	 *
	 * Prefers the per-page batched date-bounds map (retiring the historical
	 * per-row next_for_event() N+1); falls back to the per-row repository lookup
	 * when the map is not primed (e.g. isolated unit tests).
	 *
	 * @param Event $item Event object.
	 * @return string
	 */
	public function column_next_date( $item ): string {
		$next_start   = null;
		$next_all_day = false;
		$has_bounds   = ( null !== $item->id && isset( $this->date_bounds[ (int) $item->id ] ) );

		if ( $has_bounds ) {
			$next_start   = $this->date_bounds[ (int) $item->id ]['next'] ?? null;
			$next_all_day = (bool) ( $this->date_bounds[ (int) $item->id ]['next_all_day'] ?? false );
		} elseif ( null !== $item->id ) {
			$next = $this->occurrence_repo->next_for_event( $item->id );
			if ( $next ) {
				$next_start   = $next->start_datetime;
				$next_all_day = (bool) $next->all_day;
			}
		}

		if ( null === $next_start ) {
			if ( 'recurring' === $item->event_type ) {
				return '<span style="color: #999;">' . esc_html__( 'No upcoming', 'nettertech-events' ) . '</span>';
			}
			return '<span style="color: #999;">' . esc_html__( '—', 'nettertech-events' ) . '</span>';
		}

		$date = date_i18n( get_option( 'date_format' ), strtotime( $next_start ) );

		if ( ! $next_all_day ) {
			$time = date_i18n( get_option( 'time_format' ), strtotime( $next_start ) );
			return esc_html( $date ) . '<br><span style="color: #666;">' . esc_html( $time ) . '</span>';
		}

		return esc_html( $date ) . '<br><span style="color: #666;">' . esc_html__( 'All day', 'nettertech-events' ) . '</span>';
	}

	/**
	 * Render type column.
	 *
	 * @param Event $item Event object.
	 * @return string
	 */
	public function column_type( $item ): string {
		$types = array(
			'single'        => __( 'Single', 'nettertech-events' ),
			'recurring'     => __( 'Recurring', 'nettertech-events' ),
			'series_parent' => __( 'Series', 'nettertech-events' ),
		);

		$label = $types[ $item->event_type ] ?? $item->event_type;

		// An event with more than one scheduled date cannot logically be a single
		// event (NTE-159 ruling, superseding NTE-155's "Single (N dates)" label):
		// a stored-single event carrying extra hand-picked dates reads Recurring,
		// and its filter link files it there — where_type() derives the same
		// boundary, so the label and the filter cannot disagree. The stored
		// column and the conversion guards are untouched.
		$occ_count      = null !== $item->id ? (int) ( $this->date_bounds[ (int) $item->id ]['count'] ?? 0 ) : 0;
		$displayed_type = $item->event_type;
		if ( 'single' === $item->event_type && $occ_count > 1 ) {
			$displayed_type = 'recurring';
			$label          = $types['recurring'];
		}

		$filter_url = add_query_arg(
			array(
				'page'       => AdminMenu::MENU_SLUG,
				'event_type' => $displayed_type,
			),
			admin_url( 'admin.php' )
		);

		$cell = sprintf( '<a href="%s">%s</a>', esc_url( $filter_url ), esc_html( $label ) );

		// The date count carried the "(N dates)" suffix on the type label; it is now
		// the expansion control, so the label stays the plain filter link.
		if ( $occ_count > 1 && null !== $item->id ) {
			$cell .= $this->render_dates_toggle( $item->id, $occ_count );
		}

		return $cell;
	}

	/**
	 * Render the control that opens or closes an event's date rows.
	 *
	 * A link, not a button: with scripting off it requests the same page with this
	 * event added to `expanded`, which the server renders to the same rows.
	 *
	 * @param int $event_id  Event ID.
	 * @param int $occ_count Number of dates on the event.
	 * @return string
	 */
	private function render_dates_toggle( int $event_id, int $occ_count ): string {
		$is_open = in_array( $event_id, $this->expanded, true );

		$controls = '';
		if ( $is_open ) {
			$row_ids = array();
			foreach ( $this->expanded_occurrences[ $event_id ] ?? array() as $occurrence ) {
				if ( $occurrence instanceof Occurrence && null !== $occurrence->id ) {
					$row_ids[] = OccurrenceRowRenderer::row_id( (int) $occurrence->id );
				}
			}
			if ( ! empty( $row_ids ) ) {
				$controls = sprintf( ' aria-controls="%s"', esc_attr( implode( ' ', $row_ids ) ) );
			}
		}

		return sprintf(
			'<a class="nte-dates-toggle" href="%1$s" aria-expanded="%2$s"%3$s data-event-id="%4$d" data-count="%5$d"><span class="nte-dates-toggle__chevron" aria-hidden="true">%6$s</span>%7$s</a>',
			esc_url( $this->toggle_url( $event_id, $is_open ) ),
			$is_open ? 'true' : 'false',
			$controls,
			$event_id,
			$occ_count,
			$is_open ? '&#9662;' : '&#9656;',
			esc_html(
				sprintf(
					/* translators: %d: number of dates on the event. */
					_n( '%d date', '%d dates', $occ_count, 'nettertech-events' ),
					$occ_count
				)
			)
		);
	}

	/**
	 * Render status column.
	 *
	 * @param Event $item Event object.
	 * @return string
	 */
	public function column_status( $item ): string {
		$statuses = array(
			'draft'     => array(
				'label' => __( 'Draft', 'nettertech-events' ),
				'color' => '#646970',
			),
			'published' => array(
				'label' => __( 'Published', 'nettertech-events' ),
				'color' => '#00a32a',
			),
			'cancelled' => array(
				'label' => __( 'Cancelled', 'nettertech-events' ),
				'color' => '#d63638',
			),
			'postponed' => array(
				'label' => __( 'Postponed', 'nettertech-events' ),
				'color' => '#dba617',
			),
		);

		$status = $statuses[ $item->status->value ] ?? array(
			'label' => $item->status->value,
			'color' => '#646970',
		);

		$filter_url = add_query_arg(
			array(
				'page'   => AdminMenu::MENU_SLUG,
				'status' => $item->status->value,
			),
			admin_url( 'admin.php' )
		);

		return sprintf(
			'<a href="%s" style="color: %s;">%s</a>',
			esc_url( $filter_url ),
			esc_attr( $status['color'] ),
			esc_html( $status['label'] )
		);
	}

	/**
	 * Render created_at column.
	 *
	 * @param Event $item Event object.
	 * @return string
	 */
	public function column_created_at( $item ): string {
		if ( empty( $item->created_at ) ) {
			return '—';
		}

		// created_at is stored in UTC (NTE-131); get_date_from_gmt converts to the
		// site's local timezone for display, portably across environments.
		$date = get_date_from_gmt( $item->created_at, get_option( 'date_format' ) );
		$time = get_date_from_gmt( $item->created_at, get_option( 'time_format' ) );

		return esc_html( $date ) . '<br><span style="color: #666;">' . esc_html( $time ) . '</span>';
	}

	/**
	 * Render the Tickets Sold column (confirmed count + % of capacity sold).
	 *
	 * The first line is the confirmed ticket count (statuses `confirmed` /
	 * `checked_in`), matching Pro Reports. The second line is the percentage of the
	 * event's total capacity sold, computed against a shared-pool-aware denominator.
	 * Events with no ticketing configured render "—" with no second line; events
	 * with any unlimited capacity render "— of capacity" instead of a percentage.
	 * Display-only — it does not affect capacity enforcement.
	 *
	 * @param Event $item Event object.
	 * @return string
	 */
	public function column_tickets_sold( $item ): string {
		$event_id = null !== $item->id ? (int) $item->id : 0;

		return self::render_sold_cell(
			(int) ( $this->sold_counts[ $event_id ] ?? 0 ),
			$this->capacity_map[ $event_id ] ?? null
		);
	}

	/**
	 * Format a confirmed-count-over-capacity cell.
	 *
	 * Shared by the event row and the per-date child rows so a date's figure reads
	 * exactly like the event's, only against that date's house.
	 *
	 * @param int                                                               $sold     Confirmed guest count.
	 * @param array{capacity: ?int, has_unlimited: bool, configured: bool}|null $capacity Capacity denominator data.
	 * @return string
	 */
	public static function render_sold_cell( int $sold, ?array $capacity ): string {
		// No ticketing configured anywhere: single dash, no second line.
		if ( null === $capacity || empty( $capacity['configured'] ) ) {
			return '<span style="color: #999;">' . esc_html__( '—', 'nettertech-events' ) . '</span>';
		}

		$sold_markup = '<strong>' . esc_html( (string) $sold ) . '</strong>';

		// Second line: percentage of capacity sold.
		if ( ! empty( $capacity['has_unlimited'] ) || null === $capacity['capacity'] ) {
			$second_line = esc_html__( '— of capacity', 'nettertech-events' );
		} else {
			$denominator = (int) $capacity['capacity'];
			if ( $denominator <= 0 ) {
				// Zero capacity but tickets sold (data anomaly): flag, never divide by zero.
				$second_line = $sold > 0
					? esc_html__( '>100% of capacity', 'nettertech-events' )
					: esc_html__( '— of capacity', 'nettertech-events' );
			} else {
				$percent     = (int) round( ( $sold / $denominator ) * 100 );
				$second_line = sprintf(
					/* translators: %d: percentage of event capacity sold. */
					esc_html__( '%d%% of capacity', 'nettertech-events' ),
					$percent
				);
			}
		}

		return $sold_markup . '<br><span style="color: #666;">' . $second_line . '</span>';
	}

	/**
	 * Default column rendering.
	 *
	 * Routes unknown columns through the list-column action so add-ons that
	 * registered columns via {@see Hooks::FILTER_LIST_COLUMNS} can emit cell
	 * content; falls back to the matching event property for built-ins.
	 *
	 * @param Event  $item        Event object.
	 * @param string $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		$known = array( 'date', 'next_date', 'type', 'status', 'tickets_sold', 'created_at' );

		if ( ! in_array( $column_name, $known, true ) && ! isset( $item->$column_name ) ) {
			ob_start();

			/**
			 * Fires when rendering a registered custom column cell on the All Events list.
			 *
			 * @since 1.0.3
			 *
			 * @param string          $column_name The column key being rendered.
			 * @param Event           $item        The event for this row.
			 * @param Occurrence|null $occurrence  The occurrence for a date row, null on an event row.
			 */
			do_action( Hooks::ACTION_LIST_COLUMN, $column_name, $item, null );

			$output = (string) ob_get_clean();
			if ( '' !== $output ) {
				return $output;
			}
		}

		return esc_html( $item->$column_name ?? '' );
	}

	/**
	 * Display when no items.
	 *
	 * @return void
	 */
	public function no_items(): void {
		$status = AdminRequest::get_text( 'status' );
		$type   = AdminRequest::get_text( 'event_type' );
		$search = AdminRequest::get_text( 's' );

		$has_filters = '' !== $status || '' !== $type || '' !== $search;

		echo '<div class="nte-empty-state">';

		if ( $has_filters ) {
			// Build a contextual message describing the active filters.
			$parts = array();
			if ( '' !== $status ) {
				$parts[] = $status;
			}
			if ( '' !== $type ) {
				$parts[] = $type;
			}

			if ( ! empty( $parts ) ) {
				printf(
					/* translators: %s: filter description (e.g., "draft", "recurring"). */
					'<p>' . esc_html__( 'No %s events found.', 'nettertech-events' ) . '</p>',
					esc_html( implode( ' ', $parts ) )
				);
			} elseif ( '' !== $search ) {
				printf(
					/* translators: %s: search query. */
					'<p>' . esc_html__( 'No events matching "%s".', 'nettertech-events' ) . '</p>',
					esc_html( $search )
				);
			}

			$clear_url = admin_url( 'admin.php?page=' . rawurlencode( AdminRequest::get_text( 'page', 'nettertech-events' ) ) );
			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $clear_url ),
				esc_html__( 'Clear all filters', 'nettertech-events' )
			);
		} else {
			echo '<p>' . esc_html__( 'No events found.', 'nettertech-events' ) . '</p>';
		}

		$new_url = admin_url( 'admin.php?page=nettertech-events-new' );
		printf(
			'<p><a href="%s" class="button button-primary">%s</a></p>',
			esc_url( $new_url ),
			esc_html__( 'Create New Event', 'nettertech-events' )
		);

		echo '</div>';
	}

	/**
	 * Extra table navigation (filters).
	 *
	 * @param string $which Top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$current_status = AdminRequest::get_text( 'status' );
		$current_type   = AdminRequest::get_text( 'event_type' );
		$current_search = AdminRequest::get_text( 's' );
		$has_filters    = '' !== $current_status || '' !== $current_type || '' !== $current_search;

		?>
		<div class="alignleft actions nte-event-filters">
			<select name="status">
				<option value=""><?php esc_html_e( 'All Statuses', 'nettertech-events' ); ?></option>
				<option value="draft" <?php selected( $current_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'nettertech-events' ); ?></option>
				<option value="published" <?php selected( $current_status, 'published' ); ?>><?php esc_html_e( 'Published', 'nettertech-events' ); ?></option>
				<option value="cancelled" <?php selected( $current_status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'nettertech-events' ); ?></option>
				<option value="postponed" <?php selected( $current_status, 'postponed' ); ?>><?php esc_html_e( 'Postponed', 'nettertech-events' ); ?></option>
			</select>

			<select name="event_type">
				<option value=""><?php esc_html_e( 'All Types', 'nettertech-events' ); ?></option>
				<option value="single" <?php selected( $current_type, 'single' ); ?>><?php esc_html_e( 'Single', 'nettertech-events' ); ?></option>
				<option value="recurring" <?php selected( $current_type, 'recurring' ); ?>><?php esc_html_e( 'Recurring', 'nettertech-events' ); ?></option>
				<option value="<?php echo esc_attr( self::TICKETED_FILTER ); ?>" <?php selected( $current_type, self::TICKETED_FILTER ); ?>><?php esc_html_e( 'Ticketed', 'nettertech-events' ); ?></option>
			</select>

			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'nettertech-events' ); ?>">
			<?php if ( $has_filters ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . rawurlencode( AdminRequest::get_text( 'page', 'nettertech-events' ) ) ) ); ?>" class="nte-clear-filters"><?php esc_html_e( 'Clear', 'nettertech-events' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Display the table with filter notice above it.
	 *
	 * Overrides parent to add a reserved-space notice line above the table
	 * when sorting by next date ascending (past events hidden).
	 *
	 * @return void
	 */
	public function display(): void {
		$current_orderby  = AdminRequest::get_text( 'orderby' );
		$current_order    = strtolower( AdminRequest::get_text( 'order' ) );
		$is_upcoming_only = ( 'next_date' === $current_orderby && 'asc' === $current_order );

		// Output top tablenav.
		$this->display_tablenav( 'top' );

		// Filter notice — only render when there is something to say.
		if ( $is_upcoming_only ) :
			?>
			<p class="nte-filter-notice description" style="margin: 8px 0; color: #646970;">
				<?php esc_html_e( 'Showing events with upcoming dates only.', 'nettertech-events' ); ?>
			</p>
			<?php
		endif;

		// Output the table.
		$this->screen->render_screen_reader_content( 'heading_list' );
		?>
		<table class="wp-list-table <?php echo esc_attr( implode( ' ', $this->get_table_classes() ) ); ?>">
			<thead>
				<tr>
					<?php $this->print_column_headers(); ?>
				</tr>
			</thead>

			<tbody id="the-list"<?php echo $this->_args['singular'] ? " data-wp-lists='list:" . esc_attr( $this->_args['singular'] ) . "'" : ''; ?>>
				<?php $this->display_rows_or_placeholder(); ?>
			</tbody>

			<tfoot>
				<tr>
					<?php $this->print_column_headers( false ); ?>
				</tr>
			</tfoot>
		</table>
		<?php

		// Output bottom tablenav.
		$this->display_tablenav( 'bottom' );

		$this->render_skus_dialog();
	}

	/**
	 * Render the single reused SKUs dialog for the page (NTE-114).
	 *
	 * One empty `<dialog>` is emitted per page render; the "SKUs" row action
	 * populates and opens it via AJAX. WooCommerce-gated.
	 *
	 * @return void
	 */
	private function render_skus_dialog(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		?>
		<dialog id="nte-skus-dialog" class="nte-skus-dialog" aria-labelledby="nte-skus-dialog-title">
			<form method="dialog">
				<h2 id="nte-skus-dialog-title" class="nte-skus-dialog__title"></h2>
				<div class="nte-skus-dialog__body" aria-live="polite"></div>
				<div class="nte-skus-dialog__actions">
					<button type="button" class="button button-primary nte-skus-copy-all">
						<?php esc_html_e( 'Copy all', 'nettertech-events' ); ?>
					</button>
					<button type="submit" class="button">
						<?php esc_html_e( 'Close', 'nettertech-events' ); ?>
					</button>
				</div>
			</form>
		</dialog>
		<?php
	}
}
