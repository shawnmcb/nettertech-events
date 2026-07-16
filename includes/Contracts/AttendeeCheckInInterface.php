<?php
/**
 * Attendee Check-In Interface.
 *
 * Defines check-in specific operations for attendees.
 * Part of the segregated AttendeeRepository interface design (ISP compliance).
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for attendee check-in operations.
 *
 * This interface segregates check-in functionality from the core repository,
 * following the Interface Segregation Principle. Consumers that only need
 * check-in operations (CheckInController, CheckInPage) should depend on
 * this interface rather than the full AttendeeRepositoryInterface.
 *
 * @since 0.9.3
 */
interface AttendeeCheckInInterface {

	/**
	 * Mark an attendee as fully checked in.
	 *
	 * Sets checked_in to true and checked_in_count to quantity.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Attendee ID.
	 * @return bool True on success.
	 */
	public function mark_checked_in( int $id ): bool;

	/**
	 * Mark an attendee as not checked in.
	 *
	 * Sets checked_in to false and checked_in_count to 0.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Attendee ID.
	 * @return bool True on success.
	 */
	public function mark_not_checked_in( int $id ): bool;

	/**
	 * Increment checked-in count by 1.
	 *
	 * Will not exceed the attendee's quantity.
	 * Automatically sets checked_in to true when count equals quantity.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Attendee ID.
	 * @return array{success: bool, checked_in_count: int, checked_in: bool}
	 */
	public function increment_checked_in( int $id ): array;

	/**
	 * Decrement checked-in count by 1.
	 *
	 * Will not go below 0.
	 * Automatically sets checked_in to false when count reaches 0.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Attendee ID.
	 * @return array{success: bool, checked_in_count: int, checked_in: bool}
	 */
	public function decrement_checked_in( int $id ): array;

	/**
	 * Set checked-in count to a specific value.
	 *
	 * Value is clamped between 0 and quantity.
	 * Automatically updates checked_in status based on count.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id    Attendee ID.
	 * @param int $count Number of guests checked in.
	 * @return array{success: bool, checked_in_count: int, checked_in: bool}
	 */
	public function set_checked_in_count( int $id, int $count ): array;

	/**
	 * Toggle check-in status.
	 *
	 * If checked in, marks as not checked in.
	 * If not checked in, marks as fully checked in.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Attendee ID.
	 * @return bool New checked-in state.
	 */
	public function toggle_checked_in( int $id ): bool;

	/**
	 * Get check-in list for an occurrence.
	 *
	 * Returns formatted list of attendees suitable for check-in UI.
	 *
	 * @since 0.9.0
	 *
	 * @param int                  $occurrence_id Occurrence ID.
	 * @param array<string, mixed> $args          Query arguments.
	 * @return array<array<string, mixed>>
	 */
	public function get_check_in_list( int $occurrence_id, array $args = array() ): array;

	/**
	 * Get check-in statistics for an occurrence.
	 *
	 * Returns counts of checked-in, not checked-in, and total attendees.
	 *
	 * @since 0.9.0
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<string, int>
	 */
	public function get_check_in_stats( int $occurrence_id ): array;
}
