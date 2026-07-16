<?php
/**
 * Attendee field value repository interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\AttendeeFieldValue;

/**
 * Contract for attendee field value storage operations.
 *
 * @since 3.6.0
 * @api
 */
interface AttendeeFieldValueRepositoryInterface {

	/**
	 * Get all field values for an attendee.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return array<AttendeeFieldValue>
	 */
	public function for_attendee( int $attendee_id ): array;

	/**
	 * Save multiple field values for an attendee (upsert).
	 *
	 * @param int                     $attendee_id  Attendee ID.
	 * @param array<int, string|null> $field_values Map of field_id => value.
	 * @return void
	 */
	public function save_values( int $attendee_id, array $field_values ): void;

	/**
	 * Delete all field values for an attendee.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return int Number of deleted rows.
	 */
	public function delete_for_attendee( int $attendee_id ): int;

	/**
	 * Delete all values for a specific field definition.
	 *
	 * @param int $field_id Field definition ID.
	 * @return int Number of deleted rows.
	 */
	public function delete_for_field( int $field_id ): int;
}
