<?php
/**
 * Export Service Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for ExportService implementations.
 *
 * Provides CSV generation and download for check-in data exports.
 *
 * @since 2.0.0
 * @api
 */
interface ExportServiceInterface {

	/**
	 * Generate CSV data for check-in list.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return string CSV content.
	 */
	public function generate_csv( int $occurrence_id ): string;

	/**
	 * Generate a standardized CSV filename.
	 *
	 * @param string $event_title Event title.
	 * @return string Sanitized filename.
	 */
	public function get_csv_filename( string $event_title ): string;

	/**
	 * Send CSV as a download response.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $event_title   Event title for filename.
	 * @return void
	 */
	public function send_csv_download( int $occurrence_id, string $event_title ): void;

	/**
	 * Get occurrence with event title for export.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array{occurrence: \NetterTechEvents\Models\Occurrence|null, event_title: string}
	 */
	public function get_export_context( int $occurrence_id ): array;

	/**
	 * Get check-in statistics for export header.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<string, int>
	 */
	public function get_export_stats( int $occurrence_id ): array;
}
