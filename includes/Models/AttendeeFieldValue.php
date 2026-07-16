<?php
/**
 * Attendee field value model class.
 *
 * @package NetterTechEvents\Models
 */

declare(strict_types=1);

namespace NetterTechEvents\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a stored value for a custom registration field per attendee.
 *
 * @since 3.6.0
 * @api
 */
class AttendeeFieldValue {

	/**
	 * Value ID.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Parent attendee ID.
	 *
	 * @var int
	 */
	public int $attendee_id = 0;

	/**
	 * Field definition ID.
	 *
	 * @var int
	 */
	public int $field_id = 0;

	/**
	 * Field value (all types stored as text).
	 *
	 * @var string|null
	 */
	public ?string $field_value = null;

	/**
	 * Created timestamp.
	 *
	 * @var string|null
	 */
	public ?string $created_at = null;

	/**
	 * Create an AttendeeFieldValue from a database row.
	 *
	 * @param object|array<string, mixed> $row Database row.
	 * @return self
	 */
	public static function from_row( object|array $row ): self {
		$row   = (object) $row;
		$value = new self();

		$value->id          = isset( $row->id ) ? (int) $row->id : null;
		$value->attendee_id = (int) ( $row->attendee_id ?? 0 );
		$value->field_id    = (int) ( $row->field_id ?? 0 );
		$value->field_value = $row->field_value ?? null;
		$value->created_at  = $row->created_at ?? null;

		return $value;
	}

	/**
	 * Convert to array for database insertion.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'attendee_id' => $this->attendee_id,
			'field_id'    => $this->field_id,
			'field_value' => $this->field_value,
		);
	}

	/**
	 * Get format specifiers for database operations.
	 *
	 * @return array<string>
	 */
	public function get_formats(): array {
		return array(
			'%d', // attendee_id.
			'%d', // field_id.
			'%s', // field_value.
		);
	}
}
