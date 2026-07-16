<?php
/**
 * Attendee Fields Save Handler.
 *
 * Processes attendee field definitions from the event editor form.
 *
 * @package NetterTechEvents\Admin\Metaboxes
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Models\AttendeeField;

/**
 * Handles saving custom attendee field definitions from the event editor.
 *
 * @since 3.6.0
 */
class AttendeeFieldsSaveHandler {

	/**
	 * Field repository.
	 *
	 * @var AttendeeFieldRepositoryInterface
	 */
	private AttendeeFieldRepositoryInterface $field_repo;

	/**
	 * Field value repository (for cascade deletes).
	 *
	 * @var AttendeeFieldValueRepositoryInterface
	 */
	private AttendeeFieldValueRepositoryInterface $value_repo;

	/**
	 * Constructor.
	 *
	 * @param AttendeeFieldRepositoryInterface      $field_repo Field repository.
	 * @param AttendeeFieldValueRepositoryInterface $value_repo Value repository.
	 */
	public function __construct(
		AttendeeFieldRepositoryInterface $field_repo,
		AttendeeFieldValueRepositoryInterface $value_repo
	) {
		$this->field_repo = $field_repo;
		$this->value_repo = $value_repo;
	}

	/**
	 * Save attendee fields from submitted form data.
	 *
	 * Caller must pass already-unslashed field data after verifying nonce at the
	 * request boundary. The handler does not read superglobals directly.
	 *
	 * @param int                      $event_id         Event ID.
	 * @param array<int|string, mixed> $submitted_fields Submitted attendee field rows (per-field sanitization happens below).
	 * @return void
	 */
	public function save( int $event_id, array $submitted_fields ): void {
		$existing_fields = $this->field_repo->for_event( $event_id );
		$existing_ids    = array_filter( array_map( fn( AttendeeField $field ) => $field->id, $existing_fields ) );
		$submitted_ids   = array();

		$sort_order = 0;
		foreach ( $submitted_fields as $field_data ) {
			if ( ! is_array( $field_data ) ) {
				continue;
			}

			$label = isset( $field_data['label'] ) ? sanitize_text_field( wp_unslash( $field_data['label'] ) ) : '';
			if ( empty( $label ) ) {
				continue;
			}

			$field_id   = ! empty( $field_data['id'] ) ? (int) $field_data['id'] : null;
			$field_type = isset( $field_data['field_type'] ) ? sanitize_text_field( wp_unslash( $field_data['field_type'] ) ) : 'text';

			$field              = $field_id ? ( $this->field_repo->find( $field_id ) ?? new AttendeeField() ) : new AttendeeField();
			$field->event_id    = $event_id;
			$field->label       = $label;
			$field->field_type  = $field_type;
			$field->is_required = ! empty( $field_data['is_required'] );
			$field->sort_order  = $sort_order;
			$field->placeholder = isset( $field_data['placeholder'] )
				? sanitize_text_field( wp_unslash( $field_data['placeholder'] ) )
				: null;

			// Parse options from newline-separated textarea.
			if ( isset( $field_data['options'] ) ) {
				$raw_options = sanitize_textarea_field( wp_unslash( $field_data['options'] ) );
				$options     = array_values( array_filter( array_map( 'trim', explode( "\n", $raw_options ) ) ) );
				$field->set_options( $options );
			}

			$saved_field = $this->field_repo->save( $field );

			if ( $saved_field->id ) {
				$submitted_ids[] = $saved_field->id;
			}

			++$sort_order;
		}

		// Delete removed fields and their values.
		$removed_ids = array_diff( $existing_ids, $submitted_ids );
		foreach ( $removed_ids as $removed_id ) {
			$this->value_repo->delete_for_field( $removed_id );
			$this->field_repo->delete( $removed_id );
		}
	}
}
