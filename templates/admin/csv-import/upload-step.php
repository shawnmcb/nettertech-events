<?php
/**
 * Admin template: CSV import — Upload step (Step 1).
 *
 * Rendered by `CsvImportPage::render_upload_step()` after building a
 * `CsvImportUploadPresenter` and including this file. Template MUST NOT call
 * repositories, $wpdb, $_GET/$_POST, or business logic — those are in the
 * presenter. Template MUST call esc_*() on every dynamic output.
 *
 * @package NetterTechEvents\Admin
 *
 * @var \NetterTechEvents\Admin\CsvImport\Presenters\CsvImportUploadPresenter $presenter
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

?>
<p class="description">
	<?php echo esc_html( $presenter->intro_text() ); ?>
</p>

<form method="post" enctype="multipart/form-data">
	<?php wp_nonce_field( $presenter->nonce_action() ); ?>
	<input type="hidden" name="nettertech_events_csv_step" value="map" />

	<table class="form-table">
		<tr>
			<th scope="row">
				<label for="nettertech_events_csv_file"><?php echo esc_html( $presenter->file_label() ); ?></label>
			</th>
			<td>
				<input type="file" id="nettertech_events_csv_file" name="nettertech_events_csv_file"
					accept="<?php echo esc_attr( $presenter->accepted_mime_csv() ); ?>" required />
				<p class="description">
					<?php echo esc_html( $presenter->file_description() ); ?>
				</p>
			</td>
		</tr>
	</table>

	<?php submit_button( $presenter->submit_label(), 'primary', 'submit', true ); ?>
</form>
