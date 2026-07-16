<?php
/**
 * CSV Import Upload Step Presenter (T4.2.4 Pages cluster).
 *
 * @package NetterTechEvents\Admin\CsvImport\Presenters
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\CsvImport\Presenters;

defined( 'ABSPATH' ) || exit;

/**
 * Pure data-prep value object for the CSV import upload-step template.
 *
 * The upload step is static apart from the nonce action passed by the caller
 * and translatable copy. Does NOT call `esc_*()` (template's job), does NOT
 * output, does NOT read from $_GET/$_POST/get_option, does NOT call $wpdb.
 *
 * @since 1.x.x
 */
final class CsvImportUploadPresenter {

	/**
	 * Wire the nonce action and accepted-extensions list.
	 *
	 * @param string $nonce_action      The nonce action name to use for the form.
	 * @param string $accepted_mime_csv Comma-separated accept= attribute for the file input.
	 * @param string $max_size_label    Translatable max file-size label (e.g. "2MB").
	 */
	public function __construct(
		private readonly string $nonce_action,
		private readonly string $accepted_mime_csv = '.csv,.tsv,.txt',
		private readonly string $max_size_label = '2MB'
	) {}

	/**
	 * The nonce action passed to wp_nonce_field().
	 *
	 * @return string
	 */
	public function nonce_action(): string {
		return $this->nonce_action;
	}

	/**
	 * The accept= attribute for the file input.
	 *
	 * @return string
	 */
	public function accepted_mime_csv(): string {
		return $this->accepted_mime_csv;
	}

	/**
	 * Intro/description paragraph above the upload form.
	 *
	 * @return string
	 */
	public function intro_text(): string {
		return __(
			'Upload a CSV file with event data. The first row must contain column headers.',
			'nettertech-events'
		);
	}

	/**
	 * "CSV File" label.
	 *
	 * @return string
	 */
	public function file_label(): string {
		return __( 'CSV File', 'nettertech-events' );
	}

	/**
	 * Description text shown beneath the file input. Includes the max-size label.
	 *
	 * @return string
	 */
	public function file_description(): string {
		return sprintf(
			/* translators: %s: maximum file size label (e.g. "2MB"). */
			__( 'Accepted formats: .csv, .tsv, .txt. Maximum size: %s.', 'nettertech-events' ),
			$this->max_size_label
		);
	}

	/**
	 * Submit button label.
	 *
	 * @return string
	 */
	public function submit_label(): string {
		return __( 'Upload and Continue', 'nettertech-events' );
	}
}
