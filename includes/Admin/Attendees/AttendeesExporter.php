<?php
/**
 * Attendees CSV Exporter.
 *
 * @package NetterTechEvents\Admin\Attendees
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Attendees;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\AttendeesPage;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Database\Schema;
use NetterTechEvents\Models\AttendeeField;
use NetterTechEvents\Services\ExportService;

/**
 * Handles CSV export of attendee data.
 *
 * Extracted from AttendeesPage to reduce class complexity.
 * Supports both selected attendees export and filtered export-all.
 *
 * @since 1.1.0
 */
class AttendeesExporter {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Field repository.
	 *
	 * @var AttendeeFieldRepositoryInterface|null
	 */
	private ?AttendeeFieldRepositoryInterface $field_repo;

	/**
	 * Field value repository.
	 *
	 * @var AttendeeFieldValueRepositoryInterface|null
	 */
	private ?AttendeeFieldValueRepositoryInterface $value_repo;

	/**
	 * Constructor.
	 *
	 * @param \wpdb                                      $db         Database instance.
	 * @param AttendeeFieldRepositoryInterface|null      $field_repo Field repository.
	 * @param AttendeeFieldValueRepositoryInterface|null $value_repo Value repository.
	 */
	public function __construct(
		\wpdb $db,
		?AttendeeFieldRepositoryInterface $field_repo = null,
		?AttendeeFieldValueRepositoryInterface $value_repo = null
	) {
		$this->db         = $db;
		$this->field_repo = $field_repo;
		$this->value_repo = $value_repo;
	}

	/**
	 * CSV column headers.
	 *
	 * @var array<string>
	 */
	private const CSV_HEADERS = array(
		'ID',
		'Name',
		'Email',
		'Event',
		'Date/Time',
		'Quantity',
		'Status',
		'Checked In',
		'Notes',
	);

	/**
	 * Export selected attendees to CSV.
	 *
	 * @param array<int> $attendee_ids Attendee IDs to export.
	 * @param string     $orderby      Sort key selected on the list (validated against whitelist).
	 * @param string     $order        Sort direction selected on the list.
	 * @return void
	 */
	public function export_selected( array $attendee_ids, string $orderby = '', string $order = '' ): void {
		if ( empty( $attendee_ids ) ) {
			return;
		}

		$attendees_table    = Schema::table( 'attendees' );
		$occurrences_table  = Schema::table( 'occurrences' );
		$events_table       = Schema::table( 'events' );
		$ticket_types_table = Schema::table( 'ticket_types' );

		$count        = count( $attendee_ids );
		$placeholders = implode( ', ', array_fill( 0, $count, '%d' ) );
		// Whitelisted expression from AttendeesPage::SORTABLE_COLUMNS, so the
		// CSV comes out in the order the operator sees on the list (NTE-195).
		$order_clause = AttendeesPage::build_order_clause( $orderby, $order );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Export query.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from Schema class are safe.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholders for IN clause.
		$items = $this->db->get_results(
			$this->db->prepare(
				"SELECT a.*, o.start_datetime, o.end_datetime, e.title as event_title
				FROM {$attendees_table} a
				LEFT JOIN {$occurrences_table} o ON a.occurrence_id = o.id
				LEFT JOIN {$events_table} e ON o.event_id = e.id
				LEFT JOIN {$ticket_types_table} tt ON a.ticket_type_id = tt.id
				WHERE a.id IN ({$placeholders})
				ORDER BY {$order_clause}",
				$attendee_ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->output_csv( 'attendees-export-' . gmdate( 'Y-m-d-His' ) . '.csv', $items ?? array() );
	}

	/**
	 * Export all filtered attendees to CSV.
	 *
	 * @param int    $occurrence_id      Filter by occurrence ID.
	 * @param int    $event_id           Filter by event ID (the event-scoped Purchases view, NTE-118).
	 * @param string $search             Search query.
	 * @param string $status_filter      Status filter.
	 * @param string $placeholder_filter Placeholder data filter.
	 * @param string $orderby            Sort key selected on the list (validated against whitelist).
	 * @param string $order              Sort direction selected on the list.
	 * @return void
	 */
	public function export_all_filtered(
		int $occurrence_id = 0,
		int $event_id = 0,
		string $search = '',
		string $status_filter = '',
		string $placeholder_filter = '',
		string $orderby = '',
		string $order = ''
	): void {
		$attendees_table    = Schema::table( 'attendees' );
		$occurrences_table  = Schema::table( 'occurrences' );
		$events_table       = Schema::table( 'events' );
		$ticket_types_table = Schema::table( 'ticket_types' );

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

		if ( 'yes' === $placeholder_filter ) {
			$where[] = "(a.name REGEXP '^Attendee [0-9]+$' OR e.title = 'Imported Attendees - Unknown Event' OR DATE(o.start_datetime) = '2099-12-31')";
		} elseif ( 'no' === $placeholder_filter ) {
			$where[] = "a.name NOT REGEXP '^Attendee [0-9]+$'";
			$where[] = "(e.title IS NULL OR e.title != 'Imported Attendees - Unknown Event')";
			$where[] = "(o.start_datetime IS NULL OR DATE(o.start_datetime) != '2099-12-31')";
		}

		$where_clause = implode( ' AND ', $where );
		// Whitelisted expression from AttendeesPage::SORTABLE_COLUMNS, so the
		// CSV comes out in the order the operator sees on the list (NTE-195).
		$order_clause = AttendeesPage::build_order_clause( $orderby, $order );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Export query.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table names from Schema class are safe.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Conditional prepare.
		$sql = "SELECT a.*, o.start_datetime, o.end_datetime, e.title as event_title
			FROM {$attendees_table} a
			LEFT JOIN {$occurrences_table} o ON a.occurrence_id = o.id
			LEFT JOIN {$events_table} e ON o.event_id = e.id
			LEFT JOIN {$ticket_types_table} tt ON a.ticket_type_id = tt.id
			WHERE {$where_clause}
			ORDER BY {$order_clause}";

		if ( ! empty( $params ) ) {
			$sql = $this->db->prepare( $sql, $params );
		}

		$items = $this->db->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->output_csv( 'attendees-export-all-' . gmdate( 'Y-m-d-His' ) . '.csv', $items ?? array() );
	}

	/**
	 * Output CSV file to browser.
	 *
	 * @param string                      $filename Output filename.
	 * @param array<array<string, mixed>> $items    Attendee records.
	 * @return void
	 */
	protected function output_csv( string $filename, array $items ): void {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV output stream.
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		// Collect custom field definitions from the exported items.
		$custom_fields = $this->get_custom_field_columns( $items );
		$custom_labels = array_map( fn( AttendeeField $field ) => $field->label, $custom_fields );
		$headers       = array_merge( self::CSV_HEADERS, $custom_labels );

		// Pre-load custom field values for all attendees in a single pass.
		$field_values_map = $this->preload_field_values( $items );

		// Write header row.
		fputcsv( $output, $this->sanitize_row( $headers ), ',', '"', '\\' );

		// Write data rows.
		foreach ( $items as $item ) {
			$row = $this->format_row( $item );

			// Append custom field values.
			$attendee_id = (int) ( $item['id'] ?? 0 );
			foreach ( $custom_fields as $field ) {
				$row[] = $field_values_map[ $attendee_id ][ $field->id ] ?? '';
			}

			fputcsv( $output, $this->sanitize_row( $row ), ',', '"', '\\' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV output stream.
		fclose( $output );
		exit;
	}

	/**
	 * Neutralize spreadsheet formula injection across every cell in a row.
	 *
	 * Attendee names, emails and custom-field answers originate from the public
	 * RSVP/checkout forms, where `sanitize_text_field()` preserves a leading
	 * `=`, `+`, `-` or `@`. Excel and LibreOffice evaluate such a cell as a
	 * formula when the admin opens the export, so each value is escaped here.
	 *
	 * @param array<int, scalar|null> $row Raw cell values.
	 * @return array<int, string> Cells safe to hand to fputcsv().
	 */
	private function sanitize_row( array $row ): array {
		return array_map(
			static fn( $value ) => ExportService::sanitize_csv_value( (string) $value ),
			$row
		);
	}

	/**
	 * Get custom field definitions for the exported attendees' events.
	 *
	 * Returns a deduplicated, ordered list of fields across all events.
	 *
	 * @param array<array<string, mixed>> $items Attendee records (must have occurrence data joined).
	 * @return array<AttendeeField>
	 */
	private function get_custom_field_columns( array $items ): array {
		if ( null === $this->field_repo ) {
			return array();
		}

		// Collect unique event IDs from the occurrence join.
		$event_ids = array();
		$occ_table = Schema::table( 'occurrences' );

		foreach ( $items as $item ) {
			$occ_id = (int) ( $item['occurrence_id'] ?? 0 );
			if ( $occ_id && ! isset( $event_ids[ $occ_id ] ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema::table().
				$event_id = $this->db->get_var(
					$this->db->prepare(
						"SELECT event_id FROM {$occ_table} WHERE id = %d",
						$occ_id
					)
				);
				if ( $event_id ) {
					$event_ids[ $occ_id ] = (int) $event_id;
				}
			}
		}

		$unique_event_ids = array_unique( array_values( $event_ids ) );
		$all_fields       = array();
		$seen_keys        = array();

		foreach ( $unique_event_ids as $eid ) {
			foreach ( $this->field_repo->for_event( $eid ) as $field ) {
				if ( ! isset( $seen_keys[ $field->field_key ] ) ) {
					$all_fields[]                   = $field;
					$seen_keys[ $field->field_key ] = true;
				}
			}
		}

		return $all_fields;
	}

	/**
	 * Pre-load all custom field values for the exported attendees.
	 *
	 * @param array<array<string, mixed>> $items Attendee records.
	 * @return array<int, array<int, string>> Map of attendee_id => field_id => value.
	 */
	private function preload_field_values( array $items ): array {
		if ( null === $this->value_repo ) {
			return array();
		}

		$map = array();

		foreach ( $items as $item ) {
			$attendee_id = (int) ( $item['id'] ?? 0 );
			if ( ! $attendee_id ) {
				continue;
			}

			$values              = $this->value_repo->for_attendee( $attendee_id );
			$map[ $attendee_id ] = array();
			foreach ( $values as $value ) {
				$map[ $attendee_id ][ $value->field_id ] = $value->field_value ?? '';
			}
		}

		return $map;
	}

	/**
	 * Format a single attendee record for CSV output.
	 *
	 * @param array<string, mixed> $item Attendee record.
	 * @return array<string> Formatted row values.
	 */
	private function format_row( array $item ): array {
		$datetime = '';
		if ( ! empty( $item['start_datetime'] ) ) {
			$start    = new \DateTime( $item['start_datetime'] );
			$datetime = $start->format( 'Y-m-d H:i' );
		}

		$quantity      = (int) ( $item['quantity'] ?? 1 );
		$checked_count = (int) ( $item['checked_in_count'] ?? 0 );

		if ( $checked_count >= $quantity ) {
			$checked_in = 'Yes';
		} elseif ( $checked_count > 0 ) {
			$checked_in = "{$checked_count}/{$quantity}";
		} else {
			$checked_in = 'No';
		}

		return array(
			$item['id'] ?? '',
			$item['name'] ?? '',
			$item['email'] ?? '',
			$item['event_title'] ?? 'Unknown Event',
			$datetime,
			(string) $quantity,
			ucfirst( $item['status'] ?? 'confirmed' ),
			$checked_in,
			$item['notes'] ?? '',
		);
	}
}
