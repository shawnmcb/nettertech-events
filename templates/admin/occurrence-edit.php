<?php
/**
 * Per-occurrence editor form template.
 *
 * Rendered by {@see \NetterTechEvents\Admin\OccurrenceEditor::render()}.
 *
 * @package NetterTechEvents\Templates\Admin
 *
 * @var \NetterTechEvents\Models\Occurrence      $occurrence      Occurrence being edited.
 * @var \NetterTechEvents\Models\Event           $event           Parent event.
 * @var \NetterTechEvents\Admin\OccurrenceEditor $nte_editor      The editor rendering this form.
 * @var string                                   $nte_save_error  Save validation error carried across the redirect, or ''.
 * @var string                                   $nte_save_notice Informational save notice carried across the redirect, or ''.
 */

declare(strict_types=1);

use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\Branding;
use NetterTechEvents\Admin\OccurrenceEditor;

defined( 'ABSPATH' ) || exit;

// Direct (non-inherited) occurrence columns.
$nte_start_date = substr( $occurrence->start_datetime, 0, 10 );
$nte_start_time = substr( $occurrence->start_datetime, 11, 5 );
$nte_end_date   = substr( $occurrence->end_datetime, 0, 10 );
$nte_end_time   = substr( $occurrence->end_datetime, 11, 5 );
$nte_capacity   = null !== $occurrence->capacity ? (string) $occurrence->capacity : '';

// Inherited (series-default) values shown as hints for the override fields.
$nte_inherited_venue_name    = (string) ( $event->venue_name ?? '' );
$nte_inherited_venue_address = (string) ( $event->venue_address ?? '' );
$nte_inherited_virtual_url   = (string) ( $event->virtual_url ?? '' );

// Featured image: override (this occurrence) vs inherited (series).
$nte_override_image_id   = $occurrence->featured_image_id ? (int) $occurrence->featured_image_id : 0;
$nte_inherited_image_id  = $event->featured_image_id ? (int) $event->featured_image_id : 0;
$nte_override_image_url  = $nte_override_image_id ? (string) wp_get_attachment_image_url( $nte_override_image_id, 'medium' ) : '';
$nte_inherited_image_url = $nte_inherited_image_id ? (string) wp_get_attachment_image_url( $nte_inherited_image_id, 'medium' ) : '';

$nte_back_url = admin_url( 'admin.php?page=' . AdminMenu::SUBMENU_EDIT . '&event_id=' . (int) $event->id );
?>
<a class="nte-skip-link screen-reader-text" href="#nte-main-content">
	<?php esc_html_e( 'Skip to main content', 'nettertech-events' ); ?>
</a>
<div id="nte-main-content" class="wrap" tabindex="-1">
	<?php Branding::render_header(); ?>
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Edit Date', 'nettertech-events' ); ?></h1>
	<a href="<?php echo esc_url( $nte_back_url ); ?>" class="page-title-action">
		<?php esc_html_e( '← Back to Event', 'nettertech-events' ); ?>
	</a>
	<hr class="wp-header-end">

	<?php if ( '' !== $nte_save_error ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $nte_save_error ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $nte_save_notice ) : ?>
		<div class="notice notice-info is-dismissible">
			<p><?php echo esc_html( $nte_save_notice ); ?></p>
		</div>
	<?php endif; ?>

	<p class="nte-occurrence-edit__context">
		<?php
		printf(
			/* translators: 1: event title, 2: occurrence date and time */
			esc_html__( 'Editing one date of %1$s — currently %2$s.', 'nettertech-events' ),
			'<strong>' . esc_html( $event->title ) . '</strong>',
			esc_html( $occurrence->get_formatted_datetime() )
		);
		?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="nte-occurrence-editor">
		<input type="hidden" name="action" value="<?php echo esc_attr( OccurrenceEditor::SAVE_ACTION ); ?>">
		<input type="hidden" name="occurrence_id" value="<?php echo esc_attr( (string) $occurrence->id ); ?>">
		<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event->id ); ?>">
		<?php wp_nonce_field( OccurrenceEditor::NONCE_ACTION, OccurrenceEditor::NONCE_FIELD ); ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="nte-occurrence-start-date"><?php esc_html_e( 'Starts', 'nettertech-events' ); ?></label>
					</th>
					<td>
						<input type="date" id="nte-occurrence-start-date" name="start_date" value="<?php echo esc_attr( $nte_start_date ); ?>" required>
						<input type="time" id="nte-occurrence-start-time" name="start_time" value="<?php echo esc_attr( $nte_start_time ); ?>" data-nte-time-combobox>
						<span class="nte-timezone-hint">
							<?php
							$nte_timezone = '' !== (string) $occurrence->timezone ? (string) $occurrence->timezone : wp_timezone_string();
							/* translators: %s: timezone name, e.g. America/Chicago. */
							echo esc_html( sprintf( __( 'Times are in %s', 'nettertech-events' ), $nte_timezone ) );
							?>
						</span>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="nte-occurrence-end-date"><?php esc_html_e( 'Ends', 'nettertech-events' ); ?></label>
					</th>
					<td>
						<input type="date" id="nte-occurrence-end-date" name="end_date" value="<?php echo esc_attr( $nte_end_date ); ?>">
						<input type="time" id="nte-occurrence-end-time" name="end_time" value="<?php echo esc_attr( $nte_end_time ); ?>" data-nte-time-combobox data-nte-duration-from="#nte-occurrence-start-time">
						<p class="description">
							<label>
								<input type="checkbox" name="all_day" value="1" <?php checked( $occurrence->all_day ); ?>>
								<?php esc_html_e( 'All day', 'nettertech-events' ); ?>
							</label>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="nte-occurrence-status"><?php esc_html_e( 'Status', 'nettertech-events' ); ?></label>
					</th>
					<td>
						<select id="nte-occurrence-status" name="status">
							<option value="scheduled" <?php selected( ! $occurrence->is_cancelled() ); ?>><?php esc_html_e( 'Active', 'nettertech-events' ); ?></option>
							<option value="cancelled" <?php selected( $occurrence->is_cancelled() ); ?>><?php esc_html_e( 'Cancelled', 'nettertech-events' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Cancelling keeps this date in the series with a “Cancelled” label and protects it from regeneration.', 'nettertech-events' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="nte-occurrence-capacity"><?php esc_html_e( 'Capacity', 'nettertech-events' ); ?></label>
					</th>
					<td>
						<input type="number" id="nte-occurrence-capacity" name="capacity" value="<?php echo esc_attr( $nte_capacity ); ?>" min="0" step="1" class="small-text">
						<p class="description"><?php esc_html_e( 'Leave empty for unlimited capacity.', 'nettertech-events' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>

		<h2 class="title"><?php esc_html_e( 'Overrides for this date', 'nettertech-events' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Leave a field empty to inherit the series default.', 'nettertech-events' ); ?></p>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="nte-occurrence-description"><?php esc_html_e( 'Description', 'nettertech-events' ); ?></label>
					</th>
					<td>
						<textarea id="nte-occurrence-description" name="description_override" rows="4" class="large-text"><?php echo esc_textarea( (string) $occurrence->description_override ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Overrides the series description for this date only.', 'nettertech-events' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="nte-occurrence-venue-name"><?php esc_html_e( 'Venue name', 'nettertech-events' ); ?></label>
					</th>
					<td>
						<input type="text" id="nte-occurrence-venue-name" name="venue_name_override" value="<?php echo esc_attr( (string) $occurrence->venue_name_override ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $nte_inherited_venue_name ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="nte-occurrence-venue-address"><?php esc_html_e( 'Venue address', 'nettertech-events' ); ?></label>
					</th>
					<td>
						<textarea id="nte-occurrence-venue-address" name="venue_address_override" rows="2" class="large-text" placeholder="<?php echo esc_attr( $nte_inherited_venue_address ); ?>"><?php echo esc_textarea( (string) $occurrence->venue_address_override ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="nte-occurrence-virtual-url"><?php esc_html_e( 'Virtual URL', 'nettertech-events' ); ?></label>
					</th>
					<td>
						<input type="url" id="nte-occurrence-virtual-url" name="virtual_url_override" value="<?php echo esc_attr( (string) $occurrence->virtual_url_override ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $nte_inherited_virtual_url ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Featured image', 'nettertech-events' ); ?></th>
					<td>
						<div id="nte-occurrence-featured-image">
							<input type="hidden" name="featured_image_id" id="nte-occurrence-featured-image-id" value="<?php echo esc_attr( (string) $nte_override_image_id ); ?>">

							<div id="nte-occurrence-image-preview" class="nte-occurrence-edit__image-preview"<?php echo $nte_override_image_url ? '' : ' style="display:none;"'; ?>>
								<img src="<?php echo esc_url( $nte_override_image_url ); ?>" alt="" class="nte-occurrence-edit__image">
							</div>

							<?php if ( $nte_inherited_image_url ) : ?>
								<div class="nte-occurrence-edit__image-inherited"<?php echo $nte_override_image_url ? ' style="display:none;"' : ''; ?>>
									<img src="<?php echo esc_url( $nte_inherited_image_url ); ?>" alt="" class="nte-occurrence-edit__image nte-occurrence-edit__image--inherited">
									<p class="description"><?php esc_html_e( 'Inherited from the series.', 'nettertech-events' ); ?></p>
								</div>
							<?php endif; ?>

							<p>
								<button type="button" class="button" id="nte-occurrence-select-image">
									<?php echo $nte_override_image_id ? esc_html__( 'Change Image', 'nettertech-events' ) : esc_html__( 'Set Featured Image', 'nettertech-events' ); ?>
								</button>
								<button type="button" class="button" id="nte-occurrence-remove-image"<?php echo $nte_override_image_id ? '' : ' style="display:none;"'; ?>>
									<?php esc_html_e( 'Remove Override', 'nettertech-events' ); ?>
								</button>
							</p>
						</div>
					</td>
				</tr>
			</tbody>
		</table>

		<h2 class="title"><?php esc_html_e( 'Tickets for this date', 'nettertech-events' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Sold for this date only. Series passes and ticket templates belong to the event and are edited there.', 'nettertech-events' ); ?>
		</p>

		<?php $nte_editor->render_tickets_box(); ?>

		<?php if ( $event->is_recurring() ) : ?>
			<fieldset class="nte-occurrence-edit__scope">
				<legend class="nte-occurrence-edit__scope-legend"><?php esc_html_e( 'Apply these changes to', 'nettertech-events' ); ?></legend>
				<p>
					<label>
						<input type="radio" name="scope" value="this" checked>
						<?php esc_html_e( 'This date only', 'nettertech-events' ); ?>
					</label>
				</p>
				<p>
					<label>
						<input type="radio" name="scope" value="following">
						<?php esc_html_e( 'This and all following dates', 'nettertech-events' ); ?>
					</label>
				</p>
				<p>
					<label>
						<input type="radio" name="scope" value="all">
						<?php esc_html_e( 'All dates in the series', 'nettertech-events' ); ?>
					</label>
				</p>
			</fieldset>
		<?php else : ?>
			<input type="hidden" name="scope" value="this">
		<?php endif; ?>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'nettertech-events' ); ?></button>
			<a href="<?php echo esc_url( $nte_back_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel', 'nettertech-events' ); ?></a>
		</p>
	</form>
</div>
