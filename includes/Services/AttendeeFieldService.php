<?php
/**
 * Attendee Field Service.
 *
 * Business logic for custom attendee registration fields.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Enums\FieldType;
use NetterTechEvents\Models\AttendeeField;

/**
 * Service for custom attendee registration field operations.
 *
 * Handles field resolution from cart items, validation of submitted values,
 * and persistence of field values to attendee records.
 *
 * @since 3.6.0
 */
class AttendeeFieldService {

	/**
	 * Field repository.
	 *
	 * @var AttendeeFieldRepositoryInterface
	 */
	private AttendeeFieldRepositoryInterface $field_repo;

	/**
	 * Field value repository.
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
	 * Get custom fields for events in the current cart.
	 *
	 * Returns fields grouped by event ID. Only events with defined fields are included.
	 *
	 * @param array<int> $event_ids Event IDs from cart items.
	 * @return array<int, array<AttendeeField>> Map of event_id => fields.
	 */
	public function get_fields_for_events( array $event_ids ): array {
		$grouped = array();

		foreach ( array_unique( $event_ids ) as $event_id ) {
			$fields = $this->field_repo->for_event( $event_id );
			if ( ! empty( $fields ) ) {
				$grouped[ $event_id ] = $fields;
			}
		}

		return $grouped;
	}

	/**
	 * Validate custom field values for an event.
	 *
	 * @param int                   $event_id Event ID.
	 * @param array<string, string> $values   Map of field_key => submitted value.
	 * @return array<string> Error messages (empty if valid).
	 */
	public function validate_field_values( int $event_id, array $values ): array {
		$fields = $this->field_repo->for_event( $event_id );
		$errors = array();

		foreach ( $fields as $field ) {
			$value = $values[ $field->field_key ] ?? '';

			// Required check.
			if ( $field->is_required && '' === trim( (string) $value ) ) {
				$errors[] = sprintf(
					/* translators: %s: field label */
					__( '%s is required.', 'nettertech-events' ),
					$field->label
				);
				continue;
			}

			// Skip further validation if empty and not required.
			if ( '' === trim( (string) $value ) ) {
				continue;
			}

			// Type-specific validation.
			$type_error = $this->validate_field_type( $field, $value );
			if ( null !== $type_error ) {
				$errors[] = $type_error;
			}
		}

		return $errors;
	}

	/**
	 * Save custom field values for an attendee.
	 *
	 * @param int                   $attendee_id Attendee ID.
	 * @param int                   $event_id    Event ID (for field definitions).
	 * @param array<string, string> $values      Map of field_key => submitted value.
	 * @return void
	 */
	public function save_field_values( int $attendee_id, int $event_id, array $values ): void {
		$fields     = $this->field_repo->for_event( $event_id );
		$field_data = array();

		foreach ( $fields as $field ) {
			if ( null === $field->id ) {
				continue;
			}

			$value = $values[ $field->field_key ] ?? null;
			if ( null !== $value ) {
				$field_data[ $field->id ] = sanitize_text_field( $value );
			}
		}

		if ( ! empty( $field_data ) ) {
			$this->value_repo->save_values( $attendee_id, $field_data );

			do_action( 'nettertech_events_custom_field_values_saved', $attendee_id, $event_id, $values );
		}
	}

	/**
	 * Validate a single field value against its type.
	 *
	 * @param AttendeeField $field Field definition.
	 * @param string        $value Submitted value.
	 * @return string|null Error message or null if valid.
	 */
	private function validate_field_type( AttendeeField $field, string $value ): ?string {
		$type = FieldType::tryFrom( $field->field_type );
		if ( null === $type ) {
			return null;
		}

		// phpcs:disable PHPCompatibility.Operators.RemovedTernaryAssociativity.Found -- These are independent ternaries inside match arms, not chained.
		return match ( $type ) {
			FieldType::EMAIL => ! is_email( $value )
				? sprintf(
					/* translators: %s: field label */
					__( '%s must be a valid email address.', 'nettertech-events' ),
					$field->label
				)
				: null,
			FieldType::URL => ! filter_var( $value, FILTER_VALIDATE_URL )
				? sprintf(
					/* translators: %s: field label */
					__( '%s must be a valid URL.', 'nettertech-events' ),
					$field->label
				)
				: null,
			FieldType::NUMBER => ! is_numeric( $value )
				? sprintf(
					/* translators: %s: field label */
					__( '%s must be a number.', 'nettertech-events' ),
					$field->label
				)
				: null,
			FieldType::SELECT, FieldType::RADIO => ! in_array( $value, $field->get_options(), true )
				? sprintf(
					/* translators: %s: field label */
					__( '%s contains an invalid selection.', 'nettertech-events' ),
					$field->label
				)
				: null,
			default => null,
		};
		// phpcs:enable PHPCompatibility.Operators.RemovedTernaryAssociativity.Found
	}
}
