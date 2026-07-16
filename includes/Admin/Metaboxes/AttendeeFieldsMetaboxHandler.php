<?php
/**
 * Attendee Fields Metabox Handler.
 *
 * Renders the custom registration fields builder in the event editor.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Enums\FieldType;
use NetterTechEvents\Models\AttendeeField;
use NetterTechEvents\Models\Event;

/**
 * Renders the attendee registration fields builder metabox.
 *
 * @since 3.6.0
 */
class AttendeeFieldsMetaboxHandler {

	/**
	 * Event being edited.
	 *
	 * @var Event
	 */
	private Event $event;

	/**
	 * Field repository.
	 *
	 * @var AttendeeFieldRepositoryInterface
	 */
	private AttendeeFieldRepositoryInterface $field_repo;

	/**
	 * Constructor.
	 *
	 * @param Event                            $event      Event being edited.
	 * @param AttendeeFieldRepositoryInterface $field_repo Field repository.
	 */
	public function __construct( Event $event, AttendeeFieldRepositoryInterface $field_repo ) {
		$this->event      = $event;
		$this->field_repo = $field_repo;
	}

	/**
	 * Render the attendee fields metabox.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->event->id ) {
			return;
		}

		$fields = $this->field_repo->for_event( $this->event->id );

		// Enqueue field builder script.
		wp_enqueue_script(
			'nettertech-events-attendee-fields',
			NETTERTECH_EVENTS_PLUGIN_URL . 'assets/js/admin/attendee-fields.js',
			array(),
			NETTERTECH_EVENTS_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		?>
		<div class="postbox" id="nte-attendee-fields-postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Registration Fields', 'nettertech-events' ); ?></h2>
			</div>
			<div class="inside">
				<p>
					<label>
						<input type="checkbox"
							name="collect_individual_attendees"
							value="1"
							<?php checked( ! empty( $this->event->collect_individual_attendees ) ); ?>>
						<strong><?php esc_html_e( 'Collect individual attendee details when quantity > 1', 'nettertech-events' ); ?></strong>
					</label>
				</p>
				<p class="description" style="margin-bottom: 12px;">
					<?php esc_html_e( 'When enabled, checkout collects name and email for each individual ticket holder instead of using billing info for all.', 'nettertech-events' ); ?>
				</p>

				<hr style="margin: 12px 0;">

				<h3 style="margin-top: 0;"><?php esc_html_e( 'Custom Fields', 'nettertech-events' ); ?></h3>
				<p class="description" style="margin-bottom: 12px;">
					<?php esc_html_e( 'Define custom fields that attendees fill in during checkout (e.g., dietary requirements, t-shirt size).', 'nettertech-events' ); ?>
				</p>

				<div id="nte-attendee-fields-list">
					<?php
					foreach ( $fields as $index => $field ) {
						$this->render_field_row( $field, $index );
					}
					?>
				</div>

				<p>
					<button type="button" class="button" id="nte-add-attendee-field">
						<?php esc_html_e( '+ Add Field', 'nettertech-events' ); ?>
					</button>
				</p>

				<?php $this->render_field_template(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single field row.
	 *
	 * @param AttendeeField $field Field data.
	 * @param int|string    $index Row index.
	 * @return void
	 */
	private function render_field_row( AttendeeField $field, int|string $index ): void {
		$presenter = new Presenters\AttendeeFieldRowPresenter( $field, $index );
		include dirname( __DIR__, 3 ) . '/templates/admin/metaboxes/attendee-field-row.php';
	}

	/**
	 * Render the JS template for new field rows.
	 *
	 * @return void
	 */
	private function render_field_template(): void {
		?>
		<script type="text/html" id="tmpl-nte-attendee-field">
			<div class="nte-attendee-field-row" style="border: 1px solid #ddd; padding: 12px; margin-bottom: 8px; background: #fafafa;">
				<input type="hidden" name="attendee_fields[{{data.index}}][id]" value="">

				<div style="display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap;">
					<div style="flex: 2; min-width: 150px;">
						<label class="screen-reader-text"><?php esc_html_e( 'Label', 'nettertech-events' ); ?></label>
						<input type="text" name="attendee_fields[{{data.index}}][label]"
							placeholder="<?php esc_attr_e( 'Field label', 'nettertech-events' ); ?>"
							class="widefat" required>
					</div>
					<div style="flex: 1; min-width: 120px;">
						<label class="screen-reader-text"><?php esc_html_e( 'Type', 'nettertech-events' ); ?></label>
						<select name="attendee_fields[{{data.index}}][field_type]" class="widefat nte-field-type-select">
							<?php foreach ( FieldType::cases() as $type ) : ?>
								<option value="<?php echo esc_attr( $type->value ); ?>">
									<?php echo esc_html( $type->label() ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div style="flex: 0 0 auto; display: flex; align-items: center; gap: 12px;">
						<label>
							<input type="checkbox" name="attendee_fields[{{data.index}}][is_required]" value="1">
							<?php esc_html_e( 'Required', 'nettertech-events' ); ?>
						</label>
						<button type="button" class="button-link nte-remove-field" style="color: #b32d2e;">
							<?php esc_html_e( 'Remove', 'nettertech-events' ); ?>
						</button>
					</div>
				</div>

				<div class="nte-field-options-wrap" style="display:none;">
					<label class="screen-reader-text"><?php esc_html_e( 'Options', 'nettertech-events' ); ?></label>
					<textarea name="attendee_fields[{{data.index}}][options]"
						class="widefat" rows="3"
						placeholder="<?php esc_attr_e( 'One option per line', 'nettertech-events' ); ?>"
					></textarea>
					<p class="description"><?php esc_html_e( 'Enter each option on a new line.', 'nettertech-events' ); ?></p>
				</div>

				<div style="display: flex; gap: 8px; margin-top: 8px;">
					<div style="flex: 1;">
						<input type="text" name="attendee_fields[{{data.index}}][placeholder]"
							placeholder="<?php esc_attr_e( 'Placeholder text (optional)', 'nettertech-events' ); ?>"
							class="widefat">
					</div>
				</div>
			</div>
		</script>
		<?php
	}
}
