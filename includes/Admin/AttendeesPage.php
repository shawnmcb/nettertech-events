<?php
/**
 * Attendees admin page.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\Attendees\AttendeesBulkActions;
use NetterTechEvents\Contracts\AttendeesSummaryServiceInterface;
use NetterTechEvents\Admin\Attendees\Presenters\AttendeesFiltersPresenter;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Database\Schema;

/**
 * Admin page for viewing and managing attendees.
 *
 * Provides a list view of all attendees with filtering by event/occurrence.
 *
 * @since 0.9.0
 */
class AttendeesPage {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Bulk actions handler.
	 *
	 * @var AttendeesBulkActions
	 */
	private AttendeesBulkActions $bulk_actions;

	/**
	 * Event-scoped summary builder.
	 *
	 * @var AttendeesSummaryServiceInterface
	 */
	private AttendeesSummaryServiceInterface $summary_service;

	/**
	 * Items per page.
	 *
	 * @var int
	 */
	private int $per_page = 25;

	/**
	 * Sortable columns mapping: display key => database column.
	 *
	 * @var array<string, string>
	 */
	private const SORTABLE_COLUMNS = array(
		'name'        => 'a.name',
		'email'       => 'a.email',
		'event'       => 'e.title',
		'datetime'    => 'o.start_datetime',
		'status'      => 'a.status',
		'ticket_type' => 'tt.name',
	);

	/**
	 * Constructor.
	 *
	 * @param \wpdb                            $db              Database instance.
	 * @param OccurrenceRepositoryInterface    $occurrence_repo Occurrence repository.
	 * @param AttendeesBulkActions             $bulk_actions    Bulk actions handler.
	 * @param AttendeesSummaryServiceInterface $summary_service Event-scoped summary builder.
	 */
	public function __construct(
		\wpdb $db,
		OccurrenceRepositoryInterface $occurrence_repo,
		AttendeesBulkActions $bulk_actions,
		AttendeesSummaryServiceInterface $summary_service
	) {
		$this->db              = $db;
		$this->occurrence_repo = $occurrence_repo;
		$this->bulk_actions    = $bulk_actions;
		$this->summary_service = $summary_service;
	}

	/**
	 * Dispatch bulk + single-delete actions (including CSV export) on the
	 * attendees page's load-{hook}, BEFORE any output is sent.
	 *
	 * The CSV export streams its own Content-Type/Content-Disposition headers
	 * and exits. Dispatching during render() meant the admin page HTML had
	 * already started, so those headers were ignored and the export fell through
	 * to rendering the page instead of downloading. The individual handlers
	 * self-verify capability and nonce, so this is safe to call on page load.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		$this->bulk_actions->handle();
		$this->bulk_actions->handle_single_delete();
	}

	/**
	 * Render the attendees page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'nettertech-events' ) );
		}

		// Bulk/single-delete actions (incl. CSV export) are dispatched earlier on
		// the page's load-{hook} (AdminMenu::load_attendees_page) so the export can
		// send its headers before any output — do NOT dispatch them during render().

		// Display success notice for bulk delete.
		if ( AdminRequest::has( 'nettertech_events_deleted' ) ) {
			$deleted_count = AdminRequest::get_absint( 'nettertech_events_deleted' );
			add_action(
				'admin_notices',
				function () use ( $deleted_count ) {
					echo '<div class="notice notice-success is-dismissible"><p>';
					printf(
						/* translators: %d: number of attendees deleted */
						esc_html( _n( '%d attendee deleted.', '%d attendees deleted.', $deleted_count, 'nettertech-events' ) ),
						(int) $deleted_count
					);
					echo '</p></div>';
				}
			);
		}

		$occurrence_id = AdminRequest::get_absint( 'occurrence_id' );
		$event_id      = AdminRequest::get_absint( 'event_id' );
		$search        = AdminRequest::get_text( 's' );
		// Preserve historical (int)-cast semantics: negative values clamp to 1
		// via max(); absint() would flip the sign and break the clamp.
		$page               = max( 1, (int) AdminRequest::get_text( 'paged', '1' ) );
		$status_filter      = AdminRequest::get_text( 'status' );
		$placeholder_filter = AdminRequest::get_text( 'placeholder' );
		$orderby            = AdminRequest::get_text( 'orderby' );
		$order              = strtoupper( AdminRequest::get_order( 'desc' ) );

		// Validate sort parameters.
		if ( ! isset( self::SORTABLE_COLUMNS[ $orderby ] ) ) {
			$orderby = '';
		}
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		$result = $this->get_attendees( $occurrence_id, $event_id, $search, $status_filter, $placeholder_filter, $page, $orderby, $order );

		$this->render_page( $result, $occurrence_id, $event_id, $search, $status_filter, $placeholder_filter, $page, $orderby, $order );
	}

	/**
	 * Get attendees with pagination.
	 *
	 * @param int    $occurrence_id      Filter by occurrence.
	 * @param int    $event_id           Filter by event (rolls up across the event's occurrences).
	 * @param string $search             Search query.
	 * @param string $status_filter      Status filter.
	 * @param string $placeholder_filter Placeholder name filter ('yes', 'no', or '').
	 * @param int    $page               Current page.
	 * @param string $orderby            Column to sort by (must be in SORTABLE_COLUMNS).
	 * @param string $order              Sort direction (ASC or DESC).
	 * @return array{items: array<array<string, mixed>>, total: int, pages: int}
	 */
	private function get_attendees( int $occurrence_id, int $event_id, string $search, string $status_filter, string $placeholder_filter, int $page, string $orderby = '', string $order = 'DESC' ): array {
		$attendees_table   = Schema::table( 'attendees' );
		$occurrences_table = Schema::table( 'occurrences' );
		$events_table      = Schema::table( 'events' );

		$where  = array( '1=1' );
		$params = array();

		if ( $occurrence_id > 0 ) {
			$where[]  = 'a.occurrence_id = %d';
			$params[] = $occurrence_id;
		}

		if ( $event_id > 0 ) {
			$where[]  = 'o.event_id = %d';
			$params[] = $event_id;
		}

		if ( ! empty( $search ) ) {
			$where[]  = '(a.name LIKE %s OR a.email LIKE %s)';
			$like     = '%' . $this->db->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $status_filter ) ) {
			$where[]  = 'a.status = %s';
			$params[] = $status_filter;
		}

		// Filter by placeholder data (name, event, or date).
		// Placeholder patterns: name "Attendee NNNNN", event "Imported Attendees - Unknown Event", date 2099-12-31.
		if ( 'yes' === $placeholder_filter ) {
			// Match ANY placeholder indicator: placeholder name OR placeholder event OR placeholder date.
			$where[] = "(a.name REGEXP '^Attendee [0-9]+$' OR e.title = 'Imported Attendees - Unknown Event' OR DATE(o.start_datetime) = '2099-12-31')";
		} elseif ( 'no' === $placeholder_filter ) {
			// Exclude ALL placeholder indicators: must have real name AND real event AND real date.
			$where[] = "a.name NOT REGEXP '^Attendee [0-9]+$'";
			$where[] = "(e.title IS NULL OR e.title != 'Imported Attendees - Unknown Event')";
			$where[] = "(o.start_datetime IS NULL OR DATE(o.start_datetime) != '2099-12-31')";
		}

		$where_clause = implode( ' AND ', $where );
		$offset       = ( $page - 1 ) * $this->per_page;

		// Get total count.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin listing query.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table names from Schema class are safe, dynamic WHERE clause built from prepared components.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Conditional prepare when params exist.
		$count_sql = "SELECT COUNT(*) FROM {$attendees_table} a
			LEFT JOIN {$occurrences_table} o ON a.occurrence_id = o.id
			LEFT JOIN {$events_table} e ON o.event_id = e.id
			WHERE {$where_clause}";
		if ( ! empty( $params ) ) {
			$count_sql = $this->db->prepare( $count_sql, $params );
		}
		$total = (int) $this->db->get_var( $count_sql );
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// Build ORDER BY clause. Validate orderby against whitelist.
		$order_clause = 'a.id DESC'; // Default.
		if ( ! empty( $orderby ) && isset( self::SORTABLE_COLUMNS[ $orderby ] ) ) {
			$order_clause = self::SORTABLE_COLUMNS[ $orderby ] . ' ' . $order;
		}

		// Get paginated results with event info.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic params array.
		$tickets_table      = Schema::table( 'tickets' );
		$ticket_types_table = Schema::table( 'ticket_types' );

		$main_sql = "SELECT a.*, o.start_datetime, o.end_datetime, e.title as event_title,
				tt.name AS ticket_type_name,
				( SELECT GROUP_CONCAT( tk.ticket_code ORDER BY tk.id ASC SEPARATOR ', ' )
					FROM {$tickets_table} tk WHERE tk.attendee_id = a.id ) AS ticket_codes
			FROM {$attendees_table} a
			LEFT JOIN {$occurrences_table} o ON a.occurrence_id = o.id
			LEFT JOIN {$events_table} e ON o.event_id = e.id
			LEFT JOIN {$ticket_types_table} tt ON a.ticket_type_id = tt.id
			WHERE {$where_clause}
			ORDER BY {$order_clause}
			LIMIT %d OFFSET %d";
		$query    = $this->db->prepare( $main_sql, array_merge( $params, array( $this->per_page, $offset ) ) );
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$items = $this->db->get_results( $query, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'items' => ! empty( $items ) ? $items : array(),
			'total' => $total,
			'pages' => (int) ceil( $total / $this->per_page ),
		);
	}

	/**
	 * Event title for the event-scoped page heading.
	 *
	 * The by-status counts that once lived here now come from
	 * AttendeesSummaryService's Attendance Overview, so this only resolves the
	 * heading text (NTE-143).
	 *
	 * @param int $event_id Event ID.
	 * @return string Event title, or '' when the event is missing.
	 */
	private function get_event_title( int $event_id ): string {
		$events_table = Schema::table( 'events' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin heading query.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table name from Schema class; user value bound via prepare().
		$title = (string) $this->db->get_var(
			$this->db->prepare( "SELECT title FROM {$events_table} WHERE id = %d", $event_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $title;
	}

	/**
	 * Render the Event Details, Ticket Overview and Attendance Overview blocks.
	 *
	 * @param int $event_id      Event being viewed.
	 * @param int $occurrence_id Occurrence drill-down filter (0 = all occurrences).
	 * @return void
	 */
	private function render_event_summary( int $event_id, int $occurrence_id ): void {
		$summary    = $this->summary_service->build( $event_id, $occurrence_id );
		$event      = $summary['event'];
		$tickets    = $summary['tickets'];
		$attendance = $summary['attendance'];
		?>
		<div class="nte-attendees-summary nte-attendees-summary--two-col">
			<div class="nte-summary-col">
			<div class="nte-summary-card">
				<h3><?php esc_html_e( 'Event Details', 'nettertech-events' ); ?></h3>
				<ul class="nte-summary-list">
					<?php if ( '' !== $event['date'] ) : ?>
						<li>
							<span class="nte-summary-label"><?php esc_html_e( 'Date', 'nettertech-events' ); ?></span>
							<span><?php echo esc_html( $event['date'] ); ?></span>
						</li>
					<?php endif; ?>
					<?php if ( '' !== $event['venue'] ) : ?>
						<li>
							<span class="nte-summary-label"><?php esc_html_e( 'Venue', 'nettertech-events' ); ?></span>
							<span><?php echo esc_html( $event['venue'] ); ?></span>
						</li>
					<?php endif; ?>
				</ul>
				<p class="nte-summary-actions">
					<?php if ( '' !== $event['edit_url'] ) : ?>
						<a href="<?php echo esc_url( $event['edit_url'] ); ?>"><?php esc_html_e( 'Edit Event', 'nettertech-events' ); ?></a>
					<?php endif; ?>
					<?php if ( '' !== $event['view_url'] ) : ?>
						<a href="<?php echo esc_url( $event['view_url'] ); ?>"><?php esc_html_e( 'View Event', 'nettertech-events' ); ?></a>
					<?php endif; ?>
				</p>
			</div>

			<?php
			/**
			 * Filters per-ticket-type revenue for the consolidated overview.
			 *
			 * Return a tier-id-keyed map to add a net-revenue column (refunds and
			 * voids already subtracted); base renders no revenue of its own.
			 *
			 * @since 1.1.2
			 *
			 * @param array<int, array{gross: float, refunds: float, net: float}>|null $revenue       Null when nothing answers.
			 * @param int                                                              $event_id      Event being viewed.
			 * @param int                                                              $occurrence_id Occurrence filter (0 = all).
			 */
			$revenue      = apply_filters( 'nettertech_events_ticket_type_revenue', null, $event_id, $occurrence_id );
			$show_revenue = is_array( $revenue ) && function_exists( 'wc_price' );
			?>
			<?php if ( ! empty( $tickets['rows'] ) ) : ?>
				<div class="nte-summary-card">
					<h3><?php esc_html_e( 'Tickets & Attendance', 'nettertech-events' ); ?></h3>
					<table class="nte-summary-table nte-summary-table--consolidated">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Ticket', 'nettertech-events' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Issued', 'nettertech-events' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Checked in', 'nettertech-events' ); ?></th>
								<?php if ( $show_revenue ) : ?>
									<th scope="col"><?php esc_html_e( 'Net revenue', 'nettertech-events' ); ?></th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $tickets['rows'] as $ticket ) : ?>
								<tr>
									<td class="nte-summary-ticket-name"><?php echo esc_html( $ticket['name'] ); ?></td>
									<td class="nte-summary-ticket-count">
										<span class="nte-summary-issued"><?php echo esc_html( $this->format_issued( $ticket['issued'] ) ); ?></span>
										<span class="nte-summary-available"><?php echo esc_html( $this->format_available( $ticket['available'], ! empty( $tickets['shares_house'] ) && ! empty( $ticket['house_bound'] ) ) ); ?></span>
									</td>
									<td class="nte-summary-checkin-count">
										<?php echo esc_html( $this->format_checked_in( (int) $ticket['checked_in'], ! empty( $ticket['is_pass'] ) ) ); ?>
									</td>
									<?php if ( $show_revenue ) : ?>
										<td class="nte-summary-revenue"
											<?php if ( ! empty( $revenue[ $ticket['id'] ]['refunds'] ) ) : ?>
												title="<?php echo esc_attr( $this->format_revenue_detail( $revenue[ $ticket['id'] ] ) ); ?>"
											<?php endif; ?>>
											<?php echo isset( $revenue[ $ticket['id'] ] ) ? wp_kses_post( wc_price( $revenue[ $ticket['id'] ]['net'] ) ) : esc_html_x( '—', 'no revenue recorded', 'nettertech-events' ); ?>
										</td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<tr>
								<th class="nte-summary-ticket-name" scope="row"><?php esc_html_e( 'Total', 'nettertech-events' ); ?></th>
								<td class="nte-summary-ticket-count">
									<span class="nte-summary-issued"><?php echo esc_html( $this->format_issued( $tickets['total_issued'] ) ); ?></span>
									<span class="nte-summary-available"><?php echo esc_html( $this->format_available( $tickets['total_available'], false ) ); ?></span>
								</td>
								<td class="nte-summary-checkin-count">
									<?php
									printf(
										/* translators: 1: checked-in guest count, 2: percentage. */
										esc_html__( '%1$d (%2$d%%)', 'nettertech-events' ),
										(int) $attendance['checked_in_guests'],
										(int) $attendance['checked_in_percent']
									);
									?>
								</td>
								<?php if ( $show_revenue ) : ?>
									<td class="nte-summary-revenue">
										<?php echo wp_kses_post( wc_price( array_sum( array_map( static fn( array $r ): float => (float) $r['net'], $revenue ) ) ) ); ?>
									</td>
								<?php endif; ?>
							</tr>
						</tfoot>
					</table>
					<?php if ( ! empty( $tickets['shares_house'] ) ) : ?>
						<p class="nte-summary-footnote"><?php echo esc_html( $this->format_house_footnote( (int) $tickets['house'] ) ); ?></p>
					<?php endif; ?>
					<ul class="nte-summary-list nte-summary-statuses">
						<li>
							<span class="nte-summary-label"><?php esc_html_e( 'Tickets', 'nettertech-events' ); ?></span>
							<span><?php echo esc_html( (string) $attendance['total_guests'] ); ?></span>
						</li>
						<?php foreach ( $attendance['status_counts'] as $status_key => $status_count ) : ?>
							<li>
								<span class="nte-summary-label"><?php echo esc_html( ucfirst( (string) $status_key ) ); ?></span>
								<span><?php echo esc_html( (string) (int) $status_count ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			</div>

			<?php if ( has_action( 'nettertech_events_purchases_synopsis' ) ) : ?>
				<div class="nte-summary-col">
				<div class="nte-summary-card nte-summary-card--synopsis">
					<?php
					/**
					 * Fires inside the event-scoped Purchases summary, as its last card.
					 *
					 * Null-safe extension point: the card is only emitted when something
					 * hooks it, and base renders nothing extra of its own. Whatever is echoed
					 * here inherits the summary card's chrome, so an extension does not have
					 * to know the card markup. Pro attaches the financial synopsis here.
					 *
					 * @param int $event_id      The event ID being viewed.
					 * @param int $occurrence_id The occurrence drill-down filter (0 = all occurrences).
					 */
					do_action( 'nettertech_events_purchases_synopsis', $event_id, $occurrence_id );
					?>
				</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Format the issued figure for a Ticket Overview row.
	 *
	 * @param int $issued Guests issued (from confirmed attendee records).
	 * @return string Display string.
	 */
	private function format_issued( int $issued ): string {
		/* translators: %d: number of guests issued a ticket. */
		return sprintf( _n( '%d issued', '%d issued', $issued, 'nettertech-events' ), $issued );
	}

	/**
	 * Format a tier's checked-in figure for the consolidated overview.
	 *
	 * A pass is scanned at the gate of every date it spans, so its figure counts
	 * scans, not people — one buyer over two days is two scans of one pass, and
	 * saying "2 checked in" beneath "1 issued" without the unit invites a
	 * miscount panic at the door.
	 *
	 * @param int  $checked_in Check-in count (guests, or gate scans for a pass).
	 * @param bool $is_pass    Whether the tier is an event-scoped series pass.
	 * @return string Display string.
	 */
	private function format_checked_in( int $checked_in, bool $is_pass ): string {
		if ( $is_pass ) {
			/* translators: %d: number of gate scans across the pass's dates. */
			return sprintf( _n( '%d scan', '%d scans', $checked_in, 'nettertech-events' ), $checked_in );
		}

		return (string) $checked_in;
	}

	/**
	 * Format the gross→refund arithmetic behind a net revenue figure.
	 *
	 * Shown as the cell's title so the net number stays scannable while the
	 * derivation stays one hover away (reconciliation rule: no headline figure
	 * without its countable parts).
	 *
	 * @param array{gross: float, refunds: float, net: float} $revenue Tier revenue.
	 * @return string Plain-text detail line.
	 */
	private function format_revenue_detail( array $revenue ): string {
		return sprintf(
			/* translators: 1: gross amount, 2: refunded amount, 3: net amount. */
			__( 'Gross %1$s − refunds %2$s = net %3$s', 'nettertech-events' ),
			html_entity_decode( wp_strip_all_tags( wc_price( $revenue['gross'] ) ) ),
			html_entity_decode( wp_strip_all_tags( wc_price( $revenue['refunds'] ) ) ),
			html_entity_decode( wp_strip_all_tags( wc_price( $revenue['net'] ) ) )
		);
	}

	/**
	 * Format the availability figure for a Ticket Overview row.
	 *
	 * Rendered on its own line beneath the issued count so a long label wraps
	 * inside the card rather than overflowing it.
	 *
	 * @param int|null $available  Remaining capacity, or null when unlimited.
	 * @param bool     $house_bound Whether this row is quoting the house remainder.
	 * @return string Display string.
	 */
	private function format_available( ?int $available, bool $house_bound ): string {
		if ( null === $available ) {
			return __( 'unlimited', 'nettertech-events' );
		}

		if ( $house_bound ) {
			return sprintf(
				/* translators: %d: guests the house can still seat. The asterisk refers to the footnote below the table. */
				__( '%d available*', 'nettertech-events' ),
				$available
			);
		}

		return sprintf(
			/* translators: %d: guests available. */
			__( '%d available', 'nettertech-events' ),
			$available
		);
	}

	/**
	 * Explain the asterisk when several tiers sell into one house.
	 *
	 * Each tier reports what the room has left, not a private allotment, so the
	 * footnote names the house and states that the Total counts it once rather
	 * than once per tier.
	 *
	 * @param int $house Total seats the event can sell.
	 * @return string Footnote text.
	 */
	private function format_house_footnote( int $house ): string {
		return sprintf(
			/* translators: %d: total capacity of the room (the "house"). */
			__( '* house capacity (%d), counted once', 'nettertech-events' ),
			$house
		);
	}

	/**
	 * Render the page HTML.
	 *
	 * @param array<string, mixed> $result             Query result.
	 * @param int                  $occurrence_id      Current occurrence filter.
	 * @param int                  $event_id           Current event filter (0 = none).
	 * @param string               $search             Current search query.
	 * @param string               $status_filter      Current status filter.
	 * @param string               $placeholder_filter Current placeholder filter.
	 * @param int                  $page               Current page.
	 * @param string               $orderby            Current sort column.
	 * @param string               $order              Current sort direction.
	 * @return void
	 */
	private function render_page( array $result, int $occurrence_id, int $event_id, string $search, string $status_filter, string $placeholder_filter, int $page, string $orderby = '', string $order = 'DESC' ): void {
		// Occurrence dropdown: scope to the event's own occurrences when event-filtered
		// (group-by-occurrence drill-down); otherwise the upcoming set.
		$occurrences = $event_id > 0 ? $this->occurrence_repo->for_event( $event_id ) : $this->occurrence_repo->upcoming( 100 );
		$event_title = $event_id > 0 ? $this->get_event_title( $event_id ) : null;
		?>
		<div class="wrap">
			<?php Branding::render_header(); ?>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Attendees', 'nettertech-events' ); ?></h1>
			<hr class="wp-header-end">

			<?php if ( null !== $event_title ) : ?>
				<h2 class="nte-attendees-event-scope">
					<?php
					printf(
						/* translators: %s: event title */
						esc_html__( 'Purchases for: %s', 'nettertech-events' ),
						esc_html( '' !== $event_title ? $event_title : __( '(event)', 'nettertech-events' ) )
					);
					?>
				</h2>

				<?php $this->render_event_summary( $event_id, $occurrence_id ); ?>
			<?php endif; ?>

			<p class="description">
				<?php
				printf(
					/* translators: %d: total number of attendees */
					esc_html__( 'Showing %d total attendees.', 'nettertech-events' ),
					(int) $result['total']
				);
				?>
			</p>

			<?php $this->render_filters( $occurrence_id, $event_id, $search, $status_filter, $placeholder_filter, $occurrences ); ?>

			<?php if ( empty( $result['items'] ) ) : ?>
				<div class="notice notice-info">
					<p><?php esc_html_e( 'No attendees found.', 'nettertech-events' ); ?></p>
				</div>
			<?php else : ?>
				<form method="post" id="nte-attendees-bulk-form">
					<?php wp_nonce_field( 'nettertech_events_attendees_bulk_action', 'nettertech_events_attendees_nonce' ); ?>
					<input type="hidden" name="filter_occurrence_id" value="<?php echo esc_attr( (string) $occurrence_id ); ?>">
					<input type="hidden" name="filter_event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
					<input type="hidden" name="filter_search" value="<?php echo esc_attr( $search ); ?>">
					<input type="hidden" name="filter_status" value="<?php echo esc_attr( $status_filter ); ?>">
					<input type="hidden" name="filter_placeholder" value="<?php echo esc_attr( $placeholder_filter ); ?>">
					<?php $this->render_bulk_actions( $result['total'] ); ?>
					<?php
					/**
					 * Filter the extra columns appended to the Attendees table.
					 *
					 * Extensions add columns as key => header-label. The base ships
					 * none; Pro uses this for the per-attendee Coupon column. Keys
					 * become the `column-{key}` CSS class and the switch value passed
					 * to `nettertech_events_attendees_column_content`.
					 *
					 * @since 1.1.2
					 *
					 * @param array<string, string> $columns       Map of column key => header label.
					 * @param int                    $event_id      Event scope (0 = all).
					 * @param int                    $occurrence_id Occurrence scope (0 = all).
					 */
					$extra_columns = (array) apply_filters( 'nettertech_events_attendees_columns', array(), $event_id, $occurrence_id );

					/**
					 * Fires once before the attendee rows render, with the full page set.
					 *
					 * Extension columns hook this to batch-prefetch per-row data (e.g.
					 * load every WooCommerce order on the page in one query) so the row
					 * loop stays free of per-row lookups.
					 *
					 * @since 1.1.2
					 *
					 * @param array<array<string, mixed>> $items         Attendee rows on this page.
					 * @param int                         $event_id      Event scope (0 = all).
					 * @param int                         $occurrence_id Occurrence scope (0 = all).
					 */
					do_action( 'nettertech_events_attendees_prime', $result['items'], $event_id, $occurrence_id );

					$this->render_table( $result['items'], $orderby, $order, $extra_columns );
					?>
					<?php $this->render_pagination( $result['total'], $result['pages'], $page, $occurrence_id, $event_id, $search, $status_filter, $placeholder_filter, $orderby, $order ); ?>
				</form>
			<?php endif; ?>

			<?php $this->render_attendee_dialogs( $event_id, $occurrences ); ?>
		</div>

		<?php
	}

	/**
	 * Render bulk actions dropdown.
	 *
	 * @param int $total_count Total number of filtered attendees.
	 * @return void
	 */
	private function render_bulk_actions( int $total_count ): void {
		?>
		<div class="tablenav top">
			<div class="alignleft actions bulkactions">
				<label for="nte-bulk-action-selector-top" class="screen-reader-text">
					<?php esc_html_e( 'Select bulk action', 'nettertech-events' ); ?>
				</label>
				<select name="bulk_action" id="nte-bulk-action-selector-top">
					<option value=""><?php esc_html_e( 'Bulk Actions', 'nettertech-events' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete', 'nettertech-events' ); ?></option>
					<option value="export"><?php esc_html_e( 'Export Selected', 'nettertech-events' ); ?></option>
					<?php if ( class_exists( 'WooCommerce' ) ) : ?>
						<option value="email"><?php esc_html_e( 'Re-send Confirmation Email', 'nettertech-events' ); ?></option>
					<?php endif; ?>
				</select>
				<input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'nettertech-events' ); ?>">
			</div>
			<div class="alignleft actions">
				<button type="submit" name="bulk_action" value="export_all" class="button">
					<?php
					printf(
						/* translators: %d: number of attendees */
						esc_html__( 'Export All (%d)', 'nettertech-events' ),
						(int) $total_count
					);
					?>
				</button>
				<button type="button" class="button" id="nte-add-attendee-open">
					<?php esc_html_e( '+ Add Attendee', 'nettertech-events' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the filters form.
	 *
	 * @param int                                        $occurrence_id      Current occurrence filter.
	 * @param int                                        $event_id           Current event filter (0 = none).
	 * @param string                                     $search             Current search.
	 * @param string                                     $status_filter      Current status.
	 * @param string                                     $placeholder_filter Current placeholder filter.
	 * @param array<\NetterTechEvents\Models\Occurrence> $occurrences        Available occurrences.
	 * @return void
	 */
	private function render_filters( int $occurrence_id, int $event_id, string $search, string $status_filter, string $placeholder_filter, array $occurrences ): void {
		$presenter = new AttendeesFiltersPresenter(
			$occurrence_id,
			$event_id,
			$search,
			$status_filter,
			$placeholder_filter,
			$occurrences,
			AdminMenu::SUBMENU_ATTENDEES,
			admin_url( 'admin.php?page=' . AdminMenu::SUBMENU_ATTENDEES )
		);
		include dirname( __DIR__, 2 ) . '/templates/admin/attendees/filters.php';
	}

	/**
	 * Render the attendees table.
	 *
	 * @param array<array<string, mixed>> $items         Attendee records.
	 * @param string                      $orderby       Current sort column.
	 * @param string                      $order         Current sort direction.
	 * @param array<string, string>       $extra_columns Extension columns (key => header label).
	 * @return void
	 */
	private function render_table( array $items, string $orderby = '', string $order = 'DESC', array $extra_columns = array() ): void {
		?>
		<table class="wp-list-table widefat fixed striped nte-attendees-table">
			<thead>
				<tr>
					<td class="manage-column column-cb check-column">
						<input type="checkbox" id="cb-select-all" />
					</td>
					<?php
					// `column-primary` is what core's responsive list-table rules key on:
					// below 782px every column after the primary one collapses behind the
					// row's toggle. Without it the fixed column widths simply overflow.
					$this->render_sortable_header( 'name', __( 'Name', 'nettertech-events' ), $orderby, $order, 'column-name column-primary' );
					?>
					<?php $this->render_sortable_header( 'email', __( 'Email', 'nettertech-events' ), $orderby, $order, 'column-email' ); ?>
					<?php $this->render_sortable_header( 'event', __( 'Event', 'nettertech-events' ), $orderby, $order, 'column-event' ); ?>
				<?php $this->render_sortable_header( 'ticket_type', __( 'Ticket type', 'nettertech-events' ), $orderby, $order, 'column-ticket-type' ); ?>
					<?php $this->render_sortable_header( 'datetime', __( 'Date/Time', 'nettertech-events' ), $orderby, $order, 'column-datetime' ); ?>
					<th scope="col" class="column-quantity"><?php esc_html_e( 'Qty', 'nettertech-events' ); ?></th>
					<?php $this->render_sortable_header( 'status', __( 'Status', 'nettertech-events' ), $orderby, $order, 'column-status' ); ?>
					<th scope="col" class="column-checkin"><?php esc_html_e( 'Checked In', 'nettertech-events' ); ?></th>
					<?php foreach ( $extra_columns as $nettertech_events_col_key => $nettertech_events_col_label ) : ?>
						<th scope="col" class="column-<?php echo esc_attr( $nettertech_events_col_key ); ?> nte-ext-column"><?php echo esc_html( $nettertech_events_col_label ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $item ) : ?>
					<?php $this->render_row( $item, $extra_columns ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render a sortable column header.
	 *
	 * @param string $column_key  Column key (must be in SORTABLE_COLUMNS).
	 * @param string $label       Display label.
	 * @param string $orderby     Current sort column.
	 * @param string $order       Current sort direction.
	 * @param string $extra_class Additional CSS class.
	 * @return void
	 */
	private function render_sortable_header( string $column_key, string $label, string $orderby, string $order, string $extra_class = '' ): void {
		$is_sorted  = ( $orderby === $column_key );
		$new_order  = $is_sorted && 'ASC' === $order ? 'desc' : 'asc';
		$sort_class = $is_sorted ? 'sorted ' . strtolower( $order ) : 'sortable desc';
		$class      = trim( "manage-column {$extra_class} {$sort_class}" );

		// Build URL with sort params, preserving existing query args.
		$url = add_query_arg(
			array(
				'orderby' => $column_key,
				'order'   => $new_order,
			)
		);
		?>
		<th scope="col" class="<?php echo esc_attr( $class ); ?>">
			<a href="<?php echo esc_url( $url ); ?>">
				<span><?php echo esc_html( $label ); ?></span>
				<span class="sorting-indicators">
					<span class="sorting-indicator asc" aria-hidden="true"></span>
					<span class="sorting-indicator desc" aria-hidden="true"></span>
				</span>
			</a>
		</th>
		<?php
	}

	/**
	 * Render a single table row.
	 *
	 * @param array<string, mixed>  $item          Attendee record.
	 * @param array<string, string> $extra_columns Extension columns (key => header label).
	 * @return void
	 */
	private function render_row( array $item, array $extra_columns = array() ): void {
		$quantity      = (int) ( $item['quantity'] ?? 1 );
		$checked_count = (int) ( $item['checked_in_count'] ?? 0 );
		$status        = $item['status'] ?? 'confirmed';

		// Format datetime.
		$datetime = '';
		if ( ! empty( $item['start_datetime'] ) ) {
			$start    = new \DateTime( $item['start_datetime'] );
			$datetime = $start->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		}

		// Check-in display.
		if ( $checked_count >= $quantity ) {
			$checkin_class = 'checkin-yes';
			$checkin_text  = __( 'Yes', 'nettertech-events' );
		} elseif ( $checked_count > 0 ) {
			$checkin_class = 'checkin-partial';
			$checkin_text  = sprintf( '%d/%d', $checked_count, $quantity );
		} else {
			$checkin_class = 'checkin-no';
			$checkin_text  = __( 'No', 'nettertech-events' );
		}
		?>
		<tr>
			<th scope="row" class="check-column">
				<input type="checkbox" name="attendee_ids[]" value="<?php echo esc_attr( (string) ( $item['id'] ?? 0 ) ); ?>" />
			</th>
			<td class="column-name column-primary has-row-actions" data-colname="<?php esc_attr_e( 'Name', 'nettertech-events' ); ?>">
				<strong><?php echo esc_html( $item['name'] ?? '' ); ?></strong>
				<?php
				// Identity line: the attendee id anchors support conversations, the
				// ticket code is what the scanner reads — the pair makes a row
				// findable from either end of a "my ticket doesn't work" email.
				$nettertech_events_identity = '#' . (int) ( $item['id'] ?? 0 );
				if ( ! empty( $item['ticket_codes'] ) ) {
					$nettertech_events_identity .= ' — ' . (string) $item['ticket_codes'];
				}
				?>
				<div class="nte-attendee-identity"><?php echo esc_html( $nettertech_events_identity ); ?></div>
				<?php $this->render_row_actions( (int) ( $item['id'] ?? 0 ), (int) ( $item['wc_order_id'] ?? 0 ), $item ); ?>
				<button type="button" class="toggle-row">
					<span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'nettertech-events' ); ?></span>
				</button>
			</td>
			<td class="column-email" data-colname="<?php esc_attr_e( 'Email', 'nettertech-events' ); ?>">
				<a href="mailto:<?php echo esc_attr( $item['email'] ?? '' ); ?>">
					<?php echo esc_html( $item['email'] ?? '' ); ?>
				</a>
			</td>
			<td class="column-event" data-colname="<?php esc_attr_e( 'Event', 'nettertech-events' ); ?>">
				<?php echo esc_html( $item['event_title'] ?? __( 'Unknown Event', 'nettertech-events' ) ); ?>
				<?php
				$nettertech_events_order_id = (int) ( $item['wc_order_id'] ?? 0 );
				if ( $nettertech_events_order_id > 0 ) :
					$nettertech_events_order_url   = $this->wc_order_edit_url( $nettertech_events_order_id );
					$nettertech_events_order_label = sprintf(
						/* translators: %d: WooCommerce order ID */
						__( 'Order #%d', 'nettertech-events' ),
						$nettertech_events_order_id
					);
					?>
					<div class="row-order">
						<?php if ( '' !== $nettertech_events_order_url ) : ?>
							<a href="<?php echo esc_url( $nettertech_events_order_url ); ?>"><?php echo esc_html( $nettertech_events_order_label ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $nettertech_events_order_label ); ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</td>
			<td class="column-ticket-type" data-colname="<?php esc_attr_e( 'Ticket type', 'nettertech-events' ); ?>">
				<?php echo esc_html( (string) ( $item['ticket_type_name'] ?? '—' ) ); ?>
			</td>
			<td class="column-datetime" data-colname="<?php esc_attr_e( 'Date/Time', 'nettertech-events' ); ?>">
				<?php echo esc_html( $datetime ); ?>
			</td>
			<td class="column-quantity" data-colname="<?php esc_attr_e( 'Qty', 'nettertech-events' ); ?>">
				<?php echo esc_html( (string) $quantity ); ?>
			</td>
			<td class="column-status" data-colname="<?php esc_attr_e( 'Status', 'nettertech-events' ); ?>">
				<span class="status-badge status-<?php echo esc_attr( $status ); ?>">
					<?php echo esc_html( ucfirst( $status ) ); ?>
				</span>
			</td>
			<td class="column-checkin" data-colname="<?php esc_attr_e( 'Checked In', 'nettertech-events' ); ?>">
				<span class="nte-checkin-state <?php echo esc_attr( $checkin_class ); ?>">
					<?php echo esc_html( $checkin_text ); ?>
				</span>
				<?php $this->render_check_in_toggle( $item, $checked_count, $quantity ); ?>
			</td>
			<?php foreach ( $extra_columns as $nettertech_events_col_key => $nettertech_events_col_label ) : ?>
				<td class="column-<?php echo esc_attr( $nettertech_events_col_key ); ?> nte-ext-column" data-colname="<?php echo esc_attr( $nettertech_events_col_label ); ?>">
					<?php
					/**
					 * Filter the cell content for an extension column on the Attendees table.
					 *
					 * Extensions return the cell HTML for their column keyed by
					 * $column_key. Prime per-page data on
					 * `nettertech_events_attendees_prime` so this stays lookup-free.
					 * Return value is passed through wp_kses_post().
					 *
					 * @since 1.1.2
					 *
					 * @param string               $content    Cell HTML (default '').
					 * @param string               $column_key Column key being rendered.
					 * @param array<string, mixed> $item       Attendee record (includes wc_order_id).
					 */
					echo wp_kses_post( (string) apply_filters( 'nettertech_events_attendees_column_content', '', $nettertech_events_col_key, $item ) );
					?>
				</td>
			<?php endforeach; ?>
		</tr>
		<?php
	}

	/**
	 * Render the check-in toggle button for a single attendee row.
	 *
	 * Base ships logged-in check-in for anyone who can `edit_posts` — the same
	 * capability that gates this screen. Pro's volunteer check-in is a separate,
	 * token-authenticated surface and is unaffected by this control.
	 *
	 * @param array<string, mixed> $item          Attendee record.
	 * @param int                  $checked_count Guests already checked in.
	 * @param int                  $quantity      Total guests on the record.
	 * @return void
	 */
	private function render_check_in_toggle( array $item, int $checked_count, int $quantity ): void {
		$attendee_id = (int) ( $item['id'] ?? 0 );
		if ( $attendee_id <= 0 || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Only confirmed attendees hold a valid ticket. A voided, refunded,
		// cancelled or still-pending registration must not be checkable in — its
		// guests are already excluded from the check-in stats denominator.
		if ( 'confirmed' !== (string) ( $item['status'] ?? '' ) ) {
			return;
		}

		$is_checked_in = $checked_count >= $quantity;
		$name          = (string) ( $item['name'] ?? '' );

		$label = $is_checked_in
			? __( 'Undo', 'nettertech-events' )
			: __( 'Check In', 'nettertech-events' );

		if ( $is_checked_in ) {
			/* translators: %s: attendee name. */
			$accessible_label = sprintf( __( 'Undo check-in for %s', 'nettertech-events' ), $name );
		} else {
			/* translators: %s: attendee name. */
			$accessible_label = sprintf( __( 'Check in %s', 'nettertech-events' ), $name );
		}
		?>
		<button type="button"
			class="button button-small nte-checkin-toggle"
			data-attendee-id="<?php echo esc_attr( (string) $attendee_id ); ?>"
			data-attendee-name="<?php echo esc_attr( $name ); ?>"
			data-state="<?php echo esc_attr( $is_checked_in ? 'out' : 'in' ); ?>"
			aria-label="<?php echo esc_attr( $accessible_label ); ?>">
			<?php echo esc_html( $label ); ?>
		</button>
		<?php
	}

	/**
	 * Build the admin edit URL for a WooCommerce order (HPOS-aware).
	 *
	 * Computes the URL from the id without loading the order, so the listing
	 * pays no per-row order query. Returns '' if WooCommerce is unavailable.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string Admin edit URL, or '' when WooCommerce is not active.
	 */
	private function wc_order_edit_url( int $order_id ): string {
		if ( $order_id <= 0 || ! class_exists( 'WooCommerce' ) ) {
			return '';
		}

		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}

		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	/**
	 * Render row actions for a single attendee.
	 *
	 * @param int                  $attendee_id Attendee ID.
	 * @param int                  $wc_order_id Linked WooCommerce order (0 = none).
	 * @param array<string, mixed> $item        Row data for the Edit dialog's prefill.
	 * @return void
	 */
	private function render_row_actions( int $attendee_id, int $wc_order_id = 0, array $item = array() ): void {
		if ( $attendee_id <= 0 ) {
			return;
		}

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'nettertech_events_delete_attendee',
					'attendee_id' => $attendee_id,
				),
				admin_url( 'admin.php?page=' . AdminMenu::SUBMENU_ATTENDEES )
			),
			'nettertech_events_delete_attendee_' . $attendee_id
		);

		// Re-sending the confirmation replays a WooCommerce order email, so it is
		// offered only for order-backed attendees and only to order managers.
		// RSVP attendees have no order and correctly get no re-send action.
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- edit_shop_orders is a WooCommerce capability.
		$can_resend = $wc_order_id > 0 && current_user_can( 'edit_shop_orders' );
		?>
		<div class="row-actions">
			<span class="edit">
				<button type="button"
					class="button-link nte-edit-attendee"
					data-attendee-id="<?php echo esc_attr( (string) $attendee_id ); ?>"
					data-attendee-name="<?php echo esc_attr( (string) ( $item['name'] ?? '' ) ); ?>"
					data-attendee-email="<?php echo esc_attr( (string) ( $item['email'] ?? '' ) ); ?>"
					data-attendee-status="<?php echo esc_attr( (string) ( $item['status'] ?? 'confirmed' ) ); ?>"
					data-attendee-quantity="<?php echo esc_attr( (string) (int) ( $item['quantity'] ?? 1 ) ); ?>">
					<?php esc_html_e( 'Edit', 'nettertech-events' ); ?>
				</button>
				<?php echo ' | '; ?>
			</span>
			<?php if ( $can_resend ) : ?>
				<span class="resend">
					<button type="button"
						class="button-link nte-resend-email"
						data-order-id="<?php echo esc_attr( (string) $wc_order_id ); ?>">
						<?php esc_html_e( 'Re-send Email', 'nettertech-events' ); ?>
					</button>
					<?php echo ' | '; ?>
				</span>
			<?php endif; ?>
			<span class="delete">
				<a href="<?php echo esc_url( $delete_url ); ?>"
					class="submitdelete"
					onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this attendee?', 'nettertech-events' ) ); ?>');">
					<?php esc_html_e( 'Delete', 'nettertech-events' ); ?>
				</a>
			</span>
		</div>
		<?php
	}

	/**
	 * Render the Edit / Add Attendee dialogs (native <dialog>, NTE-143 C5).
	 *
	 * Both post to the admin REST controller from attendee-editor.js; the Add
	 * form offers the event's dates and each date's ticket types so a manual
	 * attendee lands on a real seat and clears the same capacity gate a
	 * purchase does.
	 *
	 * @param int                                        $event_id    Event scope (0 = all events).
	 * @param array<\NetterTechEvents\Models\Occurrence> $occurrences Occurrences offered by the page filter.
	 * @return void
	 */
	private function render_attendee_dialogs( int $event_id, array $occurrences ): void {
		$tiers_by_occurrence = $this->tiers_for_occurrences( $occurrences );
		?>
		<dialog id="nte-edit-attendee-dialog" class="nte-attendee-dialog" aria-labelledby="nte-edit-attendee-title">
			<form method="dialog" class="nte-attendee-dialog-form" id="nte-edit-attendee-form">
				<h2 id="nte-edit-attendee-title"><?php esc_html_e( 'Edit Attendee', 'nettertech-events' ); ?></h2>
				<input type="hidden" name="attendee_id" value="">
				<p>
					<label for="nte-edit-attendee-name"><?php esc_html_e( 'Name', 'nettertech-events' ); ?></label>
					<input type="text" id="nte-edit-attendee-name" name="name" class="regular-text" required>
				</p>
				<p>
					<label for="nte-edit-attendee-email"><?php esc_html_e( 'Email', 'nettertech-events' ); ?></label>
					<input type="email" id="nte-edit-attendee-email" name="email" class="regular-text" required>
				</p>
				<p>
					<label for="nte-edit-attendee-status"><?php esc_html_e( 'Status', 'nettertech-events' ); ?></label>
					<select id="nte-edit-attendee-status" name="status">
						<?php foreach ( \NetterTechEvents\Models\Attendee::STATUSES as $nettertech_events_status ) : ?>
							<option value="<?php echo esc_attr( $nettertech_events_status ); ?>"><?php echo esc_html( ucfirst( $nettertech_events_status ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="nte-edit-attendee-quantity"><?php esc_html_e( 'Quantity', 'nettertech-events' ); ?></label>
					<input type="number" id="nte-edit-attendee-quantity" name="quantity" min="1" step="1" class="small-text">
				</p>
				<p class="nte-attendee-dialog-error" role="alert" hidden></p>
				<p class="nte-attendee-dialog-actions">
					<button type="button" class="button nte-dialog-cancel"><?php esc_html_e( 'Cancel', 'nettertech-events' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'nettertech-events' ); ?></button>
				</p>
			</form>
		</dialog>

		<dialog id="nte-add-attendee-dialog" class="nte-attendee-dialog" aria-labelledby="nte-add-attendee-title">
			<form method="dialog" class="nte-attendee-dialog-form" id="nte-add-attendee-form">
				<h2 id="nte-add-attendee-title"><?php esc_html_e( 'Add Attendee', 'nettertech-events' ); ?></h2>
				<p>
					<label for="nte-add-attendee-occurrence"><?php esc_html_e( 'Date', 'nettertech-events' ); ?></label>
					<select id="nte-add-attendee-occurrence" name="occurrence_id" required>
						<option value=""><?php esc_html_e( 'Select a date…', 'nettertech-events' ); ?></option>
						<?php foreach ( $occurrences as $nettertech_events_occurrence ) : ?>
							<?php
							if ( null === $nettertech_events_occurrence->id ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( (string) $nettertech_events_occurrence->id ); ?>">
								<?php echo esc_html( $nettertech_events_occurrence->get_formatted_date() . ' · ' . $nettertech_events_occurrence->get_formatted_time() ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="nte-add-attendee-ticket-type"><?php esc_html_e( 'Ticket type', 'nettertech-events' ); ?></label>
					<select id="nte-add-attendee-ticket-type" name="ticket_type_id"
						data-tiers="<?php echo esc_attr( (string) wp_json_encode( $tiers_by_occurrence ) ); ?>">
						<option value=""><?php esc_html_e( '(none — free registration)', 'nettertech-events' ); ?></option>
					</select>
				</p>
				<p>
					<label for="nte-add-attendee-name"><?php esc_html_e( 'Name', 'nettertech-events' ); ?></label>
					<input type="text" id="nte-add-attendee-name" name="name" class="regular-text" required>
				</p>
				<p>
					<label for="nte-add-attendee-email"><?php esc_html_e( 'Email', 'nettertech-events' ); ?></label>
					<input type="email" id="nte-add-attendee-email" name="email" class="regular-text" required>
				</p>
				<p>
					<label for="nte-add-attendee-quantity"><?php esc_html_e( 'Quantity', 'nettertech-events' ); ?></label>
					<input type="number" id="nte-add-attendee-quantity" name="quantity" min="1" step="1" value="1" class="small-text">
				</p>
				<p class="nte-attendee-dialog-error" role="alert" hidden></p>
				<p class="nte-attendee-dialog-actions">
					<button type="button" class="button nte-dialog-cancel"><?php esc_html_e( 'Cancel', 'nettertech-events' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Add Attendee', 'nettertech-events' ); ?></button>
				</p>
			</form>
		</dialog>
		<?php
	}

	/**
	 * Map occurrence id => its ticket types (id + name), for the Add dialog.
	 *
	 * One query for the whole set — the dialog must not cost a query per date.
	 *
	 * @param array<\NetterTechEvents\Models\Occurrence> $occurrences Occurrences on offer.
	 * @return array<int, array<int, array{id: int, name: string}>>
	 */
	private function tiers_for_occurrences( array $occurrences ): array {
		$ids = array();
		foreach ( $occurrences as $occurrence ) {
			if ( null !== $occurrence->id ) {
				$ids[] = (int) $occurrence->id;
			}
		}

		if ( empty( $ids ) ) {
			return array();
		}

		$ticket_types = Schema::table( 'ticket_types' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin dialog options.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table name from Schema; IDs bound via prepare().
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, name, occurrence_id FROM {$ticket_types}
				WHERE status = 'active' AND occurrence_id IN ( {$placeholders} )
				ORDER BY sort_order ASC, name ASC",
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['occurrence_id'] ][] = array(
				'id'   => (int) $row['id'],
				'name' => (string) $row['name'],
			);
		}

		return $map;
	}

	/**
	 * Render pagination.
	 *
	 * @param int    $total              Total items.
	 * @param int    $pages              Total pages.
	 * @param int    $current_page       Current page.
	 * @param int    $occurrence_id      Occurrence filter.
	 * @param int    $event_id           Event filter (0 = none).
	 * @param string $search             Search query.
	 * @param string $status_filter      Status filter.
	 * @param string $placeholder_filter Placeholder filter.
	 * @param string $orderby            Current sort column.
	 * @param string $order              Current sort direction.
	 * @return void
	 */
	private function render_pagination( int $total, int $pages, int $current_page, int $occurrence_id, int $event_id, string $search, string $status_filter, string $placeholder_filter, string $orderby = '', string $order = 'DESC' ): void {
		if ( $pages <= 1 ) {
			return;
		}

		$base_url = admin_url( 'admin.php?page=' . AdminMenu::SUBMENU_ATTENDEES );
		if ( $occurrence_id ) {
			$base_url = add_query_arg( 'occurrence_id', $occurrence_id, $base_url );
		}
		if ( $event_id ) {
			$base_url = add_query_arg( 'event_id', $event_id, $base_url );
		}
		if ( $search ) {
			$base_url = add_query_arg( 's', $search, $base_url );
		}
		if ( $status_filter ) {
			$base_url = add_query_arg( 'status', $status_filter, $base_url );
		}
		if ( $placeholder_filter ) {
			$base_url = add_query_arg( 'placeholder', $placeholder_filter, $base_url );
		}
		if ( $orderby ) {
			$base_url = add_query_arg( 'orderby', $orderby, $base_url );
			$base_url = add_query_arg( 'order', strtolower( $order ), $base_url );
		}

		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		echo '<span class="displaying-num">' . esc_html(
			sprintf(
				/* translators: %d: total items */
				_n( '%d item', '%d items', $total, 'nettertech-events' ),
				$total
			)
		) . '</span>';

		echo '<span class="pagination-links">';

		// First page.
		if ( $current_page > 1 ) {
			echo '<a class="first-page button" href="' . esc_url( $base_url ) . '"><span aria-hidden="true">&laquo;</span></a>';
			echo '<a class="prev-page button" href="' . esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ) . '"><span aria-hidden="true">&lsaquo;</span></a>';
		} else {
			echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
			echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
		}

		echo '<span class="paging-input">' . esc_html( (string) $current_page ) . ' of <span class="total-pages">' . esc_html( (string) $pages ) . '</span></span>';

		// Last page.
		if ( $current_page < $pages ) {
			echo '<a class="next-page button" href="' . esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ) . '"><span aria-hidden="true">&rsaquo;</span></a>';
			echo '<a class="last-page button" href="' . esc_url( add_query_arg( 'paged', $pages, $base_url ) ) . '"><span aria-hidden="true">&raquo;</span></a>';
		} else {
			echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
			echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
		}

		echo '</span></div></div>';
	}
}
