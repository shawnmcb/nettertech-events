<?php
/**
 * Attendee field repository interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\AttendeeField;

/**
 * Contract for attendee field definition CRUD operations.
 *
 * @since 3.6.0
 * @api
 */
interface AttendeeFieldRepositoryInterface {

	/**
	 * Find a field by ID.
	 *
	 * @param int $id Field ID.
	 * @return AttendeeField|null
	 */
	public function find( int $id ): ?AttendeeField;

	/**
	 * Get all fields for an event, ordered by sort_order.
	 *
	 * @param int $event_id Event ID.
	 * @return array<AttendeeField>
	 */
	public function for_event( int $event_id ): array;

	/**
	 * Save a field (insert or update).
	 *
	 * @param AttendeeField $field Field to save.
	 * @return AttendeeField Saved field with ID populated.
	 */
	public function save( AttendeeField $field ): AttendeeField;

	/**
	 * Delete a field.
	 *
	 * @param int $id Field ID.
	 * @return bool
	 */
	public function delete( int $id ): bool;

	/**
	 * Reorder fields for an event.
	 *
	 * @param int        $event_id  Event ID.
	 * @param array<int> $field_ids Ordered array of field IDs.
	 * @return bool
	 */
	public function reorder( int $event_id, array $field_ids ): bool;

	/**
	 * Delete all fields for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int Number of deleted rows.
	 */
	public function delete_for_event( int $event_id ): int;
}
