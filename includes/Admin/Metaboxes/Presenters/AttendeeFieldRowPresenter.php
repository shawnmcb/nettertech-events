<?php
/**
 * Attendee Field Row Presenter (T4.2.4 Metaboxes cluster).
 *
 * @package NetterTechEvents\Admin\Metaboxes\Presenters
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Metaboxes\Presenters;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Enums\FieldType;
use NetterTechEvents\Models\AttendeeField;

/**
 * Pure data-prep value object for a single attendee-field builder row.
 *
 * The row is one entry in the custom registration fields builder shown on the
 * event editor's "Registration Fields" metabox. It surfaces the saved values
 * plus the form-input name prefix and the FieldType options list ready for
 * template consumption.
 *
 * Does NOT call `esc_*()` (template's job), does NOT output, does NOT read
 * from $_GET/$_POST/get_option, does NOT call $wpdb.
 *
 * @since 1.x.x
 */
final class AttendeeFieldRowPresenter {

	/**
	 * Wire the field model and the row index used in input name prefixes.
	 *
	 * @param AttendeeField $field The field to render.
	 * @param int|string    $index The row index (numeric for existing rows; the JS template uses placeholder strings).
	 */
	public function __construct(
		private readonly AttendeeField $field,
		private readonly int|string $index
	) {}

	/**
	 * Input-name prefix for the row, e.g. `attendee_fields[3]`.
	 *
	 * @return string
	 */
	public function name_prefix(): string {
		return "attendee_fields[{$this->index}]";
	}

	/**
	 * Saved field ID for the hidden ID input (may be empty string).
	 *
	 * @return string
	 */
	public function field_id(): string {
		return (string) ( $this->field->id ?? '' );
	}

	/**
	 * Field label input value.
	 *
	 * @return string
	 */
	public function label_value(): string {
		return $this->field->label;
	}

	/**
	 * Whether the field is marked required.
	 *
	 * @return bool
	 */
	public function is_required(): bool {
		return $this->field->is_required;
	}

	/**
	 * Placeholder input value (empty string if unset).
	 *
	 * @return string
	 */
	public function placeholder_value(): string {
		return $this->field->placeholder ?? '';
	}

	/**
	 * Joined options text (one per line) for the options textarea.
	 *
	 * @return string
	 */
	public function options_text(): string {
		return implode( "\n", $this->field->get_options() );
	}

	/**
	 * Whether the current field type has an options sub-list (select/checkbox/radio).
	 *
	 * Drives the inline `display:none` style on the options-wrap div.
	 *
	 * @return bool
	 */
	public function shows_options_panel(): bool {
		$type = FieldType::tryFrom( $this->field->field_type );
		return null !== $type && $type->has_options();
	}

	/**
	 * Inline style for the options-wrap div ('' when visible, 'display:none;' when hidden).
	 *
	 * @return string
	 */
	public function options_wrap_style(): string {
		return $this->shows_options_panel() ? '' : 'display:none;';
	}

	/**
	 * The field-type options for the type select.
	 *
	 * @return list<array{value: string, label: string, selected: bool}>
	 */
	public function field_type_options(): array {
		$current = $this->field->field_type;
		$out     = array();
		foreach ( FieldType::cases() as $type ) {
			$out[] = array(
				'value'    => $type->value,
				'label'    => $type->label(),
				'selected' => $type->value === $current,
			);
		}
		return $out;
	}

	// ---- Translatable labels ----

	/**
	 * "Label" screen-reader label.
	 *
	 * @return string
	 */
	public function label_sr_label(): string {
		return __( 'Label', 'nettertech-events' );
	}

	/**
	 * "Field label" input placeholder.
	 *
	 * @return string
	 */
	public function label_placeholder(): string {
		return __( 'Field label', 'nettertech-events' );
	}

	/**
	 * "Type" screen-reader label.
	 *
	 * @return string
	 */
	public function type_sr_label(): string {
		return __( 'Type', 'nettertech-events' );
	}

	/**
	 * "Required" checkbox label.
	 *
	 * @return string
	 */
	public function required_label(): string {
		return __( 'Required', 'nettertech-events' );
	}

	/**
	 * "Remove" button label.
	 *
	 * @return string
	 */
	public function remove_label(): string {
		return __( 'Remove', 'nettertech-events' );
	}

	/**
	 * "Options" screen-reader label.
	 *
	 * @return string
	 */
	public function options_sr_label(): string {
		return __( 'Options', 'nettertech-events' );
	}

	/**
	 * "One option per line" textarea placeholder.
	 *
	 * @return string
	 */
	public function options_placeholder(): string {
		return __( 'One option per line', 'nettertech-events' );
	}

	/**
	 * "Enter each option on a new line." description.
	 *
	 * @return string
	 */
	public function options_description(): string {
		return __( 'Enter each option on a new line.', 'nettertech-events' );
	}

	/**
	 * "Placeholder text (optional)" input placeholder.
	 *
	 * @return string
	 */
	public function placeholder_input_placeholder(): string {
		return __( 'Placeholder text (optional)', 'nettertech-events' );
	}
}
