<?php
/**
 * Organizer Repository Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Organizer;

/**
 * Interface for Organizer repository implementations.
 *
 * @since 0.9.0
 * @api
 */
interface OrganizerRepositoryInterface {

	/**
	 * Find an organizer by ID.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Organizer ID.
	 * @return Organizer|null
	 */
	public function find( int $id ): ?Organizer;

	/**
	 * Find an organizer by slug.
	 *
	 * @since 0.9.0
	 *
	 * @param string $slug Organizer slug.
	 * @return Organizer|null
	 */
	public function find_by_slug( string $slug ): ?Organizer;

	/**
	 * Save an organizer (insert or update).
	 *
	 * @since 0.9.0
	 *
	 * @param Organizer $organizer Organizer to save.
	 * @return Organizer The saved organizer with ID populated.
	 * @throws \RuntimeException If validation fails or save fails.
	 */
	public function save( Organizer $organizer ): Organizer;

	/**
	 * Delete an organizer.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Organizer ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool;

	/**
	 * Get all organizers.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<Organizer>
	 */
	public function get_all( array $args = array() ): array;

	/**
	 * Get organizers for an event.
	 *
	 * @since 0.9.0
	 *
	 * @param int $event_id Event ID.
	 * @return array<Organizer>
	 */
	public function find_by_event( int $event_id ): array;

	/**
	 * Get event counts for all organizers in a single query.
	 *
	 * @since 1.8.0
	 *
	 * @return array<int, int> Map of organizer_id => event_count.
	 */
	public function get_event_counts(): array;

	/**
	 * Attach an organizer to an event.
	 *
	 * @since 0.9.0
	 *
	 * @param int  $event_id     Event ID.
	 * @param int  $organizer_id Organizer ID.
	 * @param bool $is_primary   Whether this is the primary organizer.
	 * @param int  $sort_order   Sort order.
	 * @return bool True on success.
	 */
	public function attach_to_event( int $event_id, int $organizer_id, bool $is_primary = false, int $sort_order = 0 ): bool;

	/**
	 * Detach an organizer from an event.
	 *
	 * @since 0.9.0
	 *
	 * @param int $event_id     Event ID.
	 * @param int $organizer_id Organizer ID.
	 * @return bool True on success.
	 */
	public function detach_from_event( int $event_id, int $organizer_id ): bool;

	/**
	 * Sync organizers for an event.
	 *
	 * Replaces all event organizers with the provided list.
	 *
	 * @since 0.9.0
	 *
	 * @param int                                                            $event_id   Event ID.
	 * @param array<int|array{id: int, is_primary?: bool, sort_order?: int}> $organizers List of organizer IDs or config arrays.
	 * @return bool True on success.
	 */
	public function sync_event_organizers( int $event_id, array $organizers ): bool;
}
