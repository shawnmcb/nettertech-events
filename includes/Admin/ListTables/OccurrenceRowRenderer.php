<?php
/**
 * Renders the per-date child rows of an expanded event on the All Events list.
 *
 * @package NetterTechEvents\Admin\ListTables
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\ListTables;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;
use NetterTechEvents\Utilities\PathHelper;

/**
 * Builds one table row per occurrence of an expanded event.
 *
 * Both delivery paths render through this class — the server-side `expanded`
 * URL argument and the admin-ajax toggle — so the two cannot emit different
 * markup for the same date.
 *
 * @since 1.4.8
 */
final class OccurrenceRowRenderer {

	/**
	 * Column key => header label, already filtered.
	 *
	 * @var array<string, string>
	 */
	private array $columns;

	/**
	 * Column keys hidden through Screen Options.
	 *
	 * @var array<int, string>
	 */
	private array $hidden;

	/**
	 * The primary column key, rendered as a row header.
	 *
	 * @var string
	 */
	private string $primary;

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $columns Filtered column map from the list table.
	 * @param array<int, string>    $hidden  Column keys hidden through Screen Options.
	 * @param string                $primary Primary column key.
	 */
	public function __construct( array $columns, array $hidden = array(), string $primary = 'title' ) {
		$this->columns = $columns;
		$this->hidden  = $hidden;
		$this->primary = $primary;
	}

	/**
	 * Render every child row for one event.
	 *
	 * @param Event                                                                    $event        The parent event.
	 * @param array<int, Occurrence>                                                   $occurrences  Occurrences in start order.
	 * @param array<int, int>                                                          $sold_counts  Map of occurrence_id => confirmed guests.
	 * @param array<int, array{capacity: ?int, has_unlimited: bool, configured: bool}> $capacity_map Map of occurrence_id => house.
	 * @return string
	 */
	public function render( Event $event, array $occurrences, array $sold_counts, array $capacity_map ): string {
		$rows  = '';
		$first = true;

		foreach ( $occurrences as $occurrence ) {
			$rows .= $this->render_row( $event, $occurrence, $sold_counts, $capacity_map, $first, count( $occurrences ) );
			$first = false;
		}

		return $rows;
	}

	/**
	 * The DOM id carried by a child row, so a toggle can point `aria-controls` at it.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return string
	 */
	public static function row_id( int $occurrence_id ): string {
		return 'nte-occ-' . $occurrence_id;
	}

	/**
	 * Render one child row.
	 *
	 * @param Event                                                                    $event        The parent event.
	 * @param Occurrence                                                               $occurrence   The occurrence for this row.
	 * @param array<int, int>                                                          $sold_counts  Map of occurrence_id => confirmed guests.
	 * @param array<int, array{capacity: ?int, has_unlimited: bool, configured: bool}> $capacity_map Map of occurrence_id => house.
	 * @param bool                                                                     $is_first     Whether this is the first row of the group.
	 * @param int                                                                      $total        Number of dates in the group.
	 * @return string
	 */
	private function render_row(
		Event $event,
		Occurrence $occurrence,
		array $sold_counts,
		array $capacity_map,
		bool $is_first,
		int $total
	): string {
		$occurrence_id = $occurrence->id ?? 0;
		$event_id      = $event->id ?? 0;

		$row_classes = array( 'nte-occurrence-row' );
		if ( $is_first ) {
			$row_classes[] = 'nte-occurrence-row--first';
		}

		$row = sprintf(
			'<tr id="%s" class="%s" data-event-id="%d" data-occurrence-id="%d">',
			esc_attr( self::row_id( $occurrence_id ) ),
			esc_attr( implode( ' ', $row_classes ) ),
			$event_id,
			$occurrence_id
		);

		foreach ( $this->columns as $column_name => $column_label ) {
			$row .= $this->render_cell( $column_name, $column_label, $event, $occurrence, $sold_counts, $capacity_map, $total );
		}

		return $row . '</tr>';
	}

	/**
	 * Render one cell of a child row.
	 *
	 * @param string                                                                   $column_name  Column key.
	 * @param string                                                                   $column_label Column header label.
	 * @param Event                                                                    $event        The parent event.
	 * @param Occurrence                                                               $occurrence   The occurrence for this row.
	 * @param array<int, int>                                                          $sold_counts  Map of occurrence_id => confirmed guests.
	 * @param array<int, array{capacity: ?int, has_unlimited: bool, configured: bool}> $capacity_map Map of occurrence_id => house.
	 * @param int                                                                      $total        Number of dates in the group.
	 * @return string
	 */
	private function render_cell(
		string $column_name,
		string $column_label,
		Event $event,
		Occurrence $occurrence,
		array $sold_counts,
		array $capacity_map,
		int $total
	): string {
		// The check column carries no checkbox: a date is not a bulk-action target,
		// and an empty cell keeps bulk composition reading only real event rows.
		if ( 'cb' === $column_name ) {
			return '<th scope="row" class="check-column"></th>';
		}

		$occurrence_id = $occurrence->id ?? 0;

		$classes = $column_name . ' column-' . $column_name;
		if ( $this->primary === $column_name ) {
			$classes .= ' has-row-actions column-primary';
		}
		if ( in_array( $column_name, $this->hidden, true ) ) {
			$classes .= ' hidden';
		}

		$is_primary = ( $this->primary === $column_name );
		$tag        = $is_primary ? 'th' : 'td';
		$scope      = $is_primary ? ' scope="row"' : '';

		switch ( $column_name ) {
			case 'title':
				$classes .= ' nte-occurrence-row__date';
				$content  = $this->render_title_cell( $event, $occurrence, $total );
				break;

			case 'tickets_sold':
				$content = EventsListTable::render_sold_cell(
					(int) ( $sold_counts[ $occurrence_id ] ?? 0 ),
					$capacity_map[ $occurrence_id ] ?? null
				);
				break;

			case 'date':
				$content = esc_html( $occurrence->get_start_date() );
				break;

			case 'status':
				$content = $this->render_status_cell( $occurrence );
				break;

			// A date has no next date, no type of its own, and no creation stamp
			// worth repeating from the event: those cells stay empty.
			case 'next_date':
			case 'type':
			case 'created_at':
				$content = '';
				break;

			default:
				$content = $this->render_custom_cell( $column_name, $event, $occurrence );
				break;
		}

		return sprintf(
			'<%1$s class="%2$s" data-colname="%3$s"%4$s>%5$s</%1$s>',
			$tag,
			esc_attr( $classes ),
			esc_attr( wp_strip_all_tags( $column_label ) ),
			$scope,
			$content
		);
	}

	/**
	 * Render the primary cell: the date, the time, and the per-date row actions.
	 *
	 * @param Event      $event      The parent event.
	 * @param Occurrence $occurrence The occurrence for this row.
	 * @param int        $total      Number of dates in the group.
	 * @return string
	 */
	private function render_title_cell( Event $event, Occurrence $occurrence, int $total ): string {
		$occurrence_id = $occurrence->id ?? 0;
		$event_id      = $event->id ?? 0;

		$when = sprintf(
			'<span class="nte-occurrence-row__when">%s</span> <span class="nte-occurrence-row__time">%s</span>',
			esc_html( $occurrence->get_start_date() ),
			esc_html( $occurrence->get_start_time() )
		);

		$edit_url = admin_url(
			'admin.php?page=' . AdminMenu::SUBMENU_EDIT_OCCURRENCE . '&occurrence_id=' . $occurrence_id
		);

		$purchases_url = admin_url(
			'admin.php?page=' . AdminMenu::SUBMENU_ATTENDEES . '&event_id=' . $event_id . '&occurrence_id=' . $occurrence_id
		);

		$actions = array(
			'edit'      => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit date', 'nettertech-events' )
			),
			'purchases' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $purchases_url ),
				esc_html__( 'Purchases', 'nettertech-events' )
			),
		);

		if ( $event->is_published() ) {
			$actions['view'] = sprintf(
				'<a href="%s" target="_blank">%s</a>',
				esc_url( $this->occurrence_url( $event, $occurrence, $total ) ),
				esc_html__( 'View', 'nettertech-events' )
			);
		}

		return $when . $this->row_actions( $actions );
	}

	/**
	 * The public URL for one date.
	 *
	 * Mirrors the router's predicate: an event is served per-date when it carries
	 * more than one date, whatever its stored event_type. The count is already in
	 * hand here, so this resolves without the extra query Occurrence::get_url()
	 * would issue — and without depending on the occurrence carrying an event.
	 *
	 * @param Event      $event      The parent event.
	 * @param Occurrence $occurrence The occurrence for this row.
	 * @param int        $total      Number of dates in the group.
	 * @return string
	 */
	private function occurrence_url( Event $event, Occurrence $occurrence, int $total ): string {
		if ( $total <= 1 ) {
			return $event->get_permalink();
		}

		return PathHelper::get_occurrence_url( $event->slug, $occurrence->get_start()->format( 'Y-m-d-Hi' ) );
	}

	/**
	 * Render the occurrence's own status, not the event's.
	 *
	 * @param Occurrence $occurrence The occurrence for this row.
	 * @return string
	 */
	private function render_status_cell( Occurrence $occurrence ): string {
		$statuses = array(
			'scheduled'   => array(
				'label' => __( 'Scheduled', 'nettertech-events' ),
				'color' => '#00a32a',
			),
			'cancelled'   => array(
				'label' => __( 'Cancelled', 'nettertech-events' ),
				'color' => '#d63638',
			),
			'postponed'   => array(
				'label' => __( 'Postponed', 'nettertech-events' ),
				'color' => '#dba617',
			),
			'completed'   => array(
				'label' => __( 'Completed', 'nettertech-events' ),
				'color' => '#646970',
			),
			'rescheduled' => array(
				'label' => __( 'Rescheduled', 'nettertech-events' ),
				'color' => '#2271b1',
			),
		);

		$status = $statuses[ $occurrence->status ] ?? array(
			'label' => $occurrence->status,
			'color' => '#646970',
		);

		return sprintf(
			'<span style="color: %s;">%s</span>',
			esc_attr( $status['color'] ),
			esc_html( $status['label'] )
		);
	}

	/**
	 * Render a column registered by an add-on.
	 *
	 * @param string     $column_name Column key.
	 * @param Event      $event       The parent event.
	 * @param Occurrence $occurrence  The occurrence for this row.
	 * @return string
	 */
	private function render_custom_cell( string $column_name, Event $event, Occurrence $occurrence ): string {
		ob_start();

		/**
		 * Fires when rendering a registered custom column cell on the All Events list.
		 *
		 * @since 1.1.1
		 *
		 * @param string          $column_name The column key being rendered.
		 * @param Event           $item        The event for this row.
		 * @param Occurrence|null $occurrence  The occurrence for a date row, null on an event row.
		 */
		do_action( Hooks::ACTION_LIST_COLUMN, $column_name, $event, $occurrence );

		return (string) ob_get_clean();
	}

	/**
	 * Build the row-actions markup WP core emits under a primary cell.
	 *
	 * @param array<string, string> $actions Map of action key => rendered link.
	 * @return string
	 */
	private function row_actions( array $actions ): string {
		$out  = '<div class="row-actions">';
		$last = array_key_last( $actions );
		foreach ( $actions as $key => $link ) {
			$separator = ( $key === $last ) ? '' : ' | ';
			$out      .= '<span class="' . esc_attr( $key ) . '">' . $link . $separator . '</span>';
		}

		return $out . '</div>';
	}
}
