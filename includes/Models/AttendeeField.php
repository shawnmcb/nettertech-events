<?php
/**
 * Attendee field model class.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Enums\FieldType;

/**
 * Represents a custom registration field definition for an event.
 *
 * @since 3.6.0
 * @api
 */
class AttendeeField {

	/**
	 * Field ID.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Parent event ID.
	 *
	 * @var int
	 */
	public int $event_id = 0;

	/**
	 * Unique field key within the event (auto-generated from label).
	 *
	 * @var string
	 */
	public string $field_key = '';

	/**
	 * Field type (text, select, checkbox, etc.).
	 *
	 * @var string
	 */
	public string $field_type = 'text';

	/**
	 * Display label.
	 *
	 * @var string
	 */
	public string $label = '';

	/**
	 * Placeholder text for input fields.
	 *
	 * @var string|null
	 */
	public ?string $placeholder = null;

	/**
	 * Help text displayed below the field.
	 *
	 * @var string|null
	 */
	public ?string $description = null;

	/**
	 * JSON-encoded options for select/radio/checkbox fields.
	 *
	 * @var string|null
	 */
	public ?string $options = null;

	/**
	 * Whether this field is required at checkout.
	 *
	 * @var bool
	 */
	public bool $is_required = false;

	/**
	 * Display order.
	 *
	 * @var int
	 */
	public int $sort_order = 0;

	/**
	 * JSON-encoded validation rules.
	 *
	 * @var string|null
	 */
	public ?string $validation_rules = null;

	/**
	 * Created timestamp.
	 *
	 * @var string|null
	 */
	public ?string $created_at = null;

	/**
	 * Updated timestamp.
	 *
	 * @var string|null
	 */
	public ?string $updated_at = null;

	/**
	 * Create an AttendeeField from a database row.
	 *
	 * @param object|array<string, mixed> $row Database row.
	 * @return self
	 */
	public static function from_row( object|array $row ): self {
		$row   = (object) $row;
		$field = new self();

		$field->id               = isset( $row->id ) ? (int) $row->id : null;
		$field->event_id         = (int) ( $row->event_id ?? 0 );
		$field->field_key        = $row->field_key ?? '';
		$field->field_type       = $row->field_type ?? 'text';
		$field->label            = $row->label ?? '';
		$field->placeholder      = $row->placeholder ?? null;
		$field->description      = $row->description ?? null;
		$field->options          = $row->options ?? null;
		$field->is_required      = ! empty( $row->is_required );
		$field->sort_order       = (int) ( $row->sort_order ?? 0 );
		$field->validation_rules = $row->validation_rules ?? null;
		$field->created_at       = $row->created_at ?? null;
		$field->updated_at       = $row->updated_at ?? null;

		return $field;
	}

	/**
	 * Convert to array for database insertion.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'event_id'         => $this->event_id,
			'field_key'        => $this->field_key,
			'field_type'       => $this->field_type,
			'label'            => $this->label,
			'placeholder'      => $this->placeholder,
			'description'      => $this->description,
			'options'          => $this->options,
			'is_required'      => $this->is_required ? 1 : 0,
			'sort_order'       => $this->sort_order,
			'validation_rules' => $this->validation_rules,
		);
	}

	/**
	 * Get format specifiers for database operations.
	 *
	 * @return array<string>
	 */
	public function get_formats(): array {
		return array(
			'%d', // event_id.
			'%s', // field_key.
			'%s', // field_type.
			'%s', // label.
			'%s', // placeholder.
			'%s', // description.
			'%s', // options.
			'%d', // is_required.
			'%d', // sort_order.
			'%s', // validation_rules.
		);
	}

	/**
	 * Validate the field definition.
	 *
	 * @return array<string> Array of error messages, empty if valid.
	 */
	public function validate(): array {
		$errors = array();

		if ( empty( $this->event_id ) ) {
			$errors[] = __( 'Event ID is required.', 'nettertech-events' );
		}

		if ( empty( $this->label ) ) {
			$errors[] = __( 'Field label is required.', 'nettertech-events' );
		}

		if ( ! in_array( $this->field_type, FieldType::values(), true ) ) {
			$errors[] = __( 'Invalid field type.', 'nettertech-events' );
		}

		$type = FieldType::tryFrom( $this->field_type );
		if ( null !== $type && $type->has_options() && empty( $this->get_options() ) ) {
			$errors[] = __( 'Options are required for this field type.', 'nettertech-events' );
		}

		return $errors;
	}

	/**
	 * Get decoded options array.
	 *
	 * @return array<string>
	 */
	public function get_options(): array {
		if ( empty( $this->options ) ) {
			return array();
		}

		$decoded = json_decode( $this->options, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Set options from an array.
	 *
	 * @param array<string> $options Option values.
	 * @return void
	 */
	public function set_options( array $options ): void {
		$encoded       = ! empty( $options ) ? wp_json_encode( array_values( $options ) ) : null;
		$this->options = is_string( $encoded ) ? $encoded : null;
	}

	/**
	 * Get decoded validation rules.
	 *
	 * @return array<string, mixed>
	 */
	public function get_validation_rules(): array {
		if ( empty( $this->validation_rules ) ) {
			return array();
		}

		$decoded = json_decode( $this->validation_rules, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Get the FieldType enum instance.
	 *
	 * @return FieldType|null
	 */
	public function get_field_type(): ?FieldType {
		return FieldType::tryFrom( $this->field_type );
	}
}
