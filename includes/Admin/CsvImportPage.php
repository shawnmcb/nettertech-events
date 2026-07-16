<?php
/**
 * CSV Import admin page.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\CsvImport\Presenters\CsvImportUploadPresenter;
use NetterTechEvents\Services\CsvColumnMapper;
use NetterTechEvents\Services\CsvImporter;
use NetterTechEvents\Services\CsvParser;

/**
 * Admin page for importing events from CSV files.
 *
 * Provides a multi-step workflow: upload -> map columns -> preview -> import.
 *
 * @since 2.1.0
 */
class CsvImportPage {

	/**
	 * Nonce action name.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'nettertech_events_csv_import';

	/**
	 * Transient key prefix for storing upload state between steps.
	 *
	 * @var string
	 */
	private const TRANSIENT_PREFIX = 'nettertech_events_csv_import_';

	/**
	 * CSV importer service.
	 *
	 * @var CsvImporter
	 */
	private CsvImporter $importer;

	/**
	 * CSV parser.
	 *
	 * @var CsvParser
	 */
	private CsvParser $parser;

	/**
	 * Column mapper.
	 *
	 * @var CsvColumnMapper
	 */
	private CsvColumnMapper $mapper;

	/**
	 * Constructor.
	 *
	 * @param CsvImporter     $importer CSV importer service.
	 * @param CsvParser       $parser   CSV parser.
	 * @param CsvColumnMapper $mapper   Column mapper.
	 */
	public function __construct( CsvImporter $importer, CsvParser $parser, CsvColumnMapper $mapper ) {
		$this->importer = $importer;
		$this->parser   = $parser;
		$this->mapper   = $mapper;
	}

	/**
	 * Render the import page, dispatching to the correct step.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nettertech-events' ) );
		}

		$step = $this->get_current_step();

		?>
		<div class="wrap">
			<?php Branding::render_header(); ?>
			<h1><?php esc_html_e( 'Import Events from CSV', 'nettertech-events' ); ?></h1>
			<?php
			switch ( $step ) {
				case 'map':
					$this->render_mapping_step();
					break;
				case 'preview':
					$this->render_preview_step();
					break;
				case 'import':
					$this->render_import_step();
					break;
				default:
					$this->render_upload_step();
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Determine which step we're on from the request.
	 *
	 * All multi-step transitions submit via POST with the import nonce. We
	 * verify the nonce inline at the routing boundary so subsequent
	 * per-step renders can read other $_POST fields freely; each per-step
	 * render also calls `check_admin_referer()` as defense-in-depth.
	 * Absent or invalid nonce falls back to the upload step.
	 *
	 * @return string Step identifier.
	 */
	private function get_current_step(): string {
		if ( empty( $_POST['nettertech_events_csv_step'] ) ) {
			return 'upload';
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return 'upload';
		}

		return sanitize_key( wp_unslash( $_POST['nettertech_events_csv_step'] ) );
	}

	/**
	 * Step 1: File upload form.
	 *
	 * @return void
	 */
	private function render_upload_step(): void {
		$presenter = new CsvImportUploadPresenter( self::NONCE_ACTION );
		include dirname( __DIR__, 2 ) . '/templates/admin/csv-import/upload-step.php';
	}

	/**
	 * Step 2: Column mapping with preview.
	 *
	 * @return void
	 */
	private function render_mapping_step(): void {
		check_admin_referer( self::NONCE_ACTION );

		// Handle file upload. Each $_FILES sub-field is read individually with
		// the appropriate sanitization so PHPCS can see the input boundary;
		// tmp_name/error/size are PHP-controlled, name/type carry user input.
		if ( ! isset( $_FILES['nettertech_events_csv_file']['tmp_name'] ) || '' === $_FILES['nettertech_events_csv_file']['tmp_name'] ) {
			$this->render_error( __( 'No file was uploaded.', 'nettertech-events' ) );
			return;
		}

		$file = array(
			'name'     => isset( $_FILES['nettertech_events_csv_file']['name'] )
				? sanitize_file_name( wp_unslash( $_FILES['nettertech_events_csv_file']['name'] ) )
				: '',
			'type'     => isset( $_FILES['nettertech_events_csv_file']['type'] )
				? sanitize_text_field( wp_unslash( $_FILES['nettertech_events_csv_file']['type'] ) )
				: '',
			'tmp_name' => sanitize_text_field( $_FILES['nettertech_events_csv_file']['tmp_name'] ),
			'error'    => isset( $_FILES['nettertech_events_csv_file']['error'] )
				? (int) $_FILES['nettertech_events_csv_file']['error']
				: UPLOAD_ERR_NO_FILE,
			'size'     => isset( $_FILES['nettertech_events_csv_file']['size'] )
				? (int) $_FILES['nettertech_events_csv_file']['size']
				: 0,
		);

		// Validate file size (2MB).
		if ( $file['size'] > 2 * MB_IN_BYTES ) {
			$this->render_error( __( 'File exceeds 2MB limit.', 'nettertech-events' ) );
			return;
		}

		// Move to a stable temp location via WP upload API.
		$upload_overrides = array(
			'test_form' => false,
			'test_type' => false, // CSV MIME types vary across systems.
		);
		$moved            = wp_handle_upload( $file, $upload_overrides );

		if ( isset( $moved['error'] ) ) {
			$this->render_error( $moved['error'] );
			return;
		}

		$temp_path = $moved['file'];

		try {
			$preview = $this->parser->preview( $temp_path, 5 );
		} catch ( \Exception $e ) {
			wp_delete_file( $temp_path );
			$this->render_error( $e->getMessage() );
			return;
		}

		$auto_mapping = $this->mapper->auto_detect( $preview['headers'] );
		$missing      = $this->mapper->missing_required( $auto_mapping );

		// Store file path in a transient keyed by a token.
		$token = wp_generate_uuid4();
		set_transient( self::TRANSIENT_PREFIX . $token, $temp_path, HOUR_IN_SECONDS );

		?>
		<h2><?php esc_html_e( 'Step 2: Map Columns', 'nettertech-events' ); ?></h2>

		<p class="description">
			<?php
			printf(
				/* translators: %d: total row count. */
				esc_html__( 'Found %d data rows. Map your CSV columns to event fields below.', 'nettertech-events' ),
				esc_html( (string) $preview['row_count'] )
			);
			?>
		</p>

		<?php if ( ! empty( $missing ) ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %s: list of missing fields. */
						esc_html__( 'Required fields not auto-detected: %s. Please map them manually.', 'nettertech-events' ),
						'<strong>' . esc_html( implode( ', ', $missing ) ) . '</strong>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="nettertech_events_csv_step" value="preview" />
			<input type="hidden" name="nettertech_events_csv_token" value="<?php echo esc_attr( $token ); ?>" />

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'CSV Column', 'nettertech-events' ); ?></th>
						<th><?php esc_html_e( 'Maps To', 'nettertech-events' ); ?></th>
						<th><?php esc_html_e( 'Sample Values', 'nettertech-events' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $preview['headers'] as $header ) : ?>
						<tr>
							<td><code><?php echo esc_html( $header ); ?></code></td>
							<td>
								<select name="mapping[<?php echo esc_attr( $header ); ?>]">
									<option value=""><?php esc_html_e( '-- Skip --', 'nettertech-events' ); ?></option>
									<?php foreach ( CsvColumnMapper::FIELDS as $field => $label ) : ?>
										<option value="<?php echo esc_attr( $field ); ?>"
											<?php selected( $auto_mapping[ $header ] ?? '', $field ); ?>>
											<?php echo esc_html( $label ); ?>
											<?php echo in_array( $field, CsvColumnMapper::REQUIRED_FIELDS, true ) ? ' *' : ''; ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
							<td class="nte-csv-samples">
								<?php
								$samples = array_slice(
									array_column( $preview['rows'], $header ),
									0,
									3
								);
								echo esc_html( implode( ' | ', array_filter( $samples ) ) );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Import Options', 'nettertech-events' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Status', 'nettertech-events' ); ?></th>
					<td>
						<select name="import_status">
							<option value="draft"><?php esc_html_e( 'Draft', 'nettertech-events' ); ?></option>
							<option value="published"><?php esc_html_e( 'Published', 'nettertech-events' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Duplicate Handling', 'nettertech-events' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="skip_duplicates" value="1" checked />
							<?php esc_html_e( 'Skip events with matching title and start date', 'nettertech-events' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Preview Import', 'nettertech-events' ), 'primary', 'submit', true ); ?>
		</form>
		<?php
	}

	/**
	 * Step 3: Dry-run preview with validation results.
	 *
	 * @return void
	 */
	private function render_preview_step(): void {
		check_admin_referer( self::NONCE_ACTION );

		$token     = sanitize_text_field( wp_unslash( $_POST['nettertech_events_csv_token'] ?? '' ) );
		$file_path = get_transient( self::TRANSIENT_PREFIX . $token );

		if ( ! $file_path || ! is_readable( $file_path ) ) {
			$this->render_error( __( 'Upload session expired. Please upload the file again.', 'nettertech-events' ) );
			return;
		}

		if ( isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ) {
			$mapping = array_map( 'sanitize_text_field', wp_unslash( $_POST['mapping'] ) );
		} else {
			$mapping = array();
		}

		$missing = $this->mapper->missing_required( $mapping );
		if ( ! empty( $missing ) ) {
			$this->render_error(
				sprintf(
					/* translators: %s: list of missing fields. */
					__( 'Required fields are not mapped: %s', 'nettertech-events' ),
					implode( ', ', $missing )
				)
			);
			return;
		}

		$import_status   = sanitize_text_field( wp_unslash( $_POST['import_status'] ?? 'draft' ) );
		$skip_duplicates = ! empty( $_POST['skip_duplicates'] );

		$dry_run = null;
		try {
			$dry_run = $this->importer->dry_run( $file_path, $mapping );
		} catch ( \Exception $e ) {
			$this->render_error( $e->getMessage() );
			return;
		} finally {
			// Temp file is kept intentionally for the import step; only clean up on error.
			if ( null === $dry_run ) {
				wp_delete_file( $file_path );
				delete_transient( self::TRANSIENT_PREFIX . $token );
			}
		}

		?>
		<h2><?php esc_html_e( 'Step 3: Preview Results', 'nettertech-events' ); ?></h2>

		<div class="notice notice-info inline">
			<p>
				<?php
				echo wp_kses_data(
					sprintf(
						/* translators: 1: valid count, 2: error count. */
						esc_html__( '%1$d events ready to import. %2$d rows with errors (will be skipped).', 'nettertech-events' ),
						$dry_run['valid_count'],
						$dry_run['error_count']
					)
				);
				?>
			</p>
		</div>

		<?php if ( ! empty( $dry_run['errors'] ) ) : ?>
			<h3><?php esc_html_e( 'Validation Errors', 'nettertech-events' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Row', 'nettertech-events' ); ?></th>
						<th><?php esc_html_e( 'Errors', 'nettertech-events' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $dry_run['errors'] as $row_num => $row_errors ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row_num ); ?></td>
							<td><?php echo esc_html( implode( '; ', $row_errors ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $dry_run['valid_count'] > 0 ) : ?>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="nettertech_events_csv_step" value="import" />
				<input type="hidden" name="nettertech_events_csv_token" value="<?php echo esc_attr( $token ); ?>" />
				<input type="hidden" name="import_status" value="<?php echo esc_attr( $import_status ); ?>" />
				<input type="hidden" name="skip_duplicates" value="<?php echo $skip_duplicates ? '1' : '0'; ?>" />
				<?php
				// Pass mapping as hidden fields.
				foreach ( $mapping as $csv_header => $field ) :
					?>
					<input type="hidden" name="mapping[<?php echo esc_attr( $csv_header ); ?>]" value="<?php echo esc_attr( $field ); ?>" />
				<?php endforeach; ?>

				<?php
				submit_button(
					sprintf(
						/* translators: %d: number of events to import. */
						__( 'Import %d Events', 'nettertech-events' ),
						$dry_run['valid_count']
					),
					'primary',
					'submit',
					true
				);
				?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Step 4: Execute the import and show results.
	 *
	 * @return void
	 */
	private function render_import_step(): void {
		check_admin_referer( self::NONCE_ACTION );

		$token     = sanitize_text_field( wp_unslash( $_POST['nettertech_events_csv_token'] ?? '' ) );
		$file_path = get_transient( self::TRANSIENT_PREFIX . $token );

		if ( ! $file_path || ! is_readable( $file_path ) ) {
			$this->render_error( __( 'Upload session expired. Please upload the file again.', 'nettertech-events' ) );
			return;
		}

		if ( isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ) {
			$mapping = array_map( 'sanitize_text_field', wp_unslash( $_POST['mapping'] ) );
		} else {
			$mapping = array();
		}

		$options = array(
			'status'          => sanitize_text_field( wp_unslash( $_POST['import_status'] ?? 'draft' ) ),
			'skip_duplicates' => ! empty( $_POST['skip_duplicates'] ),
		);

		try {
			$results = $this->importer->import( $file_path, $mapping, $options );
		} catch ( \Exception $e ) {
			$this->render_error( $e->getMessage() );
			return;
		} finally {
			// Always clean up temp file and transient, success or failure.
			wp_delete_file( $file_path );
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}

		?>
		<h2><?php esc_html_e( 'Import Complete', 'nettertech-events' ); ?></h2>

		<div class="notice notice-success inline">
			<p>
				<?php
				echo wp_kses_data(
					sprintf(
						/* translators: 1: imported count, 2: skipped count, 3: error count. */
						esc_html__( 'Imported: %1$d | Skipped (duplicates): %2$d | Errors: %3$d', 'nettertech-events' ),
						$results['imported'],
						$results['skipped'],
						count( $results['errors'] )
					)
				);
				?>
			</p>
		</div>

		<?php if ( ! empty( $results['errors'] ) ) : ?>
			<h3><?php esc_html_e( 'Errors', 'nettertech-events' ); ?></h3>
			<ul class="ul-disc">
				<?php foreach ( $results['errors'] as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nettertech-events' ) ); ?>" class="button">
				<?php esc_html_e( 'View Events', 'nettertech-events' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nettertech-events-csv-import' ) ); ?>" class="button">
				<?php esc_html_e( 'Import More', 'nettertech-events' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Render an error message with a back link.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function render_error( string $message ): void {
		?>
		<div class="notice notice-error inline">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nettertech-events-csv-import' ) ); ?>" class="button">
				<?php esc_html_e( 'Start Over', 'nettertech-events' ); ?>
			</a>
		</p>
		<?php
	}
}
