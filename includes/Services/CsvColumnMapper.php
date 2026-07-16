<?php
/**
 * CSV Column Mapper.
 *
 * Maps CSV column headers to NTE event fields with auto-detection
 * of common header naming conventions.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Maps CSV column names to NTE event field names.
 *
 * Supports auto-detection of common header patterns (e.g., "Event Name" -> "title",
 * "Start Date" -> "start_date") and manual mapping overrides.
 *
 * @since 2.1.0
 * @api
 */
class CsvColumnMapper {

	/**
	 * NTE fields that can be mapped to.
	 *
	 * @var array<string, string> Field name => human-readable label.
	 */
	public const FIELDS = array(
		'title'           => 'Event Title',
		'description'     => 'Description',
		'start_date'      => 'Start Date',
		'start_time'      => 'Start Time',
		'end_date'        => 'End Date',
		'end_time'        => 'End Time',
		'all_day'         => 'All Day',
		'venue_name'      => 'Venue Name',
		'venue_address'   => 'Venue Address',
		'organizer_name'  => 'Organizer Name',
		'organizer_email' => 'Organizer Email',
		'category'        => 'Category',
		'tags'            => 'Tags',
		'recurrence_rule' => 'Recurrence Rule (RRULE)',
		'status'          => 'Status',
		'image_url'       => 'Image URL',
	);

	/**
	 * Required fields for a valid import.
	 *
	 * @var array<string>
	 */
	public const REQUIRED_FIELDS = array( 'title', 'start_date' );

	/**
	 * Common CSV header aliases mapped to NTE field names.
	 *
	 * Keys are normalized (lowercase, underscored) header strings.
	 *
	 * @var array<string, string>
	 */
	private const ALIASES = array(
		// Title.
		'title'             => 'title',
		'event_title'       => 'title',
		'event_name'        => 'title',
		'name'              => 'title',
		'subject'           => 'title',
		'summary'           => 'title',

		// Description.
		'description'       => 'description',
		'event_description' => 'description',
		'details'           => 'description',
		'body'              => 'description',
		'notes'             => 'description',

		// Dates and times.
		'start_date'        => 'start_date',
		'event_start_date'  => 'start_date',
		'start'             => 'start_date',
		'date'              => 'start_date',
		'event_date'        => 'start_date',
		'begin_date'        => 'start_date',

		'start_time'        => 'start_time',
		'event_start_time'  => 'start_time',
		'begin_time'        => 'start_time',
		'time'              => 'start_time',

		'end_date'          => 'end_date',
		'event_end_date'    => 'end_date',
		'finish_date'       => 'end_date',

		'end_time'          => 'end_time',
		'event_end_time'    => 'end_time',
		'finish_time'       => 'end_time',

		'all_day'           => 'all_day',
		'allday'            => 'all_day',
		'all_day_event'     => 'all_day',

		// Venue / location.
		'venue'             => 'venue_name',
		'venue_name'        => 'venue_name',
		'location'          => 'venue_name',
		'location_name'     => 'venue_name',
		'place'             => 'venue_name',

		'venue_address'     => 'venue_address',
		'address'           => 'venue_address',
		'location_address'  => 'venue_address',

		// Organizer.
		'organizer'         => 'organizer_name',
		'organizer_name'    => 'organizer_name',
		'host'              => 'organizer_name',
		'presenter'         => 'organizer_name',

		'organizer_email'   => 'organizer_email',

		// Taxonomy.
		'category'          => 'category',
		'categories'        => 'category',
		'event_category'    => 'category',
		'type'              => 'category',

		'tags'              => 'tags',
		'tag'               => 'tags',
		'keywords'          => 'tags',

		// Recurrence.
		'recurrence_rule'   => 'recurrence_rule',
		'rrule'             => 'recurrence_rule',
		'recurrence'        => 'recurrence_rule',
		'repeat'            => 'recurrence_rule',

		// Status.
		'status'            => 'status',
		'event_status'      => 'status',

		// Image.
		'image_url'         => 'image_url',
		'image'             => 'image_url',
		'featured_image'    => 'image_url',
		'photo'             => 'image_url',
		'photo_url'         => 'image_url',
	);

	/**
	 * Auto-detect field mappings from CSV headers.
	 *
	 * Returns a mapping of CSV header => NTE field name for all recognized headers.
	 * Unrecognized headers are mapped to empty string (unmapped).
	 *
	 * @param array<string> $headers Normalized CSV headers.
	 * @return array<string, string> CSV header => NTE field name (or '' if unmapped).
	 */
	public function auto_detect( array $headers ): array {
		$mapping = array();

		foreach ( $headers as $header ) {
			$mapping[ $header ] = self::ALIASES[ $header ] ?? '';
		}

		return $mapping;
	}

	/**
	 * Apply a mapping to a row of CSV data.
	 *
	 * @param array<string, string> $row     Associative row (CSV header => value).
	 * @param array<string, string> $mapping CSV header => NTE field name.
	 * @return array<string, string> NTE field name => value (only mapped fields).
	 */
	public function apply( array $row, array $mapping ): array {
		$mapped = array();

		foreach ( $mapping as $csv_header => $field ) {
			if ( '' === $field || ! isset( $row[ $csv_header ] ) ) {
				continue;
			}
			$mapped[ $field ] = trim( $row[ $csv_header ] );
		}

		return $mapped;
	}

	/**
	 * Merge a manual mapping override with auto-detected mapping.
	 *
	 * Manual overrides take precedence over auto-detection.
	 *
	 * @param array<string, string> $auto_mapping   Auto-detected mapping.
	 * @param array<string, string> $manual_mapping Manual overrides (CSV header => NTE field).
	 * @return array<string, string> Merged mapping.
	 */
	public function merge_override( array $auto_mapping, array $manual_mapping ): array {
		foreach ( $manual_mapping as $csv_header => $field ) {
			if ( isset( $auto_mapping[ $csv_header ] ) ) {
				$auto_mapping[ $csv_header ] = $field;
			}
		}

		return $auto_mapping;
	}

	/**
	 * Check if a mapping covers all required fields.
	 *
	 * @param array<string, string> $mapping CSV header => NTE field name.
	 * @return array<string> List of missing required field names.
	 */
	public function missing_required( array $mapping ): array {
		$mapped_fields = array_values( array_filter( $mapping ) );
		$missing       = array();

		foreach ( self::REQUIRED_FIELDS as $field ) {
			if ( ! in_array( $field, $mapped_fields, true ) ) {
				$missing[] = $field;
			}
		}

		return $missing;
	}
}
